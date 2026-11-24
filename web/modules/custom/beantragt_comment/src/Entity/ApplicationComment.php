<?php

declare(strict_types=1);

namespace Drupal\beantragt_comment\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Application Comment entity.
 *
 * Stores comments (Rueckfragen and replies) on applications.
 * Comments are immutable once created (no revisions, no editing).
 *
 * @ContentEntityType(
 *   id = "application_comment",
 *   label = @Translation("Application Comment"),
 *   label_collection = @Translation("Application Comments"),
 *   label_singular = @Translation("application comment"),
 *   label_plural = @Translation("application comments"),
 *   label_count = @PluralTranslation(
 *     singular = "@count application comment",
 *     plural = "@count application comments",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\beantragt_comment\ApplicationCommentAccessControlHandler",
 *   },
 *   base_table = "application_comment",
 *   admin_permission = "administer application_comment entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *     "published" = "status",
 *   },
 * )
 */
class ApplicationComment extends ContentEntityBase implements ApplicationCommentInterface {

  use EntityOwnerTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Strip HTML tags from body before save.
    $body = $this->get('field_body')->value;
    if ($body !== NULL) {
      $this->set('field_body', strip_tags($body));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getBody(): ?string {
    return $this->get('field_body')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setBody(string $body): static {
    $this->set('field_body', $body);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthorRole(): string {
    return $this->get('field_author_role')->value ?? 'citizen';
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthorRole(string $role): static {
    $this->set('field_author_role', $role);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibility(): string {
    return $this->get('field_visibility')->value ?? 'public';
  }

  /**
   * {@inheritdoc}
   */
  public function setVisibility(string $visibility): static {
    $this->set('field_visibility', $visibility);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isInternal(): bool {
    return $this->getVisibility() === 'internal';
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicationId(): ?int {
    $target_id = $this->get('field_application')->target_id;
    return $target_id !== NULL ? (int) $target_id : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['field_application'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Application'))
      ->setDescription(t('The application this comment belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'application')
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_body'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Body'))
      ->setDescription(t('The comment text.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_author_role'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Author Role'))
      ->setDescription(t('Whether the comment was written by a citizen or staff member.'))
      ->setRequired(TRUE)
      ->setDefaultValue('citizen')
      ->setSetting('allowed_values', [
        'citizen' => 'Citizen',
        'staff' => 'Staff',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_visibility'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Visibility'))
      ->setDescription(t('Whether the comment is visible to the citizen or internal staff only.'))
      ->setRequired(TRUE)
      ->setDefaultValue('public')
      ->setSetting('allowed_values', [
        'public' => 'Public citizen communication',
        'internal' => 'Internal staff note',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_attachments'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Attachments'))
      ->setDescription(t('File attachments (PDF, JPG, PNG). Max 10MB each, max 5 files.'))
      ->setCardinality(5)
      ->setSetting('file_directory', 'application_comments')
      ->setSetting('file_extensions', 'pdf jpg jpeg png')
      ->setSetting('max_filesize', '10 MB')
      ->setSetting('uri_scheme', 'private')
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(t('Author'))
      ->setDescription(t('The user who posted the comment.'));

    $fields['status']
      ->setDefaultValue(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the comment was posted.'));

    return $fields;
  }

}
