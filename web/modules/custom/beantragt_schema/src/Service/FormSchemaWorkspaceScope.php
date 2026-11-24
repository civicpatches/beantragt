<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Service;

use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Exception\WorkspaceCreateDenied;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\user\EntityOwnerInterface;

/**
 * Resolves and validates form schema workspace scope for an account.
 */
final class FormSchemaWorkspaceScope {

  /**
   * Generic denial used before exposing foreign workspace metadata.
   */
  private const UNAUTHORIZED_WORKSPACE_MESSAGE = 'You are not authorized to manage one or more selected workspaces.';

  /**
   * Cases without an organisation would follow the form on their next save.
   */
  public const UNASSIGNED_APPLICATIONS_MESSAGE = 'Applications of this form have no organisation yet.';

  /**
   * Constructs a FormSchemaWorkspaceScope service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?JurisdictionHierarchyResolverInterface $hierarchyResolver = NULL,
  ) {}

  /**
   * Returns the first workspace-scope violation, if any.
   *
   * @throws \Drupal\beantragt_schema\Exception\WorkspaceCreateDenied
   *   When a form or group write targets a hidden or unmanaged workspace.
   */
  public function violationMessage(ContentEntityInterface $schema, AccountInterface $account): ?string {
    if ($schema->isSyncing()) {
      return NULL;
    }

    // The scope fields are supplied by beantragt_group. Keep beantragt_schema
    // usable on its own for schema tooling and its isolated kernel tests.
    if (!$this->entityTypeManager->hasDefinition('group')
      || !$schema->hasField('field_jurisdiction')) {
      return $this->createOwnerViolation($schema, $account);
    }

    $account_is_exempt = $this->accountIsExempt($account);
    // Groups also need a target decision on edits to their form list alone.
    $check_target = $schema->isNew() || $schema->getEntityTypeId() === 'form_schema_group';
    $scope_changed = $check_target || ($schema instanceof FormSchemaInterface && $this->scopeChanged($schema));
    if (!$account_is_exempt && $check_target) {
      $this->assertTargetsVisible($schema, $account);
      if ($schema->isNew() && !$account->hasPermission('create ' . $schema->getEntityTypeId() . ' entities')) {
        throw new WorkspaceCreateDenied(WorkspaceCreateDenied::WRITE_FORBIDDEN);
      }
    }
    elseif (!$account_is_exempt && $scope_changed) {
      $manageable = $this->manageableWorkspaces($this->loadAllActiveWorkspaces(), $account);
      foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
        if (!$this->targetIsNewOrChanged($schema, $field_name)) {
          continue;
        }
        $target_id = $this->fieldTargetId($schema, $field_name);
        if ($target_id > 0 && !isset($manageable[$target_id])) {
          return self::UNAUTHORIZED_WORKSPACE_MESSAGE;
        }
      }
    }

    $jurisdiction_is_empty = $schema->get('field_jurisdiction')->isEmpty();
    $jurisdiction = $this->referencedWorkspace($schema, 'field_jurisdiction');
    if (!$jurisdiction_is_empty
      && (!$jurisdiction instanceof GroupInterface || $jurisdiction->bundle() !== 'jur')) {
      return 'The selected jurisdiction workspace is invalid.';
    }
    if ($jurisdiction instanceof GroupInterface
      && $this->targetIsNewOrChanged($schema, 'field_jurisdiction')
      && !$jurisdiction->isPublished()) {
      return 'The selected jurisdiction workspace is inactive.';
    }

    $organisation_is_empty = !$schema->hasField('field_organisation')
      || $schema->get('field_organisation')->isEmpty();
    $organisation = $this->referencedWorkspace($schema, 'field_organisation');
    if (!$organisation_is_empty
      && (!$organisation instanceof GroupInterface || $organisation->bundle() !== 'org')) {
      return 'The selected organisation workspace is invalid.';
    }
    if ($organisation instanceof GroupInterface
      && (!$jurisdiction instanceof GroupInterface
      || !$organisation->hasField('field_jurisdiction')
      || $organisation->get('field_jurisdiction')->isEmpty()
      || (int) $organisation->get('field_jurisdiction')->target_id !== (int) $jurisdiction->id())) {
      return 'The selected organisation must belong to the selected jurisdiction.';
    }
    if ($organisation instanceof GroupInterface
      && $this->targetIsNewOrChanged($schema, 'field_organisation')
      && !$organisation->isPublished()) {
      return 'The selected organisation workspace is inactive.';
    }

    $organisation_message = $schema instanceof FormSchemaInterface ? $this->organisationChangeViolation($schema) : NULL;
    if ($organisation_message !== NULL) {
      return $organisation_message;
    }

