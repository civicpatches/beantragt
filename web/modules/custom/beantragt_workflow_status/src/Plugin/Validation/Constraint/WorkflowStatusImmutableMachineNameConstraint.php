<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Prevents changing immutable status fields after creation.
 *
 * @Constraint(
 *   id = "WorkflowStatusImmutableMachineName",
 *   label = @Translation("Workflow status immutable fields", context = "Validation"),
 *   type = "entity:workflow_status"
 * )
 */
class WorkflowStatusImmutableMachineNameConstraint extends Constraint {

  /**
   * Message shown when the machine name is changed.
   */
  public string $message = 'The workflow status machine name cannot be changed after creation.';

  /**
   * Message shown when the owning group is changed.
   */
  public string $groupMessage = 'The workflow status workspace cannot be changed after creation.';

}
