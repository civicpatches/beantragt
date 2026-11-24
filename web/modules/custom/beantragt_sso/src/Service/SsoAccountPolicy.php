<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\user\UserInterface;

/**
 * Separates automatic account linking from interactive login eligibility.
 */
final class SsoAccountPolicy {

  /**
   * Constructs the policy.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Only active human accounts can open an interactive SSO session.
   */
  public function assertInteractiveLoginAllowed(UserInterface $user): void {
    if (!$user->isActive() || BeantragtRoles::isServiceAccount($user)) {
      throw new SsoAuthenticationException('account_ineligible', 'SSO account is not eligible for interactive login.');
    }
  }

  /**
   * Returns whether email linking would grant a privileged identity.
   */
  public function isPrivileged(UserInterface $user): bool {
    return (int) $user->id() === 1
      || array_diff($user->getRoles(), [AccountInterface::AUTHENTICATED_ROLE]) !== [];
  }

  /**
   * Existing human staff/admin bindings require administrative provenance.
   */
  public function requiresManualBinding(UserInterface $user): bool {
    if ((int) $user->id() === 1 || BeantragtRoles::isStaffAccount($user) || BeantragtRoles::isServiceAccount($user)) {
      return TRUE;
    }
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple($user->getRoles());
    foreach ($roles as $role) {
      if ($role->isAdmin()) {
        return TRUE;
      }
      foreach ($role->getPermissions() as $permission) {
        if (str_starts_with($permission, 'administer ') || str_starts_with($permission, 'bypass ')) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Refuses automatic email linking into privileged accounts.
   */
  public function assertEmailLinkAllowed(UserInterface $user): void {
    $this->assertInteractiveLoginAllowed($user);
    if ($this->isPrivileged($user)) {
      throw new SsoAuthenticationException('account_privileged', 'Privileged users must be explicitly pre-linked.');
    }
  }

}
