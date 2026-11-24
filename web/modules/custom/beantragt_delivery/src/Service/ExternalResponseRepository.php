<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\Transaction;

/**
 * Persists verified external responses independently of transport envelopes.
 */
final class ExternalResponseRepository {

  public function __construct(private readonly Connection $database) {}

  /**
   * Defines response and document tables for install and update hooks.
   */
  public static function schema(): array {
    $uuid = ['type' => 'varchar', 'length' => 36, 'not null' => TRUE];
    $timestamp = ['type' => 'int', 'not null' => FALSE];
    return [
      'beantragt_external_response' => [
        'fields' => [
          'uuid' => $uuid,
          'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'source' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
          'external_id_hash' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE],
          'external_reference' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
          'title' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
          'received' => $timestamp,
          'released' => $timestamp,
          'available' => $timestamp,
          'retrieved' => $timestamp,
          'notification_state' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'pending'],
          // 1 when the documents carry protected application data in clear
          // text (docgen notices drafted with clear values); staff downloads
          // then need the protected-data permission and are logged.
          'contains_protected' => [
            'type' => 'int',
            'size' => 'tiny',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 0,
          ],
          // approved, rejected, filed, or NULL for a response without
          // outcome (contract 1 before the optional outcome key).
          'outcome' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
          // The case system's own word for the outcome ("Festsetzung"),
          // optional key outcomeLabel of reply data version 1.
          'outcome_label' => ['type' => 'varchar', 'length' => 64, 'not null' => FALSE],
          // Either decision (releasable notice) or filing_receipt (staff only).
          'kind' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'decision'],
          // 1 for a decision (docgen approval or rejection, case-system
          // reply other than a filing receipt), 0 for a communication.
          'is_decision' => [
            'type' => 'int',
            'size' => 'tiny',
            'unsigned' => TRUE,
            'not null' => TRUE,
            'default' => 0,
          ],
          // Effective route fixed at provision (portal, postal, bundid);
          // empty while pending. Never rewritten.
          'delivery_route' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => ''],
          // DeliveryRules::REASONS, NULL when the chosen route applies.
          'route_reason' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => FALSE],
          // When the email notice was actually sent.
          'notification_sent_at' => $timestamp,
          // Postal dispatch due since (provision on route postal).
          'postal_due_at' => $timestamp,
          // Handed over to the mail room: when and by whom (uid).
          'postal_handed_over_at' => $timestamp,
          'postal_handed_over_by' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        ],
        'primary key' => ['uuid'],
        'unique keys' => ['external_identity' => ['source', 'external_id_hash']],
        'indexes' => ['application' => ['application_id']],
      ],
      'beantragt_external_response_document' => [
        'fields' => [
          'uuid' => $uuid,
          'response_uuid' => $uuid,
          'file_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'sha512' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE],
        ],
        'primary key' => ['uuid'],
        'indexes' => ['response' => ['response_uuid']],
      ],
    ];
  }

  /**
   * Loads the globally unique transport response identity.
   */
  public function findExternal(string $source, string $external_id): ?array {
    return $this->find(['source' => $source, 'external_id_hash' => hash('sha256', $external_id)]);
  }

  /**
   * Loads a response matching all supplied conditions.
   */
  public function find(array $conditions): ?array {
    $query = $this->database->select('beantragt_external_response', 'r')->fields('r');
    foreach ($conditions as $key => $value) {
      $query->condition($key, $value);
    }
    $row = $query->execute()->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Lists responses visible to the requested application audience.
   */
  public function list(int $application_id, bool $staff): array {
    $query = $this->database->select('beantragt_external_response', 'r')->fields('r')->condition('application_id', $application_id)->orderBy('received', 'DESC');
    if (!$staff) {
      // A filing receipt is never released; filter it anyway.
      $query->isNotNull('released')->condition('kind', 'decision');
    }
    return $query->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Links verified responses through the existing immutable FIT case binding.
   *
   * The reply acknowledgement is persisted immediately after ingestion. Both
   * application checks prevent an unrelated response being linked by accident.
   */
  public function listForTransfer(int $application_id, int $log_id): array {
    return $this->listForTransfers($application_id, [$log_id])[$log_id] ?? [];
  }

  /**
   * Loads safe response projections for a bounded transfer page in two queries.
   */
  public function listForTransfers(int $application_id, array $log_ids): array {
    $log_ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $log_ids), static fn(int $id): bool => $id > 0))), 0, 100);
    if ($log_ids === []) {
      return [];
    }
    $query = $this->database->select('beantragt_fit_reply_ack', 'a');
    $query->innerJoin('beantragt_fit_case', 'c', 'c.case_id = a.case_id AND c.application_id = a.application_id');
    $bindings = $query->fields('a', ['reply_id'])->fields('c', ['log_id'])
      ->condition('c.log_id', $log_ids, 'IN')->condition('c.application_id', $application_id)
      ->execute()->fetchAll(FetchAs::Associative);
    $hashes = [];
    foreach ($bindings as $binding) {
      $hashes[hash('sha256', $binding['reply_id'])] = (int) $binding['log_id'];
    }
    if ($hashes === []) {
      return [];
    }
    $responses = $this->database->select('beantragt_external_response', 'r')
      ->fields('r', ['uuid', 'title', 'received', 'released', 'available', 'retrieved', 'external_id_hash'])
      ->condition('application_id', $application_id)->condition('source', 'fit_connect')
      ->condition('external_id_hash', array_keys($hashes), 'IN')->orderBy('received', 'DESC')
      ->execute()->fetchAll(FetchAs::Associative);
    $result = [];
    foreach ($responses as $response) {
      $log_id = $hashes[$response['external_id_hash']];
      unset($response['external_id_hash']);
      $result[$log_id][] = $response;
    }
    return $result;
  }

  /**
   * Lists documents belonging to a response, in the order they were given.
   *
   * The files are written in document order, so their serial IDs keep it
   * (a request letter before its cover sheet); the random document UUID
   * would not.
   */
  public function documents(string $uuid): array {
    return $this->database->select('beantragt_external_response_document', 'd')->fields('d')->condition('response_uuid', $uuid)->orderBy('file_id')->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Makes file entities and their response references one database unit.
   */
  public function startTransaction(): Transaction {
    return $this->database->startTransaction();
  }

  /**
   * Finds a bounded batch of old managed files without a response reference.
   */
  public function staleOrphanFileIds(int $cutoff): array {
    $query = $this->database->select('file_managed', 'f');
    $query->leftJoin('beantragt_external_response_document', 'd', 'd.file_id = f.fid');
    return $query->fields('f', ['fid'])
      ->condition('f.uri', $this->database->escapeLike('private://external_responses/') . '%', 'LIKE')
      ->condition('f.created', $cutoff, '<')
      ->isNull('d.file_id')->orderBy('f.fid')->range(0, 25)->execute()->fetchCol();
  }

  /**
   * Persists the response and document references atomically.
   */
  public function insert(array $response, array $documents): void {
    $transaction = $this->database->startTransaction();
    try {
      $this->database->insert('beantragt_external_response')->fields($response)->execute();
      foreach ($documents as $document) {
        $this->database->insert('beantragt_external_response_document')->fields($document)->execute();
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Makes a response available without changing its original release time.
   *
   * The provision columns (delivery_route, route_reason, postal_due_at and
   * notification_state) are written in the same statement, only while the
   * response is still unreleased.
   *
   * @param string $uuid
   *   Response UUID.
   * @param int $time
   *   Release time.
   * @param array<string, mixed> $provision
   *   Provision columns.
   *
   * @return int
   *   Affected rows: 0 when the response was released already.
   */
  public function release(string $uuid, int $time, array $provision = []): int {
    $provision = array_intersect_key($provision, array_flip([
      'delivery_route',
      'route_reason',
      'postal_due_at',
      'notification_state',
    ]));
    return (int) $this->database->update('beantragt_external_response')
      ->fields(['released' => $time, 'available' => $time] + $provision)
      ->condition('uuid', $uuid)->isNull('released')->execute();
  }

  /**
   * Records the handover of a due postal dispatch to the mail room once.
   *
   * @return int
   *   Affected rows: 0 when no postal dispatch is due or it is recorded.
   */
  public function handOver(string $uuid, int $time, int $uid): int {
    return (int) $this->database->update('beantragt_external_response')
      ->fields(['postal_handed_over_at' => $time, 'postal_handed_over_by' => $uid])
      ->condition('uuid', $uuid)->isNotNull('released')->isNotNull('postal_due_at')->isNull('postal_handed_over_at')
      ->execute();
  }

  /**
   * Records the first applicant document request.
   */
  public function retrieved(string $uuid, int $time): void {
    $this->database->update('beantragt_external_response')->fields(['retrieved' => $time])->condition('uuid', $uuid)->isNull('retrieved')->execute();
  }

  /**
   * Finds released responses whose notification has never been attempted.
   */
  public function pendingNotifications(): array {
    return $this->database->select('beantragt_external_response', 'r')->fields('r')
      ->condition('notification_state', 'pending')->isNotNull('released')
      ->range(0, 25)->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Retires pending notifications for bulk applications, including recovery.
   */
  public function notificationNotApplicable(string $uuid): void {
    $this->database->update('beantragt_external_response')->fields(['notification_state' => 'not_applicable'])
      ->condition('uuid', $uuid)->condition('notification_state', 'pending')->execute();
  }

  /**
   * Claims an at-most-once send before contacting the mail transport.
   */
  public function claimNotification(string $uuid): bool {
    return (bool) $this->database->update('beantragt_external_response')->fields(['notification_state' => 'sending'])->condition('uuid', $uuid)->condition('notification_state', 'pending')->isNotNull('released')->execute();
  }

  /**
   * Defers side effects until an enclosing application transaction commits.
   */
  public function afterCommit(callable $callback): void {
    if ($this->database->inTransaction()) {
      $this->database->transactionManager()->addPostTransactionCallback(static function (bool $success) use ($callback): void {
        if ($success) {
          $callback();
        }
      });
    }
    else {
      $callback();
    }
  }

  /**
   * Records the actual result of a claimed notification attempt.
   */
  public function notificationResult(string $uuid, bool $sent, ?int $sentAt = NULL): void {
    $fields = ['notification_state' => $sent ? 'sent' : 'failed'];
    if ($sent && $sentAt !== NULL) {
      $fields['notification_sent_at'] = $sentAt;
    }
    $this->database->update('beantragt_external_response')->fields($fields)->condition('uuid', $uuid)->condition('notification_state', 'sending')->execute();
  }

  /**
   * Deletes response and document metadata.
   */
  public function delete(string $uuid): void {
    $this->database->delete('beantragt_external_response_document')->condition('response_uuid', $uuid)->execute();
    $this->database->delete('beantragt_external_response')->condition('uuid', $uuid)->execute();
  }

}
