<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\DeliveryAlreadyAcceptedException;
use Drupal\beantragt_delivery\Exception\DeliveryLogNotFoundException;
use Drupal\beantragt_delivery\Gateway\DeliveryGatewayInterface;
use Drupal\beantragt_delivery\Gateway\FitConnectDeliveryGateway;
use Drupal\beantragt_delivery\Gateway\MockDeliveryGateway;
use Drupal\beantragt_delivery\Gateway\WebhookDeliveryGateway;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\beantragt_delivery\Value\WebhookSettings;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Coordinates delivery queueing and adapter dispatch.
 */
final class DeliveryDispatcher {

  public const QUEUE_NAME = 'beantragt_delivery_dispatch';
  public const DEFAULT_MAX_ATTEMPTS = 3;
  public const RETRY_DELAY_SECONDS = 300;

  private const NON_RETRYABLE_ERRORS = [
    'application.not_found',
    'delivery.already_accepted',
    'delivery.invalid_envelope',
    'confirmation.required',
    'fit_connect.encryption_not_implemented',
    'fit_connect.missing_configuration',
    'fit_connect.not_implemented',
    'fit_connect.token_rejected',
    'fit_connect.token_url_not_allowed',
    'fit_connect.test_only',
    'fit_connect.unsupported_contract',
    'fit_connect.submission_already_started',
    'fit_connect.application_changed',
    'fit_connect.attachment_changed',
    'fit_connect.log_context_required',
    'webhook.missing_configuration',
    'webhook.target_host_not_allowed',
    'webhook.target_url_not_allowed',
  ];

  /**
   * Constructs the dispatcher.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DeliveryLogRepository $logRepository,
    private readonly DeliveryEnvelopeBuilder $envelopeBuilder,
    private readonly DeliveryPolicyResolver $policyResolver,
    private readonly MockDeliveryGateway $mockGateway,
    private readonly WebhookDeliveryGateway $webhookGateway,
    private readonly FitConnectDeliveryGateway $fitConnectGateway,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
    private readonly ?TransferPlanner $transferPlanner = NULL,
    private readonly ?SemanticStatusChanger $statusChanger = NULL,
    private readonly ?IntegrationRepository $integrations = NULL,
  ) {}

  /**
   * Automatically queues a delivery when enabled by configuration.
   */
  public function autoEnqueue(ApplicationInterface $application): ?int {
    if (!$application->isEmailConfirmed()) {
      return NULL;
    }

    $config = $this->configFactory->get('beantragt_delivery.settings');
    if (!$config->get('enabled')) {
      return NULL;
    }

    if ($this->transferPlanner !== NULL) {
      $id = $this->transferPlanner->plan($application);
      if ($id !== NULL || $this->transferPlanner->explain($application)['reason'] !== 'no_assignment') {
        return $id;
      }
    }
    // FIT-Connect is never selected by legacy global or form-level settings.
    $policy = $this->policyResolver->resolve($application);
    if ($policy?->adapter($this->defaultAdapter()) === 'fit_connect'
      || ($policy === NULL && $this->defaultAdapter() === 'fit_connect')) {
      return NULL;
    }
    if ($policy === NULL) {
      return $config->get('auto_enqueue') ? $this->enqueue($application) : NULL;
    }

    if (!$policy->shouldAutoEnqueue()) {
      return NULL;
    }

    return $this->enqueue($application, $policy->adapter($this->defaultAdapter()));
  }

  /**
   * Queues a delivery attempt.
   */
  public function enqueue(ApplicationInterface $application, ?string $adapter = NULL): int {
    $this->assertConfirmed($application);
    $adapter = $this->resolveAdapter($application, $adapter);
    return $this->createQueuedLog($application, $adapter, TRUE);
  }

  /**
   * Creates and immediately dispatches one delivery attempt.
   */
  public function dispatchApplication(ApplicationInterface $application, ?string $adapter = NULL): array {
    $this->assertConfirmed($application);
    $adapter = $this->resolveAdapter($application, $adapter);
    $log_id = $this->createQueuedLog($application, $adapter, FALSE);
    $result = $this->dispatchLog($log_id);

    return [
      'logId' => $log_id,
      'result' => $this->resultToArray($result),
    ];
  }

