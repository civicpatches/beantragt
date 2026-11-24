<?php

declare(strict_types=1);

namespace Drupal\beantragt_roles;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Creates the first and last name fields on user accounts.
 *
 * Idempotent: used by hook_install() on fresh installations and by an update
 * hook on existing ones. The project ships its configuration from module
 * code, the same way beantragt_group adds field_scope_jurisdiction to users.
 */
final class StaffNameFields {

  /**
   * Field definitions keyed by field name.
   */
  private const FIELDS = [
    StaffName::FIRST_NAME_FIELD => [
      'label' => 'Vorname',
      'description' => 'Wird Mitarbeitenden in Zuweisung, Verlauf und Freigaben angezeigt. Antragstellende sehen den Namen nie.',
      'weight' => -9,
    ],
    StaffName::LAST_NAME_FIELD => [
      'label' => 'Nachname',
      'description' => 'Wird Mitarbeitenden in Zuweisung, Verlauf und Freigaben angezeigt. Antragstellende sehen den Namen nie.',
      'weight' => -8,
    ],
  ];

  /**
   * Ensures field storages, fields and form display components.
   *
   * @return string[]
   *   Messages describing what was created.
   */
  public static function ensure(EntityTypeManagerInterface $entity_type_manager): array {
    $messages = [];
    $storage_storage = $entity_type_manager->getStorage('field_storage_config');
    $field_storage = $entity_type_manager->getStorage('field_config');

    foreach (self::FIELDS as $field_name => $definition) {
      if (!$storage_storage->load('user.' . $field_name)) {
        $storage_storage->create([
          'field_name' => $field_name,
          'entity_type' => 'user',
          'type' => 'string',
          'settings' => [
            'max_length' => StaffName::MAX_LENGTH,
            'is_ascii' => FALSE,
            'case_sensitive' => FALSE,
          ],
          'cardinality' => 1,
          'translatable' => FALSE,
        ])->save();
        $messages[] = "Created $field_name field storage.";
      }
      if (!$field_storage->load('user.user.' . $field_name)) {
        $field_storage->create([
          'field_name' => $field_name,
          'entity_type' => 'user',
          'bundle' => 'user',
          'label' => $definition['label'],
          'description' => $definition['description'],
          'required' => FALSE,
          'translatable' => FALSE,
        ])->save();
        $messages[] = "Added $field_name to users.";
      }
    }

    $display_storage = $entity_type_manager->getStorage('entity_form_display');
    $display = $display_storage->load('user.user.default');
    if (!$display instanceof EntityFormDisplayInterface) {
      $display = $display_storage->create([
        'targetEntityType' => 'user',
        'bundle' => 'user',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    $changed = $display->isNew();
    foreach (self::FIELDS as $field_name => $definition) {
      if ($display->getComponent($field_name) === NULL) {
        $display->setComponent($field_name, [
          'type' => 'string_textfield',
          'weight' => $definition['weight'],
          'region' => 'content',
          'settings' => [
            'size' => StaffName::MAX_LENGTH,
            'placeholder' => '',
          ],
          'third_party_settings' => [],
        ]);
        $changed = TRUE;
      }
    }
    if ($changed) {
      $display->save();
      $messages[] = 'Added first and last name to the account form.';
    }

    return $messages;
  }

}
