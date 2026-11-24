<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The old update hooks derive no membership from a site role any more.
 *
 * Update hooks 10005, 10012, 10013 and 10017 previously made administrators
 * and department admins members of every group. The hooks keep their
 * numbers and leave memberships to explicit grants.
 */
#[Group('beantragt_group')]
final class NoRoleDerivedMembershipKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('file', ['file_usage']);
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    foreach (['department_admin', 'administrator'] as $role_id) {
      if (Role::load($role_id) === NULL) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }
  }

  /**
   * Organisation members can view their group, including after an upgrade.
   */
  public function testOrganisationMembersCannotEditTheirGroup(): void {
    $municipality = $this->createGroup(['type' => 'jur', 'label' => 'Municipality', 'field_slug' => 'member-role']);
    $office = $this->createGroup([
      'type' => 'org',
      'label' => 'Office',
      'field_slug' => 'member-office',
      'field_jurisdiction' => ['target_id' => $municipality->id()],
    ]);
    $member = $this->createUser([], 'office_member');
    self::assertInstanceOf(UserInterface::class, $member);
    $admin = $this->createStaff('office_admin', 'department_admin');
    $office->addMember($member, ['group_roles' => ['org-member']]);
    $office->addMember($admin, ['group_roles' => ['org-admin']]);

    $config = $this->config('group.role.org-member');
    self::assertSame(['view group'], $config->get('permissions'));
    $config->set('permissions', ['view group', 'edit group'])->save();
    beantragt_group_update_10031();
    beantragt_group_update_10031();
    self::assertSame(['view group'], $this->config('group.role.org-member')->get('permissions'));

    self::assertTrue($office->access('view', $member));
    self::assertFalse($office->access('update', $member));
    self::assertTrue($office->access('update', $admin));
  }

  /**
   * Hooks 10005, 10012, 10013 and 10017 leave every membership as it was.
   */
  public function testOldUpdateHooksLeaveMembershipsUnchanged(): void {
    $municipality_a = $this->createGroup(['type' => 'jur', 'label' => 'Municipality A', 'field_slug' => 'derive-a']);
    $municipality_b = $this->createGroup(['type' => 'jur', 'label' => 'Municipality B', 'field_slug' => 'derive-b']);
    $office_a1 = $this->createGroup([
      'type' => 'org',
      'label' => 'Office A1',
      'field_slug' => 'derive-a1',
      'field_jurisdiction' => ['target_id' => $municipality_a->id()],
    ]);
    $this->createGroup([
      'type' => 'org',
      'label' => 'Office B1',
      'field_slug' => 'derive-b1',
      'field_jurisdiction' => ['target_id' => $municipality_b->id()],
    ]);

    $limited = $this->createStaff('derive_limited', 'department_admin');
    $office_a1->addMember($limited, ['group_roles' => ['org-admin']]);
    $full = $this->createStaff('derive_full', 'department_admin');
    $municipality_b->addMember($full, ['group_roles' => ['jur-admin']]);
    // A platform administrator other than uid 1: the old sweep took it too.
    $platform = $this->createStaff('derive_platform', 'administrator');

    $before = [
      'limited' => $this->snapshot($limited),
      'full' => $this->snapshot($full),
      'platform' => $this->snapshot($platform),
    ];
    $this->assertSame(['Office A1' => ['org-admin']], $before['limited']);
    $this->assertSame(['Municipality B' => ['jur-admin']], $before['full']);
    $this->assertSame([], $before['platform']);
    $count_before = $this->membershipCount();

    $messages = [
      '10005' => beantragt_group_update_10005(),
      '10012' => beantragt_group_update_10012(),
      '10013' => beantragt_group_update_10013(),
      '10017' => beantragt_group_update_10017(),
    ];

    $this->assertSame($before, [
      'limited' => $this->snapshot($limited),
      'full' => $this->snapshot($full),
      'platform' => $this->snapshot($platform),
    ]);
    // Hooks 10012 and 10013 may add the demo groups; a created group gets no
    // member (creator wizard), so the site-wide count stays as well.
    $this->assertSame($count_before, $this->membershipCount(), json_encode($messages));
    foreach ($messages as $hook => $message) {
      $this->assertStringNotContainsString('demo admin', $message, 'update ' . $hook);
    }
  }

  /**
   * The install hook gives a department_admin account no membership.
   *
   * Run the install hook with demo seeding enabled to verify that its
   * automatic membership grant is limited to uid 1.
   */
  public function testInstallHookLeavesDepartmentAdminWithoutMemberships(): void {
    $municipality = $this->createGroup(['type' => 'jur', 'label' => 'Install Municipality', 'field_slug' => 'install-a']);
    $this->createGroup([
      'type' => 'org',
      'label' => 'Install Office',
      'field_slug' => 'install-a1',
      'field_jurisdiction' => ['target_id' => $municipality->id()],
    ]);
    $account = $this->createStaff('install_department_admin', 'department_admin');
    $platform = $this->createStaff('install_platform', 'administrator');
    $previous = getenv('BEANTRAGT_SKIP_DEMO_DATA');
    putenv('BEANTRAGT_SKIP_DEMO_DATA');

    try {
      beantragt_group_install();
    }
    finally {
      putenv($previous === FALSE ? 'BEANTRAGT_SKIP_DEMO_DATA' : 'BEANTRAGT_SKIP_DEMO_DATA=' . $previous);
    }

    $this->assertSame([], $this->snapshot($account));
    $this->assertSame([], $this->snapshot($platform));
  }

  /**
   * The memberships of an account: group label => sorted group roles.
   *
   * @return array<string, list<string>>
   *   The snapshot, sorted by label.
   */
  private function snapshot(UserInterface $account): array {
    $rows = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $roles = array_keys($membership->getRoles(FALSE));
      sort($roles);
      $group = $membership->getGroup();
      assert($group instanceof GroupInterface);
      $rows[(string) $group->label()] = $roles;
    }
    ksort($rows);
    return $rows;
  }

  /**
   * Every membership on the site.
   */
  private function membershipCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('group_relationship')->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->count()
      ->execute();
  }

  /**
   * Creates an active account holding one site role.
   */
  private function createStaff(string $name, string $site_role): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => $name . '@example.org']);
    assert($user instanceof UserInterface);
    $user->addRole($site_role);
    $user->activate()->save();
    return $user;
  }

}
