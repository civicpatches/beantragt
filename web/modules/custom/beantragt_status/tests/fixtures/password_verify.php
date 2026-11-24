<?php

/**
 * @file
 * Test-only namespace shim, loaded before any status verifier invocation.
 */

declare(strict_types=1);

namespace Drupal\beantragt_status\Service;

use Drupal\Tests\beantragt_status\Unit\PasswordVerifySpy;

/**
 * Observes exactly how many bcrypt operations the real service performs.
 */
function password_verify(string $password, string $hash): bool {
  if (PasswordVerifySpy::$hashes !== NULL) {
    PasswordVerifySpy::$hashes[] = $hash;
  }
  return \password_verify($password, $hash);
}
