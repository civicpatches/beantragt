<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\PublicApplicationAttachmentClaimService;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests public application attachment claim handling.
 */
#[CoversClass(PublicApplicationAttachmentClaimService::class)]
#[Group('beantragt_application')]
final class PublicApplicationAttachmentClaimServiceTest extends UnitTestCase {

  /**
   * Application attachment storage mock.
   */
  private EntityStorageInterface $attachmentStorage;

  /**
   * File storage mock.
   */
  private EntityStorageInterface $fileStorage;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Time service mock.
   */
  private TimeInterface $time;

  /**
   * Lock backend mock.
   */
  private LockBackendInterface $lock;

  /**
   * Service under test.
   */
  private PublicApplicationAttachmentClaimService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->attachmentStorage = $this->createMock(EntityStorageInterface::class);
    $this->fileStorage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'application_attachment' => $this->attachmentStorage,
        'file' => $this->fileStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );
    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1781431200);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->service = new PublicApplicationAttachmentClaimService(
      $this->entityTypeManager,
      $this->configFactory(),
      $this->time,
      $this->lock,
    );
  }

  /**
   * A valid uploaded attachment claim is resolved to submission metadata.
   */
  public function testValidateClaimsAcceptsUploadedAttachment(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $attachment = $this->claimableAttachment(size: 1234);
    $this->attachmentStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(['uuid' => '1fded50b-9249-4af7-88a5-4ea2245a10bb'])
      ->willReturn([$attachment]);

    try {
      $result = $this->service->validateClaims(
        [$this->claimPayload()],
        $this->form(),
        $this->context(),
      );
    }
    finally {
      @unlink($tmp);
    }

    $this->assertNull($result['error']);
    $this->assertSame($attachment, $result['entities'][123]);
    $this->assertSame('evidence.pdf', $result['byField']['evidence'][0]['filename']);
    $this->assertSame(1234, $result['byField']['evidence'][0]['size']);
  }

  /**
   * The validation under the claim lock reloads past the entity cache.
   *
   * A story A callback may finish between the first validation and the claim
   * lock. The second pass must hand claim() the reloaded entity, so the saved
   * attachment carries the newer stories.citizen and not the cached snapshot.
   */
  public function testValidateClaimsUnderLockReloadsAndClaimSavesFreshEntity(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);

    $pending = '{"version":2,"status":"pending","stories":{"citizen":{"status":"pending","analysisRunId":"run-1"}}}';
    $complete = '{"version":2,"status":"complete","stories":{"citizen":{"status":"complete","analysisRunId":"run-1"}}}';
    $stale = $this->claimableAttachment(size: 1234, analysis_metadata: $pending);
    $fresh = $this->claimableAttachment(size: 1234, analysis_metadata: $complete);
    // The entity cache keeps returning the snapshot of the first pass.
    $this->attachmentStorage->method('loadByProperties')->willReturn([$stale]);
    $this->attachmentStorage->expects($this->once())->method('resetCache')->with([123]);
    $this->attachmentStorage->expects($this->once())->method('load')->with(123)->willReturn($fresh);

    try {
      $first = $this->service->validateClaims([$this->claimPayload()], $this->form(), $this->context());
      $locked = $this->service->validateClaims([$this->claimPayload()], $this->form(), $this->context(), NULL, TRUE);
    }
    finally {
      @unlink($tmp);
    }

    $this->assertSame($stale, $first['entities'][123]);
    $this->assertNull($locked['error']);
    $this->assertSame($fresh, $locked['entities'][123]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(7);
    $application->method('getOwnerId')->willReturn(42);
    $stale->expects($this->never())->method('save');
    $fresh->expects($this->once())->method('save');
    $this->service->claim($locked['entities'], $application);

    $saved = json_decode((string) $fresh->get('field_analysis_metadata')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('complete', $saved['stories']['citizen']['status']);
  }

  /**
   * Aggregate attachment limits are enforced before persistence.
   */
  public function testValidateClaimsRejectsAggregateSizeLimit(): void {
    $service = new PublicApplicationAttachmentClaimService(
      $this->entityTypeManager,
      $this->configFactory(1000),
      $this->time,
      $this->lock,
    );

    $tmp = tempnam(sys_get_temp_dir(), 'beantragt_attachment_');
    $this->assertIsString($tmp);
    file_put_contents($tmp, 'file');

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($tmp);
    $this->fileStorage->method('load')->with(321)->willReturn($file);
    $this->attachmentStorage->method('loadByProperties')->willReturn([
      $this->claimableAttachment(size: 1234),
    ]);

    try {
      $result = $service->validateClaims([$this->claimPayload()], $this->form(), $this->context());
    }
    finally {
      @unlink($tmp);
    }

    $this->assertSame('attachment.total_size_exceeded', $result['error']['code']);
    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $result['error']['status']);
    $this->assertSame(1000, $result['error']['details']['limit']);
  }

  /**
   * Claim locks are released when a later claim lock cannot be acquired.
   */
  public function testAcquireClaimLocksReleasesAlreadyAcquiredLocksOnFailure(): void {
    $first = $this->claimableAttachment(size: 100, id: 123);
    $second = $this->claimableAttachment(size: 100, id: 124);

    $this->lock->expects($this->exactly(2))
      ->method('acquire')
      ->willReturnCallback(
        static fn(string $name, float $timeout): bool => str_ends_with($name, '.123') && $timeout === 30.0,
      );
    $this->lock->expects($this->once())
      ->method('release')
      ->with('beantragt_application.attachment_claim.123');

    $this->assertNull($this->service->acquireClaimLocks([$first, $second]));
  }

  /**
   * Builds a valid submitted attachment claim.
   *
   * @return array<string, string>
   *   Claim payload.
   */
  private function claimPayload(): array {
    return [
      'fieldId' => 'evidence',
      'attachmentId' => '1fded50b-9249-4af7-88a5-4ea2245a10bb',
      'claimToken' => 'valid-claim-token-0123456789abcdef',
    ];
  }

  /**
   * Builds the submission context expected by the attachment service.
   *
   * @return array<string, mixed>
   *   Submission context.
   */
  private function context(): array {
    return [
      'fields' => [
        [
          'id' => 'evidence',
          'type' => 'file',
          'validation' => [
            'maxFiles' => 2,
            'maxSizeBytes' => 2048,
          ],
        ],
      ],
      'jurisdiction' => $this->mockGroup(),
    ];
  }

  /**
   * Builds a public form schema mock.
   */
  private function form(): FormSchemaInterface {
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn(12);
    return $form;
  }

  /**
   * Builds a claimable attachment mock.
   */
  private function claimableAttachment(int $size, int $id = 123, string $analysis_metadata = ''): ApplicationAttachmentInterface {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn($id);
    $attachment->method('getOwnerId')->willReturn(42);
    $attachment->method('hasField')->willReturnCallback(
      static fn(string $field): bool => $field === 'field_scan_status',
    );
    $attachment->method('get')->willReturnCallback(fn(string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->entityReference(12),
      'field_field_id' => $this->fieldValue('evidence'),
      'field_upload_status' => $this->fieldValue('uploaded'),
      'field_application' => $this->emptyField(),
      'field_file' => $this->entityReference(321),
      'field_claim_expires' => $this->fieldValue(1781431300),
      'field_claim_token_hash' => $this->fieldValue(hash('sha256', 'valid-claim-token-0123456789abcdef')),
      'field_size' => $this->fieldValue($size),
      'field_original_filename' => $this->fieldValue('evidence.pdf'),
      'field_mime_type' => $this->fieldValue('application/pdf'),
      'field_analysis_status' => $this->fieldValue('not_requested'),
      'field_scan_status' => $this->fieldValue('clean'),
      'field_analysis_metadata' => $analysis_metadata === '' ? $this->emptyField() : $this->fieldValue($analysis_metadata),
      default => $this->emptyField(),
    });
    return $attachment;
  }

  /**
   * Builds a group mock.
   */
  private function mockGroup(): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn(3);
    return $group;
  }

  /**
   * Builds a field with a scalar value.
   */
  private function fieldValue(string|int $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn(string $property): bool => $property === 'value',
    );
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an entity reference field list.
   */
  private function entityReference(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn(string $property): bool => $property === 'target_id',
    );
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'target_id' ? $target_id : NULL,
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
   * Builds config factory mock with attachment quota config.
   */
  private function configFactory(int $attachment_max_total_bytes = 52428800): ConfigFactoryInterface {
    $application_config = $this->createMock(ImmutableConfig::class);
    $application_config->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'attachment_max_total_bytes_per_application' => $attachment_max_total_bytes,
        default => NULL,
      }
    );

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($application_config);
    return $factory;
  }

}
