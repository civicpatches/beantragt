<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_group\Service\OrgHierarchyResolverInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies application retention policies and reports cleanup impact.
 */
final class RetentionCleanupService {

  /**
   * Default maximum applications processed per run.
   */
  public const DEFAULT_LIMIT = 100;

  /**
   * Maximum accepted retention window.
   */
  private const MAX_RETENTION_DAYS = 36500;

  /**
   * Shared lock name for destructive retention cleanup runs.
   */
  private const LOCK_NAME = 'beantragt_application.retention_cleanup';

  /**
   * Constructs the retention cleanup service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
    private readonly ?OrgHierarchyResolverInterface $orgHierarchy = NULL,
  ) {}

  /**
   * Runs the retention cleanup or simulates it.
   *
   * @param bool $dryRun
   *   TRUE to report candidates without deleting entities or files.
   * @param int $limit
   *   Maximum application entities processed across all jurisdiction policies.
   *
   * @return array<string, mixed>
   *   Structured report with matched and deleted entity/file counts.
   */
  public function run(bool $dryRun = FALSE, int $limit = self::DEFAULT_LIMIT): array {
    $limit = max(1, min(1000, $limit));
    $now = $this->time->getRequestTime();
    $report = $this->emptyReport($dryRun, $limit, $now);
    $lockAcquired = FALSE;

    if (!$dryRun) {
      $lockAcquired = $this->lock->acquire(self::LOCK_NAME, 3600.0);
      if (!$lockAcquired) {
        $report['errors'][] = [
          'code' => 'retention_cleanup_already_running',
          'message' => 'Retention cleanup is already running.',
        ];
        $this->logger->warning('Data retention cleanup skipped because another run is already active.');
        return $report;
      }
    }

    try {
      $upload_report = $this->deleteExpiredAttachmentUploads($dryRun);
      $this->mergeCounts($report, $upload_report);
      $report['unclaimedAttachmentUploads'] = $upload_report;

      $unconfirmed_report = $this->deleteExpiredUnconfirmedApplications($dryRun, $limit);
      $this->mergeCounts($report, $unconfirmed_report);
      $report['unconfirmedApplications'] = $unconfirmed_report;

      $default_days = $this->globalRetentionDays();
      $report['globalRetentionDays'] = $default_days;
      $schema_overrides = $this->schemaRetentionOverrides();

      $remaining = max(0, $limit - (int) $unconfirmed_report['unconfirmedApplications']['matched']);
      $dryRunApplicationIds = [];
      $jurisdictions = $this->jurisdictionPolicies($default_days);
      $organisations = $this->organisationPolicies($jurisdictions);
      $jurisdictions[0] = [
        'jurisdictionId' => NULL,
        'jurisdictionLabel' => 'Unscoped applications',
        'jurisdictionSlug' => NULL,
        'retentionDays' => $default_days,
        'source' => 'global_unscoped',
        'disabled' => $default_days <= 0,
      ];
      foreach ($jurisdictions as $jurisdictionId => $policy) {
        $orgPolicies = $organisations[$jurisdictionId] ?? [];
        $policies = [
          ...$this->schemaPoliciesForBase($policy, $schema_overrides),
          ...$orgPolicies,
          $policy + ['excludedOrganisationIds' => array_column($orgPolicies, 'organisationId')],
        ];
        foreach ($policies as $candidate) {
          if ($remaining <= 0) {
            break 2;
          }
          if (!isset($candidate['schemaId'])) {
            $candidate['excludedSchemaIds'] = array_keys($schema_overrides);
          }
          $policy_report = $this->processPolicy($candidate, $now, $dryRun, $remaining, $dryRunApplicationIds);
          $remaining -= (int) $policy_report['applications']['matched'];
          $this->mergeCounts($report, $policy_report);
          $report['policies'][] = $policy_report;
        }
      }

      if (!$dryRun && $this->reportHasChanges($report)) {
        $this->logger->info('Data retention report: @report', [
          '@report' => json_encode($this->summaryForLog($report), JSON_UNESCAPED_SLASHES),
        ]);
      }

      return $report;
    }
    finally {
      if ($lockAcquired) {
        $this->lock->release(self::LOCK_NAME);
      }
    }
  }

