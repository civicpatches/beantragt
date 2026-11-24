<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Access;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Read gate of the list-type JSON:API routes.
 *
 * Collection, related, relationship and existing-entity upload routes answer
 * only staff, the service account and platform administrators. Everyone else
 * is refused in the router, before the controller parses filters, sorts and
 * paging and before any collection query is built, with one fixed reason.
 *
 * @see \Drupal\beantragt_schema\Routing\JsonApiRouteSubscriber
 */
final class JsonApiReadGateAccessCheck implements AccessInterface {

  /**
   * The fixed reason of every refusal.
   */
  public const REASON = 'This JSON:API route is restricted to staff and service accounts.';

  /**
   * Allows staff, the service account and platform administrators.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $allowed = BeantragtRoles::isStaffAccount($account)
      || BeantragtRoles::isServiceAccount($account)
      || $account->hasPermission('administer site configuration');
    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden(self::REASON);
    return $result->addCacheContexts(['user.roles', 'user.permissions']);
  }

}
