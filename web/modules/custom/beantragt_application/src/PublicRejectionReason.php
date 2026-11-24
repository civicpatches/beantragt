<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Carries the applicant-facing explanation during one status update.
 */
final class PublicRejectionReason {

  /**
   * Explanations keyed by the entity being saved, never cached or persisted.
   *
   * @var \WeakMap<\Drupal\beantragt_application\Entity\ApplicationInterface, string>|null
   */
  private static ?\WeakMap $reasons = NULL;

  /**
   * Makes an explicit public reason available to synchronous save hooks.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application being rejected.
   * @param string $reason
   *   The explanation for the applicant.
   * @param callable(\Drupal\beantragt_application\Entity\ApplicationInterface): void $save
   *   The guarded save operation.
   */
  public static function duringSave(ApplicationInterface $application, string $reason, callable $save): void {
    self::$reasons ??= new \WeakMap();
    self::$reasons[$application] = $reason;
    try {
      $save($application);
    }
    finally {
      unset(self::$reasons[$application]);
    }
  }

  /**
   * Gets the explanation for this save, including an empty one.
   */
  public static function get(ApplicationInterface $application): string {
    return self::$reasons[$application] ?? '';
  }

}
