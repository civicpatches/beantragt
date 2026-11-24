<?php

declare(strict_types=1);

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\UserInterface;

/**
 * Looks up a fixed seed case across reference changes.
 */
final class DemoCaseReference {

  /**
   * Finds exactly one case under either its current or an earlier reference.
   */
  public static function find(EntityStorageInterface $storage, string $reference, array $legacy_keys = []): ?ApplicationInterface {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_reference_number', [$reference, ...$legacy_keys], 'IN')
      ->range(0, 2)
      ->execute();
    if (count($ids) > 1) {
      throw new RuntimeException("Multiple applications match the current or former reference of $reference.");
    }
    if (!$ids) {
      return NULL;
    }
    $application = $storage->load(reset($ids));
    if (!$application instanceof ApplicationInterface) {
      throw new RuntimeException("Could not load the application for $reference.");
    }
    return $application;
  }

  /**
   * Checks the seed's form and owner before modifying a matching reference.
   */
  public static function assertCase(ApplicationInterface $application, FormSchemaInterface $form, UserInterface $owner, ?int $previous_owner_id = NULL): void {
    if ((int) $application->get('field_schema')->target_id !== (int) $form->id()
      || !in_array((int) $application->getOwnerId(), [(int) $owner->id(), $previous_owner_id], TRUE)) {
      throw new RuntimeException(sprintf('%s belongs to another form or owner.', $application->getReferenceNumber()));
    }
  }

  /**
   * Renames in place, without adding another revision on subsequent runs.
   */
  public static function rename(ApplicationInterface $application, string $reference): void {
    if ($application->getReferenceNumber() !== $reference) {
      $application->set('field_reference_number', $reference);
      $application->save();
    }
  }

}
