<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Keeps the citizen texts of a workflow status plain and short.
 *
 * The texts reach status mails and the citizen UI, so they are plain text:
 * at most 120 characters after trimming, no control characters, no angle
 * brackets. An empty text is allowed and means "use the label".
 *
 * @Constraint(
 *   id = "WorkflowStatusCitizenLabel",
 *   label = @Translation("Workflow status citizen text", context = "Validation"),
 *   type = "entity:workflow_status"
 * )
 */
class WorkflowStatusCitizenLabelConstraint extends Constraint {

  /**
   * Maximum length of a citizen text after trimming.
   */
  public const MAX_LENGTH = 120;

  /**
   * Message shown for an invalid citizen text.
   */
  public string $message = 'The citizen text must be plain text with at most 120 characters, without control characters or angle brackets.';

}
