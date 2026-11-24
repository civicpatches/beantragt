<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Value\FitConnectSettings;

/**
 * Builds FITKO metadata from the exact plaintext bytes sent for encryption.
 */
final class FitConnectMetadataDocumentBuilder {

  /**
   * Builds metadata version 2.1.0, separately from the safe delivery manifest.
   *
   * @param string $data
   *   The exact plaintext JSON bytes.
   * @param array<int, array{attachmentId: string, bytes: string, filename: string, mimeType: string}> $attachments
   *   In-memory attachment payloads, never delivery-log metadata.
   * @param string $reference
   *   The sender reference.
   * @param string $application_date
   *   The application date in YYYY-MM-DD format.
   * @param \Drupal\beantragt_delivery\Value\FitConnectSettings $settings
   *   The submission schema configuration.
   *
   * @return array<string, mixed>
   *   The metadata document to encrypt, never to log.
   */
  public function build(string $data, array $attachments, string $reference, string $application_date, FitConnectSettings $settings): array {
    return [
      '$schema' => 'https://schema.fitko.de/fit-connect/metadata/2.1.0/metadata.schema.json',
      'contentStructure' => [
        'data' => [
          'hash' => ['type' => 'sha512', 'content' => hash('sha512', $data)],
          'submissionSchema' => [
            'schemaUri' => $settings->schemaUri,
            'mimeType' => 'application/json',
          ],
        ],
        'attachments' => array_values(array_map(static fn(array $attachment): array => [
          'attachmentId' => $attachment['attachmentId'],
          'hash' => ['type' => 'sha512', 'content' => hash('sha512', $attachment['bytes'])],
          'purpose' => 'attachment',
          'mimeType' => $attachment['mimeType'],
          'filename' => $attachment['filename'],
        ], $attachments)),
      ],
      'additionalReferenceInfo' => [
        'senderReference' => $reference,
        'applicationDate' => $application_date,
      ],
    ];
  }

}
