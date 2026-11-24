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
 * Validates that a workflow status machine name is unique within its group.
 */
final class WorkflowStatusUniqueMachineNameConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
    if (!$constraint instanceof WorkflowStatusUniqueMachineNameConstraint) {
      return;
    }
    if (!$value instanceof WorkflowStatusInterface) {
      return;
    }

    $machine_name = $value->getMachineName();
    $group_id = $value->getGroupId();
    if ($machine_name === '' || $group_id === NULL) {
      // Empty values are handled by field-level required validation.
      return;
    }

    $query = $this->entityTypeManager->getStorage('workflow_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->condition('field_machine_name', $machine_name)
      ->range(0, 2);

    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }

    $duplicates = $query->execute();
    if (!empty($duplicates)) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('%name', $machine_name)
        ->atPath('field_machine_name')
        ->addViolation();
    }
  }

}
