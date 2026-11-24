<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_delivery\Controller\ApplicationDeliveryController;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\Postbox\PostboxTransportInterface;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Stage 1 of the delivery route on real applications and groups.
 *
 * Provision fixes the effective route per response (decisions by post, no
 * email for postal rows), a pending reply is provided once at release,
 * retrieval counts only the applicant, the postal handover is recorded once,
 * and the staff delivery API is scoped like the transfer routes. Runs
 * without beantragt_docgen: a docgen release is an ingest with source
 * docgen and an explicit decision flag.
 */
#[Group('beantragt_delivery')]
final class DeliveryProvisionKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'options', 'jsonapi', 'json_form_widget',
    'serialization', 'language', 'beantragt_roles_api', 'beantragt_roles',
    'beantragt_schema', 'beantragt_form_schema_group', 'beantragt_applicant',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_workflow', 'beantragt_notification', 'beantragt_delivery',
  ];

  /**
   * Permissions of a case worker for the delivery card.
   */
  private const CASE_WORKER = [
    'view published application entities',
    'view unpublished application entities',
    'edit any application entities',
    'approve application dispatch',
  ];

  /**
   * Response columns: decision flag, route, reason, notification state.
   */
  private const STATE = ['is_decision', 'delivery_route', 'route_reason', 'notification_state'];

  /**
   * Response columns: route, reason, notification state.
   */
  private const ROUTE = ['delivery_route', 'route_reason', 'notification_state'];

  /**
   * Jurisdiction of the cases.
   */
  private GroupInterface $jurisdiction;

  /**
   * Organisation that owns form and cases.
   */
  private GroupInterface $organisation;

  /**
   * The form of every case.
   */
  private FormSchemaInterface $form;

  /**
   * Case worker, member of the organisation.
   */
  private UserInterface $caseWorker;

  /**
   * Case worker of another jurisdiction.
   */
  private UserInterface $foreignWorker;

  /**
   * Citizen who owns the applications.
   */
  private UserInterface $citizen;

  /**
   * The Nuxt service account.
   */
  private UserInterface $serviceAccount;

  /**
   * Response repository on the test database.
   */
  private ExternalResponseRepository $responses;

  /**
   * Previous encryption key of the environment.
   */
  private string|false $previousKey;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('access_manager.check_provider')->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->previousKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('d', 32));
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->manager());
    foreach (['file', 'form_schema', 'workflow_status', 'application', 'application_attachment'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence', ProtectedAccessLog::TABLE]);
    $this->container->get('module_handler')->loadInclude('beantragt_delivery', 'install');
    $this->installSchema('beantragt_delivery', array_keys(beantragt_delivery_schema()));
    $this->responses = new ExternalResponseRepository($this->container->get('database'));
    Role::create(['id' => 'service_account', 'label' => 'Service account'])->save();

    $this->setCurrentUser($this->createUser([
      'bypass beantragt group scope',
      'edit any application entities',
      'view published application entities',
    ]));
    $this->jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Fictional town', 'field_slug' => 'fictional']);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Fictional office',
      'field_slug' => 'office',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $foreign = $this->createGroup(['type' => 'jur', 'label' => 'Other town', 'field_slug' => 'other']);
    $this->caseWorker = $this->createUser(self::CASE_WORKER, 'Clara Clerk');
    // Application writes need a group admin role (beantragt_group).
    $this->organisation->addMember($this->caseWorker, ['group_roles' => ['org-admin']]);
    $this->foreignWorker = $this->createUser(self::CASE_WORKER, 'Fred Foreign');
    $foreign->addMember($this->foreignWorker, ['group_roles' => ['jur-admin']]);
    $this->citizen = $this->createUser([], 'erika', FALSE, ['mail' => 'erika@example.org']);
    $this->serviceAccount = $this->createUser([], 'nuxt_service', FALSE, ['roles' => ['service_account']]);

    $schema = [
      'sections' => [
        [
          'id' => 'main',
          'title' => 'Main',
          'fields' => [['id' => 'name', 'type' => 'text', 'label' => 'Name']],
        ],
      ],
    ];
    $form = $this->manager()->getStorage('form_schema')->create($this->scope() + [
      'label' => 'Fictional parking',
      'field_group' => $this->organisation->id(),
      'field_schema' => json_encode($schema),
    ]);
    $form->save();
    self::assertInstanceOf(FormSchemaInterface::class, $form);
    $this->form = $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->previousKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->previousKey);
    parent::tearDown();
  }

  /**
   * Decisions go by post; communications keep the route, bulk goes by post.
   */
  public function testProvisionFixesTheEffectiveRoute(): void {
    // Exactly one email: the request for information on route portal.
    $service = $this->service(1);
    $portal = $this->application();

    $decision = $service->ingest($portal, 'docgen', 'approval-1', '', 'Bewilligung', [$this->document()], TRUE, outcome: 'approved', isDecision: TRUE);
    self::assertTrue($decision['isDecision']);
    self::assertSame('postal', $decision['deliveryRoute']);
    self::assertSame('postal', $decision['notificationState']);
    $row = $this->row($decision['id']);
    self::assertSame(['1', 'postal', 'decision_postal_only', 'postal'], $this->columns($decision['id'], ...self::STATE));
    self::assertNotNull($row['postal_due_at']);
    self::assertNull($row['notification_sent_at']);

    $request = $service->ingest($portal, 'docgen', 'request-1', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE);
    self::assertFalse($request['isDecision']);
    self::assertSame('portal', $request['deliveryRoute']);
    $row = $this->row($request['id']);
    self::assertSame(['0', 'portal', NULL, 'sent'], $this->columns($request['id'], ...self::STATE));
    self::assertNull($row['postal_due_at']);
    self::assertNotNull($row['notification_sent_at']);

    $postal = $this->application(['field_delivery_route' => 'postal']);
    $row = $this->row($service->ingest($postal, 'docgen', 'request-2', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE)['id']);
    self::assertSame(['postal', 'chosen_postal', 'postal'], $this->columns($row['uuid'], ...self::ROUTE));
    self::assertNotNull($row['postal_due_at']);

    // Bundid reaches an application only through staff; without a postbox
    // transport it goes by post.
    $bundid = $this->application(['field_delivery_route' => 'bundid']);
    $row = $this->row($service->ingest($bundid, 'docgen', 'request-3', '', 'Eingangsbestätigung', [$this->document()], TRUE, isDecision: FALSE)['id']);
    self::assertSame(['postal', 'postbox_unavailable', 'postal'], $this->columns($row['uuid'], ...self::ROUTE));

    // Bulk keeps not_applicable exactly as before, the route says post.
    $bulk = $this->application(['field_origin' => 'bulk', 'uid' => $this->caseWorker->id()]);
    $row = $this->row($service->ingest($bulk, 'docgen', 'request-4', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE)['id']);
    self::assertSame(['postal', 'no_digital_option', 'not_applicable'], $this->columns($row['uuid'], ...self::ROUTE));
    self::assertNotNull($row['postal_due_at']);

    // The applicant's list carries the decision flag and the route.
    $citizen = $service->listForApplication($portal, FALSE);
    $by_title = array_column($citizen, NULL, 'title');
    self::assertSame('portal', $by_title['Nachforderung']['deliveryRoute']);
    self::assertFalse($by_title['Nachforderung']['isDecision']);
    self::assertSame('postal', $by_title['Bewilligung']['deliveryRoute']);
    self::assertTrue($by_title['Bewilligung']['isDecision']);
  }

  /**
   * A pending reply is a decision and is provided once, at release.
   */
  public function testPendingReplyIsProvidedOnceAtRelease(): void {
    // One email: the communication released on route portal.
    $service = $this->service(1);
    $application = $this->application();

    $reply = $service->ingest($application, 'fit_connect', 'reply-1', 'case-1', 'Bescheid', [$this->document()]);
    self::assertSame('pending', $reply['status']);
    self::assertSame('', $reply['deliveryRoute']);
    self::assertSame(['1', '', NULL, 'pending'], $this->columns($reply['id'], ...self::STATE));
    self::assertNull($this->row($reply['id'])['postal_due_at']);

    $released = $service->release($application, $reply['id']);
    self::assertSame('released', $released['status']);
    self::assertSame(['postal', 'decision_postal_only', 'postal'], $this->columns($reply['id'], ...self::ROUTE));
    $due = $this->row($reply['id'])['postal_due_at'];
    self::assertNotNull($due);

    // A later route change applies to future provisions only: the
    // second release changes nothing and announces nothing.
    $this->setRoute($application, 'portal', 'postal');
    $again = $service->release($application, $reply['id']);
    self::assertSame('postal', $again['deliveryRoute']);
    self::assertSame(['postal', 'decision_postal_only', 'postal'], $this->columns($reply['id'], ...self::ROUTE));
    self::assertSame($due, $this->row($reply['id'])['postal_due_at']);
    self::assertSame(0, $this->responses->release($reply['id'], 1, ['delivery_route' => 'portal']));

    // A communication released twice announces once.
    $this->setRoute($application, 'postal', 'portal');
    $communication = $service->ingest($application, 'fit_connect', 'reply-2', 'case-1', 'Eingangsbestätigung', [$this->document()], isDecision: FALSE);
    $service->release($application, $communication['id']);
    $service->release($application, $communication['id']);
    self::assertSame(['portal', NULL, 'sent'], $this->columns($communication['id'], ...self::ROUTE));

    // A filing receipt is never a decision.
    $receipt = $service->ingest($application, 'fit_connect', 'reply-3', 'case-1', 'Ablagequittung', [$this->document()], outcome: 'filed');
    self::assertSame('0', (string) $this->row($receipt['id'])['is_decision']);
  }

  /**
   * Only the positively identified applicant's download is retrieval.
   */
  public function testRetrievalCountsOnlyTheApplicant(): void {
    // Two emails: the bulk case announces none.
    $service = $this->service(2);
    $application = $this->application();
    $response = $service->ingest($application, 'docgen', 'request-1', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE);
    $file = $response['documents'][0]['id'];

    $service->download($application, $response['id'], $file, TRUE, $this->caseWorker);
    self::assertNull($this->row($response['id'])['retrieved']);
    $service->download($application, $response['id'], $file, FALSE, $this->citizen);
    $retrieved = $this->row($response['id'])['retrieved'];
    self::assertNotNull($retrieved);

    // Public submissions may be owned by the service account; its download
    // proxies someone else.
    $proxied = $this->application(['uid' => $this->serviceAccount->id()]);
    $response = $service->ingest($proxied, 'docgen', 'request-2', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE);
    $service->download($proxied, $response['id'], $response['documents'][0]['id'], FALSE, $this->serviceAccount);
    self::assertNull($this->row($response['id'])['retrieved']);

    // A bulk case has no applicant account; its owner is the uploader.
    $bulk = $this->application(['field_origin' => 'bulk', 'uid' => $this->caseWorker->id()]);
    $response = $service->ingest($bulk, 'docgen', 'request-3', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE);
    $service->download($bulk, $response['id'], $response['documents'][0]['id'], FALSE, $this->caseWorker);
    self::assertNull($this->row($response['id'])['retrieved']);

    // Staff GET shows the applicant retrieval of the first case.
    $documents = $this->viewAs($this->caseWorker, $application)['data']['documents'];
    self::assertSame((int) $retrieved, $documents[0]['retrievedAt']);
    self::assertNotNull($documents[0]['notificationSentAt']);
  }

  /**
   * The handover is recorded with time and person, never overwritten.
   */
  public function testPostalHandoverIsRecordedOnce(): void {
    $service = $this->service(1);
    $application = $this->application();
    $decision = $service->ingest($application, 'docgen', 'approval-1', '', 'Bewilligung', [$this->document()], TRUE, outcome: 'approved', isDecision: TRUE);
    $portal = $service->ingest($application, 'docgen', 'request-1', '', 'Nachforderung', [$this->document()], TRUE, isDecision: FALSE);
    $pending = $service->ingest($application, 'fit_connect', 'reply-1', 'case-1', 'Bescheid', [$this->document()]);

    $this->setCurrentUser($this->caseWorker);
    $controller = ApplicationDeliveryController::create($this->container);
    $response = $controller->handOver($application, $decision['id'], Request::create('/', 'POST'));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $documents = array_column($this->json($response)['data']['documents'], NULL, 'responseId');
    self::assertSame('Clara Clerk', $documents[$decision['id']]['postalHandedOverByName']);
    self::assertNotNull($documents[$decision['id']]['postalHandedOverAt']);
    self::assertSame((string) $this->caseWorker->id(), (string) $this->row($decision['id'])['postal_handed_over_by']);
    // The pending reply is not provided and not listed.
    self::assertArrayNotHasKey($pending['id'], $documents);
    self::assertStringNotContainsString('"postal_handed_over_by"', (string) $response->getContent());
    self::assertStringNotContainsString('byUid', (string) $response->getContent());

    $first = $this->row($decision['id'])['postal_handed_over_at'];
    $this->assertError($controller->handOver($application, $decision['id'], Request::create('/', 'POST')), 409, 'delivery.already_handed_over');
    self::assertSame($first, $this->row($decision['id'])['postal_handed_over_at']);
    $this->assertError($controller->handOver($application, $portal['id'], Request::create('/', 'POST')), 409, 'delivery.not_postal');
    $this->assertError($controller->handOver($application, $pending['id'], Request::create('/', 'POST')), 409, 'delivery.not_postal');
    $this->assertError($controller->handOver($application, '00000000-0000-4000-8000-000000000000', Request::create('/', 'POST')), 404, 'delivery.not_found');
    // A body is refused; the handover takes none.
    $this->assertError($controller->handOver($application, $decision['id'], Request::create('/', 'POST', [], [], [], [], '{"at":1}')), 422, 'delivery.input_invalid');
  }

  /**
   * The staff GET is scoped like the transfer routes.
   */
  public function testStaffViewIsScoped(): void {
    $this->service(0);
    $application = $this->application(DeliveryRoute::createValues(DeliveryRoute::choose(NULL, 'postal', 'public_submit'), 1762500000));

    self::assertTrue($this->routeAccess('beantragt_delivery.application_delivery_view', $application, $this->caseWorker));
    self::assertFalse($this->routeAccess('beantragt_delivery.application_delivery_view', $application, $this->citizen));
    self::assertFalse($this->routeAccess('beantragt_delivery.application_delivery_view', $application, $this->foreignWorker));
    self::assertFalse($this->routeAccess('beantragt_delivery.application_delivery_view', $application, new AnonymousUserSession()));
    self::assertTrue($this->routeAccess('beantragt_delivery.application_delivery_change', $application, $this->caseWorker));
    self::assertFalse($this->routeAccess('beantragt_delivery.application_delivery_change', $application, $this->foreignWorker));
    self::assertFalse($this->routeAccess('beantragt_delivery.application_delivery_postal_handover', $application, $this->citizen));

    $data = $this->viewAs($this->caseWorker, $application)['data'];
    self::assertSame('portal', $data['route']);
    self::assertSame('form_default', $data['source']);
    self::assertSame(1762500000, $data['chosenAt']);
    self::assertSame([], $data['offered']);
    // Without docgen the options are portal and post.
    self::assertSame(['portal', 'postal'], $data['routeOptions']);
    self::assertTrue($data['canEdit']);
    self::assertSame(['route' => 'postal', 'reason' => 'decision_postal_only'], $data['preview']['decision']);
    self::assertSame(['route' => 'portal', 'reason' => NULL], $data['preview']['communication']);
    self::assertSame([
      [
        'event' => 'route_chosen',
        'at' => 1762500000,
        'receivedAt' => NULL,
        'route' => 'portal',
        'source' => 'form_default',
        'reason' => 'not_configured',
        'channel' => NULL,
        'byName' => NULL,
      ],
    ], $data['events']);
    self::assertSame([], $data['documents']);

    $this->setCurrentUser($this->foreignWorker);
    self::assertSame(403, ApplicationDeliveryController::create($this->container)->view($application)->getStatusCode());
    $this->setCurrentUser($this->citizen);
    self::assertSame(403, ApplicationDeliveryController::create($this->container)->view($application)->getStatusCode());
  }

  /**
   * Staff change the route on request; everything else is refused.
   */
  public function testStaffRouteChange(): void {
    $this->service(0);
    $offer = ['configured' => TRUE, 'routes' => ['portal', 'postal'], 'default' => 'portal'];
    $application = $this->application(DeliveryRoute::createValues(DeliveryRoute::choose($offer, NULL, 'public_submit'), 1762500000));
    $created = (int) $application->getCreatedTime();
    $this->setCurrentUser($this->caseWorker);
    $controller = ApplicationDeliveryController::create($this->container);

    foreach ([time() + 3600, $created - 10, '1762500000'] as $received_at) {
      $body = ['route' => 'postal', 'receivedAt' => $received_at, 'channel' => 'letter'];
      $this->assertError($controller->change($application, $this->post($body)), 422, 'delivery.input_invalid', 'receivedAt');
    }
    $this->assertError($controller->change($application, $this->post(['route' => 'bundid', 'channel' => 'letter'])), 422, 'delivery.input_invalid', 'route');
    $this->assertError($controller->change($application, $this->post(['route' => 'postal', 'channel' => 'fax'])), 422, 'delivery.input_invalid', 'channel');
    $body = ['route' => 'postal', 'channel' => 'letter', 'note' => 'x'];
    $this->assertError($controller->change($application, $this->post($body)), 422, 'delivery.input_invalid');
    self::assertSame('portal', $this->reload($application)->getDeliveryRoute());

    $body = ['route' => 'postal', 'receivedAt' => $created, 'channel' => 'letter'];
    $response = $controller->change($application, $this->post($body));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = $this->json($response)['data'];
    self::assertTrue($data['changed']);
    self::assertSame('postal', $data['route']);
    self::assertSame('staff', $data['source']);
    self::assertSame(['route' => 'postal', 'reason' => 'chosen_postal'], $data['preview']['communication']);
    self::assertSame([
      'event' => 'route_changed',
      'receivedAt' => $created,
      'route' => 'postal',
      'source' => 'staff',
      'reason' => NULL,
      'channel' => 'letter',
      'byName' => 'Clara Clerk',
    ], array_diff_key($data['events'][1], ['at' => TRUE]));
    $stored = $this->reload($application);
    self::assertSame('postal', $stored->getDeliveryRoute());
    $event = $stored->getDeliveryRecord()[1];
    self::assertSame([
      'event' => 'route_changed',
      'receivedAt' => $created,
      'route' => 'postal',
      'previous' => 'portal',
      'source' => 'staff',
      'byUid' => (int) $this->caseWorker->id(),
      'channel' => 'letter',
    ], array_diff_key($event, ['at' => TRUE]));
    self::assertSame((int) $this->caseWorker->id(), (int) $stored->getRevisionUserId());
    $revision = (int) $stored->getRevisionId();

    // The same route again writes nothing.
    $data = $this->json($controller->change($stored, $this->post(['route' => 'postal', 'channel' => 'phone'])))['data'];
    self::assertFalse($data['changed']);
    self::assertCount(2, $this->reload($application)->getDeliveryRecord());
    self::assertSame($revision, (int) $this->reload($application)->getRevisionId());

    // The record holds at most RECORD_LIMIT events.
    $full = $this->reload($application);
    $events = $full->getDeliveryRecord();
    while (count($events) < DeliveryRoute::RECORD_LIMIT) {
      $events[] = $events[1];
    }
    $full->set('field_delivery_record', json_encode($events))->save();
    $this->assertError($controller->change($full, $this->post(['route' => 'portal', 'channel' => 'email'])), 409, 'delivery.record_full');
    self::assertSame('postal', $this->reload($application)->getDeliveryRoute());

    // A change toward post is never refused, not even on a full record. The
    // record then holds one event above the cap and refuses changes away.
    $this->reload($application)->set('field_delivery_route', 'portal')->save();
    $body = ['route' => 'postal', 'channel' => 'letter'];
    $response = $controller->change($this->reload($application), $this->post($body));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertTrue($this->json($response)['data']['changed']);
    $stored = $this->reload($application);
    self::assertSame('postal', $stored->getDeliveryRoute());
    $record = $stored->getDeliveryRecord();
    self::assertCount(DeliveryRoute::RECORD_LIMIT + 1, $record);
    $last = $record[DeliveryRoute::RECORD_LIMIT];
    self::assertSame(['postal', 'portal', 'letter'], [$last['route'], $last['previous'], $last['channel']]);
    $this->assertError($controller->change($stored, $this->post(['route' => 'portal', 'channel' => 'phone'])), 409, 'delivery.record_full');
    // A POST that changes nothing adds no event, also above the cap.
    self::assertFalse($this->json($controller->change($stored, $this->post(['route' => 'postal', 'channel' => 'phone'])))['data']['changed']);
    self::assertCount(DeliveryRoute::RECORD_LIMIT + 1, $this->reload($application)->getDeliveryRecord());
    self::assertSame('postal', $this->reload($application)->getDeliveryRoute());

    foreach (['field_delivery_route', 'field_delivery_record'] as $field) {
      self::assertFalse($this->reload($application)->get($field)->access('edit', $this->caseWorker));
    }
    self::assertFalse($this->reload($application)->get('field_delivery_record')->access('view', $this->caseWorker));
    self::assertTrue($this->reload($application)->get('field_delivery_route')->access('view', $this->caseWorker));
  }

  /**
   * A JSON:API PATCH by a case worker never writes route or record.
   *
   * Request level, as JSON:API runs with read_only FALSE in production. The
   * pointer proves the field denial, not entity access, refused the PATCH.
   */
  public function testJsonApiPatchCannotWriteTheDeliveryFields(): void {
    $this->service(0);
    $offer = ['configured' => TRUE, 'routes' => ['portal', 'postal'], 'default' => 'portal'];
    $application = $this->application(DeliveryRoute::createValues(DeliveryRoute::choose($offer, 'postal', 'public_submit'), 1762500000));
    $stored = $this->reload($application);
    self::assertSame('postal', $stored->getDeliveryRoute());
    $record = (string) $stored->get('field_delivery_record')->value;
    $revision = (int) $stored->getRevisionId();
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->caseWorker->setPassword('synthetic-delivery-password')->save();
    $this->container->get('router.builder')->rebuild();

    // A changed route, a forged record and the unchanged record: the record
    // is not viewable, so even its stored value earns no silent skip.
    $forged = json_encode([['event' => 'route_chosen', 'at' => 1, 'route' => 'portal', 'source' => 'staff']], JSON_THROW_ON_ERROR);
    foreach ([
      ['field_delivery_route', 'portal'],
      ['field_delivery_record', $forged],
      ['field_delivery_record', $record],
    ] as [$field, $value]) {
      $body = [
        'data' => [
          'type' => 'application--application',
          'id' => $application->uuid(),
          'attributes' => [$field => $value],
        ],
      ];
      $request = Request::create('/jsonapi/application/application/' . $application->uuid(), 'PATCH', server: [
        'HTTP_ACCEPT' => 'application/vnd.api+json',
        'CONTENT_TYPE' => 'application/vnd.api+json',
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($this->caseWorker->getAccountName() . ':synthetic-delivery-password'),
        'PHP_AUTH_USER' => $this->caseWorker->getAccountName(),
        'PHP_AUTH_PW' => 'synthetic-delivery-password',
      ], content: json_encode($body, JSON_THROW_ON_ERROR));
      $response = $this->container->get('http_kernel')->handle($request);
      self::assertSame(403, $response->getStatusCode(), $field . ': ' . $response->getContent());
      $error = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['errors'][0];
      self::assertSame('/data/attributes/' . $field, $error['source']['pointer'] ?? NULL, (string) $response->getContent());
    }

    $stored = $this->reload($application);
    self::assertSame('postal', $stored->getDeliveryRoute());
    self::assertSame($record, (string) $stored->get('field_delivery_record')->value);
    self::assertCount(1, $stored->getDeliveryRecord());
    self::assertSame($revision, (int) $stored->getRevisionId());
  }

  /**
   * Both staff POSTs answer delivery.locked while another mutation runs.
   *
   * The lock is non-blocking; a held lock is a retry, never an input error,
   * and nothing is written.
   */
  public function testHeldLockAnswersLocked(): void {
    $database = $this->container->get('database');
    // Kernel tests register a null lock; this case needs real database locks.
    $this->container->set('beantragt_application.dependent_mutation_lock', new ApplicationDependentMutationLock($this->manager(), new DatabaseLockBackend($database), $database));
    $service = $this->service(0);
    $application = $this->application();
    $decision = $service->ingest($application, 'docgen', 'approval-1', '', 'Bewilligung', [$this->document()], TRUE, outcome: 'approved', isDecision: TRUE);
    $this->setCurrentUser($this->caseWorker);
    $controller = ApplicationDeliveryController::create($this->container);

    $other = new DatabaseLockBackend($database);
    $name = Application::deletionLockName((int) $application->id());
    self::assertTrue($other->acquire($name, 60.0));
    try {
      $this->assertError($controller->change($application, $this->post(['route' => 'postal', 'channel' => 'letter'])), 409, 'delivery.locked');
      $this->assertError($controller->handOver($application, $decision['id'], Request::create('/', 'POST')), 409, 'delivery.locked');
    }
    finally {
      $other->release($name);
    }
    self::assertSame('portal', $this->reload($application)->getDeliveryRoute());
    self::assertNull($this->row($decision['id'])['postal_handed_over_at']);

    // Once the other mutation is done, the handover goes through.
    $response = $controller->handOver($application, $decision['id'], Request::create('/', 'POST'));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertNotNull($this->row($decision['id'])['postal_handed_over_at']);

    // A storage failure is no lock: it propagates as a logged 500 and never
    // reads as "locked" (nor as an input error in the UI).
    $second = $service->ingest($application, 'docgen', 'rejection-1', '', 'Ablehnung', [$this->document()], TRUE, outcome: 'rejected', isDecision: TRUE);
    $database->schema()->dropField('beantragt_external_response', 'postal_handed_over_by');
    $this->expectException(DatabaseExceptionWrapper::class);
    $controller->handOver($application, $second['id'], Request::create('/', 'POST'));
  }

  /**
   * Builds the response service with real storage and counted emails.
   */
  private function service(int $mails, bool $postbox = FALSE): ExternalResponseService {
    $scanner = $this->createMock(AttachmentScanServiceInterface::class);
    $scanner->method('scan')->willReturn(new AttachmentScanResult(AttachmentScanResult::STATUS_CLEAN));
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects(self::exactly($mails))->method('sendCitizenNotificationWithResult')->willReturn(TRUE);
    $transport = new class($postbox) implements PostboxTransportInterface {

      /**
       * Constructs the transport double.
       */
      public function __construct(private readonly bool $available) {}

      /**
       * {@inheritdoc}
       */
      public function available(?GroupInterface $scope): bool {
        return $this->available;
      }

    };
    $service = new ExternalResponseService(
      $this->responses,
      $this->manager(),
      $this->container->get('file_system'),
      $this->container->get('file.repository'),
      $scanner,
      $this->container->get('beantragt_application.dependent_mutation_lock'),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
      $mailer,
      NULL,
      $this->container->get('beantragt_application.data_protection'),
      NULL,
      $transport,
    );
    $this->container->set('beantragt_delivery.external_response_service', $service);
    return $service;
  }

  /**
   * Creates an application of the citizen in the organisation.
   */
  private function application(array $values = []): ApplicationInterface {
    $application = $this->manager()->getStorage('application')->create($values + $this->scope() + [
      'uid' => $this->citizen->id(),
      'field_group' => $this->organisation->id(),
      'field_schema' => $this->form->id(),
      'field_schema_revision_id' => $this->form->getRevisionId(),
      'field_schema_version' => $this->form->getVersion(),
      'field_data' => '{"name":"Erika"}',
      'field_email_confirmed' => TRUE,
    ]);
    $application->save();
    self::assertInstanceOf(ApplicationInterface::class, $application);
    return $application;
  }

  /**
   * Stores a route directly, as the staff API would.
   */
  private function setRoute(ApplicationInterface $application, string $expected, string $route): void {
    $current = $this->reload($application);
    self::assertSame($expected, $current->getDeliveryRoute());
    $current->set('field_delivery_route', $route)->save();
  }

  /**
   * Loads an application past the entity cache.
   */
  private function reload(ApplicationInterface $application): ApplicationInterface {
    $storage = $this->manager()->getStorage('application');
    $storage->resetCache([$application->id()]);
    $loaded = $storage->load($application->id());
    self::assertInstanceOf(ApplicationInterface::class, $loaded);
    return $loaded;
  }

  /**
   * A response row.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function row(string $uuid): array {
    $row = $this->responses->find(['uuid' => $uuid]);
    self::assertIsArray($row);
    return $row;
  }

  /**
   * Selected columns of a response row in order (decision flag as string).
   *
   * @return array<int, mixed>
   *   Column values.
   */
  private function columns(string $uuid, string ...$names): array {
    $row = $this->row($uuid);
    return array_map(static fn (string $name): mixed => $name === 'is_decision' ? (string) $row[$name] : $row[$name], $names);
  }

  /**
   * The staff GET payload as an account.
   *
   * @return array<string, mixed>
   *   Decoded body.
   */
  private function viewAs(AccountInterface $account, ApplicationInterface $application): array {
    $this->setCurrentUser($account);
    $response = ApplicationDeliveryController::create($this->container)->view($this->reload($application));
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    return $this->json($response);
  }

  /**
   * Asserts an error envelope.
   */
  private function assertError(JsonResponse $response, int $status, string $code, ?string $field = NULL): void {
    self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
    $error = $this->json($response)['error'];
    self::assertSame($code, $error['code']);
    if ($field !== NULL) {
      self::assertSame($field, $error['details']['field'] ?? NULL);
    }
  }

  /**
   * A JSON POST request.
   */
  private function post(array $body): Request {
    return Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The body.
   */
  private function json(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Whether the production route requirements let an account in.
   */
  private function routeAccess(string $name, ApplicationInterface $application, AccountInterface $account): bool {
    $path = $this->container->get('extension.list.module')->getPath('beantragt_delivery');
    $definition = Yaml::decode((string) file_get_contents($path . '/beantragt_delivery.routing.yml'))[$name];
    $route = new Route($definition['path'], $definition['defaults'], $definition['requirements'], $definition['options'], '', [], $definition['methods']);
    $routes = new RouteCollection();
    $routes->add($name, $route);
    $this->container->get('access_manager.check_provider')->setChecks($routes);
    $parameters = ['application' => $this->reload($application)];
    if (str_contains($definition['path'], '{response_id}')) {
      $parameters['response_id'] = '00000000-0000-4000-8000-000000000000';
    }
    $request_path = strtr($definition['path'], [
      '{application}' => (string) $application->id(),
      '{response_id}' => '00000000-0000-4000-8000-000000000000',
    ]);
    $request = Request::create($request_path, $definition['methods'][0]);
    $request->attributes->add($parameters + $route->getDefaults() + ['_route' => $name, '_route_object' => $route]);
    $this->setCurrentUser($account);
    return $this->container->get('access_manager')->check(RouteMatch::createFromRequest($request), $account, $request);
  }

  /**
   * Organisation and jurisdiction of every entity.
   *
   * @return array<string, int|string|null>
   *   Field values.
   */
  private function scope(): array {
    return ['field_jurisdiction' => $this->jurisdiction->id(), 'field_organisation' => $this->organisation->id()];
  }

  /**
   * Entity type manager.
   */
  private function manager(): EntityTypeManagerInterface {
    return $this->container->get('entity_type.manager');
  }

  /**
   * A valid PDF document payload.
   *
   * @return array<string, string>
   *   Document.
   */
  private function document(): array {
    $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    return [
      'bytes' => $bytes,
      'filename' => 'bescheid.pdf',
      'mimeType' => 'application/pdf',
      'sha512' => hash('sha512', $bytes),
    ];
  }

}
