<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Repository\FormSetupLog;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the update hooks of the guided form setup.
 */
#[Group('beantragt_schema')]
final class FormSetupUpdateHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'serialization',
    'jsonapi',
    'json_form_widget',
    'beantragt_roles_api',
    'beantragt_schema',
    'beantragt_roles',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->container->get('module_handler')->loadInclude('beantragt_schema', 'install');
    $this->container->get('module_handler')->loadInclude('beantragt_roles', 'install');
    // beantragt_application has many dependencies; its install file is
    // loaded on its own to call the update hook.
    require_once DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('beantragt_application') . '/beantragt_application.install';
  }

  /**
   * Update 10015 grants department_admin, names other editors, idempotent.
   */
  public function testPublishPermissionUpdateGrantsAndIsIdempotent(): void {
    $edit = ['edit any form_schema entities', 'view unpublished form_schema entities'];
    $this->role('department_admin', [...$edit, 'administer form_schema entities']);
    $this->role('administrator', [], TRUE);
    $this->role('service_account', [...$edit, 'create form_schema entities']);
    $this->role('form_clerk', ['edit own form_schema entities']);
    $this->role('case_worker', ['view published form_schema entities']);

    $first = beantragt_roles_update_10015();

    self::assertTrue(Role::load('department_admin')->hasPermission('publish form_schema entities'));
    self::assertFalse(Role::load('service_account')->hasPermission('publish form_schema entities'));
    self::assertFalse(Role::load('form_clerk')->hasPermission('publish form_schema entities'));
    self::assertFalse(Role::load('case_worker')->hasPermission('publish form_schema entities'));
    self::assertStringContainsString('Granted the form publish permission to department_admin.', $first);
    self::assertStringContainsString('form_clerk', $first);
    self::assertStringNotContainsString('case_worker', $first);

    $second = beantragt_roles_update_10015();

    self::assertStringContainsString('already granted', $second);
    self::assertStringContainsString('form_clerk', $second, 'The editor stays named until it is granted.');
    self::assertSame(['publish form_schema entities'], array_values(array_filter(
      Role::load('department_admin')->getPermissions(),
      static fn(string $permission): bool => str_starts_with($permission, 'publish'),
    )));
  }

  /**
   * Update 10031 grants the recommendation permission to service_account.
   */
  public function testRecommendationPermissionUpdateIsIdempotent(): void {
    self::assertSame('service_account role not found.', beantragt_application_update_10031());
    $this->role('service_account', ['edit any form_schema entities']);

    self::assertStringStartsWith('Granted', beantragt_application_update_10031());
    self::assertTrue(Role::load('service_account')->hasPermission('record form setup recommendations'));
    self::assertFalse(Role::load('service_account')->hasPermission('publish form_schema entities'));
    self::assertStringContainsString('already', beantragt_application_update_10031());
  }

  /**
   * Update 10011 creates the log table once and keeps an existing one.
   */
  public function testTableUpdateIsIdempotent(): void {
    $schema = $this->container->get('database')->schema();
    self::assertFalse($schema->tableExists(FormSetupLog::TABLE));

    self::assertSame('Created the form setup log table.', beantragt_schema_update_10011());
    self::assertTrue($schema->tableExists(FormSetupLog::TABLE));
    self::assertTrue($schema->fieldExists(FormSetupLog::TABLE, 'run_slot'));
    self::assertTrue($schema->indexExists(FormSetupLog::TABLE, 'form_run'));
    self::assertSame('Form setup log table already exists.', beantragt_schema_update_10011());
    self::assertSame([FormSetupLog::TABLE => FormSetupLog::schema()], beantragt_schema_schema());
  }

  /**
   * Creates a role with permissions.
   *
   * @param string $id
   *   Role id.
   * @param list<string> $permissions
   *   Permissions.
   * @param bool $is_admin
   *   Whether the role is an admin role.
   */
  private function role(string $id, array $permissions, bool $is_admin = FALSE): void {
    $role = Role::create(['id' => $id, 'label' => $id, 'is_admin' => $is_admin]);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }

}
