<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Reads the jurisdiction switch for applicant profile reuse.
 *
 * The switch lives in the "applicant_profile" section of the jurisdiction
 * config blob that beantragt_group maintains. This reader fails closed: a
 * missing field, a damaged blob or any value other than boolean TRUE counts
 * as switched off. It reads the stored blob on every call, so switching the
 * function off stops profile sync immediately.
 */
class ApplicantProfileSwitch {

  /**
   * Jurisdiction config field on jur groups.
   */
  public const CONFIG_FIELD = 'field_jur_config';

  /**
   * Config section holding the switch.
   */
  public const SECTION = 'applicant_profile';

  /**
   * Returns whether a jurisdiction has applicant profile reuse switched on.
   */
  public function isEnabled(?GroupInterface $jurisdiction): bool {
    return ($this->section($jurisdiction)['enabled'] ?? FALSE) === TRUE;
  }

  /**
   * Returns whether a jurisdiction also offers the bank consent checkbox.
   *
   * Needs the profile switch and its own bank switch ("bankConsent"), which
   * defaults to off: bank details are stored with that consent, but no form
   * prefills them yet.
   */
  public function isBankEnabled(?GroupInterface $jurisdiction): bool {
    $section = $this->section($jurisdiction);
    return ($section['enabled'] ?? FALSE) === TRUE && ($section['bankConsent'] ?? FALSE) === TRUE;
  }

  /**
   * Returns whether the jurisdiction of an application has the switch on.
   */
  public function isEnabledForApplication(ApplicationInterface $application): bool {
    return $this->isEnabled($this->jurisdictionOf($application));
  }

  /**
   * Returns whether the jurisdiction of an application has both switches on.
   */
  public function isBankEnabledForApplication(ApplicationInterface $application): bool {
    return $this->isBankEnabled($this->jurisdictionOf($application));
  }

  /**
   * Reads the stored applicant_profile section, empty when unusable.
   *
   * @return array<string, mixed>
   *   Stored section values.
   */
  private function section(?GroupInterface $jurisdiction): array {
    if (!$jurisdiction instanceof GroupInterface || $jurisdiction->bundle() !== 'jur') {
      return [];
    }
    $source = $jurisdiction->getUntranslated();
    if (!$source->hasField(self::CONFIG_FIELD) || $source->get(self::CONFIG_FIELD)->isEmpty()) {
      return [];
    }
    $config = json_decode((string) $source->get(self::CONFIG_FIELD)->value, TRUE);
    if (!is_array($config) || !is_array($config[self::SECTION] ?? NULL)) {
      return [];
    }
    return $config[self::SECTION];
  }

  /**
   * Resolves the jurisdiction of an application, directly or via its org.
   */
  protected function jurisdictionOf(ApplicationInterface $application): ?GroupInterface {
    $jurisdiction = $this->referencedGroup($application, 'field_jurisdiction');
    if ($jurisdiction instanceof GroupInterface) {
      return $jurisdiction;
    }
    $organisation = $this->referencedGroup($application, 'field_organisation');
    return $organisation instanceof GroupInterface
      ? $this->referencedGroup($organisation, 'field_jurisdiction')
      : NULL;
  }

  /**
   * Loads a referenced group from an entity reference field.
   */
  private function referencedGroup(FieldableEntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $group = $entity->get($field_name)->entity;
    return $group instanceof GroupInterface ? $group : NULL;
  }

}
