<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EmailConfirmationTokenService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests email confirmation token behavior.
 */
#[CoversClass(EmailConfirmationTokenService::class)]
#[Group('beantragt_application')]
final class EmailConfirmationTokenServiceTest extends UnitTestCase {

  /**
   * Pending create values store only the token hash.
   */
  public function testPendingCreateValuesHashTokenAtRest(): void {
    $service = $this->service();
    $pending = $service->pendingCreateValues();

    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{64}$/', $pending['token']);
    $this->assertFalse($pending['values']['field_email_confirmed']);
    $this->assertSame(hash('sha256', $pending['token']), $pending['values']['field_email_confirmation_token_hash']);
    $this->assertSame(1781431200 + (72 * 3600), $pending['values']['field_email_confirmation_expires']);
    $this->assertStringNotContainsString($pending['token'], json_encode($pending['values'], JSON_THROW_ON_ERROR));
  }

  /**
   * Claiming is atomic: one update wins and the replay is already-confirmed.
   */
  public function testClaimIsSingleUse(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);

    $token = str_repeat('A', 64);
    $hash = hash('sha256', $token);
    $service = new class($this->dependencies(), $hash, $application) extends EmailConfirmationTokenService {

      /**
       * Number of token reservation attempts.
       */
      public int $reserveCalls = 0;

      /**
       * Constructs the token service test double.
       */
      public function __construct(
        array $dependencies,
        private readonly string $expectedHash,
        private readonly ApplicationInterface $application,
      ) {
        parent::__construct(...$dependencies);
      }

      /**
       * {@inheritdoc}
       */
      protected function reserveTokenHash(string $hash, int $now): int {
        if ($hash !== $this->expectedHash) {
          return 0;
        }
        $this->reserveCalls++;
        return $this->reserveCalls === 1 ? 1 : 0;
      }

      /**
       * {@inheritdoc}
       */
      protected function loadConfirmedApplicationByTokenHash(string $hash, int $now): ?ApplicationInterface {
        return $hash === $this->expectedHash ? $this->application : NULL;
      }

      /**
       * {@inheritdoc}
       */
      protected function dispatchConfirmed(ApplicationInterface $application): void {}

    };

    $first = $service->claim($token);
    $second = $service->claim($token);

