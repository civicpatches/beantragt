<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Repository;

use Drupal\beantragt_nachreichung\Code\CoverCode;
use Drupal\beantragt_nachreichung\Code\CoverCodeHasher;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Cover sheet identifiers: hash, origin and state, never the identifier.
 *
 * States: draft (on a request letter not yet released, unknown on lookup),
 * active (valid), revoked. No jurisdiction or organisation column: the
 * application decides the scope (N20).
 */
class CoverCodeRepository {

  public const TABLE = 'beantragt_cover_code';
  public const ACTIVE_LIMIT = 10;

  /**
   * Constructs the repository.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly CoverCodeHasher $hasher,
  ) {}

  /**
   * Table definition for hook_schema().
   *
   * @return array<string, mixed>
   *   Schema API definition.
   */
  public static function schema(): array {
    $uuid = ['type' => 'varchar_ascii', 'length' => 36, 'not null' => FALSE];
    $optional_int = ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE];
    return [
      'description' => 'Cover sheet identifiers as keyed hashes (no identifier in clear).',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''],
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'nachforderung_id' => $optional_int,
        'docgen_document_uuid' => $uuid,
        'origin' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'letter'],
        'code_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        'key_version' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 1],
        'state' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'draft'],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'activated_at' => $optional_int,
        'revoked_at' => $optional_int,
        'revoked_by' => $optional_int,
        'revoke_reason' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'uuid' => ['uuid'],
        'code_hash' => ['code_hash'],
      ],
      'indexes' => [
        'application_state' => ['application_id', 'state'],
        'document' => ['docgen_document_uuid'],
      ],
    ];
  }

  /**
   * Inserts an identifier row.
   */
  public function insert(array $fields): int {
    return (int) $this->database->insert(self::TABLE)->fields($fields)->execute();
  }

  /**
   * Deletes the draft identifiers of a request letter.
   */
  public function deleteDrafts(string $document_uuid): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('docgen_document_uuid', $document_uuid)
      ->condition('state', 'draft')
      ->execute();
  }

  /**
   * TRUE when an identifier was issued for the document (any state).
   */
  public function existsForDocument(string $document_uuid): bool {
    return $this->database->select(self::TABLE, 'c')->fields('c', ['id'])
      ->condition('docgen_document_uuid', $document_uuid)
      ->range(0, 1)->execute()->fetchField() !== FALSE;
  }

  /**
   * Documents that have any issued identifier, in one metadata-only read.
   *
   * @return string[]
   *   Distinct document UUIDs.
   */
  public function documentsWithCodes(array $document_uuids): array {
    if ($document_uuids === []) {
      return [];
    }
    return $this->database->select(self::TABLE, 'c')->fields('c', ['docgen_document_uuid'])
      ->condition('docgen_document_uuid', $document_uuids, 'IN')->distinct()->execute()->fetchCol();
  }

  /**
   * Activates the draft identifiers of a released request letter.
   *
   * @return int
   *   Number of activated rows (0 when already active: idempotent).
   */
  public function activateForDocument(string $document_uuid, int $now): int {
    return (int) $this->database->update(self::TABLE)
      ->fields(['state' => 'active', 'activated_at' => $now])
      ->condition('docgen_document_uuid', $document_uuid)
      ->condition('state', 'draft')
      ->execute();
  }

  /**
   * Resolves manual or scanned input to an identifier row, NULL when unknown.
   *
   * Normalizes, then hashes for every key version in use. Draft identifiers
   * never left the office and count as unknown.
   */
  public function findByCode(string $input): ?array {
    $canonical = CoverCode::normalize($input);
    if ($canonical === NULL) {
      return NULL;
    }
    $versions = $this->versions();
    if ($versions === []) {
      return NULL;
    }
    $hashes = array_map(fn (int $version): string => $this->hasher->hash($canonical, $version), $versions);
    $row = $this->database->select(self::TABLE, 'c')->fields('c')
      ->condition('code_hash', $hashes, 'IN')
      ->condition('state', 'draft', '<>')
      ->range(0, 1)->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * One identifier row by id (any state), NULL when unknown.
   *
   * @return array<string, mixed>|null
   *   The row.
   */
  public function find(int $id): ?array {
    $row = $this->database->select(self::TABLE, 'c')->fields('c')->condition('id', $id)->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Key versions in use.
   *
   * @return int[]
   *   Distinct versions.
   */
  public function versions(): array {
    return array_map('intval', $this->database->select(self::TABLE, 'c')->fields('c', ['key_version'])->distinct()->execute()->fetchCol());
  }

  /**
   * Identifiers of an application, newest first.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  public function forApplication(int $application_id, int $limit = 50): array {
    return $this->database->select(self::TABLE, 'c')->fields('c')
      ->condition('application_id', $application_id)
      ->orderBy('created', 'DESC')->orderBy('id', 'DESC')
      ->range(0, $limit)->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Number of active identifiers of an application.
   *
   * @param int $application_id
   *   The application.
   * @param bool $for_update
   *   TRUE inside a storing transaction: the counted rows stay locked until
   *   it ends (SQLite serializes writers on its own).
   */
  public function activeCount(int $application_id, bool $for_update = FALSE): int {
    $query = $this->database->select(self::TABLE, 'c')
      ->condition('application_id', $application_id)
      ->condition('state', 'active');
    if (!$for_update) {
      return (int) $query->countQuery()->execute()->fetchField();
    }
    // At most a few rows; counted here because a locking read cannot be
    // wrapped in COUNT(*).
    $query->fields('c', ['id'])->forUpdate();
    return count($query->execute()->fetchCol());
  }

  /**
   * One identifier row of an application by its UUID.
   */
  public function findForApplication(int $application_id, string $uuid): ?array {
    $row = $this->database->select(self::TABLE, 'c')->fields('c')
      ->condition('application_id', $application_id)
      ->condition('uuid', strtolower($uuid))
      ->execute()->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Revokes an active identifier.
   *
   * @return bool
   *   TRUE when this call revoked it.
   */
  public function revoke(int $id, int $uid, string $reason, int $now): bool {
    return (int) $this->database->update(self::TABLE)
      ->fields(['state' => 'revoked', 'revoked_at' => $now, 'revoked_by' => $uid, 'revoke_reason' => $reason])
      ->condition('id', $id)
      ->condition('state', 'active')
      ->execute() === 1;
  }

  /**
   * Draft identifiers with their document UUIDs, for reconciliation.
   *
   * @return array<int, array{application_id: string, docgen_document_uuid: string, created: string}>
   *   Rows.
   */
  public function drafts(int $limit = 200, ?int $application_id = NULL): array {
    $query = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['application_id', 'docgen_document_uuid', 'created'])
      ->condition('state', 'draft')
      ->isNotNull('docgen_document_uuid');
    if ($application_id !== NULL) {
      $query->condition('application_id', $application_id);
    }
    return $query->range(0, $limit)->execute()->fetchAll(FetchAs::Associative);
  }

}
