<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Serializes applicant application mutations inside one tenant workspace.
 */
class ApplicantScopeMutationLock {

  /**
   * Locks held for request-local owner objects.
   *
   * @var \WeakMap<object, string[]>
   */
  private \WeakMap $heldLocks;

  /**
   * Owners awaiting the root transaction callback.
   *
   * @var \WeakMap<object, bool>
   */
  private \WeakMap $pendingReleases;

  /**
   * Request-local reference counts keyed by lock name.
   *
   * @var array<string, int>
   */
  private array $lockReferences = [];

  /**
   * Constructs the applicant scope mutation lock.
   */
  public function __construct(
    private readonly LockBackendInterface $lock,
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly ?Connection $database = NULL,
  ) {
    $this->heldLocks = new \WeakMap();
    $this->pendingReleases = new \WeakMap();
  }

  /**
   * Acquires all old and new applicant scope locks for an application save.
   */
  public function acquireForApplication(ApplicationInterface $application): bool {
    $lock_names = $this->lockNamesForApplication($application);
    if ($lock_names === []
      && $application->hasField('field_jurisdiction')
      && $this->hasApplicationIdentity($application)) {
      return FALSE;
    }
    $original = $application->getOriginal();
    if ($original instanceof ApplicationInterface) {
      $lock_names = array_merge(
        $lock_names,
        $this->lockNamesForApplication($original),
      );
    }

    return $this->acquire($application, array_values(array_unique($lock_names)));
  }

  /**
   * Acquires all old and new applicant scope locks for a profile save.
   */
  public function acquireForApplicant(FieldableEntityInterface $applicant): bool {
    $lock_names = $this->lockNamesForApplicant($applicant);
    if ($lock_names === []
      && $applicant->hasField('field_jurisdiction')
      && $this->fieldValue($applicant, 'field_email') !== '') {
      return FALSE;
    }
    $original = $applicant->getOriginal();
    if ($original instanceof FieldableEntityInterface) {
      $lock_names = array_merge(
        $lock_names,
        $this->lockNamesForApplicant($original),
      );
    }

    return $this->acquire($applicant, array_values(array_unique($lock_names)));
  }

  /**
   * Returns TRUE when an applicant has a derivable tenant scope.
   */
  public function hasTenantScopeForApplicant(FieldableEntityInterface $applicant): bool {
    return $this->scopeIdsForApplicant($applicant)['jurisdiction_id'] > 0;
  }

  /**
   * Returns TRUE when an applicant email can identify its scope lock.
   */
  public function hasLockableIdentityForApplicant(FieldableEntityInterface $applicant): bool {
    return $this->dataProtection->applicantEmailLookupHash(
      $this->fieldValue($applicant, 'field_email'),
    ) !== NULL;
  }

  /**
   * Acquires the applicant scope lock used by the administrative cleanup.
   */
  public function acquireForEmailWorkspace(
    object $owner,
    string $email,
    int $jurisdictionId,
    int $organisationId = 0,
  ): bool {
    $email_hash = $this->dataProtection->applicantEmailLookupHash($email);
    if ($email_hash === NULL || $jurisdictionId <= 0) {
      return FALSE;
    }

    return $this->acquire(
      $owner,
      $this->lockNames($email_hash, $jurisdictionId, $organisationId),
    );
  }

  /**
   * Releases locks after the active root transaction completes.
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
   * Acquires a sorted set of lock names without leaking partial acquisition.
   *
   * @param object $owner
   *   Request-local owner object.
   * @param string[] $lockNames
   *   Lock names to acquire.
   */
  private function acquire(object $owner, array $lockNames): bool {
    $held_lock_names = $this->heldLocks[$owner] ?? [];
    $lockNames = array_values(array_unique(array_merge($held_lock_names, $lockNames)));
    sort($lockNames, SORT_STRING);
    $missing_lock_names = array_values(array_diff($lockNames, $held_lock_names));
    if ($missing_lock_names === []) {
      return TRUE;
    }

    $acquired = [];
    foreach ($missing_lock_names as $lock_name) {
      if (!isset($this->lockReferences[$lock_name])) {
        if (!$this->lock->acquire($lock_name, 300.0)) {
          foreach (array_reverse($acquired) as $acquired_name) {
            $this->releaseReference($acquired_name);
          }
          return FALSE;
        }
        $this->lockReferences[$lock_name] = 0;
      }
      $this->lockReferences[$lock_name]++;
      $acquired[] = $lock_name;
    }

    $this->heldLocks[$owner] = $lockNames;
    return TRUE;
  }

  /**
   * Releases every lock held by one owner immediately.
   */
  private function releaseNow(object $owner): void {
    $lock_names = $this->heldLocks[$owner] ?? [];
    unset($this->heldLocks[$owner]);
    foreach (array_reverse($lock_names) as $lock_name) {
      $this->releaseReference($lock_name);
    }
  }

  /**
   * Releases one request-local lock reference.
   */
  private function releaseReference(string $lockName): void {
    $references = ($this->lockReferences[$lockName] ?? 1) - 1;
    if ($references > 0) {
      $this->lockReferences[$lockName] = $references;
      return;
    }
    unset($this->lockReferences[$lockName]);
    $this->lock->release($lockName);
  }

