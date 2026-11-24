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
 * Validates default workflow status handoff per group.
 */
final class WorkflowStatusDefaultConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
    if (!$constraint instanceof WorkflowStatusDefaultConstraint) {
      return;
    }
    if (!$value instanceof WorkflowStatusInterface) {
      return;
    }

    $group_id = $value->getGroupId();
    if ($group_id === NULL) {
      return;
    }

    if ($value->isDefault()) {
      return;
    }

    $query = $this->entityTypeManager->getStorage('workflow_status')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->condition('field_is_default', TRUE)
      ->range(0, 1);

    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }

    if ($query->execute() === []) {
      $this->context->addViolation($constraint->missingMessage);
    }
  }

}
