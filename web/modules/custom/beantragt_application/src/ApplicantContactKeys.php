<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

/**
 * Applicant name and postal keys shared by profile sync and correspondence.
 */
final class ApplicantContactKeys {

  /**
   * Legacy CRM address keys accepted for older stored submissions.
   */
  public const ALIASES = [
    'address.full' => 'person.address',
    'address.street' => 'person.street',
    'address.postal_code' => 'person.postal_code',
    'address.city' => 'person.city',
  ];

  /**
   * Contact keys the applicant profile writes as a group.
   */
  public const GROUPS = [
    'address' => [
      'person.address',
      'person.street',
      'person.postal_code',
      'person.city',
    ],
    'name' => [
      'person.full_name',
      'person.first_name',
      'person.last_name',
    ],
  ];

  /**
   * The separate house number is folded into the profile's street line.
   */
  public const HOUSE_NUMBER = 'person.house_number';

  /**
   * Returns a canonical applicant name or address key, if explicitly mapped.
   */
  public static function canonical(mixed $key): ?string {
    if (!is_string($key)) {
      return NULL;
    }
    $key = self::ALIASES[$key] ?? $key;
    return $key === self::HOUSE_NUMBER || in_array($key, self::GROUPS['name'], TRUE) || in_array($key, self::GROUPS['address'], TRUE) ? $key : NULL;
  }

  /**
   * Combines street and house number, preserving an already complete line.
   */
  public static function streetLine(string $street, string $number): string {
    return $number === '' || preg_match('/(?:^|\s)' . preg_quote($number, '/') . '$/iu', $street)
      ? $street : $street . ' ' . $number;
  }

}
