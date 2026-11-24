<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Result for one attachment analysis queue item.
 */
final class AttachmentAnalysisDispatchResult {

  private function __construct(
    public readonly bool $retry,
    public readonly string $code,
  ) {}

  /**
   * Creates a final result: the queue item is done, whatever the outcome.
   */
  public static function done(string $code): self {
    return new self(FALSE, $code);
  }

  /**
   * Creates a result that puts the queue item back for a later attempt.
   */
  public static function retry(string $code): self {
    return new self(TRUE, $code);
  }

}
