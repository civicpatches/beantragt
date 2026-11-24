<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;

/**
 * Request-local preloading for existing owner access checks on a staff page.
 *
 * No access decision is cached here. Entity checks still run for every case;
 * only the account's membership records are shared for this synchronous read.
 */
final class ApplicationMembershipBatch {

  /**
   * Active account, NULL outside the bounded callback.
   */
  private static ?int $uid = NULL;

  /**
   * The same membership records the individual owner checks would load.
   *
   * @var array<int, \Drupal\group\Entity\GroupMembershipInterface>
   */
  private static array $memberships = [];

  /**
   * Runs a page read after loading shared access dependencies in batches.
   */
  public static function run(EntityTypeManagerInterface $manager, AccountInterface $account, array $applications, callable $read): mixed {
    if (self::active($account)) {
      return $read();
    }
    $previous_uid = self::$uid;
    $previous_memberships = self::$memberships;
    $access = $manager->getAccessControlHandler('application');
    $access->resetCache();
    try {
      self::$uid = (int) $account->id();
      self::$memberships = [];
      // This also primes Group's own membership cache, used by delivery,
      // protected-data, assignment and docgen without changing their policy.
      foreach (GroupMembership::loadByUser($account) as $membership) {
        self::$memberships[(int) $membership->getGroupId()] = $membership;
      }
      $manager->getStorage('user')->load($account->id());
      $group_ids = [];
      $schema_ids = [];
      foreach ($applications as $application) {
        foreach (['field_organisation', 'field_group', 'field_jurisdiction', 'field_schema'] as $field) {
          if ($application->hasField($field) && !$application->get($field)->isEmpty()) {
            $id = (int) $application->get($field)->target_id;
            if ($id > 0) {
              if ($field === 'field_schema') {
                $schema_ids[$id] = $id;
              }
              else {
                $group_ids[$id] = $id;
              }
            }
          }
        }
      }
      if ($group_ids !== []) {
        $groups = $manager->getStorage('group')->loadMultiple($group_ids);
        $parents = [];
        foreach ($groups as $group) {
          if ($group instanceof FieldableEntityInterface && $group->hasField('field_jurisdiction') && !$group->get('field_jurisdiction')->isEmpty()) {
            $parent = (int) $group->get('field_jurisdiction')->target_id;
            if ($parent > 0 && !isset($group_ids[$parent])) {
              $parents[$parent] = $parent;
            }
          }
        }
        if ($parents !== []) {
          $manager->getStorage('group')->loadMultiple($parents);
        }
      }
      if ($schema_ids !== []) {
        $manager->getStorage('form_schema')->loadMultiple($schema_ids);
      }
      return $read();
    }
    finally {
      self::$uid = $previous_uid;
      self::$memberships = $previous_memberships;
      // A later mutation in the request must re-evaluate current access.
      $access->resetCache();
    }
  }

  /**
   * Whether existing access code may use the current membership snapshot.
   */
  public static function active(AccountInterface $account): bool {
    return self::$uid !== NULL && self::$uid === (int) $account->id();
  }

  /**
   * Discards a snapshot when a membership changes during the request.
   */
  public static function invalidate(int $uid): void {
    if (self::$uid === $uid) {
      self::$uid = NULL;
      self::$memberships = [];
    }
  }

  /**
   * Returns a membership only inside the caller's bounded page read.
   */
  public static function membership(int $group_id, AccountInterface $account): ?GroupMembershipInterface {
    return self::active($account) ? (self::$memberships[$group_id] ?? NULL) : NULL;
  }

  /**
   * Loads a membership, using the page snapshot when available.
   */
  public static function loadMembership(EntityTypeManagerInterface $manager, int $group_id, AccountInterface $account): ?GroupMembershipInterface {
    $uid = (int) $account->id();
    if ($uid <= 0 || $group_id <= 0 || !$manager->hasDefinition('group_relationship')) {
      return NULL;
    }
    if (self::active($account)) {
      return self::membership($group_id, $account);
    }

    $storage = $manager->getStorage('group_relationship');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $uid)
      ->condition('gid', $group_id)
      ->range(0, 1)
      ->execute();
    $membership = $ids === [] ? NULL : $storage->load(reset($ids));
    return $membership instanceof GroupMembershipInterface ? $membership : NULL;
  }

}
