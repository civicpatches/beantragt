<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\TransferGateInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Service\TransferHistoryProjection;
use Drupal\beantragt_delivery\Service\TransferPlanner;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff-only transfer inspection and independent dispatch approval.
 */
final class TransferController extends ControllerBase {

  /**
   * Common disclosure boundary for both history routes.
   */
  private readonly TransferHistoryProjection $history;

  /**
   * Users loaded once for the current transfer projection batch.
   */
  private array $projectionUsers = [];

  /**
   * Verified responses grouped by transfer, loaded once per response.
   */
  private array $projectionResponses = [];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly TransferPlanner $planner,
    private readonly ScopeAccess $scopeAccess,
    private readonly AccountProxyInterface $account,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly IntegrationAudit $audit,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ExternalResponseRepository $responses,
    ?TransferHistoryProjection $history = NULL,
    private readonly ?TransferGateInterface $gates = NULL,
  ) {
    $this->history = $history ?? new TransferHistoryProjection($this->scopeAccess);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_delivery.transfer_planner'),
      $container->get('beantragt_delivery.scope_access'),
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container->get('beantragt_delivery.integration_audit'),
      $container->get('entity_type.manager'),
      $container->get('beantragt_delivery.external_response_repository'),
      $container->get('beantragt_delivery.history_projection'),
      $container->get('beantragt_delivery.transfer_gates'),
    );
  }

  /**
   * Guards transfer routes independently from management permissions.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->scopeAccess->transferAccess($application, $account);
  }

  /**
   * Lists safe transfer metadata and explains cases without an outbox entry.
   */
  public function listTransfers(ApplicationInterface $application): JsonResponse {
    if (!$this->scopeAccess->transferAccess($application, $this->account)->isAllowed()) {
      return $this->respond(['error' => ['code' => 'integration.access_denied']], 403);
    }
    $rows = $this->planner->transfers($application);
    $this->prepareProjection($application, $rows);
    $can_mutate = $this->scopeAccess->canApproveDispatch($application, $this->account);
    $delivery = $this->planner->explain($application);
    if ($rows !== []) {
      $delivery['applicability'] = 'current_configuration';
    }
    elseif (in_array($delivery['reason'], ['trigger_deferred', 'trigger_status_missing'], TRUE)
      && $this->gates?->planningRefusal($application, 'staff', (int) $this->account->id()) === 'integration.handover_status_required'
      && !in_array('integration.handover_status_required', array_column($delivery['reasons'], 'code'), TRUE)) {
      // Explain the unavailable manual action without adding origin metadata.
      $delivery['reasons'][] = ['code' => 'integration.handover_status_required'];
    }
    return $this->respond([
      'data' => array_map(fn(array $row): array => $this->project($application, $row, $can_mutate), $rows),
      'meta' => [
        'resource' => 'applicationTransfers',
        'applicationUuid' => $application->uuid(),
        'delivery' => $delivery,
        'canCreateTransfer' => $can_mutate && $this->planner->canCreateTransfer($application),
        // The dispatch right alone, for hints about a status-triggered release.
        'canApproveDispatch' => $can_mutate,
        'pendingTransfers' => $this->pendingTransfers($application, $rows),
        'trigger' => $this->trigger($application, $delivery),
      ],
    ]);
  }

  /**
   * Describes when the transfer starts, for the hint in the case view.
   *
   * The flag "pending" stays TRUE while the rule waits for its trigger (no
   * transfer yet); "statusMissing" reports a status trigger without a status
   * carrying the trigger flag in the application's catalogue.
   *
   * @return array{mode: string, flag: string, dispatchMode: string, pending: bool, statusMissing: bool, autoApprove: bool, blockedBy: ?string}|null
   *   The trigger, or NULL without exactly one active rule.
   */
  private function trigger(ApplicationInterface $application, array $delivery): ?array {
    $trigger = $this->planner->triggerOf($application);
    if ($trigger === NULL) {
      return NULL;
    }
    $reason = $delivery['reason'] ?? '';
    return [
      'mode' => $trigger['mode'],
      'flag' => $trigger['flag'],
      'dispatchMode' => $trigger['dispatch_mode'],
      'pending' => in_array($reason, ['trigger_deferred', 'trigger_status_missing'], TRUE),
      'statusMissing' => $reason === 'trigger_status_missing',
      'autoApprove' => $trigger['dispatch_mode'] === 'approval'
      && !($application->hasField('field_origin') && $application->get('field_origin')->value === 'bulk')
      && $this->scopeAccess->canApproveDispatch($application, $this->account),
      'blockedBy' => $this->gates?->planningRefusal($application, 'status', 0),
    ];
  }

  /**
   * Names unsent transfers that a data edit would stop, without any payload.
   */
  private function pendingTransfers(ApplicationInterface $application, array $rows): array {
    $pending = [];
    foreach ($rows as $row) {
      if ($this->history->neutral($application, $row) === NULL && $this->planner->isWaitingForDispatch($row)) {
        $pending[] = [
          'uuid' => (string) $row['uuid'],
          'connection' => (string) ($row['connection_snapshot']['label'] ?? ''),
          'status' => (string) $row['status'],
        ];
      }
    }
    return $pending;
  }

  /**
   * Explicitly recovers planning when no transfer row could be created safely.
   */
  public function createTransfer(ApplicationInterface $application, Request $request): JsonResponse {
    return $this->mutate($application, '', $request, 'create_transfer');
  }

  /**
   * Approves an already validated immutable transfer without approving a case.
   */
  public function approveDispatch(ApplicationInterface $application, string $uuid, Request $request): JsonResponse {
    return $this->mutate($application, $uuid, $request, 'approve_dispatch');
  }

  /**
   * Requeues only an allowlisted retry using its original binding and payload.
   */
  public function retry(ApplicationInterface $application, string $uuid, Request $request): JsonResponse {
    return $this->mutate($application, $uuid, $request, 'retry');
  }

  /**
   * Resumes a parked transfer after its live prerequisites recover.
   */
  public function resume(ApplicationInterface $application, string $uuid, Request $request): JsonResponse {
    return $this->mutate($application, $uuid, $request, 'resume');
  }

  /**
   * Creates a fresh transfer after explicitly recoverable permanent failure.
   */
  public function replan(ApplicationInterface $application, string $uuid, Request $request): JsonResponse {
    return $this->mutate($application, $uuid, $request, 'replan');
  }

  /**
   * Checks live authorization and CSRF even when invoked outside routing.
   */
  private function mutate(ApplicationInterface $application, string $uuid, Request $request, string $operation): JsonResponse {
    $request->attributes->set('integration_resource', 'transfer');
    $request->attributes->set('integration_operation', $operation);
    $request->attributes->set('application_id', (int) $application->id());
    $request->attributes->set('application_uuid', $application->uuid());
    $scope = NULL;
    try {
      if (!$this->scopeAccess->canApproveDispatch($application, $this->account)) {
        throw new IntegrationException('integration.access_denied', 403);
      }
      $scope = $this->scopeAccess->integrationScope($application);
      $token = (string) $request->headers->get('X-CSRF-Token', '');
      if ($token === '' || (!$this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
        && !$this->csrfToken->validate($token, 'rest'))) {
        throw new IntegrationException('integration.csrf_invalid', 403);
      }
      $content = trim((string) $request->getContent());
      if ($request->query->all() !== [] || !in_array($content, ['', '{}'], TRUE)) {
        throw new IntegrationException('integration.unknown_key', 400);
      }
      $uid = (int) $this->account->id();
      $row = match ($operation) {
        'create_transfer' => $this->planner->createTransfer($application, $uid),
        'approve_dispatch' => $this->planner->approve($application, $uuid, $uid),
        'resume' => $this->planner->resume($application, $uuid, $uid),
        'replan' => $this->planner->replan($application, $uuid, $uid),
        default => $this->planner->retry($application, $uuid, $uid),
      };
      // The planner records successes/noops atomically with its row mutation.
      $this->prepareProjection($application, [$row]);
      return $this->respond(['data' => $this->project($application, $row, TRUE)]);
    }
    catch (IntegrationException $exception) {
      $this->audit->record($request, $scope, 'denied', [], $uuid, NULL, $exception->errorCode);
      return $this->respond(['error' => ['code' => $exception->errorCode]], $exception->status);
    }
  }

  /**
   * Projects safe fields without credentials, payloads or arbitrary errors.
   */
  private function project(ApplicationInterface $application, array $row, ?bool $can_mutate = NULL): array {
    $neutral = $this->history->neutral($application, $row);
    if ($neutral !== NULL) {
      return $neutral;
    }
    $snapshot = is_array($row['connection_snapshot'] ?? NULL) ? $row['connection_snapshot'] : [];
    $uid = (int) ($row['dispatch_approved_uid'] ?? 0);
    $user = $this->projectionUsers[$uid] ?? NULL;
    $status = (string) ($row['status'] ?? '');
    $adapter = (string) ($row['adapter'] ?? '');
    $mode = (string) ($row['dispatch_mode'] ?? 'automatic');
    $approved = !empty($row['dispatch_approved_at']);
    $can_mutate ??= $this->scopeAccess->canApproveDispatch($application, $this->account);
    $approval_refusal = $this->gates?->approvalRefusal($application, $row, (int) $this->account->id());
    $renews_approval = $mode === 'approval' && !empty($row['payload_reference']);
    $retry_approval_refusal = $mode === 'approval'
      ? $this->gates?->approvalRefusal($application, $row, $uid) : NULL;
    $validation = [];
    foreach (array_slice((array) ($row['validation_errors'] ?? []), 0, 50) as $error) {
      if (!is_array($error)) {
        continue;
      }
      $safe = [];
      foreach (['pointer', 'keyword', 'code', 'source'] as $key) {
        if (isset($error[$key]) && is_string($error[$key])) {
          $safe[$key] = substr($error[$key], 0, 256);
        }
      }
      $validation[] = $safe;
    }
    $code = (string) ($row['last_error_code'] ?? $row['lastError'] ?? '');
    $code = explode(':', $code, 2)[0];
    $code = preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $code) ? $code : NULL;
    // Replanning needs a failed FIT-Connect row; others skip the planner.
    $replannable = $adapter === 'fit_connect' && $status === 'failed' && $this->planner->canReplan($row, $application);
    return [
      'uuid' => (string) $row['uuid'],
      'status' => $status,
      'adapter' => $adapter,
      'connection' => empty($row['integration_connection_uuid']) ? NULL : [
        'uuid' => $row['integration_connection_uuid'],
        'label' => (string) ($snapshot['label'] ?? ''),
        'destination_id' => (string) ($snapshot['destination_id'] ?? ''),
        'environment' => (string) ($snapshot['environment'] ?? ''),
        'destination_verification' => $this->destinationVerification($row),
      ],
      'mapping' => empty($row['integration_mapping_uuid']) ? NULL : [
        'uuid' => $row['integration_mapping_uuid'],
        'label' => (string) ($snapshot['mapping_label'] ?? ''),
        'version' => (int) ($row['integration_mapping_version'] ?? 0),
      ],
      'contract' => empty($row['contract_key']) ? NULL : [
        'key' => $row['contract_key'],
        'version' => $row['contract_version'],
      ],
      'dispatch_mode' => $mode,
      'send_attachments' => (bool) ($snapshot['send_attachments'] ?? FALSE),
      'attachments' => $this->attachmentList($row),
      'approval' => [
        'status' => $approved ? 'approved' : ($mode === 'approval' ? 'pending' : 'not_required'),
        'uid' => $uid ?: NULL,
        'display_name' => $user instanceof UserInterface ? $user->getDisplayName() : NULL,
        'at' => $row['dispatch_approved_at'] ?? NULL,
      ],
      'attempts' => (int) ($row['attempts'] ?? 0),
      'created' => (int) ($row['created'] ?? 0),
      'changed' => (int) ($row['changed'] ?? 0),
      'processed' => $row['processed'] ?? NULL,
      'receipts' => [
        'submission_id' => (string) (($row['externalId'] ?? '') ?: ($row['metadata']['fitConnect']['submissionId'] ?? '')),
        'case_id' => (string) ($row['metadata']['fitConnect']['caseId'] ?? ''),
      ],
      'transport_status' => $adapter === 'mock' ? 'mock_test_only' : ($adapter === 'fit_connect' && $status === 'accepted' ? 'technically_accepted_by_fit_connect' : $status),
      'recipient_approval' => 'not_inferred_from_transport',
      'validation_errors' => $validation,
      'last_error_code' => $code,
      'is_mock' => $adapter === 'mock',
      'external_responses' => $this->projectionResponses[(int) ($row['id'] ?? 0)] ?? [],
      'meta' => [
        'approvalRefusal' => $approval_refusal,
        'canApproveDispatch' => $can_mutate && $approval_refusal === NULL && $status === 'awaiting_approval' && $validation === [] && !empty($row['payload_reference']),
        'canRetry' => $can_mutate && $retry_approval_refusal === NULL && $this->planner->canRetry($row),
        'canResume' => $can_mutate && (!$renews_approval || $approval_refusal === NULL) && $this->planner->canResume($row),
        'canReplan' => $can_mutate && $replannable,
        // Viewers without the action learn that an authorized person can act.
        'replanRestricted' => !$can_mutate && $replannable,
        'statusUnchanged' => $this->statusUnchanged($row),
      ],
    ];
  }

  /**
   * Why an accepted transfer left the case in its start status, or NULL.
   *
   * Only codes of SemanticStatusChanger::ACCEPTED_UNCHANGED_REASONS reach the
   * client, never a status name.
   *
   * @return array{code: string, reason: string}|null
   *   The neutral note for the transfer entry.
   */
  private function statusUnchanged(array $row): ?array {
    $reason = $row['metadata']['acceptance']['reason'] ?? NULL;
    if (($row['status'] ?? NULL) !== 'accepted' || !in_array($reason, SemanticStatusChanger::ACCEPTED_UNCHANGED_REASONS, TRUE)) {
      return NULL;
    }
    return ['code' => 'integration.accepted_status_unchanged', 'reason' => $reason];
  }

  /**
   * Batches related lookups only for rows the current tenant may inspect.
   */
  private function prepareProjection(ApplicationInterface $application, array $rows): void {
    $uids = [];
    $log_ids = [];
    foreach ($rows as $row) {
      if ($this->history->neutral($application, $row) !== NULL) {
        continue;
      }
      if (!empty($row['dispatch_approved_uid'])) {
        $uids[] = (int) $row['dispatch_approved_uid'];
      }
      if (($row['adapter'] ?? '') === 'fit_connect') {
        $log_ids[] = (int) $row['id'];
      }
    }
    $this->projectionUsers = $uids === [] ? [] : $this->entities->getStorage('user')->loadMultiple(array_values(array_unique($uids)));
    $this->projectionResponses = $this->responses->listForTransfers((int) $application->id(), $log_ids);
  }

  /**
   * Shows exactly the selected attachment manifest before approval.
   */
  private function attachmentList(array $row): array {
    if (empty($row['connection_snapshot']['send_attachments'])) {
      return [];
    }
    $result = [];
    foreach ((array) ($row['metadata']['attachmentManifest'] ?? []) as $attachment) {
      if (is_array($attachment)) {
        $result[] = array_intersect_key($attachment, array_flip([
          'id',
          'name',
          'size',
          'sha256',
          'mime_type',
          'field_id',
          'form_revision_id',
          'submission_revision_id',
        ]));
      }
    }
    return $result;
  }

  /**
   * Returns only authenticated destination evidence already bounded on write.
   */
  private function destinationVerification(array $row): ?array {
    $evidence = $row['metadata']['destinationVerified'] ?? NULL;
    if (!is_array($evidence)) {
      return NULL;
    }
    return [
      'name' => (string) ($evidence['name'] ?? ''),
      'service_urn' => (string) ($evidence['service_urn'] ?? ''),
      'schema_uri' => (string) ($evidence['schema_uri'] ?? ''),
      'checked_at' => (int) ($evidence['checked_at'] ?? 0),
    ];
  }

  /**
   * Prevents caching staff data and permission decisions.
   */
  private function respond(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
