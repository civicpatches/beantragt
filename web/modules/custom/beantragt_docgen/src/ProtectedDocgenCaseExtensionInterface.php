<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Audited protected system values, separate from ordinary form-data access.
 */
interface ProtectedDocgenCaseExtensionInterface {

  /**
   * Authorizes and audits before returning any protected system values.
   *
   * The builder marks documents with any returned values as protected even
   * when the application schema has no protected fields. Implementations
   * must return nothing for unauthorized callers and fail if auditing fails.
   *
   * @return array<string, string>
   *   Values for the declared system tags only.
   */
  public function protectedSystemValues(ApplicationInterface $application, AccountInterface $account): array;

}
