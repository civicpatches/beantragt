<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Grants a Fachadministration explicitly, for one municipality or one office.
 *
 * The only product path that writes an admin membership for a person. A grant
 * names the account, the group and, through the group type, the admin role:
 * jur-admin for a municipality (full role, reaches every office of it),
 * org-admin for an office (limited role, that office only). It adds the site
 * role department_admin if missing. It is additive and idempotent: it never
 * removes a membership, a group role or a site role, and a repeated call
 * changes nothing.
 *
 * The site role is account-wide: with it, every membership the account holds
 * becomes Fachadministration reach, a plain one included. Therefore a grant
 * that ADDS the site role is refused while the account holds a membership
 * outside the target's reach (the municipality and its offices, or the one
 * office), unless the caller names the widening: another municipality with
 * $allow_additional_municipality, other offices of the same municipality on
 * an office grant with $with_existing_offices. A membership in the
 * municipality group itself on an office grant, and one in an office without
 * a municipality, always refuse. An account that already holds the site role
 * reaches its memberships already; for it only the second-municipality rule
 * applies: while it holds a membership in another municipality and no admin
 * membership in the target's, the grant needs $allow_additional_municipality.
 */
final class FachadministrationGrant {

  /**
   * The site role of a Fachadministration.
   */
  public const SITE_ROLE = 'department_admin';

  /**
   * The admin group role per group type.
   */
  private const ADMIN_GROUP_ROLES = [
    'jur' => 'jur-admin',
    'org' => 'org-admin',
  ];

  /**
   * Constructs the grant service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Makes the account Fachadministration of one municipality (full role).
   *
   * @param \Drupal\user\UserInterface $account
   *   The person's account.
   * @param \Drupal\group\Entity\GroupInterface $municipality
   *   A jurisdiction group.
   * @param string $actor
   *   Who asks for the grant, for the log (for example the command name).
   * @param bool $allow_additional_municipality
   *   TRUE to allow reach in a municipality other than this one.
   *
   * @return list<string>
   *   The changes made; empty when the account already held everything.
   *
   * @throws \InvalidArgumentException
   *   When the account, the group or a reach rule refuses.
   * @throws \RuntimeException
   *   When the membership is not in place after the write.
   */
  public function grantMunicipality(UserInterface $account, GroupInterface $municipality, string $actor, bool $allow_additional_municipality = FALSE): array {
    if ($municipality->isNew() || $municipality->bundle() !== 'jur') {
      throw new \InvalidArgumentException(sprintf('Group %s is not a municipality.', $this->describeGroup($municipality)));
    }
    return $this->grant($account, $municipality, (int) $municipality->id(), $actor, $allow_additional_municipality, FALSE);
  }

  /**
   * Makes the account Fachadministration of one office (limited role).
   *
   * @param \Drupal\user\UserInterface $account
   *   The person's account.
   * @param \Drupal\group\Entity\GroupInterface $office
   *   An organisation group with a municipality.
   * @param string $actor
   *   Who asks for the grant, for the log (for example the command name).
   * @param bool $allow_additional_municipality
   *   TRUE to allow reach in a municipality other than the office's.
   * @param bool $with_existing_offices
   *   TRUE to allow, when the site role is added, that the account's
   *   memberships in other groups of the office's municipality become
   *   Fachadministration reach as well.
   *
   * @return list<string>
   *   The changes made; empty when the account already held everything.
   *
   * @throws \InvalidArgumentException
   *   When the account, the group or a reach rule refuses.
   * @throws \RuntimeException
   *   When the membership is not in place after the write.
   */
  public function grantOffice(UserInterface $account, GroupInterface $office, string $actor, bool $allow_additional_municipality = FALSE, bool $with_existing_offices = FALSE): array {
    if ($office->isNew() || $office->bundle() !== 'org') {
      throw new \InvalidArgumentException(sprintf('Group %s is not an office.', $this->describeGroup($office)));
    }
    $municipality = $this->municipalityOf($office);
    if (!$municipality instanceof GroupInterface) {
      throw new \InvalidArgumentException(sprintf('Office %s belongs to no municipality.', $this->describeGroup($office)));
    }
    return $this->grant($account, $office, (int) $municipality->id(), $actor, $allow_additional_municipality, $with_existing_offices);
  }

