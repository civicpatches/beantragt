<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Receipt records ("Eingangsvermerk") of follow-up documents.
 *
 * One row per decision W (take_over), N (request_again) or A (file_only)
 * that attached an envelope's files to an application. No names, no file
 * names, no identifier: hashes, page numbers, keys and states only.
 */
class EingangRepository {

  public const TABLE = 'beantragt_nachreichung_eingang';

  public const DECISIONS = ['take_over', 'request_again', 'file_only'];

  public const MODES = ['remove', 'reassign'];

  /**
   * Constructs the repository.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Table definition for hook_schema().
   *
   * @return array<string, mixed>
   *   Schema API definition.
   */
  public static function schema(): array {
    $optional_int = ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE];
    $day = ['type' => 'varchar_ascii', 'length' => 10, 'not null' => TRUE, 'default' => ''];
    return [
      'description' => 'Receipt records of follow-up documents (no personal data).',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''],
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'nachforderung_id' => $optional_int,
        'bulk_case_uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''],
        'received_on' => $day,
        'received_on_source' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'batch'],
        'batch_received_on' => $day,
        'decision' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => ''],
        'decided_by' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'decided_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'identity_confirmed' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'cover' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'page_count' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'files' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'result' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'request_state_before' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
        'request_state_after' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
        'reverted_at' => $optional_int,
        'reverted_by' => $optional_int,
        'reverted_mode' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
      ],
      'indexes' => [
        'application_id' => ['application_id'],
        'bulk_case_uuid' => ['bulk_case_uuid'],
      ],
    ];
  }

  /**
   * Inserts a receipt record.
   *
   * @param array<string, mixed> $fields
   *   Column values; files and result as arrays.
   */
  public function insert(array $fields): int {
    foreach (['files', 'result'] as $json) {
      if (isset($fields[$json]) && is_array($fields[$json])) {
        $fields[$json] = json_encode($fields[$json], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
      }
    }
    return (int) $this->database->insert(self::TABLE)->fields($fields)->execute();
  }

  /**
   * One receipt record of an application by its UUID.
   *
   * @return array<string, mixed>|null
   *   The row with files and result decoded.
   */
  public function findForApplication(int $application_id, string $uuid, bool $for_update = FALSE): ?array {
    $query = $this->database->select(self::TABLE, 'e')->fields('e')
      ->condition('application_id', $application_id)
      ->condition('uuid', strtolower($uuid));
    if ($for_update) {
      $query->forUpdate();
    }
    $row = $query->execute()->fetchAssoc();
    return is_array($row) ? self::decode($row) : NULL;
  }

  /**
   * Receipt records of an application, newest first.
   *
   * @return array<int, array<string, mixed>>
   *   Rows with files and result decoded.
   */
  public function forApplication(int $application_id, int $limit = 50): array {
    $rows = $this->database->select(self::TABLE, 'e')->fields('e')
      ->condition('application_id', $application_id)
      ->orderBy('decided_at', 'DESC')->orderBy('id', 'DESC')
      ->range(0, $limit)->execute()->fetchAll(FetchAs::Associative);
    return array_map(self::decode(...), $rows);
  }

  /**
   * The newest receipt record of a request record that is not reverted.
   *
   * @return array<string, mixed>|null
   *   The row with files and result decoded.
   */
  public function newestForRequest(int $application_id, int $nachforderung_id): ?array {
    $row = $this->database->select(self::TABLE, 'e')->fields('e')
      ->condition('application_id', $application_id)
      ->condition('nachforderung_id', $nachforderung_id)
      ->isNull('reverted_at')
      ->orderBy('decided_at', 'DESC')->orderBy('id', 'DESC')
      ->range(0, 1)->execute()->fetchAssoc();
    return is_array($row) ? self::decode($row) : NULL;
  }

  /**
   * TRUE when a later receipt record of the request is not reverted.
   */
  public function laterForRequest(int $nachforderung_id, int $id): bool {
    return $this->database->select(self::TABLE, 'e')->fields('e', ['id'])
      ->condition('nachforderung_id', $nachforderung_id)
      ->condition('id', $id, '>')
      ->isNull('reverted_at')
      ->range(0, 1)->execute()->fetchField() !== FALSE;
  }

  /**
   * Marks a record reverted; FALSE when another request was first.
   */
  public function revert(int $id, int $uid, string $mode, int $now): bool {
    if (!in_array($mode, self::MODES, TRUE)) {
      throw new \InvalidArgumentException('Unknown revert mode.');
    }
    return (int) $this->database->update(self::TABLE)
      ->fields(['reverted_at' => $now, 'reverted_by' => $uid, 'reverted_mode' => $mode])
      ->condition('id', $id)
      ->isNull('reverted_at')
      ->execute() === 1;
  }

  /**
   * Decodes the JSON columns of a row.
   *
   * @param array<string, mixed> $row
   *   Raw row.
   *
   * @return array<string, mixed>
   *   Row with files and result as arrays.
   */
  private static function decode(array $row): array {
    foreach (['files', 'result'] as $json) {
      $decoded = json_decode((string) ($row[$json] ?? ''), TRUE);
      $row[$json] = is_array($decoded) ? $decoded : [];
    }
    return $row;
  }

}
