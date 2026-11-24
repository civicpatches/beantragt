<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires verified mailbox claims for automatic account linking.
 */
#[Constraint(
  id: 'SsoProviderEmailVerification',
  label: new TranslatableMarkup('Verified SSO mailbox claims', [], ['context' => 'Validation']),
  type: ['config_object']
)]
final class SsoProviderEmailVerificationConstraint extends SymfonyConstraint {

  /**
   * The validation error message.
   */
  public string $message = 'Linking users by email requires verified email claims.';

}
