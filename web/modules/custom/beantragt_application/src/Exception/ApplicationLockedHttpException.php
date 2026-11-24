<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refuses a staff save while a transfer, deletion or comment holds the case.
 *
 * Nothing was stored. The editor may send the same change again shortly, so
 * the answer is 423 with a stable code instead of a generic server error.
 */
final class ApplicationLockedHttpException extends HttpException {

  /**
   * Stable error code for API clients and the staff UI proxies.
   */
  public const ERROR_CODE = 'application.locked';

  /**
   * Constructs the refusal around the original storage failure.
   */
  public function __construct(?\Throwable $previous = NULL) {
    parent::__construct(423, 'Application is currently being delivered or changed. Retry saving shortly.', $previous);
  }

  /**
   * Returns the refusal when the failure stems from the shared case lock.
   *
   * Entity storage wraps the lock failure from the presave hook, so the
   * cause chain is walked in every case. Other failures stay unchanged.
   */
  public static function fromFailure(\Throwable $exception): \Throwable {
    for ($cause = $exception; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
      if ($cause instanceof ApplicationDeletionLockedException) {
        return new self($exception);
      }
    }
    return $exception;
  }

}
