<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Exception;

/**
 * Signals that no analysis source could be prepared for an attachment.
 *
 * The dispatcher and upload route persist the bounded analysis code.
 * Nothing is ever sent instead: there is no fallback to the raw PDF.
 */
final class AttachmentAnalysisSourceException extends \RuntimeException {

  public function __construct(
    public readonly string $analysisCode,
    public readonly bool $retryable,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($analysisCode, 0, $previous);
  }

}
