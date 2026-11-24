<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Identifies shared accounts that must not persist individual preferences.
 */
interface SharedAccountReadOnlyInterface {

  /**
   * Returns whether this account's preferences are read-only.
   */
  public function isReadOnly(AccountInterface $account): bool;

}
