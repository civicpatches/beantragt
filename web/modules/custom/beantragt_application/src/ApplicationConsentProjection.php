<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Projects the profile and bank consents of an application for API detail.
 *
 * Staff and citizen views get whether and when a consent was given, when it
 * became effective, its text version and the organisation or jurisdiction it
 * names. The rendered text and its hash stay in the stored record.
 *
 * "at" is the moment the text was accepted on submission. An anonymous
 * application proves its address only by the confirmation link, and only
 * then does the profile sync run, so the consent takes effect at
 * "effectiveAt": the later of submission and confirmation, NULL while the
 * application is unconfirmed.
 */
final class ApplicationConsentProjection {

  /**
   * Returns the profileConsent and bankConsent blocks.
   *
   * @return array{profileConsent: array{given: bool, at: string|null, effectiveAt: string|null, textVersion: string|null, scopeLabel: string|null}, bankConsent: array{given: bool, at: string|null, effectiveAt: string|null, textVersion: string|null, scopeLabel: string|null}}
   *   Consent blocks keyed by API name.
   */
  public static function project(ApplicationInterface $application): array {
    $confirmed_time = self::confirmedTime($application);
    return [
      'profileConsent' => self::block($application->hasProfileConsent(), $application->getProfileConsentRecord(), $confirmed_time),
      'bankConsent' => self::block($application->hasBankConsent(), $application->getBankConsentRecord(), $confirmed_time),
    ];
  }

  /**
   * Returns when the application was confirmed, NULL while unconfirmed.
   *
   * 0 means confirmed without a recorded time (an application that needed no
   * confirmation link).
   */
  private static function confirmedTime(ApplicationInterface $application): ?int {
    if (!$application->isEmailConfirmed()) {
      return NULL;
    }
    if (!$application->hasField('field_email_confirmed_time') || $application->get('field_email_confirmed_time')->isEmpty()) {
      return 0;
    }
    return max(0, (int) $application->get('field_email_confirmed_time')->value);
  }

  /**
   * Builds one consent block from its flag and record.
   *
   * @param bool $given
   *   Whether the consent is set on the application.
   * @param array<string, mixed> $record
   *   Stored consent record.
   * @param int|null $confirmed_time
   *   Confirmation time of the application, NULL while unconfirmed.
   *
   * @return array{given: bool, at: string|null, effectiveAt: string|null, textVersion: string|null, scopeLabel: string|null}
   *   Consent block without text or hash.
   */
  private static function block(bool $given, array $record, ?int $confirmed_time): array {
    if (!$given) {
      return [
        'given' => FALSE,
        'at' => NULL,
        'effectiveAt' => NULL,
        'textVersion' => NULL,
        'scopeLabel' => NULL,
      ];
    }
    $time = is_int($record['time'] ?? NULL) ? $record['time'] : 0;
    $effective = $confirmed_time === NULL ? 0 : max($time, $confirmed_time);
    $version = $record['textVersion'] ?? NULL;
    $label = is_array($record['scope'] ?? NULL) ? ($record['scope']['label'] ?? NULL) : NULL;
    return [
      'given' => TRUE,
      'at' => $time > 0 ? gmdate(\DateTimeInterface::ATOM, $time) : NULL,
      'effectiveAt' => $effective > 0 ? gmdate(\DateTimeInterface::ATOM, $effective) : NULL,
      'textVersion' => is_string($version) && $version !== '' ? $version : NULL,
      'scopeLabel' => is_string($label) && $label !== '' ? $label : NULL,
    ];
  }

}
