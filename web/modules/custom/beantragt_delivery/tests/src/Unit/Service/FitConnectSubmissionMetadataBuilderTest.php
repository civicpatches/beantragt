<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Service\FitConnectSubmissionMetadataBuilder;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;

/**
 * Tests FIT-Connect submission metadata manifest construction.
 *
 * @group beantragt_delivery
 */
final class FitConnectSubmissionMetadataBuilderTest extends UnitTestCase {

  /**
   * Complete settings build deterministic metadata without raw submitted data.
   */
  public function testBuildsMetadataManifest(): void {
    $builder = new FitConnectSubmissionMetadataBuilder();

    $metadata = $builder->build($this->envelope(), $this->settings())->toArray();

    $this->assertSame('2.1.0', $metadata['metadataSchemaVersion']);
    $this->assertSame('MS-2026-00010', $metadata['submission']['referenceNumber']);
    $this->assertSame('urn:de:fim:leika:leistung:512431', $metadata['service']['identifier']);
    $this->assertSame('DE000000000000', $metadata['service']['region']);
    $this->assertSame('destination-123', $metadata['service']['destinationId']);
    $this->assertSame('urn:beantragt:dev:musterantrag-bescheid:v1', $metadata['contentStructure']['data']['submissionSchema']['schemaUri']);
    $this->assertSame('application/json', $metadata['contentStructure']['data']['submissionSchema']['mimeType']);
    $this->assertSame(2, $metadata['contentSummary']['fieldCount']);
    $this->assertSame(1, $metadata['contentSummary']['attachmentCount']);
    $this->assertArrayNotHasKey('fieldData', $metadata);
    $this->assertArrayNotHasKey('clientSecret', $metadata);
  }

  /**
   * Safe metadata exposes only diagnostics needed for logs.
   */
  public function testSafeMetadata(): void {
    $manifest = (new FitConnectSubmissionMetadataBuilder())->build($this->envelope(), $this->settings());

    $safe = $manifest->toSafeMetadata();

    $this->assertTrue($safe['submissionMetadataReady']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $safe['submissionMetadataHash']);
    $this->assertSame('2.1.0', $safe['metadataSchemaVersion']);
    $this->assertSame('urn:de:fim:leika:leistung:512431', $safe['serviceUrn']);
    $this->assertSame('DE000000000000', $safe['region']);
    $this->assertSame('urn:beantragt:dev:musterantrag-bescheid:v1', $safe['submissionSchema']['schemaUri']);
    $this->assertArrayNotHasKey('dataHash', $safe);
    $this->assertArrayNotHasKey('clientSecret', $safe);
  }

  /**
   * Missing settings fail before any package handoff.
   */
  public function testMissingSettingsFailClosed(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('client_id');

    (new FitConnectSubmissionMetadataBuilder())->build(
      $this->envelope(),
      new FitConnectSettings(
        'TEST',
        '',
        'FIT_CONNECT_CLIENT_SECRET',
        '',
        FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
        FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
        FitConnectSettings::DEFAULT_TEST_DESTINATION_API_BASE,
        FitConnectSettings::DEFAULT_TEST_ROUTING_API_BASE,
        '',
        '',
        '',
        '',
        'application/json',
        '2.1.0',
        FALSE,
      ),
    );
  }

  /**
   * Builds a delivery envelope.
   */
  private function envelope(): DeliveryEnvelope {
    return new DeliveryEnvelope(
      10,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00010',
      'd5974725-034f-4c53-bc44-058de7e31951',
      'Hundesteuer',
      1,
      hash('sha256', '{"name":"Ada","email":"ada@example.org"}'),
      2,
      1,
    );
  }

  /**
   * Builds complete FIT-Connect settings.
   */
  private function settings(): FitConnectSettings {
    return new FitConnectSettings(
      'TEST',
      'sender-client',
      'FIT_CONNECT_CLIENT_SECRET',
      'super-secret',
      FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      FitConnectSettings::DEFAULT_TEST_DESTINATION_API_BASE,
      FitConnectSettings::DEFAULT_TEST_ROUTING_API_BASE,
      'destination-123',
      'urn:de:fim:leika:leistung:512431',
      '000000000000',
      'urn:beantragt:dev:musterantrag-bescheid:v1',
      'application/json',
      '2.1.0',
      FALSE,
    );
  }

}
