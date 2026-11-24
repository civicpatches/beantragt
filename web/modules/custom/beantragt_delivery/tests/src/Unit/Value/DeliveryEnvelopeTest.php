<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Value;

use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\Tests\UnitTestCase;

/**
 * Tests delivery envelope guards.
 *
 * @group beantragt_delivery
 */
final class DeliveryEnvelopeTest extends UnitTestCase {

  /**
   * Log metadata excludes raw submitted data.
   */
  public function testLogMetadataIsNonSensitive(): void {
    $envelope = new DeliveryEnvelope(
      42,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00042',
      'd5974725-034f-4c53-bc44-058de7e31951',
      'Hundesteuer',
      3,
      hash('sha256', '{"name":"Ada"}'),
      7,
      2,
      ['jurisdiction' => ['slug' => 'musterstadt']],
      [
        [
          'id' => 'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
          'fieldId' => 'nachweis',
          'filename' => 'nachweis.pdf',
          'mimeType' => 'application/pdf',
          'size' => 415,
        ],
      ],
    );

    $metadata = $envelope->toLogMetadata();

    $this->assertSame('MS-2026-00042', $metadata['referenceNumber']);
    $this->assertSame(7, $metadata['fieldCount']);
    $this->assertSame(2, $metadata['attachmentCount']);
    $this->assertSame('e85b56a6-ec90-4a4f-8173-786e7d29b54f', $metadata['attachmentManifest'][0]['id']);
    $this->assertArrayNotHasKey('filename', $metadata['attachmentManifest'][0]);
    $this->assertArrayNotHasKey('fieldId', $metadata['attachmentManifest'][0]);
    $this->assertArrayNotHasKey('mimeType', $metadata['attachmentManifest'][0]);
    $this->assertArrayNotHasKey('data', $metadata);
    $this->assertArrayNotHasKey('fieldData', $metadata);
  }

  /**
   * Invalid data hashes are rejected.
   */
  public function testInvalidDataHashFails(): void {
    $this->expectException(\InvalidArgumentException::class);

    new DeliveryEnvelope(
      42,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00042',
      '',
      '',
      1,
      'not-a-sha256',
      0,
      0,
    );
  }

  /**
   * Delivery envelopes can be rebuilt from queue-time log snapshots.
   */
  public function testFromLogRowUsesSnapshotData(): void {
    $data_hash = hash('sha256', '{"field":"queued-value"}');
    $envelope = DeliveryEnvelope::fromLogRow([
      'applicationId' => 42,
      'applicationUuid' => '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'dataHash' => $data_hash,
      'metadata' => [
        'referenceNumber' => 'MS-2026-00042',
        'schema' => [
          'id' => 'd5974725-034f-4c53-bc44-058de7e31951',
          'label' => 'Hundesteuer',
          'version' => 3,
        ],
        'fieldCount' => 7,
        'attachmentCount' => 2,
        'attachmentManifest' => [
          [
            'id' => 'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
            'fieldId' => 'nachweis',
            'filename' => 'nachweis.pdf',
            'mimeType' => 'application/pdf',
            'size' => 415,
          ],
        ],
        'workspace' => ['organisation' => ['slug' => 'citizen-services']],
      ],
    ]);

    $this->assertInstanceOf(DeliveryEnvelope::class, $envelope);
    $this->assertSame($data_hash, $envelope->dataHash);
    $this->assertSame('MS-2026-00042', $envelope->referenceNumber);
    $this->assertSame('Hundesteuer', $envelope->schemaLabel);
    $this->assertSame(7, $envelope->fieldCount);
    $this->assertSame(2, $envelope->attachmentCount);
    $this->assertSame('e85b56a6-ec90-4a4f-8173-786e7d29b54f', $envelope->attachmentManifest[0]['id']);
    $this->assertArrayNotHasKey('filename', $envelope->attachmentManifest[0]);
    $this->assertSame(['organisation' => ['slug' => 'citizen-services']], $envelope->workspace);
  }

  /**
   * Invalid log snapshots are rejected instead of being dispatched.
   */
  public function testFromLogRowRejectsInvalidSnapshot(): void {
    $this->assertNull(DeliveryEnvelope::fromLogRow([
      'applicationId' => 42,
      'applicationUuid' => '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'dataHash' => 'not-a-hash',
      'metadata' => [],
    ]));
  }

}
