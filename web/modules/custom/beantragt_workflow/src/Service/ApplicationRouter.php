<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\ApplicationAssignmentPolicy;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes new applications to the appropriate assignee.
 *
 * The default routing strategy assigns applications to the first admin member
 * of the application's organisation. If no organisation is available, it falls
 * back to the jurisdiction group.
 */
class ApplicationRouter {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ApplicationRouter.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('beantragt_workflow');
  }

  /**
   * Routes an application to the appropriate assignee.
   *
   * Determines the default assignee based on explicit organisation or
   * jurisdiction scope.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   */
  public function route(ApplicationInterface $application): void {
    if (!ApplicationAssignmentPolicy::personalAssignmentEnabled($application)) {
      return;
    }
    // Only route if not already assigned.
    if (!$application->get('field_assigned_to')->isEmpty()) {
      return;
    }

    $groupId = $this->resolveRoutingGroupId($application);
    if ($groupId === NULL) {
      $this->logger->notice('Application @id has no routing group, skipping routing.', [
        '@id' => $application->id() ?? 'new',
      ]);
      return;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      $this->logger->warning('Application @id references non-existent group @gid.', [
        '@id' => $application->id() ?? 'new',
        '@gid' => $groupId,
      ]);
      return;
    }

    $assignee = $this->findGroupAdmin($groupId, $group->bundle());
    if ($assignee) {
      $application->set('field_assigned_to', ['target_id' => $assignee]);
      $this->logger->info('Application @id auto-assigned to user @uid (group @gid admin).', [
        '@id' => $application->id() ?? 'new',
        '@uid' => $assignee,
        '@gid' => $groupId,
      ]);
    }
    else {
      $this->logger->notice('No admin member found for group @gid. Application @id unassigned.', [
        '@gid' => $groupId,
        '@id' => $application->id() ?? 'new',
      ]);
    }
  }

  /**
   * Resolves the group used for assignment.
   *
   * Organisation wins because it models the responsible department. The legacy
   * field_group and jurisdiction field remain fallbacks for existing records
   * and jurisdiction-wide workflows.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   *
   * @return int|null
   *   The group ID used for routing, or NULL.
   */
  protected function resolveRoutingGroupId(ApplicationInterface $application): ?int {
    foreach (['field_organisation', 'field_jurisdiction', 'field_group'] as $fieldName) {
      if (!$application->hasField($fieldName) || $application->get($fieldName)->isEmpty()) {
        continue;
      }

      $groupId = (int) $application->get($fieldName)->target_id;
      if ($groupId > 0) {
        return $groupId;
      }
    }

    return NULL;
  }

  /**
   * Finds the first admin member of a group.
   *
   * Queries the group_relationship table for members with the admin role
   * in the given group.
   *
   * @param int $groupId
   *   The group entity ID.
   * @param string $groupBundle
   *   The group bundle (e.g. 'org', 'jur').
   *
   * @return int|null
   *   The user ID of the first admin, or NULL if none found.
   */
  protected function findGroupAdmin(int $groupId, string $groupBundle): ?int {
    // Group relationship entities store membership.
    // The admin role ID follows the pattern: {bundle}-admin.
    $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');

    $membershipIds = $relationshipStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $groupId)
      ->condition('plugin_id', $groupBundle . '-group_membership')
      ->range(0, 50)
      ->execute();

    if (empty($membershipIds)) {
      return NULL;
    }

    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return NULL;
    }

    $memberships = $relationshipStorage->loadMultiple($membershipIds);
    foreach ($memberships as $membership) {
      $userId = (int) $membership->get('entity_id')->target_id;
      $user = $this->entityTypeManager->getStorage('user')->load($userId);
      if (!$user) {
        continue;
      }

      $member = $group->getMember($user);
      if ($member) {
        $roles = $member->getRoles();
        foreach ($roles as $role) {
          if ($role->id() === $groupBundle . '-admin') {
            return $userId;
          }
        }
      }
    }

    // Fallback: return the first member if no admin found.
    $firstMembership = reset($memberships);
    if ($firstMembership) {
      return (int) $firstMembership->get('entity_id')->target_id;
    }

    return NULL;
  }

}
