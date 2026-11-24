<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\DeliveryLogNotFoundException;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\DeliveryPolicyResolver;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests form-level delivery decisions in the dispatcher.
 */
#[CoversClass(DeliveryDispatcher::class)]
#[Group('beantragt_delivery')]
final class DeliveryDispatcherTest extends UnitTestCase {

  /**
   * Self-contained policy suppresses automatic delivery even if global is on.
   */
  public function testAutoEnqueueSelfContainedPolicySuppressesGlobalAutoEnqueue(): void {
    $dispatcher = $this->dispatcher([
      'enabled' => TRUE,
      'auto_enqueue' => TRUE,
      'default_adapter' => 'mock',
    ]);

    $result = $dispatcher->autoEnqueue($this->applicationWithSchema([
      'delivery' => [],
    ]));

    $this->assertNull($result);
  }

  /**
   * Missing policy keeps the legacy global auto-enqueue switch.
   */
  public function testAutoEnqueueAbsentPolicyHonorsDisabledGlobalAutoEnqueue(): void {
    $dispatcher = $this->dispatcher([
      'enabled' => TRUE,
      'auto_enqueue' => FALSE,
      'default_adapter' => 'mock',
    ]);

    $result = $dispatcher->autoEnqueue($this->applicationWithSchema([
      'sections' => [],
    ]));

    $this->assertNull($result);
  }

