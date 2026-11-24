<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership as GroupMembershipEntity;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\user\UserInterface;

/**
 * Shared delivery and integration tenant membership checks.
 */
final class ScopeAccess {

  /**
   * Constructs the scope checker.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Requires an enabled account with an admin role in the jurisdiction.
   *
   * Integrations belong to the jurisdiction, which holds the connection to
   * the case system, so only jurisdiction admins manage them. Organisation
   * admins administer their workflow, not transfers. Legacy delivery access
   * deliberately retains its original membership policy.
   */
  public function allowsAdministration(int $scope, AccountInterface $account): bool {
    if (!$account->isAuthenticated() || $scope < 1) {
      return FALSE;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return FALSE;
    }
    $group = $this->entityTypeManager->getStorage('group')->load($scope);
    if (!$group instanceof GroupInterface || $group->bundle() !== 'jur') {
      return FALSE;
    }
    if ($account->hasPermission('administer application entities') || $account->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    return $this->accountHasGroupAdminRole($group, $account);
  }

  /**
   * Checks delivery permission and tenant scope membership.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer application entities')
      || $account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    if (!$account->hasPermission('manage application deliveries')) {
      return AccessResult::forbidden('Missing delivery administration permission.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    if (!$application->access('view', $account)) {
      return AccessResult::forbidden('Missing application view access.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    $group = $this->scopeGroup($application);
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden('Application has no tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->setCacheMaxAge(0)
        ->addCacheableDependency($application);
    }

    $allowed = $this->accountIsGroupMember($group, $account);
    $jurisdiction = NULL;
    if (!$allowed && $group->bundle() === 'org') {
      $jurisdiction = $this->parentJurisdiction($group);
      $allowed = $jurisdiction instanceof GroupInterface
        && $this->accountHasGroupAdminRole($jurisdiction, $account);
    }

    $result = AccessResult::allowedIf($allowed)
      ->cachePerPermissions()
      ->cachePerUser()
      ->setCacheMaxAge(0)
      ->addCacheableDependency($application)
      ->addCacheableDependency($group);
    if ($jurisdiction instanceof GroupInterface) {
      $result->addCacheableDependency($jurisdiction);
    }

    return $allowed
      ? $result
      : AccessResult::forbidden('User may not manage deliveries for this tenant.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->setCacheMaxAge(0)
        ->addCacheableDependency($application)
        ->addCacheableDependency($group);
  }

  /**
   * Checks the independent dispatch permission and editable case scope.
   */
  public function canApproveDispatch(ApplicationInterface $application, AccountInterface $account): bool {
    return $account->hasPermission('approve application dispatch')
      && $application->access('update', $account)
      && $this->allowsCaseScope($application, $account);
  }

  /**
   * Checks access to the staff-only transfer projection.
   */
  public function transferAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(
      ($account->hasPermission('manage application deliveries') || $account->hasPermission('approve application dispatch'))
      && $application->access('view', $account)
      && $this->allowsCaseScope($application, $account)
    )->cachePerPermissions()->cachePerUser()->setCacheMaxAge(0)->addCacheableDependency($application);
  }

  /**
   * Requires a live account and delivery-level tenant membership.
   */
  private function allowsCaseScope(ApplicationInterface $application, AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return FALSE;
    }
    $group = $this->scopeGroup($application);
    if (!$group instanceof GroupInterface) {
      return FALSE;
    }
    if ($account->hasPermission('administer application entities') || $account->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    if ($this->accountIsGroupMember($group, $account)) {
      return TRUE;
    }
    $parent = $group->bundle() === 'org' ? $this->parentJurisdiction($group) : NULL;
    return $parent instanceof GroupInterface && $this->accountHasGroupAdminRole($parent, $account);
  }

  /**
   * Resolves the scope whose integration definitions apply to an application.
   *
   * Assignments live in the jurisdiction of their source form, also when the
   * form belongs to one of its organisations. The case must sit in that
   * jurisdiction or one of its organisations; any other combination has no
   * integration scope. Authorization always stays with the case scope.
   */
  public function integrationScope(ApplicationInterface $application): int {
    $case = $this->scopeGroup($application);
    $case_jurisdiction = $case instanceof GroupInterface ? $this->jurisdictionOf($case) : NULL;
    if (!$case_jurisdiction instanceof GroupInterface) {
      return 0;
    }
    $form = $application->hasField('field_schema') ? ($application->get('field_schema')->entity ?? NULL) : NULL;
    if (!$form instanceof ContentEntityInterface) {
      return (int) $case_jurisdiction->id();
    }
    $form_scope = $this->formIntegrationScope($form);
    return $form_scope === (int) $case_jurisdiction->id() ? $form_scope : 0;
  }

  /**
   * Resolves the jurisdiction that holds a form's integration definitions.
   *
   * A form of an organisation uses the definitions of the organisation's
   * jurisdiction, so assigning a form to an organisation keeps its transfer
   * rules. Returns 0 when the form has no resolvable jurisdiction.
   */
  public function formIntegrationScope(ContentEntityInterface $form): int {
    $group = $this->scopeGroup($form);
    return (int) ($group instanceof GroupInterface ? $this->jurisdictionOf($group)?->id() : 0);
  }

  /**
   * Resolves the application tenant scope group.
   */
  public function scopeGroup(ContentEntityInterface $application): ?GroupInterface {
    foreach (['field_organisation', 'field_group', 'field_jurisdiction'] as $field_name) {
      if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
        continue;
      }
      $group = $application->get($field_name)->entity ?? NULL;
      if ($group instanceof GroupInterface) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * Returns TRUE when the account is a member of the group.
   */
  private function accountIsGroupMember(GroupInterface $group, AccountInterface $account): bool {
    return GroupMembershipEntity::loadSingle($group, $account) instanceof GroupMembershipInterface;
  }

  /**
   * Returns TRUE when the account has an admin role in the group.
   */
  private function accountHasGroupAdminRole(GroupInterface $group, AccountInterface $account): bool {
    $membership = GroupMembershipEntity::loadSingle($group, $account);
    return $membership instanceof GroupMembershipInterface
      && ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Returns the group itself for a jurisdiction, else an org's jurisdiction.
   */
  private function jurisdictionOf(GroupInterface $group): ?GroupInterface {
    return match ($group->bundle()) {
      'jur' => $group,
      'org' => $this->parentJurisdiction($group),
      default => NULL,
    };
  }

  /**
   * Resolves an organisation's parent jurisdiction.
   */
  private function parentJurisdiction(GroupInterface $group): ?GroupInterface {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $jurisdiction = $group->get('field_jurisdiction')->entity ?? NULL;
    return $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL;
  }

}
