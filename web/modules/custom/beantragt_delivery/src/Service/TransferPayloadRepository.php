<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores immutable payload bytes under the application's encryption/retention.
 */
final class TransferPayloadRepository {

  /**
   * Constructs protected payload storage.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ApplicationDataProtectionService $protection,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly ?ApplicationDeliveryPurger $purger = NULL,
  ) {}

  /**
   * Defines private SQL storage, never an entity or a downloadable file.
   */
  public static function schema(): array {
    return [
      'beantragt_transfer_payload' => [
        'fields' => [
          'uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE],
          'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'ciphertext' => ['type' => 'text', 'size' => 'big', 'not null' => TRUE],
          'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        ],
        'primary key' => ['uuid'],
        'indexes' => ['application' => ['application_id']],
      ],
    ];
  }

  /**
   * Encrypts the exact canonical bytes before writing anything to storage.
   */
  public function store(int $application_id, string $json): string {
    $uuid = $this->uuid->generate();
    $protected = $this->protectContext($uuid, $application_id, $json);
    $this->database->insert('beantragt_transfer_payload')->fields([
      'uuid' => $uuid,
      'application_id' => $application_id,
      'ciphertext' => $protected,
      'created' => $this->time->getCurrentTime(),
    ])->execute();
    return $uuid;
  }

  /**
   * Authenticates the immutable owner and reference inside the GCM envelope.
   */
  private function protectContext(string $uuid, int $application_id, string $json): string {
    if (strlen($json) > MappedPayloadBuilder::MAX_PAYLOAD_BYTES) {
      throw new IntegrationException('integration.payload_protection_failed');
    }
    $context = json_encode(['format' => 2, 'uuid' => $uuid, 'application_id' => $application_id, 'json' => $json], JSON_THROW_ON_ERROR);
    $protected = $this->protection->protectData(['payload' => $context], [
      'sections' => [['fields' => [['id' => 'payload', 'type' => 'text', 'sensitive' => TRUE]]]],
    ]);
    if (!$this->protection->isEncryptedValue($protected['payload'])) {
      throw new IntegrationException('integration.payload_protection_failed');
    }
    return json_encode($protected, JSON_THROW_ON_ERROR);
  }

  /**
   * Authenticates encrypted storage and verifies the immutable payload hash.
   */
  public function verified(array $row): string {
    try {
      $stored = $this->database->select('beantragt_transfer_payload', 'p')->fields('p', ['ciphertext'])
        ->condition('uuid', $row['payload_reference'] ?? '')
        ->condition('application_id', $row['applicationId'])->execute()->fetchField();
      $protected = json_decode((string) $stored, TRUE, flags: JSON_THROW_ON_ERROR);
      if (!is_array($protected) || !$this->protection->isEncryptedValue($protected['payload'] ?? NULL)) {
        throw new \RuntimeException('Invalid protected payload.');
      }
      $context = json_decode($this->protection->revealData($protected)['payload'], TRUE, flags: JSON_THROW_ON_ERROR);
      if (($context['format'] ?? NULL) !== 2 || ($context['uuid'] ?? NULL) !== ($row['payload_reference'] ?? NULL)
        || ($context['application_id'] ?? NULL) !== (int) $row['applicationId']) {
        throw new \RuntimeException('Payload context mismatch.');
      }
      $json = $context['json'] ?? NULL;
      if (!is_string($json) || strlen($json) > MappedPayloadBuilder::MAX_PAYLOAD_BYTES
        || !hash_equals((string) ($row['payload_sha256'] ?? ''), hash('sha256', $json))) {
        throw new \RuntimeException('Payload integrity failed.');
      }
      return $json;
    }
    catch (\Throwable) {
      throw new IntegrationException('integration.payload_integrity_failed');
    }
  }

  /**
   * Deletes payloads with their owning application, including retention expiry.
   */
  public function deleteForApplicationId(int $id): void {
    $this->database->delete('beantragt_transfer_payload')->condition('application_id', $id)->execute();
  }

  /**
   * Removes the unnecessary payload copy after technical acceptance.
   */
  public function deleteReference(string $reference): void {
    $this->database->delete('beantragt_transfer_payload')->condition('uuid', $reference)->execute();
  }

  /**
   * Rewraps pre-context payloads once during the explicit schema update.
   *
   * There is deliberately no legacy fallback in verified(). The existing hash
   * and owner join must be valid before a legacy payload can be migrated.
   */
  public function migrateLegacyContext(): void {
    $query = $this->database->select('beantragt_transfer_payload', 'p');
    $query->innerJoin('beantragt_delivery_log', 'd', 'd.payload_reference = p.uuid AND d.application_id = p.application_id');
    $query->fields('p', ['uuid', 'application_id', 'ciphertext']);
    $query->addField('d', 'payload_sha256');
    foreach ($query->execute() as $stored) {
      try {
        $protected = json_decode($stored->ciphertext, TRUE, flags: JSON_THROW_ON_ERROR);
        if (!is_array($protected) || !$this->protection->isEncryptedValue($protected['payload'] ?? NULL)) {
          continue;
        }
        $json = $this->protection->revealData($protected)['payload'] ?? NULL;
        if (!is_string($json) || !hash_equals((string) $stored->payload_sha256, hash('sha256', $json))) {
          // Includes already migrated records, whose plaintext is the context.
          continue;
        }
        $ciphertext = $this->protectContext($stored->uuid, (int) $stored->application_id, $json);
        $this->database->update('beantragt_transfer_payload')->fields(['ciphertext' => $ciphertext])->condition('uuid', $stored->uuid)->execute();
      }
      catch (\Throwable) {
        // A corrupt legacy record remains unreadable and fails closed on use.
      }
    }
  }

  /**
   * Removes a bounded batch of old orphaned payloads left by interrupted work.
   */
  public function cleanupOrphans(): void {
    $this->purger?->cleanupUnreferencedPayloads();
  }

  /**
   * Repairs an interrupted acceptance cleanup without waiting for retention.
   */
  public function cleanupAccepted(): void {
    $query = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id']);
    $query->condition('status', DeliveryLogRepository::STATUS_ACCEPTED)->isNotNull('payload_reference')->range(0, 100);
    foreach ($query->execute() as $row) {
      $this->purgeTerminalPayload((int) $row->id);
    }
  }

  /**
   * Delegates removal of terminal payload copies to the unified purge service.
   */
  public function purgeTerminalPayload(int $log_id): void {
    $this->purger?->purgeTerminalPayload($log_id);
  }

}
