<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigSectionValidatorInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Jurisdiction section "nachreichung": the cover sheet switch.
 *
 * Closed keys: coverSheet (bool, default off). The holiday region it needs
 * lives in the docgen section; validators see only their own section, so
 * the region is checked when a sheet is drafted or printed and in the
 * status report.
 */
class NachreichungSectionValidator implements JurisdictionConfigSectionValidatorInterface {

  public const SECTION = 'nachreichung';

  /**
   * Constructs the validator.
   */
  public function __construct(private readonly ?JurisdictionConfigServiceInterface $config) {}

  /**
   * {@inheritdoc}
   */
  public function sectionName(): string {
    return self::SECTION;
  }

  /**
   * {@inheritdoc}
   */
  public function validateSection(array $section): array {
    $errors = [];
    if (array_diff(array_keys($section), ['coverSheet']) !== []) {
      $errors[] = 'Unknown nachreichung setting.';
    }
    if (array_key_exists('coverSheet', $section) && !is_bool($section['coverSheet'])) {
      $errors[] = 'nachreichung.coverSheet must be true or false.';
    }
    return $errors;
  }

  /**
   * TRUE when the jurisdiction of the application prints cover sheets.
   */
  public function coverSheet(ApplicationInterface $application): bool {
    $jurisdiction = $application->get('field_jurisdiction')->entity;
    return $jurisdiction instanceof GroupInterface && $this->coverSheetFor($jurisdiction);
  }

  /**
   * TRUE when the jurisdiction prints cover sheets.
   */
  public function coverSheetFor(GroupInterface $jurisdiction): bool {
    return ($this->config?->getSection($jurisdiction, self::SECTION)['coverSheet'] ?? FALSE) === TRUE;
  }

}
