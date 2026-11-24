<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;

/**
 * Builds entity queries with a fail-closed tenant scoping net.
 */
class TenantScopedEntityQuery {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * Creates a query scoped to the current account's tenant memberships.
   */
  public function getQuery(string $entityTypeId, AccountInterface $account, bool $emailConfirmed = TRUE): QueryInterface {
    $query = $this->entityTypeManager
      ->getStorage($entityTypeId)
      ->getQuery();
    return $this->scopeQuery($query, $entityTypeId, $account, $emailConfirmed);
  }

  /**
   * Applies the getQuery() scope to a query the caller constructed.
   *
   * Aggregate callers compile their own query object; this keeps their scope
   * identical to the one every staff list query receives.
   */
  public function scopeQuery(QueryInterface $query, string $entityTypeId, AccountInterface $account, bool $emailConfirmed = TRUE): QueryInterface {
    $query->accessCheck(FALSE);
    $this->applyConfirmedApplicationScope($query, $entityTypeId, $emailConfirmed);
    $this->applyAccountScope($query, $entityTypeId, $account);
    return $query;
  }

  /**
   * Applies the current account's tenant memberships to an entity query.
   */
  public function applyAccountScope(QueryInterface $query, string $entityTypeId, AccountInterface $account): void {
    if ($this->accountCanBypassTenantScope($account)) {
      return;
    }

    $fields = $this->scopeFields($entityTypeId);
    if ($fields === []) {
      $this->denyAll($query);
      return;
    }

    $memberships = $this->accountMembershipScopes($account);
    $organisation_ids = $memberships['organisation_ids'];
    $jurisdiction_ids = $memberships['jurisdiction_ids'];
    $admin_jurisdiction_ids = $memberships['jurisdiction_admin_ids'];
    if ($entityTypeId !== 'application') {
      $organisation_ids = $this->positiveUniqueIds(array_merge(
        $organisation_ids,
        $this->organisationIdsForJurisdictions($admin_jurisdiction_ids),
      ));
    }

    $scope_group = $query->orConditionGroup();
    $has_condition = FALSE;

    if (isset($fields['field_organisation']) && $organisation_ids !== []) {
      $scope_group->condition('field_organisation', $organisation_ids, 'IN');
      $has_condition = TRUE;
    }
    $legacy_ids = [...$organisation_ids, ...$jurisdiction_ids];
    if (isset($fields['field_group']) && $legacy_ids !== []) {
      $scope_group->condition($this->legacyGroupCondition($query, $fields, $legacy_ids));
      $has_condition = TRUE;
    }
    if (isset($fields['field_jurisdiction']) && $jurisdiction_ids !== []) {
      $scope_group->condition($this->jurisdictionDirectCondition($query, $fields, $jurisdiction_ids));
      $has_condition = TRUE;
    }
    if ($entityTypeId === 'application') {
      foreach ($admin_jurisdiction_ids as $jurisdiction_id) {
        $office_ids = array_diff($this->organisationIdsForJurisdictions([$jurisdiction_id]), $organisation_ids);
        if ($office_ids === []) {
          continue;
        }
        // Mirror matchesRecordedJurisdiction() for inherited access.
        $offices = $query->orConditionGroup()
          ->condition('field_organisation', $office_ids, 'IN')
          ->condition($this->legacyGroupCondition($query, $fields, $office_ids));
        $scope_group->condition($query->andConditionGroup()
          ->condition('field_jurisdiction', $jurisdiction_id)
          ->condition($offices));
        $has_condition = TRUE;
      }
    }

    if (!$has_condition) {
      $this->denyAll($query);
      return;
    }

    $query->condition($scope_group);
  }

  /**
   * Applies an already-authorized workspace scope to an entity query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   Entity query to scope.
   * @param string $entityTypeId
   *   Entity type ID.
   * @param \Drupal\group\Entity\GroupInterface $jurisdiction
   *   Jurisdiction workspace.
   * @param \Drupal\group\Entity\GroupInterface|null $organisation
   *   Optional organisation workspace.
   * @param bool $includeUnconfirmedApplications
   *   Whether unconfirmed applications remain visible to this query.
   */
  public function applyWorkspaceScope(
    QueryInterface $query,
    string $entityTypeId,
    GroupInterface $jurisdiction,
    ?GroupInterface $organisation = NULL,
    bool $includeUnconfirmedApplications = FALSE,
  ): void {
    if (!$includeUnconfirmedApplications) {
      $this->applyConfirmedApplicationScope($query, $entityTypeId);
    }
    $fields = $this->scopeFields($entityTypeId);
    if ($fields === []) {
      $this->denyAll($query);
      return;
    }

    if ($organisation instanceof GroupInterface) {
      $this->applyOrganisationWorkspaceScope($query, $fields, (int) $organisation->id());
      return;
    }

    $this->applyJurisdictionWorkspaceScope($query, $fields, (int) $jurisdiction->id());
  }

