<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Scans uploaded application attachment bytes before they can be claimed.
 */
interface AttachmentScanServiceInterface {

  /**
   * Scans one already MIME-validated upload.
   */
  public function scan(string $contents, string $filename, string $mime_type): AttachmentScanResult;

}
