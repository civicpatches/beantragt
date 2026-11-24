<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Immutable result for an application attachment scan.
 */
final class AttachmentScanResult {

  public const STATUS_CLEAN = 'clean';
  public const STATUS_QUARANTINED = 'quarantined';

  /**
   * Constructs an immutable scan result.
   */
  public function __construct(
    private readonly string $status,
    private readonly string $message = '',
    private readonly bool $verifiedClean = FALSE,
  ) {}

  /**
   * Returns the scanner status.
   */
  public function status(): string {
    return $this->status;
  }

  /**
   * Returns the bounded scanner explanation.
   */
  public function message(): string {
    return $this->message;
  }

  /**
   * Distinguishes a positive antivirus verdict from local fallback/defaults.
   */
  public function isVerifiedClean(): bool {
    return $this->status === self::STATUS_CLEAN && $this->verifiedClean;
  }

  /**
   * Reports whether transport and downloads must be blocked.
   */
  public function isQuarantined(): bool {
    return $this->status === self::STATUS_QUARANTINED;
  }

}
