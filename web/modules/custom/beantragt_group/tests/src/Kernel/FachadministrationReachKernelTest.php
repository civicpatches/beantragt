<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Pins the reach of the Fachadministration and of the case workers.
 *
 * The site role says WHAT an account may do, the group membership says
 * WHERE. A full Fachadministration holds jur-admin in its municipality and no
 * office membership; a limited one holds org-admin in one office; the editor
 * case worker holds org-admin and the viewer org-member in that office. The
 * roles come from beantragt_roles_install(), the checks are the real entity
 * access handler and the route access methods that decide data correction,
 * status and deadline, the reveal of protected data and assignment. Neutral
 * groups, invented addresses.
 */
#[Group('beantragt_group')]
#[Group('beantragt_application')]
final class FachadministrationReachKernelTest extends GroupKernelTestBase {

  /**
   * Every check of the matrix, all allowed.
   */
  private const ALL = [
    'view' => TRUE,
    'update' => TRUE,
    'delete' => TRUE,
    'data_correction' => TRUE,
    'status_and_deadline' => TRUE,
    'reveal_protected' => TRUE,
    'assign' => TRUE,
  ];

  /**
   * Every check of the matrix, all forbidden.
   */
  private const NONE = [
    'view' => FALSE,
    'update' => FALSE,
    'delete' => FALSE,
    'data_correction' => FALSE,
    'status_and_deadline' => FALSE,
    'reveal_protected' => FALSE,
    'assign' => FALSE,
  ];

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
    'beantragt_roles_api',
    'beantragt_roles',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_workflow',
    'beantragt_notification',
    'beantragt_delivery',
  ];

  /**
   * Municipality A.
   */
  private GroupInterface $municipalityA;

  /**
   * Municipality B.
   */
  private GroupInterface $municipalityB;

  /**
   * Office A1 of municipality A.
   */
  private GroupInterface $officeA1;

  /**
   * One application per office, keyed by office.
   *
   * @var array<string, \Drupal\beantragt_application\Entity\ApplicationInterface>
   */
  private array $applications = [];

  /**
   * Full Fachadministration of A: jur-admin in A, no office membership.
   */
  private UserInterface $full;

  /**
   * Limited Fachadministration of A1: org-admin in A1 only.
   */
  private UserInterface $limited;

  /**
   * Editor case worker of A1: org-admin in A1.
   */
  private UserInterface $editor;

  /**
   * Viewer case worker of A1: org-member in A1.
   */
  private UserInterface $viewer;

  /**
   * Whether routing has been built for this test.
   */
  private bool $routesBuilt = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->config('system.site')->set('mail', 'site@example.test')->save();
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->entityTypeManager());
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence', 'beantragt_protected_access_log']);
    $this->installSchema('beantragt_delivery', ['beantragt_delivery_log']);
    $this->installSchema('file', ['file_usage']);

    // The real site roles of the product, not a hand-made permission list.
    $this->container->get('module_handler')->loadInclude('beantragt_roles', 'install');
    beantragt_roles_install();
    $this->assertRolePreconditions();

    $this->municipalityA = $this->createMunicipality('reach-a', 'Municipality A');
    $this->municipalityB = $this->createMunicipality('reach-b', 'Municipality B');
    $this->officeA1 = $this->createOffice('reach-a1', 'Office A1', $this->municipalityA);
    $office_a2 = $this->createOffice('reach-a2', 'Office A2', $this->municipalityA);
    $office_b1 = $this->createOffice('reach-b1', 'Office B1', $this->municipalityB);

    // Applications exist before any persona, so no routing touches them.
    foreach (['a1' => $this->officeA1, 'a2' => $office_a2, 'b1' => $office_b1] as $key => $office) {
      $this->applications[$key] = $this->createApplicationIn($office);
    }

    $this->full = $this->createPersona('reach_full', 'department_admin');
    $this->municipalityA->addMember($this->full, ['group_roles' => ['jur-admin']]);

    $this->limited = $this->createPersona('reach_limited', 'department_admin');
    $this->officeA1->addMember($this->limited, ['group_roles' => ['org-admin']]);

    $this->editor = $this->createPersona('reach_editor', 'case_worker');
    $this->officeA1->addMember($this->editor, ['group_roles' => ['org-admin']]);

    $this->viewer = $this->createPersona('reach_viewer', 'case_worker');
    $this->officeA1->addMember($this->viewer, ['group_roles' => ['org-member']]);
  }

  /**
   * The full role reaches every office of its municipality, without joining.
   */
  public function testFullReachesEveryOfficeOfItsMunicipality(): void {
    $this->assertSame(self::ALL, $this->reach($this->applications['a1'], $this->full), 'Office A1');
    $this->assertSame(self::ALL, $this->reach($this->applications['a2'], $this->full), 'Office A2');
    $this->assertMembershipsAreExactly($this->full, ['Municipality A' => ['jur-admin']]);
  }

  /**
   * The full role of A reaches nothing in municipality B.
   */
  public function testFullIsForbiddenInTheOtherMunicipality(): void {
    $this->assertSame(self::ALL, $this->reach($this->applications['a1'], $this->full), 'Own municipality');
    $this->assertSame(self::NONE, $this->reach($this->applications['b1'], $this->full), 'Other municipality');
  }

  /**
   * The limited role reaches its office and nothing next to or above it.
   */
  public function testLimitedStaysInItsOffice(): void {
    $this->assertSame(self::ALL, $this->reach($this->applications['a1'], $this->limited), 'Own office');
    $this->assertSame(self::NONE, $this->reach($this->applications['a2'], $this->limited), 'Other office of the same municipality');
    $this->assertSame(self::NONE, $this->reach($this->applications['b1'], $this->limited), 'Office of the other municipality');
    $this->assertMembershipsAreExactly($this->limited, ['Office A1' => ['org-admin']]);
  }

  /**
   * An office created later is reached through the municipality membership.
   */
  public function testOfficeCreatedLaterIsReachedWithoutNewMembership(): void {
    $office_a3 = $this->createOffice('reach-a3', 'Office A3', $this->municipalityA);
    $application = $this->createApplicationIn($office_a3);

    // A programmatic create adds nobody (creator_wizard), not even the
    // municipality's Fachadministration.
    $this->assertSame([], GroupMembership::loadByGroup($office_a3));
    $this->assertSame(self::ALL, $this->reach($application, $this->full), 'Full role in the later office');
    $this->assertMembershipsAreExactly($this->full, ['Municipality A' => ['jur-admin']]);
    $this->assertSame(self::ALL, $this->reach($this->applications['a1'], $this->limited), 'Limited role in its own office');
    $this->assertSame(self::NONE, $this->reach($application, $this->limited), 'Limited role in the later office');
  }

  /**
   * Both case workers can work in the office, with existing extra rights.
   */
  public function testCaseWorkerWithOfficeMemberOrAdminRole(): void {
    $editor = $this->reach($this->applications['a1'], $this->editor);
    $viewer = $this->reach($this->applications['a1'], $this->viewer);

    foreach (['status_and_deadline', 'reveal_protected', 'assign'] as $check) {
      $this->assertTrue($editor[$check], 'PINNED: the editor case worker reaches "' . $check . '" in its office.');
    }

    $this->assertSame([
      'view' => TRUE,
      'update' => TRUE,
      'delete' => FALSE,
      'data_correction' => TRUE,
      'status_and_deadline' => TRUE,
      'reveal_protected' => TRUE,
      'assign' => TRUE,
    ], $editor, 'Editor in its office');
    $this->assertSame([
      'view' => TRUE,
      'update' => TRUE,
      'delete' => FALSE,
      'data_correction' => TRUE,
      'status_and_deadline' => TRUE,
      'reveal_protected' => TRUE,
      'assign' => TRUE,
    ], $viewer, 'Viewer in its office');

    foreach (['a2', 'b1'] as $key) {
      $this->assertSame(self::NONE, $this->reach($this->applications[$key], $this->editor), 'Editor outside its office: ' . $key);
      $this->assertSame(self::NONE, $this->reach($this->applications[$key], $this->viewer), 'Viewer outside its office: ' . $key);
    }
  }

  /**
   * A scoped case worker needs a reason, and the revision supplies history.
   */
  public function testCaseWorkerCorrectionRecordsReasonAndHistory(): void {
    $application = $this->applications['a1'];
    $path = '/api/admin/applications/' . $application->id() . '/data';
    $original_id = $application->getRevisionId();
    $document = ['data' => ['message' => 'Updated count'], 'expected_changed' => $application->getChangedTime()];

    $detail = $this->send('GET', $path, $this->viewer);
    $this->assertSame(200, $detail->getStatusCode());
    $meta = json_decode((string) $detail->getContent(), TRUE)['meta'];
    $this->assertTrue($meta['can_edit_data']);
    $this->assertArrayHasKey('edit_data_blocked_reason', $meta);
    $this->assertNull($meta['edit_data_blocked_reason']);

    foreach ([
      [],
      ['reason' => ['invalid']],
      ['reason' => " \t\n\u{00a0}"],
      ['reason' => "\u{200B}"],
      ['reason' => "\u{FEFF}"],
      ['reason' => "\u{00AD}"],
      ['reason' => "\u{202E}"],
      ['reason' => '<b></b>'],
    ] as $reason) {
      $response = $this->send('PATCH', $path, $this->viewer, $document + $reason);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('application.data_edit_reason_required', json_decode((string) $response->getContent(), TRUE)['error']['code']);
      $this->assertSame($original_id, $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id())->getRevisionId());
    }

    $too_long = $this->send('PATCH', $path, $this->viewer, $document + ['reason' => '<b>' . str_repeat('x', 501) . '</b>']);
    $this->assertSame(400, $too_long->getStatusCode());
    $this->assertSame('application.data_edit_reason_too_long', json_decode((string) $too_long->getContent(), TRUE)['error']['code']);

    $document['reason'] = "  <b>Compared</b>\u{200B}\twith the supplied document.  " . str_repeat('<b></b>', 100);
    $response = $this->send('PATCH', $path, $this->viewer, $document);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $saved = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertNotSame($original_id, $saved->getRevisionId());
    $this->assertSame((int) $this->viewer->id(), (int) $saved->getRevisionUserId());
    $this->assertSame('Compared with the supplied document.', RevisionLog::message((string) $saved->getRevisionLogMessage()));
    $this->assertSame($document['data'], json_decode($saved->getData(), TRUE));

    $history = $this->send('GET', $this->historyPath($application), $this->viewer);
    $this->assertSame(200, $history->getStatusCode(), (string) $history->getContent());
    $this->assertStringContainsString('no-store', (string) $history->headers->get('Cache-Control'));
    $expected = [
      [
        'kind' => 'data_edited',
        'revisionId' => (int) $saved->getRevisionId(),
        'timestamp' => date(\DateTimeInterface::ATOM, (int) $saved->getRevisionCreationTime()),
        'author' => ['id' => (int) $this->viewer->id(), 'label' => $this->viewer->getDisplayName()],
        'reason' => 'Compared with the supplied document.',
        'changes' => [['field' => 'message', 'oldValue' => 'Hello', 'hadOldValue' => TRUE]],
      ],
    ];
    $this->assertSame($expected, json_decode((string) $history->getContent(), TRUE)['meta']['dataCorrections']);
    $history = $this->send('GET', $this->historyPath($application), $this->viewer);
    $this->assertSame(200, $history->getStatusCode());
    $this->assertSame($expected, json_decode((string) $history->getContent(), TRUE)['meta']['dataCorrections']);
  }

  /**
   * A correction with a missing revision author exposes neither ID nor label.
   */
  public function testDataCorrectionHistoryOmitsUnavailableAuthorId(): void {
    $this->installSchema('user', ['users_data']);
    $application = $this->applications['a1'];
    $response = $this->correct($application, $this->editor);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $editor_id = (int) $this->editor->id();
    $this->officeA1->removeMember($this->editor);
    $this->editor->delete();
    $saved = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertSame($editor_id, (int) $saved->getRevisionUserId());
    $this->assertNull($saved->getRevisionUser());

    $history = $this->send('GET', $this->historyPath($application), $this->limited);
    $this->assertSame(200, $history->getStatusCode(), (string) $history->getContent());
    $this->assertSame(['label' => NULL], json_decode((string) $history->getContent(), TRUE)['meta']['dataCorrections'][0]['author']);
  }

  /**
   * The route preserves the writable office boundary and the protected gate.
   */
  public function testCaseWorkerCorrectionRefusesOtherOfficesAndProtectedData(): void {
    foreach (['a2' => $this->viewer, 'b1' => $this->viewer] as $key => $account) {
      $application = $this->applications[$key];
      $response = $this->correct($application, $account);
      $this->assertSame(403, $response->getStatusCode(), $key . ': ' . (string) $response->getContent());
      $this->assertSame($application->getRevisionId(), $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id())->getRevisionId());
    }

    $application = $this->createApplicationIn($this->officeA1, TRUE);
    $response = $this->correct($application, $this->viewer);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $saved = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $history = $this->send('GET', $this->historyPath($application), $this->viewer);
    $this->assertSame(200, $history->getStatusCode(), (string) $history->getContent());
    $this->assertSame('Hello', json_decode((string) $history->getContent(), TRUE)['meta']['dataCorrections'][0]['changes'][0]['oldValue']);
    $this->assertFalse($saved->get('revision_log_message')->access('view', $this->viewer));
    $channel = $this->container->get('database')->select('beantragt_protected_access_log', 'l')
      ->fields('l', ['channel'])->condition('application_uuid', $application->uuid())->execute()->fetchField();
    $this->assertSame('admin_data_history', $channel);

    $role = Role::load('case_worker');
    $role->revokePermission('view protected application data')->save();
    $this->assertSame(403, $this->correct($saved, $this->viewer)->getStatusCode());
    $this->assertSame($saved->getRevisionId(), $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id())->getRevisionId());
    $history = $this->send('GET', $this->historyPath($application), $this->viewer);
    $this->assertSame(200, $history->getStatusCode());
    $this->assertSame([], json_decode((string) $history->getContent(), TRUE)['meta']['dataCorrections']);
    $data = $this->send('GET', '/api/admin/applications/' . $application->id() . '/data', $this->viewer);
    $this->assertSame(200, $data->getStatusCode());
    $meta = json_decode((string) $data->getContent(), TRUE)['meta'];
    $this->assertFalse($meta['can_edit_data']);
    $this->assertSame('protected', $meta['edit_data_blocked_reason']);
    $this->assertTrue($meta['protected_data_redacted']);
  }

  /**
   * A jurisdiction editor may correct applications in that jurisdiction.
   */
  public function testCaseWorkerCorrectionInJurisdictionScope(): void {
    $editor = $this->createPersona('reach_jurisdiction_editor', 'case_worker');
    $this->municipalityA->addMember($editor, ['group_roles' => ['jur-admin']]);
    $response = $this->correct($this->applications['a2'], $editor);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $response = $this->correct($this->applications['b1'], $editor);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * The event history pages corrections without losing prior values.
   */
  public function testDataCorrectionHistoryPagination(): void {
    $application = $this->applications['a1'];
    $path = '/api/admin/applications/' . $application->id() . '/data';
    $storage = $this->entityTypeManager()->getStorage('application');
    $revision_ids = [];
    for ($index = 1; $index <= 11; $index++) {
      $response = $this->send('PATCH', $path, $this->editor, [
        'data' => ['message' => 'Entry ' . $index],
        'expected_changed' => $application->getChangedTime(),
        'reason' => 'Compared with the supplied document.',
      ]);
      $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
      $application = $storage->loadUnchanged($application->id());
      $revision_ids[] = (int) $application->getRevisionId();
    }

    $first = $this->send('GET', $this->historyPath($application), $this->editor);
    $this->assertSame(200, $first->getStatusCode(), (string) $first->getContent());
    $page = json_decode((string) $first->getContent(), TRUE)['meta'];
    $this->assertSame(array_reverse(array_slice($revision_ids, 1)), array_column($page['dataCorrections'], 'revisionId'));
    $this->assertSame('Entry 10', $page['dataCorrections'][0]['changes'][0]['oldValue']);
    $this->assertSame($revision_ids[1], $page['dataCorrectionsNextBefore']);

    $older = $this->send('GET', $this->historyPath($application) . '&dataCorrectionsBefore=' . $page['dataCorrectionsNextBefore'], $this->editor);
    $this->assertSame(200, $older->getStatusCode(), (string) $older->getContent());
    $page = json_decode((string) $older->getContent(), TRUE)['meta'];
    $this->assertSame([$revision_ids[0]], array_column($page['dataCorrections'], 'revisionId'));
    $this->assertSame('Hello', $page['dataCorrections'][0]['changes'][0]['oldValue']);
    $this->assertNull($page['dataCorrectionsNextBefore']);
  }

  /**
   * Planned and sent transfers both freeze corrections for all staff.
   */
  public function testDataCorrectionRefusesPlannedAndAcceptedTransfers(): void {
    $application = $this->applications['a1'];
    $path = '/api/admin/applications/' . $application->id() . '/data';
    $database = $this->container->get('database');
    $log_id = $database->insert('beantragt_delivery_log')->fields([
      'uuid' => $this->container->get('uuid')->generate(),
      'application_id' => $application->id(),
      'application_uuid' => $application->uuid(),
      'adapter' => 'mock',
      'status' => DeliveryLogRepository::STATUS_QUEUED,
      'data_hash' => hash('sha256', (string) $application->getData()),
      'created' => 1,
      'changed' => 1,
    ])->execute();
    foreach ([DeliveryLogRepository::STATUS_QUEUED, DeliveryLogRepository::STATUS_ACCEPTED] as $status) {
      $database->update('beantragt_delivery_log')->fields(['status' => $status])->condition('id', $log_id)->execute();
      foreach ([$this->editor, $this->viewer, $this->limited] as $account) {
        $response = $this->correct($application, $account);
        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('application.data_edit_transfer_locked', json_decode((string) $response->getContent(), TRUE)['error']['code']);
        $data = $this->send('GET', $path, $account);
        $this->assertSame(200, $data->getStatusCode());
        $meta = json_decode((string) $data->getContent(), TRUE)['meta'];
        $this->assertFalse($meta['can_edit_data']);
        $this->assertSame('transfer', $meta['edit_data_blocked_reason']);
      }
    }
    $this->assertSame($application->getRevisionId(), $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id())->getRevisionId());
    Role::load('case_worker')->revokePermission('edit application data entities')->save();
    $this->assertSame(403, $this->correct($application, $this->editor)->getStatusCode());
    $data = $this->send('GET', $path, $this->editor);
    $this->assertSame(200, $data->getStatusCode());
    $meta = json_decode((string) $data->getContent(), TRUE)['meta'];
    $this->assertFalse($meta['can_edit_data']);
    $this->assertSame('permission', $meta['edit_data_blocked_reason']);
  }

  /**
   * Sends a data correction with the current timestamp and a reason.
   */
  private function correct(ApplicationInterface $application, UserInterface $account): Response {
    return $this->send('PATCH', '/api/admin/applications/' . $application->id() . '/data', $account, [
      'data' => ['message' => 'Updated count'],
      'expected_changed' => $application->getChangedTime(),
      'reason' => 'Compared with the supplied document.',
    ]);
  }

  /**
   * Returns the internal history request in the fixture workspace.
   */
  private function historyPath(ApplicationInterface $application): string {
    return '/api/internal/staff/applications/' . $application->uuid() . '/events?jurisdiction=reach-a&organisation=reach-a1&includeDataCorrections=1';
  }

  /**
   * Sends a cookie-authenticated application request through the kernel.
   */
  private function send(string $method, string $path, UserInterface $account, ?array $document = NULL): Response {
    if (!$this->routesBuilt) {
      $this->container->get('router.builder')->rebuild();
      $this->routesBuilt = TRUE;
    }
    $request = Request::create($path, $method, server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_ACCEPT' => 'application/json',
    ], content: $document === NULL ? NULL : json_encode($document, JSON_THROW_ON_ERROR));
    $request->setRequestFormat('json');
    $session = new Session(new MockArraySessionStorage());
    $session->set('uid', $account->id());
    $request->setSession($session);
    $options = $this->container->get('session_configuration')->getOptions($request);
    $request->cookies->set($options['name'], $session->getId());
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $current_user = $this->container->get('current_user');
    $previous = $current_user->getAccount();
    $current_user->setAccount(new AnonymousUserSession());
    $this->container->get('router.request_context')->fromRequest($request);
    $this->container->get('router.route_provider')->reset();
    while ($this->container->get('request_stack')->getCurrentRequest()) {
      $this->container->get('request_stack')->pop();
    }
    try {
      return $this->container->get('http_kernel')->handle($request);
    }
    finally {
      $current_user->setAccount($previous);
    }
  }

  /**
   * Asks every real gate of the matrix for one application and one account.
   *
   * @return array<string, bool>
   *   The check name and whether it allowed the account.
   */
  private function reach(ApplicationInterface $application, UserInterface $account): array {
    $this->entityTypeManager()->getAccessControlHandler('application')->resetCache();
    $data = ApplicationDataController::create($this->container);
    $assignment = ApplicationAssignmentController::create($this->container);
    $gate = $this->container->get('beantragt_application.protected_data_gate');
    assert($gate instanceof ProtectedDataGate);

    return [
      'view' => $application->access('view', $account),
      'update' => $application->access('update', $account),
      'delete' => $application->access('delete', $account),
      'data_correction' => $data->access($application, $account)->isAllowed(),
      // The deadline route uses the same requirement as the status route.
      'status_and_deadline' => $data->statusAccess($application, $account)->isAllowed(),
      'reveal_protected' => $gate->access($application, $account)->isAllowed(),
      'assign' => $assignment->assignAccess($application, $account)->isAllowed(),
    ];
  }

  /**
   * The installed roles carry what the gates ask for, so no denial is vacuous.
   */
  private function assertRolePreconditions(): void {
    $shared = [
      'view published application entities',
      'edit any application entities',
      'edit application data entities',
      'manage workflow transitions',
      'assign applications',
    ];
    $department_admin = Role::load('department_admin');
    $case_worker = Role::load('case_worker');
    $this->assertNotNull($department_admin);
    $this->assertNotNull($case_worker);
    foreach ($shared as $permission) {
      $this->assertTrue($department_admin->hasPermission($permission), 'department_admin: ' . $permission);
      $this->assertTrue($case_worker->hasPermission($permission), 'case_worker: ' . $permission);
    }
    $this->assertTrue($department_admin->hasPermission('delete any application entities'));
    $this->assertFalse($case_worker->hasPermission('delete any application entities'));
    foreach ([$department_admin, $case_worker] as $role) {
      $this->assertFalse($role->hasPermission('bypass beantragt group scope'), $role->id() . ' bypasses the scope.');
      $this->assertFalse($role->hasPermission('administer application entities'), $role->id() . ' administers applications.');
    }
  }

  /**
   * Asserts the account's memberships, by group label and group roles.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account whose memberships are compared.
   * @param array<string, list<string>> $expected
   *   Group label => group role IDs.
   */
  private function assertMembershipsAreExactly(UserInterface $account, array $expected): void {
    $actual = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $roles = array_keys($membership->getRoles(FALSE));
      sort($roles);
      $actual[(string) $membership->getGroup()->label()] = $roles;
    }
    ksort($actual);
    ksort($expected);
    $this->assertSame($expected, $actual);
  }

  /**
   * Creates an active account holding one product site role.
   */
  private function createPersona(string $name, string $site_role): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => $name . '@example.org']);
    assert($user instanceof UserInterface);
    $user->addRole($site_role);
    $user->activate()->save();
    return $user;
  }

  /**
   * Creates a municipality (jurisdiction group).
   */
  private function createMunicipality(string $slug, string $label): GroupInterface {
    return $this->createGroup([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
    ]);
  }

  /**
   * Creates an office (organisation group) of one municipality.
   */
  private function createOffice(string $slug, string $label, GroupInterface $municipality): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $label,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $municipality->id()],
    ]);
  }

  /**
   * Creates a published form, its default status and one application.
   *
   * Tests can add a sensitive form to an office with an existing status.
   */
  private function createApplicationIn(GroupInterface $office, bool $sensitive = FALSE): ApplicationInterface {
    $municipality_id = (int) $office->get('field_jurisdiction')->target_id;
    $storage = $this->entityTypeManager();

    if (!$sensitive) {
      $storage->getStorage('workflow_status')->create([
        'label' => 'Eingegangen',
        'field_group' => ['target_id' => $office->id()],
        'field_machine_name' => 'eingegangen',
        'field_color' => '#2563eb',
        'field_icon' => 'i-lucide-inbox',
        'field_weight' => 0,
        'field_is_default' => TRUE,
        'field_is_terminal' => FALSE,
        'field_semantic_flags' => [['value' => 'received']],
      ])->save();
    }

    $schema = $storage->getStorage('form_schema')->create([
      'label' => 'Reach smoke ' . $office->label(),
      'field_schema' => json_encode([
        'title' => 'Reach smoke',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              [
                'id' => 'message',
                'type' => 'text',
                'label' => 'Message',
                'required' => FALSE,
                'sensitive' => $sensitive,
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $municipality_id],
      'field_organisation' => ['target_id' => $office->id()],
      'field_group' => ['target_id' => $office->id()],
      'status' => TRUE,
    ]);
    assert($schema instanceof FormSchemaInterface);
    $this->saveUnderScopeBypass($schema);

    $application = $storage->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['message' => 'Hello'], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'applicant@example.org',
      'field_reference_number' => 'CASE-' . $office->id() . ($sensitive ? '-PROTECTED' : ''),
      'field_jurisdiction' => ['target_id' => $municipality_id],
      'field_organisation' => ['target_id' => $office->id()],
      'field_group' => ['target_id' => $office->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Saves form scaffolding the way trusted tooling does.
   *
   * The form is fixture, not behaviour under test; the base class signs in an
   * account without group roles.
   */
  private function saveUnderScopeBypass(FormSchemaInterface $schema): void {
    $current_user = $this->container->get('current_user');
    $previous = $current_user->getAccount();
    $trusted = $this->createUser(['bypass beantragt group scope']);
    $current_user->setAccount($trusted);
    try {
      // New forms are authored by the account that saves them.
      if ($schema->isNew()) {
        $schema->setOwnerId((int) $trusted->id());
      }
      $schema->save();
    }
    finally {
      $current_user->setAccount($previous);
    }
  }

}
