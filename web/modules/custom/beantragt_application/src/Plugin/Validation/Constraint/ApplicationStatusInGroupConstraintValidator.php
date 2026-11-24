<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Plugin\Validation\Constraint;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that an application's workflow status belongs to its workspace.
 *
 * The workflow_status entity is provided by beantragt_workflow_status. The
 * referenced status entity is checked via its field_group field so this
 * constraint stays independent of workflow runtime services.
 */
class ApplicationStatusInGroupConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ApplicationStatusInGroupConstraint) {
      return;
    }
    if (!$value instanceof FieldItemListInterface) {
      return;
    }

    $application = $value->getEntity();
    if (!$application instanceof ApplicationInterface) {
      return;
    }

    if ($value->isEmpty()) {
      $default_status_id = NULL;
      if ($application->isNew() && function_exists('_beantragt_application_default_workflow_status_id')) {
        $default_status_id = _beantragt_application_default_workflow_status_id($application);
      }
      if ($default_status_id === NULL) {
        $this->context->buildViolation($constraint->requiredMessage)
          ->addViolation();
      }
      return;
    }

    $status = $value->entity;
    if (!$status instanceof FieldableEntityInterface
      || $status->getEntityTypeId() !== 'workflow_status'
      || !$status->hasField('field_group')) {
      // A missing or invalid reference is handled by the field's own
      // ValidReference constraint.
      return;
    }

    // Resolve the application's workflow owner. Organisation scope wins, and
    // jurisdiction-direct schemas fall back to the jurisdiction catalogue.
    $workflow_group_id = NULL;
    if (function_exists('_beantragt_application_resolve_workflow_group_id')) {
      $workflow_group_id = _beantragt_application_resolve_workflow_group_id($application);
    }

    if ($workflow_group_id === NULL) {
      $this->context->buildViolation($constraint->missingScopeMessage)
        ->addViolation();
      return;
    }

    if ($status->get('field_group')->isEmpty()) {
      $this->context->buildViolation($constraint->missingScopeMessage)
        ->addViolation();
      return;
    }

    $status_group_id = (int) $status->get('field_group')->target_id;
    if ($status_group_id > 0 && $status_group_id !== $workflow_group_id) {
      $this->context->buildViolation($constraint->message)
        ->addViolation();
    }
  }

}