  /**
   * Deletes direct application attachments and their files.
   *
   * @param int[] $applicationIds
   *   Application entity IDs.
   * @param bool $dryRun
   *   TRUE to only count matching attachments and files.
   *
   * @return array<string, array<string, int>>
   *   Matched/deleted attachment and file counts.
   */
  public function deleteApplicationAttachments(array $applicationIds, bool $dryRun = FALSE): array {
    $result = [
      'applicationAttachments' => ['matched' => 0, 'deleted' => 0],
      'applicationAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
    ];
    $applicationIds = $this->positiveIds($applicationIds);
    if ($applicationIds === [] || !$this->entityTypeManager->hasDefinition('application_attachment')) {
      return $result;
    }

    $attachmentStorage = $this->entityTypeManager->getStorage('application_attachment');
    $attachmentIds = $attachmentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', $applicationIds, 'IN')
      ->execute();
    if (empty($attachmentIds)) {
      return $result;
    }

    $attachments = $attachmentStorage->loadMultiple($attachmentIds);
    $originalFileIds = $this->fileIdsFromEntities($attachments, 'field_file');
    $fileIds = array_values(array_unique([
      ...$originalFileIds,
      ...$this->fileIdsFromEntities($attachments, 'field_guard_derivative'),
    ]));

    $result['applicationAttachments']['matched'] = count($attachments);
    $result['applicationAttachmentFiles']['matched'] = count($fileIds);
    if ($dryRun) {
      return $result;
    }

    $attachmentStorage->delete($attachments);
    $this->deleteFiles($originalFileIds);
    $result['applicationAttachments']['deleted'] = count($attachments);
    $result['applicationAttachmentFiles']['deleted'] = count($fileIds);
    return $result;
  }

  /**
   * Deletes application-owned dependent entities and files.
   *
   * @param int[] $applicationIds
   *   Application entity IDs.
   * @param bool $dryRun
   *   TRUE to only count matching dependencies.
   *
   * @return array<string, array<string, int>>
   *   Matched/deleted dependency counts.
   */
  public function deleteApplicationDependents(array $applicationIds, bool $dryRun = FALSE): array {
    $result = [
      'applicationAttachments' => ['matched' => 0, 'deleted' => 0],
      'applicationAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
      'comments' => ['matched' => 0, 'deleted' => 0],
      'commentAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
    ];

    $this->mergeTopLevelCounts($result, $this->deleteApplicationAttachments($applicationIds, $dryRun));
    $this->mergeTopLevelCounts($result, $this->deleteApplicationComments($applicationIds, $dryRun));
    return $result;
  }

  /**
   * Resolves a jurisdiction retention policy from field_jur_config.
   *
   * @param array<string, mixed> $config
   *   Decoded field_jur_config array.
   * @param int $defaultDays
   *   Global fallback retention in days.
   *
   * @return array{days:int, source:string}
   *   Normalized retention days and source marker.
   */
  public static function retentionDaysFromConfig(array $config, int $defaultDays): array {
    $retention = $config['retention'] ?? NULL;
    if (!is_array($retention) || !array_key_exists('dataRetentionDays', $retention)) {
      return ['days' => $defaultDays, 'source' => 'global'];
    }

    $days = self::normalizeRetentionDays($retention['dataRetentionDays']);
    if ($days === NULL) {
      return ['days' => $defaultDays, 'source' => 'global_invalid'];
    }

    return ['days' => $days, 'source' => 'jurisdiction'];
  }

  /**
   * Resolves a schema-level retention override from field_schema JSON.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema JSON.
   * @param int $defaultDays
   *   Inherited fallback retention in days.
   * @param string $defaultSource
   *   Inherited fallback source marker.
   *
   * @return array{days:int, source:string}
   *   Normalized retention days and source marker.
   */
  public static function retentionDaysFromSchema(array $schema, int $defaultDays, string $defaultSource): array {
    $days = self::schemaRetentionOverrideDays($schema);
    if ($days === NULL) {
      return ['days' => $defaultDays, 'source' => $defaultSource];
    }

    return ['days' => $days, 'source' => 'schema'];
  }

  /**
   * Normalizes a retention day value.
   */
  public static function normalizeRetentionDays(mixed $value): ?int {
    if (is_int($value)) {
      $days = $value;
    }
    elseif (is_string($value)) {
      $value = trim($value);
      if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return NULL;
      }
      $days = (int) $value;
    }
    else {
      return NULL;
    }

