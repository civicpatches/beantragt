<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Exception\AttachmentAnalysisMetadataLockedException;
use Drupal\beantragt_application\Exception\AttachmentAnalysisRerunRefusedException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * The one writer of per-story attachment analysis metadata (v2).
 *
 * A story profile (citizenPrompt or staffPrompt set) runs two checks on the
 * same file: story A ('citizen', the upload check) and story B ('staff', the
 * purpose-bound reading after the claim). Both are written into one record,
 * from different processes (upload proxy, consent route, queue dispatcher,
 * Nuxt callback). Every story write therefore goes through mergeStory():
 * under the attachment claim lock it reloads the entity, replaces only
 * stories.{story}, recomputes the overall status and saves. One story can
 * never delete the other.
 *
 * Shape (version 2):
 * @code
 * {"version": 2, "status": "pending", "language": "de", "profile": {...},
 *  "stories": {"citizen": {...}, "staff": {...}}}
 * @endcode
 * Each story carries its own status, code, analysisRunId, analysisStartedAt,
 * worker, promptVersion, provider, source and result. Legacy profiles keep
 * the version 1 record and are not written here.
 */
final class AttachmentAnalysisMetadataWriter {

  public const METADATA_VERSION = 2;
  public const STORY_CITIZEN = 'citizen';
  public const STORY_STAFF = 'staff';
  public const STORY_LEGACY = 'legacy';
  public const STORIES = [self::STORY_CITIZEN, self::STORY_STAFF];

  /**
   * Model classes a policy may dispatch private bytes to.
   *
   * Story B (staff) only ever runs under sovereign_only.
   */
  public const DISPATCHABLE_MODEL_CLASSES_BY_EGRESS_POLICY = [
    'external_redacted' => ['claude_sonnet', 'ollama', 'openai', 'openai_compatible'],
    'external_allowed' => ['claude_sonnet', 'ollama', 'openai', 'openai_compatible'],
    'sovereign_only' => ['ollama', 'openai_compatible'],
  ];

  private const CLAIM_LOCK_TIMEOUT_SECONDS = 30.0;
  private const CLAIM_LOCK_WAIT_SECONDS = 5;

  /**
   * Wait rounds of a terminal write: 7 x 5 s outlasts the 30 s lock TTL.
   *
   * Every holder of the claim lock takes it with a 30 s TTL, so a holder that
   * crashed inside the lock frees it within that time. A terminal write
   * (failed after a minted run) waits that long instead of giving up, because
   * its run already holds a lease and a retry of the queue item would be
   * consumed by that lease.
   */
  public const TERMINAL_WRITE_WAIT_ROUNDS = 7;
  private const MAX_METADATA_BYTES = 65536;
  private const MAX_STAFF_FACTS = 20;
  private const STATUSES = ['not_requested', 'pending', 'complete', 'failed'];

  /**
   * Keys of a version 1 run record that never survive the lift to v2.
   *
   * Everything else at the top level (language, comment context) is kept.
   */
  private const V1_RUN_KEYS = [
    'version',
    'status',
    'code',
    'requiresHumanReview',
    'analysisRunId',
    'analysisStartedAt',
    'promptVersion',
    'profile',
    'source',
    'worker',
    'provider',
    'result',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?TimeInterface $time = NULL,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    private readonly ?Connection $database = NULL,
  ) {}

  /**
   * Returns the lock shared by public claims and attachment analysis writes.
   */
  public static function claimLockName(ApplicationAttachmentInterface $attachment): string {
    return 'beantragt_application.attachment_claim.' . (int) $attachment->id();
  }

