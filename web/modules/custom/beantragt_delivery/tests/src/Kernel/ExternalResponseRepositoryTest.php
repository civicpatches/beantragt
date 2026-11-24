<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises real SQL uniqueness, citizen visibility and at-most-once delivery.
 */
#[Group('beantragt_delivery')]
final class ExternalResponseRepositoryTest extends KernelTestBase {

  /**
   * Modules enabled for this test.
   *
   * @var string[]
   */
  protected static $modules = ['system'];

  /**
   * Repository under test.
   */
  private ExternalResponseRepository $repository;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $database = $this->container->get('database');
    foreach (ExternalResponseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->repository = new ExternalResponseRepository($database);
  }

  /**
   * Tests release visibility, notification claims and document deletion.
   */
  public function testReleaseVisibilityAndNotificationClaim(): void {
    $this->repository->insert($this->row(), [
      ['uuid' => 'doc', 'response_uuid' => 'one', 'file_id' => 17, 'sha512' => str_repeat('a', 128)],
    ]);
    self::assertCount(0, $this->repository->list(1, FALSE));
    self::assertCount(1, $this->repository->list(1, TRUE));
    self::assertCount(0, $this->repository->list(2, TRUE));
    self::assertFalse($this->repository->claimNotification('one'));
    $this->repository->release('one', 100);
    $this->repository->release('one', 200);
    self::assertCount(1, $this->repository->list(1, FALSE));
    self::assertSame(100, (int) $this->repository->find(['uuid' => 'one'])['released']);
    self::assertTrue($this->repository->claimNotification('one'));
    self::assertFalse($this->repository->claimNotification('one'));
    $this->repository->notificationResult('one', FALSE);
    self::assertSame('failed', $this->repository->find(['uuid' => 'one'])['notification_state']);
    self::assertFalse($this->repository->claimNotification('one'));
    $this->repository->retrieved('one', 300);
    $this->repository->retrieved('one', 400);
    self::assertSame(300, (int) $this->repository->find(['uuid' => 'one'])['retrieved']);
    $this->repository->delete('one');
    self::assertSame([], $this->repository->documents('one'));
    self::assertNull($this->repository->find(['uuid' => 'one']));
  }

  /**
   * Tests that an external identity cannot be stored twice across applications.
   */
  public function testExternalIdentityIsUniqueAcrossApplications(): void {
    $this->repository->insert($this->row(), []);
    $duplicate = $this->row();
    $duplicate['uuid'] = 'two';
    $duplicate['application_id'] = 2;
    $this->expectException(IntegrityConstraintViolationException::class);
    $this->repository->insert($duplicate, []);
  }

  /**
   * Existing FIT-Connect responses are protected once, regardless of state.
   */
  public function testProtectExistingFitConnectResponsesUpdate(): void {
    require_once dirname(__DIR__, 3) . '/beantragt_delivery.install';
    foreach ([
      ['pending', 'fit_connect', 0, NULL],
      ['released', 'fit_connect', 0, 100],
      ['protected', 'fit_connect', 1, 100],
      ['other', 'docgen', 0, 100],
    ] as [$uuid, $source, $protected, $released]) {
      $this->repository->insert(array_replace($this->row(), [
        'uuid' => $uuid,
        'source' => $source,
        'external_id_hash' => hash('sha256', $uuid),
        'contains_protected' => $protected,
        'released' => $released,
      ]), []);
    }
    self::assertSame('Protected 2 existing FIT-Connect responses.', beantragt_delivery_update_10017());
    foreach (['pending', 'released', 'protected'] as $uuid) {
      self::assertSame(1, (int) $this->repository->find(['uuid' => $uuid])['contains_protected']);
    }
    self::assertSame(0, (int) $this->repository->find(['uuid' => 'other'])['contains_protected']);
    self::assertSame('Protected 0 existing FIT-Connect responses.', beantragt_delivery_update_10017());
    $this->container->get('database')->schema()->dropTable('beantragt_external_response');
    self::assertSame('Protected 0 existing FIT-Connect responses (table absent).', beantragt_delivery_update_10017());
  }

  /**
   * Returns a valid external response row for the test fixtures.
   */
  private function row(): array {
    return [
      'uuid' => 'one',
      'application_id' => 1,
      'source' => 'fit_connect',
      'external_id_hash' => hash('sha256', 'external'),
      'external_reference' => 'reference',
      'title' => 'Bescheid',
      'received' => 1,
    ];
  }

}
