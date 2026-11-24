<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow_status\Service\WorkflowStatusManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * Resolves the effective application workflow from schema plus org defaults.
 *
 * The organisation owns the status catalogue. A form schema may narrow that
 * catalogue into a workflow override in its field_schema JSON:
 * workflow.defaultStatus, workflow.statuses, workflow.transitions and optional
 * transition attributes. The JSON is intentionally not translatable; it is
 * tenant/form configuration, not UI copy.
 *
 * Which form revision governs the workflow: the form's default
 * revision when it is published, else the latest published revision, else
 * (never published) the intake revision the application was filled in with.
 * Fields, validation and display stay on the intake revision; they are not
 * resolved here. A new published flow therefore reaches running applications
 * at publish, never at a draft save.
 *
 * Freshness: projections (lists, detail) may use the per-instance memo. The
 * enforcement checks in WorkflowManager call refresh() first, so a publish
 * from another process reaches a long-running worker at its next check.
 */
class EffectiveWorkflowResolver {

  /**
   * Review code: the current status is not part of the current workflow.
   */
  public const REVIEW_STATUS_NOT_IN_WORKFLOW = 'status_not_in_workflow';

  /**
   * Workflow source: a published revision of the form.
   */
  public const SOURCE_PUBLISHED = 'published';

  /**
   * Workflow source: the intake revision of a never published form.
   */
  public const SOURCE_INTAKE = 'intake';

  private const MACHINE_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

  /**
   * Published workflow per form and default revision.
   *
   * Keyed "formId:defaultRevisionId". The value describes the governing
   * published revision, or is NULL when the form was never published. This
   * memo never holds an intake workflow.
   *
   * @var array<string, array{revision_id: int, version: int, workflow: array<string, mixed>, definition: string|null}|null>
   */
  private array $published = [];

  /**
   * Intake workflow per form, default revision and schema version.
   *
   * Keyed "formId:defaultRevisionId:schemaVersion". Only used for never
   * published forms, where applications keep the flow of their intake.
   *
   * @var array<string, array{revision_id: int, version: int, workflow: array<string, mixed>, definition: string|null}>
   */
  private array $intake = [];

  /**
   * Constructs the resolver.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly WorkflowStatusManager $statusManager,
  ) {}

  /**
   * Resolves the initial status for a new application.
   */
  public function resolveDefaultStatusForApplication(ApplicationInterface $application, int $group_id): ?WorkflowStatusInterface {
    $workflow = $this->workflowConfig($application);
    $default_machine_name = $this->machineName($workflow['defaultStatus'] ?? NULL);
    if ($default_machine_name !== NULL) {
      $status = $this->loadStatusByMachineName($group_id, $default_machine_name);
      if ($status instanceof WorkflowStatusInterface) {
        return $status;
      }
    }

    return $this->statusManager->resolveDefaultStatusForGroup($group_id);
  }

  /**
   * Resolves allowed transition target ids for an application.
   *
   * @return int[]|null
   *   Target workflow_status ids when the schema overrides transitions. NULL
   *   means callers should use the organisation status graph.
   */
  public function allowedTargetIds(ApplicationInterface $application, WorkflowStatusInterface $from_status): ?array {
    $workflow = $this->workflowConfig($application);
    if (!$this->hasWorkflowOverride($workflow)) {
      // The standard: the organisation graph, but only from a status the
      // standard reaches. A status outside it (a function the form switched
      // off, stored empty) has no transitions: its library edges could lead
      // to an outcome the form does not carry. The review state applies.
      if ($this->isOutsideStandard($workflow, $from_status)) {
        return [];
      }
      return NULL;
    }

    $from_machine_name = $from_status->getMachineName();
    if (!$this->workflowContainsStatus($workflow, $from_machine_name)) {
      return [];
    }

    $group_id = $from_status->getGroupId();
    if ($group_id === NULL) {
      return [];
    }

    $targets = [];
    foreach ($this->transitions($workflow) as $transition) {
      if (($transition['from'] ?? NULL) !== $from_machine_name) {
        continue;
      }
      $target_machine_name = $this->machineName($transition['to'] ?? NULL);
      if ($target_machine_name === NULL || !$this->workflowContainsStatus($workflow, $target_machine_name)) {
        continue;
      }
      $target = $this->loadStatusByMachineName($group_id, $target_machine_name);
      if ($target instanceof WorkflowStatusInterface && $target->getGroupId() === $group_id) {
        $targets[(int) $target->id()] = (int) $target->id();
      }
    }

    return array_values($targets);
  }

