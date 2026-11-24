<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Session\AccountInterface;

/**
 * Allows account preferences unless an optional module supplies a policy.
 */
final class SharedAccountReadOnly implements SharedAccountReadOnlyInterface {

  /**
   * {@inheritdoc}
   */
  public function isReadOnly(AccountInterface $account): bool {
    return FALSE;
  }

}
