<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires group workspace assignments and form references to be authorized.
 */
#[Constraint(
  id: 'FormSchemaGroupCreateScope',
  label: new TranslatableMarkup('Form schema group workspace scope', [], ['context' => 'Validation']),
  type: 'entity',
)]
final class FormSchemaGroupCreateScopeConstraint extends SymfonyConstraint {

  /**
   * The entity-level violation message.
   */
  public string $message = '@detail';

}
