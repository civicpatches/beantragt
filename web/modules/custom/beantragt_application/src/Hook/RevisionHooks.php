<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\ApplicationAssignmentPolicy;
use Drupal\Core\Entity\EntityStorageException;

/**
 * Captures the final domain state with explicit Drupal 11 hook ordering.
 */
final class RevisionHooks {

  /**
   * Builds action metadata after the other domain presave hooks.
   */
  #[Hook('entity_presave', order: Order::Last)]
  public function entityPresave(EntityInterface $entity): void {
    if (!$entity instanceof ApplicationInterface) {
      return;
    }
    // Scope normalization and routing can run after the entity-type guard.
    // Enforce the opt-out again against the final state before it is stored.
    if ($entity->hasField('field_assigned_to') && !$entity->get('field_assigned_to')->isEmpty()
      && !ApplicationAssignmentPolicy::personalAssignmentEnabled($entity)
      && !ApplicationAssignmentPolicy::retainsExistingAssignment($entity)) {
      throw new EntityStorageException('Personal assignment is disabled for this tenant.');
    }
    $entity->setRevisionLogMessage(RevisionLog::build($entity, [
      'field_organisation' => 'responsibility',
      'field_assigned_to' => 'person',
      'field_assigned_team' => 'team',
      'field_workflow_status' => 'status',
    ]));
  }

}
