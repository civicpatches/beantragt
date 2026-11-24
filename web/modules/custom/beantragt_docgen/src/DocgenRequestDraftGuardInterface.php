<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Enforces case-specific constraints before writing a request letter.
 */
interface DocgenRequestDraftGuardInterface {

  /**
   * Rejects a conflicting request draft, with the application lock held.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application receiving the request.
   * @param array $documents
   *   The existing request letters, from newest to oldest.
   */
  public function assertRequestDraftAllowed(ApplicationInterface $application, array $documents): void;

}
