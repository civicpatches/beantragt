<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow\Event\WorkflowTransitionEvent;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow\Service\WorkflowManager;
use Drupal\Core\Config\Config;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the group-aware WorkflowManager.
 *
 * @coversDefaultClass \Drupal\beantragt_workflow\Service\WorkflowManager
 * @group beantragt_workflow
 */
class WorkflowManagerTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user mock.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The event dispatcher mock.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The effective workflow resolver mock.
   *
   * @var \Drupal\beantragt_application\Service\EffectiveWorkflowResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EffectiveWorkflowResolver $effectiveWorkflowResolver;

  /**
   * The manager under test.
   *
   * @var \Drupal\beantragt_workflow\Service\WorkflowManager
   */
  protected WorkflowManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);
    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    \Drupal::setContainer($container);

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->effectiveWorkflowResolver = $this->createMock(EffectiveWorkflowResolver::class);

    $channel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($channel);

    $this->manager = new WorkflowManager(
      $this->configFactory,
      $this->currentUser,
      $this->eventDispatcher,
      $loggerFactory,
      $this->entityTypeManager,
      $this->effectiveWorkflowResolver,
    );
  }

  /**
   * Builds a mocked workflow_status entity.
   *
   * @param int $id
   *   The entity id.
   * @param int $group_id
   *   The owning group id.
   * @param string $machine_name
   *   The machine name.
   * @param int[] $allowed_next_ids
   *   The reachable target ids.
   * @param string[] $allowed_next_names
   *   The reachable target machine names.
   * @param bool $is_terminal
   *   Whether the status is terminal.
   *
   * @return \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked status.
   */
  protected function makeStatus(int $id, int $group_id, string $machine_name, array $allowed_next_ids = [], array $allowed_next_names = [], bool $is_terminal = FALSE): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('id')->willReturn((string) $id);
    $status->method('getGroupId')->willReturn($group_id);
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('getAllowedNextIds')->willReturn($allowed_next_ids);
    $status->method('getAllowedNextMachineNames')->willReturn($allowed_next_names);
    $status->method('isTerminal')->willReturn($is_terminal);
    return $status;
  }

  /**
   * @covers ::getAllowedTransitions
   */
  public function testGetAllowedTransitionsReadsFromStatusEntity(): void {
    $new = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $this->assertSame(['in_review'], $this->manager->getAllowedTransitions($new));
  }

  /**
   * @covers ::getAllowedTransitions
   */
  public function testGetAllowedTransitionsNullStatusIsEmpty(): void {
    $this->assertSame([], $this->manager->getAllowedTransitions(NULL));
  }

  /**
   * @covers ::isTransitionAllowed
   */
  public function testTransitionAllowedWhenTargetIsInAllowedNext(): void {
    $new = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $inReview = $this->makeStatus(2, 5, 'in_review');
    $this->assertTrue($this->manager->isTransitionAllowed($new, $inReview));
  }

  /**
   * @covers ::isTransitionAllowed
   */
  public function testTransitionDeniedWhenTargetNotInAllowedNext(): void {
    // "new" only allows "in_review", not the "approved" target (id 3).
    $new = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $approved = $this->makeStatus(3, 5, 'approved');
    $this->assertFalse($this->manager->isTransitionAllowed($new, $approved));
  }

  /**
   * @covers ::isTransitionAllowed
   */
  public function testTerminalStatusHasNoOutgoingTransitions(): void {
    $approved = $this->makeStatus(3, 5, 'approved', [4], ['returned'], TRUE);
    $returned = $this->makeStatus(4, 5, 'returned');
    $this->assertFalse($this->manager->isTransitionAllowed($approved, $returned));
    $this->assertSame([], $this->manager->getAllowedTransitions($approved));
  }

  /**
   * @covers ::isTransitionAllowed
   */
  public function testSameStatusTransitionIsAllowedNoOp(): void {
    $new = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $sameNew = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $this->assertTrue($this->manager->isTransitionAllowed($new, $sameNew));
  }

  /**
   * @covers ::isTransitionAllowed
   */
  public function testCrossGroupTransitionAlwaysDenied(): void {
    // Even if the from-status lists id 2 as allowed, a target in another
    // group must never be reachable.
    $new = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $foreign = $this->makeStatus(2, 99, 'in_review');
    $this->assertFalse($this->manager->isTransitionAllowed($new, $foreign));
  }

  /**
   * @covers ::isTransitionAllowed
   */
  public function testNullStatusOperandsDeny(): void {
    $new = $this->makeStatus(1, 5, 'new', [2], ['in_review']);
    $this->assertFalse($this->manager->isTransitionAllowed(NULL, $new));
    $this->assertFalse($this->manager->isTransitionAllowed($new, NULL));
  }

  /**
   * @covers ::resolveDefaultStatusForGroup
   */
  public function testResolveDefaultStatusForGroup(): void {
    $default = $this->makeStatus(1, 5, 'new', [2], ['in_review']);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([1 => '1']);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->with('1')->willReturn($default);

    $this->entityTypeManager->method('getStorage')
      ->with('workflow_status')
      ->willReturn($storage);

    $resolved = $this->manager->resolveDefaultStatusForGroup(5);
    $this->assertSame($default, $resolved);
  }

  /**
   * @covers ::resolveDefaultStatusForGroup
   */
  public function testResolveDefaultStatusForGroupReturnsNullForInvalidGroup(): void {
    $this->assertNull($this->manager->resolveDefaultStatusForGroup(0));
  }

  /**
   * @covers ::getEscalationDays
   */
  public function testGetEscalationDaysReadsGlobalConfig(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('escalation_days')->willReturn(14);
    $this->configFactory->method('get')
      ->with('beantragt_workflow.settings')
      ->willReturn($config);

    $this->assertSame(14, $this->manager->getEscalationDays());
  }

  /**
   * @covers ::shouldEscalate
   */
  public function testShouldEscalateTrueForStaleNonTerminalStatus(): void {
    $this->mockEscalationDays(14);

    $status = $this->makeStatus(2, 5, 'in_review', [], [], FALSE);
    $changed = 1000;
    $now = $changed + (15 * 86400);

    $application = $this->mockApplicationWithStatus($status, $changed);
    $this->assertTrue($this->manager->shouldEscalate($application, $now));
  }

  /**
   * @covers ::shouldEscalate
   */
  public function testShouldNotEscalateTerminalStatus(): void {
    $this->mockEscalationDays(14);

    $status = $this->makeStatus(3, 5, 'approved', [], [], TRUE);
    $changed = 1000;
    $now = $changed + (100 * 86400);

    $application = $this->mockApplicationWithStatus($status, $changed);
    $this->assertFalse($this->manager->shouldEscalate($application, $now));
  }

  /**
   * @covers ::shouldEscalate
   */
  public function testShouldNotEscalateWhenEscalationDisabled(): void {
    $this->mockEscalationDays(0);

    $status = $this->makeStatus(2, 5, 'in_review', [], [], FALSE);
    $application = $this->mockApplicationWithStatus($status, 1000);
    $this->assertFalse($this->manager->shouldEscalate($application, PHP_INT_MAX));
  }

  /**
   * @covers ::shouldEscalate
   */
  public function testShouldNotEscalateWithinWindow(): void {
    $this->mockEscalationDays(14);

    $status = $this->makeStatus(2, 5, 'in_review', [], [], FALSE);
    $changed = 1000;
    $now = $changed + (5 * 86400);

    $application = $this->mockApplicationWithStatus($status, $changed);
    $this->assertFalse($this->manager->shouldEscalate($application, $now));
  }

  /**
   * @covers ::validateTransition
   */
  public function testValidateTransitionIgnoresSystemWorkflowAttributes(): void {
    $old = $this->makeStatus(1, 5, 'eingegangen', [2], ['in_bearbeitung']);
    $new = $this->makeStatus(2, 5, 'in_bearbeitung');

    $original = $this->mockApplicationForTransition($old, NULL);
    $application = $this->mockApplicationForTransition($new, '{"submissionLocale":"de","reason":"ok"}');
    $application->method('getOriginal')->willReturn($original);
    $application->method('id')->willReturn(7);
    $application->method('isNew')->willReturn(FALSE);
    $application->method('isWorkflowStatusResetAllowed')->willReturn(FALSE);
    $application->method('getRevisionLogMessage')->willReturn('');

    $account = $this->createMock(AccountInterface::class);
    $account->method('getDisplayName')->willReturn('Admin');
    $account->method('id')->willReturn(1);
    $this->currentUser->method('getAccount')->willReturn($account);

    $this->effectiveWorkflowResolver->method('allowedTargetIds')
      ->with($application, $old)
      ->willReturn(NULL);
    $this->effectiveWorkflowResolver->method('transitionAttributes')
      ->with($application, $old, $new)
      ->willReturn([
        [
          'code' => 'reason',
          'description' => 'Reason',
          'datatype' => 'text',
          'required' => TRUE,
        ],
      ]);
    $this->effectiveWorkflowResolver->expects($this->once())
      ->method('validateTransitionAttributes')
      ->with(
        $this->anything(),
        ['reason' => 'ok'],
      )
      ->willReturn([]);

    $reason = '  Fachlich prüfen. Please review.  ';
    $application->expects($this->exactly(2))->method('setRevisionLogMessage')->with($reason);
    $application->expects($this->once())->method('setRevisionUserId')->with(1);
    $application->expects($this->once())->method('setNewRevision')->with(TRUE);

    RevisionLog::setMessage($application, $reason);
    $this->manager->validateTransition($application);
    $audit = RevisionLog::decode(RevisionLog::build($application, []));
    $this->assertSame($reason, $audit['message']);
    $this->assertSame(['status_changed'], $audit['events']);
  }

  /**
   * Automatic changes are logged as the system, not as the triggering user.
   *
   * @covers ::validateTransition
   */
  public function testValidateTransitionLogsSystemChangeWithoutUser(): void {
    $old = $this->makeStatus(1, 5, 'eingegangen', [2], ['fachverfahren_uebergeben']);
    $new = $this->makeStatus(2, 5, 'fachverfahren_uebergeben');

    $original = $this->mockApplicationForTransition($old, NULL);
    $application = $this->mockApplicationForTransition($new, NULL);
    $application->method('getOriginal')->willReturn($original);
    $application->method('id')->willReturn(7);
    $application->method('isNew')->willReturn(FALSE);
    $application->method('isWorkflowStatusResetAllowed')->willReturn(FALSE);
    $application->method('getRevisionLogMessage')->willReturn('Staff text that must not leak into the log');
    $application->method('getSystemStatusChange')->willReturn('beantragt_delivery:auto_in_case_system');

    $account = $this->createMock(AccountInterface::class);
    $account->method('getDisplayName')->willReturn('Erika');
    $account->method('id')->willReturn(42);
    $this->currentUser->method('getAccount')->willReturn($account);
    $this->effectiveWorkflowResolver->method('allowedTargetIds')->willReturn(NULL);
    $this->effectiveWorkflowResolver->method('transitionAttributes')->willReturn([]);
    $this->effectiveWorkflowResolver->method('validateTransitionAttributes')->willReturn([]);

    // The inherited staff text is replaced by an empty reason.
    $application->expects($this->once())->method('setRevisionLogMessage')->with('');
    $application->expects($this->once())->method('setRevisionUserId')->with(0);
    // A fresh revision date, or the timeline shows the change days early.
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1792483200);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1792483200);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $manager = new WorkflowManager(
      $this->configFactory,
      $this->currentUser,
      $this->eventDispatcher,
      $loggerFactory,
      $this->entityTypeManager,
      $this->effectiveWorkflowResolver,
      $time,
    );
    $manager->validateTransition($application);
    $audit = RevisionLog::decode(RevisionLog::build($application, []));
    $this->assertSame(['status_changed', 'case_system_accepted'], $audit['events']);
    $this->assertSame('', $audit['message']);
  }

  /**
   * A change caused by a staff comment keeps the staff member as actor.
   *
   * @covers ::validateTransition
   */
  public function testValidateTransitionKeepsActorForCommentDrivenChange(): void {
    $old = $this->makeStatus(1, 5, 'eingegangen', [3], ['nachforderung_offen']);
    $new = $this->makeStatus(3, 5, 'nachforderung_offen');

    $original = $this->mockApplicationForTransition($old, NULL);
    $application = $this->mockApplicationForTransition($new, NULL);
    $application->method('getOriginal')->willReturn($original);
    $application->method('id')->willReturn(7);
    $application->method('isNew')->willReturn(FALSE);
    $application->method('isWorkflowStatusResetAllowed')->willReturn(FALSE);
    $application->method('getRevisionLogMessage')->willReturn('');
    $application->method('getSystemStatusChange')->willReturn('beantragt_comment:auto_request_info');

    $account = $this->createMock(AccountInterface::class);
    $account->method('getDisplayName')->willReturn('Erika');
    $account->method('id')->willReturn(42);
    $this->currentUser->method('getAccount')->willReturn($account);
    $this->effectiveWorkflowResolver->method('allowedTargetIds')->willReturn(NULL);
    $this->effectiveWorkflowResolver->method('transitionAttributes')->willReturn([]);
    $this->effectiveWorkflowResolver->method('validateTransitionAttributes')->willReturn([]);

    $application->expects($this->once())->method('setRevisionLogMessage')->with('');
    $application->expects($this->once())->method('setRevisionUserId')->with(42);

    $this->manager->validateTransition($application);
    $audit = RevisionLog::decode(RevisionLog::build($application, []));
    $this->assertSame(['status_changed', 'comment_request_info'], $audit['events']);
    $this->assertSame('', $audit['message']);
  }

  /**
   * A decision notice records its cause as a stable event code.
   *
   * A received notice belongs to the system; a notice released in
   * beantragt.ai keeps the releasing person as revision author.
   *
   * @covers ::validateTransition
   */
  #[DataProvider('decisionReasonProvider')]
  public function testValidateTransitionRecordsDecisionEvent(string $reason, string $event, int $revision_uid): void {
    $old = $this->makeStatus(1, 5, 'fachverfahren_uebergeben');
    $new = $this->makeStatus(4, 5, 'bescheid_erstellt');

    $original = $this->mockApplicationForTransition($old, NULL);
    $application = $this->mockApplicationForTransition($new, NULL);
    $application->method('getOriginal')->willReturn($original);
    $application->method('id')->willReturn(7);
    $application->method('isNew')->willReturn(FALSE);
    // The notice closes the procedure outside the status graph.
    $application->method('isWorkflowStatusResetAllowed')->willReturn(TRUE);
    $application->method('getRevisionLogMessage')->willReturn('');
    $application->method('getSystemStatusChange')->willReturn($reason);

    $account = $this->createMock(AccountInterface::class);
    $account->method('getDisplayName')->willReturn('Erika');
    $account->method('id')->willReturn(42);
    $this->currentUser->method('getAccount')->willReturn($account);

    $application->expects($this->once())->method('setRevisionUserId')->with($revision_uid);

    $this->manager->validateTransition($application);
    $audit = RevisionLog::decode(RevisionLog::build($application, []));
    $this->assertSame(['status_changed', $event], $audit['events']);
    $this->assertSame('', $audit['message']);
  }

  /**
   * Decision reasons with their event code and revision author.
   */
  public static function decisionReasonProvider(): array {
    return [
      'notice received from the case system' => [SemanticStatusChanger::DECISION_ISSUED, 'decision_issued', 0],
      'notice released by staff' => [SemanticStatusChanger::DECISION_RELEASED, 'decision_released', 42],
    ];
  }

  /**
   * Configures the config factory to return a given escalation_days value.
   *
   * @param int $days
   *   The escalation days value.
   */
  protected function mockEscalationDays(int $days): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('escalation_days')->willReturn($days);
    $this->configFactory->method('get')
      ->with('beantragt_workflow.settings')
      ->willReturn($config);
  }

  /**
   * @covers ::dispatchTransition
   */
  public function testDispatchTransitionFiresEventWithLabels(): void {
    $old = $this->makeStatus(1, 5, 'eingegangen');
    $old->method('label')->willReturn('Eingegangen');
    $new = $this->makeStatus(2, 5, 'vollstaendigkeit_geprueft');
    $new->method('label')->willReturn('Vollständigkeit geprüft');

    $original = $this->mockApplicationWithStatus($old, 0);
    $application = $this->mockApplicationWithStatus($new, 0);
    $application->method('getOriginal')->willReturn($original);

    $account = $this->createMock(AccountInterface::class);
    $this->currentUser->method('getAccount')->willReturn($account);

    $this->eventDispatcher->expects($this->once())
      ->method('dispatch')
      ->with(
        $this->callback(static function (WorkflowTransitionEvent $event): bool {
          return $event->getFromStatus() === 'eingegangen'
            && $event->getToStatus() === 'vollstaendigkeit_geprueft'
            && $event->getFromLabel() === 'Eingegangen'
            && $event->getToLabel() === 'Vollständigkeit geprüft';
        }),
        WorkflowTransitionEvent::TRANSITION,
      );

    $this->manager->dispatchTransition($application);
  }

  /**
   * @covers ::dispatchTransition
   */
  public function testDispatchTransitionSkipsUnchangedStatus(): void {
    $status = $this->makeStatus(1, 5, 'eingegangen');
    $original = $this->mockApplicationWithStatus($status, 0);
    $application = $this->mockApplicationWithStatus($status, 0);
    $application->method('getOriginal')->willReturn($original);

    $this->eventDispatcher->expects($this->never())->method('dispatch');

    $this->manager->dispatchTransition($application);
  }

  /**
   * @covers ::dispatchTransition
   */
  public function testDispatchTransitionSkipsWithoutOriginal(): void {
    $status = $this->makeStatus(1, 5, 'eingegangen');
    $application = $this->mockApplicationWithStatus($status, 0);
    $application->method('getOriginal')->willReturn(NULL);

    $this->eventDispatcher->expects($this->never())->method('dispatch');

    $this->manager->dispatchTransition($application);
  }

  /**
   * Builds an application mock whose status field resolves to a status entity.
   *
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface $status
   *   The referenced status entity.
   * @param int $changed
   *   The changed timestamp.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked application.
   */
  protected function mockApplicationWithStatus(WorkflowStatusInterface $status, int $changed): ApplicationInterface {
    $item = new \stdClass();
    $item->entity = $status;

    $field = $this->getMockBuilder(FieldItemListInterface::class)
      ->getMock();
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->with('entity')->willReturn($status);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->with('field_workflow_status')->willReturn(TRUE);
    $application->method('get')->with('field_workflow_status')->willReturn($field);
    $application->method('getChangedTime')->willReturn($changed);

    return $application;
  }

  /**
   * Builds an application mock with status and workflow attributes.
   */
  protected function mockApplicationForTransition(WorkflowStatusInterface $status, ?string $workflowAttributes): ApplicationInterface {
    $statusField = $this->getMockBuilder(FieldItemListInterface::class)
      ->getMock();
    $statusField->method('isEmpty')->willReturn(FALSE);
    $statusField->method('__get')->with('entity')->willReturn($status);

    $attributesField = $this->getMockBuilder(FieldItemListInterface::class)
      ->getMock();
    $attributesField->method('isEmpty')->willReturn($workflowAttributes === NULL);
    $attributesField->method('__get')
      ->willReturnCallback(static fn (string $property): ?string => $property === 'value' ? $workflowAttributes : NULL);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field): bool => in_array($field, [
        'field_workflow_status',
        'field_workflow_attributes',
      ], TRUE));
    $application->method('get')
      ->willReturnCallback(
        static fn (string $field): FieldItemListInterface => $field === 'field_workflow_status'
          ? $statusField
          : $attributesField,
      );

    return $application;
  }

}
