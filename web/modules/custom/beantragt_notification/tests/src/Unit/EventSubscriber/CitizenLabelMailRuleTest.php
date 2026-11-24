<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\EventSubscriber;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\CitizenStatusProjection;
use Drupal\beantragt_notification\EventSubscriber\WorkflowNotificationSubscriber;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Status mails go out only when the text applicants see changes.
 */
#[CoversClass(WorkflowNotificationSubscriber::class)]
#[Group('beantragt_notification')]
final class CitizenLabelMailRuleTest extends UnitTestCase {

  /**
   * Connection text the alter hook puts on in_case_system entries.
   */
  private string $handoverText = '';

  /**
   * Internal steps with the same citizen text send no mail.
   */
  public function testSameCitizenTextSendsNoMail(): void {
    $mailer = $this->mailer('de');
    $mailer->expects($this->never())->method('sendCitizenNotification');
    $this->fire($mailer, 'fachpruefung', 'fachverfahren_weiterleitung');
    // Hand-over accepted without a connection text: still "In Bearbeitung".
    $this->fire($mailer, 'fachverfahren_weiterleitung', 'fachverfahren_uebergeben', SemanticStatusChanger::CASE_SYSTEM_ACCEPTED);
  }

  /**
   * A changed citizen text sends the status mail with citizen texts.
   */
  public function testChangedCitizenTextSendsStatusMailWithCitizenTokens(): void {
    $mailer = $this->mailer('de');
    $mailer->expects($this->once())->method('sendCitizenNotification')->with(
      $this->anything(),
      'status_change',
      ['@old_status' => 'Eingegangen', '@new_status' => 'In Bearbeitung'],
    );
    $this->fire($mailer, 'eingegangen', 'fachpruefung');
  }

  /**
   * English applicants read English texts.
   */
  public function testCitizenLanguageDecidesTheTokens(): void {
    $mailer = $this->mailer('en');
    $mailer->expects($this->once())->method('sendCitizenNotification')->with(
      $this->anything(),
      'status_change',
      ['@old_status' => 'Received', '@new_status' => 'In progress'],
    );
    $this->fire($mailer, 'eingegangen', 'fachverfahren_weiterleitung');
  }

  /**
   * The connection text of the hand-over counts as a change.
   */
  public function testConnectionTextOfTheHandOverSendsStatusMail(): void {
    $this->handoverText = 'Beim Steueramt zur Festsetzung eingegangen';
    $mailer = $this->mailer('de');
    $mailer->expects($this->once())->method('sendCitizenNotification')->with(
      $this->anything(),
      'status_change',
      ['@old_status' => 'In Bearbeitung', '@new_status' => 'Beim Steueramt zur Festsetzung eingegangen'],
    );
    $this->fire($mailer, 'fachverfahren_weiterleitung', 'fachverfahren_uebergeben', SemanticStatusChanger::CASE_SYSTEM_ACCEPTED);
  }

  /**
   * A filing or a closure on receipt informs with "Abgeschlossen".
   */
  public function testFilingAndClosureOnReceiptSendStatusMail(): void {
    foreach ([SemanticStatusChanger::CASE_SYSTEM_FILED, SemanticStatusChanger::CLOSED_ON_RECEIPT] as $reason) {
      $mailer = $this->mailer('de');
      $mailer->expects($this->once())->method('sendCitizenNotification')->with(
        $this->anything(),
        'status_change',
        ['@old_status' => 'In Bearbeitung', '@new_status' => 'Abgeschlossen'],
      );
      $this->fire($mailer, 'fachverfahren_uebergeben', 'im_fachverfahren_abgelegt', $reason);
    }
  }

  /**
   * Outcomes with their own notice stay silent.
   */
  public function testOutcomeReasonsWithOwnNoticeStaySilent(): void {
    foreach ([
      SemanticStatusChanger::CASE_SYSTEM_APPROVED,
      SemanticStatusChanger::CASE_SYSTEM_REJECTED,
      SemanticStatusChanger::DECISION_RELEASED_APPROVED,
      SemanticStatusChanger::DECISION_RELEASED_REJECTED,
      SemanticStatusChanger::DECISION_ISSUED,
    ] as $reason) {
      $mailer = $this->mailer('de');
      $mailer->expects($this->never())->method('sendCitizenNotification');
      $this->fire($mailer, 'fachverfahren_uebergeben', 'genehmigt', $reason);
    }
  }

