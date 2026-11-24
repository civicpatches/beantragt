<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Access\ApplicationMembershipBatch;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the access control handler for the application entity type.
 *
 * The staff v1 counts and report (StaffApplicationStatistics) cannot check
 * rows one by one; they mirror the staff list's view rules in SQL: the tenant
 * scope of TenantScopedEntityQuery plus the published/unpublished view
 * permissions checked below. A new view rule here or in a
 * hook_application_access() implementation must be mirrored there as well,
 * or the counts and the staff list drift apart. JSON:API collection queries
 * mirror these rules in the JsonApiGroupQueryAccess service.
 * The same applies to the short-info list of unconfirmed applications
 * (StaffApplicationApiController::unconfirmedApplicationScope()), which
 * repeats the scoped view rules because the entity stays inaccessible
 * until the e-mail address is confirmed.
 *
 * @see \Drupal\beantragt_group\Service\JsonApiGroupQueryAccess
 */
final class ApplicationAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Constructs the access handler.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfirmedApplicationMailbox $confirmedMailbox,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('beantragt_application.confirmed_mailbox'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(EntityInterface $entity, $operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $this->prepareUser($account);
    if ($account instanceof UserInterface && !$account->isActive()) {
      $result = AccessResult::forbidden('Inactive accounts cannot access applications.');
    }
    else {
      $result = parent::access($entity, $operation, $account, TRUE);
    }
    // A neutral scope hook can lose its cacheability when another access
    // checker grants. Attach the account dependencies to the final result.
    if ($result instanceof AccessResult) {
      $result->cachePerUser()->addCacheTags(ApplicationCaseScope::cacheTags($account));
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view all revisions') {
      if ((int) $account->id() <= 0 || (!$account->hasPermission('administer application entities')
        && !$account->hasPermission('bypass beantragt group scope')
        && !$account->hasPermission('view workflow assignments'))) {
        return AccessResult::forbidden('Application revisions are restricted to staff.')
          ->cachePerPermissions()->cachePerUser();
      }
      // JSON:API uses this separate operation for non-default revisions.
      // Reuse the full view checks, including current and historical scope.
      $view = $entity->access('view', $account, TRUE);
      return $view instanceof AccessResult ? $view->cachePerPermissions()->cachePerUser() : $view;
    }

    if ($operation === 'view' && (int) $account->id() === 0) {
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($entity);
    }

    $is_admin = $account->hasPermission($this->entityType->getAdminPermission());
    $staff_delete = $operation === 'delete'
      && ($is_admin || $account->hasPermission('delete any application entities'));
    if (in_array($operation, ['view', 'update', 'delete'], TRUE)
      && !$staff_delete
      && !$this->isEmailConfirmed($entity)) {
      return AccessResult::forbidden('Application applicant email is not confirmed.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity);
    }
    if ($is_admin) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($entity);
    }

    switch ($operation) {
      case 'view':
        // Authenticated users with general view permission.
        $staff_view_access = AccessResult::neutral()->cachePerPermissions();
        if ($entity->isPublished()) {
          $access = AccessResult::allowedIfHasPermission($account, 'view published application entities');
          if ($access->isAllowed()) {
            $staff_view_access = $this->checkScopedStaffViewAccess($entity, $account);
            if ($staff_view_access->isAllowed()) {
              return $access
                ->andIf($staff_view_access)
                ->addCacheableDependency($entity);
            }
          }
        }
        else {
          $access = AccessResult::allowedIfHasPermission($account, 'view unpublished application entities');
          if ($access->isAllowed()) {
            $staff_view_access = $this->checkScopedStaffViewAccess($entity, $account);
            if ($staff_view_access->isAllowed()) {
              return $access
                ->andIf($staff_view_access)
                ->addCacheableDependency($entity);
            }
          }
        }

        $own_application = $account->hasPermission('view own application entities')
          && $this->isOwnApplication($entity, $account);
        if ($staff_view_access->isForbidden() && !$own_application) {
          return $staff_view_access;
        }

        // Own applications: check ownership by uid or by the verified account
        // email captured on public submissions.
        if ($account->hasPermission('view own application entities')) {
          return AccessResult::allowedIf($own_application)
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheTags(['user:' . $account->id()])
            ->addCacheableDependency($entity);
        }

        return AccessResult::neutral()->cachePerPermissions();

      case 'update':
        if ($account->hasPermission('edit any application entities')) {
          return AccessResult::allowed()->cachePerPermissions()->addCacheableDependency($entity);
        }
        return AccessResult::allowedIf(
          $account->hasPermission('edit own application entities') &&
          (int) $account->id() === (int) $entity->getOwnerId()
        )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);

      case 'delete':
        if ($account->hasPermission('delete any application entities')) {
          return $this->checkScopedStaffDeleteAccess($entity, $account);
        }
        // A submitted application belongs to the record of the authority and
        // leaves it only through the retention rules or staff. Citizens delete
        // their account and profile under "Meine Daten"; the legacy
        // "delete own application entities" permission grants nothing here.
        // Forbidden, so no hook_entity_access() can reopen it.
        return AccessResult::forbidden('Submitted applications stay with the authority.')
          ->cachePerPermissions();

      default:
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->hasPermission($this->entityType->getAdminPermission())
      || (
        $this->isServiceAccount($account)
        && $account->hasPermission('create application entities')
      )
    )->cachePerPermissions()->cachePerUser();
  }

  /**
   * Returns TRUE when the account owns the application.
   */
  private function isOwnApplication(EntityInterface $entity, AccountInterface $account): bool {
    if ((int) $account->id() <= 0 || $this->isServiceAccount($account)) {
      return FALSE;
    }
    if ((int) $account->id() === (int) $entity->getOwnerId()) {
      return TRUE;
    }
    return $entity instanceof ApplicationInterface && $this->confirmedMailbox->allowsRead($entity, $account);
  }

  /**
   * Returns TRUE when the application has completed email confirmation.
   */
  private function isEmailConfirmed(EntityInterface $entity): bool {
    if (!$entity instanceof FieldableEntityInterface
      || !$entity->hasField('field_email_confirmed')
      || $entity->get('field_email_confirmed')->isEmpty()) {
      return TRUE;
    }

    return (bool) $entity->get('field_email_confirmed')->value;
  }

  /**
   * Checks the tenant scope for broad staff view permissions.
   */
  private function checkScopedStaffViewAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if (!$entity instanceof FieldableEntityInterface) {
      $access = AccessResult::forbidden('Application view requires tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity);
      return $this->addMembershipCacheTags($access, $account);
    }

    $scope = $this->loadEffectiveScopeGroup($entity);
    if (!$scope instanceof GroupInterface) {
      $access = AccessResult::forbidden('Application view requires tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity);
      return $this->addMembershipCacheTags($access, $account);
    }

    if (!in_array($scope->bundle(), ['org', 'jur'], TRUE)) {
      $access = AccessResult::forbidden('Application view references an invalid tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity)
        ->addCacheableDependency($scope);
      return $this->addMembershipCacheTags($access, $account);
    }

    if ($this->accountIsGroupMember($scope, $account)) {
      $access = AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity)
        ->addCacheableDependency($scope);
      return $this->addMembershipCacheTags($access, $account);
    }

    $jurisdiction = ApplicationCaseScope::recordedParentJurisdiction($entity, $scope, $this->entityTypeManager);
    if ($jurisdiction instanceof GroupInterface && $jurisdiction->bundle() === 'jur'
      && ApplicationCaseScope::hasAdminGroupRole($this->membership($jurisdiction, $account)?->getRoles(FALSE) ?? [])) {
      $access = AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity)
        ->addCacheableDependency($scope)
        ->addCacheableDependency($jurisdiction);
      return $this->addMembershipCacheTags($access, $account);
    }

    $access = AccessResult::forbidden('Application view requires membership in the application tenant scope.')
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($scope);
    if ($jurisdiction instanceof GroupInterface) {
      $access->addCacheableDependency($jurisdiction);
    }
    return $this->addMembershipCacheTags($access, $account);
  }

  /**
   * Checks tenant administrator scope for permanent application deletion.
   */
  private function checkScopedStaffDeleteAccess(EntityInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if (!$entity instanceof FieldableEntityInterface) {
      $access = AccessResult::forbidden('Application deletion requires tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity);
      return $this->addMembershipCacheTags($access, $account);
    }

    $scope = $this->loadEffectiveScopeGroup($entity);
    if (!$scope instanceof GroupInterface
      || !in_array($scope->bundle(), ['org', 'jur'], TRUE)) {
      $access = AccessResult::forbidden('Application deletion requires a valid tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity);
      return $this->addMembershipCacheTags($access, $account);
    }

    $membership = $this->membership($scope, $account);
    $roles = $membership?->getRoles(FALSE) ?? [];
    if (ApplicationCaseScope::hasAdminGroupRole($roles)
      || ApplicationCaseScope::isOfficeCaseWorker($scope->bundle(), $account, array_keys($roles))) {
      $access = AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity)
        ->addCacheableDependency($scope);
      return $this->addMembershipCacheTags($access, $account);
    }

    $jurisdiction = ApplicationCaseScope::recordedParentJurisdiction($entity, $scope, $this->entityTypeManager);
    if ($jurisdiction instanceof GroupInterface && $jurisdiction->bundle() === 'jur'
      && ApplicationCaseScope::hasAdminGroupRole($this->membership($jurisdiction, $account)?->getRoles(FALSE) ?? [])) {
      $access = AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity)
        ->addCacheableDependency($scope)
        ->addCacheableDependency($jurisdiction);
      return $this->addMembershipCacheTags($access, $account);
    }

    $access = AccessResult::forbidden('Application deletion requires an administrator role in the application tenant scope.')
      ->cachePerPermissions()
      ->cachePerUser()
      ->addCacheableDependency($entity)
      ->addCacheableDependency($scope);
    if ($jurisdiction instanceof GroupInterface) {
      $access->addCacheableDependency($jurisdiction);
    }
    return $this->addMembershipCacheTags($access, $account);
  }

  /**
   * Loads the effective application scope group.
   */
  private function loadEffectiveScopeGroup(FieldableEntityInterface $entity): ?GroupInterface {
    foreach (['field_organisation', 'field_group', 'field_jurisdiction'] as $field_name) {
      $group = $this->loadGroupFromField($entity, $field_name);
      if ($group instanceof GroupInterface) {
        return $group;
      }
    }

    return NULL;
  }

  /**
   * Loads a referenced group from an entity field.
   */
  private function loadGroupFromField(FieldableEntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }

    $group_id = (int) $entity->get($field_name)->target_id;
    if ($group_id <= 0) {
      return NULL;
    }

    if (!$this->entityTypeManager->hasDefinition('group')) {
      return NULL;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Returns TRUE when an account has any membership in a group.
   */
  private function accountIsGroupMember(GroupInterface $group, AccountInterface $account): bool {
    return $this->membership($group, $account) !== NULL;
  }

  /**
   * Loads the current user's explicit membership in the group.
   */
  private function membership(GroupInterface $group, AccountInterface $account): ?GroupMembershipInterface {
    return ApplicationMembershipBatch::loadMembership($this->entityTypeManager, (int) $group->id(), $account);
  }

  /**
   * Adds cache tags for group membership changes affecting one account.
   */
  private function addMembershipCacheTags(AccessResultInterface $access, AccountInterface $account): AccessResultInterface {
    $uid = (int) $account->id();
    if ($uid <= 0 || !$access instanceof RefinableCacheableDependencyInterface) {
      return $access;
    }

    $access->addCacheTags(ApplicationCaseScope::cacheTags($account));
    return $access;
  }

  /**
   * Returns TRUE when an account is a backend service account.
   */
  private function isServiceAccount(AccountInterface $account): bool {
    return BeantragtRoles::isServiceAccount($account);
  }

}
