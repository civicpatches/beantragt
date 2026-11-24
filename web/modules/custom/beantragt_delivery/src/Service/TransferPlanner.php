<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\TransferGateInterface;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Plans and authorizes immutable case transfers without performing HTTP.
 */
final class TransferPlanner {

  /**
   * Who may start planning.
   *
   * Submission and status follow the rule's trigger, staff (create transfer,
   * replan) plans in every trigger mode.
   */
  public const PLAN_TRIGGERS = ['submission', 'status', 'staff'];

  /**
   * The explain() reasons under which the rule itself refuses a transfer.
   *
   * Not here: assigned, trigger_deferred, no_assignment, scope_unresolved,
   * form_missing and planning_failed. planning_failed reports an earlier
   * failed planning (history), not the current rule.
   */
  public const RULE_BLOCKING_REASONS = [
    'application_anonymised',
    'assignment_blocked',
    'revision_not_covered',
    'assignment_ambiguous',
    'trigger_status_missing',
  ];

  /**
   * Shared revision identity and lifecycle coverage policy.
   */
  private readonly FormRevisionCoverage $revisions;

  /**
   * The last status planning denial code of this process, by application id.
   *
   * Kept even when the audit writer collapses a repeated denial, so the
   * caller that saved the status still reads the real code.
   *
   * @var array<int, string>
   */
  private array $statusDenials = [];

