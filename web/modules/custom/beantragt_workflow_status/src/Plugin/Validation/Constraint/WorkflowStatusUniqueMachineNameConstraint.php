<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures a workflow status machine name is unique within its owning group.
 *
 * @Constraint(
 *   id = "WorkflowStatusUniqueMachineName",
 *   label = @Translation("Workflow status unique machine name", context = "Validation"),
 *   type = "entity:workflow_status"
 * )
 */
class WorkflowStatusUniqueMachineNameConstraint extends Constraint {

  /**
   * Message shown when the machine name already exists in the group.
   */
  public string $message = 'A workflow status with machine name "%name" already exists for this group.';

}
