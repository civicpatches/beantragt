<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\DeliveryEnvelopeBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests delivery envelope building.
 *
 * @group beantragt_delivery
 */
final class DeliveryEnvelopeBuilderTest extends UnitTestCase {

  /**
   * Attachment manifests keep opaque proof without leaking field or filenames.
   */
  public function testAttachmentManifestIsRedactedAndSorted(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())
      ->method('accessCheck')
      ->with(FALSE)
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('condition')
      ->with('field_application', 42)
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('sort')
      ->with('created', 'ASC')
      ->willReturnSelf();
    $query->expects($this->once())
      ->method('execute')
      ->willReturn([20, 10]);

    $first_attachment = $this->attachment(
      '89ab024f-0d2d-46af-9d77-fb3a58d42782',
      'medical-proof-ada-lovelace.pdf',
      'health_certificate',
      'application/pdf',
      415,
    );
    $second_attachment = $this->attachment(
      'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
      'salary-june.png',
      'income_proof',
      'image/png',
      266460,
    );

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects($this->once())
      ->method('loadMultiple')
      ->with([20, 10])
      ->willReturn([
        20 => $first_attachment,
        10 => $second_attachment,
      ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->with('application_attachment')
      ->willReturn(TRUE);
    $entity_type_manager->method('getStorage')
      ->with('application_attachment')
      ->willReturn($storage);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('uuid')->willReturn('6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce');
    $application->method('getData')->willReturn('{"name":"Ada","service":"parking"}');
    $application->method('getReferenceNumber')->willReturn('MS-2026-00042');
    $application->method('getSchemaVersion')->willReturn(3);
    $application->method('hasField')->willReturn(FALSE);

    $builder = new DeliveryEnvelopeBuilder($entity_type_manager);
    $envelope = $builder->build($application);

    $this->assertSame(2, $envelope->fieldCount);
    $this->assertSame(2, $envelope->attachmentCount);
    $this->assertSame([
      [
        'id' => '89ab024f-0d2d-46af-9d77-fb3a58d42782',
        'size' => 415,
      ],
      [
        'id' => 'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
        'size' => 266460,
      ],
    ], $envelope->attachmentManifest);

    foreach ($envelope->attachmentManifest as $attachment) {
      $this->assertArrayNotHasKey('filename', $attachment);
      $this->assertArrayNotHasKey('fieldId', $attachment);
      $this->assertArrayNotHasKey('mimeType', $attachment);
    }
  }

  /**
   * Builds a fieldable attachment mock.
   */
  private function attachment(
    string $uuid,
    string $filename,
    string $field_id,
    string $mime_type,
    int $size,
  ): FieldableEntityInterface {
    $fields = [
      'field_original_filename' => $filename,
      'field_field_id' => $field_id,
      'field_mime_type' => $mime_type,
      'field_size' => $size,
    ];

    $attachment = $this->createMock(FieldableEntityInterface::class);
    $attachment->method('uuid')->willReturn($uuid);
    $attachment->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => array_key_exists($field_name, $fields));
    $attachment->method('get')
      ->willReturnCallback(fn(string $field_name): FieldItemListInterface => $this->fieldItemList($fields[$field_name] ?? NULL));

    return $attachment;
  }

  /**
   * Builds a scalar field item list mock.
   */
  private function fieldItemList(mixed $value): FieldItemListInterface {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn($value === NULL || $value === '');
    $list->method('__isset')
      ->with('value')
      ->willReturn($value !== NULL);
    $list->method('__get')
      ->with('value')
      ->willReturn($value);
    return $list;
  }

}
