<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;

/**
 * Authorizes personal preferences and defaults in the selected staff workspace.
 */
final class CaseLayoutAccess {

  /**
   * Constructs the workspace access policy.
   */
  public function __construct(
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * Checks staff access, optionally requiring workspace management authority.
   */
  public function allows(AccountInterface $account, GroupInterface $workspace, bool $manage = FALSE): bool {
    if (!$account->isAuthenticated() || !BeantragtRoles::isStaffAccount($account)
      || !in_array($workspace->bundle(), ['jur', 'org'], TRUE) || !$workspace->isPublished()) {
      return FALSE;
    }
    if ((int) $account->id() === 1 || $account->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    if ($manage && !BeantragtRoles::isManagementAccount($account)) {
      return FALSE;
    }
    $jurisdiction_id = $workspace->bundle() === 'jur' ? (int) $workspace->id()
      : (int) ($workspace->hasField('field_jurisdiction') ? $workspace->get('field_jurisdiction')->target_id : 0);
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $group = $membership->getGroup();
      $admin = ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
      if ((int) $group->id() === (int) $workspace->id()
        && ($admin || (!$manage && $workspace->bundle() === 'org'))) {
        return TRUE;
      }
      if ($admin && $group->bundle() === 'jur' && $jurisdiction_id > 0
        && $this->hierarchyResolver->isSameOrDescendantById($jurisdiction_id, (int) $group->id())) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
