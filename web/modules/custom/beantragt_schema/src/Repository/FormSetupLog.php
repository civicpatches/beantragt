<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Append-only log of the guided form setup.
 *
 * Holds recommendations, decisions and the one information sheet run per
 * form, keyed by form UUID and revision id. Never stored in field_schema.
 * Rows hold no applicant data: values come from the schema, quotes from
 * published public documents. There is no update method on purpose; rows
 * are only appended, and deleted together with their form.
 */
class FormSetupLog {

  public const TABLE = 'beantragt_form_setup_log';

  public const KIND_RECOMMENDATION = 'recommendation';
  public const KIND_DECISION = 'decision';
  public const KIND_RUN = 'run';

  /**
   * Value of run_slot on the run row; NULL on every other row.
   */
  public const RUN_SLOT = 'run';

  /**
   * Table definition for hook_schema().
   *
   * @return array<string, mixed>
   *   Schema API definition.
   */
  public static function schema(): array {
    return [
      'description' => 'Guided form setup: recommendations, decisions and runs per form (no applicant data).',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'form_uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''],
        'revision_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'kind' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => ''],
        'screen_id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'field_id' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'property' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        // Big: the wording snapshot of a 100-field section can exceed the
        // 65,535 bytes of a MariaDB TEXT column.
        'value' => ['type' => 'text', 'size' => 'big', 'not null' => FALSE],
        'choice' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
        'origin' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => FALSE],
        'origin_ref' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'model_id' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'recommended_value' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'quote' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'source_id' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => FALSE],
        'source_title' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
        'source_version' => ['type' => 'varchar', 'length' => 128, 'not null' => FALSE],
        'source_page' => ['type' => 'int', 'not null' => FALSE],
        'basis_hash' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => FALSE],
        'run_slot' => ['type' => 'varchar_ascii', 'length' => 8, 'not null' => FALSE],
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'created' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['id'],
      // NULL never collides in a unique key: any number of recommendation and
      // decision rows, exactly one run row per form.
      'unique keys' => [
        'form_run' => ['form_uuid', 'run_slot'],
      ],
      'indexes' => [
        'form_kind' => ['form_uuid', 'kind', 'id'],
        'form_property' => ['form_uuid', 'screen_id', 'field_id', 'property', 'id'],
      ],
    ];
  }

  /**
   * Constructs the log.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Appends decision rows in one transaction.
   *
   * @param string $form_uuid
   *   Form UUID.
   * @param int $revision_id
   *   Form revision the decisions refer to.
   * @param string $screen_id
   *   Section id.
   * @param list<array<string, mixed>> $rows
   *   Rows with field_id, property, value, choice and the recommendation
   *   snapshot columns (origin, origin_ref, model_id, recommended_value,
   *   quote, source_*). JSON columns are given decoded.
   * @param int $uid
   *   Staff user from the session.
   *
   * @return int
   *   Number of rows written.
   */
  public function appendDecisions(string $form_uuid, int $revision_id, string $screen_id, array $rows, int $uid): int {
    $transaction = $this->database->startTransaction();
    try {
      foreach ($rows as $row) {
        $this->insert([
          'form_uuid' => $form_uuid,
          'revision_id' => $revision_id,
          'kind' => self::KIND_DECISION,
          'screen_id' => $screen_id,
          'uid' => $uid,
        ] + $row);
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    return count($rows);
  }

  /**
   * Appends recommendation rows and the one run row in one transaction.
   *
   * @param string $form_uuid
   *   Form UUID.
   * @param int $revision_id
   *   Form revision the run refers to.
   * @param string $model_id
   *   Model id of the run.
   * @param list<array<string, mixed>> $rows
   *   Recommendation rows (screen_id, field_id, property, value, quote,
   *   basis_hash, source_*).
   * @param array<string, mixed> $run_value
   *   Run value: documents, kept, dropped.
   *
   * @return int
   *   Number of recommendation rows written.
   *
   * @throws \Drupal\Core\Database\IntegrityConstraintViolationException
   *   When the form already has a run row (unique key form_uuid, run_slot);
   *   the transaction is rolled back and nothing is written.
   */
  public function appendRun(string $form_uuid, int $revision_id, string $model_id, array $rows, array $run_value): int {
    $transaction = $this->database->startTransaction();
    try {
      foreach ($rows as $row) {
        $this->insert([
          'form_uuid' => $form_uuid,
          'revision_id' => $revision_id,
          'kind' => self::KIND_RECOMMENDATION,
          'model_id' => $model_id,
          'uid' => 0,
        ] + $row);
      }
      $this->insert([
        'form_uuid' => $form_uuid,
        'revision_id' => $revision_id,
        'kind' => self::KIND_RUN,
        'property' => 'run',
        'value' => $run_value,
        'model_id' => $model_id,
        'run_slot' => self::RUN_SLOT,
        'uid' => 0,
      ]);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    return count($rows);
  }

  /**
   * Returns the latest decision row per (screen, field, property).
   *
   * @return list<array<string, mixed>>
   *   Decoded rows in id order.
   */
  public function latestDecisions(string $form_uuid): array {
    $latest = [];
    foreach ($this->rows($form_uuid, self::KIND_DECISION) as $row) {
      $latest[$row['screen_id'] . "\0" . $row['field_id'] . "\0" . $row['property']] = $row;
    }
    $rows = array_values($latest);
    usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
    return $rows;
  }

  /**
   * Returns all recommendation rows of a form.
   *
   * @return list<array<string, mixed>>
   *   Decoded rows in id order.
   */
  public function recommendations(string $form_uuid): array {
    return $this->rows($form_uuid, self::KIND_RECOMMENDATION);
  }

  /**
   * Returns one recommendation row of a form, or NULL.
   *
   * @return array<string, mixed>|null
   *   Decoded row.
   */
  public function recommendation(string $form_uuid, int $id): ?array {
    $row = $this->database->select(self::TABLE, 'l')->fields('l')
      ->condition('form_uuid', $form_uuid)
      ->condition('kind', self::KIND_RECOMMENDATION)
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $this->decode($row) : NULL;
  }

  /**
   * Returns the run row of a form, or NULL.
   *
   * @return array<string, mixed>|null
   *   Decoded row.
   */
  public function run(string $form_uuid): ?array {
    $rows = $this->rows($form_uuid, self::KIND_RUN);
    return $rows[0] ?? NULL;
  }

  /**
   * Deletes every row of a form (the form itself was deleted).
   *
   * @return int
   *   Number of deleted rows.
   */
  public function deleteForForm(string $form_uuid): int {
    // Before update 10011 has run (and in kernel tests that do not install
    // the table), deleting a form must keep working.
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return 0;
    }
    return (int) $this->database->delete(self::TABLE)
      ->condition('form_uuid', $form_uuid)
      ->execute();
  }

  /**
   * Inserts one row; JSON columns are encoded here.
   *
   * @param array<string, mixed> $row
   *   Row values.
   */
  private function insert(array $row): void {
    foreach (['value', 'recommended_value'] as $json_column) {
      if (array_key_exists($json_column, $row)) {
        $row[$json_column] = json_encode($row[$json_column], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
      }
    }
    $row['created'] ??= $this->time->getRequestTime();
    $this->database->insert(self::TABLE)->fields($row)->execute();
  }

  /**
   * Returns the rows of one kind in id order.
   *
   * @return list<array<string, mixed>>
   *   Decoded rows.
   */
  private function rows(string $form_uuid, string $kind): array {
    $rows = $this->database->select(self::TABLE, 'l')->fields('l')
      ->condition('form_uuid', $form_uuid)
      ->condition('kind', $kind)
      ->orderBy('id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
    return array_values(array_map(fn(array $row): array => $this->decode($row), $rows));
  }

  /**
   * Decodes JSON columns and casts integer columns.
   *
   * @param array<string, mixed> $row
   *   Raw row.
   *
   * @return array<string, mixed>
   *   Decoded row.
   */
  private function decode(array $row): array {
    foreach (['id', 'revision_id', 'uid', 'created'] as $int_column) {
      $row[$int_column] = (int) $row[$int_column];
    }
    $row['source_page'] = $row['source_page'] === NULL ? NULL : (int) $row['source_page'];
    foreach (['value', 'recommended_value'] as $json_column) {
      $row[$json_column] = $row[$json_column] === NULL
        ? NULL
        : json_decode((string) $row[$json_column], TRUE, 512, JSON_THROW_ON_ERROR);
    }
    return $row;
  }

}
