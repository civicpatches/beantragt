<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Validation\Constraint;

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates form schema definitions with the shared preSave() guard logic.
 */
final class ValidFormSchemaDefinitionConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

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
    if (!$constraint instanceof ValidFormSchemaDefinitionConstraint) {
      throw new UnexpectedTypeException($constraint, ValidFormSchemaDefinitionConstraint::class);
    }
    if (!$value instanceof FormSchemaInterface) {
      return;
    }

    $schema_value = $value->get('field_schema')->value;
    if ($schema_value === NULL) {
      if (($value->get('field_workflow_status')->value ?? NULL) === 'published') {
        try {
          FormSchema::validatePublishedFormSchemaShape(NULL);
        }
        catch (\InvalidArgumentException $exception) {
          $this->addViolation($exception->getMessage());
        }
      }
      return;
    }

    try {
      FormSchema::validateFormSchemaJson($schema_value);
    }
    catch (\InvalidArgumentException $exception) {
      $this->addViolation($exception->getMessage());
      return;
    }

    $original_schema_value = $this->originalSchemaValue($value);
    try {
      FormSchema::validateFormSchemaRootId(
        $schema_value,
        $original_schema_value,
        $value->isNew(),
        $value->uuid(),
      );
    }
    catch (\InvalidArgumentException $exception) {
      $this->addViolation($exception->getMessage());
      return;
    }

    // Mirror preSave(): only new or changed definitions are re-validated, so
    // a payload stored under an older contract does not block unrelated
    // updates such as the status downgrade needed to correct it.
    if ($this->definitionRequiresValidation($value, $schema_value, $original_schema_value)) {
      try {
        FormSchema::validateFormSchemaDefinitionDetails($schema_value);
      }
      catch (\InvalidArgumentException $exception) {
        $this->addViolation($exception->getMessage());
        return;
      }
    }
    // Same rule as preSave(): only a condition the save brings in.
    if ($schema_value !== $original_schema_value) {
      try {
        FormSchema::validateConditionValues($schema_value, $original_schema_value);
      }
      catch (\InvalidArgumentException $exception) {
        $this->addViolation($exception->getMessage());
        return;
      }
    }

    if (($value->get('field_workflow_status')->value ?? NULL) !== 'published') {
      return;
    }

    // preSave() canonicalizes privacy.kind spellings before running the
    // publish checks, so validate the same normalized payload here.
    $normalized_value = FormSchema::normalizeSchemaPrivacyKinds($schema_value);
    try {
      FormSchema::validatePublishedFormSchemaShape($normalized_value);
      FormSchema::validatePublishableFormSchemaDefinition($normalized_value);
    }
    catch (\InvalidArgumentException $exception) {
      $this->addViolation($exception->getMessage());
    }
  }

  /**
   * Returns whether the schema definition is new or changed.
   */
  private function definitionRequiresValidation(
    FormSchemaInterface $entity,
    string $schema_value,
    ?string $original_schema_value,
  ): bool {
    if ($entity->isNew()) {
      return TRUE;
    }
    if (($entity->get('field_workflow_status')->value ?? NULL) === 'published') {
      return TRUE;
    }
    if ($original_schema_value === NULL) {
      return TRUE;
    }
    return $schema_value !== $original_schema_value;
  }

  /**
   * Returns the persisted schema definition for change-aware validation.
   */
  private function originalSchemaValue(FormSchemaInterface $entity): ?string {
    if ($entity->isNew()) {
      return NULL;
    }
    $original = $entity->getOriginal();
    if (!$original instanceof FormSchemaInterface) {
      $original = $this->entityTypeManager
        ->getStorage('form_schema')
        ->loadUnchanged($entity->id());
    }
    if (!$original instanceof FormSchemaInterface) {
      return NULL;
    }
    $value = $original->get('field_schema')->value;
    return is_string($value) ? $value : NULL;
  }

  /**
   * Records one safely escaped entity-level violation.
   */
  private function addViolation(string $message): void {
    $this->context->buildViolation('@detail')
      ->setParameter('@detail', $message)
      ->addViolation();
  }

}
