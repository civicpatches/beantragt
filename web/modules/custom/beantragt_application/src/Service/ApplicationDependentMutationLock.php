<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Prevents dependent entities from being persisted across application deletion.
 */
final class ApplicationDependentMutationLock {

  /**
   * Lock names held for dependent entity objects in this request.
   *
   * @var \WeakMap<object, string>
   */
  private \WeakMap $heldLocks;

  /**
   * Owners whose release is deferred until the root transaction completes.
   *
   * @var \WeakMap<object, bool>
   */
  private \WeakMap $pendingReleases;

  /**
   * Reentrant reference counts for locks held by this service instance.
   *
   * @var array<string, int>
   */
  private array $lockReferences = [];

  /**
   * Constructs the dependent mutation lock.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly ?Connection $database = NULL,
  ) {
    $this->heldLocks = new \WeakMap();
    $this->pendingReleases = new \WeakMap();
  }

  /**
   * Acquires the application lock and revalidates its existence from storage.
   */
  public function acquire(EntityInterface $dependent): void {
    if (!$dependent instanceof FieldableEntityInterface
      || !$this->isApplicationDependent($dependent)
      || isset($this->heldLocks[$dependent])) {
      return;
    }

    $application_id = (int) $dependent->get('field_application')->target_id;
    if ($application_id <= 0) {
      return;
    }

    if (!$this->acquireForOwner($dependent, $application_id)) {
      throw new ApplicationDeletionLockedException('The related application is currently being deleted or delivered.');
    }

    try {
      if (!$this->freshApplication($application_id) instanceof ApplicationInterface) {
        throw new EntityStorageException('The related application no longer exists.');
      }
    }
    catch (\Throwable $exception) {
      $this->release($dependent);
      throw $exception;
    }
  }

  /**
   * Locks and freshly revalidates an application-scoped producer operation.
   */
  public function acquireApplication(ApplicationInterface $application): bool {
    $application_id = (int) $application->id();
    if ($application_id <= 0 || !$this->acquireForOwner($application, $application_id)) {
      return FALSE;
    }

    try {
      $current = $this->freshApplication($application_id);
      if (!$current instanceof ApplicationInterface
        || $current->uuid() !== $application->uuid()) {
        $this->release($application);
        return FALSE;
      }
      return TRUE;
    }
    catch (\Throwable $exception) {
      $this->release($application);
      throw $exception;
    }
  }

  /**
   * Releases a lock acquired for a saved dependent entity.
   */
  public function release(object $owner): void {
    if (!isset($this->heldLocks[$owner])) {
      return;
    }

    if ($this->database instanceof Connection
      && $this->database->inTransaction()
      && !isset($this->pendingReleases[$owner])) {
      $this->pendingReleases[$owner] = TRUE;
      try {
        $this->database->transactionManager()->addPostTransactionCallback(
          function (bool $success) use ($owner): void {
            unset($this->pendingReleases[$owner]);
            $this->releaseNow($owner);
          },
        );
      }
      catch (\Throwable $exception) {
        unset($this->pendingReleases[$owner]);
        $this->releaseNow($owner);
        throw $exception;
      }
      return;
    }

    if (isset($this->pendingReleases[$owner])) {
      return;
    }

    $this->releaseNow($owner);
  }

  /**
   * Returns whether this service instance already owns the shared lock.
   */
  public function isHeld(object $owner): bool {
    return isset($this->heldLocks[$owner]);
  }

  /**
   * Releases one owner reference immediately.
   */
  private function releaseNow(object $owner): void {
    if (!isset($this->heldLocks[$owner])) {
      return;
    }

    $lock_name = $this->heldLocks[$owner];
    unset($this->heldLocks[$owner]);
    $references = ($this->lockReferences[$lock_name] ?? 1) - 1;
    if ($references > 0) {
      $this->lockReferences[$lock_name] = $references;
      return;
    }

    unset($this->lockReferences[$lock_name]);
    $this->lock->release($lock_name);
  }

  /**
   * Acquires or references a lock already held by this service instance.
   */
  private function acquireForOwner(object $owner, int $applicationId): bool {
    if (isset($this->heldLocks[$owner])) {
      return TRUE;
    }

    $lock_name = Application::deletionLockName($applicationId);
    if (!isset($this->lockReferences[$lock_name])) {
      if (!$this->lock->acquire($lock_name, 300.0)) {
        return FALSE;
      }
      $this->lockReferences[$lock_name] = 0;
    }

    $this->lockReferences[$lock_name]++;
    $this->heldLocks[$owner] = $lock_name;
    return TRUE;
  }

  /**
   * Loads an application after clearing Drupal's per-request entity cache.
   */
  private function freshApplication(int $applicationId): ?ApplicationInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $storage->resetCache([$applicationId]);
    $application = $storage->load($applicationId);
    return $application instanceof ApplicationInterface ? $application : NULL;
  }

  /**
   * Returns TRUE for persisted entities owned by one application.
   */
  private function isApplicationDependent(FieldableEntityInterface $entity): bool {
    return in_array($entity->getEntityTypeId(), ['application_attachment', 'application_comment'], TRUE)
      && $entity->hasField('field_application')
      && !$entity->get('field_application')->isEmpty();
  }

}