    return $days >= 0 && $days <= self::MAX_RETENTION_DAYS ? $days : NULL;
  }

  /**
   * Resolves the retention rule that will remove one confirmed application.
   *
   * Mirrors run(): precedence is form schema, organisation, jurisdiction,
   * then global fallback. The window starts at the
   * creation time of the application. A window of 0 days means the rule
   * deletes nothing. An application referencing a group that is not a
   * jurisdiction matches no policy of run() and is reported as such.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   A confirmed application.
   *
   * @return array{days: int, source: string, deleteAfter: ?int}
   *   Days, source (schema, organisation, jurisdiction, global, global_invalid,
   *   global_unscoped or unmatched) and the Unix timestamp
   *   from which the next cleanup run removes the application, or NULL when
   *   no rule removes it.
   */
  public function retentionForApplication(ApplicationInterface $application): array {
    $global = $this->globalRetentionDays();
    $rule = NULL;

    $schemaId = $this->referencedId($application, 'field_schema');
    if ($schemaId > 0 && $this->entityTypeManager->hasDefinition('form_schema')) {
      $schema = $this->entityTypeManager->getStorage('form_schema')->load($schemaId);
      if ($schema instanceof FieldableEntityInterface) {
        $days = self::schemaRetentionOverrideDays($this->readSchemaDefinition($schema));
        if ($days !== NULL) {
          $rule = ['days' => $days, 'source' => 'schema'];
        }
      }
    }

    if ($rule === NULL) {
      $jurisdictionId = $this->referencedId($application, 'field_jurisdiction');
      if ($jurisdictionId <= 0) {
        $rule = ['days' => $global, 'source' => 'global_unscoped'];
      }
      else {
        $group = $this->entityTypeManager->hasDefinition('group')
          ? $this->entityTypeManager->getStorage('group')->load($jurisdictionId)
          : NULL;
        $bundle = (string) ($this->configFactory
          ->get('beantragt_group.settings')
          ->get('jurisdiction_group_type') ?: 'jur');
        $rule = $group instanceof GroupInterface && $group->bundle() === $bundle
          ? self::retentionDaysFromConfig($this->readJurisdictionConfig($group), $global)
          : ['days' => 0, 'source' => 'unmatched'];
      }

      $organisationId = $this->referencedId($application, 'field_organisation');
      if ($organisationId > 0 && $rule['source'] !== 'unmatched' && $this->entityTypeManager->hasDefinition('group')) {
        $organisation = $this->entityTypeManager->getStorage('group')->load($organisationId);
        if ($organisation instanceof GroupInterface && $this->referencedId($organisation, 'field_jurisdiction') === $jurisdictionId) {
          $days = $this->organisationRetentionDays($organisation);
          if ($days !== NULL) {
            $rule = ['days' => $days, 'source' => 'organisation'];
          }
        }
      }
    }

    $created = (int) $application->getCreatedTime();
    $rule['deleteAfter'] = $rule['days'] > 0 && $created > 0
      ? $created + ($rule['days'] * 86400)
      : NULL;
    return $rule;
  }

  /**
   * Reads a single entity reference target ID, or 0.
   */
  private function referencedId(FieldableEntityInterface $entity, string $fieldName): int {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return 0;
    }
    return (int) ($entity->get($fieldName)->target_id ?? 0);
  }

  /**
   * Creates the base report.
   */
  private function emptyReport(bool $dryRun, int $limit, int $now): array {
    return [
      'dryRun' => $dryRun,
      'generatedAt' => $now,
      'limit' => $limit,
      'globalRetentionDays' => 0,
      'counts' => [
        'applications' => ['matched' => 0, 'deleted' => 0],
        'unconfirmedApplications' => ['matched' => 0, 'deleted' => 0],
        'applicationAttachments' => ['matched' => 0, 'deleted' => 0],
        'applicationAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
        'comments' => ['matched' => 0, 'deleted' => 0],
        'commentAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
        'unclaimedAttachmentUploads' => ['matched' => 0, 'deleted' => 0],
        'unclaimedAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
        'orphanedApplicantProfiles' => ['matched' => 0, 'deleted' => 0],
      ],
      'unclaimedAttachmentUploads' => [
        'unclaimedAttachmentUploads' => ['matched' => 0, 'deleted' => 0],
        'unclaimedAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
      ],
      'unconfirmedApplications' => [
        'unconfirmedApplications' => ['matched' => 0, 'deleted' => 0],
        'applicationAttachments' => ['matched' => 0, 'deleted' => 0],
        'applicationAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
        'comments' => ['matched' => 0, 'deleted' => 0],
        'commentAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
      ],
      'policies' => [],
      'errors' => [],
    ];
  }

  /**
   * Reads the global fallback retention days.
   */
  private function globalRetentionDays(): int {
    $days = $this->configFactory
      ->get('beantragt_application.settings')
      ->get('data_retention_days');
    return self::normalizeRetentionDays($days) ?? 0;
  }

  /**
   * Reads the unconfirmed application purge window in hours.
   */
  private function confirmationPurgeHours(): int {
    $hours = $this->configFactory
      ->get('beantragt_application.settings')
      ->get('confirmation_purge_hours');
    if (is_string($hours) && preg_match('/^\d+$/', trim($hours)) === 1) {
      $hours = (int) trim($hours);
    }
    return is_int($hours) ? max(1, min(87600, $hours)) : 168;
  }

  /**
   * Loads jurisdiction-specific retention policies.
   *
   * @return array<int, array<string, mixed>>
   *   Policies keyed by jurisdiction group ID.
   */
  private function jurisdictionPolicies(int $defaultDays): array {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return [];
    }

    $bundle = (string) ($this->configFactory
      ->get('beantragt_group.settings')
      ->get('jurisdiction_group_type') ?: 'jur');
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $ids = $groupStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->sort('id', 'ASC')
      ->execute();
    if (empty($ids)) {
      return [];
    }

    $policies = [];
    foreach ($groupStorage->loadMultiple($ids) as $group) {
      if (!$group instanceof GroupInterface) {
        continue;
      }
      $normalized = self::retentionDaysFromConfig($this->readJurisdictionConfig($group), $defaultDays);
      $days = $normalized['days'];
      $policies[(int) $group->id()] = [
        'jurisdictionId' => (int) $group->id(),
        'jurisdictionLabel' => $group->label(),
        'jurisdictionSlug' => $this->fieldString($group, 'field_slug'),
        'retentionDays' => $days,
        'source' => $normalized['source'],
        'disabled' => $days <= 0,
      ];
    }

    return $policies;
  }

  /**
   * Loads form schemas with explicit retention overrides.
   *
   * @return array<int, array<string, mixed>>
   *   Override metadata keyed by form schema entity ID.
   */
  private function schemaRetentionOverrides(): array {
    if (!$this->entityTypeManager->hasDefinition('form_schema')) {
      return [];
    }

    $schemaStorage = $this->entityTypeManager->getStorage('form_schema');
    $ids = $schemaStorage->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->execute();
    if (empty($ids)) {
      return [];
    }

    $overrides = [];
    foreach ($schemaStorage->loadMultiple($ids) as $schema) {
      if (!$schema instanceof FieldableEntityInterface) {
        continue;
      }

      $schemaId = (int) $schema->id();
      if ($schemaId <= 0) {
        continue;
      }

      $days = self::schemaRetentionOverrideDays($this->readSchemaDefinition($schema));
      if ($days === NULL) {
        continue;
      }

      $overrides[$schemaId] = [
        'schemaId' => $schemaId,
        'schemaLabel' => $schema->label(),
        'schemaUuid' => $schema->uuid(),
        'retentionDays' => $days,
        'source' => 'schema',
        'disabled' => $days <= 0,
      ];
    }

    return $overrides;
  }

  /**
   * Builds schema-specific policies for a jurisdiction or unscoped base policy.
   *
   * @param array<string, mixed> $basePolicy
   *   Jurisdiction or unscoped inherited policy.
   * @param array<int, array<string, mixed>> $schemaOverrides
   *   Schema override metadata keyed by form schema entity ID.
   *
   * @return array<int, array<string, mixed>>
   *   Policies keyed by form schema entity ID.
   */
  private function schemaPoliciesForBase(array $basePolicy, array $schemaOverrides): array {
    $policies = [];
    foreach ($schemaOverrides as $schemaId => $override) {
      $policies[$schemaId] = [
        'jurisdictionId' => $basePolicy['jurisdictionId'],
        'jurisdictionLabel' => $basePolicy['jurisdictionLabel'],
        'jurisdictionSlug' => $basePolicy['jurisdictionSlug'],
        'schemaId' => $override['schemaId'],
        'schemaLabel' => $override['schemaLabel'],
        'schemaUuid' => $override['schemaUuid'],
        'retentionDays' => $override['retentionDays'],
        'source' => $override['source'],
        'disabled' => $override['disabled'],
      ];
    }
    return $policies;
  }

  /**
   * Returns valid organisation overrides grouped by their jurisdiction.
   *
   * @param array<int, array<string, mixed>> $jurisdictions
   *   Existing jurisdiction policies.
   *
   * @return array<int, list<array<string, mixed>>>
   *   Organisation policies keyed by their jurisdiction, or 0 if unscoped.
   */
  private function organisationPolicies(array $jurisdictions): array {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('group');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'org')
      ->sort('id', 'ASC')
      ->execute();
    $policies = [];
    foreach ($storage->loadMultiple($ids) as $group) {
      if (!$group instanceof GroupInterface) {
        continue;
      }

      $days = $this->organisationRetentionDays($group);
      $jurisdictionId = $this->referencedId($group, 'field_jurisdiction');
      if ($days === NULL || ($jurisdictionId > 0 && !isset($jurisdictions[$jurisdictionId]))) {
        continue;
      }

      $policies[$jurisdictionId][] = array_replace($jurisdictions[$jurisdictionId] ?? [
        'jurisdictionId' => NULL,
        'jurisdictionLabel' => 'Unscoped applications',
        'jurisdictionSlug' => NULL,
      ], [
        'organisationId' => (int) $group->id(),
        'retentionDays' => $days,
        'source' => 'organisation',
        'disabled' => $days <= 0,
      ]);
    }
    return $policies;
  }

  /**
   * Resolves the nearest organisation override, including a disabled policy.
   */
  private function organisationRetentionDays(GroupInterface $group): ?int {
    if ($group->bundle() !== 'org') {
      return NULL;
    }

    $days = $this->explicitOrganisationRetentionDays($group);
    if ($days !== NULL || $this->orgHierarchy === NULL) {
      return $days;
    }

    $storage = $this->entityTypeManager->getStorage('group');
    foreach ($this->orgHierarchy->getAncestorIds((int) $group->id()) as $id) {
      $ancestor = $storage->load($id);
      if ($ancestor instanceof GroupInterface) {
        $days = $this->explicitOrganisationRetentionDays($ancestor);
        if ($days !== NULL) {
          return $days;
        }
      }
    }
    return NULL;
  }

  /**
   * Reads the override stored directly on an organisation.
   */
  private function explicitOrganisationRetentionDays(GroupInterface $group): ?int {
    return $group->hasField('field_retention_days')
      ? self::normalizeRetentionDays($group->getUntranslated()->get('field_retention_days')->value ?? NULL)
      : NULL;
  }

  /**
   * Processes one jurisdiction or fallback retention policy.
   */
  private function processPolicy(array $policy, int $now, bool $dryRun, int $limit, array &$dryRunApplicationIds): array {
    $policyReport = [
      'jurisdictionId' => $policy['jurisdictionId'],
      'jurisdictionLabel' => $policy['jurisdictionLabel'],
      'jurisdictionSlug' => $policy['jurisdictionSlug'],
      'organisationId' => $policy['organisationId'] ?? NULL,
      'schemaId' => $policy['schemaId'] ?? NULL,
      'schemaLabel' => $policy['schemaLabel'] ?? NULL,
      'schemaUuid' => $policy['schemaUuid'] ?? NULL,
      'retentionDays' => $policy['retentionDays'],
      'source' => $policy['source'],
      'disabled' => $policy['disabled'],
      'cutoff' => NULL,
      'applications' => ['matched' => 0, 'deleted' => 0],
      'applicationAttachments' => ['matched' => 0, 'deleted' => 0],
      'applicationAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
      'comments' => ['matched' => 0, 'deleted' => 0],
      'commentAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
      'orphanedApplicantProfiles' => ['matched' => 0, 'deleted' => 0],
    ];

    if ($policy['disabled']) {
      return $policyReport;
    }

    $cutoff = $now - ((int) $policy['retentionDays'] * 86400);
    $policyReport['cutoff'] = $cutoff;
    $applicationIds = $this->expiredApplicationIds(
      $policy,
      $cutoff,
      $limit,
    );
    if ($applicationIds === []) {
      return $policyReport;
    }

    $applicationStorage = $this->entityTypeManager->getStorage('application');
    $applications = $applicationStorage->loadMultiple($applicationIds);
    $ownerUids = $this->ownerUids($applications);

    $policyReport['applications']['matched'] = count($applications);
    // Count first. Actual dependent deletion runs from the application's
    // predelete hook only after the entity has acquired its delivery lock.
    $dependentReport = $this->deleteApplicationDependents($applicationIds, TRUE);

    if (!$dryRun) {
      $applicationStorage->delete($applications);
      $policyReport['applications']['deleted'] = count($applications);
      $this->markMatchedDependentsDeleted($dependentReport);
    }
    $this->mergeTopLevelCounts($policyReport, $dependentReport);

    $removedApplicationIds = $dryRun
      ? array_values(array_unique(array_merge($dryRunApplicationIds, $applicationIds)))
      : $applicationIds;
    $profileReport = $this->cleanupOrphanedProfiles($ownerUids, $dryRun, $removedApplicationIds);
    if ($dryRun) {
      $dryRunApplicationIds = $removedApplicationIds;
    }
    $this->mergeTopLevelCounts($policyReport, $profileReport);
    return $policyReport;
  }

  /**
   * Loads expired application IDs for a policy.
   *
   * @return int[]
   *   Application entity IDs.
   */
  private function expiredApplicationIds(array $policy, int $cutoff, int $limit): array {
    if (!$this->entityTypeManager->hasDefinition('application')) {
      return [];
    }

    $query = $this->entityTypeManager->getStorage('application')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmed', TRUE)
      ->condition('created', $cutoff, '<')
      ->sort('created', 'ASC')
      ->range(0, $limit);
    if ($policy['jurisdictionId'] !== NULL) {
      $query->condition('field_jurisdiction', $policy['jurisdictionId']);
    }
    else {
      $query->notExists('field_jurisdiction');
    }

    if (isset($policy['schemaId'])) {
      $query->condition('field_schema', $policy['schemaId']);
    }
    elseif (!empty($policy['excludedSchemaIds'])) {
      $query->condition('field_schema', $policy['excludedSchemaIds'], 'NOT IN');
    }
    if (isset($policy['organisationId'])) {
      $query->condition('field_organisation', $policy['organisationId']);
    }
    elseif (!empty($policy['excludedOrganisationIds'])) {
      $query->condition($query->orConditionGroup()
        ->notExists('field_organisation')
        ->condition('field_organisation', $policy['excludedOrganisationIds'], 'NOT IN'));
    }

    return $this->positiveIds($query->execute());
  }

  /**
   * Deletes expired pre-submission uploads that were never claimed.
   */
  private function deleteExpiredAttachmentUploads(bool $dryRun): array {
    $result = [
      'unclaimedAttachmentUploads' => ['matched' => 0, 'deleted' => 0],
      'unclaimedAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
    ];
    if (!$this->entityTypeManager->hasDefinition('application_attachment')) {
      return $result;
    }

    $attachmentStorage = $this->entityTypeManager->getStorage('application_attachment');
    $attachmentIds = $attachmentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_upload_status', 'uploaded')
      ->condition('field_claim_expires', $this->time->getRequestTime(), '<')
      ->condition('field_scan_status', 'clean')
      ->range(0, 100)
      ->execute();
    if (empty($attachmentIds)) {
      return $result;
    }

    $attachments = $attachmentStorage->loadMultiple($attachmentIds);
    $fileIds = array_values(array_unique([
      ...$this->fileIdsFromEntities($attachments, 'field_file'),
      ...$this->fileIdsFromEntities($attachments, 'field_guard_derivative'),
    ]));
    $result['unclaimedAttachmentUploads']['matched'] = count($attachments);
    $result['unclaimedAttachmentFiles']['matched'] = count($fileIds);
    if ($dryRun) {
      return $result;
    }

    $deleted_attachments = 0;
    $deleted_files = 0;
    foreach ($attachments as $attachment) {
      if (!$attachment instanceof FieldableEntityInterface) {
        continue;
      }
      $attachment_id = (int) $attachment->id();
      $claim_lock_names = $attachment_id > 0
        ? $this->acquireAttachmentClaimLocks($attachment_id)
        : NULL;
      if ($claim_lock_names === NULL) {
        continue;
      }

      $application_lock_name = NULL;
      try {
        $attachmentStorage->resetCache([$attachment_id]);
        $current = $attachmentStorage->load($attachment_id);
        if (!$current instanceof FieldableEntityInterface
          || !$this->isExpiredCleanUploadReservation($current)) {
          continue;
        }

        if ($current->hasField('field_application') && !$current->get('field_application')->isEmpty()) {
          $application_id = (int) ($current->get('field_application')->target_id ?? 0);
          if ($application_id > 0) {
            $application_lock_name = Application::deletionLockName($application_id);
            if (!$this->lock->acquire($application_lock_name, 300.0)) {
              continue;
            }
            $attachmentStorage->resetCache([$attachment_id]);
            $reloaded = $attachmentStorage->load($attachment_id);
            if (!$reloaded instanceof FieldableEntityInterface
              || !$this->isExpiredCleanUploadReservation($reloaded)) {
              continue;
            }
            $current = $reloaded;
          }
        }

        $original_file_ids = $this->fileIdsFromEntities([$current], 'field_file');
        $current_file_ids = array_values(array_unique([
          ...$original_file_ids,
          ...$this->fileIdsFromEntities([$current], 'field_guard_derivative'),
        ]));
        $attachmentStorage->delete([$current]);
        $this->deleteFiles($original_file_ids);
        $deleted_attachments++;
        $deleted_files += count($current_file_ids);
      }
      finally {
        if (is_string($application_lock_name)) {
          $this->lock->release($application_lock_name);
        }
        foreach (array_reverse($claim_lock_names) as $claim_lock_name) {
          $this->lock->release($claim_lock_name);
        }
      }
    }
    $result['unclaimedAttachmentUploads']['deleted'] = $deleted_attachments;
    $result['unclaimedAttachmentFiles']['deleted'] = $deleted_files;
    return $result;
  }

  /**
   * Revalidates one upload reservation after acquiring its claim lock.
   */
  private function isExpiredCleanUploadReservation(FieldableEntityInterface $attachment): bool {
    return (string) ($attachment->get('field_upload_status')->value ?? '') === 'uploaded'
      && (int) ($attachment->get('field_claim_expires')->value ?? 0) < $this->time->getRequestTime()
      && (string) ($attachment->get('field_scan_status')->value ?? '') === 'clean';
  }

  /**
   * Locks both producer namespaces before deleting an expired reservation.
   *
   * Form field IDs are author-defined and cannot safely identify whether the
   * application or comment claim service created a reservation.
   *
   * @return string[]|null
   *   Acquired lock names, or NULL when one namespace is already locked.
   */
  private function acquireAttachmentClaimLocks(int $attachmentId): ?array {
    $lock_names = [
      'beantragt_application.attachment_claim.' . $attachmentId,
      'beantragt_comment.attachment_claim.' . $attachmentId,
    ];
    $acquired = [];
    foreach ($lock_names as $lock_name) {
      if (!$this->lock->acquire($lock_name, 30.0)) {
        foreach (array_reverse($acquired) as $acquired_lock) {
          $this->lock->release($acquired_lock);
        }
        return NULL;
      }
      $acquired[] = $lock_name;
    }
    return $acquired;
  }

  /**
   * Deletes anonymous applications that never completed email confirmation.
   */
  private function deleteExpiredUnconfirmedApplications(bool $dryRun, int $limit): array {
    $result = [
      'unconfirmedApplications' => ['matched' => 0, 'deleted' => 0],
      'applicationAttachments' => ['matched' => 0, 'deleted' => 0],
      'applicationAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
      'comments' => ['matched' => 0, 'deleted' => 0],
      'commentAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
    ];
    if (!$this->entityTypeManager->hasDefinition('application')) {
      return $result;
    }

    $cutoff = $this->time->getRequestTime() - ($this->confirmationPurgeHours() * 3600);
    $applicationStorage = $this->entityTypeManager->getStorage('application');
    $applicationIds = $applicationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmed', FALSE)
      ->condition('created', $cutoff, '<')
      ->sort('created', 'ASC')
      ->range(0, max(1, $limit))
      ->execute();
    $applicationIds = $this->positiveIds($applicationIds);
    if ($applicationIds === []) {
      return $result;
    }

    $applications = $applicationStorage->loadMultiple($applicationIds);
    $result['unconfirmedApplications']['matched'] = count($applications);
    // Count first. Actual dependent deletion runs from the application's
    // predelete hook only after the entity has acquired its delivery lock.
    $dependentReport = $this->deleteApplicationDependents($applicationIds, TRUE);

    if (!$dryRun) {
      $applicationStorage->delete($applications);
      $result['unconfirmedApplications']['deleted'] = count($applications);
      $this->markMatchedDependentsDeleted($dependentReport);
    }
    $this->mergeTopLevelCounts($result, $dependentReport);

    return $result;
  }

  /**
   * Deletes comments and their attachment files.
   */
  private function deleteApplicationComments(array $applicationIds, bool $dryRun): array {
    $result = [
      'comments' => ['matched' => 0, 'deleted' => 0],
      'commentAttachmentFiles' => ['matched' => 0, 'deleted' => 0],
    ];
    $applicationIds = $this->positiveIds($applicationIds);
    if ($applicationIds === [] || !$this->entityTypeManager->hasDefinition('application_comment')) {
      return $result;
    }

    $commentStorage = $this->entityTypeManager->getStorage('application_comment');
    $commentIds = $commentStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', $applicationIds, 'IN')
      ->execute();
    if (empty($commentIds)) {
      return $result;
    }

    $comments = $commentStorage->loadMultiple($commentIds);
    $fileIds = $this->fileIdsFromEntities($comments, 'field_attachments');
    $result['comments']['matched'] = count($comments);
    $result['commentAttachmentFiles']['matched'] = count($fileIds);
    if ($dryRun) {
      return $result;
    }

    $this->deleteFiles($fileIds);
    $commentStorage->delete($comments);
    $result['comments']['deleted'] = count($comments);
    $result['commentAttachmentFiles']['deleted'] = count($fileIds);
    return $result;
  }

  /**
   * Marks counted dependents as deleted after a successful entity cascade.
   *
   * @param array<string, array<string, int>> $report
   *   Dependent cleanup counters.
   */
  private function markMatchedDependentsDeleted(array &$report): void {
    foreach ($report as &$counts) {
      $counts['deleted'] = $counts['matched'];
    }
    unset($counts);
  }

  /**
   * Deletes applicant profiles that would have no applications left.
   */
  private function cleanupOrphanedProfiles(array $uids, bool $dryRun, array $removedApplicationIds): array {
    $result = [
      'orphanedApplicantProfiles' => ['matched' => 0, 'deleted' => 0],
    ];
    $uids = $this->positiveIds($uids);
    if ($uids === []
      || !$this->entityTypeManager->hasDefinition('application')
      || !$this->entityTypeManager->hasDefinition('applicant')) {
      return $result;
    }

    $applicationStorage = $this->entityTypeManager->getStorage('application');
    $profileStorage = $this->entityTypeManager->getStorage('applicant');
    $profilesToDelete = [];
    foreach ($uids as $uid) {
      $remainingQuery = $applicationStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid);
      if ($dryRun && $removedApplicationIds !== []) {
        $remainingQuery->condition('id', $this->positiveIds($removedApplicationIds), 'NOT IN');
      }
      $remaining = (int) $remainingQuery->count()->execute();
      if ($remaining > 0) {
        continue;
      }

      $profiles = $profileStorage->loadByProperties(['uid' => $uid]);
      foreach ($profiles as $profile) {
        $profilesToDelete[(int) $profile->id()] = $profile;
      }
    }

    $result['orphanedApplicantProfiles']['matched'] = count($profilesToDelete);
    if (!$dryRun && $profilesToDelete !== []) {
      $profileStorage->delete($profilesToDelete);
      $result['orphanedApplicantProfiles']['deleted'] = count($profilesToDelete);
    }
    return $result;
  }

  /**
   * Reads field_jur_config from a jurisdiction group.
   *
   * @return array<string, mixed>
   *   Decoded jurisdiction config.
   */
  private function readJurisdictionConfig(GroupInterface $group): array {
    $source = $group->getUntranslated();
    if (!$source->hasField('field_jur_config') || $source->get('field_jur_config')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $source->get('field_jur_config')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Reads the JSON definition from a form schema entity.
   *
   * @return array<string, mixed>
   *   Decoded form schema JSON.
   */
  private function readSchemaDefinition(FieldableEntityInterface $schema): array {
    if (!$schema->hasField('field_schema') || $schema->get('field_schema')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $schema->get('field_schema')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Collects unique file IDs from fieldable entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   Entities to inspect.
   * @param string $fieldName
   *   File entity reference field name.
   *
   * @return int[]
   *   Unique file entity IDs.
   */
  private function fileIdsFromEntities(array $entities, string $fieldName): array {
    $fileIds = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof FieldableEntityInterface
        || ($fieldName === 'field_guard_derivative'
          && $entity->getEntityTypeId() !== 'application_attachment')
        || !$entity->hasField($fieldName)
        || $entity->get($fieldName)->isEmpty()) {
        continue;
      }
      foreach ($entity->get($fieldName)->getValue() as $value) {
        $targetId = is_array($value) ? (int) ($value['target_id'] ?? 0) : 0;
        if ($targetId > 0) {
          $fileIds[$targetId] = $targetId;
        }
      }
    }
    return array_values($fileIds);
  }

  /**
   * Deletes file entities.
   *
   * @param int[] $fileIds
   *   File entity IDs.
   */
  private function deleteFiles(array $fileIds): void {
    $fileIds = $this->positiveIds($fileIds);
    if ($fileIds === []) {
      return;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $files = $fileStorage->loadMultiple($fileIds);
    if ($files !== []) {
      $fileStorage->delete($files);
    }
  }

  /**
   * Collects owner UIDs from applications.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $applications
   *   Application entities.
   *
   * @return int[]
   *   Owner user IDs.
   */
  private function ownerUids(array $applications): array {
    $uids = [];
    foreach ($applications as $application) {
      $uid = method_exists($application, 'getOwnerId') ? (int) $application->getOwnerId() : 0;
      if ($uid > 0) {
        $uids[$uid] = $uid;
      }
    }
    return array_values($uids);
  }

  /**
   * Reads a string field from a group.
   */
  private function fieldString(GroupInterface $group, string $fieldName): ?string {
    $source = $group->getUntranslated();
    if (!$source->hasField($fieldName) || $source->get($fieldName)->isEmpty()) {
      return NULL;
    }
    $value = $source->get($fieldName)->value ?? NULL;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Normalizes positive entity IDs.
   *
   * @param iterable<mixed> $ids
   *   Raw IDs.
   *
   * @return int[]
   *   Positive integer IDs.
   */
  private function positiveIds(iterable $ids): array {
    $normalized = [];
    foreach ($ids as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $normalized[$id] = $id;
      }
    }
    return array_values($normalized);
  }

  /**
   * Reads an explicit schema retention override, if present and valid.
   */
  private static function schemaRetentionOverrideDays(array $schema): ?int {
    $retention = $schema['retention'] ?? NULL;
    if (!is_array($retention) || !array_key_exists('dataRetentionDays', $retention)) {
      return NULL;
    }

    return self::normalizeRetentionDays($retention['dataRetentionDays']);
  }

  /**
   * Merges a partial report into the root report counts.
   */
  private function mergeCounts(array &$report, array $partial): void {
    foreach ($partial as $key => $value) {
      if (!is_array($value) || !isset($report['counts'][$key])) {
        continue;
      }
      $report['counts'][$key]['matched'] += (int) ($value['matched'] ?? 0);
      $report['counts'][$key]['deleted'] += (int) ($value['deleted'] ?? 0);
    }
  }

  /**
   * Merges partial entity counts into one policy report.
   */
  private function mergeTopLevelCounts(array &$report, array $partial): void {
    foreach ($partial as $key => $value) {
      if (!is_array($value) || !isset($report[$key])) {
        continue;
      }
      $report[$key]['matched'] += (int) ($value['matched'] ?? 0);
      $report[$key]['deleted'] += (int) ($value['deleted'] ?? 0);
    }
  }

  /**
   * Returns TRUE when the report deleted anything.
   */
  private function reportHasChanges(array $report): bool {
    foreach ($report['counts'] as $count) {
      if ((int) ($count['deleted'] ?? 0) > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reduces a report to the log-safe summary shape.
   */
  private function summaryForLog(array $report): array {
    return [
      'dryRun' => $report['dryRun'],
      'generatedAt' => $report['generatedAt'],
      'globalRetentionDays' => $report['globalRetentionDays'],
      'counts' => $report['counts'],
      'policies' => array_map(static fn (array $policy): array => [
        'jurisdictionId' => $policy['jurisdictionId'],
        'jurisdictionSlug' => $policy['jurisdictionSlug'],
        'organisationId' => $policy['organisationId'],
        'schemaId' => $policy['schemaId'],
        'schemaUuid' => $policy['schemaUuid'],
        'retentionDays' => $policy['retentionDays'],
        'source' => $policy['source'],
        'disabled' => $policy['disabled'],
        'cutoff' => $policy['cutoff'],
        'applications' => $policy['applications'],
      ], $report['policies']),
    ];
  }

}
