<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Plugin\Validation\Constraint;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates immutable workflow status fields.
 */
final class WorkflowStatusImmutableMachineNameConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof WorkflowStatusImmutableMachineNameConstraint) {
      return;
    }
    if (!$value instanceof WorkflowStatusInterface || $value->isNew()) {
      return;
    }

    $original = $this->entityTypeManager->getStorage('workflow_status')->loadUnchanged($value->id());
    if (!$original instanceof WorkflowStatusInterface) {
      return;
    }

    if ($original->getMachineName() !== $value->getMachineName()) {
      $this->context->buildViolation($constraint->message)
        ->atPath('field_machine_name')
        ->addViolation();
    }

    if ($original->getGroupId() !== $value->getGroupId()) {
      $this->context->buildViolation($constraint->groupMessage)
        ->atPath('field_group')
        ->addViolation();
    }
  }

}
