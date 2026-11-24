<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests application-dependent mutation locking.
 */
#[CoversClass(ApplicationDependentMutationLock::class)]
#[Group('beantragt_application')]
final class ApplicationDependentMutationLockTest extends UnitTestCase {

  /**
   * A dependent save revalidates the application under the shared lock.
   */
  public function testAcquireRevalidatesAndReleaseUnlocks(): void {
    $dependent = $this->dependent(42);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('resetCache')->with([42]);
    $storage->expects($this->once())->method('load')->with(42)
      ->willReturn($this->createMock(ApplicationInterface::class));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.delete.42', 300.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_application.delete.42');

    $service = new ApplicationDependentMutationLock($manager, $lock);
    $service->acquire($dependent);
    $service->release($dependent);
  }

  /**
   * A missing application aborts the dependent save and releases the lock.
   */
  public function testAcquireRejectsMissingApplication(): void {
    $dependent = $this->dependent(42);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('resetCache')->with([42]);
    $storage->expects($this->once())->method('load')->with(42)->willReturn(NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())->method('release');

    $service = new ApplicationDependentMutationLock($manager, $lock);

    $this->expectException(EntityStorageException::class);
    $service->acquire($dependent);
  }

  /**
   * Nested owners keep the shared backend lock until the outer owner releases.
   */
  public function testNestedApplicationAndDependentLocksAreReferenceCounted(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('uuid')->willReturn('5efc64a8-3004-4b09-8642-64d9fb506758');
    $dependent = $this->dependent(42);

    $current = $this->createMock(ApplicationInterface::class);
    $current->method('uuid')->willReturn('5efc64a8-3004-4b09-8642-64d9fb506758');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('resetCache')->with([42]);
    $storage->expects($this->exactly(2))->method('load')->with(42)->willReturn($current);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.delete.42', 300.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_application.delete.42');

    $service = new ApplicationDependentMutationLock($manager, $lock);
    $this->assertTrue($service->acquireApplication($application));
    $service->acquire($dependent);
    $service->release($dependent);
    $service->release($application);
  }

  /**
   * A hook release keeps the backend lock until the DB transaction completes.
   */
  public function testReleaseIsDeferredUntilRootTransactionCompletes(): void {
    $dependent = $this->dependent(42);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)
      ->willReturn($this->createMock(ApplicationInterface::class));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);

    $released = FALSE;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $lock->method('release')->willReturnCallback(
      static function (string $lockName) use (&$released): void {
        $released = TRUE;
      },
    );

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

    $service = new ApplicationDependentMutationLock($manager, $lock, $database);
    $service->acquire($dependent);
    $service->release($dependent);

    $this->assertFalse($released);
    $this->assertIsCallable($callback);
    $callback(TRUE);
    $this->assertTrue($released);
  }

  /**
   * Builds a dependent entity referencing one application.
   */
  private function dependent(int $applicationId): FieldableEntityInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('target_id')->willReturn($applicationId);

    $dependent = $this->createMock(FieldableEntityInterface::class);
    $dependent->method('getEntityTypeId')->willReturn('application_comment');
    $dependent->method('hasField')->with('field_application')->willReturn(TRUE);
    $dependent->method('get')->with('field_application')->willReturn($field);
    return $dependent;
  }

}
