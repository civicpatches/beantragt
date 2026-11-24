<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Access;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\user\UserInterface;

/**
 * Scope of staff actions on a single application.
 *
 * Site permissions and entity access are checked by the action's caller.
 */
final class ApplicationCaseScope {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks the effective case scope with cacheability for grants and denials.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResult {
    $access = AccessResult::allowed()->cachePerPermissions()->cachePerUser()
      ->addCacheableDependency($application)
      ->addCacheTags(self::cacheTags($account));
    if ($account->hasPermission('administer application entities')
      || $account->hasPermission('bypass beantragt group scope')) {
      return $access;
    }

    $group = self::effectiveGroup($application, $this->entityTypeManager);
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden('Application has no valid case scope.')
        ->addCacheableDependency($access);
    }
    $jurisdiction = self::recordedParentJurisdiction($application, $group, $this->entityTypeManager);
    $result = $this->allowsGroup($group, $account, $jurisdiction !== NULL)
      ? AccessResult::allowed()
      : AccessResult::forbidden('User may not work on cases in this scope.');
    $result->addCacheableDependency($access)
      ->addCacheableDependency($group);
    if ($jurisdiction instanceof GroupInterface) {
      $result->addCacheableDependency($jurisdiction);
    }
    return $result;
  }

  /**
   * Whether the status and deadline routes permit writes on this case.
   */
  public function canChangeStatus(ApplicationInterface $application, AccountInterface $account): bool {
    return $account->hasPermission('manage workflow transitions')
      && $application->access('update', $account)
      && $this->access($application, $account)->isAllowed();
  }

  /**
   * Whether the account may use the permanent deletion route for this case.
   */
  public function canDelete(ApplicationInterface $application, AccountInterface $account): bool {
    return ($account->hasPermission('delete any application entities')
        || $account->hasPermission('administer application entities'))
      && $application->access('delete', $account)
      && $this->access($application, $account)->isAllowed();
  }

  /**
   * Checks direct office membership or existing office/jurisdiction admin.
   */
  public function allowsGroup(GroupInterface $group, AccountInterface $account, bool $inherit_jurisdiction = TRUE): bool {
    if (!$account->isAuthenticated() || ($account instanceof UserInterface && !$account->isActive())) {
      return FALSE;
    }
    if ($this->administersGroup($group, $account)) {
      return TRUE;
    }
    if ($group->bundle() !== 'org') {
      return FALSE;
    }
    $membership = GroupMembership::loadSingle($group, $account);
    if ($membership instanceof GroupMembershipInterface
      && self::isOfficeCaseWorker($group->bundle(), $account, array_keys($membership->getRoles(FALSE)))) {
      return TRUE;
    }
    $jurisdiction = $inherit_jurisdiction ? $this->parentJurisdiction($group) : NULL;
    return $jurisdiction instanceof GroupInterface && $this->administersGroup($jurisdiction, $account);
  }

  /**
   * Whether the account has the case role and direct office membership.
   *
   * @param string $group_type
   *   The bundle of the case's effective group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   * @param string[] $membership_role_ids
   *   Explicit role IDs on the account's membership in that group.
   */
  public static function isOfficeCaseWorker(string $group_type, AccountInterface $account, array $membership_role_ids): bool {
    return $group_type === 'org'
      && (int) $account->id() > 0
      && (!$account instanceof UserInterface || $account->isActive())
      && in_array('case_worker', $account->getRoles(), TRUE)
      && in_array('org-member', $membership_role_ids, TRUE);
  }

  /**
   * Tags shared by application and route access decisions for one account.
   */
  public static function cacheTags(AccountInterface $account): array {
    $uid = $account->id();
    return ["user:$uid", "group_relationship_list:plugin:group_membership:entity:$uid", 'config:group_role_list'];
  }

  /**
   * Loads the application's effective scope.
   */
  public static function effectiveGroup(FieldableEntityInterface $application, EntityTypeManagerInterface $manager): ?GroupInterface {
    foreach (['field_organisation', 'field_group', 'field_jurisdiction'] as $field_name) {
      if ($application->hasField($field_name) && !$application->get($field_name)->isEmpty()) {
        $group = $manager->getStorage('group')->load($application->get($field_name)->target_id);
        return $group instanceof GroupInterface && in_array($group->bundle(), ['org', 'jur'], TRUE) ? $group : NULL;
      }
    }
    return NULL;
  }

  /**
   * Resolves an office parent only if it is the case's recorded jurisdiction.
   */
  public static function recordedParentJurisdiction(FieldableEntityInterface $application, GroupInterface $group, EntityTypeManagerInterface $manager): ?GroupInterface {
    if ($group->bundle() !== 'org' || !$group->hasField('field_jurisdiction')) {
      return NULL;
    }
    $parent_id = (int) $group->get('field_jurisdiction')->target_id;
    if (!self::matchesRecordedJurisdiction($application, $parent_id)) {
      return NULL;
    }
    $parent = $manager->getStorage('group')->load($parent_id);
    return $parent instanceof GroupInterface && $parent->bundle() === 'jur' ? $parent : NULL;
  }

  /**
   * Whether inherited access still targets the case's recorded jurisdiction.
   */
  public static function matchesRecordedJurisdiction(FieldableEntityInterface $application, int $group_id): bool {
    return $group_id > 0 && $application->hasField('field_jurisdiction')
      && (int) $application->get('field_jurisdiction')->target_id === $group_id;
  }

  /**
   * Whether the account holds an admin group role in the group itself.
   */
  public function administersGroup(GroupInterface $group, AccountInterface $account): bool {
    $membership = GroupMembership::loadSingle($group, $account);
    return $membership instanceof GroupMembershipInterface
      && self::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Checks explicit membership roles for Group's admin flag.
   *
   * @param \Drupal\group\Entity\GroupRoleInterface[] $roles
   *   The roles explicitly assigned to the membership.
   */
  public static function hasAdminGroupRole(array $roles): bool {
    foreach ($roles as $role) {
      if ($role->isAdmin()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns an office's municipality, or NULL.
   */
  public function parentJurisdiction(GroupInterface $group): ?GroupInterface {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $target_id = (int) ($group->get('field_jurisdiction')->target_id ?? 0);
    if ($target_id <= 0) {
      return NULL;
    }
    $jurisdiction = $this->entityTypeManager->getStorage('group')->load($target_id);
    return $jurisdiction instanceof GroupInterface && $jurisdiction->bundle() === 'jur' ? $jurisdiction : NULL;
  }

}
