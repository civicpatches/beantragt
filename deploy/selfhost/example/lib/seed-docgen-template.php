<?php

/**
 * @file
 * Stores versioned, pre-inspected letter templates for fictional demo forms.
 */

declare(strict_types=1);

use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Creates and releases one template version unless the released one equals it.
 */
function beantragt_demo_docgen_ensure_template(EntityTypeManagerInterface $entity_type_manager, TemplateCatalog $catalog, GroupInterface $jurisdiction, GroupInterface $scope, UserInterface $admin, string $seed_key, array $template, array &$counts): DocgenTemplateInterface {
  $bytes = $template['bytes'];
  $sha256 = hash('sha256', $bytes);
  $family = beantragt_demo_docgen_family($scope, $seed_key);
  $existing = $catalog->latestVersion($family);
  if ($existing instanceof DocgenTemplateInterface && (int) $existing->getScopeGroup()?->id() !== (int) $scope->id()) {
    throw new RuntimeException(sprintf('Template family %s of %s belongs to another scope.', $family, $template['label']));
  }
  if ($existing instanceof DocgenTemplateInterface && $existing->isReleased() && $existing->getSha256() === $sha256) {
    printf("Unchanged showcase template %s v%d.\n", $template['label'], $existing->getVersion());
    $counts['unchanged']++;
    return $existing;
  }

  $directory = TemplateCatalog::DIRECTORY . '/' . (int) $scope->id();
  $file_system = \Drupal::service('file_system');
  if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
    throw new RuntimeException('Private file storage for docgen templates is unavailable.');
  }
  $file = \Drupal::service('file.repository')->writeData($bytes, $directory . '/' . \Drupal::service('uuid')->generate() . '.docx', FileExists::Error);
  $file->setFilename($template['filename']);
  $file->setOwnerId((int) $admin->id());
  $file->setPermanent();
  $file->save();
  $created = $entity_type_manager->getStorage('docgen_template')->create([
    'family' => $family,
    'version' => ($existing?->getVersion() ?? 0) + 1,
    'label' => $template['label'],
    'purpose' => $template['purpose'],
    'status' => DocgenTemplateInterface::STATUS_DRAFT,
    'field_jurisdiction' => (int) $jurisdiction->id(),
    'field_organisation' => $scope->bundle() === 'org' ? (int) $scope->id() : NULL,
    'file' => ['target_id' => (int) $file->id()],
    'filename' => $template['filename'],
    'sha256' => $sha256,
    'size' => strlen($bytes),
    'inspection' => json_encode($template['inspection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    'uid' => (int) $admin->id(),
  ]);
  $created->save();
  if (!$created instanceof DocgenTemplateInterface) {
    throw new RuntimeException(sprintf('The showcase template %s could not be stored.', $template['label']));
  }
  if ($existing instanceof DocgenTemplateInterface && !$existing->isReleased()) {
    // An unreleased leftover of an earlier run is replaced, not kept.
    $catalog->deleteDraft($existing);
  }
  $released = $catalog->release($created, $admin);
  printf(
    "Released showcase template %s v%d (placeholders from %s; protected, rendered through the audited reveal: %s).\n",
    $template['label'],
    $released->getVersion(),
    $template['source'],
    $template['protected'] === [] ? 'none' : implode(', ', $template['protected']),
  );
  $counts['written']++;
  return $released;
}

/**
 * Derives the family UUID of a seeded template (UUID version 5 layout).
 */
function beantragt_demo_docgen_family(GroupInterface $scope, string $seed_key): string {
  $hash = sha1('beantragt-demo-showcase-docgen:' . $scope->uuid() . ':' . $seed_key);
  return sprintf(
    '%s-%s-5%s-%02x%s-%s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    substr($hash, 13, 3),
    (hexdec(substr($hash, 16, 2)) & 0x3f) | 0x80,
    substr($hash, 18, 2),
    substr($hash, 20, 12),
  );
}
