<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Application Attachment entity.
 *
 * Stores private files uploaded before an application is submitted. The upload
 * is claimed during application creation using a one-time token.
 *
 * @ContentEntityType(
 *   id = "application_attachment",
 *   label = @Translation("Application Attachment"),
 *   label_collection = @Translation("Application Attachments"),
 *   label_singular = @Translation("application attachment"),
 *   label_plural = @Translation("application attachments"),
 *   label_count = @PluralTranslation(
 *     singular = "@count application attachment",
 *     plural = "@count application attachments",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\beantragt_application\ApplicationAttachmentAccessControlHandler",
 *   },
 *   base_table = "application_attachment",
 *   admin_permission = "administer application_attachment entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "field_original_filename",
 *     "owner" = "uid",
 *   },
 * )
 */
class ApplicationAttachment extends ContentEntityBase implements ApplicationAttachmentInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $filename = $this->get('field_original_filename')->value;
    return is_string($filename) && $filename !== ''
      ? $filename
      : 'Application attachment ' . (string) ($this->id() ?? '');
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
  public function getFieldId(): string {
    return (string) ($this->get('field_field_id')->value ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function isClaimed(): bool {
    return !$this->get('field_application')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['field_file'] = BaseFieldDefinition::create('file')
      ->setLabel(t('File'))
      ->setDescription(t('The uploaded private attachment file.'))
      ->setRequired(TRUE)
      ->setSetting('file_directory', 'application_attachments/[date:custom:Y-m]')
      ->setSetting('file_extensions', 'pdf jpg jpeg png')
      ->setSetting('max_filesize', '10 MB')
      ->setSetting('uri_scheme', 'private')
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_schema'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Form schema'))
      ->setDescription(t('The public form schema this attachment was uploaded for.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'form_schema')
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_application'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Application'))
      ->setDescription(t('The application this attachment was claimed by.'))
      ->setSetting('target_type', 'application')
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_field_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field ID'))
      ->setDescription(t('The form field id this attachment belongs to.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_claim_token_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Claim token hash'))
      ->setDescription(t('One-time token hash used to bind this upload to an application.'))
      ->setSetting('max_length', 64);

    $fields['field_claim_expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Claim token expires'))
      ->setDescription(t('Unix timestamp when the upload claim token expires.'));

    $fields['field_upload_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Upload status'))
      ->setDescription(t('Attachment lifecycle status.'))
      ->setRequired(TRUE)
      ->setDefaultValue('uploaded')
      ->setSetting('allowed_values', [
        'uploaded' => 'Uploaded',
        'claimed' => 'Claimed',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_analysis_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Analysis status'))
      ->setDescription(t('Future AI analysis lifecycle status.'))
      ->setRequired(TRUE)
      ->setDefaultValue('not_requested')
      ->setSetting('allowed_values', [
        'not_requested' => 'Not requested',
        'pending' => 'Pending',
        'complete' => 'Complete',
        'failed' => 'Failed',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_external_analysis_consent'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('External analysis consent'))
      ->setDescription(t('Whether the citizen explicitly consented to external AI analysis for this attachment during the trusted upload.'))
      ->setDefaultValue(FALSE)
      ->setReadOnly(TRUE);

    $fields['field_guard_status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Document guard status'))
      ->setDescription(t('Lifecycle status of the privacy-preserving document guard.'))
      ->setDefaultValue('pending')
      ->setSetting('max_length', 32)
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_guard_report'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Document guard report'))
      ->setDescription(t('PII-free report returned unchanged by the document guard.'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_guard_derivative'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Document guard derivative'))
      ->setDescription(t('The privacy-preserving derivative approved for external analysis.'))
      ->setSetting('file_directory', 'application_attachments/derivatives/[date:custom:Y-m]')
      ->setSetting('file_extensions', 'pdf')
      ->setSetting('max_filesize', '10 MB')
      ->setSetting('uri_scheme', 'private')
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_analysis_metadata'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Analysis metadata'))
      ->setDescription(t('Structured metadata from attachment analysis jobs.'))
      ->setInternal(TRUE);

    $fields['field_scan_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Scan status'))
      ->setDescription(t('Malware scan lifecycle status.'))
      ->setRequired(TRUE)
      ->setDefaultValue('clean')
      ->setSetting('allowed_values', [
        'clean' => 'Clean',
        'quarantined' => 'Quarantined',
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_scan_sha256'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Verified antivirus content hash'))
      ->setDescription(t('SHA-256 of bytes with an explicit positive antivirus verdict.'))
      ->setSetting('max_length', 64)
      ->setReadOnly(TRUE);

    $fields['field_submission_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Original submission revision'))
      ->setDescription(t('Original application revision that claimed this form upload.'))
      ->setReadOnly(TRUE);

    $fields['field_scan_message'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Scan message'))
      ->setDescription(t('Scanner message for quarantined attachments.'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_original_filename'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Original filename'))
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_mime_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('MIME type'))
      ->setSetting('max_length', 128)
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_size'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('File size'))
      ->setDescription(t('File size in bytes.'))
      ->setDisplayConfigurable('view', TRUE);

    // Where the attachment came from. Empty for rows from before the field
    // and for citizen and comment uploads; the transfer selector never picks
    // a follow-up document (Nachreichung) as an original submission.
    $fields['field_provenance'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Provenance'))
      ->setSetting('allowed_values', [
        'submission' => 'Submission',
        'comment' => 'Comment',
        'bulk' => 'Bulk intake',
        'nachreichung' => 'Follow-up documents',
      ]);

    $fields['field_received_on'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Received on'))
      ->setDescription(t('Receipt date (YYYY-MM-DD) of a follow-up document.'))
      ->setSetting('max_length', 10);

    $fields['field_nachreichung'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Follow-up receipt'))
      ->setDescription(t('UUID of the receipt record (Vermerk) that attached this file.'))
      ->setSetting('max_length', 36);

    $fields['uid']
      ->setLabel(t('Owner'))
      ->setDescription(t('The service account that created this attachment.'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the attachment was uploaded.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the attachment was last changed.'));

    return $fields;
  }

}
