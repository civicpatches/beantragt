<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow_status\Unit\Service;

use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow_status\Service\WorkflowStatusSeeder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests adding the case-system statuses to existing catalogues.
 *
 * @coversDefaultClass \Drupal\beantragt_workflow_status\Service\WorkflowStatusSeeder
 * @group beantragt_workflow_status
 */
final class WorkflowStatusSeederSystemStatusesTest extends UnitTestCase {

  /**
   * Recorded field_allowed_next values keyed by machine name.
   *
   * @var array<string, list<int>>
   */
  private array $edges = [];

  /**
   * Citizen texts the created statuses carry, keyed by machine name.
   *
   * @var array<string, array{de: string, en: string}>
   */
  private array $citizenLabels = [];

  /**
   * A catalogue without the flags gets all system statuses, wired like a seed.
   *
   * They are library statuses: their own proposals and the transitions from
   * other library statuses, never a transition from the standard.
   *
   * @covers ::addMissingSystemStatuses
   */
  public function testAddsAllSystemStatusesAndTheirTransitions(): void {
    $existing = [
      $this->statusMock('eingegangen', 1, TRUE, [], [2]),
      $this->statusMock('nachforderung_offen', 2, FALSE, ['request_info'], []),
      $this->statusMock('in_bearbeitung', 3, FALSE, [], []),
    ];
    $seeder = new WorkflowStatusSeeder($this->entityTypeManager($existing, [
      'fachverfahren_weiterleitung' => 10,
      'fachverfahren_uebergeben' => 11,
      'bescheid_erstellt' => 12,
      'im_fachverfahren_abgelegt' => 13,
    ]));

    $created = $seeder->addMissingSystemStatuses(5);

    $this->assertSame([
      'fachverfahren_weiterleitung',
      'fachverfahren_uebergeben',
      'bescheid_erstellt',
      'im_fachverfahren_abgelegt',
    ], $created);
    // The standard stays as it is: no edge into a library status.
    $this->assertArrayNotHasKey('eingegangen', $this->edges);
    $this->assertArrayNotHasKey('in_bearbeitung', $this->edges);
    $this->assertSame([11, 3, 2], $this->edges['fachverfahren_weiterleitung']);
    $this->assertSame([12, 13, 2], $this->edges['fachverfahren_uebergeben']);
    $this->assertArrayNotHasKey('nachforderung_offen', $this->edges);
    // New statuses carry their citizen texts from the seed definition.
    $this->assertSame(['de' => 'In Bearbeitung', 'en' => 'In progress'], $this->citizenLabels['fachverfahren_weiterleitung']);
    $this->assertSame(['de' => 'Abgeschlossen', 'en' => 'Completed'], $this->citizenLabels['im_fachverfahren_abgelegt']);
    $this->assertSame(['de' => '', 'en' => 'Decision issued'], $this->citizenLabels['bescheid_erstellt']);
  }

  /**
   * Every seeded status has citizen texts and the hand-over/filing statuses.
   */
  public function testDefaultCatalogueCarriesHandOverAndFilingStatuses(): void {
    $definitions = [];
    foreach (WorkflowStatusSeeder::getDefaultStatusDefinitions() as $definition) {
      $this->assertArrayHasKey('citizen_label_de', $definition);
      $this->assertArrayHasKey('citizen_label_en', $definition);
      $definitions[$definition['machine_name']] = $definition;
    }
    $hand_over = $definitions['fachverfahren_weiterleitung'];
    $this->assertSame(['hand_over'], $hand_over['semantic_flags']);
    $this->assertFalse($hand_over['is_terminal']);
    $this->assertSame([
      'fachverfahren_uebergeben',
      'in_bearbeitung',
      'nachforderung_offen',
      'abgeschlossen',
      'zurueckgezogen',
    ], $hand_over['allowed_next']);
    $filed = $definitions['im_fachverfahren_abgelegt'];
    $this->assertSame(['filed', 'closed'], $filed['semantic_flags']);
    $this->assertTrue($filed['is_terminal']);
    $this->assertSame([], $filed['allowed_next']);
    // A library status: no standard status leads to it.
    foreach (['eingegangen', 'in_bearbeitung'] as $source) {
      $this->assertNotContains('fachverfahren_weiterleitung', $definitions[$source]['allowed_next'], $source);
    }
    foreach (['genehmigt', 'abgelehnt', 'im_fachverfahren_abgelegt'] as $target) {
      $this->assertContains($target, $definitions['fachverfahren_uebergeben']['allowed_next'], $target);
    }
    // Nachreichung: "Übernehmen und fortsetzen" returns here (step 2).
    $this->assertSame(['documents_received'], $definitions['in_bearbeitung']['semantic_flags']);
    $this->assertSame('Bewilligt', $definitions['genehmigt']['citizen_label_de']);
    $this->assertSame('In Bearbeitung', $definitions['fachverfahren_uebergeben']['citizen_label_de']);
    $this->assertSame([
      'hand_over' => 'fachverfahren_weiterleitung',
      'in_case_system' => 'fachverfahren_uebergeben',
      'decision_issued' => 'bescheid_erstellt',
      'filed' => 'im_fachverfahren_abgelegt',
    ], WorkflowStatusSeeder::SYSTEM_STATUS_FLAGS);
  }

