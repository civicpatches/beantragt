<?php

/**
 * @file
 * Hooks provided by the beantragt_application module.
 */

declare(strict_types=1);

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the non-PII status timeline of an application.
 *
 * Invoked by every timeline builder: public status lookup, citizen detail
 * and staff detail. Entries carry at least "status" (machine name) and
 * "timestamp"; "label", "color", "icon" and "actor" are optional. Never add
 * personal data: the public status lookup shows the same entries.
 *
 * @param array<int, array<string, mixed>> $timeline
 *   Timeline entries, oldest first.
 * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
 *   The application.
 * @param string|null $langcode
 *   The language the reader asked for, or NULL for the current language.
 */
function hook_beantragt_application_timeline_alter(array &$timeline, ApplicationInterface $application, ?string $langcode): void {
  foreach ($timeline as &$entry) {
    if (($entry['status'] ?? '') === 'fachverfahren_uebergeben') {
      $entry['label'] = 'In der E-Akte abgelegt';
    }
  }
}

/**
 * Sends the confirmation request for an application.
 *
 * The recipient argument can carry a plain email address
 * (personal data), including when the application stores only a lookup hash.
 * Use it only for delivery. Do not log it or the token-bearing URL.
 *
 * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
 *   The application awaiting confirmation.
 * @param string $confirmation_url
 *   The confirmation link containing the current token.
 * @param string|null $fallback_recipient
 *   The plain email address supplied for delivery, if available.
 */
function hook_beantragt_application_confirmation_request(
  ApplicationInterface $application,
  string $confirmation_url,
  ?string $fallback_recipient = NULL,
): void {}

/**
 * @} End of "addtogroup hooks".
 */
