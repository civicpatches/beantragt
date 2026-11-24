<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow_status\Service\WorkflowStatusManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the access control handler for the workflow_status entity type.
 *
 * Direct entity access is intentionally staff/service only. Citizen-facing
 * status rendering goes through application/status endpoints that scope the
 * status definition through an application reference.
 */
final class WorkflowStatusAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Constructs the access handler.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\beantragt_workflow_status\Service\WorkflowStatusManager $statusManager
   *   The workflow status manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected readonly WorkflowStatusManager $statusManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('beantragt_workflow_status.status_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    switch ($operation) {
      case 'view':
        if (!$entity instanceof WorkflowStatusInterface || !$this->canViewDefinitions($account)) {
          return AccessResult::forbidden('User may not view workflow status definitions.')
            ->cachePerPermissions()
            ->cachePerUser();
        }
        return $this->checkGroupAccess($entity, $account, FALSE);

      case 'update':
        if (!$entity instanceof WorkflowStatusInterface || !$this->canWriteDefinitions($account)) {
          return AccessResult::forbidden('User may not update workflow status definitions.')
            ->cachePerPermissions()
            ->cachePerUser();
        }
        return $this->checkGroupAccess($entity, $account, TRUE);

      case 'delete':
        if (!$entity instanceof WorkflowStatusInterface || !$this->canWriteDefinitions($account)) {
          return AccessResult::forbidden('User may not delete workflow status definitions.')
            ->cachePerPermissions()
            ->cachePerUser();
        }
        $group_access = $this->checkGroupAccess($entity, $account, TRUE);
        if (!$group_access->isAllowed()) {
          return $group_access;
        }
        $blockers = $this->statusManager->deleteBlockers($entity);
        if ($blockers !== []) {
          // The reason reaches the client as errors[0].detail of the 403.
          return AccessResult::forbidden(WorkflowStatusManager::deleteBlockerReason($blockers))
            ->addCacheableDependency($entity)
            ->cachePerPermissions()
            ->cachePerUser()
            ->setCacheMaxAge(0);
        }
        // Not cacheable either: a later application or form adds a blocker
        // without touching the status.
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->cachePerUser()
          ->setCacheMaxAge(0);

      default:
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   *
   * The computed delete_blockers field is read-only and shown only to
   * accounts that may write definitions (the settings page). It is never
   * cached: applications and forms change it without touching the status.
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($field_definition->getName() !== 'delete_blockers') {
      return parent::checkFieldAccess($operation, $field_definition, $account, $items);
    }
    if ($operation !== 'view') {
      return AccessResult::forbidden('delete_blockers is computed.');
    }
    if (!$this->canWriteDefinitions($account)) {
      return AccessResult::forbidden('Only definition administrators read delete blockers.')->cachePerPermissions();
    }
    return AccessResult::allowed()->cachePerPermissions()->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIf($this->canWriteDefinitions($account))
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * Checks whether an account may read workflow definitions directly.
   */
  protected function canViewDefinitions(AccountInterface $account): bool {
    return $this->canWriteDefinitions($account)
      || $account->hasPermission('manage workflow transitions')
      || $account->hasPermission('view workflow assignments')
      || $account->hasPermission('view published application entities')
      || $account->hasPermission('view unpublished application entities');
  }

  /**
   * Checks whether an account may write workflow definitions directly.
   */
  protected function canWriteDefinitions(AccountInterface $account): bool {
    return $account->hasPermission($this->entityType->getAdminPermission());
  }

  /**
   * Checks whether an account may access definitions for a status group.
   */
  protected function checkGroupAccess(WorkflowStatusInterface $status, AccountInterface $account, bool $write): AccessResultInterface {
    if ($account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::allowed()
        ->addCacheableDependency($status)
        ->cachePerPermissions()
        ->cachePerUser();
    }

    $group_id = $status->getGroupId();
    if ($group_id === NULL) {
      return AccessResult::forbidden('Workflow status has no owning group.')
        ->addCacheableDependency($status)
        ->cachePerPermissions()
        ->cachePerUser();
    }

    $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden('Workflow status references a missing group.')
        ->addCacheableDependency($status)
        ->cachePerPermissions()
        ->cachePerUser();
    }

    $allowed = $write
      ? $this->accountCanWriteGroup($group, $account)
      : $this->accountCanReadGroup($group, $account);
    $result = $allowed
      ? AccessResult::allowed()
      : AccessResult::forbidden('User may not access workflow statuses for this group.');
    $result
      ->addCacheableDependency($status)
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser();

    $jurisdiction = $this->statusJurisdiction($group);
    if ($jurisdiction instanceof GroupInterface) {
      $result->addCacheableDependency($jurisdiction);
    }
    return $result;
  }

  /**
   * Checks group-read access.
   */
  protected function accountCanReadGroup(GroupInterface $group, AccountInterface $account): bool {
    if ($group->getMember($account)) {
      return TRUE;
    }

    $jurisdiction = $this->statusJurisdiction($group);
    return $jurisdiction instanceof GroupInterface
      && $this->accountAdministersGroup($jurisdiction, $account);
  }

  /**
   * Checks group-write access.
   */
  protected function accountCanWriteGroup(GroupInterface $group, AccountInterface $account): bool {
    if ($this->accountAdministersGroup($group, $account)) {
      return TRUE;
    }

    $jurisdiction = $this->statusJurisdiction($group);
    return $jurisdiction instanceof GroupInterface
      && $this->accountAdministersGroup($jurisdiction, $account);
  }

  /**
   * Checks group-admin access.
   */
  protected function accountAdministersGroup(GroupInterface $group, AccountInterface $account): bool {
    if (!function_exists('_beantragt_group_account_has_group_admin_role')) {
      return FALSE;
    }
    return _beantragt_group_account_has_group_admin_role($group, $account);
  }

  /**
   * Resolves the jurisdiction owning an organisation status group.
   *
   * Jurisdiction-owned statuses return NULL here because their own group is the
   * access boundary.
   */
  protected function statusJurisdiction(GroupInterface $group): ?GroupInterface {
    if ($group->bundle() !== 'org'
      || !$group->hasField('field_jurisdiction')
      || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $jurisdiction = $group->get('field_jurisdiction')->entity;
    return $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL;
  }

}
