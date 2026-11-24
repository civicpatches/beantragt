<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/beantragt_group.module';

/**
 * Tests native Group field access fences.
 */
#[Group('beantragt_group')]
final class GroupFieldAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Platform administrators can use native widgets for managed fields.
   */
  #[DataProvider('platformEditableFieldProvider')]
  public function testPlatformAdminCanEditManagedField(string $field_name): void {
    $field = $this->fieldDefinition($field_name);
    $admin = $this->account(['administer group']);

    $result = beantragt_group_entity_field_access('edit', $field, $admin);

    self::assertTrue($result->isAllowed());
  }

  /**
   * Supplies the field name for each case.
   *
   * @return array<string, array{string}>
   *   Named test cases.
   */
  public static function platformEditableFieldProvider(): array {
    return [
      'API-managed settings' => ['field_jur_config'],
      'administrative parent organisation' => ['field_parent_org'],
    ];
  }

  /**
   * Workspace membership alone does not grant access to managed fields.
   */
  #[DataProvider('workspaceProtectedFieldProvider')]
  public function testWorkspaceMemberCannotEditManagedField(string $field_name): void {
    $field = $this->fieldDefinition($field_name);
    $member = $this->account([]);

    $result = beantragt_group_entity_field_access('edit', $field, $member);

    self::assertTrue($result->isForbidden());
  }

  /**
   * Supplies the field name for each case.
   *
   * @return array<string, array{string}>
   *   Named test cases.
   */
  public static function workspaceProtectedFieldProvider(): array {
    return [
      'API-managed settings' => ['field_jur_config'],
      'administrative jurisdiction' => ['field_jurisdiction'],
    ];
  }

  /**
   * Service-account scope bypass does not grant native settings edits.
   */
  public function testScopeBypassCannotEditApiManagedField(): void {
    $field = $this->fieldDefinition('field_jur_config');
    $service_account = $this->account(['bypass beantragt group scope']);

    $result = beantragt_group_entity_field_access('edit', $field, $service_account);

    self::assertTrue($result->isForbidden());
  }

  /**
   * Workspace members cannot redirect notification mail or rename workspaces.
   */
  public function testWorkspaceMemberCannotEditIdentityOrNotificationFields(): void {
    $member = $this->account([]);

    foreach (['label', 'field_org_email'] as $field_name) {
      $result = beantragt_group_entity_field_access(
        'edit',
        $this->fieldDefinition($field_name),
        $member,
      );
      self::assertTrue($result->isForbidden(), $field_name);
    }
  }

  /**
   * Ordinary, non-administrative Group fields keep the core access decision.
   */
  public function testOrdinaryFieldRemainsNeutral(): void {
    $field = $this->fieldDefinition('revision_log_message');
    $member = $this->account([]);

    $result = beantragt_group_entity_field_access('edit', $field, $member);

    self::assertTrue($result->isNeutral());
  }

  /**
   * Administrative edit fences do not hide the public workspace label.
   */
  public function testWorkspaceLabelRemainsVisible(): void {
    $member = $this->account([]);

    $result = beantragt_group_entity_field_access(
      'view',
      $this->fieldDefinition('label'),
      $member,
    );

    self::assertTrue($result->isNeutral());
  }

  /**
   * Global Group administrators can manage workspaces without membership.
   *
   * The account holds what the role "administrator" holds for this purpose:
   * "administer group" and "administer site configuration", the one
   * definition of a platform administrator (contract "memberships and groups
   * are written only by product code", section 2).
   */
  public function testGlobalAdminCanManageAnyGroup(): void {
    $group = $this->createMock(EntityInterface::class);
    $admin = $this->account(['administer group', 'administer site configuration']);

    foreach (['view', 'update', 'delete'] as $operation) {
      $result = beantragt_group_group_access($group, $operation, $admin);
      self::assertTrue($result->isAllowed(), $operation);
    }
  }

  /**
   * On purpose: a Group permission alone no longer deletes a group.
   *
   * Since the rule "memberships and groups are written only by product code"
   * a group is deleted only by product code or a platform administrator
   * ("administer site configuration"). An account with "administer group" or
   * "administer beantragt groups" but without it keeps view and update and is
   * refused delete with the rule's reason. No role in the product holds
   * either permission without the other: the role "administrator" holds all
   * three through is_admin.
   */
  public function testGroupPermissionWithoutSiteConfigurationIsRefusedDelete(): void {
    $group = $this->createMock(EntityInterface::class);
    foreach (['administer group', 'administer beantragt groups'] as $permission) {
      $account = $this->account([$permission]);
      foreach (['view', 'update'] as $operation) {
        self::assertTrue(beantragt_group_group_access($group, $operation, $account)->isAllowed(), $permission . ': ' . $operation);
      }
      $delete = beantragt_group_group_access($group, 'delete', $account);
      self::assertTrue($delete->isForbidden(), $permission . ': delete');
      self::assertInstanceOf(AccessResultReasonInterface::class, $delete);
      self::assertSame('Memberships and groups are written only by product code or a platform administrator.', $delete->getReason());
    }
  }

  /**
   * Ordinary Group access remains under the membership access handler.
   */
  public function testWorkspaceMemberGroupAccessRemainsNeutral(): void {
    $group = $this->createMock(EntityInterface::class);
    $member = $this->account([]);

    $result = beantragt_group_group_access($group, 'update', $member);

    self::assertTrue($result->isNeutral());
  }

  /**
   * Global administrators can create both supported workspace bundles.
   */
  public function testGlobalAdminCanCreateWorkspaceBundles(): void {
    $admin = $this->account(['administer beantragt groups']);

    foreach (['jur', 'org'] as $bundle) {
      $result = beantragt_group_group_create_access($admin, [], $bundle);
      self::assertTrue($result->isAllowed(), $bundle);
    }
  }

  /**
   * Ordinary accounts keep Group's membership-oriented create decision.
   */
  public function testWorkspaceMemberCreateAccessRemainsNeutral(): void {
    $member = $this->account([]);

    $result = beantragt_group_group_create_access($member, [], 'jur');

    self::assertTrue($result->isNeutral());
  }

  /**
   * Creates a Group field definition mock.
   */
  private function fieldDefinition(string $name): FieldDefinitionInterface {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getTargetEntityTypeId')->willReturn('group');
    $field->method('getName')->willReturn($name);

    return $field;
  }

  /**
   * Creates an account mock with the supplied permissions.
   *
   * @param string[] $permissions
   *   Granted permissions.
   */
  private function account(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );

    return $account;
  }

}