  /**
   * Applies a single group workspace scope to an entity query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   Entity query to scope.
   * @param string $entityTypeId
   *   Entity type ID.
   * @param \Drupal\group\Entity\GroupInterface $workspace
   *   Selected jurisdiction or organisation workspace.
   * @param bool $includeUnconfirmedApplications
   *   Whether unconfirmed applications remain visible to this query.
   */
  public function applyWorkspaceGroupScope(
    QueryInterface $query,
    string $entityTypeId,
    GroupInterface $workspace,
    bool $includeUnconfirmedApplications = FALSE,
  ): void {
    if ($workspace->bundle() === 'org') {
      $jurisdiction = $this->parentJurisdiction($workspace);
      if (!$jurisdiction instanceof GroupInterface) {
        $this->denyAll($query);
        return;
      }
      $this->applyWorkspaceScope(
        $query,
        $entityTypeId,
        $jurisdiction,
        $workspace,
        $includeUnconfirmedApplications,
      );
      return;
    }

    $this->applyWorkspaceScope(
      $query,
      $entityTypeId,
      $workspace,
      NULL,
      $includeUnconfirmedApplications,
    );
  }

  /**
   * Selects confirmed or unconfirmed application rows, confirmed by default.
   */
  public function applyConfirmedApplicationScope(QueryInterface $query, string $entityTypeId, bool $emailConfirmed = TRUE): void {
    if ($entityTypeId !== 'application' || !$this->entityHasField($entityTypeId, 'field_email_confirmed')) {
      return;
    }

    $query->condition('field_email_confirmed', $emailConfirmed);
  }

  /**
   * Returns TRUE when an account legitimately bypasses query scoping.
   */
  private function accountCanBypassTenantScope(AccountInterface $account): bool {
    return BeantragtRoles::isServiceAccount($account)
      || $account->hasPermission('bypass beantragt group scope');
  }

  /**
   * Returns available tenant scope fields for an entity type.
   *
   * @return array<string, true>
   *   Scope field names keyed by field name.
   */
  private function scopeFields(string $entityTypeId): array {
    $definitions = $this->fieldManager->getFieldStorageDefinitions($entityTypeId);
    $fields = [];
    foreach (['field_organisation', 'field_jurisdiction', 'field_group'] as $field_name) {
      if (isset($definitions[$field_name])) {
        $fields[$field_name] = TRUE;
      }
    }
    return $fields;
  }

  /**
   * Checks whether an entity type exposes a field storage definition.
   */
  private function entityHasField(string $entityTypeId, string $fieldName): bool {
    $definitions = $this->fieldManager->getFieldStorageDefinitions($entityTypeId);
    return isset($definitions[$fieldName]);
  }

  /**
   * Resolves tenant membership IDs for an account.
   *
   * @return array{organisation_ids:int[], jurisdiction_ids:int[], jurisdiction_admin_ids:int[]}
   *   Membership scope IDs.
   */
  private function accountMembershipScopes(AccountInterface $account): array {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [
        'organisation_ids' => [],
        'jurisdiction_ids' => [],
        'jurisdiction_admin_ids' => [],
      ];
    }

