<?php

declare(strict_types=1);

namespace Drupal\beantragt_roles_api;

use Drupal\Core\Session\AccountInterface;

/**
 * Single source of truth for service-account and staff role detection.
 *
 * Before this class existed, "is this a backend service account" and "is
 * this a staff member" checks share one role list so access checks cannot
 * accidentally omit `case_worker`.
 */
final class BeantragtRoles {

  /**
   * Roles treated as backend service accounts, never as citizen profiles.
   *
   * `nuxt_service` is the *username* of the Basic Auth service account used
   * for server-to-server API calls (created in
   * beantragt_application.install:318), not a Drupal role machine name. It
   * can never appear in AccountInterface::getRoles(), so older call sites
   * that checked for `['service_account', 'nuxt_service']` were checking a
   * role that does not exist alongside the real one. Only `service_account`
   * is an actual role.
   */
  public const SERVICE_ACCOUNT_ROLES = ['service_account'];

  /**
   * Roles treated as staff (case workers and above).
   */
  public const STAFF_ROLES = ['case_worker', 'department_admin', 'administrator'];

  /**
   * Roles allowed to manage tenant configuration and admin-only metadata.
   */
  public const MANAGEMENT_ROLES = ['department_admin', 'administrator'];

  /**
   * Roles that process applications as a person (case work).
   *
   * Narrower than STAFF_ROLES: the global `administrator` role is system
   * administration, not case work, so an account that holds only that role is
   * no person a case can be assigned to.
   */
  public const CASE_PROCESSING_ROLES = ['case_worker', 'department_admin'];

  /**
   * Returns TRUE when the account holds a backend service account role.
   */
  public static function isServiceAccount(AccountInterface $account): bool {
    return array_intersect(self::SERVICE_ACCOUNT_ROLES, $account->getRoles()) !== [];
  }

  /**
   * Returns TRUE when the account holds a staff role.
   *
   * This does not special-case uid 1: callers that treat the superuser as
   * staff regardless of roles must OR that check in separately.
   */
  public static function isStaffAccount(AccountInterface $account): bool {
    return array_intersect(self::STAFF_ROLES, $account->getRoles()) !== [];
  }

  /**
   * Returns TRUE when the account holds a management role.
   *
   * This is intentionally narrower than STAFF_ROLES: case workers can process
   * applications, but they must not manage tenant configuration.
   */
  public static function isManagementAccount(AccountInterface $account): bool {
    return array_intersect(self::MANAGEMENT_ROLES, $account->getRoles()) !== [];
  }

  /**
   * Returns TRUE when the account is a person who processes applications.
   *
   * Excludes uid 1 (the installation's system account), service accounts and
   * accounts whose only staff role is `administrator`.
   */
  public static function isCaseProcessingAccount(AccountInterface $account): bool {
    return (int) $account->id() > 1
      && !self::isServiceAccount($account)
      && array_intersect(self::CASE_PROCESSING_ROLES, $account->getRoles()) !== [];
  }

}
