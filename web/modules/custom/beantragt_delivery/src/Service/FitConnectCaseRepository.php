<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Stores case bindings and encrypted per-case reply keys outside the outbox.
 */
final class FitConnectCaseRepository {

  public function __construct(private readonly Connection $database) {}

  /**
   * Defines case-key and retryable acceptance storage.
   */
  public static function schema(): array {
    $uuid = ['type' => 'varchar_ascii', 'length' => 36, 'not null' => FALSE];
    $text = ['type' => 'text', 'size' => 'big', 'not null' => TRUE];
    return [
      'beantragt_fit_case' => [
        'fields' => [
          'log_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'application_uuid' => $uuid,
          'scope_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
          'client_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
          'destination_id' => $uuid,
          'submission_id' => $uuid,
          'case_id' => $uuid,
          'process_standard' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
          'private_key' => $text,
          'public_key' => $text,
        ],
        'primary key' => ['log_id'],
        'unique keys' => ['case_id' => ['case_id']],
        'indexes' => ['application' => ['application_id']],
      ],
      'beantragt_fit_reply_ack' => [
        'fields' => [
          'reply_id' => array_replace($uuid, ['not null' => TRUE]),
          'case_id' => $uuid,
          'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'tags' => $text,
          'state' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE],
          'acceptance_set' => $text,
        ],
        'primary key' => ['reply_id'],
        'indexes' => ['application' => ['application_id'], 'state' => ['state']],
      ],
    ];
  }

  /**
   * Persists the private key before it can be advertised remotely.
   */
  public function prepare(ApplicationInterface $application, int $log_id, FitConnectSettings $settings): array {
    $settings->assertTestTransportAllowed();
    if ($settings->replyProcessStandard !== 'urn:beantragt:process:decision:1') {
      throw new FitConnectTransportException('fit_connect.unsupported_reply_standard', 'The reply process standard is unsupported.');
    }
    $existing = $this->load(['log_id' => $log_id]);
    if ($existing !== NULL) {
      $this->assertBinding($existing, $application, $settings);
      return json_decode($existing['public_key'], TRUE, flags: JSON_THROW_ON_ERROR);
    }
    // Validate wrapping key before spending time generating RSA material.
    $wrapping_key = $this->wrappingKey();
    $key = JWKFactory::createRSAKey(4096, [
      'kid' => bin2hex(random_bytes(16)),
      'alg' => 'RSA-OAEP-256',
      'key_ops' => ['wrapKey'],
    ]);
    $public = $key->toPublic()->all();
    $row = [
      'log_id' => $log_id,
      'application_id' => (int) $application->id(),
      'application_uuid' => $application->uuid(),
      'scope_hash' => self::scopeHash($application),
      'client_hash' => self::clientHash($settings),
      'destination_id' => $settings->destinationId,
      'process_standard' => $settings->replyProcessStandard,
      'public_key' => json_encode($public, JSON_THROW_ON_ERROR),
    ];
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(json_encode($key->all(), JSON_THROW_ON_ERROR), 'aes-256-gcm', $wrapping_key, OPENSSL_RAW_DATA, $iv, $tag, self::aad($row));
    if ($ciphertext === FALSE) {
      throw new \RuntimeException('Reply key protection failed.');
    }
    $row['private_key'] = base64_encode($iv . $tag . $ciphertext);
    $this->database->insert('beantragt_fit_case')->fields($row)->execute();
    return $public;
  }

  /**
   * Binds a prepared key to the returned remote submission and case.
   */
  public function bind(int $log_id, string $submission_id, string $case_id): void {
    $changed = $this->database->update('beantragt_fit_case')->fields([
      'submission_id' => $submission_id,
      'case_id' => $case_id,
    ])->condition('log_id', $log_id)->isNull('case_id')->execute();
    if (!$changed) {
      throw new \RuntimeException('Reply case binding could not be persisted.');
    }
  }

  /**
   * Loads a case binding by internal identifiers.
   */
  public function load(array $conditions): ?array {
    $query = $this->database->select('beantragt_fit_case', 'c')->fields('c');
    foreach ($conditions as $key => $value) {
      $query->condition($key, $value);
    }
    $row = $query->execute()->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Rotates representative frozen contexts, independent of their case counts.
   *
   * Grouping the complete snapshot retains distinct operator profile bindings.
   * A context's minimum log id is stable as cases accumulate. Cases with erased
   * key material cannot receive replies and are excluded. Remote case closure
   * is not persisted by this protocol; accepted submissions can still reply.
   */
  public function boundContextLogIds(int $limit = 100, int $after = 0): array {
    $query = $this->database->select('beantragt_fit_case', 'c');
    $query->innerJoin('beantragt_delivery_log', 'd', 'd.id = c.log_id');
    $query->addExpression('MIN(c.log_id)', 'context_log_id');
    foreach ([
      'scope_group_id', 'integration_connection_uuid', 'service_urn',
      'contract_key', 'contract_version', 'connection_snapshot',
    ] as $column) {
      $query->groupBy('d.' . $column);
    }
    return array_map('intval', $query
      ->isNotNull('c.case_id')->isNotNull('d.scope_group_id')
      ->isNotNull('d.connection_snapshot')->condition('c.private_key', '', '<>')
      ->condition('d.adapter', 'fit_connect')
      ->having('MIN(c.log_id) > :after', [':after' => max(0, $after)])
      ->orderBy('context_log_id')->range(0, max(1, min(100, $limit)))->execute()->fetchCol());
  }

  /**
   * Rejects changed application, tenant, destination or sender context.
   */
  public function assertBinding(array $row, ApplicationInterface $application, FitConnectSettings $settings): void {
    if ((int) $row['application_id'] !== (int) $application->id()
      || $row['application_uuid'] !== $application->uuid()
      || !hash_equals($row['scope_hash'], self::scopeHash($application))
      || !hash_equals($row['client_hash'], self::clientHash($settings))
      || $row['destination_id'] !== $settings->destinationId
      || $row['process_standard'] !== $settings->replyProcessStandard) {
      throw new FitConnectTransportException('fit_connect.reply_binding_mismatch', 'Reply does not match the original application delivery scope.');
    }
  }

  /**
   * Decrypts the case key with authenticated binding context.
   */
  public function privateKey(array $row): array {
    $bytes = base64_decode($row['private_key'], TRUE);
    if ($bytes === FALSE || strlen($bytes) < 29) {
      throw new \RuntimeException('Reply key is unavailable.');
    }
    $plaintext = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->wrappingKey(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), self::aad($row));
    if ($plaintext === FALSE) {
      throw new \RuntimeException('Reply key is unavailable.');
    }
    return json_decode($plaintext, TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Records retryable technical acceptance only after response persistence.
   */
  public function pendingAck(string $reply_id, array $binding, array $tags): void {
    $existing = $this->database->select('beantragt_fit_reply_ack', 'a')->fields('a')->condition('reply_id', $reply_id)->execute()->fetchAssoc();
    if ($existing !== FALSE) {
      if ($existing['case_id'] !== $binding['case_id'] || (int) $existing['application_id'] !== (int) $binding['application_id'] || json_decode($existing['tags'], TRUE) !== $tags) {
        throw new \RuntimeException('Reply acceptance binding mismatch.');
      }
      return;
    }
    $this->database->insert('beantragt_fit_reply_ack')->fields([
      'reply_id' => $reply_id,
      'case_id' => $binding['case_id'],
      'application_id' => $binding['application_id'],
      'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
      'state' => 'pending',
      'acceptance_set' => '',
    ])->execute();
  }

  /**
   * Lists a bounded batch of outstanding technical receipts.
   */
  public function pendingAcks(?array $context = NULL, bool $legacy_only = FALSE): array {
    $query = $this->database->select('beantragt_fit_reply_ack', 'a')->fields('a')->condition('a.state', 'pending');
    if ($context !== NULL || $legacy_only) {
      $query->innerJoin('beantragt_fit_case', 'c', 'c.case_id = a.case_id');
      $query->innerJoin('beantragt_delivery_log', 'd', 'd.id = c.log_id');
      if ($legacy_only) {
        $query->isNull('d.scope_group_id');
      }
      else {
        foreach (['scope_group_id', 'integration_connection_uuid', 'service_urn', 'contract_key', 'contract_version'] as $column) {
          $query->condition('d.' . $column, $context[$column]);
        }
      }
    }
    return $query->range(0, 50)->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Persists verified acceptance evidence.
   */
  public function accepted(string $reply_id, string $set): void {
    $this->database->update('beantragt_fit_reply_ack')->fields(['state' => 'accepted', 'acceptance_set' => $set])->condition('reply_id', $reply_id)->execute();
  }

  /**
   * Erases case keys and receipts with their application.
   */
  public function cleanupForApplication(ApplicationInterface $application): void {
    foreach (['beantragt_fit_reply_ack', 'beantragt_fit_case'] as $table) {
      $this->database->delete($table)->condition('application_id', (int) $application->id())->execute();
    }
  }

  /**
   * Fingerprints the tenant while allowing internal organisation delegation.
   */
  private static function scopeHash(ApplicationInterface $application): string {
    $scope = [];
    foreach (['field_jurisdiction'] as $field) {
      $scope[$field] = $application->hasField($field) ? (int) ($application->get($field)->target_id ?? 0) : 0;
    }
    if ($scope['field_jurisdiction'] === 0) {
      throw new FitConnectTransportException('fit_connect.reply_scope_missing', 'Reply delivery requires a jurisdiction.');
    }
    return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
  }

  /**
   * Fingerprints the original TEST sender identity.
   */
  private static function clientHash(FitConnectSettings $settings): string {
    return hash('sha256', $settings->environment . '|' . $settings->submissionApiBase . '|' . $settings->clientId);
  }

  /**
   * Binds encrypted key material to its immutable delivery context.
   */
  private static function aad(array $row): string {
    return implode('|', [
      'beantragt-fit-case-v1', $row['log_id'], $row['application_uuid'],
      $row['scope_hash'], $row['client_hash'], $row['destination_id'],
      $row['process_standard'],
    ]);
  }

  /**
   * Derives a purpose-specific key from existing application key material.
   */
  private function wrappingKey(): string {
    $raw = trim((string) getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY'));
    if (str_starts_with($raw, 'base64:')) {
      $raw = base64_decode(substr($raw, 7), TRUE);
    }
    elseif (strlen($raw) >= 64 && strlen($raw) % 2 === 0 && ctype_xdigit($raw)) {
      $raw = hex2bin($raw);
    }
    if (!is_string($raw) || strlen($raw) < 32) {
      throw new \RuntimeException('Reply key encryption requires configured application key material.');
    }
    return hash_hkdf('sha256', $raw, 32, 'beantragt_fit_reply_private_key_v1');
  }

}