  /**
   * Merges one story under the claim lock and saves the reloaded entity.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment to write; it is reloaded under the lock.
   * @param string $story
   *   Story name, citizen or staff.
   * @param array<string, mixed> $patch
   *   Trusted story record (status, code, worker, result, ...). 'profile'
   *   moves to the top level, 'language' of story A updates the top level.
   * @param bool $start_run
   *   TRUE marks the story pending with a new run id and lease.
   * @param array<string, mixed> $extra_fields
   *   Further entity fields to set on the reloaded entity before the save.
   * @param int $wait_rounds
   *   How often to wait CLAIM_LOCK_WAIT_SECONDS for a held lock; a start
   *   write waits once, a terminal write TERMINAL_WRITE_WAIT_ROUNDS times.
   *
   * @return array{attachment: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface, story: array<string, mixed>, metadata: array<string, mixed>}
   *   The saved entity, the written story record and the full record.
   *
   * @throws \Drupal\beantragt_application\Exception\AttachmentAnalysisMetadataLockedException
   *   When another writer holds the claim lock.
   */
  public function mergeStory(ApplicationAttachmentInterface $attachment, string $story, array $patch, bool $start_run = FALSE, array $extra_fields = [], int $wait_rounds = 1): array {
    $lock_name = self::claimLockName($attachment);
    if ($this->lock instanceof LockBackendInterface
      && !$this->lock->acquire($lock_name, self::CLAIM_LOCK_TIMEOUT_SECONDS)) {
      // Claim and callback writes hold this lock only briefly. wait()
      // returns as soon as the lock is free, so a round costs at most 5 s.
      $acquired = FALSE;
      for ($round = 0; $round < max(1, $wait_rounds) && !$acquired; $round++) {
        $this->lock->wait($lock_name, self::CLAIM_LOCK_WAIT_SECONDS);
        $acquired = $this->lock->acquire($lock_name, self::CLAIM_LOCK_TIMEOUT_SECONDS);
      }
      if (!$acquired) {
        throw new AttachmentAnalysisMetadataLockedException('Attachment analysis metadata is locked.');
      }
    }
    try {
      $fresh = $this->reload($attachment);
      foreach ($extra_fields as $field_name => $value) {
        $fresh->set($field_name, $value);
      }
      $written = $this->mergeStoryLocked($fresh, $story, $patch, $start_run);
      return ['attachment' => $fresh] + $written;
    }
    finally {
      if ($this->lock instanceof LockBackendInterface) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Merges one story for a caller that already holds the claim lock.
   *
   * The caller must have loaded the entity after acquiring the lock.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment loaded under the claim lock.
   * @param string $story
   *   Story name, citizen or staff.
   * @param array<string, mixed> $patch
   *   Trusted story record.
   * @param bool $start_run
   *   TRUE marks the story pending with a new run id and lease.
   *
   * @return array{story: array<string, mixed>, metadata: array<string, mixed>}
   *   The written story record and the full record.
   */
  public function mergeStoryLocked(ApplicationAttachmentInterface $attachment, string $story, array $patch, bool $start_run = FALSE): array {
    if (!in_array($story, self::STORIES, TRUE)) {
      throw new \InvalidArgumentException('Unknown attachment analysis story.');
    }
    $stored = self::decode((string) ($attachment->get('field_analysis_metadata')->value ?? ''));
    if (self::holdsLegacyRun($stored)) {
      // Callers skip such attachments; never wipe a legacy verdict.
      throw new \LogicException('Attachment analysis metadata holds a legacy run.');
    }
    $metadata = self::liftToV2($stored);
    $current = self::storyRecord($metadata, $story);

    $record = $patch;
    unset($record['version'], $record['profile'], $record['stories'], $record['analysisRunId'], $record['analysisStartedAt']);
    if (!in_array($record['status'] ?? NULL, self::STATUSES, TRUE)) {
      $record['status'] = $start_run ? 'pending' : 'failed';
    }
    if ($start_run) {
      $record['analysisRunId'] = bin2hex(random_bytes(16));
      $record['analysisStartedAt'] = $this->requestTime();
    }
    else {
      foreach (['analysisRunId', 'analysisStartedAt', 'worker'] as $key) {
        if (!array_key_exists($key, $record) && array_key_exists($key, $current)) {
          $record[$key] = $current[$key];
        }
      }
    }

    if (isset($patch['profile']) && is_array($patch['profile']) && $patch['profile'] !== []) {
      $metadata['profile'] = $patch['profile'];
    }
    if ($story === self::STORY_CITIZEN && in_array($patch['language'] ?? NULL, ['de', 'en'], TRUE)) {
      $metadata['language'] = $patch['language'];
    }

    $stories = is_array($metadata['stories'] ?? NULL) ? $metadata['stories'] : [];
    $stories[$story] = $record;
    $ordered = [];
    foreach (self::STORIES as $name) {
      if (isset($stories[$name]) && is_array($stories[$name])) {
        $ordered[$name] = $this->protectRecord($stories[$name]);
      }
    }
    if ($story === self::STORY_STAFF) {
      $scan_status = $attachment->hasField('field_scan_status') ? (string) $attachment->get('field_scan_status')->value : '';
      $guard_status = $attachment->hasField('field_guard_status') ? (string) $attachment->get('field_guard_status')->value : '';
      $changed = $attachment->getChangedTime() ?? $attachment->getCreatedTime();
      $metadata['staffScanChange'] = [
        'at' => $this->requestTime(),
        'previousVisibleAt' => self::scanChangedWithoutStaff($metadata, $changed, $scan_status, $guard_status),
        'scanStatus' => $scan_status,
        'guardStatus' => $guard_status,
      ];
    }
    else {
      unset($metadata['staffScanChange']);
    }
    $metadata['stories'] = $ordered;
    $metadata['status'] = self::overallStatus($ordered);
    $metadata = $this->protectRecord($metadata);

    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($encoded) > self::MAX_METADATA_BYTES) {
      throw new \RuntimeException('Attachment analysis metadata is too large.');
    }
    $attachment->set('field_analysis_status', $metadata['status']);
    $attachment->set('field_analysis_metadata', $encoded);
    $attachment->save();

    return ['story' => $ordered[$story], 'metadata' => $metadata];
  }

  /**
   * Gets the last scan-event change time independent of staff-only writes.
   *
   * A later edit or an independent scan or guard transition in the same second
   * can still make its own finding visible.
   */
  public static function scanChangedWithoutStaff(array $metadata, int $changed, string $scan_status, string $guard_status): int {
    $change = $metadata['staffScanChange'] ?? [];
    return ($change['at'] ?? NULL) === $changed
      && ($change['scanStatus'] ?? NULL) === $scan_status
      && ($change['guardStatus'] ?? NULL) === $guard_status
        ? (int) ($change['previousVisibleAt'] ?? $changed)
        : $changed;
  }

  /**
   * Starts a staff-requested rerun of one story under the claim lock.
   *
   * Only that story is set to pending, with a new run id and a new lease;
   * the other story stays as it is. A complete story may rerun, a failed one
   * too; a guard block stays final and a run that still holds its lease is
   * not started twice. A legacy rerun writes a version 1 run record with its
   * own run id, which the legacy callback path checks. A story rerun on a
   * field that still holds a legacy run replaces that legacy record: the
   * case worker asked for the story explicitly.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment; it is reloaded under the lock.
   * @param string $story
   *   Story name: legacy, citizen or staff.
   * @param bool $guard_derivative_required
   *   TRUE when this run reads the persisted guard derivative, so a guard
   *   result of needs_review or rejected blocks it.
   * @param int $lease_ttl
   *   Lease of a pending run in seconds.
   * @param callable|null $enqueue
   *   Queues the run while its reservation is still locked.
   *
   * @return array{attachment: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface, runId: string}
   *   The saved entity and the new run id.
   *
   * @throws \Drupal\beantragt_application\Exception\AttachmentAnalysisRerunRefusedException
   *   With attachment.guard_blocked, attachment.analysis_running or
   *   attachment.analysis_story_required.
   * @throws \Drupal\beantragt_application\Exception\AttachmentAnalysisMetadataLockedException
   *   When another writer holds the claim lock.
   */
  public function startRerun(ApplicationAttachmentInterface $attachment, string $story, bool $guard_derivative_required, int $lease_ttl, ?callable $enqueue = NULL): array {
    if ($story !== self::STORY_LEGACY && !in_array($story, self::STORIES, TRUE)) {
      throw new \InvalidArgumentException('Unknown attachment analysis story.');
    }
    $lock_name = self::claimLockName($attachment);
    if ($this->lock instanceof LockBackendInterface
      && !$this->lock->acquire($lock_name, self::CLAIM_LOCK_TIMEOUT_SECONDS)) {
      $this->lock->wait($lock_name, self::CLAIM_LOCK_WAIT_SECONDS);
      if (!$this->lock->acquire($lock_name, self::CLAIM_LOCK_TIMEOUT_SECONDS)) {
        throw new AttachmentAnalysisMetadataLockedException('Attachment analysis metadata is locked.');
      }
    }
    $transaction = NULL;
    try {
      $fresh = $this->reload($attachment);
      if ($guard_derivative_required
        && $fresh->hasField('field_guard_status')
        && in_array((string) ($fresh->get('field_guard_status')->value ?? ''), ['needs_review', 'rejected'], TRUE)) {
        throw new AttachmentAnalysisRerunRefusedException('attachment.guard_blocked');
      }
      $stored = self::decode((string) ($fresh->get('field_analysis_metadata')->value ?? ''));
      $now = $this->requestTime();

      // Publish the reservation and database queue item together.
      $transaction = $enqueue !== NULL ? $this->database?->startTransaction() : NULL;
      if ($story === self::STORY_LEGACY) {
        if (self::isV2($stored)) {
          throw new AttachmentAnalysisRerunRefusedException('attachment.analysis_story_required');
        }
        $this->refuseRunningOrBlocked($stored, $fresh, $now, $lease_ttl);
        $run_id = bin2hex(random_bytes(16));
        $metadata = [];
        foreach ($stored as $key => $value) {
          if (!in_array($key, self::V1_RUN_KEYS, TRUE)) {
            $metadata[$key] = $value;
          }
        }
        $metadata = [
          'version' => 1,
          'status' => 'pending',
          'code' => 'analysis_queued',
          'requiresHumanReview' => TRUE,
          'language' => in_array($stored['language'] ?? NULL, ['de', 'en'], TRUE) ? $stored['language'] : 'de',
          'analysisRunId' => $run_id,
          'analysisStartedAt' => $now,
        ] + $metadata;
        $metadata = $this->protectRecord($metadata);
        $fresh->set('field_analysis_status', 'pending');
        $fresh->set('field_analysis_metadata', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $fresh->save();
        if ($enqueue !== NULL) {
          $enqueue($fresh, $run_id);
        }
        unset($transaction);
        return ['attachment' => $fresh, 'runId' => $run_id];
      }

      if (self::holdsLegacyRun($stored)) {
        $this->refuseRunningOrBlocked($stored, $fresh, $now, $lease_ttl);
        // Explicit rerun of a story: the legacy record gives way to version 2.
        $fresh->set('field_analysis_metadata', json_encode(self::liftToV2($stored), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
      }
      else {
        $record = self::storyRecord($stored, $story);
        if (self::hasActiveLease($record, $now, $lease_ttl)) {
          throw new AttachmentAnalysisRerunRefusedException('attachment.analysis_running');
        }
        if (($record['code'] ?? NULL) === 'guard_blocked') {
          throw new AttachmentAnalysisRerunRefusedException('attachment.guard_blocked');
        }
      }
      // No worker key: the attempt count of the rerun starts at 1.
      $written = $this->mergeStoryLocked($fresh, $story, [
        'status' => 'pending',
        'code' => 'analysis_queued',
        'requiresHumanReview' => TRUE,
      ], TRUE);
      $run_id = (string) $written['story']['analysisRunId'];
      if ($enqueue !== NULL) {
        $enqueue($fresh, $run_id);
      }
      unset($transaction);
      return ['attachment' => $fresh, 'runId' => $run_id];
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
        $this->entityTypeManager->getStorage('application_attachment')->resetCache([$attachment->id()]);
      }
      throw $exception;
    }
    finally {
      if ($this->lock instanceof LockBackendInterface) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Protects values in both the replaced and the retained story before saving.
   *
   * @param array<string, mixed> $record
   *   One story or legacy record.
   *
   * @return array<string, mixed>
   *   The protected record.
   */
  private function protectRecord(array $record): array {
    if (!empty($record['result']['extracted'])) {
      $protection = $this->dataProtection ?? throw new \RuntimeException('Analysis data protection is unavailable.');
      $record['result'] = $protection->protectAnalysisResult($record['result']);
    }
    return $record;
  }

  /**
   * Refuses a version 1 run that is still running or guard blocked.
   *
   * @param array<string, mixed> $stored
   *   Decoded version 1 metadata.
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment loaded under the claim lock.
   * @param int $now
   *   Current request time.
   * @param int $lease_ttl
   *   Lease of a pending run in seconds.
   */
  private function refuseRunningOrBlocked(array $stored, ApplicationAttachmentInterface $attachment, int $now, int $lease_ttl): void {
    if (($stored['code'] ?? NULL) === 'guard_blocked') {
      throw new AttachmentAnalysisRerunRefusedException('attachment.guard_blocked');
    }
    $pending = (string) ($attachment->get('field_analysis_status')->value ?? '') === 'pending'
      || ($stored['status'] ?? NULL) === 'pending';
    if ($pending
      && (self::hasActiveLease($stored, $now, $lease_ttl)
        || (int) $attachment->getChangedTime() >= $now - $lease_ttl)) {
      throw new AttachmentAnalysisRerunRefusedException('attachment.analysis_running');
    }
  }

  /**
   * Returns TRUE when a case worker may rerun this story on the profile.
   *
   * Legacy profiles rerun their single check ('legacy'). Story profiles run
   * story A under the policies that have it, story B only under
   * sovereign_only. local_only and no_ai have no runtime and never rerun.
   *
   * @param array<string, mixed>|null $profile
   *   Field analysis profile.
   * @param string $story
   *   Requested story.
   */
  public static function rerunStoryAllowed(?array $profile, string $story): bool {
    if ($profile === NULL
      || ($profile['enabled'] ?? FALSE) !== TRUE
      || !self::modelClassAllowed($profile)) {
      return FALSE;
    }
    $egress_policy = $profile['egressPolicy'] ?? NULL;
    if (!self::isStoryProfile($profile)) {
      return $story === self::STORY_LEGACY;
    }
    return match ($story) {
      self::STORY_CITIZEN => self::profileHasCitizenStory($profile)
        && in_array($egress_policy, ['external_redacted', 'external_allowed', 'sovereign_only'], TRUE),
      self::STORY_STAFF => $egress_policy === 'sovereign_only' && self::profileHasStaffStory($profile),
      default => FALSE,
    };
  }

  /**
   * Builds the v2 record for a new upload entering citizen analysis.
   *
   * @return array{metadata: array<string, mixed>, runId: string}
   *   The record and the minted run id.
   */
  public function startRecordWithCitizenRun(string $language): array {
    $run_id = bin2hex(random_bytes(16));
    return [
      'runId' => $run_id,
      'metadata' => [
        'version' => self::METADATA_VERSION,
        'status' => 'pending',
        'language' => $language,
        'stories' => [
          self::STORY_CITIZEN => [
            'status' => 'pending',
            'language' => $language,
            'analysisRunId' => $run_id,
            'analysisStartedAt' => $this->requestTime(),
          ],
        ],
      ],
    ];
  }

  /**
   * Decodes stored metadata, returning an empty array for anything invalid.
   *
   * @return array<string, mixed>
   *   Decoded metadata object.
   */
  public static function decode(string $raw): array {
    if (trim($raw) === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : [];
  }

  /**
   * Returns TRUE for a version 2 (per-story) record.
   *
   * @param array<string, mixed> $metadata
   *   Decoded metadata.
   */
  public static function isV2(array $metadata): bool {
    return ($metadata['version'] ?? NULL) === self::METADATA_VERSION
      && is_array($metadata['stories'] ?? NULL);
  }

  /**
   * Returns TRUE for a version 1 record that holds a legacy run.
   *
   * A legacy run (pending, complete or failed) was started under a profile
   * without citizenPrompt and staffPrompt. When an admin switches the field to
   * a story profile afterwards, that record is kept as it is: the queue and
   * the consent route skip the attachment and a late legacy callback still
   * lands on the legacy path. Only the version 1 start record
   * {version: 1, status: 'not_requested', language} counts as no run.
   *
   * @param array<string, mixed> $metadata
   *   Decoded metadata.
   */
  public static function holdsLegacyRun(array $metadata): bool {
    return $metadata !== []
      && !self::isV2($metadata)
      && in_array($metadata['status'] ?? NULL, ['pending', 'complete', 'failed'], TRUE);
  }

  /**
   * Lifts a version 1 start record to version 2, keeping language.
   *
   * A record that holds a legacy run is never lifted (holdsLegacyRun()).
   *
   * @param array<string, mixed> $metadata
   *   Decoded metadata.
   *
   * @return array<string, mixed>
   *   Version 2 record.
   */
  public static function liftToV2(array $metadata): array {
    if (self::isV2($metadata)) {
      return $metadata;
    }
    $lifted = ['version' => self::METADATA_VERSION];
    foreach ($metadata as $key => $value) {
      if (!in_array($key, self::V1_RUN_KEYS, TRUE)) {
        $lifted[$key] = $value;
      }
    }
    $lifted['stories'] = [];
    return $lifted;
  }

  /**
   * Returns one story record of a version 2 record, or an empty array.
   *
   * @param array<string, mixed> $metadata
   *   Decoded metadata.
   * @param string $story
   *   Story name, citizen or staff.
   *
   * @return array<string, mixed>
   *   Story record.
   */
  public static function storyRecord(array $metadata, string $story): array {
    if (!self::isV2($metadata)) {
      return [];
    }
    $record = $metadata['stories'][$story] ?? NULL;
    return is_array($record) ? $record : [];
  }

  /**
   * Overall status: pending before failed before complete.
   *
   * @param array<string, mixed> $stories
   *   Story records keyed by story.
   */
  public static function overallStatus(array $stories): string {
    $statuses = [];
    foreach ($stories as $record) {
      if (is_array($record) && is_string($record['status'] ?? NULL)) {
        $statuses[] = $record['status'];
      }
    }
    foreach (['pending', 'failed', 'complete'] as $status) {
      if (in_array($status, $statuses, TRUE)) {
        return $status;
      }
    }
    return 'not_requested';
  }

  /**
   * Returns TRUE while a pending story run holds its lease.
   *
   * @param array<string, mixed> $record
   *   Story record.
   * @param int $now
   *   Current request time.
   * @param int $ttl
   *   Lease length in seconds.
   */
  public static function hasActiveLease(array $record, int $now, int $ttl): bool {
    $started_at = $record['analysisStartedAt'] ?? NULL;
    return ($record['status'] ?? NULL) === 'pending'
      && is_int($started_at)
      && $started_at > $now - $ttl;
  }

  /**
   * Returns the raw analysis profile of the attachment's file field.
   *
   * @return array<string, mixed>|null
   *   The profile, enabled or not, or NULL when the field has none.
   */
  public static function fieldAnalysisProfile(ApplicationAttachmentInterface $attachment): ?array {
    $field_id = trim((string) ($attachment->get('field_field_id')->value ?? ''));
    if ($field_id === '') {
      return NULL;
    }
    $schema_entity = $attachment->get('field_schema')->entity;
    if (!$schema_entity instanceof FormSchemaInterface) {
      return NULL;
    }
    try {
      $schema = json_decode((string) $schema_entity->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($schema)) {
      return NULL;
    }
    foreach (($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach (($section['fields'] ?? []) as $field) {
        if (!is_array($field) || ($field['id'] ?? NULL) !== $field_id) {
          continue;
        }
        $analysis = $field['analysis'] ?? NULL;
        return ($field['type'] ?? NULL) === 'file' && is_array($analysis) ? $analysis : NULL;
      }
    }
    return NULL;
  }

  /**
   * Returns TRUE when the profile runs in story mode.
   *
   * The mode is decided by the prompt keys alone, like the Nuxt helper
   * attachmentAnalysisPrompts(): citizenPrompt or staffPrompt carries text.
   *
   * @param array<string, mixed>|null $profile
   *   Analysis profile.
   */
  public static function isStoryProfile(?array $profile): bool {
    if ($profile === NULL) {
      return FALSE;
    }
    foreach (['citizenPrompt', 'staffPrompt'] as $key) {
      if (is_string($profile[$key] ?? NULL) && trim($profile[$key]) !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when story A has something to check.
   *
   * @param array<string, mixed> $profile
   *   Analysis profile.
   */
  public static function profileHasCitizenStory(array $profile): bool {
    if (is_string($profile['citizenPrompt'] ?? NULL) && trim($profile['citizenPrompt']) !== '') {
      return TRUE;
    }
    foreach (self::resultDefinitions($profile) as $definition) {
      if (($definition['nonPersonal'] ?? FALSE) === TRUE || ($definition['xBeantragtNonPersonal'] ?? FALSE) === TRUE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the keys of the profile's nonPersonal facts (story A).
   *
   * The applicant may only ever see these. A key that is also declared
   * purposeBound never counts, whatever else its definition says.
   *
   * @param array<string, mixed>|null $profile
   *   Analysis profile.
   *
   * @return list<string>
   *   Fact keys from properties and fields.
   */
  public static function citizenFactKeys(?array $profile): array {
    $schema = is_array($profile['resultSchema'] ?? NULL) ? $profile['resultSchema'] : NULL;
    if ($schema === NULL) {
      return [];
    }
    $candidates = [];
    if (is_array($schema['properties'] ?? NULL)) {
      foreach ($schema['properties'] as $key => $definition) {
        $candidates[] = [$key, $definition];
      }
    }
    if (is_array($schema['fields'] ?? NULL)) {
      foreach ($schema['fields'] as $definition) {
        $key = is_array($definition) ? ($definition['key'] ?? $definition['id'] ?? $definition['name'] ?? NULL) : NULL;
        $candidates[] = [$key, $definition];
      }
    }
    $keys = [];
    foreach ($candidates as [$key, $definition]) {
      if (!is_string($key)
        || trim($key) === ''
        || !is_array($definition)
        || ($definition['purposeBound'] ?? FALSE) === TRUE) {
        continue;
      }
      if (($definition['nonPersonal'] ?? FALSE) === TRUE || ($definition['xBeantragtNonPersonal'] ?? FALSE) === TRUE) {
        $keys[] = trim($key);
      }
    }
    return array_values(array_unique($keys));
  }

  /**
   * Returns TRUE when story B has at least one purpose-bound value to read.
   *
   * Without such a definition the staff run could return nothing, so it is
   * not dispatched (one call instead of two).
   *
   * @param array<string, mixed> $profile
   *   Analysis profile.
   */
  public static function profileHasStaffStory(array $profile): bool {
    return self::staffFactDefinitions($profile) !== [];
  }

  /**
   * Returns configured purpose-bound scalar facts keyed by extraction key.
   *
   * @param array<string, mixed> $profile
   *   The attachment analysis profile.
   *
   * @return array<string, array<string, mixed>>
   *   Allowlisted definitions from either result schema format.
   */
  public static function staffFactDefinitions(array $profile): array {
    $schema = $profile['resultSchema'] ?? NULL;
    if (!is_array($schema)) {
      return [];
    }
    $definitions = [];
    foreach (['properties', 'fields'] as $format) {
      foreach (is_array($schema[$format] ?? NULL) ? $schema[$format] : [] as $key => $definition) {
        if (!is_array($definition)
          || ($definition['purposeBound'] ?? FALSE) !== TRUE
          || ($definition['nonPersonal'] ?? FALSE) === TRUE
          || ($definition['xBeantragtNonPersonal'] ?? FALSE) === TRUE
          || !in_array($definition['type'] ?? NULL, ['number', 'integer', 'boolean'], TRUE)) {
          continue;
        }
        if ($format === 'fields') {
          $key = $definition['key'] ?? $definition['id'] ?? $definition['name'] ?? '';
        }
        $key = is_string($key) ? trim($key) : '';
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,79}$/D', $key) === 1) {
          $definitions[$key] ??= $definition;
          if (count($definitions) === self::MAX_STAFF_FACTS) {
            return $definitions;
          }
        }
      }
    }
    return $definitions;
  }

  /**
   * Returns TRUE when the policy may send private bytes to the model class.
   *
   * @param array<string, mixed> $profile
   *   Analysis profile.
   */
  public static function modelClassAllowed(array $profile): bool {
    $egress_policy = $profile['egressPolicy'] ?? NULL;
    $model_class = $profile['modelClass'] ?? NULL;
    if (!is_string($egress_policy) || !is_string($model_class)) {
      return FALSE;
    }
    return in_array(
      $model_class,
      self::DISPATCHABLE_MODEL_CLASSES_BY_EGRESS_POLICY[$egress_policy] ?? [],
      TRUE,
    );
  }

  /**
   * Returns TRUE when story A runs on the upload path for this profile.
   *
   * Trigger table: sovereign_only always, external_allowed only with the
   * persisted citizen consent. external_redacted and external_allowed
   * without consent run story A in the queue after the claim instead.
   *
   * @param array<string, mixed>|null $profile
   *   Analysis profile.
   * @param bool $consent
   *   Persisted citizen consent for external analysis.
   */
  public static function uploadRunsCitizenStory(?array $profile, bool $consent): bool {
    if ($profile === NULL
      || ($profile['enabled'] ?? FALSE) !== TRUE
      || !self::isStoryProfile($profile)
      || !self::profileHasCitizenStory($profile)
      || !self::modelClassAllowed($profile)) {
      return FALSE;
    }
    $egress_policy = $profile['egressPolicy'] ?? NULL;
    return $egress_policy === 'sovereign_only'
      || ($egress_policy === 'external_allowed' && $consent);
  }

  /**
   * Returns the result-schema definitions of a profile.
   *
   * @param array<string, mixed> $profile
   *   Analysis profile.
   *
   * @return list<array<string, mixed>>
   *   Definitions from properties and fields.
   */
  private static function resultDefinitions(array $profile): array {
    $schema = $profile['resultSchema'] ?? NULL;
    if (!is_array($schema)) {
      return [];
    }
    $definitions = [];
    if (is_array($schema['properties'] ?? NULL)) {
      foreach ($schema['properties'] as $definition) {
        if (is_array($definition)) {
          $definitions[] = $definition;
        }
      }
    }
    if (is_array($schema['fields'] ?? NULL)) {
      foreach ($schema['fields'] as $definition) {
        if (is_array($definition)) {
          $definitions[] = $definition;
        }
      }
    }
    return $definitions;
  }

  /**
   * Reloads the attachment after the cache reset.
   */
  private function reload(ApplicationAttachmentInterface $attachment): ApplicationAttachmentInterface {
    $storage = $this->entityTypeManager->getStorage('application_attachment');
    $id = (int) $attachment->id();
    if ($id > 0) {
      $storage->resetCache([$id]);
    }
    $matches = $storage->loadByProperties(['uuid' => (string) $attachment->uuid()]);
    $fresh = count($matches) === 1 ? reset($matches) : NULL;
    if (!$fresh instanceof ApplicationAttachmentInterface) {
      throw new \RuntimeException('Attachment disappeared before its analysis metadata could be written.');
    }
    return $fresh;
  }

  /**
   * Returns the request time used for run leases.
   */
  private function requestTime(): int {
    return $this->time?->getRequestTime() ?? time();
  }

}
