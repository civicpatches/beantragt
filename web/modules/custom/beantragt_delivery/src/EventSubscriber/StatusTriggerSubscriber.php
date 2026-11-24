<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\EventSubscriber;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\TransferPlanner;
use Drupal\beantragt_delivery\Service\TransferPlanningRecovery;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Starts the transfer when staff set the trigger status of the rule.
 *
 * Only a person's status change into a status with a trigger flag (from a
 * status without that flag) counts: automatic changes, administrative resets
 * and rollbacks never start a transfer. The planning itself runs after the
 * status save committed (_beantragt_delivery_plan_on_status_after_commit()).
 */
class StatusTriggerSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TransferPlanner $planner,
    protected readonly LoggerInterface $logger,
    protected readonly ?TransferPlanningRecovery $recovery = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WorkflowTransitionEvent::TRANSITION => ['onWorkflowTransition', -10],
    ];
  }

  /**
   * Schedules status-triggered planning when every condition holds.
   */
  public function onWorkflowTransition(WorkflowTransitionEvent $event): void {
    $application = $event->getApplication();
    if ($this->leavesTriggerStatus($event)) {
      // Any change out of the trigger status, rollbacks and resets included,
      // voids a status intent that has not been planned yet.
      try {
        $this->recovery?->revokeStatusIntent((int) $application->id());
      }
      catch (\Throwable $exception) {
        $this->logger->error('Status intent of application @id was not revoked: @class', [
          '@id' => $application->id(),
          '@class' => $exception::class,
        ]);
      }
    }
    $flag = $this->triggerFlag($event);
    if ($flag === NULL
      || $application->getSystemStatusChange() !== NULL
      || $application->isWorkflowStatusResetAllowed()
      || !$application->isEmailConfirmed()
      || $this->planner->isAnonymised($application)
      || !$this->configFactory->get('beantragt_delivery.settings')->get('enabled')) {
      return;
    }
    $trigger = $this->planner->triggerOf($application);
    if ($trigger === NULL || $trigger['mode'] !== 'status' || $trigger['flag'] !== $flag) {
      return;
    }
    try {
      $this->schedule($application, (int) $event->getAccount()->id());
    }
    catch (\Throwable $exception) {
      // The status change is the human act and stands; only codes are logged.
      $this->logger->error('Status-triggered transfer of application @id was not scheduled: @class', [
        '@id' => $application->id(),
        '@class' => $exception::class,
      ]);
    }
  }

  /**
   * Returns the trigger flag the transition enters, or NULL.
   *
   * The target must carry a trigger flag the source does not carry; moving
   * between two hand-over statuses is no new hand-over.
   */
  protected function triggerFlag(WorkflowTransitionEvent $event): ?string {
    $to = $event->getToEntity();
    if (!$to instanceof WorkflowStatusInterface) {
      return NULL;
    }
    $from = $event->getFromEntity();
    foreach (WorkflowStatusInterface::TRIGGER_FLAGS as $flag) {
      if ($to->hasSemanticFlag($flag) && !($from instanceof WorkflowStatusInterface && $from->hasSemanticFlag($flag))) {
        return $flag;
      }
    }
    return NULL;
  }

  /**
   * Whether the transition leaves a status carrying a trigger flag.
   */
  protected function leavesTriggerStatus(WorkflowTransitionEvent $event): bool {
    $from = $event->getFromEntity();
    if (!$from instanceof WorkflowStatusInterface) {
      return FALSE;
    }
    $to = $event->getToEntity();
    foreach (WorkflowStatusInterface::TRIGGER_FLAGS as $flag) {
      if ($from->hasSemanticFlag($flag) && !($to instanceof WorkflowStatusInterface && $to->hasSemanticFlag($flag))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Records the intent and plans after the status save commits.
   */
  protected function schedule(ApplicationInterface $application, int $actor_uid): void {
    _beantragt_delivery_plan_on_status_after_commit($application, $actor_uid);
  }

}
