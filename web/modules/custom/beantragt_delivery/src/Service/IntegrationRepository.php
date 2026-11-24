<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * Persists scoped integration definitions and explicitly pinned revisions.
 */
final class IntegrationRepository {

  /**
   * Shared revision identity and lifecycle coverage policy.
   */
  private readonly FormRevisionCoverage $revisions;

  private const TABLES = [
    'connection' => 'beantragt_integration_connection',
    'mapping' => 'beantragt_integration_mapping',
    'assignment' => 'beantragt_integration_assignment',
  ];

  private const QUOTAS = ['connection' => 200, 'mapping' => 200, 'assignment' => 400];

  /**
   * When the procedure closes: on the reply, on receipt, or only by staff.
   *
   * The rule on_receipt closes on the technical acceptance of the transfer
   * (DeliveryLogRepository::STATUS_ACCEPTED). The recipient's own
   * acknowledgement needs verified accept-submission events and is a later
   * slice; on_receipt moves to that event once it is available.
   */
  public const CLOSURE_RULES = ['on_response', 'on_receipt', 'manual'];

  /**
   * Allowed follow-up flags per event; the first entry is the default.
   */
  public const FOLLOW_UP_FLAGS = SemanticStatusChanger::FOLLOW_UP_FLAGS;

  /**
   * When a transfer is planned: on submission, on a status, or only by staff.
   */
  public const TRANSFER_TRIGGERS = ['submission', 'status', 'manual'];

  /**
   * Flag of the status the case system's acceptance moves an application to.
   *
   * Only the status trigger relies on it, together with its trigger flag:
   * a person's change into the hand-over status starts the transfer, the
   * acceptance moves the case on to this status. A rule on submission or by
   * staff needs neither: on a form without the function the acceptance
   * moves the case to the standard's in-progress status
   * (SemanticStatusChanger::caseSystemAccepted()).
   */
  private const FLAG_IN_CASE_SYSTEM = 'in_case_system';

  /**
   * Presentation keys holding a German/English text pair.
   */
  public const PRESENTATION_LABELS = [
    'handover_label',
    'response_label',
    'approved_label',
    'rejected_label',
    'filed_label',
  ];

  private const PRESENTATION_DEFAULTS = [
    'handover_label' => ['de' => '', 'en' => ''],
    'response_label' => ['de' => '', 'en' => ''],
    // Staff-facing words for the outcome of a case-system answer, e.g.
    // "Festsetzung" instead of the product's "Bewilligung".
    'approved_label' => ['de' => '', 'en' => ''],
    'rejected_label' => ['de' => '', 'en' => ''],
    'filed_label' => ['de' => '', 'en' => ''],
    'closure_rule' => 'on_response',
    'follow_up' => [
      'approved' => 'approved',
      'rejected' => 'rejected',
      'filed' => 'filed',
      'decision' => 'decision_issued',
      'receipt' => 'filed',
    ],
  ];

  private const WRITABLE = [
    'connection' => [
      'label',
      'adapter',
      'environment',
      'credential_profile',
      'destination_id',
      'service_urn',
      'service_name',
      'contract_key',
      'contract_version',
      'reply_process_standard',
    ],
    'mapping' => ['label', 'contract_key', 'contract_version'],
    'assignment' => [
      'form_schema_uuid',
      'compatible_revisions',
      'service_urn',
      'connection_uuid',
      'mapping_uuid',
      'mapping_version',
      'dispatch_mode',
      'send_attachments',
      'transfer_trigger',
      'trigger_flag',
    ],
  ];

