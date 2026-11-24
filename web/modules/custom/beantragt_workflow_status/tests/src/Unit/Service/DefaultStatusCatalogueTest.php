<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow_status\Unit\Service;

use Drupal\beantragt_workflow_status\Service\WorkflowStatusSeeder;
use Drupal\Tests\UnitTestCase;

/**
 * Pins the default status catalogue of a new organisation.
 *
 * Three statuses are the standard: they are wired from the default status
 * and are all an untouched form ever sees. Eight library statuses sit in the
 * catalogue without any incoming edge from the standard; a form switches
 * them on in its own workflow.
 *
 * @coversDefaultClass \Drupal\beantragt_workflow_status\Service\WorkflowStatusSeeder
 * @group beantragt_workflow_status
 */
final class DefaultStatusCatalogueTest extends UnitTestCase {

  /**
   * The standard: reachable from the default status.
   */
  private const STANDARD = ['eingegangen', 'in_bearbeitung', 'abgeschlossen'];

  /**
   * The library: in the catalogue, reachable from no standard status.
   */
  private const LIBRARY = [
    'nachforderung_offen',
    'fachverfahren_weiterleitung',
    'fachverfahren_uebergeben',
    'bescheid_erstellt',
    'im_fachverfahren_abgelegt',
    'genehmigt',
    'abgelehnt',
    'zurueckgezogen',
  ];

  /**
   * The expected catalogue: machine name to flags, terminal, allowed next.
   *
   * @var array<string, array{0: list<string>, 1: bool, 2: list<string>}>
   */
  private const EXPECTED = [
    'eingegangen' => [['received'], FALSE, ['in_bearbeitung', 'abgeschlossen']],
    'in_bearbeitung' => [['documents_received'], FALSE, ['abgeschlossen']],
    'abgeschlossen' => [['closed'], TRUE, []],
    'nachforderung_offen' => [
      ['request_info'],
      FALSE,
      ['in_bearbeitung', 'abgeschlossen', 'abgelehnt', 'zurueckgezogen'],
    ],
    'fachverfahren_weiterleitung' => [
      ['hand_over'],
      FALSE,
      [
        'fachverfahren_uebergeben',
        'in_bearbeitung',
        'nachforderung_offen',
        'abgeschlossen',
        'zurueckgezogen',
      ],
    ],
    'fachverfahren_uebergeben' => [
      ['in_case_system'],
      FALSE,
      [
        'genehmigt',
        'abgelehnt',
        'bescheid_erstellt',
        'im_fachverfahren_abgelegt',
        'nachforderung_offen',
        'abgeschlossen',
        'zurueckgezogen',
      ],
    ],
    'bescheid_erstellt' => [['decision_issued', 'closed'], TRUE, []],
    'im_fachverfahren_abgelegt' => [['filed', 'closed'], TRUE, []],
    'genehmigt' => [['approved', 'closed'], TRUE, []],
    'abgelehnt' => [['rejected', 'closed'], TRUE, []],
    'zurueckgezogen' => [['withdrawn', 'closed'], TRUE, []],
  ];

  /**
   * Flags the product resolves a target by, each to exactly one status.
   *
   * "closed" is left out: it marks every closing status and is only a
   * fallback of the closing changes.
   */
  private const PRODUCT_FLAGS = [
    'received' => 'eingegangen',
    'documents_received' => 'in_bearbeitung',
    'request_info' => 'nachforderung_offen',
    'hand_over' => 'fachverfahren_weiterleitung',
    'in_case_system' => 'fachverfahren_uebergeben',
    'decision_issued' => 'bescheid_erstellt',
    'filed' => 'im_fachverfahren_abgelegt',
    'approved' => 'genehmigt',
    'rejected' => 'abgelehnt',
    'withdrawn' => 'zurueckgezogen',
  ];

