<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use PHPUnit\Framework\MockObject\MockObject;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_comment\Entity\ApplicationCommentInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher;
use Drupal\beantragt_application\Service\AttachmentAnalysisSourcePreparer;
use Drupal\beantragt_application\Service\AttachmentScanService;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\DocumentGuardClientInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the public attachment upload and scoped download controller.
 */
#[CoversClass(ApplicationAttachmentApiController::class)]
#[Group('beantragt_application')]
final class ApplicationAttachmentApiControllerTest extends UnitTestCase {

  private const CITIZEN_FORM_UUID = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
  private const CITIZEN_ATTACHMENT_UUID = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
  private const CITIZEN_CLAIM_TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEF';
  private const CITIZEN_RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
  private const CITIZEN_JPEG = "\xFF\xD8\xFF\xE0citizen-page";
  private const LEGACY_BATCH_FILENAME = '57417087-80aa-4336-93ac-5048ca1cee6b.pdf';
  private const LEGACY_BATCH_URI = 'private://application_attachments/bulk-069a1301-66a9-424a-a800-31e5704b5161/' . self::LEGACY_BATCH_FILENAME;

  /**
   * Original service user environment value.
   */
  private string|false $originalServiceUser;

  /**
   * Original service password environment value.
   */
  private string|false $originalServicePassword;

  /**
   * Original PHP timezone.
   */
  private string $originalTimezone;

  /**
   * Form schema storage mock.
   */
  private EntityStorageInterface $formStorage;

  /**
   * Attachment storage mock.
   */
  private EntityStorageInterface $attachmentStorage;

  /**
   * User storage mock.
   */
  private EntityStorageInterface $userStorage;

  /**
   * Message storage mock.
   */
  private EntityStorageInterface $commentStorage;

  /**
   * File storage mock.
   */
  private EntityStorageInterface $fileStorage;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * File repository mock.
   */
  private FileRepositoryInterface $fileRepository;

  /**
   * File system mock.
   */
  private FileSystemInterface $fileSystem;

  /**
   * Flood control mock.
   */
  private FloodInterface $flood;

  /**
   * Lock backend mock.
   */
  private LockBackendInterface $lock;

  /**
   * Database connection mock.
   */
  private Connection $database;

  /**
   * Logger mock.
   */
  private LoggerInterface $logger;

  /**
   * Time service mock.
   */
  private TimeInterface $time;

  /**
   * Original encryption key.
   */
  private string|false $originalKey;

