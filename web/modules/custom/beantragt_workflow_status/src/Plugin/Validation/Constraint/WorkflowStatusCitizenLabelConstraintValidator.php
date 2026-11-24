<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the citizen texts of a workflow status.
 */
final class WorkflowStatusCitizenLabelConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof WorkflowStatusCitizenLabelConstraint || !$value instanceof WorkflowStatusInterface) {
      return;
    }
    foreach (WorkflowStatusInterface::CITIZEN_LANGCODES as $langcode) {
      $field = 'field_citizen_label_' . $langcode;
      if (!$value->hasField($field) || $value->get($field)->isEmpty()) {
        continue;
      }
      if (!self::isValid((string) $value->get($field)->value)) {
        $this->context->buildViolation($constraint->message)
          ->atPath($field)
          ->addViolation();
      }
    }
  }

  /**
   * Whether one citizen text is acceptable; empty is.
   */
  public static function isValid(string $text): bool {
    $text = trim($text);
    return mb_strlen($text) <= WorkflowStatusCitizenLabelConstraint::MAX_LENGTH
      && preg_match('/[\x00-\x1F\x7F<>]/u', $text) === 0;
  }

}
