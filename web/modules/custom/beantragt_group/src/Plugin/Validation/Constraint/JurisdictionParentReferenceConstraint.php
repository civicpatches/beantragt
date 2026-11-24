<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures jurisdiction groups do not reference themselves as parent.
 *
 * @Constraint(
 *   id = "JurisdictionParentReference",
 *   label = @Translation("Jurisdiction parent reference", context = "Validation"),
 *   type = "entity:group"
 * )
 */
class JurisdictionParentReferenceConstraint extends Constraint {

  /**
   * Message shown when a jurisdiction points at itself as parent.
   */
  public string $selfReferenceMessage = 'A jurisdiction cannot reference itself as parent jurisdiction.';

  /**
   * Message shown when the parent is not a jurisdiction group.
   */
  public string $parentTypeMessage = 'A parent jurisdiction reference must point to a jurisdiction group.';

  /**
   * Message shown when the parent reference creates a cycle.
   */
  public string $circularReferenceMessage = 'A jurisdiction parent reference cannot create a circular hierarchy.';

}