  /**
   * Resolves attribute definitions for a transition.
   *
   * @return array<int, array<string, mixed>>
   *   Attribute definitions sorted by order.
   */
  public function transitionAttributes(ApplicationInterface $application, WorkflowStatusInterface $from_status, WorkflowStatusInterface $to_status): array {
    $workflow = $this->workflowConfig($application);
    if (!$this->hasWorkflowOverride($workflow)) {
      return [];
    }

    $from = $from_status->getMachineName();
    $to = $to_status->getMachineName();
    foreach ($this->transitions($workflow) as $transition) {
      if (($transition['from'] ?? NULL) !== $from || ($transition['to'] ?? NULL) !== $to) {
        continue;
      }
      $status_attributes = $this->statusAttributes($workflow, $to);
      $transition_attributes = $transition['attributes'] ?? [];
      if (!is_array($transition_attributes)) {
        $transition_attributes = [];
      }
      return array_values(array_filter(
        $this->normalizeAttributes([...$status_attributes, ...$transition_attributes]),
        static fn(array $attribute): bool => ($attribute['variable'] ?? TRUE) !== FALSE,
      ));
    }

    return [];
  }

  /**
   * Validates submitted transition attributes.
   *
   * @param array<int, array<string, mixed>> $definitions
   *   Attribute definitions for the transition.
   * @param array<string, mixed> $values
   *   Submitted values keyed by attribute code.
   *
   * @return string[]
   *   Human-readable validation messages.
   */
  public function validateTransitionAttributes(array $definitions, array $values): array {
    $errors = [];
    $definitions_by_code = [];
    foreach ($definitions as $definition) {
      $code = $definition['code'] ?? NULL;
      if (!is_string($code) || $code === '') {
        continue;
      }
      $definitions_by_code[$code] = $definition;
    }

    foreach ($values as $code => $_value) {
      if (!isset($definitions_by_code[$code])) {
        $errors[] = sprintf('Workflow attribute "%s" is not allowed for this transition.', $code);
      }
    }

    foreach ($definitions_by_code as $code => $definition) {
      $datatype = is_string($definition['datatype'] ?? NULL)
        ? $definition['datatype']
        : (is_string($definition['type'] ?? NULL) ? $definition['type'] : 'string');

      $value = $values[$code] ?? NULL;
      if (($definition['required'] ?? FALSE) === TRUE && $this->emptyValue($value)) {
        $errors[] = sprintf('Workflow attribute "%s" is required.', $code);
        continue;
      }
      if ($this->emptyValue($value)) {
        continue;
      }

      $allowed_options = $this->allowedOptionKeys($definition);
      switch ($datatype) {
        case 'number':
          if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            $errors[] = sprintf('Workflow attribute "%s" must be numeric.', $code);
          }
          break;

        case 'singlevaluelist':
          if (!is_scalar($value)) {
            $errors[] = sprintf('Workflow attribute "%s" must be a single value.', $code);
            break;
          }
          if ($allowed_options !== [] && !in_array((string) $value, $allowed_options, TRUE)) {
            $errors[] = sprintf('Workflow attribute "%s" contains an invalid option.', $code);
          }
          break;

        case 'multivaluelist':
          if (!is_array($value)) {
            $errors[] = sprintf('Workflow attribute "%s" must be a list.', $code);
            break;
          }
          foreach ($value as $item) {
            if (!is_scalar($item) || ($allowed_options !== [] && !in_array((string) $item, $allowed_options, TRUE))) {
              $errors[] = sprintf('Workflow attribute "%s" contains an invalid option.', $code);
              break;
            }
          }
          break;

        case 'imagelist':
          if (!is_array($value)) {
            $errors[] = sprintf('Workflow attribute "%s" must be a file list.', $code);
          }
          break;

        case 'string':
        case 'text':
        case 'datetime':
        default:
          if (!is_scalar($value)) {
            $errors[] = sprintf('Workflow attribute "%s" must be a scalar value.', $code);
          }
          break;
      }
    }
    return $errors;
  }

  /**
   * Parses the workflow extension of the governing form revision.
   *
   * @return array<string, mixed>
   *   Workflow config, or empty array.
   */
  public function workflowConfig(ApplicationInterface $application): array {
    return $this->resolve($application)['workflow'];
  }

  /**
   * Re-reads the form when another process published a new revision.
   *
   * Drupal keeps loaded entities for the life of the process. One
   * primary-key query reads the current default revision id; when it differs
   * from the cached form object, the form leaves the storage cache and this
   * resolver drops its memo entries for that form.
   */
  public function refresh(ApplicationInterface $application): void {
    $form_id = $this->formId($application);
    if ($form_id === NULL) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('form_schema');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('id', $form_id)
      ->execute();
    $current_revision_id = (int) (array_key_first($ids) ?? 0);
    $cached = $storage->load($form_id);
    $cached_revision_id = $cached instanceof FormSchemaInterface ? (int) $cached->getRevisionId() : 0;
    if ($current_revision_id === $cached_revision_id) {
      return;
    }

    $storage->resetCache([$form_id]);
    $prefix = $form_id . ':';
    foreach (array_keys($this->published) as $key) {
      if (str_starts_with($key, $prefix)) {
        unset($this->published[$key]);
      }
    }
    foreach (array_keys($this->intake) as $key) {
      if (str_starts_with($key, $prefix)) {
        unset($this->intake[$key]);
      }
    }
  }

  /**
   * Returns the id of the form revision the workflow is read from.
   *
   * @return int
   *   The published or intake revision id, 0 without a form.
   */
  public function workflowRevisionId(ApplicationInterface $application): int {
    return $this->resolve($application)['revision_id'];
  }

  /**
   * Describes the governing form revision.
   *
   * @return array{uuid: string, version: int, revision_id: int, source: string}|null
   *   Form UUID, revision version and id and the source (published or
   *   intake); NULL when the application has no form.
   */
  public function workflowSource(ApplicationInterface $application): ?array {
    $resolved = $this->resolve($application);
    if ($resolved['source'] === NULL) {
      return NULL;
    }
    return [
      'uuid' => $resolved['uuid'],
      'version' => $resolved['version'],
      'revision_id' => $resolved['revision_id'],
      'source' => $resolved['source'],
    ];
  }

  /**
   * Whether the governing workflow overrides the organisation graph.
   */
  public function hasOverride(ApplicationInterface $application): bool {
    return $this->hasWorkflowOverride($this->workflowConfig($application));
  }

  /**
   * Returns the raw workflow text of the governing revision.
   *
   * @return string|null
   *   The stored field_workflow_definition when it decodes to an object, else
   *   the JSON of field_schema.workflow; NULL when the workflow is no
   *   override (including mode "inherit" with left-over keys).
   */
  public function workflowDefinition(ApplicationInterface $application): ?string {
    $resolved = $this->resolve($application);
    return $this->hasWorkflowOverride($resolved['workflow']) ? $resolved['definition'] : NULL;
  }

  /**
   * Returns the review code when the application needs a person to look.
   *
   * The code is set iff the current status has a group, the current status
   * is not part of the governing workflow (an override's status list, or for
   * a form without own workflow the statuses the standard reaches), and the
   * current status is not terminal. Terminal statuses are exempt: system
   * changes outside the graph may close an application in a status the form
   * does not list, which needs no action.
   */
  public function reviewCode(ApplicationInterface $application): ?string {
    $status = $this->currentStatus($application);
    if (!$status instanceof WorkflowStatusInterface || $status->getGroupId() === NULL || $status->isTerminal()) {
      return NULL;
    }

    $workflow = $this->workflowConfig($application);
    $outside = $this->hasWorkflowOverride($workflow)
      ? !$this->workflowContainsStatus($workflow, $status->getMachineName())
      : $this->isOutsideStandard($workflow, $status);
    return $outside ? self::REVIEW_STATUS_NOT_IN_WORKFLOW : NULL;
  }

  /**
   * Whether a status lies outside the standard of its group.
   *
   * For a form without own workflow: the statuses reachable from the default
   * status (statusIdsOfWorkflow()). A status without group is never outside.
   *
   * @param array<string, mixed> $workflow
   *   The decoded governing workflow (no override).
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface $status
   *   The status.
   */
  private function isOutsideStandard(array $workflow, WorkflowStatusInterface $status): bool {
    $group_id = $status->getGroupId();
    if ($group_id === NULL || $group_id <= 0) {
      return FALSE;
    }
    return !in_array((int) $status->id(), $this->statusIdsOfWorkflow($workflow, $group_id), TRUE);
  }

  /**
   * Returns the statuses of the governing workflow in the status group.
   *
   * @return int[]|null
   *   Status ids of the current workflow in the application's status group;
   *   NULL = no restriction (no override, or an empty status list).
   */
  public function workflowStatusIds(ApplicationInterface $application): ?array {
    $workflow = $this->workflowConfig($application);
    if (!$this->hasWorkflowOverride($workflow)) {
      return NULL;
    }
    $statuses = $workflow['statuses'] ?? NULL;
    if (!is_array($statuses) || $statuses === []) {
      return NULL;
    }

    $group_id = $this->currentStatus($application)?->getGroupId();
    if ($group_id === NULL) {
      return [];
    }

    $ids = [];
    foreach ($statuses as $status) {
      $machine_name = $this->machineName(is_array($status) ? ($status['machineName'] ?? $status['id'] ?? NULL) : $status);
      if ($machine_name === NULL) {
        continue;
      }
      $entity = $this->loadStatusByMachineName($group_id, $machine_name);
      if ($entity instanceof WorkflowStatusInterface && $entity->getGroupId() === $group_id) {
        $ids[(int) $entity->id()] = (int) $entity->id();
      }
    }
    return array_values($ids);
  }

  /**
   * Returns the statuses of the workflow that governs the application.
   *
   * The answer to "which status entities are part of the workflow that
   * governs this application". Automation that looks for "the status with
   * flag X" asks this set instead of the whole catalogue, so a status the
   * form never switched on is no target (lookup
   * rule):
   * - override with a status list: the statuses of that list in the group;
   * - override without a status list: the whole catalogue of the group, the
   *   same "no restriction" as workflowContainsStatus();
   * - no override: the statuses reachable over field_allowed_next from the
   *   group's default status (and from the form's defaultStatus, if it names
   *   one), the default included. A group without a default status counts
   *   as unrestricted: the whole catalogue.
   *
   * Reads the governing form revision fresh (refresh()), like the
   * enforcement checks. The reachable set is not memoised here, but the
   * edges are read through the entity storage, so a long-running process
   * sees a graph edit of another process only after that storage cache is
   * reset (the same as every status check of WorkflowManager).
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param int|null $group_id
   *   The group whose catalogue is asked; NULL = the group of the current
   *   status.
   *
   * @return int[]
   *   Status ids in that group; empty without a group.
   */
  public function governingStatusIds(ApplicationInterface $application, ?int $group_id = NULL): array {
    $this->refresh($application);
    $group_id ??= $this->currentStatus($application)?->getGroupId();
    if ($group_id === NULL || $group_id <= 0) {
      return [];
    }
    return $this->statusIdsOfWorkflow($this->workflowConfig($application), $group_id);
  }

  /**
   * Returns the statuses of a form's governing workflow in one group.
   *
   * The variant for checks without an application, such as a transfer rule
   * that names the form: the form's published revision governs (the latest
   * published one when the default revision is a draft), a never published
   * form is read from its default revision.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param int $group_id
   *   The group whose catalogue is asked.
   *
   * @return int[]
   *   Status ids in that group, same rules as governingStatusIds().
   */
  public function governingStatusIdsForForm(FormSchemaInterface $form, int $group_id): array {
    if ($group_id <= 0) {
      return [];
    }
    $schema = $this->workflowSchema($form) ?? $form;
    return $this->statusIdsOfWorkflow($this->describe($schema)['workflow'], $group_id);
  }

  /**
   * Whether the form's governing workflow holds a status with the flag.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param int[] $group_ids
   *   The groups an application of the form may land in; TRUE when the
   *   workflow has the status in any of them.
   * @param string $flag
   *   The semantic flag.
   */
  public function formWorkflowHasFlag(FormSchemaInterface $form, array $group_ids, string $flag): bool {
    $storage = $this->entityTypeManager->getStorage('workflow_status');
    foreach ($group_ids as $group_id) {
      $ids = $this->governingStatusIdsForForm($form, (int) $group_id);
      foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $status) {
        if ($status instanceof WorkflowStatusInterface && $status->hasSemanticFlag($flag)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Resolves the status ids of a decoded workflow in one group.
   *
   * @param array<string, mixed> $workflow
   *   The decoded workflow of the governing form revision.
   * @param int $group_id
   *   The group whose catalogue is asked.
   *
   * @return int[]
   *   Status ids, in catalogue order (weight, then id).
   */
  private function statusIdsOfWorkflow(array $workflow, int $group_id): array {
    if (!$this->entityTypeManager->hasDefinition('workflow_status')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->sort('field_weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $catalogue = [];
    foreach ($storage->loadMultiple($ids) as $status) {
      if ($status instanceof WorkflowStatusInterface && $status->getGroupId() === $group_id) {
        $catalogue[(int) $status->id()] = $status;
      }
    }

    if ($this->hasWorkflowOverride($workflow)) {
      return array_keys(array_filter(
        $catalogue,
        fn(WorkflowStatusInterface $status): bool => $this->workflowContainsStatus($workflow, $status->getMachineName()),
      ));
    }

    // No override: the organisation graph, walked from its start.
    $queue = [];
    foreach ($catalogue as $id => $status) {
      if ($status->isDefault()) {
        $queue[] = $id;
      }
    }
    $form_default = $this->machineName($workflow['defaultStatus'] ?? NULL);
    if ($form_default !== NULL) {
      foreach ($catalogue as $id => $status) {
        if ($status->getMachineName() === $form_default) {
          $queue[] = $id;
        }
      }
    }
    if ($queue === []) {
      return array_keys($catalogue);
    }
    $reached = [];
    while ($queue !== []) {
      $id = array_shift($queue);
      if (isset($reached[$id]) || !isset($catalogue[$id])) {
        continue;
      }
      $reached[$id] = TRUE;
      // Terminal statuses have no way out (WorkflowManager), whatever is
      // stored in their field.
      if ($catalogue[$id]->isTerminal()) {
        continue;
      }
      foreach ($catalogue[$id]->getAllowedNextIds() as $next) {
        if (!isset($reached[(int) $next])) {
          $queue[] = (int) $next;
        }
      }
    }
    return array_keys(array_intersect_key($catalogue, $reached));
  }

  /**
   * Returns the statuses a correction may set.
   *
   * The statuses of the governing workflow minus decision statuses and the
   * current status. A correction skips transition and attribute validation,
   * so it must never approve or reject. For a form without own
   * workflow the governing workflow is the standard: the statuses reachable
   * from the default status.
   *
   * @return int[]
   *   Status ids.
   */
  public function correctionTargetIds(ApplicationInterface $application): array {
    $ids = $this->workflowStatusIds($application);
    $workflow = $this->workflowConfig($application);
    if ($ids === NULL && !$this->hasWorkflowOverride($workflow)) {
      $group_id = $this->currentStatus($application)?->getGroupId();
      $ids = $group_id !== NULL && $group_id > 0 ? $this->statusIdsOfWorkflow($workflow, $group_id) : [];
    }
    if ($ids === NULL || $ids === []) {
      return [];
    }

    $current_id = (int) ($application->get('field_workflow_status')->target_id ?? 0);
    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $targets = [];
    foreach ($storage->loadMultiple($ids) as $status) {
      if (!$status instanceof WorkflowStatusInterface
        || (int) $status->id() === $current_id
        || self::isDecisionStatus($status)) {
        continue;
      }
      $targets[] = (int) $status->id();
    }
    return $targets;
  }

  /**
   * Whether a status is a decision (approval or rejection) status.
   *
   * Mirrors the template choice of WorkflowNotificationSubscriber
   * (targetSemanticFlags()): the semantic flags when the status has any,
   * else the legacy machine names. A change of that rule must change both;
   * a unit test ties the two together.
   */
  public static function isDecisionStatus(WorkflowStatusInterface $status): bool {
    $flags = $status->getSemanticFlags();
    if ($flags !== []) {
      return in_array('approved', $flags, TRUE) || in_array('rejected', $flags, TRUE);
    }
    return in_array($status->getMachineName(), ['approved', 'genehmigt', 'rejected', 'abgelehnt'], TRUE);
  }

  /**
   * Resolves the governing workflow, memoised per form revision.
   *
   * @return array{uuid: string, version: int, revision_id: int, source: string|null, workflow: array<string, mixed>, definition: string|null}
   *   The resolved workflow and its revision; source NULL without a form.
   */
  private function resolve(ApplicationInterface $application): array {
    $none = [
      'uuid' => '',
      'version' => 0,
      'revision_id' => 0,
      'source' => NULL,
      'workflow' => [],
      'definition' => NULL,
    ];
    $form = $this->defaultForm($application);
    if (!$form instanceof FormSchemaInterface) {
      return $none;
    }

    $uuid = (string) $form->uuid();
    $base_key = $form->id() . ':' . (int) $form->getRevisionId();
    if (!array_key_exists($base_key, $this->published)) {
      $published = $this->workflowSchema($form);
      $this->published[$base_key] = $published instanceof FormSchemaInterface ? $this->describe($published) : NULL;
    }
    if ($this->published[$base_key] !== NULL) {
      return ['uuid' => $uuid, 'source' => self::SOURCE_PUBLISHED] + $this->published[$base_key];
    }

    $intake_key = $base_key . ':' . $application->getSchemaVersion();
    if (!array_key_exists($intake_key, $this->intake)) {
      $intake = $this->applicationSchema($application, $form);
      $this->intake[$intake_key] = $intake instanceof FormSchemaInterface
        ? $this->describe($intake)
        : ['revision_id' => 0, 'version' => 0, 'workflow' => [], 'definition' => NULL];
    }
    return ['uuid' => $uuid, 'source' => self::SOURCE_INTAKE] + $this->intake[$intake_key];
  }

  /**
   * Loads the form's default revision through the storage.
   *
   * Not through the entity object cached in the reference item, which can be
   * stale after a form save in the same process.
   */
  private function defaultForm(ApplicationInterface $application): ?FormSchemaInterface {
    $form_id = $this->formId($application);
    if ($form_id === NULL) {
      return NULL;
    }
    $form = $this->entityTypeManager->getStorage('form_schema')->load($form_id);
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * Returns the referenced form id, or NULL.
   */
  private function formId(ApplicationInterface $application): ?int {
    if (!$application->hasField('field_schema')) {
      return NULL;
    }
    $form_id = (int) ($application->get('field_schema')->target_id ?? 0);
    return $form_id > 0 ? $form_id : NULL;
  }

  /**
   * Returns the published revision that governs the workflow, or NULL.
   *
   * The default revision when its lifecycle is published (no query), else
   * the latest revision whose lifecycle is published. "Published" means the
   * lifecycle field only; publication scope and the status flag play no
   * role.
   */
  private function workflowSchema(FormSchemaInterface $form): ?FormSchemaInterface {
    if ($form->getSchemaStatus() === 'published') {
      return $form;
    }

    $storage = $this->entityTypeManager->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface || $form->id() === NULL) {
      return NULL;
    }
    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $form->id())
      ->condition('field_workflow_status', 'published')
      ->sort('revision_id', 'DESC')
      ->range(0, 1)
      ->execute();
    $revision_id = array_key_first($revision_ids);
    if ($revision_id === NULL) {
      return NULL;
    }

    $revision = $storage->loadRevision($revision_id);
    return $revision instanceof FormSchemaInterface ? $revision : NULL;
  }

  /**
   * Decodes the workflow of one form revision.
   *
   * @return array{revision_id: int, version: int, workflow: array<string, mixed>, definition: string|null}
   *   Revision id, version, decoded workflow and its raw text.
   */
  private function describe(FormSchemaInterface $schema): array {
    $described = [
      'revision_id' => (int) $schema->getRevisionId(),
      'version' => $schema->getVersion(),
      'workflow' => [],
      'definition' => NULL,
    ];

    $definition = $schema->getWorkflowDefinition();
    if (is_string($definition) && $definition !== '') {
      $decoded_definition = json_decode($definition, TRUE);
      if (is_array($decoded_definition) && !array_is_list($decoded_definition)) {
        $described['workflow'] = $decoded_definition;
        $described['definition'] = $definition;
        return $described;
      }
    }

    $raw_schema = $schema->getSchema();
    if ($raw_schema === NULL || $raw_schema === '') {
      return $described;
    }
    $decoded = json_decode($raw_schema, TRUE);
    $workflow = is_array($decoded) ? ($decoded['workflow'] ?? NULL) : NULL;
    if (is_array($workflow)) {
      $described['workflow'] = $workflow;
      if ($workflow !== [] && !array_is_list($workflow)) {
        $encoded = json_encode($workflow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $described['definition'] = is_string($encoded) ? $encoded : NULL;
      }
    }
    return $described;
  }

  /**
   * Loads the application's current status entity.
   */
  private function currentStatus(ApplicationInterface $application): ?WorkflowStatusInterface {
    if (!$application->hasField('field_workflow_status') || $application->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }
    $status = $application->get('field_workflow_status')->entity ?? NULL;
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Resolves a workflow_status by group and machine name.
   */
  private function loadStatusByMachineName(int $group_id, string $machine_name): ?WorkflowStatusInterface {
    if ($group_id <= 0 || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('workflow_status');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $group_id)
      ->condition('field_machine_name', $machine_name)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $status = $storage->load(reset($ids));
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Checks whether schema workflow should override org transitions.
   */
  private function hasWorkflowOverride(array $workflow): bool {
    if (($workflow['mode'] ?? NULL) === 'inherit') {
      return FALSE;
    }
    return ($workflow['mode'] ?? NULL) === 'override'
      || array_key_exists('statuses', $workflow)
      || array_key_exists('transitions', $workflow);
  }

  /**
   * Resolves the intake revision: the one the application was filled in with.
   */
  private function applicationSchema(ApplicationInterface $application, FormSchemaInterface $schema): ?FormSchemaInterface {
    $schema_version = $application->getSchemaVersion();
    if ($schema_version <= 0 || $schema->getVersion() === $schema_version) {
      return $schema;
    }

    return $this->loadSchemaRevisionByVersion($schema, $schema_version);
  }

  /**
   * Loads the form schema revision matching an application schema version.
   */
  private function loadSchemaRevisionByVersion(FormSchemaInterface $schema, int $schema_version): ?FormSchemaInterface {
    $storage = $this->entityTypeManager->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface || $schema->id() === NULL) {
      return NULL;
    }

    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $schema->id())
      ->condition('field_version', $schema_version)
      ->sort('revision_id', 'DESC')
      ->range(0, 1)
      ->execute();
    $revision_id = array_key_first($revision_ids);
    if ($revision_id === NULL) {
      return NULL;
    }

    $revision = $storage->loadRevision($revision_id);
    return $revision instanceof FormSchemaInterface ? $revision : NULL;
  }

  /**
   * Checks whether a machine name is active in the schema workflow.
   */
  private function workflowContainsStatus(array $workflow, string $machine_name): bool {
    $statuses = $workflow['statuses'] ?? NULL;
    if (!is_array($statuses) || $statuses === []) {
      return TRUE;
    }

    foreach ($statuses as $status) {
      if ($this->machineName(is_array($status) ? ($status['machineName'] ?? $status['id'] ?? NULL) : $status) === $machine_name) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns normalized transitions.
   *
   * @return array<int, array<string, mixed>>
   *   Transitions with valid machine-name endpoints.
   */
  private function transitions(array $workflow): array {
    $transitions = $workflow['transitions'] ?? [];
    if (!is_array($transitions)) {
      return [];
    }

    $normalized = [];
    foreach ($transitions as $transition) {
      if (!is_array($transition)) {
        continue;
      }
      $from = $this->machineName($transition['from'] ?? NULL);
      $to = $this->machineName($transition['to'] ?? NULL);
      if ($from === NULL || $to === NULL || $from === $to) {
        continue;
      }
      $normalized[] = [
        'from' => $from,
        'to' => $to,
        'attributes' => is_array($transition['attributes'] ?? NULL) ? $transition['attributes'] : [],
      ];
    }
    return $normalized;
  }

  /**
   * Returns raw attribute definitions attached to a workflow status.
   *
   * @return array<int, mixed>
   *   Attribute definitions from workflow.statuses.
   */
  private function statusAttributes(array $workflow, string $machine_name): array {
    $statuses = $workflow['statuses'] ?? [];
    if (!is_array($statuses)) {
      return [];
    }

    foreach ($statuses as $status) {
      if (!is_array($status)) {
        continue;
      }
      $status_machine_name = $this->machineName($status['machineName'] ?? $status['id'] ?? NULL);
      if ($status_machine_name !== $machine_name) {
        continue;
      }
      return is_array($status['attributes'] ?? NULL) ? $status['attributes'] : [];
    }

    return [];
  }

  /**
   * Normalizes status/transition attribute definitions.
   *
   * @param array<int, mixed> $attributes
   *   Raw attribute definitions.
   *
   * @return array<int, array<string, mixed>>
   *   Safe attribute definitions.
   */
  private function normalizeAttributes(array $attributes): array {
    $normalized = [];
    foreach ($attributes as $attribute) {
      if (!is_array($attribute)) {
        continue;
      }
      $code = $this->machineName($attribute['code'] ?? NULL);
      if ($code === NULL) {
        continue;
      }
      $datatype = is_string($attribute['datatype'] ?? NULL)
        ? trim($attribute['datatype'])
        : (is_string($attribute['type'] ?? NULL) ? trim($attribute['type']) : 'string');
      $values = is_array($attribute['values'] ?? NULL)
        ? $attribute['values']
        : (is_array($attribute['options'] ?? NULL) ? $attribute['options'] : []);
      $description = is_string($attribute['description'] ?? NULL)
        ? trim($attribute['description'])
        : (is_string($attribute['label'] ?? NULL) ? trim($attribute['label']) : $code);
      $label = is_string($attribute['label'] ?? NULL)
        ? trim($attribute['label'])
        : $description;

      $normalized[] = array_filter(
        [
          'code' => $code,
          'label' => $label,
          'description' => $description,
          'variable' => ($attribute['variable'] ?? TRUE) !== FALSE,
          'type' => $datatype,
          'datatype' => $datatype,
          'required' => ($attribute['required'] ?? FALSE) === TRUE,
          'order' => (int) ($attribute['order'] ?? 0),
          'options' => $values,
          'values' => $values,
          'media_type' => is_string($attribute['media_type'] ?? NULL)
            ? trim($attribute['media_type'])
            : NULL,
          'media_group' => is_string($attribute['media_group'] ?? NULL)
            ? trim($attribute['media_group'])
            : NULL,
          'datatype_description' => is_string($attribute['datatype_description'] ?? NULL)
            ? trim($attribute['datatype_description'])
            : NULL,
          'default_value' => is_scalar($attribute['default_value'] ?? NULL)
            ? $attribute['default_value']
            : NULL,
          'validation' => is_array($attribute['validation'] ?? NULL)
            ? $attribute['validation']
            : [],
          'conditions' => is_array($attribute['conditions'] ?? NULL)
            ? $attribute['conditions']
            : [],
        ],
        static fn(mixed $value): bool => $value !== NULL,
      );
    }

    $by_code = [];
    foreach ($normalized as $definition) {
      $by_code[$definition['code']] = $definition;
    }
    $normalized = array_values($by_code);

    usort($normalized, static fn(array $a, array $b): int => ($a['order'] <=> $b['order']) ?: strcmp($a['code'], $b['code']));
    return $normalized;
  }

  /**
   * Returns a valid machine name, or NULL.
   */
  private function machineName(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    if ($value === '' || !preg_match(self::MACHINE_NAME_PATTERN, $value)) {
      return NULL;
    }
    return $value;
  }

  /**
   * Checks whether a submitted value is empty.
   */
  private function emptyValue(mixed $value): bool {
    return $value === NULL
      || $value === ''
      || (is_array($value) && $value === []);
  }

  /**
   * Returns allowed option keys for a list attribute definition.
   *
   * @param array<string, mixed> $definition
   *   Attribute definition.
   *
   * @return string[]
   *   Allowed option keys.
   */
  private function allowedOptionKeys(array $definition): array {
    $raw_options = is_array($definition['values'] ?? NULL)
      ? $definition['values']
      : (is_array($definition['options'] ?? NULL) ? $definition['options'] : []);
    $keys = [];
    foreach ($raw_options as $key => $option) {
      if (is_array($option)) {
        $option_key = $option['key'] ?? NULL;
        if (is_scalar($option_key)) {
          $keys[] = (string) $option_key;
        }
        continue;
      }
      if (!is_int($key)) {
        $keys[] = (string) $key;
      }
      elseif (is_scalar($option)) {
        $keys[] = (string) $option;
      }
    }
    return array_values(array_unique($keys));
  }

}
