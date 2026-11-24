<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates and claims public application attachment upload tokens.
 */
final class PublicApplicationAttachmentClaimService {

  private const MAX_ATTACHMENTS = 20;
  private const MAX_ATTACHMENTS_PER_FIELD = 5;
  private const MAX_ATTACHMENT_FILE_BYTES = 10485760;
  private const DEFAULT_MAX_ATTACHMENT_BYTES_PER_APPLICATION = 52428800;
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
  private const CLAIM_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,96}$/';

  /**
   * Revision events that follow an insert without changing submitted data.
   */
  private const CREATION_FOLLOW_UP_EVENTS = ['label_generated', 'assignment_sync'];

  /**
   * Constructs the public attachment claim service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly ?AttachmentStorageQuota $attachmentStorageQuota = NULL,
  ) {
  }

  /**
   * Validates attachment claim tokens for submitted file fields.
   *
   * @param mixed $claims
   *   Submitted attachment claim list.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   Public form schema.
   * @param array<string, mixed> $context
   *   Submission context.
   * @param int|null $expected_owner_id
   *   Optional authenticated owner id expected on claimed attachments.
   * @param bool $reload
   *   TRUE under the claim locks: every attachment is reloaded past the
   *   entity cache, so claim() saves the state another writer (an analysis
   *   callback) stored before the lock, not the snapshot of the first pass.
   *
   * @return array{byField: array<string, array<int, array<string, mixed>>>, entities: array<int, \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface>, error: array<string, mixed>|null}
   *   Attachment metadata grouped by field, loaded attachment entities and
   *   optional error.
   */
  public function validateClaims(mixed $claims, FormSchemaInterface $form, array $context, ?int $expected_owner_id = NULL, bool $reload = FALSE): array {
    $empty = [
      'byField' => [],
      'entities' => [],
      'error' => NULL,
    ];
    if ($claims === NULL) {
      return $empty;
    }
    if (!is_array($claims) || !array_is_list($claims)) {
      return [
        ...$empty,
        'error' => [
          'code' => 'attachment.invalid_claims',
          'message' => 'Attachment claims must be an array.',
          'status' => Response::HTTP_BAD_REQUEST,
        ],
      ];
    }
    if (count($claims) > self::MAX_ATTACHMENTS) {
      return [
        ...$empty,
        'error' => [
          'code' => 'attachment.too_many',
          'message' => 'Too many attachments.',
          'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        ],
      ];
    }

    $file_fields = [];
    foreach ($context['fields'] as $field) {
      if (($field['type'] ?? NULL) === 'file') {
        $file_fields[(string) $field['id']] = $field;
      }
    }

    $by_field = [];
    $entities = [];
    $seen = [];
    $total_size = 0;
    $max_total_size = $this->maxAttachmentBytesPerApplication();
    $storage = $this->entityTypeManager->getStorage('application_attachment');
    foreach ($claims as $claim) {
      if (!is_array($claim) || array_is_list($claim)) {
        return $this->error('attachment.invalid_claim', 'Attachment claim is invalid.', [], Response::HTTP_BAD_REQUEST);
      }
      $field_id = is_string($claim['fieldId'] ?? NULL) ? trim($claim['fieldId']) : '';
      $attachment_uuid = $this->normalizeUuid($claim['attachmentId'] ?? NULL);
      $claim_token = is_string($claim['claimToken'] ?? NULL) ? trim($claim['claimToken']) : '';
      if ($field_id === '' || !isset($file_fields[$field_id]) || $attachment_uuid === NULL || preg_match(self::CLAIM_TOKEN_PATTERN, $claim_token) !== 1) {
        return $this->error('attachment.invalid_claim', 'Attachment claim is invalid.', ['field' => $field_id], Response::HTTP_BAD_REQUEST);
      }
      if (isset($seen[$attachment_uuid])) {
        return $this->error('attachment.duplicate', 'Attachment claim is duplicated.', ['attachmentId' => $attachment_uuid], Response::HTTP_BAD_REQUEST);
      }
      $seen[$attachment_uuid] = TRUE;
      $field_max_files = $this->fileFieldMaxFiles($file_fields[$field_id]);
      if (count($by_field[$field_id] ?? []) >= $field_max_files) {
        return $this->error('attachment.too_many_for_field', 'Too many attachments for one field.', ['field' => $field_id], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
      }

      $matches = $storage->loadByProperties(['uuid' => $attachment_uuid]);
      $attachment = count($matches) === 1 ? reset($matches) : NULL;
      if ($reload && $attachment instanceof ApplicationAttachmentInterface) {
        $attachment_id = (int) $attachment->id();
        $storage->resetCache([$attachment_id]);
        $attachment = $storage->load($attachment_id);
      }
      if (!$attachment instanceof ApplicationAttachmentInterface) {
        return $this->error('attachment.not_found', 'Attachment not found.', ['attachmentId' => $attachment_uuid], Response::HTTP_NOT_FOUND);
      }
      if ((int) $attachment->get('field_schema')->target_id !== (int) $form->id()
        || (string) $attachment->get('field_field_id')->value !== $field_id
        || (string) $attachment->get('field_upload_status')->value !== 'uploaded'
        || !$attachment->get('field_application')->isEmpty()) {
        return $this->error('attachment.claim_invalid', 'Attachment claim is invalid.', ['attachmentId' => $attachment_uuid]);
      }
      if (!$this->attachmentFileExists($attachment)) {
        return $this->error('attachment.claim_invalid', 'Attachment claim is invalid.', ['attachmentId' => $attachment_uuid]);
      }
      if ($this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_QUARANTINED) {
        return $this->error('attachment.quarantined', 'Attachment was quarantined by malware scanning.', ['attachmentId' => $attachment_uuid]);
      }
      if ($expected_owner_id !== NULL && (int) $attachment->getOwnerId() !== $expected_owner_id) {
        return $this->error('attachment.claim_invalid', 'Attachment claim is invalid.', ['attachmentId' => $attachment_uuid]);
      }
      $expires = (int) ($attachment->get('field_claim_expires')->value ?? 0);
      if ($expires <= $this->time->getRequestTime()) {
        return $this->error('attachment.claim_expired', 'Attachment claim has expired.', ['attachmentId' => $attachment_uuid]);
      }
      $hash = (string) ($attachment->get('field_claim_token_hash')->value ?? '');
      if ($hash === '' || !hash_equals($hash, hash('sha256', $claim_token))) {
        return $this->error('attachment.claim_invalid', 'Attachment claim is invalid.', ['attachmentId' => $attachment_uuid]);
      }

      $size = (int) ($attachment->get('field_size')->value ?? 0);
      $field_max_size = $this->fileFieldMaxBytes($file_fields[$field_id]);
      if ($size > $field_max_size) {
        return $this->error(
          'attachment.file_too_large',
          'Attachment exceeds the field size limit.',
          [
            'attachmentId' => $attachment_uuid,
            'field' => $field_id,
          ],
          Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
      }
      $total_size += max(0, $size);
      if ($max_total_size > 0 && $total_size > $max_total_size) {
        return $this->error('attachment.total_size_exceeded', 'Total attachment size exceeds the application limit.', ['limit' => $max_total_size], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
      }

      $by_field[$field_id][] = [
        'attachmentId' => $attachment_uuid,
        'filename' => (string) ($attachment->get('field_original_filename')->value ?? ''),
        'mimeType' => (string) ($attachment->get('field_mime_type')->value ?? ''),
        'size' => $size,
        'analysisStatus' => (string) ($attachment->get('field_analysis_status')->value ?? 'not_requested'),
      ];
      $entities[(int) $attachment->id()] = $attachment;
    }

    if ($entities !== [] && ($context['jurisdiction'] ?? NULL) instanceof GroupInterface) {
      $violation = $this->attachmentStorageQuota?->violationForJurisdiction($context['jurisdiction'], 0);
      if ($violation !== NULL) {
        return $this->error(
          'attachment.tenant_storage_quota_exceeded',
          'Tenant attachment storage quota would be exceeded.',
          $violation,
          Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
      }
    }

    return [
      'byField' => $by_field,
      'entities' => $entities,
      'error' => NULL,
    ];
  }

  /**
   * Keeps only attachment entities that survived conditional field validation.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated attachment entities.
   * @param array<string, mixed> $sanitized_data
   *   Final application data after conditional visibility filtering.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[]
   *   Claimable attachment entities.
   */
  public function visibleEntities(array $attachments, array $sanitized_data): array {
    return array_filter(
      $attachments,
      static fn(ApplicationAttachmentInterface $attachment): bool => array_key_exists(
        (string) $attachment->get('field_field_id')->value,
        $sanitized_data,
      ),
    );
  }

  /**
   * Returns the insert revision while it still holds the submitted data.
   *
   * Every application save creates a revision.
   * The insert is the earliest revision. System follow-ups of the same
   * request, the generated label and automatic routing, add revisions that
   * never touch field_data. Any other later revision, such as a data edit,
   * means the application is no longer the original submission.
   *
   * @return int
   *   The insert revision ID, or 0 when the proof does not hold.
   */
  private function submissionRevisionId(ApplicationInterface $application): int {
    $storage = $this->entityTypeManager->getStorage('application');
    if (!$storage instanceof RevisionableStorageInterface) {
      return 0;
    }
    $revision_ids = array_map('intval', array_keys($storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('id', (int) $application->id())
      ->sort($storage->getEntityType()->getKey('revision'), 'ASC')
      ->range(0, 10)
      ->execute()));
    $insert = array_shift($revision_ids);
    if ($insert === NULL || count($revision_ids) > 8) {
      return 0;
    }
    $insert_revision = $storage->loadRevision($insert);
    if (!$insert_revision instanceof ApplicationInterface) {
      return 0;
    }
    $submitted_data = (string) $insert_revision->getData();
    foreach ($storage->loadMultipleRevisions($revision_ids) as $revision) {
      $events = $revision instanceof RevisionLogInterface
        ? (RevisionLog::decode((string) $revision->getRevisionLogMessage())['events'] ?? [])
        : [];
      if ($events === [] || array_diff($events, self::CREATION_FOLLOW_UP_EVENTS) !== []) {
        return 0;
      }
      // The event codes are a convention of their writers; the stored data
      // itself must still be the submitted data.
      if (!$revision instanceof ApplicationInterface || !hash_equals($submitted_data, (string) $revision->getData())) {
        return 0;
      }
    }
    return $insert;
  }

  /**
   * Claims uploaded attachments for a newly created application.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated attachment entities.
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Newly created application entity.
   * @param bool $original_submission
   *   TRUE only at the original public submission call site after first save.
   */
  public function claim(array $attachments, ApplicationInterface $application, bool $original_submission = FALSE): void {
    if ($attachments === []) {
      return;
    }
    $submission_revision = 0;
    if ($original_submission && $application->getCreatedTime() >= $this->time->getRequestTime()) {
      $submission_revision = $this->submissionRevisionId($application);
    }
    $file_storage = $this->entityTypeManager->getStorage('file');
    foreach ($attachments as $attachment) {
      $attachment->set('field_application', ['target_id' => (int) $application->id()]);
      $attachment->set('field_upload_status', 'claimed');
      $attachment->set('field_submission_revision_id', $submission_revision ?: NULL);
      $attachment->set('field_claim_token_hash', NULL);
      $attachment->set('field_claim_expires', NULL);
      $attachment->setOwnerId((int) $application->getOwnerId());

      if (!$attachment->get('field_file')->isEmpty()) {
        $file = $file_storage->load($attachment->get('field_file')->target_id);
        if ($file) {
          $file->setPermanent();
          $file->setOwnerId((int) $application->getOwnerId());
          $file->save();
        }
      }
      $attachment->save();
    }
  }

  /**
   * Acquires short-lived locks for attachment claim entities.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated attachment entities.
   *
   * @return string[]|null
   *   Lock names, or NULL when a lock could not be acquired.
   */
  public function acquireClaimLocks(array $attachments): ?array {
    $locks = [];
    foreach ($attachments as $attachment) {
      $lock_name = 'beantragt_application.attachment_claim.' . (int) $attachment->id();
      if (!$this->lock->acquire($lock_name, 30.0)) {
        $this->releaseLocks($locks);
        return NULL;
      }
      $locks[] = $lock_name;
    }
    return $locks;
  }

  /**
   * Acquires the jurisdiction quota lock while attachment claims are bound.
   *
   * @param array<string, mixed> $context
   *   Submission context.
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated attachment entities.
   *
   * @return string|false|null
   *   Lock name, NULL when no lock is needed, or FALSE when acquisition failed.
   */
  public function acquireTenantQuotaLock(array $context, array $attachments): string|false|null {
    if ($attachments === [] || !$this->attachmentStorageQuota instanceof AttachmentStorageQuota) {
      return NULL;
    }
    $jurisdiction = $context['jurisdiction'] ?? NULL;
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $lock_name = 'beantragt_application.attachment_storage_quota.' . (int) $jurisdiction->id();
    return $this->lock->acquire($lock_name, 30.0) ? $lock_name : FALSE;
  }

  /**
   * Releases a single lock when one was acquired.
   */
  public function releaseLock(string|false|null $lock_name): void {
    if (is_string($lock_name)) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Releases attachment claim locks.
   *
   * @param string[] $locks
   *   Lock names.
   */
  public function releaseLocks(array $locks): void {
    foreach ($locks as $lock_name) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Builds an attachment claim validation error result.
   */
  private function error(string $code, string $message, array $details = [], int $status = Response::HTTP_UNPROCESSABLE_ENTITY): array {
    return [
      'byField' => [],
      'entities' => [],
      'error' => [
        'code' => $code,
        'message' => $message,
        'status' => $status,
        'details' => $details,
      ],
    ];
  }

  /**
   * Resolves the maximum attachment count for one file field.
   *
   * @param array<string, mixed> $field
   *   Schema field definition.
   */
  private function fileFieldMaxFiles(array $field): int {
    $validation = is_array($field['validation'] ?? NULL) ? $field['validation'] : [];
    $candidates = [
      $validation['maxFiles'] ?? NULL,
      $field['maxFiles'] ?? NULL,
    ];
    foreach ($candidates as $candidate) {
      $value = $this->positiveInteger($candidate);
      if ($value !== NULL) {
        return min($value, self::MAX_ATTACHMENTS_PER_FIELD);
      }
    }
    return self::MAX_ATTACHMENTS_PER_FIELD;
  }

  /**
   * Resolves the maximum byte size for one file field.
   *
   * @param array<string, mixed> $field
   *   Schema field definition.
   */
  private function fileFieldMaxBytes(array $field): int {
    $validation = is_array($field['validation'] ?? NULL) ? $field['validation'] : [];
    $candidates = [
      $validation['maxSizeBytes'] ?? NULL,
      $validation['maxFileSizeBytes'] ?? NULL,
      $field['maxSizeBytes'] ?? NULL,
      $field['maxFileSizeBytes'] ?? NULL,
    ];
    foreach ($candidates as $candidate) {
      $value = $this->positiveInteger($candidate);
      if ($value !== NULL) {
        return min($value, self::MAX_ATTACHMENT_FILE_BYTES);
      }
    }
    return self::MAX_ATTACHMENT_FILE_BYTES;
  }

  /**
   * Returns the aggregate attachment size cap for one application.
   */
  private function maxAttachmentBytesPerApplication(): int {
    $configured = $this->configFactory
      ->get('beantragt_application.settings')
      ->get('attachment_max_total_bytes_per_application');
    $value = $this->nonNegativeInteger($configured);
    return $value ?? self::DEFAULT_MAX_ATTACHMENT_BYTES_PER_APPLICATION;
  }

  /**
   * Normalizes a positive integer-like schema or config value.
   */
  private function positiveInteger(mixed $value): ?int {
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1) {
      return (int) $value;
    }
    return NULL;
  }

  /**
   * Normalizes a non-negative integer-like config value.
   */
  private function nonNegativeInteger(mixed $value): ?int {
    if (is_int($value) && $value >= 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,11})$/', $value) === 1) {
      return (int) $value;
    }
    return NULL;
  }

  /**
   * Returns TRUE when the claimed file entity and private file still exist.
   */
  private function attachmentFileExists(ApplicationAttachmentInterface $attachment): bool {
    if ($attachment->get('field_file')->isEmpty()) {
      return FALSE;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($attachment->get('field_file')->target_id);
    if (!$file instanceof FileInterface) {
      return FALSE;
    }
    $uri = (string) $file->getFileUri();
    return $uri !== '' && is_file($uri);
  }

  /**
   * Returns the stored malware scan status.
   */
  private function attachmentScanStatus(ApplicationAttachmentInterface $attachment): string {
    if (!$attachment->hasField('field_scan_status') || $attachment->get('field_scan_status')->isEmpty()) {
      return AttachmentScanResult::STATUS_CLEAN;
    }
    $status = (string) ($attachment->get('field_scan_status')->value ?? '');
    return in_array($status, [AttachmentScanResult::STATUS_CLEAN, AttachmentScanResult::STATUS_QUARANTINED], TRUE)
      ? $status
      : AttachmentScanResult::STATUS_QUARANTINED;
  }

  /**
   * Normalizes public UUID inputs.
   */
  private function normalizeUuid(mixed $uuid): ?string {
    if (!is_string($uuid)) {
      return NULL;
    }
    $uuid = strtolower(trim($uuid));
    return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : NULL;
  }

}
