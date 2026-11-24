<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Controller\TransferController;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Service\TransferPlanner;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Psr\Log\NullLogger;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\FitConnectCaseRepository;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies transfer response lookup without adding citizen-facing metadata.
 */
#[Group('beantragt_delivery')]
final class TransferResponseLookupTest extends KernelTestBase {

  /**
   * {@inheritdoc}
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
   * Response linkage is exact to both the FIT case and application.
   */
  public function testTransferLookupUsesVerifiedCaseBinding(): void {
    $database = $this->container->get('database');
    foreach (FitConnectCaseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->repository->insert([
      'uuid' => 'one',
      'application_id' => 1,
      'source' => 'fit_connect',
      'external_id_hash' => hash('sha256', 'external'),
      'external_reference' => 'reference',
      'title' => 'Synthetic decision',
      'received' => 1,
    ], []);
    $database->insert('beantragt_fit_case')->fields([
      'log_id' => 42,
      'application_id' => 1,
      'case_id' => 'synthetic-case',
      'scope_hash' => 'synthetic-scope',
      'client_hash' => 'synthetic-client',
      'process_standard' => 'synthetic-standard',
      'private_key' => 'not-a-key',
      'public_key' => 'not-a-key',
    ])->execute();
    $database->insert('beantragt_fit_reply_ack')->fields([
      'reply_id' => 'external',
      'case_id' => 'synthetic-case',
      'application_id' => 1,
      'tags' => '[]',
      'state' => 'pending',
      'acceptance_set' => '',
    ])->execute();
    self::assertSame('one', $this->repository->listForTransfer(1, 42)[0]['uuid']);
    self::assertSame([], $this->repository->listForTransfer(2, 42));
    self::assertSame([], $this->repository->listForTransfer(1, 43));
    $batch = $this->repository->listForTransfers(1, [42, 43, 44]);
    self::assertSame([42 => $this->repository->listForTransfer(1, 42)], $batch);
    self::assertSame([], $this->repository->listForTransfers(2, [42, 43]));
    self::assertSame([], $this->repository->listForTransfers(1, []));
    $database->update('beantragt_fit_reply_ack')->fields(['application_id' => 2])->execute();
    self::assertSame([], $this->repository->listForTransfer(1, 42));
  }

  /**
   * SQL transfer history survives a tenant move without revealing old metadata.
   */
  public function testTenantMoveNeutralisesForeignRowsAndBatchesApprovers(): void {
    require_once dirname(__DIR__, 3) . '/beantragt_delivery.install';
    $database = $this->container->get('database');
    $database->schema()->createTable('beantragt_delivery_log', beantragt_delivery_schema()['beantragt_delivery_log']);
    foreach (FitConnectCaseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    for ($id = 1; $id <= 3; $id++) {
      $database->insert('beantragt_delivery_log')->fields([
        'id' => $id,
        'uuid' => 'transfer-' . $id,
        'application_id' => 42,
        'application_uuid' => 'synthetic-application',
        'adapter' => 'fit_connect',
        'status' => 'accepted',
        'data_hash' => str_repeat('a', 64),
        'created' => $id,
        'changed' => $id,
        'scope_group_id' => $id === 1 ? 1 : 2,
        'dispatch_approved_uid' => $id === 1 ? 99 : 7,
        'dispatch_approved_at' => 1,
        'integration_connection_uuid' => 'synthetic-connection',
        'connection_snapshot' => json_encode(['label' => $id === 1 ? 'PRIVATE_OTHER_TENANT' : 'Current connection'], JSON_THROW_ON_ERROR),
      ])->execute();
    }
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn('42');
    $application->method('uuid')->willReturn('synthetic-application');
    $application->method('getData')->willReturn('{}');
    $application->method('access')->willReturn(TRUE);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(0);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('2');
    $group->method('bundle')->willReturn('jur');
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturn(TRUE);
    $field->method('__get')->with('entity')->willReturn($group);
    $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
    $application->method('get')->with('field_jurisdiction')->willReturn($field);
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getDisplayName')->willReturn('Current approver');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('7')->willReturn($user);
    $storage->expects($this->once())->method('loadMultiple')->with([7])->willReturn([7 => $user]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('user')->willReturn($storage);
    $scope = new ScopeAccess($manager);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturn(TRUE);
    $logs = new DeliveryLogRepository($database, $this->container->get('uuid'), $this->container->get('datetime.time'), new NullLogger());
    // Only the read-only, form-missing branch of explain is exercised here;
    // full planning/locking has its own SQL and mocked-HTTP kernel tests.
    $planner = (new \ReflectionClass(TransferPlanner::class))->newInstanceWithoutConstructor();
    // The optional gate composite stays NULL: no gate equals today's behavior.
    foreach (['logs' => $logs, 'scopeAccess' => $scope, 'gates' => NULL] as $name => $value) {
      (new \ReflectionProperty($planner, $name))->setValue($planner, $value);
    }
    $controller = new TransferController($planner, $scope, $account, $this->createMock(CsrfTokenGenerator::class),
      new IntegrationAudit(new NullLogger(), $this->container->get('datetime.time'), $account), $manager, $this->repository);
    $response = $controller->listTransfers($application);
    self::assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $rows = array_column($data['data'], NULL, 'uuid');
    self::assertTrue($rows['transfer-1']['foreign_scope']);
    self::assertArrayNotHasKey('approval', $rows['transfer-1']);
    self::assertArrayNotHasKey('connection', $rows['transfer-1']);
    self::assertArrayNotHasKey('external_responses', $rows['transfer-1']);
    self::assertSame('Current approver', $rows['transfer-2']['approval']['display_name']);
    self::assertSame('Current approver', $rows['transfer-3']['approval']['display_name']);
    self::assertSame('current_configuration', $data['meta']['delivery']['applicability']);
    self::assertFalse($data['meta']['canCreateTransfer']);
    self::assertStringNotContainsString('PRIVATE_', $response->getContent());
  }

}