  /**
   * Constructs the planner with protected storage and explicit definitions.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ScopeAccess $scopeAccess,
    private readonly IntegrationRepository $integrations,
    private readonly IntegrationCredentialProfiles $profiles,
    private readonly DataContractRegistry $registry,
    private readonly MappedPayloadBuilder $builder,
    private readonly ApplicationDataProtectionService $protection,
    private readonly TransferPayloadRepository $payloads,
    private readonly DeliveryLogRepository $logs,
    private readonly DeliveryEnvelopeBuilder $envelopes,
    private readonly QueueFactory $queue,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
    ?FormRevisionCoverage $revisions = NULL,
    private readonly ?TransferGateInterface $gates = NULL,
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly ?EffectiveWorkflowResolver $workflowResolver = NULL,
  ) {
    $this->revisions = $revisions ?? new FormRevisionCoverage($this->entities);
  }

  /**
   * Explains current eligibility without sending or creating a transfer.
   */
  public function explain(ApplicationInterface $application): array {
    if ($this->isAnonymised($application)) {
      return ['reason' => 'application_anonymised', 'reasons' => [['code' => 'integration.application_anonymised']]];
    }
    $scope = $this->scopeAccess->integrationScope($application);
    $form = $this->sourceForm($application);
    if ($scope < 1 || $form === NULL) {
      return ['reason' => $scope < 1 ? 'scope_unresolved' : 'form_missing', 'reasons' => []];
    }
    $ids = $this->assignmentIds($scope, (string) $form->uuid());
    if ($ids === []) {
      return $this->hasAssignment($application)
        ? ['reason' => 'assignment_blocked', 'reasons' => [['code' => 'integration.assignment_inactive']]]
        : ['reason' => 'no_assignment', 'reasons' => []];
    }
    if (count($ids) > 1) {
      return ['reason' => 'assignment_ambiguous', 'reasons' => [['code' => 'integration.assignment_ambiguous']]];
    }
    try {
      $definition = $this->integrations->readTransferDefinition($scope, $ids[0]);
      $revision = $this->revisions->resolve($application, $form);
      $this->assertDefinition($definition, $scope, (string) $form->uuid(), $revision);
      $this->profiles->settings($this->binding($definition, $revision));
      $failed = $this->database->select('beantragt_transfer_audit', 'a')->fields('a', ['id'])
        ->condition('application_id', (int) $application->id())->condition('action', 'plan')->condition('outcome', 'denied')->range(0, 1)->execute()->fetchField();
      $has_rows = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])->condition('application_id', (int) $application->id())->range(0, 1)->execute()->fetchField();
      if ($failed !== FALSE && $has_rows === FALSE) {
        return ['reason' => 'planning_failed', 'reasons' => [['code' => 'integration.planning_failed']]];
      }
      $trigger = self::trigger($definition['assignment']);
      if ($trigger['mode'] !== 'submission' && $has_rows === FALSE) {
        // Not due yet: a status change or staff starts the transfer.
        if ($trigger['mode'] === 'status' && !$this->catalogueHasFlag($application, $trigger['flag'])) {
          return [
            'reason' => 'trigger_status_missing',
            'reasons' => [['code' => 'integration.coupling_status_missing']],
            'assignment_uuid' => $ids[0],
            'trigger' => $trigger,
          ];
        }
        return ['reason' => 'trigger_deferred', 'reasons' => [], 'assignment_uuid' => $ids[0], 'trigger' => $trigger];
      }
      return ['reason' => 'assigned', 'reasons' => [], 'assignment_uuid' => $ids[0]];
    }
    catch (IntegrationException $e) {
      return [
        'reason' => $e->errorCode === 'integration.revision_not_covered' ? 'revision_not_covered' : 'assignment_blocked',
        'reasons' => [['code' => $e->errorCode]],
      ];
    }
    catch (\Throwable) {
      return ['reason' => 'planning_failed', 'reasons' => [['code' => 'integration.planning_failed']]];
    }
  }

  /**
   * The code why the current rule refuses a transfer, or NULL.
   *
   * Reads the same explanation as the transfer area, so a forward elsewhere
   * is refused for exactly the reasons the case page shows.
   */
  public function ruleRefusal(ApplicationInterface $application): ?string {
    $explained = $this->explain($application);
    if (!in_array($explained['reason'], self::RULE_BLOCKING_REASONS, TRUE)) {
      return NULL;
    }
    return $explained['reasons'][0]['code'] ?? 'integration.planning_failed';
  }

  /**
   * Makes failed trigger planning visible without inventing a mutable binding.
   */
  public function recordPlanningFailure(ApplicationInterface $application): void {
    $this->audit($application, '', 0, 'plan', 'denied', 'integration.planning_failed');
  }

  /**
   * Allows explicit staff recovery when a trigger could not create any row.
   */
  public function createTransfer(ApplicationInterface $application, int $uid): array {
    if (($refusal = $this->gates?->planningRefusal($application, 'staff', $uid)) !== NULL) {
      throw new IntegrationException($refusal, 409);
    }
    $id = $this->plan($application, NULL, $uid, 'staff');
    if ($id === NULL) {
      throw new IntegrationException('integration.assignment_unavailable', 409);
    }
    return $this->logs->load($id);
  }

  /**
   * The same creation predicate guards the route and its advertised UI flag.
   */
  public function canCreateTransfer(ApplicationInterface $application): bool {
    return $this->gates?->planningRefusal($application, 'staff', 0) === NULL
      && $this->creationFailure($application) === NULL;
  }

  /**
   * Records eligible configuration identity before the application commits.
   */
  public function planningIdentity(ApplicationInterface $application): array {
    $scope = $this->scopeAccess->integrationScope($application);
    $form = $this->sourceForm($application);
    $ids = $form === NULL ? [] : $this->assignmentIds($scope, (string) $form->uuid());
    if (count($ids) !== 1) {
      throw new IntegrationException(count($ids) > 1 ? 'integration.assignment_ambiguous' : 'integration.assignment_unavailable');
    }
    $definition = $this->integrations->readTransferDefinition($scope, $ids[0]);
    $revision = $this->revisions->resolve($application, $form);
    $this->assertDefinition($definition, $scope, (string) $form->uuid(), $revision);
    $binding = $this->binding($definition, $revision);
    $this->profiles->settings($binding);
    return [
      'assignment_uuid' => $ids[0],
      'binding_checksum' => hash('sha256', IntegrationRules::canonical($binding)),
      'source_sha256' => hash('sha256', $application->getData() ?? '{}'),
    ];
  }

  /**
   * Checks current eligibility and every historical submission to this target.
   */
  private function creationFailure(ApplicationInterface $application, ?array $replaces = NULL): ?string {
    if ($this->isAnonymised($application)) {
      return 'integration.application_anonymised';
    }
    if (!$application->isEmailConfirmed()) {
      return 'integration.confirmation_required';
    }
    $scope = $this->scopeAccess->integrationScope($application);
    $form = $this->sourceForm($application);
    $assignments = $form === NULL ? [] : $this->assignmentIds($scope, (string) $form->uuid());
    if (count($assignments) !== 1) {
      return count($assignments) > 1 ? 'integration.assignment_ambiguous' : 'integration.assignment_unavailable';
    }
    try {
      $definition = $this->integrations->readTransferDefinition($scope, $assignments[0]);
      $this->assertDefinition($definition, $scope, (string) $form->uuid(), $this->revisions->resolve($application, $form));
      $this->profiles->settings($this->binding($definition, $this->revisions->resolve($application, $form)));
      $destination = strtolower((string) $definition['connection']['destination_id']);
    }
    catch (IntegrationException $exception) {
      return $exception->errorCode;
    }
    $ids = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
      ->condition('application_id', (int) $application->id())->execute()->fetchCol();
    foreach ($ids as $id) {
      $row = $this->logs->load((int) $id);
      if ($row['adapter'] !== 'fit_connect') {
        continue;
      }
      $target = strtolower(trim((string) (($row['connection_snapshot']['destination_id'] ?? '') ?: ($row['target'] ?? '') ?: ($row['metadata']['fitConnect']['destinationId'] ?? ''))));
      // Unknown legacy destinations cannot establish that another send is safe.
      if (($target === '' || $target === $destination)
        && (in_array($row['status'], [DeliveryLogRepository::STATUS_ACCEPTED, DeliveryLogRepository::STATUS_PROCESSING], TRUE) || ($row['metadata']['fitConnect'] ?? []) !== []
          || ($row['binding_integrity'] ?? '') === 'failed')) {
        return 'integration.already_submitted';
      }
      if ($replaces !== NULL && $row['uuid'] === $replaces['uuid']) {
        continue;
      }
      if (($replaces !== NULL || $row['scope_group_id'] === NULL) && $row['status'] === DeliveryLogRepository::STATUS_FAILED
        && TransferFailures::classify(TransferFailures::code($row)) === 'permanent') {
        continue;
      }
      if ($row['scope_group_id'] !== NULL && (int) $row['scope_group_id'] !== $scope
        && in_array($row['status'], [DeliveryLogRepository::STATUS_FAILED, DeliveryLogRepository::STATUS_ACCEPTED], TRUE)) {
        continue;
      }
      if ($row['scope_group_id'] === $scope || $target === '' || $target === $destination) {
        return 'integration.transfer_already_live';
      }
    }
    return NULL;
  }

  /**
   * Emergency stops suppress substitute channels, even without an active rule.
   */
  public function hasAssignment(ApplicationInterface $application): bool {
    $form = $this->sourceForm($application);
    $scope = $this->scopeAccess->integrationScope($application);
    return $form !== NULL && $scope > 0 && (bool) $this->database->select('beantragt_integration_assignment', 'a')->fields('a', ['uuid'])
      ->condition('scope_group_id', $scope)->condition('form_schema_uuid', $form->uuid())
      ->condition('status', 'draft', '<>')->range(0, 1)->execute()->fetchField();
  }

  /**
   * Recognizes every in-place erasure without interpreting ordinary field data.
   */
  public function isAnonymised(ApplicationInterface $application): bool {
    return (json_decode($application->getData() ?? '', TRUE)['_anonymized'] ?? NULL) === TRUE;
  }

  /**
   * The trigger of the one active assignment of an application, if any.
   *
   * Reads without locks; errors in the definition count as no trigger.
   *
   * @return array{mode: string, flag: string, assignment_uuid: string, dispatch_mode: string}|null
   *   The trigger, or NULL without exactly one active assignment.
   */
  public function triggerOf(ApplicationInterface $application): ?array {
    try {
      $scope = $this->scopeAccess->integrationScope($application);
      $form = $this->sourceForm($application);
      $ids = $form === NULL || $scope < 1 ? [] : $this->assignmentIds($scope, (string) $form->uuid());
      if (count($ids) !== 1) {
        return NULL;
      }
      $definition = $this->integrations->readTransferDefinition($scope, $ids[0]);
      return self::trigger($definition['assignment']) + [
        'assignment_uuid' => $ids[0],
        // Unknown modes read as approval: the hint then never promises a
        // send without release.
        'dispatch_mode' => ($definition['assignment']['dispatch_mode'] ?? '') === 'automatic' ? 'automatic' : 'approval',
      ];
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Plans a transfer because staff set the trigger status, and approves it.
   *
   * Planning runs as the system (uid 0): plan() with a person would require
   * that person's dispatch right and refuse staff without it. A transfer
   * that waits for approval is approved at once when the person who set the
   * status may approve dispatch in this case (live check). The audit names
   * that person with plan_on_status.
   *
   * @return int|null
   *   The transfer id, or NULL when the rule is not status-triggered.
   */
  public function planOnStatus(ApplicationInterface $application, int $actor_uid): ?int {
    if (($refusal = $this->gates?->planningRefusal($application, 'status', $actor_uid)) !== NULL) {
      $this->audit($application, '', $actor_uid, 'plan_on_status', 'denied', $refusal);
      $this->gates->onPlanning($application, NULL, $actor_uid, $refusal);
      return NULL;
    }
    $before = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
      ->condition('application_id', (int) $application->id())->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
    try {
      $id = $this->plan($application, NULL, 0, 'status');
    }
    catch (IntegrationException $error) {
      if ($error->errorCode !== 'integration.handover_approval_mode_required') {
        throw $error;
      }
      $this->audit($application, '', $actor_uid, 'plan_on_status', 'denied', $error->errorCode);
      $this->gates?->onPlanning($application, NULL, $actor_uid, $error->errorCode);
      return NULL;
    }
    if ($id === NULL) {
      return NULL;
    }
    $row = $this->logs->load($id);
    if ($row === NULL) {
      return $id;
    }
    $this->audit($application, $row['uuid'], $actor_uid, 'plan_on_status', $before !== FALSE && (int) $before === $id ? 'noop' : 'success');
    if ($before === FALSE || (int) $before !== $id) {
      $this->gates?->onPlanning($application, $row, $actor_uid, NULL);
    }
    if (($application->hasField('field_origin') && $application->get('field_origin')->value === 'bulk')
      || $row['status'] !== DeliveryLogRepository::STATUS_AWAITING_APPROVAL || $actor_uid < 1) {
      return $id;
    }
    $users = $this->entities->getStorage('user');
    $users->resetCache([$actor_uid]);
    $actor = $users->load($actor_uid);
    if (!$actor instanceof UserInterface || !$this->scopeAccess->canApproveDispatch($this->freshApplication($application), $actor)) {
      // Waits for "Versand freigeben" by someone with the right.
      return $id;
    }
    try {
      $this->approve($application, $row['uuid'], $actor_uid);
    }
    catch (IntegrationException $exception) {
      // The status change stands; the transfer stays visible with its state.
      $this->audit($application, $row['uuid'], $actor_uid, 'plan_on_status', 'denied', $exception->errorCode);
      if ($exception->errorCode === 'integration.internal_error') {
        throw $exception;
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Status-triggered approval failed: @class.', ['@class' => $exception::class]);
      $this->audit($application, $row['uuid'], $actor_uid, 'plan_on_status', 'denied', 'integration.internal_error');
      throw new IntegrationException('integration.internal_error', 500);
    }
    return $id;
  }

  /**
   * Atomically binds and validates one transfer; duplicate triggers reuse it.
   *
   * A NULL result means no usable assignment, or a rule whose trigger does
   * not match: $trigger is submission or status for automatic planning and
   * staff for explicit creation and replanning (allowed in every mode). The
   * dispatcher must use explain() to distinguish absence from a blocked or
   * deferred rule before considering mock/webhook.
   */
  public function plan(ApplicationInterface $application, ?string $replaces = NULL, int $uid = 0, string $trigger = 'submission'): ?int {
    if (!in_array($trigger, self::PLAN_TRIGGERS, TRUE)) {
      throw new \InvalidArgumentException('Unknown planning trigger.');
    }
    $lock_name = Application::deletionLockName((int) $application->id());
    if (!$this->lock->acquire($lock_name, 300.0)) {
      throw new IntegrationException('integration.transfer_busy', 409);
    }
    $binding = NULL;
    try {
      $this->ensureQueueStorage();
      $transaction = $this->database->startTransaction();
      $this->lockApplication($application);
      $application = $this->freshApplication($application);
      if ($uid > 0 || $replaces !== NULL) {
        $this->assertActor($application, $uid);
      }
      if ($this->isAnonymised($application)) {
        throw new IntegrationException('integration.application_anonymised', 409);
      }
      if (!$application->isEmailConfirmed()) {
        return NULL;
      }
      $old = NULL;
      if ($replaces !== NULL) {
        $old = $this->ownedRow($application, $replaces);
        if ($this->creationFailure($application, $old) === 'integration.already_submitted') {
          throw new IntegrationException('integration.already_submitted', 409);
        }
        $repeat = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
          ->condition('application_id', (int) $application->id())->condition('replaces_uuid', $replaces)->execute()->fetchField();
        if ($repeat !== FALSE) {
          throw new IntegrationException('integration.replan_not_allowed', 409);
        }
        if (!$this->canReplan($old, $application)) {
          throw new IntegrationException('integration.replan_not_allowed', 409);
        }
      }
      $creation_failure = $this->creationFailure($application, $old);
      if ($creation_failure === 'integration.already_submitted' || ($uid > 0 && $creation_failure !== NULL)) {
        throw new IntegrationException($creation_failure, 409);
      }
      $existing = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
        ->condition('application_id', (int) $application->id())
        ->condition('scope_group_id', $this->scopeAccess->integrationScope($application));
      if ($replaces !== NULL) {
        $existing->condition('status', [DeliveryLogRepository::STATUS_ACCEPTED, DeliveryLogRepository::STATUS_FAILED], 'NOT IN');
      }
      $existing_id = $existing->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
      if ($existing_id !== FALSE) {
        if ($replaces !== NULL) {
          throw new IntegrationException('integration.transfer_already_live', 409);
        }
        if ($uid > 0) {
          $this->audit($application, $this->logs->load((int) $existing_id)['uuid'], $uid, 'create_transfer', 'noop');
        }
        return (int) $existing_id;
      }
      $form = $this->sourceForm($application);
      $scope = $this->scopeAccess->integrationScope($application);
      $ids = $form === NULL ? [] : $this->assignmentIds($scope, (string) $form->uuid());
      if ($ids === []) {
        return NULL;
      }
      if (count($ids) > 1) {
        $id = $this->logs->createQueued($application, $this->envelope($application, FALSE), 'fit_connect', '', [
          'scope_group_id' => $scope,
          'form_schema_uuid' => $form->uuid(),
          'validation_errors' => '[]',
        ], DeliveryLogRepository::STATUS_FAILED);
        $this->logs->markResult($id, DeliveryResult::failed('integration.assignment_ambiguous', 'Multiple active assignments match this form.'));
        if ($uid > 0) {
          $this->audit($application, $this->logs->load($id)['uuid'], $uid, 'create_transfer', 'denied', 'integration.assignment_ambiguous');
        }
        return $id;
      }
      $definition = $this->integrations->readTransferDefinition($scope, $ids[0]);
      if ($trigger !== 'staff' && self::trigger($definition['assignment'])['mode'] !== $trigger) {
        // Not due for this trigger: no row, no audit, no error.
        return NULL;
      }
      $revision = $this->revisions->resolve($application, $form);
      $binding = $this->binding($definition, $revision);
      $definition = $this->integrations->lockTransferDefinition($scope, $ids[0]);
      $this->lockSourceForm($form, $revision);
      $this->assertDefinition($definition, $scope, (string) $form->uuid(), $revision);
      $binding = $this->binding($definition, $revision);
      // The advisory gate precedes the planning transaction. Enforce the bulk
      // invariant on the locked binding too, including explicit replanning.
      if ($application->hasField('field_origin') && $application->get('field_origin')->value === 'bulk'
        && $binding['dispatch_mode'] !== 'approval') {
        throw new IntegrationException('integration.handover_approval_mode_required', 409);
      }
      // Operator confirmation precedes mapping and sensitive payload retention.
      $this->profiles->settings($binding);
      $envelope = $this->envelopes->buildTransfer($application, $revision, (bool) $binding['connection_snapshot']['send_attachments']);
      $built = $this->buildPayload($application, $definition, $revision);
      $binding['validation_errors'] = json_encode($built['errors'], JSON_THROW_ON_ERROR);
      $status = $built['valid']
        ? ($binding['dispatch_mode'] === 'approval' ? DeliveryLogRepository::STATUS_AWAITING_APPROVAL : DeliveryLogRepository::STATUS_QUEUED)
        : DeliveryLogRepository::STATUS_FAILED;
      if ($built['valid']) {
        $binding['payload_reference'] = $this->payloads->store((int) $application->id(), $built['json']);
        $binding['payload_sha256'] = $built['sha256'];
      }
      $id = $this->createBoundRow($application, $binding, $status, $envelope);
      if ($replaces !== NULL) {
        $this->database->update('beantragt_delivery_log')->fields(['replaces_uuid' => $replaces])->condition('id', $id)->execute();
        $this->payloads->purgeTerminalPayload((int) $old['id']);
        $this->audit($application, $this->logs->load($id)['uuid'], $uid, 'replan', 'success');
      }
      if ($replaces === NULL && $uid > 0) {
        $this->audit($application, $this->logs->load($id)['uuid'], $uid, 'create_transfer', 'success');
      }
      if (!$built['valid']) {
        $this->logs->markResult($id, DeliveryResult::failed('integration.payload_invalid', 'Mapped payload does not satisfy the pinned contract.'));
      }
      elseif ($status === DeliveryLogRepository::STATUS_QUEUED) {
        $this->enqueue($id, (int) $application->id());
      }
      return $id;
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
        unset($transaction);
      }
      if ($e instanceof IntegrationException && $e->errorCode === 'integration.revision_not_covered' && $replaces === NULL) {
        return NULL;
      }
      if ($e instanceof IntegrationException && $e->errorCode === 'integration.handover_approval_mode_required') {
        throw $e;
      }
      if ($binding !== NULL && $replaces === NULL) {
        unset($binding['payload_reference'], $binding['payload_sha256']);
        $binding['validation_errors'] = '[]';
        $code = $e instanceof IntegrationException ? $e->errorCode : 'integration.planning_failed';
        $state = TransferFailures::recoverable($code) ? DeliveryLogRepository::STATUS_BLOCKED : DeliveryLogRepository::STATUS_FAILED;
        $id = $this->createBoundRow($application, $binding, $state);
        if ($state === DeliveryLogRepository::STATUS_BLOCKED) {
          $this->logs->markBlocked($id, $code);
        }
        else {
          $this->logs->markResult($id, DeliveryResult::failed($code, 'Transfer planning failed.'));
        }
        if ($uid > 0) {
          $this->audit($application, $this->logs->load($id)['uuid'], $uid, 'create_transfer', 'denied', $code);
        }
        return $id;
      }
      throw $e;
    }
    finally {
      unset($transaction);
      $this->lock->release($lock_name);
    }
  }

  /**
   * Builds only from the frozen form revision, never its current default.
   */
  private function buildPayload(ApplicationInterface $application, array $definition, int $revision): array {
    $storage = $this->entities->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new IntegrationException('integration.revision_not_covered');
    }
    $form = $storage->loadRevision($revision);
    if (!$form instanceof FormSchemaInterface) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    return $this->builder->build(
      $this->protection->revealData(json_decode($application->getData() ?? '{}', TRUE, flags: JSON_THROW_ON_ERROR)),
      $definition['version']['rules'], $definition['mapping']['contract_key'], $definition['mapping']['contract_version'],
      json_decode((string) $form->get('field_schema')->value, TRUE, flags: JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Persists a complete, non-secret binding and an explicitly scoped manifest.
   */
  private function createBoundRow(ApplicationInterface $application, array $binding, string $status, ?DeliveryEnvelope $envelope = NULL): int {
    $snapshot = $binding['connection_snapshot'];
    $binding['connection_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
    return $this->logs->createQueued($application, $envelope ?? $this->envelope($application, FALSE),
      'fit_connect', $snapshot['destination_id'], $binding, $status);
  }

  /**
   * Attachments are opt-in, independently of mapping source fields.
   */
  private function envelope(ApplicationInterface $application, bool $attachments): DeliveryEnvelope {
    $envelope = $this->envelopes->build($application);
    return $attachments ? $envelope : new DeliveryEnvelope($envelope->applicationId, $envelope->applicationUuid,
      $envelope->referenceNumber, $envelope->schemaUuid, $envelope->schemaLabel, $envelope->schemaVersion,
      $envelope->dataHash, $envelope->fieldCount, 0, $envelope->workspace, []);
  }

  /**
   * Rechecks all mutable prerequisites in one locked transaction before HTTP.
   */
  public function prepareSend(array $row, ApplicationInterface $application, bool $require_payload = TRUE, bool $require_approval = TRUE): array {
    $transaction = $this->database->startTransaction();
    try {
      $this->lockApplication($application);
      $application = $this->freshApplication($application);
      if ($this->isAnonymised($application)) {
        throw new IntegrationException('integration.application_anonymised');
      }
      if (($row['binding_integrity'] ?? '') === 'failed') {
        throw new IntegrationException('integration.binding_invalid');
      }
      $definition = $this->integrations->lockTransferDefinition((int) $row['scope_group_id'], (string) $row['integration_assignment_uuid']);
      $scope = $this->scopeAccess->integrationScope($application);
      $form = $this->sourceForm($application);
      $pinned = $application->hasField('field_schema_revision_id') && !$application->get('field_schema_revision_id')->isEmpty();
      $revision = $form === NULL ? 0 : ($pinned ? $this->revisions->resolve($application, $form) : (int) ($row['form_schema_revision_id'] ?? 0));
      if ($form !== NULL && (!$application->hasField('field_schema_revision_id') || $application->get('field_schema_revision_id')->isEmpty())) {
        // Historical version resolution happens once. Later lifecycle-only
        // revisions must never replace a transfer's already frozen revision.
        $revision = (int) ($row['form_schema_revision_id'] ?? 0);
        $storage = $this->entities->getStorage('form_schema');
        $historical = $storage instanceof RevisionableStorageInterface ? $storage->loadRevision($revision) : NULL;
        if (!$historical instanceof FormSchemaInterface || $historical->uuid() !== $form->uuid()
          || $historical->getVersion() !== $application->getSchemaVersion()) {
          throw new IntegrationException('integration.revision_not_covered');
        }
      }
      if ($form !== NULL) {
        $this->lockSourceForm($form, $revision);
      }
      if (!$application->isEmailConfirmed()) {
        throw new IntegrationException('integration.confirmation_required');
      }
      $this->assertDefinition($definition, $scope, (string) $form?->uuid(), $revision);
      $current = $this->binding($definition, $revision);
      foreach ($current as $key => $value) {
        if ($key === 'dispatch_approval_status') {
          continue;
        }
        if ($key === 'connection_snapshot') {
          $snapshot = $row[$key] ?? [];
          unset($value['label'], $value['mapping_label'], $snapshot['label'], $snapshot['mapping_label']);
          if (IntegrationRules::canonical($value) !== IntegrationRules::canonical($snapshot)) {
            throw new IntegrationException('integration.binding_changed');
          }
        }
        elseif (($row[$key] ?? NULL) !== $value) {
          throw new IntegrationException('integration.binding_changed');
        }
      }
      if ($require_approval && $row['dispatch_mode'] === 'approval' && ($row['dispatch_approval_status'] !== 'approved'
        || empty($row['dispatch_approved_uid']) || empty($row['dispatch_approved_at']))) {
        throw new IntegrationException('integration.dispatch_approval_required');
      }
      if ($require_approval && $row['dispatch_mode'] === 'approval') {
        $users = $this->entities->getStorage('user');
        $users->resetCache([$row['dispatch_approved_uid']]);
        $approver = $users->load($row['dispatch_approved_uid']);
        if (!$approver instanceof UserInterface || !$this->scopeAccess->canApproveDispatch($application, $approver)) {
          throw new IntegrationException('integration.dispatch_approver_unauthorized');
        }
        if (($refusal = $this->gates?->approvalRefusal($application, $row, (int) $approver->id())) !== NULL) {
          throw new IntegrationException($refusal, 409);
        }
      }
      if ($require_payload && (($row['validation_errors'] ?? []) !== [] || empty($row['payload_reference']))) {
        throw new IntegrationException('integration.payload_invalid');
      }
      if (!hash_equals((string) $row['dataHash'], hash('sha256', $application->getData() ?? '{}'))) {
        throw new IntegrationException('integration.application_changed');
      }
      if ($require_payload) {
        $envelope = $this->envelopes->buildTransfer($application, $revision, (bool) ($row['connection_snapshot']['send_attachments'] ?? FALSE));
        if (IntegrationRules::canonical($envelope->attachmentManifest) !== IntegrationRules::canonical($row['metadata']['attachmentManifest'] ?? [])) {
          throw new IntegrationException('integration.attachment_changed');
        }
      }
      $settings = $this->profiles->settings($row);
      $json = $require_payload ? $this->payloads->verified($row) : '';
      return ['settings' => $settings, 'json' => $json];
    }
    finally {
      unset($transaction);
    }
  }

  /**
   * Permits only migration-marked unbound legacy rows to use global settings.
   */
  public function assertLegacy(array $row): void {
    if (($row['legacy_eligible'] ?? NULL) !== 1) {
      throw new IntegrationException('integration.legacy_delivery_forbidden');
    }
    foreach (DeliveryLogRepository::bindingFields() as $key => $unused) {
      if ($key !== 'legacy_eligible' && ($row[$key] ?? NULL) !== NULL) {
        throw new IntegrationException('integration.binding_invalid');
      }
    }
  }

  /**
   * Reads safe queue metadata for at most 500 caller-authorized applications.
   *
   * @param int[] $application_ids
   *   Application IDs already bounded to the caller's scope.
   * @param string[] $statuses
   *   Transfer states to include.
   *
   * @return array<int, array{uuid: string, application_id: int, status: string, code: string, changed: int}>
   *   Minimal rows, newest first; no payload or raw error text.
   */
  public function transfersForApplications(array $application_ids, array $statuses): array {
    if (count($application_ids) > 500) {
      throw new \InvalidArgumentException('At most 500 application IDs are allowed.');
    }
    if ($application_ids === [] || $statuses === []) {
      return [];
    }
    $rows = $this->database->select('beantragt_delivery_log', 'd')
      ->fields('d', ['uuid', 'application_id', 'status', 'last_error', 'changed'])
      ->condition('application_id', $application_ids, 'IN')->condition('status', $statuses, 'IN')
      ->orderBy('changed', 'DESC')->orderBy('uuid')->execute()->fetchAll(FetchAs::Associative);
    return array_map(static function (array $row): array {
      $code = explode(':', (string) $row['last_error'], 2)[0];
      return [
        'uuid' => (string) $row['uuid'],
        'application_id' => (int) $row['application_id'],
        'status' => (string) $row['status'],
        'code' => preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $code) ? $code : 'integration.internal_error',
        'changed' => (int) $row['changed'],
      ];
    }, $rows);
  }

  /**
   * Returns internal rows for the controller's explicit safe projection.
   */
  public function transfers(ApplicationInterface $application): array {
    return $this->logs->listForApplication($application, 100, TRUE);
  }

  /**
   * Approves once under a row lock; double clicks return the existing state.
   */
  public function approve(ApplicationInterface $application, string $uuid, int $uid): array {
    return $this->mutate($application, $uuid, $uid, 'approve_dispatch');
  }

  /**
   * Explicit transport retry has a separate manual budget.
   */
  public function retry(ApplicationInterface $application, string $uuid, int $uid = 0): array {
    return $this->mutate($application, $uuid, $uid, 'retry');
  }

  /**
   * Resumes a parked transfer only after live prerequisites recover.
   */
  public function resume(ApplicationInterface $application, string $uuid, int $uid): array {
    return $this->mutate($application, $uuid, $uid, 'resume');
  }

  /**
   * Replaces only a stale-data failure; the old row remains immutable history.
   */
  public function replan(ApplicationInterface $application, string $uuid, int $uid): array {
    $id = $this->plan($application, $uuid, $uid, 'staff');
    if ($id === NULL) {
      throw new IntegrationException('integration.assignment_unavailable', 409);
    }
    return $this->logs->load($id);
  }

  /**
   * A bound transfer that holds frozen data but has not started any send.
   *
   * Changing the case data dooms exactly these rows: prepareSend() compares
   * the frozen data hash before every approval and every dispatch.
   */
  public function isWaitingForDispatch(array $row): bool {
    return ($row['adapter'] ?? '') === 'fit_connect' && ($row['scope_group_id'] ?? NULL) !== NULL
      && in_array($row['status'] ?? '', [
        DeliveryLogRepository::STATUS_QUEUED,
        DeliveryLogRepository::STATUS_AWAITING_APPROVAL,
      ], TRUE)
      && ($row['metadata']['fitConnect'] ?? []) === []
      && ($row['binding_integrity'] ?? '') !== 'failed';
  }

  /**
   * Fails waiting transfers at once after a committed staff data edit.
   *
   * The dispatcher would reject these rows with the same code on its next
   * run. Recording the result exposes both the outcome and the replan path
   * immediately. Without the shared application lock a send may be running;
   * that send fences itself through prepareSend(), so nothing is marked here.
   *
   * @return string[]
   *   UUIDs of the transfers that were marked as failed.
   */
  public function invalidateChangedData(ApplicationInterface $application, int $uid = 0): array {
    // Most edited cases have no waiting transfer: skip the shared lock, so an
    // edit never makes a concurrent approval or dispatcher run back off.
    if ($this->waitingCandidates($application)->range(0, 1)->execute()->fetchField() === FALSE) {
      return [];
    }
    $lock_name = Application::deletionLockName((int) $application->id());
    if (!$this->lock->acquire($lock_name, 300.0)) {
      return [];
    }
    $failed = [];
    try {
      $transaction = $this->database->startTransaction();
      $this->lockApplication($application);
      $application = $this->freshApplication($application);
      if ($this->isAnonymised($application)) {
        return [];
      }
      $hash = hash('sha256', $application->getData() ?? '{}');
      $query = $this->waitingCandidates($application);
      $query->forUpdate();
      foreach ($query->execute()->fetchCol() as $id) {
        $row = $this->logs->load((int) $id);
        // A leased queue item belongs to a worker that may already be sending.
        if ($row === NULL || !$this->isWaitingForDispatch($row) || hash_equals((string) $row['dataHash'], $hash)
          || $this->hasLeasedQueueItem($row)) {
          continue;
        }
        $this->logs->markResult($row['id'], DeliveryResult::failed('integration.application_changed', 'Application data changed before dispatch.'));
        $this->payloads->purgeTerminalPayload($row['id']);
        $this->audit($application, $row['uuid'], $uid, 'invalidate', 'success', 'integration.application_changed');
        $failed[] = $row['uuid'];
      }
      return $failed;
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
    finally {
      unset($transaction);
      $this->lock->release($lock_name);
    }
  }

  /**
   * Selects the rows a data edit may still stop, in a stable order.
   */
  private function waitingCandidates(ApplicationInterface $application): SelectInterface {
    return $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
      ->condition('application_id', (int) $application->id())
      ->condition('adapter', 'fit_connect')
      ->condition('status', [DeliveryLogRepository::STATUS_QUEUED, DeliveryLogRepository::STATUS_AWAITING_APPROVAL], 'IN')
      ->orderBy('id');
  }

  /**
   * Separates automatic retry ownership from human intervention.
   */
  public function canAutoRequeue(array $row): bool {
    $max = max(1, min(10, (int) ($this->config->get('beantragt_delivery.settings')->get('max_attempts') ?: 3)));
    $automatic = $row;
    $automatic['manual_resumes'] = 0;
    return $this->canRetry($automatic) && (int) ($row['automatic_attempts'] ?? $row['attempts']) < $max;
  }

  /**
   * Manual transport retry does not inherit the automatic attempt cap.
   */
  public function canRetry(array $row): bool {
    return $this->canRecover($row) && $row['status'] === DeliveryLogRepository::STATUS_FAILED
      && in_array(TransferFailures::code($row), TransferFailures::TRANSPORT, TRUE)
      && ($row['dispatch_mode'] !== 'approval' || $row['dispatch_approval_status'] === 'approved')
      && (!empty($row['payload_reference']) || ($row['legacy_eligible'] ?? NULL) === 1);
  }

  /**
   * Only explicit configuration blocks may resume with the same binding.
   */
  public function canResume(array $row): bool {
    return $this->canRecover($row) && $row['status'] === DeliveryLogRepository::STATUS_BLOCKED
      && TransferFailures::recoverable(TransferFailures::code($row));
  }

  /**
   * A checkpoint always requires reconciliation, including after data edits.
   */
  public function canReplan(array $row, ?ApplicationInterface $application = NULL): bool {
    return $row['adapter'] === 'fit_connect' && $row['status'] === DeliveryLogRepository::STATUS_FAILED
      && ($row['binding_integrity'] ?? '') !== 'failed'
      && ($row['metadata']['fitConnect'] ?? []) === []
      && TransferFailures::classify(TransferFailures::code($row)) === 'permanent'
      && !in_array(TransferFailures::code($row), ['integration.application_anonymised', 'integration.already_submitted'], TRUE)
      && ($application === NULL || $this->creationFailure($application, $row) === NULL);
  }

  /**
   * Shared invariant for bounded staff intervention.
   */
  private function canRecover(array $row): bool {
    return $row['adapter'] === 'fit_connect' && ($row['metadata']['fitConnect'] ?? []) === []
      && (int) ($row['manual_resumes'] ?? 0) < TransferFailures::MANUAL_LIMIT
      && ($row['binding_integrity'] ?? '') !== 'failed';
  }

  /**
   * Loads a row using both its case and its current tenant as boundaries.
   */
  private function ownedRow(ApplicationInterface $application, string $uuid): array {
    $id = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
      ->condition('uuid', strtolower($uuid))->condition('application_id', (int) $application->id())->execute()->fetchField();
    $row = $id === FALSE ? NULL : $this->logs->load((int) $id);
    if ($row === NULL || ($row['adapter'] === 'fit_connect' && $row['scope_group_id'] === NULL)
      || ($row['scope_group_id'] !== NULL && $row['scope_group_id'] !== $this->scopeAccess->integrationScope($application))) {
      throw new IntegrationException('integration.transfer_not_found', 404);
    }
    return $row;
  }

  /**
   * Writes successes/noops in the transition transaction, denials afterward.
   *
   * A status planning denial is also remembered for statusPlanningDenial(),
   * whether or not the audit writer stores the row.
   */
  public function audit(ApplicationInterface $application, string $uuid, int $uid, string $operation, string $outcome, string $code = ''): void {
    if ($operation === 'plan_on_status' && $outcome === 'denied' && $code !== '') {
      $this->statusDenials[(int) $application->id()] = $code;
    }
    $this->logs->audit((int) $application->id(), (string) $application->uuid(),
      $this->scopeAccess->integrationScope($application) ?: NULL, $uuid, $uid, $operation, $outcome, $code);
  }

  /**
   * The id of the newest audit row of this application, 0 without rows.
   */
  public function lastAuditId(ApplicationInterface $application): int {
    $query = $this->database->select('beantragt_transfer_audit', 'a')
      ->condition('application_id', (int) $application->id());
    $query->addExpression('MAX(id)', 'max_id');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Forgets the remembered status planning denial before a new status save.
   */
  public function forgetStatusPlanningDenial(ApplicationInterface $application): void {
    unset($this->statusDenials[(int) $application->id()]);
  }

  /**
   * The code of the status planning denial since the mark, or NULL.
   *
   * The status hook plans in the process that saved the status, so the
   * denial remembered since forgetStatusPlanningDenial() wins. The audit
   * writer collapses identical denials of one person per case and minute
   * and a repeat then writes no row; the remembered code still names it.
   * Without one, the newest plan_on_status denial row after the audit id
   * answers.
   */
  public function statusPlanningDenial(ApplicationInterface $application, int $afterId): ?string {
    if (isset($this->statusDenials[(int) $application->id()])) {
      return $this->statusDenials[(int) $application->id()];
    }
    $code = $this->database->select('beantragt_transfer_audit', 'a')->fields('a', ['code'])
      ->condition('application_id', (int) $application->id())
      ->condition('action', 'plan_on_status')->condition('outcome', 'denied')
      ->condition('id', $afterId, '>')->condition('code', '', '<>')
      ->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
    return is_string($code) && $code !== '' ? $code : NULL;
  }

  /**
   * Serializes state changes and durably audits before queue visibility.
   */
  private function mutate(ApplicationInterface $application, string $uuid, int $uid, string $operation): array {
    $lock_name = Application::deletionLockName((int) $application->id());
    if (!$this->lock->acquire($lock_name, 300.0)) {
      $application = $this->freshApplication($application);
      $this->assertActor($application, $uid);
      $row = $this->ownedRow($application, $uuid);
      if (($operation === 'approve_dispatch' && $row['dispatch_approval_status'] === 'approved')
        || ($operation === 'retry' && $row['status'] === DeliveryLogRepository::STATUS_QUEUED)) {
        $this->audit($application, $uuid, $uid, $operation, 'noop');
        return $row;
      }
      throw new IntegrationException('integration.transfer_busy', 409);
    }
    try {
      $this->ensureQueueStorage();
      $transaction = $this->database->startTransaction();
      $this->lockApplication($application);
      $application = $this->freshApplication($application);
      $this->assertActor($application, $uid);
      if ($this->isAnonymised($application)) {
        throw new IntegrationException('integration.application_anonymised', 409);
      }
      $query = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
        ->condition('uuid', strtolower($uuid))->condition('application_id', (int) $application->id());
      $query->forUpdate();
      $query->execute()->fetchField();
      $row = $this->ownedRow($application, $uuid);
      if (($operation === 'approve_dispatch' && $row['dispatch_approval_status'] === 'approved')
        || ($operation === 'retry' && $row['status'] === DeliveryLogRepository::STATUS_QUEUED)) {
        $this->audit($application, $uuid, $uid, $operation, 'noop');
        return $row;
      }
      $approval = $operation === 'approve_dispatch';
      if ($approval && ($row['status'] !== DeliveryLogRepository::STATUS_AWAITING_APPROVAL || $row['dispatch_mode'] !== 'approval' || $uid < 1)) {
        throw new IntegrationException('integration.dispatch_not_approvable', 409);
      }
      if (!$approval && !(($operation === 'resume' && $this->canResume($row)) || ($operation === 'retry' && $this->canRetry($row)))) {
        throw new IntegrationException($operation === 'resume' ? 'integration.resume_not_allowed' : 'integration.retry_not_allowed', 409);
      }
      if (!$approval && $this->hasLeasedQueueItem($row)) {
        throw new IntegrationException('integration.transfer_busy', 409);
      }
      $needs_payload = empty($row['payload_reference']) && $row['scope_group_id'] !== NULL;
      if ($approval || ($operation === 'resume' && !$needs_payload && $row['dispatch_mode'] === 'approval')) {
        if (($refusal = $this->gates?->approvalRefusal($application, $row, $uid)) !== NULL) {
          throw new IntegrationException($refusal, 409);
        }
        // Explicit resume renews approval under the staff member's live access.
        $row['dispatch_approval_status'] = 'approved';
        $row['dispatch_approved_uid'] = $uid;
        $row['dispatch_approved_at'] = $this->time->getCurrentTime();
      }
      try {
        if ($row['scope_group_id'] !== NULL) {
          $this->prepareSend($row, $application, !$needs_payload, !$needs_payload);
        }
        else {
          $this->assertLegacy($row);
        }
      }
      catch (IntegrationException $e) {
        if ($operation === 'retry' && $e->errorCode === 'integration.four_eyes_required') {
          $this->logs->markBlocked((int) $row['id'], $e->errorCode);
          $this->audit($application, $uuid, $uid, $operation, 'denied', $e->errorCode);
          // Keep the block durable so another person can renew approval.
          unset($transaction);
          throw new IntegrationException($e->errorCode, 409);
        }
        if (TransferFailures::recoverable($e->errorCode)) {
          throw new IntegrationException($e->errorCode, 409);
        }
        $this->logs->markResult($row['id'], DeliveryResult::failed($e->errorCode, 'Transfer prerequisites no longer hold.'));
        $this->payloads->purgeTerminalPayload((int) $row['id']);
        $this->audit($application, $uuid, $uid, $operation, 'denied', $e->errorCode);
        unset($transaction);
        throw $e;
      }
      $fields = [
        'status' => DeliveryLogRepository::STATUS_QUEUED,
        'changed' => $this->time->getCurrentTime(),
        'last_error' => '',
      ];
      if ($needs_payload) {
        $definition = $this->integrations->lockTransferDefinition($row['scope_group_id'], $row['integration_assignment_uuid']);
        $envelope = $this->envelopes->buildTransfer($application, $row['form_schema_revision_id'], (bool) ($row['connection_snapshot']['send_attachments'] ?? FALSE));
        $built = $this->buildPayload($application, $definition, $row['form_schema_revision_id']);
        if (!$built['valid']) {
          $this->database->update('beantragt_delivery_log')->fields(['validation_errors' => json_encode($built['errors'], JSON_THROW_ON_ERROR)])->condition('id', $row['id'])->execute();
          $this->logs->markResult($row['id'], DeliveryResult::failed('integration.payload_invalid', 'Mapped payload does not satisfy the pinned contract.'));
          $this->audit($application, $uuid, $uid, $operation, 'denied', 'integration.payload_invalid');
          return $this->logs->load($row['id']);
        }
        $fields += [
          'payload_reference' => $this->payloads->store((int) $application->id(), $built['json']),
          'payload_sha256' => $built['sha256'],
          'validation_errors' => '[]',
          'metadata' => json_encode(array_merge($row['metadata'], [
            'attachmentManifest' => $envelope->attachmentManifest,
            'attachmentCount' => $envelope->attachmentCount,
          ]), JSON_THROW_ON_ERROR),
        ];
        if ($row['dispatch_mode'] === 'approval') {
          $fields['status'] = DeliveryLogRepository::STATUS_AWAITING_APPROVAL;
        }
      }
      if ($approval || ($operation === 'resume' && !$needs_payload && $row['dispatch_mode'] === 'approval')) {
        $fields += array_intersect_key($row, array_flip([
          'dispatch_approval_status', 'dispatch_approved_uid', 'dispatch_approved_at',
        ]));
      }
      if (!$approval) {
        $fields += ['manual_resumes' => (int) ($row['manual_resumes'] ?? 0) + 1, 'automatic_attempts' => 0];
      }
      $this->database->update('beantragt_delivery_log')->fields($fields)->condition('id', $row['id'])->execute();
      if ($approval || ($operation === 'resume' && !$needs_payload && $row['dispatch_mode'] === 'approval')) {
        $this->gates?->onApproved($application, $this->logs->load($row['id']), $uid);
      }
      $this->audit($application, $uuid, $uid, $operation, 'success');
      if ($fields['status'] === DeliveryLogRepository::STATUS_QUEUED) {
        $this->enqueue($row['id'], (int) $application->id());
      }
      return $this->logs->load($row['id']);
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
    finally {
      unset($transaction);
      $this->lock->release($lock_name);
    }
  }

  /**
   * Accepted transfers no longer need a decryptable payload copy.
   */
  public function completeAccepted(array $row): void {
    if (!empty($row['payload_reference'])) {
      $this->payloads->deleteReference($row['payload_reference']);
      $this->database->update('beantragt_delivery_log')->fields(['payload_reference' => NULL])->condition('id', $row['id'])->execute();
    }
  }

  /**
   * Rechecks the acting human after the fresh application is locked/reloaded.
   */
  private function assertActor(ApplicationInterface $application, int $uid): void {
    $storage = $this->entities->getStorage('user');
    $storage->resetCache([$uid]);
    $actor = $uid > 0 ? $storage->load($uid) : NULL;
    if (!$actor instanceof UserInterface || !$this->scopeAccess->canApproveDispatch($application, $actor)) {
      throw new IntegrationException('integration.access_denied', 403);
    }
  }

  /**
   * A finishing worker still owns its leased item until core deletes it.
   */
  private function hasLeasedQueueItem(array $row): bool {
    if (!$this->queueTableExists()) {
      return FALSE;
    }
    $data = ['logId' => $row['id'], 'applicationId' => $row['applicationId'], 'adapter' => 'fit_connect'];
    return (bool) $this->database->select('queue', 'q')->fields('q', ['item_id'])
      ->condition('name', DeliveryDispatcher::QUEUE_NAME)->condition('data', serialize($data))
      ->condition('expire', 0, '>')->range(0, 1)->execute()->fetchField();
  }

  /**
   * Loads the current case after acquiring its shared deletion/delivery lock.
   */
  private function freshApplication(ApplicationInterface $application): ApplicationInterface {
    $storage = $this->entities->getStorage('application');
    $storage->resetCache([$application->id()]);
    $fresh = $storage->load($application->id());
    if (!$fresh instanceof ApplicationInterface || $fresh->uuid() !== $application->uuid()) {
      throw new IntegrationException('integration.application_unavailable', 404);
    }
    return $fresh;
  }

  /**
   * Locks the application's base row against concurrent field/scope writes.
   */
  private function lockApplication(ApplicationInterface $application): void {
    $table = $this->entities->getDefinition('application')->getBaseTable();
    $query = $this->database->select($table, 'a')->fields('a', ['id'])->condition('id', (int) $application->id());
    $query->forUpdate();
    $query->execute()->fetchField();
  }

  /**
   * Locks historical and current source rows before inspecting mutable scope.
   */
  private function lockSourceForm(FormSchemaInterface $form, int $revision): void {
    foreach ([
      'form_schema' => ['id', (int) $form->id()],
      'form_schema_revision' => ['revision_id', $revision],
    ] as $table => [$key, $id]) {
      $query = $this->database->select($table, 'f')->fields('f', [$key])->condition($key, $id);
      $query->forUpdate();
      if ($query->execute()->fetchField() === FALSE) {
        throw new IntegrationException('integration.form_schema_not_found', 404);
      }
    }
    $this->entities->getStorage('form_schema')->resetCache([$form->id()]);
  }

  /**
   * Resolves the referenced form, never a tenant-selected external resource.
   */
  private function sourceForm(ApplicationInterface $application): ?FormSchemaInterface {
    $form = $application->hasField('field_schema') ? $application->get('field_schema')->entity : NULL;
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * Fetches at most two matching IDs: this slice never fans out or picks first.
   */
  private function assignmentIds(int $scope, string $form): array {
    return $this->database->select('beantragt_integration_assignment', 'a')->fields('a', ['uuid'])
      ->condition('scope_group_id', $scope)->condition('form_schema_uuid', $form)->condition('status', 'active')
      ->orderBy('uuid')->range(0, 2)->execute()->fetchCol();
  }

  /**
   * Checks live lifecycle, scope, source revision, contract and rule integrity.
   */
  private function assertDefinition(array $definition, int $scope, string $form, int $revision): void {
    ['assignment' => $assignment, 'connection' => $connection, 'mapping' => $mapping, 'version' => $version] = $definition;
    if (($version['integrity'] ?? 'ok') !== 'ok') {
      throw new IntegrationException('integration.mapping_checksum_mismatch');
    }
    foreach ($definition as $row) {
      if ($row['scope_group_id'] !== $scope || $scope < 1) {
        throw new IntegrationException('integration.scope_mismatch');
      }
    }
    foreach ([
      'assignment' => 'integration.assignment_inactive',
      'connection' => 'integration.connection_disabled',
      'version' => 'integration.mapping_inactive',
    ] as $key => $code) {
      if ($definition[$key]['status'] !== 'active') {
        throw new IntegrationException($code);
      }
    }
    if ($assignment['form_schema_uuid'] !== $form || $version['form_schema_uuid'] !== $form
      || !$this->revisions->covers($revision, $assignment['compatible_revisions'])
      || !$this->revisions->covers($revision, $version['compatible_revisions'])) {
      throw new IntegrationException('integration.revision_not_covered');
    }
    $this->integrations->assertTransferForm($form, $revision, $scope);
    $projected = $this->integrations->load('assignment', $assignment['uuid']);
    foreach ($projected['blocked_reasons'] as $reason) {
      // Current-form coverage and a function the form switched off after the
      // rule was activated are management warnings, not in-flight vetoes.
      if (!in_array($reason['code'], ['integration.revision_not_covered', 'integration.coupling_status_missing'], TRUE)) {
        throw new IntegrationException($reason['code']);
      }
    }
    if ($connection['contract_key'] !== $mapping['contract_key'] || $connection['contract_version'] !== $mapping['contract_version']
      || $connection['service_urn'] !== $assignment['service_urn']) {
      throw new IntegrationException('integration.contract_mismatch');
    }
    if (!hash_equals($version['checksum'], hash('sha256', IntegrationRules::canonical($version['rules'])))) {
      throw new IntegrationException('integration.mapping_checksum_mismatch');
    }
    $errors = $this->profiles->check($connection['credential_profile'], $scope);
    if ($errors !== []) {
      throw new IntegrationException($errors[0]['code']);
    }
    if (count($this->assignmentIds($scope, $form)) !== 1) {
      throw new IntegrationException('integration.assignment_ambiguous');
    }
  }

  /**
   * Reads the trigger of an assignment row; legacy rows plan on submission.
   *
   * @return array{mode: string, flag: string}
   *   The trigger mode and the trigger flag.
   */
  private static function trigger(array $assignment): array {
    $mode = $assignment['transfer_trigger'] ?? 'submission';
    $flag = $assignment['trigger_flag'] ?? WorkflowStatusInterface::FLAG_HAND_OVER;
    return [
      'mode' => in_array($mode, IntegrationRepository::TRANSFER_TRIGGERS, TRUE) ? $mode : 'submission',
      'flag' => in_array($flag, WorkflowStatusInterface::TRIGGER_FLAGS, TRUE) ? $flag : WorkflowStatusInterface::FLAG_HAND_OVER,
    ];
  }

  /**
   * Whether the stored current status of the application carries the flag.
   *
   * Unlike catalogueHasFlag() an unknown status counts as not carrying it:
   * recovery of a status trigger must prove the trigger still holds.
   */
  public function currentStatusHasFlag(ApplicationInterface $application, string $flag): bool {
    if (!$this->entities->hasDefinition('workflow_status')) {
      return FALSE;
    }
    $status_id = $application->getWorkflowStatusId();
    $status = $status_id === NULL ? NULL : $this->entities->getStorage('workflow_status')->load($status_id);
    return $status instanceof WorkflowStatusInterface && $status->hasSemanticFlag($flag);
  }

  /**
   * Whether the application's governing workflow has a status with the flag.
   *
   * Asks the workflow that governs the application (the form's own, or the
   * standard of the organisation graph), not the whole catalogue: a status
   * the form never switched on can never start the transfer. Unknown
   * catalogues count as having it: only a known gap is reported.
   */
  private function catalogueHasFlag(ApplicationInterface $application, string $flag): bool {
    if (!$this->entities->hasDefinition('workflow_status')) {
      return TRUE;
    }
    $status_id = $application->getWorkflowStatusId();
    $storage = $this->entities->getStorage('workflow_status');
    $current = $status_id === NULL ? NULL : $storage->load($status_id);
    if (!$current instanceof WorkflowStatusInterface || $current->getGroupId() === NULL) {
      return TRUE;
    }
    if ($this->workflowResolver !== NULL) {
      $ids = $this->workflowResolver->governingStatusIds($application, $current->getGroupId());
      foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $status) {
        if ($status instanceof WorkflowStatusInterface && $status->hasSemanticFlag($flag)) {
          return TRUE;
        }
      }
      return FALSE;
    }
    return (bool) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $current->getGroupId())
      ->condition('field_semantic_flags', $flag)
      ->range(0, 1)
      ->count()
      ->execute();
  }

  /**
   * Freezes only non-secret identity and display fields.
   */
  private function binding(array $definition, int $revision): array {
    ['assignment' => $assignment, 'connection' => $connection, 'mapping' => $mapping, 'version' => $version] = $definition;
    $schema = $this->registry->load($mapping['contract_key'], $mapping['contract_version']);
    return [
      'scope_group_id' => $assignment['scope_group_id'],
      'integration_assignment_uuid' => $assignment['uuid'],
      'integration_connection_uuid' => $connection['uuid'],
      'integration_mapping_uuid' => $mapping['uuid'],
      'integration_mapping_version' => $version['version'],
      'integration_mapping_checksum' => $version['checksum'],
      'form_schema_uuid' => $assignment['form_schema_uuid'],
      'form_schema_revision_id' => $revision,
      'service_urn' => $assignment['service_urn'],
      'contract_key' => $mapping['contract_key'],
      'contract_version' => $mapping['contract_version'],
      'dispatch_mode' => $assignment['dispatch_mode'],
      'dispatch_approval_status' => $assignment['dispatch_mode'] === 'approval' ? 'pending' : 'not_required',
      'connection_snapshot' => [
        'label' => $connection['label'],
        'destination_id' => $connection['destination_id'],
        'environment' => $connection['environment'],
        'credential_profile' => $connection['credential_profile'],
        'schema_uri' => $schema['$id'],
        'mapping_label' => $mapping['label'],
        'reply_process_standard' => $connection['reply_process_standard'] ?? '',
        'send_attachments' => (bool) ($assignment['send_attachments'] ?? FALSE),
      ],
    ];
  }

  /**
   * Creates core queue storage before any business transaction or row lock.
   *
   * DatabaseQueue::createQueue() is a no-op. Lazy DDL inside createItem() would
   * implicitly commit MySQL transactions, so an outer transaction fails closed.
   */
  private function ensureQueueStorage(): void {
    $queue = $this->queue->get(DeliveryDispatcher::QUEUE_NAME);
    if (!$queue instanceof DatabaseQueue) {
      throw new IntegrationException('integration.queue_backend_unsupported', 409);
    }
    if (!$this->queueTableExists()) {
      if ($this->database->inTransaction()) {
        throw new IntegrationException('integration.queue_backend_unsupported', 409);
      }
      try {
        $this->database->schema()->createTable('queue', $queue->schemaDefinition());
      }
      catch (\Exception $e) {
        // Concurrent creation is harmless; other DDL failures are not.
        if (!$this->queueTableExists()) {
          throw $e;
        }
      }
    }
  }

  /**
   * Observes concurrent first-use DDL rather than caching a previous absence.
   *
   * @phpstan-impure
   */
  private function queueTableExists(): bool {
    return $this->database->schema()->tableExists('queue');
  }

  /**
   * Adds a queue item within the same database transaction as its transition.
   */
  private function enqueue(int $id, int $application_id): void {
    $queue = $this->queue->get(DeliveryDispatcher::QUEUE_NAME);
    if (!$queue instanceof DatabaseQueue || !$this->database->schema()->tableExists('queue')) {
      throw new IntegrationException('integration.queue_backend_unsupported', 409);
    }
    // Exact serialized content plus the locked transfer fences delayed items.
    $data = ['logId' => $id, 'applicationId' => $application_id, 'adapter' => 'fit_connect'];
    $existing = $this->database->select('queue', 'q')->fields('q', ['item_id'])
      ->condition('name', DeliveryDispatcher::QUEUE_NAME)->condition('data', serialize($data))->range(0, 1)->execute()->fetchField();
    $item_id = $existing === FALSE ? $queue->createItem($data) : (int) $existing;
    $this->database->update('beantragt_delivery_log')->fields(['queue_item_id' => $item_id])->condition('id', $id)->execute();
  }

}
