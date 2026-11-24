<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Service;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;

/**
 * Resolves and protects per-workspace workflow status definitions.
 */
class WorkflowStatusManager {

  /**
   * The closed list of delete blockers a client may read, in output order.
   *
   * The same codes deleteBlockers() returns for a saved status: the default
   * status, current or historical use by applications, a status
   * whose transitions lead to it, a form whose workflow lists it. The field
   * delete_blockers and the reason of a refused delete carry only these.
   */
  public const DELETE_BLOCKER_CODES = [
    'default',
    'application_reference',
    'application_revision_reference',
    'transition_reference',
    'form_workflow_reference',
  ];

  /**
   * The form index per tenant, for the current request.
   *
   * Key: the tenant (a jurisdiction, or an organisation without one). Value:
   * machine names the forms list, by the catalogue they serve: "org" holds
   * the forms of an organisation by its id, "scope" the forms without
   * organisation by their field_jurisdiction (else field_group). Built once
   * per tenant and request from field-level reads (no entity loads), so a
   * status list across all groups of a jurisdiction asks the forms once.
   * Reset by every form save or delete (beantragt_workflow_status.module)
   * and before the delete guard reads it. Group saves do not reset it: an
   * organisation moved to another jurisdiction in the same process is seen
   * by the next request (php-fpm builds the service per request) or after
   * the next form save; the delete guard reads the forms fresh anyway.
   *
   * @var array<int, array{org: array<int, array<string, true>>, scope: array<int, array<string, true>>}>
   */
  private array $formIndex = [];

  /**
   * Constructs the workflow status manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface|null $entityFieldManager
   *   The entity field manager; without it the form scan is not scoped by
   *   query (the scope fields are only known through it).
   * @param \Drupal\Core\Database\Connection|null $database
   *   The database; without it the form index loads entities instead of
   *   reading the five columns it needs.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ?EntityFieldManagerInterface $entityFieldManager = NULL,
    protected readonly ?Connection $database = NULL,
  ) {}

  /**
   * Forgets the form index (after a form was saved or deleted).
   */
  public function resetFormWorkflowIndex(): void {
    $this->formIndex = [];
  }

  /**
   * The reason a refused delete carries, readable by the Nuxt proxy.
   *
   * JSON:API emits the access result's reason as errors[0].detail of the
   * 403. The token "[delete_blockers=code,code]" holds codes of
   * DELETE_BLOCKER_CODES only; a 403 without it is another refusal (no
   * permission, no group access).
   *
   * @param string[] $codes
   *   Codes from deleteBlockers().
   */
  public static function deleteBlockerReason(array $codes): string {
    $codes = array_values(array_intersect(self::DELETE_BLOCKER_CODES, $codes));
    // Without a code of the closed list (only the internal missing_id) the
    // refusal carries no token: the client never sees an empty list.
    return $codes === []
      ? 'Workflow status is protected from deletion.'
      : 'Workflow status is protected from deletion. [delete_blockers=' . implode(',', $codes) . ']';
  }