    $relationship_storage = $this->entityTypeManager->getStorage('group_relationship');
    $ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $uid)
      ->execute();
    if ($ids === []) {
      return [
        'organisation_ids' => [],
        'jurisdiction_ids' => [],
        'jurisdiction_admin_ids' => [],
      ];
    }

    $organisation_ids = [];
    $jurisdiction_ids = [];
    $jurisdiction_admin_ids = [];
    $group_storage = $this->entityTypeManager->getStorage('group');
    foreach ($relationship_storage->loadMultiple($ids) as $relationship) {
      if (!$relationship instanceof GroupMembershipInterface
        || !$relationship->hasField('gid')
        || $relationship->get('gid')->isEmpty()) {
        continue;
      }
      $group_id = (int) ($relationship->get('gid')->target_id ?? 0);
      $group = $group_id > 0 ? $group_storage->load($group_id) : NULL;
      if (!$group instanceof GroupInterface) {
        continue;
      }

      if ($group->bundle() === 'org') {
        $organisation_ids[] = (int) $group->id();
        continue;
      }
      if ($group->bundle() !== 'jur') {
        continue;
      }

      $jurisdiction_ids[] = (int) $group->id();
      if (ApplicationCaseScope::hasAdminGroupRole($relationship->getRoles(FALSE))) {
        $jurisdiction_admin_ids[] = (int) $group->id();
      }
    }

    return [
      'organisation_ids' => $this->positiveUniqueIds($organisation_ids),
      'jurisdiction_ids' => $this->positiveUniqueIds($jurisdiction_ids),
      'jurisdiction_admin_ids' => $this->positiveUniqueIds($jurisdiction_admin_ids),
    ];
  }

  /**
   * Resolves organisation IDs that belong to jurisdictions.
   *
   * @param int[] $jurisdictionIds
   *   Jurisdiction group IDs.
   *
   * @return int[]
   *   Organisation group IDs.
   */
  private function organisationIdsForJurisdictions(array $jurisdictionIds): array {
    $jurisdictionIds = $this->positiveUniqueIds($jurisdictionIds);
    if ($jurisdictionIds === []) {
      return [];
    }

    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $jurisdictionIds, 'IN')
      ->execute();
    return $this->positiveUniqueIds($ids);
  }

  /**
   * Adds a workspace organisation condition.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query to constrain.
   * @param array<string, true> $fields
   *   Available tenant fields for the queried entity type.
   * @param int $organisationId
   *   Organisation group ID.
   */
  private function applyOrganisationWorkspaceScope(QueryInterface $query, array $fields, int $organisationId): void {
    if ($organisationId <= 0) {
      $this->denyAll($query);
      return;
    }
    $scope_group = $query->orConditionGroup();
    $has_condition = FALSE;
    if (isset($fields['field_organisation'])) {
      $scope_group->condition('field_organisation', $organisationId);
      $has_condition = TRUE;
    }
    if (isset($fields['field_group'])) {
      $scope_group->condition($this->legacyGroupCondition($query, $fields, [$organisationId]));
      $has_condition = TRUE;
    }
    if (!$has_condition) {
      $this->denyAll($query);
      return;
    }
    $query->condition($scope_group);
  }

  /**
   * Adds a workspace jurisdiction condition.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query to constrain.
   * @param array<string, true> $fields
   *   Available tenant fields for the queried entity type.
   * @param int $jurisdictionId
   *   Jurisdiction group ID.
   */
  private function applyJurisdictionWorkspaceScope(QueryInterface $query, array $fields, int $jurisdictionId): void {
    if ($jurisdictionId <= 0) {
      $this->denyAll($query);
      return;
    }

    $scope_group = $query->orConditionGroup();
    $has_condition = FALSE;
    if (isset($fields['field_jurisdiction'])) {
      $scope_group->condition('field_jurisdiction', $jurisdictionId);
      $has_condition = TRUE;
    }
    if (isset($fields['field_group'])) {
      $legacy_group_ids = array_merge(
        [$jurisdictionId],
        $this->organisationIdsForJurisdictions([$jurisdictionId]),
      );
      $scope_group->condition($this->legacyGroupCondition($query, $fields, $legacy_group_ids));
      $has_condition = TRUE;
    }

    if (!$has_condition) {
      $this->denyAll($query);
      return;
    }

    $query->condition($scope_group);
  }

  /**
   * Builds a jurisdiction-direct account condition.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query used to create the condition group.
   * @param array<string, true> $fields
   *   Available tenant fields for the queried entity type.
   * @param int[] $jurisdictionIds
   *   Allowed jurisdiction IDs.
   */
  private function jurisdictionDirectCondition(QueryInterface $query, array $fields, array $jurisdictionIds): ConditionInterface {
    $condition = $query->andConditionGroup()
      ->condition('field_jurisdiction', $jurisdictionIds, 'IN');
    if (isset($fields['field_organisation'])) {
      $condition->notExists('field_organisation');
    }
    if (isset($fields['field_group'])) {
      $condition->notExists('field_group');
    }
    return $condition;
  }

  /**
   * Builds a legacy field_group-only condition.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query used to create the condition group.
   * @param array<string, true> $fields
   *   Available tenant fields for the queried entity type.
   * @param int[] $groupIds
   *   Allowed group IDs.
   */
  private function legacyGroupCondition(QueryInterface $query, array $fields, array $groupIds): ConditionInterface {
    $condition = $query->andConditionGroup()
      ->condition('field_group', $groupIds, 'IN');
    if (isset($fields['field_organisation'])) {
      $condition->notExists('field_organisation');
    }
    return $condition;
  }

  /**
   * Loads the parent jurisdiction for an organisation group.
   */
  private function parentJurisdiction(GroupInterface $organisation): ?GroupInterface {
    if (!$organisation->hasField('field_jurisdiction') || $organisation->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $jurisdiction = $organisation->get('field_jurisdiction')->entity;
    return $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL;
  }

  /**
   * Forces a scoped query to return no rows.
   */
  private function denyAll(QueryInterface $query): void {
    $query->condition('id', 0);
  }

  /**
   * Normalizes positive numeric IDs and removes duplicates.
   *
   * @param mixed[] $ids
   *   Candidate IDs.
   *
   * @return int[]
   *   Positive unique IDs.
   */
  private function positiveUniqueIds(array $ids): array {
    $normalized = [];
    foreach ($ids as $id) {
      $id = is_numeric($id) ? (int) $id : 0;
      if ($id > 0) {
        $normalized[$id] = $id;
      }
    }
    return array_values($normalized);
  }

}
