<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow\Service;

use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Manages workflow status transitions for applications.
 *
 * Transitions are resolved per group from each workflow_status entity's
 * field_allowed_next graph (replacing the former global transition config).
 * Escalation still reads escalation_days from beantragt_workflow.settings,
 * which stays a global default. Successful transitions set a revision log
 * message, force a new revision and dispatch a WorkflowTransitionEvent.
 */
class WorkflowManager {

  /**
   * Internal workflow attributes that are not submitted transition fields.
   */
  private const SYSTEM_WORKFLOW_ATTRIBUTES = [
    'submissionLocale' => TRUE,
  ];

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a WorkflowManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\beantragt_application\Service\EffectiveWorkflowResolver $effectiveWorkflowResolver
   *   The effective workflow resolver.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The time service; stamps the revision of a status change.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly EventDispatcherInterface $eventDispatcher,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EffectiveWorkflowResolver $effectiveWorkflowResolver,
    protected readonly ?TimeInterface $time = NULL,
  ) {
    $this->logger = $loggerFactory->get('beantragt_workflow');
  }

  /**
   * Gets the allowed transitions from a given status entity.
   *
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null $fromStatus
   *   The current workflow status entity.
   *
   * @return string[]
   *   Array of allowed target status machine names.
   */
  public function getAllowedTransitions(?WorkflowStatusInterface $fromStatus): array {
    if ($fromStatus === NULL) {
      return [];
    }
    if ($fromStatus->isTerminal()) {
      return [];
    }

    return $fromStatus->getAllowedNextMachineNames();
  }

  /**
   * Checks whether a specific transition is allowed.
   *
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null $fromStatus
   *   The current workflow status entity.
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null $toStatus
   *   The target workflow status entity.
   *
   * @return bool
   *   TRUE if the transition is allowed.
   */
  public function isTransitionAllowed(?WorkflowStatusInterface $fromStatus, ?WorkflowStatusInterface $toStatus): bool {
    if ($fromStatus === NULL || $toStatus === NULL) {
      return FALSE;
    }

    // Same status is always allowed (no-op).
    if ((int) $fromStatus->id() === (int) $toStatus->id()) {
      return TRUE;
    }

    // Terminal statuses have no outgoing transitions.
    if ($fromStatus->isTerminal()) {
      return FALSE;
    }

    // A target from another group is never reachable.
    if ($fromStatus->getGroupId() !== $toStatus->getGroupId()) {
      return FALSE;
    }

    return in_array((int) $toStatus->id(), $fromStatus->getAllowedNextIds(), TRUE);
  }

  /**
   * Checks whether a transition is allowed for this application.
   */
  public function isApplicationTransitionAllowed(ApplicationInterface $application, ?WorkflowStatusInterface $fromStatus, ?WorkflowStatusInterface $toStatus): bool {
    // Enforcement is fresh: a form published by another process reaches a
    // long-running worker here, not after its restart.
    $this->effectiveWorkflowResolver->refresh($application);

    if ($fromStatus === NULL || $toStatus === NULL) {
      return FALSE;
    }

    if ((int) $fromStatus->id() === (int) $toStatus->id()) {
      return TRUE;
    }

    if ($fromStatus->getGroupId() !== $toStatus->getGroupId()) {
      return FALSE;
    }

    if ($fromStatus->isTerminal()) {
      return FALSE;
    }

    $schema_allowed = $this->effectiveWorkflowResolver->allowedTargetIds($application, $fromStatus);
    if ($schema_allowed !== NULL) {
      return in_array((int) $toStatus->id(), $schema_allowed, TRUE);
    }

    return $this->isTransitionAllowed($fromStatus, $toStatus);
  }

  /**
   * Validates and records a workflow transition on an application.
   *
   * Should be called from hook_application_presave(). If the transition
   * is invalid, an exception is thrown to prevent the save.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity being saved.
   *
   * @throws \InvalidArgumentException
   *   If the transition is not allowed.
   */
  public function validateTransition(ApplicationInterface $application): void {
    // New entities start at their group default, no transition to validate.
    if ($application->isNew()) {
      return;
    }

    $newStatus = $this->resolveStatusEntity($application);

    $original = $application->getOriginal();
    if ($original === NULL) {
      return;
    }

    $oldStatus = $this->resolveStatusEntity($original);

    $oldId = $oldStatus !== NULL ? (int) $oldStatus->id() : 0;
    $newId = $newStatus !== NULL ? (int) $newStatus->id() : 0;

    // No change, nothing to validate.
    if ($oldId === $newId) {
      return;
    }

    // Refresh the governing form revision only for a status change.
    $this->effectiveWorkflowResolver->refresh($application);

    $is_administrative_reset = $application->isWorkflowStatusResetAllowed();

    if (!$is_administrative_reset && !$this->isApplicationTransitionAllowed($application, $oldStatus, $newStatus)) {
      throw new \InvalidArgumentException(sprintf(
        'Workflow transition from "%s" to "%s" is not allowed for application %s.',
        $oldStatus !== NULL ? $oldStatus->getMachineName() : 'unknown',
        $newStatus !== NULL ? $newStatus->getMachineName() : 'unknown',
        $application->id() ?? 'new',
      ));
    }

    if (!$is_administrative_reset && $oldStatus !== NULL && $newStatus !== NULL) {
      $attribute_errors = $this->transitionAttributeErrors($application, $oldStatus, $newStatus);
      if ($attribute_errors !== []) {
        throw new \InvalidArgumentException(implode(' ', $attribute_errors));
      }
    }

    $oldMachineName = $oldStatus !== NULL ? $oldStatus->getMachineName() : 'unknown';
    $newMachineName = $newStatus !== NULL ? $newStatus->getMachineName() : 'unknown';

    $account = $this->currentUser->getAccount();
    // Automatic action codes and the original human reason remain separate:
    // the civic.revision.v1 log carries stable event codes, never prose.
    $system_reason = $application->getSystemStatusChange();
    if ($system_reason !== NULL) {
      // An automatic change records its cause as an event. Without a human
      // reason, an inherited log message must not ride along.
      RevisionLog::setEvent($application, 'status_changed');
      $system_event = SemanticStatusChanger::REVISION_EVENTS[$system_reason] ?? NULL;
      if ($system_event !== NULL) {
        RevisionLog::setEvent($application, $system_event);
      }
      RevisionLog::setMessage($application, RevisionLog::pendingMessage($application) ?? '');
      // Facts from outside (a transfer accepted, a notice received) belong
      // to the system, not to whoever's request happened to trigger them.
      $revision_user_id = in_array($system_reason, SemanticStatusChanger::SYSTEM_ACTOR_REASONS, TRUE)
        ? 0
        : (int) $account->id();
    }
    else {
      RevisionLog::setEvent($application, $is_administrative_reset ? 'status_reset' : 'status_changed');
      $raw_log = (string) $application->getRevisionLogMessage();
      $submitted_log_message = RevisionLog::pendingMessage($application)
        ?? ($raw_log === (string) $original->getRevisionLogMessage() ? '' : RevisionLog::message($raw_log));
      RevisionLog::setMessage($application, $submitted_log_message);
      $revision_user_id = (int) $account->id();
    }
    $application->setRevisionUserId($revision_user_id);
    $application->setNewRevision(TRUE);
    // The timeline dates each status from its revision; a new revision
    // otherwise keeps the creation time of the one it was loaded from.
    if ($this->time !== NULL) {
      $application->setRevisionCreationTime($this->time->getRequestTime());
    }

    $this->logger->info('Application @id: transition "@from" -> "@to" by @user.', [
      '@id' => $application->id(),
      '@from' => $oldMachineName,
      '@to' => $newMachineName,
      '@user' => $account->getDisplayName(),
    ]);

    // The WorkflowTransitionEvent is dispatched post-save from
    // hook_application_update() via dispatchTransition(), so subscribers
    // (mail notifications, ECA) only react to committed status changes.
  }

  /**
   * Checks the pending transition's attribute values without saving or logging.
   *
   * @return string[]
   *   Validation messages, empty when the transition attributes are valid.
   */
  public function transitionAttributeErrors(ApplicationInterface $application, WorkflowStatusInterface $from, WorkflowStatusInterface $to): array {
    $definitions = $this->effectiveWorkflowResolver->transitionAttributes($application, $from, $to);
    $values = array_diff_key($this->decodeWorkflowAttributes($application), self::SYSTEM_WORKFLOW_ATTRIBUTES);
    return $this->effectiveWorkflowResolver->validateTransitionAttributes($definitions, $values);
  }

  /**
   * Dispatches the transition event for a committed status change.
   *
   * Must be called from hook_application_update(), after the save has been
   * committed. Dispatching post-save guarantees that side effects such as
   * citizen notification mails never fire for a save that later fails.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity that was just saved.
   */
  public function dispatchTransition(ApplicationInterface $application): void {
    $original = $application->getOriginal();
    if (!$original instanceof ApplicationInterface) {
      return;
    }

    $oldStatus = $this->resolveStatusEntity($original);
    $newStatus = $this->resolveStatusEntity($application);

    $oldId = $oldStatus !== NULL ? (int) $oldStatus->id() : 0;
    $newId = $newStatus !== NULL ? (int) $newStatus->id() : 0;
    if ($oldId === $newId) {
      return;
    }

    $oldMachineName = $oldStatus !== NULL ? $oldStatus->getMachineName() : 'unknown';
    $newMachineName = $newStatus !== NULL ? $newStatus->getMachineName() : 'unknown';

    // The event keeps its string status signature; machine names are passed
    // for compatibility, labels travel separately for human-facing output.
    $event = new WorkflowTransitionEvent(
      $application,
      $oldMachineName,
      $newMachineName,
      $this->currentUser->getAccount(),
      $oldStatus !== NULL ? (string) $oldStatus->label() : '',
      $newStatus !== NULL ? (string) $newStatus->label() : '',
      $oldStatus,
      $newStatus,
    );
    $this->eventDispatcher->dispatch($event, WorkflowTransitionEvent::TRANSITION);
  }

  /**
   * Decodes current workflow attributes from the application.
   *
   * @return array<string, mixed>
   *   Attribute values keyed by code.
   */
  private function decodeWorkflowAttributes(ApplicationInterface $application): array {
    if (!$application->hasField('field_workflow_attributes')
      || $application->get('field_workflow_attributes')->isEmpty()) {
      return [];
    }

    $raw = $application->get('field_workflow_attributes')->value;
    if (!is_string($raw) || $raw === '') {
      return [];
    }

    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
  }

  /**
   * Resolves the workflow_status entity referenced by an application.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null
   *   The referenced status entity, or NULL if none is set.
   */
  public function resolveStatusEntity(ApplicationInterface $application): ?WorkflowStatusInterface {
    if (!$application->hasField('field_workflow_status')
      || $application->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }

    $status = $application->get('field_workflow_status')->entity;
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Resolves the default workflow status for a group.
   *
   * @param int $group_id
   *   The owning organisation group id.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null
   *   The group's default status, or NULL if none is defined.
   */
  public function resolveDefaultStatusForGroup(int $group_id): ?WorkflowStatusInterface {
    if ($group_id <= 0) {
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
   * Gets the configured number of days before escalation.
   *
   * @return int
   *   Number of days. 0 means escalation is disabled.
   */
  public function getEscalationDays(): int {
    $config = $this->configFactory->get('beantragt_workflow.settings');
    return (int) ($config->get('escalation_days') ?? 0);
  }

  /**
   * Checks whether an application should be escalated.
   *
   * An application is escalation-worthy if its current status is non-terminal
   * (field_is_terminal == FALSE) and it has not changed for longer than the
   * configured escalation period.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param int $currentTime
   *   The current timestamp (for testability).
   *
   * @return bool
   *   TRUE if the application should be escalated.
   */
  public function shouldEscalate(ApplicationInterface $application, int $currentTime): bool {
    $escalationDays = $this->getEscalationDays();
    if ($escalationDays <= 0) {
      return FALSE;
    }

    $status = $this->resolveStatusEntity($application);
    // No status, or a terminal status, is never escalated.
    if ($status === NULL || $status->isTerminal()) {
      return FALSE;
    }

    $changedTime = (int) $application->getChangedTime();
    $escalationThreshold = $changedTime + ($escalationDays * 86400);

    return $currentTime >= $escalationThreshold;
  }

}
