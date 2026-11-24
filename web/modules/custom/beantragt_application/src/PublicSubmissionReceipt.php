<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Projects the public receipt for an accepted application.
 */
final class PublicSubmissionReceipt {

  /**
   * Builds a receipt from the saved submission title, time and scope.
   *
   * @return array{referenceNumber: string, formTitle: string, submittedAt: string, jurisdictionName: ?string, organisationName: ?string}|null
   *   Public receipt or NULL if the stored context cannot be resolved.
   */
  public static function build(ApplicationInterface $application): ?array {
    $reference = (string) $application->getReferenceNumber();
    $created = $application->getCreatedTime();
    $title = $application->hasField('field_receipt_form_title')
      ? trim((string) $application->get('field_receipt_form_title')->value)
      : '';
    // Older submissions lack the original public title. A label-only edit
    // could have updated the form revision in place since they were submitted.
    if (trim($reference) === '' || $created <= 0 || $title === '') {
      return NULL;
    }

    $jurisdiction = self::group($application, 'field_jurisdiction', 'jur');
    $organisation = self::group($application, 'field_organisation', 'org');
    if ($application->hasField('field_jurisdiction') && !$application->get('field_jurisdiction')->isEmpty()
      && !self::organisationBelongsToJurisdiction($organisation, $jurisdiction)) {
      $organisation = NULL;
    }
    $jurisdiction_name = $jurisdiction?->isPublished() ? trim((string) $jurisdiction->getUntranslated()->label()) : '';
    $organisation_name = $organisation?->isPublished() ? trim((string) $organisation->getUntranslated()->label()) : '';
    if ($jurisdiction_name === '' && $organisation_name === '') {
      return NULL;
    }

    return [
      'referenceNumber' => $reference,
      'formTitle' => $title,
      'submittedAt' => gmdate('Y-m-d\TH:i:s\Z', $created),
      'jurisdictionName' => $jurisdiction_name !== '' ? $jurisdiction_name : NULL,
      'organisationName' => $organisation_name !== '' ? $organisation_name : NULL,
    ];
  }

  /**
   * Returns the application's stored group for the expected scope.
   */
  public static function group(ApplicationInterface $application, string $field, string $bundle): ?GroupInterface {
    $group = $application->hasField($field) ? $application->get($field)->entity : NULL;
    return $group instanceof GroupInterface && $group->bundle() === $bundle ? $group : NULL;
  }

  /**
   * Checks the parent before returning organisation context.
   */
  public static function organisationBelongsToJurisdiction(?GroupInterface $organisation, ?GroupInterface $jurisdiction): bool {
    return $jurisdiction !== NULL
      && $organisation !== NULL
      && $organisation->hasField('field_jurisdiction')
      && (int) $organisation->get('field_jurisdiction')->target_id === (int) $jurisdiction->id();
  }

}
