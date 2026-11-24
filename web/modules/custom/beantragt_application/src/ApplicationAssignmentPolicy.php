<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Authoritative jurisdiction policy shared by manual and automatic assignment.
 */
final class ApplicationAssignmentPolicy {

  /**
   * Existing tenants retain personal assignment unless explicitly disabled.
   */
  public static function personalAssignmentEnabled(ApplicationInterface $application): bool {
    $jurisdiction = NULL;
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field) {
      if (!$application->hasField($field) || $application->get($field)->isEmpty()) {
        continue;
      }
      $group = $application->get($field)->entity;
      if (!$group instanceof GroupInterface) {
        continue;
      }
      if ($group->bundle() === 'org' && $group->hasField('field_jurisdiction')) {
        $group = $group->get('field_jurisdiction')->entity;
      }
      if ($group instanceof GroupInterface && $group->bundle() === 'jur') {
        $jurisdiction = $group;
        break;
      }
    }
    if ($jurisdiction && \Drupal::hasService('beantragt_group.jurisdiction_hierarchy_resolver')) {
      $root_id = \Drupal::service('beantragt_group.jurisdiction_hierarchy_resolver')->getRootJurisdictionId((int) $jurisdiction->id());
      if ($root_id === NULL) {
        return FALSE;
      }
      $root = \Drupal::entityTypeManager()->getStorage('group')->load($root_id);
      $jurisdiction = $root instanceof GroupInterface ? $root : NULL;
    }
    if (!$jurisdiction || !$jurisdiction->hasField('field_jur_config') || $jurisdiction->get('field_jur_config')->isEmpty()) {
      return TRUE;
    }
    $config = json_decode((string) $jurisdiction->get('field_jur_config')->value, TRUE);
    if (!is_array($config)) {
      return TRUE;
    }
    $section = $config['delegation'] ?? [];
    if (!is_array($section)) {
      return TRUE;
    }
    return ($section['personalAssignment'] ?? TRUE) !== FALSE;
  }

  /**
   * Disabling personal assignment preserves an already stored assignment.
   */
  public static function retainsExistingAssignment(ApplicationInterface $application): bool {
    if ($application->isNew()) {
      return FALSE;
    }
    $stored = \Drupal::entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    if (!$stored instanceof ApplicationInterface
      || $stored->get('field_assigned_to')->getValue() !== $application->get('field_assigned_to')->getValue()) {
      return FALSE;
    }
    $stored_root = self::rootId($stored);
    $current_root = self::rootId($application);
    if ($stored_root !== NULL || $current_root !== NULL) {
      return $stored_root !== NULL && $stored_root === $current_root;
    }
    // A broken legacy scope can retain its exact stored assignment. It may
    // neither change scope nor introduce an assignee through this exception.
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field) {
      if ($stored->hasField($field) !== $application->hasField($field)
        || ($stored->hasField($field) && $stored->get($field)->getValue() !== $application->get($field)->getValue())) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The exception for an existing assignee never crosses tenant roots.
   */
  private static function rootId(ApplicationInterface $application): ?int {
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field) {
      if (!$application->hasField($field) || $application->get($field)->isEmpty()) {
        continue;
      }
      $group = $application->get($field)->entity;
      if ($group instanceof GroupInterface && $group->bundle() === 'org' && $group->hasField('field_jurisdiction')) {
        $group = $group->get('field_jurisdiction')->entity;
      }
      if ($group instanceof GroupInterface && $group->bundle() === 'jur') {
        return \Drupal::service('beantragt_group.jurisdiction_hierarchy_resolver')->getRootJurisdictionId((int) $group->id());
      }
    }
    return NULL;
  }

}
