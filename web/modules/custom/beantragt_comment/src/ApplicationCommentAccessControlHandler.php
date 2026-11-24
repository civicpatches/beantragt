<?php

declare(strict_types=1);

namespace Drupal\beantragt_comment;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;

/**
 * Defines the access control handler for the application_comment entity type.
 */
class ApplicationCommentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    // Messages of erased accounts belong to the anonymous user (uid 0). An
    // anonymous visitor must never match that owner, whatever permissions
    // the anonymous role carries.
    if ((int) $account->id() === 0) {
      return AccessResult::forbidden('Anonymous users cannot access application messages.')
        ->cachePerUser();
    }

    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    switch ($operation) {
      case 'view':
        $permission = AccessResult::allowedIfHasPermission($account, 'view application_comment entities')
          ->cachePerUser()->addCacheableDependency($entity)->addCacheContexts(['user.roles']);
        return $permission->isAllowed()
          ? $permission->andIf($this->commentApplicationAccess($entity, $account))
          : $permission;

      case 'delete':
        if ($account->hasPermission('delete any application_comment entities')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        $application = $this->commentApplication($entity);
        if ($application instanceof ApplicationInterface && $application->isEmailConfirmed()) {
          // Messages on a submitted application belong to the record of the
          // authority, like the application itself.
          return AccessResult::forbidden('Messages on submitted applications stay with the authority.')
            ->cachePerPermissions()
            ->addCacheableDependency($entity)
            ->addCacheableDependency($application);
        }
        return AccessResult::allowedIf(
          $account->hasPermission('delete own application_comment entities') &&
          (int) $account->id() === (int) $entity->getOwnerId()
        )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);

      default:
        // Comments are immutable. No update operation.
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
        && $account->hasPermission('create application_comment entities')
      )
    )->cachePerPermissions()->cachePerUser();
  }

  /**
   * Checks whether the account can access the related application.
   */
  protected function commentApplicationAccess(EntityInterface $comment, AccountInterface $account): AccessResultInterface {
    $application = $this->commentApplication($comment);
    if (!$application instanceof ApplicationInterface) {
      return AccessResult::forbidden('The comment has no application.');
    }

    if ($this->isStaff($account)) {
      return $application->access('view', $account, TRUE);
    }

    return AccessResult::allowedIf((int) $application->getOwnerId() === (int) $account->id())
      ->cachePerUser()->addCacheableDependency($application);
  }

  /**
   * Loads the application a comment belongs to, or NULL.
   */
  protected function commentApplication(EntityInterface $comment): ?ApplicationInterface {
    if (!$comment instanceof FieldableEntityInterface
      || !$comment->hasField('field_application')
      || $comment->get('field_application')->isEmpty()) {
      return NULL;
    }
    $application = $comment->get('field_application')->entity;
    return $application instanceof ApplicationInterface ? $application : NULL;
  }

  /**
   * Identifies staff accounts for application view access.
   */
  protected function isStaff(AccountInterface $account): bool {
    return (int) $account->id() === 1 || BeantragtRoles::isStaffAccount($account);
  }

}
