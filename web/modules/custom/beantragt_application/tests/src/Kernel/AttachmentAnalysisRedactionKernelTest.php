<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher;
use Drupal\beantragt_application\Service\AttachmentAnalysisMetadataWriter;
use Drupal\beantragt_application\Service\AttachmentAnalysisSourcePreparer;
use Drupal\beantragt_application\Service\DocumentGuardClientInterface;
use Drupal\beantragt_application\Service\PublicApplicationAttachmentClaimService;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies that an unredactable citizen source remains submittable.
 */
#[Group('beantragt_application')]
final class AttachmentAnalysisRedactionKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'beantragt_schema', 'beantragt_workflow',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_roles_api',
  ];

  private const ATTACHMENT_TOKEN = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuv';
  private const RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

  /**
   * Form that requests an optional citizen check and a separate staff check.
   */
  private FormSchemaInterface $form;

  /**
   * Jurisdiction responsible for the test form.
   */
  private GroupInterface $jurisdiction;

  /**
   * Office that receives the application.
   */
  private GroupInterface $organisation;

  /**
   * Initial workflow status for the submitted application.
   */
  private WorkflowStatusInterface $workflowStatus;

  /**
   * Original environment variables.
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
      'NUXT_DRUPAL_SERVICE_USER' => 'nuxt_service',
      'NUXT_DRUPAL_SERVICE_PASSWORD' => 'test-secret',
    ] as $name => $value) {
      $this->originalEnv[$name] = getenv($name);
      putenv($name . '=' . $value);
    }
    parent::setUp();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    foreach (['file', 'form_schema', 'workflow_status', 'application', 'application_attachment'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));

    $this->jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Test jurisdiction']);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Test office',
      'field_jurisdiction' => $this->jurisdiction->id(),
    ]);
    $this->workflowStatus = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Received',
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_machine_name' => 'received',
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => TRUE,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ]);
    $this->workflowStatus->save();
    $this->form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Photo proof',
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_organisation' => $this->organisation->id(),
      'field_group' => $this->organisation->id(),
      'field_schema' => json_encode([
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              [
                'id' => 'proof',
                'type' => 'file',
                'label' => 'Photo proof',
                'analysis' => [
                  'enabled' => TRUE,
                  'citizenPrompt' => 'Check the fictional roof photo.',
                  'staffPrompt' => 'Review this attachment for the case.',
                  'egressPolicy' => 'sovereign_only',
                  'modelClass' => 'openai_compatible',
                  'redaction' => 'when_possible',
                  'resultSchema' => [
                    'type' => 'object',
                    'properties' => [
                      'roof_visible' => ['type' => 'boolean', 'nonPersonal' => TRUE],
                      'staff_measurement' => ['type' => 'number', 'purposeBound' => TRUE],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ]);
    $this->form->save();
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
   * The skipped citizen run preserves the file and its submission claim.
   */
  #[DataProvider('unredactableFiles')]
  public function testCitizenRedactionFailureIsTerminalAndRemainsClaimable(
    string $mime_type,
    string $fixture_name,
  ): void {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/attachment-precheck/' . $fixture_name);
    $this->assertIsString($contents);
    $attachment = $this->attachment($fixture_name, $mime_type, $contents);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($mime_type === 'application/pdf' ? $this->once() : $this->never())
      ->method('createDerivative')
      ->with($contents, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn([
        'status' => 'needs_review',
        'reportJson' => '{"reasons":["empty_text_extraction"],"extraction":{"hasTextLayer":false}}',
        'derivative' => NULL,
        'mediaType' => NULL,
        'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
      ]);
    $guard->expects($this->never())->method('renderPages');
    $this->container->set('beantragt_application.attachment_analysis_source_preparer', new AttachmentAnalysisSourcePreparer($guard));
    $this->setCurrentUser(new AnonymousUserSession());

    $response = ApplicationAttachmentApiController::create($this->container)
      ->prepareCitizenAnalysisSource($attachment->uuid(), $this->sourceRequest());

    $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    $error = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['error'];
    $this->assertSame('attachment.analysis_source_failed', $error['code']);
    $this->assertSame([
      'analysisCode' => 'redaction_not_possible',
      'recorded' => TRUE,
    ], $error['details']);

    $storage = $this->entityTypeManager()->getStorage('application_attachment');
    $storage->resetCache([$attachment->id()]);
    $stored = $storage->load($attachment->id());
    $this->assertInstanceOf(ApplicationAttachmentInterface::class, $stored);
    $this->assertTrue($stored->get('field_application')->isEmpty());
    $this->assertSame('uploaded', $stored->get('field_upload_status')->value);
    $this->assertSame(hash('sha256', self::ATTACHMENT_TOKEN), $stored->get('field_claim_token_hash')->value);

    $metadata = AttachmentAnalysisMetadataWriter::decode((string) $stored->get('field_analysis_metadata')->value);
    $this->assertSame('failed', $metadata['stories']['citizen']['status']);
    $this->assertSame('redaction_not_possible', $metadata['stories']['citizen']['code']);
    $this->assertSame(self::RUN_ID, $metadata['stories']['citizen']['analysisRunId']);
    $this->assertTrue($metadata['stories']['citizen']['requiresHumanReview']);
    $this->assertSame('complete', $metadata['stories']['staff']['status']);
    $this->assertSame('staff_result_unchanged', $metadata['stories']['staff']['code']);

    $poll = ApplicationAttachmentApiController::create($this->container)
      ->citizenAttachmentAnalysis($attachment->uuid(), $this->pollRequest());
    $this->assertSame(200, $poll->getStatusCode());
    $data = json_decode((string) $poll->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data'];
    $this->assertSame('failed', $data['status']);
    $this->assertSame('redaction_not_possible', $data['code']);
    $this->assertArrayNotHasKey('result', $data);

    $application = $this->claimAttachment($stored);
    $storage->resetCache([$attachment->id()]);
    $claimed = $storage->load($attachment->id());
    $this->assertInstanceOf(ApplicationAttachmentInterface::class, $claimed);
    $this->assertSame((int) $application->id(), (int) $claimed->get('field_application')->target_id);
    $this->assertSame('claimed', $claimed->get('field_upload_status')->value);
    $this->assertTrue($claimed->get('field_claim_token_hash')->isEmpty());
    $file = $claimed->get('field_file')->entity;
    $this->assertTrue($file->isPermanent());
    $this->assertSame($contents, file_get_contents($file->getFileUri()));
  }

  /**
   * The UI's saved image skip survives both the claim and a queued job.
   */
  public function testCitizenImageSkipWrittenByUiPreventsQueueDispatch(): void {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/attachment-precheck/roof.jpg');
    $this->assertIsString($contents);
    $attachment = $this->attachment('roof.jpg', 'image/jpeg', $contents);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    $this->container->set('http_client', $http_client);
    $this->setCurrentUser(new AnonymousUserSession());

    $request = Request::create(
      '/api/internal/attachments/' . $attachment->uuid() . '/analysis',
      'POST',
      [],
      [],
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret')],
      json_encode([
        'status' => 'failed',
        'metadata' => [
          'version' => 1,
          'status' => 'failed',
          'code' => 'redaction_not_possible',
          'requiresHumanReview' => TRUE,
          'language' => 'de',
        ],
        'story' => 'citizen',
        'analysisRunId' => self::RUN_ID,
        'formId' => $this->form->uuid(),
        'fieldId' => 'proof',
      ], JSON_THROW_ON_ERROR),
    );
    $response = ApplicationAttachmentApiController::create($this->container)
      ->updateAttachmentAnalysis($attachment->uuid(), $request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $storage = $this->entityTypeManager()->getStorage('application_attachment');
    $stored = $storage->loadUnchanged($attachment->id());
    $this->assertInstanceOf(ApplicationAttachmentInterface::class, $stored);
    $metadata = (string) $stored->get('field_analysis_metadata')->value;
    $citizen = AttachmentAnalysisMetadataWriter::decode($metadata)['stories']['citizen'];
    $this->assertSame('failed', $citizen['status']);
    $this->assertSame('redaction_not_possible', $citizen['code']);
    $this->assertSame(self::RUN_ID, $citizen['analysisRunId']);
    $this->assertTrue($citizen['requiresHumanReview']);

    // Make citizen the queued story before submission so the skip, rather
    // than the original upload-only profile, is what prevents dispatch.
    $schema = json_decode($this->form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    $profile = &$schema['sections'][0]['fields'][0]['analysis'];
    $profile['egressPolicy'] = 'external_redacted';
    unset($profile['staffPrompt'], $profile['resultSchema']['properties']['staff_measurement']);
    $this->form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR))->save();
    $application = $this->claimAttachment($stored);
    $claimed = $storage->loadUnchanged($attachment->id());
    $this->assertInstanceOf(ApplicationAttachmentInterface::class, $claimed);
    $this->assertSame((int) $application->id(), (int) $claimed->get('field_application')->target_id);
    $queue_name = AttachmentAnalysisDispatcher::QUEUE_NAME;
    $this->assertSame(0, $this->container->get('queue')->get($queue_name)->numberOfItems());

    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance($queue_name);
    $worker->processItem(['attachmentId' => $attachment->uuid(), 'story' => 'citizen']);
    $after_queue = $storage->loadUnchanged($attachment->id());
    $this->assertInstanceOf(ApplicationAttachmentInterface::class, $after_queue);
    $this->assertSame($metadata, $after_queue->get('field_analysis_metadata')->value);
  }

  /**
   * Provides the fictional attachments used by the citizen upload flow.
   *
   * @return array<string, array{string, string}>
   *   Detected media type and fixture filename.
   */
  public static function unredactableFiles(): array {
    return [
      'payslip image chosen by mistake' => ['image/png', 'fictional-payslip.png'],
      'roof photo' => ['image/jpeg', 'roof.jpg'],
      'scan with no text layer' => ['application/pdf', 'scan.pdf'],
    ];
  }

  /**
   * Creates a clean, unclaimed attachment with separate citizen and staff runs.
   */
  private function attachment(string $filename, string $mime_type, string $contents): ApplicationAttachmentInterface {
    $directory = 'private://application_attachments';
    $this->assertTrue($this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY));
    $uri = $directory . '/' . $filename;
    $this->assertSame(strlen($contents), file_put_contents($uri, $contents));
    $file = $this->entityTypeManager()->getStorage('file')->create([
      'filename' => $filename,
      'uri' => $uri,
      'filemime' => $mime_type,
      'status' => 0,
    ]);
    $file->save();
    $metadata = [
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => [
        'citizen' => [
          'status' => 'pending',
          'code' => 'analysis_queued',
          'analysisRunId' => self::RUN_ID,
          'analysisStartedAt' => $this->container->get('datetime.time')->getRequestTime(),
        ],
        'staff' => [
          'status' => 'complete',
          'code' => 'staff_result_unchanged',
          'result' => ['outcome' => 'needs_review'],
        ],
      ],
    ];
    $attachment = $this->entityTypeManager()->getStorage('application_attachment')->create([
      'field_file' => $file->id(),
      'field_schema' => $this->form->id(),
      'field_field_id' => 'proof',
      'field_upload_status' => 'uploaded',
      'field_claim_token_hash' => hash('sha256', self::ATTACHMENT_TOKEN),
      'field_claim_expires' => $this->container->get('datetime.time')->getRequestTime() + 3600,
      'field_analysis_status' => 'pending',
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
      'field_scan_status' => 'clean',
      'field_mime_type' => $mime_type,
      'field_original_filename' => $filename,
      'field_size' => strlen($contents),
    ]);
    $attachment->save();
    return $attachment;
  }

  /**
   * Creates the application the public claim service binds the upload to.
   *
   * @param array<string, mixed> $data
   *   Validated submission data for the file field.
   */
  private function application(array $data): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $this->form->id()],
      'field_data' => json_encode($data, JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $this->workflowStatus->id()],
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Builds the trusted source request, including the exact stored claim.
   */
  private function sourceRequest(): Request {
    return Request::create(
      '/api/internal/attachments/source?story=citizen',
      'POST',
      [],
      [],
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret')],
      json_encode([
        'formId' => $this->form->uuid(),
        'fieldId' => 'proof',
        'claimToken' => self::ATTACHMENT_TOKEN,
        'analysisRunId' => self::RUN_ID,
      ], JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Builds the trusted citizen polling request.
   */
  private function pollRequest(): Request {
    return Request::create(
      '/api/internal/attachments/citizen-analysis',
      'POST',
      [],
      [],
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret')],
      json_encode(['claimToken' => self::ATTACHMENT_TOKEN], JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Validates and binds the upload through the public submission services.
   */
  private function claimAttachment(ApplicationAttachmentInterface $attachment): ApplicationInterface {
    $schema = json_decode($this->form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    $claims = $this->claims()->validateClaims([
      [
        'attachmentId' => $attachment->uuid(),
        'fieldId' => 'proof',
        'claimToken' => self::ATTACHMENT_TOKEN,
      ],
    ], $this->form, ['fields' => $schema['sections'][0]['fields']]);
    $this->assertNull($claims['error']);
    $this->assertCount(1, $claims['entities']);
    $application = $this->application($claims['byField']);
    $this->claims()->claim($claims['entities'], $application, TRUE);
    return $application;
  }

  /**
   * Returns the real public claim service used by application submission.
   */
  private function claims(): PublicApplicationAttachmentClaimService {
    return $this->container->get('beantragt_application.public_attachment_claims');
  }

}
