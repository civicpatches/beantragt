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
 * Validates organisation jurisdiction references.
 */
class OrgRootJurisdictionReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs an OrgRootJurisdictionReferenceConstraintValidator.
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
    if (!$constraint instanceof OrgRootJurisdictionReferenceConstraint) {
      return;
    }

    if (!$value instanceof GroupInterface || $value->bundle() !== 'org') {
      return;
    }

    if (!$value->hasField('field_jurisdiction') || $value->get('field_jurisdiction')->isEmpty()) {
      $this->context->buildViolation($constraint->missingJurisdictionMessage)
        ->atPath('field_jurisdiction')
        ->addViolation();
      return;
    }

    $jurisdiction_field = $value->get('field_jurisdiction');
    if (!$jurisdiction_field instanceof EntityReferenceFieldItemListInterface) {
      $this->context->buildViolation($constraint->invalidJurisdictionMessage)
        ->atPath('field_jurisdiction')
        ->addViolation();
      return;
    }

    $jurisdictions = $jurisdiction_field->referencedEntities();
    $jurisdiction = reset($jurisdictions);
    if (!$jurisdiction instanceof GroupInterface || !$this->isJurisdictionGroup($jurisdiction)) {
      $this->context->buildViolation($constraint->invalidJurisdictionMessage)
        ->atPath('field_jurisdiction')
        ->addViolation();
      return;
    }

    if ($jurisdiction->hasField('field_parent_jurisdiction')
      && !$jurisdiction->get('field_parent_jurisdiction')->isEmpty()) {
      $this->context->buildViolation($constraint->message)
        ->atPath('field_jurisdiction')
        ->addViolation();
    }
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