  /**
   * Texts applicants read per status, de and en.
   *
   * The library statuses keep the texts they had before the standard, so a
   * form that switches them on sends the same status mails as before
   * (WorkflowNotificationSubscriber compares these texts).
   */
  private const CITIZEN_TEXTS = [
    'eingegangen' => ['Eingegangen', 'Received'],
    'in_bearbeitung' => ['In Bearbeitung', 'In progress'],
    'abgeschlossen' => ['Abgeschlossen', 'Completed'],
    'nachforderung_offen' => ['Nachforderung offen', 'Information requested'],
    'fachverfahren_weiterleitung' => ['In Bearbeitung', 'In progress'],
    'fachverfahren_uebergeben' => ['In Bearbeitung', 'In progress'],
    'bescheid_erstellt' => ['Bescheid erstellt', 'Decision issued'],
    'im_fachverfahren_abgelegt' => ['Abgeschlossen', 'Completed'],
    'genehmigt' => ['Bewilligt', 'Approved'],
    'abgelehnt' => ['Abgelehnt', 'Rejected'],
    'zurueckgezogen' => ['Zurückgezogen', 'Withdrawn'],
  ];

  /**
   * Exactly the three standard statuses are reachable from the default.
   */
  public function testThreeReachableFromTheDefaultEightNot(): void {
    $definitions = $this->definitions();
    $defaults = array_keys(array_filter($definitions, static fn(array $definition): bool => $definition['is_default'] === TRUE));
    $this->assertSame(['eingegangen'], $defaults);
    $reached = $this->reachableFrom('eingegangen');
    sort($reached);
    $standard = self::STANDARD;
    sort($standard);
    $this->assertSame($standard, $reached);
    foreach (self::LIBRARY as $machine_name) {
      $this->assertNotContains($machine_name, $reached, $machine_name);
    }
    // No standard status names a library status.
    foreach (self::STANDARD as $machine_name) {
      $this->assertSame([], array_diff($definitions[$machine_name]['allowed_next'], self::STANDARD), $machine_name);
    }
  }

  /**
   * The catalogue holds exactly eleven statuses with these flags and edges.
   */
  public function testCatalogueHasExactlyTheElevenStatuses(): void {
    $definitions = $this->definitions();
    $this->assertSame(array_keys(self::EXPECTED), array_keys($definitions));
    $this->assertSame([...self::STANDARD, ...self::LIBRARY], array_keys($definitions));
    $this->assertCount(11, $definitions);
    foreach (self::EXPECTED as $machine_name => [$flags, $terminal, $next]) {
      $definition = $definitions[$machine_name];
      $this->assertSame($flags, $definition['semantic_flags'], $machine_name);
      $this->assertSame($terminal, $definition['is_terminal'], $machine_name);
      $this->assertSame($next, $definition['allowed_next'], $machine_name);
    }
    // "In Prüfung" was replaced by "In Bearbeitung".
    $this->assertArrayNotHasKey('fachpruefung', $definitions);
    // The closing status of the standard claims no outcome.
    $this->assertSame(['closed'], $definitions['abgeschlossen']['semantic_flags']);
  }

  /**
   * Applicants read the texts above; labels are staff-only.
   *
   * Effective text as WorkflowStatusInterface::getCitizenLabel() reads it:
   * German falls back to the label, English to German.
   */
  public function testCitizenTexts(): void {
    $actual = [];
    foreach ($this->definitions() as $machine_name => $definition) {
      $de = $definition['citizen_label_de'] !== '' ? $definition['citizen_label_de'] : $definition['label'];
      $en = $definition['citizen_label_en'] !== '' ? $definition['citizen_label_en'] : $de;
      $actual[$machine_name] = [$de, $en];
    }
    $this->assertSame(self::CITIZEN_TEXTS, $actual);
    $this->assertSame(['Eingegangen', 'In Bearbeitung', 'Abgeschlossen'], array_map(
      fn(string $machine_name): string => $this->definitions()[$machine_name]['label'],
      self::STANDARD,
    ));
    $this->assertSame('Unterlagen nachgefordert', $this->definitions()['nachforderung_offen']['label']);
    $this->assertSame('Im Fachverfahren', $this->definitions()['fachverfahren_uebergeben']['label']);
  }

