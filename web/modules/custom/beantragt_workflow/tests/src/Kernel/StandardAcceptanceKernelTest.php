<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The case system's acceptance on a form without the hand-over function.
 *
 * A transfer on submission or by staff
 * needs nothing of the form; when the case system accepts it, an
 * application in its start status moves to the standard's in-progress
 * status, found by its flag. Neutral catalogue: a (received, default), b
 * (documents_received), c (closed, terminal) wired a>b, a>c, b>c; library
 * r (request_info), h (hand_over), i (in_case_system), p (approved, closed,
 * terminal). Every status has a citizen text "Text <name>". The mailer is a
 * recording double: the real subscriber decides which mail goes out.
 */
#[Group('beantragt_workflow')]
final class StandardAcceptanceKernelTest extends GroupKernelTestBase {

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
    'language',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
    'beantragt_notification',
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
   * Statuses of the neutral catalogue by machine name.
   *
   * @var array<string, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   */
  private array $statuses = [];

  /**
   * Citizen mails the subscriber sent: template key and tokens.
   *
   * @var list<array{0: string, 1: array<string, string>}>
   */
  private array $mails = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . str_repeat('k', 32));
    parent::setUp();
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->method('citizenNotificationLangcode')->willReturn('de');
    $mailer->method('sendCitizenNotification')->willReturnCallback(function (ApplicationInterface $application, string $key, array $tokens = []): bool {
      $this->mails[] = [$key, $tokens];
      return TRUE;
    });
    $this->container->set('beantragt_notification.mailer', $mailer);
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('file', ['file_usage']);
    $this->setCurrentUser($this->createUser([
      'bypass beantragt group scope',
      'manage workflow transitions',
      'administer application entities',
    ]));
    $this->jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Neutral jurisdiction',
      'field_slug' => 'neutral-jur',
    ]);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Neutral organisation',
      'field_slug' => 'neutral-org',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $this->neutralCatalogue();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? self::KEY_ENV : self::KEY_ENV . '=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * Start status to in-progress with one general status mail (a, g).
   *
   * The target is found by its flag; the revision names the case system;
   * the mail is status_change with the two citizen texts, nothing else.
   */
  public function testAcceptanceMovesTheStartStatusToInProgress(): void {
    $case = $this->applicationIn($this->createForm(NULL), 'a');
    $this->mails = [];
    $this->assertTrue($this->changer()->caseSystemAccepted($case));
    $stored = $this->reload($case);
    $this->assertSame('b', $stored->getWorkflowStatus());
    $this->assertSame(['status_changed', 'case_system_accepted'], RevisionLog::decode((string) $stored->getRevisionLogMessage())['events'] ?? NULL);
    $this->assertSame(0, (int) $stored->getRevisionUserId());
    $this->assertSame([['status_change', ['@old_status' => 'Text a', '@new_status' => 'Text b']]], $this->mails);

    // Without a confirmed address no mail, the status still moves.
    $unconfirmed = $this->applicationIn($this->createForm(NULL), 'a', FALSE);
    $this->mails = [];
    $this->assertTrue($this->changer()->caseSystemAccepted($unconfirmed));
    $this->assertSame('b', $this->reload($unconfirmed)->getWorkflowStatus());
    $this->assertSame([], $this->mails);

    // Same citizen text on both statuses: no mail, the status moves.
    $this->statuses['b']->set('field_citizen_label_de', 'Text a')->save();
    $same = $this->applicationIn($this->createForm(NULL), 'a');
    $this->mails = [];
    $this->assertTrue($this->changer()->caseSystemAccepted($same));
    $this->assertSame('b', $this->reload($same)->getWorkflowStatus());
    $this->assertSame([], $this->mails);
  }

  /**
   * Without an in-progress status or its edge the case stays (a).
   */
  public function testNoInProgressStatusOrNoEdgeLeavesTheCase(): void {
    // The form's workflow has no in-progress status.
    $without = $this->createForm($this->workflow(['a', 'c'], [['a', 'c']]));
    $case = $this->applicationIn($without, 'a');
    $this->mails = [];
    $this->assertFalse($this->changer()->caseSystemAccepted($case));
    $this->assertSame('a', $this->reload($case)->getWorkflowStatus());
    $this->assertSame([], $this->mails);

    // In the workflow, but no edge from the start status to it.
    $no_edge = $this->createForm($this->workflow(['a', 'b', 'c', 'r'], [['a', 'c'], ['a', 'r'], ['r', 'b'], ['b', 'c']]));
    $case = $this->applicationIn($no_edge, 'a');
    $this->mails = [];
    $this->assertFalse($this->changer()->caseSystemAccepted($case));
    $this->assertSame('a', $this->reload($case)->getWorkflowStatus());
    $this->assertSame([], $this->mails);
  }

  /**
   * A case already in progress or further neither moves nor mails (b).
   */
  public function testCaseInProgressOrFurtherStays(): void {
    $standard = $this->createForm(NULL);
    foreach (['b', 'c'] as $name) {
      $case = $this->applicationIn($standard, $name);
      $this->mails = [];
      $this->assertFalse($this->changer()->caseSystemAccepted($case), $name);
      $this->assertSame($name, $this->reload($case)->getWorkflowStatus());
      $this->assertSame([], $this->mails, $name);
    }
    // A request for documents is past the start status as well.
    $request = $this->createForm($this->workflow(['a', 'b', 'c', 'r'], [['a', 'b'], ['a', 'r'], ['r', 'b'], ['b', 'c']]));
    $case = $this->applicationIn($request, 'r');
    $this->mails = [];
    $this->assertFalse($this->changer()->caseSystemAccepted($case));
    $this->assertSame('r', $this->reload($case)->getWorkflowStatus());
    $this->assertSame([], $this->mails);
  }

  /**
   * A connection that closes on receipt closes as before (c).
   */
  public function testClosingOnReceiptIsUnchanged(): void {
    $case = $this->applicationIn($this->createForm(NULL), 'a');
    $this->mails = [];
    $this->assertTrue($this->changer()->caseSystemAccepted($case, 'on_receipt', ['receipt' => 'closed']));
    $stored = $this->reload($case);
    $this->assertSame('c', $stored->getWorkflowStatus());
    $this->assertSame(['status_changed', 'closed_on_receipt'], RevisionLog::decode((string) $stored->getRevisionLogMessage())['events'] ?? NULL);
    $this->assertSame([['status_change', ['@old_status' => 'Text a', '@new_status' => 'Text c']]], $this->mails);
  }

  /**
   * The outcome says why a start status stayed; nothing else changes (S9b).
   *
   * Each of the three reasons for a case in its start status, no mail, no
   * status change; a case past the start status and a moved case carry no
   * reason.
   */
  public function testOutcomeNamesWhyTheStartStatusStayed(): void {
    $workflow_with_attribute = $this->workflow(['a', 'b', 'c'], [['a', 'c'], ['b', 'c']]);
    $workflow_with_attribute['transitions'][] = [
      'from' => 'a',
      'to' => 'b',
      'attributes' => [['code' => 'file_number', 'label' => 'File number', 'type' => 'string', 'required' => TRUE]],
    ];
    $cases = [
      'no_target_status' => $this->createForm($this->workflow(['a', 'c'], [['a', 'c']])),
      'no_transition' => $this->createForm($this->workflow(
        ['a', 'b', 'c', 'r'],
        [['a', 'c'], ['a', 'r'], ['r', 'b'], ['b', 'c']],
      )),
      'transition_attributes_required' => $this->createForm($workflow_with_attribute),
    ];
    foreach ($cases as $reason => $form) {
      $case = $this->applicationIn($form, 'a');
      $this->mails = [];
      $this->assertSame(['changed' => FALSE, 'unchanged' => $reason], $this->changer()->caseSystemAcceptedOutcome($case), $reason);
      $this->assertSame('a', $this->reload($case)->getWorkflowStatus(), $reason);
      $this->assertSame([], $this->mails, $reason);
    }

    // Past the start status: the expected outcome, no reason.
    $past = $this->applicationIn($this->createForm(NULL), 'b');
    $this->assertSame(['changed' => FALSE, 'unchanged' => NULL], $this->changer()->caseSystemAcceptedOutcome($past));
    // Moved: no reason. Closure on receipt: no reason.
    $moved = $this->applicationIn($this->createForm(NULL), 'a');
    $this->assertSame(['changed' => TRUE, 'unchanged' => NULL], $this->changer()->caseSystemAcceptedOutcome($moved));
    $closing = $this->applicationIn($this->createForm($this->workflow(['a', 'c'], [['a', 'c']])), 'a');
    $this->assertSame(['changed' => TRUE, 'unchanged' => NULL], $this->changer()->caseSystemAcceptedOutcome($closing, 'on_receipt', ['receipt' => 'closed']));
  }

  /**
   * A form with the function still moves to "in case system", not further.
   */
  public function testFormWithTheFunctionMovesToInCaseSystem(): void {
    $with = $this->createForm($this->workflow(
      ['a', 'b', 'c', 'h', 'i', 'p'],
      [['a', 'b'], ['a', 'c'], ['b', 'c'], ['a', 'h'], ['h', 'i'], ['a', 'i'], ['b', 'i'], ['i', 'p'], ['i', 'c']],
    ));
    $case = $this->applicationIn($with, 'a');
    $this->mails = [];
    $this->assertTrue($this->changer()->caseSystemAccepted($case));
    $this->assertSame('i', $this->reload($case)->getWorkflowStatus());
    $this->assertSame([['status_change', ['@old_status' => 'Text a', '@new_status' => 'Text i']]], $this->mails);
  }

  /**
   * Builds the neutral catalogue; the seeded default becomes "a".
   */
  private function neutralCatalogue(): void {
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $seeded = $storage->loadByProperties(['field_group' => $this->organisation->id()]);
    $default = NULL;
    foreach ($seeded as $id => $status) {
      assert($status instanceof WorkflowStatusInterface);
      $status->set('field_allowed_next', [])->save();
      if ($status->isDefault()) {
        $default = $status;
        unset($seeded[$id]);
      }
    }
    $storage->delete($seeded);
    assert($default instanceof WorkflowStatusInterface);
    $default->set('field_machine_name', 'a')
      ->set('label', 'a')
      ->set('field_citizen_label_de', 'Text a')
      ->set('field_semantic_flags', [['value' => SemanticStatusChanger::FLAG_RECEIVED]])
      ->save();
    $this->statuses['a'] = $default;
    $definitions = [
      'b' => [[SemanticStatusChanger::FLAG_IN_PROGRESS], FALSE, 10],
      'c' => [['closed'], TRUE, 90],
      'r' => [['request_info'], FALSE, 20],
      'h' => [['hand_over'], FALSE, 25],
      'i' => [['in_case_system'], FALSE, 30],
      'p' => [['approved', 'closed'], TRUE, 95],
    ];
    foreach ($definitions as $machine_name => [$flags, $terminal, $weight]) {
      $status = $storage->create([
        'label' => $machine_name,
        'field_group' => ['target_id' => $this->organisation->id()],
        'field_machine_name' => $machine_name,
        'field_citizen_label_de' => 'Text ' . $machine_name,
        'field_weight' => $weight,
        'field_is_default' => FALSE,
        'field_is_terminal' => $terminal,
        'field_semantic_flags' => array_map(static fn(string $flag): array => ['value' => $flag], $flags),
      ]);
      $status->save();
      assert($status instanceof WorkflowStatusInterface);
      $this->statuses[$machine_name] = $status;
    }
    foreach (['a' => ['b', 'c'], 'b' => ['c']] as $from => $targets) {
      $this->statuses[$from]->set('field_allowed_next', array_map(
        fn(string $target): array => ['target_id' => $this->statuses[$target]->id()],
        $targets,
      ))->save();
    }
  }

  /**
   * Builds a catalog workflow definition.
   *
   * @param string[] $statuses
   *   Machine names.
   * @param list<array{0: string, 1: string}> $transitions
   *   From and to.
   *
   * @return array<string, mixed>
   *   The definition.
   */
  private function workflow(array $statuses, array $transitions): array {
    return [
      'mode' => 'catalog',
      'defaultStatus' => 'a',
      'statuses' => array_map(static fn(string $status): array => ['machineName' => $status], $statuses),
      'transitions' => array_map(static fn(array $transition): array => [
        'from' => $transition[0],
        'to' => $transition[1],
      ], $transitions),
    ];
  }

  /**
   * Creates a published form of the organisation.
   *
   * @param array<string, mixed>|null $workflow
   *   The own workflow, NULL for the standard.
   */
  private function createForm(?array $workflow): FormSchemaInterface {
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Neutral form',
      'field_schema' => json_encode([
        'title' => 'Neutral form',
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
      'field_workflow_status' => 'published',
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
   * Creates an application of the form in a status.
   */
  private function applicationIn(FormSchemaInterface $form, string $machine_name, bool $confirmed = TRUE): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $form->id()],
      'field_data' => json_encode(['note' => 'synthetic'], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $this->statuses['a']->id()],
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_email_confirmed' => $confirmed,
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    if ($machine_name !== 'a') {
      $application->setWorkflowStatusId((int) $this->statuses[$machine_name]->id());
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

  /**
   * The changer from the container.
   */
  private function changer(): SemanticStatusChanger {
    return $this->container->get('beantragt_workflow.semantic_status_changer');
  }

}