  /**
   * Constructs the repository.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ScopeAccess $scopeAccess,
    private readonly IntegrationCredentialProfiles $profiles,
    private readonly DataContractRegistry $registry,
    private readonly IntegrationRules $rules,
    ?FormRevisionCoverage $revisions = NULL,
    private readonly ?EffectiveWorkflowResolver $workflowResolver = NULL,
  ) {
    $this->revisions = $revisions ?? new FormRevisionCoverage($this->entityTypeManager);
  }

  /**
   * Defines plain tables; active_key supplies a database concurrency fence.
   */
  public static function schema(): array {
    $string = static fn(int $length = 255): array => ['type' => 'varchar', 'length' => $length, 'not null' => TRUE];
    $integer = ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE];
    $json = ['type' => 'text', 'size' => 'big', 'not null' => TRUE];
    $common = [
      'uuid' => $string(36),
      'scope_group_id' => $integer,
      'created' => $integer,
      'changed' => $integer,
      'uid' => $integer,
    ];
    $contract = ['contract_key' => $string(128), 'contract_version' => $string(32)];
    $fields = [
      'connection' => $common + $contract + [
        'label' => $string(),
        'adapter' => $string(32),
        'environment' => $string(16),
        'credential_profile' => $string(128),
        'destination_id' => $string(36),
        'service_urn' => $string(255),
        'service_name' => $string(),
        'reply_process_standard' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
        'status' => $string(16),
        'last_check_result' => $json,
        // Display texts and closure rule, never part of a binding.
        'presentation' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
      ],
      'mapping' => $common + $contract + ['label' => $string()],
      'assignment' => $common + [
        'form_schema_uuid' => $string(36),
        'compatible_revisions' => $json,
        'service_urn' => $string(),
        'connection_uuid' => $string(36),
        'mapping_uuid' => $string(36),
        'mapping_version' => $integer,
        'dispatch_mode' => $string(16),
        'send_attachments' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        // When a transfer is planned; never part of a binding.
        'transfer_trigger' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'submission'],
        'trigger_flag' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE, 'default' => 'hand_over'],
        'status' => $string(16),
        'active_key' => ['type' => 'varchar', 'length' => 64, 'not null' => FALSE],
      ],
    ];
    $schema = [];
    foreach (self::TABLES as $resource => $table) {
      $schema[$table] = [
        'description' => 'Tenant-scoped integration ' . $resource . ' definitions.',
        'fields' => $fields[$resource],
        'primary key' => ['uuid'],
        'indexes' => ['scope' => ['scope_group_id']],
      ];
    }
    $schema[self::TABLES['assignment']]['unique keys']['single_active'] = ['active_key'];
    $schema['beantragt_integration_mapping_version'] = [
      'description' => 'Immutable activated mapping definitions and editable drafts.',
      'fields' => [
        'mapping_uuid' => $string(36),
        'version' => $integer,
        'scope_group_id' => $integer,
        'status' => $string(16),
        'form_schema_uuid' => $string(36),
        'compatible_revisions' => $json,
        'rules' => $json,
        'checksum' => $string(64),
        'created' => $integer,
        'changed' => $integer,
        'uid' => $integer,
      ],
      'primary key' => ['mapping_uuid', 'version'],
      'indexes' => ['scope' => ['scope_group_id']],
    ];
    foreach ($schema as &$definition) {
      foreach ($definition['fields'] as $name => &$field) {
        if ($field['type'] === 'varchar' && !in_array($name, ['label', 'service_name'], TRUE)) {
          $field['type'] = 'varchar_ascii';
        }
      }
      unset($field);
    }
    unset($definition);
    $schema[self::TABLES['connection']]['fields']['last_check_result']['not null'] = FALSE;
    $schema[self::TABLES['assignment']]['indexes'] += [
      'form_status' => ['form_schema_uuid', 'status'],
      'connection' => ['connection_uuid'],
      'mapping_version' => ['mapping_uuid', 'mapping_version'],
    ];
    $schema['beantragt_integration_mapping_version']['indexes']['status'] = ['status'];
    $schema['beantragt_integration_scope_lock'] = [
      'description' => 'Serializes integration management mutations per scope.',
      'fields' => ['scope_group_id' => $integer],
      'primary key' => ['scope_group_id'],
    ];
    return $schema;
  }

  /**
   * Lists at most 200 definitions in one explicitly authorized scope.
   */
  public function list(string $resource, int $scope): array {
    return $this->listResult($resource, $scope)['data'];
  }

  /**
   * Returns bounded lists with explicit truncation and lightweight summaries.
   */
  public function listResult(string $resource, int $scope): array {
    $query = $this->database->select($this->table($resource), 'i')->fields('i')->condition('scope_group_id', $scope);
    if ($resource === 'mapping') {
      $query->addExpression("CASE WHEN EXISTS (SELECT 1 FROM {beantragt_integration_mapping_version} v WHERE v.mapping_uuid = i.uuid AND v.status = 'active') THEN 0 ELSE 1 END", 'active_order');
      $query->orderBy('active_order');
    }
    else {
      $query->addExpression("CASE WHEN status = 'active' THEN 0 ELSE 1 END", 'active_order');
      $query->orderBy('active_order');
    }
    $rows = $query->orderBy('created')->orderBy('uuid')->range(0, 200)->execute()->fetchAll(FetchAs::Associative);
    $total = (int) $this->database->select($this->table($resource), 'i')->condition('scope_group_id', $scope)->countQuery()->execute()->fetchField();
    return [
      'data' => array_map(fn(array $row): array => $this->project($resource, $this->normalize($resource, $row), FALSE), $rows),
      'meta' => ['total' => $total, 'truncated' => $total > count($rows)],
    ];
  }

  /**
   * Resolves scope before decoding or projecting any tenant-owned data.
   */
  public function scopeOf(string $resource, string $uuid): ?int {
    $scope = $this->database->select($this->table($resource), 'i')->fields('i', ['scope_group_id'])
      ->condition('uuid', strtolower($uuid))->execute()->fetchField();
    return $scope === FALSE ? NULL : (int) $scope;
  }

  /**
   * Returns the connection only when it belongs to the scope of the row.
   *
   * Defense in depth next to the scope check at planning, like the scope
   * join of TimelineLabels::presentation(): a bound transfer (row with a
   * scope) never reads the coupling of a connection of another scope; the
   * caller then uses the defaults. A row without a scope (legacy, unbound)
   * has no tenant to compare and keeps the connection it names.
   *
   * @param array|null $connection
   *   The loaded connection, or NULL.
   * @param array $row
   *   The delivery log row naming the connection.
   */
  public static function inScopeOf(?array $connection, array $row): ?array {
    if ($connection === NULL || ($row['scope_group_id'] ?? NULL) === NULL) {
      return $connection;
    }
    return (int) ($connection['scope_group_id'] ?? 0) === (int) $row['scope_group_id'] ? $connection : NULL;
  }

  /**
   * Loads a definition whose scope must be authorized by the caller.
   */
  public function load(string $resource, string $uuid): ?array {
    $row = $this->database->select($this->table($resource), 'i')->fields('i')
      ->condition('uuid', strtolower($uuid))->execute()->fetchAssoc();
    return is_array($row) ? $this->project($resource, $this->normalize($resource, $row), TRUE) : NULL;
  }

  /**
   * Creates a draft with generated identity and server-managed status.
   */
  public function create(string $resource, array $data, int $uid): array {
    $this->keys($data, [...self::WRITABLE[$resource], 'scope_group_id']);
    $scope = $data['scope_group_id'] ?? NULL;
    if (!is_int($scope) || $scope <= 0 || !$this->entityTypeManager->getStorage('group')->load($scope)) {
      throw new IntegrationException('integration.scope_not_found', 404);
    }
    $data = $this->normalizeInput($data);
    $row = $data + [
      'uuid' => $this->uuid->generate(),
      'uid' => $uid,
      'created' => $this->time->getCurrentTime(),
      'changed' => $this->time->getCurrentTime(),
    ];
    if ($resource !== 'mapping') {
      $row['status'] = 'draft';
    }
    if ($resource === 'assignment') {
      $row += [
        'send_attachments' => FALSE,
        'transfer_trigger' => 'submission',
        'trigger_flag' => WorkflowStatusInterface::FLAG_HAND_OVER,
      ];
    }
    if ($resource === 'connection') {
      $row += ['reply_process_standard' => NULL, 'last_check_result' => NULL, 'presentation' => NULL];
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->scopeLock($scope);
      $count_query = $this->database->select($this->table($resource), 'i');
      $count_query->fields('i', ['uuid'])->condition('scope_group_id', $scope);
      $count_query->forUpdate();
      $count = count($count_query->execute()->fetchCol());
      if ($count >= self::QUOTAS[$resource]) {
        throw new IntegrationException('integration.quota_exceeded', 409);
      }
      $this->validate($resource, $row);
      $this->database->insert($this->table($resource))->fields($this->encode($row))->execute();
      return $this->require($resource, $row['uuid']);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Updates editable drafts while preventing changes behind active references.
   */
  public function update(string $resource, string $uuid, array $data): array {
    $this->keys($data, self::WRITABLE[$resource]);
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->lock($resource, $uuid);
      if ($resource === 'connection') {
        $in_use = $this->usedBy($row['uuid'], NULL, TRUE);
        if ($in_use !== []) {
          throw new IntegrationException('integration.in_use', 409, array_slice($in_use, 0, 400));
        }
      }
      if ($resource === 'mapping' || ($row['status'] ?? '') === 'active') {
        throw new IntegrationException('integration.definition_immutable', 409);
      }
      if ($data === []) {
        return $this->require($resource, $uuid);
      }
      $row = array_replace($row, $this->normalizeInput($data));
      $row['changed'] = $this->time->getCurrentTime();
      if ($resource === 'connection') {
        $row['last_check_result'] = NULL;
      }
      $this->validate($resource, $row);
      $this->write($resource, $uuid, $row);
      return $this->require($resource, $uuid);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Performs explicit activation/deactivation under row and uniqueness locks.
   */
  public function transition(string $resource, string $uuid, string $status): array {
    if (!in_array($status, $resource === 'connection' ? [
      'active',
      'disabled',
    ] : [
      'active',
      'inactive',
    ], TRUE) || $resource === 'mapping') {
      throw new IntegrationException('integration.transition_invalid');
    }
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->lock($resource, $uuid);
      $row['status'] = $status;
      // Disabling must remain possible after a prerequisite is removed.
      if ($status === 'active') {
        $this->validate($resource, $row, 'activation');
      }
      if ($status === 'active' && $resource === 'connection') {
        $errors = $this->localErrors($row);
        if ($errors !== []) {
          throw new IntegrationException('integration.connection_invalid', 422, $errors);
        }
        foreach ($this->usedBy($row['uuid'], NULL, TRUE) as $use) {
          $assignment = $this->lock('assignment', $use['assignment_uuid']);
          $reasons = $this->blockedReasons($assignment, $row, TRUE);
          if ($reasons !== []) {
            throw new IntegrationException('integration.connection_invalid', 422, $reasons);
          }
        }
      }
      if ($resource === 'assignment') {
        if ($status === 'active') {
          $this->assertSlotAvailable($row);
        }
        $row['active_key'] = $status === 'active' ? $this->activeKey($row) : NULL;
      }
      $row['changed'] = $this->time->getCurrentTime();
      $this->write($resource, $uuid, $row);
      return $this->require($resource, $uuid);
    }
    catch (IntegrityConstraintViolationException $e) {
      $transaction->rollBack();
      throw new IntegrationException('integration.assignment_conflict', 409);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Records a strictly local, non-transport consistency check.
   */
  public function checkConnection(string $uuid): array {
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->lock('connection', $uuid);
      $errors = $this->localErrors($row);
      $row['last_check_result'] = ['checked' => 'local', 'valid' => $errors === [], 'errors' => $errors];
      $row['changed'] = $this->time->getCurrentTime();
      $this->write('connection', $uuid, $row);
      return $this->require('connection', $uuid);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Creates a draft and serializes version numbering on the parent row.
   */
  public function createVersion(string $uuid, array $data, int $uid): array {
    $uuid = strtolower($uuid);
    $this->keys($data, ['form_schema_uuid', 'compatible_revisions', 'rules']);
    $transaction = $this->database->startTransaction();
    try {
      $mapping = $this->lock('mapping', $uuid);
      $query = $this->database->select('beantragt_integration_mapping_version', 'v');
      $query->fields('v', [
        'version',
      ])->condition('mapping_uuid', strtolower($uuid))->orderBy('version', 'DESC')->range(0, 1);
      $query->forUpdate();
      $version = (int) $query->execute()->fetchField() + 1;
      if ($version > 50) {
        throw new IntegrationException('integration.quota_exceeded', 409);
      }
      $data = $this->normalizeInput($data);
      $row = $data + [
        'mapping_uuid' => $uuid,
        'scope_group_id' => $mapping['scope_group_id'],
        'version' => $version,
        'status' => 'draft',
        'uid' => $uid,
        'created' => $this->time->getCurrentTime(),
        'changed' => $this->time->getCurrentTime(),
      ];
      $this->validateVersion($row);
      $row['checksum'] = hash('sha256', IntegrationRules::canonical($row['rules']));
      $this->database->insert('beantragt_integration_mapping_version')->fields($this->encode($row))->execute();
      return $this->version($uuid, $version);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Rejects every mutation of an activated or retired version.
   */
  public function updateVersion(string $uuid, int $version, array $data): array {
    $this->keys($data, ['form_schema_uuid', 'compatible_revisions', 'rules']);
    return $this->mutateVersion($uuid, $version, $data, NULL);
  }

  /**
   * Activates drafts or retires active versions without changing their data.
   */
  public function transitionVersion(string $uuid, int $version, string $status): array {
    if (!in_array($status, ['active', 'retired'], TRUE)) {
      throw new IntegrationException('integration.transition_invalid');
    }
    return $this->mutateVersion($uuid, $version, [], $status);
  }

  /**
   * Reports compatibility without silently adding the revision to a mapping.
   */
  public function compatibility(string $uuid, int $version, int $revision): array {
    $row = $this->versionRow($uuid, $version);
    $this->assertIntact($row);
    $original = $this->form($row['form_schema_uuid'], $row['compatible_revisions'][0], $row['scope_group_id']);
    $candidate = $this->form($row['form_schema_uuid'], $revision, $row['scope_group_id']);
    $errors = $this->rules->compatibility($row['rules'], $original, $candidate);
    return [
      'compatible' => $errors === [],
      'revision_id' => $revision,
      'registered' => $this->revisions->covers($revision, $row['compatible_revisions']),
      'errors' => $errors,
    ];
  }

  /**
   * Prepares a revision-adoption version without activating or switching.
   *
   * Reuses an identical active or draft version; only a new one is a draft.
   */
  public function adoptRevision(string $assignment_uuid, int $revision, int $uid): array {
    $transaction = $this->database->startTransaction();
    try {
      $initial = $this->raw('assignment', $assignment_uuid);
      $this->scopeLock($initial['scope_group_id']);
      $assignment = $this->lock('assignment', $assignment_uuid);
      $report = $this->compatibility($assignment['mapping_uuid'], $assignment['mapping_version'], $revision);
      if ($report['registered']) {
        throw new IntegrationException('integration.revision_already_covered', 409);
      }
      if (!$report['compatible']) {
        throw new IntegrationException('integration.revision_incompatible', 422, $report['errors']);
      }
      $version = $this->versionRow($assignment['mapping_uuid'], $assignment['mapping_version']);
      $revisions = array_values(array_unique([...$version['compatible_revisions'], $revision]));
      sort($revisions, SORT_NUMERIC);
      // A retry after a partly finished adoption must not mint a new version
      // each time: an identical active version wins over an identical draft.
      $draft = NULL;
      $candidates = $this->database->select('beantragt_integration_mapping_version', 'v')->fields('v')
        ->condition('mapping_uuid', $assignment['mapping_uuid'])->condition('status', ['active', 'draft'], 'IN')
        ->condition('checksum', $version['checksum'])->orderBy('version')->execute()->fetchAll(FetchAs::Associative);
      foreach ($candidates as $candidate) {
        $candidate = $this->normalize('version', $candidate);
        $candidate_revisions = $candidate['compatible_revisions'];
        sort($candidate_revisions, SORT_NUMERIC);
        if ($candidate['integrity'] === 'ok' && $candidate['form_schema_uuid'] === $version['form_schema_uuid'] && $candidate_revisions === $revisions
          && ($draft === NULL || ($candidate['status'] === 'active' && $draft['status'] !== 'active'))) {
          $draft = $this->project('version', $candidate, TRUE);
        }
      }
      $draft ??= $this->createVersion($assignment['mapping_uuid'], [
        'form_schema_uuid' => $version['form_schema_uuid'],
        'compatible_revisions' => $revisions,
        'rules' => $version['rules'],
      ], $uid);
      return [
        'compatibility' => $report,
        'draft' => $draft,
        'next' => [
          'activate_mapping_version' => $draft['version'],
          'replace_assignment_uuid' => $assignment_uuid,
          'compatible_revisions' => $draft['compatible_revisions'],
        ],
      ];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Locks a complete definition under the management scope serialization lock.
   *
   * The caller must retain its transaction until all binding checks finish.
   */
  public function lockTransferDefinition(int $scope, string $uuid): array {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('Transfer checks require a transaction.');
    }
    $this->scopeLock($scope);
    $assignment = $this->reference('assignment', $uuid, $scope, TRUE);
    $connection = $this->reference('connection', $assignment['connection_uuid'], $scope, TRUE);
    $mapping = $this->reference('mapping', $assignment['mapping_uuid'], $scope, TRUE);
    $version = $this->versionRow($mapping['uuid'], $assignment['mapping_version']);
    $this->assertIntact($version);
    return compact('assignment', 'connection', 'mapping', 'version');
  }

  /**
   * Reads advisory case-view prerequisites without acquiring database locks.
   */
  public function readTransferDefinition(int $scope, string $uuid): array {
    $assignment = $this->raw('assignment', $uuid);
    $connection = $this->raw('connection', $assignment['connection_uuid']);
    $mapping = $this->raw('mapping', $assignment['mapping_uuid']);
    $version = $this->versionRow($mapping['uuid'], $assignment['mapping_version'], FALSE);
    foreach ([$assignment, $connection, $mapping, $version] as $row) {
      if ($row['scope_group_id'] !== $scope) {
        throw new IntegrationException('integration.reference_not_found', 404);
      }
    }
    return compact('assignment', 'connection', 'mapping', 'version');
  }

  /**
   * Requires the named historical revision and its current form in this scope.
   */
  public function assertTransferForm(string $uuid, int $revision, int $scope): void {
    $this->form($uuid, $revision, $scope);
  }

  /**
   * Loads an explicitly numbered version.
   */
  public function version(string $uuid, int $version): array {
    return $this->project('version', $this->versionRow($uuid, $version), TRUE);
  }

  /**
   * Loads an internal version without expanding assignment references.
   */
  private function versionRow(string $uuid, int $version, bool $lock = TRUE): array {
    $uuid = strtolower($uuid);
    $mapping = $this->raw('mapping', $uuid);
    $query = $this->database->select('beantragt_integration_mapping_version', 'v')->fields('v')
      ->condition('mapping_uuid', $uuid)->condition('version', $version);
    if ($lock && $this->database->inTransaction()) {
      $query->forUpdate();
    }
    $row = $query->execute()->fetchAssoc();
    if (!is_array($row) || (int) $row['scope_group_id'] !== $mapping['scope_group_id']) {
      throw new IntegrationException('integration.mapping_version_not_found', 404);
    }
    return $this->normalize('version', $row);
  }

  /**
   * Mutates a version while holding its parent's serialization lock.
   */
  private function mutateVersion(string $uuid, int $version, array $data, ?string $status): array {
    $transaction = $this->database->startTransaction();
    try {
      $mapping = $this->lock('mapping', $uuid);
      $row = $this->versionRow($uuid, $version);
      if ($status === 'retired' && $row['status'] === 'active') {
        $row['status'] = 'retired';
      }
      elseif ($row['status'] !== 'draft') {
        throw new IntegrationException('integration.mapping_version_immutable', 409);
      }
      else {
        $this->assertIntact($row);
        if ($status === 'retired') {
          throw new IntegrationException('integration.transition_invalid');
        }
        $row = array_replace($row, $this->normalizeInput($data));
        $this->validateVersion($row);
        if ($status === 'active') {
          $errors = [];
          foreach ($row['compatible_revisions'] as $revision) {
            $form = $this->form($row['form_schema_uuid'], $revision, $row['scope_group_id']);
            $errors = [
              ...$errors,
              ...$this->rules->check($row['rules'],
            $form,
            $mapping['contract_key'],
            $mapping['contract_version']),
            ];
          }
          if ($errors !== []) {
            throw new IntegrationException('integration.mapping_incomplete', 422, array_slice($errors, 0, 100));
          }
          $row['status'] = 'active';
        }
        $row['checksum'] = hash('sha256', IntegrationRules::canonical($row['rules']));
      }
      $row['changed'] = $this->time->getCurrentTime();
      $fields = $status === 'retired'
        ? ['status' => 'retired', 'changed' => $row['changed']]
        : $this->encode($this->storedFields('version', $row));
      $this->database->update('beantragt_integration_mapping_version')->fields($fields)
        ->condition('mapping_uuid', $uuid)->condition('version', $version)->execute();
      return $this->version($uuid, $version);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Changes display texts and the closure rule of a connection.
   *
   * Allowed in every status: these fields never reach a transfer, its
   * binding or its payload, so editing them cannot invalidate a send.
   */
  public function updatePresentation(string $uuid, array $data): array {
    $this->keys($data, array_keys(self::PRESENTATION_DEFAULTS));
    if ($data === []) {
      return $this->require('connection', $uuid);
    }
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->lock('connection', $uuid);
      $presentation = self::presentation($row['presentation'] ?? NULL);
      foreach ($data as $key => $value) {
        $presentation[$key] = match ($key) {
          'closure_rule' => $this->closureRule($value),
          'follow_up' => $this->followUp($value, $presentation['follow_up']),
          default => $this->labelPair($key, $value, $presentation[$key]),
        };
      }
      $this->database->update(self::TABLES['connection'])
        ->fields([
          'presentation' => IntegrationRules::canonical($presentation),
          'changed' => $this->time->getCurrentTime(),
        ])
        ->condition('uuid', strtolower($uuid))->execute();
      return $this->require('connection', $uuid);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Returns a connection's presentation with defaults for missing parts.
   *
   * Stored data without follow_up (or with invalid entries) yields the
   * default follow-up flags: every connection is coupled both ways.
   *
   * @return array{handover_label: array{de: string, en: string}, response_label: array{de: string, en: string}, approved_label: array{de: string, en: string}, rejected_label: array{de: string, en: string}, filed_label: array{de: string, en: string}, closure_rule: string, follow_up: array<string, string>}
   *   Empty labels mean "use the product's default text".
   */
  public static function presentation(mixed $stored): array {
    $presentation = self::PRESENTATION_DEFAULTS;
    if (!is_array($stored)) {
      return $presentation;
    }
    foreach (self::PRESENTATION_LABELS as $key) {
      foreach (['de', 'en'] as $langcode) {
        $value = $stored[$key][$langcode] ?? '';
        $presentation[$key][$langcode] = is_string($value) ? $value : '';
      }
    }
    if (in_array($stored['closure_rule'] ?? NULL, self::CLOSURE_RULES, TRUE)) {
      $presentation['closure_rule'] = $stored['closure_rule'];
    }
    $presentation['follow_up'] = SemanticStatusChanger::followUp(is_array($stored['follow_up'] ?? NULL) ? $stored['follow_up'] : []);
    return $presentation;
  }

  /**
   * Changes when transfers of an assignment are planned.
   *
   * Allowed in every status, like updatePresentation(): the trigger only
   * decides when a future transfer is planned. It is never part of a
   * binding, so it cannot change or invalidate a frozen transfer.
   */
  public function updateCoupling(string $uuid, array $data): array {
    $this->keys($data, ['transfer_trigger', 'trigger_flag']);
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->lock('assignment', $uuid);
      if ($data === []) {
        return $this->require('assignment', $uuid);
      }
      $row = array_replace($row, $data);
      $this->validateCoupling($row);
      $this->database->update(self::TABLES['assignment'])
        ->fields([
          'transfer_trigger' => $row['transfer_trigger'],
          'trigger_flag' => $row['trigger_flag'],
          'changed' => $this->time->getCurrentTime(),
        ])
        ->condition('uuid', strtolower($uuid))->execute();
      return $this->require('assignment', $uuid);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Validates trigger and trigger flag of an assignment.
   *
   * A status trigger needs the rule's form to carry the function "Übergabe
   * an ein Fachverfahren" (couplingGap()): the hand-over status (a person's
   * change starts the transfer) and the in-case-system status (the
   * acceptance moves the case there inside the graph). A trigger on
   * submission or by staff needs nothing of the form.
   * Refused with integration.coupling_status_missing;
   * the detail names the function, the missing flags (never a status name),
   * the moment (activation or save) and whether the form has an
   * organisation (only then can its workflow switch the function on).
   *
   * @param array<string, mixed> $row
   *   The assignment row.
   * @param string $moment
   *   The moment: "activation" when the rule is activated, else "save".
   */
  private function validateCoupling(array $row, string $moment = 'save'): void {
    foreach (['transfer_trigger' => self::TRANSFER_TRIGGERS, 'trigger_flag' => WorkflowStatusInterface::TRIGGER_FLAGS] as $key => $allowed) {
      if (!in_array($row[$key] ?? NULL, $allowed, TRUE)) {
        throw new IntegrationException('integration.input_invalid', 422, [
          ['pointer' => '/' . $key, 'code' => 'integration.input_invalid'],
        ]);
      }
    }
    $gap = $this->couplingGap($row);
    if ($gap !== NULL) {
      throw new IntegrationException('integration.coupling_status_missing', 422, [
        $this->couplingReason($row, $gap) + ['moment' => $moment],
      ]);
    }
  }

  /**
   * The detail of a coupling refusal or blocked reason.
   *
   * @param array<string, mixed> $row
   *   The assignment row.
   * @param array{missing_flags: list<string>, form_has_organisation: bool|null} $gap
   *   What couplingGap() found.
   *
   * @return array<string, mixed>
   *   Pointer, code, function key, missing flags and the organisation fact.
   */
  private function couplingReason(array $row, array $gap): array {
    return [
      // Only the status trigger needs the function (couplingGap()).
      'pointer' => '/trigger_flag',
      'code' => 'integration.coupling_status_missing',
      'feature' => 'handOver',
      'missing_flags' => $gap['missing_flags'],
      'form_has_organisation' => $gap['form_has_organisation'],
    ];
  }

  /**
   * What the rule's trigger relies on and the form's workflow lacks.
   *
   * @param array<string, mixed> $row
   *   The assignment row.
   *
   * @return array{missing_flags: list<string>, form_has_organisation: bool|null}|null
   *   NULL when nothing is missing; form_has_organisation NULL when the form
   *   was not asked (catalogue-wide check).
   */
  private function couplingGap(array $row): ?array {
    $trigger = (string) ($row['transfer_trigger'] ?? '');
    $required = match ($trigger) {
      'status' => [(string) $row['trigger_flag'], self::FLAG_IN_CASE_SYSTEM],
      default => [],
    };
    if ($required === []) {
      return NULL;
    }
    $scope = (int) $row['scope_group_id'];
    $form = NULL;
    if ($this->workflowResolver !== NULL && is_string($row['form_schema_uuid'] ?? NULL) && Uuid::isValid($row['form_schema_uuid'])) {
      $forms = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $row['form_schema_uuid']]);
      $form = reset($forms);
    }
    // Without the resolver, a loadable form, or with a form outside the
    // rule's scope (another tenant's workflow never decides) the old
    // catalogue-wide check of the status trigger stands; the form reference
    // reports its own code later.
    if ($this->workflowResolver === NULL || !$form instanceof FormSchemaInterface || $this->scopeAccess->formIntegrationScope($form) !== $scope) {
      if ($trigger === 'status' && !$this->scopeHasFlaggedStatus($scope, (string) $row['trigger_flag'])) {
        return ['missing_flags' => [(string) $row['trigger_flag']], 'form_has_organisation' => NULL];
      }
      return NULL;
    }
    $organisation = $this->formOrganisationId($form);
    $groups = $organisation !== NULL ? [$organisation] : $this->scopeGroups($scope);
    $missing = [];
    foreach ($required as $flag) {
      if (!$this->workflowResolver->formWorkflowHasFlag($form, $groups, $flag)) {
        $missing[] = $flag;
      }
    }
    return $missing === [] ? NULL : ['missing_flags' => $missing, 'form_has_organisation' => $organisation !== NULL];
  }

  /**
   * The organisation a form belongs to, or NULL for a jurisdiction form.
   *
   * Same order as the application's catalogue resolution
   * (_beantragt_group_entity_organisation_id()): field_organisation, then an
   * organisation in field_group.
   */
  private function formOrganisationId(FormSchemaInterface $form): ?int {
    foreach (['field_organisation', 'field_group'] as $field) {
      if (!$form->hasField($field) || $form->get($field)->isEmpty()) {
        continue;
      }
      $group = $form->get($field)->entity;
      if ($group instanceof EntityInterface && $group->bundle() === 'org' && (int) $group->id() > 0) {
        return (int) $group->id();
      }
      if ($field === 'field_organisation') {
        // A set organisation field that names no organisation decides.
        return NULL;
      }
    }
    return NULL;
  }

  /**
   * The jurisdiction and its organisations.
   *
   * @return int[]
   *   Group ids, the jurisdiction first.
   */
  private function scopeGroups(int $scope): array {
    $groups = [$scope];
    if ($this->entityTypeManager->hasDefinition('group')) {
      $organisations = $this->entityTypeManager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'org')
        ->condition('field_jurisdiction', $scope)
        ->execute();
      $groups = [...$groups, ...array_map('intval', array_values($organisations))];
    }
    return $groups;
  }

  /**
   * Whether a status of the scope or one of its organisations has the flag.
   */
  private function scopeHasFlaggedStatus(int $scope, string $flag): bool {
    if ($scope <= 0 || !$this->entityTypeManager->hasDefinition('workflow_status')) {
      return FALSE;
    }
    return (bool) $this->entityTypeManager->getStorage('workflow_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $this->scopeGroups($scope), 'IN')
      ->condition('field_semantic_flags', $flag)
      ->range(0, 1)
      ->count()
      ->execute();
  }

  /**
   * Validates a partial follow-up object against the allowed flags.
   *
   * @param mixed $value
   *   Submitted follow-up flags; a missing event keeps its stored flag.
   * @param array<string, string> $stored
   *   The normalized stored follow-up flags.
   */
  private function followUp(mixed $value, array $stored): array {
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      throw $this->invalidPresentation('/follow_up');
    }
    $follow_up = $stored;
    foreach ($value as $event => $flag) {
      if (!isset(self::FOLLOW_UP_FLAGS[$event]) || !in_array($flag, self::FOLLOW_UP_FLAGS[$event], TRUE)) {
        throw $this->invalidPresentation('/follow_up/' . $event);
      }
      $follow_up[$event] = $flag;
    }
    return $follow_up;
  }

  /**
   * Validates one bilingual display text pair.
   */
  private function labelPair(string $key, mixed $value, array $stored): array {
    if (!is_array($value) || array_diff(array_keys($value), ['de', 'en']) !== []) {
      throw $this->invalidPresentation('/' . $key);
    }
    // A language left out keeps its stored text; send '' to clear it.
    $pair = $stored;
    foreach ($value as $langcode => $label) {
      // Rendered to citizens: plain text only, empty means default text.
      if (!is_string($label) || ($label !== '' && !$this->safeString($label, 120))) {
        throw $this->invalidPresentation('/' . $key . '/' . $langcode);
      }
      $pair[$langcode] = $label;
    }
    return $pair;
  }

  /**
   * Validates the closure rule.
   */
  private function closureRule(mixed $value): string {
    if (!in_array($value, self::CLOSURE_RULES, TRUE)) {
      throw $this->invalidPresentation('/closure_rule');
    }
    return $value;
  }

  /**
   * Builds a 422 that points at the rejected presentation field.
   */
  private function invalidPresentation(string $pointer): IntegrationException {
    return new IntegrationException('integration.input_invalid', 422, [
      ['pointer' => $pointer, 'code' => 'integration.input_invalid'],
    ]);
  }

  /**
   * Validates strict resource types and all same-scope references.
   */
  private function validate(string $resource, array $row, string $moment = 'save'): void {
    foreach (self::WRITABLE[$resource] as $key) {
      if (in_array($key, ['reply_process_standard', 'compatible_revisions', 'mapping_version', 'send_attachments'], TRUE)) {
        continue;
      }
      $max = match ($key) {
        'adapter' => 32, 'environment', 'dispatch_mode' => 16,
        'transfer_trigger' => 16, 'trigger_flag' => 32,
        'contract_key', 'credential_profile' => 128, 'contract_version' => 32,
        default => 255,
      };
      if (!isset($row[$key]) || !is_string($row[$key]) || !$this->safeString($row[$key], $max)) {
        throw new IntegrationException('integration.input_invalid', 422, [
        [
          'pointer' => '/' . $key,
          'code' => 'integration.input_invalid',
        ],
        ]);
      }
      if (str_ends_with($key, '_uuid') && !Uuid::isValid($row[$key])) {
        throw new IntegrationException('integration.input_invalid');
      }
    }
    if (isset($row['service_urn']) && !$this->validUrn($row['service_urn'])) {
      throw new IntegrationException('integration.input_invalid');
    }
    if ($resource === 'connection') {
      if ($row['adapter'] !== 'fit_connect' || $row['environment'] !== 'test' || !Uuid::isValid($row['destination_id'])) {
        throw new IntegrationException('integration.connection_invalid');
      }
      if ($row['reply_process_standard'] !== NULL && (!is_string($row['reply_process_standard']) || !$this->safeString($row['reply_process_standard'], 255) || !$this->validUrn($row['reply_process_standard']))) {
        throw new IntegrationException('integration.input_invalid');
      }
      $profiles = array_column($this->profiles->options($row['scope_group_id']), 'key');
      if (!in_array($row['credential_profile'], $profiles, TRUE)) {
        throw new IntegrationException('integration.credential_profile_not_found', 404);
      }
    }
    if ($resource !== 'assignment') {
      $this->registry->load($row['contract_key'], $row['contract_version']);
      return;
    }
    if (!is_bool($row['send_attachments'] ?? NULL)) {
      throw new IntegrationException('integration.input_invalid');
    }
    $this->validateCoupling($row, $moment);
    if (!is_int($row['mapping_version'] ?? NULL) || $row['mapping_version'] <= 0 || !in_array($row['dispatch_mode'], [
      'automatic',
      'approval',
    ], TRUE)) {
      throw new IntegrationException('integration.input_invalid');
    }
    $this->revisions($row['compatible_revisions'] ?? NULL);
    $active = $row['status'] === 'active';
    $connection = $this->reference('connection', $row['connection_uuid'], $row['scope_group_id'], $active);
    $mapping = $this->reference('mapping', $row['mapping_uuid'], $row['scope_group_id'], $active);
    try {
      $version = $this->versionRow($row['mapping_uuid'], $row['mapping_version']);
    }
    catch (IntegrationException $e) {
      if ($e->status !== 404) {
        throw $e;
      }
      throw new IntegrationException('integration.reference_not_found', 404);
    }
    $this->assertIntact($version);
    if ($version['scope_group_id'] !== $row['scope_group_id']) {
      throw new IntegrationException('integration.reference_not_found', 404);
    }
    foreach ($row['compatible_revisions'] as $revision) {
      $this->form($row['form_schema_uuid'], $revision, $row['scope_group_id']);
    }
    if ($version['form_schema_uuid'] !== $row['form_schema_uuid'] || array_diff($row['compatible_revisions'], $version['compatible_revisions']) !== []) {
      throw new IntegrationException('integration.revision_incompatible');
    }
    if ($connection['contract_key'] !== $mapping['contract_key'] || $connection['contract_version'] !== $mapping['contract_version'] || $connection['service_urn'] !== $row['service_urn']) {
      throw new IntegrationException('integration.contract_mismatch');
    }
    if ($row['status'] === 'active') {
      if ($connection['status'] !== 'active' || $version['status'] !== 'active') {
        throw new IntegrationException('integration.prerequisite_inactive');
      }
      if ($this->localErrors($connection) !== []) {
        throw new IntegrationException('integration.connection_invalid');
      }
    }
  }

  /**
   * Validates version rules and explicitly compatible FormSchema revisions.
   */
  private function validateVersion(array $row): void {
    if (!is_string($row['form_schema_uuid'] ?? NULL) || !Uuid::isValid($row['form_schema_uuid']) || !is_array($row['rules'] ?? NULL)) {
      throw new IntegrationException('integration.input_invalid');
    }
    $this->revisions($row['compatible_revisions'] ?? NULL);
    $this->rules->validate($row['rules']);
    $original = NULL;
    foreach ($row['compatible_revisions'] as $revision) {
      $form = $this->form($row['form_schema_uuid'], $revision, $row['scope_group_id']);
      if ($original !== NULL) {
        $errors = $this->rules->compatibility($row['rules'], $original, $form);
        if ($errors !== []) {
          throw new IntegrationException('integration.revision_incompatible', 422, $errors);
        }
      }
      $original ??= $form;
    }
  }

  /**
   * Loads only a revision of the named form in the requested scope.
   */
  private function form(string $uuid, int $revision, int $scope): array {
    $storage = $this->entityTypeManager->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    $entity = $storage->loadRevision($revision);
    if (!$entity instanceof ContentEntityInterface || $entity->uuid() !== $uuid || $this->scopeAccess->formIntegrationScope($entity) !== $scope) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    // A historical scope cannot resurrect access after the form is transferred.
    $current = $storage->loadUnchanged($entity->id());
    if (!$current instanceof ContentEntityInterface || $this->scopeAccess->formIntegrationScope($current) !== $scope) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    $form = json_decode((string) $entity->get('field_schema')->value, TRUE);
    if (!is_array($form)) {
      throw new IntegrationException('integration.form_schema_invalid');
    }
    return $form;
  }

  /**
   * Checks strictly positive numeric revision identifiers, never field_version.
   */
  private function revisions(mixed $revisions): void {
    if (!is_array($revisions) || !array_is_list($revisions) || $revisions === [] || count($revisions) > 100 || count(array_unique($revisions, SORT_REGULAR)) !== count($revisions)) {
      throw new IntegrationException('integration.input_invalid');
    }
    foreach ($revisions as $revision) {
      if (!is_int($revision) || $revision <= 0) {
        throw new IntegrationException('integration.input_invalid');
      }
    }
  }

  /**
   * Computes safe checks without resolving credentials or contacting any host.
   */
  private function localErrors(array $row): array {
    $errors = $this->profiles->check($row['credential_profile'], $row['scope_group_id']);
    if ($row['environment'] !== 'test' || $row['adapter'] !== 'fit_connect') {
      $errors[] = ['pointer' => '/environment', 'code' => 'integration.test_only'];
    }
    if (!Uuid::isValid($row['destination_id'])) {
      $errors[] = ['pointer' => '/destination_id', 'code' => 'integration.destination_invalid'];
    }
    try {
      $this->registry->load($row['contract_key'], $row['contract_version']);
    }
    catch (\InvalidArgumentException $e) {
      $errors[] = ['pointer' => '/contract_key', 'code' => 'integration.contract_unavailable'];
    }
    return array_slice($errors, 0, 100);
  }

  /**
   * Obtains the resource row lock for a mutation transaction.
   */
  private function lock(string $resource, string $uuid): array {
    $uuid = strtolower($uuid);
    $before = $this->raw($resource, $uuid);
    $this->scopeLock($before['scope_group_id']);
    $query = $this->database->select($this->table($resource), 'i');
    $query->fields('i')->condition('uuid', $uuid);
    $query->forUpdate();
    $row = $query->execute()->fetchAssoc();
    if (!is_array($row)) {
      throw new IntegrationException(TransferFailures::resourceNotFound($resource), 404);
    }
    return $this->normalize($resource, $row);
  }

  /**
   * Gives missing and foreign body references the same non-enumerating error.
   */
  private function reference(string $resource, string $uuid, int $scope, bool $lock): array {
    try {
      $row = $this->raw($resource, $uuid);
      if ($lock && $row['scope_group_id'] === $scope) {
        $row = $this->lock($resource, $uuid);
      }
    }
    catch (IntegrationException $e) {
      throw new IntegrationException('integration.reference_not_found', 404);
    }
    if ($row['scope_group_id'] !== $scope) {
      throw new IntegrationException('integration.reference_not_found', 404);
    }
    return $row;
  }

  /**
   * Loads or emits the resource's stable not-found code.
   */
  private function require(string $resource, string $uuid): array {
    return $this->load($resource, $uuid) ?? throw new IntegrationException(TransferFailures::resourceNotFound($resource), 404);
  }

  /**
   * Resolves only known table identifiers.
   */
  private function table(string $resource): string {
    return self::TABLES[$resource] ?? throw new IntegrationException('integration.resource_unknown', 404);
  }

  /**
   * Rejects unknown keys, including protected status, scope, and audit fields.
   */
  private function keys(array $data, array $allowed): void {
    if (array_diff(array_keys($data), $allowed) !== []) {
      throw new IntegrationException('integration.unknown_key');
    }
  }

  /**
   * Updates a known table after removing derived API fields.
   */
  private function write(string $resource, string $uuid, array $row): void {
    $row = $this->storedFields($resource, $row);
    $query = $this->database->update($this->table($resource));
    $query->fields($this->encode($row))->condition('uuid', strtolower($uuid));
    $query->execute();
  }

  /**
   * Serializes structured columns as deterministic JSON.
   */
  private function encode(array $row): array {
    if (isset($row['send_attachments'])) {
      $row['send_attachments'] = (int) $row['send_attachments'];
    }
    foreach (['rules', 'compatible_revisions', 'last_check_result', 'presentation'] as $key) {
      if (array_key_exists($key, $row) && $row[$key] !== NULL) {
        $row[$key] = IntegrationRules::canonical($row[$key]);
      }
    }
    return $row;
  }

  /**
   * Restores stable JSON types and hides the internal uniqueness key.
   */
  private function normalize(string $resource, array $row): array {
    foreach (['scope_group_id', 'uid', 'created', 'changed', 'mapping_version', 'version'] as $key) {
      if (isset($row[$key])) {
        $row[$key] = (int) $row[$key];
      }
    }
    $intact = TRUE;
    foreach (['rules', 'compatible_revisions', 'last_check_result'] as $key) {
      if (isset($row[$key])) {
        try {
          $json = $row[$key];
          $row[$key] = json_decode($json, TRUE, 64, JSON_THROW_ON_ERROR);
          if ($key === 'rules') {
            $row[$key] = $this->rules->decode($json);
          }
          if ($key === 'compatible_revisions' && !is_array($row[$key])) {
            throw new \UnexpectedValueException('Invalid revision list.');
          }
        }
        catch (\Throwable) {
          $intact = FALSE;
          $row[$key] = $key === 'compatible_revisions' ? [] : NULL;
        }
      }
    }
    unset($row['active_key'], $row['active_order']);
    if ($resource === 'connection') {
      $row['presentation'] = self::presentation(is_string($row['presentation'] ?? NULL) ? json_decode($row['presentation'], TRUE) : NULL);
    }
    if ($resource === 'assignment') {
      $row['send_attachments'] = (bool) ($row['send_attachments'] ?? FALSE);
    }
    if ($resource === 'version') {
      $intact = $intact && is_array($row['rules'] ?? NULL)
        && hash_equals($row['checksum'], hash('sha256', IntegrationRules::canonical($row['rules'])));
      $row['integrity'] = $intact ? 'ok' : 'failed';
      if (!$intact) {
        unset($row['rules']);
      }
    }
    return $row;
  }

  /**
   * Rejects corrupt definitions only when an operation can increase risk.
   */
  private function assertIntact(array $version): void {
    if (($version['integrity'] ?? 'failed') !== 'ok') {
      throw new IntegrationException('integration.mapping_checksum_mismatch', 409);
    }
  }

  /**
   * Checks a persisted draft without activating or changing it.
   */
  public function checkVersion(string $uuid, int $version): array {
    $mapping = $this->raw('mapping', $uuid);
    $row = $this->versionRow($uuid, $version);
    $this->assertIntact($row);
    $errors = [];
    $coverage = ['required_total' => 0, 'required_covered' => 0];
    foreach ($row['compatible_revisions'] as $revision) {
      $form = $this->form($row['form_schema_uuid'], $revision, $row['scope_group_id']);
      $report = $this->rules->report($row['rules'], $form, $mapping['contract_key'], $mapping['contract_version']);
      $errors = [...$errors, ...$report['errors']];
      $coverage = $report['coverage'];
    }
    return ['valid' => $errors === [], 'errors' => array_slice($errors, 0, 100), 'coverage' => $coverage];
  }

  /**
   * Atomically replaces one active assignment with its validated successor.
   */
  public function replaceActive(string $old_uuid, string $new_uuid): array {
    if (!Uuid::isValid($old_uuid) || !Uuid::isValid($new_uuid) || trim($old_uuid) !== $old_uuid || trim($new_uuid) !== $new_uuid) {
      throw new IntegrationException('integration.input_invalid');
    }
    $old_uuid = strtolower($old_uuid);
    $new_uuid = strtolower($new_uuid);
    $transaction = $this->database->startTransaction();
    try {
      $old = $this->raw('assignment', $old_uuid);
      try {
        $new = $this->raw('assignment', $new_uuid);
      }
      catch (IntegrationException $e) {
        if ($e->status !== 404) {
          throw $e;
        }
        throw new IntegrationException('integration.reference_not_found', 404);
      }
      if ($old['scope_group_id'] !== $new['scope_group_id']) {
        throw new IntegrationException('integration.reference_not_found', 404);
      }
      $this->scopeLock($old['scope_group_id']);
      $old = $this->lock('assignment', $old_uuid);
      $new = $this->lock('assignment', $new_uuid);
      if ($old['uuid'] === $new['uuid'] || $old['status'] !== 'active' || $new['status'] === 'active'
        || $this->activeKey($old) !== $this->activeKey($new)) {
        throw new IntegrationException('integration.assignment_conflict', 409);
      }
      $new['status'] = 'active';
      $this->validate('assignment', $new, 'activation');
      $old['status'] = 'inactive';
      $old['active_key'] = NULL;
      $old['changed'] = $new['changed'] = $this->time->getCurrentTime();
      $new['active_key'] = $this->activeKey($new);
      $this->write('assignment', $old['uuid'], $old);
      $this->assertSlotAvailable($new);
      $this->write('assignment', $new['uuid'], $new);
      return $this->require('assignment', $new['uuid']);
    }
    catch (IntegrityConstraintViolationException $e) {
      $transaction->rollBack();
      throw new IntegrationException('integration.assignment_conflict', 409);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Serializes quotas and lifecycle changes before locking any resource row.
   */
  private function scopeLock(int $scope): void {
    $this->database->upsert('beantragt_integration_scope_lock')
      ->key('scope_group_id')->fields(['scope_group_id'])->values([$scope])->execute();
    $query = $this->database->select('beantragt_integration_scope_lock', 's');
    $query->fields('s')->condition('scope_group_id', $scope);
    $query->forUpdate();
    $query->execute()->fetchField();
  }

  /**
   * Loads internal rows without recursively expanding versions or references.
   */
  private function raw(string $resource, string $uuid): array {
    $row = $this->database->select($this->table($resource), 'i')->fields('i')
      ->condition('uuid', strtolower($uuid))->execute()->fetchAssoc();
    if (!is_array($row)) {
      throw new IntegrationException(TransferFailures::resourceNotFound($resource), 404);
    }
    return $this->normalize($resource, $row);
  }

  /**
   * Returns bounded references without applicant data.
   */
  private function usedBy(string $uuid, ?int $version = NULL, bool $active = FALSE): array {
    $query = $this->database->select(self::TABLES['assignment'], 'i')->fields('i', ['uuid', 'status']);
    if ($version === NULL) {
      $query->condition('connection_uuid', $uuid);
    }
    else {
      $query->condition('mapping_uuid', $uuid)->condition('mapping_version', $version);
    }
    if ($active) {
      $query->condition('status', 'active');
    }
    if ($active) {
      $query->forUpdate();
    }
    $rows = $query->orderBy('uuid')->range(0, 401)->execute()->fetchAll(FetchAs::Associative);
    return array_map(static fn(array $row): array => [
      'assignment_uuid' => $row['uuid'],
      'status' => $row['status'],
    ], $rows);
  }

  /**
   * Adds only bounded UI projections; internal references remain lightweight.
   */
  private function project(string $resource, array $row, bool $detail): array {
    if ($resource === 'mapping') {
      $query = $this->database->select('beantragt_integration_mapping_version', 'v');
      $query->fields('v');
      $versions = $query->condition('mapping_uuid', $row['uuid'])->condition('scope_group_id', $row['scope_group_id'])->orderBy('version')->range(0, 50)->execute()->fetchAll(FetchAs::Associative);
      $row['versions'] = array_map(function (array $version) use ($detail): array {
        if ($detail) {
          return $this->project('version', $this->normalize('version', $version), TRUE);
        }
        $version = $this->normalize('version', $version);
        return array_intersect_key($version, array_flip(['version', 'status', 'checksum', 'changed', 'integrity']));
      }, $versions);
    }
    elseif ($resource === 'assignment') {
      $row['blocked_reasons'] = $this->blockedReasons($row);
      $row['blocked'] = $row['blocked_reasons'] !== [];
    }
    elseif ($resource === 'connection' || $resource === 'version') {
      $uses = $this->usedBy($resource === 'connection' ? $row['uuid'] : $row['mapping_uuid'], $resource === 'version' ? $row['version'] : NULL);
      $row['used_by'] = array_slice($uses, 0, 400);
      $row['used_by_truncated'] = count($uses) > 400;
    }
    return $row;
  }

  /**
   * Reports stale prerequisites without changing assignment lifecycle status.
   */
  private function blockedReasons(array $row, ?array $connection = NULL, bool $current = FALSE): array {
    $errors = [];
    try {
      $connection ??= $this->raw('connection', $row['connection_uuid']);
      $mapping = $current ? $this->reference('mapping', $row['mapping_uuid'], $row['scope_group_id'], TRUE) : $this->raw('mapping', $row['mapping_uuid']);
      $version = $this->versionRow($row['mapping_uuid'], $row['mapping_version'], $current);
      if ($version['integrity'] !== 'ok') {
        $errors[] = ['code' => 'integration.mapping_checksum_mismatch', 'pointer' => '/mapping_version'];
      }
      if ($connection['status'] !== 'active') {
        $errors[] = ['code' => 'integration.connection_disabled', 'pointer' => '/connection_uuid'];
      }
      if ($version['status'] !== 'active') {
        $errors[] = ['code' => 'integration.mapping_inactive', 'pointer' => '/mapping_version'];
      }
      if ($row['scope_group_id'] !== $connection['scope_group_id'] || $row['scope_group_id'] !== $mapping['scope_group_id'] || $row['scope_group_id'] !== $version['scope_group_id']) {
        $errors[] = ['code' => 'integration.scope_mismatch', 'pointer' => '/scope_group_id'];
      }
      if ($connection['contract_key'] !== $mapping['contract_key'] || $connection['contract_version'] !== $mapping['contract_version'] || $connection['service_urn'] !== $row['service_urn']) {
        $errors[] = ['code' => 'integration.contract_mismatch', 'pointer' => '/connection_uuid'];
      }
      if ($version['form_schema_uuid'] !== $row['form_schema_uuid'] || array_diff($row['compatible_revisions'], $version['compatible_revisions']) !== []) {
        $errors[] = ['code' => 'integration.revision_incompatible', 'pointer' => '/compatible_revisions'];
      }
      foreach ($row['compatible_revisions'] as $revision) {
        $this->form($row['form_schema_uuid'], $revision, $row['scope_group_id']);
      }
      $forms = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $row['form_schema_uuid']]);
      $current_form = reset($forms);
      if (!$current && $current_form instanceof ContentEntityInterface && (int) $current_form->getRevisionId() > 0
        && (!$this->revisions->covers((int) $current_form->getRevisionId(), $row['compatible_revisions'])
          || !$this->revisions->covers((int) $current_form->getRevisionId(), $version['compatible_revisions']))) {
        $errors[] = ['code' => 'integration.revision_not_covered', 'pointer' => '/compatible_revisions'];
      }
      $errors = [...$errors, ...$this->localErrors($connection)];
      // A status-trigger rule whose form lost the function (only that trigger
      // relies on it): shown at the rule and the form; a management warning
      // like revision_not_covered, not an in-flight veto (TransferPlanner
      // skips both), so a transfer still goes.
      if (!$current) {
        $gap = $this->couplingGap($row);
        if ($gap !== NULL) {
          $errors[] = $this->couplingReason($row, $gap);
        }
      }
    }
    catch (IntegrationException $e) {
      $errors[] = ['code' => $e->errorCode, 'pointer' => '/mapping_uuid'];
    }
    return array_slice($errors, 0, 100);
  }

  /**
   * Keeps derived projection data out of SQL writes.
   */
  private function storedFields(string $resource, array $row): array {
    $table = $resource === 'version' ? 'beantragt_integration_mapping_version' : $this->table($resource);
    return array_intersect_key($row, self::schema()[$table]['fields']);
  }

  /**
   * Checks the canonical slot even when an older row carries a legacy hash.
   */
  private function assertSlotAvailable(array $row): void {
    $query = $this->database->select(self::TABLES['assignment'], 'i');
    $query->fields('i', ['uuid'])->condition('scope_group_id', $row['scope_group_id'])
      ->condition('form_schema_uuid', strtolower($row['form_schema_uuid']))
      ->condition('service_urn', strtolower($row['service_urn']))->condition('status', 'active')
      ->condition('uuid', $row['uuid'], '<>')->range(0, 1);
    $query->forUpdate();
    if ($query->execute()->fetchField() !== FALSE) {
      throw new IntegrationException('integration.assignment_conflict', 409);
    }
  }

  /**
   * Creates a canonical slot key consistent with stored identifiers.
   */
  private function activeKey(array $row): string {
    return hash('sha256', IntegrationRules::canonical([
      $row['scope_group_id'],
      strtolower($row['form_schema_uuid']),
      strtolower($row['service_urn']),
    ]));
  }

  /**
   * Rejects ambiguous whitespace, controls, markup and invalid Unicode.
   */
  private function safeString(string $value, int $max): bool {
    return $value !== '' && $value === trim($value) && preg_match('/^\s|\s$/u', $value) === 0 && mb_strlen($value) <= $max
      && preg_match('/^[^\p{C}<>]+$/uD', $value) === 1;
  }

  /**
   * Requires an ASCII URN with explicit namespace and nonempty specific part.
   */
  private function validUrn(string $value): bool {
    return preg_match("/^urn:[a-z0-9][a-z0-9-]{0,31}:[a-z0-9()+,\\-.:=@;\$_!*'%\\/?#]+$/D", $value) === 1;
  }

  /**
   * Normalizes identifiers once, preserving user labels exactly.
   */
  private function normalizeInput(array $data): array {
    foreach ([
      'form_schema_uuid',
      'connection_uuid',
      'mapping_uuid',
      'destination_id',
      'service_urn',
      'reply_process_standard',
    ] as $key) {
      if (isset($data[$key]) && is_string($data[$key])) {
        $data[$key] = strtolower($data[$key]);
      }
    }
    if (isset($data['rules']) && is_array($data['rules'])) {
      $data['rules'] = $this->rules->normalize($data['rules']);
    }
    return $data;
  }

}
