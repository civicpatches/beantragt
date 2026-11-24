<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\EventSubscriber;

use Drupal\beantragt_application\PublicRejectionReason;
use Drupal\beantragt_application\Service\CitizenStatusProjection;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to workflow transition events and dispatches email notifications.
 *
 * Determines which notification templates to fire based on the transition's
 * target status and sends the appropriate emails to citizens and staff.
 */
class WorkflowNotificationSubscriber implements EventSubscriberInterface {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a WorkflowNotificationSubscriber.
   *
   * @param \Drupal\beantragt_notification\Service\NotificationMailer $mailer
   *   The notification mailer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\beantragt_application\Service\CitizenStatusProjection|null $statusProjection
   *   Projects the citizen texts that decide whether a mail goes out.
   */
  public function __construct(
    protected readonly NotificationMailer $mailer,
    protected readonly ConfigFactoryInterface $configFactory,
    LoggerInterface $logger,
    protected readonly ?CitizenStatusProjection $statusProjection = NULL,
  ) {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WorkflowTransitionEvent::TRANSITION => ['onWorkflowTransition', 0],
    ];
  }

  /**
   * Reacts to workflow transitions by sending notification emails.
   *
   * Determines which templates apply based on the target status:
   * - Semantic approved statuses fire approval.
   * - Semantic rejected statuses fire rejection.
   * - Any other change fires status_change unless it was caused by the public
   *   staff comment reply. That action already sends the comment mail.
   * No mail goes out when the text applicants see stays the same (citizen
   * text or connection text): internal steps such as the hand-over to the
   * case system read "In Bearbeitung" before and after.
   *
   * @param \Drupal\beantragt_workflow\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onWorkflowTransition(WorkflowTransitionEvent $event): void {
    $application = $event->getApplication();
    if (!$application->isEmailConfirmed()) {
      return;
    }

    $fromStatus = $event->getFromStatus();
    $toStatus = $event->getToStatus();

    // Skip no-op transitions (re-saves without actual status change).
    if ($fromStatus === $toStatus) {
      return;
    }

    if ($this->hasOwnCitizenNotice($event)) {
      return;
    }

    // Citizen-facing tokens carry the texts applicants see; the machine
    // names above only drive the template selection below.
    $langcode = $this->mailer->citizenNotificationLangcode($application);
    [$from_label, $to_label] = [$event->getFromLabel(), $event->getToLabel()];
    if ($this->statusProjection !== NULL) {
      // A status that cannot be resolved yields an empty citizen text, never
      // the internal label or the machine name of the event.
      $statuses = [$event->getFromEntity(), $event->getToEntity()];
      [$from_label, $to_label] = $this->statusProjection->labels($application, $statuses, $langcode);
      if ($to_label === '') {
        $this->logger->warning('Status mail for application @id skipped, target status has no citizen text.', [
          '@id' => $application->id() ?? 'new',
        ]);
        return;
      }
    }
    if ($from_label === $to_label) {
      $this->logger->info('Status mail for application @id suppressed, citizen label unchanged.', [
        '@id' => $application->id() ?? 'new',
      ]);
      return;
    }

    $this->logger->info(
      'Processing notifications for application @id: @from -> @to.',
      [
        '@id' => $application->id() ?? 'new',
        '@from' => $fromStatus,
        '@to' => $toStatus,
      ]
    );

    $extraTokens = [
      '@old_status' => $this->sanitizeLabelToken($from_label),
      '@new_status' => $this->sanitizeLabelToken($to_label),
    ];

    // An administrative reset restores a status without issuing a decision.
    // Automatic changes may also bypass the graph and keep their own rules.
    $to_semantics = $application->isWorkflowStatusResetAllowed() && $application->getSystemStatusChange() === NULL
      ? []
      : $this->targetSemanticFlags($event);
    if (in_array('approved', $to_semantics, TRUE)) {
      $this->mailer->sendCitizenNotification($application, 'approval', $extraTokens);
    }
    elseif (in_array('rejected', $to_semantics, TRUE)) {
      $extraTokens['@rejection_reason'] = PublicRejectionReason::get($application);
      $this->mailer->sendCitizenNotification($application, 'rejection', $extraTokens);
    }
    else {
      $this->mailer->sendCitizenNotification($application, 'status_change', $extraTokens);
    }
  }

  /**
   * Sanitizes a staff-defined status label for use in mail bodies.
   *
   * Labels are privileged input (status editor), but mails must never carry
   * markup or CR/LF (header injection in legacy mail backends).
   *
   * @param string $label
   *   The raw status label.
   *
   * @return string
   *   The sanitized label, capped at 100 characters.
   */
  protected function sanitizeLabelToken(string $label): string {
    $clean = strip_tags($label);
    $clean = preg_replace('/[\r\n\t]+/', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s{2,}/', ' ', $clean) ?? $clean;
    return trim(mb_substr($clean, 0, 100));
  }

  /**
   * Returns TRUE for automatic changes whose citizen notice has its own mail.
   *
   * A staff comment sends application_comment_staff, a released decision
   * notice sends its release mail. The generic status mail would be a second
   * mail for the same event.
   *
   * @param \Drupal\beantragt_workflow\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  protected function hasOwnCitizenNotice(WorkflowTransitionEvent $event): bool {
    return in_array($event->getApplication()->getSystemStatusChange(), SemanticStatusChanger::SILENT_REASONS, TRUE);
  }

  /**
   * Resolves target status semantics from the event application.
   *
   * @return string[]
   *   Semantic flags for the target status.
   */
  protected function targetSemanticFlags(WorkflowTransitionEvent $event): array {
    $application = $event->getApplication();
    if ($application->hasField('field_workflow_status')
      && !$application->get('field_workflow_status')->isEmpty()) {
      $status = $application->get('field_workflow_status')->entity;
      if ($status instanceof WorkflowStatusInterface) {
        $flags = $status->getSemanticFlags();
        if ($flags !== []) {
          return $flags;
        }
      }
    }

    return match ($event->getToStatus()) {
      'approved', 'genehmigt' => ['approved', 'closed'],
      'rejected', 'abgelehnt' => ['rejected', 'closed'],
      'returned', 'nachforderung_offen', 'rueckfrage_offen' => ['request_info'],
      default => [],
    };
  }

}