  /**
   * Form-level FIT-Connect policies cannot create new legacy transfers.
   */
  public function testResolveAdapterUsesFormPolicyWhenExplicitAdapterAbsent(): void {
    $dispatcher = $this->dispatcher([
      'enabled' => TRUE,
      'auto_enqueue' => TRUE,
      'default_adapter' => 'mock',
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->resolveAdapter($dispatcher, $this->applicationWithSchema([
      'delivery' => [
        'processingMode' => 'pass_through',
        'adapter' => 'fit_connect',
      ],
    ]));
  }

  /**
   * Old FIT policies and global settings create neither rows nor queue items.
   */
  #[DataProvider('legacyFitPolicies')]
  public function testLegacyFitSelectionNeverEnqueues(string $default, array $schema): void {
    $dispatcher = $this->dispatcher([
      'enabled' => TRUE,
      'auto_enqueue' => TRUE,
      'default_adapter' => $default,
    ]);
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('insert');
    $this->setPrivateProperty($dispatcher, 'logRepository', new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    ));
    $queue = $this->createMock(QueueFactory::class);
    $queue->expects($this->never())->method('get');
    $this->setPrivateProperty($dispatcher, 'queueFactory', $queue);
    $application = $this->applicationWithSchema($schema);
    self::assertNull($dispatcher->autoEnqueue($application));
    try {
      $dispatcher->enqueue($application, 'fit_connect');
      self::fail('Explicit FIT-Connect must not bypass assignment planning.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertSame('FIT-Connect transfers require an active integration assignment.', $exception->getMessage());
    }
  }

  /**
   * Includes legacy form policy even when a different global fallback exists.
   */
  public static function legacyFitPolicies(): array {
    return [
      'global FIT without policy' => ['fit_connect', ['sections' => []]],
      'global FIT inherited by pass-through' => ['fit_connect', ['delivery' => ['processingMode' => 'pass_through']]],
      'form FIT with mock global' => [
        'mock',
        ['delivery' => ['processingMode' => 'pass_through', 'adapter' => 'fit_connect']],
      ],
      'form FIT with webhook global' => [
        'webhook',
        ['delivery' => ['processingMode' => 'pass_through', 'adapter' => 'fit_connect']],
      ],
    ];
  }

  /**
   * Unassigned legacy delivery resolution retains only local/mock and webhook.
   */
  public function testUnassignedFallbackAdaptersRemainAvailable(): void {
    foreach (['mock', 'webhook'] as $adapter) {
      $dispatcher = $this->dispatcher(['default_adapter' => $adapter]);
      self::assertSame($adapter, $this->resolveAdapter($dispatcher, $this->applicationWithSchema(['sections' => []])));
    }
  }

  /**
   * Explicit adapter requests still win over form-level policy.
   */
  public function testResolveAdapterExplicitAdapterWins(): void {
    $dispatcher = $this->dispatcher([
      'enabled' => TRUE,
      'auto_enqueue' => TRUE,
      'default_adapter' => 'mock',
    ]);

    $adapter = $this->resolveAdapter(
      $dispatcher,
      $this->applicationWithSchema([
        'delivery' => [
          'processingMode' => 'pass_through',
          'adapter' => 'fit_connect',
        ],
      ]),
      'mock',
    );

    $this->assertSame('mock', $adapter);
  }

  /**
   * A queue item whose log was removed with its application is terminal.
   */
  public function testDispatchLogSignalsDeletedLog(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->with('beantragt_delivery_log', 'd')->willReturn($select);
    $repository = new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $reflection = new \ReflectionClass(DeliveryDispatcher::class);
    $dispatcher = $reflection->newInstanceWithoutConstructor();
    $this->setPrivateProperty($dispatcher, 'logRepository', $repository);

    $this->expectException(DeliveryLogNotFoundException::class);
    $dispatcher->dispatchLog(91);
  }

  /**
   * A log removed while waiting for the application lock is never delivered.
   */
  public function testDispatchLogRechecksLogUnderApplicationLock(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturnOnConsecutiveCalls([
      'id' => 91,
      'application_id' => 165,
      'status' => DeliveryLogRepository::STATUS_QUEUED,
      'adapter' => 'mock',
    ], FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')->with('beantragt_delivery_log', 'd')->willReturn($select);
    $repository = new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.delete.165', 300.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_application.delete.165');

    $reflection = new \ReflectionClass(DeliveryDispatcher::class);
    $dispatcher = $reflection->newInstanceWithoutConstructor();
    $this->setPrivateProperty($dispatcher, 'logRepository', $repository);
    $this->setPrivateProperty($dispatcher, 'lock', $lock);

    $this->expectException(DeliveryLogNotFoundException::class);
    $dispatcher->dispatchLog(91);
  }

  /**
   * Application reads under the shared lock bypass stale request cache entries.
   */
  public function testLoadApplicationResetsEntityCache(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('resetCache')->with([165]);
    $storage->expects($this->once())->method('load')->with(165)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->once())->method('getStorage')->with('application')->willReturn($storage);

    $reflection = new \ReflectionClass(DeliveryDispatcher::class);
    $dispatcher = $reflection->newInstanceWithoutConstructor();
    $this->setPrivateProperty($dispatcher, 'entityTypeManager', $manager);

    $method = new \ReflectionMethod(DeliveryDispatcher::class, 'loadApplication');
    $method->setAccessible(TRUE);

    $this->assertSame($application, $method->invoke($dispatcher, 165));
  }

  /**
   * A processing FIT log is never reclaimed or overwritten by another worker.
   */
  public function testProcessingFitConnectLogRemainsUntouched(): void {
    $dispatcher = $this->dispatcherForFitConnectClaim(DeliveryLogRepository::STATUS_PROCESSING);
    $result = $dispatcher->dispatchLog(91);
    $this->assertSame('fit_connect.submission_already_started', $result->errorCode);
    $this->assertFalse($dispatcher->shouldRetryLog(91, $result));
  }

  /**
   * Approval rows are structurally unsendable before any claim or gateway call.
   */
  public function testAwaitingApprovalNeverClaimsOrDispatches(): void {
    $dispatcher = $this->dispatcherForFitConnectClaim(DeliveryLogRepository::STATUS_AWAITING_APPROVAL);
    $result = $dispatcher->dispatchLog(91);
    self::assertSame('integration.dispatch_approval_required', $result->errorCode);
    self::assertFalse($dispatcher->shouldRetryLog(91, $result));
  }

  /**
   * Losing the atomic claim cannot dispatch or overwrite the winning worker.
   */
  public function testLostFitConnectClaimDoesNotWriteResult(): void {
    $dispatcher = $this->dispatcherForFitConnectClaim(DeliveryLogRepository::STATUS_QUEUED);
    $result = $dispatcher->dispatchLog(91);
    $this->assertSame('fit_connect.submission_already_started', $result->errorCode);
    $this->assertFalse($dispatcher->shouldRetryLog(91, $result));
  }

  /**
   * Constructs a real repository with strict claim and result-write boundaries.
   */
  private function dispatcherForFitConnectClaim(string $status): DeliveryDispatcher {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->exactly(2))->method('fetchAssoc')->willReturn([
      'id' => 91,
      'application_id' => 165,
      'status' => $status,
      'adapter' => 'fit_connect',
      'attempts' => 0,
    ]);
    $select = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'range'] as $method) {
      $select->method($method)->willReturnSelf();
    }
    $select->method('execute')->willReturn($statement);
    $database = $this->createMock(Connection::class);
    $database->method('select')->with('beantragt_delivery_log', 'd')->willReturn($select);
    $processing = in_array($status, [
      DeliveryLogRepository::STATUS_PROCESSING,
      DeliveryLogRepository::STATUS_AWAITING_APPROVAL,
    ], TRUE);
    if ($processing) {
      $database->expects($this->never())->method('update');
    }
    else {
      $update = $this->createMock(Update::class);
      $update->expects($this->once())->method('expression')->with('automatic_attempts', 'automatic_attempts + 1')->willReturnSelf();
      $update->expects($this->once())->method('fields')->with([
        'status' => DeliveryLogRepository::STATUS_PROCESSING,
        'attempts' => 1,
        'changed' => 0,
        'processed' => 0,
        'last_error' => '',
      ])->willReturnSelf();
      $conditions = [];
      $update->expects($this->exactly(3))->method('condition')->willReturnCallback(
        function ($field, $value, $operator = NULL) use (&$conditions, $update) {
          $conditions[] = [$field, $value, $operator];
          return $update;
        },
      );
      $update->expects($this->once())->method('execute')->willReturnCallback(function () use (&$conditions) {
        $this->assertSame([
          ['id', 91, '='],
          ['adapter', 'fit_connect', '='],
          ['status', [DeliveryLogRepository::STATUS_QUEUED, DeliveryLogRepository::STATUS_FAILED], 'IN'],
        ], $conditions);
        return 0;
      });
      // Exactly one failed conditional UPDATE, never a subsequent markResult.
      $database->expects($this->once())->method('update')->with('beantragt_delivery_log')->willReturn($update);
    }
    $repository = new DeliveryLogRepository(
      $database,
      $this->createMock(UuidInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->with('beantragt_application.delete.165', 300.0)->willReturn(TRUE);
    $lock->expects($this->once())->method('release')->with('beantragt_application.delete.165');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->never())->method('resetCache')->with([165]);
    $storage->expects($this->never())->method('load')->with(165)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage')->with('application')->willReturn($storage);
    $reflection = new \ReflectionClass(DeliveryDispatcher::class);
    $dispatcher = $reflection->newInstanceWithoutConstructor();
    $this->setPrivateProperty($dispatcher, 'logRepository', $repository);
    $this->setPrivateProperty($dispatcher, 'lock', $lock);
    $this->setPrivateProperty($dispatcher, 'entityTypeManager', $manager);
    return $dispatcher;
  }

  /**
   * A started remote submission requires reconciliation and leaves the queue.
   */
  public function testStartedFitConnectSubmissionIsNotRetried(): void {
    $dispatcher = $this->dispatcher(['max_attempts' => 10]);
    $result = DeliveryResult::failed(
      'fit_connect.submission_already_started',
      'A previous submission attempt requires reconciliation.',
    );
    $this->assertFalse($dispatcher->shouldRetryLog(91, $result));
  }

  /**
   * Creates a dispatcher with only the dependencies needed by these branches.
   *
   * @param array<string, mixed> $settings
   *   Delivery settings.
   */
  private function dispatcher(array $settings): DeliveryDispatcher {
    $reflection = new \ReflectionClass(DeliveryDispatcher::class);
    $dispatcher = $reflection->newInstanceWithoutConstructor();
    $this->setPrivateProperty($dispatcher, 'configFactory', $this->configFactory($settings));
    $this->setPrivateProperty($dispatcher, 'policyResolver', new DeliveryPolicyResolver());
    $this->setPrivateProperty($dispatcher, 'transferPlanner', NULL);
    return $dispatcher;
  }

  /**
   * Calls the private adapter resolver.
   */
  private function resolveAdapter(
    DeliveryDispatcher $dispatcher,
    ApplicationInterface $application,
    ?string $adapter = NULL,
  ): string {
    $reflection = new \ReflectionMethod(DeliveryDispatcher::class, 'resolveAdapter');
    $reflection->setAccessible(TRUE);
    return $reflection->invoke($dispatcher, $application, $adapter);
  }

  /**
   * Sets an uninitialized private readonly property on the reflected instance.
   */
  private function setPrivateProperty(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue($object, $value);
  }

  /**
   * Builds a config factory for delivery settings.
   *
   * @param array<string, mixed> $settings
   *   Settings values.
   */
  private function configFactory(array $settings): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => $settings[$key] ?? NULL,
    );

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_delivery.settings')
      ->willReturn($config);
    return $factory;
  }

  /**
   * Builds an application mock with a JSON schema array.
   *
   * @param array<string, mixed> $schema_data
   *   Schema fixture.
   */
  private function applicationWithSchema(array $schema_data): ApplicationInterface {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('getSchema')->willReturn(json_encode($schema_data, JSON_THROW_ON_ERROR));

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'entity' ? $schema : NULL,
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('hasField')->with('field_schema')->willReturn(TRUE);
    $application->method('get')->with('field_schema')->willReturn($field);
    return $application;
  }

}
