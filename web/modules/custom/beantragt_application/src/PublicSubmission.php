<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * Decides whether an application came in through a public internet form.
 *
 * The decision uses the form schema revision the application was submitted
 * against. Lifecycle changes (draft, archive, unpublish through the admin UI)
 * create new revisions, so they no longer lock citizens out of applications
 * they already submitted.
 */
final class PublicSubmission {

  /**
   * Returns the schema revision the application was submitted against.
   *
   * Falls back to the current schema when the application carries no
   * submission revision or the revision cannot be loaded.
   */
  public static function submittedSchema(FieldableEntityInterface $application, ?EntityTypeManagerInterface $entity_type_manager): ?FieldableEntityInterface {
    if (!$application->hasField('field_schema') || $application->get('field_schema')->isEmpty()) {
      return NULL;
    }
    $schema = $application->get('field_schema')->entity;

    $revision_id = $application->hasField('field_schema_revision_id') && !$application->get('field_schema_revision_id')->isEmpty()
      ? (int) $application->get('field_schema_revision_id')->value
      : 0;
    if ($revision_id > 0 && $entity_type_manager?->hasDefinition('form_schema')) {
      $storage = $entity_type_manager->getStorage('form_schema');
      if ($storage instanceof RevisionableStorageInterface) {
        $revision = $storage->loadRevision($revision_id);
        // Ignore a revision of another form, e.g. after field_schema was
        // re-pointed by staff.
        if ($revision instanceof FieldableEntityInterface
          && $schema instanceof FieldableEntityInterface
          && (int) $revision->id() === (int) $schema->id()) {
          return $revision;
        }
      }
    }

    return $schema instanceof FieldableEntityInterface ? $schema : NULL;
  }

  /**
   * Resolves the submitted schema for protected data and staff edits.
   */
  public static function protectedSchema(ApplicationInterface $application, EntityTypeManagerInterface $entity_type_manager): ?FormSchemaInterface {
    $schema = self::submittedSchema($application, $entity_type_manager);
    if (!$schema instanceof FormSchemaInterface) {
      return NULL;
    }

    $schema_version = $application->getSchemaVersion();
    $revision_id = $application->hasField('field_schema_revision_id')
      ? (int) ($application->get('field_schema_revision_id')->value ?? 0)
      : 0;
    if ($revision_id > 0) {
      // Do not fall through to a different revision when a pin exists.
      return (int) $schema->getRevisionId() === $revision_id
        && ($schema_version <= 0 || $schema->getVersion() === $schema_version)
        ? $schema
        : NULL;
    }

    if ($schema_version <= 0 || $schema->getVersion() === $schema_version) {
      return $schema;
    }

    return self::loadSchemaRevisionByVersion($schema, $schema_version, $entity_type_manager);
  }

  /**
   * Loads the form schema revision matching an application's schema version.
   */
  private static function loadSchemaRevisionByVersion(FormSchemaInterface $schema, int $schema_version, EntityTypeManagerInterface $entity_type_manager): ?FormSchemaInterface {
    $storage = $entity_type_manager->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface || $schema->id() === NULL) {
      return NULL;
    }

    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $schema->id())
      ->condition('field_version', $schema_version)
      ->sort('revision_id', 'DESC')
      ->range(0, 1)
      ->execute();
    $revision_id = array_key_first($revision_ids);
    if ($revision_id === NULL) {
      return NULL;
    }

    $revision = $storage->loadRevision($revision_id);
    return $revision instanceof FormSchemaInterface ? $revision : NULL;
  }

  /**
   * Returns TRUE when a schema is a published form scoped to the internet.
   */
  public static function isPublicInternetSchema(mixed $schema): bool {
    if (!$schema instanceof FieldableEntityInterface
      || !$schema->hasField('field_publication_scope')
      || !$schema->hasField('field_workflow_status')) {
      return FALSE;
    }

    if (method_exists($schema, 'isPublished') && !$schema->isPublished()) {
      return FALSE;
    }

    return $schema->get('field_publication_scope')->value === 'internet'
      && $schema->get('field_workflow_status')->value === 'published';
  }

}
