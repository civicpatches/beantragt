<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

/**
 * Validates an optional module's jurisdiction configuration section.
 */
interface JurisdictionConfigSectionValidatorInterface {

  /**
   * Returns the owned top-level section name.
   */
  public function sectionName(): string;

  /**
   * Returns value-free errors for a section, or an empty list when valid.
   */
  public function validateSection(array $section): array;

}