    $legacy_is_empty = !$schema->hasField('field_group')
      || $schema->get('field_group')->isEmpty();
    $legacy = $this->referencedWorkspace($schema, 'field_group');
    if (!$legacy_is_empty
      && (!$legacy instanceof GroupInterface || !in_array($legacy->bundle(), ['jur', 'org'], TRUE))) {
      return 'The selected legacy workspace is invalid.';
    }
    if ($legacy instanceof GroupInterface
      && $this->targetIsNewOrChanged($schema, 'field_group')
      && !$legacy->isPublished()) {
      return 'The selected legacy workspace is inactive.';
    }
    if ($legacy instanceof GroupInterface && $jurisdiction instanceof GroupInterface) {
      $expected_legacy_id = $organisation instanceof GroupInterface
        ? (int) $organisation->id()
        : (int) $jurisdiction->id();
      $explicit_scope_changed = $this->targetIsNewOrChanged($schema, 'field_jurisdiction')
        || $this->targetIsNewOrChanged($schema, 'field_organisation');
      if ((int) $legacy->id() !== $expected_legacy_id
        && ($schema->isNew()
          || $this->targetIsNewOrChanged($schema, 'field_group')
          || !$explicit_scope_changed)) {
        return 'The legacy workspace must match the selected organisation or jurisdiction.';
      }
    }

    if (!$jurisdiction instanceof GroupInterface) {
      return $account_is_exempt
        ? $this->createOwnerViolation($schema, $account)
        : 'A jurisdiction workspace is required.';
    }

