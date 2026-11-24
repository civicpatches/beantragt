<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates the stored form schema definition on entity validation.
 *
 * Reuses the guards that FormSchema::preSave() enforces as exceptions, so
 * that JSON:API POST/PATCH requests fail entity validation with a 422 and a
 * meaningful detail message instead of a 500 from the storage layer. The
 * preSave() exceptions stay in place as defense in depth for direct entity
 * saves that skip validation.
 */
#[Constraint(
  id: 'ValidFormSchemaDefinition',
  label: new TranslatableMarkup('Valid form schema definition', [], ['context' => 'Validation']),
  type: 'entity',
)]
final class ValidFormSchemaDefinitionConstraint extends SymfonyConstraint {
}
