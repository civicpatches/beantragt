<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Request records ("Akte"): what a request letter asked for, until when.
 *
 * States: draft, open, partly_answered (a decision "Erneut nachfordern"),
 * answered ("Übernehmen und fortsetzen") and superseded. The deadline may be
 * extended (deadline_extended_*), the printed one stays.
 */
class NachforderungRepository {

  public const TABLE = 'beantragt_nachforderung';

  /**
   * States that still wait for documents.
   */
  public const WAITING = ['open', 'partly_answered'];

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
    $uuid = ['type' => 'varchar_ascii', 'length' => 36, 'not null' => FALSE];
    $optional_int = ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE];
    $day = ['type' => 'varchar_ascii', 'length' => 10, 'not null' => TRUE, 'default' => ''];
    return [
      'description' => 'Request records of request letters (no personal data).',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''],
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'form_revision_id' => $optional_int,
        'channel' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'letter'],
        'docgen_document_uuid' => $uuid,
        'response_uuid' => $uuid,
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'released_at' => $optional_int,
        'released_by' => $optional_int,
        'closed_at' => $optional_int,
        'deadline_printed' => $day,
        'deadline' => $day,
        'deadline_extended_at' => $optional_int,
        'deadline_extended_by' => $optional_int,
        'deadline_reason' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => FALSE],
        'requested' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'state' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'draft'],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
      ],
      'indexes' => [
        'application_state' => ['application_id', 'state'],
        'document' => ['docgen_document_uuid'],
      ],
    ];
  }

  /**
   * Inserts a record.
   */
  public function insert(array $fields): int {
    return (int) $this->database->insert(self::TABLE)->fields($fields)->execute();
  }

  /**
   * Updates a record.
   */
  public function update(int $id, array $fields): void {
    $this->database->update(self::TABLE)->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * The draft record of a request letter, if any.
   */
  public function draftForDocument(string $document_uuid): ?array {
    $row = $this->database->select(self::TABLE, 'n')->fields('n')
      ->condition('docgen_document_uuid', $document_uuid)
      ->condition('state', 'draft')
      ->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Deletes the draft record of a request letter.
   */
  public function deleteDraft(string $document_uuid): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('docgen_document_uuid', $document_uuid)
      ->condition('state', 'draft')
      ->execute();
  }

  /**
   * Marks waiting records of earlier releases as superseded.
   *
   * @param int $application_id
   *   The application.
   * @param int[] $keep_ids
   *   Records of the release itself (all request letters of one response);
   *   they never supersede each other. Must not be empty.
   * @param int $now
   *   Time of the release and closing time. Records released later stay: a
   *   late repair of an older release never closes a newer request.
   *
   * @return int
   *   Number of superseded records.
   */
  public function supersedeOthers(int $application_id, array $keep_ids, int $now): int {
    if ($keep_ids === []) {
      throw new \InvalidArgumentException('A release keeps at least one record.');
    }
    return (int) $this->database->update(self::TABLE)
      ->fields(['state' => 'superseded', 'closed_at' => $now])
      ->condition('application_id', $application_id)
      ->condition('id', array_values($keep_ids), 'NOT IN')
      ->condition('state', self::WAITING, 'IN')
      ->condition('released_at', $now, '<=')
      ->execute();
  }

  /**
   * The newest waiting record of an application.
   */
  public function newestWaiting(int $application_id): ?array {
    $row = $this->database->select(self::TABLE, 'n')->fields('n')
      ->condition('application_id', $application_id)
      ->condition('state', self::WAITING, 'IN')
      ->orderBy('released_at', 'DESC')->orderBy('id', 'DESC')
      ->range(0, 1)->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * One record by id, NULL when it does not exist.
   *
   * @return array<string, mixed>|null
   *   The row.
   */
  public function find(int $id, bool $for_update = FALSE): ?array {
    $query = $this->database->select(self::TABLE, 'n')->fields('n')->condition('id', $id);
    if ($for_update) {
      $query->forUpdate();
    }
    $row = $query->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * One record of an application by its UUID.
   *
   * @return array<string, mixed>|null
   *   The row.
   */
  public function findForApplication(int $application_id, string $uuid): ?array {
    $row = $this->database->select(self::TABLE, 'n')->fields('n')
      ->condition('application_id', $application_id)
      ->condition('uuid', strtolower($uuid))
      ->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * The newest answered record of an application.
   *
   * @return array<string, mixed>|null
   *   The row.
   */
  public function newestAnswered(int $application_id): ?array {
    $row = $this->database->select(self::TABLE, 'n')->fields('n')
      ->condition('application_id', $application_id)
      ->condition('state', 'answered')
      ->orderBy('closed_at', 'DESC')->orderBy('id', 'DESC')
      ->range(0, 1)->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Sets the state of a record only while it still holds the expected one.
   *
   * @return bool
   *   TRUE when this call changed it.
   */
  public function transition(int $id, string $expected, string $state, ?int $closed_at): bool {
    return (int) $this->database->update(self::TABLE)
      ->fields(['state' => $state, 'closed_at' => $closed_at])
      ->condition('id', $id)
      ->condition('state', $expected)
      ->execute() === 1;
  }

  /**
   * Records of an application, newest first.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  public function forApplication(int $application_id, int $limit = 50): array {
    return $this->database->select(self::TABLE, 'n')->fields('n')
      ->condition('application_id', $application_id)
      ->orderBy('created', 'DESC')->orderBy('id', 'DESC')
      ->range(0, $limit)->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Draft records with their document UUIDs, for reconciliation.
   *
   * @return array<int, array{application_id: string, docgen_document_uuid: string, created: string}>
   *   Rows.
   */
  public function drafts(int $limit = 200, ?int $application_id = NULL): array {
    $query = $this->database->select(self::TABLE, 'n')
      ->fields('n', ['application_id', 'docgen_document_uuid', 'created'])
      ->condition('state', 'draft')
      ->isNotNull('docgen_document_uuid');
    if ($application_id !== NULL) {
      $query->condition('application_id', $application_id);
    }
    return $query->range(0, $limit)->execute()->fetchAll(FetchAs::Associative);
  }

}
