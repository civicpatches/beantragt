<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\TransferPayloadRepository;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests erasure, orphan repair and commit-boundary planning with real SQL.
 */
#[Group('beantragt_delivery')]
final class TransferLifecycleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Protected storage.
   */
  private TransferPayloadRepository $payloads;

  /**
   * Outbox storage.
   */
  private DeliveryLogRepository $logs;

  /**
   * Original environment value.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.module';
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('s', 32));
    $database = $this->container->get('database');
    $database->schema()->createTable('beantragt_delivery_log', beantragt_delivery_schema()['beantragt_delivery_log']);
    $database->schema()->createTable('beantragt_transfer_audit', beantragt_delivery_schema()['beantragt_transfer_audit']);
    $database->schema()->createTable('beantragt_transfer_payload', TransferPayloadRepository::schema()['beantragt_transfer_payload']);
    // A different base table proves cleanup follows entity metadata.
    $database->schema()->createTable('synthetic_application', [
      'fields' => ['id' => ['type' => 'int', 'not null' => TRUE], 'field_data' => ['type' => 'text']],
      'primary key' => ['id'],
    ]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(200000);
    $time->method('getRequestTime')->willReturn(200000);
    $this->logs = new DeliveryLogRepository($database, $this->container->get('uuid'), $time, new NullLogger());
    $this->payloads = new TransferPayloadRepository($database,
      new ApplicationDataProtectionService($this->container->get('entity_type.manager'), new NullLogger()),
      $this->container->get('uuid'), $time);
    $this->container->set('beantragt_delivery.transfer_payload_repository', $this->payloads);
    $this->container->set('beantragt_delivery.log_repository', $this->logs);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * Insert/confirmation callbacks wait for commit and disappear on rollback.
   */
  public function testPlanningRunsAfterCommitOnly(): void {
    $this->registerRecoveryBoundary();
    $application = $this->application(FALSE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(1)->willReturn($application);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('application')->willReturn($storage);
    $this->container->set('entity_type.manager', $entities);
    $dispatcher = new class() {

      /**
       * Transaction states observed by the dispatcher entry point.
       */
      public array $transactions = [];

      /**
       * Records the callback boundary without planning or transport.
       */
      public function autoEnqueue(ApplicationInterface $application): ?int {
        $this->transactions[] = \Drupal::database()->inTransaction();
        return NULL;
      }

    };
    $this->container->set('beantragt_delivery.dispatcher', $dispatcher);
    $transaction = $this->container->get('database')->startTransaction();
    beantragt_delivery_application_insert($application);
    $this->assertSame([], $dispatcher->transactions);
    unset($transaction);
    $this->assertSame([FALSE], $dispatcher->transactions);
    $transaction = $this->container->get('database')->startTransaction();
    beantragt_delivery_beantragt_application_confirmed($application);
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([FALSE], $dispatcher->transactions);
    beantragt_delivery_beantragt_application_confirmed($application);
    $this->assertSame([FALSE, FALSE], $dispatcher->transactions);
  }

  /**
   * A post-save planning exception records a safe case diagnostic.
   */
  public function testPlanningFailureRecordsDiagnostic(): void {
    $this->registerRecoveryBoundary();
    $application = $this->application(FALSE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($application);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturn($storage);
    $this->container->set('entity_type.manager', $entities);
    $this->container->set('beantragt_delivery.dispatcher', new class() {

      /**
       * Represents an internal exception containing data unsuitable for logs.
       */
      public function autoEnqueue(ApplicationInterface $application): void {
        throw new \RuntimeException('Synthetic private exception detail');
      }

    });
    $planner = new class() {

      /**
       * The diagnosed case identifiers.
       */
      public array $ids = [];

      /**
       * Records the diagnostic without network or queue work.
       */
      public function recordPlanningFailure(ApplicationInterface $application): void {
        $this->ids[] = $application->id();
      }

    };
    $this->container->set('beantragt_delivery.transfer_planner', $planner);
    beantragt_delivery_application_insert($application);
    $this->assertSame([1], $planner->ids);
  }

  /**
   * Only a committed staff data edit fails waiting transfers.
   */
  public function testDataEditInvalidatesWaitingTransfersAfterCommitOnly(): void {
    $edited = $this->edited('{"name":"before"}', '{"name":"after"}', ['data_edited']);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(1)->willReturn($edited);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('application')->willReturn($storage);
    $this->container->set('entity_type.manager', $entities);
    $planner = new class() {

      /**
       * Transaction state and acting user per invalidation call.
       */
      public array $calls = [];

      /**
       * Records the boundary without touching transfer storage.
       */
      public function invalidateChangedData(ApplicationInterface $application, int $uid): array {
        $this->calls[] = [\Drupal::database()->inTransaction(), $uid];
        return [];
      }

    };
    $this->container->set('beantragt_delivery.transfer_planner', $planner);
    $transaction = $this->container->get('database')->startTransaction();
    beantragt_delivery_application_update($edited);
    $this->assertSame([], $planner->calls);
    unset($transaction);
    $this->assertSame([[FALSE, 9]], $planner->calls);
    $transaction = $this->container->get('database')->startTransaction();
    beantragt_delivery_application_update($edited);
    $transaction->rollBack();
    unset($transaction);
    $this->assertCount(1, $planner->calls, 'A rolled back edit changes nothing.');
    // Status changes and edits without a data difference keep the transfer.
    beantragt_delivery_application_update($this->edited('{"name":"before"}', '{"name":"after"}', ['status_changed']));
    beantragt_delivery_application_update($this->edited('{"name":"same"}', '{"name":"same"}', ['data_edited']));
    $this->assertCount(1, $planner->calls);
  }

  /**
   * Builds an updated application with its original and revision events.
   */
  private function edited(string $before, string $after, array $events): ApplicationInterface {
    $original = $this->createMock(ApplicationInterface::class);
    $original->method('getData')->willReturn($before);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('getData')->willReturn($after);
    $application->method('getOriginal')->willReturn($original);
    $application->method('getRevisionUserId')->willReturn(9);
    $application->method('getRevisionLogMessage')->willReturn(json_encode([
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [],
      'events' => $events,
      'message' => '',
    ], JSON_THROW_ON_ERROR));
    return $application;
  }

  /**
   * Controlled entity boundary; production hooks and repositories remain real.
   */
  private function application(bool $anonymized): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('uuid')->willReturn('synthetic-application');
    $application->method('getData')->willReturn($anonymized ? '{"_anonymized":true}' : '{}');
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    return $application;
  }

  /**
   * Isolates transaction callbacks; real recovery has its own kernel proof.
   */
  private function registerRecoveryBoundary(): void {
    $this->container->set('beantragt_delivery.planning_recovery', new class() {

      /**
       * Records intent without involving the independent planner fixture.
       */
      public function record(ApplicationInterface $application): void {
      }

      /**
       * Completes intent without involving the independent planner fixture.
       */
      public function complete(int $id): void {
      }

    });
  }

}
