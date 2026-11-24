<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Access\ApplicationMembershipBatch;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\user\UserInterface;

/**
 * Tenant scope checks of the docgen staff API (group model).
 *
 * Mirrors beantragt_delivery's ScopeAccess: a global permission says what a
 * person may do, the group membership says where. Templates belong to an
 * organisation or, for forms without one, to the jurisdiction. Managing
 * them needs an admin role in that group or in the organisation's
 * jurisdiction; using them needs membership. Case work needs view access to
 * the application (update access for changes and release) and membership in
 * its scope group (or an admin role in the jurisdiction above the
 * organisation).
 */
class DocgenScopeAccess {

  public const PERMISSION_ADMINISTER = 'administer docgen templates';
  public const PERMISSION_CREATE = 'create docgen documents';
  public const PERMISSION_RELEASE = 'release docgen documents';

  /**
   * Constructs the checker.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Loads an active jurisdiction or organisation group by ID.
   */
  public function loadScope(int $group_id): ?GroupInterface {
    if ($group_id < 1) {
      return NULL;
    }
    $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    return $group instanceof GroupInterface && in_array($group->bundle(), ['jur', 'org'], TRUE) ? $group : NULL;
  }

  /**
   * Returns the jurisdiction of a scope group.
   */
  public function jurisdictionOf(GroupInterface $group): ?GroupInterface {
    if ($group->bundle() === 'jur') {
      return $group;
    }
    if ($group->bundle() !== 'org' || !$group->hasField('field_jurisdiction')) {
      return NULL;
    }
    $parent = $group->get('field_jurisdiction')->entity ?? NULL;
    return $parent instanceof GroupInterface && $parent->bundle() === 'jur' ? $parent : NULL;
  }

  /**
   * Returns the scope group of a form or application: organisation first.
   */
  public function scopeOf(ContentEntityInterface $entity): ?GroupInterface {
    foreach (['field_organisation', 'field_group', 'field_jurisdiction'] as $field_name) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }
      $group = $entity->get($field_name)->entity ?? NULL;
      if ($group instanceof GroupInterface) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * May the account upload, release and assign templates in the scope?
   */
  public function canManageTemplates(GroupInterface $scope, AccountInterface $account): bool {
    if (!$this->isActive($account) || !$account->hasPermission(self::PERMISSION_ADMINISTER)) {
      return FALSE;
    }
    if ($this->bypasses($account)) {
      return TRUE;
    }
    if ($this->hasAdminRole($scope, $account)) {
      return TRUE;
    }
    $jurisdiction = $scope->bundle() === 'org' ? $this->jurisdictionOf($scope) : NULL;
    return $jurisdiction instanceof GroupInterface && $this->hasAdminRole($jurisdiction, $account);
  }

  /**
   * May the account list and read templates of the scope?
   */
  public function canReadTemplates(GroupInterface $scope, AccountInterface $account): bool {
    if (!$this->isActive($account)
      || (!$account->hasPermission(self::PERMISSION_ADMINISTER) && !$account->hasPermission(self::PERMISSION_CREATE))) {
      return FALSE;
    }
    return $this->bypasses($account) || $this->isMemberOrJurisdictionAdmin($scope, $account);
  }

  /**
   * May the account do case work with the given permission?
   *
   * Reading documents needs view access to the application. Drafting,
   * uploading, previewing, discarding and releasing change the case (release
   * provides the notice and sets the status), so they need update access,
   * like the dispatch approval in beantragt_delivery.
   */
  public function canWorkOnCase(ApplicationInterface $application, AccountInterface $account, string $permission, bool $write = FALSE): bool {
    // Queue retries may share a PHP process with the original permission check.
    // Re-evaluate entity access after role or workspace changes.
    if (!ApplicationMembershipBatch::active($account)) {
      $this->entityTypeManager->getAccessControlHandler('application')->resetCache();
    }
    if (!$this->isActive($account) || !$account->hasPermission($permission) || !$application->access('view', $account)) {
      return FALSE;
    }
    if ($write && !$application->access('update', $account)) {
      return FALSE;
    }
    $scope = $this->scopeOf($application);
    if (!$scope instanceof GroupInterface) {
      return FALSE;
    }
    return $this->bypasses($account) || $this->isMemberOrJurisdictionAdmin($scope, $account);
  }

  /**
   * Member of the group, or admin of the jurisdiction above an organisation.
   */
  private function isMemberOrJurisdictionAdmin(GroupInterface $scope, AccountInterface $account): bool {
    if (GroupMembership::loadSingle($scope, $account) instanceof GroupMembershipInterface) {
      return TRUE;
    }
    $jurisdiction = $scope->bundle() === 'org' ? $this->jurisdictionOf($scope) : NULL;
    return $jurisdiction instanceof GroupInterface && $this->hasAdminRole($jurisdiction, $account);
  }

  /**
   * Has an explicit admin role in the group.
   */
  private function hasAdminRole(GroupInterface $group, AccountInterface $account): bool {
    $membership = GroupMembership::loadSingle($group, $account);
    return $membership instanceof GroupMembershipInterface
      && ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Platform-wide administrators.
   */
  private function bypasses(AccountInterface $account): bool {
    return $account->hasPermission('bypass beantragt group scope') || $account->hasPermission('administer application entities');
  }

  /**
   * Authenticated, existing and not blocked.
   */
  private function isActive(AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    return $user instanceof UserInterface && $user->isActive();
  }

}
