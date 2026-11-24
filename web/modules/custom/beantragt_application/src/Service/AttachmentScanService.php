<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Local deterministic attachment scan used until an external scanner is wired.
 */
final class AttachmentScanService implements AttachmentScanServiceInterface {

  /**
   * EICAR anti-malware test signature.
   */
  private const EICAR_SIGNATURE = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

  /**
   * {@inheritdoc}
   */
  public function scan(string $contents, string $filename, string $mime_type): AttachmentScanResult {
    if (str_contains($contents, self::EICAR_SIGNATURE)) {
      return new AttachmentScanResult(
        AttachmentScanResult::STATUS_QUARANTINED,
        'EICAR test signature detected.',
      );
    }

    return new AttachmentScanResult(AttachmentScanResult::STATUS_CLEAN);
  }

}
