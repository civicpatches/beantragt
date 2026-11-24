<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Automation asks the workflow that governs the application (lookup rule).
 *
 * Runs against the seeded catalogue of an organisation. A form without own
 * workflow is governed by the organisation graph (the statuses reachable
 * from the default status); a form with own workflow by its status list.
 * A status of the catalogue outside that set is no target of automation.
 */
#[Group('beantragt_workflow')]
final class GoverningWorkflowLookupKernelTest extends GroupKernelTestBase {

  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * Original application data key environment value.
   */
  private string|false $originalKey;

  /**
   * The organisation owning the catalogue.
   */
  private GroupInterface $organisation;

  /**
   * The jurisdiction of the organisation.
   */
  private GroupInterface $jurisdiction;

  /**
   * Log records of the workflow channel: [level, message].
   *
   * @var list<array{0: mixed, 1: string}>
   */
  private array $records = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . str_repeat('k', 32));
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
    $this->jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Lookup jurisdiction',
      'field_slug' => 'lookup-jur',
    ]);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Lookup organisation',
      'field_slug' => 'lookup-org',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $this->container->get('beantragt_workflow_status.status_seeder')->seedForGroup($this->organisation);
    $records = &$this->records;
    $this->container->get('logger.factory')->addLogger(new class($records) implements LoggerInterface {
      use RfcLoggerTrait;

      public function __construct(private array &$records) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? NULL) === 'beantragt_workflow') {
          $this->records[] = [$level, (string) $message];
        }
      }

    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? self::KEY_ENV : self::KEY_ENV . '=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * A form without own workflow is governed by the reachable graph.
   */
  public function testUntouchedFormIsGovernedByTheReachableGraph(): void {
    $form = $this->createForm(NULL);
    $application = $this->applicationIn($form, 'eingegangen');
    $catalogue = $this->catalogueIds();
    // The standard: the three statuses wired from the default, no library.
    $standard = [
      (int) $this->catalogueStatus('eingegangen')->id(),
      (int) $this->catalogueStatus('in_bearbeitung')->id(),
      (int) $this->catalogueStatus('abgeschlossen')->id(),
    ];
    $this->assertCount(11, $catalogue);
    $this->assertEqualsCanonicalizing($standard, $this->resolver()->governingStatusIds($application));
    $this->assertEqualsCanonicalizing($standard, $this->resolver()->governingStatusIdsForForm($form, (int) $this->organisation->id()));
    $this->assertFalse($this->resolver()->formWorkflowHasFlag($form, [(int) $this->organisation->id()], 'hand_over'));
    $this->assertFalse($this->resolver()->formWorkflowHasFlag($form, [(int) $this->organisation->id()], 'request_info'));
    $this->assertTrue($this->resolver()->formWorkflowHasFlag($form, [(int) $this->organisation->id()], 'closed'));
    // A library status without an incoming edge is no part of it.
    $library = $this->createStatus('bibliothek', ['closed'], TRUE);
    $this->assertNotContains((int) $library->id(), $this->resolver()->governingStatusIds($application));
    $this->assertNotContains((int) $library->id(), $this->resolver()->governingStatusIdsForForm($form, (int) $this->organisation->id()));
    // An edge from a reachable status makes it part of the standard.
    $this->addEdge('in_bearbeitung', 'bibliothek');
    $this->assertContains((int) $library->id(), $this->resolver()->governingStatusIds($application));
    // A terminal status never leads further, whatever its field holds.
    $behind = $this->createStatus('hinter_ende', ['closed'], TRUE);
    $library->set('field_allowed_next', [['target_id' => $behind->id()]])->save();
    $this->assertNotContains((int) $behind->id(), $this->resolver()->governingStatusIds($application));
    // Another group is asked explicitly; its catalogue is not this one.
    $jurisdiction_set = $this->resolver()->governingStatusIds($application, (int) $this->jurisdiction->id());
    $this->assertNotSame([], $jurisdiction_set);
    $this->assertSame([], array_intersect($catalogue, $jurisdiction_set));
  }

  /**
   * A form with own workflow is governed by its status list.
   */
  public function testFormWorkflowGovernsByItsStatusList(): void {
    $form = $this->createForm($this->workflow(
      ['eingegangen', 'in_bearbeitung', 'zurueckgezogen'],
      [['eingegangen', 'in_bearbeitung']],
    ));
    $application = $this->applicationIn($form, 'eingegangen');
    $expected = [
      (int) $this->catalogueStatus('eingegangen')->id(),
      (int) $this->catalogueStatus('in_bearbeitung')->id(),
      (int) $this->catalogueStatus('zurueckgezogen')->id(),
    ];
    $this->assertEqualsCanonicalizing($expected, $this->resolver()->governingStatusIds($application));
    $this->assertEqualsCanonicalizing($expected, $this->resolver()->governingStatusIdsForForm($form, (int) $this->organisation->id()));
    $this->assertTrue($this->resolver()->formWorkflowHasFlag($form, [(int) $this->organisation->id()], 'withdrawn'));
    $this->assertFalse($this->resolver()->formWorkflowHasFlag($form, [(int) $this->organisation->id()], 'hand_over'));
    // An override without status list restricts nothing.
    $open = $this->createForm([
      'mode' => 'override',
      'transitions' => [['from' => 'eingegangen', 'to' => 'in_bearbeitung']],
    ]);
    $this->assertEqualsCanonicalizing($this->catalogueIds(), $this->resolver()->governingStatusIds($this->applicationIn($open, 'eingegangen')));
    // A form that switches the hand-over status on has it.
    $switched = $this->createForm($this->workflow(
      ['eingegangen', 'in_bearbeitung', 'fachverfahren_weiterleitung', 'abgeschlossen'],
      [['eingegangen', 'fachverfahren_weiterleitung']],
    ));
    $this->assertTrue($this->resolver()->formWorkflowHasFlag($switched, [(int) $this->organisation->id()], 'hand_over'));
  }

  /**
   * An in-graph change to a status the form left out is skipped silently.
   */
  public function testInGraphTargetOutsideTheFormWorkflowIsNoWarning(): void {
    $form = $this->createForm($this->workflow(
      ['eingegangen', 'fachverfahren_weiterleitung', 'in_bearbeitung', 'genehmigt'],
      [['eingegangen', 'fachverfahren_weiterleitung'], ['fachverfahren_weiterleitung', 'in_bearbeitung']],
    ));
    $application = $this->applicationIn($form, 'fachverfahren_weiterleitung');
    $this->records = [];
    $this->assertFalse($this->changer()->caseSystemAccepted($application));
    $this->assertSame('fachverfahren_weiterleitung', $this->reload($application)->getWorkflowStatus());
    $this->assertNoWarningOrError();
    // The capture works: the outcome is recorded below warning level. The
    // form has no "in case system" status, so the acceptance would move a
    // case in its start status to "in progress"; this one is past that point.
    $this->assertNotEmpty(array_filter($this->records, static fn(array $record): bool => str_contains($record[1], 'not applicable, the current status carries none of the flags')));

    // Control: a form that switches "Im Fachverfahren" on moves there.
    $switched = $this->createForm($this->workflow(
      ['eingegangen', 'fachverfahren_weiterleitung', 'fachverfahren_uebergeben', 'abgeschlossen'],
      [['eingegangen', 'fachverfahren_weiterleitung'], ['fachverfahren_weiterleitung', 'fachverfahren_uebergeben']],
    ));
    $application = $this->applicationIn($switched, 'fachverfahren_weiterleitung');
    $this->assertTrue($this->changer()->caseSystemAccepted($application));
    $this->assertSame('fachverfahren_uebergeben', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * A notice closes on the form's own closing status, not a library outcome.
   */
  public function testClosingChangeStaysInsideTheFormWorkflow(): void {
    $this->createStatus('erledigt', ['closed'], TRUE);
    $form = $this->createForm($this->workflow(
      ['eingegangen', 'in_bearbeitung', 'erledigt'],
      [['eingegangen', 'in_bearbeitung'], ['in_bearbeitung', 'erledigt']],
    ));
    foreach ([
      ['approved', 'fit_connect', FALSE],
      ['rejected', 'docgen', TRUE],
      ['filed', 'fit_connect', FALSE],
      [NULL, 'fit_connect', FALSE],
    ] as [$outcome, $source, $person]) {
      $application = $this->applicationIn($form, 'in_bearbeitung');
      $this->assertTrue($this->changer()->caseSystemResponded($application, $outcome, $person, [], $source), (string) $outcome);
      $this->assertSame('erledigt', $this->reload($application)->getWorkflowStatus(), (string) $outcome);
      // A standard form closes every response on "Abgeschlossen".
      $application = $this->applicationIn($this->createForm(NULL), 'in_bearbeitung');
      $this->assertTrue($this->changer()->caseSystemResponded($application, $outcome, $person, [], $source), (string) $outcome);
      $this->assertSame('abgeschlossen', $this->reload($application)->getWorkflowStatus(), (string) $outcome);
    }
    // The accepted transfer under on_receipt closes there as well.
    $application = $this->applicationIn($form, 'in_bearbeitung');
    $this->assertTrue($this->changer()->caseSystemAccepted($application, 'on_receipt'));
    $this->assertSame('erledigt', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * A form workflow without any closing status still closes on a notice.
   */
  public function testClosingChangeFallsBackToTheCatalogue(): void {
    $form = $this->createForm($this->workflow(['eingegangen', 'in_bearbeitung'], [['eingegangen', 'in_bearbeitung']]));
    $application = $this->applicationIn($form, 'in_bearbeitung');
    $this->records = [];
    $this->assertTrue($this->changer()->caseSystemResponded($application, 'approved'));
    $this->assertSame('genehmigt', $this->reload($application)->getWorkflowStatus());
    $this->assertNoWarningOrError();
  }

  /**
   * The published workflow governs: published, draft after a publish, archived.
   *
   * Both the application variant and the form variant of the rule check.
   */
  public function testPublishedRevisionGoverns(): void {
    $published = ['eingegangen', 'zurueckgezogen'];
    $form = $this->createForm($this->workflow($published, [['eingegangen', 'zurueckgezogen']]));
    $application = $this->applicationIn($form, 'eingegangen');
    $expected = array_map(fn(string $machine_name): int => (int) $this->catalogueStatus($machine_name)->id(), $published);
    $storage = $this->entityTypeManager()->getStorage('form_schema');
    $assert = function (string $state) use ($application, $expected, $storage, $form): void {
      $current = $storage->loadUnchanged($form->id());
      assert($current instanceof FormSchemaInterface);
      $this->assertEqualsCanonicalizing($expected, $this->resolver()->governingStatusIds($application), $state);
      $this->assertEqualsCanonicalizing($expected, $this->resolver()->governingStatusIdsForForm($current, (int) $this->organisation->id()), $state);
    };

    // Published.
    $form->set('field_workflow_status', 'published');
    $form->save();
    $assert('published');

    // Draft after a publish with another workflow: the published one wins.
    $draft = $storage->loadUnchanged($form->id());
    $draft->set('field_workflow_status', 'draft');
    $draft->save();
    $draft = $storage->loadUnchanged($form->id());
    $draft->set('field_workflow_definition', json_encode($this->workflow(
      ['eingegangen', 'genehmigt'],
      [['eingegangen', 'genehmigt']],
    ), JSON_THROW_ON_ERROR));
    $draft->save();
    $assert('draft after publish');

    // Archived: the last published revision still governs.
    $archived = $storage->loadUnchanged($form->id());
    $archived->set('field_workflow_status', 'archived');
    $archived->save();
    $assert('archived');
  }

  /**
   * Asserts that the workflow channel logged no warning or error.
   */
  private function assertNoWarningOrError(): void {
    foreach ($this->records as [$level, $message]) {
      $this->assertGreaterThan(4, (int) $level, $message);
    }
  }

  /**
   * The resolver from the container.
   */
  private function resolver(): EffectiveWorkflowResolver {
    return $this->container->get('beantragt_application.effective_workflow_resolver');
  }

  /**
   * The changer from the container.
   */
  private function changer(): SemanticStatusChanger {
    return $this->container->get('beantragt_workflow.semantic_status_changer');
  }

  /**
   * Builds a catalog workflow definition.
   *
   * @param string[] $statuses
   *   Machine names of the statuses.
   * @param list<array{0: string, 1: string}> $transitions
   *   From and to machine names.
   *
   * @return array<string, mixed>
   *   The workflow definition.
   */
  private function workflow(array $statuses, array $transitions): array {
    return [
      'mode' => 'catalog',
      'defaultStatus' => $statuses[0],
      'statuses' => array_map(static fn(string $status): array => ['machineName' => $status], $statuses),
      'transitions' => array_map(static fn(array $transition): array => [
        'from' => $transition[0],
        'to' => $transition[1],
      ], $transitions),
    ];
  }

  /**
   * Ids of every status of the organisation catalogue.
   *
   * @return int[]
   *   Status ids.
   */
  private function catalogueIds(): array {
    $ids = $this->entityTypeManager()->getStorage('workflow_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $this->organisation->id())
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Loads one status of the catalogue by machine name.
   */
  private function catalogueStatus(string $machine_name): WorkflowStatusInterface {
    $statuses = $this->entityTypeManager()->getStorage('workflow_status')->loadByProperties([
      'field_group' => $this->organisation->id(),
      'field_machine_name' => $machine_name,
    ]);
    $status = reset($statuses);
    $this->assertInstanceOf(WorkflowStatusInterface::class, $status, $machine_name);
    return $status;
  }

  /**
   * Adds a status without incoming edges to the catalogue.
   *
   * @param string $machine_name
   *   The machine name.
   * @param string[] $flags
   *   Semantic flags.
   * @param bool $terminal
   *   Whether the status is terminal.
   */
  private function createStatus(string $machine_name, array $flags, bool $terminal): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => $machine_name,
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_machine_name' => $machine_name,
      'field_weight' => 130,
      'field_is_default' => FALSE,
      'field_is_terminal' => $terminal,
      'field_semantic_flags' => array_map(static fn(string $flag): array => ['value' => $flag], $flags),
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Adds an edge of the organisation graph.
   */
  private function addEdge(string $from, string $to): void {
    $source = $this->catalogueStatus($from);
    $source->set('field_allowed_next', array_map(
      static fn(int $id): array => ['target_id' => $id],
      [...$source->getAllowedNextIds(), (int) $this->catalogueStatus($to)->id()],
    ));
    $source->save();
  }

  /**
   * Creates a never published form of the organisation.
   *
   * @param array<string, mixed>|null $workflow
   *   The form's own workflow, NULL for none.
   */
  private function createForm(?array $workflow): FormSchemaInterface {
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Lookup form',
      'field_schema' => json_encode([
        'title' => 'Lookup form',
        'sections' => [[
          'id' => 'main',
          'title' => 'Main',
          'fields' => [[
            'id' => 'note',
            'type' => 'text',
            'label' => 'Note',
            'privacy' => ['kind' => 'other_personal'],
          ],
          ],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_definition' => $workflow === NULL ? NULL : json_encode($workflow, JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'status' => TRUE,
    ]);
    $form->save();
    assert($form instanceof FormSchemaInterface);
    return $form;
  }

  /**
   * Creates an application of the form and resets it to a status.
   */
  private function applicationIn(FormSchemaInterface $form, string $machine_name): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $form->id()],
      'field_data' => json_encode(['note' => 'synthetic'], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $this->catalogueStatus('eingegangen')->id()],
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    if ($machine_name !== 'eingegangen') {
      $application->setWorkflowStatusId((int) $this->catalogueStatus($machine_name)->id());
      $application->allowWorkflowStatusReset();
      $application->save();
    }
    return $this->reload($application);
  }

  /**
   * Reloads the stored application.
   */
  private function reload(ApplicationInterface $application): ApplicationInterface {
    $stored = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    assert($stored instanceof ApplicationInterface);
    return $stored;
  }

}
