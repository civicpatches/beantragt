<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires form schemas to stay inside the acting account's workspaces.
 */
#[Constraint(
  id: 'FormSchemaWorkspaceScope',
  label: new TranslatableMarkup('Form schema workspace scope', [], ['context' => 'Validation']),
  type: 'entity',
)]
final class FormSchemaWorkspaceScopeConstraint extends SymfonyConstraint {

  /**
   * Safely parameterized entity-level validation message.
   */
  public string $message = '@detail';

}
