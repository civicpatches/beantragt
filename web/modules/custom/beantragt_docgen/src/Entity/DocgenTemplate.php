<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Entity;

use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * One version of a Word template for decision notices.
 *
 * Versions of one template share the family UUID. A released version is
 * immutable; a change creates the next version as a draft. The entity is
 * internal: it is not exposed through JSON:API and is only served by the
 * scoped docgen staff API.
 *
 * @ContentEntityType(
 *   id = "docgen_template",
 *   label = @Translation("Decision template"),
 *   label_collection = @Translation("Decision templates"),
 *   label_singular = @Translation("decision template"),
 *   label_plural = @Translation("decision templates"),
 *   label_count = @PluralTranslation(
 *     singular = "@count decision template",
 *     plural = "@count decision templates",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\beantragt_docgen\DocgenTemplateAccessControlHandler",
 *   },
 *   base_table = "beantragt_docgen_template",
 *   internal = TRUE,
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *   },
 * )
 */
class DocgenTemplate extends ContentEntityBase implements DocgenTemplateInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public function getFamily(): string {
    return (string) $this->get('family')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): int {
    return (int) $this->get('version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPurpose(): string {
    return (string) $this->get('purpose')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isReleased(): bool {
    return $this->getStatus() === self::STATUS_RELEASED;
  }

  /**
   * {@inheritdoc}
   */
  public function getScopeGroup(): ?GroupInterface {
    foreach (['field_organisation', 'field_jurisdiction'] as $field_name) {
      $group = $this->get($field_name)->entity ?? NULL;
      if ($group instanceof GroupInterface) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFile(): ?FileInterface {
    $file = $this->get('file')->entity ?? NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSha256(): string {
    return (string) $this->get('sha256')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getInspection(): array {
    $decoded = json_decode((string) ($this->get('inspection')->value ?? ''), TRUE);
    $decoded = is_array($decoded) ? $decoded : [];
    return [
      'placeholders' => array_values(array_filter($decoded['placeholders'] ?? [], 'is_string')),
      'loops' => array_values(array_filter($decoded['loops'] ?? [], 'is_string')),
      'errors' => is_array($decoded['errors'] ?? NULL) ? array_values($decoded['errors']) : [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!in_array($this->getPurpose(), self::PURPOSES, TRUE)) {
      throw new \InvalidArgumentException('Unknown decision template purpose.');
    }
    if (!in_array($this->getStatus(), [self::STATUS_DRAFT, self::STATUS_RELEASED], TRUE)) {
      throw new \InvalidArgumentException('Unknown decision template status.');
    }
    if ($this->get('field_jurisdiction')->isEmpty()) {
      throw new \InvalidArgumentException('A decision template needs a jurisdiction.');
    }
    $original = $this->isNew() ? NULL : ($this->getOriginal() ?? $storage->loadUnchanged($this->id()));
    if ($original instanceof DocgenTemplateInterface && $original->isReleased()) {
      // A released version is the legal basis of every notice drafted from
      // it. Nothing may change; a change is the next version.
      foreach ($this->getFields(FALSE) as $name => $items) {
        if ($name === 'changed') {
          continue;
        }
        if (!$items->equals($original->get($name))) {
          throw new \LogicException('Released decision template versions are immutable.');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    foreach ($entities as $entity) {
      // Only TemplateCatalog::purgeScope() removes released versions, when
      // their organisation or jurisdiction is deleted and no document uses
      // them.
      if ($entity instanceof DocgenTemplateInterface && $entity->isReleased()
        && !TemplateCatalog::isPurging((int) $entity->id())) {
        throw new \LogicException('Released decision template versions cannot be deleted.');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['family'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Template family'))
      ->setDescription(t('UUID shared by all versions of one template.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 36);

    $fields['version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(1);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['purpose'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Purpose'))
      ->setDescription(t('approval, rejection, request_info, acknowledgement or other.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('draft or released.'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_DRAFT)
      ->setSetting('max_length', 16);

    $group_reference = static fn (string $label, string $bundle): BaseFieldDefinition => BaseFieldDefinition::create('entity_reference')
      ->setLabel($label)
      ->setSetting('target_type', 'group')
      ->setSetting('handler', 'default:group')
      ->setSetting('handler_settings', ['target_bundles' => [$bundle => $bundle], 'auto_create' => FALSE])
      ->setCardinality(1);
    // Same field names as the beantragt_group scope fields, so scope helpers
    // that read field_organisation before field_jurisdiction work unchanged.
    $fields['field_jurisdiction'] = $group_reference((string) t('Jurisdiction'), 'jur')->setRequired(TRUE);
    $office_label = \Drupal::languageManager()->getCurrentLanguage()->getId() === 'de'
      ? t('Dienststelle') : t('Organisation');
    $fields['field_organisation'] = $group_reference((string) $office_label, 'org');

    $fields['file'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Template file'))
      ->setRequired(TRUE)
      ->setSetting('file_directory', 'docgen/templates')
      ->setSetting('file_extensions', 'docx')
      ->setSetting('uri_scheme', 'private');

    $fields['filename'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Original filename'))
      ->setSetting('max_length', 255);

    $fields['sha256'] = BaseFieldDefinition::create('string')
      ->setLabel(t('SHA-256 of the template file'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['size'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Size in bytes'))
      ->setSetting('unsigned', TRUE);

    $fields['inspection'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Inspection result'))
      ->setDescription(t('Placeholders, loops and template errors reported by docgen /inspect (JSON).'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Created by'))
      ->setSetting('target_type', 'user');

    $fields['released_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Released by'))
      ->setSetting('target_type', 'user');

    $fields['released'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Released'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
