<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Events of cover sheets and request records, without person reference.
 *
 * Params hold counts, codes and dates only: never an identifier, reference
 * number, name or file name. Purged after 365 days.
 */
class NachreichungEventLog {

  public const TABLE = 'beantragt_nachreichung_event';
  public const RETENTION_SECONDS = 365 * 86400;

  /**
   * Allowed event codes of steps 1 and 2.
   */
  public const CODES = [
    'cover_issued',
    'cover_activated',
    'cover_printed',
    'cover_revoked',
    'request_opened',
    'request_superseded',
    'request_answered',
    'request_partly_answered',
    'request_reopened',
    'deadline_extended',
    'target_lookup_hit',
    'target_lookup_miss',
    'applicant_shown',
    'nachreichung_attached',
    'nachreichung_reverted',
  ];

  /**
   * Constructs the log.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Table definition for hook_schema().
   *
   * @return array<string, mixed>
   *   Schema API definition.
   */
  public static function schema(): array {
    return [
      'description' => 'Events of cover sheets and request records (no personal data).',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'subject_uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE, 'default' => ''],
        'code' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'params' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'application' => ['application_id'],
        'created' => ['created'],
      ],
    ];
  }

  /**
   * Appends an event.
   *
   * @param int|null $application_id
   *   The application.
   * @param string $subject_uuid
   *   UUID of the identifier row or the request record.
   * @param string $code
   *   One of self::CODES.
   * @param array<string, int|string|bool|null> $params
   *   Counts, codes and dates only.
   * @param int $uid
   *   Acting person, 0 for the system.
   * @param int|null $now
   *   Event time, default the request time.
   */
  public function append(?int $application_id, string $subject_uuid, string $code, array $params, int $uid, ?int $now = NULL): void {
    if (!in_array($code, self::CODES, TRUE)) {
      throw new \InvalidArgumentException('Unknown nachreichung event.');
    }
    $this->database->insert(self::TABLE)->fields([
      'application_id' => $application_id,
      'subject_uuid' => $subject_uuid,
      'code' => $code,
      'params' => $params === [] ? NULL : json_encode($params, JSON_THROW_ON_ERROR),
      'uid' => $uid,
      'created' => $now ?? $this->time->getRequestTime(),
    ])->execute();
  }

  /**
   * Events of an application in order.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  public function forApplication(int $application_id): array {
    return $this->database->select(self::TABLE, 'e')->fields('e')
      ->condition('application_id', $application_id)
      ->orderBy('id')->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * TRUE when the person was shown the applicant of the target on the envelope.
   *
   * The identity confirmation of a decision (D14) needs this row.
   */
  public function shown(int $uid, string $subject_uuid, int $application_id): bool {
    return $this->database->select(self::TABLE, 'e')->fields('e', ['id'])
      ->condition('code', 'applicant_shown')
      ->condition('uid', $uid)
      ->condition('subject_uuid', $subject_uuid)
      ->condition('application_id', $application_id)
      ->range(0, 1)->execute()->fetchField() !== FALSE;
  }

  /**
   * Deletes events past the retention period.
   */
  public function purge(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('created', $this->time->getRequestTime() - self::RETENTION_SECONDS, '<')
      ->execute();
  }

}
