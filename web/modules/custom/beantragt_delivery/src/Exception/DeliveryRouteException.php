<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Exception;

/**
 * Stable error of the staff delivery route API (delivery.* codes).
 */
final class DeliveryRouteException extends \RuntimeException {

  /**
   * Constructs the error.
   *
   * @param string $errorCode
   *   A delivery.* code the UI translates.
   * @param int $status
   *   HTTP status.
   * @param array<string, string> $details
   *   Non-sensitive details, such as the offending field.
   */
  public function __construct(
    public readonly string $errorCode,
    public readonly int $status = 422,
    public readonly array $details = [],
  ) {
    parent::__construct($errorCode);
  }

}
