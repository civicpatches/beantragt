<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\group\Entity\GroupInterface;

/**
 * Reads and writes top-level sections of the field_jur_config JSON blob.
 */
interface JurisdictionConfigServiceInterface {

  /**
   * Runs one settings mutation against a fresh group while holding its lock.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group used to resolve the persistent entity ID.
   * @param callable(\Drupal\group\Entity\GroupInterface): mixed $callback
   *   The mutation to run against the freshly loaded untranslated group.
   *
   * @return mixed
   *   The callback result.
   */
  public function withLockedGroup(GroupInterface $group, callable $callback): mixed;

  /**
   * Returns one top-level section of the jurisdiction config JSON.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group (any translation).
   * @param string $section
   *   The top-level JSON key, e.g. "general", "theme", "languages".
   *
   * @return array
   *   The decoded section data, or an empty array when unset or invalid.
   */
  public function getSection(GroupInterface $group, string $section): array;

  /**
   * Replaces one top-level section of the jurisdiction config JSON.
   *
   * Performs a read-modify-write: all other top-level keys are preserved.
   * Saves the group entity.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group (any translation).
   * @param string $section
   *   The top-level JSON key to replace.
   * @param array $values
   *   The new section data.
   *
   * @return array
   *   The stored section data.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When saving the group entity fails.
   */
  public function updateSection(GroupInterface $group, string $section, array $values): array;

  /**
   * Merges provided values into the freshest stored top-level section.
   *
   * The read, merge, and write are protected by the jurisdiction config lock.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group (any translation).
   * @param string $section
   *   The top-level JSON key to merge.
   * @param array $values
   *   The partial section values.
   *
   * @return array
   *   The stored merged section data.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When saving the group entity fails.
   * @throws \RuntimeException
   *   When the jurisdiction config lock cannot be acquired.
   */
  public function mergeSection(GroupInterface $group, string $section, array $values): array;

  /**
   * Saves discrete group fields inside withLockedGroup().
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group with pending discrete field changes.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   When saving the group entity fails.
   * @throws \LogicException
   *   When called outside withLockedGroup().
   */
  public function saveGroup(GroupInterface $group): void;

}
