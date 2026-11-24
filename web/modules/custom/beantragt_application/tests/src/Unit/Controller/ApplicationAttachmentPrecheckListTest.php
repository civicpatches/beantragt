<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\beantragt_application\AttachmentPrecheckProviderInterface;
use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\AttachmentPrecheck;
use Drupal\beantragt_application\Service\AttachmentScanService;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * The pre-check block on the staff attachment list, fictional data.
 *
 * Attachment 91 is emitted, 92 belongs to a sensitive field the reader may
 * not see, 93 is quarantined and 94 has no file.
 */
#[CoversClass(ApplicationAttachmentApiController::class)]
#[Group('beantragt_application')]
final class ApplicationAttachmentPrecheckListTest extends UnitTestCase {

  /**
   * A complete finding block in wire order.
   */
  private const BLOCK = [
    'state' => 'finding',
    'fieldId' => 'public_proof',
    'label' => 'Fictional proof',
    'proofStatus' => NULL,
    'confidence' => 'high',
    'page' => 1,
    'pagesChecked' => 1,
    'pageCount' => 1,
    'truncated' => FALSE,
    'codes' => ['person_unmatched'],
    'criteria' => [['key' => 'legible', 'title' => NULL, 'explanation' => NULL, 'answer' => 'met']],
    'notices' => [['code' => 'person_unmatched', 'params' => []]],
  ];

  /**
   * Keys a provider might add that the contract never delivers.
   */
  private const FOREIGN = [
    'personBlock' => 'household#0',
    'itemId' => '00000000-0000-4000-8000-0000000000b1',
    'provider' => ['name' => 'Fictional provider'],
  ];

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Logger mock.
   */
  private LoggerInterface&MockObject $logger;

  /**
   * Access log mock.
   */
  private ProtectedAccessLog&MockObject $accessLog;

