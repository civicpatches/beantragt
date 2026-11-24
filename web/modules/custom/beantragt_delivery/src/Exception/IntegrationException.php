<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Exception;

/**
 * Carries stable, non-sensitive management API errors.
 */
final class IntegrationException extends \RuntimeException {

  /**
   * Constructs a safe integration error.
   */
  public function __construct(
    public readonly string $errorCode,
    public readonly int $status = 422,
    public readonly array $errors = [],
  ) {
    parent::__construct($errorCode);
  }

}
