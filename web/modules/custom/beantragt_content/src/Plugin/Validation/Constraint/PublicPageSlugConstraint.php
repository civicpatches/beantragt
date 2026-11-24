<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Ensures public page route slugs are deterministic within a jurisdiction.
 *
 * @Constraint(
 *   id = "PublicPageSlug",
 *   label = @Translation("Public page slug", context = "Validation"),
 *   type = "entity:node"
 * )
 */
class PublicPageSlugConstraint extends Constraint {

  /**
   * Message shown when a footer-visible published page has no public slug.
   */
  public string $missingMessage = 'Published public footer pages require a public slug.';

  /**
   * Message shown when the slug shape is invalid.
   */
  public string $invalidMessage = 'Public page slug "%slug" must contain only lowercase letters, numbers and hyphens.';

  /**
   * Message shown when another page already owns the slug.
   */
  public string $duplicateMessage = 'Another published page in this scope already uses public slug "%slug".';

  /**
   * Message shown when another public page owns the stable page key.
   */
  public string $duplicateKeyMessage = 'Another published page in this scope already uses public page key "%key".';

  /**
   * Message shown when an organisation does not belong to the jurisdiction.
   */
  public string $organisationScopeMessage = 'The selected organisation does not belong to the selected jurisdiction.';

}
