<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Trait;

use Drupal\group\Entity\GroupInterface;

/**
 * Resolves jurisdiction identifiers from slug or numeric ID.
 *
 * Accepts both numeric group IDs (e.g. 3) and URL slugs (e.g. "musterstadt").
 * Slugs are resolved via field_slug on published jurisdiction group entities.
 *
 * Ported from markaspot_group's JurisdictionIdResolverTrait, simplified for
 * Beantragt: the jurisdiction group type is the fixed 'jur' bundle and the
 * role-ID mapping helpers are not needed here.
 *
 * Usage: classes using this trait must provide an entityTypeManager() method,
 * e.g. by extending ControllerBase.
 */
trait JurisdictionIdResolverTrait {

  /**
   * Resolves a jurisdiction value (numeric ID or slug) to a group ID.
   *
   * @param string|int|null $value
   *   The jurisdiction identifier: numeric group ID, slug string, or NULL.
   *
   * @return int|null
   *   The numeric group ID, or NULL if not found or input is empty.
   */
  protected function resolveJurisdictionId(string|int|null $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    // Numeric = direct group ID.
    if (is_numeric($value)) {
      return (int) $value;
    }

    // Validate slug format: lowercase alphanumeric with inner hyphens.
    $slug = strtolower(trim((string) $value));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug)) {
      return NULL;
    }

    // Lookup by slug on published jurisdiction groups.
    $groups = $this->entityTypeManager()->getStorage('group')->loadByProperties([
      'type' => $this->getJurisdictionGroupType(),
      'field_slug' => $slug,
      'status' => 1,
    ]);

    $group = reset($groups);
    return $group ? (int) $group->id() : NULL;
  }

  /**
   * Gets the jurisdiction group type machine name.
   *
   * @return string
   *   The jurisdiction group type machine name.
   */
  protected function getJurisdictionGroupType(): string {
    return 'jur';
  }

  /**
   * Checks whether a group is a jurisdiction group.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the group uses the jurisdiction type.
   */
  protected function isJurisdictionGroup(mixed $group): bool {
    return $group instanceof GroupInterface
      && $group->bundle() === $this->getJurisdictionGroupType();
  }

}
