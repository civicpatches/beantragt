<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Protects native jurisdiction settings writes with API-equivalent checks.
 *
 * @Constraint(
 *   id = "JurisdictionSettingsSafety",
 *   label = @Translation("Jurisdiction settings safety", context = "Validation"),
 *   type = "entity:group"
 * )
 */
final class JurisdictionSettingsSafetyConstraint extends Constraint {

  /**
   * Validation message for the complete jurisdiction configuration.
   */
  public string $configMessage = 'Jurisdiction configuration is invalid: @error';

  /**
   * Validation message for jurisdiction custom CSS.
   */
  public string $cssMessage = 'Custom CSS is invalid: @error';

  /**
   * Validation message for an individual jurisdiction field.
   */
  public string $fieldMessage = '@field is invalid: @error';

  /**
   * Validation message for unauthorized retention changes.
   */
  public string $retentionPermissionMessage = 'Changing retention settings requires the retention administration permission.';

}