  /**
   * Original filename of every attachment.
   */
  private string $originalFilename = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->accessLog = $this->createMock(ProtectedAccessLog::class);

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);
    $account->method('hasPermission')->willReturn(FALSE);
    $url_generator = $this->createMock(UrlGeneratorInterface::class);
    $url_generator->method('generateFromRoute')->willReturnCallback(
      static fn(string $route, array $parameters): string => '/api/admin/applications/' . $parameters['application'] . '/attachments/' . $parameters['attachment_uuid'],
    );
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    $container->set('url_generator', $url_generator);
    \Drupal::setContainer($container);
  }

  /**
   * The provider is asked once, for emitted attachments only.
   *
   * Blocks it returns for any other attachment never reach the list, and the
   * emitted block arrives bounded to the contract keys.
   */
  public function testBlockOnlyOnEmittedAttachments(): void {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->expects($this->once())->method('forApplication')
      ->with($this->isInstanceOf(ApplicationInterface::class), [91])
      ->willReturn([
        91 => self::FOREIGN + self::BLOCK,
        92 => self::BLOCK,
        93 => self::BLOCK,
        94 => self::BLOCK,
      ]);
    $this->accessLog->expects($this->once())->method('record')
      ->with($this->isInstanceOf(ApplicationInterface::class), $this->isInstanceOf(AccountInterface::class), 'attachment_list');

    $data = $this->list($this->controller($provider))['data'];

    $this->assertSame(['00000000-0000-4000-8000-000000000091', '00000000-0000-4000-8000-000000000093'], array_column($data, 'id'));
    $this->assertSame(self::BLOCK, $data[0]['precheck']);
    $this->assertArrayNotHasKey('precheck', $data[1], 'A quarantined attachment gets no block.');
  }

  /**
   * Without a provider the response is byte-identical to the list today.
   */
  public function testListUnchangedWithoutProvider(): void {
    $this->accessLog->expects($this->never())->method('record');
    $empty = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $empty->method('forApplication')->willReturn([]);

    $without = $this->listContent($this->controller(NULL, FALSE));
    $this->assertSame($without, $this->listContent($this->controller(NULL)));
    $this->assertSame($without, $this->listContent($this->controller($empty)));
    $this->assertStringNotContainsString('precheck', $without);
  }

  /**
   * A provider failure keeps the attachments and logs no value.
   */
  public function testProviderExceptionKeepsAttachments(): void {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->method('forApplication')->willThrowException(new \RuntimeException('Fictional Person scan.pdf'));
    $this->accessLog->expects($this->never())->method('record');
    $this->logger->expects($this->once())->method('warning')->with(
      $this->stringContains('pre-check'),
      $this->callback(static fn(array $context): bool => $context === ['@class' => \RuntimeException::class]),
    );

    $without = $this->listContent($this->controller(NULL, FALSE));
    $this->assertSame($without, $this->listContent($this->controller($provider)));
  }

  /**
   * Every emitted block is audited, whatever the file names say (D2).
   */
  #[DataProvider('filenames')]
  public function testAuditIndependentOfFileNames(string $original): void {
    $this->originalFilename = $original;
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->method('forApplication')->willReturn([91 => self::BLOCK]);
    $this->accessLog->expects($this->once())->method('record')->with($this->anything(), $this->anything(), 'attachment_list');

    $this->assertSame(self::BLOCK, $this->list($this->controller($provider))['data'][0]['precheck']);
  }

  /**
   * File names with and without their own list audit.
   *
   * @return array<string, array{string}>
   *   Original filename.
   */
  public static function filenames(): array {
    return ['no original name' => [''], 'equal name' => ['stored.pdf'], 'own name' => ['Fictional scan.pdf']];
  }

  /**
   * No block leaves when its read cannot be recorded; attachments stay.
   */
  public function testNoBlockWhenAuditFails(): void {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->method('forApplication')->willReturn([91 => self::BLOCK]);
    $this->accessLog->expects($this->once())->method('record')->willThrowException(new \RuntimeException('database down'));
    $this->logger->expects($this->once())->method('error')->with(
      $this->stringContains('could not be audited'),
      ['@class' => \RuntimeException::class],
    );

    $without = $this->listContent($this->controller(NULL, FALSE));
    $this->assertSame($without, $this->listContent($this->controller($provider)));
  }

  /**
   * Without an access log no block leaves either.
   */
  public function testNoBlockWithoutAccessLog(): void {
    $provider = $this->createMock(AttachmentPrecheckProviderInterface::class);
    $provider->method('forApplication')->willReturn([91 => self::BLOCK]);
    $this->logger->expects($this->once())->method('error')->with($this->stringContains('cannot be audited'));

    $content = $this->listContent($this->controller($provider, TRUE, FALSE));
    $this->assertStringNotContainsString('precheck', $content);
    $this->assertCount(2, json_decode($content, TRUE)['data']);
  }

  /**
   * Builds the controller with an optional collector and access log.
   */
  private function controller(?AttachmentPrecheckProviderInterface $provider, bool $collector = TRUE, bool $access_log = TRUE): ApplicationAttachmentApiController {
    $precheck = NULL;
    if ($collector) {
      $precheck = new AttachmentPrecheck();
      if ($provider !== NULL) {
        $precheck->addProvider($provider);
      }
    }
    return new ApplicationAttachmentApiController(
      $this->entityTypeManager(),
      $this->createMock(FloodInterface::class),
      $this->logger,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class),
      $this->createMock(TimeInterface::class),
      new AttachmentScanService(),
      $this->createMock(Connection::class),
      $this->createMock(LockBackendInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      accessLog: $access_log ? $this->accessLog : NULL,
      precheck: $precheck,
    );
  }

  /**
   * Lists the fixture application's attachments.
   */
  private function list(ApplicationAttachmentApiController $controller): array {
    return json_decode($this->listContent($controller), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Lists the fixture application's attachments as raw response content.
   */
  private function listContent(ApplicationAttachmentApiController $controller): string {
    $response = $controller->listApplicationAttachments($this->application());
    $this->assertSame(200, $response->getStatusCode());
    // Responses stay uncached, block or not.
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    return (string) $response->getContent();
  }

  /**
   * Storage for the four fixture attachments and their files.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    if (isset($this->entityTypeManager)) {
      return $this->entityTypeManager;
    }
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([91, 92, 93, 94]);
    $attachments = [
      91 => $this->attachment(91, 'public_proof', 321),
      92 => $this->attachment(92, 'secret_proof', 321),
      93 => $this->attachment(93, 'public_proof', 321, 'quarantined'),
      94 => $this->attachment(94, 'public_proof', NULL),
    ];
    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('getQuery')->willReturn($query);
    $attachment_storage->method('loadMultiple')->willReturn($attachments);
    $file = $this->createMock(FileInterface::class);
    $file->method('getFilename')->willReturn('stored.pdf');
    $file->method('getSize')->willReturn(2048);
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->willReturn($file);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(static fn(string $type): EntityStorageInterface => match ($type) {
      'application_attachment' => $attachment_storage,
      'file' => $file_storage,
      default => throw new \InvalidArgumentException($type),
    });
    return $this->entityTypeManager = $manager;
  }

  /**
   * Builds one attachment.
   */
  private function attachment(int $id, string $field_id, ?int $file_id, ?string $scan_status = NULL): ApplicationAttachmentInterface {
    $fields = [
      'field_field_id' => $this->value($field_id),
      'field_original_filename' => $this->value($this->originalFilename),
      'field_mime_type' => $this->value('application/pdf'),
      'field_analysis_status' => $this->value('not_requested'),
      'created' => $this->value(1782862200),
    ];
    if ($file_id !== NULL) {
      $fields['field_file'] = $this->target($file_id);
    }
    if ($scan_status !== NULL) {
      $fields['field_scan_status'] = $this->value($scan_status);
    }
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn($id);
    $attachment->method('uuid')->willReturn(sprintf('00000000-0000-4000-8000-%012d', $id));
    $attachment->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    return $attachment;
  }

  /**
   * The fixture application with one open and one sensitive file field.
   */
  private function application(): ApplicationInterface {
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('getSchema')->willReturn((string) json_encode([
      'sections' => [[
        'id' => 'main',
        'fields' => [
        ['id' => 'public_proof', 'type' => 'file', 'label' => 'Fictional proof', 'sensitive' => FALSE],
        ['id' => 'secret_proof', 'type' => 'file', 'label' => 'Fictional secret proof', 'sensitive' => TRUE],
        ],
      ],
      ],
    ]));
    $schema = $this->createMock(FieldItemListInterface::class);
    $schema->method('isEmpty')->willReturn(FALSE);
    $schema->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? $form : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(54);
    $application->method('access')->willReturnCallback(static fn(string $operation): bool => $operation === 'view');
    $application->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_schema');
    $application->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => $field === 'field_schema' ? $schema : $this->emptyField());
    return $application;
  }

  /**
   * A field list with a scalar value.
   */
  private function value(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(static fn(string $property): bool => $property === 'value');
    $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $value : NULL);
    return $field;
  }

  /**
   * A field list with a target id.
   */
  private function target(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'target_id' ? $target_id : NULL);
    return $field;
  }

  /**
   * An empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

}
