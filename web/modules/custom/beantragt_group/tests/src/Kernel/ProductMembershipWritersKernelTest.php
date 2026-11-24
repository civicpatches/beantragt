<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_group\Controller\WorkspaceInviteController;
use Drupal\beantragt_group\Controller\WorkspaceOrganisationController;
use Drupal\beantragt_group\Service\FachadministrationGrant;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * The product's own membership and group writers, one call each.
 *
 * Contract "memberships and groups are written only by product code": these
 * writers save through storage and carry their own checks, so a refusal at
 * entity access must leave them working. Each runs under a current user that
 * holds no group role where it writes, so a pass shows the write does not
 * ask entity access. Pinned before the rule (commit A) and kept green by it.
 * The demo login of a persona is DemoPersonaLoginScopeKernelTest.
 */
#[Group('beantragt_group')]
final class ProductMembershipWritersKernelTest extends GroupKernelTestBase {

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
    'field',
    'filter',
    'text',
    'node',
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
   * A citizen without any membership or role, as the current user.
   */
  private UserInterface $bystander;

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
    // The office archive refuses while content references the office; its
    // check fails closed when the node field is missing, so the fixture has
    // the field beantragt_content installs.
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'writer_page', 'name' => 'Writer page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_organisation',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_organisation',
      'entity_type' => 'node',
      'bundle' => 'writer_page',
    ])->save();
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    foreach (['department_admin', 'case_worker'] as $role_id) {
      if (Role::load($role_id) === NULL) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }
    $this->municipality = $this->createGroup([
      'type' => 'jur',
      'label' => 'Writer Municipality',
      'field_slug' => 'writer-town',
      'status' => TRUE,
    ]);
    $this->office = $this->createGroup([
      'type' => 'org',
      'label' => 'Writer Office',
      'field_slug' => 'writer-office',
      'field_jurisdiction' => ['target_id' => $this->municipality->id()],
    ]);
    $this->bystander = $this->createAccount('writer_bystander', []);
    $this->setCurrentUser($this->bystander);
  }

  /**
   * The grant service writes site role and admin membership.
   */
  public function testGrantServiceWrites(): void {
    $account = $this->createAccount('writer_granted', []);
    $grant = $this->container->get('beantragt_group.fachadministration_grant');
    assert($grant instanceof FachadministrationGrant);

    $grant->grantOffice($account, $this->office, 'test');

    $this->assertRoles($this->office, $account, ['org-admin']);
  }

  /**
   * The invitation claim writes the invited member role.
   *
   * The controller's own write step (grantInviteRoles()) on a real
   * controller; issuing and claiming a token are covered by its unit test.
   */
  public function testInvitationClaimWrites(): void {
    $claimant = $this->createAccount('writer_claimant', []);
    $controller = WorkspaceInviteController::create($this->container);
    $step = new \ReflectionMethod($controller, 'grantInviteRoles');

    $step->invoke($controller, $claimant, $this->office, ['org-member']);

    $this->assertRoles($this->office, $claimant, ['org-member']);
  }

  /**
   * The seed's writes: add a member, add a role, prune a membership.
   */
  public function testSeedStyleWrites(): void {
    $account = $this->createAccount('writer_seeded', []);

    $this->office->addMember($account, ['group_roles' => ['org-member']]);
    $membership = GroupMembership::loadSingle($this->office, $account);
    $this->assertInstanceOf(GroupMembershipInterface::class, $membership);
    $membership->addRole('org-admin');
    $this->assertRoles($this->office, $account, ['org-admin', 'org-member']);

    $membership->delete();
    $this->assertFalse(GroupMembership::loadSingle($this->office, $account));
  }

  /**
   * The product's office management creates and archives an office.
   *
   * It runs as the full Fachadministration and asks no entity access: its own
   * check decides (canManageJurisdiction()), and "delete" archives.
   */
  public function testOfficeManagementCreatesAndArchives(): void {
    $full = $this->createAccount('writer_full', ['department_admin']);
    $this->municipality->addMember($full, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($full);
    $controller = WorkspaceOrganisationController::create($this->container);

    $created = $controller->createOrganisation($this->request('POST', [
      'data' => ['label' => 'Writer New Office', 'slug' => 'writer-new-office'],
    ]));
    $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
    $offices = $this->container->get('entity_type.manager')->getStorage('group')->loadByProperties([
      'type' => 'org',
      'field_slug' => 'writer-new-office',
    ]);
    $this->assertCount(1, $offices);
    $new_office = reset($offices);
    assert($new_office instanceof GroupInterface);
    $this->assertSame([], GroupMembership::loadByGroup($new_office), 'A created office has no members (creator wizard).');

    $archived = $controller->deleteOrganisation($this->request('DELETE'), (string) $new_office->uuid());
    $this->assertSame(200, $archived->getStatusCode(), (string) $archived->getContent());
    $reloaded = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($new_office->id());
    $this->assertInstanceOf(GroupInterface::class, $reloaded, 'Archived, not deleted.');
    $this->assertFalse($reloaded->isPublished());
  }

  /**
   * A request of the office management for the fixture municipality.
   *
   * @param string $method
   *   The HTTP method.
   * @param array<string, mixed>|null $body
   *   The JSON body, if any.
   */
  private function request(string $method, ?array $body = NULL): Request {
    return Request::create('/api/admin/workspace-organisations?jurisdiction=writer-town', $method, [], [], [], [], $body === NULL ? NULL : json_encode($body, JSON_THROW_ON_ERROR));
  }

  /**
   * Asserts the group roles an account holds in a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param list<string> $expected
   *   Sorted group role IDs.
   */
  private function assertRoles(GroupInterface $group, UserInterface $account, array $expected): void {
    $this->container->get('entity_type.manager')->getStorage('group_relationship')->resetCache();
    $membership = GroupMembership::loadSingle($group, $account);
    $this->assertInstanceOf(GroupMembershipInterface::class, $membership);
    $roles = array_keys($membership->getRoles(FALSE));
    sort($roles);
    $this->assertSame($expected, $roles);
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
