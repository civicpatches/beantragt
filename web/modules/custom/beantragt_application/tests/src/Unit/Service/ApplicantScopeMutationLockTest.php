<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests applicant and workspace scoped mutation locking.
 */
#[CoversClass(ApplicantScopeMutationLock::class)]
#[Group('beantragt_application')]
final class ApplicantScopeMutationLockTest extends UnitTestCase {

  /**
   * Cleanup locks both the jurisdiction and selected organisation.
   */
  public function testAcquireForEmailWorkspaceUsesOpaqueScopedLocks(): void {
    $acquired = [];
    $released = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturnCallback(
        static function (string $lock_name, float $timeout) use (&$acquired): bool {
          $acquired[] = $lock_name;
          return $timeout === 300.0;
        },
      );
    $lock->expects($this->exactly(2))
      ->method('release')
      ->willReturnCallback(
        static function (string $lock_name) use (&$released): void {
          $released[] = $lock_name;
        },
      );

    $data_protection = new ApplicationDataProtectionService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $service = new ApplicantScopeMutationLock($lock, $data_protection);
    $owner = new \stdClass();

    $this->assertTrue($service->acquireForEmailWorkspace(
      $owner,
      'ada@example.org',
      12,
      34,
    ));
    $service->release($owner);

    $this->assertCount(2, $acquired);
    $this->assertSame(array_reverse($acquired), $released);
    $this->assertStringContainsString('.jur.12', $acquired[0]);
    $this->assertStringContainsString('.org.34', $acquired[1]);
    $this->assertStringNotContainsString('ada@example.org', implode(' ', $acquired));
  }

  /**
   * An application move locks both its original and target workspaces.
   */
  public function testApplicationMoveLocksOriginalAndNewScopes(): void {
    $acquired = [];
    $released = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(4))
      ->method('acquire')
      ->willReturnCallback(
        static function (string $lock_name) use (&$acquired): bool {
          $acquired[] = $lock_name;
          return TRUE;
        },
      );
    $lock->expects($this->exactly(4))
      ->method('release')
      ->willReturnCallback(
        static function (string $lock_name) use (&$released): void {
          $released[] = $lock_name;
        },
      );

    $service = $this->createService($lock);
    $original = $this->createApplication('ada@example.org', 12, 34);
    $application = $this->createApplication('ada@example.org', 56, 78, $original);

    $this->assertTrue($service->acquireForApplication($application));
    $service->release($application);

