<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;

/**
 * Adds widgets for configurable fields missing from workspace forms.
 */
final class AdminFormDisplayCompleter {

  /**
   * Supported group bundles.
   */
  private const SUPPORTED_BUNDLES = ['jur', 'org'];

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly FieldTypePluginManagerInterface $fieldTypeManager,
  ) {}

  /**
   * Adds default widgets for all configurable fields without a component.
   *
   * Computed and read-only fields remain managed by Drupal and are not made
   * editable. Existing widget choices and ordering are preserved.
   *
   * @return int
   *   The number of components added to the display.
   */
  public function complete(
    EntityFormDisplayInterface $form_display,
    string $entity_type_id,
    string $bundle,
  ): int {
    if (
      $entity_type_id !== 'group'
      || !in_array($bundle, self::SUPPORTED_BUNDLES, TRUE)
      || $form_display->getMode() !== 'default'
    ) {
      return 0;
    }

    $added = 0;
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    foreach ($field_definitions as $field_name => $field_definition) {
      if (
        $field_definition->isComputed()
        || $field_definition->isReadOnly()
        || !$field_definition->isDisplayConfigurable('form')
        || $form_display->getComponent($field_name) !== NULL
      ) {
        continue;
      }

      $field_type = $this->fieldTypeManager->getDefinition($field_definition->getType(), FALSE);
      $widget = $field_type['default_widget'] ?? NULL;
      if (!is_string($widget) || $widget === '') {
        continue;
      }

      $form_display->setComponent($field_name, ['type' => $widget]);
      $added++;
    }

    return $added;
  }

}