  /**
   * Resolves the default workflow status for a group.
   *
   * @param int $group_id
   *   The owning jurisdiction or organisation group id.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null
   *   The group's default status, or NULL if none is defined.
   */
  public function resolveDefaultStatusForGroup(int $group_id): ?WorkflowStatusInterface {
    if ($group_id <= 0 || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->condition('field_is_default', TRUE)
      ->sort('field_weight', 'ASC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $status = $storage->load(reset($ids));
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Returns reasons why a workflow status must not be deleted.
   *
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface $status
   *   The workflow status.
   *
   * @return string[]
   *   Machine-readable blocker keys.
   */
  public function deleteBlockers(WorkflowStatusInterface $status): array {
    $blockers = [];
    $status_id = (int) $status->id();
    if ($status_id <= 0) {
      return ['missing_id'];
    }

    if ($status->isDefault()) {
      $blockers[] = 'default';
    }

    if ($this->isReferencedByApplications($status_id)) {
      $blockers[] = 'application_reference';
    }

    if ($this->isReferencedByApplicationRevisions($status_id)) {
      $blockers[] = 'application_revision_reference';
    }

    if ($this->isReferencedByAllowedNext($status_id)) {
      $blockers[] = 'transition_reference';
    }

    if ($this->isListedByFormWorkflow($status)) {
      $blockers[] = 'form_workflow_reference';
    }

    return $blockers;
  }

  /**
   * Whether a form's stored workflow still lists the status.
   *
   * Forms name statuses by machine name; the name is resolved in the
   * catalogue of the group an application lands in. A status counts as
   * listed when a form whose applications may use this catalogue (a form of
   * this organisation, or a form without organisation whose
   * field_jurisdiction, else field_group, names this group or the
   * organisation's jurisdiction) names it in the workflow of its
   * current revision or of its latest published revision: as a status, the
   * start status or an end of a transition. Deleting it would leave that
   * form's workflow with an unknown status (the editor answers 422, running
   * applications lose the step).
   */
  public function isListedByFormWorkflow(WorkflowStatusInterface $status): bool {
    $group_id = $status->getGroupId();
    $machine_name = $status->getMachineName();
    if ($group_id === NULL || $machine_name === '') {
      return FALSE;
    }
    return isset($this->listedMachineNames($group_id)[$machine_name]);
  }

  /**
   * The machine names the forms using a group's catalogue list.
   *
   * A form serves the catalogue of its organisation; a form without
   * organisation serves the group its field_jurisdiction (else field_group)
   * names and, when that is a jurisdiction, every organisation of it. Per
   * form the current revision and the latest published one (see
   * isListedByFormWorkflow()).
   *
   * @return array<string, true>
   *   Machine names as keys.
   */
  private function listedMachineNames(int $group_id): array {
    $jurisdictions = $this->jurisdictionIds($group_id);
    $tenant = $jurisdictions[0] ?? $group_id;
    $index = $this->formIndex[$tenant] ??= $this->buildFormIndex($tenant, $group_id);
    $names = ($index['org'][$group_id] ?? []) + ($index['scope'][$group_id] ?? []);
    foreach ($jurisdictions as $jurisdiction) {
      $names += $index['scope'][$jurisdiction] ?? [];
    }
    return $names;
  }

  /**
   * Builds the form index of one tenant.
   *
   * The candidate query over-matches: every form whose organisation,
   * jurisdiction or group field names the tenant, one of its organisations
   * or the asking group. The keying in this method decides, as described in
   * listedMachineNames().
   *
   * @return array{org: array<int, array<string, true>>, scope: array<int, array<string, true>>}
   *   The index.
   */
  private function buildFormIndex(int $tenant, int $group_id): array {
    $index = ['org' => [], 'scope' => []];
    if (!$this->entityTypeManager->hasDefinition('form_schema')) {
      return $index;
    }
    $storage = $this->entityTypeManager->getStorage('form_schema');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $fields = $this->entityFieldManager?->getFieldStorageDefinitions('form_schema') ?? [];
    if (isset($fields['field_organisation'], $fields['field_jurisdiction'], $fields['field_group'])) {
      $keys = array_values(array_unique([$tenant, $group_id, ...$this->organisationIds($tenant)]));
      $query->condition($query->orConditionGroup()
        ->condition('field_organisation', $keys, 'IN')
        ->condition('field_jurisdiction', $keys, 'IN')
        ->condition('field_group', $keys, 'IN'));
    }
    $ids = array_map('intval', array_values($query->execute()));
    if ($ids === []) {
      return $index;
    }
    foreach ($this->formWorkflowRows($storage, $ids) as $row) {
      $names = [];
      foreach ($row['definitions'] as $definition) {
        $names += $this->definitionMachineNames($definition);
      }
      if ($names === []) {
        continue;
      }
      if ($row['organisation'] !== NULL) {
        $index['org'][$row['organisation']] = ($index['org'][$row['organisation']] ?? []) + $names;
        continue;
      }
      $scope = $row['jurisdiction'] ?? $row['group'];
      if ($scope !== NULL) {
        $index['scope'][$scope] = ($index['scope'][$scope] ?? []) + $names;
      }
    }
    return $index;
  }

  /**
   * The organisations of a jurisdiction.
   *
   * @return int[]
   *   Group ids; none when the group field is unknown.
   */
  private function organisationIds(int $jurisdiction): array {
    if (!$this->entityTypeManager->hasDefinition('group')
      || !isset(($this->entityFieldManager?->getFieldStorageDefinitions('group') ?? [])['field_jurisdiction'])) {
      return [];
    }
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $jurisdiction)
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Scope and workflow definitions of forms, without loading them.
   *
   * Per form: its scope references and the workflow definition of its
   * current revision and of its latest published revision. Reads the five
   * columns from the SQL tables (three queries for all forms); an other
   * storage falls back to entity loads.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The form storage.
   * @param int[] $ids
   *   Form ids.
   *
   * @return list<array{organisation: int|null, jurisdiction: int|null, group: int|null, definitions: list<string|null>}>
   *   One row per form.
   */
  private function formWorkflowRows(EntityStorageInterface $storage, array $ids): array {
    $columns = $this->sqlColumns($storage);
    if ($columns === NULL || $this->database === NULL) {
      return $this->loadedFormWorkflowRows($storage, $ids);
    }
    $reference = static fn(mixed $value): ?int => $value === NULL || $value === '' ? NULL : (int) $value;
    $rows = [];
    $current = $this->database->select($columns['base'], 'f')
      ->fields('f', [
        $columns['id'],
        $columns['definition'],
        $columns['organisation'],
        $columns['jurisdiction'],
        $columns['group'],
      ])
      ->condition('f.' . $columns['id'], $ids, 'IN')
      ->execute();
    foreach ($current as $record) {
      $record = (array) $record;
      $rows[(int) $record[$columns['id']]] = [
        'organisation' => $reference($record[$columns['organisation']]),
        'jurisdiction' => $reference($record[$columns['jurisdiction']]),
        'group' => $reference($record[$columns['group']]),
        'definitions' => [$record[$columns['definition']]],
      ];
    }
    if ($rows === []) {
      return [];
    }
    $latest = $this->database->select($columns['revision'], 'r');
    $latest->addField('r', $columns['id'], 'id');
    $latest->addExpression('MAX(r.' . $columns['revision_id'] . ')', 'latest');
    $latest->condition('r.' . $columns['id'], array_keys($rows), 'IN')
      ->condition('r.' . $columns['status'], 'published')
      ->groupBy('r.' . $columns['id']);
    $published = array_map('intval', $latest->execute()->fetchAllKeyed());
    if ($published !== []) {
      $definitions = $this->database->select($columns['revision'], 'r')
        ->fields('r', [$columns['id'], $columns['definition']])
        ->condition('r.' . $columns['revision_id'], array_values($published), 'IN')
        ->execute();
      foreach ($definitions as $record) {
        $record = (array) $record;
        $rows[(int) $record[$columns['id']]]['definitions'][] = $record[$columns['definition']];
      }
    }
    return array_values($rows);
  }

  /**
   * Table and column names of the five form fields, or NULL.
   *
   * NULL unless the storage is SQL and every field sits in the shared base
   * table (current values) and the revision table (revisions).
   *
   * @return array<string, string>|null
   *   Tables "base" and "revision", keys "id" and "revision_id", columns
   *   "definition", "status", "organisation", "jurisdiction", "group".
   */
  private function sqlColumns(EntityStorageInterface $storage): ?array {
    if (!$storage instanceof SqlEntityStorageInterface) {
      return NULL;
    }
    $mapping = $storage->getTableMapping();
    $entity_type = $storage->getEntityType();
    $base = $entity_type->getBaseTable();
    $revision = $entity_type->getRevisionTable();
    $id = $entity_type->getKey('id');
    $revision_id = $entity_type->getKey('revision');
    if (!is_string($base) || !is_string($revision) || !is_string($id) || !is_string($revision_id)) {
      return NULL;
    }
    $columns = [
      'base' => $base,
      'revision' => $revision,
      'id' => $id,
      'revision_id' => $revision_id,
    ];
    $fields = [
      'definition' => ['field_workflow_definition', 'value'],
      'status' => ['field_workflow_status', 'value'],
      'organisation' => ['field_organisation', 'target_id'],
      'jurisdiction' => ['field_jurisdiction', 'target_id'],
      'group' => ['field_group', 'target_id'],
    ];
    try {
      foreach ($fields as $key => [$field, $property]) {
        if ($mapping->getFieldTableName($field) !== $base) {
          return NULL;
        }
        $column = $mapping->getColumnNames($field)[$property] ?? NULL;
        if (!is_string($column)) {
          return NULL;
        }
        $columns[$key] = $column;
      }
    }
    catch (\Throwable) {
      return NULL;
    }
    return $columns;
  }

  /**
   * The same rows as formWorkflowRows(), from loaded entities.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The form storage.
   * @param int[] $ids
   *   Form ids.
   *
   * @return list<array{organisation: int|null, jurisdiction: int|null, group: int|null, definitions: list<string|null>}>
   *   One row per form.
   */
  private function loadedFormWorkflowRows(EntityStorageInterface $storage, array $ids): array {
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $form) {
      if (!$form instanceof FieldableEntityInterface) {
        continue;
      }
      $reference = static fn(string $field): ?int => $form->hasField($field) && !$form->get($field)->isEmpty()
        ? (int) $form->get($field)->target_id
        : NULL;
      $rows[(int) $form->id()] = [
        'organisation' => $reference('field_organisation'),
        'jurisdiction' => $reference('field_jurisdiction'),
        'group' => $reference('field_group'),
        'definitions' => [$this->definitionValue($form)],
      ];
    }
    if ($rows !== [] && $storage instanceof RevisionableStorageInterface) {
      $published = $storage->getQuery()
        ->accessCheck(FALSE)
        ->allRevisions()
        ->condition('id', array_keys($rows), 'IN')
        ->condition('field_workflow_status', 'published')
        ->execute();
      $latest = [];
      foreach ($published as $revision_id => $form_id) {
        $latest[(int) $form_id] = max($latest[(int) $form_id] ?? 0, (int) $revision_id);
      }
      foreach ($storage->loadMultipleRevisions(array_values($latest)) as $revision) {
        if ($revision instanceof FieldableEntityInterface) {
          $rows[(int) $revision->id()]['definitions'][] = $this->definitionValue($revision);
        }
      }
    }
    return array_values($rows);
  }

  /**
   * The stored workflow definition of a form revision.
   */
  private function definitionValue(FieldableEntityInterface $revision): ?string {
    if (!$revision->hasField('field_workflow_definition')) {
      return NULL;
    }
    $value = $revision->get('field_workflow_definition')->value ?? NULL;
    return is_string($value) ? $value : NULL;
  }

  /**
   * The jurisdiction ids a group's catalogue serves jurisdiction forms of.
   *
   * @return int[]
   *   The group itself for a jurisdiction, the parent for an organisation.
   */
  private function jurisdictionIds(int $group_id): array {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return [];
    }
    $group = $this->entityTypeManager->getStorage('group')->load($group_id);
    if (!$group instanceof FieldableEntityInterface) {
      return [];
    }
    if ($group->bundle() === 'jur') {
      return [$group_id];
    }
    if ($group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()) {
      return [(int) $group->get('field_jurisdiction')->target_id];
    }
    return [];
  }

  /**
   * The machine names a stored workflow definition names.
   *
   * As a status, the start status or an end of a transition; none for a
   * workflow in mode "inherit" or without own workflow.
   *
   * @return array<string, true>
   *   Machine names as keys.
   */
  private function definitionMachineNames(mixed $json): array {
    if (!is_string($json) || $json === '') {
      return [];
    }
    $definition = json_decode($json, TRUE);
    if (!is_array($definition) || ($definition['mode'] ?? NULL) === 'inherit') {
      return [];
    }
    $names = [];
    $add = static function (mixed $name) use (&$names): void {
      if (is_string($name) && $name !== '') {
        $names[$name] = TRUE;
      }
    };
    $add($definition['defaultStatus'] ?? NULL);
    foreach (is_array($definition['statuses'] ?? NULL) ? $definition['statuses'] : [] as $entry) {
      $add(is_array($entry) ? ($entry['machineName'] ?? $entry['id'] ?? NULL) : $entry);
    }
    foreach (is_array($definition['transitions'] ?? NULL) ? $definition['transitions'] : [] as $transition) {
      if (is_array($transition)) {
        $add($transition['from'] ?? NULL);
        $add($transition['to'] ?? NULL);
      }
    }
    return $names;
  }

  /**
   * Checks whether applications reference a workflow status.
   */
  public function isReferencedByApplications(int $status_id): bool {
    if ($status_id <= 0 || !$this->entityTypeManager->hasDefinition('application')) {
      return FALSE;
    }

    $ids = $this->entityTypeManager->getStorage('application')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_workflow_status', $status_id)
      ->range(0, 1)
      ->execute();

    return !empty($ids);
  }

  /**
   * Checks whether application revisions reference a workflow status.
   */
  public function isReferencedByApplicationRevisions(int $status_id): bool {
    if ($status_id <= 0 || !$this->entityTypeManager->hasDefinition('application')) {
      return FALSE;
    }

    $ids = $this->entityTypeManager->getStorage('application')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('field_workflow_status', $status_id)
      ->range(0, 1)
      ->execute();

    return !empty($ids);
  }

  /**
   * Checks whether another workflow status references this status as a target.
   */
  public function isReferencedByAllowedNext(int $status_id): bool {
    if ($status_id <= 0 || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return FALSE;
    }

    $ids = $this->entityTypeManager->getStorage('workflow_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_allowed_next', $status_id)
      ->range(0, 1)
      ->execute();

    return !empty($ids);
  }

}
