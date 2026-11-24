<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for form_schema_group entities.
 */
class FormSchemaGroupAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    switch ($operation) {
      case 'view':
        if ($entity->isPublished()) {
          return AccessResult::allowedIfHasPermission($account, 'view published form_schema_group entities')
            ->addCacheableDependency($entity);
        }
        return AccessResult::allowedIfHasPermission($account, 'view unpublished form_schema_group entities')
          ->addCacheableDependency($entity);

      case 'update':
        if ($account->hasPermission('edit any form_schema_group entities')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        return AccessResult::allowedIf(
          $account->hasPermission('edit own form_schema_group entities') &&
          (int) $account->id() === (int) $entity->getOwnerId()
        )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);

      case 'delete':
        if ($account->hasPermission('delete any form_schema_group entities')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        return AccessResult::allowedIf(
          $account->hasPermission('delete own form_schema_group entities') &&
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
    // Drupal checks this before the submitted entity is available. The
    // entity checks the target workspace and each reference again at save.
    $allowed = $account->hasPermission('create form_schema_group entities')
      || ($account->hasPermission($this->entityType->getAdminPermission())
        && ($account->hasPermission('bypass beantragt group scope') || BeantragtRoles::isServiceAccount($account)));
    return AccessResult::allowedIf($account->isAuthenticated() && $allowed)
      ->cachePerPermissions()
      ->cachePerUser();
  }

}
