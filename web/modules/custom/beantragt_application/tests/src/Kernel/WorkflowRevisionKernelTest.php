<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Controller\StaffApplicationApiController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow\Service\WorkflowManager;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Workflow transitions follow the form's current published revision.
 *
 * Runs against the seeded status catalogue of a fictional organisation, real
 * form revisions and real application revisions. Every form edit goes
 * through the lifecycle (published, back to draft, edit, publish).
 */
#[Group('beantragt_application')]
final class WorkflowRevisionKernelTest extends GroupKernelTestBase {

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
    'beantragt_form_schema_group',
    'beantragt_roles_api',
  ];

  /**
   * Original application data key environment value.
   */
  private string|false $originalKey;

  /**
   * The fictional jurisdiction.
   */
  private GroupInterface $jurisdiction;

  /**
   * The organisation owning the catalogue.
   */
  private GroupInterface $organisation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . str_repeat('w', 32));
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('form_schema_group');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->entityTypeManager());
    $this->setCurrentUser($this->createUser([
      'bypass beantragt group scope',
      'manage workflow transitions',
      'edit any application entities',
      'view published application entities',
    ]));
    $this->jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Ablaufstadt',
      'field_slug' => 'ablaufstadt',
    ]);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Ablaufamt',
      'field_slug' => 'ablaufamt',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $this->container->get('beantragt_workflow_status.status_seeder')->seedForGroup($this->organisation);
    // Two open statuses outside the default catalogue, without transitions
    // of the organisation: only form workflows reach them.
    $this->createStatus('vollstaendigkeit_geprueft', [], FALSE);
    $this->createStatus('amtsleitung_offen', [], FALSE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? self::KEY_ENV : self::KEY_ENV . '=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * A transition that exists only in the newly published revision applies.
   */
  public function testTransitionAddedInPublishedRevisionAppliesToRunningApplication(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $this->assertSame('eingegangen', $application->getWorkflowStatus());

    $this->republish($form, $this->withAmtsleitung($this->baseFlow()));

    // Amtsleitung is no organisation edge of "eingegangen": only the form
    // revision allows it.
    $this->assertNotContains((int) $this->catalogueStatus('amtsleitung_offen')->id(), $this->catalogueStatus('eingegangen')->getAllowedNextIds());
    $this->move($application, 'amtsleitung_offen');
    $this->assertSame('amtsleitung_offen', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * Profile sync resolves consented values against the submitted revision.
   */
  public function testProfileConsentUsesTheSubmittedRevision(): void {
    $flow = $this->baseFlow();
    $name = ['id' => 'name', 'type' => 'text', 'label' => 'Eigener Name', 'privacy' => ['kind' => 'identity']];
    $other = [
      'id' => 'other',
      'type' => 'text',
      'label' => 'Name einer weiteren Person',
      'privacy' => ['kind' => 'identity'],
    ];
    $draft = $this->createForm($flow, 'draft');
    $form = $this->publish($this->editDraft($draft, $flow, [
      $name + ['crm_key' => 'person.full_name'],
      $other + ['crm_key' => 'dependent.full_name'],
    ]));
    $values = [
      'field_data' => json_encode(['name' => 'Ada Beispiel', 'other' => 'Emil Beispiel'], JSON_THROW_ON_ERROR),
      'field_profile_consent' => TRUE,
    ];
    $application = $this->createApplication($form, [], $values + [
      'field_profile_consent_record' => json_encode([
        'formUuid' => $form->uuid(),
        'formRevision' => (int) $form->getRevisionId(),
        'fields' => ['person.full_name'],
      ], JSON_THROW_ON_ERROR),
    ]);
    $legacy_proof = [
      'textVersion' => '2026-10-20.3',
      'textHash' => str_repeat('b', 64),
    ];
    $legacy = $this->createApplication($form, [], $values + [
      'field_profile_consent_record' => json_encode($legacy_proof, JSON_THROW_ON_ERROR),
    ]);
    $updated = $this->republish($form, $flow, [
      $name + ['crm_key' => 'dependent.full_name'],
      $other + ['crm_key' => 'person.full_name'],
    ]);
    $this->assertNotSame($form->getRevisionId(), $updated->getRevisionId());
    $this->assertSame(['person.full_name' => 'Ada Beispiel'], $this->reload($application)->getApplicantCrmData());
    $legacy = $this->reload($legacy);
    $this->assertSame(['person.full_name' => 'Ada Beispiel'], $legacy->getApplicantCrmData());
    $this->assertSame($legacy_proof, $legacy->getProfileConsentRecord());
  }

  /**
   * An application save preserves the historical wording and proof hashes.
   */
  public function testApplicationSavePreservesPreviousConsentVersionAndHash(): void {
    $form = $this->createForm($this->baseFlow());
    $records = [];
    $hashes = [
      'contact' => '8143047339c18ae130349c8e350174440eaae8a30744856f8b2f83af54025179',
      'bank' => '540c2aeea71f78a00e0ae453a80bbe5feda24d16e4eec68e50e4599a79e297a9',
    ];
    foreach ($hashes as $kind => $hash) {
      $records[$kind] = [
        'textVersion' => '2026-11-20',
        'textHash' => $hash,
        'locale' => 'de',
        'text' => ProfileConsentText::render($kind, 'de', 'Bürgerbüro', '2026-11-20'),
      ];
    }
    $application = $this->createApplication($form, [], [
      'field_profile_consent' => TRUE,
      'field_profile_consent_record' => json_encode($records['contact'], JSON_THROW_ON_ERROR),
      'field_bank_consent' => TRUE,
      'field_bank_consent_record' => json_encode($records['bank'], JSON_THROW_ON_ERROR),
    ]);

    $application->setNewRevision(TRUE);
    $application->save();
    $stored = $this->reload($application);
    $this->assertSame($records['contact'], $stored->getProfileConsentRecord());
    $this->assertSame($records['bank'], $stored->getBankConsentRecord());
  }

  /**
   * A transition removed by the published revision is refused.
   */
  public function testTransitionRemovedInPublishedRevisionIsRefused(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);

    $flow = $this->baseFlow();
    $flow['transitions'] = array_values(array_filter(
      $flow['transitions'],
      static fn(array $transition): bool => $transition['from'] !== 'eingegangen',
    ));
    $this->republish($form, $flow);

    $this->assertRefused(fn() => $this->move($application, 'vollstaendigkeit_geprueft'));
    $this->assertSame('eingegangen', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * Transition attributes come from the published revision.
   */
  public function testAttributesComeFromPublishedRevision(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);

    $flow = $this->baseFlow();
    $flow['transitions'][0]['attributes'] = [[
      'code' => 'pruefnotiz',
      'label' => 'Prüfnotiz',
      'datatype' => 'string',
      'required' => TRUE,
    ],
    ];
    $this->republish($form, $flow);

    $this->assertRefused(fn() => $this->move($application, 'vollstaendigkeit_geprueft'));
    $this->move($application, 'vollstaendigkeit_geprueft', ['pruefnotiz' => 'Fiktive Notiz']);
    $this->assertSame('vollstaendigkeit_geprueft', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * A draft edit does not reach running applications until it is published.
   */
  public function testDraftEditDoesNotReachRunningApplications(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);

    $form = $this->toDraft($form, $this->withAmtsleitung($this->baseFlow()));
    $this->assertSame('draft', $form->getSchemaStatus());
    $this->assertRefused(fn() => $this->move($application, 'amtsleitung_offen'));

    $this->publish($form);
    $this->move($application, 'amtsleitung_offen');
    $this->assertSame('amtsleitung_offen', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * A withdrawn form keeps its last published workflow.
   */
  public function testArchivedFormKeepsLastPublishedWorkflow(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $form = $this->republish($form, $this->withAmtsleitung($this->baseFlow()));

    $form->set('field_workflow_status', 'archived');
    $form->save();
    $this->assertSame('archived', $this->reloadForm($form)->getSchemaStatus());

    $this->move($application, 'amtsleitung_offen');
    $this->assertSame('amtsleitung_offen', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * A never-published form keeps the intake revision (today's behaviour).
   */
  public function testNeverPublishedFormUsesIntakeRevision(): void {
    $form = $this->createForm($this->baseFlow(), 'draft');
    $intake_revision_id = (int) $form->getRevisionId();
    $application = $this->createApplication($form);

    $this->editDraft($form, $this->withAmtsleitung($this->baseFlow()));

    $resolver = $this->resolver();
    $this->assertSame($intake_revision_id, $resolver->workflowRevisionId($application));
    $this->assertSame(EffectiveWorkflowResolver::SOURCE_INTAKE, $resolver->workflowSource($application)['source']);
    $this->assertRefused(fn() => $this->move($application, 'amtsleitung_offen'));
    $this->move($application, 'vollstaendigkeit_geprueft');
    $this->assertSame('vollstaendigkeit_geprueft', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * A status removed by the published revision yields the review code.
   */
  public function testRemovedStatusYieldsReviewCode(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $this->move($application, 'vollstaendigkeit_geprueft');

    $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));
    $application = $this->reload($application);

    $resolver = $this->resolver();
    $this->assertSame(EffectiveWorkflowResolver::REVIEW_STATUS_NOT_IN_WORKFLOW, $resolver->reviewCode($application));
    $this->assertSame([], $resolver->allowedTargetIds($application, $this->catalogueStatus('vollstaendigkeit_geprueft')));

    $this->setCurrentUser($this->staff());
    $status = $this->staffDetail($application)['data']['status'];
    $this->assertSame('status_not_in_workflow', $status['reviewCode'] ?? NULL);
    $this->assertSame([], $status['allowedNextIds']);
  }

  /**
   * A terminal status outside the workflow needs no review.
   */
  public function testTerminalStatusOutsideWorkflowNeedsNoReview(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    // A system change outside the graph may close the case in a status the
    // form does not list.
    $this->forceStatus($application, 'genehmigt');

    $this->assertTrue($this->catalogueStatus('genehmigt')->isTerminal());
    $this->assertNull($this->resolver()->reviewCode($this->reload($application)));
  }

  /**
   * The correction sets a valid status as an administrative reset.
   */
  public function testCorrectionSetsValidStatus(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form, ['submissionLocale' => 'en', 'pruefnotiz' => 'Alt']);
    $other = $this->createApplication($form);
    $this->forceStatus($application, 'vollstaendigkeit_geprueft');
    $this->forceStatus($other, 'vollstaendigkeit_geprueft');
    $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));

    $controller = ApplicationDataController::create($this->container);
    $application = $this->reload($application);
    $response = $controller->correctStatus($application, $this->correction($application, 'in_bearbeitung', 'Fiktive Korrektur'));
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['success']);
    $this->assertSame('in_bearbeitung', $payload['target_status']['machineName']);

    $stored = $this->reload($application);
    $this->assertSame('in_bearbeitung', $stored->getWorkflowStatus());
    $log = RevisionLog::decode((string) $stored->getRevisionLogMessage());
    $this->assertContains('status_reset', $log['events'] ?? []);
    $this->assertNotContains('status_changed', $log['events'] ?? []);
    $this->assertSame('Fiktive Korrektur', $log['message'] ?? NULL);
    $this->assertSame((int) $this->getCurrentUser()->id(), (int) $stored->getRevisionUserId());
    $this->assertSame(['submissionLocale' => 'en'], json_decode((string) $stored->get('field_workflow_attributes')->value, TRUE));
    $this->assertNull($this->resolver()->reviewCode($stored));

    // Not in the review state any more.
    $this->assertHttpConflict(fn() => $controller->correctStatus($stored, $this->correction($stored, 'eingegangen')));
    // A target outside the current workflow.
    $other = $this->reload($other);
    $this->assertUnprocessable(fn() => $controller->correctStatus($other, $this->correction($other, 'nachforderung_offen')));
    // The current status itself.
    $this->assertUnprocessable(fn() => $controller->correctStatus($other, $this->correction($other, 'vollstaendigkeit_geprueft')));
    // A stale expectation.
    $stale = $this->correction($other, 'in_bearbeitung', '', (int) $this->catalogueStatus('eingegangen')->id());
    $this->assertHttpConflict(fn() => $controller->correctStatus($other, $stale));
    $this->assertSame('vollstaendigkeit_geprueft', $this->reload($other)->getWorkflowStatus());
  }

  /**
   * A request loaded before another correction cannot overwrite its result.
   */
  public function testCorrectionRejectsStaleApplicationAfterAnotherCorrection(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $this->forceStatus($application, 'vollstaendigkeit_geprueft');
    $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));
    $stale = $this->reload($application);
    $request = $this->correction($stale, 'eingegangen');
    $controller = ApplicationDataController::create($this->container);

    $current = $this->reload($application);
    $controller->correctStatus($current, $this->correction($current, 'in_bearbeitung', 'First correction'));
    $stored = $this->reload($application);
    $revision_id = $stored->getRevisionId();
    $this->assertSame('in_bearbeitung', $stored->getWorkflowStatus());
    $this->assertSame('vollstaendigkeit_geprueft', $stale->getWorkflowStatus());

    $this->assertHttpConflict(fn() => $controller->correctStatus($stale, $request));
    $stored = $this->reload($application);
    $this->assertSame($revision_id, $stored->getRevisionId());
    $this->assertSame('in_bearbeitung', $stored->getWorkflowStatus());
    $this->assertSame('First correction', RevisionLog::decode((string) $stored->getRevisionLogMessage())['message'] ?? NULL);
  }

  /**
   * A publish restoring the current status invalidates a memoized review.
   */
  public function testCorrectionRejectsReviewAfterAnotherProcessPublishes(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $this->forceStatus($application, 'vollstaendigkeit_geprueft');
    $form = $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));
    $stale = $this->reload($application);
    $revision_id = $stale->getRevisionId();
    $request = $this->correction($stale, 'in_bearbeitung');
    $resolver = $this->resolver();
    $this->assertSame(EffectiveWorkflowResolver::REVIEW_STATUS_NOT_IN_WORKFLOW, $resolver->reviewCode($stale));
    $held = $this->entityTypeManager()->getStorage('form_schema')->load($form->id());

    $this->republish($form, $this->baseFlow());
    // Simulate another process publishing while this request keeps its cache.
    $this->container->get('entity.memory_cache')->set(
      'values:form_schema:' . $form->id(),
      $held,
      MemoryCacheInterface::CACHE_PERMANENT,
      ['entity.memory_cache:form_schema'],
    );
    $this->assertSame(EffectiveWorkflowResolver::REVIEW_STATUS_NOT_IN_WORKFLOW, $resolver->reviewCode($stale));

    $controller = ApplicationDataController::create($this->container);
    $this->assertHttpConflict(fn() => $controller->correctStatus($stale, $request));
    $stored = $this->reload($application);
    $this->assertSame($revision_id, $stored->getRevisionId());
    $this->assertSame('vollstaendigkeit_geprueft', $stored->getWorkflowStatus());
    $this->assertNull($resolver->reviewCode($stored));
  }

  /**
   * The correction note is limited by characters, as in the dialog.
   */
  public function testCorrectionNoteIsCutByCharacters(): void {
    $form = $this->createForm($this->baseFlow());
    $within = $this->createApplication($form);
    $over = $this->createApplication($form);
    $this->forceStatus($within, 'vollstaendigkeit_geprueft');
    $this->forceStatus($over, 'vollstaendigkeit_geprueft');
    $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));
    $controller = ApplicationDataController::create($this->container);

    // 2000 characters, 2001 bytes: a byte cut would split the umlaut.
    $note = str_repeat('a', 1999) . 'ä';
    $within = $this->reload($within);
    $controller->correctStatus($within, $this->correction($within, 'in_bearbeitung', $note));
    $stored = $this->reload($within);
    $this->assertSame('in_bearbeitung', $stored->getWorkflowStatus());
    $this->assertSame($note, RevisionLog::decode((string) $stored->getRevisionLogMessage())['message'] ?? NULL);

    // Longer notes keep their first 2000 characters.
    $over = $this->reload($over);
    $controller->correctStatus($over, $this->correction($over, 'in_bearbeitung', str_repeat('ä', 2001)));
    $stored = $this->reload($over);
    $this->assertSame(str_repeat('ä', 2000), RevisionLog::decode((string) $stored->getRevisionLogMessage())['message'] ?? NULL);
  }

  /**
   * Fields, validation and display stay on the intake revision.
   */
  public function testFieldsStayOnIntakeRevision(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $this->republish($form, $this->baseFlow(), [
      ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => FALSE],
      ['id' => 'zusatz', 'type' => 'text', 'label' => 'Zusatz', 'required' => FALSE],
    ]);

    $response = ApplicationDataController::create($this->container)->view($this->reload($application));
    $schema = json_decode((string) $response->getContent(), TRUE)['meta']['schema'];
    $this->assertSame(1, $schema['attributes']['field_version']);
    $this->assertStringNotContainsString('zusatz', $schema['attributes']['field_schema']['value']);
  }

  /**
   * Applications of a never-published form keep their own intake flow.
   */
  public function testNeverPublishedFormKeepsWorkflowPerIntakeVersion(): void {
    $first = $this->flow(
      ['eingegangen', 'vollstaendigkeit_geprueft', 'in_bearbeitung'],
      [['eingegangen', 'vollstaendigkeit_geprueft']],
    );
    $second = $this->flow(
      ['eingegangen', 'vollstaendigkeit_geprueft', 'in_bearbeitung'],
      [['eingegangen', 'in_bearbeitung']],
    );
    $form = $this->createForm($first, 'draft');
    $x = $this->createApplication($form);
    $this->editDraft($form, $second);
    $y = $this->createApplication($form);
    $this->assertNotSame($x->getSchemaVersion(), $y->getSchemaVersion());

    $received = $this->catalogueStatus('eingegangen');
    $expected_x = [(int) $this->catalogueStatus('vollstaendigkeit_geprueft')->id()];
    $expected_y = [(int) $this->catalogueStatus('in_bearbeitung')->id()];
    $resolver = $this->newResolver();
    $this->assertSame($expected_x, $resolver->allowedTargetIds($x, $received));
    $this->assertSame($expected_y, $resolver->allowedTargetIds($y, $received));
    $resolver = $this->newResolver();
    $this->assertSame($expected_y, $resolver->allowedTargetIds($y, $received));
    $this->assertSame($expected_x, $resolver->allowedTargetIds($x, $received));

    $this->setCurrentUser($this->staff(['edit any application entities', 'manage workflow transitions']));
    $rows = array_column($this->staffList()['data'], NULL, 'id');
    $this->assertSame([$this->catalogueStatus('vollstaendigkeit_geprueft')->uuid()], $rows[$x->uuid()]['status']['allowedNextIds']);
    $this->assertSame([$this->catalogueStatus('in_bearbeitung')->uuid()], $rows[$y->uuid()]['status']['allowedNextIds']);
  }

  /**
   * A publish by another process reaches enforcement in a running worker.
   */
  public function testPublishFromAnotherProcessReachesEnforcement(): void {
    $form = $this->createForm($this->baseFlow());
    $application = $this->createApplication($form);
    $received = $this->catalogueStatus('eingegangen');
    $amtsleitung = $this->catalogueStatus('amtsleitung_offen');
    $resolver = $this->resolver();
    $manager = $this->workflowManager();
    // Fill the memo with the first flow and keep the form object the
    // worker holds.
    $this->assertFalse($manager->isApplicationTransitionAllowed($application, $received, $amtsleitung));
    $storage = $this->entityTypeManager()->getStorage('form_schema');
    $held = $storage->load($form->id());
    $this->assertInstanceOf(FormSchemaInterface::class, $held);
    $held_revision_id = (int) $held->getRevisionId();

    $this->republish($form, $this->withAmtsleitung($this->baseFlow()));
    // The other process: this one still holds the pre-publish form object.
    $this->container->get('entity.memory_cache')->set(
      'values:form_schema:' . $form->id(),
      $held,
      MemoryCacheInterface::CACHE_PERMANENT,
      ['entity.memory_cache:form_schema'],
    );
    $this->assertSame($held_revision_id, (int) $storage->load($form->id())->getRevisionId());

    $this->assertSame($resolver, $this->resolver());
    $this->assertTrue($manager->isApplicationTransitionAllowed($application, $received, $amtsleitung));
    $this->move($application, 'amtsleitung_offen');
    $this->assertSame('amtsleitung_offen', $this->reload($application)->getWorkflowStatus());
  }

  /**
   * New applications start in the default status of the governing flow.
   */
  public function testDefaultStatusOnDraftAfterPublishComesFromPublishedWorkflow(): void {
    $first = $this->baseFlow();
    $first['defaultStatus'] = 'vollstaendigkeit_geprueft';
    $second = $this->baseFlow();
    $second['defaultStatus'] = 'in_bearbeitung';

    $published = $this->createForm($first);
    $this->toDraft($published, $second);
    $this->assertSame('vollstaendigkeit_geprueft', $this->createApplication($published)->getWorkflowStatus());

    $never = $this->createForm($first, 'draft');
    $this->editDraft($never, $second);
    $this->assertSame('in_bearbeitung', $this->createApplication($never)->getWorkflowStatus());
  }

  /**
   * Both new routes are staff-only; the owning citizen is refused.
   */
  public function testWorkflowRouteAccess(): void {
    $this->container->get('router.builder')->rebuild();
    $form = $this->createForm($this->baseFlow());
    $citizen = $this->createUser(['view own application entities', 'edit own application entities']);
    $application = $this->createApplication($form, [], ['uid' => $citizen->id()]);
    $this->forceStatus($application, 'vollstaendigkeit_geprueft');
    $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));
    $application = $this->reload($application);

    $foreign_organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Fremdamt',
      'field_slug' => 'fremdamt',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $foreign = $this->staff(['manage workflow transitions', 'edit any application entities'], $foreign_organisation);
    $reader = $this->staff(['edit any application entities']);
    $no_update = $this->staff(['manage workflow transitions']);
    $full = $this->staff(['manage workflow transitions', 'edit any application entities'], group_role: 'org-member');

    $this->assertTrue($application->access('view', $citizen), 'The owner passes entity view access.');
    $cases = [
      'citizen owner' => [$citizen, FALSE, FALSE],
      'foreign workspace' => [$foreign, FALSE, FALSE],
      'without transition permission' => [$reader, TRUE, FALSE],
      'without update access' => [$no_update, TRUE, FALSE],
      'all requirements' => [$full, TRUE, TRUE],
    ];
    $access = $this->container->get('access_manager');
    foreach ($cases as $label => [$account, $read, $correct]) {
      $this->assertSame($read, $access->checkNamedRoute('beantragt_application.admin_workflow', ['application' => $application->id()], $account), "$label: workflow");
      $this->assertSame($correct, $access->checkNamedRoute('beantragt_application.admin_status_correction', ['application' => $application->id()], $account), "$label: correction");
      if (!$read) {
        continue;
      }
      $this->setCurrentUser($account);
      $review = $this->workflowResponse($application)['review'];
      $this->assertSame($correct, $review['can_correct'], "$label: can_correct");
      if (!$correct) {
        $this->assertSame([], $review['targets'], "$label: targets");
      }
      else {
        $this->assertNotSame([], $review['targets'], "$label: targets");
      }
    }
  }

  /**
   * The route's allowed targets are exactly what enforcement accepts.
   */
  public function testWorkflowRouteAllowedNextMatchesEnforcement(): void {
    // Override with A to C where C is no organisation edge of A.
    $form = $this->createForm($this->withAmtsleitung($this->baseFlow()));
    $application = $this->createApplication($form);
    $payload = $this->workflowResponse($application);
    $this->assertTrue($payload['override']);
    $this->assertIsString($payload['definition']);
    $this->assertSame(EffectiveWorkflowResolver::SOURCE_PUBLISHED, $payload['form']['source']);
    $this->assertSame($form->uuid(), $payload['form']['uuid']);
    $amtsleitung = $this->catalogueStatus('amtsleitung_offen');
    $this->assertContains(['id' => (int) $amtsleitung->id(), 'uuid' => $amtsleitung->uuid()], $payload['allowed_next']);
    $this->assertAllowedNextMatchesEnforcement($application);

    // Mode inherit, with and without left-over keys: organisation edges.
    foreach ([
      ['mode' => 'inherit'],
      ['mode' => 'inherit'] + $this->withAmtsleitung($this->baseFlow()),
    ] as $index => $inherit) {
      $inherit['mode'] = 'inherit';
      $inherited = $this->createApplication($this->createForm($inherit));
      $payload = $this->workflowResponse($inherited);
      $this->assertFalse($payload['override'], "inherit $index");
      $this->assertNull($payload['definition'], "inherit $index");
      $expected = $this->catalogueStatus('eingegangen')->getAllowedNextIds();
      sort($expected);
      $actual = array_column($payload['allowed_next'], 'id');
      sort($actual);
      $this->assertSame($expected, $actual, "inherit $index");
      $this->assertAllowedNextMatchesEnforcement($inherited);
    }

    // Terminal current status: nothing.
    $terminal = $this->createApplication($form);
    $this->forceStatus($terminal, 'zurueckgezogen');
    $this->assertSame([], $this->workflowResponse($terminal)['allowed_next']);
    $this->assertAllowedNextMatchesEnforcement($terminal);

    // Review state: nothing.
    $review = $this->createApplication($form);
    $this->forceStatus($review, 'nachforderung_offen');
    $payload = $this->workflowResponse($review);
    $this->assertSame('status_not_in_workflow', $payload['review']['code']);
    $this->assertSame([], $payload['allowed_next']);
    $this->assertAllowedNextMatchesEnforcement($review);
  }

  /**
   * Approval and rejection statuses are never correction targets.
   */
  public function testCorrectionTargetsExcludeDecisionStatuses(): void {
    // A flagless status with a legacy decision name.
    $this->catalogueStatus('abgelehnt')->set('field_semantic_flags', [])->save();
    $this->createStatus('ablehnung_neu', ['rejected']);
    $flow = $this->flow(
      ['eingegangen', 'in_bearbeitung', 'genehmigt', 'abgelehnt', 'ablehnung_neu'],
      [['eingegangen', 'in_bearbeitung']],
    );
    $form = $this->createForm($flow);
    $application = $this->createApplication($form);
    $this->forceStatus($application, 'vollstaendigkeit_geprueft');
    $application = $this->reload($application);

    $review = $this->workflowResponse($application)['review'];
    $this->assertTrue($review['can_correct']);
    $targets = array_column($review['targets'], 'id');
    sort($targets);
    $expected = [
      (int) $this->catalogueStatus('eingegangen')->id(),
      (int) $this->catalogueStatus('in_bearbeitung')->id(),
    ];
    sort($expected);
    $this->assertSame($expected, $targets);

    $controller = ApplicationDataController::create($this->container);
    foreach (['genehmigt', 'abgelehnt', 'ablehnung_neu'] as $decision) {
      $this->assertUnprocessable(fn() => $controller->correctStatus($application, $this->correction($application, $decision)), $decision);
    }
    $this->assertSame('vollstaendigkeit_geprueft', $this->reload($application)->getWorkflowStatus());

    // Only decision statuses left: still marked, nothing to set by hand.
    $decisions_only = $this->createForm($this->flow(['genehmigt', 'abgelehnt', 'ablehnung_neu'], []));
    $stuck = $this->createApplication($decisions_only);
    $this->forceStatus($stuck, 'vollstaendigkeit_geprueft');
    $review = $this->workflowResponse($this->reload($stuck))['review'];
    $this->assertSame('status_not_in_workflow', $review['code']);
    $this->assertTrue($review['can_correct']);
    $this->assertSame([], $review['targets']);
  }

  /**
   * Staff API v1 list and detail carry the review code only when it holds.
   */
  public function testReviewCodeInStaffProjections(): void {
    $form = $this->createForm($this->baseFlow());
    $marked = $this->createApplication($form);
    $ordinary = $this->createApplication($form);
    $closed = $this->createApplication($form);
    $this->forceStatus($marked, 'vollstaendigkeit_geprueft');
    $this->forceStatus($ordinary, 'in_bearbeitung');
    $this->forceStatus($closed, 'genehmigt');
    $this->republish($form, $this->withoutStatus($this->baseFlow(), 'vollstaendigkeit_geprueft'));

    $admin = $this->getCurrentUser();
    $this->setCurrentUser($this->staff(['edit any application entities', 'manage workflow transitions']));
    $rows = array_column($this->staffList()['data'], NULL, 'id');
    $this->assertSame('status_not_in_workflow', $rows[$marked->uuid()]['status']['reviewCode'] ?? NULL);
    $this->assertSame([], $rows[$marked->uuid()]['status']['allowedNextIds']);
    $this->assertArrayNotHasKey('reviewCode', $rows[$ordinary->uuid()]['status']);
    $this->assertNotSame([], $rows[$ordinary->uuid()]['status']['allowedNextIds']);
    $this->assertArrayNotHasKey('reviewCode', $rows[$closed->uuid()]['status']);
    $detail = $this->staffDetail($marked)['data']['status'];
    $this->assertSame('status_not_in_workflow', $detail['reviewCode'] ?? NULL);
    $this->assertSame([], $detail['allowedNextIds']);
    $this->assertArrayNotHasKey('reviewCode', $this->staffDetail($ordinary)['data']['status']);
    $this->assertArrayNotHasKey('reviewCode', $this->staffDetail($closed)['data']['status']);

    $this->setCurrentUser($admin);
    $marked = $this->reload($marked);
    ApplicationDataController::create($this->container)->correctStatus($marked, $this->correction($marked, 'eingegangen'));
    $this->setCurrentUser($this->staff(['edit any application entities', 'manage workflow transitions']));
    $rows = array_column($this->staffList()['data'], NULL, 'id');
    $this->assertArrayNotHasKey('reviewCode', $rows[$marked->uuid()]['status']);
    $this->assertArrayNotHasKey('reviewCode', $this->staffDetail($marked)['data']['status']);
  }

  /**
   * Asserts allowed_next membership equals isApplicationTransitionAllowed().
   */
  private function assertAllowedNextMatchesEnforcement(ApplicationInterface $application): void {
    $application = $this->reload($application);
    $current = $application->get('field_workflow_status')->entity;
    $this->assertInstanceOf(WorkflowStatusInterface::class, $current);
    $allowed = array_column($this->workflowResponse($application)['allowed_next'], 'id');
    $statuses = $this->entityTypeManager()->getStorage('workflow_status')->loadByProperties([
      'field_group' => $this->organisation->id(),
    ]);
    foreach ($statuses as $status) {
      assert($status instanceof WorkflowStatusInterface);
      if ((int) $status->id() === (int) $current->id()) {
        // Staying is a no-op, never a target.
        continue;
      }
      $this->assertSame(
        $this->workflowManager()->isApplicationTransitionAllowed($application, $current, $status),
        in_array((int) $status->id(), $allowed, TRUE),
        $current->getMachineName() . ' -> ' . $status->getMachineName(),
      );
    }
  }

  /**
   * Calls the workflow route controller as the current user.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private function workflowResponse(ApplicationInterface $application): array {
    $response = ApplicationDataController::create($this->container)->workflow($this->reload($application));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Builds a correction request.
   */
  private function correction(ApplicationInterface $application, string $target, string $note = '', ?int $expected_status_id = NULL): Request {
    return Request::create('/api/admin/applications/' . $application->id() . '/status-correction', 'PATCH', content: json_encode([
      'expected_status_id' => $expected_status_id ?? (int) $application->get('field_workflow_status')->target_id,
      'expected_changed' => $application->getChangedTime(),
      'target_status_id' => (int) $this->catalogueStatus($target)->id(),
      'note' => $note,
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Returns the staff v1 list of the organisation workspace.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private function staffList(): array {
    $response = StaffApplicationApiController::create($this->container)->list(Request::create('/api/v1/staff/applications', 'GET', [
      'jurisdiction' => 'ablaufstadt',
      'organisation' => 'ablaufamt',
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Returns the staff v1 detail of one application.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private function staffDetail(ApplicationInterface $application): array {
    $response = StaffApplicationApiController::create($this->container)->detail($application->uuid(), Request::create('/api/v1/staff/applications/' . $application->uuid(), 'GET', [
      'jurisdiction' => 'ablaufstadt',
      'organisation' => 'ablaufamt',
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Creates a staff account with an admin membership in an organisation.
   *
   * @param string[] $permissions
   *   Extra permissions.
   * @param \Drupal\group\Entity\GroupInterface|null $organisation
   *   The organisation, the fixture organisation by default.
   * @param string $group_role
   *   The role on the direct membership.
   */
  private function staff(array $permissions = [], ?GroupInterface $organisation = NULL, string $group_role = 'org-admin'): UserInterface {
    if ($this->entityTypeManager()->getStorage('user_role')->load('case_worker') === NULL) {
      $this->createRole(['view published application entities'], 'case_worker');
    }
    $user = $this->createUser($permissions);
    assert($user instanceof UserInterface);
    $user->addRole('case_worker');
    $user->save();
    ($organisation ?? $this->organisation)->addMember($user, ['group_roles' => [$group_role]]);
    return $user;
  }

  /**
   * Asserts that a status save is refused.
   */
  private function assertRefused(callable $operation): void {
    try {
      $operation();
    }
    catch (\Exception $exception) {
      $this->assertStringContainsString('Workflow', $exception->getMessage() . ' ' . $exception->getPrevious()?->getMessage());
      return;
    }
    $this->fail('The status change was not refused.');
  }

  /**
   * Asserts a 409.
   */
  private function assertHttpConflict(callable $operation): void {
    try {
      $operation();
    }
    catch (ConflictHttpException) {
      $this->addToAssertionCount(1);
      return;
    }
    $this->fail('Expected 409.');
  }

  /**
   * Asserts a 422.
   */
  private function assertUnprocessable(callable $operation, string $message = ''): void {
    try {
      $operation();
    }
    catch (UnprocessableEntityHttpException) {
      $this->addToAssertionCount(1);
      return;
    }
    $this->fail('Expected 422. ' . $message);
  }

  /**
   * The first published flow of the fixture form.
   *
   * @return array<string, mixed>
   *   Workflow definition.
   */
  private function baseFlow(): array {
    return $this->flow(
      ['eingegangen', 'vollstaendigkeit_geprueft', 'in_bearbeitung', 'zurueckgezogen'],
      [
        ['eingegangen', 'vollstaendigkeit_geprueft'],
        ['vollstaendigkeit_geprueft', 'in_bearbeitung'],
        ['in_bearbeitung', 'zurueckgezogen'],
      ],
    );
  }

  /**
   * Adds the status "amtsleitung_offen" and the transition into it.
   *
   * @param array<string, mixed> $flow
   *   Workflow definition.
   *
   * @return array<string, mixed>
   *   Workflow definition.
   */
  private function withAmtsleitung(array $flow): array {
    $flow['statuses'][] = ['machineName' => 'amtsleitung_offen'];
    $flow['transitions'][] = ['from' => 'eingegangen', 'to' => 'amtsleitung_offen'];
    return $flow;
  }

  /**
   * Removes a status and every transition touching it.
   *
   * @param array<string, mixed> $flow
   *   Workflow definition.
   * @param string $machine_name
   *   The status to remove.
   *
   * @return array<string, mixed>
   *   Workflow definition.
   */
  private function withoutStatus(array $flow, string $machine_name): array {
    $flow['statuses'] = array_values(array_filter(
      $flow['statuses'],
      static fn(array $status): bool => $status['machineName'] !== $machine_name,
    ));
    $flow['transitions'] = array_values(array_filter(
      $flow['transitions'],
      static fn(array $transition): bool => $transition['from'] !== $machine_name && $transition['to'] !== $machine_name,
    ));
    return $flow;
  }

  /**
   * Builds a catalog workflow definition.
   *
   * @param string[] $statuses
   *   Status machine names.
   * @param array<int, array{0: string, 1: string}> $transitions
   *   Transitions as from/to pairs.
   *
   * @return array<string, mixed>
   *   Workflow definition.
   */
  private function flow(array $statuses, array $transitions): array {
    return [
      'mode' => 'catalog',
      'defaultStatus' => 'eingegangen',
      'statuses' => array_map(static fn(string $status): array => ['machineName' => $status], $statuses),
      'transitions' => array_map(static fn(array $pair): array => ['from' => $pair[0], 'to' => $pair[1]], $transitions),
    ];
  }

  /**
   * Creates a form of the organisation.
   *
   * @param array<string, mixed> $workflow
   *   Workflow definition.
   * @param string $lifecycle
   *   Lifecycle status.
   */
  private function createForm(array $workflow, string $lifecycle = 'published'): FormSchemaInterface {
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Fiktives Ablaufformular',
      'field_schema' => $this->schemaJson([
        ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => FALSE],
      ]),
      'field_workflow_definition' => json_encode($workflow, JSON_THROW_ON_ERROR),
      'field_workflow_status' => $lifecycle,
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'status' => $lifecycle === 'published',
    ]);
    $form->save();
    assert($form instanceof FormSchemaInterface);
    return $form;
  }

  /**
   * Encodes a one-section form schema.
   *
   * @param array<int, array<string, mixed>> $fields
   *   Fields.
   */
  private function schemaJson(array $fields): string {
    return json_encode([
      'title' => 'Fiktives Ablaufformular',
      'sections' => [['id' => 'main', 'title' => 'Main', 'fields' => $fields]],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Puts a published form back to draft and changes its workflow.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed> $workflow
   *   Workflow definition.
   * @param array<int, array<string, mixed>>|null $fields
   *   New fields, or NULL to keep them.
   */
  private function toDraft(FormSchemaInterface $form, array $workflow, ?array $fields = NULL): FormSchemaInterface {
    $form = $this->reloadForm($form);
    if ($form->getSchemaStatus() !== 'draft') {
      $form->set('field_workflow_status', 'draft');
      $form->save();
      $form = $this->reloadForm($form);
    }
    return $this->editDraft($form, $workflow, $fields);
  }

  /**
   * Changes the workflow (and optionally the fields) of a draft.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed> $workflow
   *   Workflow definition.
   * @param array<int, array<string, mixed>>|null $fields
   *   New fields, or NULL to keep them.
   */
  private function editDraft(FormSchemaInterface $form, array $workflow, ?array $fields = NULL): FormSchemaInterface {
    $form = $this->reloadForm($form);
    $form->set('field_workflow_definition', json_encode($workflow, JSON_THROW_ON_ERROR));
    if ($fields !== NULL) {
      $form->set('field_schema', $this->schemaJson($fields));
    }
    $form->save();
    return $this->reloadForm($form);
  }

  /**
   * Publishes a form.
   */
  private function publish(FormSchemaInterface $form): FormSchemaInterface {
    $form = $this->reloadForm($form);
    $form->set('field_workflow_status', 'published');
    $form->setPublished();
    $form->save();
    return $this->reloadForm($form);
  }

  /**
   * Publishes a new workflow (draft, edit, publish).
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed> $workflow
   *   Workflow definition.
   * @param array<int, array<string, mixed>>|null $fields
   *   New fields, or NULL to keep them.
   */
  private function republish(FormSchemaInterface $form, array $workflow, ?array $fields = NULL): FormSchemaInterface {
    return $this->publish($this->toDraft($form, $workflow, $fields));
  }

  /**
   * Reloads a form's default revision.
   */
  private function reloadForm(FormSchemaInterface $form): FormSchemaInterface {
    $stored = $this->entityTypeManager()->getStorage('form_schema')->loadUnchanged($form->id());
    assert($stored instanceof FormSchemaInterface);
    return $stored;
  }

  /**
   * Creates a confirmed application of the organisation.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed> $attributes
   *   Workflow attributes stored at intake.
   * @param array<string, mixed> $values
   *   Extra entity values.
   */
  private function createApplication(FormSchemaInterface $form, array $attributes = [], array $values = []): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create($values + [
      'field_schema' => ['target_id' => $form->id()],
      'field_data' => json_encode(['note' => 'Fiktiver Inhalt'], JSON_THROW_ON_ERROR),
      'field_workflow_attributes' => $attributes === [] ? NULL : json_encode($attributes, JSON_THROW_ON_ERROR),
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_email_confirmed' => TRUE,
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $this->reload($application);
  }

  /**
   * Moves an application through a normal transition.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string $machine_name
   *   Target status.
   * @param array<string, mixed> $attributes
   *   Transition attributes.
   */
  private function move(ApplicationInterface $application, string $machine_name, array $attributes = []): void {
    $application = $this->reload($application);
    $application->setWorkflowStatusId((int) $this->catalogueStatus($machine_name)->id());
    $application->set('field_workflow_attributes', $attributes === [] ? '{}' : json_encode($attributes, JSON_THROW_ON_ERROR));
    $application->save();
  }

  /**
   * Puts an application into a status outside the graph (reset path).
   */
  private function forceStatus(ApplicationInterface $application, string $machine_name): void {
    $application = $this->reload($application);
    $application->setWorkflowStatusId((int) $this->catalogueStatus($machine_name)->id());
    $application->allowWorkflowStatusReset();
    $application->save();
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
   * Loads one status of the catalogue by machine name.
   */
  private function catalogueStatus(string $machine_name): WorkflowStatusInterface {
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $storage->resetCache();
    $statuses = $storage->loadByProperties([
      'field_group' => $this->organisation->id(),
      'field_machine_name' => $machine_name,
    ]);
    $status = reset($statuses);
    $this->assertInstanceOf(WorkflowStatusInterface::class, $status, $machine_name);
    return $status;
  }

  /**
   * Adds a status to the catalogue.
   *
   * @param string $machine_name
   *   Machine name.
   * @param string[] $flags
   *   Semantic flags.
   * @param bool $terminal
   *   Whether the status is terminal.
   */
  private function createStatus(string $machine_name, array $flags, bool $terminal = TRUE): void {
    $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => $machine_name,
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_machine_name' => $machine_name,
      'field_is_terminal' => $terminal,
      'field_semantic_flags' => array_map(static fn(string $flag): array => ['value' => $flag], $flags),
    ])->save();
  }

  /**
   * The resolver service the workflow manager uses.
   */
  private function resolver(): EffectiveWorkflowResolver {
    $resolver = $this->container->get('beantragt_application.effective_workflow_resolver');
    assert($resolver instanceof EffectiveWorkflowResolver);
    return $resolver;
  }

  /**
   * A fresh resolver with an empty memo.
   */
  private function newResolver(): EffectiveWorkflowResolver {
    return new EffectiveWorkflowResolver(
      $this->entityTypeManager(),
      $this->container->get('beantragt_workflow_status.status_manager'),
    );
  }

  /**
   * The workflow manager service.
   */
  private function workflowManager(): WorkflowManager {
    $manager = $this->container->get('beantragt_workflow.workflow_manager');
    assert($manager instanceof WorkflowManager);
    return $manager;
  }

}
