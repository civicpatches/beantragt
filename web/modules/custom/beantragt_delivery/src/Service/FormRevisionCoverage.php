<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * One definition of exact and lifecycle-equivalent form revision coverage.
 */
final class FormRevisionCoverage {

  /**
   * Constructs the revision resolver.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entities) {}

  /**
   * Accepts listed revisions and revisions with identical schema bytes.
   *
   * A transfer reads only the form fields (field_schema) of the intake
   * revision. The form version also moves when only the processing workflow
   * changes; transitions follow the form's current published revision for
   * running cases too, while the fields stay with the intake revision.
   * Such a revision produces exactly the same transfer data, so it stays
   * covered.
   */
  public function covers(int $revision, array $covered): bool {
    if (in_array($revision, $covered, TRUE)) {
      return TRUE;
    }
    $storage = $this->entities->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface) {
      return FALSE;
    }
    $actual = $storage->loadRevision($revision);
    if (!$actual instanceof FormSchemaInterface || $actual->getVersion() < 1) {
      return FALSE;
    }
    foreach ($covered as $id) {
      $candidate = $storage->loadRevision($id);
      if ($candidate instanceof FormSchemaInterface && $candidate->uuid() === $actual->uuid()
        && $candidate->getVersion() >= 1
        && hash_equals(hash('sha256', (string) $actual->get('field_schema')->value), hash('sha256', (string) $candidate->get('field_schema')->value))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Resolves legacy content versions only when every historical copy agrees.
   */
  public function resolve(ApplicationInterface $application, FormSchemaInterface $form): int {
    if ($application->hasField('field_schema_revision_id') && !$application->get('field_schema_revision_id')->isEmpty()) {
      return (int) $application->get('field_schema_revision_id')->value;
    }
    $storage = $this->entities->getStorage('form_schema');
    if (!$storage instanceof RevisionableStorageInterface || $application->getSchemaVersion() < 1) {
      throw new IntegrationException('integration.revision_not_covered');
    }
    $revisions = $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', $form->id())
      ->condition('field_version', $application->getSchemaVersion())->sort('revision_id', 'DESC')->range(0, 1001)->execute();
    if ($revisions === [] || count($revisions) > 1000) {
      throw new IntegrationException('integration.revision_not_covered');
    }
    $hash = NULL;
    foreach (array_keys($revisions) as $revision) {
      $candidate = $storage->loadRevision($revision);
      if (!$candidate instanceof FormSchemaInterface || $candidate->uuid() !== $form->uuid()
        || $candidate->getVersion() !== $application->getSchemaVersion()) {
        throw new IntegrationException('integration.revision_not_covered');
      }
      $next = hash('sha256', (string) $candidate->get('field_schema')->value);
      if ($hash !== NULL && !hash_equals($hash, $next)) {
        throw new IntegrationException('integration.revision_not_covered');
      }
      $hash = $next;
    }
    return (int) array_key_first($revisions);
  }

}