    // Existing entities may be resaved by unrelated system workflows. Actor
    // authorization is required only when a scope target actually changes.
    if (!$scope_changed || $account_is_exempt
      || ($organisation instanceof GroupInterface && $this->accountHasGroupAdminRole($organisation, $account))
      || $this->accountCanManageJurisdiction($jurisdiction, $account)) {
      return $this->createOwnerViolation($schema, $account);
    }
    if ($check_target) {
      throw new WorkspaceCreateDenied(WorkspaceCreateDenied::WRITE_FORBIDDEN);
    }
    return $organisation instanceof GroupInterface
      ? 'You are not authorized to manage the selected organisation workspace.'
      : 'You are not authorized to manage the selected jurisdiction workspace.';
  }

  /**
   * Binds authorship to the person even when they may bypass scope checks.
   */
  private function createOwnerViolation(ContentEntityInterface $form, AccountInterface $account): ?string {
    if ($form->isNew() && $form instanceof EntityOwnerInterface && $account->isAuthenticated()
      && !BeantragtRoles::isServiceAccount($account) && (int) $form->getOwnerId() !== (int) $account->id()) {
      return 'The author must be the person creating the form.';
    }
    return NULL;
  }

  /**
   * Hides foreign references before reporting any assignment errors.
   */
  private function assertTargetsVisible(ContentEntityInterface $schema, AccountInterface $account): void {
    $groups = $this->loadAllActiveWorkspaces();
    $visible = $this->manageableWorkspaces($groups, $account);
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $group = $groups[(int) $membership->getGroupId()] ?? NULL;
      if ($group === NULL) {
        continue;
      }
      $visible[(int) $group->id()] = $group;
      // An organisation member also sees its municipality in the catalog.
      if ($group->bundle() === 'org') {
        $parent_id = $this->organisationJurisdictionId($group);
        if (isset($groups[$parent_id])) {
          $visible[$parent_id] = $groups[$parent_id];
        }
      }
    }
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      if ($schema->hasField($field_name) && !$schema->get($field_name)->isEmpty()
        && !isset($visible[$this->fieldTargetId($schema, $field_name)])) {
        throw new WorkspaceCreateDenied(WorkspaceCreateDenied::WORKSPACE_NOT_FOUND);
      }
    }
  }

  /**
   * Returns selectable active workspaces keyed by group entity ID.
   *
   * @return array{jurisdictions: array<int, string>, organisations: array<int, string>, organisation_required_jurisdictions: list<int>, scope_required: bool}
   *   Select options for jurisdiction and organisation entity references.
   */
  public function workspaceOptions(AccountInterface $account): array {
    $groups = $this->loadAllActiveWorkspaces();
    $account_is_exempt = $this->accountIsExempt($account);
    if (!$account_is_exempt) {
      $groups = $this->manageableWorkspaces($groups, $account);
    }

    $options = [
      'jurisdictions' => [],
      'organisations' => [],
      'organisation_required_jurisdictions' => [],
      'scope_required' => !$account_is_exempt,
    ];
    foreach ($groups as $group) {
      if ($group->hasField('status') && !$group->isPublished()) {
        continue;
      }
      $target = match ($group->bundle()) {
        'jur' => 'jurisdictions',
        'org' => 'organisations',
        default => NULL,
      };
      if ($target !== NULL) {
        $label = (string) $group->label();
        if ($group->bundle() === 'org') {
          $jurisdiction_id = $this->organisationJurisdictionId($group);
          $jurisdiction = $groups[$jurisdiction_id] ?? NULL;
          if ($jurisdiction instanceof GroupInterface) {
            $label .= ' (' . $jurisdiction->label() . ')';
          }
        }
        $options[$target][(int) $group->id()] = $label;
      }
    }

    natcasesort($options['jurisdictions']);
    natcasesort($options['organisations']);

    if (!$account_is_exempt) {
      $managed_jurisdiction_ids = $this->managedJurisdictionIds($groups, $account);
      foreach ($groups as $group) {
        if ($group->bundle() === 'jur'
          && !isset($managed_jurisdiction_ids[(int) $group->id()])) {
          $options['organisation_required_jurisdictions'][] = (int) $group->id();
        }
      }
      sort($options['organisation_required_jurisdictions']);
    }

    return $options;
  }

  /**
   * Rejects a first organisation while cases of the form have none.
   *
   * Cases without an organisation copy the form's organisation on their next
   * save, which would let its members read them without anyone deciding so.
   * Applies to every actor.
   */
  private function organisationChangeViolation(FormSchemaInterface $schema): ?string {
    if ($schema->isNew() || !$schema->hasField('field_organisation') || !$this->targetIsNewOrChanged($schema, 'field_organisation')) {
      return NULL;
    }
    $original = $schema->getOriginal() ?? $this->entityTypeManager->getStorage('form_schema')->loadUnchanged($schema->id());
    $previous = $original instanceof FormSchemaInterface ? $this->fieldTargetId($original, 'field_organisation') : 0;
    $next = $this->fieldTargetId($schema, 'field_organisation');
    if ($previous > 0 || $next === 0 || !$this->entityTypeManager->hasDefinition('application')) {
      return NULL;
    }
    $unassigned = $this->entityTypeManager->getStorage('application')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_schema', $schema->id())
      ->notExists('field_organisation')
      ->range(0, 1)
      ->count()
      ->execute();
    return (int) $unassigned > 0 ? self::UNASSIGNED_APPLICATIONS_MESSAGE : NULL;
  }

  /**
   * Returns TRUE for trusted accounts that bypass workspace membership scope.
   */
  public function accountIsExempt(AccountInterface $account): bool {
    return $account->hasPermission('bypass beantragt group scope')
      || BeantragtRoles::isServiceAccount($account)
      // Drush seed and update scripts run without an HTTP session. Their
      // owner and workspace values are explicit, trusted fixture input.
      || (PHP_SAPI === 'cli' && (int) $account->id() === 0);
  }

  /**
   * Returns TRUE when the target reference was added or changed.
   */
  private function targetIsNewOrChanged(ContentEntityInterface $schema, string $fieldName): bool {
    if ($schema->isNew()) {
      return TRUE;
    }

    $original = $schema->getOriginal();
    if (!$original instanceof ContentEntityInterface) {
      $original = $this->entityTypeManager->getStorage($schema->getEntityTypeId())->loadUnchanged($schema->id());
    }
    if (!$original instanceof ContentEntityInterface || !$original->hasField($fieldName)) {
      return TRUE;
    }

    $original_id = $this->fieldTargetId($original, $fieldName);
    $current_id = $this->fieldTargetId($schema, $fieldName);
    return $original_id !== $current_id;
  }

  /**
   * Returns TRUE when any explicit or legacy scope target changed.
   */
  private function scopeChanged(FormSchemaInterface $schema): bool {
    if ($schema->isNew()) {
      return TRUE;
    }
    $original = $schema->getOriginal();
    if (!$original instanceof FormSchemaInterface) {
      $original = $this->entityTypeManager->getStorage('form_schema')->loadUnchanged($schema->id());
    }
    if (!$original instanceof FormSchemaInterface) {
      return TRUE;
    }

    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      if ($this->fieldTargetId($schema, $field_name) !== $this->fieldTargetId($original, $field_name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns one optional entity-reference target ID.
   */
  private function fieldTargetId(ContentEntityInterface $schema, string $field_name): int {
    if (!$schema->hasField($field_name) || $schema->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) ($schema->get($field_name)->target_id ?? 0);
  }

  /**
   * Loads one referenced workspace without applying entity access filtering.
   */
  private function referencedWorkspace(ContentEntityInterface $schema, string $fieldName): ?GroupInterface {
    if (!$schema->hasField($fieldName) || $schema->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $target_id = (int) ($schema->get($fieldName)->target_id ?? 0);
    if ($target_id <= 0 || !$this->entityTypeManager->hasDefinition('group')) {
      return NULL;
    }
    $group = $this->entityTypeManager->getStorage('group')->load($target_id);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Filters workspaces through beantragt_group's canonical admin-role check.
   *
   * Organisation administrators can select their organisation and its parent
   * jurisdiction. Jurisdiction administrators can select that jurisdiction
   * and every organisation rooted in it, matching existing entity writes and
   * the administration workspace catalog.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Active workspace groups keyed by entity ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Manageable workspace groups keyed by entity ID.
   */
  private function manageableWorkspaces(array $groups, AccountInterface $account): array {
    $jurisdictions = [];
    $organisations = [];
    foreach ($groups as $group) {
      if ($group->bundle() === 'jur') {
        $jurisdictions[(int) $group->id()] = $group;
      }
      elseif ($group->bundle() === 'org') {
        $organisations[(int) $group->id()] = $group;
      }
    }

    $managed_jurisdiction_ids = $this->managedJurisdictionIds($jurisdictions, $account);

    $allowed = [];
    foreach (array_keys($managed_jurisdiction_ids) as $jurisdiction_id) {
      if (isset($jurisdictions[$jurisdiction_id])) {
        $allowed[$jurisdiction_id] = $jurisdictions[$jurisdiction_id];
      }
    }
    foreach ($organisations as $organisation) {
      $jurisdiction_id = $this->organisationJurisdictionId($organisation);
      if ($jurisdiction_id <= 0) {
        continue;
      }
      if (!isset($managed_jurisdiction_ids[$jurisdiction_id])
        && !$this->accountHasGroupAdminRole($organisation, $account)) {
        continue;
      }
      $allowed[(int) $organisation->id()] = $organisation;
      if (isset($jurisdictions[$jurisdiction_id])) {
        $allowed[$jurisdiction_id] = $jurisdictions[$jurisdiction_id];
      }
    }

    return $allowed;
  }

  /**
   * Returns jurisdiction IDs managed directly or through an ancestor.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Available workspace groups keyed by entity ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting account.
   *
   * @return array<int, true>
   *   Managed jurisdiction IDs keyed by group entity ID.
   */
  private function managedJurisdictionIds(array $groups, AccountInterface $account): array {
    $jurisdictions = array_filter(
      $groups,
      static fn (GroupInterface $group): bool => $group->bundle() === 'jur',
    );
    $managed = [];
    foreach ($jurisdictions as $jurisdiction) {
      if (!$this->accountHasGroupAdminRole($jurisdiction, $account)) {
        continue;
      }
      $descendant_ids = $this->hierarchyResolver?->getDescendantIds((int) $jurisdiction->id())
        ?? [(int) $jurisdiction->id()];
      foreach ($descendant_ids as $jurisdiction_id) {
        if (isset($jurisdictions[$jurisdiction_id])) {
          $managed[$jurisdiction_id] = TRUE;
        }
      }
    }
    return $managed;
  }

  /**
   * Returns whether an account manages a jurisdiction or one of its ancestors.
   */
  private function accountCanManageJurisdiction(GroupInterface $jurisdiction, AccountInterface $account): bool {
    $groups = $this->loadAllActiveWorkspaces();
    return isset($this->managedJurisdictionIds($groups, $account)[(int) $jurisdiction->id()]);
  }

  /**
   * Uses the shared beantragt_group write-membership decision.
   */
  private function accountHasGroupAdminRole(GroupInterface $group, AccountInterface $account): bool {
    return function_exists('_beantragt_group_account_has_group_admin_role')
      && \_beantragt_group_account_has_group_admin_role($group, $account);
  }

  /**
   * Returns an organisation's direct jurisdiction target ID.
   */
  private function organisationJurisdictionId(GroupInterface $organisation): int {
    if (!$organisation->hasField('field_jurisdiction')
      || $organisation->get('field_jurisdiction')->isEmpty()) {
      return 0;
    }
    return (int) $organisation->get('field_jurisdiction')->target_id;
  }

  /**
   * Loads all active workspaces for a scope-bypass or service account.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Workspace groups keyed by entity ID.
   */
  private function loadAllActiveWorkspaces(): array {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', ['jur', 'org'], 'IN')
      ->condition('status', 1)
      ->execute();
    return array_filter(
      $storage->loadMultiple($ids),
      static fn (object $group): bool => $group instanceof GroupInterface,
    );
  }

}
