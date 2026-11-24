<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_group\Service\WorkspaceInviteAccess;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scoped inviter cannot move its own invite scope.
 *
 * The scope decides in which jurisdiction an account may issue invites. If
 * the account could edit it, for example through a JSON:API PATCH of its own
 * user, it could invite into any jurisdiction.
 */
#[Group('beantragt_group')]
final class WorkspaceInviteScopeFieldAccessKernelTest extends GroupKernelTestBase {

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
    _beantragt_group_ensure_workspace_inviter_scope();
    if (Role::load('department_admin') === NULL) {
      Role::create(['id' => 'department_admin', 'label' => 'department_admin'])->save();
    }
  }

  /**
   * Only user or group administrators may change the scope.
   */
  public function testInviterCannotEditOwnScope(): void {
    $municipality = $this->createGroup(['type' => 'jur', 'label' => 'Municipality', 'field_slug' => 'scope-home']);

    $inviter = $this->createUser([], 'scoped_inviter');
    self::assertInstanceOf(UserInterface::class, $inviter);
    $inviter->addRole('department_admin');
    $inviter->addRole(WorkspaceInviteAccess::ROLE_ID);
    $inviter->set(WorkspaceInviteAccess::SCOPE_FIELD, ['target_id' => $municipality->id()]);
    $inviter->save();

    $scope = $inviter->get(WorkspaceInviteAccess::SCOPE_FIELD);
    self::assertFalse($scope->access('edit', $inviter), 'The inviter cannot move its own scope.');
    self::assertTrue($scope->access('view', $inviter), 'The inviter still sees its own scope.');

    $user_admin = $this->createUser(['administer users'], 'user_admin');
    self::assertInstanceOf(UserInterface::class, $user_admin);
    self::assertTrue($scope->access('edit', $user_admin), 'A user administrator may set the scope.');

    $group_admin = $this->createUser(['administer beantragt groups'], 'group_admin');
    self::assertInstanceOf(UserInterface::class, $group_admin);
    self::assertTrue($scope->access('edit', $group_admin), 'A group administrator may set the scope.');

    $plain = $this->createUser([], 'plain_account');
    self::assertInstanceOf(UserInterface::class, $plain);
    self::assertFalse($scope->access('edit', $plain), 'Other accounts cannot set the scope either.');
  }

}
