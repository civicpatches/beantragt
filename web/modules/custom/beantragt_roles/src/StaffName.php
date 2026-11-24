<?php

declare(strict_types=1);

namespace Drupal\beantragt_roles;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * First and last name of staff accounts and who may see them.
 *
 * Staff see each other by name (assignment, status history, transfers,
 * decision notices). Citizens and anonymous visitors never see staff names:
 * the name fields are staff-only and the user label, which carries the name,
 * is hidden from them as well.
 */
final class StaffName {

  /**
   * First name field on the user entity.
   */
  public const FIRST_NAME_FIELD = 'field_first_name';

  /**
   * Last name field on the user entity.
   */
  public const LAST_NAME_FIELD = 'field_last_name';

  /**
   * Maximum length of each name part in characters.
   */
  public const MAX_LENGTH = 60;

  /**
   * Characters a name part must not contain (markup and control characters).
   */
  public const FORBIDDEN_PATTERN = '/[<>\x00-\x1F\x7F]/u';

  /**
   * Returns the cleaned display name, or '' when no part is set.
   *
   * Removes markup characters and control characters, collapses whitespace
   * and caps each part at MAX_LENGTH characters. A single part (for example
   * only "Systemadministration" as last name) is a valid display name.
   */
  public static function compose(?string $first_name, ?string $last_name): string {
    $parts = [];
    foreach ([$first_name, $last_name] as $part) {
      $clean = self::clean((string) $part);
      if ($clean !== '') {
        $parts[] = $clean;
      }
    }
    return implode(' ', $parts);
  }

  /**
   * Cleans one name part.
   */
  public static function clean(string $value): string {
    $value = preg_replace(self::FORBIDDEN_PATTERN, ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, self::MAX_LENGTH);
  }

  /**
   * Returns the display name stored on a user entity, or '' without one.
   */
  public static function fromUser(UserInterface $user): string {
    if (!$user->hasField(self::FIRST_NAME_FIELD) || !$user->hasField(self::LAST_NAME_FIELD)) {
      return '';
    }
    return self::compose(
      $user->get(self::FIRST_NAME_FIELD)->value,
      $user->get(self::LAST_NAME_FIELD)->value,
    );
  }

  /**
   * Whether the viewer may see names of other accounts.
   *
   * Staff (including uid 1) and user administrators may. Citizens, anonymous
   * visitors and service accounts may not; everyone may see their own name.
   */
  public static function viewerSeesNames(AccountInterface $viewer): bool {
    return (int) $viewer->id() === 1
      || BeantragtRoles::isStaffAccount($viewer)
      || $viewer->hasPermission('administer users');
  }

}
