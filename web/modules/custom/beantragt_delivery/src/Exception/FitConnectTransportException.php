<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Exception;

/**
 * Safe exception for FIT-Connect transport failures.
 */
final class FitConnectTransportException extends \RuntimeException {

  /**
   * Constructs the exception.
   */
  public function __construct(
    public readonly string $errorCode,
    string $message,
    public readonly int $statusCode = 0,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}
