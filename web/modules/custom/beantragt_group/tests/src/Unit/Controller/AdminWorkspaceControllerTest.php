<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Controller;

use Drupal\beantragt_group\Controller\AdminWorkspaceController;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Verwaltung workspace catalogue.
 */
#[CoversClass(AdminWorkspaceController::class)]
#[Group('beantragt_group')]
final class AdminWorkspaceControllerTest extends UnitTestCase {

  /**
   * Org members need a readable organisation workspace.
   */
  public function testOrganisationMemberReceivesOrganisationWorkspaceOnly(): void {
    $account = $this->account(['authenticated', 'department_admin']);
    $jurisdiction = $this->group(12, 'jur', 'dunkelstadt', 'Stadt Dunkelstadt');
    $organisation = $this->group(61, 'org', '11', 'Personal- und Verwaltungsmanagement', $jurisdiction);
    $manager = $this->entityTypeManager(
      [$jurisdiction, $organisation],
      [$this->membership(61, ['org-member'])],
      ['org-member' => FALSE],
      TRUE,
    );

    $response = (new AdminWorkspaceController($manager, $account, new FormSchemaWorkspaceScope($manager)))->listWorkspaces();
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $payload['jurisdictions']);
    $this->assertSame(['dunkelstadt/11'], array_column($payload['organisations'], 'key'));
    $this->assertSame(['dunkelstadt/11'], array_column($payload['workspaces'], 'key'));
    $this->assertSame(1, $payload['count']);
    $this->assertFalse($payload['workspaces'][0]['canCreateFormSchema']);
  }

  /**
   * Case workers need the same scoped workspace catalogue for lists.
   */
  public function testCaseWorkerMemberReceivesOrganisationWorkspaceOnly(): void {
    $account = $this->account(['authenticated', 'case_worker']);
    $jurisdiction = $this->group(12, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(61, 'org', 'citizen-services', 'Bürgerbüro', $jurisdiction);
    $manager = $this->entityTypeManager(
      [$jurisdiction, $organisation],
      [$this->membership(61, ['org-member'])],
      ['org-member' => FALSE],
    );

    $response = (new AdminWorkspaceController($manager, $account, new FormSchemaWorkspaceScope($manager)))->listWorkspaces();
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $payload['jurisdictions']);
    $this->assertSame(['musterstadt/citizen-services'], array_column($payload['organisations'], 'key'));
    $this->assertSame(['musterstadt/citizen-services'], array_column($payload['workspaces'], 'key'));
    $this->assertSame(1, $payload['count']);
    $this->assertFalse($payload['workspaces'][0]['canCreateFormSchema']);
  }

  /**
   * Plain staff accounts without memberships stay fail-closed.
   */
  public function testStaffWithoutMembershipReceivesNoWorkspaces(): void {
    $account = $this->account(['authenticated', 'department_admin']);
    $jurisdiction = $this->group(12, 'jur', 'dunkelstadt', 'Stadt Dunkelstadt');
    $organisation = $this->group(61, 'org', '11', 'Personal- und Verwaltungsmanagement', $jurisdiction);
    $manager = $this->entityTypeManager([$jurisdiction, $organisation], [], []);

    $response = (new AdminWorkspaceController($manager, $account, new FormSchemaWorkspaceScope($manager)))->listWorkspaces();
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $payload['workspaces']);
    $this->assertSame(0, $payload['count']);
  }

  /**
   * A group role named with an admin suffix needs the admin flag.
   */
  public function testAdminRoleSuffixDoesNotGrantJurisdictionWorkspace(): void {
    $account = $this->account(['authenticated', 'department_admin']);
    $jurisdiction = $this->group(12, 'jur', 'dunkelstadt', 'Stadt Dunkelstadt');
    $manager = $this->entityTypeManager(
      [$jurisdiction],
      [$this->membership(12, ['jur-auditor-admin'])],
      ['jur-auditor-admin' => FALSE],
    );

    $response = (new AdminWorkspaceController($manager, $account, new FormSchemaWorkspaceScope($manager)))->listWorkspaces();
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $payload['jurisdictions']);
    $this->assertSame([], $payload['workspaces']);
  }

  /**
   * Only a personal session receives the form creation capability.
   */
  public function testScopeBypassAndServiceAccountCatalogue(): void {
    $jurisdiction = $this->group(12, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(61, 'org', 'service', 'Service', $jurisdiction);
    $manager = $this->entityTypeManager([$jurisdiction, $organisation], [], [], TRUE);
    foreach (['department_admin', 'service_account'] as $role) {
      $account = $this->account(['authenticated', $role], ['bypass beantragt group scope']);
      $response = (new AdminWorkspaceController($manager, $account, new FormSchemaWorkspaceScope($manager)))->listWorkspaces();
      $payload = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame(2, $payload['count']);
      foreach ($payload['workspaces'] as $workspace) {
        if ($role === 'service_account') {
          $this->assertArrayNotHasKey('canCreateFormSchema', $workspace);
        }
        else {
          $this->assertTrue($workspace['canCreateFormSchema']);
        }
      }
    }
  }

  /**
   * Creates an account mock.
   *
   * @param string[] $roles
   *   Site roles.
   * @param string[] $permissions
   *   Permission strings granted to the account.
   */
  private function account(array $roles, array $permissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $permissions, TRUE)
    );
    return $account;
  }

  /**
   * Creates an entity type manager mock.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Workspace groups returned by the group storage.
   * @param \Drupal\group\Entity\GroupRelationshipInterface[] $memberships
   *   Current-user membership relationships.
   * @param array<string, bool> $groupRoles
   *   Group role admin flags keyed by role id.
   * @param bool $allowCreate
   *   Create access from the form schema entity access handler.
   */
  private function entityTypeManager(array $groups, array $memberships, array $groupRoles, bool $allowCreate = FALSE): EntityTypeManagerInterface {
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('getQuery')->willReturn($this->query(array_keys($groups)));
    $group_storage->method('loadMultiple')->willReturn($groups);

    $relationship_storage = $this->createMock(EntityStorageInterface::class);
    $relationship_storage->method('getQuery')->willReturn($this->query(array_keys($memberships)));
    $relationship_storage->method('loadMultiple')->willReturn($memberships);

    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('load')->willReturnCallback(
      fn(string $role_id): ?GroupRoleInterface => array_key_exists($role_id, $groupRoles)
        ? $this->groupRole($groupRoles[$role_id])
        : NULL
    );

    $access = $this->createMock(EntityAccessControlHandlerInterface::class);
    $access->method('createAccess')->willReturn($allowCreate);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getAccessControlHandler')->with('form_schema')->willReturn($access);
    $manager->method('hasDefinition')->with('group')->willReturn(TRUE);
    $manager->method('getStorage')->willReturnCallback(
      static fn(string $entity_type): EntityStorageInterface => match ($entity_type) {
        'group' => $group_storage,
        'group_relationship' => $relationship_storage,
        'group_role' => $role_storage,
        default => throw new \InvalidArgumentException(sprintf('Unexpected storage "%s".', $entity_type)),
      }
    );
    return $manager;
  }

  /**
   * Creates a fluent entity query mock.
   *
   * @param int[] $ids
   *   IDs returned by execute().
   */
  private function query(array $ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine($ids, $ids) ?: []);
    return $query;
  }

  /**
   * Creates a group mock.
   */
  private function group(
    int $id,
    string $bundle,
    string $slug,
    string $label,
    ?GroupInterface $jurisdiction = NULL,
  ): GroupInterface {
    $fields = [
      'field_slug' => $this->field(FALSE, $slug),
      'field_parent_jurisdiction' => $this->field(TRUE),
    ];
    if ($jurisdiction instanceof GroupInterface) {
      $fields['field_jurisdiction'] = $this->field(FALSE, $jurisdiction->id(), $jurisdiction);
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('uuid')->willReturn('uuid-' . $id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);
    $group->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $fields)
    );
    $group->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field "%s".', $field_name))
    );
    return $group;
  }

  /**
   * Creates a field item list mock.
   */
  private function field(bool $empty, mixed $targetId = NULL, mixed $entity = NULL): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->value = $targetId;
    $field->target_id = $targetId;
    $field->entity = $entity;
    $field->method('isEmpty')->willReturn($empty);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => match ($property) {
        'value' => $targetId,
        'target_id' => $targetId,
        'entity' => $entity,
        default => NULL,
      }
    );
    return $field;
  }

  /**
   * Creates a membership relationship mock.
   *
   * @param int $groupId
   *   Group ID referenced by the relationship.
   * @param string[] $roleIds
   *   Group role IDs on the relationship.
   */
  private function membership(int $groupId, array $roleIds): GroupRelationshipInterface {
    $role_items = array_map(
      static fn(string $role_id): object => (object) ['target_id' => $role_id],
      $roleIds,
    );

    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => in_array($field_name, ['gid', 'group_roles'], TRUE)
    );
    $relationship->method('get')->willReturnCallback(
      fn(string $field_name): FieldItemListInterface => match ($field_name) {
        'gid' => $this->field(FALSE, $groupId),
        'group_roles' => $this->fieldList($role_items),
        default => throw new \InvalidArgumentException(sprintf('Unexpected field "%s".', $field_name)),
      }
    );
    return $relationship;
  }

  /**
   * Creates a field list mock that can be iterated.
   *
   * @param object[] $items
   *   Field item objects.
   */
  private function fieldList(array $items): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn(array_map(
      static fn(object $item): array => ['target_id' => $item->target_id],
      $items,
    ));
    return $field;
  }

  /**
   * Creates a group role mock.
   */
  private function groupRole(bool $isAdmin): GroupRoleInterface {
    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('isAdmin')->willReturn($isAdmin);
    return $role;
  }

}
