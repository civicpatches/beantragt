<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationProofAssignments;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\file\FileInterface;

/**
 * Selects positively claimed files using the current confirmed assignments.
 */
final class TransferAttachmentSelector {

  public const MAX_COUNT = 20;
  public const MAX_PER_FIELD = 5;
  public const MAX_FILE_BYTES = 10485760;
  public const MAX_TOTAL_BYTES = 52428800;
  public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

  /**
   * Constructs the local-only selector.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ApplicationDataProtectionService $protection,
  ) {}

  /**
   * Freezes clean claimed uploads with a submitted or confirmed assignment.
   */
  public function select(ApplicationInterface $application, int $revision, bool $send): array {
    if (!$send) {
      return [];
    }
    // Scan revocations and replaced managed files must bypass entity caches.
    $this->entities->getStorage('application_attachment')->resetCache();
    $this->entities->getStorage('file')->resetCache();
    $forms = $this->entities->getStorage('form_schema');
    $form = $forms instanceof RevisionableStorageInterface ? $forms->loadRevision($revision) : NULL;
    if (!$form instanceof FormSchemaInterface) {
      throw new IntegrationException('integration.attachment_provenance_invalid', 409);
    }
    $schema = json_decode((string) $form->get('field_schema')->value, TRUE, flags: JSON_THROW_ON_ERROR);
    $data = $this->data($application);
    $manifest = [];
    $size = 0;
    foreach ($schema['sections'] ?? [] as $section) {
      foreach ($section['fields'] ?? [] as $field) {
        if (($field['type'] ?? '') !== 'file' || !is_string($field['id'] ?? NULL)) {
          continue;
        }
        $field_id = $field['id'];
        $required = ($field['required'] ?? FALSE) === TRUE;
        // The real submit validator removes conditionally hidden field keys.
        // Such a field did not participate in the original submission.
        if (isset($field['conditionalOn']) && !array_key_exists($field_id, $data)) {
          continue;
        }
        $selected = 0;
        foreach (self::referenceIds($data[$field_id] ?? NULL) as $id) {
          $matches = $this->entities->getStorage('application_attachment')->loadByProperties([
            'uuid' => $id,
            'field_application' => (int) $application->id(),
          ]);
          $attachment = count($matches) === 1 ? reset($matches) : NULL;
          if (!$attachment instanceof ApplicationAttachmentInterface || !$this->original($attachment, $application, $form, $field_id)) {
            if ($required) {
              $this->fail('integration.attachment_not_clean', $field_id);
            }
            continue;
          }
          try {
            $file = $this->file($attachment);
            $bytes = $this->read($file);
            $hash = hash('sha256', $bytes);
            $proof = (string) ($attachment->get('field_scan_sha256')->value ?? '');
            if ((string) $attachment->get('field_scan_status')->value !== 'clean'
              || !preg_match('/^[a-f0-9]{64}$/D', $proof) || !hash_equals($proof, $hash)) {
              $this->fail('integration.attachment_not_clean', $field_id);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $name = (string) $attachment->get('field_original_filename')->value;
            if (!in_array($mime, self::MIME_TYPES, TRUE) || $mime !== (string) $attachment->get('field_mime_type')->value
              || strlen($bytes) !== (int) $attachment->get('field_size')->value
              || $name === '' || strlen($name) > 255 || basename($name) !== $name || preg_match('/[\x00-\x1f\x7f]/', $name)) {
              $this->fail('integration.attachment_invalid', $field_id);
            }
          }
          catch (IntegrationException $exception) {
            if ($required) {
              throw $exception;
            }
            continue;
          }
          $manifest[] = [
            'id' => $id,
            'size' => strlen($bytes),
            'name' => $name,
            'sha256' => $hash,
            'mime_type' => $mime,
            'field_id' => $field_id,
            'form_revision_id' => $revision,
            'submission_revision_id' => (int) $attachment->get('field_submission_revision_id')->value,
          ];
          $selected++;
          $size += strlen($bytes);
          if (count($manifest) > self::MAX_COUNT || $selected > self::MAX_PER_FIELD || $size > self::MAX_TOTAL_BYTES) {
            $this->fail('integration.attachment_bounds_exceeded', $field_id);
          }
        }
        if ($required && $selected === 0) {
          $this->fail('integration.attachment_not_clean', $field_id);
        }
      }
    }
    usort($manifest, static fn(array $a, array $b): int => [$a['field_id'], $a['id']] <=> [$b['field_id'], $b['id']]);
    return $manifest;
  }

  /**
   * Revalidates frozen provenance and bytes before any external request.
   */
  public function verified(ApplicationInterface $application, int $revision, array $manifest, bool $send): array {
    $current = $this->select($application, $revision, $send);
    if (IntegrationRules::canonical($current) !== IntegrationRules::canonical($manifest)) {
      throw new IntegrationException('integration.attachment_changed', 409);
    }
    $result = [];
    foreach ($manifest as $item) {
      $matches = $this->entities->getStorage('application_attachment')->loadByProperties([
        'uuid' => $item['id'],
        'field_application' => (int) $application->id(),
      ]);
      $attachment = reset($matches);
      if (!$attachment instanceof ApplicationAttachmentInterface) {
        throw new IntegrationException('integration.attachment_changed', 409);
      }
      $bytes = $this->read($this->file($attachment));
      if (!hash_equals($item['sha256'], hash('sha256', $bytes))) {
        throw new IntegrationException('integration.attachment_changed', 409);
      }
      $result[] = [
        'attachmentId' => $item['id'],
        'bytes' => $bytes,
        'filename' => $item['name'],
        'mimeType' => $item['mime_type'],
      ];
    }
    return $result;
  }

  /**
   * Requires a positive original-submission claim, never timestamps alone.
   */
  private function original(ApplicationAttachmentInterface $attachment, ApplicationInterface $application, FormSchemaInterface $form, string $field_id): bool {
    $revision = (int) ($attachment->get('field_submission_revision_id')->value ?? 0);
    if ($revision < 1 || (int) $attachment->get('field_application')->target_id !== (int) $application->id()
      || (int) $attachment->get('field_schema')->target_id !== (int) $form->id()
      || $field_id === 'comment_attachment' || $attachment->getFieldId() === 'comment_attachment'
      // Follow-up documents (Nachreichung) never count as the original
      // submission; they reach the case system only with step 6 (N16).
      || ($attachment->hasField('field_provenance') && $attachment->get('field_provenance')->value === 'nachreichung')
      || (string) $attachment->get('field_upload_status')->value !== 'claimed'
      || !$attachment->get('field_claim_token_hash')->isEmpty() || !$attachment->get('field_claim_expires')->isEmpty()
      || $attachment->getCreatedTime() > $application->getCreatedTime()) {
      return FALSE;
    }
    $storage = $this->entities->getStorage('application');
    $original = $storage instanceof RevisionableStorageInterface ? $storage->loadRevision($revision) : NULL;
    if (!$original instanceof ApplicationInterface || $original->uuid() !== $application->uuid()
      || (int) $original->get('field_schema')->target_id !== (int) $form->id()
      || (int) $original->get('field_schema_revision_id')->value !== (int) $form->getRevisionId()) {
      return FALSE;
    }
    $uuid = $attachment->uuid();
    $confirmed = ApplicationProofAssignments::read($application)[$uuid] ?? NULL;
    if ($confirmed !== NULL) {
      return $application->get('field_origin')->value === 'bulk'
        && $original->get('field_origin')->value === 'bulk'
        && $confirmed['fieldId'] === $field_id;
    }
    return $attachment->getFieldId() === $field_id && in_array($uuid, self::referenceIds($this->data($original)[$field_id] ?? NULL), TRUE);
  }

  /**
   * Extracts only the server-produced file claim identity representation.
   */
  public static function referenceIds(mixed $value): array {
    if (!is_array($value) || !array_is_list($value)) {
      return [];
    }
    $ids = [];
    foreach ($value as $item) {
      if (is_array($item) && is_string($item['attachmentId'] ?? NULL)
        && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $item['attachmentId'])) {
        $ids[] = $item['attachmentId'];
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Reveals data locally without persisting another plaintext copy.
   */
  private function data(ApplicationInterface $application): array {
    return $this->protection->revealData(json_decode($application->getData() ?? '{}', TRUE, flags: JSON_THROW_ON_ERROR));
  }

  /**
   * Resolves original private form storage, excluding comment/reply paths.
   */
  private function file(ApplicationAttachmentInterface $attachment): FileInterface {
    $file = $this->entities->getStorage('file')->load($attachment->get('field_file')->target_id);
    if (!$file instanceof FileInterface || !str_starts_with($file->getFileUri(), 'private://application_attachments/')) {
      throw new IntegrationException('integration.attachment_provenance_invalid', 409);
    }
    return $file;
  }

  /**
   * Bounds local reads before hashing, MIME detection or transport encryption.
   */
  private function read(FileInterface $file): string {
    $bytes = @file_get_contents($file->getFileUri(), FALSE, NULL, 0, self::MAX_FILE_BYTES + 1);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_FILE_BYTES) {
      throw new IntegrationException('integration.attachment_bounds_exceeded', 409);
    }
    return $bytes;
  }

  /**
   * Emits bounded identifiers only, never file names or bytes in diagnostics.
   */
  private function fail(string $code, string $field_id): never {
    throw new IntegrationException($code, 409, [['code' => $code, 'source' => $field_id]]);
  }

}
