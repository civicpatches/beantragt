<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\EventSubscriber;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_notification\EventSubscriber\WorkflowNotificationSubscriber;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests workflow notification routing.
 */
#[CoversClass(WorkflowNotificationSubscriber::class)]
#[Group('beantragt_notification')]
final class WorkflowNotificationSubscriberTest extends UnitTestCase {

  /**
   * Semantic rejected statuses use the rejection template without audit text.
   */
  public function testRejectedSemanticFlagSelectsRejectionTemplate(): void {
    $application = $this->applicationWithStatusFlags(['rejected', 'closed'], json_encode([
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [],
      'message' => 'Nicht möglich.',
    ], JSON_THROW_ON_ERROR));

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with(
        $application,
        'rejection',
        $this->callback(static fn(array $tokens): bool => ($tokens['@rejection_reason'] ?? NULL) === ''),
      );

    $subscriber = new WorkflowNotificationSubscriber(
      $mailer,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'fachpruefung',
      'fachlich_negativ',
      $this->createMock(AccountInterface::class),
      'Fachprüfung',
      'Fachlich negativ',
    ));
  }

  /**
   * Semantic approved statuses use the approval template.
   */
  public function testApprovedSemanticFlagSelectsApprovalTemplate(): void {
    $application = $this->applicationWithStatusFlags(['approved', 'closed']);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with($application, 'approval', $this->isType('array'));

    $subscriber = new WorkflowNotificationSubscriber(
      $mailer,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'pruefung',
      'positiv_erledigt',
      $this->createMock(AccountInterface::class),
      'Prüfung',
      'Positiv erledigt',
    ));
  }

  /**
   * Comment-driven request-info transitions do not send a second status mail.
   *
   * The log is the one WorkflowManager persists; the marker travels on the
   * entity object, not in the log text.
   */
  public function testCommentDrivenRequestInfoTransitionSuppressesStatusChangeTemplate(): void {
    $application = $this->applicationWithStatusFlags(
      ['request_info'],
      json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [['field' => 'status', 'before' => [['id' => '3']], 'after' => [['id' => '4']]]],
        'events' => ['comment_request_info', 'status_changed'],
        'message' => '',
      ], JSON_THROW_ON_ERROR),
      SemanticStatusChanger::COMMENT_REQUEST_INFO,
    );

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->never())->method('sendCitizenNotification');

    $subscriber = new WorkflowNotificationSubscriber(
      $mailer,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'eingegangen',
      'nachforderung_offen',
      $this->createMock(AccountInterface::class),
      'Eingegangen',
      'Nachforderung offen',
    ));
  }

  /**
   * A decision notice closes silently; its release mail informs the citizen.
   */
  public function testDecisionIssuedTransitionSendsNoStatusMail(): void {
    $application = $this->applicationWithStatusFlags(['decision_issued', 'closed'], '', SemanticStatusChanger::DECISION_ISSUED);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->never())->method('sendCitizenNotification');

    $this->subscriber($mailer)->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'fachverfahren_uebergeben',
      'bescheid_erstellt',
      $this->createMock(AccountInterface::class),
      'An Fachverfahren übergeben',
      'Bescheid erstellt',
    ));
  }

  /**
   * The automatic handover is news for the citizen and keeps its status mail.
   */
  public function testCaseSystemHandoverSendsStatusChangeTemplate(): void {
    $application = $this->applicationWithStatusFlags(['in_case_system'], '', SemanticStatusChanger::CASE_SYSTEM_ACCEPTED);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with($application, 'status_change', $this->isType('array'));

    $this->subscriber($mailer)->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'eingegangen',
      'fachverfahren_uebergeben',
      $this->createMock(AccountInterface::class),
      'Eingegangen',
      'An Fachverfahren übergeben',
    ));
  }

  /**
   * A staff note quoting the marker must not silence a manual change.
   */
  public function testMarkerTextInStaffNoteDoesNotSuppressStatusMail(): void {
    $application = $this->applicationWithStatusFlags(
      ['request_info'],
      json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [],
        'events' => ['status_changed'],
        'message' => 'beantragt_comment:auto_request_info',
      ], JSON_THROW_ON_ERROR),
    );

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with($application, 'status_change', $this->isType('array'));

    $this->subscriber($mailer)->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'eingegangen',
      'nachforderung_offen',
      $this->createMock(AccountInterface::class),
      'Eingegangen',
      'Nachforderung offen',
    ));
  }

  /**
   * Manual request-info transitions still send the generic status mail.
   */
  public function testManualRequestInfoTransitionSendsStatusChangeTemplate(): void {
    $application = $this->applicationWithStatusFlags(['request_info']);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with($application, 'status_change', $this->isType('array'));

    $subscriber = new WorkflowNotificationSubscriber(
      $mailer,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'eingegangen',
      'nachforderung_offen',
      $this->createMock(AccountInterface::class),
      'Eingegangen',
      'Nachforderung offen',
    ));
  }

  /**
   * A status correction sends only the generic mail, never the note.
   *
   * The correction is an administrative reset into a status that is never a
   * decision status; its internal note must not reach any token.
   */
  public function testStatusCorrectionSendsGenericMailWithoutNote(): void {
    $application = $this->applicationWithStatusFlags(['documents_received'], json_encode([
      'schema' => 'civic.revision.v1',
      'action' => 'updated',
      'changes' => [],
      'events' => ['status_reset'],
      'message' => 'Interne Korrekturnotiz',
    ], JSON_THROW_ON_ERROR), NULL, TRUE);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with(
        $application,
        'status_change',
        $this->callback(static function (array $tokens): bool {
          foreach ($tokens as $value) {
            if (str_contains((string) $value, 'Korrekturnotiz')) {
              return FALSE;
            }
          }
          return !array_key_exists('@rejection_reason', $tokens);
        }),
      );

    $this->subscriber($mailer)->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'entfallener_status',
      'vollstaendigkeit_geprueft',
      $this->createMock(AccountInterface::class),
      'Entfallen',
      'In Bearbeitung',
    ));
  }

  /**
   * A correction that keeps the citizen text sends no mail.
   */
  public function testStatusCorrectionWithUnchangedCitizenTextSendsNothing(): void {
    $application = $this->applicationWithStatusFlags([], 'Interne Korrekturnotiz', NULL, TRUE);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->never())->method('sendCitizenNotification');

    $this->subscriber($mailer)->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      'entfallener_status',
      'fachpruefung',
      $this->createMock(AccountInterface::class),
      'In Bearbeitung',
      'In Bearbeitung',
    ));
  }

  /**
   * The correction's decision rule and the mail template choice agree.
   *
   * EffectiveWorkflowResolver::isDecisionStatus() copies the rule of
   * targetSemanticFlags(); this test fails when one of them drifts.
   */
  #[DataProvider('decisionStatusCases')]
  public function testDecisionStatusRuleMatchesTemplateChoice(array $flags, string $machine_name): void {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('getSemanticFlags')->willReturn($flags);
    $status->method('getMachineName')->willReturn($machine_name);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? $status : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->with('field_workflow_status')->willReturn(TRUE);
    $application->method('get')->with('field_workflow_status')->willReturn($field);
    $event = new WorkflowTransitionEvent(
      $application,
      'eingegangen',
      $machine_name,
      $this->createMock(AccountInterface::class),
      'Eingegangen',
      $machine_name,
    );

    $template_flags = (new \ReflectionMethod(WorkflowNotificationSubscriber::class, 'targetSemanticFlags'))
      ->invoke($this->subscriber($this->createMock(NotificationMailer::class)), $event);
    $outcome_template = in_array('approved', $template_flags, TRUE) || in_array('rejected', $template_flags, TRUE);

    $this->assertSame($outcome_template, EffectiveWorkflowResolver::isDecisionStatus($status));
  }

  /**
   * Flagged, flagless-named and ordinary statuses.
   */
  public static function decisionStatusCases(): array {
    return [
      'approved flag' => [['approved', 'closed'], 'positiv_erledigt'],
      'rejected flag' => [['rejected', 'closed'], 'fachlich_negativ'],
      'other flags on a legacy name' => [['received'], 'genehmigt'],
      'flagless genehmigt' => [[], 'genehmigt'],
      'flagless approved' => [[], 'approved'],
      'flagless abgelehnt' => [[], 'abgelehnt'],
      'flagless rejected' => [[], 'rejected'],
      'flagless returned' => [[], 'returned'],
      'ordinary flagged' => [['documents_received'], 'vollstaendigkeit_geprueft'],
      'ordinary flagless' => [[], 'fachpruefung'],
      'withdrawn' => [['withdrawn', 'closed'], 'zurueckgezogen'],
    ];
  }

  /**
   * Builds an application mock with a referenced semantic status.
   *
   * @param string[] $flags
   *   Semantic flags.
   * @param string $revision_log
   *   Application revision log message.
   * @param string|null $system_change
   *   Reason code of an automatic status change.
   * @param bool $reset
   *   Whether the change is an administrative reset (rollback, correction).
   */
  private function applicationWithStatusFlags(array $flags, string $revision_log = 'Nicht möglich.', ?string $system_change = NULL, bool $reset = FALSE): ApplicationInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('getSemanticFlags')->willReturn($flags);

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')
      ->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? $status : NULL);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('hasField')
      ->with('field_workflow_status')
      ->willReturn(TRUE);
    $application->method('get')
      ->with('field_workflow_status')
      ->willReturn($field);
    $application->method('getRevisionLogMessage')->willReturn($revision_log);
    $application->method('getSystemStatusChange')->willReturn($system_change);
    $application->method('isWorkflowStatusResetAllowed')->willReturn($reset);

    return $application;
  }

  /**
   * Builds the subscriber under test.
   */
  private function subscriber(NotificationMailer $mailer): WorkflowNotificationSubscriber {
    return new WorkflowNotificationSubscriber(
      $mailer,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

}
