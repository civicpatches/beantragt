<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\beantragt_delivery\Value\FitConnectSubmissionMetadata;

/**
 * Builds the pre-encryption FIT-Connect submission metadata manifest.
 */
final class FitConnectSubmissionMetadataBuilder {

  /**
   * Builds metadata for one submission attempt.
   */
  public function build(DeliveryEnvelope $envelope, FitConnectSettings $settings): FitConnectSubmissionMetadata {
    $missing = $settings->missingForSubmission();
    if ($missing !== []) {
      throw new \InvalidArgumentException('FIT-Connect settings are incomplete: ' . implode(', ', $missing));
    }

    return new FitConnectSubmissionMetadata([
      'metadataSchemaVersion' => $settings->metadataSchemaVersion,
      'submission' => [
        'applicationUuid' => $envelope->applicationUuid,
        'referenceNumber' => $envelope->referenceNumber,
      ],
      'service' => [
        'identifier' => $settings->serviceUrn,
        'region' => $this->region($settings->ars),
        'destinationId' => $settings->destinationId,
      ],
      'contentStructure' => [
        'data' => [
          'submissionSchema' => [
            'schemaUri' => $settings->schemaUri,
            'mimeType' => $settings->schemaMimeType,
          ],
        ],
      ],
      'contentSummary' => [
        'dataHash' => $envelope->dataHash,
        'fieldCount' => $envelope->fieldCount,
        'attachmentCount' => $envelope->attachmentCount,
      ],
    ]);
  }

  /**
   * Normalizes the ARS into the FIT-Connect region shape.
   */
  private function region(string $ars): string {
    $ars = strtoupper(trim($ars));
    if ($ars === '') {
      return '';
    }
    return str_starts_with($ars, 'DE') ? $ars : 'DE' . $ars;
  }

}
