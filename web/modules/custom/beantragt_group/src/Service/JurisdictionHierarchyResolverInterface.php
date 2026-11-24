<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

/**
 * Resolves relationships inside the jurisdiction group tree.
 */
interface JurisdictionHierarchyResolverInterface {

  /**
   * Finds the root jurisdiction by traversing field_parent_jurisdiction upward.
   */
  public function getRootJurisdictionId(int $groupId): ?int;

  /**
   * Checks if a jurisdiction has a parent jurisdiction.
   */
  public function isChildJurisdiction(int $groupId): bool;

  /**
   * Returns IDs of all root jurisdictions.
   *
   * @return int[]
   *   Root jurisdiction group IDs.
   */
  public function getAllRootJurisdictionIds(): array;

  /**
   * Gets all descendant jurisdiction IDs including the given ID.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  public function getDescendantIds(int $groupId): array;

  /**
   * Gets jurisdiction IDs covering a whole term/category tree.
   *
   * @return int[]
   *   Jurisdiction group IDs.
   */
  public function getTermJurisdictionIds(int $groupId): array;

  /**
   * Returns TRUE when the candidate jurisdiction equals or descends from scope.
   */
  public function isSameOrDescendantById(int $candidateId, int $scopeId): bool;

}
