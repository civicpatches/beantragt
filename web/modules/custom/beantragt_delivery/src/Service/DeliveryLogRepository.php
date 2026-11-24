<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Persists outbound delivery attempts.
 */
final class DeliveryLogRepository {

  /**
   * Shared bounded transfer audit writer.
   */
  private readonly TransferAuditRepository $transferAudit;

  public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
  public const STATUS_QUEUED = 'queued';
  public const STATUS_PROCESSING = 'processing';
  public const STATUS_BLOCKED = 'blocked';
  public const STATUS_ACCEPTED = 'accepted';
  public const STATUS_FAILED = 'failed';

  /**
   * Constructs the repository.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    ?TransferAuditRepository $transfer_audit = NULL,
  ) {
    $this->transferAudit = $transfer_audit ?? new TransferAuditRepository($database);
  }

  /**
   * Defines nullable frozen bindings; unbound rows remain readable.
   */
  public static function bindingFields(): array {
    $fields = [];
    foreach ([
      'scope_group_id',
      'integration_mapping_version',
      'form_schema_revision_id',
      'dispatch_approved_uid',
      'dispatch_approved_at',
    ] as $key) {
      $fields[$key] = ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE];
    }
    foreach ([
      'integration_assignment_uuid' => 36,
      'integration_connection_uuid' => 36,
      'integration_mapping_uuid' => 36,
      'integration_mapping_checksum' => 64,
      'form_schema_uuid' => 36,
      'service_urn' => 255,
      'contract_key' => 128,
      'contract_version' => 32,
      'payload_reference' => 255,
      'payload_sha256' => 64,
      'dispatch_mode' => 16,
      'dispatch_approval_status' => 16,
    ] as $key => $length) {
      $fields[$key] = ['type' => 'varchar_ascii', 'length' => $length, 'not null' => FALSE];
    }
    foreach (['connection_snapshot', 'validation_errors'] as $key) {
      $fields[$key] = ['type' => 'text', 'size' => 'big', 'not null' => FALSE];
    }
    $fields['legacy_eligible'] = ['type' => 'int', 'size' => 'tiny', 'not null' => FALSE];
    return $fields;
  }

  /**
   * Defines operational counters separately from immutable legacy bindings.
   */
  public static function operationalFields(): array {
    return [
      'automatic_attempts' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
      'manual_resumes' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
      'queue_item_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
      'replaces_uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => FALSE],
    ];
  }

  /**
   * Creates a queued delivery attempt.
   */
  public function createQueued(ApplicationInterface $application, DeliveryEnvelope $envelope, string $adapter, string $target, array $binding = [], string $status = self::STATUS_QUEUED): int {
    if ($adapter === 'fit_connect' && !isset($binding['scope_group_id'])) {
      throw new \InvalidArgumentException('New FIT-Connect rows require an integration binding.');
    }
    // Eligibility is a migration privilege, never a producer-supplied binding.
    unset($binding['legacy_eligible']);
    $now = $this->time->getRequestTime();
    $id = $this->database->insert('beantragt_delivery_log')
      ->fields([
        'uuid' => $this->uuid->generate(),
        'application_id' => (int) $application->id(),
        'application_uuid' => (string) $application->uuid(),
        'adapter' => $adapter,
        'target' => $target,
        'status' => $status,
        'attempts' => 0,
        'data_hash' => $envelope->dataHash,
        'metadata' => $this->encodeJson($envelope->toLogMetadata()),
        'created' => $now,
        'changed' => $now,
      ] + array_intersect_key($binding, self::bindingFields()))
      ->execute();

    return (int) $id;
  }

  /**
   * Marks a delivery attempt as processing.
   */
  public function markProcessing(int $log_id, int $attempts): void {
    $now = $this->time->getRequestTime();
    $this->database->update('beantragt_delivery_log')
      ->fields([
        'status' => self::STATUS_PROCESSING,
        'attempts' => $attempts,
        'changed' => $now,
        'processed' => $now,
        'last_error' => '',
      ])
      ->condition('id', $log_id)
      ->execute();
  }

  /**
   * Claims a FIT-Connect attempt without reclaiming processing rows.
   *
   * A crashed attempt needs reconciliation. This prevents another worker from
   * overwriting its progress even if the shared application lock expires.
   */
  public function claimFitConnectProcessing(int $log_id, int $attempts): bool {
    $now = $this->time->getCurrentTime();
    return $this->database->update('beantragt_delivery_log')
      ->fields([
        'status' => self::STATUS_PROCESSING,
        'attempts' => $attempts,
        'changed' => $now,
        'processed' => $now,
        'last_error' => '',
      ])
      ->expression('automatic_attempts', 'automatic_attempts + 1')
      ->condition('id', $log_id)
      ->condition('adapter', 'fit_connect')
      ->condition('status', [self::STATUS_QUEUED, self::STATUS_FAILED], 'IN')
      ->execute() === 1;
  }

  /**
   * Parks a recoverable failure without consuming a transport attempt.
   */
  public function markBlocked(int $log_id, string $code, bool $undo_claim = FALSE): void {
    $query = $this->database->update('beantragt_delivery_log')->fields([
      'status' => self::STATUS_BLOCKED,
      'last_error' => $this->boundedError($code, 'Transfer is blocked pending explicit staff resume.'),
      'changed' => $this->time->getCurrentTime(),
    ])->condition('id', $log_id);
    if ($undo_claim) {
      $query->expression('attempts', 'CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END');
      $query->expression('automatic_attempts', 'CASE WHEN automatic_attempts > 0 THEN automatic_attempts - 1 ELSE 0 END');
    }
    $query->execute();
  }

  /**
   * Checkpoints transport progress independently of the final result.
   *
   * Callers hold the application delivery lock. Only safe transport identifiers
   * and a phase are persisted, never payloads or credentials.
   */
  public function checkpointFitConnect(int $log_id, string $phase, string $destination_id, string $submission_id = '', string $case_id = ''): void {
    $row = $this->load($log_id);
    if ($row === NULL || $row['adapter'] !== 'fit_connect' || $row['status'] !== self::STATUS_PROCESSING) {
      throw new \RuntimeException('FIT-Connect requires a processing delivery log.');
    }
    $metadata = $row['metadata'];
    $metadata['fitConnect'] = [
      'phase' => $phase,
      'destinationId' => $destination_id,
      'submissionId' => $submission_id,
      'caseId' => $case_id,
      'payloadSha256' => $row['payload_sha256'] ?? NULL,
    ];
    $updated = $this->database->update('beantragt_delivery_log')
      ->fields([
        'metadata' => $this->encodeJson($metadata),
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('id', $log_id)
      ->condition('status', self::STATUS_PROCESSING)
      ->execute();
    if ($updated !== 1) {
      throw new \RuntimeException('FIT-Connect checkpoint could not be persisted.');
    }
  }

  /**
   * Stores authenticated, bounded destination evidence separately from phases.
   */
  public function recordDestination(int $log_id, array $destination, string $service, string $schema): void {
    $row = $this->load($log_id);
    if ($row === NULL || $row['status'] !== self::STATUS_PROCESSING) {
      throw new \RuntimeException('Destination evidence requires a processing transfer.');
    }
    $name = is_string($destination['name'] ?? NULL) ? $destination['name'] : '';
    $metadata = $row['metadata'];
    $metadata['destinationVerified'] = [
      'name' => mb_substr(strip_tags($name), 0, 255),
      'service_urn' => $service,
      'schema_uri' => $schema,
      'checked_at' => $this->time->getCurrentTime(),
    ];
    $this->database->update('beantragt_delivery_log')->fields(['metadata' => $this->encodeJson($metadata)])
      ->condition('id', $log_id)->condition('status', self::STATUS_PROCESSING)->execute();
  }

  /**
   * Persists a gateway result.
   */
  public function markResult(int $log_id, DeliveryResult $result): void {
    $now = $this->time->getRequestTime();
    $fields = [
      'status' => $result->isAccepted() ? self::STATUS_ACCEPTED : self::STATUS_FAILED,
      'external_id' => $result->externalId,
      'receipt' => $result->receipt,
      'last_error' => $result->isAccepted() ? '' : $this->boundedError($result->errorCode, $result->errorMessage),
      'changed' => $now,
      'processed' => $now,
    ];

    $row = $this->load($log_id);
    if (is_array($row)) {
      $metadata = $row['metadata'];
      $metadata['result'] = $result->metadata;
      $fields['metadata'] = $this->encodeJson($metadata);
    }

    $this->database->update('beantragt_delivery_log')
      ->fields($fields)
      ->condition('id', $log_id)
      ->execute();
  }

  /**
   * Records that an accepted transfer left the case in its start status.
   *
   * The reason is a code of SemanticStatusChanger::ACCEPTED_UNCHANGED_REASONS;
   * the transfer list shows it to staff. Only on an accepted row.
   */
  public function recordAcceptanceStatus(int $log_id, string $reason): void {
    $row = $this->load($log_id);
    if ($row === NULL || $row['status'] !== self::STATUS_ACCEPTED) {
      return;
    }
    $metadata = $row['metadata'];
    $metadata['acceptance'] = ['statusChanged' => FALSE, 'reason' => $reason];
    $this->database->update('beantragt_delivery_log')->fields(['metadata' => $this->encodeJson($metadata)])
      ->condition('id', $log_id)->condition('status', self::STATUS_ACCEPTED)->execute();
  }

  /**
   * Loads one delivery attempt.
   *
   * @return array<string, mixed>|null
   *   Log row, or NULL.
   */
  public function load(int $log_id): ?array {
    $row = $this->database->select('beantragt_delivery_log', 'd')
      ->fields('d')
      ->condition('id', $log_id)
      ->range(0, 1)
      ->execute()
      ?->fetchAssoc();
    if (!is_array($row)) {
      return NULL;
    }
    return $this->normalizeRow($row, TRUE);
  }

  /**
   * Lists delivery attempts for one application.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized log rows.
   */
  public function listForApplication(ApplicationInterface $application, int $limit = 20, bool $include_bindings = FALSE): array {
    $limit = max(1, min(100, $limit));
    $result = $this->database->select('beantragt_delivery_log', 'd')
      ->fields('d')
      ->condition('application_id', (int) $application->id())
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute();

    $rows = [];
    foreach ($result as $row) {
      $rows[] = $this->normalizeRow((array) $row, $include_bindings);
    }
    return $rows;
  }

  /**
   * Reduces erased cases to value-free identifiers, states and timestamps.
   */
  public function anonymizeForApplicationId(int $application_id): void {
    $rows = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id', 'status', 'last_error'])
      ->condition('application_id', $application_id)->execute()->fetchAll();
    foreach ($rows as $row) {
      $fields = [
        'target' => '',
        'data_hash' => '',
        'metadata' => '{}',
        'receipt' => '',
        'external_id' => '',
        'connection_snapshot' => NULL,
        'validation_errors' => NULL,
        'payload_reference' => NULL,
        'payload_sha256' => NULL,
        'dispatch_approved_uid' => NULL,
        'dispatch_approved_at' => NULL,
        'service_urn' => NULL,
        'contract_key' => NULL,
        'contract_version' => NULL,
        'changed' => $this->time->getCurrentTime(),
      ];
      $fields['last_error'] = 'integration.application_anonymised';
      if (!in_array($row->status, [self::STATUS_ACCEPTED, self::STATUS_FAILED], TRUE)) {
        $fields['status'] = self::STATUS_FAILED;
      }
      $this->database->update('beantragt_delivery_log')->fields($fields)->condition('id', $row->id)->execute();
    }
    if ($this->database->schema()->tableExists('beantragt_transfer_audit')) {
      $this->database->update('beantragt_transfer_audit')->fields(['uid' => NULL])->condition('application_id', $application_id)->execute();
    }
  }

  /**
   * Stores durable, value-free dispatch evidence in the caller's transaction.
   */
  public function audit(int $application_id, string $application_uuid, ?int $scope, string $uuid, int $uid, string $action, string $outcome, string $code = ''): void {
    $this->transferAudit->append([
      'application_id' => $application_id,
      'application_uuid' => $application_uuid,
      'scope_group_id' => $scope,
      'transfer_uuid' => $uuid,
      'uid' => $uid,
      'action' => $action,
      'outcome' => $outcome,
      'code' => $code,
      'created' => $this->time->getCurrentTime(),
    ]);
  }

  /**
   * Bounds identifier-only audit retention independently of rotating dblog.
   */
  public function cleanupAudit(): void {
    $this->database->delete('beantragt_transfer_audit')->condition('created', $this->time->getCurrentTime() - 31536000, '<')->execute();
  }

  /**
   * Deletes all locally stored delivery attempts for an application.
   */
  public function deleteForApplicationId(int $application_id): int {
    if ($application_id <= 0) {
      return 0;
    }

    return (int) $this->database->delete('beantragt_delivery_log')
      ->condition('application_id', $application_id)
      ->execute();
  }

  /**
   * Normalizes a database row for API and Drush output.
   *
   * @param array<string, mixed> $row
   *   Raw row.
   * @param bool $include_data_hash
   *   Whether the internal data hash should be included.
   *
   * @return array<string, mixed>
   *   Normalized row.
   */
  private function normalizeRow(array $row, bool $include_data_hash = FALSE): array {
    $metadata = [];
    $metadata_valid = isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '';
    if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
      try {
        $decoded = json_decode($row['metadata'], TRUE, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
          throw new \JsonException('Invalid metadata shape.');
        }
        $metadata = $decoded;
      }
      catch (\JsonException $e) {
        $metadata_valid = FALSE;
        $this->logger->warning('Delivery log @id contains invalid metadata JSON: @message', [
          '@id' => (string) ($row['id'] ?? ''),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    $normalized = [
      'id' => (int) ($row['id'] ?? 0),
      'uuid' => (string) ($row['uuid'] ?? ''),
      'applicationId' => (int) ($row['application_id'] ?? 0),
      'applicationUuid' => (string) ($row['application_uuid'] ?? ''),
      'adapter' => (string) ($row['adapter'] ?? ''),
      'target' => (string) ($row['target'] ?? ''),
      'status' => (string) ($row['status'] ?? ''),
      'attempts' => (int) ($row['attempts'] ?? 0),
      'externalId' => (string) ($row['external_id'] ?? ''),
      'receipt' => (string) ($row['receipt'] ?? ''),
      'lastError' => (string) ($row['last_error'] ?? ''),
      'metadata' => $metadata,
      'created' => (int) ($row['created'] ?? 0),
      'changed' => (int) ($row['changed'] ?? 0),
      'processed' => isset($row['processed']) ? (int) $row['processed'] : NULL,
    ];

    if ($include_data_hash) {
      if (!$metadata_valid) {
        $normalized['binding_integrity'] = 'failed';
      }
      $normalized['dataHash'] = (string) ($row['data_hash'] ?? '');
      foreach (self::bindingFields() as $key => $definition) {
        $value = $row[$key] ?? NULL;
        if ($value !== NULL && in_array($key, ['connection_snapshot', 'validation_errors'], TRUE)) {
          try {
            $value = json_decode($value, TRUE, flags: JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
              throw new \JsonException('Invalid binding shape.');
            }
          }
          catch (\JsonException) {
            $value = NULL;
            $normalized['binding_integrity'] = 'failed';
          }
        }
        elseif ($value !== NULL && $definition['type'] === 'int') {
          $value = (int) $value;
        }
        $normalized[$key] = $value;
      }
      foreach (self::operationalFields() as $key => $definition) {
        $normalized[$key] = ($definition['type'] === 'int') ? (int) ($row[$key] ?? 0) : ($row[$key] ?? NULL);
      }
    }

    return $normalized;
  }

  /**
   * Encodes metadata.
   *
   * @param array<string, mixed> $metadata
   *   Metadata to encode.
   */
  private function encodeJson(array $metadata): string {
    return json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * Builds a bounded non-sensitive error message.
   */
  private function boundedError(string $code, string $message): string {
    $error = trim($code . ': ' . $message, ': ');
    return mb_substr($error, 0, 512);
  }

}
