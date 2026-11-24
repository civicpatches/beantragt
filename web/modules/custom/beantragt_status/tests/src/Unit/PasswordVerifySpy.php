<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_status\Unit;

/**
 * Opt-in observation of bcrypt calls without replacing their real behavior.
 */
final class PasswordVerifySpy {

  /**
   * Observed hashes, or NULL when observation is disabled.
   *
   * @var list<string>|null
   */
  public static ?array $hashes = NULL;

}