    $this->assertSame(array_reverse($acquired), $released);
    $all_locks = implode(' ', $acquired);
    $this->assertStringContainsString('.jur.12', $all_locks);
    $this->assertStringContainsString('.org.34', $all_locks);
    $this->assertStringContainsString('.jur.56', $all_locks);
    $this->assertStringContainsString('.org.78', $all_locks);
  }

  /**
   * A second acquisition widens locks after presave scope normalization.
   */
  public function testApplicationReacquisitionAddsNormalizedScope(): void {
    $acquired = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturnCallback(
        static function (string $lock_name) use (&$acquired): bool {
          $acquired[] = $lock_name;
          return TRUE;
        },
      );
    $lock->expects($this->exactly(2))->method('release');

    $jurisdiction_id = 12;
    $fields = $this->scopeFields('ada@example.org', 0, 0);
    $fields['field_jurisdiction'] = $this->createMock(FieldItemListInterface::class);
    $fields['field_jurisdiction']->method('__isset')
      ->willReturnCallback(static fn(string $name): bool => $name === 'target_id');
    $fields['field_jurisdiction']->method('__get')
      ->willReturnCallback(
        static function (string $name) use (&$jurisdiction_id): mixed {
          return $name === 'target_id' ? $jurisdiction_id : NULL;
        },
      );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $application->method('get')
      ->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);
    $application->method('getOriginal')->willReturn(NULL);

    $service = $this->createService($lock);
    $this->assertTrue($service->acquireForApplication($application));
    $jurisdiction_id = 56;
    $this->assertTrue($service->acquireForApplication($application));
    $service->release($application);

    $all_locks = implode(' ', $acquired);
    $this->assertStringContainsString('.jur.12', $all_locks);
    $this->assertStringContainsString('.jur.56', $all_locks);
  }

  /**
   * A profile move locks both its original and target workspaces.
   */
  public function testApplicantMoveLocksOriginalAndNewScopes(): void {
    $acquired = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(4))
      ->method('acquire')
      ->willReturnCallback(
        static function (string $lock_name) use (&$acquired): bool {
          $acquired[] = $lock_name;
          return TRUE;
        },
      );
    $lock->expects($this->exactly(4))->method('release');

    $service = $this->createService($lock);
    $original = $this->createApplicant('ada@example.org', 12, 34);
    $applicant = $this->createApplicant('ada@example.org', 56, 78, $original);

    $this->assertTrue($service->acquireForApplicant($applicant));
    $service->release($applicant);

    $all_locks = implode(' ', $acquired);
    $this->assertStringContainsString('.jur.12', $all_locks);
    $this->assertStringContainsString('.org.34', $all_locks);
    $this->assertStringContainsString('.jur.56', $all_locks);
    $this->assertStringContainsString('.org.78', $all_locks);
  }

  /**
   * An applicant email without a jurisdiction is explicitly unscoped.
   */
  public function testApplicantWithoutJurisdictionHasNoTenantScope(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->never())->method('acquire');
    $service = $this->createService($lock);
    $applicant = $this->createApplicant('ada@example.org', 0, 0);

    $this->assertFalse($service->hasTenantScopeForApplicant($applicant));
    $this->assertTrue($service->hasLockableIdentityForApplicant($applicant));
    $this->assertFalse($service->acquireForApplicant($applicant));
  }

  /**
   * Scoped applicants still fail closed when their mutation lock is held.
   */
  public function testScopedApplicantCannotAcquireHeldMutationLock(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(FALSE);
    $service = $this->createService($lock);
    $applicant = $this->createApplicant('ada@example.org', 12, 0);

    $this->assertTrue($service->hasTenantScopeForApplicant($applicant));
    $this->assertFalse($service->acquireForApplicant($applicant));
  }

  /**
   * An identified application with an unresolved tenant scope fails closed.
   */
  public function testApplicationWithoutNormalizedScopeCannotAcquire(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->never())->method('acquire');
    $service = $this->createService($lock);

    $application = $this->createApplication('ada@example.org', 0, 0);

    $this->assertFalse($service->acquireForApplication($application));
  }

  /**
   * Repeated releases stay deferred until the root transaction completes.
   */
  public function testReleaseIsDeferredOnceUntilRootTransactionCompletes(): void {
    $owner = new \stdClass();
    $released = FALSE;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release')
      ->willReturnCallback(static function () use (&$released): void {
        $released = TRUE;
      });

    $callback = NULL;
    $transaction_manager = $this->createMock(TransactionManagerInterface::class);
    $transaction_manager->expects($this->once())
      ->method('addPostTransactionCallback')
      ->willReturnCallback(static function (callable $registered) use (&$callback): void {
        $callback = $registered;
      });
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(TRUE);
    $database->method('transactionManager')->willReturn($transaction_manager);

    $service = $this->createService($lock, $database);
    $this->assertTrue($service->acquireForEmailWorkspace($owner, 'ada@example.org', 12));
    $service->release($owner);
    $service->release($owner);

    $this->assertFalse($released);
    $this->assertIsCallable($callback);
    $callback(TRUE);
    $this->assertTrue($released);
  }

  /**
   * Callback registration failures release locks before being rethrown.
   */
  public function testCallbackRegistrationFailureReleasesImmediately(): void {
    $owner = new \stdClass();
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');

    $transaction_manager = $this->createMock(TransactionManagerInterface::class);
    $transaction_manager->expects($this->once())
      ->method('addPostTransactionCallback')
      ->willThrowException(new \RuntimeException('callback registration failed'));
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(TRUE);
    $database->method('transactionManager')->willReturn($transaction_manager);

    $service = $this->createService($lock, $database);
    $this->assertTrue($service->acquireForEmailWorkspace($owner, 'ada@example.org', 12));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('callback registration failed');
    $service->release($owner);
  }

  /**
   * Creates the service with its real deterministic email protection helper.
   */
  private function createService(
    LockBackendInterface $lock,
    ?Connection $database = NULL,
  ): ApplicantScopeMutationLock {
    $data_protection = new ApplicationDataProtectionService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    return new ApplicantScopeMutationLock($lock, $data_protection, $database);
  }

  /**
   * Creates an application mock with applicant identity and tenant scope.
   */
  private function createApplication(
    string $email,
    int $jurisdictionId,
    int $organisationId,
    ?ApplicationInterface $original = NULL,
  ): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $fields = $this->scopeFields($email, $jurisdictionId, $organisationId);
    $application->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $application->method('get')
      ->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);
    $application->method('getOriginal')->willReturn($original);
    return $application;
  }

  /**
   * Creates an applicant mock with identity and tenant scope.
   */
  private function createApplicant(
    string $email,
    int $jurisdictionId,
    int $organisationId,
    ?ApplicantInterface $original = NULL,
  ): ApplicantInterface {
    $applicant = $this->createMock(ApplicantInterface::class);
    $fields = $this->scopeFields($email, $jurisdictionId, $organisationId, TRUE);
    $applicant->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $applicant->method('get')
      ->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);
    $applicant->method('getOriginal')->willReturn($original);
    return $applicant;
  }

  /**
   * Creates mocked field lists for one applicant identity and tenant scope.
   *
   * @return array<string, \Drupal\Core\Field\FieldItemListInterface>
   *   Field item lists keyed by field name.
   */
  private function scopeFields(
    string $email,
    int $jurisdictionId,
    int $organisationId,
    bool $profile = FALSE,
  ): array {
    return [
      $profile ? 'field_email' : 'field_applicant_email' => $this->field(['value' => $email]),
      'field_applicant_email_hash' => $this->field(['value' => '']),
      'field_jurisdiction' => $this->field(['target_id' => $jurisdictionId]),
      'field_organisation' => $this->field(['target_id' => $organisationId]),
      'field_group' => $this->field(['entity' => NULL]),
    ];
  }

  /**
   * Creates a field item list exposing selected magic properties.
   *
   * @param array<string, mixed> $properties
   *   Field properties.
   */
  private function field(array $properties): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__isset')
      ->willReturnCallback(static fn(string $name): bool => array_key_exists($name, $properties));
    $field->method('__get')
      ->willReturnCallback(static fn(string $name): mixed => $properties[$name] ?? NULL);
    return $field;
  }

}
