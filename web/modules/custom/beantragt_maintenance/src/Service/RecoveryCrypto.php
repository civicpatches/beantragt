<?php

declare(strict_types=1);

namespace Drupal\beantragt_maintenance\Service;

/**
 * Provides cryptographic primitives for maintenance recovery.
 */
final class RecoveryCrypto implements RecoveryCryptoInterface {

  /**
   * {@inheritdoc}
   */
  public function generateCode(): string {
    return (string) random_int(100000, 999999);
  }

  /**
   * {@inheritdoc}
   */
  public function generateToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * {@inheritdoc}
   */
  public function hashCode(string $code): string {
    return password_hash($code, PASSWORD_BCRYPT);
  }

  /**
   * {@inheritdoc}
   */
  public function verifyCode(string $code, string $hash): bool {
    return password_verify($code, $hash);
  }

}
