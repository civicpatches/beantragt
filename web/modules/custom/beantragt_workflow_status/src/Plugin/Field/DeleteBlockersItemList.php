<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Field;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow_status\Service\WorkflowStatusManager;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computes why a workflow status cannot be deleted.
 *
 * Backs the read-only field delete_blockers: one item per code of
 * WorkflowStatusManager::DELETE_BLOCKER_CODES, in that order, computed by
 * deleteBlockers(), the function the delete access check asks. A status
 * that is not saved yet has none and is not marked as computed.
 */
final class DeleteBlockersItemList extends FieldItemList {

  use ComputedItemListTrait {
    ensureComputedValue as computeOnce;
  }

  /**
   * Computes only for a saved status.
   *
   * A new status (validate() during a JSON:API POST) is not marked as
   * computed, so the response of the POST computes the blockers of the
   * saved status instead of carrying an empty list for a question nobody
   * asked yet: a form may already list the new machine name.
   */
  protected function ensureComputedValue(): void {
    if ($this->getEntity()->isNew()) {
      return;
    }
    $this->computeOnce();
  }

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $status = $this->getEntity();
    if (!$status instanceof WorkflowStatusInterface || $status->isNew()) {
      return;
    }
    // Computed item lists are typed data plugins without injection.
    $manager = \Drupal::service('beantragt_workflow_status.status_manager');
    $codes = array_values(array_intersect(WorkflowStatusManager::DELETE_BLOCKER_CODES, $manager->deleteBlockers($status)));
    foreach ($codes as $delta => $code) {
      $this->list[$delta] = $this->createItem($delta, $code);
    }
  }

}
