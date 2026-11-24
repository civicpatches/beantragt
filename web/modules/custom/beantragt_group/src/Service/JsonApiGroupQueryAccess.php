<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Restricts workspace and tenant rows in JSON:API collection queries.
 */
final class JsonApiGroupQueryAccess {

  private const JSONAPI_FILTERABLE_TYPES = ['application', 'form_schema', 'form_schema_group', 'workflow_status'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly RendererInterface $renderer,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Checks the same platform permissions as the group entity view hook.
   */
  public static function isGroupAdministrator(AccountInterface $account): bool {
    return $account->hasPermission('administer group') || $account->hasPermission('administer beantragt groups');
  }

  /**
   * Identifies the additional tenant-scoped collections used by the UI.
   */
  public static function supportsTenantFilter(string $entity_type): bool {
    return in_array($entity_type, self::JSONAPI_FILTERABLE_TYPES, TRUE);
  }

  /**
   * Checks the permissions for the subset constrained by the SQL guard.
   */
  public static function mayFilter(string $entity_type, AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if ($account->hasPermission("administer $entity_type entities")) {
      return TRUE;
    }
    if ($entity_type === 'workflow_status') {
      return $account->hasPermission('manage workflow transitions')
        || $account->hasPermission('view workflow assignments')
        || $account->hasPermission('view published application entities')
        || $account->hasPermission('view unpublished application entities');
    }
    return $account->hasPermission("view published $entity_type entities")
      || $account->hasPermission("view unpublished $entity_type entities");
  }

  /**
   * Intersects each group table alias with the account's visible workspaces.
   */
  public function alter(SelectInterface $query, bool $working_copies): void {
    $entity_type = $query->getMetaData('entity_type');
    if (($working_copies || $query->getMetaData('all_revisions'))
      && ($entity_type === 'group' || self::supportsTenantFilter($entity_type))) {
      // These collections authorize current rows. Revision scope requires
      // checking both historical and current workspace access per revision.
      $query->alwaysFalse();
      return;
    }

    $groups = ['groups', 'groups_field_data'];
    $tables = array_filter($query->getTables(), static fn(array $table): bool =>
      in_array($table['table'], [...$groups, ...self::JSONAPI_FILTERABLE_TYPES], TRUE));
    if ($tables === []) {
      return;
    }

    $scope = $this->staffWorkspaceIds();
    $lookup = $scope[2];
    $bypass = $this->currentUser->hasPermission('bypass beantragt group scope');
    foreach ($tables as $alias => $table) {
      $guard = $query->andConditionGroup();
      if (in_array($table['table'], $groups, TRUE)) {
        if (self::isGroupAdministrator($this->currentUser)) {
          continue;
        }
        $visible = $this->publishedWorkspaces();
        // The service account resolves published workspaces for every tenant.
        if (!$bypass && !BeantragtRoles::isServiceAccount($this->currentUser)) {
          $visible->condition('workspace.id', $lookup ?: [0], 'IN');
        }
        $guard->condition("$alias.id", $visible, 'IN');
        if ($table['table'] === 'groups_field_data') {
          $guard->condition("$alias.status", 1);
        }
      }
      else {
        $this->restrictTenantEntity($guard, $alias, $table['table'], $scope);
      }
      if (count($guard->conditions()) === 1) {
        // A permission bypass can leave only the conjunction metadata.
        continue;
      }
      // A missing optional relationship carries no fields to filter or sort.
      $query->condition($table['join type'] === 'LEFT'
        ? $query->orConditionGroup()->isNull("$alias.id")->condition($guard)
        : $guard);
    }

    // JSON:API captures query cacheability in its render context, including
    // empty results and the count query for pagination.
    if ($this->renderer->hasRenderContext()) {
      $build = [
        '#cache' => [
          'contexts' => ['beantragt_jsonapi_workspace_scope', 'user.permissions', 'user.roles'],
        ],
      ];
      $this->renderer->render($build);
    }
  }

  /**
   * Selects published workspaces irrespective of their translations.
   */
  private function publishedWorkspaces(): SelectInterface {
    $visible = $this->database->select('groups_field_data', 'workspace');
    $visible->addField('workspace', 'id');
    $visible->condition('workspace.type', ['jur', 'org'], 'IN')
      ->condition('workspace.status', 1)
      ->condition('workspace.default_langcode', 1);
    // Group normally constrains only the primary group query. Run its query
    // access hooks on this subquery so joined group fields also respect the
    // account's live Group permissions, including revoked outsider access.
    $visible->addTag('entity_query')->addTag('group_access')
      ->addMetaData('entity_type', 'group')
      ->addMetaData('base_table', 'groups_field_data')
      ->addMetaData('beantragt_group_workspace_subquery', TRUE);
    // Drupal compiles a SELECT used as an IN operand without preparing it.
    if (!$visible->preExecute()) {
      $visible->condition('workspace.id', 0);
    }
    return $visible;
  }

  /**
   * Adds the view and effective workspace predicates for an entity alias.
   *
   * @param \Drupal\Core\Database\Query\ConditionInterface $guard
   *   The independent collection SQL condition for this table alias.
   * @param string $alias
   *   Alias of the entity base table.
   * @param string $type
   *   Supported entity type ID.
   * @param array{int[], int[], int[], int[], int[]} $scope
   *   Workspace IDs from staffWorkspaceIds().
   */
  private function restrictTenantEntity(ConditionInterface $guard, string $alias, string $type, array $scope): void {
    [$jurisdictions, $organisations, , $direct_organisations, $administered] = $scope;
    $account = $this->currentUser;
    if (!self::mayFilter($type, $account)) {
      $guard->condition("$alias.id", 0);
      return;
    }
    $admin = $account->hasPermission("administer $type entities");
    if (!$admin && $type !== 'workflow_status') {
      $statuses = [];
      if ($account->hasPermission("view published $type entities")) {
        $statuses[] = 1;
      }
      if ($account->hasPermission("view unpublished $type entities")) {
        $statuses[] = 0;
      }
      $guard->condition("$alias.status", $statuses, 'IN');
    }
    if ($type === 'application') {
      // A missing value is treated as confirmed for legacy applications.
      $guard->condition($guard->orConditionGroup()
        ->condition("$alias.field_email_confirmed", 1)
        ->isNull("$alias.field_email_confirmed"));
    }
    if ($account->hasPermission('bypass beantragt group scope')) {
      return;
    }
    if ($type === 'form_schema' && BeantragtRoles::isServiceAccount($account)) {
      $guard->condition("$alias.status", 1)
        ->condition("$alias.field_workflow_status", 'published')
        ->condition("$alias.field_publication_scope", 'internet');
      $workspaces = $this->publishedWorkspaces();
      $guard->condition($guard->orConditionGroup()
        ->condition("$alias.field_organisation", clone $workspaces, 'IN')
        ->condition($guard->andConditionGroup()->isNull("$alias.field_organisation")
          ->condition("$alias.field_group", clone $workspaces, 'IN'))
        ->condition($guard->andConditionGroup()->isNull("$alias.field_organisation")
          ->isNull("$alias.field_group")->condition("$alias.field_jurisdiction", $workspaces, 'IN')));
      return;
    }

    $membership_ids = [...$jurisdictions, ...$organisations];
    if ($type === 'workflow_status') {
      $guard->condition("$alias.field_group", $membership_ids ?: [0], 'IN');
      return;
    }
    $organisation_scope = $guard->orConditionGroup()
      ->condition("$alias.field_organisation", ($type === 'application' ? $direct_organisations : $organisations) ?: [0], 'IN');
    $legacy_scope = $guard->orConditionGroup()->condition(
      "$alias.field_group",
      ($type === 'application' ? [...$jurisdictions, ...$direct_organisations] : $membership_ids) ?: [0],
      'IN',
    );
    if ($type === 'application' && $administered !== []) {
      // Inherited access requires the recorded jurisdiction to still be
      // the office's parent. Moving an office does not move its cases.
      $managed = $this->database->select('group__field_jurisdiction', 'case_office');
      $managed->addField('case_office', 'entity_id');
      $managed->condition('case_office.bundle', 'org')
        ->condition('case_office.deleted', 0)
        ->condition('case_office.field_jurisdiction_target_id', $administered, 'IN')
        ->where("[case_office].[field_jurisdiction_target_id] = [$alias].[field_jurisdiction]");
      $organisation_scope->condition("$alias.field_organisation", clone $managed, 'IN');
      $legacy_scope->condition("$alias.field_group", $managed, 'IN');
    }
    $guard->condition($guard->orConditionGroup()
      ->condition($organisation_scope)
      ->condition($guard->andConditionGroup()->isNull("$alias.field_organisation")
        ->condition($legacy_scope))
      ->condition($guard->andConditionGroup()->isNull("$alias.field_organisation")
        ->isNull("$alias.field_group")->condition("$alias.field_jurisdiction", $jurisdictions ?: [0], 'IN')));
  }

  /**
   * Returns memberships, their jurisdiction and administered descendants.
   *
   * @return array{int[], int[], int[], int[], int[]}
   *   Jurisdictions, all organisations, group lookup, direct organisations
   *   and administered jurisdictions.
   */
  public function staffWorkspaceIds(): array {
    if ($this->currentUser->hasPermission('bypass beantragt group scope')
      || !$this->currentUser->isAuthenticated()
      || !BeantragtRoles::isStaffAccount($this->currentUser)) {
      return [[], [], [], [], []];
    }
    $request = $this->requestStack->getCurrentRequest();
    $cache_key = '_beantragt_jsonapi_workspace_ids:' . $this->currentUser->id();
    if ($request?->attributes->has($cache_key)) {
      return $request->attributes->all($cache_key);
    }

    $jurisdictions = $organisations = $parents = $administered = $descendants = [];
    foreach (GroupMembership::loadByUser($this->currentUser) as $membership) {
      $group = $membership->getGroup();
      if (!$group->isPublished() || !in_array($group->bundle(), ['jur', 'org'], TRUE)) {
        continue;
      }
      $id = (int) $group->id();
      if ($group->bundle() === 'org') {
        $organisations[] = $id;
        $parent = (int) $group->get('field_jurisdiction')->target_id;
        if ($parent > 0) {
          $parents[] = $parent;
        }
        continue;
      }
      $jurisdictions[] = $id;
      if (ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE))) {
        $administered[] = $id;
        $descendants = array_merge($descendants, $this->hierarchyResolver->getDescendantIds($id));
      }
    }
    $lookup = [...$jurisdictions, ...$organisations, ...$parents, ...$descendants];
    $direct_organisations = $organisations;
    if ($administered !== []) {
      $managed = $this->entityTypeManager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'org')
        ->condition('field_jurisdiction', $administered, 'IN')
        ->execute();
      $organisations = array_merge($organisations, array_map('intval', $managed));
      $lookup = array_merge($lookup, $organisations);
    }
    $scope = [
      array_unique($jurisdictions),
      array_unique($organisations),
      array_unique($lookup),
      array_unique($direct_organisations),
      array_unique($administered),
    ];
    $request?->attributes->set($cache_key, $scope);
    return $scope;
  }

}
