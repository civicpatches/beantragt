<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow\Event;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Session\AccountInterface;

/**
 * Event dispatched when an application undergoes a workflow transition.
 *
 * Consumers (including ECA) can subscribe to this event to trigger
 * notifications, logging, or other side effects on status changes.
 */
class WorkflowTransitionEvent extends Event {

  /**
   * The event name for workflow transitions.
   */
  const string TRANSITION = 'beantragt_workflow.transition';

  /**
   * Constructs a WorkflowTransitionEvent.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param string $fromStatus
   *   The previous workflow status.
   * @param string $toStatus
   *   The new workflow status.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user who triggered the transition.
   * @param string $fromLabel
   *   The human-readable label of the previous status. Optional for
   *   backwards compatibility; falls back to the machine name.
   * @param string $toLabel
   *   The human-readable label of the new status. Optional for backwards
   *   compatibility; falls back to the machine name.
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null $fromEntity
   *   The previous status entity, when known.
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null $toEntity
   *   The new status entity, when known.
   */
  public function __construct(
    protected readonly ApplicationInterface $application,
    protected readonly string $fromStatus,
    protected readonly string $toStatus,
    protected readonly AccountInterface $account,
    protected readonly string $fromLabel = '',
    protected readonly string $toLabel = '',
    protected readonly ?WorkflowStatusInterface $fromEntity = NULL,
    protected readonly ?WorkflowStatusInterface $toEntity = NULL,
  ) {}

  /**
   * Gets the previous status entity.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null
   *   The status entity, or NULL when the dispatcher did not pass it.
   */
  public function getFromEntity(): ?WorkflowStatusInterface {
    return $this->fromEntity;
  }

  /**
   * Gets the new status entity.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null
   *   The status entity, or NULL when the dispatcher did not pass it.
   */
  public function getToEntity(): ?WorkflowStatusInterface {
    return $this->toEntity;
  }

  /**
   * Gets the application entity.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationInterface
   *   The application entity.
   */
  public function getApplication(): ApplicationInterface {
    return $this->application;
  }

  /**
   * Gets the previous workflow status.
   *
   * @return string
   *   The status before the transition.
   */
  public function getFromStatus(): string {
    return $this->fromStatus;
  }

  /**
   * Gets the new workflow status.
   *
   * @return string
   *   The status after the transition.
   */
  public function getToStatus(): string {
    return $this->toStatus;
  }

  /**
   * Gets the human-readable label of the previous workflow status.
   *
   * @return string
   *   The label, or the machine name when no label was provided.
   */
  public function getFromLabel(): string {
    return $this->fromLabel !== '' ? $this->fromLabel : $this->fromStatus;
  }

  /**
   * Gets the human-readable label of the new workflow status.
   *
   * @return string
   *   The label, or the machine name when no label was provided.
   */
  public function getToLabel(): string {
    return $this->toLabel !== '' ? $this->toLabel : $this->toStatus;
  }

  /**
   * Gets the user who triggered the transition.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The user account.
   */
  public function getAccount(): AccountInterface {
    return $this->account;
  }

}
