<?php

declare(strict_types=1);

namespace Drupal\beantragt_maintenance\Service;

/**
 * Defines maintenance-only OTP and recovery-token operations.
 */
interface BreakGlassOtpServiceInterface {

  /**
   * Reports whether either platform or Core maintenance is active.
   */
  public function isAvailable(): bool;

  /**
   * Requests a generic recovery-code response.
   *
   * @return array{success: true, message: string, expiresIn: int, codeLength: int}
   *   The generic response returned for all syntactically valid requests.
   */
  public function requestCode(string $email): array;

  /**
   * Consumes a code and issues a short-lived recovery token.
   *
   * @return array{recoveryToken: string, expiresIn: int}|null
   *   Recovery token details, or NULL for an invalid code or account.
   */
  public function verifyCode(string $email, string $code): ?array;

  /**
   * Checks a recovery token without consuming it.
   */
  public function isRecoveryTokenValid(string $token): bool;

  /**
   * Consumes a recovery token and returns the authorizing account ID.
   */
  public function consumeRecoveryToken(string $token): ?int;

  /**
   * Invalidates every outstanding maintenance code and recovery token.
   */
  public function invalidateRecoveryArtifacts(): void;

}
