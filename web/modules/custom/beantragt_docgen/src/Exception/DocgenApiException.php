<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Exception;

/**
 * Error with a stable code for the staff API and its i18n keys.
 *
 * Codes use the dotted house style (docgen.busy, docgen.preview_missing).
 * Details never contain document content or personal data.
 */
final class DocgenApiException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $errorCode
   *   Stable error code, for example docgen.busy.
   * @param int $status
   *   HTTP status for the staff API.
   * @param array<string, mixed> $details
   *   Safe details (no content, no personal data).
   * @param int|null $retryAfter
   *   Seconds after which a retry makes sense (busy answers only).
   */
  public function __construct(
    public readonly string $errorCode,
    public readonly int $status = 422,
    public readonly array $details = [],
    public readonly ?int $retryAfter = NULL,
  ) {
    parent::__construct($errorCode);
  }

}
