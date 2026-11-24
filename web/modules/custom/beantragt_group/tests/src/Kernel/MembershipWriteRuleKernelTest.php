<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The rule: memberships and groups are written only by product code.
 *
 * Entity access refuses create, update and delete of a membership and the
 * delete of a group to everyone but a platform administrator, whatever group
 * role the account holds. Every generic surface that asks this entity access
 * (Group's add, edit and delete forms, JSON:API, Views operation links) gets
 * the refusal from one place: _beantragt_group_structure_write_access().
 * Group's own leave form and editing or reverting a group do not ask it and
 * keep their own access checks.
 */
#[Group('beantragt_group')]
final class MembershipWriteRuleKernelTest extends GroupKernelTestBase {

  /**
   * The one reason of every refusal.
   */
  private const REASON = 'Memberships and groups are written only by product code or a platform administrator.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_roles_api',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
  ];

  /**
   * Municipality A.
   */
  private GroupInterface $municipality;

  /**
   * Office A1.
   */
  private GroupInterface $office;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    foreach (['department_admin', 'case_worker'] as $role_id) {
      if (Role::load($role_id) === NULL) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }
    $this->municipality = $this->createGroup([
      'type' => 'jur',
      'label' => 'Rule Municipality',
      'field_slug' => 'rule-town',
    ]);
    $this->office = $this->createGroup([
      'type' => 'org',
      'label' => 'Rule Office',
      'field_slug' => 'rule-office',
      'field_jurisdiction' => ['target_id' => $this->municipality->id()],
    ]);
  }

  /**
   * No group role writes a membership or deletes a group.
   */
  public function testEveryGroupRoleIsRefused(): void {
    $colleague = $this->createAccount('rule_colleague', ['case_worker']);
    $this->office->addMember($colleague, ['group_roles' => ['org-member']]);
    $member_of_municipality = $this->createAccount('rule_jur_member', ['case_worker']);
    $this->municipality->addMember($member_of_municipality, ['group_roles' => ['jur-member']]);

    $editor = $this->createAccount('rule_editor', ['case_worker']);
    $this->office->addMember($editor, ['group_roles' => ['org-admin']]);
    $limited = $this->createAccount('rule_limited', ['department_admin']);
    $this->office->addMember($limited, ['group_roles' => ['org-admin']]);
    $full = $this->createAccount('rule_full', ['department_admin']);
    $this->municipality->addMember($full, ['group_roles' => ['jur-admin']]);

    foreach ([
      'editor case worker (org-admin)' => [$editor, $this->office, $colleague],
      'limited Fachadministration (org-admin)' => [$limited, $this->office, $colleague],
      'full Fachadministration (jur-admin)' => [$full, $this->municipality, $member_of_municipality],
    ] as $who => [$account, $group, $other]) {
      $other_membership = $this->membership($group, $other);
      $own_membership = $this->membership($group, $account);
      foreach ([
        'update a colleague' => $other_membership->access('update', $account, TRUE),
        'delete a colleague' => $other_membership->access('delete', $account, TRUE),
        'update the own membership' => $own_membership->access('update', $account, TRUE),
        // Entity access only: Group's own leave form asks the group
        // permission "leave group" instead and stays open (contract section
        // 10), pinned in MembershipWriteSurfacesTest.
        'delete the own membership through entity access' => $own_membership->access('delete', $account, TRUE),
        'create a membership' => $this->createAccess($group, $account),
        'delete the group' => $group->access('delete', $account, TRUE),
      ] as $what => $result) {
        $this->assertRefusedByTheRule($result, $who . ': ' . $what);
      }
      // Reads are left alone.
      $this->assertTrue($own_membership->access('view', $account), $who . ': view the own membership');
    }
  }

  /**
   * A platform administrator passes the rule; Group decides as before.
   */
  public function testPlatformAdministratorPassesTheRule(): void {
    $platform = $this->createUser(['administer site configuration']);
    $this->assertInstanceOf(UserInterface::class, $platform);

    $this->assertTrue(_beantragt_group_structure_write_access($platform)->isNeutral());
    $this->assertTrue(_beantragt_group_structure_write_access($this->createAccount('rule_nobody', []))->isForbidden());
  }

  /**
   * The decision varies by permission and is never shared through a cache.
   *
   * A platform administrator and an editor case worker, both admin members of
   * the office, ask in turns for the same membership. The rule stays neutral
   * for the administrator, so Group allows it as before; the editor is
   * refused each time. An administrator without membership keeps Group's own
   * answer: not allowed, and not by the rule.
   */
  public function testDecisionVariesByPermissionAcrossTurns(): void {
    $colleague = $this->createAccount('rule_turn_colleague', ['case_worker']);
    $this->office->addMember($colleague, ['group_roles' => ['org-member']]);
    $editor = $this->createAccount('rule_turn_editor', ['case_worker']);
    $this->office->addMember($editor, ['group_roles' => ['org-admin']]);
    $platform = $this->createUser(['administer site configuration'], 'rule_turn_platform');
    $this->assertInstanceOf(UserInterface::class, $platform);
    $this->office->addMember($platform, ['group_roles' => ['org-admin']]);
    $platform_outside = $this->createUser(['administer site configuration'], 'rule_turn_platform_outside');
    $this->assertInstanceOf(UserInterface::class, $platform_outside);
    $membership = $this->membership($this->office, $colleague);

    foreach ([1, 2] as $turn) {
      $this->assertRefusedByTheRule($membership->access('update', $editor, TRUE), 'editor, turn ' . $turn);
      $this->assertTrue($membership->access('update', $platform), 'platform administrator, admin member, turn ' . $turn);
      $outside = $membership->access('update', $platform_outside, TRUE);
      $this->assertFalse($outside->isAllowed(), 'platform administrator without membership, turn ' . $turn);
      $reason = $outside instanceof AccessResultReasonInterface ? (string) $outside->getReason() : '';
      $this->assertStringNotContainsString(self::REASON, $reason, 'Group refuses, not the rule.');
    }
    $this->assertContains('user.permissions', _beantragt_group_structure_write_access($editor)->getCacheContexts());
    $this->assertContains('user.permissions', _beantragt_group_structure_write_access($platform)->getCacheContexts());
  }

  /**
   * A view of a membership and other relationship operations pass.
   */
  public function testTheRuleTouchesOnlyWrites(): void {
    $account = $this->createAccount('rule_viewer', ['case_worker']);
    $this->office->addMember($account, ['group_roles' => ['org-member']]);
    $membership = $this->membership($this->office, $account);

    $this->assertFalse(beantragt_group_group_relationship_access($membership, 'view', $account)->isForbidden());
    $this->assertFalse(beantragt_group_group_access($this->office, 'update', $account)->isForbidden());
    $this->assertFalse(beantragt_group_group_access($this->office, 'view', $account)->isForbidden());
  }

  /**
   * Asserts a refusal that comes from the one rule.
   */
  private function assertRefusedByTheRule(AccessResultInterface $result, string $message): void {
    $this->assertTrue($result->isForbidden(), $message);
    $this->assertInstanceOf(AccessResultReasonInterface::class, $result, $message);
    $this->assertStringContainsString(self::REASON, (string) $result->getReason(), $message);
  }

  /**
   * Create access for a membership of the group, as Group's add page asks.
   */
  private function createAccess(GroupInterface $group, UserInterface $account): AccessResultInterface {
    $bundle = $group->bundle() . '-group_membership';
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('group_relationship');
    $handler->resetCache();
    $result = $handler->createAccess($bundle, $account, ['group' => $group], TRUE);
    assert($result instanceof AccessResultInterface);
    return $result;
  }

  /**
   * The membership of an account in a group.
   */
  private function membership(GroupInterface $group, UserInterface $account): GroupMembershipInterface {
    $membership = GroupMembership::loadSingle($group, $account);
    $this->assertInstanceOf(GroupMembershipInterface::class, $membership);
    return $membership;
  }

  /**
   * Creates an active account with site roles.
   *
   * @param string $name
   *   The account name.
   * @param list<string> $site_roles
   *   The site roles.
   */
  private function createAccount(string $name, array $site_roles): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => $name . '@example.org']);
    assert($user instanceof UserInterface);
    foreach ($site_roles as $role) {
      $user->addRole($role);
    }
    $user->activate()->save();
    return $user;
  }

}
