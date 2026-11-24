<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

/**
 * Internal FIT-Connect submission metadata manifest.
 *
 * This value is the pre-encryption manifest. It deliberately contains schema,
 * routing and integrity metadata only, not raw submitted field values or file
 * contents.
 */
final class FitConnectSubmissionMetadata {

  /**
   * Constructs the manifest.
   *
   * @param array<string, mixed> $metadata
   *   Submission metadata manifest.
   */
  public function __construct(
    private readonly array $metadata,
  ) {}

  /**
   * Returns the full internal manifest.
   *
   * @return array<string, mixed>
   *   Metadata manifest.
   */
  public function toArray(): array {
    return $this->metadata;
  }

  /**
   * Returns a stable hash of the manifest.
   */
  public function hash(): string {
    return hash('sha256', $this->json());
  }

  /**
   * Returns metadata safe for delivery logs and preflight diagnostics.
   *
   * @return array<string, mixed>
   *   Secret-free metadata summary.
   */
  public function toSafeMetadata(): array {
    $content_structure = $this->metadata['contentStructure'] ?? [];
    $data = is_array($content_structure) ? ($content_structure['data'] ?? []) : [];
    $schema = is_array($data) ? ($data['submissionSchema'] ?? []) : [];

    return [
      'submissionMetadataReady' => TRUE,
      'submissionMetadataHash' => $this->hash(),
      'metadataSchemaVersion' => (string) ($this->metadata['metadataSchemaVersion'] ?? ''),
      'serviceUrn' => (string) ($this->metadata['service']['identifier'] ?? ''),
      'region' => (string) ($this->metadata['service']['region'] ?? ''),
      'submissionSchema' => is_array($schema) ? array_filter([
        'schemaUri' => (string) ($schema['schemaUri'] ?? ''),
        'mimeType' => (string) ($schema['mimeType'] ?? ''),
      ], static fn(string $value): bool => $value !== '') : [],
      'fieldCount' => (int) ($this->metadata['contentSummary']['fieldCount'] ?? 0),
      'attachmentCount' => (int) ($this->metadata['contentSummary']['attachmentCount'] ?? 0),
    ];
  }

  /**
   * Encodes the manifest deterministically.
   */
  private function json(): string {
    return json_encode($this->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

}
