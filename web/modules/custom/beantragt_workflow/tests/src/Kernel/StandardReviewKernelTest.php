<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow\Kernel;

use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A form back on the standard treats a status outside it like an override.
 *
 * Neutral catalogue: a (start), b (documents_received), c (closed, terminal)
 * wired a>b, a>c, b>c; library r (request_info, next b, c, n), i
 * (in_case_system, next p, n, c), p (approved), n (rejected). A form that
 * switched r or i on and is stored empty again leaves its cases in a status
 * the standard does not reach: review state, no transitions, corrections
 * only to the standard, never an outcome.
 */
#[Group('beantragt_workflow')]
final class StandardReviewKernelTest extends GroupKernelTestBase {

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
   * Statuses of the neutral catalogue by machine name.
   *
   * @var array<string, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   */
  private array $statuses = [];

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
   * A case in a switched-off library status: review state, no outcome.
   */
  public function testLibraryStatusAfterBackToStandardNeedsReview(): void {
    foreach (['r', 'i'] as $library) {
      $form = $this->createForm($this->workflow(
        ['a', 'b', 'c', $library],
        [['a', 'b'], ['a', 'c'], ['b', 'c'], ['a', $library], [$library, 'c']],
      ));
      $application = $this->applicationIn($form, $library);
      $this->assertNull($this->resolver()->reviewCode($application), $library);

      // The function is switched off: the form is stored empty and published.
      $this->publish($form, NULL);
      $application = $this->reload($application);
      $this->assertFalse($this->resolver()->hasOverride($application));
      $this->assertSame(EffectiveWorkflowResolver::REVIEW_STATUS_NOT_IN_WORKFLOW, $this->resolver()->reviewCode($application), $library);
      $current = $this->statuses[$library];
      $this->assertSame([], $this->resolver()->allowedTargetIds($application, $current), $library);

      // Enforcement refuses every library edge, the approval in particular.
      $manager = $this->container->get('beantragt_workflow.workflow_manager');
      foreach (['p', 'n', 'b', 'c'] as $target) {
        $this->assertFalse($manager->isApplicationTransitionAllowed($application, $current, $this->statuses[$target]), "$library>$target");
      }
      $application->setWorkflowStatusId((int) $this->statuses['p']->id());
      try {
        $application->save();
        $this->fail("A save $library>p was accepted.");
      }
      catch (\Throwable $exception) {
        $this->assertStringContainsString('is not allowed', $exception->getMessage());
      }
      $application = $this->reload($application);

      // Corrections: the standard's statuses, no outcome, not the current.
      $targets = $this->names($this->resolver()->correctionTargetIds($application));
      $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $targets, $library);
      $this->assertNotContains('p', $targets);
      $this->assertNotContains('n', $targets);

      // The staff route offers no approval, neither as a step nor as a fix.
      $answer = json_decode((string) ApplicationDataController::create($this->container)->workflow($application)->getContent(), TRUE);
      $this->assertSame([], $answer['allowed_next'], $library);
      $this->assertSame(EffectiveWorkflowResolver::REVIEW_STATUS_NOT_IN_WORKFLOW, $answer['review']['code']);
      $offered = $this->names(array_column($answer['review']['targets'], 'id'));
      $this->assertNotContains('p', $offered);
      $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $offered);
    }
  }

  /**
   * Inside the standard nothing changes; a terminal status stays exempt.
   */
  public function testStandardStatusesAndTerminalStatusesAreUnchanged(): void {
    $form = $this->createForm(NULL);
    $application = $this->applicationIn($form, 'b');
    $this->assertNull($this->resolver()->reviewCode($application));
    $this->assertNull($this->resolver()->allowedTargetIds($application, $this->statuses['b']));
    // The correction route needs the review state first; its targets would be
    // the standard without the current status.
    $this->assertEqualsCanonicalizing(['a', 'c'], $this->names($this->resolver()->correctionTargetIds($application)));

    // A case closed outside the graph on an outcome needs no review.
    $closed = $this->applicationIn($form, 'p');
    $this->assertNull($this->resolver()->reviewCode($closed));
  }

  /**
   * A status a form's stored workflow lists cannot be deleted.
   *
   * Forms of the organisation and forms without organisation of its
   * jurisdiction count; a form of another organisation does not.
   */
  public function testListedLibraryStatusCannotBeDeleted(): void {
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $spare = $storage->create([
      'label' => 'x',
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_machine_name' => 'x',
      'field_weight' => 50,
    ]);
    $spare->save();
    assert($spare instanceof WorkflowStatusInterface);
    $this->assertNotContains('form_workflow_reference', $manager->deleteBlockers($spare));

    // Listed by a form of the organisation: protected, also from deletion.
    $form = $this->createForm($this->workflow(['a', 'b', 'c', 'x'], [['a', 'b'], ['a', 'c'], ['b', 'c'], ['a', 'x']]));
    $this->assertContains('form_workflow_reference', $manager->deleteBlockers($spare));
    try {
      $spare->delete();
      $this->fail('A status a form lists was deleted.');
    }
    catch (\Throwable $exception) {
      $this->assertStringContainsString('form_workflow_reference', $exception->getMessage());
    }

    // Back to the standard: free again.
    $this->publish($form, NULL);
    $this->assertNotContains('form_workflow_reference', $manager->deleteBlockers($spare));

    // A form of another organisation names its own catalogue's "x".
    $other = $this->createGroup([
      'type' => 'org',
      'label' => 'Other organisation',
      'field_slug' => 'other-org',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $foreign = $this->createForm(['mode' => 'override', 'statuses' => [['machineName' => 'x']]]);
    $foreign->set('field_organisation', ['target_id' => $other->id()])->set('field_group', ['target_id' => $other->id()])->save();
    $this->assertNotContains('form_workflow_reference', $manager->deleteBlockers($spare));

    // A form without organisation of the jurisdiction lands here as well.
    $foreign->set('field_organisation', [])->set('field_group', ['target_id' => $this->jurisdiction->id()])->save();
    $this->assertContains('form_workflow_reference', $manager->deleteBlockers($spare));
  }

  /**
   * An undo never leads into a status the form no longer has ("Rückgängig").
   *
   * The review's scenario: a form with the outcome function, a case b > p >
   * undo to b; the function is unticked and published; the next undo would
   * return to p (and send its mail): refused before anything is saved. The
   * same for a standard form whose r was switched off; an undo inside the
   * standard still works.
   */
  public function testUndoNeverLeadsOutsideTheGoverningWorkflow(): void {
    $outcome = $this->createForm($this->workflow(['a', 'b', 'c', 'p'], [['a', 'b'], ['a', 'c'], ['b', 'c'], ['b', 'p']]));
    $case = $this->applicationIn($outcome, 'b');
    $case = $this->moveTo($case, 'p');
    $first = $this->rollback($case);
    $this->assertSame(200, $first->getStatusCode(), (string) $first->getContent());
    $case = $this->reload($case);
    $this->assertSame((int) $this->statuses['b']->id(), (int) $case->get('field_workflow_status')->target_id);

    // The function is unticked and the form published again.
    $this->publish($outcome, $this->workflow(['a', 'b', 'c'], [['a', 'b'], ['a', 'c'], ['b', 'c']]));
    $this->assertRefusedUndo($case, 'p');

    // A standard form whose r was switched off.
    $with_request = $this->workflow(['a', 'b', 'c', 'r'], [['a', 'b'], ['a', 'c'], ['b', 'c'], ['a', 'r'], ['r', 'b']]);
    $standard = $this->createForm($with_request);
    $case = $this->applicationIn($standard, 'r');
    $case = $this->moveTo($case, 'b');
    $this->publish($standard, NULL);
    $this->assertRefusedUndo($case, 'r');

    // Control: an undo inside the standard.
    $plain = $this->createForm(NULL);
    $case = $this->moveTo($this->applicationIn($plain, 'a'), 'b');
    $target = json_decode((string) $this->controller()->statusRollbackTarget($case)->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertTrue($target['available']);
    $response = $this->rollback($case);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame((int) $this->statuses['a']->id(), (int) $this->reload($case)->get('field_workflow_status')->target_id);
  }

  /**
   * The undo into a status outside the workflow is refused, nothing saved.
   */
  private function assertRefusedUndo(ApplicationInterface $case, string $target): void {
    $case = $this->reload($case);
    $status = (int) $case->get('field_workflow_status')->target_id;
    $offer = json_decode((string) $this->controller()->statusRollbackTarget($case)->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertFalse($offer['available']);
    $this->assertSame('target_not_in_workflow', $offer['reason']);
    $this->assertSame((int) $this->statuses[$target]->id(), $offer['targetStatus']['internalId']);
    $revisions = $this->revisionCount($case);

    $response = $this->rollback($case);
    $this->assertSame(422, $response->getStatusCode());
    $cache = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    sort($cache);
    $this->assertSame(['max-age=0', 'no-store', 'private'], $cache);
    $message = 'The previous status is not part of the workflow that governs this form.';
    $this->assertSame([
      'message' => $message,
      'error' => ['code' => 'workflow.rollback_target_not_in_workflow', 'message' => $message],
    ], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR));
    // No save: no new revision, no status change, so no status mail.
    $this->assertSame($revisions, $this->revisionCount($case));
    $this->assertSame($status, (int) $this->reload($case)->get('field_workflow_status')->target_id);
  }

  /**
   * Moves a case to a status in a new revision, as a person's change does.
   */
  private function moveTo(ApplicationInterface $case, string $machine_name): ApplicationInterface {
    $case = $this->reload($case);
    $case->setNewRevision(TRUE);
    $case->setWorkflowStatusId((int) $this->statuses[$machine_name]->id());
    $case->allowWorkflowStatusReset();
    $case->save();
    return $this->reload($case);
  }

  /**
   * Sends "Rückgängig" for the case as loaded.
   */
  private function rollback(ApplicationInterface $case): Response {
    $case = $this->reload($case);
    return $this->controller()->rollbackStatus($case, new Request(content: json_encode([
      'expected_status_id' => (int) $case->get('field_workflow_status')->target_id,
      'expected_changed' => $case->getChangedTime(),
      'note' => '',
    ], JSON_THROW_ON_ERROR)));
  }

  /**
   * Counts the stored revisions of a case.
   */
  private function revisionCount(ApplicationInterface $case): int {
    return (int) $this->entityTypeManager()->getStorage('application')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $case->id())
      ->count()
      ->execute();
  }

  /**
   * The staff data controller from the container.
   */
  private function controller(): ApplicationDataController {
    return ApplicationDataController::create($this->container);
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
    $default->set('field_machine_name', 'a')->set('label', 'a')->set('field_semantic_flags', [['value' => 'received']])->save();
    $this->statuses['a'] = $default;
    $definitions = [
      'b' => [['documents_received'], FALSE, 10],
      'c' => [['closed'], TRUE, 90],
      'r' => [['request_info'], FALSE, 20],
      'i' => [['in_case_system'], FALSE, 30],
      'p' => [['approved', 'closed'], TRUE, 95],
      'n' => [['rejected', 'closed'], TRUE, 96],
    ];
    foreach ($definitions as $machine_name => [$flags, $terminal, $weight]) {
      $status = $storage->create([
        'label' => $machine_name,
        'field_group' => ['target_id' => $this->organisation->id()],
        'field_machine_name' => $machine_name,
        'field_weight' => $weight,
        'field_is_default' => FALSE,
        'field_is_terminal' => $terminal,
        'field_semantic_flags' => array_map(static fn(string $flag): array => ['value' => $flag], $flags),
      ]);
      $status->save();
      assert($status instanceof WorkflowStatusInterface);
      $this->statuses[$machine_name] = $status;
    }
    foreach ([
      'a' => ['b', 'c'],
      'b' => ['c'],
      'r' => ['b', 'c', 'n'],
      'i' => ['p', 'n', 'c'],
    ] as $from => $targets) {
      $this->statuses[$from]->set('field_allowed_next', array_map(
        fn(string $target): array => ['target_id' => $this->statuses[$target]->id()],
        $targets,
      ))->save();
    }
  }

  /**
   * Machine names of status ids.
   *
   * @param int[] $ids
   *   Status ids.
   *
   * @return string[]
   *   Machine names.
   */
  private function names(array $ids): array {
    $names = [];
    foreach ($this->entityTypeManager()->getStorage('workflow_status')->loadMultiple($ids) as $status) {
      assert($status instanceof WorkflowStatusInterface);
      $names[] = $status->getMachineName();
    }
    return $names;
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
   * Creates and publishes a form of the organisation.
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
   * Publishes a new workflow (draft, change, publish), as the editor does.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed>|null $workflow
   *   The new workflow; NULL stores it empty (the standard).
   */
  private function publish(FormSchemaInterface $form, ?array $workflow): void {
    $storage = $this->entityTypeManager()->getStorage('form_schema');
    $draft = $storage->loadUnchanged($form->id());
    $draft->set('field_workflow_status', 'draft');
    $draft->save();
    $draft = $storage->loadUnchanged($form->id());
    $draft->set('field_workflow_definition', $workflow === NULL ? NULL : json_encode($workflow, JSON_THROW_ON_ERROR));
    $draft->save();
    $published = $storage->loadUnchanged($form->id());
    $published->set('field_workflow_status', 'published');
    $published->save();
  }

  /**
   * Creates an application of the form and resets it to a status.
   */
  private function applicationIn(FormSchemaInterface $form, string $machine_name): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $form->id()],
      'field_data' => json_encode(['note' => 'synthetic'], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $this->statuses['a']->id()],
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
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
   * The resolver from the container.
   */
  private function resolver(): EffectiveWorkflowResolver {
    return $this->container->get('beantragt_application.effective_workflow_resolver');
  }

}