  /**
   * The municipalities the account reaches through any of its memberships.
   *
   * A membership of an office counts for the office's municipality; one of an
   * office without a municipality counts for none.
   *
   * @return list<int>
   *   Jurisdiction group IDs, sorted.
   */
  public function reachedMunicipalityIds(UserInterface $account): array {
    $ids = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $municipality = $this->municipalityOf($membership->getGroup());
      if ($municipality instanceof GroupInterface) {
        $ids[(int) $municipality->id()] = TRUE;
      }
    }
    $ids = array_keys($ids);
    sort($ids);
    return $ids;
  }

  /**
   * Every group the account reaches as Fachadministration, for display.
   *
   * @return list<string>
   *   One line per membership group, sorted: type, label, id, group roles;
   *   an admin membership of a municipality also reaches every office of it.
   */
  public function describeReach(UserInterface $account): array {
    $lines = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $line = $this->describeMembership($membership);
      if ($membership->getGroup()->bundle() === 'jur' && $this->hasAdminRole($membership)) {
        $line .= ', with every office of it';
      }
      $lines[] = $line;
    }
    sort($lines);
    return $lines;
  }

  /**
   * Validates everything, then writes the site role and the one membership.
   *
   * @return list<string>
   *   The changes made.
   */
  private function grant(UserInterface $account, GroupInterface $group, int $municipality_id, string $actor, bool $allow_additional_municipality, bool $with_existing_offices): array {
    $this->assertGrantableAccount($account);
    if ($this->entityTypeManager->getStorage('user_role')->load(self::SITE_ROLE) === NULL) {
      throw new \InvalidArgumentException(sprintf('The site role %s does not exist.', self::SITE_ROLE));
    }
    $group_role = self::ADMIN_GROUP_ROLES[$group->bundle()];

    $membership = GroupMembership::loadSingle($group, $account);
    $needs_site_role = !$account->hasRole(self::SITE_ROLE);
    $needs_group_role = !$membership instanceof GroupMembershipInterface
      || !in_array($group_role, array_keys($membership->getRoles(FALSE)), TRUE);
    if (!$needs_site_role && !$needs_group_role) {
      return [];
    }

    if ($needs_site_role) {
      $this->assertNoReachOutsideTarget($account, $group, $municipality_id, $allow_additional_municipality, $with_existing_offices);
    }
    else {
      $this->assertNoSecondMunicipality($account, $municipality_id, $allow_additional_municipality);
    }

    $changes = [];
    $transaction = $this->database->startTransaction();
    try {
      if ($needs_site_role) {
        $account->addRole(self::SITE_ROLE);
        $account->save();
        $changes[] = 'site role ' . self::SITE_ROLE;
      }
      if (!$membership instanceof GroupMembershipInterface) {
        $group->addMember($account, ['group_roles' => [$group_role]]);
        $changes[] = 'membership ' . $group_role;
      }
      elseif ($needs_group_role) {
        $membership->addRole($group_role);
        $changes[] = 'group role ' . $group_role;
      }

      $stored = GroupMembership::loadSingle($group, $account);
      if (!$stored instanceof GroupMembershipInterface
        || !in_array($group_role, array_keys($stored->getRoles(FALSE)), TRUE)) {
        throw new \RuntimeException(sprintf('Membership %s in %s is not in place after the grant.', $group_role, $this->describeGroup($group)));
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);

    // The log names the account by uid only: account names can be addresses.
    $this->logger->notice('Fachadministration grant by @actor (current uid @actor_uid): account uid @account received @role in @group; changes: @changes; reach after the grant: @reach.', [
      '@actor' => $actor,
      '@actor_uid' => (int) $this->currentUser->id(),
      '@account' => (int) $account->id(),
      '@role' => $group_role,
      '@group' => $this->describeGroup($group),
      '@changes' => implode(', ', $changes),
      '@reach' => implode('; ', $this->describeReach($account)),
    ]);

    return $changes;
  }

  /**
   * Refuses adding the site role while memberships lie outside the target.
   *
   * Every concerned group is named with the group roles held there, and with
   * the option that would accept it, if any.
   */
  private function assertNoReachOutsideTarget(UserInterface $account, GroupInterface $target, int $municipality_id, bool $allow_additional_municipality, bool $with_existing_offices): void {
    $orphaned = [];
    $foreign = [];
    $own_municipality = [];
    $other_offices = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $group = $membership->getGroup();
      if ((int) $group->id() === (int) $target->id()) {
        continue;
      }
      $municipality = $this->municipalityOf($group);
      if (!$municipality instanceof GroupInterface) {
        $orphaned[] = $this->describeMembership($membership);
      }
      elseif ((int) $municipality->id() !== $municipality_id) {
        $foreign[] = $this->describeMembership($membership);
      }
      elseif ($target->bundle() === 'org' && $group->bundle() === 'jur') {
        // On an office grant the municipality group itself lies outside, and
        // no office option covers it: with the site role a jur-admin there is
        // the full Fachadministration.
        $own_municipality[] = $this->describeMembership($membership);
      }
      elseif ($target->bundle() === 'org') {
        $other_offices[] = $this->describeMembership($membership);
      }
    }

    $reasons = [];
    if ($orphaned !== []) {
      sort($orphaned);
      $reasons[] = 'memberships in offices without a municipality, which no option accepts: ' . implode(', ', $orphaned);
    }
    if ($foreign !== [] && !$allow_additional_municipality) {
      sort($foreign);
      $reasons[] = 'memberships in another municipality, accepted only with the additional-municipality option: ' . implode(', ', $foreign);
    }
    if ($own_municipality !== []) {
      $reasons[] = 'a membership in the municipality group itself, which no office option accepts: with the site role a plain membership there becomes reach over the cases filed directly under the municipality, and jur-admin becomes the full role. Either use the municipality grant if the full role is meant, or have the operator remove this membership first (no command removes memberships today): ' . implode(', ', $own_municipality);
    }
    if ($other_offices !== [] && !$with_existing_offices) {
      sort($other_offices);
      $reasons[] = 'memberships in other offices of the same municipality, accepted only with the with-existing-offices option: ' . implode(', ', $other_offices);
    }
    if ($reasons !== []) {
      throw new \InvalidArgumentException(sprintf(
        'Account uid %d does not hold %s yet, and the site role would turn every membership into Fachadministration reach. Refused because of %s.',
        (int) $account->id(),
        self::SITE_ROLE,
        implode('; ', $reasons),
      ));
    }
  }

  /**
   * Refuses a grant into a new municipality while another one is reached.
   *
   * For an account that holds the site role. Any membership counts for "the
   * account reaches another municipality"; only an ADMIN membership in the
   * target's municipality (jur-admin there, or org-admin in one of its
   * offices) counts for "the target municipality is already its own". A plain
   * membership there, from an invitation for example, waives nothing.
   */
  private function assertNoSecondMunicipality(UserInterface $account, int $municipality_id, bool $allow_additional_municipality): void {
    if ($allow_additional_municipality) {
      return;
    }
    $elsewhere = [];
    $admin_in_target = FALSE;
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $municipality = $this->municipalityOf($membership->getGroup());
      if (!$municipality instanceof GroupInterface) {
        continue;
      }
      if ((int) $municipality->id() === $municipality_id) {
        $admin_in_target = $admin_in_target || $this->hasAdminRole($membership);
      }
      else {
        $elsewhere[] = $this->describeMembership($membership);
      }
    }
    if ($elsewhere !== [] && !$admin_in_target) {
      sort($elsewhere);
      throw new \InvalidArgumentException(sprintf(
        'Account uid %d already reaches another municipality and holds no admin membership in the target municipality yet; a second one needs the additional-municipality option. Memberships elsewhere: %s.',
        (int) $account->id(),
        implode(', ', $elsewhere),
      ));
    }
  }

  /**
   * One membership for messages: group type, label, id and group roles.
   */
  private function describeMembership(GroupMembershipInterface $membership): string {
    $roles = array_keys($membership->getRoles(FALSE));
    sort($roles);
    return $this->describeGroup($membership->getGroup()) . ' ' . ($roles === [] ? '(no group role)' : implode('+', $roles));
  }

  /**
   * Refuses accounts a grant must never touch.
   */
  private function assertGrantableAccount(UserInterface $account): void {
    if ($account->isNew() || (int) $account->id() <= 1) {
      throw new \InvalidArgumentException('The anonymous account, uid 1 and unsaved accounts are never granted.');
    }
    if (BeantragtRoles::isServiceAccount($account)) {
      throw new \InvalidArgumentException(sprintf('Account uid %d is a service account and is never granted.', (int) $account->id()));
    }
  }

  /**
   * TRUE when one of the membership's group roles is an admin role.
   */
  private function hasAdminRole(GroupMembershipInterface $membership): bool {
    return ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * The municipality of a group: itself, or an office's municipality, or NULL.
   */
  private function municipalityOf(GroupInterface $group): ?GroupInterface {
    if ($group->bundle() === 'jur') {
      return $group;
    }
    if ($group->bundle() !== 'org' || !$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $municipality = $this->entityTypeManager->getStorage('group')->load((int) $group->get('field_jurisdiction')->target_id);
    return $municipality instanceof GroupInterface && $municipality->bundle() === 'jur' ? $municipality : NULL;
  }

  /**
   * Group type, label and id for messages and the log.
   */
  private function describeGroup(GroupInterface $group): string {
    return sprintf('%s "%s" (id %s)', $group->bundle() === 'jur' ? 'municipality' : 'office', (string) $group->label(), (string) ($group->id() ?? 'new'));
  }

}
