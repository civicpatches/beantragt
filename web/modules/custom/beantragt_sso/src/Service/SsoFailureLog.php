<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use Psr\Log\LoggerInterface;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;

/**
 * Writes bounded SSO failure records without authentication payload contents.
 */
final class SsoFailureLog {

  /**
   * Returns a safe category only from locally typed authentication failures.
   */
  public static function code(\Throwable $exception, string $fallback): string {
    return self::sanitizeCode($exception instanceof SsoAuthenticationException ? $exception->failureCode : $fallback);
  }

  /**
   * Writes one safe failure record and returns its local correlation ID.
   */
  public static function log(LoggerInterface $logger, string $level, string $code, string $_provider_id): string {
    $code = self::sanitizeCode($code);
    $correlation_id = bin2hex(random_bytes(16));
    $context = [
      '@code' => $code,
      '@correlation' => $correlation_id,
    ];
    $logger->log(
      in_array($level, ['error', 'warning', 'notice'], TRUE) ? $level : 'warning',
      'SSO failure @code (correlation @correlation).',
      $context,
    );
    return $correlation_id;
  }

  /**
   * Refuses arbitrary exception messages or provider-controlled categories.
   */
  private static function sanitizeCode(string $code): string {
    $allowed_codes = [
      'acs_rejected',
      'identity_disabled',
      'account_ineligible',
      'account_privileged',
      'identity_busy',
      'bad_signature',
      'login_start_failed',
      'metadata_unavailable',
      'mock_login_failed',
      'profile_claim_failed',
      'profile_update_failed',
      'relay_state_rejected',
      'unsafe_email_link_config',
    ];
    return in_array($code, $allowed_codes, TRUE) ? $code : 'acs_rejected';
  }

}
