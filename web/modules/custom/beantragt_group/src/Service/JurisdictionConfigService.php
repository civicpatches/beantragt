<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Reads and writes top-level sections of the field_jur_config JSON blob.
 *
 * Configuration-as-Content: per-jurisdiction settings live as JSON in the
 * field_jur_config string_long field on the jur group entity (string_long,
 * NOT text_long — text processing would corrupt the JSON). The field is
 * non-translatable, so all reads and writes go through the untranslated
 * (default) entity translation.
 */
class JurisdictionConfigService implements JurisdictionConfigServiceInterface {

  /**
   * The machine name of the JSON config field on jur groups.
   */
  public const CONFIG_FIELD = 'field_jur_config';

  /**
   * JSON encode flags for the stored config blob.
   */
  public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Request-local lock ownership for nested section writes.
   *
   * @var array<string, true>
   */
  private array $heldLocks = [];

  /**
   * {@inheritdoc}
   */
  public function withLockedGroup(GroupInterface $group, callable $callback): mixed {
    $lock_name = $this->lockName($group);
    if (isset($this->heldLocks[$lock_name])) {
      return $callback($group->getUntranslated());
    }

    $this->acquireLock($lock_name);
    try {
      $fresh = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
      if (!$fresh instanceof GroupInterface) {
        throw new \RuntimeException('The jurisdiction could not be reloaded for mutation.');
      }

      $this->heldLocks[$lock_name] = TRUE;
      return $callback($fresh->getUntranslated());
    }
    finally {
      unset($this->heldLocks[$lock_name]);
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSection(GroupInterface $group, string $section): array {
    $config = $this->readConfig($group);
    $value = $config[$section] ?? [];

    return is_array($value) ? $value : [];
  }

  /**
   * {@inheritdoc}
   */
  public function updateSection(GroupInterface $group, string $section, array $values): array {
    return $this->writeSection($group, $section, $values, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeSection(GroupInterface $group, string $section, array $values): array {
    return $this->writeSection($group, $section, $values, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function saveGroup(GroupInterface $group): void {
    $lock_name = $this->lockName($group);
    if (!isset($this->heldLocks[$lock_name])) {
      throw new \LogicException('saveGroup() must be called inside withLockedGroup().');
    }

    $group->getUntranslated()->save();
  }

  /**
   * Writes one section while holding the jurisdiction config lock.
   */
  protected function writeSection(GroupInterface $group, string $section, array $values, bool $merge): array {
    $lock_name = $this->lockName($group);
    $owns_lock = isset($this->heldLocks[$lock_name]);
    if (!$owns_lock) {
      $this->acquireLock($lock_name);
    }

    try {
      // Merge onto the freshest stored blob. The lock protects both updates
      // to different sections and partial updates to the same section.
      $fresh = $owns_lock
        ? $group->getUntranslated()
        : $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
      if (!$fresh instanceof GroupInterface) {
        throw new \RuntimeException('The jurisdiction could not be reloaded for mutation.');
      }
      $config = $this->readConfig($fresh);
      $stored_values = $values;
      if ($merge) {
        $current = $config[$section] ?? [];
        $stored_values = array_replace(is_array($current) ? $current : [], $values);
      }
      $config[$section] = $stored_values;

      $fresh->set(self::CONFIG_FIELD, json_encode($config, self::JSON_FLAGS));
      $fresh->save();

      return $stored_values;
    }
    finally {
      if (!$owns_lock) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Returns the shared lock name for all writers of one jurisdiction config.
   */
  private function lockName(GroupInterface $group): string {
    return 'beantragt_group:jurisdiction_config:' . $group->id();
  }

  /**
   * Acquires the config lock after one bounded contention wait.
   */
  private function acquireLock(string $lock_name): void {
    if ($this->lock->acquire($lock_name, 30.0)) {
      return;
    }

    $this->lock->wait($lock_name, 5);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new \RuntimeException('Could not acquire the jurisdiction config lock.');
    }
  }

  /**
   * Decodes the full config JSON from the default translation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group (any translation).
   *
   * @return array
   *   The decoded config, or an empty array when unset or invalid.
   */
  protected function readConfig(GroupInterface $group): array {
    $source = $group->getUntranslated();
    if ($source->hasField(self::CONFIG_FIELD) && !$source->get(self::CONFIG_FIELD)->isEmpty()) {
      $decoded = json_decode((string) $source->get(self::CONFIG_FIELD)->value, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    return [];
  }

}
