<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that allowed next workflow statuses stay in the same group.
 */
final class WorkflowStatusAllowedNextInGroupConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof WorkflowStatusAllowedNextInGroupConstraint) {
      return;
    }
    if (!$value instanceof WorkflowStatusInterface) {
      return;
    }

    $group_id = $value->getGroupId();
    if ($group_id === NULL) {
      return;
    }

    if ($value->isTerminal() && !$value->get('field_allowed_next')->isEmpty()) {
      $this->context->buildViolation($constraint->terminalMessage)
        ->atPath('field_allowed_next')
        ->addViolation();
      return;
    }

    foreach ($value->get('field_allowed_next')->referencedEntities() as $target) {
      if (!$target instanceof WorkflowStatusInterface) {
        continue;
      }
      if ($target->getGroupId() !== $group_id) {
        $this->context->buildViolation($constraint->message)
          ->setParameter('%label', $target->label())
          ->atPath('field_allowed_next')
          ->addViolation();
      }
    }
  }

}
