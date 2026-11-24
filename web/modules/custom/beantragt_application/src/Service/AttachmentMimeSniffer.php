<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Shared magic-byte allowlist for application and bulk attachments.
 */
final class AttachmentMimeSniffer {

  /**
   * Detects the supported MIME type from a bounded prefix.
   */
  public function sniff(string $bytes): ?string {
    if (str_starts_with($bytes, '%PDF-')) {
      return 'application/pdf';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
      return 'image/png';
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
      return 'image/jpeg';
    }
    return NULL;
  }

  /**
   * Reads only the signature of a local upload.
   */
  public function fromPath(string $path): ?string {
    $handle = @fopen($path, 'rb');
    if ($handle === FALSE) {
      return NULL;
    }
    try {
      $bytes = fread($handle, 12);
      return is_string($bytes) ? $this->sniff($bytes) : NULL;
    }
    finally {
      fclose($handle);
    }
  }

}
