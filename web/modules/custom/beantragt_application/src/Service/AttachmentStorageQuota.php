<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves tenant attachment storage policy and durable usage.
 */
class AttachmentStorageQuota {

  public const DEFAULT_WARNING_PERCENT = 80;
  public const MAX_STORAGE_BYTES = 1099511627776;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?TimeInterface $time = NULL,
    private readonly ?Connection $database = NULL,
  ) {}

  /**
   * Normalizes stored upload settings from field_jur_config.
   *
   * @param array<string, mixed> $uploads
   *   Stored upload settings.
   *
   * @return array{maxStorageBytes: int, warningThresholdPercent: int}
   *   Normalized storage policy.
   */
  public static function normalizePolicy(array $uploads): array {
    return [
      'maxStorageBytes' => self::nonNegativeInteger($uploads['maxStorageBytes'] ?? NULL) ?? 0,
      'warningThresholdPercent' => self::percentInteger($uploads['warningThresholdPercent'] ?? NULL) ?? self::DEFAULT_WARNING_PERCENT,
    ];
  }

  /**
   * Returns upload storage usage and status for a jurisdiction.
   *
   * @return array<string, int|string|bool>
   *   Usage summary.
   */
  public function usageSummary(GroupInterface $jurisdiction): array {
    $policy = $this->policyForJurisdiction($jurisdiction);
    $usage = $this->usageForJurisdiction($jurisdiction);
    return $this->summaryFromUsage($usage['usedStorageBytes'], $usage['attachmentCount'], $policy);
  }

  /**
   * Returns a quota violation for a form upload, or NULL when allowed.
   *
   * @return array<string, int|string>|null
   *   Violation details.
   */
  public function violationForFormUpload(FormSchemaInterface $form, int $nextBytes): ?array {
    $jurisdiction = $this->jurisdictionForForm($form);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    return $this->violationForJurisdiction($jurisdiction, $nextBytes);
  }

  /**
   * Returns a quota violation for a jurisdiction, or NULL when allowed.
   *
   * @return array<string, int|string>|null
   *   Violation details.
   */
  public function violationForJurisdiction(GroupInterface $jurisdiction, int $nextBytes): ?array {
    $policy = $this->policyForJurisdiction($jurisdiction);
    $limit = $policy['maxStorageBytes'];
    if ($limit <= 0) {
      return NULL;
    }

    $usage = $this->usageForJurisdiction($jurisdiction);
    $used = $usage['usedStorageBytes'] + $this->pendingUploadBytesForJurisdiction($jurisdiction);
    $projected = $used + max(0, $nextBytes);
    if ($projected <= $limit) {
      return NULL;
    }

    return [
      'limit' => $limit,
      'used' => $used,
      'projected' => $projected,
      'warningThresholdPercent' => $policy['warningThresholdPercent'],
    ];
  }

  /**
   * Resolves policy for a jurisdiction group.
   *
   * @return array{maxStorageBytes: int, warningThresholdPercent: int}
   *   Normalized policy.
   */
  public function policyForJurisdiction(GroupInterface $jurisdiction): array {
    $config = $this->jurisdictionConfig($jurisdiction);
    $uploads = $config['uploads'] ?? [];
    return self::normalizePolicy(is_array($uploads) ? $uploads : []);
  }

  /**
   * Sums durable claimed attachment storage for a jurisdiction.
   *
   * @return array{usedStorageBytes: int, attachmentCount: int}
   *   Usage counters.
   */
  public function usageForJurisdiction(GroupInterface $jurisdiction): array {
    $jurisdiction_id = (int) $jurisdiction->id();
    if ($jurisdiction_id <= 0) {
      return ['usedStorageBytes' => 0, 'attachmentCount' => 0];
    }

    $attachment_usage = $this->durableAttachmentUsage($jurisdiction_id);
    $comment_usage = $this->legacyCommentAttachmentUsage($jurisdiction_id);
    $used = $attachment_usage['usedStorageBytes']
      + $this->guardDerivativeBytes($jurisdiction_id)
      + $comment_usage['usedStorageBytes'];
    $count = $attachment_usage['attachmentCount'] + $comment_usage['attachmentCount'];

    return ['usedStorageBytes' => $used, 'attachmentCount' => $count];
  }

  /**
   * Sums currently unclaimed upload bytes for forms in a jurisdiction.
   */
  public function pendingUploadBytesForJurisdiction(GroupInterface $jurisdiction): int {
    $jurisdiction_id = (int) $jurisdiction->id();
    if ($jurisdiction_id <= 0) {
      return 0;
    }

    $query = $this->database()->select('application_attachment', 'attachment');
    $form_alias = $query->innerJoin(
      'form_schema',
      'form_schema',
      'form_schema.id = attachment.field_schema',
    );
    $organisation_scope_alias = $query->leftJoin(
      'group__field_jurisdiction',
      'form_organisation_scope',
      'form_organisation_scope.entity_id = form_schema.field_organisation AND form_organisation_scope.deleted = 0',
    );
    $legacy_organisation_scope_alias = $query->leftJoin(
      'group__field_jurisdiction',
      'form_legacy_organisation_scope',
      'form_legacy_organisation_scope.entity_id = form_schema.field_group AND form_legacy_organisation_scope.deleted = 0',
    );
    $jurisdiction_scope = $query->orConditionGroup()
      ->condition($form_alias . '.field_jurisdiction', $jurisdiction_id)
      ->condition($form_alias . '.field_group', $jurisdiction_id)
      ->condition($organisation_scope_alias . '.field_jurisdiction_target_id', $jurisdiction_id)
      ->condition($legacy_organisation_scope_alias . '.field_jurisdiction_target_id', $jurisdiction_id);
    $active_claim = $query->orConditionGroup()
      ->condition('attachment.field_scan_status', AttachmentScanResult::STATUS_QUARANTINED)
      ->condition('attachment.field_claim_expires', $this->requestTime(), '>');
    $query
      ->condition($jurisdiction_scope)
      ->condition('attachment.field_upload_status', 'uploaded')
      ->isNull('attachment.field_application')
      ->condition($active_claim)
      ->addExpression(
        'COALESCE(SUM(CASE WHEN attachment.field_size > 0 THEN attachment.field_size ELSE 0 END), 0)',
        'pending_storage_bytes',
      );

    return max(0, (int) $query->execute()->fetchField());
  }

  /**
   * Aggregates durable attachment rows for a jurisdiction.
   *
   * @return array{usedStorageBytes:int, attachmentCount:int}
   *   Durable attachment usage.
   */
  private function durableAttachmentUsage(int $jurisdictionId): array {
    $query = $this->database()->select('application_attachment', 'attachment');
    $application_alias = $query->innerJoin(
      'application',
      'application',
      'application.id = attachment.field_application',
    );
    $query->condition($application_alias . '.field_jurisdiction', $jurisdictionId);
    $query->addExpression(
      'COALESCE(SUM(CASE WHEN attachment.field_size > 0 THEN attachment.field_size ELSE 0 END), 0)',
      'used_storage_bytes',
    );
    $query->addExpression('COUNT(attachment.id)', 'attachment_count');
    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'usedStorageBytes' => max(0, (int) ($row['used_storage_bytes'] ?? 0)),
      'attachmentCount' => max(0, (int) ($row['attachment_count'] ?? 0)),
    ];
  }

  /**
   * Aggregates distinct document guard derivative bytes for a jurisdiction.
   */
  private function guardDerivativeBytes(int $jurisdictionId): int {
    $derivative_ids = $this->database()->select('application_attachment', 'derivative_attachment');
    $application_alias = $derivative_ids->innerJoin(
      'application',
      'derivative_application',
      'derivative_application.id = derivative_attachment.field_application',
    );
    $derivative_ids
      ->condition($application_alias . '.field_jurisdiction', $jurisdictionId)
      ->isNotNull('derivative_attachment.field_guard_derivative__target_id');
    $derivative_ids->addField('derivative_attachment', 'field_guard_derivative__target_id', 'fid');
    $derivative_ids->distinct();

    $query = $this->database()->select($derivative_ids, 'derivative_ids');
    $file_alias = $query->innerJoin(
      'file_managed',
      'derivative_file',
      'derivative_file.fid = derivative_ids.fid',
    );
    $query->addExpression(
      sprintf(
        'COALESCE(SUM(CASE WHEN %1$s.filesize > 0 THEN %1$s.filesize ELSE 0 END), 0)',
        $file_alias,
      ),
      'derivative_storage_bytes',
    );

    return max(0, (int) $query->execute()->fetchField());
  }

  /**
   * Aggregates legacy comment files not represented by attachment entities.
   *
   * @return array{usedStorageBytes:int, attachmentCount:int}
   *   Legacy comment attachment usage.
   */
  private function legacyCommentAttachmentUsage(int $jurisdictionId): array {
    if (!$this->entityTypeManager->hasDefinition('application_comment')) {
      return ['usedStorageBytes' => 0, 'attachmentCount' => 0];
    }

    $counted_files = $this->database()->select('application_attachment', 'counted_attachment');
    $counted_application_alias = $counted_files->innerJoin(
      'application',
      'counted_application',
      'counted_application.id = counted_attachment.field_application',
    );
    $counted_files
      ->condition($counted_application_alias . '.field_jurisdiction', $jurisdictionId)
      ->where(
        '(counted_attachment.field_file__target_id = comment_file.field_attachments_target_id OR counted_attachment.field_guard_derivative__target_id = comment_file.field_attachments_target_id)',
      )
      ->addExpression('1');

    $query = $this->database()->select('application_comment', 'application_comment');
    $application_alias = $query->innerJoin(
      'application',
      'comment_application',
      'comment_application.id = application_comment.field_application',
    );
    $comment_file_alias = $query->innerJoin(
      'application_comment__field_attachments',
      'comment_file',
      'comment_file.entity_id = application_comment.id AND comment_file.deleted = 0',
    );
    $file_alias = $query->innerJoin(
      'file_managed',
      'comment_file_data',
      'comment_file_data.fid = comment_file.field_attachments_target_id',
    );
    $query
      ->condition($application_alias . '.field_jurisdiction', $jurisdictionId)
      ->notExists($counted_files);
    $query->addExpression(
      sprintf(
        'COALESCE(SUM(CASE WHEN %1$s.filesize > 0 THEN %1$s.filesize ELSE 0 END), 0)',
        $file_alias,
      ),
      'used_storage_bytes',
    );
    $query->addExpression('COUNT(' . $comment_file_alias . '.field_attachments_target_id)', 'attachment_count');
    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'usedStorageBytes' => max(0, (int) ($row['used_storage_bytes'] ?? 0)),
      'attachmentCount' => max(0, (int) ($row['attachment_count'] ?? 0)),
    ];
  }

  /**
   * Returns the injected database connection.
   */
  private function database(): Connection {
    if (!$this->database instanceof Connection) {
      throw new \LogicException('Attachment storage quota database connection is not configured.');
    }
    return $this->database;
  }

  /**
   * Resolves the jurisdiction group referenced by a form.
   */
  public function jurisdictionForForm(FormSchemaInterface $form): ?GroupInterface {
    $direct = $this->referencedGroup($form, 'field_jurisdiction');
    if ($direct instanceof GroupInterface && $direct->bundle() === 'jur') {
      return $direct;
    }

    $organisation = $this->referencedGroup($form, 'field_organisation')
      ?? $this->referencedGroup($form, 'field_group');
    if ($organisation instanceof GroupInterface && $organisation->bundle() === 'org') {
      $parent = $this->referencedGroup($organisation, 'field_jurisdiction');
      return $parent instanceof GroupInterface && $parent->bundle() === 'jur' ? $parent : NULL;
    }

    $legacy = $this->referencedGroup($form, 'field_group');
    return $legacy instanceof GroupInterface && $legacy->bundle() === 'jur' ? $legacy : NULL;
  }

  /**
   * Builds stable summary fields from usage and policy.
   *
   * @return array<string, int|string|bool>
   *   Usage summary.
   */
  public function summaryFromUsage(int $usedStorageBytes, int $attachmentCount, array $policy): array {
    $limit = (int) ($policy['maxStorageBytes'] ?? 0);
    $warning_percent = (int) ($policy['warningThresholdPercent'] ?? self::DEFAULT_WARNING_PERCENT);
    $warning_bytes = $limit > 0 ? (int) floor($limit * ($warning_percent / 100)) : 0;
    $usage_percent = $limit > 0 ? (int) floor(($usedStorageBytes / $limit) * 100) : 0;
    $status = 'disabled';
    if ($limit > 0) {
      $status = $usedStorageBytes > $limit
        ? 'exceeded'
        : ($usedStorageBytes >= $warning_bytes ? 'warning' : 'ok');
    }

    return [
      'maxStorageBytes' => $limit,
      'warningThresholdPercent' => $warning_percent,
      'warningStorageBytes' => $warning_bytes,
      'usedStorageBytes' => max(0, $usedStorageBytes),
      'attachmentCount' => max(0, $attachmentCount),
      'quotaEnabled' => $limit > 0,
      'usagePercent' => min(999, max(0, $usage_percent)),
      'quotaStatus' => $status,
    ];
  }

  /**
   * Reads the raw jurisdiction config blob.
   *
   * @return array<string, mixed>
   *   Decoded config.
   */
  private function jurisdictionConfig(GroupInterface $jurisdiction): array {
    $source = $jurisdiction->getUntranslated();
    if (!$source->hasField('field_jur_config') || $source->get('field_jur_config')->isEmpty()) {
      return [];
    }
    $decoded = json_decode((string) $source->get('field_jur_config')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Resolves an entity reference field to a group.
   */
  private function referencedGroup(FieldableEntityInterface $entity, string $fieldName): ?GroupInterface {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return NULL;
    }
    $group = $entity->get($fieldName)->entity;
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Normalizes a non-negative integer.
   */
  private static function nonNegativeInteger(mixed $value): ?int {
    if (is_int($value) && $value >= 0 && $value <= self::MAX_STORAGE_BYTES) {
      return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,12})$/', trim($value)) === 1) {
      $int_value = (int) trim($value);
      return $int_value <= self::MAX_STORAGE_BYTES ? $int_value : NULL;
    }
    return NULL;
  }

  /**
   * Normalizes a percentage integer.
   */
  private static function percentInteger(mixed $value): ?int {
    if (is_int($value) && $value >= 1 && $value <= 100) {
      return $value;
    }
    if (is_string($value) && preg_match('/^(?:[1-9][0-9]?|100)$/', trim($value)) === 1) {
      return (int) trim($value);
    }
    return NULL;
  }

  /**
   * Returns the current request time.
   */
  private function requestTime(): int {
    return $this->time?->getRequestTime() ?? time();
  }

}
