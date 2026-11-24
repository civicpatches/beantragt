<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

/**
 * Validates staff case layouts, including sections absent in the open build.
 */
final class CaseLayout implements JurisdictionConfigSectionValidatorInterface {

  /**
   * Stable section IDs shared with the casework frontend.
   */
  public const SECTIONS = [
    'attachments', 'data', 'messages', 'deadline', 'assignment',
    'status_history', 'crm', 'docgen', 'delivery', 'transfers',
    'supplements', 'responses',
  ];

  /**
   * Accepts only a complete, versioned partition of the known section IDs.
   */
  public static function valid(mixed $layout): bool {
    if (!is_array($layout) || count($layout) !== 3
      || ($layout['version'] ?? NULL) !== 1
      || !isset($layout['left'], $layout['right'])
      || !is_array($layout['left']) || !is_array($layout['right'])
      || !array_is_list($layout['left']) || !array_is_list($layout['right'])) {
      return FALSE;
    }
    $ids = array_merge($layout['left'], $layout['right']);
    if (count($ids) !== count(self::SECTIONS)) {
      return FALSE;
    }
    foreach ($ids as $id) {
      if (!is_string($id) || !in_array($id, self::SECTIONS, TRUE)) {
        return FALSE;
      }
    }
    return count(array_unique($ids)) === count(self::SECTIONS);
  }

  /**
   * {@inheritdoc}
   */
  public function sectionName(): string {
    return 'staffForms';
  }

  /**
   * {@inheritdoc}
   */
  public function validateSection(array $section): array {
    if (array_diff(array_keys($section), ['caseLayouts']) !== []
      || !is_array($section['caseLayouts'] ?? NULL)) {
      return ['Invalid staff form settings.'];
    }
    foreach ($section['caseLayouts'] as $workspace_id => $layout) {
      if (!ctype_digit((string) $workspace_id) || (int) $workspace_id <= 0 || !self::valid($layout)) {
        return ['Invalid workspace case layout.'];
      }
    }
    return [];
  }

}
