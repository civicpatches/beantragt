<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_group\Service\SharedAccountReadOnlyInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;

/**
 * Access-filtered event snapshots, batched once per kind for a staff page.
 *
 * Only counters, public identifiers and version fingerprints leave the source
 * readers. Content used to decide visibility is never logged or persisted.
 */
class ApplicationEventReadState {

  public const RECEIPTS_TABLE = 'beantragt_application_event_receipt';

  public const KINDS = ['transfer', 'scan', 'release', 'message', 'documents', 'reply', 'assigned'];
  public const SINCE_SEEN = ['message', 'documents', 'reply', 'assigned', 'scan'];

  /**
   * Delivery audience decisions for the active snapshot only.
   *
   * @var array<int, bool>
   */
  private array $pendingReplyAccess = [];

  /**
   * Field access decisions, scoped to one account and snapshot.
   *
   * @var array<int, array<string, bool>>
   */
  private array $scanFieldAccess = [];

  /**
   * Confirmed assignments, loaded once per application in each snapshot.
   */
  private array $scanAssignments = [];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly ApplicationMessageReadState $messages,
    private readonly StateInterface $state,
    private readonly ClassResolverInterface $classes,
    private readonly ProtectedDataGate $protectedGate,
    private readonly ?ScopeAccess $deliveryScope = NULL,
    private readonly ?DocgenScopeAccess $docgenScope = NULL,
    private readonly ?DocumentService $documents = NULL,
    private readonly ?ScanEventIntakeSourceInterface $intakeSource = NULL,
    private readonly ?SharedAccountReadOnlyInterface $sharedAccounts = NULL,
  ) {}

  /**
   * Counts only sources the caller can open, for already-authorized cases.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   The authorized page, never a list of arbitrary IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current staff member.
   * @param string[] $kinds
   *   Event kinds to count.
   *
   * @return array<string, object>
   *   One sparse kind map for each application UUID.
   */
  public function counts(array $applications, AccountInterface $account, array $kinds = self::KINDS): array {
    $snapshot = $this->snapshot($applications, $account, $kinds);
    return array_map(static fn(array $item): object => (object) $item['counts'], $snapshot);
  }

  /**
   * Selects candidates for the unread message filter in a scoped list query.
   *
   * The caller must apply application scope and verify message counts before
   * returning candidates. Receipts, legacy boundaries and per-case access
   * are evaluated by the same snapshot as the list markers.
   */
  public function messageCandidateQuery(AccountInterface $account): ?SelectInterface {
    if (!$this->messages->accountCanReadMessages($account) || BeantragtRoles::isServiceAccount($account)
      || !$this->hasReadTable() || !$this->database->schema()->tableExists('application_comment')) {
      return NULL;
    }
    $query = $this->publicApplicantMessages()
      ->fields('e', ['field_application'])
      ->condition('e.created', $this->time->getCurrentTime(), '<=');
    if ($this->database->databaseType() !== 'mysql') {
      return $query;
    }

    // Compare the same UUID and version hashes as unseen(). On MySQL and
    // MariaDB, discard acknowledged and historical rows before loading cases.
    $uid = (int) $account->id();
    $query->leftJoin(ApplicationMessageReadState::TABLE, 's',
      "s.uid = :message_cursor_uid AND s.application_id = e.field_application AND s.kind = 'message'",
      [':message_cursor_uid' => $uid]);
    $query->leftJoin(self::RECEIPTS_TABLE, 'r',
      "r.uid = :message_receipt_uid AND r.application_id = e.field_application AND r.kind = 'message'
       AND r.event_key = CONVERT(SHA2(e.uuid, 256) USING ascii)",
      [':message_receipt_uid' => $uid]);
    $legacy_cursor = 'COALESCE(s.legacy_seen_at, CASE WHEN s.seen_tokens IS NULL THEN s.seen_at END)';
    $without_receipt = $query->andConditionGroup()->isNull('r.token')->condition($query->orConditionGroup()
      ->where("e.created > COALESCE($legacy_cursor, :message_baseline)",
        [':message_baseline' => (int) $this->state->get(ApplicationMessageReadState::BASELINE_STATE, 0)])
      ->where("$legacy_cursor IS NOT NULL AND e.id > s.last_comment_id"));
    return $query->condition($query->orConditionGroup()->condition($without_receipt)
      ->where("BINARY r.token <> SHA2(CONCAT('message:', e.uuid, ':', e.created), 256)"));
  }

  /**
   * Reads a detail snapshot. Opening a case does not mark any section seen.
   */
  public function detail(ApplicationInterface $application, AccountInterface $account): array {
    $snapshot = $this->snapshot([$application], $account)[$application->uuid()];
    return $this->publicSnapshot($snapshot);
  }

  /**
   * Schema of per-source receipts, one row per current event and staff member.
   */
  public static function receiptSchema(): array {
    return [
      'fields' => [
        'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'kind' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE],
        'event_key' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
        'token' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
      ],
      'primary key' => ['uid', 'application_id', 'kind', 'event_key'],
      'indexes' => ['application' => ['application_id']],
    ];
  }

  /**
   * Marks only rendered versions, never events arriving after the detail read.
   *
   * @return array|null
   *   Pre-mark snapshot, NULL when this section is inaccessible.
   */
  public function markSeen(ApplicationInterface $application, AccountInterface $account, string $kind, array $tokens): ?array {
    if (!in_array($kind, self::SINCE_SEEN, TRUE) || !$this->allowed($kind, $application, $account)) {
      return NULL;
    }
    $snapshot = $this->snapshot([$application], $account)[$application->uuid()];
    // Shared accounts (public demo personas) never persist read receipts:
    // one visitor must not mark events as seen for everybody else.
    if (!$this->hasReadTable() || $this->sharedAccounts?->isReadOnly($account)) {
      return $this->publicSnapshot($snapshot);
    }
    $transaction = $this->database->startTransaction();
    try {
      $keys = ['uid' => (int) $account->id(), 'application_id' => (int) $application->id(), 'kind' => $kind];
      foreach (array_unique($tokens) as $token) {
        if (!isset($snapshot['receiptKeys'][$kind][$token])) {
          continue;
        }
        $this->database->merge(self::RECEIPTS_TABLE)
          ->keys($keys + ['event_key' => $snapshot['receiptKeys'][$kind][$token]])
          ->fields(['token' => $token])->execute();
      }
      $now = $this->time->getCurrentTime();
      // Keep the original legacy cursor unchanged. Exact receipts determine
      // future unread state, while seen_at records section visits only.
      $this->database->merge(ApplicationMessageReadState::TABLE)->keys($keys)
        ->insertFields($keys + ['seen_at' => $now, 'last_comment_id' => 0, 'seen_tokens' => '[]'])
        ->updateFields(['legacy_seen_at' => $snapshot['legacyCutoffs'][$kind] ?? NULL])
        ->expression('seen_at', 'GREATEST(seen_at, :seen)', [':seen' => $now])->execute();
      $snapshot['seenAt'][$kind] = max($now, $snapshot['previousSeenAt'][$kind] ?? 0);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    return $this->publicSnapshot($snapshot);
  }

  /**
   * The response shape shared by detail and the CSRF-protected seen POST.
   */
  private function publicSnapshot(array $snapshot): array {
    return [
      'counts' => (object) $snapshot['counts'],
      'previousSeenAt' => (object) $snapshot['previousSeenAt'],
      'seenAt' => (object) $snapshot['seenAt'],
      'newEventIds' => (object) $snapshot['newEventIds'],
      'newMessageIds' => $snapshot['newEventIds']['message'] ?? [],
      'seenTokens' => (object) $snapshot['tokens'],
    ];
  }

  /**
   * Collects read state once and one source query for each permitted kind.
   */
  private function snapshot(array $applications, AccountInterface $account, array $kinds = self::KINDS): array {
    $this->pendingReplyAccess = [];
    $this->scanFieldAccess = [];
    $this->scanAssignments = [];
    try {
      return $this->messages->withAccessBatch($applications, $account, fn(): array => $this->buildSnapshot($applications, $account, $kinds));
    }
    finally {
      $this->pendingReplyAccess = [];
      $this->scanFieldAccess = [];
      $this->scanAssignments = [];
    }
  }

  /**
   * Evaluates the source page inside the owning access predicates' batch.
   */
  private function buildSnapshot(array $applications, AccountInterface $account, array $kinds): array {
    /** @var array<string, array<string, mixed>> $result */
    $result = [];
    $by_id = [];
    foreach ($applications as $application) {
      $by_id[(int) $application->id()] = $application;
      $result[$application->uuid()] = [
        'counts' => [],
        'previousSeenAt' => [],
        'seenAt' => [],
        'newEventIds' => [],
        'tokens' => [],
        'receiptKeys' => [],
        'legacyCutoffs' => [],
      ];
    }
    if ($by_id === [] || !$account->isAuthenticated() || BeantragtRoles::isServiceAccount($account)) {
      return $result;
    }
    $read_state = [];
    $receipts = [];
    $has_table = $this->hasReadTable();
    if ($has_table) {
      foreach ($this->database->select(ApplicationMessageReadState::TABLE, 's')->fields('s')
        ->condition('uid', (int) $account->id())->condition('application_id', array_keys($by_id), 'IN')
        ->condition('kind', $kinds, 'IN')->execute() as $row) {
        $read_state[(int) $row->application_id][$row->kind] = (array) $row;
      }
    }
    if ($has_table) {
      foreach ($this->database->select(self::RECEIPTS_TABLE, 'r')->fields('r')
        ->condition('uid', (int) $account->id())->condition('application_id', array_keys($by_id), 'IN')
        ->condition('kind', $kinds, 'IN')->execute() as $receipt) {
        $receipts[(int) $receipt->application_id][$receipt->kind][$receipt->event_key] = $receipt->token;
      }
    }
    foreach ($kinds as $kind) {
      $since_seen = in_array($kind, self::SINCE_SEEN, TRUE);
      if ($since_seen && !$has_table) {
        continue;
      }
      $permitted = array_filter($by_id, fn(ApplicationInterface $application): bool => $this->allowed($kind, $application, $account));
      if ($permitted === []) {
        continue;
      }
      foreach ($permitted as $id => $application) {
        if ($since_seen) {
          $previous = isset($read_state[$id][$kind]) ? (int) $read_state[$id][$kind]['seen_at'] : NULL;
          $result[$application->uuid()]['legacyCutoffs'][$kind] = $read_state[$id][$kind]['legacy_seen_at'] ?? (isset($read_state[$id][$kind]) && $read_state[$id][$kind]['seen_tokens'] === NULL ? $previous : NULL);
          $result[$application->uuid()]['previousSeenAt'][$kind] = $previous;
          $result[$application->uuid()]['seenAt'][$kind] = $previous;
        }
      }
      $cutoff = (int) $this->state->get(ApplicationMessageReadState::BASELINE_STATE, 0);
      foreach ($permitted as $id => $application) {
        $legacy = $result[$application->uuid()]['legacyCutoffs'][$kind] ?? NULL;
        if ($legacy !== NULL) {
          // Legacy messages also compare IDs to preserve arrivals in the
          // cursor's second. Include that second, then apply unseen() exactly.
          $cutoff = min($cutoff, (int) $legacy - ($kind === 'message' ? 1 : 0));
        }
      }
      $rows = $this->rows($kind, $permitted, $account, $cutoff);
      $release_blockers = $kind === 'release' && $this->documents !== NULL
        ? $this->documents->releaseBlockersForEvents($permitted, $rows, $account) : [];
      foreach ($rows as $row) {
        if ($kind === 'release') {
          $row['release_blockers'] = $release_blockers[$row['uuid']] ?? ['docgen.unavailable'];
        }
        $id = (int) $row['application_id'];
        if (!isset($permitted[$id])) {
          continue;
        }
        $application = $permitted[$id];
        if (!$this->rowVisible($kind, $row, $application, $account)) {
          continue;
        }
        $item =& $result[$application->uuid()];
        $at = (int) ($row['at'] ?? 0);
        $numeric_id = (int) ($row['numeric_id'] ?? 0);
        $token = hash('sha256', $kind . ':' . (string) $row['event_id'] . ':' . (string) ($row['version'] ?? $at));
        if ($since_seen) {
          if ($at > $this->time->getCurrentTime()) {
            continue;
          }
          $event_key = hash('sha256', (string) $row['event_id']);
          if (!$this->unseen($read_state[$id][$kind] ?? NULL, $at, $numeric_id, $token,
            $receipts[$id][$kind][$event_key] ?? NULL)) {
            continue;
          }
          $item['tokens'][$kind][] = $token;
          $item['receiptKeys'][$kind][$token] = $event_key;
        }
        $item['counts'][$kind] = ($item['counts'][$kind] ?? 0) + 1;
        // State events also expose their allowed target IDs.
        $item['newEventIds'][$kind][] = (string) $row['event_id'];
        unset($item);
      }
    }
    return $result;
  }

  /**
   * First-read baseline applies to every since-seen kind, strictly after it.
   */
  private function unseen(?array $seen, int $at, int $numeric_id, string $token, ?string $receipt): bool {
    if ($receipt !== NULL) {
      return !hash_equals($receipt, $token);
    }
    if ($seen !== NULL && ($seen['legacy_seen_at'] !== NULL || $seen['seen_tokens'] === NULL)) {
      // Stored message read positions are a frozen legacy cutoff.
      return $at > (int) ($seen['legacy_seen_at'] ?? $seen['seen_at']) || $numeric_id > (int) $seen['last_comment_id'];
    }
    return $at > (int) $this->state->get(ApplicationMessageReadState::BASELINE_STATE, 0);
  }

  /**
   * Reuses the owning surface's access rule before querying any source.
   */
  protected function allowed(string $kind, ApplicationInterface $application, AccountInterface $account): bool {
    if (!$account->isAuthenticated() || !$application->access('view', $account) || BeantragtRoles::isServiceAccount($account)) {
      return FALSE;
    }
    return match ($kind) {
      'message' => $this->messages->canReadMessages($application, $account),
      'documents' => $account->hasPermission('view protected application data') && $this->protectedGate->allows($application, $account),
      'reply' => $this->deliveryScope !== NULL,
      'transfer' => $this->deliveryScope?->transferAccess($application, $account)->isAllowed() === TRUE,
      'release' => $this->docgenScope?->canWorkOnCase($application, $account, DocgenScopeAccess::PERMISSION_RELEASE, TRUE) === TRUE,
      'assigned' => $account->hasPermission('view workflow assignments')
        && $this->classes->getInstanceFromDefinition(ApplicationAssignmentController::class)->viewAccess($application, $account)->isAllowed(),
      'scan' => $this->protectedGate->allows($application, $account),
      default => FALSE,
    };
  }

  /**
   * Builds each source read once, independent of the number of applications.
   *
   * @return array<int, array<string, mixed>>
   *   Internal metadata rows; never returned directly.
   */
  private function rows(string $kind, array $applications, AccountInterface $account, int $cutoff): array {
    $tables = [
      'message' => 'application_comment',
      'documents' => 'beantragt_nachreichung_eingang',
      'reply' => 'beantragt_external_response',
      'transfer' => 'beantragt_delivery_log',
      'release' => 'beantragt_docgen_document',
      'assigned' => 'application_revision',
      'scan' => 'application_attachment',
    ];
    if (!$this->database->schema()->tableExists($tables[$kind])) {
      return [];
    }
    $query = $kind === 'message' ? $this->publicApplicantMessages() : $this->database->select($tables[$kind], 'e');
    $application_field = in_array($kind, ['message', 'scan'], TRUE) ? 'field_application' : ($kind === 'assigned' ? 'id' : 'application_id');
    $query->condition('e.' . $application_field, array_keys($applications), 'IN');
    $query->addField('e', $application_field, 'application_id');
    switch ($kind) {
      case 'message':
        $query->fields('e', ['uuid', 'created', 'id']);
        $query->condition('e.created', $cutoff, '>');
        break;

      case 'documents':
        $query->fields('e', ['uuid', 'id', 'decided_at'])->isNull('reverted_at')->isNotNull('nachforderung_id');
        $query->condition('e.decided_at', $cutoff, '>')->condition('e.decided_by', (int) $account->id(), '<>');
        break;

      case 'reply':
        $query->fields('e', ['uuid', 'received', 'contains_protected', 'released', 'kind'])->condition('source', 'docgen', '<>');
        $query->condition('e.received', $cutoff, '>');
        break;

      case 'transfer':
        $query->fields('e', ['uuid', 'status'])->condition('e.status', ['failed', 'blocked', 'awaiting_approval'], 'IN');
        $replacement = $this->database->select('beantragt_delivery_log', 'replacement');
        $replacement->addExpression('1');
        $replacement->where('replacement.replaces_uuid = e.uuid');
        $query->notExists($replacement);
        break;

      case 'release':
        $query->fields('e', [
          'uuid', 'state', 'purpose', 'created', 'created_by', 'rendered_at', 'missing',
          'pdf_file_id', 'pdf_sha256', 'docx_sha256', 'pdf_docx_sha256', 'validation',
          'cover_pdf_file_id', 'cover_pdf_sha256', 'cover_validation',
        ])->condition('e.state', 'draft')->condition('e.created_by', (int) $account->id(), '<>');
        break;

      case 'assigned':
        $query->fields('e', ['revision_id', 'revision_created', 'revision_log_message', 'revision_user']);
        $query->condition('revision_user', (int) $account->id(), '<>');
        $query->condition('e.revision_created', $cutoff, '>');
        // Logs are JSON envelopes; only revisions with a person change can
        // contribute. Keep the exact recipient check in rowVisible().
        if ($this->database->databaseType() === 'mysql') {
          // JSON paths and fallback data must be parameters: Drupal treats
          // brackets and braces in SQL as identifier/table placeholders.
          $query->where('JSON_CONTAINS(JSON_EXTRACT(CASE WHEN JSON_VALID(e.revision_log_message) THEN e.revision_log_message ELSE :empty_log END, :changes_path), :event_person) = 1', [
            ':empty_log' => '{}',
            ':changes_path' => '$.changes[*].field',
            ':event_person' => '"person"',
          ]);
        }
        elseif ($this->database->databaseType() === 'sqlite') {
          $query->where('EXISTS (SELECT 1 FROM json_each(CASE WHEN json_valid(e.revision_log_message) THEN e.revision_log_message ELSE :empty_log END, :changes_path) change WHERE json_extract(change.value, :field_path) = :event_person)', [
            ':empty_log' => '{}',
            ':changes_path' => '$.changes',
            ':field_path' => '$.field',
            ':event_person' => 'person',
          ]);
        }
        else {
          $query->condition('e.revision_log_message', '%"field"%:%"person"%', 'LIKE');
        }
        break;

      case 'scan':
        $query->fields('e', [
          'uuid', 'id', 'changed', 'field_field_id', 'field_scan_status',
          'field_guard_status', 'field_analysis_metadata', 'field_provenance',
        ]);
        if ($this->intakeSource !== NULL) {
          $this->intakeSource->extendScanQuery($query, $cutoff);
        }
        else {
          $query->condition('e.changed', $cutoff, '>');
        }
        break;
    }
    $rows = [];
    $bulk_items = [];
    $can_read_staff_analysis = $kind === 'scan' && ProtectedDataGate::canReadProtectedAttachmentData($account);
    foreach ($query->execute() as $record) {
      $row = (array) $record;
      $row['event_id'] = $kind === 'assigned'
        ? 'assignment:' . hash('sha256', $applications[(int) $row['application_id']]->uuid() . ':' . $row['revision_id'])
        : (string) $row['uuid'];
      $row['at'] = (int) ($row['created'] ?? $row['decided_at'] ?? $row['received'] ?? $row['revision_created'] ?? $row['changed'] ?? 0);
      $row['numeric_id'] = (int) ($kind === 'assigned' ? ($row['revision_id'] ?? 0) : ($row['id'] ?? 0));
      if ($kind === 'scan') {
        $metadata = AttachmentAnalysisMetadataWriter::decode((string) $row['field_analysis_metadata']);
        $has_stories = is_array($metadata['stories'] ?? NULL);
        $stories = $has_stories ? $metadata['stories'] : [$metadata];
        $scan_status = $row['field_scan_status'] ?: AttachmentScanResult::STATUS_CLEAN;
        if ($has_stories
          && (!$can_read_staff_analysis || $scan_status !== AttachmentScanResult::STATUS_CLEAN)) {
          unset($stories[AttachmentAnalysisMetadataWriter::STORY_STAFF]);
          // A hidden staff update must not reopen an acknowledged scan event.
          $row['field_analysis_metadata'] = json_encode($stories, JSON_THROW_ON_ERROR);
          $row['at'] = AttachmentAnalysisMetadataWriter::scanChangedWithoutStaff(
            $metadata, $row['at'], (string) $row['field_scan_status'], (string) $row['field_guard_status'],
          );
        }
        $row['at'] = max($row['at'], (int) ($row['bulk_changed'] ?? 0));
        $row['visible_analysis_stories'] = $stories;
        $row['version'] = hash('sha256', json_encode(array_intersect_key($row, array_flip([
          'field_scan_status', 'field_guard_status', 'field_analysis_metadata', 'classification', 'bulk_result',
        ])), JSON_THROW_ON_ERROR));
      }
      if ($kind === 'scan' && !empty($row['bound_application'])) {
        $bulk_items[$row['event_id']][] = (string) $row['item_uuid'];
      }
      $rows[$row['event_id']] = $row;
    }
    foreach ($bulk_items as $event_id => $items) {
      if (count($items) !== 1) {
        $rows[$event_id]['bound_application'] = NULL;
      }
    }
    return array_values($rows);
  }

  /**
   * Builds the message source shared by the marker and filter.
   */
  private function publicApplicantMessages(): SelectInterface {
    return $this->database->select('application_comment', 'e')
      ->condition('e.status', 1)
      ->condition('e.field_author_role', 'citizen')
      ->condition('e.field_visibility', 'public');
  }

  /**
   * Source-specific visibility uses the same owner decisions as its section.
   */
  private function rowVisible(string $kind, array $row, ApplicationInterface $application, AccountInterface $account): bool {
    if ($kind === 'reply') {
      $staff = $this->canReadPendingReply($application, $account);
      return ($staff || ($row['released'] !== NULL && $row['kind'] === 'decision'))
        && (empty($row['contains_protected']) || $account->hasPermission(ExternalResponseService::PROTECTED_PERMISSION));
    }
    if ($kind === 'release') {
      return $this->documents !== NULL
        && (!DocumentService::flags($row)['protectedValues'] || $account->hasPermission('view protected application data'))
        && array_diff($row['release_blockers'], ['docgen.preview_not_viewed']) === [];
    }
    if ($kind === 'assigned') {
      $log = RevisionLog::decode((string) $row['revision_log_message']);
      foreach ($log['changes'] ?? [] as $change) {
        if ($change['field'] === 'person' && in_array((string) $account->id(), array_column($change['after'], 'id'), TRUE)) {
          return TRUE;
        }
      }
      return FALSE;
    }
    if ($kind !== 'scan') {
      return TRUE;
    }
    $field_id = (string) $row['field_field_id'];
    $application_id = (int) $application->id();
    $assignments = $this->scanAssignments[$application_id] ??= ApplicationProofAssignments::read($application);
    $target = ApplicationProofAssignments::target($assignments, (string) $row['uuid']) ?? $field_id;
    foreach ([$field_id, $target] as $field) {
      if (!($this->scanFieldAccess[$application_id][$field] ??= $this->scanFieldVisible($application, $field, $account))) {
        return FALSE;
      }
    }
    if (in_array($row['field_scan_status'], ['quarantined', 'failed'], TRUE)
      || in_array($row['field_guard_status'], ['needs_review', 'rejected', 'failed'], TRUE)) {
      return TRUE;
    }
    foreach ($row['visible_analysis_stories'] as $story) {
      if (is_array($story) && (($story['status'] ?? '') === 'failed'
        || in_array($story['result']['outcome'] ?? '', ['needs_review', 'fail', 'unknown'], TRUE))) {
        return TRUE;
      }
    }
    if (empty($row['bound_application']) || ($row['field_provenance'] ?? '') === 'nachreichung' || $this->intakeSource === NULL) {
      return FALSE;
    }
    return $this->intakeSource->hasFinding($row, $target);
  }

  /**
   * Mirrors the external response controller's staff audience switch.
   */
  protected function canReadPendingReply(ApplicationInterface $application, AccountInterface $account): bool {
    return $this->pendingReplyAccess[(int) $application->id()] ??= $this->deliveryScope?->access($application, $account)->isAllowed() === TRUE;
  }

  /**
   * Attachment field visibility is identical to the owning staff list.
   */
  protected function scanFieldVisible(ApplicationInterface $application, string $field_id, AccountInterface $account): bool {
    return $this->classes->getInstanceFromDefinition(ApplicationAttachmentApiController::class)->canAccessAttachmentField($application, $field_id, $account);
  }

  /**
   * Safe mixed-code/schema deployment: state events remain readable.
   */
  private function hasReadTable(): bool {
    return $this->database->schema()->tableExists(ApplicationMessageReadState::TABLE)
      && $this->database->schema()->fieldExists(ApplicationMessageReadState::TABLE, 'kind')
      && $this->database->schema()->tableExists(self::RECEIPTS_TABLE);
  }

}
