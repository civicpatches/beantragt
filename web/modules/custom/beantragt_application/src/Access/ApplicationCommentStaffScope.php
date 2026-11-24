<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Access;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;

/**
 * Shared staff scope for the comment endpoint and its unread message counts.
 */
class ApplicationCommentStaffScope {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Shares access dependencies only during one synchronous page read.
   */
  public function withBatch(array $applications, AccountInterface $account, callable $read): mixed {
    return ApplicationMembershipBatch::run($this->entityTypeManager, $account, $applications, $read);
  }

  /**
   * Checks staff access against the application's workspace scope.
   */
  public function allows(ApplicationInterface $application, AccountInterface $account): bool {
    if ((int) $account->id() === 1
      || $account->hasPermission('administer application entities')
      || $account->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }

    $group = ApplicationCaseScope::effectiveGroup($application, $this->entityTypeManager);
    if (!$group instanceof GroupInterface) {
      return FALSE;
    }
    if ($group->bundle() === 'jur') {
      return $this->accountHasGroupAdminRole($group, $account);
    }
    if ($this->accountIsGroupMember($group, $account)) {
      return TRUE;
    }
    $jurisdiction = ApplicationCaseScope::recordedParentJurisdiction($application, $group, $this->entityTypeManager);
    return $jurisdiction instanceof GroupInterface && $this->accountHasGroupAdminRole($jurisdiction, $account);
  }

  /**
   * Returns TRUE when the account has any membership in a group.
   */
  private function accountIsGroupMember(GroupInterface $group, AccountInterface $account): bool {
    return $this->membership($group, $account) !== NULL;
  }

  /**
   * Returns TRUE when the account has an explicit Group admin role.
   */
  private function accountHasGroupAdminRole(GroupInterface $group, AccountInterface $account): bool {
    return ApplicationCaseScope::hasAdminGroupRole($this->membership($group, $account)?->getRoles(FALSE) ?? []);
  }

  /**
   * Loads the account's membership of one workspace.
   */
  private function membership(GroupInterface $group, AccountInterface $account): ?GroupMembershipInterface {
    return ApplicationMembershipBatch::loadMembership($this->entityTypeManager, (int) $group->id(), $account);
  }

}
