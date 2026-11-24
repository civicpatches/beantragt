<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Tells which proofs a request letter asks for (N1: bulk knows nr, never back).
 */
interface RequestedProofsProviderInterface {

  /**
   * The requested proofs of an application, NULL when unknown to the provider.
   *
   * @return array<int, array{key: string, fieldId: string, personBlock: ?string, label: string, reason: string, criteria: list<string>}>|null
   *   Entries in stored order; reason is missing, person_unmatched or
   *   requirement_not_met; criteria are fact keys, never personal data.
   */
  public function requested(ApplicationInterface $application): ?array;

}
