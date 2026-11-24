<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures applications are only re-homed into manageable tenant scopes.
 *
 * @Constraint(
 *   id = "ApplicationTargetScope",
 *   label = @Translation("Application target scope authorization", context = "Validation"),
 *   type = "field"
 * )
 */
final class ApplicationTargetScopeConstraint extends Constraint {

  /**
   * Message shown when the target tenant scope is not manageable.
   */
  public string $message = 'You are not authorized to move this application to "@scope".';

}
