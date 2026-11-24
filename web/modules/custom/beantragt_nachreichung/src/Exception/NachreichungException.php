<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Exception;

/**
 * A refusal with a stable nachreichung.* code for the staff API envelope.
 */
final class NachreichungException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $errorCode
   *   Code of contract section 10.
   * @param int $status
   *   HTTP status.
   * @param array<string, mixed> $details
   *   Value-free details (limit, retryAfter).
   * @param int|null $retryAfter
   *   Seconds for Retry-After, if any.
   */
  public function __construct(
    public readonly string $errorCode,
    public readonly int $status = 409,
    public readonly array $details = [],
    public readonly ?int $retryAfter = NULL,
  ) {
    parent::__construct($errorCode);
  }

}
