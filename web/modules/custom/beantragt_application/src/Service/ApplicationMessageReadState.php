<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Access\ApplicationCommentStaffScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Per-person read position for public applicant messages on visible cases.
 *
 * Callers must first check application and workspace access. No message bodies,
 * attachments or applicant identity are read or stored here.
 */
class ApplicationMessageReadState {

  public const TABLE = 'beantragt_application_message_seen';

  public const BASELINE_STATE = 'beantragt_application.message_read_baseline';

  /**
   * Reuses the original owner checks with shared page access dependencies.
   */
  public function withAccessBatch(array $applications, AccountInterface $account, callable $read): mixed {
    return $this->staffScope->withBatch($applications, $account, $read);
  }

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ApplicationCommentStaffScope $staffScope,
    private readonly StateInterface $state,
  ) {}

  /**
   * Defines the read-position table for new installs and updates.
   */
  public static function schema(): array {
    return [
      'description' => 'Per-staff read position for public applicant messages.',
      'fields' => [
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'kind' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'message'],
        'seen_tokens' => ['type' => 'text', 'size' => 'big', 'not null' => FALSE],
        'legacy_seen_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'seen_at' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'last_comment_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
      ],
      'primary key' => ['uid', 'application_id', 'kind'],
      'indexes' => ['application' => ['application_id']],
    ];
  }

  /**
   * Counts messages in one aggregate query over already-authorized cases.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   Only applications the caller has authorized for this account.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current staff account.
   *
   * @return array<string, int>
   *   Application UUID to count, including zeroes.
   */
  public function counts(array $applications, AccountInterface $account): array {
    $counts = [];
    $ids = [];
    $available = $this->database->schema()->tableExists(self::TABLE);
    foreach ($applications as $application) {
      $counts[$application->uuid()] = 0;
      if ($available && $this->canReadMessages($application, $account)) {
        $ids[(int) $application->id()] = $application->uuid();
      }
    }
    if ($ids === []) {
      return $counts;
    }
    $query = $this->unreadQuery(array_keys($ids), $account);
    $query->addField('c', 'field_application', 'application_id');
    $query->addExpression('COUNT(c.id)', 'message_count');
    $query->groupBy('c.field_application');
    foreach ($query->execute() as $row) {
      $counts[$ids[(int) $row->application_id]] = (int) $row->message_count;
    }
    return $counts;
  }

  /**
   * Returns the old read position and optionally advances to this opening.
   *
   * The internal id watermark disambiguates messages created in the same
   * second. It never leaves this service; the UI receives public UUIDs only.
   *
   * @return array{count: int, previousSeenAt: ?int, seenAt: ?int, newMessageIds: string[]}
   *   Snapshot before marking, for this opening's header and message labels.
   */
  public function detail(ApplicationInterface $application, AccountInterface $account, bool $mark_seen): array {
    $empty = ['count' => 0, 'previousSeenAt' => NULL, 'seenAt' => NULL, 'newMessageIds' => []];
    if (!$this->database->schema()->tableExists(self::TABLE) || !$this->canReadMessages($application, $account)) {
      return $empty;
    }
    $application_id = (int) $application->id();
    $uid = (int) $account->id();
    $previous = $this->database->select(self::TABLE, 's')->fields('s', ['seen_at'])
      ->condition('uid', $uid)->condition('application_id', $application_id)->condition('kind', 'message')
      ->execute()->fetchField();
    $previous = $previous === FALSE ? NULL : (int) $previous;
    $now = $this->time->getCurrentTime();
    // Capture the insertion boundary before reading UUIDs. A later insert in
    // this same second must remain unread, including delayed request saves.
    $boundary = $this->database->select('application_comment', 'c');
    $boundary->addExpression('MAX(c.id)');
    $last_id = (int) $boundary->condition('field_application', $application_id)
      ->condition('created', $now, '<=')->execute()->fetchField();
    $query = $this->unreadQuery([$application_id], $account);
    $query->addField('c', 'uuid');
    $message_ids = $query->condition('c.created', $now, '<=')
      ->condition('c.id', $last_id, '<=')->orderBy('c.created')->orderBy('c.id')
      ->execute()->fetchCol();
    if ($mark_seen) {
      // Concurrent older openings never move the position backwards.
      $this->database->merge(self::TABLE)
        ->keys(['uid' => $uid, 'application_id' => $application_id, 'kind' => 'message'])
        ->insertFields([
          'uid' => $uid,
          'application_id' => $application_id,
          'kind' => 'message',
          'seen_at' => $now,
          'last_comment_id' => $last_id,
        ])
        ->expression('seen_at', 'GREATEST(seen_at, :seen_at)', [':seen_at' => $now])
        ->expression('last_comment_id', 'GREATEST(last_comment_id, :last_comment_id)', [':last_comment_id' => $last_id])
        ->execute();
    }
    return [
      'count' => count($message_ids),
      'previousSeenAt' => $previous,
      'seenAt' => $mark_seen ? max($now, $previous ?? 0) : $previous,
      'newMessageIds' => $message_ids,
    ];
  }

  /**
   * Deletes read state with its parent application or staff account.
   */
  public function deleteFor(string $field, int $id): void {
    if (!in_array($field, ['uid', 'application_id'], TRUE)) {
      throw new \InvalidArgumentException('Invalid read-state owner.');
    }
    if ($this->database->schema()->tableExists(self::TABLE)) {
      $this->database->delete(self::TABLE)->condition($field, $id)->execute();
    }
    if ($this->database->schema()->tableExists(ApplicationEventReadState::RECEIPTS_TABLE)) {
      $this->database->delete(ApplicationEventReadState::RECEIPTS_TABLE)->condition($field, $id)->execute();
    }
  }

  /**
   * Preserves the message endpoint's permission and confirmation restrictions.
   */
  public function canReadMessages(ApplicationInterface $application, AccountInterface $account): bool {
    return $this->accountCanReadMessages($account)
      && ($application->isEmailConfirmed() || $account->hasPermission('administer application entities'))
      && $this->staffScope->allows($application, $account);
  }

  /**
   * Checks the message permission before looking up candidate applications.
   */
  public function accountCanReadMessages(AccountInterface $account): bool {
    return $this->moduleHandler->moduleExists('beantragt_comment')
      && $account->isAuthenticated()
      && ($account->hasPermission('view application_comment entities') || $account->hasPermission('administer application_comment entities'));
  }

  /**
   * Builds the unread predicate, excluding staff and internal messages.
   */
  private function unreadQuery(array $ids, AccountInterface $account): SelectInterface {
    $query = $this->database->select('application_comment', 'c');
    $query->leftJoin(self::TABLE, 's', 's.application_id = c.field_application AND s.uid = :reader_uid AND s.kind = :reader_kind', [
      ':reader_uid' => (int) $account->id(),
      ':reader_kind' => 'message',
    ]);
    $query->condition('c.field_application', $ids, 'IN')
      ->condition('c.status', 1)
      ->condition('c.field_author_role', 'citizen')
      ->condition('c.field_visibility', 'public');
    $first_read = $query->andConditionGroup()->isNull('s.seen_at')
      ->condition('c.created', (int) $this->state->get(self::BASELINE_STATE, 0), '>');
    $unread = $query->orConditionGroup()->condition($first_read)
      ->where('c.created > s.seen_at')
      ->where('c.id > s.last_comment_id');
    return $query->condition($unread);
  }

}
