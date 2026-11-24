<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests automatic status changes by semantic flag.
 *
 * @coversDefaultClass \Drupal\beantragt_workflow\Service\SemanticStatusChanger
 * @group beantragt_workflow
 */
class SemanticStatusChangerTest extends UnitTestCase {

  /**
   * The stored application the service loads.
   */
  protected ApplicationInterface&MockObject $stored;

  /**
   * Statuses of catalogue 5 keyed by id, in weight order.
   *
   * @var array<int, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   */
  protected array $catalogue = [];

  /**
   * The logger channel mock.
   */
  protected LoggerChannelInterface&MockObject $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->catalogue = [
      1 => $this->makeStatus(1, 'eingegangen', ['received']),
      2 => $this->makeStatus(2, 'fachverfahren_uebergeben', ['in_case_system']),
      3 => $this->makeStatus(3, 'umgesetzt', ['closed']),
      4 => $this->makeStatus(4, 'genehmigt', ['approved', 'closed'], TRUE),
      5 => $this->makeStatus(5, 'bescheid_erstellt', ['decision_issued', 'closed'], TRUE),
    ];
  }

  /**
   * FIT-Connect acceptance moves to the handed-over status inside the graph.
   *
   * @covers ::caseSystemAccepted
   */
  public function testCaseSystemAcceptedUsesGraphAndMarksReason(): void {
    $changer = $this->changer(1);
    $this->stored->expects($this->once())->method('setWorkflowStatusId')->with(2);
    $this->stored->expects($this->once())->method('markSystemStatusChange')->with(SemanticStatusChanger::CASE_SYSTEM_ACCEPTED);
    $this->stored->expects($this->never())->method('allowWorkflowStatusReset');
    $this->stored->expects($this->once())->method('save');

    $passed = $this->createMock(ApplicationInterface::class);
    $passed->method('id')->willReturn(9);
    $passed->expects($this->never())->method('save');

    $this->assertTrue($changer->caseSystemAccepted($passed));
  }

  /**
   * A decision notice closes outside the graph on the dedicated status.
   *
   * @covers ::decisionIssued
   */
  public function testDecisionIssuedPrefersDedicatedStatusOutsideGraph(): void {
    $changer = $this->changer(2);
    $this->stored->expects($this->once())->method('setWorkflowStatusId')->with(5);
    $this->stored->expects($this->once())->method('markSystemStatusChange')->with(SemanticStatusChanger::DECISION_ISSUED);
    $this->stored->expects($this->once())->method('allowWorkflowStatusReset');

    $this->assertTrue($changer->decisionIssued($this->passed()));
  }

  /**
   * A notice a person released keeps that person as actor, silently.
   *
   * @covers ::decisionIssued
   */
  public function testDecisionReleasedByPersonIsNoSystemChange(): void {
    $changer = $this->changer(2);
    $this->stored->expects($this->once())->method('setWorkflowStatusId')->with(5);
    $this->stored->expects($this->once())->method('markSystemStatusChange')->with(SemanticStatusChanger::DECISION_RELEASED);

    $this->assertTrue($changer->decisionIssued($this->passed(), TRUE));
    $this->assertContains(SemanticStatusChanger::DECISION_RELEASED, SemanticStatusChanger::SILENT_REASONS);
    $this->assertNotContains(SemanticStatusChanger::DECISION_RELEASED, SemanticStatusChanger::SYSTEM_ACTOR_REASONS);
  }

  /**
   * Without a dedicated status, a neutral closed status is used.
   *
   * @covers ::decisionIssued
   */
  public function testDecisionIssuedFallsBackToNeutralClosedStatus(): void {
    unset($this->catalogue[5], $this->catalogue[3]);
    $this->catalogue[6] = $this->makeStatus(6, 'erledigt', ['closed']);
    $changer = $this->changer(1);
    // "genehmigt" is lighter but claims an outcome the notice may not have.
    $this->stored->expects($this->once())->method('setWorkflowStatusId')->with(6);

    $this->assertTrue($changer->decisionIssued($this->passed()));
  }

  /**
   * A procedure closed by staff stays as staff left it.
   *
   * @covers ::decisionIssued
   */
  public function testDecisionIssuedLeavesClosedProcedureAlone(): void {
    $changer = $this->changer(3);
    $this->stored->expects($this->never())->method('save');

    $this->assertFalse($changer->decisionIssued($this->passed()));
  }

  /**
   * A refused save is logged and never thrown at the triggering fact.
   *
   * @covers ::caseSystemAccepted
   */
  public function testRefusedSaveIsLoggedNotThrown(): void {
    $changer = $this->changer(1);
    $this->stored->method('save')->willThrowException(new \InvalidArgumentException('Workflow transition not allowed.'));
    $this->logger->expects($this->once())->method('warning');

    $this->assertFalse($changer->caseSystemAccepted($this->passed()));
  }

  /**
   * A catalogue without the flag leaves the status unchanged.
   *
   * @covers ::caseSystemAccepted
   */
  public function testMissingFlagLeavesStatus(): void {
    unset($this->catalogue[2]);
    $changer = $this->changer(1);
    $this->stored->expects($this->never())->method('save');
    $this->logger->expects($this->once())->method('warning');

    $this->assertFalse($changer->caseSystemAccepted($this->passed()));
  }

  /**
   * A governing workflow without the flag is a normal outcome, no warning.
   *
   * @covers ::caseSystemAccepted
   */
  public function testFlagOutsideGoverningWorkflowIsNoWarning(): void {
    $changer = $this->changer(1, [1, 3]);
    $this->stored->expects($this->never())->method('save');
    $this->logger->expects($this->never())->method('warning');
    $this->logger->expects($this->once())->method('info');

    $this->assertFalse($changer->caseSystemAccepted($this->passed()));
  }

  /**
   * A notice closes inside the governing workflow first, then the catalogue.
   *
   * @covers ::decisionIssued
   */
  public function testClosingChangePrefersGoverningWorkflowThenCatalogue(): void {
    // "umgesetzt" (closed) is the workflow's own end: the dedicated decision
    // status of the catalogue is not part of it.
    $changer = $this->changer(1, [1, 3]);
    $this->stored->expects($this->once())->method('setWorkflowStatusId')->with(3);
    $this->assertTrue($changer->decisionIssued($this->passed()));

    // Without any closing status in the workflow the catalogue decides.
    $changer = $this->changer(1, [1, 2]);
    $this->stored->expects($this->once())->method('setWorkflowStatusId')->with(5);
    $this->logger->expects($this->never())->method('warning');
    $this->assertTrue($changer->decisionIssued($this->passed()));
  }

  /**
   * A notice that closes nothing anywhere stays a warning.
   *
   * Neither the governing workflow nor the catalogue holds a closing
   * status: the case stays open, which is no normal outcome.
   *
   * @covers ::decisionIssued
   */
  public function testClosingChangeWithoutAnyClosingStatusWarns(): void {
    $this->catalogue = [
      1 => $this->makeStatus(1, 'eingegangen', ['received']),
      2 => $this->makeStatus(2, 'fachverfahren_uebergeben', ['in_case_system']),
    ];
    $changer = $this->changer(1, [1, 2]);
    $this->stored->expects($this->never())->method('save');
    $this->logger->expects($this->once())->method('warning');
    $this->logger->expects($this->never())->method('info');

    $this->assertFalse($changer->decisionIssued($this->passed()));
  }

  /**
   * Builds the service with a stored application on the given status.
   */
  protected function changer(int $current_status_id, ?array $governing = NULL): SemanticStatusChanger {
    $this->stored = $this->createMock(ApplicationInterface::class);
    $this->stored->method('id')->willReturn(9);
    $this->stored->method('getWorkflowStatusId')->willReturn($current_status_id);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('loadUnchanged')->with(9)->willReturn($this->stored);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine(array_keys($this->catalogue), array_keys($this->catalogue)));

    $status_storage = $this->createMock(EntityStorageInterface::class);
    $status_storage->method('getQuery')->willReturn($query);
    $status_storage->method('load')->willReturnCallback(fn(int $id): ?WorkflowStatusInterface => $this->catalogue[$id] ?? NULL);
    $status_storage->method('loadMultiple')->willReturnCallback(fn(array $ids): array => array_intersect_key($this->catalogue, array_flip($ids)));

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $manager->method('getStorage')->willReturnCallback(fn(string $type): EntityStorageInterface => $type === 'application' ? $application_storage : $status_storage);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->logger);

    $resolver = NULL;
    if ($governing !== NULL) {
      $resolver = $this->createMock(EffectiveWorkflowResolver::class);
      $resolver->method('governingStatusIds')->willReturn($governing);
    }
    return new SemanticStatusChanger($manager, $factory, $resolver);
  }

  /**
   * Builds the application object a caller passes in.
   */
  protected function passed(): ApplicationInterface {
    $passed = $this->createMock(ApplicationInterface::class);
    $passed->method('id')->willReturn(9);
    return $passed;
  }

  /**
   * Builds a status of catalogue 5.
   */
  protected function makeStatus(int $id, string $machine_name, array $flags, bool $terminal = FALSE): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('id')->willReturn((string) $id);
    $status->method('getGroupId')->willReturn(5);
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('getSemanticFlags')->willReturn($flags);
    $status->method('hasSemanticFlag')->willReturnCallback(static fn(string $flag): bool => in_array($flag, $flags, TRUE));
    $status->method('isTerminal')->willReturn($terminal);
    return $status;
  }

}
