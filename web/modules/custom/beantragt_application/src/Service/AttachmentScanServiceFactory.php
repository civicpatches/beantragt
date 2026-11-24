<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Selects the configured attachment scanner for the current environment.
 */
final class AttachmentScanServiceFactory {

  /**
   * Creates the scanner configured through environment variables.
   */
  public function create(): AttachmentScanServiceInterface {
    $is_production_like = $this->isProductionLike();
    $default_mode = $is_production_like ? 'clamav' : 'eicar';
    $mode = strtolower(trim((string) (getenv('BEANTRAGT_ATTACHMENT_SCANNER') ?: $default_mode)));
    if (!in_array($mode, ['eicar', 'clamav', 'auto'], TRUE)) {
      $mode = $default_mode;
    }

    if ($is_production_like && $mode === 'eicar') {
      $mode = 'clamav';
    }

    if ($mode === 'clamav') {
      return $this->clamAvScanner();
    }

    if ($mode === 'auto') {
      $clamav = $this->clamAvScanner();
      if ($clamav->isAvailable()) {
        return $clamav;
      }
      if ($is_production_like) {
        return $clamav;
      }
    }

    return new AttachmentScanService();
  }

  /**
   * Creates a ClamAV scanner from environment configuration.
   */
  private function clamAvScanner(): ClamAvAttachmentScanService {
    $host = trim((string) (getenv('BEANTRAGT_CLAMAV_HOST') ?: 'clamav'));
    $port = (int) (getenv('BEANTRAGT_CLAMAV_PORT') ?: 3310);
    $timeout = (float) (getenv('BEANTRAGT_CLAMAV_TIMEOUT_SECONDS') ?: 5.0);

    return new ClamAvAttachmentScanService(
      $host !== '' ? $host : 'clamav',
      $port > 0 ? $port : 3310,
      $timeout > 0 ? $timeout : 5.0,
    );
  }

  /**
   * Returns TRUE when silent scanner fallback would be unsafe.
   */
  private function isProductionLike(): bool {
    if (getenv('IS_DDEV_PROJECT') === 'true') {
      return FALSE;
    }

    $environment = strtolower(trim((string) (getenv('BEANTRAGT_ENV') ?: '')));
    if ($environment === '') {
      return TRUE;
    }

    return !in_array($environment, [
      'dev',
      'local',
      'ddev',
      'development',
      'test',
      'testing',
    ], TRUE);
  }

}