  /**
   * Dispatches an existing log entry.
   */
  public function dispatchLog(int $log_id): DeliveryResult {
    $handed_over = NULL;
    $result = $this->dispatchLogLocked($log_id, $handed_over);
    // Outside the application lock: the status save takes and releases the
    // same lock name, which would end this dispatcher's hold on it early.
    if ($handed_over instanceof ApplicationInterface) {
      $presentation = $this->connectionPresentation($this->logRepository->load($log_id) ?? []);
      $outcome = $this->statusChanger?->caseSystemAcceptedOutcome($handed_over, $presentation['closure_rule'], $presentation['follow_up']);
      // A case left in its start status on a form without the hand-over
      // function: the transfer row says why, for staff (no mail, no change).
      if (is_string($outcome['unchanged'] ?? NULL)) {
        $this->logRepository->recordAcceptanceStatus($log_id, $outcome['unchanged']);
      }
    }
    return $result;
  }

  /**
   * Closure rule and follow-up flags of the connection a transfer used.
   *
   * Legacy rows without a connection, or a deleted connection, use the
   * defaults (close on the reply, default follow-up).
   *
   * @return array{closure_rule: string, follow_up: array<string, string>}
   *   The coupling settings.
   */
  private function connectionPresentation(array $row): array {
    $uuid = $row['integration_connection_uuid'] ?? NULL;
    $connection = IntegrationRepository::inScopeOf(is_string($uuid) && $uuid !== '' ? $this->integrations?->load('connection', $uuid) : NULL, $row);
    $presentation = IntegrationRepository::presentation($connection['presentation'] ?? NULL);
    return ['closure_rule' => $presentation['closure_rule'], 'follow_up' => $presentation['follow_up']];
  }

