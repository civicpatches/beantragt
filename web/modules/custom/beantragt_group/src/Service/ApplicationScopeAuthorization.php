<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;

/**
 * Authorizes re-homing existing applications into a target tenant scope.
 */
final class ApplicationScopeAuthorization implements ApplicationScopeAuthorizationInterface {

  /**
   * Constructs an ApplicationScopeAuthorization service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function findUnauthorizedTarget(ApplicationInterface $application, AccountInterface $account): ?array {
    if ($application->isNew() || $application->isSyncing()) {
      return NULL;
    }

    $original = $application->getOriginal();
    if (!$original instanceof ApplicationInterface) {
      $original = $this->applicationStorage()->loadUnchanged($application->id());
    }

    $current_ids = $this->scopeIds($application);
    // Fail closed when the persisted state cannot be loaded: treat every scope
    // target as newly added so each must be manageable by the actor.
    if ($original instanceof ApplicationInterface) {
      $original_ids = $this->scopeIds($original);
      $previous_jurisdiction_id = $this->effectiveJurisdictionId($original, $original_ids);
    }
    else {
      $original_ids = ['jurisdiction' => 0, 'organisation' => 0, 'legacy' => 0];
      $previous_jurisdiction_id = 0;
    }

    if ($current_ids === $original_ids) {
      return NULL;
    }

    $checked_jurisdictions = [];
    $new_jurisdiction_id = $current_ids['jurisdiction'];
    if ($new_jurisdiction_id > 0 && $new_jurisdiction_id !== $original_ids['jurisdiction']) {
      $checked_jurisdictions[$new_jurisdiction_id] = TRUE;
      if (!$this->canManageJurisdiction($account, $new_jurisdiction_id)) {
        return [
          'scope_id' => $new_jurisdiction_id,
          'jurisdiction_id' => $new_jurisdiction_id,
          'field_name' => 'field_jurisdiction',
        ];
      }
    }

    $new_organisation_id = $current_ids['organisation'];
    if ($new_organisation_id > 0 && $new_organisation_id !== $original_ids['organisation']) {
      $target_jurisdiction_id = $this->organisationJurisdictionId($new_organisation_id);
      if ($target_jurisdiction_id > 0
        && $target_jurisdiction_id !== $previous_jurisdiction_id
        && !isset($checked_jurisdictions[$target_jurisdiction_id])) {
        if (!$this->canManageJurisdiction($account, $target_jurisdiction_id)) {
          return [
            'scope_id' => $new_organisation_id,
            'jurisdiction_id' => $target_jurisdiction_id,
            'field_name' => 'field_organisation',
          ];
        }
      }
    }

    // field_group is a compatibility field. Normalization only consults it
    // when both explicit scope fields are empty, so guard that path as well.
    $new_legacy_id = $current_ids['legacy'];
    if ($new_legacy_id > 0
      && $new_legacy_id !== $original_ids['legacy']
      && $new_jurisdiction_id === 0
      && $new_organisation_id === 0) {
      $legacy_group = $this->groupStorage()->load($new_legacy_id);
      if ($legacy_group instanceof GroupInterface) {
        $target_jurisdiction_id = $legacy_group->bundle() === 'jur'
          ? (int) $legacy_group->id()
          : $this->groupJurisdictionId($legacy_group);
        // No jurisdiction was checked above: this path requires both
        // explicit scope fields to be empty.
        if ($target_jurisdiction_id > 0
          && $target_jurisdiction_id !== $previous_jurisdiction_id
          && !$this->canManageJurisdiction($account, $target_jurisdiction_id)) {
          return [
            'scope_id' => $new_legacy_id,
            'jurisdiction_id' => $target_jurisdiction_id,
            'field_name' => 'field_group',
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Returns TRUE when an account manages a jurisdiction or an ancestor.
   *
   * Mirrors JurisdictionConfigController::accessCheck(): user 1 and Drupal
   * administrators bypass, while department administrators need an individual
   * admin group role in the target jurisdiction or an ancestor.
   */
  private function canManageJurisdiction(AccountInterface $account, int $jurisdiction_id): bool {
    if ((int) $account->id() === 1 || in_array('administrator', $account->getRoles(), TRUE)) {
      return TRUE;
    }
    if (!BeantragtRoles::isManagementAccount($account)) {
      return FALSE;
    }

    foreach (GroupMembership::loadByUser($account) as $membership) {
      if (!$this->membershipGrantsAdmin($membership)) {
        continue;
      }
      $managed_group = $membership->getGroup();
      if ($managed_group->bundle() !== 'jur') {
        continue;
      }
      if ($this->hierarchyResolver->isSameOrDescendantById(
        $jurisdiction_id,
        (int) $managed_group->id(),
      )) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when an individual membership carries an admin role.
   */
  private function membershipGrantsAdmin(GroupMembershipInterface $membership): bool {
    return ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Returns the three application scope target IDs.
   *
   * @return array{jurisdiction: int, organisation: int, legacy: int}
   *   Scope target IDs, with zero for empty fields.
   */
  private function scopeIds(ApplicationInterface $application): array {
    return [
      'jurisdiction' => $this->fieldTargetId($application, 'field_jurisdiction'),
      'organisation' => $this->fieldTargetId($application, 'field_organisation'),
      'legacy' => $this->fieldTargetId($application, 'field_group'),
    ];
  }

  /**
   * Returns one entity-reference target ID.
   */
  private function fieldTargetId(ApplicationInterface $application, string $field_name): int {
    if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) ($application->get($field_name)->getValue()[0]['target_id'] ?? 0);
  }

  /**
   * Resolves the effective persisted jurisdiction without widening scope.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The persisted application state.
   * @param array{jurisdiction: int, organisation: int, legacy: int} $scope_ids
   *   Persisted scope target IDs.
   */
  private function effectiveJurisdictionId(ApplicationInterface $application, array $scope_ids): int {
    if ($scope_ids['jurisdiction'] > 0) {
      return $scope_ids['jurisdiction'];
    }
    if ($scope_ids['organisation'] > 0) {
      return $this->organisationJurisdictionId($scope_ids['organisation']);
    }
    if ($scope_ids['legacy'] <= 0) {
      return 0;
    }

    $group = $this->groupStorage()->load($scope_ids['legacy']);
    if (!$group instanceof GroupInterface) {
      return 0;
    }
    return $group->bundle() === 'jur'
      ? (int) $group->id()
      : $this->groupJurisdictionId($group);
  }

  /**
   * Resolves an organisation's jurisdiction ID.
   */
  private function organisationJurisdictionId(int $organisation_id): int {
    $organisation = $this->groupStorage()->load($organisation_id);
    if (!$organisation instanceof GroupInterface || $organisation->bundle() !== 'org') {
      return 0;
    }
    return $this->groupJurisdictionId($organisation);
  }

  /**
   * Returns a group's direct jurisdiction target ID.
   */
  private function groupJurisdictionId(GroupInterface $group): int {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return 0;
    }
    return (int) ($group->get('field_jurisdiction')->getValue()[0]['target_id'] ?? 0);
  }

  /**
   * Returns application storage.
   */
  private function applicationStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('application');
  }

  /**
   * Returns group storage.
   */
  private function groupStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('group');
  }

}
