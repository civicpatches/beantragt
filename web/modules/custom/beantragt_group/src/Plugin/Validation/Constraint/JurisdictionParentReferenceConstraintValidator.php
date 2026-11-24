<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates jurisdiction parent references.
 */
class JurisdictionParentReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a JurisdictionParentReferenceConstraintValidator.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof JurisdictionParentReferenceConstraint) {
      return;
    }

    if (!$value instanceof GroupInterface || !$this->isJurisdictionGroup($value)) {
      return;
    }

    if (!$value->hasField('field_parent_jurisdiction')) {
      return;
    }

    $parent_field = $value->get('field_parent_jurisdiction');
    if ($parent_field->isEmpty() || !$parent_field instanceof EntityReferenceFieldItemListInterface) {
      return;
    }

    $parents = $parent_field->referencedEntities();
    $parent = reset($parents);
    if (!$parent instanceof GroupInterface) {
      return;
    }

    if (!$this->isJurisdictionGroup($parent)) {
      $this->context->buildViolation($constraint->parentTypeMessage)
        ->atPath('field_parent_jurisdiction')
        ->addViolation();
      return;
    }

    $value_id = (int) $value->id();
    if ($value_id > 0 && (int) $parent->id() === $value_id) {
      $this->context->buildViolation($constraint->selfReferenceMessage)
        ->atPath('field_parent_jurisdiction')
        ->addViolation();
      return;
    }

    if ($this->createsCircularReference($value, $parent)) {
      $this->context->buildViolation($constraint->circularReferenceMessage)
        ->atPath('field_parent_jurisdiction')
        ->addViolation();
    }
  }

  /**
   * Checks whether assigning parent creates or joins a circular hierarchy.
   */
  protected function createsCircularReference(GroupInterface $group, GroupInterface $parent): bool {
    $group_id = (int) $group->id();
    if ($group_id <= 0) {
      return FALSE;
    }

    $visited = [];
    $current = $parent;
    for ($depth = 0; $depth < 64; $depth++) {
      $current_id = (int) $current->id();
      if ($current_id <= 0) {
        return FALSE;
      }
      if ($current_id === $group_id) {
        return TRUE;
      }
      if (isset($visited[$current_id])) {
        return TRUE;
      }
      $visited[$current_id] = TRUE;

      if (!$current->hasField('field_parent_jurisdiction')) {
        return FALSE;
      }
      $parent_field = $current->get('field_parent_jurisdiction');
      if ($parent_field->isEmpty() || !$parent_field instanceof EntityReferenceFieldItemListInterface) {
        return FALSE;
      }

      $parents = $parent_field->referencedEntities();
      $next = reset($parents);
      if (!$next instanceof GroupInterface || !$this->isJurisdictionGroup($next)) {
        return FALSE;
      }

      $current = $next;
    }

    return TRUE;
  }

  /**
   * Checks whether a group is the configured jurisdiction bundle.
   */
  protected function isJurisdictionGroup(GroupInterface $group): bool {
    return $group->bundle() === $this->jurisdictionGroupType();
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  protected function jurisdictionGroupType(): string {
    $configured = $this->configFactory->get('beantragt_group.settings')->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