  /**
   * Dispatches a log entry while holding the application lock.
   *
   * @param int $log_id
   *   The delivery log id.
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface|null $handed_over
   *   Set to the application when FIT-Connect accepted the transfer.
   */
  private function dispatchLogLocked(int $log_id, ?ApplicationInterface &$handed_over): DeliveryResult {
    $row = $this->logRepository->load($log_id);
    if ($row === NULL) {
      throw new DeliveryLogNotFoundException(sprintf('Delivery log %d was not found.', $log_id));
    }
    if ((string) $row['status'] === DeliveryLogRepository::STATUS_ACCEPTED) {
      throw new DeliveryAlreadyAcceptedException(sprintf('Delivery log %d is already accepted.', $log_id));
    }

    $application_id = (int) $row['applicationId'];
    $lock_name = Application::deletionLockName($application_id);
    if (!$this->lock->acquire($lock_name, 300.0)) {
      throw new \RuntimeException(sprintf('Application %d is currently being deleted or delivered.', $application_id));
    }

    try {
      // Reload after taking the shared lock. A concurrent deletion may have
      // removed the log between the initial lookup and lock acquisition.
      $row = $this->logRepository->load($log_id);
      if ($row === NULL) {
        throw new DeliveryLogNotFoundException(sprintf('Delivery log %d was not found.', $log_id));
      }
      if ((string) $row['status'] === DeliveryLogRepository::STATUS_ACCEPTED) {
        throw new DeliveryAlreadyAcceptedException(sprintf('Delivery log %d is already accepted.', $log_id));
      }

      $adapter = $this->normalizeAdapter((string) $row['adapter']);
      if (in_array($row['status'], [
        DeliveryLogRepository::STATUS_AWAITING_APPROVAL,
        DeliveryLogRepository::STATUS_BLOCKED,
      ], TRUE)) {
        return DeliveryResult::failed('integration.dispatch_approval_required', 'Dispatch approval is required.');
      }
      if ($adapter === 'fit_connect' && $row['status'] === DeliveryLogRepository::STATUS_PROCESSING) {
        // Leave the active (or crashed) owner's row untouched.
        return DeliveryResult::failed('fit_connect.submission_already_started', 'A processing FIT-Connect attempt requires completion or reconciliation.');
      }
      if ($adapter === 'fit_connect' && $row['status'] === DeliveryLogRepository::STATUS_FAILED
        && $this->transferPlanner !== NULL && !$this->transferPlanner->canAutoRequeue($row)) {
        return DeliveryResult::failed(explode(':', $row['lastError'], 2)[0] ?: 'integration.retry_not_allowed', 'This transfer requires reconciliation or correction.');
      }
      $attempts = ((int) $row['attempts']) + 1;
      if ($adapter === 'fit_connect') {
        if (!$this->logRepository->claimFitConnectProcessing($log_id, $attempts)) {
          return DeliveryResult::failed('fit_connect.submission_already_started', 'Another worker has claimed this FIT-Connect attempt.');
        }
      }

      $application = $this->loadApplication($application_id);
      if (!$application instanceof ApplicationInterface) {
        $result = DeliveryResult::failed('application.not_found', 'Application for delivery log was not found.');
        $this->logRepository->markResult($log_id, $result);
        return $result;
      }
      if ($this->transferPlanner !== NULL && $this->transferPlanner->isAnonymised($application)) {
        $result = DeliveryResult::failed('integration.application_anonymised', 'Application was anonymised.');
        $this->logRepository->markResult($log_id, $result);
        return $result;
      }
      if ($adapter !== 'fit_connect' && $this->transferPlanner !== NULL && $this->transferPlanner->hasAssignment($application)) {
        $result = DeliveryResult::failed('integration.assignment_controls_delivery', 'An integration assignment suppresses substitute channels.');
        $this->logRepository->markResult($log_id, $result);
        return $result;
      }
      if (!$application->isEmailConfirmed()) {
        $result = DeliveryResult::failed('confirmation.required', 'Application email confirmation is required before delivery.');
        $this->logRepository->markResult($log_id, $result);
        return $result;
      }

      if ($adapter !== 'fit_connect') {
        $this->logRepository->markProcessing($log_id, $attempts);
      }

      $envelope = DeliveryEnvelope::fromLogRow($row);
      if (!$envelope instanceof DeliveryEnvelope) {
        $result = DeliveryResult::failed('delivery.invalid_envelope', 'Delivery log envelope snapshot is invalid.');
        $this->logRepository->markResult($log_id, $result);
        return $result;
      }

      try {
        $gateway = $this->gateway($adapter);
        $result = $gateway instanceof FitConnectDeliveryGateway
          ? $gateway->deliver($envelope, $log_id)
          : $gateway->deliver($envelope);
      }
      catch (\Throwable $e) {
        $this->logger->error('Delivery log @id failed in adapter @adapter with @class.', [
          '@id' => $log_id,
          '@adapter' => $adapter,
          '@class' => $e::class,
        ]);
        $result = DeliveryResult::failed('delivery.exception', 'Delivery adapter failed. Check server logs.', [
          'adapter' => $adapter,
          'exceptionClass' => $e::class,
        ]);
      }

      $current = $this->logRepository->load($log_id);
      if ($adapter === 'fit_connect' && $row['scope_group_id'] !== NULL
        && ($current['metadata']['fitConnect'] ?? []) === []
        && ($current['binding_integrity'] ?? '') !== 'failed'
        && !in_array($result->errorCode, TransferFailures::TRANSPORT, TRUE)
        && TransferFailures::recoverable($result->errorCode)) {
        $this->logRepository->markBlocked($log_id, $result->errorCode, TRUE);
      }
      else {
        $this->logRepository->markResult($log_id, $result);
        // Mock and webhook deliveries never reach a case system.
        if ($result->isAccepted() && $adapter === 'fit_connect') {
          $handed_over = $application;
        }
        if ($result->isAccepted() && $this->transferPlanner !== NULL) {
          $this->transferPlanner->completeAccepted($row);
        }
        elseif (TransferFailures::classify($result->errorCode) === 'permanent' && $this->transferPlanner !== NULL) {
          $this->transferPlanner->completeAccepted($row);
        }
      }
      return $result;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns TRUE when a failed log should stay in the queue.
   */
  public function shouldRetryLog(int $log_id, DeliveryResult $result): bool {
    if ($result->isAccepted()) {
      return FALSE;
    }
    if (TransferFailures::classify($result->errorCode) !== 'unknown'
      && !in_array($result->errorCode, TransferFailures::TRANSPORT, TRUE)) {
      return FALSE;
    }

    $row = $this->logRepository->load($log_id);
    if (is_array($row) && $row['adapter'] === 'fit_connect' && $this->transferPlanner !== NULL) {
      return $this->transferPlanner->canAutoRequeue($row);
    }
    return is_array($row)
      && !in_array($result->errorCode, self::NON_RETRYABLE_ERRORS, TRUE)
      && (int) ($row['attempts'] ?? 0) < $this->maxAttempts();
  }

  /**
   * Returns the retry delay for queue workers.
   */
  public function retryDelaySeconds(): int {
    return self::RETRY_DELAY_SECONDS;
  }

  /**
   * Creates a delivery log while excluding concurrent application deletion.
   */
  private function createQueuedLog(ApplicationInterface $application, string $adapter, bool $enqueue): int {
    $application_id = (int) $application->id();
    if ($application_id <= 0) {
      throw new \InvalidArgumentException('Delivery requires a saved application.');
    }

    $lock_name = Application::deletionLockName($application_id);
    if (!$this->lock->acquire($lock_name, 300.0)) {
      throw new \RuntimeException(sprintf('Application %d is currently being deleted or delivered.', $application_id));
    }

    try {
      $current_application = $this->loadApplication($application_id);
      if (!$current_application instanceof ApplicationInterface
        || $current_application->uuid() !== $application->uuid()) {
        throw new \RuntimeException(sprintf('Application %d no longer exists.', $application_id));
      }
      $this->assertConfirmed($current_application);

      $envelope = $this->envelopeBuilder->build($current_application);
      $log_id = $this->logRepository->createQueued(
        $current_application,
        $envelope,
        $adapter,
        $this->targetForAdapter($adapter),
      );

      if ($enqueue) {
        $this->queueFactory->get(self::QUEUE_NAME)->createItem([
          'logId' => $log_id,
          'applicationId' => $application_id,
          'adapter' => $adapter,
        ]);
      }

      return $log_id;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Converts a result into a stable API shape.
   *
   * @return array<string, mixed>
   *   Result data.
   */
  public function resultToArray(DeliveryResult $result): array {
    return array_filter([
      'status' => $result->status,
      'externalId' => $result->externalId,
      'receipt' => $result->receipt,
      'errorCode' => $result->errorCode,
      'errorMessage' => $result->errorMessage,
      'metadata' => $result->metadata,
    ], static fn(mixed $value): bool => $value !== '' && $value !== []);
  }

  /**
   * Loads an application.
   */
  private function loadApplication(int $application_id): ?ApplicationInterface {
    if ($application_id <= 0) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('application');
    // A route parameter or producer may have populated the per-request entity
    // cache before a concurrent deletion won the shared lock.
    $storage->resetCache([$application_id]);
    $application = $storage->load($application_id);
    return $application instanceof ApplicationInterface ? $application : NULL;
  }

  /**
   * Rejects explicit delivery for applications waiting on double opt-in.
   */
  private function assertConfirmed(ApplicationInterface $application): void {
    if (!$application->isEmailConfirmed()) {
      throw new \InvalidArgumentException('Application email confirmation is required before delivery.');
    }
  }

  /**
   * Resolves the gateway.
   */
  private function gateway(string $adapter): DeliveryGatewayInterface {
    return match ($adapter) {
      $this->mockGateway->id() => $this->mockGateway,
      $this->webhookGateway->id() => $this->webhookGateway,
      $this->fitConnectGateway->id() => $this->fitConnectGateway,
      default => throw new \InvalidArgumentException(sprintf('Unsupported delivery adapter "%s".', $adapter)),
    };
  }

  /**
   * Reads the configured default adapter.
   */
  private function defaultAdapter(): string {
    return (string) ($this->configFactory->get('beantragt_delivery.settings')->get('default_adapter') ?: 'mock');
  }

  /**
   * Reads the configured maximum queue attempts.
   */
  private function maxAttempts(): int {
    $attempts = (int) ($this->configFactory->get('beantragt_delivery.settings')->get('max_attempts') ?: self::DEFAULT_MAX_ATTEMPTS);
    return max(1, min(10, $attempts));
  }

  /**
   * Normalizes adapter names.
   */
  private function normalizeAdapter(string $adapter): string {
    $adapter = strtolower(trim($adapter));
    return match ($adapter) {
      'mock', 'webhook', 'fit_connect' => $adapter,
      'fit-connect', 'fitconnect' => 'fit_connect',
      default => throw new \InvalidArgumentException(sprintf('Unsupported delivery adapter "%s".', $adapter)),
    };
  }

  /**
   * Resolves explicit, form-level or global adapter.
   */
  private function resolveAdapter(ApplicationInterface $application, ?string $adapter): string {
    if ($this->transferPlanner !== NULL && $this->transferPlanner->hasAssignment($application)) {
      throw new \InvalidArgumentException('An integration assignment controls delivery; use case transfers.');
    }
    $policy = $adapter === NULL ? $this->policyResolver->resolve($application) : NULL;
    $resolved = $this->normalizeAdapter($adapter ?? $policy?->adapter($this->defaultAdapter()) ?? $this->defaultAdapter());
    if ($resolved === 'fit_connect') {
      throw new \InvalidArgumentException('FIT-Connect transfers require an active integration assignment.');
    }
    return $resolved;
  }

  /**
   * Resolves a non-sensitive target label for the adapter.
   */
  private function targetForAdapter(string $adapter): string {
    $settings = $this->configFactory->get('beantragt_delivery.settings');
    if ($adapter === 'fit_connect') {
      return FitConnectSettings::fromConfig($this->configFactory)->destinationId;
    }
    if ($adapter === 'webhook') {
      return WebhookSettings::fromConfig($this->configFactory)->targetHost() ?: 'webhook';
    }

    $config = $settings->get('mock') ?? [];
    return is_array($config) ? (string) ($config['target'] ?? 'local-mock') : 'local-mock';
  }

}
