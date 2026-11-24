<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Component\Uuid\Uuid;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Audit trail of protected application reads and mailbox-based citizen access.
 *
 * Lives in its own table because dblog rotates after 1000 rows, which a few
 * list views would exhaust. Stores who read which application through which
 * path and when, never the values themselves.
 */
class ProtectedAccessLog {

  public const TABLE = 'beantragt_protected_access_log';

  /**
   * Window for deduplicating application reads by the same account.
   */
  public const DEDUPE_SECONDS = 600;

  /**
   * Rows older than this are purged by cron.
   */
  public const RETENTION_SECONDS = 365 * 86400;

  /**
   * Constructs the access log.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Records a read unless the same person read the same case recently.
   *
   * Only earlier application reads count for the dedupe: a subject row that
   * names the application (a cover sheet print) must not hide the next read.
   * An attachment_list row only covers later attachment_list reads, never a
   * later read through another channel. An incoming attachment_list read still
   * deduplicates against every recent application read by the same account.
   */
  public function record(ApplicationInterface $application, AccountInterface $account, string $channel): void {
    $now = $this->time->getRequestTime();
    $uid = (int) $account->id();
    $uuid = (string) $application->uuid();
    $query = $this->database->select(self::TABLE, 'l')
      ->fields('l', ['id'])
      ->condition('uid', $uid)
      ->condition('application_uuid', $uuid)
      ->condition('subject_type', 'application')
      ->condition('created', $now - self::DEDUPE_SECONDS, '>=')
      ->range(0, 1);
    if ($channel !== 'attachment_list') {
      $query->condition('channel', 'attachment_list', '!=');
    }
    $recent = $query->execute()->fetchField();
    if ($recent !== FALSE) {
      return;
    }
    $this->database->insert(self::TABLE)
      ->fields([
        'uid' => $uid,
        'application_uuid' => $uuid,
        'subject_type' => 'application',
        'subject_uuid' => $uuid,
        'reference' => mb_substr((string) ($application->getReferenceNumber() ?? ''), 0, 64),
        'channel' => mb_substr($channel, 0, 32),
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Records a subject read or print before any value or byte is returned.
   *
   * Exceptions deliberately propagate. Each channel has its own audit row.
   * A cover sheet print (cover_code) and an attachment download
   * (application_attachment, every download, no dedupe) name their
   * application: the row carries the application UUID and reference
   * number, never the identifier or a file name.
   */
  public function recordSubject(string $type, string $uuid, AccountInterface $account, string $channel, ?ApplicationInterface $application = NULL): void {
    $channels = [
      'bulk_case' => 'bulk_case_view',
      'bulk_item' => 'bulk_item_download',
      'cover_code' => 'cover_printed',
      'application_attachment' => 'attachment_download',
      'application_mailbox' => 'verified_mailbox_read',
    ];
    $named = in_array($type, ['cover_code', 'application_attachment'], TRUE);
    if (($channels[$type] ?? NULL) !== $channel || !Uuid::isValid($uuid)
      || ($named && $application === NULL)) {
      throw new \InvalidArgumentException('Invalid protected access subject.');
    }
    $application_uuid = '';
    $reference = '';
    if ($named) {
      $application_uuid = (string) $application->uuid();
      $reference = mb_substr((string) ($application->getReferenceNumber() ?? ''), 0, 64);
    }
    $this->database->insert(self::TABLE)->fields([
      'uid' => (int) $account->id(),
      'application_uuid' => $application_uuid,
      'subject_type' => $type,
      'subject_uuid' => $uuid,
      'reference' => $reference,
      'channel' => $channel,
      'created' => $this->time->getCurrentTime(),
    ])->execute();
  }

  /**
   * Deletes rows past the retention period.
   *
   * @return int
   *   Number of deleted rows.
   */
  public function purge(): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('created', $this->time->getRequestTime() - self::RETENTION_SECONDS, '<')
      ->execute();
  }

  /**
   * Table definition for hook_schema() and the install update.
   *
   * @return array<string, mixed>
   *   Schema API definition.
   */
  public static function schema(): array {
    return [
      'description' => 'Staff reads of protected application values (no values stored).',
      'fields' => [
        'id' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'application_uuid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'subject_type' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE, 'default' => 'application'],
        'subject_uuid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'reference' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        'channel' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'subject' => ['subject_type', 'subject_uuid'],
        'application_uuid' => ['application_uuid'],
        'uid_application_created' => ['uid', 'application_uuid', 'created'],
        'created' => ['created'],
      ],
    ];
  }

}
