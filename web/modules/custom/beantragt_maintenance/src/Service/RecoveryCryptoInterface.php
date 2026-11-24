<?php

declare(strict_types=1);

namespace Drupal\beantragt_maintenance\Service;

/**
 * Defines cryptographic operations used by maintenance recovery.
 */
interface RecoveryCryptoInterface {

  /**
   * Generates a six-digit recovery code.
   */
  public function generateCode(): string;

  /**
   * Generates an opaque high-entropy recovery token.
   */
  public function generateToken(): string;

  /**
   * Creates a slow password hash for a recovery code.
   */
  public function hashCode(string $code): string;

  /**
   * Verifies a recovery code against a slow password hash.
   */
  public function verifyCode(string $code, string $hash): bool;

}
