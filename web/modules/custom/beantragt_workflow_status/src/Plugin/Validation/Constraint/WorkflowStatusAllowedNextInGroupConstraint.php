<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures allowed next statuses belong to the same group.
 *
 * @Constraint(
 *   id = "WorkflowStatusAllowedNextInGroup",
 *   label = @Translation("Workflow status allowed next in group", context = "Validation"),
 *   type = "entity:workflow_status"
 * )
 */
class WorkflowStatusAllowedNextInGroupConstraint extends Constraint {

  /**
   * Message shown when an allowed next status belongs to another group.
   */
  public string $message = 'Allowed next status "%label" must belong to the same group.';

  /**
   * Message shown when a terminal status has outgoing transitions.
   */
  public string $terminalMessage = 'Terminal statuses cannot define allowed next statuses.';

}
