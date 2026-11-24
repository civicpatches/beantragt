<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Service\FitConnectMetadataDocumentBuilder;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;

/**
 * Tests FITKO metadata against the exact bytes selected for encryption.
 *
 * @group beantragt_delivery
 */
final class FitConnectMetadataDocumentBuilderTest extends UnitTestCase {

  /**
   * Hashes use SHA-512 over plaintext, preserving filenames and MIME types.
   */
  public function testExactByteHashes(): void {
    $settings = new FitConnectSettings('TEST', '', '', '', '', '', '', '', '', '', '', 'urn:test:schema', 'application/json', '2.1.0', FALSE);
    $data = "{\"name\": \"Müller\"}\n";
    $attachment = [
      'attachmentId' => '845f7d74-f6d1-48c1-bb90-f0a7712ddf53',
      'bytes' => "%PDF-1.7\n\0\xff",
      'filename' => 'nachweis.pdf',
      'mimeType' => 'application/pdf',
    ];
    $metadata = (new FitConnectMetadataDocumentBuilder())->build($data, [$attachment], 'UNIT-123', '2026-09-18', $settings);
    $this->assertSame('https://schema.fitko.de/fit-connect/metadata/2.1.0/metadata.schema.json', $metadata['$schema']);
    $this->assertSame(['type' => 'sha512', 'content' => hash('sha512', $data)], $metadata['contentStructure']['data']['hash']);
    $this->assertNotSame(hash('sha512', trim($data)), $metadata['contentStructure']['data']['hash']['content']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{128}$/', $metadata['contentStructure']['data']['hash']['content']);
    $this->assertSame(['schemaUri' => 'urn:test:schema', 'mimeType' => 'application/json'], $metadata['contentStructure']['data']['submissionSchema']);
    $this->assertSame([
      'attachmentId' => $attachment['attachmentId'],
      'hash' => ['type' => 'sha512', 'content' => hash('sha512', $attachment['bytes'])],
      'purpose' => 'attachment',
      'mimeType' => 'application/pdf',
      'filename' => 'nachweis.pdf',
    ], $metadata['contentStructure']['attachments'][0]);
    $this->assertSame(['senderReference' => 'UNIT-123', 'applicationDate' => '2026-09-18'], $metadata['additionalReferenceInfo']);
    $this->assertArrayNotHasKey('bytes', $metadata['contentStructure']['attachments'][0]);
    $empty = (new FitConnectMetadataDocumentBuilder())->build($data, [], 'UNIT-123', '2026-09-18', $settings);
    $this->assertSame([], $empty['contentStructure']['attachments']);
  }

}
