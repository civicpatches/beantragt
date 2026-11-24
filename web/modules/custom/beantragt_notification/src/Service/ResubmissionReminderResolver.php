<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Resolves effective resubmission reminder policy values.
 */
final class ResubmissionReminderResolver {

  public const SCOPE_ORG = 'org';
  public const SCOPE_USER = 'user';

  private const DEFAULT_DAYS = 42;
  private const DEFAULT_SCOPE = self::SCOPE_ORG;
  private const DEFAULT_INTERVAL_DAYS = 7;
  private const DEFAULT_MAX_REMINDERS = 0;
  private const MAX_REMINDER_DAYS = 365;

  /**
   * Constructs the resubmission reminder resolver.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves the effective application reminder policy.
   *
   * @return array{days: int, scope: string, daysSource: string, scopeSource: string}
   *   The effective reminder days and recipient scope.
   */
  public function resolvePolicy(ApplicationInterface $application): array {
    $config = $this->configFactory->get('beantragt_workflow.settings');
    return self::policyFromSources(
      $this->readSchemaDefinition($application),
      $this->readJurisdictionConfig($application),
      $config->get('resubmission_days'),
      $config->get('resubmission_scope'),
    );
  }

  /**
   * Resolves the global repeat interval in days.
   */
  public function intervalDays(): int {
    $days = self::normalizeReminderDays(
      $this->configFactory->get('beantragt_workflow.settings')->get('resubmission_interval_days')
    );
    return $days ?? self::DEFAULT_INTERVAL_DAYS;
  }

  /**
   * Resolves the maximum reminder count. A value of 0 means unlimited.
   */
  public function maxReminders(): int {
    $value = $this->configFactory->get('beantragt_workflow.settings')->get('resubmission_max_reminders');
    if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
      $value = (int) trim($value);
    }
    return is_int($value) ? max(0, $value) : self::DEFAULT_MAX_REMINDERS;
  }

  /**
   * Resolves a policy from decoded schema and jurisdiction config sources.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema JSON.
   * @param array<string, mixed> $jurisdictionConfig
   *   Decoded field_jur_config JSON.
   * @param mixed $globalDays
   *   Global reminder days config value.
   * @param mixed $globalScope
   *   Global reminder scope config value.
   *
   * @return array{days: int, scope: string, daysSource: string, scopeSource: string}
   *   The effective reminder days and recipient scope.
   */
  public static function policyFromSources(array $schema, array $jurisdictionConfig, mixed $globalDays, mixed $globalScope): array {
    $policy = [
      'days' => self::normalizeReminderDays($globalDays) ?? self::DEFAULT_DAYS,
      'scope' => self::normalizeScope($globalScope) ?? self::DEFAULT_SCOPE,
      'daysSource' => self::normalizeReminderDays($globalDays) === NULL ? 'default' : 'global',
      'scopeSource' => self::normalizeScope($globalScope) === NULL ? 'default' : 'global',
    ];

    $jurisdiction = $jurisdictionConfig['resubmission'] ?? NULL;
    if (is_array($jurisdiction)) {
      if (array_key_exists('reminderDays', $jurisdiction)) {
        $days = self::normalizeReminderDays($jurisdiction['reminderDays']);
        if ($days !== NULL) {
          $policy['days'] = $days;
          $policy['daysSource'] = 'jurisdiction';
        }
      }
      if (array_key_exists('reminderScope', $jurisdiction)) {
        $scope = self::normalizeScope($jurisdiction['reminderScope']);
        if ($scope !== NULL) {
          $policy['scope'] = $scope;
          $policy['scopeSource'] = 'jurisdiction';
        }
      }
    }

    $resubmission = $schema['resubmission'] ?? NULL;
    if (is_array($resubmission)) {
      if (array_key_exists('reminderDays', $resubmission)) {
        $days = self::normalizeReminderDays($resubmission['reminderDays']);
        if ($days !== NULL) {
          $policy['days'] = $days;
          $policy['daysSource'] = 'schema';
        }
      }
      if (array_key_exists('reminderScope', $resubmission)) {
        $scope = self::normalizeScope($resubmission['reminderScope']);
        if ($scope !== NULL) {
          $policy['scope'] = $scope;
          $policy['scopeSource'] = 'schema';
        }
      }
    }

    return $policy;
  }

  /**
   * Normalizes a reminder day value.
   */
  public static function normalizeReminderDays(mixed $value): ?int {
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

    return $days >= 1 && $days <= self::MAX_REMINDER_DAYS ? $days : NULL;
  }

  /**
   * Normalizes a reminder recipient scope value.
   */
  public static function normalizeScope(mixed $value): ?string {
    return is_string($value) && in_array($value, [self::SCOPE_ORG, self::SCOPE_USER], TRUE)
      ? $value
      : NULL;
  }

  /**
   * Reads the application schema JSON.
   *
   * @return array<string, mixed>
   *   Decoded form schema JSON.
   */
  private function readSchemaDefinition(ApplicationInterface $application): array {
    if (!$application->hasField('field_schema') || $application->get('field_schema')->isEmpty()) {
      return [];
    }

    $schema = $application->get('field_schema')->entity;
    if (!$schema instanceof FieldableEntityInterface
      || !$schema->hasField('field_schema')
      || $schema->get('field_schema')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $schema->get('field_schema')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Reads the application jurisdiction config JSON.
   *
   * @return array<string, mixed>
   *   Decoded field_jur_config JSON.
   */
  private function readJurisdictionConfig(ApplicationInterface $application): array {
    $jurisdiction = $this->resolveJurisdictionGroup($application);
    if (!$jurisdiction instanceof FieldableEntityInterface) {
      return [];
    }

    $source = $jurisdiction->getUntranslated();
    if (!$source->hasField('field_jur_config')
      || $source->get('field_jur_config')->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $source->get('field_jur_config')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Resolves the jurisdiction group for an application.
   */
  private function resolveJurisdictionGroup(ApplicationInterface $application): ?GroupInterface {
    $direct = $this->referencedGroup($application, 'field_jurisdiction');
    if ($direct instanceof GroupInterface && $direct->bundle() === 'jur') {
      return $direct;
    }

    $workspace = $this->referencedGroup($application, 'field_organisation')
      ?? $this->referencedGroup($application, 'field_group');
    if (!$workspace instanceof GroupInterface) {
      return NULL;
    }
    if ($workspace->bundle() === 'jur') {
      return $workspace;
    }

    return $this->referencedGroup($workspace, 'field_jurisdiction');
  }

  /**
   * Reads a referenced group from an entity reference field.
   */
  private function referencedGroup(FieldableEntityInterface $entity, string $fieldName): ?GroupInterface {
    if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $field = $entity->get($fieldName);
    $referenced = $field->entity ?? NULL;
    if ($referenced instanceof GroupInterface) {
      return $referenced;
    }

    $targetId = (int) ($field->target_id ?? 0);
    if ($targetId <= 0) {
      return NULL;
    }

    $loaded = $this->entityTypeManager->getStorage('group')->load($targetId);
    return $loaded instanceof GroupInterface ? $loaded : NULL;
  }

}