  /**
   * Controller under test.
   */
  private ApplicationAttachmentApiController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('k', 32));
    $this->originalServiceUser = getenv('NUXT_DRUPAL_SERVICE_USER');
    $this->originalServicePassword = getenv('NUXT_DRUPAL_SERVICE_PASSWORD');
    $this->originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    $this->formStorage = $this->createMock(RevisionableStorageInterface::class);
    $this->attachmentStorage = $this->createMock(EntityStorageInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $this->fileStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->commentStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'form_schema' => $this->formStorage,
        'application_attachment' => $this->attachmentStorage,
        'user' => $this->userStorage,
        'file' => $this->fileStorage,
        'application_comment' => $this->commentStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')->willReturn(TRUE);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->database = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->fileSystem->method('prepareDirectory')->willReturn(TRUE);
    $this->fileRepository = $this->createMock(FileRepositoryInterface::class);

    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1782862200);

    $this->controller = new ApplicationAttachmentApiController(
      $this->entityTypeManager,
      $this->flood,
      $this->logger,
      $this->fileSystem,
      $this->fileRepository,
      $this->time,
      new AttachmentScanService(),
      $this->database,
      $this->lock,
      $this->configFactory(),
      NULL,
      new ApplicationDataProtectionService($this->entityTypeManager, $this->logger),
      accessLog: $this->accessLog = $this->createMock(ProtectedAccessLog::class),
    );
    // The download audit names the current user (ControllerBase).
    $container = new ContainerBuilder();
    $container->set('current_user', $this->createMock(AccountProxyInterface::class));
    \Drupal::setContainer($container);
  }

  /**
   * Audit log of downloads.
   */
  private ProtectedAccessLog&MockObject $accessLog;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY', $this->originalKey);
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_USER', $this->originalServiceUser);
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_PASSWORD', $this->originalServicePassword);
    date_default_timezone_set($this->originalTimezone);
    parent::tearDown();
  }

  /**
   * Uploading a valid public attachment creates a claimable attachment entity.
   */
  #[DataProvider('positiveScanVerdicts')]
  public function testUploadAttachmentCreatesClaimableAttachment(bool $positive): void {
    if ($positive) {
      $scanner = $this->createMock(AttachmentScanServiceInterface::class);
      $scanner->method('scan')->willReturn(new AttachmentScanResult('clean', '', TRUE));
      $this->controller = new ApplicationAttachmentApiController(
        $this->entityTypeManager, $this->flood, $this->logger, $this->fileSystem,
        $this->fileRepository, $this->time, $scanner, $this->database,
        $this->lock, $this->configFactory(),
      );
    }
    $form = $this->mockForm($this->schemaJson());
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])
      ->willReturn([$form]);

    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);

    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('setTemporary');
    $file->expects($this->once())->method('setOwnerId')->with(11)->willReturnSelf();
    $file->expects($this->once())->method('save');
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('scan.png');
    $file->method('getSize')->willReturn(68);

    $this->fileRepository->expects($this->once())
      ->method('writeData')
      ->with(
        $this->callback(static fn (string $data): bool => str_starts_with($data, "\x89PNG\r\n\x1A\n")),
        'private://application_attachments/2026-07/fbc3b086-ac53-412e-9bbf-ccb03f286923/nachweis/scan.png',
        $this->anything(),
      )
      ->willReturn($file);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $upload = $this->pngFile();
    $expected_hash = hash_file('sha256', $upload);
    $request = $this->uploadRequest($upload);
    $request->request->set('field_scan_sha256', str_repeat('f', 64));
    $request->request->set('field_submission_revision_id', '123');
    $response = $this->controller->uploadAttachment($request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $payload['data']['id']);
    $this->assertSame('nachweis', $payload['data']['fieldId']);
    $this->assertSame('scan.png', $payload['data']['filename']);
    $this->assertSame('image/png', $payload['data']['mimeType']);
    $this->assertFalse($payload['data']['externalAnalysisConsent']);
    $this->assertIsArray($created_values);
    $this->assertSame(['target_id' => 12], $created_values['field_schema']);
    $this->assertSame(['target_id' => 321], $created_values['field_file']);
    $this->assertSame('uploaded', $created_values['field_upload_status']);
    $this->assertSame('not_requested', $created_values['field_analysis_status']);
    $this->assertSame([
      'version' => 1,
      'status' => 'not_requested',
      'language' => 'de',
    ], json_decode((string) $created_values['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR));
    $this->assertFalse($created_values['field_external_analysis_consent']);
    $this->assertSame('clean', $created_values['field_scan_status']);
    $this->assertSame($positive ? $expected_hash : NULL, $created_values['field_scan_sha256']);
    $this->assertArrayNotHasKey('field_submission_revision_id', $created_values);
    $this->assertSame(11, $created_values['uid']);
    $this->assertSame(hash('sha256', $payload['data']['claimToken']), $created_values['field_claim_token_hash']);
    $this->assertSame('clean', $payload['data']['scanStatus']);
  }

  /**
   * Default clean labels and actual scanner-positive results differ.
   */
  public static function positiveScanVerdicts(): array {
    return [[FALSE], [TRUE]];
  }

  /**
   * A trusted explicit opt-in is persisted for an active external profile.
   */
  public function testUploadAttachmentPersistsTrustedExternalAnalysisConsent(): void {
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])
      ->willReturn([$form]);

    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('scan.png');
    $file->method('getSize')->willReturn(68);
    $this->fileRepository->method('writeData')->willReturn($file);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $response = $this->controller->uploadAttachment($this->uploadRequest(
      $this->pngFile(),
      parameters: ['analysisConsent' => 'true', 'language' => 'en'],
      trusted_proxy: TRUE,
    ));

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertIsArray($created_values);
    $this->assertTrue($created_values['field_external_analysis_consent']);
    $this->assertSame('en', json_decode((string) $created_values['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR)['language'] ?? NULL);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($payload['data']['externalAnalysisConsent']);
    $this->assertSame('en', $payload['data']['analysisLanguage']);
  }

  /**
   * A later trusted opt-in is claim-bound and exposes only stored file bytes.
   */
  public function testConsentAttachmentAnalysisReturnsCanonicalStoredFile(): void {
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $claim_token = 'abcdefghijklmnopqrstuvwxyzABCDEF';
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => $form_uuid])
      ->willReturn([$form]);

    $path = tempnam(sys_get_temp_dir(), 'beantragt_analysis_consent_');
    $this->assertIsString($path);
    file_put_contents($path, '%PDF-1.4 canonical fixture');
    $size = filesize($path);
    $this->assertIsInt($size);
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($path);
    $file->method('getSize')->willReturn($size);
    $file->method('getMimeType')->willReturn('application/pdf');

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_external_analysis_consent'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_claim_expires' => $this->fieldValue(1782865800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', $claim_token)),
      'field_file' => $this->entityReference($file),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      default => $this->emptyField(),
    });
    $attachment->expects($this->exactly(3))->method('set')->willReturnSelf();
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => $attachment_uuid])
      ->willReturn([$attachment]);

    $request = Request::create(
      '/api/v1/attachments/' . $attachment_uuid . '/analysis-consent',
      'POST',
      [
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
        'claimToken' => $claim_token,
        'language' => 'en',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
    );
    $response = $this->controller->consentAttachmentAnalysis($attachment_uuid, $request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertInstanceOf(BinaryFileResponse::class, $response);
    $this->assertSame($attachment_uuid, $response->headers->get('X-Beantragt-Attachment-Id'));
    $this->assertSame($form_uuid, $response->headers->get('X-Beantragt-Form-Id'));
    $this->assertSame('nachweis', $response->headers->get('X-Beantragt-Field-Id'));
    $this->assertSame('en', $response->headers->get('X-Beantragt-Analysis-Language'));
    $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $response->headers->get('X-Beantragt-Analysis-Run-Id'));
    $this->assertSame((string) $size, $response->headers->get('X-Beantragt-Attachment-Size'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();
    $this->assertSame('%PDF-1.4 canonical fixture', $body);
    unlink($path);
  }

  /**
   * Redacted-only external analysis never exposes original bytes for consent.
   */
  public function testConsentAttachmentAnalysisRejectsExternalRedactedProfile(): void {
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_redacted',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => $form_uuid])
      ->willReturn([$form]);
    $this->attachmentStorage->expects($this->never())->method('loadByProperties');

    $request = Request::create(
      '/api/v1/attachments/' . $attachment_uuid . '/analysis-consent',
      'POST',
      [
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
        'claimToken' => 'abcdefghijklmnopqrstuvwxyzABCDEF',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
    );
    $response = $this->controller->consentAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_consent_not_available', $payload['error']['code']);
  }

  /**
   * A consent request cannot override a sovereign file field or its story.
   */
  public function testConsentRefusesOriginalForProtectedCitizenPrecheck(): void {
    $form = $this->mockForm($this->schemaJson(analysis: $this->storyAnalysis() + ['redaction' => 'when_possible']));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->attachmentStorage->expects($this->never())->method('loadByProperties');
    $request = $this->consentRequest();
    $request->request->add([
      'egressPolicy' => 'external_allowed',
      'redaction' => 'none',
      'sourceKind' => 'derivative',
      'story' => 'staff',
    ]);

    $response = $this->controller->consentAttachmentAnalysis(self::CITIZEN_ATTACHMENT_UUID, $request);
    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_not_allowed', json_decode((string) $response->getContent(), TRUE)['error']['code']);
  }

  /**
   * A later opt-in cannot use an invalid attachment claim token.
   */
  public function testConsentAttachmentAnalysisRejectsInvalidClaimToken(): void {
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_claim_expires' => $this->fieldValue(1782865800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', 'abcdefghijklmnopqrstuvwxyzABCDEF')),
      default => $this->emptyField(),
    });
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $request = Request::create(
      '/api/v1/attachments/' . $attachment_uuid . '/analysis-consent',
      'POST',
      [
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
        'claimToken' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef',
        'language' => 'de',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
    );
    $response = $this->controller->consentAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('attachment.claim_invalid', $payload['error']['code']);
  }

  /**
   * A live analysis lease prevents replaying the attachment claim.
   */
  public function testConsentAttachmentAnalysisRejectsReplayWhilePending(): void {
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $claim_token = 'abcdefghijklmnopqrstuvwxyzABCDEF';
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(91);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status' => $this->fieldValue('pending'),
      'field_analysis_metadata' => $this->fieldValue(json_encode([
        'version' => 1,
        'status' => 'pending',
        'analysisRunId' => '0123456789abcdef0123456789abcdef',
        'analysisStartedAt' => 1782862190,
      ], JSON_THROW_ON_ERROR)),
      'field_claim_expires' => $this->fieldValue(1782865800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', $claim_token)),
      default => $this->emptyField(),
    });
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $request = Request::create(
      '/api/v1/attachments/' . $attachment_uuid . '/analysis-consent',
      'POST',
      [
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
        'claimToken' => $claim_token,
        'language' => 'de',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
    );
    $response = $this->controller->consentAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_already_started', $payload['error']['code']);
  }

  /**
   * The attachment is reloaded under the claim lock before consent is saved.
   */
  public function testConsentAttachmentAnalysisRejectsAttachmentClaimedBeforeLockReload(): void {
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);

    $initial = $this->createMock(ApplicationAttachmentInterface::class);
    $initial->method('id')->willReturn(92);
    $claimed = $this->createMock(ApplicationAttachmentInterface::class);
    $claimed->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $claimed->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('claimed'),
      'field_application' => $this->fieldTarget(501),
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $claimed->expects($this->never())->method('set');
    $claimed->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')
      ->willReturnOnConsecutiveCalls([$initial], [$claimed]);

    $request = Request::create(
      '/api/v1/attachments/' . $attachment_uuid . '/analysis-consent',
      'POST',
      [
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
        'claimToken' => 'abcdefghijklmnopqrstuvwxyzABCDEF',
        'language' => 'de',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
    );
    $response = $this->controller->consentAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.claim_invalid', $payload['error']['code']);
  }

  /**
   * An older analysis callback cannot overwrite the current generation.
   */
  public function testUpdateAttachmentAnalysisRejectsStaleRunId(): void {
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $current_run_id = '0123456789abcdef0123456789abcdef';
    $form = $this->mockForm($this->schemaJson());
    $form->method('uuid')->willReturn($form_uuid);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->entityReference($form),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status' => $this->fieldValue('pending'),
      'field_analysis_metadata' => $this->fieldValue(json_encode([
        'version' => 1,
        'status' => 'pending',
        'analysisRunId' => $current_run_id,
      ], JSON_THROW_ON_ERROR)),
      default => $this->emptyField(),
    });
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $request = Request::create(
      '/api/internal/attachments/' . $attachment_uuid . '/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode([
        'status' => 'complete',
        'metadata' => ['version' => 1, 'status' => 'complete'],
        'analysisRunId' => 'fedcba9876543210fedcba9876543210',
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
      ], JSON_THROW_ON_ERROR),
    );
    $response = $this->controller->updateAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_run_invalid', $payload['error']['code']);
  }

  /**
   * A run-bound analysis callback cannot omit its generation identifier.
   */
  public function testUpdateAttachmentAnalysisRejectsMissingRunIdForRunBoundAttachment(): void {
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $current_run_id = '0123456789abcdef0123456789abcdef';
    $form = $this->mockForm($this->schemaJson());
    $form->method('uuid')->willReturn($form_uuid);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->entityReference($form),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status' => $this->fieldValue('pending'),
      'field_analysis_metadata' => $this->fieldValue(json_encode([
        'version' => 1,
        'status' => 'pending',
        'analysisRunId' => $current_run_id,
      ], JSON_THROW_ON_ERROR)),
      default => $this->emptyField(),
    });
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $request = Request::create(
      '/api/internal/attachments/' . $attachment_uuid . '/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode([
        'status' => 'complete',
        'metadata' => ['version' => 1, 'status' => 'complete'],
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
      ], JSON_THROW_ON_ERROR),
    );
    $response = $this->controller->updateAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_run_invalid', $payload['error']['code']);
  }

  /**
   * The current run may complete when generation and context match.
   */
  public function testUpdateAttachmentAnalysisAcceptsCurrentRunIdAndContext(): void {
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $current_run_id = '0123456789abcdef0123456789abcdef';
    $form = $this->mockForm($this->schemaJson());
    $form->method('uuid')->willReturn($form_uuid);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('uuid')->willReturn($attachment_uuid);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->entityReference($form),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status' => $this->fieldValue('pending'),
      'field_analysis_metadata' => $this->fieldValue(json_encode([
        'version' => 1,
        'status' => 'pending',
        'analysisRunId' => $current_run_id,
      ], JSON_THROW_ON_ERROR)),
      default => $this->emptyField(),
    });
    $attachment->expects($this->exactly(2))->method('set')->willReturnSelf();
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $request = Request::create(
      '/api/internal/attachments/' . $attachment_uuid . '/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode([
        'status' => 'complete',
        'metadata' => ['version' => 1, 'status' => 'complete'],
        'analysisRunId' => $current_run_id,
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
      ], JSON_THROW_ON_ERROR),
    );
    $response = $this->controller->updateAttachmentAnalysis($attachment_uuid, $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('complete', $payload['data']['analysisStatus']);
    $this->assertSame($current_run_id, $payload['data']['analysisMetadata']['analysisRunId']);
  }

  /**
   * A trusted opt-in cannot enable a profile without citizen consent support.
   */
  #[DataProvider('profilesWithoutCitizenConsent')]
  public function testUploadAttachmentIgnoresConsentWithoutConsentEligibleProfile(string $egress_policy, string $model_class): void {
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => $egress_policy,
      'modelClass' => $model_class,
    ]));
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])
      ->willReturn([$form]);

    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('scan.png');
    $file->method('getSize')->willReturn(68);
    $this->fileRepository->method('writeData')->willReturn($file);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $response = $this->controller->uploadAttachment($this->uploadRequest(
      $this->pngFile(),
      parameters: ['analysisConsent' => 'true'],
      trusted_proxy: TRUE,
    ));

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertIsArray($created_values);
    $this->assertFalse($created_values['field_external_analysis_consent']);
  }

  /**
   * Profiles that cannot use the citizen consent path.
   *
   * @return array<string, array{string, string}>
   *   Egress policy and model class.
   */
  public static function profilesWithoutCitizenConsent(): array {
    return [
      'sovereign only' => ['sovereign_only', 'openai_compatible'],
      'external redacted' => ['external_redacted', 'claude_sonnet'],
    ];
  }

  /**
   * A direct public multipart value cannot persist the external opt-in.
   */
  public function testUploadAttachmentIgnoresUntrustedExternalAnalysisConsent(): void {
    $form = $this->mockForm($this->schemaJson(analysis: [
      'enabled' => TRUE,
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ]));
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])
      ->willReturn([$form]);

    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);

    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('scan.png');
    $file->method('getSize')->willReturn(68);
    $this->fileRepository->method('writeData')->willReturn($file);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $response = $this->controller->uploadAttachment($this->uploadRequest(
      $this->pngFile(),
      parameters: ['analysisConsent' => 'true'],
    ));

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertIsArray($created_values);
    $this->assertFalse($created_values['field_external_analysis_consent']);
  }

  /**
   * Uploading EICAR content stores a quarantined attachment without a claim.
   */
  public function testUploadAttachmentQuarantinesEicarPayload(): void {
    $form = $this->mockForm($this->schemaJson());
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])
      ->willReturn([$form]);

    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);

    $file = $this->createMock(FileInterface::class);
    $file->expects($this->once())->method('setTemporary');
    $file->expects($this->once())->method('setOwnerId')->with(11)->willReturnSelf();
    $file->expects($this->once())->method('save');
    $file->method('id')->willReturn(322);
    $file->method('getFilename')->willReturn('scan.pdf');
    $file->method('getSize')->willReturn(1024);

    $this->fileRepository->expects($this->once())
      ->method('writeData')
      ->with(
        $this->callback(static fn (string $data): bool => str_starts_with($data, '%PDF-') && str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')),
        'private://application_attachments/2026-07/fbc3b086-ac53-412e-9bbf-ccb03f286923/nachweis/scan.pdf',
        $this->anything(),
      )
      ->willReturn($file);

    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('9cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->expects($this->once())
      ->method('create')
      ->with($this->callback(static function (array $values) use (&$created_values): bool {
        $created_values = $values;
        return TRUE;
      }))
      ->willReturn($attachment);

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->eicarPdfFile(), 'scan.pdf', 'application/pdf'));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('attachment.quarantined', $payload['error']['code']);
    $this->assertSame('9cf1ce03-5c64-4098-8b1c-a8322a62e073', $payload['error']['details']['attachmentId']);
    $this->assertIsArray($created_values);
    $this->assertSame('uploaded', $created_values['field_upload_status']);
    $this->assertSame('quarantined', $created_values['field_scan_status']);
    $this->assertSame('EICAR test signature detected.', $created_values['field_scan_message']);
    $this->assertNull($created_values['field_claim_token_hash']);
  }

  /**
   * Required-auth forms reject direct public uploads without a trusted owner.
   */
  public function testUploadAttachmentRequiresTrustedOwnerForAuthRequiredForms(): void {
    $form = $this->mockForm($this->schemaJson(TRUE));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->fileRepository->expects($this->never())->method('writeData');
    $this->attachmentStorage->expects($this->never())->method('create');

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->pngFile()));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    $this->assertSame('auth.required', $payload['error']['code']);
  }

  /**
   * File fields may define a stricter per-file byte limit.
   */
  public function testUploadAttachmentRejectsFieldSizeLimit(): void {
    $form = $this->mockForm($this->schemaJson(max_size_bytes: 32));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->fileRepository->expects($this->never())->method('writeData');
    $this->attachmentStorage->expects($this->never())->method('create');

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->pngFile()));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.file_too_large', $payload['error']['code']);
  }

  /**
   * Open pre-submit uploads are capped before writing another temporary file.
   */
  public function testUploadAttachmentRejectsPendingAggregateQuota(): void {
    $form = $this->mockForm($this->schemaJson());
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(52428790);
    $this->fileRepository->expects($this->never())->method('writeData');
    $this->attachmentStorage->expects($this->never())->method('create');

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->pngFile()));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.total_size_exceeded', $payload['error']['code']);
    $this->assertSame(52428800, $payload['error']['details']['limit']);
  }

  /**
   * Tenant storage quotas are enforced before writing another file.
   */
  public function testUploadAttachmentRejectsTenantStorageQuota(): void {
    $form = $this->mockForm($this->schemaJson());
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')
      ->with(['name' => 'nuxt_service'])
      ->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);

    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->method('jurisdictionForForm')->with($form)->willReturn($this->mockGroup('jur', 3));
    $quota->expects($this->once())
      ->method('violationForFormUpload')
      ->with($form, 68)
      ->willReturn([
        'limit' => 100,
        'used' => 90,
        'projected' => 158,
        'warningThresholdPercent' => 75,
      ]);
    $this->controller = new ApplicationAttachmentApiController(
      $this->entityTypeManager,
      $this->flood,
      $this->logger,
      $this->fileSystem,
      $this->fileRepository,
      $this->time,
      new AttachmentScanService(),
      $this->database,
      $this->lock,
      $this->configFactory(),
      $quota,
    );

    $this->fileRepository->expects($this->never())->method('writeData');
    $this->attachmentStorage->expects($this->never())->method('create');

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->pngFile()));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
    $this->assertSame('attachment.tenant_storage_quota_exceeded', $payload['error']['code']);
    $this->assertSame(100, $payload['error']['details']['limit']);
    $this->assertSame(158, $payload['error']['details']['projected']);
  }

  /**
   * Public attachment uploads fail closed when the service owner is missing.
   */
  public function testUploadAttachmentRequiresConfiguredServiceAccountOwner(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=');
    $form = $this->mockForm($this->schemaJson());
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->userStorage->expects($this->never())->method('loadByProperties');
    $this->fileRepository->expects($this->never())->method('writeData');
    $this->attachmentStorage->expects($this->never())->method('create');

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->pngFile()));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    $this->assertSame('attachment.owner_unavailable', $payload['error']['code']);
  }

  /**
   * The staff list prefers the stored original filename.
   */
  public function testListAttachmentsPrefersOriginalFilename(): void {
    $payload = $this->listAttachmentPayload(
      original_filename: 'unterlage-04.pdf',
      file_filename: self::LEGACY_BATCH_FILENAME,
      file_uri: self::LEGACY_BATCH_URI,
    );
    $this->assertSame('unterlage-04.pdf', $payload['data'][0]['filename']);
  }

  /**
   * Attachments without an original filename keep the file entity name.
   */
  public function testListAttachmentsKeepsFileNameWithoutOriginalFilename(): void {
    $payload = $this->listAttachmentPayload();
    $this->assertSame('evidence.pdf', $payload['data'][0]['filename']);
  }

  /**
   * A legacy batch scan uses the label of the submitted form revision.
   */
  public function testListLegacyBatchAttachmentUsesSubmittedFieldLabel(): void {
    $this->entityTypeManager->method('hasDefinition')->with('form_schema')->willReturn(TRUE);
    $revision = $this->mockForm(str_replace('Nachweis', 'Historischer Nachweis', $this->schemaJson()));
    $revision->method('getRevisionId')->willReturn(279);
    $this->formStorage->expects($this->atLeastOnce())
      ->method('loadRevision')->with(279)->willReturn($revision);

    $payload = $this->listAttachmentPayload(
      original_filename: ' ',
      file_filename: self::LEGACY_BATCH_FILENAME,
      file_uri: self::LEGACY_BATCH_URI,
      schema_revision_id: 279,
    );
    $this->assertSame('Historischer Nachweis', $payload['data'][0]['filename']);
    $this->assertSame('nachweis', $payload['data'][0]['fieldId']);
    $this->assertSame('application/pdf', $payload['data'][0]['mimeType']);
  }

  /**
   * Legacy scans without a matching file field still have a readable label.
   */
  public function testListLegacyBatchAttachmentWithoutFieldUsesNeutralLabel(): void {
    $this->controller->setStringTranslation($this->getStringTranslationStub());
    $payload = $this->listAttachmentPayload(
      file_filename: self::LEGACY_BATCH_FILENAME,
      file_uri: self::LEGACY_BATCH_URI,
      field_id: 'old_proof',
    );
    $this->assertSame('Attachment', $payload['data'][0]['filename']);
  }

  /**
   * UUID-shaped filenames from ordinary uploads retain their existing name.
   */
  public function testListUuidFilenameOutsideBatchStorageUsesFileName(): void {
    $payload = $this->listAttachmentPayload(
      file_filename: self::LEGACY_BATCH_FILENAME,
      file_uri: 'private://application_attachments/uploads/' . self::LEGACY_BATCH_FILENAME,
    );
    $this->assertSame(self::LEGACY_BATCH_FILENAME, $payload['data'][0]['filename']);
  }

  /**
   * List and download share a safe, type-preserving filename rule.
   */
  #[DataProvider('attachmentFilenameCases')]
  public function testAttachmentFilenameRule(string $original, string $stored, string $expected): void {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('get')->with('field_original_filename')->willReturn($this->fieldValue($original));
    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn($stored);
    $method = new \ReflectionMethod($this->controller, 'attachmentFilename');
    $this->assertSame($expected, $method->invoke($this->controller, $attachment, $file));
  }

  /**
   * Filename examples independent of any form schema.
   *
   * @return array<string, array{string, string, string}>
   *   Original name, file entity name and expected display name.
   */
  public static function attachmentFilenameCases(): array {
    return [
      'original' => ['unterlage-04.pdf', '57417087-80aa-4336-93ac-5048ca1cee6b.pdf', 'unterlage-04.pdf'],
      'equal' => ['api-smoke.png', 'api-smoke.png', 'api-smoke.png'],
      'fallback' => ['', 'evidence.pdf', 'evidence.pdf'],
      'jpeg alias' => ['Foto.JPEG', 'file.jpg', 'Foto.JPEG'],
      'suffix' => ['scan.pdf (Teil 2)', 'file.pdf', 'scan.pdf (Teil 2).pdf'],
      'unicode' => ['Dokument Müller.pdf', 'file.pdf', 'Dokument Müller.pdf'],
      'slash' => ['folder/scan.pdf', 'file.pdf', 'folder_scan.pdf'],
      'backslash and controls' => ["folder\\scan\r\n.pdf", 'file.pdf', 'folder_scan__.pdf'],
      'trimmed' => ['  scan.pdf  ', 'file.pdf', 'scan.pdf'],
      'empty' => ['', '', 'attachment'],
    ];
  }

  /**
   * The download carries the original Unicode name and ASCII fallback.
   */
  public function testDownloadAttachmentUsesOriginalFilename(): void {
    $tmp = $this->pngFile();
    try {
      $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
      $attachment = $this->createMock(ApplicationAttachmentInterface::class);
      $attachment->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
        'field_application' => $this->fieldTarget(54),
        'field_field_id' => $this->fieldValue('nachweis'),
        'field_file' => $this->fieldTarget(321),
        'field_original_filename' => $this->fieldValue('Dokument Müller.pdf'),
        default => $this->emptyField(),
      });
      $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn($tmp);
      $file->method('getMimeType')->willReturn('application/pdf');
      $file->method('getFilename')->willReturn('file.pdf');
      $this->fileStorage->method('load')->with(321)->willReturn($file);

      $response = $this->controller->downloadApplicationAttachment($application, self::CITIZEN_ATTACHMENT_UUID);
      $header = (string) $response->headers->get('Content-Disposition');
      $this->assertStringStartsWith('attachment;', $header);
      $this->assertStringContainsString("filename*=utf-8''Dokument%20M%C3%BCller.pdf", $header);
      $this->assertMatchesRegularExpression('/filename="[\x20-\x7E]+";/', $header);
    }
    finally {
      @unlink($tmp);
    }
  }

  /**
   * An original name is audited once, whatever the field's protection.
   */
  #[DataProvider('originalFilenameListSizes')]
  public function testListAttachmentsLogsOriginalFilenameRead(int $count, bool $sensitive): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice')->with(
      $this->stringContains('Protected application data revealed'),
      $this->callback(static fn (array $context): bool => ($context['@channel'] ?? '') === 'attachment_list'
        && !str_contains(json_encode($context), 'scan.pdf')),
    );
    $this->controller = new ApplicationAttachmentApiController(
      $this->entityTypeManager, $this->flood, $this->logger, $this->fileSystem,
      $this->fileRepository, $this->time, new AttachmentScanService(), $this->database,
      $this->lock, $this->configFactory(), NULL,
      new ApplicationDataProtectionService($this->entityTypeManager, $logger),
    );
    $payload = $this->listAttachmentPayload(original_filename: 'scan.pdf', file_sensitive: $sensitive, attachment_count: $count);
    $this->assertCount($count, $payload['data']);
  }

  /**
   * Provides list sizes.
   *
   * @return array<string, array{int, bool}>
   *   List size and field sensitivity.
   */
  public static function originalFilenameListSizes(): array {
    return ['one' => [1, TRUE], 'two' => [2, TRUE], 'unprotected field' => [1, FALSE]];
  }

  /**
   * A name equal to the stored file name needs no new audit.
   */
  #[DataProvider('filenamesWithoutNewAudit')]
  public function testListAttachmentsDoesNotLogEqualFilenames(string $original, bool $sensitive): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');
    $this->controller = new ApplicationAttachmentApiController(
      $this->entityTypeManager, $this->flood, $this->logger, $this->fileSystem,
      $this->fileRepository, $this->time, new AttachmentScanService(), $this->database,
      $this->lock, $this->configFactory(), NULL,
      new ApplicationDataProtectionService($this->entityTypeManager, $logger),
    );
    $this->listAttachmentPayload(original_filename: $original, file_sensitive: $sensitive);
  }

  /**
   * Provides names that need no additional audit.
   *
   * @return array<string, array{string, bool}>
   *   Original name and field sensitivity.
   */
  public static function filenamesWithoutNewAudit(): array {
    return ['equal' => ['evidence.pdf', TRUE], 'equal, unprotected' => ['evidence.pdf', FALSE]];
  }

  /**
   * Downloads are scoped to the application and forced to safe attachment mode.
   */
  public function testDownloadAttachmentUsesSafePrivateDownloadHeaders(): void {
    $tmp = $this->pngFile();
    try {
      $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
      $attachment = $this->createMock(ApplicationAttachmentInterface::class);
      $attachment->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
        'field_application' => $this->fieldTarget(54),
        'field_field_id' => $this->fieldValue('nachweis'),
        'field_file' => $this->fieldTarget(321),
        default => $this->emptyField(),
      });
      $this->attachmentStorage->method('loadByProperties')
        ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
        ->willReturn([$attachment]);

      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn($tmp);
      $file->method('getMimeType')->willReturn('image/png');
      $file->method('getFilename')->willReturn('scan.png');
      $this->fileStorage->method('load')->with(321)->willReturn($file);

      $response = $this->controller->downloadApplicationAttachment($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073');

      $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
      $this->assertSame('image/png', $response->headers->get('Content-Type'));
      $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
      $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
      $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
      $this->assertStringContainsString('scan.png', (string) $response->headers->get('Content-Disposition'));
    }
    finally {
      @unlink($tmp);
    }
  }

  /**
   * Every download writes its own audit row; without it nothing is served.
   */
  public function testDownloadAttachmentIsAuditedEveryTimeAndFailsClosed(): void {
    $tmp = $this->pngFile();
    try {
      $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
      $attachment = $this->createMock(ApplicationAttachmentInterface::class);
      $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
      $attachment->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
        'field_application' => $this->fieldTarget(54),
        'field_field_id' => $this->fieldValue('nachweis'),
        'field_file' => $this->fieldTarget(321),
        default => $this->emptyField(),
      });
      $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn($tmp);
      $file->method('getMimeType')->willReturn('image/png');
      $file->method('getFilename')->willReturn('scan.png');
      $this->fileStorage->method('load')->willReturn($file);
      $calls = 0;
      $this->accessLog->method('recordSubject')->willReturnCallback(function (string $type, string $uuid, $account, string $channel, $app) use (&$calls, $application): void {
        $this->assertSame(['application_attachment', '8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'attachment_download'], [
          $type,
          $uuid,
          $channel,
        ]);
        $this->assertSame($application, $app);
        if (++$calls === 3) {
          throw new \RuntimeException('audit down');
        }
      });
      $this->controller->downloadApplicationAttachment($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073');
      $this->controller->downloadApplicationAttachment($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073');
      $this->assertSame(2, $calls);
      try {
        $this->controller->downloadApplicationAttachment($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073');
        $this->fail('A download without its audit row was served.');
      }
      catch (ServiceUnavailableHttpException) {
        $this->assertSame(3, $calls);
      }
    }
    finally {
      @unlink($tmp);
    }
  }

  /**
   * Guarded derivatives use the same scoped private download headers.
   */
  public function testDownloadDerivativeUsesSafePrivateDownloadHeaders(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'guard-derivative-');
    $this->assertIsString($tmp);
    $derivative = '%PDF- guarded derivative';
    file_put_contents($tmp, $derivative);
    $guard_report = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($derivative),
        'sha256' => hash('sha256', $derivative),
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    try {
      $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
      $attachment = $this->createMock(ApplicationAttachmentInterface::class);
      $attachment->method('hasField')->willReturnCallback(
        static fn(string $field): bool => in_array($field, [
          'field_guard_status',
          'field_guard_report',
          'field_guard_derivative',
        ], TRUE),
      );
      $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
        'field_application' => $this->fieldTarget(54),
        'field_field_id' => $this->fieldValue('nachweis'),
        'field_guard_status' => $this->fieldValue('redacted'),
        'field_guard_report' => $this->fieldValue($guard_report),
        'field_guard_derivative' => $this->fieldTarget(654),
        default => $this->emptyField(),
      });
      $this->attachmentStorage->method('loadByProperties')
        ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
        ->willReturn([$attachment]);

      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn($tmp);
      $file->method('getMimeType')->willReturn('application/pdf');
      $file->method('getFilename')->willReturn('derivative.pdf');
      $this->fileStorage->method('load')->with(654)->willReturn($file);

      $response = $this->controller->downloadApplicationAttachmentDerivative(
        $application,
        '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      );

      $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
      $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
      $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
      $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
      $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
    }
    finally {
      @unlink($tmp);
    }
  }

  /**
   * Missing guarded derivatives return the stable conflict code.
   */
  public function testDownloadDerivativeReturnsConflictWhenMissing(): void {
    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturn(FALSE);
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->fileStorage->expects($this->never())->method('load');

    $response = $this->controller->downloadApplicationAttachmentDerivative(
      $application,
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.guard_derivative_missing', $payload['error']['code']);
  }

  /**
   * Derivative access fails before lookup when application view is denied.
   */
  public function testDownloadDerivativeRequiresApplicationViewAccess(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('access')->with('view')->willReturn(FALSE);
    $this->attachmentStorage->expects($this->never())->method('loadByProperties');

    $this->expectException(AccessDeniedHttpException::class);
    $this->controller->downloadApplicationAttachmentDerivative(
      $application,
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
    );
  }

  /**
   * Unsent upload reservations are absent from the application file list.
   */
  public function testMessageUploadWithoutSentCommentIsHidden(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->commentStorage->method('getQuery')->willReturn($query);
    $this->commentStorage->method('loadMultiple')->willReturn([]);
    $payload = $this->listAttachmentPayload(message_file: TRUE);
    $this->assertSame([], $payload['data']);
  }

  /**
   * Sent message files carry their context, but never the message body.
   */
  public function testMessageAttachmentCarriesPublicContext(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([19]);
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->method('id')->willReturn(19);
    $comment->method('getAuthorRole')->willReturn('citizen');
    $comment->method('getVisibility')->willReturn('public');
    $comment->method('getCreatedTime')->willReturn(1782862200);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getValue')->willReturn([['target_id' => 321]]);
    $comment->method('get')->with('field_attachments')->willReturn($items);
    $this->commentStorage->method('getQuery')->willReturn($query);
    $this->commentStorage->method('loadMultiple')->willReturn([$comment]);
    $payload = $this->listAttachmentPayload(message_file: TRUE);
    $this->assertSame([
      'id' => '19',
      'authorRole' => 'citizen',
      'visibility' => 'public',
      'createdAt' => gmdate('c', 1782862200),
    ], $payload['data'][0]['comment']);
    $this->assertArrayNotHasKey('body', $payload['data'][0]['comment']);
    $this->assertSame('22222222-2222-4222-8222-222222222222', $payload['data'][0]['fileId']);
  }

  /**
   * Internal message files are omitted entirely for a non-staff reader.
   */
  public function testInternalMessageAttachmentIsHiddenFromNonStaff(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([19]);
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->method('id')->willReturn(19);
    $comment->method('getAuthorRole')->willReturn('staff');
    $comment->method('getVisibility')->willReturn('internal');
    $comment->method('getCreatedTime')->willReturn(1782862200);
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('getValue')->willReturn([['target_id' => 321]]);
    $comment->method('get')->with('field_attachments')->willReturn($items);
    $this->commentStorage->method('getQuery')->willReturn($query);
    $this->commentStorage->method('loadMultiple')->willReturn([$comment]);
    $payload = $this->listAttachmentPayload(message_file: TRUE);
    $this->assertSame([], $payload['data']);
    $this->assertSame(0, $payload['meta']['count']);
  }

  /**
   * Clean and redacted attachments expose their complete guard verdict.
   */
  #[DataProvider('downloadableGuardStatusProvider')]
  public function testListAttachmentsExposesDownloadableGuardVerdict(string $guard_status): void {
    $guard_report = [
      'guardVersion' => '1',
      'status' => $guard_status,
      'reasons' => [],
      'advisories' => [],
      'redactionDensity' => $guard_status === 'redacted' ? 0.1 : 0,
      'source' => [
        'mediaType' => 'application/pdf',
        'size' => 2048,
        'pages' => 1,
      ],
      'extraction' => [
        'engine' => 'pdfminer.six',
        'textChars' => 24,
        'hasTextLayer' => TRUE,
      ],
      'findings' => $guard_status === 'redacted' ? [[
        'category' => 'email',
        'count' => 1,
        'pages' => [1],
      ],
      ] : [],
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => 2048,
        'sha256' => str_repeat('a', 64),
      ],
    ];
    $payload = $this->listAttachmentPayload(
      $guard_status,
      json_encode($guard_report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      TRUE,
    );

    $this->assertSame($guard_status, $payload['data'][0]['guardStatus']);
    $this->assertSame($guard_report, $payload['data'][0]['guardReport']);
    $this->assertSame(
      '/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/derivative',
      $payload['data'][0]['guardDerivativeUrl'],
    );
  }

  /**
   * Provides guard statuses that permit derivative downloads.
   *
   * @return array<string, array{string}>
   *   Downloadable guard statuses.
   */
  public static function downloadableGuardStatusProvider(): array {
    return [
      'clean' => ['clean'],
      'redacted' => ['redacted'],
    ];
  }

  /**
   * Attachments created before the guard fields existed expose safe defaults.
   */
  public function testListAttachmentsDefaultsMissingGuardFields(): void {
    $payload = $this->listAttachmentPayload();

    $this->assertSame('', $payload['data'][0]['guardStatus']);
    $this->assertNull($payload['data'][0]['guardReport']);
    $this->assertNull($payload['data'][0]['guardDerivativeUrl']);
  }

  /**
   * Malformed guard report JSON is not exposed and does not fail the list.
   */
  public function testListAttachmentsIgnoresMalformedGuardReport(): void {
    $payload = $this->listAttachmentPayload('clean', '{not-json', TRUE);

    $this->assertNull($payload['data'][0]['guardReport']);
  }

  /**
   * Review-required attachments never expose a derivative URL.
   */
  public function testListAttachmentsHidesDerivativeForNeedsReviewGuard(): void {
    $payload = $this->listAttachmentPayload(
      'needs_review',
      '{"status":"needs_review","reasons":["annotations_present"]}',
      TRUE,
    );

    $this->assertSame('needs_review', $payload['data'][0]['guardStatus']);
    $this->assertNull($payload['data'][0]['guardDerivativeUrl']);
  }

  /**
   * Quarantined attachments cannot be downloaded even by scoped staff.
   */
  public function testDownloadAttachmentRejectsQuarantinedFile(): void {
    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, ['field_scan_status', 'field_scan_message'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_file' => $this->fieldTarget(321),
      'field_scan_status' => $this->fieldValue('quarantined'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
      ->willReturn([$attachment]);

    $this->expectException(AccessDeniedHttpException::class);
    $this->controller->downloadApplicationAttachment($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073');
  }

  /**
   * Sensitive quarantine metadata stays hidden without reveal access.
   */
  public function testListAttachmentsHidesSensitiveQuarantineMetadataWithoutProtectedDataAccess(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('field_application', 54)->willReturnSelf();
    $query->method('sort')->with('created', 'ASC')->willReturnSelf();
    $query->method('execute')->willReturn([91]);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_scan_message'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('quarantined'),
      'field_scan_message' => $this->fieldValue('EICAR test signature detected.'),
      'field_file' => $this->fieldTarget(321),
      default => $this->emptyField(),
    });

    $this->attachmentStorage->method('getQuery')->willReturn($query);
    $this->attachmentStorage->method('loadMultiple')->with([91])->willReturn([91 => $attachment]);
    $this->fileStorage->expects($this->never())->method('load');

    $response = $this->controller->listApplicationAttachments(
      $this->mockApplication($this->schemaJson(FALSE, TRUE)),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([], $payload['data']);
    $this->assertSame(0, $payload['meta']['count']);
  }

  /**
   * Analysis metadata updates require the trusted Nuxt proxy header.
   */
  public function testUpdateAttachmentAnalysisRequiresTrustedProxy(): void {
    $this->attachmentStorage->expects($this->never())->method('loadByProperties');

    $response = $this->controller->updateAttachmentAnalysis(
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis', 'POST'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('attachment.access_denied', $payload['error']['code']);
  }

  /**
   * Trusted analysis metadata updates persist a bounded JSON object.
   */
  public function testUpdateAttachmentAnalysisPersistsMetadata(): void {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $set_calls = [];
    $attachment->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(static function (string $field, mixed $value) use (&$set_calls, $attachment): ApplicationAttachmentInterface {
        $set_calls[] = [$field, $value];
        return $attachment;
      });
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
      ->willReturn([$attachment]);

    $request = Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      [],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      json_encode([
        'status' => 'complete',
        'metadata' => [
          'version' => 1,
          'status' => 'failed',
          'language' => 'en',
          'result' => [
            'outcome' => 'pass',
            'summary' => "Line\x01break OK",
            'missing' => ['Required scale cannot be confirmed.'],
            'missingCount' => 1,
            'missingKeys' => ['scale_shown'],
            'extracted' => [
              ['key' => 'owner', 'value' => 'Max Mustermann'],
              ['key' => 'scale_shown', 'value' => FALSE, 'type' => 'boolean'],
            ],
          ],
          'bad key' => 'dropped',
        ],
      ], JSON_THROW_ON_ERROR),
    );

    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('complete', $payload['data']['analysisStatus']);
    $this->assertSame('needs_review', $payload['data']['analysisMetadata']['result']['outcome']);
    $this->assertSame('field_analysis_status', $set_calls[0][0]);
    $this->assertSame('complete', $set_calls[0][1]);
    $this->assertSame('field_analysis_metadata', $set_calls[1][0]);
    $decoded_metadata = json_decode((string) $set_calls[1][1], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('en', $decoded_metadata['language']);
    $this->assertSame('complete', $decoded_metadata['status']);
    $this->assertArrayNotHasKey('summary', $decoded_metadata['result']);
    $this->assertArrayNotHasKey('missing', $decoded_metadata['result']);
    $this->assertSame(['scale_shown'], $decoded_metadata['result']['missingKeys']);
    $this->assertSame([
      ['key' => 'scale_shown', 'value' => FALSE, 'type' => 'boolean'],
    ], (new ApplicationDataProtectionService($this->entityTypeManager, $this->logger))->revealAnalysisResult($decoded_metadata['result'])['extracted']);
    $this->assertStringNotContainsString('Max Mustermann', (string) $set_calls[1][1]);
    $this->assertArrayNotHasKey('bad key', $decoded_metadata);
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * Empty JSON metadata objects are accepted for pending analyses.
   */
  public function testUpdateAttachmentAnalysisAcceptsEmptyMetadataObject(): void {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $set_calls = [];
    $attachment->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(static function (string $field, mixed $value) use (&$set_calls, $attachment): ApplicationAttachmentInterface {
        $set_calls[] = [$field, $value];
        return $attachment;
      });
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
      ->willReturn([$attachment]);

    $request = Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      [],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      '{"status":"pending","metadata":{}}',
    );

    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('pending', $payload['data']['analysisStatus']);
    $this->assertSame(['status' => 'pending'], $payload['data']['analysisMetadata']);
    $this->assertSame('field_analysis_metadata', $set_calls[1][0]);
    $this->assertSame('{"status":"pending"}', $set_calls[1][1]);
  }

  /**
   * Invalid analysis status values are rejected before saving.
   */
  public function testUpdateAttachmentAnalysisRejectsInvalidStatus(): void {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $attachment->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $request = Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      [],
      [],
      [],
      [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      json_encode(['status' => 'done', 'metadata' => []], JSON_THROW_ON_ERROR),
    );

    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $request);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('attachment.invalid_analysis', $payload['error']['code']);
  }

  /**
   * Quarantined attachments can be deleted with an application audit revision.
   */
  public function testDeleteQuarantinedAttachmentDeletesFileAndWritesApplicationRevision(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(17);
    $account->method('getDisplayName')->willReturn('Security Admin');
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $application->expects($this->once())->method('setNewRevision')->with(TRUE);
    $application->expects($this->once())->method('setRevisionUserId')->with(17);
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1782862200);
    $application->expects($this->once())
      ->method('setRevisionLogMessage')
      ->with('Citizen will upload clean copy.');
    $application->expects($this->once())->method('save');
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $application->method('getRevisionId')->willReturn(88);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('label')->willReturn('malware.pdf');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_scan_message'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_file' => $this->fieldTarget(321),
      'field_scan_status' => $this->fieldValue('quarantined'),
      'field_scan_message' => $this->fieldValue('EICAR test signature detected.'),
      'field_original_filename' => $this->fieldValue('malware.pdf'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')
      ->with(['uuid' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073'])
      ->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->once())
      ->method('delete')
      ->with([$attachment]);

    $file = $this->createMock(FileInterface::class);
    $this->fileStorage->method('load')->with(321)->willReturn($file);
    $this->fileStorage->expects($this->once())
      ->method('delete')
      ->with([$file]);
    $rolled_back = FALSE;
    $transaction = $this->transaction(static function () use (&$rolled_back): void {
      $rolled_back = TRUE;
    });
    $this->database->expects($this->once())
      ->method('startTransaction')
      ->with('application_attachment_quarantine_delete')
      ->willReturn($transaction);

    $this->logger->expects($this->once())->method('notice')->with(
      'Quarantined attachment @attachment for application @application deleted by uid @uid. Scanner message: @message',
      [
        '@attachment' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
        '@application' => '9a67f32b-93cc-48c5-8899-9d5470f57f44',
        '@uid' => '17',
        '@message' => 'EICAR test signature detected.',
      ],
    );

    $request = Request::create(
      '/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      'DELETE',
      [],
      [],
      [],
      [],
      json_encode(['reason' => "Citizen\nwill\t upload \r clean copy."], JSON_THROW_ON_ERROR),
    );
    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $application,
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      $request,
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(TRUE, $payload['data']['deleted']);
    $this->assertSame(88, $payload['meta']['revisionId']);
    $this->assertFalse($rolled_back);
  }

  /**
   * A transfer holding the case refuses the deletion with 423, not 500.
   */
  public function testDeleteQuarantinedAttachmentAnswersLockedWhileTransferHoldsCase(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(17);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    // Entity storage wraps the presave lock failure.
    $application->expects($this->once())->method('save')->willThrowException(new EntityStorageException(
      'The application is currently being deleted or delivered.',
      0,
      new ApplicationDeletionLockedException('The application is currently being deleted or delivered.'),
    ));

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_scan_message'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_file' => $this->fieldTarget(321),
      'field_scan_status' => $this->fieldValue('quarantined'),
      'field_scan_message' => $this->fieldValue('EICAR test signature detected.'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->fileStorage->method('load')->with(321)->willReturn($this->createMock(FileInterface::class));
    $this->fileStorage->expects($this->never())->method('delete');
    $this->logger->expects($this->never())->method('error');
    $rolled_back = FALSE;
    $transaction = $this->transaction(static function () use (&$rolled_back): void {
      $rolled_back = TRUE;
    });
    $this->database->expects($this->once())
      ->method('startTransaction')
      ->with('application_attachment_quarantine_delete')
      ->willReturn($transaction);

    try {
      $this->controller->deleteQuarantinedApplicationAttachment(
        $application,
        '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
        Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
      );
      $this->fail('A locked case must not delete the attachment.');
    }
    catch (ApplicationLockedHttpException $exception) {
      $this->assertSame(423, $exception->getStatusCode());
    }
    $this->assertTrue($rolled_back);
  }

  /**
   * Clean attachments are not accepted by the quarantine delete endpoint.
   */
  public function testDeleteQuarantinedAttachmentRejectsCleanAttachment(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->fileStorage->expects($this->never())->method('delete');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $application,
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.not_quarantined', $payload['error']['code']);
  }

  /**
   * Sensitive clean attachment UUIDs do not expose quarantine state.
   */
  public function testDeleteQuarantinedAttachmentChecksFieldAccessBeforeStatus(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->database->expects($this->never())->method('startTransaction');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $this->mockApplication($this->schemaJson(FALSE, TRUE)),
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('attachment.access_denied', $payload['error']['code']);
  }

  /**
   * The quarantine delete endpoint denies users without the explicit role.
   */
  public function testDeleteQuarantinedAttachmentRequiresPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $this->setCurrentUser($account);

    $this->attachmentStorage->expects($this->never())->method('loadByProperties');
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->database->expects($this->never())->method('startTransaction');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $this->mockApplication($this->schemaJson(FALSE, FALSE)),
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('attachment.access_denied', $payload['error']['code']);
  }

  /**
   * Attachments from another application are hidden as not found.
   */
  public function testDeleteQuarantinedAttachmentRejectsWrongApplicationScope(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(999),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->database->expects($this->never())->method('startTransaction');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $this->mockApplication($this->schemaJson(FALSE, FALSE)),
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('attachment.not_found', $payload['error']['code']);
  }

  /**
   * Invalid audit reasons are rejected before destructive work starts.
   */
  public function testDeleteQuarantinedAttachmentRejectsMalformedReason(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('quarantined'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->database->expects($this->never())->method('startTransaction');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $this->mockApplication($this->schemaJson(FALSE, FALSE)),
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create(
        '/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073',
        'DELETE',
        [],
        [],
        [],
        [],
        json_encode(['reason' => ['not' => 'text']], JSON_THROW_ON_ERROR),
      ),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('attachment.invalid_request', $payload['error']['code']);
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * Failed audit revision saves roll back and keep the attachment entities.
   */
  public function testDeleteQuarantinedAttachmentRollsBackWhenAuditRevisionFails(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(17);
    $account->method('getDisplayName')->willReturn('Security Admin');
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $application->expects($this->once())->method('save')->willThrowException(new \RuntimeException('revision failed'));

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('label')->willReturn('malware.pdf');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_scan_message'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_file' => $this->fieldTarget(321),
      'field_scan_status' => $this->fieldValue('quarantined'),
      'field_scan_message' => $this->fieldValue('EICAR test signature detected.'),
      'field_original_filename' => $this->fieldValue('malware.pdf'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->never())->method('delete');
    $this->fileStorage->method('load')->with(321)->willReturn($this->createMock(FileInterface::class));
    $this->fileStorage->expects($this->never())->method('delete');
    $rolled_back = FALSE;
    $transaction = $this->transaction(static function () use (&$rolled_back): void {
      $rolled_back = TRUE;
    });
    $this->database->expects($this->once())->method('startTransaction')->willReturn($transaction);
    $this->logger->expects($this->once())->method('error');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $application,
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    $this->assertSame('attachment.delete_failed', $payload['error']['code']);
    $this->assertTrue($rolled_back);
  }

  /**
   * Attachment delete failures roll back and skip file deletion.
   */
  public function testDeleteQuarantinedAttachmentRollsBackWhenAttachmentDeleteFails(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(17);
    $account->method('getDisplayName')->willReturn('Security Admin');
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'manage quarantined application attachments',
    );
    $this->setCurrentUser($account);

    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE));
    $application->method('uuid')->willReturn('9a67f32b-93cc-48c5-8899-9d5470f57f44');
    $application->expects($this->once())->method('save');

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('label')->willReturn('malware.pdf');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_scan_message'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_file' => $this->fieldTarget(321),
      'field_scan_status' => $this->fieldValue('quarantined'),
      'field_scan_message' => $this->fieldValue('EICAR test signature detected.'),
      'field_original_filename' => $this->fieldValue('malware.pdf'),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $this->attachmentStorage->expects($this->once())
      ->method('delete')
      ->with([$attachment])
      ->willThrowException(new \RuntimeException('delete failed'));
    $this->fileStorage->method('load')->with(321)->willReturn($this->createMock(FileInterface::class));
    $this->fileStorage->expects($this->never())->method('delete');
    $rolled_back = FALSE;
    $transaction = $this->transaction(static function () use (&$rolled_back): void {
      $rolled_back = TRUE;
    });
    $this->database->expects($this->once())->method('startTransaction')->willReturn($transaction);
    $this->logger->expects($this->once())->method('error');

    $response = $this->controller->deleteQuarantinedApplicationAttachment(
      $application,
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      Request::create('/api/admin/applications/54/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'DELETE'),
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    $this->assertSame('attachment.delete_failed', $payload['error']['code']);
    $this->assertTrue($rolled_back);
  }

  /**
   * A case worker's rerun starts only that story and queues it, no bytes.
   */
  public function testRerunOfCompleteStoryQueuesNewRunAndLeavesOtherStory(): void {
    $citizen = [
      'status' => 'complete',
      'code' => 'analysis_complete',
      'analysisRunId' => str_repeat('a', 32),
      'result' => ['outcome' => 'pass'],
    ];
    [, $state] = $this->rerunAttachment($this->storyAnalysis(), [
      'version' => 2,
      'status' => 'complete',
      'stories' => [
        'citizen' => $citizen,
        'staff' => ['status' => 'complete', 'code' => 'analysis_complete', 'analysisRunId' => str_repeat('b', 32)],
      ],
    ]);
    $item = NULL;
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem')->willReturnCallback(
      static function (array $data) use (&$item): string {
        $item = $data;
        return '1';
      },
    );
    $controller = $this->rerunController($queue);

    $response = $controller->rerunApplicationAttachmentAnalysis(
      $this->mockApplication($this->schemaJson(FALSE, FALSE, analysis: $this->storyAnalysis())),
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      'staff',
    );

    $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('pending', $payload['data']['analysisStatus']);
    $this->assertSame('staff', $payload['data']['analysisStory']);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($citizen, $stored['stories']['citizen']);
    $this->assertSame('pending', $stored['stories']['staff']['status']);
    $this->assertNotSame(str_repeat('b', 32), $stored['stories']['staff']['analysisRunId']);
    $this->assertSame([
      'attachmentId' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      'applicationId' => 54,
      'story' => 'staff',
      'analysisRunId' => $stored['stories']['staff']['analysisRunId'],
    ], $item);
  }

  /**
   * A guard block stays final and a story outside its policy never reruns.
   */
  public function testRerunRefusesGuardBlockedAndDisallowedStory(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');
    $profile = $this->storyAnalysis('external_redacted', 'claude_sonnet');
    $application = $this->mockApplication($this->schemaJson(FALSE, FALSE, analysis: $profile));
    $blocked = ['status' => 'failed', 'code' => 'guard_blocked', 'analysisRunId' => str_repeat('c', 32)];
    [, $state] = $this->rerunAttachment($profile, [
      'version' => 2,
      'status' => 'failed',
      'stories' => ['citizen' => $blocked],
    ]);
    $controller = $this->rerunController($queue);

    $response = $controller->rerunApplicationAttachmentAnalysis($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'citizen');
    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.guard_blocked', json_decode((string) $response->getContent(), TRUE)['error']['code']);
    $this->assertSame($blocked, json_decode((string) $state['field_analysis_metadata'], TRUE)['stories']['citizen']);

    // Story B reads purpose-bound values and never runs outside sovereign_only.
    $response = $controller->rerunApplicationAttachmentAnalysis($application, '8cf1ce03-5c64-4098-8b1c-a8322a62e073', 'staff');
    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_not_available', json_decode((string) $response->getContent(), TRUE)['error']['code']);
  }

  /**
   * Page facts of the PDF chain survive the allowlist; unknown values do not.
   */
  public function testStoryWriteKeepsRenderedPageFactsAndNotices(): void {
    $run_id = str_repeat('d', 32);
    [, $state] = $this->storyUpdateAttachment([
      'version' => 2,
      'status' => 'pending',
      'stories' => ['staff' => ['status' => 'pending', 'analysisRunId' => $run_id, 'analysisStartedAt' => 1782862000]],
    ]);
    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->storyUpdateRequest('staff', $run_id, [
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
      'source' => [
        'mimeType' => 'application/pdf',
        'renderedFrom' => 'original',
        'pages' => 3,
        'pageCount' => 5,
        'truncated' => TRUE,
        'redaction' => 'unredacted_needs_review',
        'pageImages' => ['must not be stored'],
      ],
      'result' => [
        'outcome' => 'pass',
        'notices' => ['pages_truncated', 'unredacted_scan', 'free text'],
        'pagesChecked' => 3,
      ],
    ]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([
      'mimeType' => 'application/pdf',
      'renderedFrom' => 'original',
      'pages' => 3,
      'pageCount' => 5,
      'truncated' => TRUE,
      'redaction' => 'unredacted_needs_review',
    ], $stored['stories']['staff']['source']);
    $this->assertSame(['pages_truncated', 'unredacted_scan'], $stored['stories']['staff']['result']['notices']);
    $this->assertSame(3, $stored['stories']['staff']['result']['pagesChecked']);
    // A notice never lets a pass stand: the case worker looks again.
    $this->assertSame('needs_review', $stored['stories']['staff']['result']['outcome']);
  }

  /**
   * A story write keeps where the run sent the file, as one line of text.
   *
   * The location is an operator label with spaces and umlauts, so it does
   * not pass the token rule of name and model; control characters and
   * unknown provider keys never reach the record.
   */
  public function testStoryWriteKeepsProviderLocationAsBoundedLabel(): void {
    $run_id = str_repeat('e', 32);
    [, $state] = $this->storyUpdateAttachment([
      'version' => 2,
      'status' => 'pending',
      'stories' => ['staff' => ['status' => 'pending', 'analysisRunId' => $run_id, 'analysisStartedAt' => 1782862000]],
    ]);
    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->storyUpdateRequest('staff', $run_id, [
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
      'provider' => [
        'name' => 'sovereign',
        'model' => 'Qwen/Qwen3.8-27B',
        'location' => " Rechenzentrum\x01in\n  Deutschland (Falkenquell) ",
        'baseUrl' => 'https://must-not-be-stored.example',
      ],
      'result' => ['outcome' => 'unknown'],
    ]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([
      'name' => 'sovereign',
      'model' => 'Qwen/Qwen3.8-27B',
      'location' => 'Rechenzentrum in Deutschland (Falkenquell)',
    ], $stored['stories']['staff']['provider']);
    $this->assertStringNotContainsString('must-not-be-stored', (string) $state['field_analysis_metadata']);
  }

  /**
   * The location label is capped and dropped when it is not a usable string.
   *
   * @param mixed $location
   *   Location sent by the analysis service.
   * @param array<string, string> $expected
   *   Stored provider record.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('providerLocationCases')]
  public function testUpdateAttachmentAnalysisBoundsProviderLocation(mixed $location, array $expected): void {
    $stored = $this->persistedV1Metadata([
      'version' => 1,
      'status' => 'complete',
      'provider' => ['name' => 'claude', 'model' => 'claude-sonnet-4-6', 'location' => $location],
    ]);
    $this->assertSame($expected, $stored['provider']);
  }

  /**
   * Location inputs and the provider record that survives them.
   *
   * @return array<string, array{0: mixed, 1: array<string, string>}>
   *   Cases.
   */
  public static function providerLocationCases(): array {
    $base = ['name' => 'claude', 'model' => 'claude-sonnet-4-6'];
    return [
      'spaces and umlauts' => [
        'Rechenzentrum in Deutschland, Silberhain',
        $base + ['location' => 'Rechenzentrum in Deutschland, Silberhain'],
      ],
      'capped at 120 characters' => [str_repeat('Ä', 130), $base + ['location' => str_repeat('Ä', 120)]],
      'not a string' => [['USA'], $base],
      'only white space' => ["  \t ", $base],
      'control characters only' => ["\x01\x02", $base],
    ];
  }

  /**
   * Persists v1 metadata through the update route and returns the record.
   *
   * @param array<string, mixed> $metadata
   *   Metadata sent by the analysis service.
   *
   * @return array<string, mixed>
   *   Decoded stored metadata.
   */
  private function persistedV1Metadata(array $metadata): array {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_scan_status' => $this->fieldValue('clean'),
      default => $this->emptyField(),
    });
    $stored = '';
    $attachment->method('set')->willReturnCallback(static function (string $field, mixed $value) use (&$stored, $attachment): ApplicationAttachmentInterface {
      if ($field === 'field_analysis_metadata') {
        $stored = (string) $value;
      }
      return $attachment;
    });
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      [],
      [],
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret')],
      json_encode(['status' => 'complete', 'metadata' => $metadata], JSON_THROW_ON_ERROR),
    ));
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    return json_decode($stored, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * An upload run of story A failing after the claim queues story A again.
   *
   * The claim-time enqueue skipped story A while that run held its lease.
   * A queue run (with a worker record) keeps the queue's retry policy and
   * is never queued from here.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('failedCitizenRunCases')]
  public function testFailedUploadRunAfterClaimQueuesCitizenStory(bool $queue_run, string $status, int $expected_items): void {
    $run_id = str_repeat('a', 32);
    $citizen = ['status' => 'pending', 'analysisRunId' => $run_id, 'analysisStartedAt' => 1782862100];
    if ($queue_run) {
      $citizen['worker'] = ['attempt' => 1, 'maxAttempts' => 3];
    }
    $form = $this->mockForm($this->schemaJson(FALSE, FALSE, analysis: $this->storyAnalysis('external_allowed', 'claude_sonnet')));
    $form->method('uuid')->willReturn('fbc3b086-ac53-412e-9bbf-ccb03f286923');
    $state = new \ArrayObject([
      'field_analysis_status' => 'pending',
      'field_analysis_metadata' => json_encode([
        'version' => 2,
        'status' => 'pending',
        'language' => 'de',
        'stories' => ['citizen' => $citizen],
      ], JSON_THROW_ON_ERROR),
    ]);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_schema' => $this->entityReference($form),
      'field_file' => $this->fieldTarget(321),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_external_analysis_consent' => $this->fieldValue('1'),
      'field_analysis_status', 'field_analysis_metadata' => $this->fieldValue($state[$field]),
      default => $this->emptyField(),
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state): ApplicationAttachmentInterface {
        $state[$field] = $value;
        return $attachment;
      },
    );
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    $items = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(
      static function (array $data) use (&$items): string {
        $items[] = $data;
        return '1';
      },
    );

    $response = $this->rerunController($queue)->updateAttachmentAnalysis(
      '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
      $this->storyUpdateRequest('citizen', $run_id, [
        'version' => 1,
        'status' => $status,
        'code' => $status === 'failed' ? 'analysis_failed' : 'analysis_complete',
        'language' => 'de',
      ]),
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame($status, json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR)['stories']['citizen']['status']);
    $this->assertCount($expected_items, $items);
    if ($expected_items > 0) {
      $this->assertSame([
        'attachmentId' => '8cf1ce03-5c64-4098-8b1c-a8322a62e073',
        'applicationId' => 54,
        'story' => 'citizen',
      ], $items[0]);
    }
  }

  /**
   * Upload or queue run, written status, expected queue items.
   *
   * @return array<string, array{0: bool, 1: string, 2: int}>
   *   Cases.
   */
  public static function failedCitizenRunCases(): array {
    return [
      'failed upload run' => [FALSE, 'failed', 1],
      'complete upload run' => [FALSE, 'complete', 0],
      'failed queue run' => [TRUE, 'failed', 0],
    ];
  }

  /**
   * Builds a bound attachment for the rerun route.
   *
   * @param array<string, mixed> $analysis
   *   Field analysis profile.
   * @param array<string, mixed> $metadata
   *   Stored analysis metadata.
   *
   * @return array{0: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface, 1: \ArrayObject<string, mixed>}
   *   Attachment and state.
   */
  private function rerunAttachment(array $analysis, array $metadata): array {
    $form = $this->mockForm($this->schemaJson(FALSE, FALSE, analysis: $analysis));
    $state = new \ArrayObject([
      'field_analysis_status' => (string) ($metadata['status'] ?? 'not_requested'),
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ]);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->fieldTarget(54),
      'field_schema' => $this->entityReference($form),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status', 'field_analysis_metadata' => $this->fieldValue($state[$field]),
      default => $this->emptyField(),
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state): ApplicationAttachmentInterface {
        $state[$field] = $value;
        return $attachment;
      },
    );
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    return [$attachment, $state];
  }

  /**
   * Builds a controller whose dispatcher writes into the given queue.
   */
  private function rerunController(QueueInterface $queue): ApplicationAttachmentApiController {
    $account = $this->mockAccount(5, ['authenticated', 'case_worker']);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => $permission === 'view protected application data',
    );
    $this->setCurrentUser($account);
    $factory = $this->createMock(QueueFactory::class);
    $factory->method('get')->with(AttachmentAnalysisDispatcher::QUEUE_NAME)->willReturn($queue);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    return new ApplicationAttachmentApiController(
      $this->entityTypeManager,
      $this->flood,
      $this->logger,
      $this->fileSystem,
      $this->fileRepository,
      $this->time,
      new AttachmentScanService(),
      $this->database,
      $this->lock,
      $this->configFactory(),
      NULL,
      NULL,
      NULL,
      new AttachmentAnalysisDispatcher($this->entityTypeManager, $factory, $http_client, $this->logger),
    );
  }

  /**
   * Story profile used by the per-story tests.
   *
   * @return array<string, mixed>
   *   Analysis profile.
   */
  private function storyAnalysis(string $egress_policy = 'sovereign_only', string $model_class = 'openai_compatible'): array {
    return [
      'enabled' => TRUE,
      'citizenPrompt' => 'Prüfe, ob es eine lesbare Gehaltsabrechnung ist.',
      'staffPrompt' => 'Lies das Bruttoentgelt aus.',
      'egressPolicy' => $egress_policy,
      'modelClass' => $model_class,
      'resultSchema' => [
        'type' => 'object',
        'properties' => [
          'legible' => ['type' => 'boolean', 'title' => 'Lesbar', 'nonPersonal' => TRUE],
          'gross_pay_month' => ['type' => 'number', 'title' => 'Bruttoentgelt', 'purposeBound' => TRUE],
        ],
      ],
    ];
  }

  /**
   * The trusted upload of a sovereign story profile mints the run of story A.
   */
  public function testUploadAttachmentMintsCitizenRunForStoryProfile(): void {
    $created_values = $this->uploadStoryAttachment(TRUE);
    [$created, $payload] = $created_values;

    $metadata = json_decode((string) $created['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('pending', $created['field_analysis_status']);
    $this->assertSame(2, $metadata['version']);
    $this->assertSame('de', $metadata['language']);
    $this->assertSame(['citizen'], array_keys($metadata['stories']));
    $this->assertSame('pending', $metadata['stories']['citizen']['status']);
    $this->assertSame(1782862200, $metadata['stories']['citizen']['analysisStartedAt']);
    $this->assertSame($metadata['stories']['citizen']['analysisRunId'], $payload['data']['analysisRunId']);
    $this->assertSame('citizen', $payload['data']['analysisStory']);
    $this->assertSame('pending', $payload['data']['analysisStatus']);
  }

  /**
   * A direct, untrusted upload never starts a story run nobody would finish.
   */
  public function testUploadAttachmentWithoutTrustedProxyKeepsStartRecord(): void {
    [$created, $payload] = $this->uploadStoryAttachment(FALSE);

    $this->assertSame('not_requested', $created['field_analysis_status']);
    $this->assertSame(
      ['version' => 1, 'status' => 'not_requested', 'language' => 'de'],
      json_decode((string) $created['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR),
    );
    $this->assertArrayNotHasKey('analysisRunId', $payload['data']);
  }

  /**
   * Later consent on a story profile starts story A only.
   */
  public function testConsentAttachmentAnalysisStartsCitizenStoryRun(): void {
    $form_uuid = 'fbc3b086-ac53-412e-9bbf-ccb03f286923';
    $attachment_uuid = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
    $claim_token = 'abcdefghijklmnopqrstuvwxyzABCDEF';
    $form = $this->mockForm($this->schemaJson(analysis: $this->storyAnalysis('external_allowed', 'claude_sonnet')));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $path = tempnam(sys_get_temp_dir(), 'beantragt_analysis_consent_');
    $this->assertIsString($path);
    file_put_contents($path, '%PDF-1.4 canonical fixture');
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($path);
    $file->method('getSize')->willReturn((int) filesize($path));

    $state = new \ArrayObject([
      'field_analysis_status' => 'not_requested',
      'field_analysis_metadata' => '{"version":1,"status":"not_requested","language":"de"}',
    ]);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, [
        'field_scan_status',
        'field_external_analysis_consent',
        'field_analysis_metadata',
      ], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_claim_expires' => $this->fieldValue(1782865800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', $claim_token)),
      'field_file' => $this->entityReference($file),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_analysis_status', 'field_analysis_metadata' => $this->fieldValue($state[$field]),
      default => $this->emptyField(),
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state): ApplicationAttachmentInterface {
        $state[$field] = $value;
        return $attachment;
      },
    );
    $attachment->expects($this->once())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $response = $this->controller->consentAttachmentAnalysis($attachment_uuid, Request::create(
      '/api/v1/attachments/' . $attachment_uuid . '/analysis-consent',
      'POST',
      [
        'formId' => $form_uuid,
        'fieldId' => 'nachweis',
        'claimToken' => $claim_token,
        'language' => 'en',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret')],
    ));
    unlink($path);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('citizen', $response->headers->get('X-Beantragt-Analysis-Story'));
    $this->assertTrue($state['field_external_analysis_consent']);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['citizen'], array_keys($stored['stories']));
    $this->assertSame('en', $stored['language']);
    $this->assertSame($response->headers->get('X-Beantragt-Analysis-Run-Id'), $stored['stories']['citizen']['analysisRunId']);
  }

  /**
   * Consent never starts story A on a story profile that has none.
   *
   * Same rule as the upload path: without citizenPrompt and without
   * nonPersonal values there is nothing for the upload check to report.
   */
  public function testConsentAttachmentAnalysisRefusesStoryProfileWithoutCitizenStory(): void {
    $analysis = $this->storyAnalysis('external_allowed', 'claude_sonnet');
    unset($analysis['citizenPrompt'], $analysis['resultSchema']['properties']['legible']);
    $form = $this->mockForm($this->schemaJson(analysis: $analysis));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->attachmentStorage->expects($this->never())->method('loadByProperties');

    $response = $this->controller->consentAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->consentRequest());

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_not_allowed', json_decode((string) $response->getContent(), TRUE)['error']['code']);
  }

  /**
   * Consent keeps a legacy run from before a switch to a story profile.
   */
  public function testConsentAttachmentAnalysisKeepsLegacyRunAfterProfileSwitch(): void {
    $form = $this->mockForm($this->schemaJson(analysis: $this->storyAnalysis('external_allowed', 'claude_sonnet')));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $legacy = '{"version":1,"status":"complete","code":"analysis_complete","language":"de","result":{"outcome":"pass"}}';
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldTarget(12),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status' => $this->fieldValue('complete'),
      'field_analysis_metadata' => $this->fieldValue($legacy),
      default => $this->emptyField(),
    });
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);

    $response = $this->controller->consentAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->consentRequest());

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('attachment.analysis_already_started', json_decode((string) $response->getContent(), TRUE)['error']['code']);
  }

  /**
   * A legacy callback in flight during a switch to a story profile lands.
   */
  public function testUpdateAttachmentAnalysisLegacyRunLandsAfterSwitchToStoryProfile(): void {
    $this->assertLegacyRunWriteLands(NULL);
  }

  /**
   * A 'citizen' write against a legacy record takes the legacy path.
   *
   * Story A is narrower than the legacy check, so Nuxt honours a requested
   * citizen run on any profile; Drupal checks it against the legacy run id.
   */
  public function testUpdateAttachmentAnalysisCitizenWriteOnLegacyRecordTakesLegacyPath(): void {
    $this->assertLegacyRunWriteLands('citizen');
  }

  /**
   * Writes a result for a pending legacy run and asserts the v1 record.
   */
  private function assertLegacyRunWriteLands(?string $story): void {
    $run_id = str_repeat('b', 32);
    [, $state] = $this->storyUpdateAttachment([
      'version' => 1,
      'status' => 'pending',
      'language' => 'de',
      'analysisRunId' => $run_id,
      'analysisStartedAt' => 1782862000,
    ]);
    $request = $this->storyUpdateRequest('citizen', $run_id, [
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
    ]);
    $payload = json_decode((string) $request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    if ($story === NULL) {
      unset($payload['story']);
    }
    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode($payload, JSON_THROW_ON_ERROR),
    ));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertArrayNotHasKey('stories', $stored);
    $this->assertSame('complete', $stored['status']);
    $this->assertSame('complete', $state['field_analysis_status']);
    $this->assertSame($run_id, $stored['analysisRunId']);
  }

  /**
   * Builds a trusted consent request for the standard claim.
   */
  private function consentRequest(): Request {
    return Request::create(
      '/api/v1/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis-consent',
      'POST',
      [
        'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
        'fieldId' => 'nachweis',
        'claimToken' => 'abcdefghijklmnopqrstuvwxyzABCDEF',
        'language' => 'de',
        'analysisConsent' => 'true',
      ],
      [],
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret')],
    );
  }

  /**
   * Results of story A and story B arrive in any order and both survive.
   */
  public function testUpdateAttachmentAnalysisStoryWritesKeepTheOtherStory(): void {
    $citizen_run = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $staff_run = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    [$attachment, $state] = $this->storyUpdateAttachment([
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => [
        'citizen' => ['status' => 'pending', 'analysisRunId' => $citizen_run, 'analysisStartedAt' => 1782862100],
        'staff' => [
          'status' => 'pending',
          'analysisRunId' => $staff_run,
          'analysisStartedAt' => 1782862150,
          'worker' => ['attempt' => 1, 'maxAttempts' => 3],
        ],
      ],
    ]);
    $attachment->expects($this->exactly(2))->method('save');

    $staff = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->storyUpdateRequest('staff', $staff_run, [
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
      'promptVersion' => 'attachment-analysis:v3',
      'profile' => ['version' => 1, 'egressPolicy' => 'sovereign_only', 'modelClass' => 'openai_compatible'],
      'result' => [
        'outcome' => 'unknown',
        'missingCount' => 0,
        'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480]],
      ],
    ]));
    $this->assertSame(Response::HTTP_OK, $staff->getStatusCode());
    $this->assertSame('pending', json_decode((string) $staff->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data']['analysisStatus']);

    $citizen = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->storyUpdateRequest('citizen', $citizen_run, [
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
      'language' => 'de',
      'result' => [
        'outcome' => 'pass',
        'missingCount' => 0,
        'extracted' => [['key' => 'legible', 'type' => 'boolean', 'value' => TRUE]],
      ],
    ]));
    $this->assertSame(Response::HTTP_OK, $citizen->getStatusCode());

    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('complete', $state['field_analysis_status']);
    $this->assertSame('complete', $stored['status']);
    $this->assertEquals([
      'egressPolicy' => 'sovereign_only',
      'modelClass' => 'openai_compatible',
      'version' => 1,
    ], array_intersect_key($stored['profile'], ['egressPolicy' => 1, 'modelClass' => 1, 'version' => 1]));
    $this->assertSame($staff_run, $stored['stories']['staff']['analysisRunId']);
    $this->assertSame(['attempt' => 1, 'maxAttempts' => 3], $stored['stories']['staff']['worker']);
    $this->assertTrue($stored['stories']['staff']['result']['extracted'][0]['value'][ApplicationDataProtectionService::ENCRYPTED_MARKER]);
    $this->assertSame($citizen_run, $stored['stories']['citizen']['analysisRunId']);
    $this->assertSame('pass', $stored['stories']['citizen']['result']['outcome']);
    $this->assertArrayNotHasKey('version', $stored['stories']['citizen']);
  }

  /**
   * A stale run id of one story is refused without touching the other.
   */
  public function testUpdateAttachmentAnalysisRejectsStaleStoryRunIdWithoutTouchingOtherStory(): void {
    $stored = [
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => [
        'citizen' => [
          'status' => 'complete',
          'analysisRunId' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          'result' => ['outcome' => 'pass'],
        ],
        'staff' => [
          'status' => 'pending',
          'analysisRunId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'analysisStartedAt' => 1782862150,
        ],
      ],
    ];
    [$attachment, $state] = $this->storyUpdateAttachment($stored);
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $before = $state['field_analysis_metadata'];

    foreach ([
      ['staff', 'cccccccccccccccccccccccccccccccc'],
      // The current run id of story A cannot write story B.
      ['staff', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
      // Story A is complete: its own run id is no longer current.
      ['citizen', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ] as [$story, $run_id]) {
      $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->storyUpdateRequest($story, $run_id, ['status' => 'complete']));
      $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
      $this->assertSame('attachment.analysis_run_invalid', json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['error']['code']);
    }
    $this->assertSame($before, $state['field_analysis_metadata']);
  }

  /**
   * A queue retry after lease expiry cannot overwrite a newer B result.
   */
  public function testUpdateAttachmentAnalysisRejectsLateStaffCallbackAfterNewerResult(): void {
    [$attachment] = $this->storyUpdateAttachment([
      'version' => 2,
      'status' => 'complete',
      'stories' => [
        'staff' => [
          'status' => 'complete',
          'analysisRunId' => 'dddddddddddddddddddddddddddddddd',
          'result' => [
            'outcome' => 'unknown',
            'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480]],
          ],
        ],
      ],
    ]);
    $attachment->expects($this->never())->method('save');

    $response = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', $this->storyUpdateRequest('staff', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', [
      'status' => 'complete',
      'result' => [
        'outcome' => 'unknown',
        'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 1]],
      ],
    ]));
    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
  }

  /**
   * A story profile never accepts a write without story and run id.
   */
  public function testUpdateAttachmentAnalysisRejectsStoryProfileWriteWithoutRunId(): void {
    [$attachment] = $this->storyUpdateAttachment(['version' => 1, 'status' => 'not_requested', 'language' => 'de']);
    $attachment->expects($this->never())->method('save');

    $without_story = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode(['status' => 'complete', 'metadata' => ['version' => 1, 'status' => 'complete']], JSON_THROW_ON_ERROR),
    ));
    $this->assertSame(Response::HTTP_CONFLICT, $without_story->getStatusCode());

    $request = $this->storyUpdateRequest('citizen', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ['status' => 'complete']);
    $payload = json_decode((string) $request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    unset($payload['analysisRunId']);
    $without_run = $this->controller->updateAttachmentAnalysis('8cf1ce03-5c64-4098-8b1c-a8322a62e073', Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode($payload, JSON_THROW_ON_ERROR),
    ));
    $this->assertSame(Response::HTTP_CONFLICT, $without_run->getStatusCode());
  }

  /**
   * Version 1 metadata keeps today's allowlist.
   */
  public function testListAttachmentsNormalisesV1Metadata(): void {
    $v1 = [
      'version' => 1,
      'status' => 'complete',
      'language' => 'de',
      'result' => ['outcome' => 'pass', 'missingCount' => 0],
      'secret' => 'dropped',
    ];
    $payload = $this->listAttachmentWithMetadata($v1, FALSE);
    $this->assertSame([
      'version' => 1,
      'status' => 'complete',
      'language' => 'de',
      'result' => ['outcome' => 'pass', 'missingCount' => 0],
    ], $payload['data'][0]['analysisMetadata']);
  }

  /**
   * Version 2 passes the per-story allowlist; unknown stories are dropped.
   */
  public function testListAttachmentsNormalisesV2MetadataPerStory(): void {
    $v2 = [
      'version' => 2,
      'status' => 'complete',
      'language' => 'en',
      'profile' => [
        'egressPolicy' => 'sovereign_only',
        'modelClass' => 'openai_compatible',
        'staffPrompt' => 'never echoed',
      ],
      'stories' => [
        'citizen' => [
          'status' => 'complete',
          'version' => 1,
          'promptVersion' => 'attachment-analysis:v3',
          'provider' => [
            'name' => 'sovereign',
            'model' => 'Qwen/Qwen3.8-27B',
            'location' => 'Rechenzentrum in Deutschland',
            'apiKey' => 'dropped',
          ],
          'result' => ['outcome' => 'pass', 'missingCount' => 0, 'missing' => ['free text']],
        ],
        'staff' => [
          'status' => 'complete',
          'analysisRunId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'result' => [
            'outcome' => 'unknown',
            'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480, 'label' => 'x']],
          ],
        ],
        'other' => ['status' => 'complete'],
      ],
    ];
    $payload = $this->listAttachmentWithMetadata($v2, FALSE);
    $this->assertSame([
      'version' => 2,
      'status' => 'complete',
      'language' => 'en',
      'profile' => ['egressPolicy' => 'sovereign_only', 'modelClass' => 'openai_compatible'],
      'stories' => [
        'citizen' => [
          'status' => 'complete',
          'promptVersion' => 'attachment-analysis:v3',
          'provider' => [
            'name' => 'sovereign',
            'model' => 'Qwen/Qwen3.8-27B',
            'location' => 'Rechenzentrum in Deutschland',
          ],
          'result' => ['outcome' => 'pass', 'missingCount' => 0],
        ],
        'staff' => [
          'status' => 'complete',
          'analysisRunId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'result' => [
            'outcome' => 'unknown',
            'extracted' => [['key' => 'gross_pay_month', 'value' => 2480, 'type' => 'number']],
          ],
        ],
      ],
    ], $payload['data'][0]['analysisMetadata']);
  }

  /**
   * A staff read of stories.staff.result.extracted is a protected reveal.
   */
  public function testListAttachmentsLogsStaffStoryExtractedRead(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->with($this->stringContains('Protected application data revealed'), $this->callback(
        static fn (array $context): bool => ($context['@channel'] ?? '') === 'attachment_analysis',
      ));
    $this->controller = new ApplicationAttachmentApiController(
      $this->entityTypeManager, $this->flood, $this->logger, $this->fileSystem,
      $this->fileRepository, $this->time, new AttachmentScanService(), $this->database,
      $this->lock, $this->configFactory(), NULL,
      new ApplicationDataProtectionService($this->entityTypeManager, $logger),
    );

    $this->listAttachmentWithMetadata([
      'version' => 2,
      'status' => 'complete',
      'stories' => [
        'citizen' => ['status' => 'complete', 'result' => ['outcome' => 'pass']],
        'staff' => [
          'status' => 'complete',
          'result' => [
            'outcome' => 'unknown',
            'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480]],
          ],
        ],
      ],
    ], TRUE);
  }

  /**
   * A readable file does not grant access to its staff result or state.
   */
  public function testListAttachmentsWithholdsStaffMetadataWithoutProtectedAccess(): void {
    $payload = $this->listAttachmentWithMetadata([
      'version' => 2,
      'status' => 'pending',
      'stories' => [
        'citizen' => ['status' => 'pending'],
        'staff' => ['status' => 'complete', 'result' => ['outcome' => 'unknown']],
      ],
    ], FALSE, FALSE);

    $row = $payload['data'][0];
    $this->assertArrayNotHasKey('staffAnalysis', $row);
    $this->assertArrayNotHasKey('staff', $row['analysisMetadata']['stories']);
    $this->assertSame('pending', $row['analysisStatus']);
    $this->assertSame('pending', $row['analysisMetadata']['status']);
  }

  /**
   * Uploads into a sovereign story field and returns created values.
   *
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}
   *   Created entity values and decoded response.
   */
  private function uploadStoryAttachment(bool $trusted): array {
    $form = $this->mockForm($this->schemaJson(analysis: $this->storyAnalysis()));
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $service_user = $this->mockAccount(11, ['authenticated', 'service_account']);
    $this->userStorage->method('loadByProperties')->willReturn([$service_user]);
    $this->mockPendingUploadBytes(0);
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(321);
    $file->method('getFilename')->willReturn('scan.png');
    $file->method('getSize')->willReturn(68);
    $this->fileRepository->method('writeData')->willReturn($file);
    $created_values = NULL;
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $this->attachmentStorage->method('create')->willReturnCallback(
      static function (array $values) use (&$created_values, $attachment): ApplicationAttachmentInterface {
        $created_values = $values;
        return $attachment;
      },
    );

    $response = $this->controller->uploadAttachment($this->uploadRequest($this->pngFile(), trusted_proxy: $trusted));
    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertIsArray($created_values);
    return [$created_values, json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)];
  }

  /**
   * Builds a claimed story attachment whose analysis fields persist.
   *
   * @param array<string, mixed> $metadata
   *   Stored metadata.
   *
   * @return array{0: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface&\PHPUnit\Framework\MockObject\MockObject, 1: \ArrayObject<string, mixed>}
   *   Attachment and state.
   */
  private function storyUpdateAttachment(array $metadata): array {
    $form = $this->mockForm($this->schemaJson(analysis: $this->storyAnalysis()));
    $form->method('uuid')->willReturn('fbc3b086-ac53-412e-9bbf-ccb03f286923');
    $state = new \ArrayObject([
      'field_analysis_status' => (string) ($metadata['status'] ?? 'not_requested'),
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ]);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->entityReference($form),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_status', 'field_analysis_metadata' => $this->fieldValue($state[$field]),
      default => $this->emptyField(),
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state): ApplicationAttachmentInterface {
        $state[$field] = $value;
        return $attachment;
      },
    );
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    return [$attachment, $state];
  }

  /**
   * Builds a trusted story write request.
   *
   * @param string $story
   *   Story name.
   * @param string $run_id
   *   Run id of the callback.
   * @param array<string, mixed> $metadata
   *   Story metadata.
   */
  private function storyUpdateRequest(string $story, string $run_id, array $metadata): Request {
    return Request::create(
      '/api/internal/attachments/8cf1ce03-5c64-4098-8b1c-a8322a62e073/analysis',
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ],
      content: json_encode([
        'status' => $metadata['status'] ?? 'complete',
        'story' => $story,
        'analysisRunId' => $run_id,
        'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
        'fieldId' => 'nachweis',
        'metadata' => $metadata,
      ], JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Lists one attachment carrying the given analysis metadata.
   *
   * @param array<string, mixed> $metadata
   *   Stored metadata.
   * @param bool $sensitive
   *   Whether the file field is marked sensitive.
   * @param bool $can_read_protected
   *   Whether the viewer may read protected attachment data.
   *
   * @return array<string, mixed>
   *   Decoded list response.
   */
  private function listAttachmentWithMetadata(array $metadata, bool $sensitive, bool $can_read_protected = TRUE): array {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $can_read_protected && $permission === 'view protected application data',
    );
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([91]);
    $fields = [
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_file' => $this->fieldTarget(321),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_analysis_status' => $this->fieldValue('complete'),
      'field_analysis_metadata' => $this->fieldValue(json_encode($metadata, JSON_THROW_ON_ERROR)),
      'created' => $this->fieldValue(1782862200),
    ];
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('evidence.pdf');
    $file->method('getSize')->willReturn(2048);
    $this->attachmentStorage->method('getQuery')->willReturn($query);
    $this->attachmentStorage->method('loadMultiple')->willReturn([91 => $attachment]);
    $this->fileStorage->method('load')->willReturn($file);
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturn('/api/admin/applications/54/attachments/x');
    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $response = $this->controller->listApplicationAttachments($this->mockApplication($this->schemaJson(FALSE, $sensitive)));
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Builds an upload request with a test file.
   */
  private function uploadRequest(
    string $path,
    string $filename = 'scan.png',
    string $mime_type = 'image/png',
    array $parameters = [],
    bool $trusted_proxy = FALSE,
  ): Request {
    $uploaded = new UploadedFile($path, $filename, $mime_type, NULL, TRUE);
    $server = ['REMOTE_ADDR' => '198.51.100.22'];
    if ($trusted_proxy) {
      $server['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION'] = 'Basic ' . base64_encode('nuxt_service:proxy-secret');
    }
    return Request::create(
      '/api/v1/attachments',
      'POST',
      [
        'formId' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923',
        'fieldId' => 'nachweis',
        ...$parameters,
      ],
      [],
      ['file' => $uploaded],
      $server,
    );
  }

  /**
   * Creates a temporary PNG test file.
   */
  private function pngFile(): string {
    $path = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($path);
    file_put_contents($path, "\x89PNG\r\n\x1A\n" . str_repeat("\0", 60));
    return $path;
  }

  /**
   * Creates a temporary PDF test file containing the EICAR signature.
   */
  private function eicarPdfFile(): string {
    $path = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($path);
    file_put_contents($path, '%PDF-1.4' . "\n" . 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
    return $path;
  }

  /**
   * Builds a form schema mock.
   */
  private function mockForm(string $schema): FormSchemaInterface {
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn(12);
    $form->method('isPublished')->willReturn(TRUE);
    $form->method('getSchemaStatus')->willReturn('published');
    $form->method('getPublicationScope')->willReturn('internet');
    $form->method('getSchema')->willReturn($schema);
    return $form;
  }

  /**
   * Builds a group mock.
   */
  private function mockGroup(string $bundle, int $id): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    return $group;
  }

  /**
   * Builds an application mock.
   */
  private function mockApplication(string $schema, ?int $schema_revision_id = NULL): ApplicationInterface {
    $schema_entity = $this->mockForm($schema);
    $fields = ['field_schema' => $this->entityReference($schema_entity)];
    if ($schema_revision_id !== NULL) {
      $fields['field_schema_revision_id'] = $this->fieldValue($schema_revision_id);
    }
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(54);
    $application->method('access')->willReturnCallback(
      static fn(string $operation): bool => in_array($operation, ['view', 'update'], TRUE),
    );
    $application->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    return $application;
  }

  /**
   * Builds an account mock.
   *
   * @param int $id
   *   User id.
   * @param string[] $roles
   *   Role ids.
   */
  private function mockAccount(int $id, array $roles): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

  /**
   * Builds config factory mock with attachment quota defaults.
   */
  private function configFactory(int|string|null $attachment_max_total_bytes = 52428800): ConfigFactoryInterface {
    $application_config = $this->createMock(ImmutableConfig::class);
    $application_config->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'attachment_max_total_bytes_per_application' => $attachment_max_total_bytes,
        default => NULL,
      },
    );

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_application.settings')
      ->willReturn($application_config);
    return $factory;
  }

  /**
   * Mocks the open pre-submit byte sum for the current upload target.
   */
  private function mockPendingUploadBytes(int $bytes): void {
    $ids = $bytes > 0 ? [91] : [];

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $this->attachmentStorage->method('getQuery')->willReturn($query);

    if ($bytes <= 0) {
      $this->attachmentStorage->method('loadMultiple')->with([])->willReturn([]);
      return;
    }

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_application' => $this->emptyField(),
      'field_size' => $this->fieldValue($bytes),
      default => $this->emptyField(),
    });
    $this->attachmentStorage->method('loadMultiple')->with($ids)->willReturn([91 => $attachment]);
  }

  /**
   * Lists one attachment with the supplied optional guard fields.
   *
   * @return array<string, mixed>
   *   Decoded list response.
   */
  private function listAttachmentPayload(
    ?string $guard_status = NULL,
    ?string $guard_report = NULL,
    bool $has_guard_derivative = FALSE,
    string $original_filename = '',
    string $file_filename = 'evidence.pdf',
    bool $file_sensitive = FALSE,
    int $attachment_count = 1,
    bool $message_file = FALSE,
    string $file_uri = '',
    ?int $schema_revision_id = NULL,
    string $field_id = 'nachweis',
  ): array {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('getRoles')->willReturn(['authenticated']);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => $permission === 'view protected application data',
    );
    $ids = range(91, 90 + $attachment_count);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->with('field_application', 54)->willReturnSelf();
    $query->method('sort')->with('created', 'ASC')->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $fields = [
      'field_original_filename' => $this->fieldValue($original_filename),
      'field_field_id' => $this->fieldValue($message_file ? 'comment_attachment' : $field_id),
      'field_file' => $this->fieldTarget(321),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_analysis_status' => $this->fieldValue('not_requested'),
      'created' => $this->fieldValue(1782862200),
    ];
    if ($guard_status !== NULL) {
      $fields['field_guard_status'] = $this->fieldValue($guard_status);
    }
    if ($guard_report !== NULL) {
      $fields['field_guard_report'] = $this->fieldValue($guard_report);
    }
    if ($has_guard_derivative) {
      $fields['field_guard_derivative'] = $this->fieldTarget(654);
    }

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('uuid')->willReturn('8cf1ce03-5c64-4098-8b1c-a8322a62e073');
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => isset($fields[$field]),
    );
    $attachment->method('get')->willReturnCallback(
      fn(string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField(),
    );

    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn($file_filename);
    $file->method('getFileUri')->willReturn($file_uri);
    $file->method('uuid')->willReturn('22222222-2222-4222-8222-222222222222');
    $file->method('getSize')->willReturn(2048);

    $this->attachmentStorage->method('getQuery')->willReturn($query);
    $this->attachmentStorage->method('loadMultiple')->with($ids)->willReturn(array_fill_keys($ids, $attachment));
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturnCallback(
      static function (string $route_name, array $parameters): string {
        $base = '/api/admin/applications/' . $parameters['application']
          . '/attachments/' . $parameters['attachment_uuid'];
        return $route_name === 'beantragt_application.admin_application_attachment_derivative'
          ? $base . '/derivative'
          : $base;
      },
    );
    $container = new ContainerBuilder();
    $container->set('url_generator', $url_generator);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);

    $response = $this->controller->listApplicationAttachments(
      $this->mockApplication($this->schemaJson(FALSE, $file_sensitive), $schema_revision_id),
    );
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Sets the current user service for controller permission checks.
   */
  private function setCurrentUser(AccountInterface $account): void {
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a transaction double without invoking Core transaction teardown.
   */
  private function transaction(?callable $onRollback = NULL): Transaction {
    $rollback = $onRollback === NULL ? NULL : \Closure::fromCallable($onRollback);
    return new class($rollback) extends Transaction {

      /**
       * Rollback callback.
       */
      private ?\Closure $onRollback;

      public function __construct(?\Closure $onRollback) {
        $this->onRollback = $onRollback;
      }

      /**
       * {@inheritdoc}
       */
      public function __destruct() {}

      /**
       * {@inheritdoc}
       */
      public function rollBack(): void {
        if ($this->onRollback !== NULL) {
          ($this->onRollback)();
        }
      }

    };
  }

  /**
   * Builds an entity reference field list.
   */
  private function entityReference(object $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => method_exists($entity, 'id') ? $entity->id() : NULL,
        default => NULL,
      },
    );
    return $field;
  }

  /**
   * Builds a field list exposing a target_id property.
   */
  private function fieldTarget(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(static fn(string $property): bool => $property === 'target_id');
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'target_id' ? $target_id : NULL,
    );
    return $field;
  }

  /**
   * Builds a field list exposing a scalar value property.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => $property === 'value',
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

  /**
   * Builds schema fixture JSON.
   */
  private function schemaJson(
    bool $auth_required = FALSE,
    bool $file_sensitive = TRUE,
    ?int $max_size_bytes = NULL,
    ?array $analysis = NULL,
  ): string {
    $file_field = [
      'id' => 'nachweis',
      'type' => 'file',
      'label' => 'Nachweis',
      'sensitive' => $file_sensitive,
    ];
    if ($max_size_bytes !== NULL) {
      $file_field['validation'] = ['maxSizeBytes' => $max_size_bytes];
    }
    if ($analysis !== NULL) {
      $file_field['analysis'] = $analysis;
    }

    return (string) json_encode([
      'title' => 'US-048 Smoke Anlage',
      'auth' => [
        'mode' => $auth_required ? 'required' : 'optional',
      ],
      'sections' => [
        [
          'id' => 'basis',
          'title' => 'Basis',
          'fields' => [
            $file_field,
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Restores an environment variable.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }
    putenv($name . '=' . $value);
  }

  /**
   * Only the Nuxt service proxy reaches the pages of a citizen upload.
   *
   * An anonymous caller and a citizen browser session (cookie, no service
   * header) are refused before any attachment is loaded.
   */
  public function testCitizenAnalysisRoutesRefuseCallersWithoutServiceAuthorization(): void {
    $this->attachmentStorage->expects($this->never())->method('loadByProperties');
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->expects($this->never())->method('renderPages');
    $controller = $this->citizenSourceController($guard);
    $body = $this->citizenSourceBody();

    foreach ([
      [],
      ['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:wrong')],
    ] as $headers) {
      $source = $controller->prepareCitizenAnalysisSource(
        self::CITIZEN_ATTACHMENT_UUID,
        $this->citizenSourceRequest($body, headers: $headers, cookies: ['SSESS0123' => 'citizen-session']),
      );
      $poll = $controller->citizenAttachmentAnalysis(
        self::CITIZEN_ATTACHMENT_UUID,
        $this->citizenPollRequest(self::CITIZEN_CLAIM_TOKEN, headers: $headers, cookies: ['SSESS0123' => 'citizen-session']),
      );
      $this->assertSame(Response::HTTP_FORBIDDEN, $source->getStatusCode());
      $this->assertSame(Response::HTTP_FORBIDDEN, $poll->getStatusCode());
      $this->assertSame('attachment.access_denied', json_decode((string) $source->getContent(), TRUE)['error']['code']);
    }
  }

  /**
   * A sovereign PDF leaves as at most eight transient pages, nothing stored.
   */
  public function testAnalysisSourceRendersTransientPagesAndNeverWritesGuardFields(): void {
    $analysis = $this->storyAnalysis() + ['redaction' => 'when_possible'];
    [$attachment, $state, $sets, $path] = $this->citizenUploadAttachment($analysis, $this->pendingCitizenRecord());
    $attachment->expects($this->never())->method('save');
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())
      ->method('createDerivative')
      ->with($this->stringStartsWith('%PDF-'), 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn([
        'status' => 'redacted',
        'reportJson' => '{"status":"redacted","reasons":[]}',
        'derivative' => '%PDF-1.7 transient sovereign derivative',
        'mediaType' => 'application/pdf',
        'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
      ]);
    $guard->expects($this->once())
      ->method('renderPages')
      ->with('%PDF-1.7 transient sovereign derivative')
      ->willReturn([
        'pages' => array_map(
          static fn (int $page): array => ['page' => $page, 'mediaType' => 'image/jpeg', 'data' => self::CITIZEN_JPEG],
          range(1, 9),
        ),
        'pageCount' => 10,
        'truncated' => TRUE,
      ]);

    $response = $this->citizenSourceController($guard)->prepareCitizenAnalysisSource(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenSourceRequest($this->citizenSourceBody()),
    );
    unlink($path);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $data = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data'];
    $this->assertSame('citizen', $data['story']);
    $this->assertSame('application/pdf', $data['mediaType']);
    $this->assertSame('derivative', $data['renderedFrom']);
    $this->assertSame('redacted', $data['redaction']);
    $this->assertSame(10, $data['pageCount']);
    $this->assertTrue($data['truncated']);
    $this->assertCount(AttachmentAnalysisSourcePreparer::PAGE_LIMIT, $data['pages']);
    $this->assertSame(['mediaType' => 'image/jpeg', 'data' => base64_encode(self::CITIZEN_JPEG)], $data['pages'][0]);
    $this->assertArrayNotHasKey('analysisRunId', $data);
    $this->assertSame([], array_filter($sets->getArrayCopy(), static fn (string $field): bool => str_starts_with($field, 'field_guard_')));
    $this->assertSame([], $sets->getArrayCopy());
    $this->assertSame('pending', json_decode((string) $state['field_analysis_metadata'], TRUE)['stories']['citizen']['status']);
  }

  /**
   * Foreign tokens, stale runs, story B and images never get pages.
   */
  public function testAnalysisSourceRefusesForeignTokenStaleRunStaffStoryAndImages(): void {
    [, , , $path] = $this->citizenUploadAttachment($this->storyAnalysis(), $this->pendingCitizenRecord());
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->expects($this->never())->method('renderPages');
    $controller = $this->citizenSourceController($guard);

    $foreign = $controller->prepareCitizenAnalysisSource(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenSourceRequest(['claimToken' => 'ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvu'] + $this->citizenSourceBody()),
    );
    $stale = $controller->prepareCitizenAnalysisSource(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenSourceRequest(['analysisRunId' => str_repeat('b', 32)] + $this->citizenSourceBody()),
    );
    $staff = $controller->prepareCitizenAnalysisSource(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenSourceRequest($this->citizenSourceBody(), 'staff'),
    );
    unlink($path);

    $this->assertSame(Response::HTTP_NOT_FOUND, $foreign->getStatusCode());
    $this->assertSame('attachment.not_found', json_decode((string) $foreign->getContent(), TRUE)['error']['code']);
    $this->assertSame(Response::HTTP_CONFLICT, $stale->getStatusCode());
    $this->assertSame('attachment.analysis_run_invalid', json_decode((string) $stale->getContent(), TRUE)['error']['code']);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $staff->getStatusCode());

    $this->attachmentStorage = $this->createMock(EntityStorageInterface::class);
    [, , , $image_path] = $this->citizenUploadAttachment($this->storyAnalysis(), $this->pendingCitizenRecord(), 'image/png');
    $image = $this->citizenSourceController($guard)->prepareCitizenAnalysisSource(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenSourceRequest($this->citizenSourceBody()),
    );
    unlink($image_path);
    $this->assertSame(Response::HTTP_CONFLICT, $image->getStatusCode());
    $this->assertSame('attachment.analysis_source_unsupported', json_decode((string) $image->getContent(), TRUE)['error']['code']);
  }

  /**
   * A refused guard verdict fails story A of this run with its own code.
   */
  public function testAnalysisSourceRecordsGuardBlockInCitizenStoryOnly(): void {
    $analysis = $this->storyAnalysis() + ['redaction' => 'when_possible'];
    [, $state, $sets, $path] = $this->citizenUploadAttachment($analysis, $this->pendingCitizenRecord());
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->method('createDerivative')->willReturn([
      'status' => 'rejected',
      'reportJson' => '{"status":"rejected","reasons":["encrypted_pdf"]}',
      'derivative' => NULL,
      'mediaType' => NULL,
      'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
    ]);
    $guard->expects($this->never())->method('renderPages');

    $response = $this->citizenSourceController($guard)->prepareCitizenAnalysisSource(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenSourceRequest($this->citizenSourceBody()),
    );
    unlink($path);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $error = json_decode((string) $response->getContent(), TRUE)['error'];
    $this->assertSame('attachment.analysis_source_failed', $error['code']);
    $this->assertSame(['analysisCode' => 'guard_blocked', 'recorded' => TRUE], $error['details']);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('failed', $stored['stories']['citizen']['status']);
    $this->assertSame('guard_blocked', $stored['stories']['citizen']['code']);
    $this->assertSame(self::CITIZEN_RUN_ID, $stored['stories']['citizen']['analysisRunId']);
    $this->assertSame(['citizen'], array_keys($stored['stories']));
    $this->assertSame('failed', $state['field_analysis_status']);
    $this->assertSame(['field_analysis_status', 'field_analysis_metadata'], $sets->getArrayCopy());
  }

  /**
   * The poll answers the citizen reduction of story A and nothing else.
   */
  public function testCitizenAnalysisReturnsOnlyTheCitizenReduction(): void {
    $analysis = $this->storyAnalysis();
    $analysis['resultSchema']['properties']['pay_period_visible'] = [
      'type' => 'boolean',
      'title' => 'Zeitraum erkennbar',
      'nonPersonal' => TRUE,
    ];
    [, , , $path] = $this->citizenUploadAttachment($analysis, [
      'version' => 2,
      'status' => 'complete',
      'language' => 'en',
      'stories' => [
        'citizen' => [
          'status' => 'complete',
          'code' => 'analysis_complete',
          'analysisRunId' => self::CITIZEN_RUN_ID,
          'analysisStartedAt' => 1782862190,
          'promptVersion' => 'attachment-analysis:v3',
          'provider' => ['name' => 'sovereign', 'model' => 'Qwen/Qwen3.8-27B'],
          'source' => [
            'mimeType' => 'application/pdf',
            'pages' => 3,
            'pageCount' => 5,
            'truncated' => TRUE,
            'redaction' => 'unredacted_needs_review',
          ],
          'result' => [
            'outcome' => 'needs_review',
            'missingCount' => 1,
            'missingKeys' => ['pay_period_visible', 'gross_pay_month'],
            'extracted' => [
              ['key' => 'legible', 'type' => 'boolean', 'value' => TRUE],
              ['key' => 'pay_period_visible', 'type' => 'boolean', 'value' => FALSE],
              ['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480],
            ],
            'notices' => ['pages_truncated', 'unredacted_scan'],
            'pagesChecked' => 3,
          ],
        ],
        'staff' => [
          'status' => 'complete',
          'code' => 'analysis_complete',
          'analysisRunId' => str_repeat('c', 32),
          'result' => [
            'outcome' => 'unknown',
            'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2481]],
          ],
        ],
      ],
    ]);

    $response = $this->controller->citizenAttachmentAnalysis(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenPollRequest(self::CITIZEN_CLAIM_TOKEN),
    );
    unlink($path);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $raw = (string) $response->getContent();
    $data = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR)['data'];
    $this->assertSame([
      'id' => self::CITIZEN_ATTACHMENT_UUID,
      'type' => 'application_attachment_citizen_analysis',
      'formId' => self::CITIZEN_FORM_UUID,
      'fieldId' => 'nachweis',
      'mode' => 'story',
      'language' => 'en',
      'status' => 'complete',
      'code' => 'analysis_complete',
      'result' => [
        'outcome' => 'needs_review',
        'missingCount' => 1,
        'missingKeys' => ['pay_period_visible'],
        'notices' => ['pages_truncated', 'unredacted_scan'],
        'pagesChecked' => 3,
        'extracted' => [
          ['key' => 'legible', 'type' => 'boolean', 'value' => TRUE],
          ['key' => 'pay_period_visible', 'type' => 'boolean', 'value' => FALSE],
        ],
      ],
    ], $data);
    $forbidden_tokens = [
      'gross_pay_month', '2480', '2481', 'staff', 'analysisRunId', 'analysisStartedAt',
      'provider', 'Qwen', 'promptVersion', 'purposeBound', 'redaction',
    ];
    foreach ($forbidden_tokens as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $raw);
    }
  }

  /**
   * Only explicit nonPersonal story A booleans may be revealed to the citizen.
   */
  public function testCitizenPollRevealsOnlyDeclaredCitizenBooleans(): void {
    $protection = new ApplicationDataProtectionService($this->entityTypeManager, $this->logger);
    $analysis = $this->storyAnalysis();
    $analysis['resultSchema']['properties'] += [
      'wrong_value' => ['type' => 'boolean', 'nonPersonal' => TRUE],
      'alias_only' => ['type' => 'boolean', 'xBeantragtNonPersonal' => TRUE],
      'truthy_flag' => ['type' => 'boolean', 'nonPersonal' => 1],
      'purpose_bound' => ['type' => 'boolean', 'nonPersonal' => TRUE, 'purposeBound' => TRUE],
    ];
    $result = $protection->protectAnalysisResult([
      'outcome' => 'pass',
      'extracted' => [
        ['key' => 'legible', 'value' => TRUE],
        ['key' => 'wrong_value', 'value' => 2340.5],
        ['key' => 'undeclared', 'value' => TRUE],
        ['key' => 'alias_only', 'value' => TRUE],
        ['key' => 'truthy_flag', 'value' => TRUE],
        ['key' => 'purpose_bound', 'value' => TRUE],
      ],
    ]);
    [, , , $path] = $this->citizenUploadAttachment($analysis, [
      'version' => 2,
      'stories' => [
        'citizen' => ['status' => 'complete', 'result' => $result],
        'staff' => [
          'status' => 'complete',
          'result' => $protection->protectAnalysisResult([
            'extracted' => [['key' => 'legible', 'value' => FALSE]],
          ]),
        ],
      ],
    ]);
    $response = $this->controller->citizenAttachmentAnalysis(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenPollRequest(self::CITIZEN_CLAIM_TOKEN),
    );
    unlink($path);
    $this->assertSame(200, $response->getStatusCode());
    $raw = (string) $response->getContent();
    $this->assertSame([
      'outcome' => 'pass',
      'extracted' => [['key' => 'legible', 'type' => 'boolean', 'value' => TRUE]],
    ], json_decode($raw, TRUE)['data']['result']);
    foreach ([
      '2340.5', 'wrong_value', 'undeclared', 'alias_only', 'truthy_flag',
      'purpose_bound', 'staff', ApplicationDataProtectionService::ENCRYPTED_MARKER,
    ] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $raw);
    }
  }

  /**
   * Unknown, foreign, expired and claimed uploads all look the same.
   */
  public function testCitizenAnalysisAnswersNotFoundForEveryMismatch(): void {
    [, , , $path] = $this->citizenUploadAttachment($this->storyAnalysis(), $this->pendingCitizenRecord());
    $foreign = $this->controller->citizenAttachmentAnalysis(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenPollRequest('ZYXWVUTSRQPONMLKJIHGFEDCBAzyxwvu'),
    );
    $malformed = $this->controller->citizenAttachmentAnalysis(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenPollRequest('short'),
    );
    $pending = $this->controller->citizenAttachmentAnalysis(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenPollRequest(self::CITIZEN_CLAIM_TOKEN),
    );
    unlink($path);

    $this->attachmentStorage = $this->createMock(EntityStorageInterface::class);
    [, , , $claimed_path] = $this->citizenUploadAttachment($this->storyAnalysis(), $this->pendingCitizenRecord(), claimed: TRUE);
    $claimed = $this->controller->citizenAttachmentAnalysis(
      self::CITIZEN_ATTACHMENT_UUID,
      $this->citizenPollRequest(self::CITIZEN_CLAIM_TOKEN),
    );
    unlink($claimed_path);

    $bodies = [];
    foreach ([$foreign, $malformed, $claimed] as $response) {
      $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
      $bodies[] = (string) $response->getContent();
    }
    $this->assertCount(1, array_unique($bodies));
    $this->assertSame(Response::HTTP_OK, $pending->getStatusCode());
    $pending_data = json_decode((string) $pending->getContent(), TRUE)['data'];
    $this->assertSame('pending', $pending_data['status']);
    $this->assertArrayNotHasKey('result', $pending_data);
    $this->assertStringNotContainsString(self::CITIZEN_RUN_ID, (string) $pending->getContent());
  }

  /**
   * Returns a version 2 record whose story A run was just minted.
   *
   * @return array<string, mixed>
   *   Stored metadata.
   */
  private function pendingCitizenRecord(): array {
    return [
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => [
        'citizen' => [
          'status' => 'pending',
          'language' => 'de',
          'analysisRunId' => self::CITIZEN_RUN_ID,
          'analysisStartedAt' => 1782862190,
        ],
      ],
    ];
  }

  /**
   * Builds an unclaimed upload of a story profile with a stored file.
   *
   * @param array<string, mixed> $analysis
   *   Field analysis profile.
   * @param array<string, mixed> $metadata
   *   Stored analysis metadata.
   * @param string $mime
   *   Detected media type.
   * @param bool $claimed
   *   Whether an application already claimed the upload.
   *
   * @return array{0: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface&\PHPUnit\Framework\MockObject\MockObject, 1: \ArrayObject<string, mixed>, 2: \ArrayObject<int, string>, 3: string}
   *   Attachment, field state, names of set fields and the file path.
   */
  private function citizenUploadAttachment(array $analysis, array $metadata, string $mime = 'application/pdf', bool $claimed = FALSE): array {
    $form = $this->mockForm($this->schemaJson(analysis: $analysis));
    $form->method('uuid')->willReturn(self::CITIZEN_FORM_UUID);
    $path = tempnam(sys_get_temp_dir(), 'beantragt_citizen_source_');
    $this->assertIsString($path);
    file_put_contents($path, $mime === 'application/pdf' ? '%PDF-1.7 citizen upload fixture' : "\x89PNG\r\n\x1A\nfixture");
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($path);
    $file->method('getSize')->willReturn((int) filesize($path));
    $state = new \ArrayObject([
      'field_analysis_status' => (string) ($metadata['status'] ?? 'not_requested'),
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ]);
    $sets = new \ArrayObject();
    $application = $this->createMock(ApplicationInterface::class);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(93);
    $attachment->method('uuid')->willReturn(self::CITIZEN_ATTACHMENT_UUID);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => in_array($field, ['field_scan_status', 'field_analysis_metadata'], TRUE),
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->entityReference($form),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $claimed ? $this->entityReference($application) : $this->emptyField(),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_claim_expires' => $this->fieldValue(1782865800),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', self::CITIZEN_CLAIM_TOKEN)),
      'field_file' => $this->entityReference($file),
      'field_mime_type' => $this->fieldValue($mime),
      'field_analysis_status', 'field_analysis_metadata' => $this->fieldValue($state[$field]),
      default => $this->emptyField(),
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state, $sets): ApplicationAttachmentInterface {
        $state[$field] = $value;
        $sets->append($field);
        return $attachment;
      },
    );
    $this->attachmentStorage->method('loadByProperties')->willReturn([$attachment]);
    return [$attachment, $state, $sets, $path];
  }

  /**
   * Builds a controller whose source preparer uses the given guard client.
   */
  private function citizenSourceController(DocumentGuardClientInterface $guard): ApplicationAttachmentApiController {
    return new ApplicationAttachmentApiController(
      $this->entityTypeManager,
      $this->flood,
      $this->logger,
      $this->fileSystem,
      $this->fileRepository,
      $this->time,
      new AttachmentScanService(),
      $this->database,
      $this->lock,
      $this->configFactory(),
      sourcePreparer: new AttachmentAnalysisSourcePreparer($guard),
    );
  }

  /**
   * Returns the body the upload proxy sends for story A.
   *
   * @return array<string, string>
   *   Request body.
   */
  private function citizenSourceBody(): array {
    return [
      'formId' => self::CITIZEN_FORM_UUID,
      'fieldId' => 'nachweis',
      'claimToken' => self::CITIZEN_CLAIM_TOKEN,
      'analysisRunId' => self::CITIZEN_RUN_ID,
    ];
  }

  /**
   * Builds a pages request; trusted by default.
   *
   * @param array<string, string> $body
   *   JSON body.
   * @param string|null $story
   *   Story query parameter.
   * @param array<string, string>|null $headers
   *   Server headers; NULL sends the valid service authorization.
   * @param array<string, string> $cookies
   *   Request cookies.
   */
  private function citizenSourceRequest(array $body, ?string $story = 'citizen', ?array $headers = NULL, array $cookies = []): Request {
    return Request::create(
      '/api/internal/attachments/' . self::CITIZEN_ATTACHMENT_UUID . '/analysis-source' . ($story !== NULL ? '?story=' . $story : ''),
      'POST',
      [],
      $cookies,
      [],
      ['CONTENT_TYPE' => 'application/json'] + ($headers ?? [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ]),
      json_encode($body, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Builds a poll request; trusted by default.
   *
   * @param string $claim_token
   *   Claim token the citizen holds.
   * @param array<string, string>|null $headers
   *   Server headers; NULL sends the valid service authorization.
   * @param array<string, string> $cookies
   *   Request cookies.
   */
  private function citizenPollRequest(string $claim_token, ?array $headers = NULL, array $cookies = []): Request {
    return Request::create(
      '/api/internal/attachments/' . self::CITIZEN_ATTACHMENT_UUID . '/citizen-analysis',
      'POST',
      [],
      $cookies,
      [],
      ['CONTENT_TYPE' => 'application/json'] + ($headers ?? [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ]),
      json_encode(['claimToken' => $claim_token], JSON_THROW_ON_ERROR),
    );
  }

}
