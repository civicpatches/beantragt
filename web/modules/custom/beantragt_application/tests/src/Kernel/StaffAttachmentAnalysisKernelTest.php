<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_application\Controller\PublicApplicationApiController;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\beantragt_application\Service\ApplicationMessageReadState;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher;
use Drupal\beantragt_application\Service\AttachmentScanService;
use Drupal\beantragt_application\Service\DocumentGuardClientInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Exercises online attachment claims, the real queue and protected results.
 */
#[Group('beantragt_application')]
final class StaffAttachmentAnalysisKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'beantragt_schema', 'beantragt_workflow',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * Original environment settings.
   *
   * @var array<string, string|false>
   */
  private array $originalEnv = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    foreach ([
      'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' => str_repeat('k', 32),
      'BEANTRAGT_INTELLIGENCE_SERVICE_KEY' => 'test-intelligence',
      'BEANTRAGT_NUXT_INTERNAL_BASE_URL' => 'http://worker.invalid',
      'NUXT_DRUPAL_SERVICE_USER' => 'nuxt_service',
      'NUXT_DRUPAL_SERVICE_PASSWORD' => 'test-secret',
    ] as $name => $value) {
      $this->originalEnv[$name] = getenv($name);
      putenv($name . '=' . $value);
    }
    parent::setUp();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    $this->container->set('beantragt_application.attachment_scanner', new AttachmentScanService());
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    foreach (['file', 'form_schema', 'workflow_status', 'application', 'application_attachment'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_application', [
      'beantragt_ref_sequence',
      ProtectedAccessLog::TABLE,
      ApplicationMessageReadState::TABLE,
      ApplicationEventReadState::RECEIPTS_TABLE,
    ]);
    $queue = $this->container->get('queue')->get(AttachmentAnalysisDispatcher::QUEUE_NAME);
    $queue->createItem([]);
    $queue->deleteItem($queue->claimItem());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->originalEnv as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * A later publication cannot loosen or tighten existing attachment access.
   */
  public function testAttachmentVisibilityFollowsSubmittedRevision(): void {
    $editor = $this->createUser(['bypass beantragt group scope', 'administer application entities']);
    $this->setCurrentUser($editor);
    $jurisdiction = $this->createGroup(['type' => 'jur']);
    $office = $this->createGroup(['type' => 'org', 'field_jurisdiction' => $jurisdiction->id()]);
    $reader = $this->createUser(['view published application entities', 'view application_attachment entities']);
    $office->addMember($reader, ['group_roles' => ['org-member']]);
    $scope = [
      'field_jurisdiction' => $jurisdiction->id(),
      'field_organisation' => $office->id(),
      'field_group' => $office->id(),
    ];
    $schema = [
      'title' => 'Attachment visibility',
      'sections' => [
        [
          'id' => 'main',
          'title' => 'Main',
          'fields' => [
            ['id' => 'private_proof', 'type' => 'file', 'label' => 'Private proof', 'sensitive' => TRUE],
            ['id' => 'ordinary_proof', 'type' => 'file', 'label' => 'Ordinary proof', 'sensitive' => FALSE],
          ],
        ],
      ],
    ];
    $manager = $this->entityTypeManager();
    $form = $manager->getStorage('form_schema')->create($scope + [
      'label' => $schema['title'],
      'status' => TRUE,
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
    ]);
    $form->save();
    $applications = $manager->getStorage('application');
    $application = $applications->create($scope + [
      'field_schema' => $form->id(),
      'field_email_confirmed' => TRUE,
      'status' => TRUE,
    ]);
    $application->save();
    $pin = (int) $application->get('field_schema_revision_id')->value;
    $this->assertSame((int) $form->getRevisionId(), $pin);
    $file = $manager->getStorage('file')->create(['uri' => 'private://fixture.pdf', 'status' => TRUE]);
    $file->save();
    $storage = $manager->getStorage('application_attachment');
    $attachments = [];
    foreach (['private_proof', 'ordinary_proof'] as $field_id) {
      $attachment = $storage->create([
        'field_application' => $application->id(),
        'field_field_id' => $field_id,
        'field_file' => $file->id(),
      ]);
      $attachment->save();
      $attachments[$field_id] = $attachment->id();
    }
    $this->container->get('router.builder')->rebuild();
    $controller = ApplicationAttachmentApiController::create($this->container);

    foreach ([FALSE, TRUE] as $republished) {
      if ($republished) {
        $this->setCurrentUser($editor);
        $schema['sections'][0]['fields'][0]['sensitive'] = FALSE;
        $schema['sections'][0]['fields'][1]['sensitive'] = TRUE;
        $form->set('field_workflow_status', 'draft')->save();
        $form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR))->save();
        $form->set('field_workflow_status', 'published')->save();
        $this->assertNotSame($pin, (int) $form->getRevisionId());
      }
      $application = $applications->loadUnchanged($application->id());
      $this->assertSame($pin, (int) $application->get('field_schema_revision_id')->value);
      $manager->getAccessControlHandler('application_attachment')->resetCache();
      $this->setCurrentUser($reader);
      $response = $controller->listApplicationAttachments($application);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame(['ordinary_proof'], array_column($this->body($response)['data'], 'fieldId'));
      $this->assertFalse($storage->loadUnchanged($attachments['private_proof'])->access('view', $reader));
      $this->assertTrue($storage->loadUnchanged($attachments['ordinary_proof'])->access('view', $reader));
    }
  }

  /**
   * Covers divergence, match and insufficient evidence on two schemas.
   *
   * @return array<string, array{array<string, array{string, int, int|null, string}>, string}>
   *   Result definitions, submitted answers, extracted values and states.
   */
  public static function comparisons(): array {
    return [
      'fictional quotation' => [
        [
          'document_area' => ['reported_area', 48, 28, 'mismatch'],
          'document_total' => ['reported_total', 4850, 3805, 'mismatch'],
        ],
        'needs_review',
      ],
      'different schema with a match' => [
        ['document_limit' => ['reported_limit', 1200, 1200, 'match']],
        'complete',
      ],
      'different schema with unreadable proof' => [
        ['document_limit' => ['reported_limit', 1200, NULL, 'no_value']],
        'needs_review',
      ],
    ];
  }

  /**
   * Follows an online submission through claim, dispatch and authorized read.
   */
  #[DataProvider('comparisons')]
  public function testOnlineStaffResult(array $expectations, string $expected_state): void {
    $setup_account = $this->createUser(['bypass beantragt group scope', 'administer application entities']);
    $citizen = $this->createUser(['view own application entities']);
    $this->setCurrentUser($setup_account);
    $workspace = $this->createGroup(['type' => 'jur', 'label' => 'Test jurisdiction']);
    $other_workspace = $this->createGroup(['type' => 'jur', 'label' => 'Other jurisdiction']);
    $office = $this->createGroup(['type' => 'org', 'field_jurisdiction' => $workspace->id()]);
    Role::create([
      'id' => 'case_worker',
      'label' => 'Case worker',
      'permissions' => ['view published application entities'],
    ])->save();
    $staff = $this->createUser(['view protected application data']);
    $staff->addRole('case_worker')->save();
    $restricted = $this->createUser();
    $restricted->addRole('case_worker')->save();
    $foreign_staff = $this->createUser(['view protected application data']);
    $foreign_staff->addRole('case_worker')->save();
    $office->addMember($staff, ['group_roles' => ['org-member']]);
    $office->addMember($restricted, ['group_roles' => ['org-member']]);
    $other_workspace->addMember($foreign_staff, ['group_roles' => ['jur-admin']]);
    $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Received',
      'field_group' => ['target_id' => $office->id()],
      'field_machine_name' => 'received',
      'field_is_default' => TRUE,
      'field_semantic_flags' => [['value' => 'received']],
    ])->save();

    $properties = ['recognised_proof' => ['type' => 'boolean', 'nonPersonal' => TRUE]];
    $fields = [];
    $answers = [];
    $values = [];
    $comparisons = [];
    foreach ($expectations as $key => [$field_id, $reported, $extracted, $state]) {
      $fields[] = ['id' => $field_id, 'type' => 'number', 'label' => $field_id, 'sensitive' => TRUE];
      $properties[$key] = ['type' => 'number', 'purposeBound' => TRUE, 'compareWith' => $field_id];
      $answers[$field_id] = $reported;
      $values[] = ['key' => $key, 'type' => 'number', 'value' => $extracted];
      $comparisons[] = [
        'key' => $key,
        'fieldId' => $field_id,
        'state' => $state,
        'extracted' => $extracted,
        'reported' => $reported,
      ];
    }
    $fields[] = [
      'id' => 'proof',
      'type' => 'file',
      'label' => 'Evidence',
      'sensitive' => TRUE,
      'analysis' => [
        'enabled' => TRUE,
        'citizenPrompt' => 'Check the document type only.',
        'staffPrompt' => 'Read the configured measurements without the party or validity.',
        'redaction' => 'none',
        'egressPolicy' => 'sovereign_only',
        'modelClass' => 'openai_compatible',
        'resultSchema' => ['type' => 'object', 'properties' => $properties],
      ],
    ];
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Synthetic online form',
      'status' => TRUE,
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_jurisdiction' => $workspace->id(),
      'field_organisation' => $office->id(),
      'field_group' => $office->id(),
      'field_schema' => json_encode([
        'title' => 'Synthetic online form',
        'sections' => [['id' => 'main', 'title' => 'Main', 'fields' => $fields]],
      ], JSON_THROW_ON_ERROR),
    ]);
    $form->save();

    $pdf = "%PDF-1.4\nSynthetic Quote: Example Testperson, 28 m2, EUR 3805, valid through 2026-02-28.\n%%EOF\n";
    $page = "\xFF\xD8\xFF\xE0synthetic rendered page";
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->expects($this->once())->method('renderPages')->with($pdf)->willReturn([
      'pages' => [['mediaType' => 'image/jpeg', 'data' => $page]],
      'pageCount' => 1,
      'truncated' => FALSE,
    ]);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturnCallback(
      function (string $method, string $url, array $options) use ($form, $page, $values): Response {
        $this->assertSame('POST', $method);
        $this->assertSame('http://worker.invalid/api/internal/attachments/analyze', $url);
        $payload = $options['json'];
        $this->assertSame('staff', $payload['story']);
        $this->assertSame($form->uuid(), $payload['formId']);
        $this->assertSame('proof', $payload['fieldId']);
        $this->assertSame([['mediaType' => 'image/jpeg', 'data' => base64_encode($page)]], $payload['pages']);
        $this->assertArrayNotHasKey('data', $payload);
        $response = ApplicationAttachmentApiController::create($this->container)->updateAttachmentAnalysis(
          $payload['attachmentId'],
          $this->jsonRequest([
            'status' => 'complete',
            'story' => 'staff',
            'formId' => $payload['formId'],
            'fieldId' => 'proof',
            'analysisRunId' => $payload['analysisRunId'],
            'metadata' => [
              'result' => [
                'outcome' => 'unknown',
                'extracted' => [...$values, ['key' => 'party', 'value' => 'Example Testperson']],
              ],
            ],
          ]),
        );
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringNotContainsString('extracted', (string) $response->getContent());
        return new Response(200);
      },
    );
    $this->container->set('beantragt_application.document_guard_client', $guard);
    $this->container->set('http_client', $http);
    $controller = ApplicationAttachmentApiController::create($this->container);
    $this->setCurrentUser($citizen);
    $path = tempnam(sys_get_temp_dir(), 'staff-proof-');
    $this->assertIsString($path);
    try {
      $this->assertSame(strlen($pdf), file_put_contents($path, $pdf));
      $upload = $controller->uploadAttachment(Request::create(
        '/api/v1/attachments',
        'POST',
        ['formId' => $form->uuid(), 'fieldId' => 'proof', 'ownerUuid' => $citizen->uuid()],
        files: ['file' => new UploadedFile($path, 'evidence.pdf', 'application/pdf', NULL, TRUE)],
        server: $this->serviceHeaders(),
      ));
    }
    finally {
      unlink($path);
    }
    $this->assertSame(201, $upload->getStatusCode(), (string) $upload->getContent());
    $file = $this->body($upload)['data'];
    $this->assertSame('pending', $file['analysisStatus']);
    $this->assertArrayNotHasKey('staffAnalysis', $file);
    $submission = PublicApplicationApiController::create($this->container)->createApplication($this->jsonRequest([
      'data' => [
        'type' => 'application',
        'attributes' => [
          'formId' => $form->uuid(),
          'ownerUuid' => $citizen->uuid(),
          'consentAccepted' => TRUE,
          'locale' => 'de',
          'data' => $answers,
          'identity' => ['fullName' => 'Fictional Applicant', 'email' => 'applicant@example.org'],
          'attachments' => [['attachmentId' => $file['id'], 'fieldId' => 'proof', 'claimToken' => $file['claimToken']]],
        ],
      ],
    ]));
    $this->assertSame(201, $submission->getStatusCode(), (string) $submission->getContent());

    $uuid = $this->body($submission)['data']['id'];
    $application_storage = $this->entityTypeManager()->getStorage('application');
    $application = $application_storage->loadByProperties(['uuid' => $uuid]);
    $application = reset($application);
    $this->assertInstanceOf(ApplicationInterface::class, $application);
    $route = 'beantragt_application.admin_application_attachments';
    $access = $this->container->get('access_manager');
    $parameters = ['application' => $application->id()];
    $this->assertFalse($access->checkNamedRoute($route, $parameters, $citizen));
    $this->assertTrue($access->checkNamedRoute($route, $parameters, $staff));
    $this->assertTrue($access->checkNamedRoute($route, $parameters, $restricted));
    $this->assertFalse($access->checkNamedRoute($route, $parameters, $foreign_staff));
    $queue = $this->container->get('queue')->get(AttachmentAnalysisDispatcher::QUEUE_NAME);
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $this->assertSame($file['id'], $item->data['attachmentId']);
    $this->assertSame('staff', $item->data['story']);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(AttachmentAnalysisDispatcher::QUEUE_NAME);
    $worker->processItem($item->data);
    $queue->deleteItem($item);

    $this->assertSame(403, $controller->rerunApplicationAttachmentAnalysis($application, $file['id'], 'staff')->getStatusCode());
    $events = $this->container->get('beantragt_application.event_read_state');
    $this->assertSame(1, $events->detail($application, $staff)['counts']->scan);
    $this->assertSame([], (array) $events->detail($application, $foreign_staff)['counts']);
    $this->container->get('router.builder')->rebuild();
    $rerun_path = '/api/admin/applications/' . $application->id() . '/attachments/' . $file['id'] . '/analysis/staff?_format=json';
    $this->setCurrentUser($foreign_staff);
    $this->assertSame(403, $this->container->get('http_kernel')->handle(Request::create($rerun_path, 'POST'))->getStatusCode());

    // Publishing a less restrictive field cannot change an earlier case's
    // attachment access.
    $original_schema = $form->getSchema();
    $changed_schema = json_decode($original_schema, TRUE, 512, JSON_THROW_ON_ERROR);
    $proof_index = array_key_last($fields);
    $changed_schema['sections'][0]['fields'][$proof_index]['sensitive'] = FALSE;
    unset($changed_schema['sections'][0]['fields'][$proof_index]['analysis']);
    $this->setCurrentUser($setup_account);
    $form->set('field_workflow_status', 'draft')->save();
    $form->set('field_schema', json_encode($changed_schema, JSON_THROW_ON_ERROR))->save();
    $application = $application_storage->loadUnchanged($application->id());
    $this->setCurrentUser($restricted);
    $this->assertNotSame((int) $form->getRevisionId(), (int) $application->get('field_schema_revision_id')->value);
    $this->assertFalse($controller->canAccessAttachmentField($application, 'proof'));
    $this->assertSame([], $this->body($controller->listApplicationAttachments($application))['data']);
    $storage = $this->entityTypeManager()->getStorage('application_attachment');
    $attachments = $storage->loadByProperties(['uuid' => $file['id']]);
    $attachment = reset($attachments);
    $this->assertInstanceOf(ApplicationAttachmentInterface::class, $attachment);
    $this->assertFalse($attachment->access('view', $restricted));

    // Exercise the projection of a file field submitted as non-sensitive.
    $non_sensitive = clone $application;
    $non_sensitive->set('field_schema_revision_id', $form->getRevisionId());
    $non_sensitive->set('field_schema_version', $form->getVersion());
    $this->assertTrue($controller->canAccessAttachmentField($non_sensitive, 'proof'));
    $restricted_row = $this->body($controller->listApplicationAttachments($non_sensitive))['data'][0];
    $this->assertArrayNotHasKey('staffAnalysis', $restricted_row);
    $this->assertSame(['citizen'], array_keys($restricted_row['analysisMetadata']['stories']));
    $event_detail = $events->detail($application, $restricted);
    foreach (['counts', 'newEventIds', 'seenTokens'] as $key) {
      $this->assertArrayNotHasKey('scan', (array) $event_detail[$key]);
    }
    $denied = $this->container->get('http_kernel')->handle(Request::create($rerun_path, 'POST'));
    $this->assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());
    $this->assertStringContainsString('attachment.access_denied', (string) $denied->getContent());
    $this->assertSame(0, $this->auditCount());

    $raw = (string) $attachment->get('field_analysis_metadata')->value;
    $this->assertStringContainsString(ApplicationDataProtectionService::ENCRYPTED_MARKER, $raw);
    $this->assertStringNotContainsString('Example Testperson', $raw);
    $staff_only = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    unset($staff_only['stories']['citizen']);
    $attachment->set('field_analysis_metadata', json_encode($staff_only, JSON_THROW_ON_ERROR))->save();
    $response = $controller->listApplicationAttachments($non_sensitive);
    $decoded = json_decode((string) $response->getContent(), FALSE, 512, JSON_THROW_ON_ERROR);
    $this->assertEquals(new \stdClass(), $decoded->data[0]->analysisMetadata->stories);
    $this->assertSame('not_requested', $decoded->data[0]->analysisStatus);
    $this->assertSame(0, $this->auditCount());
    $attachment->set('field_analysis_metadata', $raw)->save();

    $this->setCurrentUser($setup_account);
    $form->set('field_schema', $original_schema)->save();
    $form->set('field_workflow_status', 'published')->save();
    $application = $application_storage->loadUnchanged($application->id());
    $this->setCurrentUser($staff);
    $result = $controller->listApplicationAttachments($application);
    $row = $this->body($result)['data'][0];
    $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
    $this->assertSame($expected_state, $row['staffAnalysis']['state']);
    $this->assertSame('pending', $row['analysisMetadata']['stories']['citizen']['status']);
    $this->assertSame($comparisons, $row['staffAnalysis']['comparisons']);
    $this->assertSame($values, $row['staffAnalysis']['facts']);
    $this->assertSame(['party' => 'not_configured', 'validity' => 'not_configured'], $row['staffAnalysis']['checks']);
    $this->assertStringNotContainsString('Example Testperson', (string) $result->getContent());
    $this->assertStringNotContainsString('2026-02-28', (string) $result->getContent());
    $this->assertSame(1, $this->auditCount());

    $derivative = $this->container->get('file.repository')->writeData($pdf, 'private://verified-staff-proof.pdf');
    $attachment->set('field_guard_derivative', $derivative->id());
    $attachment->set('field_guard_status', 'clean');
    $attachment->set('field_guard_report', json_encode([
      'status' => 'clean',
      'derivative' => ['mediaType' => 'application/pdf', 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf)],
    ], JSON_THROW_ON_ERROR));
    $attachment->save();
    $kernel = $this->container->get('http_kernel');
    $path = '/api/admin/applications/' . $application->id() . '/attachments/' . $file['id'];
    foreach ([[$staff, 200], [$restricted, 403], [$foreign_staff, 403]] as [$viewer, $status]) {
      $this->setCurrentUser($viewer);
      foreach (['/download', '/derivative'] as $suffix) {
        $this->assertSame($status, $kernel->handle(Request::create($path . $suffix))->getStatusCode(), $suffix);
      }
    }
    $this->setCurrentUser($staff);

    $attachment->set('field_scan_status', 'quarantined')->save();
    $quarantined = $this->body($controller->listApplicationAttachments($application))['data'][0];
    $this->assertSame('quarantined', $quarantined['scanStatus']);
    $this->assertSame(['citizen'], array_keys($quarantined['analysisMetadata']['stories']));
    $this->assertSame('file_unavailable', $quarantined['staffAnalysis']['reason']);
    $this->assertSame([], $quarantined['staffAnalysis']['facts']);
    $this->assertSame([], $quarantined['staffAnalysis']['comparisons']);
    $this->assertFalse($quarantined['staffAnalysis']['canRun']);
    $this->assertSame(409, $controller->rerunApplicationAttachmentAnalysis($application, $file['id'], 'staff')->getStatusCode());
    $this->assertSame(3, $this->auditCount());
    $attachment->set('field_scan_status', 'clean')->save();

    // First-use table creation must not commit either of the rerun writes.
    $database = $this->container->get('database');
    $database->schema()->dropTable(DatabaseQueue::TABLE_NAME);
    $failing_queue = $this->getMockBuilder(DatabaseQueue::class)
      ->disableOriginalConstructor()->onlyMethods(['createItem'])->getMock();
    $failing_queue->expects($this->once())->method('createItem')->willReturnCallback(
      function (array $data) use ($queue, $database): bool {
        $this->assertTrue($database->schema()->tableExists(DatabaseQueue::TABLE_NAME));
        $this->assertTrue($database->inTransaction());
        $queue->createItem($data);
        return FALSE;
      },
    );
    $failing_factory = $this->createMock(QueueFactory::class);
    $failing_factory->method('get')->with(AttachmentAnalysisDispatcher::QUEUE_NAME)->willReturn($failing_queue);
    $this->container->set('beantragt_application.attachment_analysis_dispatcher', new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(), $failing_factory, $http, $this->container->get('logger.channel.beantragt_application'),
    ));
    $failing_controller = ApplicationAttachmentApiController::create($this->container);
    $failed_rerun = $failing_controller->rerunApplicationAttachmentAnalysis($application, $file['id'], 'staff');
    $this->assertSame(503, $failed_rerun->getStatusCode());
    $this->assertSame('attachment.analysis_queue_unavailable', $this->body($failed_rerun)['error']['code']);
    $restored = $storage->loadUnchanged($attachment->id());
    $this->assertSame($raw, $restored->get('field_analysis_metadata')->value);
    $this->assertSame($attachment->get('field_analysis_status')->value, $restored->get('field_analysis_status')->value);
    $this->assertSame(0, $queue->numberOfItems());

    $rerun = $controller->rerunApplicationAttachmentAnalysis($application, $file['id'], 'staff');
    $this->assertSame(202, $rerun->getStatusCode(), (string) $rerun->getContent());
    $pending = $this->body($controller->listApplicationAttachments($application))['data'][0]['staffAnalysis'];
    $this->assertSame('pending', $pending['state']);
    $this->assertSame('queued', $pending['reason']);
    $this->assertFalse($pending['canRun']);
    $this->assertGreaterThan(time(), $pending['pollUntil']);
    $this->assertSame([], $pending['comparisons']);

    $storage->resetCache([$attachment->id()]);
    $attachment = $storage->load($attachment->id());
    $metadata = json_decode($attachment->get('field_analysis_metadata')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    $metadata['stories']['staff']['analysisStartedAt'] -= AttachmentAnalysisDispatcher::PENDING_DISPATCH_LEASE_SECONDS + 5;
    $attachment->set('field_analysis_metadata', json_encode($metadata, JSON_THROW_ON_ERROR))->save();
    $expired = $this->body($controller->listApplicationAttachments($application))['data'][0]['staffAnalysis'];
    $this->assertSame('unavailable', $expired['state']);
    $this->assertSame('result_not_received', $expired['reason']);
    $this->assertTrue($expired['canRun']);
    $this->assertNull($expired['pollUntil']);
  }

  /**
   * Builds a request for a trusted Nuxt-to-Drupal callback or submission.
   */
  private function jsonRequest(array $body): Request {
    return Request::create('/', 'POST', server: $this->serviceHeaders() + ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Supplies the isolated test service credentials.
   */
  private function serviceHeaders(): array {
    return ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret')];
  }

  /**
   * Decodes a controller response.
   */
  private function body(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Counts protected reads written to SQL.
   */
  private function auditCount(): int {
    return (int) $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'l')->countQuery()->execute()->fetchField();
  }

}
