<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Builds non-sensitive delivery envelopes from applications.
 */
final class DeliveryEnvelopeBuilder {

  /**
   * Constructs the envelope builder.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?TransferAttachmentSelector $transferAttachments = NULL,
  ) {}

  /**
   * Builds a non-sensitive delivery envelope for an application.
   */
  public function build(ApplicationInterface $application): DeliveryEnvelope {
    return $this->envelope($application, $this->attachmentManifest($application));
  }

  /**
   * Builds shared case metadata around an explicitly selected manifest.
   */
  private function envelope(ApplicationInterface $application, array $attachment_manifest): DeliveryEnvelope {
    $data_json = $application->getData() ?? '{}';
    $data = json_decode($data_json, TRUE);
    $field_count = is_array($data) && !array_is_list($data) ? count($data) : 0;
    $schema = $this->referencedEntity($application, 'field_schema');

    return new DeliveryEnvelope(
      (int) $application->id(),
      (string) $application->uuid(),
      $application->getReferenceNumber() ?? '',
      $schema instanceof EntityInterface ? (string) ($schema->uuid() ?? '') : '',
      $schema instanceof EntityInterface ? (string) ($schema->label() ?? '') : '',
      $application->getSchemaVersion(),
      hash('sha256', $data_json),
      $field_count,
      count($attachment_manifest),
      $this->workspace($application),
      $attachment_manifest,
    );
  }

  /**
   * Builds the bound transfer envelope with a positive original-upload list.
   */
  public function buildTransfer(ApplicationInterface $application, int $revision, bool $send_attachments): DeliveryEnvelope {
    if ($send_attachments && $this->transferAttachments === NULL) {
      throw new IntegrationException('integration.attachment_provenance_invalid', 409);
    }
    $manifest = $send_attachments ? $this->transferAttachments->select($application, $revision, TRUE) : [];
    return $this->envelope($application, $manifest);
  }

  /**
   * Counts application attachments without reading file payloads.
   */
  private function attachmentManifest(ApplicationInterface $application): array {
    if (!$this->entityTypeManager->hasDefinition('application_attachment')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('application_attachment');
    $ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', (int) $application->id())
      ->sort('created', 'ASC')
      ->execute();
    if ($ids === []) {
      return [];
    }

    $manifest = [];
    foreach ($storage->loadMultiple($ids) as $attachment) {
      $manifest[] = array_filter([
        'id' => (string) ($attachment->uuid() ?? ''),
        'size' => $this->fieldIntValue($attachment, 'field_size'),
      ], static fn(int|string $value): bool => $value !== '' && $value !== 0);
    }

    return $manifest;
  }

  /**
   * Builds a non-sensitive workspace projection.
   *
   * @return array<string, mixed>
   *   Workspace projection.
   */
  private function workspace(ApplicationInterface $application): array {
    return array_filter([
      'jurisdiction' => $this->groupProjection($this->referencedGroup($application, 'field_jurisdiction')),
      'organisation' => $this->groupProjection($this->referencedGroup($application, 'field_organisation')),
      'legacyGroup' => $this->groupProjection($this->referencedGroup($application, 'field_group')),
    ], static fn(mixed $value): bool => $value !== NULL);
  }

  /**
   * Projects a group entity without internal numeric IDs.
   *
   * @return array<string, string>|null
   *   Group projection, or NULL.
   */
  private function groupProjection(?GroupInterface $group): ?array {
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    $projection = [
      'uuid' => (string) ($group->uuid() ?? ''),
      'label' => (string) ($group->label() ?? ''),
    ];
    if ($group->hasField('field_slug')
      && !$group->get('field_slug')->isEmpty()) {
      $projection['slug'] = trim((string) $group->get('field_slug')->value);
    }

    return array_filter($projection, static fn(string $value): bool => $value !== '');
  }

  /**
   * Reads an entity reference field.
   */
  private function referencedEntity(ApplicationInterface $application, string $field_name): ?EntityInterface {
    if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
      return NULL;
    }
    $entity = $application->get($field_name)->entity ?? NULL;
    return $entity instanceof EntityInterface ? $entity : NULL;
  }

  /**
   * Reads one scalar field as a positive integer.
   */
  private function fieldIntValue(EntityInterface $entity, string $field_name): int {
    if (!$entity instanceof FieldableEntityInterface
      || !$entity->hasField($field_name)
      || $entity->get($field_name)->isEmpty()) {
      return 0;
    }
    return max(0, (int) ($entity->get($field_name)->value ?? 0));
  }

  /**
   * Reads a referenced group.
   */
  private function referencedGroup(ApplicationInterface $application, string $field_name): ?GroupInterface {
    $entity = $this->referencedEntity($application, $field_name);
    return $entity instanceof GroupInterface ? $entity : NULL;
  }

}