  /**
   * A person approving sends the approval template with citizen texts.
   */
  public function testStaffApprovalSendsApprovalTemplate(): void {
    $mailer = $this->mailer('de');
    $mailer->expects($this->once())->method('sendCitizenNotification')->with(
      $this->anything(),
      'approval',
      ['@old_status' => 'In Bearbeitung', '@new_status' => 'Bewilligt'],
    );
    $this->fire($mailer, 'fachpruefung', 'genehmigt');
  }

  /**
   * An unresolvable status never leaks the internal label or machine name.
   */
  public function testUnresolvableStatusNeverLeaksInternalLabels(): void {
    // Source status gone: the old status token is empty, not "unknown".
    $mailer = $this->mailer('de');
    $mailer->expects($this->once())->method('sendCitizenNotification')->with(
      $this->anything(),
      'status_change',
      ['@old_status' => '', '@new_status' => 'In Bearbeitung'],
    );
    $this->fire($mailer, 'unknown', 'fachpruefung');
    // Target status gone: no mail at all instead of the internal label.
    $mailer = $this->mailer('de');
    $mailer->expects($this->never())->method('sendCitizenNotification');
    $this->fire($mailer, 'fachpruefung', 'unknown');
  }

  /**
   * Fires one transition through a subscriber with the real projection.
   *
   * The machine name "unknown" stands for a status that cannot be loaded.
   */
  private function fire(NotificationMailer $mailer, string $from, string $to, ?string $reason = NULL): void {
    $from_status = $from === 'unknown' ? NULL : $this->seededStatus($from);
    $to_status = $to === 'unknown' ? NULL : $this->seededStatus($to);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? $to_status : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(44);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('hasField')->willReturn(TRUE);
    $application->method('get')->willReturn($field);
    $application->method('getRevisionLogMessage')->willReturn('');
    $application->method('getSystemStatusChange')->willReturn($reason);

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('alter')->willReturnCallback(function (string $type, array &$timeline): void {
      foreach ($timeline as &$entry) {
        if ($this->handoverText !== '' && ($entry['status'] ?? '') === 'fachverfahren_uebergeben') {
          $entry['label'] = $this->handoverText;
        }
      }
    });
    $projection = new CitizenStatusProjection($this->createMock(EntityTypeManagerInterface::class), $module_handler);
    $subscriber = new WorkflowNotificationSubscriber(
      $mailer,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
      $projection,
    );
    $subscriber->onWorkflowTransition(new WorkflowTransitionEvent(
      $application,
      $from,
      $to,
      $this->createMock(AccountInterface::class),
      $from_status === NULL ? 'unknown' : (string) $from_status->label(),
      $to_status === NULL ? 'Interner Status' : (string) $to_status->label(),
      $from_status,
      $to_status,
    ));
  }

  /**
   * Builds the mailer mock with the applicant's language.
   */
  private function mailer(string $langcode): NotificationMailer {
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->method('citizenNotificationLangcode')->willReturn($langcode);
    return $mailer;
  }

  /**
   * Builds a status of the seeded catalogue.
   */
  private function seededStatus(string $machine_name): WorkflowStatusInterface {
    $definitions = [
      'eingegangen' => ['Eingegangen', '', 'Received', ['received']],
      'fachpruefung' => ['Fachprüfung', 'In Bearbeitung', 'In progress', []],
      'fachverfahren_weiterleitung' => [
        'Weiterleitung an Fachverfahren',
        'In Bearbeitung',
        'In progress',
        ['hand_over'],
      ],
      'fachverfahren_uebergeben' => ['An Fachverfahren übergeben', 'In Bearbeitung', 'In progress', ['in_case_system']],
      'im_fachverfahren_abgelegt' => ['Im Fachverfahren abgelegt', 'Abgeschlossen', 'Completed', ['filed', 'closed']],
      'genehmigt' => ['Genehmigt', 'Bewilligt', 'Approved', ['approved', 'closed']],
    ];
    [$label, $de, $en, $flags] = $definitions[$machine_name];
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('label')->willReturn($label);
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('getSemanticFlags')->willReturn($flags);
    $status->method('getColor')->willReturn('#000000');
    $status->method('getIcon')->willReturn('i-lucide-circle');
    $status->method('getCitizenLabel')->willReturnCallback(static fn(string $langcode): string => ($langcode === 'en' ? ($en ?: $de) : $de) ?: $label);
    return $status;
  }

}
