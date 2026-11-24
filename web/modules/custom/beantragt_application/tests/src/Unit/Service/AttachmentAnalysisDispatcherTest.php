<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\Core\File\FileExists;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Plugin\QueueWorker\AttachmentAnalysisQueueWorker;
use Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher;
use Drupal\beantragt_application\Service\AttachmentAnalysisMetadataWriter;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\DocumentGuardClientInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests submit-bound attachment analysis dispatching.
 *
 * @coversDefaultClass \Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher
 */
final class AttachmentAnalysisDispatcherTest extends UnitTestCase {

  private const ATTACHMENT_UUID = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
  private const APPLICATION_UUID = '4047a794-5853-4fe5-96b6-d7b08334fa3b';
  private const FORM_UUID = 'dc68b071-dbbf-4603-a80c-5c34ea241042';
  private const NOW = 1782862200;
  private const OLD_RUN_ID = '0123456789abcdef0123456789abcdef';
  private const CITIZEN_RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
  private const JPEG_PAGE = "\xFF\xD8\xFF\xE0rendered page";

  /**
   * A story profile: upload check (A) and purpose-bound reading (B).
   */
  private const STORY_ANALYSIS = [
    'citizenPrompt' => 'Prüfe, ob es eine lesbare Gehaltsabrechnung ist.',
    'staffPrompt' => 'Lies das Bruttoentgelt des Monats aus.',
    'resultSchema' => [
      'type' => 'object',
      'required' => ['legible'],
      'properties' => [
        'legible' => ['type' => 'boolean', 'title' => 'Lesbar', 'nonPersonal' => TRUE],
        'gross_pay_month' => [
          'type' => 'number',
          'title' => 'Bruttoentgelt',
          'purposeBound' => TRUE,
          'compareWith' => 'bruttoeinkommen_monat',
        ],
      ],
    ],
  ];

