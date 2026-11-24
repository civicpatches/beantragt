<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures each group has exactly one default workflow status.
 *
 * @Constraint(
 *   id = "WorkflowStatusDefault",
 *   label = @Translation("Workflow status default", context = "Validation"),
 *   type = "entity:workflow_status"
 * )
 */
class WorkflowStatusDefaultConstraint extends Constraint {

  /**
   * Message shown when another default already exists in the group.
   */
  public string $duplicateMessage = 'Another default workflow status already exists for this group.';

  /**
   * Message shown when saving would leave the group without a default.
   */
  public string $missingMessage = 'Each group must have exactly one default workflow status.';

}
