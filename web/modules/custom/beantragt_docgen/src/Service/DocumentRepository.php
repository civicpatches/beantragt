<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\Transaction;

/**
 * Decision documents of applications, their preview views and event chain.
 *
 * One row per document; an application can have any number of documents,
 * each with its own life cycle (draft, preview, released) and its own hash
 * chain in beantragt_docgen_event. Rows never hold document content; files
 * live in private://docgen/<application uuid>/<document uuid>/.
 */
class DocumentRepository {

  public const STATE_DRAFT = 'draft';
  /**
   * Release decided and stored, provision to the applicant not yet booked.
   *
   * Set durably before ExternalResponseService::ingest(). A document in this
   * state can no longer be changed or discarded; the next release call for
   * it resumes the provision with the same deterministic identity.
   */
  public const STATE_RELEASING = 'releasing';
  public const STATE_RELEASED = 'released';

  /**
   * Constructs the repository.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Table definitions for hook_schema().
   *
   * @return array<string, array<string, mixed>>
   *   Schema API definitions.
   */
  public static function schema(): array {
    $uuid = ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''];
    $optional_uuid = ['type' => 'varchar_ascii', 'length' => 36, 'not null' => FALSE];
    $hash = ['type' => 'varchar_ascii', 'length' => 64, 'not null' => FALSE];
    $int = ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0];
    $optional_int = ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE];
    return [
      'beantragt_docgen_document' => [
        'description' => 'Decision documents drafted from templates (metadata and hashes, no content).',
        'fields' => [
          'uuid' => $uuid,
          'application_id' => $int,
          'application_uuid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE, 'default' => ''],
          'template_id' => $int,
          'template_family' => $uuid,
          'template_version' => $int,
          'template_sha256' => $hash,
          'purpose' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE, 'default' => 'other'],
          'title' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
          'state' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => self::STATE_DRAFT],
          'data_sha256' => $hash,
          'missing' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
          'docx_file_id' => $optional_int,
          'docx_sha256' => $hash,
          'docx_revision' => $int,
          'docx_source' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'rendered'],
          'pdf_file_id' => $optional_int,
          'pdf_sha256' => $hash,
          'pdf_docx_sha256' => $hash,
          'validation' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        ] + self::requestLetterFields() + [
          'created_by' => $int,
          'created' => $int,
          'changed' => $int,
          'released_by' => $optional_int,
          'released_at' => $optional_int,
          'release_uuid' => $optional_uuid,
          'response_uuid' => $optional_uuid,
        ],
        'primary key' => ['uuid'],
        'indexes' => [
          'application' => ['application_id'],
          'template' => ['template_id'],
          'release' => ['release_uuid'],
        ],
      ],
      'beantragt_docgen_preview' => [
        'description' => 'Which preview PDF was served to whom (viewed = 0) and whose browser confirmed it (viewed = time); release requires the releasing person to have confirmed the current PDF.',
        'fields' => [
          'document_uuid' => $uuid,
          'pdf_sha256' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE, 'default' => ''],
          'uid' => $int,
          'viewed' => $int,
        ],
        'primary key' => ['document_uuid', 'pdf_sha256', 'uid'],
      ],
      'beantragt_docgen_event' => [
        'description' => 'Hash-chained life cycle events of decision documents (no content).',
        'fields' => [
          'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
          'document_uuid' => $uuid,
          'application_id' => $int,
          'event' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE, 'default' => ''],
          'uid' => $int,
          'docx_sha256' => $hash,
          'pdf_sha256' => $hash,
          'created' => $int,
          'previous_hash' => $hash,
          'chain_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        ],
        'primary key' => ['id'],
        // Second guard next to the row lock in appendEvent(): two events can
        // never continue the same link, so the chain stays a line.
        'unique keys' => [
          'chain_link' => ['document_uuid', 'previous_hash'],
        ],
        'indexes' => [
          'document' => ['document_uuid'],
          'application' => ['application_id'],
        ],
      ],
    ];
  }

  /**
   * Request letter fields: render day and the companion cover sheet PDF.
   *
   * Also added to existing sites by beantragt_docgen_update_10002().
   *
   * @return array<string, array<string, mixed>>
   *   Schema API field definitions.
   */
  public static function requestLetterFields(): array {
    return [
      'rendered_at' => [
        'type' => 'int',
        'unsigned' => TRUE,
        'not null' => FALSE,
        'description' => 'When the DOCX was last rendered from the template; the deadline of a request letter counts from this day.',
      ],
      'cover_pdf_file_id' => [
        'type' => 'int',
        'unsigned' => TRUE,
        'not null' => FALSE,
        'description' => 'Cover sheet PDF/A of a request letter, released with the letter.',
      ],
      'cover_pdf_sha256' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => FALSE],
      'cover_validation' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
    ];
  }

  /**
   * Starts a transaction.
   */
  public function startTransaction(): Transaction {
    return $this->database->startTransaction();
  }

  /**
   * Inserts a new document row.
   */
  public function insert(array $row): void {
    $this->database->insert('beantragt_docgen_document')->fields($row)->execute();
  }

  /**
   * Updates fields of one document.
   */
  public function update(string $uuid, array $fields): void {
    $this->database->update('beantragt_docgen_document')->fields($fields)->condition('uuid', $uuid)->execute();
  }

  /**
   * Loads one document of an application.
   */
  public function find(int $application_id, string $uuid): ?array {
    $row = $this->database->select('beantragt_docgen_document', 'd')->fields('d')
      ->condition('uuid', $uuid)->condition('application_id', $application_id)
      ->execute()->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Lists the documents of an application, newest first.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  public function list(int $application_id): array {
    return $this->database->select('beantragt_docgen_document', 'd')->fields('d')
      ->condition('application_id', $application_id)
      ->orderBy('created', 'DESC')->orderBy('uuid')
      ->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Reads minimal document metadata for caller-authorized applications.
   *
   * @param int[] $application_ids
   *   At most 500 application IDs already bounded to the caller's scope.
   * @param string $purpose
   *   Document purpose to include, in every state.
   *
   * @return array<int, array{uuid: string, application_id: int, state: string, created_by: int, created: int}>
   *   Minimal rows, newest first.
   */
  public function forApplications(array $application_ids, string $purpose): array {
    if (count($application_ids) > 500) {
      throw new \InvalidArgumentException('At most 500 application IDs are allowed.');
    }
    if ($application_ids === []) {
      return [];
    }
    $rows = $this->database->select('beantragt_docgen_document', 'd')
      ->fields('d', ['uuid', 'application_id', 'state', 'created_by', 'created'])
      ->condition('application_id', $application_ids, 'IN')->condition('purpose', $purpose)
      ->orderBy('created', 'DESC')->orderBy('uuid')->execute()->fetchAll(FetchAs::Associative);
    return array_map(static fn(array $row): array => [
      'uuid' => (string) $row['uuid'],
      'application_id' => (int) $row['application_id'],
      'state' => (string) $row['state'],
      'created_by' => (int) $row['created_by'],
      'created' => (int) $row['created'],
    ], $rows);
  }

  /**
   * Lists the documents of one release of an application, ordered by UUID.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  public function listRelease(int $application_id, string $release_uuid): array {
    return $this->database->select('beantragt_docgen_document', 'd')->fields('d')
      ->condition('application_id', $application_id)->condition('release_uuid', $release_uuid)
      ->orderBy('uuid')
      ->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Counts documents that reference a template version.
   */
  public function countForTemplate(int $template_id): int {
    return (int) $this->database->select('beantragt_docgen_document', 'd')
      ->condition('template_id', $template_id)->countQuery()->execute()->fetchField();
  }

  /**
   * Deletes a document with its views and events.
   */
  public function delete(string $uuid): void {
    $this->database->delete('beantragt_docgen_preview')->condition('document_uuid', $uuid)->execute();
    $this->database->delete('beantragt_docgen_event')->condition('document_uuid', $uuid)->execute();
    $this->database->delete('beantragt_docgen_document')->condition('uuid', $uuid)->execute();
  }

  /**
   * Records that a person opened a preview PDF.
   */
  public function recordView(string $uuid, string $pdf_sha256, int $uid, int $time): void {
    $this->database->merge('beantragt_docgen_preview')
      ->keys(['document_uuid' => $uuid, 'pdf_sha256' => $pdf_sha256, 'uid' => $uid])
      ->fields(['viewed' => $time])
      ->execute();
  }

  /**
   * Records that a preview PDF was served to a person (not yet a view).
   *
   * Inserts a row with viewed = 0 once; an existing row, served or already
   * confirmed, stays as it is.
   */
  public function recordServed(string $uuid, string $pdf_sha256, int $uid, int $time): void {
    if ($this->wasServed($uuid, $pdf_sha256, $uid)) {
      return;
    }
    try {
      $this->database->insert('beantragt_docgen_preview')
        ->fields(['document_uuid' => $uuid, 'pdf_sha256' => $pdf_sha256, 'uid' => $uid, 'viewed' => 0])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Served in parallel; the row exists.
    }
  }

  /**
   * Returns TRUE when exactly this PDF was served to the person.
   */
  public function wasServed(string $uuid, string $pdf_sha256, int $uid): bool {
    return $this->database->select('beantragt_docgen_preview', 'p')->fields('p', ['uid'])
      ->condition('document_uuid', $uuid)->condition('pdf_sha256', $pdf_sha256)->condition('uid', $uid)
      ->range(0, 1)->execute()->fetchField() !== FALSE;
  }

  /**
   * Returns TRUE when the person's browser confirmed exactly this PDF.
   */
  public function hasViewed(string $uuid, string $pdf_sha256, int $uid): bool {
    return (bool) $this->database->select('beantragt_docgen_preview', 'p')->fields('p', ['viewed'])
      ->condition('document_uuid', $uuid)->condition('pdf_sha256', $pdf_sha256)->condition('uid', $uid)
      ->execute()->fetchField();
  }

  /**
   * Records the first opening of a preview PDF by a person.
   *
   * The check and the "viewed" event happen under the document row lock, so
   * two parallel openings write one event per person and PDF, and the chain
   * stays linear.
   *
   * @return bool
   *   TRUE when this call wrote the event.
   */
  public function recordViewOnce(string $uuid, int $application_id, string $pdf_sha256, ?string $docx_sha256, int $uid, int $time): bool {
    $transaction = $this->database->startTransaction();
    try {
      $this->lockDocument($uuid);
      $first = !$this->hasViewed($uuid, $pdf_sha256, $uid);
      if ($first) {
        $this->appendEvent($uuid, $application_id, 'viewed', $uid, $docx_sha256, $pdf_sha256, $time);
      }
      $this->recordView($uuid, $pdf_sha256, $uid, $time);
      return $first;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Appends a hash-chained event to a document's history.
   *
   * Chain_hash = sha256(previous chain hash | document | event | uid |
   * docx hash | pdf hash | time). A changed or removed earlier row breaks
   * every later hash (verifyChain()). Reading the last link and inserting
   * the next one run in one transaction under the document row lock, so
   * parallel appends wait for each other instead of forking the chain.
   */
  public function appendEvent(string $uuid, int $application_id, string $event, int $uid, ?string $docx_sha256, ?string $pdf_sha256, int $time): string {
    $transaction = $this->database->startTransaction();
    try {
      $this->lockDocument($uuid);
      $previous = $this->database->select('beantragt_docgen_event', 'e')->fields('e', ['chain_hash'])
        ->condition('document_uuid', $uuid)->orderBy('id', 'DESC')->range(0, 1)
        ->execute()->fetchField();
      $previous = is_string($previous) ? $previous : '';
      $chain = self::chainHash($previous, $uuid, $event, $uid, $docx_sha256, $pdf_sha256, $time);
      $this->database->insert('beantragt_docgen_event')->fields([
        'document_uuid' => $uuid,
        'application_id' => $application_id,
        'event' => $event,
        'uid' => $uid,
        'docx_sha256' => $docx_sha256,
        'pdf_sha256' => $pdf_sha256,
        'created' => $time,
        'previous_hash' => $previous === '' ? NULL : $previous,
        'chain_hash' => $chain,
      ])->execute();
      return $chain;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Locks the document row until the surrounding transaction ends.
   */
  private function lockDocument(string $uuid): void {
    if ($this->database->databaseType() === 'sqlite') {
      // SQLite serializes writers on its own and has no FOR UPDATE.
      return;
    }
    $this->database->query('SELECT [uuid] FROM {beantragt_docgen_document} WHERE [uuid] = :uuid FOR UPDATE', [':uuid' => $uuid]);
  }

  /**
   * Lists a document's events, oldest first.
   *
   * @return array<int, array<string, mixed>>
   *   Event rows.
   */
  public function events(string $uuid): array {
    return $this->database->select('beantragt_docgen_event', 'e')->fields('e')
      ->condition('document_uuid', $uuid)->orderBy('id')
      ->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Recomputes a document's chain; FALSE when any row was changed.
   */
  public function verifyChain(string $uuid): bool {
    $previous = '';
    foreach ($this->events($uuid) as $event) {
      $expected = self::chainHash($previous, $uuid, (string) $event['event'], (int) $event['uid'], $event['docx_sha256'], $event['pdf_sha256'], (int) $event['created']);
      if (!hash_equals($expected, (string) $event['chain_hash']) || (string) ($event['previous_hash'] ?? '') !== $previous) {
        return FALSE;
      }
      $previous = $expected;
    }
    return TRUE;
  }

  /**
   * Computes one chain link.
   */
  private static function chainHash(string $previous, string $uuid, string $event, int $uid, ?string $docx_sha256, ?string $pdf_sha256, int $time): string {
    return hash('sha256', implode('|', [
      $previous,
      $uuid,
      $event,
      (string) $uid,
      (string) $docx_sha256,
      (string) $pdf_sha256,
      (string) $time,
    ]));
  }

}
