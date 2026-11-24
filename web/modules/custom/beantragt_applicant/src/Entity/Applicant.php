<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\encrypt\EncryptionProfileInterface;
use Drupal\field_encrypt\Entity\FieldEncryptEntityType;
use Drupal\field_encrypt\ProcessEntities;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Applicant entity.
 *
 * Stores recurring personal data for cross-form pre-filling.
 *
 * @ContentEntityType(
 *   id = "applicant",
 *   label = @Translation("Applicant"),
 *   label_collection = @Translation("Applicants"),
 *   label_singular = @Translation("applicant"),
 *   label_plural = @Translation("applicants"),
 *   label_count = @PluralTranslation(
 *     singular = "@count applicant",
 *     plural = "@count applicants",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\beantragt_applicant\ApplicantListBuilder",
 *     "form" = {
 *       "default" = "Drupal\beantragt_applicant\Form\ApplicantForm",
 *       "add" = "Drupal\beantragt_applicant\Form\ApplicantForm",
 *       "edit" = "Drupal\beantragt_applicant\Form\ApplicantForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\beantragt_applicant\ApplicantAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "applicant",
 *   admin_permission = "administer applicant entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "uid",
 *     "published" = "status",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/applicants/{applicant}",
 *     "add-form" = "/admin/content/applicants/add",
 *     "edit-form" = "/admin/content/applicants/{applicant}/edit",
 *     "delete-form" = "/admin/content/applicants/{applicant}/delete",
 *     "collection" = "/admin/content/applicants",
 *   },
 * )
 */
class Applicant extends ContentEntityBase implements ApplicantInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $this->assertFieldEncryptionRuntimeAvailable();

    // Auto-generate label from full_name or email.
    $full_name = $this->get('field_full_name')->value;
    $email = $this->get('field_email')->value;

    if (!empty($full_name)) {
      $this->set('label', $full_name);
    }
    elseif (!empty($email)) {
      $this->set('label', $email);
    }
    else {
      $id = $this->id() ?: 'new';
      $this->set('label', "Applicant #{$id}");
    }
  }

  /**
   * Fails closed when protected applicant fields cannot be encrypted on save.
   */
  public function assertFieldEncryptionRuntimeAvailable(): void {
    if (!\Drupal::moduleHandler()->hasImplementations('entity_presave', 'field_encrypt')) {
      throw new EntityStorageException('Applicant field encryption is unavailable. Rebuild Drupal caches before saving applicant data.');
    }

    if (\Drupal::config('field_encrypt.settings')->get('encryption_profile') !== 'beantragt_applicant') {
      throw new EntityStorageException('Applicant field encryption is unavailable. Restore the beantragt_applicant encryption profile before saving applicant data.');
    }

    $profile = \Drupal::entityTypeManager()
      ->getStorage('encryption_profile')
      ->load('beantragt_applicant');
    if (!$profile instanceof EncryptionProfileInterface) {
      throw new EntityStorageException('Applicant field encryption is unavailable. Restore the beantragt_applicant encryption profile before saving applicant data.');
    }

    $mapping = \Drupal::entityTypeManager()
      ->getStorage('field_encrypt_entity_type')
      ->load('applicant');
    if (!$mapping instanceof FieldEncryptEntityType) {
      throw new EntityStorageException('Applicant field encryption is unavailable. Verify the Field Encrypt mapping before saving applicant data.');
    }

    $base_fields = $mapping->getBaseFields();
    foreach (ApplicantInterface::PROTECTED_FIELDS as $field_name) {
      $properties = $base_fields[$field_name] ?? [];
      $field_definition = $this->getFieldDefinition($field_name);
      if ($field_definition === NULL) {
        throw new EntityStorageException('Applicant field encryption is unavailable. Verify the Field Encrypt mapping before saving applicant data.');
      }
      $storage_definition = $field_definition->getFieldStorageDefinition();
      if (!in_array('value', $properties, TRUE)
        || !$storage_definition->getSetting('field_encrypt.encrypt')
        || !in_array('value', $storage_definition->getSetting('field_encrypt.properties') ?? [], TRUE)) {
        throw new EntityStorageException('Applicant field encryption is unavailable. Verify the Field Encrypt mapping before saving applicant data.');
      }
    }

    if (!$this->hasField(ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME)) {
      throw new EntityStorageException('Applicant field encryption is unavailable. Rebuild Drupal caches before saving applicant data.');
    }

    $allowed = TRUE;
    $entity = $this;
    \Drupal::moduleHandler()->invokeAllWith('field_encrypt_allow_encryption', function (callable $hook) use (&$allowed, $entity): void {
      if ($allowed && $hook($entity) === FALSE) {
        $allowed = FALSE;
      }
    });
    if (!$allowed) {
      throw new EntityStorageException('Applicant field encryption is unavailable. Encryption cannot be disabled for protected applicant fields.');
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
  public function getFullName(): ?string {
    return $this->get('field_full_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail(): ?string {
    return $this->get('field_email')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSessionToken(): ?string {
    return $this->get('field_session_token')->value;
  }

  /**
   * Returns the bank and consent provenance base fields.
   *
   * Shared by baseFieldDefinitions() and beantragt_applicant_update_10004().
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   Field definitions keyed by field name.
   */
  public static function consentBaseFieldDefinitions(): array {
    $fields = [];
    $fields['field_account_holder'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Account holder'))
      ->setDescription(t('Bank account holder, stored only with bank consent. Stored encrypted at rest through Drupal Field Encrypt.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    foreach (['field_consent' => 'contact data', 'field_bank_consent' => 'bank details'] as $prefix => $subject) {
      $fields[$prefix . '_time'] = BaseFieldDefinition::create('timestamp')
        ->setLabel(t('Consent time for @subject', ['@subject' => $subject]))
        ->setDescription(t('Time of the latest consent that let an application update @subject.', ['@subject' => $subject]));
      $fields[$prefix . '_text_version'] = BaseFieldDefinition::create('string')
        ->setLabel(t('Consent text version for @subject', ['@subject' => $subject]))
        ->setSetting('max_length', 32);
      $fields[$prefix . '_source'] = BaseFieldDefinition::create('string')
        ->setLabel(t('Consent source for @subject', ['@subject' => $subject]))
        ->setDescription(t('UUID of the application that carried the latest consent.'))
        ->setSetting('max_length', 64);
    }

    return $fields;
  }

  /**
   * Returns the summary proof fields of the latest consent per kind.
   *
   * Shared by baseFieldDefinitions() and beantragt_applicant_update_10007().
   * Hash, locale and Stelle name accompany the summary stamp. The complete
   * proof for an individual value is kept in CONSENT_PROOF_FIELD.
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   Field definitions keyed by field name.
   */
  public static function consentProofBaseFieldDefinitions(): array {
    $fields = [];
    foreach (['field_consent' => 'contact data', 'field_bank_consent' => 'bank details'] as $prefix => $subject) {
      $fields[$prefix . '_text_hash'] = BaseFieldDefinition::create('string')
        ->setLabel(t('Consent text hash for @subject', ['@subject' => $subject]))
        ->setDescription(t('SHA-256 hash of the consent text shown with the latest consent for @subject.', ['@subject' => $subject]))
        ->setSetting('max_length', 64);
      $fields[$prefix . '_locale'] = BaseFieldDefinition::create('string')
        ->setLabel(t('Consent text language for @subject', ['@subject' => $subject]))
        ->setDescription(t('Language of the consent text shown with the latest consent for @subject.', ['@subject' => $subject]))
        ->setSetting('max_length', 12);
      $fields[$prefix . '_scope_label'] = BaseFieldDefinition::create('string')
        ->setLabel(t('Consent Stelle for @subject', ['@subject' => $subject]))
        ->setDescription(t('Name of the Stelle the consent text for @subject named when the citizen consented; the text hash covers it, so a later rename does not break the proof.', ['@subject' => $subject]))
        ->setSetting('max_length', 255);
    }
    return $fields;
  }

  /**
   * Defines independent consent snapshots for individual profile fields.
   */
  public static function consentProofByFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('string_long')
      ->setLabel(t('Consent proof by profile field'))
      ->setDescription(t('Internal JSON map of profile fields to the consent records that cover them.'))
      ->setInternal(TRUE);
  }

  /**
   * Returns the consent revocation and login confirmation base fields.
   *
   * Shared by baseFieldDefinitions() and beantragt_applicant_update_10005().
   * A revocation time blocks every consent given at or before it; the login
   * confirmation names the identity values a sign-in (for example BundID)
   * confirmed, which rest on the authentication, not on a consent.
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   Field definitions keyed by field name.
   */
  public static function revocationBaseFieldDefinitions(): array {
    $fields = [];
    foreach (['field_consent' => 'contact data', 'field_bank_consent' => 'bank details'] as $prefix => $subject) {
      $fields[$prefix . '_revoked_time'] = BaseFieldDefinition::create('timestamp')
        ->setLabel(t('Consent revoked for @subject', ['@subject' => $subject]))
        ->setDescription(t('Time the citizen withdrew the consent for @subject. Consents given at or before this time no longer update the profile.', ['@subject' => $subject]));
    }
    $fields['field_login_confirmed_provider'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Login that confirmed identity values'))
      ->setDescription(t('Sign-in provider (for example bundid) whose login confirmed identity values on this profile.'))
      ->setSetting('max_length', 64);
    $fields['field_login_confirmed_time'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Time of the confirming login'));
    $fields['field_login_confirmed_keys'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Identity values confirmed by login'))
      ->setDescription(t('JSON map of CRM key to the keyed hash (HMAC-SHA-256 with the site hash salt) of the value the login confirmed. A value changed later no longer counts as confirmed.'))
      ->setInternal(TRUE);
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Auto-generated from full name or email.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_full_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Full Name'))
      ->setDescription(t('The applicant full name.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_first_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('First Name'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_last_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Last Name'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_birthdate'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Birthdate'))
      ->setDescription(t('Birthdate as string for format flexibility.'))
      ->setSetting('max_length', 50)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_address'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Address'))
      ->setDescription(t('Full address line.'))
      ->setSetting('max_length', 500)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_street'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Street'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_postal_code'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Postal Code'))
      ->setSetting('max_length', 20)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_city'] = BaseFieldDefinition::create('string')
      ->setLabel(t('City'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_phone'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Phone'))
      ->setSetting('max_length', 50)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Email'))
      ->setDescription(t('The applicant email address.'))
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'email_mailto',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_iban'] = BaseFieldDefinition::create('string')
      ->setLabel(t('IBAN'))
      ->setDescription(t('Stored encrypted at rest through Drupal Field Encrypt.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_gender'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Gender'))
      ->setSetting('max_length', 50)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_nationality'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Nationality'))
      ->setSetting('max_length', 100)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_marital_status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Marital Status'))
      ->setSetting('max_length', 50)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_tax_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tax ID'))
      ->setDescription(t('Tax identification number. Stored encrypted at rest through Drupal Field Encrypt.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields += static::consentBaseFieldDefinitions();
    $fields += static::revocationBaseFieldDefinitions();
    $fields += static::consentProofBaseFieldDefinitions();
    $fields[self::CONSENT_PROOF_FIELD] = static::consentProofByFieldDefinition();

    $fields['field_session_token'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Session Token'))
      ->setDescription(t('Hashed token for anonymous applicant identification across sessions. Never exposed via JSON:API.'))
      ->setSetting('max_length', 255)
      ->setInternal(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(t('Owner'))
      ->setDescription(t('The user account associated with this applicant.'))
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 15,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the applicant was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the applicant was last updated.'));

    return $fields;
  }

}
