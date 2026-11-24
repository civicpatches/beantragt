<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Scans attachment bytes through clamd's TCP INSTREAM protocol.
 */
final class ClamAvAttachmentScanService implements AttachmentScanServiceInterface {

  private const UNAVAILABLE_MESSAGE = 'Attachment scanner unavailable.';

  public function __construct(
    private readonly string $host = 'clamav',
    private readonly int $port = 3310,
    private readonly float $timeoutSeconds = 5.0,
  ) {}

  /**
   * Returns TRUE when clamd accepts TCP connections.
   */
  public function isAvailable(): bool {
    $socket = $this->openSocket();
    if (!is_resource($socket)) {
      return FALSE;
    }
    fclose($socket);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function scan(string $contents, string $filename, string $mime_type): AttachmentScanResult {
    $deterministic = (new AttachmentScanService())->scan($contents, $filename, $mime_type);
    if ($deterministic->isQuarantined()) {
      return $deterministic;
    }

    $socket = $this->openSocket();
    if (!is_resource($socket)) {
      return new AttachmentScanResult(
        AttachmentScanResult::STATUS_QUARANTINED,
        self::UNAVAILABLE_MESSAGE,
      );
    }

    try {
      fwrite($socket, "zINSTREAM\0");
      foreach (str_split($contents, 8192) as $chunk) {
        fwrite($socket, pack('N', strlen($chunk)) . $chunk);
      }
      fwrite($socket, pack('N', 0));

      $response = '';
      while (!feof($socket)) {
        $part = fread($socket, 8192);
        if ($part === FALSE || $part === '') {
          break;
        }
        $response .= $part;
        if (str_contains($response, "\0") || str_ends_with($response, "\n")) {
          break;
        }
      }
    }
    finally {
      fclose($socket);
    }

    return $this->resultFromResponse($response);
  }

  /**
   * Opens one clamd socket.
   *
   * @return resource|false
   *   A stream socket, or FALSE when clamd cannot be reached.
   */
  private function openSocket() {
    $socket = @stream_socket_client(
      sprintf('tcp://%s:%d', $this->host, $this->port),
      $errno,
      $errstr,
      $this->timeoutSeconds,
      STREAM_CLIENT_CONNECT,
    );
    if (!is_resource($socket)) {
      return FALSE;
    }
    stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));
    return $socket;
  }

  /**
   * Converts the clamd response into the public scan result contract.
   */
  private function resultFromResponse(string $response): AttachmentScanResult {
    $normalized = trim(str_replace("\0", '', $response));

    if (str_ends_with($normalized, ': OK')) {
      return new AttachmentScanResult(AttachmentScanResult::STATUS_CLEAN, '', TRUE);
    }

    if (str_contains($normalized, 'FOUND')) {
      $signature = trim((string) preg_replace('/^.*?:\s*(.*?)\s+FOUND$/', '$1', $normalized));
      $message = $signature !== '' && $signature !== $normalized
        ? sprintf('ClamAV detected malware: %s.', $signature)
        : 'ClamAV detected malware.';
      return new AttachmentScanResult(
        AttachmentScanResult::STATUS_QUARANTINED,
        $message,
      );
    }

    return new AttachmentScanResult(
      AttachmentScanResult::STATUS_QUARANTINED,
      self::UNAVAILABLE_MESSAGE,
    );
  }

}