    $this->assertSame(EmailConfirmationTokenService::STATUS_CONFIRMED, $first['status']);
    $this->assertSame(EmailConfirmationTokenService::STATUS_ALREADY_CONFIRMED, $second['status']);
    $this->assertSame(2, $service->reserveCalls);
  }

  /**
   * An unclaimable token without a confirmed match is invalid.
   */
  public function testExpiredTokenIsInvalid(): void {
    $service = new class(...$this->dependencies()) extends EmailConfirmationTokenService {

      /**
       * {@inheritdoc}
       */
      protected function reserveTokenHash(string $hash, int $now): int {
        return 0;
      }

      /**
       * {@inheritdoc}
       */
      protected function loadConfirmedApplicationByTokenHash(string $hash, int $now): ?ApplicationInterface {
        return NULL;
      }

    };

    $result = $service->claim(str_repeat('B', 64));

    $this->assertSame(EmailConfirmationTokenService::STATUS_INVALID, $result['status']);
  }

  /**
   * Successful token reservation mirrors revisionable fields.
   */
  public function testReserveTokenHashMirrorsCurrentRevision(): void {
    $hash = hash('sha256', str_repeat('C', 64));
    $database = $this->databaseForRawWriteTest(
      [
        [
          'table' => 'application',
          'fields' => [
            'field_email_confirmed' => 1,
            'field_email_confirmed_time' => 1781431200,
            'changed' => 1781431200,
          ],
          'conditions' => [
            ['field_email_confirmation_token_hash', $hash, '='],
            ['field_email_confirmed', 0, '='],
            ['field_email_confirmation_expires', 1781431200, '>='],
          ],
          'result' => 1,
        ],
        [
          'table' => 'application_revision',
          'fields' => [
            'field_email_confirmed' => 1,
            'changed' => 1781431200,
          ],
          'conditions' => [
            ['id', 42, '='],
            ['revision_id', 84, '='],
          ],
          'result' => 1,
        ],
      ],
      [
        'conditions' => [
          ['field_email_confirmation_token_hash', $hash, '='],
        ],
        'record' => [
          'id' => 42,
          'revision_id' => 84,
        ],
      ],
      'email_confirmation_claim',
    );
    $entity_type_manager = $this->entityTypeManagerExpectingReset(42);

    $service = new class(
      $entity_type_manager,
      $database,
      $this->configFactory(),
      $this->time(),
      $this->createMock(LoggerInterface::class),
    ) extends EmailConfirmationTokenService {

      /**
       * Exposes token reservation for testing.
       */
      public function reserve(string $hash, int $now): int {
        return $this->reserveTokenHash($hash, $now);
      }

    };

    $this->assertSame(1, $service->reserve($hash, 1781431200));
  }

  /**
   * Failed revision mirrors roll back the base claim and skip dispatch.
   */
  public function testClaimRollsBackWhenRevisionMirrorFails(): void {
    $token = str_repeat('D', 64);
    $hash = hash('sha256', $token);
    $base_confirmed = FALSE;

    $transaction = new class(static function () use (&$base_confirmed): void {
      $base_confirmed = FALSE;
    }) extends Transaction {

      /**
       * Number of rollbacks.
       */
      public int $rollBackCalls = 0;

      /**
       * Constructs the transaction test double.
       */
      public function __construct(
        private readonly \Closure $onRollBack,
      ) {}

      /**
       * Prevents the parent destructor from accessing core transaction state.
       */
      public function __destruct() {}

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        $this->rollBackCalls++;
        ($this->onRollBack)();
      }

    };

    $base_update = $this->updateQueryWithExecuteCallback(
      [
        'field_email_confirmed' => 1,
        'field_email_confirmed_time' => 1781431200,
        'changed' => 1781431200,
      ],
      [
        ['field_email_confirmation_token_hash', $hash, '='],
        ['field_email_confirmed', 0, '='],
        ['field_email_confirmation_expires', 1781431200, '>='],
      ],
      static function () use (&$base_confirmed): int {
        $base_confirmed = TRUE;
        return 1;
      },
    );
    $revision_update = $this->updateQueryWithExecuteCallback(
      [
        'field_email_confirmed' => 1,
        'changed' => 1781431200,
      ],
      [
        ['id', 42, '='],
        ['revision_id', 84, '='],
      ],
      static function (): int {
        throw new \RuntimeException('revision deadlock');
      },
    );

    $update_call = 0;
    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('startTransaction')
      ->with('email_confirmation_claim')
      ->willReturn($transaction);
    $database->expects($this->exactly(2))
      ->method('update')
      ->willReturnCallback(static function (string $table) use (&$update_call, $base_update, $revision_update): Update {
        self::assertSame($update_call === 0 ? 'application' : 'application_revision', $table);
        $update_call++;
        return $update_call === 1 ? $base_update : $revision_update;
      });
    $database->expects($this->once())
      ->method('select')
      ->with('application', 'a')
      ->willReturn($this->selectQuery(
        [
          ['field_email_confirmation_token_hash', $hash, '='],
        ],
        [
          'id' => 42,
          'revision_id' => 84,
        ],
      ));
    $database->method('schema')->willReturn($this->revisionSchema());

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')
      ->willReturnCallback(static fn(): bool => $base_confirmed);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $service = new class(
      $entity_type_manager,
      $database,
      $this->configFactory(),
      $this->time(),
      $logger,
      $hash,
      $application,
    ) extends EmailConfirmationTokenService {

      /**
       * Number of confirmed dispatch calls.
       */
      public int $dispatchCalls = 0;

      /**
       * Constructs the claim rollback test double.
       */
      public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        Connection $database,
        ConfigFactoryInterface $config_factory,
        TimeInterface $time,
        LoggerInterface $logger,
        private readonly string $expectedHash,
        private readonly ApplicationInterface $application,
      ) {
        parent::__construct(
          $entity_type_manager,
          $database,
          $config_factory,
          $time,
          $logger,
          NULL,
          NULL,
        );
      }

      /**
       * {@inheritdoc}
       */
      protected function loadConfirmedApplicationByTokenHash(string $hash, int $now): ?ApplicationInterface {
        return $hash === $this->expectedHash && $this->application->isEmailConfirmed() ? $this->application : NULL;
      }

      /**
       * {@inheritdoc}
       */
      protected function dispatchConfirmed(ApplicationInterface $application): void {
        $this->dispatchCalls++;
      }

    };

    $result = $service->claim($token);

    $this->assertSame(EmailConfirmationTokenService::STATUS_INVALID, $result['status']);
    $this->assertSame(0, $service->dispatchCalls);
    $this->assertSame(1, $transaction->rollBackCalls);
    $this->assertFalse($base_confirmed);
  }

  /**
   * Silent revision mirror misses roll back the base claim.
   */
  public function testReserveTokenHashRollsBackWhenRevisionMirrorUpdatesNoRows(): void {
    $hash = hash('sha256', str_repeat('E', 64));
    $transaction = new class() extends Transaction {

      /**
       * Number of rollbacks.
       */
      public int $rollBackCalls = 0;

      /**
       * Prevents the parent constructor from touching core state.
       */
      public function __construct() {}

      /**
       * Prevents the parent destructor from accessing core transaction state.
       */
      public function __destruct() {}

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        $this->rollBackCalls++;
      }

    };

    $updates = [
      $this->updateQuery(
        [
          'field_email_confirmed' => 1,
          'field_email_confirmed_time' => 1781431200,
          'changed' => 1781431200,
        ],
        [
          ['field_email_confirmation_token_hash', $hash, '='],
          ['field_email_confirmed', 0, '='],
          ['field_email_confirmation_expires', 1781431200, '>='],
        ],
        1,
      ),
      $this->updateQuery(
        [
          'field_email_confirmed' => 1,
          'changed' => 1781431200,
        ],
        [
          ['id', 42, '='],
          ['revision_id', 84, '='],
        ],
        0,
      ),
    ];
    $update_call = 0;

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('startTransaction')
      ->with('email_confirmation_claim')
      ->willReturn($transaction);
    $database->expects($this->exactly(2))
      ->method('update')
      ->willReturnCallback(static function (string $table) use (&$update_call, $updates): Update {
        self::assertSame($update_call === 0 ? 'application' : 'application_revision', $table);
        return $updates[$update_call++];
      });
    $database->expects($this->once())
      ->method('select')
      ->with('application', 'a')
      ->willReturn($this->selectQuery(
        [
          ['field_email_confirmation_token_hash', $hash, '='],
        ],
        [
          'id' => 42,
          'revision_id' => 84,
        ],
      ));
    $database->method('schema')->willReturn($this->revisionSchema());

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $service = new class(
      $entity_type_manager,
      $database,
      $this->configFactory(),
      $this->time(),
      $logger,
    ) extends EmailConfirmationTokenService {

      /**
       * Exposes token reservation for testing.
       */
      public function reserve(string $hash, int $now): int {
        return $this->reserveTokenHash($hash, $now);
      }

    };

    $this->assertSame(0, $service->reserve($hash, 1781431200));
    $this->assertSame(1, $transaction->rollBackCalls);
  }

  /**
   * Multi-row claim updates roll back instead of consuming duplicate tokens.
   */
  public function testReserveTokenHashRollsBackWhenClaimMatchesMultipleRows(): void {
    $hash = hash('sha256', str_repeat('F', 64));
    $transaction = new class() extends Transaction {

      /**
       * Number of rollbacks.
       */
      public int $rollBackCalls = 0;

      /**
       * Prevents the parent constructor from touching core state.
       */
      public function __construct() {}

      /**
       * Prevents the parent destructor from accessing core transaction state.
       */
      public function __destruct() {}

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        $this->rollBackCalls++;
      }

    };

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())
      ->method('startTransaction')
      ->with('email_confirmation_claim')
      ->willReturn($transaction);
    $database->expects($this->once())
      ->method('update')
      ->with('application')
      ->willReturn($this->updateQuery(
        [
          'field_email_confirmed' => 1,
          'field_email_confirmed_time' => 1781431200,
          'changed' => 1781431200,
        ],
        [
          ['field_email_confirmation_token_hash', $hash, '='],
          ['field_email_confirmed', 0, '='],
          ['field_email_confirmation_expires', 1781431200, '>='],
        ],
        2,
      ));
    $database->expects($this->never())->method('select');
    $database->expects($this->never())->method('schema');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $service = new class(
      $entity_type_manager,
      $database,
      $this->configFactory(),
      $this->time(),
      $logger,
    ) extends EmailConfirmationTokenService {

      /**
       * Exposes token reservation for testing.
       */
      public function reserve(string $hash, int $now): int {
        return $this->reserveTokenHash($hash, $now);
      }

    };

    $this->assertSame(0, $service->reserve($hash, 1781431200));
    $this->assertSame(1, $transaction->rollBackCalls);
  }

  /**
   * Replacing a token keeps revisionable changed time in sync.
   */
  public function testReplaceTokenHashMirrorsCurrentRevisionChangedTime(): void {
    $database = $this->databaseForRawWriteTest(
      [
        [
          'table' => 'application',
          'fields' => [
            'field_email_confirmation_token_hash' => 'new-hash',
            'field_email_confirmation_expires' => 1781690400,
            'changed' => 1781431200,
          ],
          'conditions' => [
            ['id', 77, '='],
            ['field_email_confirmed', 0, '='],
          ],
          'result' => 1,
        ],
        [
          'table' => 'application_revision',
          'fields' => [
            'changed' => 1781431200,
          ],
          'conditions' => [
            ['id', 77, '='],
            ['revision_id', 88, '='],
          ],
          'result' => 1,
        ],
      ],
      [
        'conditions' => [
          ['id', 77, '='],
        ],
        'record' => [
          'id' => 77,
          'revision_id' => 88,
        ],
      ],
      'email_confirmation_replace',
    );
    $entity_type_manager = $this->entityTypeManagerExpectingReset(77);

    $service = new class(
      $entity_type_manager,
      $database,
      $this->configFactory(),
      $this->time(),
      $this->createMock(LoggerInterface::class),
    ) extends EmailConfirmationTokenService {

      /**
       * Exposes token replacement for testing.
       */
      public function replace(int $applicationId, string $hash, int $expires): int {
        return $this->replaceTokenHash($applicationId, $hash, $expires);
      }

    };

    $this->assertSame(1, $service->replace(77, 'new-hash', 1781690400));
  }

  /**
   * Resend replaces the active token hash.
   */
  public function testResendInvalidatesPriorToken(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(77);
    $application->method('getApplicantEmail')->willReturn('ada@example.org');

    $service = new class($this->dependencies(), $application) extends EmailConfirmationTokenService {

      /**
       * Last stored token hash.
       */
      public ?string $storedHash = 'old-hash';

      /**
       * Constructs the token service test double.
       */
      public function __construct(
        array $dependencies,
        private readonly ApplicationInterface $application,
      ) {
        parent::__construct(...$dependencies);
      }

      /**
       * {@inheritdoc}
       */
      protected function issueToken(): array {
        return [
          'token' => 'new-token',
          'hash' => 'new-hash',
          'expires' => 1781690400,
        ];
      }

      /**
       * {@inheritdoc}
       */
      protected function loadUnconfirmedApplicationByReference(string $referenceNumber): ?ApplicationInterface {
        return $referenceNumber === 'BEA-2026-00001' ? $this->application : NULL;
      }

      /**
       * {@inheritdoc}
       */
      protected function replaceTokenHash(int $applicationId, string $hash, int $expires): int {
        $this->storedHash = $hash;
        return $applicationId === 77 && $expires === 1781690400 ? 1 : 0;
      }

      /**
       * {@inheritdoc}
       */
      public function confirmationFrontendUrl(string $token): ?string {
        return 'https://beantragt.example/confirm/' . $token;
      }

      /**
       * {@inheritdoc}
       */
      public function dispatchConfirmationRequest(
        ApplicationInterface $application,
        string $confirmationUrl,
        ?string $fallbackRecipient = NULL,
      ): void {
        Assert::assertSame('ada@example.org', $fallbackRecipient);
      }

    };

    $service->resend('BEA-2026-00001', 'ADA@example.org');

    $this->assertSame('new-hash', $service->storedHash);
  }

  /**
   * Lifecycle hook dispatch continues after one implementation fails.
   */
  public function testLifecycleHooksContinueAfterFailingImplementation(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $called = [];
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->expects($this->once())
      ->method('invokeAllWith')
      ->with('beantragt_application_confirmed', $this->isType('callable'))
      ->willReturnCallback(static function (string $hook, callable $callback) use (&$called): void {
        $callback(
          static function (): void {
            throw new \RuntimeException('boom');
          },
          'bad_module',
        );
        $callback(
          static function () use (&$called): void {
            $called[] = 'good_module';
          },
          'good_module',
        );
      });
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $service = new class(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
      $this->configFactory(),
      $this->time(),
      $logger,
      NULL,
      $module_handler,
    ) extends EmailConfirmationTokenService {

      /**
       * Exposes confirmed hook dispatch for testing.
       */
      public function fireConfirmed(ApplicationInterface $application): void {
        $this->dispatchConfirmed($application);
      }

    };

    $service->fireConfirmed($application);

    $this->assertSame(['good_module'], $called);
  }

  /**
   * Builds the service dependencies.
   *
   * @return array<int, object|null>
   *   Constructor arguments.
   */
  private function dependencies(): array {
    return [
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
      $this->configFactory(),
      $this->time(),
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
    ];
  }

  /**
   * Builds a normal service instance.
   */
  private function service(): EmailConfirmationTokenService {
    return new EmailConfirmationTokenService(...$this->dependencies());
  }

  /**
   * Builds a database mock for raw application write tests.
   *
   * @param array<int, array<string, mixed>> $updateExpectations
   *   Expected update calls.
   * @param array<string, mixed> $selectExpectation
   *   Expected select call.
   * @param string $transactionName
   *   Expected transaction name.
   */
  private function databaseForRawWriteTest(array $updateExpectations, array $selectExpectation, string $transactionName): Connection {
    $database = $this->createMock(Connection::class);
    $transaction = new class() extends Transaction {

      /**
       * Prevents the parent destructor from accessing core transaction state.
       */
      public function __construct() {}

      /**
       * Prevents the parent destructor from accessing core transaction state.
       */
      public function __destruct() {}

      /**
       * {@inheritdoc}
       */
      public function rollBack() {
        throw new \RuntimeException('Rollback should not be called.');
      }

    };
    $database->expects($this->once())
      ->method('startTransaction')
      ->with($transactionName)
      ->willReturn($transaction);

    $updates = [];
    foreach ($updateExpectations as $expectation) {
      $updates[] = $this->updateQuery(
        $expectation['fields'],
        $expectation['conditions'],
        $expectation['result'],
      );
    }
    $update_call = 0;
    $database->expects($this->exactly(count($updateExpectations)))
      ->method('update')
      ->willReturnCallback(static function (string $table) use (&$update_call, $updateExpectations, $updates): Update {
        self::assertSame($updateExpectations[$update_call]['table'], $table);
        return $updates[$update_call++];
      });

    $database->expects($this->once())
      ->method('select')
      ->with('application', 'a')
      ->willReturn($this->selectQuery($selectExpectation['conditions'], $selectExpectation['record']));

    $database->method('schema')->willReturn($this->revisionSchema());

    return $database;
  }

  /**
   * Builds an update query mock with field and condition assertions.
   *
   * @param array<string, mixed> $expectedFields
   *   Expected fields.
   * @param array<int, array{0: string, 1: mixed, 2: string}> $expectedConditions
   *   Expected conditions.
   * @param int $result
   *   Update result.
   */
  private function updateQuery(array $expectedFields, array $expectedConditions, int $result): Update {
    $query = $this->createMock(Update::class);
    $query->expects($this->once())
      ->method('fields')
      ->with($expectedFields)
      ->willReturnSelf();
    $condition_call = 0;
    $query->expects($this->exactly(count($expectedConditions)))
      ->method('condition')
      ->willReturnCallback(static function (string $field, mixed $value = NULL, string $operator = '=') use (&$condition_call, $expectedConditions, $query): Update {
        self::assertSame($expectedConditions[$condition_call], [$field, $value, $operator]);
        $condition_call++;
        return $query;
      });
    $query->expects($this->once())
      ->method('execute')
      ->willReturn($result);

    return $query;
  }

  /**
   * Builds an update query mock with a custom execute callback.
   *
   * @param array<string, mixed> $expectedFields
   *   Expected fields.
   * @param array<int, array{0: string, 1: mixed, 2: string}> $expectedConditions
   *   Expected conditions.
   * @param callable(): int $executeCallback
   *   Execute callback.
   */
  private function updateQueryWithExecuteCallback(array $expectedFields, array $expectedConditions, callable $executeCallback): Update {
    $query = $this->createMock(Update::class);
    $query->expects($this->once())
      ->method('fields')
      ->with($expectedFields)
      ->willReturnSelf();
    $condition_call = 0;
    $query->expects($this->exactly(count($expectedConditions)))
      ->method('condition')
      ->willReturnCallback(static function (string $field, mixed $value = NULL, string $operator = '=') use (&$condition_call, $expectedConditions, $query): Update {
        self::assertSame($expectedConditions[$condition_call], [$field, $value, $operator]);
        $condition_call++;
        return $query;
      });
    $query->expects($this->once())
      ->method('execute')
      ->willReturnCallback($executeCallback);

    return $query;
  }

  /**
   * Builds a select query mock with condition assertions.
   *
   * @param array<int, array{0: string, 1: mixed, 2: string}> $expectedConditions
   *   Expected conditions.
   * @param array<string, mixed> $record
   *   Returned database record.
   */
  private function selectQuery(array $expectedConditions, array $record): SelectInterface {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchAssoc')
      ->willReturn($record);

    $query = $this->createMock(SelectInterface::class);
    $query->expects($this->once())
      ->method('fields')
      ->with('a', ['id', 'revision_id'])
      ->willReturnSelf();
    $condition_call = 0;
    $query->expects($this->exactly(count($expectedConditions)))
      ->method('condition')
      ->willReturnCallback(static function (string $field, mixed $value = NULL, string $operator = '=') use (&$condition_call, $expectedConditions, $query): SelectInterface {
        self::assertSame($expectedConditions[$condition_call], [$field, $value, $operator]);
        $condition_call++;
        return $query;
      });
    $query->expects($this->once())
      ->method('range')
      ->with(0, 1)
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    return $query;
  }

  /**
   * Builds a revision schema mock for the current application schema.
   */
  private function revisionSchema(): Schema {
    $schema = $this->createMock(Schema::class);
    $schema->method('fieldExists')
      ->willReturnCallback(static fn(string $table, string $field): bool => $table === 'application_revision' && in_array($field, [
        'field_email_confirmed',
        'changed',
      ], TRUE));
    return $schema;
  }

  /**
   * Builds an entity type manager that expects a cache reset.
   */
  private function entityTypeManagerExpectingReset(int $applicationId): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('resetCache')
      ->with([$applicationId]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('application')
      ->willReturn($storage);

    return $entity_type_manager;
  }

  /**
   * Builds a fixed time service.
   */
  private function time(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1781431200);
    return $time;
  }

  /**
   * Builds config with confirmation defaults.
   */
  private function configFactory(): ConfigFactoryInterface {
    $application = $this->createMock(ImmutableConfig::class);
    $application->method('get')
      ->willReturnCallback(static fn(string $key): mixed => match ($key) {
        'confirmation_token_ttl_hours' => 72,
        'confirmation_purge_hours' => 168,
        default => NULL,
      });
    $group = $this->createMock(ImmutableConfig::class);
    $group->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $key === 'frontend_base_url' ? 'https://beantragt.example' : NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturnCallback(static fn(string $name): ImmutableConfig => $name === 'beantragt_group.settings' ? $group : $application);
    return $factory;
  }

}
