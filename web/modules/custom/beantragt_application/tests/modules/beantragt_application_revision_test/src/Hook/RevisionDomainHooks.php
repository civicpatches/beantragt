<?php

namespace Drupal\beantragt_application_revision_test\Hook;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * A real Drupal 11 domain hook that must run before the revision collector.
 */
final class RevisionDomainHooks {

  /**
   * Applies a configured late team assignment for the lifecycle regression.
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    $team = \Drupal::state()->get('application_revision_probe.team');
    if ($entity instanceof ApplicationInterface && $team !== NULL) {
      $entity->set('field_assigned_team', $team);
    }
  }

}