  /**
   * A status that already carries a flag keeps the catalogue as it is.
   *
   * Mutation probe: creating the status by machine name alone adds a second
   * in_case_system status next to the custom one.
   *
   * @covers ::addMissingSystemStatuses
   */
  public function testKeepsCustomFlaggedStatusesAndIsIdempotent(): void {
    $existing = [
      $this->statusMock('eingegangen', 1, TRUE, [], []),
      $this->statusMock('weitergeben', 6, FALSE, ['hand_over'], []),
      $this->statusMock('an_dms_uebergeben', 7, FALSE, ['in_case_system'], []),
      $this->statusMock('bescheid_erstellt', 8, FALSE, ['decision_issued', 'closed'], []),
      $this->statusMock('in_der_akte', 9, FALSE, ['filed', 'closed'], []),
    ];
    $seeder = new WorkflowStatusSeeder($this->entityTypeManager($existing, []));

    $this->assertSame([], $seeder->addMissingSystemStatuses(5));
    $this->assertSame([], $this->edges);
  }

  /**
   * An incomplete catalogue without default status is left to the seed.
   *
   * @covers ::addMissingSystemStatuses
   */
  public function testSkipsCatalogueWithoutDefaultStatus(): void {
    $seeder = new WorkflowStatusSeeder($this->entityTypeManager([
      $this->statusMock('in_bearbeitung', 3, FALSE, [], []),
    ], []));

    $this->assertSame([], $seeder->addMissingSystemStatuses(5));
  }

  /**
   * Builds the entity type manager for one catalogue.
   *
   * @param list<\Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface> $existing
   *   Existing statuses of the group.
   * @param array<string, int> $new_ids
   *   Ids of statuses the storage creates, keyed by machine name.
   */
  private function entityTypeManager(array $existing, array $new_ids): EntityTypeManagerInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->with('field_group', 5)->willReturnSelf();
    $query->method('execute')->willReturn(array_map(static fn(WorkflowStatusInterface $status): int => (int) $status->id(), $existing));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($existing);
    if ($new_ids === []) {
      $storage->expects($this->never())->method('create');
    }
    $storage->method('create')->willReturnCallback(function (array $values) use ($new_ids): WorkflowStatusInterface {
      $machine_name = $values['field_machine_name'];
      $this->assertFalse($values['field_is_default']);
      $flags = array_column($values['field_semantic_flags'], 'value');
      $this->citizenLabels[$machine_name] = [
        'de' => $values['field_citizen_label_de'],
        'en' => $values['field_citizen_label_en'],
      ];
      return $this->statusMock($machine_name, $new_ids[$machine_name], FALSE, $flags, []);
    });
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('workflow_status')->willReturn($storage);
    return $entity_type_manager;
  }

  /**
   * Builds a status mock that records its transitions.
   *
   * @param string $machine_name
   *   Machine name.
   * @param int $id
   *   Status id.
   * @param bool $default
   *   Whether it is the default status.
   * @param list<string> $flags
   *   Semantic flags.
   * @param list<int> $next
   *   Allowed next status ids.
   */
  private function statusMock(string $machine_name, int $id, bool $default, array $flags, array $next): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('id')->willReturn($id);
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('isDefault')->willReturn($default);
    $status->method('hasSemanticFlag')->willReturnCallback(static fn(string $flag): bool => in_array($flag, $flags, TRUE));
    $status->method('getAllowedNextIds')->willReturnCallback(fn(): array => $this->edges[$machine_name] ?? $next);
    $status->method('set')->willReturnCallback(function (string $field, mixed $value) use ($machine_name, $status): WorkflowStatusInterface {
      if ($field === 'field_allowed_next') {
        $this->edges[$machine_name] = array_map(static fn(array $item): int => (int) $item['target_id'], $value);
      }
      return $status;
    });
    return $status;
  }

}
