<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the applicant entity type.
 */
class ApplicantAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view' && (int) $account->id() === 0) {
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($entity);
    }

    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    switch ($operation) {
      case 'view':
        // Applicant profiles contain cross-form CRM data. Broad staff access
        // must go through scoped application/admin APIs, never raw JSON:API.
        if ($account->hasPermission('view own applicant entities')) {
          return AccessResult::allowedIf(
            (int) $account->id() === (int) $entity->getOwnerId()
          )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);
        }

        return AccessResult::neutral()->cachePerPermissions();

      case 'update':
        if ($account->hasPermission('edit any applicant entities')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        return AccessResult::allowedIf(
          $account->hasPermission('edit own applicant entities') &&
          (int) $account->id() === (int) $entity->getOwnerId()
        )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);

      case 'delete':
        if ($account->hasPermission('delete any applicant entities')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        return AccessResult::allowedIf(
          $account->hasPermission('delete own applicant entities') &&
          (int) $account->id() === (int) $entity->getOwnerId()
        )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);

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
        BeantragtRoles::isServiceAccount($account)
        && $account->hasPermission('create applicant entities')
      )
    )->cachePerPermissions()->cachePerUser();
  }

}
