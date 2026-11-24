<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\ComplexDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates SSO provider mailbox provenance configuration.
 */
final class SsoProviderEmailVerificationConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof SsoProviderEmailVerificationConstraint) {
      throw new UnexpectedTypeException($constraint, SsoProviderEmailVerificationConstraint::class);
    }
    $settings = $value instanceof ComplexDataInterface ? $value->getValue() : $value;
    if (!is_array($settings)) {
      return;
    }
    foreach ($settings['providers'] ?? [] as $provider) {
      if (is_array($provider)
        && !empty($provider['link_by_email'])
        && ($provider['email_verified'] ?? FALSE) !== TRUE) {
        $this->context->addViolation($constraint->message);
        return;
      }
    }
  }

}