  /**
   * Every flag the product resolves names exactly one default status.
   */
  public function testEveryProductFlagResolvesToExactlyOneStatus(): void {
    foreach (self::PRODUCT_FLAGS as $flag => $machine_name) {
      $carriers = array_keys(array_filter(
        $this->definitions(),
        static fn(array $definition): bool => in_array($flag, $definition['semantic_flags'], TRUE),
      ));
      $this->assertSame([$machine_name], $carriers, $flag);
    }
    // The repair of older catalogues adds library statuses only.
    foreach (WorkflowStatusSeeder::SYSTEM_STATUS_FLAGS as $flag => $machine_name) {
      $this->assertSame(self::PRODUCT_FLAGS[$flag], $machine_name, $flag);
      $this->assertContains($machine_name, self::LIBRARY, $flag);
    }
  }

  /**
   * Terminal statuses lead nowhere; every open status reaches an end.
   *
   * Holds for the library as well: a form that switches a library status on
   * along its proposed edges always keeps a way to an end.
   */
  public function testTerminalStatusesAreEndsAndEveryOpenStatusReachesOne(): void {
    $definitions = $this->definitions();
    foreach ($definitions as $machine_name => $definition) {
      if ($definition['is_terminal']) {
        $this->assertSame([], $definition['allowed_next'], $machine_name);
        $this->assertContains('closed', $definition['semantic_flags'], $machine_name);
        $this->assertSame(0, $definition['sla_days'], $machine_name);
        $this->assertSame(0, $definition['escalation_days'], $machine_name);
        continue;
      }
      $this->assertNotSame([], $definition['allowed_next'], $machine_name);
      $reached = $this->reachableFrom($machine_name);
      $ends = array_filter($reached, static fn(string $next): bool => $definitions[$next]['is_terminal']);
      $this->assertNotSame([], $ends, $machine_name);
    }
  }

  /**
   * The library keeps the edges its automatic changes need, as proposals.
   *
   * They take effect only in a form workflow that switches the statuses on;
   * pairs of the status an application is in and the flag of the target.
   */
  public function testLibraryProposalsCoverTheAutomaticChanges(): void {
    $cases = [
      // The case system accepts a transfer started by the status trigger.
      ['fachverfahren_weiterleitung', 'in_case_system'],
      // Documents taken over in a batch review.
      ['nachforderung_offen', 'documents_received'],
      // A public staff message while the transfer is pending or running.
      ['fachverfahren_weiterleitung', 'request_info'],
      ['fachverfahren_uebergeben', 'request_info'],
    ];
    $definitions = $this->definitions();
    foreach ($cases as [$from, $flag]) {
      $this->assertContains(self::PRODUCT_FLAGS[$flag], $definitions[$from]['allowed_next'], "$from by $flag");
    }
    // Batch "not pursued": every open status reaches a neutral end, the
    // standard through "Abgeschlossen".
    foreach ($definitions as $machine_name => $definition) {
      if (!$definition['is_terminal']) {
        $this->assertContains('abgeschlossen', $definition['allowed_next'], $machine_name);
      }
    }
  }

  /**
   * Machine names reachable from a status over allowed_next, itself included.
   *
   * @return list<string>
   *   Machine names in visiting order.
   */
  private function reachableFrom(string $start): array {
    $definitions = $this->definitions();
    $seen = [$start => TRUE];
    $queue = [$start];
    while ($queue !== []) {
      $current = array_shift($queue);
      foreach ($definitions[$current]['allowed_next'] as $next) {
        $this->assertArrayHasKey($next, $definitions, "$current to $next");
        if (!isset($seen[$next])) {
          $seen[$next] = TRUE;
          $queue[] = $next;
        }
      }
    }
    return array_keys($seen);
  }

  /**
   * The default definitions keyed by machine name.
   *
   * @return array<string, array<string, mixed>>
   *   Definitions in seed order.
   */
  private function definitions(): array {
    $definitions = [];
    foreach (WorkflowStatusSeeder::getDefaultStatusDefinitions() as $definition) {
      $definitions[$definition['machine_name']] = $definition;
    }
    return $definitions;
  }

}
