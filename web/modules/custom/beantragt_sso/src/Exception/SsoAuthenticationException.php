<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Exception;

/**
 * Carries a bounded failure category without exposing provider payloads.
 */
final class SsoAuthenticationException extends \RuntimeException {

  /**
   * Constructs a failure with a locally assigned category.
   */
  public function __construct(public readonly string $failureCode, string $message) {
    parent::__construct($message);
  }

}