  /**
   * Resolves applicant scope locks from an application entity.
   *
   * @return string[]
   *   Lock names, or an empty array when identity or scope is unavailable.
   */
  private function lockNamesForApplication(ApplicationInterface $application): array {
    $email_hash = $this->fieldValue($application, 'field_applicant_email_hash');
    if ($email_hash === '') {
      $email_hash = $this->dataProtection->applicantEmailLookupHash(
        $this->fieldValue($application, 'field_applicant_email'),
      ) ?? '';
    }
    $jurisdiction_id = $this->targetId($application, 'field_jurisdiction');
    $organisation_id = $this->targetId($application, 'field_organisation');
    $group = $application->hasField('field_group')
      ? $application->get('field_group')->entity
      : NULL;
    if ($group instanceof GroupInterface) {
      if ($group->bundle() === 'jur' && $jurisdiction_id <= 0) {
        $jurisdiction_id = (int) $group->id();
      }
      if ($group->bundle() === 'org') {
        $organisation_id = $organisation_id ?: (int) $group->id();
        $jurisdiction_id = $jurisdiction_id
          ?: $this->targetId($group, 'field_jurisdiction');
      }
    }

    $schema = $application->hasField('field_schema')
      ? $application->get('field_schema')->entity
      : NULL;
    if ($schema instanceof FieldableEntityInterface) {
      $jurisdiction_id = $jurisdiction_id
        ?: $this->targetId($schema, 'field_jurisdiction');
      $organisation_id = $organisation_id
        ?: $this->targetId($schema, 'field_organisation');
      $schema_group = $schema->hasField('field_group')
        ? $schema->get('field_group')->entity
        : NULL;
      if ($schema_group instanceof GroupInterface) {
        if ($schema_group->bundle() === 'jur' && $jurisdiction_id <= 0) {
          $jurisdiction_id = (int) $schema_group->id();
        }
        if ($schema_group->bundle() === 'org') {
          $organisation_id = $organisation_id ?: (int) $schema_group->id();
          $jurisdiction_id = $jurisdiction_id
            ?: $this->targetId($schema_group, 'field_jurisdiction');
        }
      }
    }

    return $email_hash !== '' && $jurisdiction_id > 0
      ? $this->lockNames($email_hash, $jurisdiction_id, $organisation_id)
      : [];
  }

  /**
   * Resolves applicant scope locks from a persisted profile entity.
   *
   * @return string[]
   *   Lock names, or an empty array when identity is unavailable.
   */
  private function lockNamesForApplicant(FieldableEntityInterface $applicant): array {
    $email_hash = $this->dataProtection->applicantEmailLookupHash(
      $this->fieldValue($applicant, 'field_email'),
    ) ?? '';
    $scope = $this->scopeIdsForApplicant($applicant);

    return $email_hash !== '' && $scope['jurisdiction_id'] > 0
      ? $this->lockNames(
        $email_hash,
        $scope['jurisdiction_id'],
        $scope['organisation_id'],
      )
      : [];
  }

  /**
   * Resolves the tenant scope carried directly or through the group field.
   *
   * @return array{jurisdiction_id: int, organisation_id: int}
   *   Resolved tenant scope IDs.
   */
  private function scopeIdsForApplicant(FieldableEntityInterface $applicant): array {
    $jurisdiction_id = $this->targetId($applicant, 'field_jurisdiction');
    $organisation_id = $this->targetId($applicant, 'field_organisation');
    $group = $applicant->hasField('field_group')
      ? $applicant->get('field_group')->entity
      : NULL;
    if ($group instanceof GroupInterface) {
      if ($group->bundle() === 'jur' && $jurisdiction_id <= 0) {
        $jurisdiction_id = (int) $group->id();
      }
      if ($group->bundle() === 'org') {
        $organisation_id = $organisation_id ?: (int) $group->id();
        $jurisdiction_id = $jurisdiction_id
          ?: $this->targetId($group, 'field_jurisdiction');
      }
    }

    return [
      'jurisdiction_id' => $jurisdiction_id,
      'organisation_id' => $organisation_id,
    ];
  }

  /**
   * Returns TRUE when an application carries applicant identity linkage.
   */
  private function hasApplicationIdentity(ApplicationInterface $application): bool {
    return $this->fieldValue($application, 'field_applicant_email_hash') !== ''
      || $this->fieldValue($application, 'field_applicant_email') !== '';
  }

  /**
   * Returns the jurisdiction-wide and optional organisation lock names.
   *
   * @return string[]
   *   Ordered lock names.
   */
  private function lockNames(
    string $emailHash,
    int $jurisdictionId,
    int $organisationId,
  ): array {
    $prefix = 'beantragt_application.applicant_scope.' . $emailHash;
    $lock_names = [sprintf('%s.jur.%d', $prefix, $jurisdictionId)];
    if ($organisationId > 0) {
      $lock_names[] = sprintf('%s.org.%d', $prefix, $organisationId);
    }
    return $lock_names;
  }

  /**
   * Returns one scalar field value.
   */
  private function fieldValue(
    FieldableEntityInterface $entity,
    string $fieldName,
  ): string {
    return $entity->hasField($fieldName)
      ? trim((string) ($entity->get($fieldName)->value ?? ''))
      : '';
  }

  /**
   * Returns one entity-reference target ID.
   */
  private function targetId(
    FieldableEntityInterface $entity,
    string $fieldName,
  ): int {
    return $entity->hasField($fieldName)
      ? (int) ($entity->get($fieldName)->target_id ?? 0)
      : 0;
  }

}
