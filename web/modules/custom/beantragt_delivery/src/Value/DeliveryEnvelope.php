<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

/**
 * Non-sensitive application delivery envelope.
 *
 * The envelope deliberately carries counts, identifiers and hashes only. Raw
 * field_data and attachment binaries are resolved by concrete adapters when
 * a transport genuinely needs them.
 */
final class DeliveryEnvelope {

  /**
   * Redacted attachment manifest.
   *
   * @var array<int, array<string, int|string>>
   */
  public readonly array $attachmentManifest;

  /**
   * Constructs a delivery envelope.
   *
   * @param int $applicationId
   *   Application ID.
   * @param string $applicationUuid
   *   Application UUID.
   * @param string $referenceNumber
   *   Sender reference.
   * @param string $schemaUuid
   *   Schema UUID.
   * @param string $schemaLabel
   *   Schema label.
   * @param int $schemaVersion
   *   Schema version.
   * @param string $dataHash
   *   SHA-256 hash of stored application JSON.
   * @param int $fieldCount
   *   Field count.
   * @param int $attachmentCount
   *   Attachment count.
   * @param array<string, mixed> $workspace
   *   Jurisdiction and organisation projection.
   * @param array<int, array<string, int|string>> $attachmentManifest
   *   Opaque attachment identifiers and sizes.
   */
  public function __construct(
    public readonly int $applicationId,
    public readonly string $applicationUuid,
    public readonly string $referenceNumber,
    public readonly string $schemaUuid,
    public readonly string $schemaLabel,
    public readonly int $schemaVersion,
    public readonly string $dataHash,
    public readonly int $fieldCount,
    public readonly int $attachmentCount,
    public readonly array $workspace = [],
    array $attachmentManifest = [],
  ) {
    $this->attachmentManifest = self::normalizeAttachmentManifest($attachmentManifest);

    if ($this->applicationId <= 0) {
      throw new \InvalidArgumentException('Application ID must be positive.');
    }
    if ($this->applicationUuid === '') {
      throw new \InvalidArgumentException('Application UUID is required.');
    }
    if ($this->dataHash === '' || strlen($this->dataHash) !== 64) {
      throw new \InvalidArgumentException('Data hash must be a SHA-256 hex digest.');
    }
    if ($this->fieldCount < 0 || $this->attachmentCount < 0) {
      throw new \InvalidArgumentException('Envelope counts must not be negative.');
    }
  }

  /**
   * Builds the metadata stored in the delivery log.
   *
   * @return array<string, mixed>
   *   Non-sensitive technical metadata.
   */
  public function toLogMetadata(): array {
    return array_filter([
      'referenceNumber' => $this->referenceNumber,
      'schema' => array_filter([
        'id' => $this->schemaUuid,
        'label' => $this->schemaLabel,
        'version' => $this->schemaVersion,
      ], static fn(mixed $value): bool => $value !== '' && $value !== 0),
      'fieldCount' => $this->fieldCount,
      'attachmentCount' => $this->attachmentCount,
      'attachmentManifest' => $this->attachmentManifest,
      'workspace' => $this->workspace,
    ], static fn(mixed $value): bool => $value !== '' && $value !== []);
  }

  /**
   * Rebuilds a delivery envelope from a normalized delivery-log row.
   *
   * @param array<string, mixed> $row
   *   Normalized delivery log row.
   */
  public static function fromLogRow(array $row): ?self {
    $metadata = $row['metadata'] ?? [];
    if (!is_array($metadata)) {
      return NULL;
    }

    $schema = $metadata['schema'] ?? [];
    if (!is_array($schema)) {
      $schema = [];
    }

    try {
      return new self(
        (int) ($row['applicationId'] ?? 0),
        (string) ($row['applicationUuid'] ?? ''),
        (string) ($metadata['referenceNumber'] ?? ''),
        (string) ($schema['id'] ?? ''),
        (string) ($schema['label'] ?? ''),
        (int) ($schema['version'] ?? 0),
        (string) ($row['dataHash'] ?? ''),
        (int) ($metadata['fieldCount'] ?? 0),
        (int) ($metadata['attachmentCount'] ?? 0),
        is_array($metadata['workspace'] ?? NULL) ? $metadata['workspace'] : [],
        is_array($metadata['attachmentManifest'] ?? NULL) ? $metadata['attachmentManifest'] : [],
      );
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Keeps attachment proof opaque at the value boundary.
   *
   * @param array<int, mixed> $manifest
   *   Raw attachment manifest.
   *
   * @return array<int, array<string, int|string>>
   *   Redacted attachment manifest.
   */
  private static function normalizeAttachmentManifest(array $manifest): array {
    $normalized = [];
    foreach ($manifest as $attachment) {
      if (!is_array($attachment)) {
        continue;
      }

      $item = [];
      $id = trim((string) ($attachment['id'] ?? ''));
      if ($id !== '') {
        $item['id'] = $id;
      }

      $size = max(0, (int) ($attachment['size'] ?? 0));
      if ($size > 0) {
        $item['size'] = $size;
      }

      foreach (['name', 'sha256', 'mime_type', 'field_id'] as $key) {
        if (isset($attachment[$key]) && is_string($attachment[$key])) {
          $item[$key] = $attachment[$key];
        }
      }
      foreach (['form_revision_id', 'submission_revision_id'] as $key) {
        if (isset($attachment[$key])) {
          $item[$key] = (int) $attachment[$key];
        }
      }
      if ($item !== []) {
        $normalized[] = $item;
      }
    }

    return $normalized;
  }

}