  /**
   * Original PHP timezone.
   */
  private string $originalTimezone;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('BEANTRAGT_ENV');
    putenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY=test-intelligence-key');
    putenv('IS_DDEV_PROJECT');
    $this->originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_ENV');
    putenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY');
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL');
    putenv('IS_DDEV_PROJECT');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD');
    putenv('BEANTRAGT_ATTACHMENT_ANALYSIS_MAX_ATTEMPTS');
    date_default_timezone_set($this->originalTimezone);
    parent::tearDown();
  }

  /**
   * Provides stored analysis-language compatibility cases.
   *
   * @return array<string, array{0: string, 1: string}>
   *   Stored metadata and expected worker language.
   */
  public static function analysisLanguageCases(): array {
    return [
      'english' => ['{"language":"en"}', 'en'],
      'missing' => ['{}', 'de'],
      'unsupported' => ['{"language":"fr"}', 'de'],
      'malformed' => ['{', 'de'],
      'scalar' => ['"en"', 'de'],
    ];
  }

  /**
   * Local Drupal dispatch mirrors the frontend development-only key default.
   */
  public function testUsesFrontendDevelopmentIntelligenceKeyDefault(): void {
    putenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY');
    putenv('BEANTRAGT_ENV=development');

    $this->assertSame(
      'Bearer beantragt-intelligence-local-development',
      $this->intelligenceAuthorizationHeader(),
    );
  }

  /**
   * Production dispatch fails closed when the Intelligence key is missing.
   */
  public function testDoesNotDefaultIntelligenceKeyOutsideDevelopment(): void {
    putenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY');
    putenv('BEANTRAGT_ENV=production');

    $this->assertSame('', $this->intelligenceAuthorizationHeader());
  }

  /**
   * @covers ::enqueue
   */
  public function testEnqueueCreatesQueueItemForClaimedCleanAttachment(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())
      ->method('createItem')
      ->with($this->callback(static fn (array $item): bool =>
        $item['attachmentId'] === self::ATTACHMENT_UUID
        && $item['applicationId'] === 42
      ));

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertTrue($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'external_allowed', 'claude_sonnet'),
      'field_external_analysis_consent' => $this->fieldValue('1'),
    ])));
  }

  /**
   * @covers ::enqueue
   */
  public function testEnqueueSkipsQuarantinedAttachments(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_scan_status' => $this->fieldValue(AttachmentScanResult::STATUS_QUARANTINED),
    ])));
  }

  /**
   * @covers ::enqueue
   */
  public function testEnqueueSkipsAttachmentsWithoutRuntimeAllowedAnalysisProfile(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(FALSE),
    ])));
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'no_ai', NULL),
    ])));
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'local_only', 'local_ocr'),
    ])));
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'claude_sonnet'),
    ])));
  }

  /**
   * @covers ::enqueue
   */
  public function testEnqueueAllowsExternalAnalysisWithoutPersistedConsent(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->exactly(2))->method('createItem');

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $external_profile = $this->fieldSchemaReference(TRUE, 'external_allowed', 'claude_sonnet');
    $this->assertTrue($dispatcher->enqueue($this->attachment([
      'field_schema' => $external_profile,
    ])));
    $this->assertTrue($dispatcher->enqueue($this->attachment([
      'field_schema' => $external_profile,
      'field_external_analysis_consent' => $this->fieldValue('0'),
    ])));
  }

  /**
   * A recent durable pending state suppresses duplicate external dispatch.
   */
  public function testEnqueueSkipsRecentPendingAnalysis(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1781431200);
    $attachment = $this->attachment([
      'field_analysis_status' => $this->fieldValue('pending'),
    ]);
    $attachment->method('getChangedTime')->willReturn(1781431199);

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $time,
    );

    $this->assertFalse($dispatcher->enqueue($attachment));
  }

  /**
   * A stale pending state may be explicitly queued for recovery.
   */
  public function testEnqueueAllowsStalePendingAnalysisRecovery(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1781431200);
    $attachment = $this->attachment([
      'field_analysis_status' => $this->fieldValue('pending'),
    ]);
    $attachment->method('getChangedTime')->willReturn(1781427599);

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $time,
    );

    $this->assertTrue($dispatcher->enqueue($attachment));
  }

  /**
   * Both external policies enqueue and dispatch only the guarded derivative.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('externalDerivativePolicies')]
  public function testProcessGuardsExternalAttachmentWithoutConsentAndDispatchesDerivative(string $egress_policy): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    [$attachment, $state] = $this->mutableExternalAttachment($egress_policy);
    $original = '%PDF- original private fixture';
    $derivative = '%PDF- guarded derivative fixture';
    $guard_report = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($derivative),
        'sha256' => hash('sha256', $derivative),
      ],
      'findings' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $original_path = $this->temporaryFile($original);
    $derivative_path = $this->temporaryFile($derivative);

    $original_file = $this->file(321, $original_path, strlen($original));
    $derivative_file = $this->file(654, $derivative_path, strlen($derivative));
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())
      ->method('createDerivative')
      ->with($original, 'application/pdf', 'full')
      ->willReturn([
        'status' => 'redacted',
        'reportJson' => $guard_report,
        'derivative' => $derivative,
        'mediaType' => 'application/pdf',
        'entityProfile' => 'full',
      ]);
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->once())
      ->method('prepareDirectory')
      ->with(
        'private://application_attachments/derivatives/2026-07/' . self::FORM_UUID . '/nachweis',
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
      )
      ->willReturn(TRUE);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->once())
      ->method('writeData')
      ->with(
        $derivative,
        'private://application_attachments/derivatives/2026-07/' . self::FORM_UUID . '/nachweis/' . self::ATTACHMENT_UUID . '.pdf',
        FileExists::Replace,
      )
      ->willReturn($derivative_file);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1782862200);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with('POST', 'http://node-dev:3000/api/internal/attachments/analyze', $this->callback(
        static function (array $options) use ($derivative): bool {
          $json = $options['json'] ?? [];
          return ($json['sourceKind'] ?? '') === 'derivative'
            && ($json['redaction'] ?? '') === 'redacted'
            && ($json['citizenConsent'] ?? TRUE) === FALSE
            && ($json['data'] ?? '') === base64_encode($derivative)
            && ($json['size'] ?? 0) === strlen($derivative);
        },
      ))
      ->willReturn($response);

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem');
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $original_file, NULL, $derivative_file),
      $this->queueFactory($queue),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      $time,
      $guard,
      $file_system,
      $file_repository,
    );
    $this->assertTrue($dispatcher->enqueue($attachment));
    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);

    @unlink($original_path);
    @unlink($derivative_path);
    $this->assertFalse($result->retry);
    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertSame('redacted', $state['field_guard_status']);
    $this->assertSame($guard_report, $state['field_guard_report']);
    $this->assertSame(654, $state['field_guard_derivative']);
  }

  /**
   * External policies that must use the document-guard derivative path.
   *
   * @return array<string, array{string}>
   *   Egress policies.
   */
  public static function externalDerivativePolicies(): array {
    return [
      'citizen opt-in allowed' => ['external_allowed'],
      'redacted only' => ['external_redacted'],
    ];
  }

  /**
   * A derivative is not persisted when it would exceed tenant storage quota.
   */
  public function testProcessChecksTenantQuotaBeforePersistingDerivative(): void {
    [$attachment, $state] = $this->mutableExternalAttachment();
    $original = '%PDF- original private fixture';
    $derivative = '%PDF- guarded derivative fixture';
    $report = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($derivative),
        'sha256' => hash('sha256', $derivative),
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $original_path = $this->temporaryFile($original);
    $original_file = $this->file(321, $original_path, strlen($original));
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())->method('createDerivative')->willReturn([
      'status' => 'redacted',
      'reportJson' => $report,
      'derivative' => $derivative,
      'mediaType' => 'application/pdf',
      'entityProfile' => 'full',
    ]);
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('prepareDirectory')->willReturn(TRUE);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->never())->method('writeData');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('uuid')->willReturn(self::APPLICATION_UUID);
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn(7);
    $quota = $this->createMock(AttachmentStorageQuota::class);
    $quota->expects($this->once())->method('jurisdictionForForm')->willReturn($jurisdiction);
    $quota->expects($this->once())
      ->method('violationForJurisdiction')
      ->with($jurisdiction, strlen($derivative))
      ->willReturn(['limit' => 100, 'used' => 90, 'projected' => 120]);

    $acquired = [];
    $released = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))->method('acquire')->willReturnCallback(
      static function (string $name) use (&$acquired): bool {
        $acquired[] = $name;
        return TRUE;
      },
    );
    $lock->expects($this->exactly(2))->method('release')->willReturnCallback(
      static function (string $name) use (&$released): void {
        $released[] = $name;
      },
    );
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $original_file, $application),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      $lock,
      NULL,
      $guard,
      $file_system,
      $file_repository,
      $quota,
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($original_path);

    $this->assertTrue($result->retry);
    $this->assertSame('analysis.guard_unavailable', $result->code);
    $this->assertSame('pending', $state['field_guard_status']);
    $this->assertSame([
      'beantragt_application.analysis.42',
      'beantragt_application.attachment_storage_quota.7',
    ], $acquired);
    $this->assertSame([
      'beantragt_application.attachment_storage_quota.7',
      'beantragt_application.analysis.42',
    ], $released);
  }

  /**
   * A human-review guard result is terminal and preserves the report.
   */
  public function testProcessStopsWithoutRetryWhenGuardNeedsReview(): void {
    [$attachment, $state] = $this->mutableExternalAttachment();
    $original_path = $this->temporaryFile('%PDF- original private fixture');
    $original_file = $this->file(321, $original_path, filesize($original_path) ?: 0);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())->method('createDerivative')->willReturn([
      'status' => 'needs_review',
      'reportJson' => '{"status":"needs_review","reasons":["annotations_present"]}',
      'derivative' => NULL,
      'mediaType' => NULL,
      'entityProfile' => 'full',
    ]);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $original_file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      $guard,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($original_path);

    $this->assertFalse($result->retry);
    $this->assertSame('analysis.guard_blocked', $result->code);
    $this->assertSame('needs_review', $state['field_guard_status']);
    $this->assertSame('{"status":"needs_review","reasons":["annotations_present"]}', $state['field_guard_report']);
    $metadata = json_decode((string) $state['field_analysis_metadata'], TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame('failed', $state['field_analysis_status']);
    $this->assertSame('guard_blocked', $metadata['code']);
  }

  /**
   * A guard result without the full entity profile is never persisted.
   */
  public function testProcessRefusesToPersistReducedProfileDerivative(): void {
    [$attachment, $state] = $this->mutableExternalAttachment();
    $original_path = $this->temporaryFile('%PDF- original private fixture');
    $original_file = $this->file(321, $original_path, filesize($original_path) ?: 0);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())->method('createDerivative')->willReturn([
      'status' => 'redacted',
      'reportJson' => '{"status":"redacted"}',
      'derivative' => '%PDF- reduced derivative',
      'mediaType' => 'application/pdf',
      'entityProfile' => 'analysis_sovereign',
    ]);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->never())->method('saveData');
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->never())->method('writeData');
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $original_file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      $guard,
      $file_system,
      $file_repository,
    );

    $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($original_path);

    $this->assertNotSame('redacted', $state['field_guard_status'] ?? NULL);
    $this->assertNotSame('{"status":"redacted"}', $state['field_guard_report'] ?? NULL);
    $this->assertEmpty($state['field_guard_derivative'] ?? NULL);
  }

  /**
   * Guard transport failures retry through maxAttempts and then fail closed.
   */
  public function testProcessRetriesGuardUnavailableUntilMaxAttempts(): void {
    putenv('BEANTRAGT_ATTACHMENT_ANALYSIS_MAX_ATTEMPTS=3');
    [$attachment, $state] = $this->mutableExternalAttachment();
    $original_path = $this->temporaryFile('%PDF- original private fixture');
    $original_file = $this->file(321, $original_path, filesize($original_path) ?: 0);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->exactly(3))
      ->method('createDerivative')
      ->willThrowException(new \RuntimeException('FICTIONAL_PAYSLIP_CONTENT_FROM_GUARD'));
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(3))->method('warning')->with(
      'Attachment document guard failed for @attachment on attempt @attempt.',
      $this->callback(static fn (array $context): bool => array_keys($context) === ['@attachment', '@attempt']
        && $context['@attachment'] === self::ATTACHMENT_UUID && is_int($context['@attempt'])),
    );
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $original_file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $logger,
      NULL,
      NULL,
      $guard,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
    );

    $first = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    $second = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    $third = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($original_path);

    $this->assertTrue($first->retry);
    $this->assertTrue($second->retry);
    $this->assertFalse($third->retry);
    $this->assertSame('analysis.guard_unavailable', $third->code);
    $metadata = json_decode((string) $state['field_analysis_metadata'], TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame('failed', $state['field_guard_status']);
    $this->assertSame('failed', $state['field_analysis_status']);
    $this->assertSame('guard_unavailable', $metadata['code']);
    $this->assertSame(3, $metadata['worker']['attempt']);
  }

  /**
   * Existing external derivatives never fall back to the original file bytes.
   */
  public function testExternalDispatchNeverReadsOriginalFieldFile(): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $derivative = '%PDF- guarded derivative fixture';
    $derivative_path = $this->temporaryFile($derivative);
    $guard_report = json_encode([
      'status' => 'clean',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($derivative),
        'sha256' => hash('sha256', $derivative),
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $attachment = $this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'external_allowed', 'claude_sonnet'),
      'field_guard_status' => $this->fieldValue('clean'),
      'field_guard_derivative' => $this->fieldTarget(654),
      'field_guard_report' => $this->fieldValue($guard_report),
      'field_mime_type' => $this->fieldValue('image/png'),
    ]);
    $attachment->expects($this->exactly(2))->method('set')->willReturnSelf();
    $attachment->expects($this->once())->method('save');
    $original_file = $this->createMock(FileInterface::class);
    $original_file->expects($this->never())->method('getFileUri');
    $original_file->expects($this->never())->method('getSize');
    $derivative_file = $this->file(654, $derivative_path, strlen($derivative));
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with('POST', 'http://node-dev:3000/api/internal/attachments/analyze', $this->callback(
        static fn(array $options): bool => ($options['json']['sourceKind'] ?? '') === 'derivative'
          && ($options['json']['mediaType'] ?? '') === 'application/pdf'
          && ($options['json']['data'] ?? '') === base64_encode($derivative),
      ))
      ->willReturn($response);
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $original_file, NULL, $derivative_file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      $guard,
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($derivative_path);

    $this->assertSame('analysis.dispatched', $result->code);
  }

  /**
   * A stale persisted derivative is deleted and regenerated before dispatch.
   */
  public function testProcessRegeneratesDerivativeWhenStoredHashDoesNotMatch(): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    [$attachment, $state] = $this->mutableExternalAttachment();
    $state['field_guard_status'] = 'redacted';
    $state['field_guard_derivative'] = 654;
    $state['field_guard_report'] = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => 10,
        'sha256' => str_repeat('0', 64),
      ],
    ], JSON_THROW_ON_ERROR);
    $original = '%PDF- original private fixture';
    $stale = '%PDF- stale derivative';
    $replacement = '%PDF- replacement derivative';
    $original_path = $this->temporaryFile($original);
    $stale_path = $this->temporaryFile($stale);
    $replacement_path = $this->temporaryFile($replacement);
    $original_file = $this->file(321, $original_path, strlen($original));
    $stale_file = $this->file(654, $stale_path, strlen($stale));
    $stale_file->expects($this->once())->method('delete');
    $replacement_file = $this->file(654, $replacement_path, strlen($replacement));
    $replacement_report = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($replacement),
        'sha256' => hash('sha256', $replacement),
      ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())->method('createDerivative')->with($original, 'application/pdf', 'full')->willReturn([
      'status' => 'redacted',
      'reportJson' => $replacement_report,
      'derivative' => $replacement,
      'mediaType' => 'application/pdf',
      'entityProfile' => 'full',
    ]);
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('prepareDirectory')->willReturn(TRUE);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->method('writeData')->willReturn($replacement_file);
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())->method('request')
      ->with('POST', 'http://node-dev:3000/api/internal/attachments/analyze', $this->callback(
        static fn(array $options): bool => ($options['json']['data'] ?? '') === base64_encode($replacement),
      ))
      ->willReturn($response);
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(
        $attachment,
        $original_file,
        NULL,
        $replacement_file,
        $stale_file,
      ),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      $guard,
      $file_system,
      $file_repository,
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($original_path);
    @unlink($stale_path);
    @unlink($replacement_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertSame($replacement_report, $state['field_guard_report']);
  }

  /**
   * @covers ::processQueuedAttachment
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('analysisLanguageCases')]
  public function testProcessDispatchesPrivateFileBytesToNuxtWorker(string $stored_metadata, string $expected_language): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    $bytes = '%PDF private fixture';
    $file_path = tempnam(sys_get_temp_dir(), 'attachment-analysis-');
    $this->assertIsString($file_path);
    file_put_contents($file_path, $bytes);

    $mutable_metadata = $stored_metadata;
    $attachment = $this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible'),
      'field_external_analysis_consent' => $this->fieldValue('1'),
      'field_analysis_metadata' => $this->mutableFieldValue($mutable_metadata),
    ]);
    $attachment->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(function (string $field, mixed $value) use ($attachment, &$mutable_metadata): ApplicationAttachmentInterface {
        if ($field === 'field_analysis_metadata') {
          $mutable_metadata = (string) $value;
        }
        return $attachment;
      });
    $attachment->expects($this->once())->method('save');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($file_path);
    $file->method('getSize')->willReturn(strlen($bytes));
    $file->method('getMimeType')->willReturn('application/pdf');

    $entity_type_manager = $this->entityTypeManager($attachment, $file);
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'http://node-dev:3000/api/internal/attachments/analyze',
        $this->callback(static function (array $options) use ($bytes, $expected_language): bool {
          $json = $options['json'] ?? [];
          return ($options['allow_redirects'] ?? TRUE) === FALSE
            && ($options['headers']['Authorization'] ?? '') === 'Bearer test-intelligence-key'
            && ($options['headers']['X-Beantragt-Service-Authorization'] ?? '') === 'Basic ' . base64_encode('nuxt_service:proxy-secret')
            && ($json['attachmentId'] ?? '') === self::ATTACHMENT_UUID
            && ($json['applicationId'] ?? '') === self::APPLICATION_UUID
            && ($json['formId'] ?? '') === self::FORM_UUID
            && ($json['fieldId'] ?? '') === 'nachweis'
            && ($json['mediaType'] ?? '') === 'application/pdf'
            && ($json['citizenConsent'] ?? FALSE) === TRUE
            && ($json['sourceKind'] ?? '') === 'original'
            && ($json['language'] ?? '') === $expected_language
            && ($json['size'] ?? 0) === strlen($bytes)
            // A sovereign PDF leaves as rendered original pages, never raw.
            && !array_key_exists('data', $json)
            && ($json['pages'] ?? NULL) === [['mediaType' => 'image/jpeg', 'data' => base64_encode(self::JPEG_PAGE)]]
            && ($json['renderedFrom'] ?? '') === 'original'
            && ($json['redaction'] ?? '') === 'none'
            && ($json['pageCount'] ?? 0) === 1
            && ($json['truncated'] ?? NULL) === FALSE
            && !array_key_exists('filename', $json);
        }),
      )
      ->willReturn($response);

    $dispatcher = new AttachmentAnalysisDispatcher(
      $entity_type_manager,
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      $this->renderingGuard(),
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertFalse($result->retry);
    $this->assertSame('analysis.dispatched', $result->code);
    $pending_metadata = json_decode($mutable_metadata, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($expected_language, $pending_metadata['language'] ?? NULL);
  }

  /**
   * The application lock remains held while attachment bytes leave Drupal.
   */
  public function testProcessHoldsApplicationLockThroughAnalysisDispatch(): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    [$attachment, $file_path] = $this->attachmentWithReadableFile([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible'),
      'field_external_analysis_consent' => $this->fieldValue('1'),
    ]);
    $attachment->method('set')->willReturnSelf();

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('uuid')->willReturn(self::APPLICATION_UUID);

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($file_path);
    $file->method('getSize')->willReturn(filesize($file_path) ?: 0);
    $file->method('getMimeType')->willReturn('application/pdf');
    $manager = $this->entityTypeManager($attachment, $file, $application);

    $released = FALSE;
    $backend = $this->createMock(LockBackendInterface::class);
    $backend->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.analysis.42', 900.0)
      ->willReturn(TRUE);
    $backend->expects($this->once())
      ->method('release')
      ->with('beantragt_application.analysis.42')
      ->willReturnCallback(
      static function (string $lock_name) use (&$released): void {
        $released = TRUE;
      },
    );
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->willReturnCallback(function () use (&$released, $response): ResponseInterface {
        $this->assertFalse($released);
        return $response;
      });

    $dispatcher = new AttachmentAnalysisDispatcher(
      $manager,
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      $backend,
      NULL,
      $this->renderingGuard(),
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertTrue($released);
  }

  /**
   * A transport timeout keeps both the durable pending state and egress lock.
   */
  public function testProcessKeepsAnalysisLockedWhenDispatchOutcomeIsUncertain(): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    [$attachment, $file_path] = $this->attachmentWithReadableFile([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible'),
      'field_external_analysis_consent' => $this->fieldValue('1'),
    ]);
    $attachment->expects($this->exactly(2))->method('set')->willReturnSelf();
    $attachment->expects($this->once())->method('save');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('uuid')->willReturn(self::APPLICATION_UUID);
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($file_path);
    $file->method('getSize')->willReturn(filesize($file_path) ?: 0);
    $file->method('getMimeType')->willReturn('application/pdf');

    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.analysis.42', 900.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_application.analysis.42');

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())
      ->method('request')
      ->willThrowException(new ConnectException(
        'Worker response timed out.',
        new Request('POST', 'http://node-dev:3000/api/internal/attachments/analyze'),
      ));

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $file, $application),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      $lock,
      NULL,
      $this->renderingGuard(),
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertFalse($result->retry);
    $this->assertSame('analysis.dispatch_uncertain', $result->code);
  }

  /**
   * @covers ::processQueuedAttachment
   */
  public function testProcessRetriesDispatchFailures(): void {
    [$attachment, $file_path] = $this->attachmentWithReadableFile();
    $attachment->expects($this->exactly(4))
      ->method('set')
      ->willReturnSelf();
    $attachment->expects($this->exactly(2))->method('save');

    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(new \RuntimeException('FICTIONAL_PAYSLIP_CONTENT_FROM_PROVIDER'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      'Attachment analysis dispatch failed for @attachment on attempt @attempt: @exception.',
      [
        '@attachment' => self::ATTACHMENT_UUID,
        '@attempt' => 1,
        '@exception' => \RuntimeException::class,
      ],
    );

    $dispatcher = $this->dispatcherWithAttachmentAndFile(
      $attachment,
      $file_path,
      $client,
      $logger,
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertTrue($result->retry);
    $this->assertSame('analysis.dispatch_failed', $result->code);
  }

  /**
   * Missing production key is logged before any attachment bytes are sent.
   */
  public function testProcessLogsMissingProductionIntelligenceKey(): void {
    putenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY');
    putenv('BEANTRAGT_ENV=production');
    [$attachment, $file_path] = $this->attachmentWithReadableFile([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'external_allowed', 'claude_sonnet'),
    ]);
    $attachment->expects($this->exactly(4))
      ->method('set')
      ->willReturnSelf();
    $attachment->expects($this->exactly(2))->method('save');

    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Attachment analysis key is not configured for @attachment on attempt @attempt.',
        ['@attachment' => self::ATTACHMENT_UUID, '@attempt' => 1],
      );
    $document_guard = $this->createMock(DocumentGuardClientInterface::class);
    $document_guard->expects($this->never())->method('createDerivative');

    $dispatcher = $this->dispatcherWithAttachmentAndFile(
      $attachment,
      $file_path,
      $http_client,
      $logger,
      $document_guard,
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertTrue($result->retry);
    $this->assertSame('analysis.dispatch_failed', $result->code);
  }

  /**
   * @covers ::processQueuedAttachment
   */
  public function testProcessStopsRetryingAfterMaxAttempts(): void {
    [$attachment, $file_path] = $this->attachmentWithReadableFile([
      'field_analysis_metadata' => $this->fieldValue('{"worker":{"attempt":2}}'),
    ]);
    $attachment->expects($this->exactly(4))
      ->method('set')
      ->willReturnSelf();
    $attachment->expects($this->exactly(2))->method('save');

    $dispatcher = $this->dispatcherWithAttachmentAndFile(
      $attachment,
      $file_path,
      $this->failingHttpClient(),
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertFalse($result->retry);
    $this->assertSame('analysis.dispatch_failed', $result->code);
  }

  /**
   * @covers \Drupal\beantragt_application\Plugin\QueueWorker\AttachmentAnalysisQueueWorker::processItem
   */
  public function testQueueWorkerDelaysRetryableDispatchFailures(): void {
    [$attachment, $file_path] = $this->attachmentWithReadableFile();
    $attachment->method('set')->willReturnSelf();

    $dispatcher = $this->dispatcherWithAttachmentAndFile(
      $attachment,
      $file_path,
      $this->failingHttpClient(),
    );
    $worker = new AttachmentAnalysisQueueWorker([], AttachmentAnalysisDispatcher::QUEUE_NAME, [], $dispatcher);

    try {
      $worker->processItem(['attachmentId' => self::ATTACHMENT_UUID]);
      $this->fail('Expected delayed requeue exception.');
    }
    catch (DelayedRequeueException $e) {
      @unlink($file_path);
      $this->assertSame(AttachmentAnalysisDispatcher::RETRY_DELAY_SECONDS, $e->getDelay());
    }
  }

  /**
   * Story A pending from the upload does not block story B after the claim.
   */
  public function testStoryCitizenPendingDoesNotBlockStaffEnqueue(): void {
    $item = NULL;
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem')->willReturnCallback(
      static function (array $data) use (&$item): bool {
        $item = $data;
        return TRUE;
      },
    );
    $attachment = $this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible', self::STORY_ANALYSIS),
      'field_analysis_status' => $this->fieldValue('pending'),
      'field_analysis_metadata' => $this->fieldValue(json_encode([
        'version' => 2,
        'status' => 'pending',
        'language' => 'de',
        'stories' => [
          'citizen' => [
            'status' => 'pending',
            'analysisRunId' => self::CITIZEN_RUN_ID,
            'analysisStartedAt' => self::NOW - 5,
          ],
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $attachment->method('getChangedTime')->willReturn(self::NOW - 5);

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
    );

    $this->assertTrue($dispatcher->enqueue($attachment));
    $this->assertSame('staff', $item['story'] ?? NULL);
  }

  /**
   * No purpose-bound run exists before the claim, and none without a value.
   */
  public function testStoryStaffRunNeedsClaimAndPurposeBoundDefinition(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
    );

    // Unclaimed draft upload: the queue never reads a purpose-bound value.
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_application' => $this->emptyField(),
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible', self::STORY_ANALYSIS),
    ])));
    // Story profile without a purpose-bound definition: nothing to read.
    $citizen_only = self::STORY_ANALYSIS;
    unset($citizen_only['resultSchema']['properties']['gross_pay_month']);
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible', $citizen_only),
    ])));
    // Story B never runs outside sovereign_only.
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'local_only', 'local_vision', self::STORY_ANALYSIS),
    ])));
  }

  /**
   * The staff run gets its own run id and leaves story A untouched.
   */
  public function testStoryProcessDispatchesStaffRunWithRunIdAndKeepsCitizen(): void {
    $citizen = [
      'status' => 'complete',
      'code' => 'analysis_complete',
      'analysisRunId' => self::CITIZEN_RUN_ID,
      'analysisStartedAt' => self::NOW - 60,
      'result' => ['outcome' => 'pass', 'missingCount' => 0],
    ];
    $metadata = json_encode([
      'version' => 2,
      'status' => 'complete',
      'language' => 'en',
      'stories' => ['citizen' => $citizen],
    ], JSON_THROW_ON_ERROR);
    [$attachment, $file_path, $sent] = $this->storyDispatchFixture($metadata);
    $dispatcher = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $sent['client']);

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
    @unlink($file_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('pending', $attachment['status']);
    $this->assertSame($citizen, $stored['stories']['citizen']);
    $this->assertSame('pending', $stored['stories']['staff']['status']);
    $this->assertSame(['attempt' => 1, 'maxAttempts' => 3], $stored['stories']['staff']['worker']);
    $json = $sent['json']->getArrayCopy();
    $this->assertSame('staff', $json['story']);
    $this->assertSame($stored['stories']['staff']['analysisRunId'], $json['analysisRunId']);
    $this->assertNotSame(self::CITIZEN_RUN_ID, $json['analysisRunId']);
    $this->assertSame('original', $json['sourceKind']);
    $this->assertSame('en', $json['language']);
  }

  /**
   * A queue retry after the lease expired never overwrites a newer B result.
   */
  public function testStoryQueueRetryAfterLeaseExpiryDoesNotOverwriteNewerStaffResult(): void {
    $metadata = json_encode([
      'version' => 2,
      'status' => 'complete',
      'language' => 'de',
      'stories' => [
        'staff' => [
          'status' => 'complete',
          'code' => 'analysis_complete',
          'analysisRunId' => self::OLD_RUN_ID,
          'analysisStartedAt' => self::NOW - 7200,
          'result' => [
            'outcome' => 'unknown',
            'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480]],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $attachment = $this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible', self::STORY_ANALYSIS),
      'field_analysis_status' => $this->fieldValue('complete'),
      'field_analysis_metadata' => $this->fieldValue($metadata),
    ]);
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->never())->method('request');

    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
    );

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
    $this->assertFalse($result->retry);
    $this->assertSame('attachment.not_dispatchable', $result->code);
  }

  /**
   * An expired staff lease restarts with a new run id.
   *
   * The old run's late callback then no longer matches.
   */
  public function testStoryExpiredStaffLeaseRestartsWithNewRunId(): void {
    $metadata = json_encode([
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => [
        'staff' => [
          'status' => 'pending',
          'code' => 'analysis_queued',
          'analysisRunId' => self::OLD_RUN_ID,
          'analysisStartedAt' => self::NOW - 3601,
          'worker' => ['attempt' => 1, 'maxAttempts' => 3],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    [$attachment, $file_path, $sent] = $this->storyDispatchFixture($metadata, 'pending');
    $dispatcher = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $sent['client']);

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertNotSame(self::OLD_RUN_ID, $stored['stories']['staff']['analysisRunId']);
    $this->assertSame(['attempt' => 2, 'maxAttempts' => 3], $stored['stories']['staff']['worker']);
    $this->assertSame($stored['stories']['staff']['analysisRunId'], $sent['json']['analysisRunId']);
  }

  /**
   * A queued legacy run keeps today's payload: no story, no run id.
   */
  public function testLegacyQueueItemAndPayloadCarryNoStory(): void {
    $item = NULL;
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(
      static function (array $data) use (&$item): bool {
        $item = $data;
        return TRUE;
      },
    );
    [$attachment, $file_path, $sent] = $this->storyDispatchFixture('{"version":1,"status":"not_requested","language":"de"}', 'not_requested', []);
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $this->assertTrue($dispatcher->enqueue($attachment['entity']));
    $this->assertSame(['attachmentId', 'applicationId'], array_keys($item));

    $result = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $sent['client'])
      ->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($file_path);
    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertArrayNotHasKey('story', $sent['json']->getArrayCopy());
    $this->assertArrayNotHasKey('analysisRunId', $sent['json']->getArrayCopy());
    $this->assertSame(1, json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR)['version']);
  }

  /**
   * External policies run story A after the claim on the guard derivative.
   *
   * Without consent this is story A's only run; it gets its own run id.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('externalDerivativePolicies')]
  public function testStoryExternalPolicyRunsCitizenOnGuardDerivativeAfterClaim(string $egress_policy): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    [$attachment, $state] = $this->mutableExternalAttachment($egress_policy, self::STORY_ANALYSIS);
    $state['field_analysis_metadata'] = '{"version":1,"status":"not_requested","language":"de"}';
    $original = '%PDF- original private fixture';
    $derivative = '%PDF- guarded derivative fixture';
    $guard_report = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($derivative),
        'sha256' => hash('sha256', $derivative),
      ],
      'findings' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $original_path = $this->temporaryFile($original);
    $derivative_path = $this->temporaryFile($derivative);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())->method('createDerivative')->willReturn([
      'status' => 'redacted',
      'reportJson' => $guard_report,
      'derivative' => $derivative,
      'mediaType' => 'application/pdf',
      'entityProfile' => 'full',
    ]);
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('prepareDirectory')->willReturn(TRUE);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->method('writeData')->willReturn($this->file(654, $derivative_path, strlen($derivative)));

    $sent = NULL;
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects($this->once())->method('request')->willReturnCallback(
      static function (string $method, string $uri, array $options) use (&$sent, $response): ResponseInterface {
        $sent = $options['json'] ?? [];
        return $response;
      },
    );
    $item = NULL;
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(
      static function (array $data) use (&$item): bool {
        $item = $data;
        return TRUE;
      },
    );
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $this->file(321, $original_path, strlen($original)), NULL, $this->file(654, $derivative_path, strlen($derivative))),
      $this->queueFactory($queue),
      $http_client,
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
      $guard,
      $file_system,
      $file_repository,
    );

    $this->assertTrue($dispatcher->enqueue($attachment));
    $this->assertSame('citizen', $item['story'] ?? NULL);
    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'citizen');
    @unlink($original_path);
    @unlink($derivative_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertSame('derivative', $sent['sourceKind']);
    $this->assertFalse($sent['citizenConsent']);
    $this->assertSame(base64_encode($derivative), $sent['data']);
    $this->assertSame('citizen', $sent['story']);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['citizen'], array_keys($stored['stories']));
    $this->assertSame($stored['stories']['citizen']['analysisRunId'], $sent['analysisRunId']);
    $this->assertSame('redacted', $state['field_guard_status']);
  }

  /**
   * A dispatch failure of story B keeps its run id and story A untouched.
   */
  public function testStoryDispatchFailureMarksOnlyStaff(): void {
    $citizen = ['status' => 'pending', 'analysisRunId' => self::CITIZEN_RUN_ID, 'analysisStartedAt' => self::NOW - 5];
    $metadata = json_encode([
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => ['citizen' => $citizen],
    ], JSON_THROW_ON_ERROR);
    [$attachment, $file_path] = $this->storyDispatchFixture($metadata, 'pending');

    $result = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $this->failingHttpClient())
      ->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
    @unlink($file_path);

    $this->assertTrue($result->retry);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($citizen, $stored['stories']['citizen']);
    $this->assertSame('failed', $stored['stories']['staff']['status']);
    $this->assertSame('worker_dispatch_failed', $stored['stories']['staff']['code']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $stored['stories']['staff']['analysisRunId']);
    $this->assertSame('pending', $attachment['status']);
  }

  /**
   * A story failure write waits out a held claim lock and the retry runs.
   *
   * The start write already gave the run a lease. Had the failure write given
   * up, the run would stay pending and the queue retry would be consumed by
   * that lease. It waits instead (a crashed holder frees the lock within its
   * 30 s TTL), so the run is failed and the retry dispatches again.
   */
  public function testStoryFailureWriteWaitsOutHeldClaimLockAndRetryDispatches(): void {
    $metadata = json_encode(['version' => 2, 'status' => 'not_requested', 'language' => 'de', 'stories' => []], JSON_THROW_ON_ERROR);
    [$attachment, $file_path] = $this->storyDispatchFixture($metadata, 'not_requested');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $claim_acquisitions = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(
      static function (string $name) use (&$claim_acquisitions): bool {
        if ($name !== 'beantragt_application.attachment_claim.77') {
          return TRUE;
        }
        // The start write gets the lock; the failure write meets a crashed
        // holder for its first acquire and two wait rounds, then gets it.
        $claim_acquisitions++;
        return $claim_acquisitions === 1 || $claim_acquisitions >= 5;
      },
    );
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->exactly(2))->method('request')->willThrowException(new \RuntimeException('worker unavailable'));
    $file = $this->file(321, $file_path, (int) filesize($file_path));
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment['entity'], $file, $application),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $client,
      $this->createMock(LoggerInterface::class),
      $lock,
      $this->time(),
      $this->renderingGuard(),
    );
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    try {
      $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
      $this->assertTrue($result->retry);
      $this->assertSame('analysis.dispatch_failed', $result->code);
      $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame('failed', $stored['stories']['staff']['status']);
      $this->assertSame('worker_dispatch_failed', $stored['stories']['staff']['code']);
      $first_run_id = $stored['stories']['staff']['analysisRunId'];

      // The queue retry is not consumed by a lease: it dispatches again.
      $retry = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
    }
    finally {
      @unlink($file_path);
    }
    $this->assertSame('analysis.dispatch_failed', $retry->code);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(2, $stored['stories']['staff']['worker']['attempt']);
    $this->assertNotSame($first_run_id, $stored['stories']['staff']['analysisRunId']);
  }

  /**
   * A failure write that cannot get the lock even after the TTL retries.
   *
   * Only a writer re-taking the lock for longer than its 30 s TTL causes
   * this; nothing is written and the run stays pending until the rerun.
   */
  public function testStoryFailureWriteMeetingLockHeldPastTtlRetries(): void {
    $metadata = json_encode(['version' => 2, 'status' => 'not_requested', 'language' => 'de', 'stories' => []], JSON_THROW_ON_ERROR);
    [$attachment, $file_path] = $this->storyDispatchFixture($metadata, 'not_requested');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $claim_acquisitions = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(
      static function (string $name) use (&$claim_acquisitions): bool {
        if ($name !== 'beantragt_application.attachment_claim.77') {
          return TRUE;
        }
        // The pending mark gets the lock, the failure write never does.
        return ++$claim_acquisitions === 1;
      },
    );
    $lock->expects($this->exactly(AttachmentAnalysisMetadataWriter::TERMINAL_WRITE_WAIT_ROUNDS))->method('wait');
    $file = $this->file(321, $file_path, (int) filesize($file_path));
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment['entity'], $file, $application),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $this->failingHttpClient(),
      $this->createMock(LoggerInterface::class),
      $lock,
      $this->time(),
    );
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
    @unlink($file_path);

    $this->assertTrue($result->retry);
    $this->assertSame('attachment.analysis_metadata_locked', $result->code);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('pending', $stored['stories']['staff']['status']);
  }

  /**
   * A legacy run kept from before a switch to a story profile is not queued.
   */
  public function testStoryProfileSkipsAttachmentHoldingLegacyRun(): void {
    $legacy = json_encode([
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
      'language' => 'de',
      'result' => ['outcome' => 'pass', 'extracted' => [['key' => 'gross_pay_month', 'value' => 2480]]],
    ], JSON_THROW_ON_ERROR);
    [$attachment, $file_path] = $this->storyDispatchFixture($legacy, 'complete');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $result = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $client)
      ->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff');
    @unlink($file_path);

    $this->assertFalse($result->retry);
    $this->assertSame('attachment.not_dispatchable', $result->code);
    $this->assertSame($legacy, $attachment['metadata']);
    $this->assertSame('complete', $attachment['status']);
  }

  /**
   * A sovereign when_possible run never stores its weaker derivative.
   *
   * The transient analysis_sovereign derivative keeps dates readable. It is
   * rendered and dropped, field_guard_* stays untouched, and a later
   * external_redacted run (policy switch) guards the original again with the
   * full entity profile instead of reusing anything.
   */
  public function testSovereignWhenPossibleNeverWritesGuardFieldsAndExternalBuildsOwnFullDerivative(): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    [$sovereign, $state] = $this->mutableExternalAttachment('sovereign_only', ['redaction' => 'when_possible'], 'openai_compatible');
    $original = '%PDF- original payslip fixture';
    $sovereign_derivative = '%PDF- sovereign derivative keeps dates';
    $full_derivative = '%PDF- fully redacted derivative';
    $original_path = $this->temporaryFile($original);
    $derivative_path = $this->temporaryFile($full_derivative);
    $original_file = $this->file(321, $original_path, strlen($original));
    $derivative_file = $this->file(654, $derivative_path, strlen($full_derivative));

    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())
      ->method('createDerivative')
      ->with($original, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn([
        'status' => 'redacted',
        'reportJson' => '{"status":"redacted"}',
        'derivative' => $sovereign_derivative,
        'mediaType' => 'application/pdf',
        'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
      ]);
    $guard->expects($this->once())
      ->method('renderPages')
      ->with($sovereign_derivative)
      ->willReturn([
        'pages' => [['page' => 1, 'mediaType' => 'image/jpeg', 'data' => self::JPEG_PAGE]],
        'pageCount' => 1,
        'truncated' => FALSE,
      ]);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->expects($this->never())->method('writeData');
    $sent = new \ArrayObject();
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(
      static function (string $method, string $uri, array $options) use ($sent, $response): ResponseInterface {
        $sent->append($options['json'] ?? []);
        return $response;
      },
    );

    $result = (new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($sovereign, $original_file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $client,
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
      $guard,
      $this->createMock(FileSystemInterface::class),
      $file_repository,
    ))->processQueuedAttachment(self::ATTACHMENT_UUID);

    $this->assertSame('analysis.dispatched', $result->code);
    $json = $sent[0];
    $this->assertArrayNotHasKey('data', $json);
    $this->assertSame('derivative', $json['renderedFrom']);
    $this->assertSame('redacted', $json['redaction']);
    $this->assertSame('original', $json['sourceKind']);
    $this->assertSame([['mediaType' => 'image/jpeg', 'data' => base64_encode(self::JPEG_PAGE)]], $json['pages']);
    $this->assertSame([], array_values(array_filter(
      $state['_written'] ?? [],
      static fn (string $field): bool => str_starts_with($field, 'field_guard_'),
    )));
    $this->assertSame('pending', $state['field_guard_status']);
    $this->assertSame('', $state['field_guard_report']);
    $this->assertNull($state['field_guard_derivative']);

    // The field switches to external_redacted: a full-profile derivative of
    // the original is created and stored, nothing of the sovereign run.
    [$external] = $this->mutableExternalAttachment('external_redacted', [], 'claude_sonnet', $state);
    $full_report = json_encode([
      'status' => 'redacted',
      'derivative' => [
        'mediaType' => 'application/pdf',
        'size' => strlen($full_derivative),
        'sha256' => hash('sha256', $full_derivative),
      ],
      'findings' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $full_guard = $this->createMock(DocumentGuardClientInterface::class);
    $full_guard->expects($this->once())
      ->method('createDerivative')
      ->with($original, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_FULL)
      ->willReturn([
        'status' => 'redacted',
        'reportJson' => $full_report,
        'derivative' => $full_derivative,
        'mediaType' => 'application/pdf',
        'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_FULL,
      ]);
    $full_guard->expects($this->never())->method('renderPages');
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('prepareDirectory')->willReturn(TRUE);
    $full_repository = $this->createMock(FileRepositoryInterface::class);
    $full_repository->expects($this->once())
      ->method('writeData')
      ->with($full_derivative, $this->stringEndsWith(self::ATTACHMENT_UUID . '.pdf'), FileExists::Replace)
      ->willReturn($derivative_file);

    $result = (new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($external, $original_file, NULL, $derivative_file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $client,
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
      $full_guard,
      $file_system,
      $full_repository,
    ))->processQueuedAttachment(self::ATTACHMENT_UUID);
    @unlink($original_path);
    @unlink($derivative_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertSame('redacted', $state['field_guard_status']);
    $this->assertSame($full_report, $state['field_guard_report']);
    $this->assertSame(654, $state['field_guard_derivative']);
    $this->assertSame('derivative', $sent[1]['sourceKind']);
    $this->assertSame(base64_encode($full_derivative), $sent[1]['data']);
    $this->assertArrayNotHasKey('pages', $sent[1]);
  }

  /**
   * A staff rerun of a sovereign PDF goes through the preparer, never raw.
   *
   * The rerun route already set story B pending with its run id; the queue
   * item bypasses the lease for exactly that run and keeps the run id.
   */
  public function testRerunSovereignPdfWhenPossibleGoesThroughPreparerWithRunId(): void {
    $rerun_id = str_repeat('9', 32);
    $citizen = [
      'status' => 'complete',
      'code' => 'analysis_complete',
      'analysisRunId' => self::CITIZEN_RUN_ID,
      'analysisStartedAt' => self::NOW - 7200,
      'result' => ['outcome' => 'pass', 'missingCount' => 0],
    ];
    $metadata = json_encode([
      'version' => 2,
      'status' => 'pending',
      'language' => 'de',
      'stories' => [
        'citizen' => $citizen,
        'staff' => [
          'status' => 'pending',
          'code' => 'analysis_queued',
          'analysisRunId' => $rerun_id,
          'analysisStartedAt' => time() - 5,
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    [$attachment, $file_path, $sent] = $this->storyDispatchFixture($metadata, 'pending', self::STORY_ANALYSIS + ['redaction' => 'when_possible']);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())
      ->method('createDerivative')
      ->with('%PDF private fixture', 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn([
        'status' => 'needs_review',
        // What the guard reports for a scanned PDF without a text layer.
        'reportJson' => '{"reasons":["embedded_image_without_ocr","empty_text_extraction"],"extraction":{"engine":"pdfminer.six","hasTextLayer":false,"textChars":0},"status":"needs_review"}',
        'derivative' => NULL,
        'mediaType' => NULL,
        'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
      ]);
    $guard->expects($this->once())
      ->method('renderPages')
      ->with('%PDF private fixture')
      ->willReturn([
        'pages' => [['page' => 1, 'mediaType' => 'image/jpeg', 'data' => self::JPEG_PAGE]],
        'pageCount' => 1,
        'truncated' => FALSE,
      ]);
    $dispatcher = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $sent['client'], NULL, $guard);

    // A plain queue item meets the fresh lease and does nothing.
    $this->assertSame('attachment.not_dispatchable', $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff')->code);
    // An item of an older rerun is obsolete.
    $this->assertSame('attachment.not_dispatchable', $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff', str_repeat('8', 32))->code);

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'staff', $rerun_id);
    @unlink($file_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $json = $sent['json']->getArrayCopy();
    $this->assertSame('staff', $json['story']);
    $this->assertSame($rerun_id, $json['analysisRunId']);
    $this->assertArrayNotHasKey('data', $json);
    $this->assertSame('application/pdf', $json['mediaType']);
    $this->assertSame('original', $json['renderedFrom']);
    $this->assertSame('unredacted_needs_review', $json['redaction']);
    $this->assertSame([['mediaType' => 'image/jpeg', 'data' => base64_encode(self::JPEG_PAGE)]], $json['pages']);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($citizen, $stored['stories']['citizen']);
    $this->assertSame($rerun_id, $stored['stories']['staff']['analysisRunId']);
    $this->assertSame('pending', $stored['stories']['staff']['status']);
    $this->assertSame(['attempt' => 1, 'maxAttempts' => 3], $stored['stories']['staff']['worker']);
  }

  /**
   * A citizen rerun retries an outage, then stops at the scan verdict.
   */
  public function testCitizenScanRetryNeverDispatchesOriginalOrStartsStaff(): void {
    $run_id = self::CITIZEN_RUN_ID;
    $staff = ['status' => 'complete', 'code' => 'analysis_complete'];
    [$attachment, $state] = $this->mutableExternalAttachment('sovereign_only', self::STORY_ANALYSIS + ['redaction' => 'when_possible'], 'openai_compatible');
    $state['field_analysis_status'] = 'pending';
    $state['field_analysis_metadata'] = json_encode([
      'version' => 2,
      'stories' => [
        'citizen' => ['status' => 'pending', 'analysisRunId' => $run_id],
        'staff' => $staff,
      ],
    ], JSON_THROW_ON_ERROR);
    $path = dirname(__DIR__, 3) . '/fixtures/attachment-precheck/scan.pdf';
    $scan = file_get_contents($path);
    $this->assertIsString($scan);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $verdict = [
      'status' => 'needs_review',
      'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
      'derivative' => NULL,
      'mediaType' => NULL,
    ];
    $guard->expects($this->exactly(2))->method('createDerivative')
      ->with($scan, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn(
        $verdict + ['reportJson' => '{"extraction":{"hasTextLayer":false},"reasons":["guard_analyzer_unavailable"]}'],
        $verdict + ['reportJson' => '{"extraction":{"hasTextLayer":false},"reasons":["empty_text_extraction"]}'],
      );
    $guard->expects($this->never())->method('renderPages');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $dispatcher = $this->dispatcherWithAttachmentAndFile($attachment, $path, $client, NULL, $guard);
    $worker = new AttachmentAnalysisQueueWorker([], AttachmentAnalysisDispatcher::QUEUE_NAME, [], $dispatcher);
    $item = ['attachmentId' => self::ATTACHMENT_UUID, 'story' => 'citizen', 'analysisRunId' => $run_id];

    try {
      $worker->processItem($item);
      $this->fail('The temporary guard outage must be retried.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertSame(AttachmentAnalysisDispatcher::RETRY_DELAY_SECONDS, $e->getDelay());
    }
    $stored = json_decode($state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('guard_unavailable', $stored['stories']['citizen']['code']);
    $worker->processItem($item);
    // A duplicate delivery of the finished run must not start another one.
    $worker->processItem($item);
    $stored = json_decode($state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $citizen = $stored['stories']['citizen'];
    $this->assertSame('failed', $citizen['status']);
    $this->assertSame('redaction_not_possible', $citizen['code']);
    $this->assertTrue($citizen['requiresHumanReview']);
    $this->assertSame($run_id, $citizen['analysisRunId']);
    $this->assertSame(2, $citizen['worker']['attempt']);
    $this->assertArrayNotHasKey('result', $citizen);
    $this->assertSame($staff, $stored['stories']['staff']);
  }

  /**
   * The single legacy queue after submission uses citizen protection.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('unredactableImageFiles')]
  public function testLegacyImageCannotBeDispatchedAfterClaim(string $filename, string $mime_type, bool $key_configured): void {
    if (!$key_configured) {
      putenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY');
      putenv('BEANTRAGT_ENV=production');
    }
    [$attachment, $state] = $this->mutableExternalAttachment('sovereign_only', ['redaction' => 'when_possible'], 'openai_compatible');
    $state['field_mime_type'] = $mime_type;
    $state['field_external_analysis_consent'] = '1';
    $path = dirname(__DIR__, 3) . '/fixtures/attachment-precheck/' . $filename;
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $dispatcher = $this->dispatcherWithAttachmentAndFile($attachment, $path, $client);

    $this->assertTrue($dispatcher->enqueue($attachment));
    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID);
    $this->assertFalse($result->retry);
    $this->assertSame('analysis.redaction_not_possible', $result->code);
    $record = json_decode($state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('failed', $record['status']);
    $this->assertSame('redaction_not_possible', $record['code']);
    $this->assertTrue($record['requiresHumanReview']);
    $this->assertFalse($dispatcher->enqueue($attachment));
    $this->assertSame('attachment.not_dispatchable', $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID)->code);
  }

  /**
   * Provides the two fictional images a citizen may choose as photo proof.
   */
  public static function unredactableImageFiles(): array {
    return [
      'roof photo' => ['roof.jpg', 'image/jpeg', TRUE],
      'mistaken payslip' => ['fictional-payslip.png', 'image/png', TRUE],
      'roof photo without model credentials' => ['roof.jpg', 'image/jpeg', FALSE],
    ];
  }

  /**
   * A switch to legacy processing after upload preserves the citizen skip.
   */
  public function testLegacyQueuePreservesTerminalCitizenRecordAfterProfileChange(): void {
    $metadata = json_encode([
      'version' => 2,
      'stories' => [
        'citizen' => ['status' => 'failed', 'code' => 'redaction_not_possible'],
      ],
    ], JSON_THROW_ON_ERROR);
    [$state, $path, $sent] = $this->storyDispatchFixture($metadata, 'failed', ['redaction' => 'none']);
    try {
      $dispatcher = $this->dispatcherWithAttachmentAndFile($state['entity'], $path, $sent['client']);
      $this->assertFalse($dispatcher->enqueue($state['entity']));
      $this->assertSame('attachment.not_dispatchable', $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID)->code);
      $this->assertCount(0, $sent['json']);
      $this->assertSame($metadata, $state['metadata']);
    }
    finally {
      unlink($path);
    }
  }

  /**
   * A legacy rerun sends its run id without a story and keeps it stored.
   */
  public function testLegacyRerunKeepsRunIdAndSendsItWithoutStory(): void {
    $rerun_id = str_repeat('7', 32);
    $started = time() - 5;
    $metadata = json_encode([
      'version' => 1,
      'status' => 'pending',
      'code' => 'analysis_queued',
      'language' => 'en',
      'analysisRunId' => $rerun_id,
      'analysisStartedAt' => $started,
    ], JSON_THROW_ON_ERROR);
    [$attachment, $file_path, $sent] = $this->storyDispatchFixture($metadata, 'pending', ['redaction' => 'none']);
    $dispatcher = $this->dispatcherWithAttachmentAndFile($attachment['entity'], $file_path, $sent['client']);

    $result = $dispatcher->processQueuedAttachment(self::ATTACHMENT_UUID, 'legacy', $rerun_id);
    @unlink($file_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $json = $sent['json']->getArrayCopy();
    $this->assertArrayNotHasKey('story', $json);
    $this->assertSame($rerun_id, $json['analysisRunId']);
    $this->assertSame('en', $json['language']);
    $this->assertArrayHasKey('pages', $json);
    $stored = json_decode($attachment['metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(1, $stored['version']);
    $this->assertSame($rerun_id, $stored['analysisRunId']);
    $this->assertSame($started, $stored['analysisStartedAt']);
    $this->assertSame('pending', $attachment['status']);
  }

  /**
   * A rerun of story A under external_allowed with consent sends the original.
   *
   * The queue's own run keeps the guard derivative; only the staff rerun
   * of a consented story A reads the original, and Drupal sends it.
   */
  public function testRerunCitizenExternalAllowedWithConsentSendsOriginalFromDrupal(): void {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $rerun_id = str_repeat('6', 32);
    [$attachment, $state] = $this->mutableExternalAttachment('external_allowed', self::STORY_ANALYSIS + ['redaction' => 'when_possible']);
    $state['field_external_analysis_consent'] = '1';
    $state['field_analysis_status'] = 'pending';
    $state['field_analysis_metadata'] = json_encode([
      'version' => 2,
      'status' => 'pending',
      'stories' => [
        'citizen' => ['status' => 'pending', 'analysisRunId' => $rerun_id, 'analysisStartedAt' => self::NOW],
      ],
    ], JSON_THROW_ON_ERROR);
    $original = '%PDF- consented original';
    $original_path = $this->temporaryFile($original);
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->expects($this->never())->method('renderPages');
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('POST', 'http://node-dev:3000/api/internal/attachments/analyze', $this->callback(
        static fn (array $options): bool => ($options['json']['sourceKind'] ?? '') === 'original'
          && ($options['json']['data'] ?? '') === base64_encode($original)
          && ($options['json']['citizenConsent'] ?? FALSE) === TRUE
          && ($options['json']['story'] ?? '') === 'citizen'
          && ($options['json']['analysisRunId'] ?? '') === $rerun_id,
      ))
      ->willReturn($response);

    $result = (new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $this->file(321, $original_path, strlen($original))),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $client,
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
      $guard,
    ))->processQueuedAttachment(self::ATTACHMENT_UUID, 'citizen', $rerun_id);
    @unlink($original_path);

    $this->assertSame('analysis.dispatched', $result->code);
    $this->assertSame('pending', $state['field_guard_status']);
  }

  /**
   * The rerun item for a queue item carries the story and run id.
   *
   * @covers ::enqueueRerun
   */
  public function testEnqueueRerunQueuesStoryAndRunId(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem')->with([
      'attachmentId' => self::ATTACHMENT_UUID,
      'applicationId' => 42,
      'story' => 'staff',
      'analysisRunId' => str_repeat('5', 32),
    ]);
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $dispatcher->enqueueRerun($this->attachment(), 'staff', str_repeat('5', 32));
  }

  /**
   * Story A whose upload run outlived the claim is queued once it failed.
   *
   * At the claim the run still holds its lease, so the claim-time enqueue
   * creates no item; the failure write then queues story A.
   */
  public function testUploadRunFailingAfterClaimQueuesCitizenStoryAgain(): void {
    $items = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(
      static function (array $data) use (&$items): bool {
        $items[] = $data;
        return TRUE;
      },
    );
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->entityTypeManager(),
      $this->queueFactory($queue),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->time(),
    );
    $citizen_record = static fn (string $status): string => json_encode([
      'version' => 2,
      'status' => $status,
      'language' => 'de',
      'stories' => [
        'citizen' => [
          'status' => $status,
          'analysisRunId' => self::CITIZEN_RUN_ID,
          'analysisStartedAt' => self::NOW - 30,
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $external = $this->fieldSchemaReference(TRUE, 'external_allowed', 'claude_sonnet', self::STORY_ANALYSIS);

    // Claim while the upload run is still pending: nothing is queued.
    $this->assertFalse($dispatcher->enqueue($this->attachment([
      'field_schema' => $external,
      'field_external_analysis_consent' => $this->fieldValue('1'),
      'field_analysis_status' => $this->fieldValue('pending'),
      'field_analysis_metadata' => $this->fieldValue($citizen_record('pending')),
    ])));
    $this->assertSame([], $items);

    $failed = $this->attachment([
      'field_schema' => $external,
      'field_external_analysis_consent' => $this->fieldValue('1'),
      'field_analysis_status' => $this->fieldValue('failed'),
      'field_analysis_metadata' => $this->fieldValue($citizen_record('failed')),
    ]);
    $this->assertTrue($dispatcher->enqueueAfterUploadRunFailed($failed, 'citizen'));
    $this->assertSame([[
      'attachmentId' => self::ATTACHMENT_UUID,
      'applicationId' => 42,
      'story' => 'citizen',
    ],
    ], $items);

    // The queue runs story B under sovereign_only: a failed story A there
    // queues nothing, and a story the queue does not run is never queued.
    $this->assertFalse($dispatcher->enqueueAfterUploadRunFailed($this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible', self::STORY_ANALYSIS),
      'field_analysis_status' => $this->fieldValue('failed'),
      'field_analysis_metadata' => $this->fieldValue($citizen_record('failed')),
    ]), 'citizen'));
    $this->assertFalse($dispatcher->enqueueAfterUploadRunFailed($failed, 'staff'));
    // Unclaimed: the claim-time enqueue covers it.
    $this->assertFalse($dispatcher->enqueueAfterUploadRunFailed($this->attachment([
      'field_application' => $this->emptyField(),
      'field_schema' => $external,
      'field_analysis_metadata' => $this->fieldValue($citizen_record('failed')),
    ]), 'citizen'));
    $this->assertCount(1, $items);
  }

  /**
   * Builds a sovereign story attachment whose analysis fields are mutable.
   *
   * @param string $metadata
   *   Stored analysis metadata JSON.
   * @param string $status
   *   Stored overall analysis status.
   * @param array<string, mixed>|null $analysis
   *   Extra analysis keys, defaults to the story profile.
   *
   * @return array{0: \ArrayObject<string, mixed>, 1: string, 2: \ArrayObject<string, mixed>}
   *   Attachment state (entity, metadata, status), file path and sent request.
   */
  private function storyDispatchFixture(string $metadata, string $status = 'complete', ?array $analysis = NULL): array {
    $file_path = $this->temporaryFile('%PDF private fixture');
    $holder = new \ArrayObject(['metadata' => $metadata, 'status' => $status]);
    $metadata_field = $this->createMock(FieldItemListInterface::class);
    $metadata_field->method('isEmpty')->willReturn(FALSE);
    $metadata_field->method('__isset')->willReturnCallback(static fn (string $property): bool => $property === 'value');
    $metadata_field->method('__get')->willReturnCallback(static fn (string $property): mixed => $property === 'value' ? $holder['metadata'] : NULL);
    $status_field = $this->createMock(FieldItemListInterface::class);
    $status_field->method('isEmpty')->willReturn(FALSE);
    $status_field->method('__isset')->willReturnCallback(static fn (string $property): bool => $property === 'value');
    $status_field->method('__get')->willReturnCallback(static fn (string $property): mixed => $property === 'value' ? $holder['status'] : NULL);
    $entity = $this->attachment([
      'field_schema' => $this->fieldSchemaReference(TRUE, 'sovereign_only', 'openai_compatible', $analysis ?? self::STORY_ANALYSIS),
      'field_analysis_metadata' => $metadata_field,
      'field_analysis_status' => $status_field,
    ]);
    $entity->method('getChangedTime')->willReturn(self::NOW - 4000);
    $entity->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($entity, $holder): ApplicationAttachmentInterface {
        if ($field === 'field_analysis_metadata') {
          $holder['metadata'] = (string) $value;
        }
        if ($field === 'field_analysis_status') {
          $holder['status'] = (string) $value;
        }
        return $entity;
      },
    );
    $holder['entity'] = $entity;

    $sent = new \ArrayObject(['json' => new \ArrayObject()]);
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getStatusCode')->willReturn(200);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(
      static function (string $method, string $uri, array $options) use ($sent, $response): ResponseInterface {
        $sent['json'] = new \ArrayObject($options['json'] ?? []);
        return $response;
      },
    );
    $sent['client'] = $client;
    return [$holder, $file_path, $sent];
  }

  /**
   * Returns a fixed request time.
   */
  private function time(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    return $time;
  }

  /**
   * Builds an attachment entity mock.
   *
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $overrides
   *   Field overrides.
   */
  private function attachment(array $overrides = []): ApplicationAttachmentInterface {
    $fields = [
      'field_application' => $this->fieldReference(42, self::APPLICATION_UUID),
      'field_schema' => $this->fieldSchemaReference(TRUE),
      'field_file' => $this->fieldTarget(321),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue(AttachmentScanResult::STATUS_CLEAN),
      'field_analysis_status' => $this->fieldValue('not_requested'),
      'field_analysis_metadata' => $this->fieldValue(''),
      'field_external_analysis_consent' => $this->fieldValue('0'),
      'field_guard_status' => $this->fieldValue('pending'),
      'field_guard_report' => $this->fieldValue(''),
      'field_guard_derivative' => $this->emptyField(),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_size' => $this->fieldValue(1234),
      ...$overrides,
    ];

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(77);
    $attachment->method('uuid')->willReturn(self::ATTACHMENT_UUID);
    $attachment->method('getOwnerId')->willReturn(9);
    $attachment->method('hasField')->willReturnCallback(static fn (string $field): bool => array_key_exists($field, $fields));
    $attachment->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    return $attachment;
  }

  /**
   * Builds an external attachment whose persisted lifecycle fields can change.
   *
   * @return array{0: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface, 1: \ArrayObject<string, mixed>}
   *   Attachment and observable field state.
   */
  private function mutableExternalAttachment(string $egress_policy = 'external_allowed', array $extra_analysis = [], string $model_class = 'claude_sonnet', ?\ArrayObject $shared_state = NULL): array {
    $state = $shared_state ?? new \ArrayObject([
      'field_analysis_status' => 'not_requested',
      'field_analysis_metadata' => '',
      'field_external_analysis_consent' => '0',
      'field_guard_status' => 'pending',
      'field_guard_report' => '',
      'field_guard_derivative' => NULL,
      'field_mime_type' => 'application/pdf',
      'field_size' => 1234,
    ]);
    $fixed_fields = [
      'field_application' => $this->fieldReference(42, self::APPLICATION_UUID),
      'field_schema' => $this->fieldSchemaReference(TRUE, $egress_policy, $model_class, $extra_analysis),
      'field_file' => $this->fieldTarget(321),
      'field_field_id' => $this->fieldValue('nachweis'),
      'field_scan_status' => $this->fieldValue(AttachmentScanResult::STATUS_CLEAN),
    ];

    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(77);
    $attachment->method('uuid')->willReturn(self::ATTACHMENT_UUID);
    $attachment->method('getOwnerId')->willReturn(9);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => array_key_exists($field, $fixed_fields) || $state->offsetExists($field),
    );
    $attachment->method('get')->willReturnCallback(function (string $field) use ($fixed_fields, $state): FieldItemListInterface {
      if (array_key_exists($field, $fixed_fields)) {
        return $fixed_fields[$field];
      }
      if ($field === 'field_guard_derivative') {
        $target_id = $state[$field];
        return is_int($target_id) ? $this->fieldTarget($target_id) : $this->emptyField();
      }
      return $state->offsetExists($field)
        ? $this->fieldValue($state[$field])
        : $this->emptyField();
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state): ApplicationAttachmentInterface {
        $state[$field] = is_array($value) && array_key_exists('target_id', $value)
          ? (int) $value['target_id']
          : $value;
        $state['_written'] = [...($state['_written'] ?? []), $field];
        return $attachment;
      },
    );
    return [$attachment, $state];
  }

  /**
   * Builds an entity type manager mock.
   */
  private function entityTypeManager(?ApplicationAttachmentInterface $attachment = NULL, ?FileInterface $file = NULL, ?ApplicationInterface $application = NULL, ?FileInterface $derivative_file = NULL, ?FileInterface $initial_derivative_file = NULL): EntityTypeManagerInterface {
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('loadByProperties')
      ->with(['uuid' => self::ATTACHMENT_UUID])
      ->willReturn($attachment ? [$attachment] : []);

    $file_storage = $this->createMock(EntityStorageInterface::class);
    $derivative_loads = 0;
    $file_storage->method('load')->willReturnCallback(
      static function (int $id) use ($file, $derivative_file, $initial_derivative_file, &$derivative_loads): ?FileInterface {
        if ($id === 321) {
          return $file;
        }
        if ($id === 654) {
          return $initial_derivative_file instanceof FileInterface && $derivative_loads++ === 0
            ? $initial_derivative_file
            : $derivative_file;
        }
        return NULL;
      },
    );

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('load')->with(42)->willReturn($application);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(
      static fn (string $entity_type): EntityStorageInterface => match ($entity_type) {
        'application_attachment' => $attachment_storage,
        'application' => $application_storage,
        'file' => $file_storage,
        default => throw new \InvalidArgumentException($entity_type),
      },
    );
    return $manager;
  }

  /**
   * Builds a dispatcher for one readable file attachment.
   */
  private function dispatcherWithAttachmentAndFile(ApplicationAttachmentInterface $attachment, string $file_path, ClientInterface $http_client, ?LoggerInterface $logger = NULL, ?DocumentGuardClientInterface $document_guard = NULL): AttachmentAnalysisDispatcher {
    putenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL=http://node-dev:3000');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($file_path);
    $file->method('getSize')->willReturn(filesize($file_path) ?: 0);
    $file->method('getMimeType')->willReturn('application/pdf');

    return new AttachmentAnalysisDispatcher(
      $this->entityTypeManager($attachment, $file),
      $this->queueFactory($this->createMock(QueueInterface::class)),
      $http_client,
      $logger ?? $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      $document_guard ?? $this->renderingGuard(),
    );
  }

  /**
   * Builds a guard that renders one JPEG page and never redacts.
   *
   * Sovereign PDFs leave Drupal as rendered pages only.
   */
  private function renderingGuard(int $page_count = 1, bool $truncated = FALSE): DocumentGuardClientInterface {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->method('renderPages')->willReturn([
      'pages' => array_map(
        static fn (int $page): array => ['page' => $page, 'mediaType' => 'image/jpeg', 'data' => self::JPEG_PAGE],
        range(1, min($page_count, 4)),
      ),
      'pageCount' => $page_count,
      'truncated' => $truncated,
    ]);
    return $guard;
  }

  /**
   * Invokes the private Intelligence authorization builder.
   */
  private function intelligenceAuthorizationHeader(): string {
    $dispatcher = new AttachmentAnalysisDispatcher(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(QueueFactory::class),
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $method = new \ReflectionMethod($dispatcher, 'intelligenceServiceAuthorizationHeader');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($dispatcher);
  }

  /**
   * Builds an attachment and a temporary readable file.
   *
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $overrides
   *   Field overrides.
   *
   * @return array{0: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface, 1: string}
   *   Attachment and file path.
   */
  private function attachmentWithReadableFile(array $overrides = []): array {
    $file_path = $this->temporaryFile('%PDF private fixture');

    return [$this->attachment($overrides), $file_path];
  }

  /**
   * Creates a readable temporary file.
   */
  private function temporaryFile(string $contents): string {
    $file_path = tempnam(sys_get_temp_dir(), 'attachment-analysis-');
    $this->assertIsString($file_path);
    file_put_contents($file_path, $contents);
    return $file_path;
  }

  /**
   * Builds one file entity fixture.
   */
  private function file(int $id, string $path, int $size): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($id);
    $file->method('getFileUri')->willReturn($path);
    $file->method('getSize')->willReturn($size);
    $file->method('getMimeType')->willReturn('application/pdf');
    $file->method('getFilename')->willReturn(basename($path));
    return $file;
  }

  /**
   * Builds an HTTP client that fails dispatch.
   */
  private function failingHttpClient(): ClientInterface {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willThrowException(new \RuntimeException('worker unavailable'));
    return $http_client;
  }

  /**
   * Builds a queue factory mock.
   */
  private function queueFactory(QueueInterface $queue): QueueFactory {
    $factory = $this->createMock(QueueFactory::class);
    $factory->method('get')
      ->with(AttachmentAnalysisDispatcher::QUEUE_NAME)
      ->willReturn($queue);
    return $factory;
  }

  /**
   * Builds a field list exposing a target_id property.
   */
  private function fieldTarget(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn (string $property): bool => $property === 'target_id');
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'target_id' ? $target_id : NULL,
    );
    return $field;
  }

  /**
   * Builds a field list exposing a referenced entity and target_id.
   */
  private function fieldReference(int $target_id, string $uuid): FieldItemListInterface {
    $entity = new class($target_id, $uuid) {

      /**
       * Constructs a referenced entity fixture.
       */
      public function __construct(private readonly int $id, private readonly string $uuid) {
      }

      /**
       * Returns the fixture entity ID.
       */
      public function id(): int {
        return $this->id;
      }

      /**
       * Returns the fixture UUID.
       */
      public function uuid(): string {
        return $this->uuid;
      }

    };

    return $this->fieldEntityReference($target_id, $entity);
  }

  /**
   * Builds a schema field list exposing an analysis-enabled file field.
   */
  private function fieldSchemaReference(bool $analysis_enabled, string $egress_policy = 'sovereign_only', ?string $model_class = 'openai_compatible', array $extra_analysis = []): FieldItemListInterface {
    $field = [
      'id' => 'nachweis',
      'type' => 'file',
    ];
    if ($analysis_enabled) {
      $field['analysis'] = [
        'enabled' => TRUE,
        'egressPolicy' => $egress_policy,
      ];
      if ($model_class !== NULL) {
        $field['analysis']['modelClass'] = $model_class;
      }
      $field['analysis'] += $extra_analysis;
    }

    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('uuid')->willReturn(self::FORM_UUID);
    $schema->method('getSchema')->willReturn(json_encode([
      'sections' => [
        [
          'id' => 'anlagen',
          'fields' => [$field],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    return $this->fieldEntityReference(12, $schema);
  }

  /**
   * Builds a field list exposing a referenced entity and target_id.
   */
  private function fieldEntityReference(int $target_id, object $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn (string $property): bool => in_array($property, ['entity', 'target_id'], TRUE));
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => $target_id,
        default => NULL,
      },
    );
    return $field;
  }

  /**
   * Builds a field list exposing a scalar value property.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn (string $property): bool => $property === 'value');
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds a mutable scalar field list for entity lifecycle assertions.
   */
  private function mutableFieldValue(mixed &$value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')
      ->willReturnCallback(static fn (string $property): bool => $property === 'value');
    $field->method('__get')->willReturnCallback(
      static function (string $property) use (&$value): mixed {
        return $property === 'value' ? $value : NULL;
      },
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

}
