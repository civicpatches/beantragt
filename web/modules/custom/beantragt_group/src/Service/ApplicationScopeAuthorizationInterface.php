<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Authorizes target scope changes for existing applications.
 */
interface ApplicationScopeAuthorizationInterface {

  /**
   * Finds the first target scope the account may not manage.
   *
   * @return array{scope_id: int, jurisdiction_id: int, field_name: string}|null
   *   The disallowed scope and its jurisdiction, or NULL when allowed.
   *
   * @phpstan-impure
   */
  public function findUnauthorizedTarget(ApplicationInterface $application, AccountInterface $account): ?array;

}
