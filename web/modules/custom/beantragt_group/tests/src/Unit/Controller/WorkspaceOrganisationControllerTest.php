<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Controller;

use Drupal\beantragt_group\Controller\WorkspaceOrganisationController;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\OrgHierarchyResolverInterface;
use Drupal\Component\Utility\EmailValidator;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests organisation workspace structure management.
 */
#[CoversClass(WorkspaceOrganisationController::class)]
#[Group('beantragt_group')]
final class WorkspaceOrganisationControllerTest extends UnitTestCase {

  /**
   * Captured set() calls.
   *
   * @var array<string, mixed>
   */
  private array $captured = [];

  /**
   * The group created by storage::create().
   */
  private ?GroupInterface $createdGroup = NULL;

  /**
   * Creating an organisation persists parent and metadata.
   */
  public function testCreateOrganisationWithParentPersistsFields(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $parent = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $controller = $this->controller([$jurisdiction, $parent]);

    $response = $controller->createOrganisation(Request::create(
      '/api/admin/workspace-organisations?jurisdiction=musterstadt',
      'POST',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
      content: (string) json_encode([
        'data' => [
          'label' => 'Digitales Team',
          'slug' => 'digital-team',
          'parentOrganisationId' => $this->uuid(7),
          'orgEmail' => 'digital@musterstadt.example.org',
          'referencePrefix' => 'MS-DIG',
          'referencePattern' => '{ORG}-{YEAR}-{SEQ:5}',
        ],
      ])
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertInstanceOf(GroupInterface::class, $this->createdGroup);
    $this->assertSame(['target_id' => 7], $this->captured['field_parent_org']);
    $this->assertSame('digital@musterstadt.example.org', $this->captured['field_org_email']);
    $this->assertSame('MS-DIG', $this->captured['field_reference_prefix']);
    $this->assertSame('musterstadt/digital-team', $payload['organisation']['key']);
  }

  /**
   * Patching parentOrganisationId rewires the organisation.
   */
  public function testPatchOrganisationParentPersistsRewire(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(8, 'org', 'digital-team', 'Digitales Team', $jurisdiction);
    $parent = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->once())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation, $parent]);

    $response = $controller->patchOrganisation(Request::create(
      '/api/admin/workspace-organisations/' . $this->uuid(8) . '?jurisdiction=musterstadt',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
      content: (string) json_encode(['data' => ['parentOrganisationId' => $this->uuid(7)]])
    ), $this->uuid(8));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(['target_id' => 7], $this->captured['field_parent_org']);
    $this->assertSame('musterstadt/digital-team', $payload['organisation']['key']);
  }

  /**
   * Circular parent rewires are rejected before saving.
   */
  public function testPatchRejectsCircularParentRewire(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $parent = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation = $this->group(8, 'org', 'digital-team', 'Digitales Team', $jurisdiction, $parent);
    $parent = $this->withParent($parent, $organisation);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation, $parent]);

    $response = $controller->patchOrganisation(Request::create(
      '/api/admin/workspace-organisations/' . $this->uuid(8) . '?jurisdiction=musterstadt',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
      content: (string) json_encode(['data' => ['parentOrganisationId' => $this->uuid(7)]])
    ), $this->uuid(8));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('parentOrganisationId', $payload['error']['field']);
  }

  /**
   * Numeric route identifiers are rejected instead of being treated as IDs.
   */
  public function testPatchRejectsNumericOrganisationIdentifier(): void {
    $jurisdiction = $this->group(3, 'jur', 'dunkelstadt', 'Dunkelstadt');
    $organisation = $this->group(61, 'org', '11', 'Amt 11', $jurisdiction);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->patchOrganisation(Request::create(
      '/api/admin/workspace-organisations/11?jurisdiction=dunkelstadt',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=dunkelstadt'],
      content: '{"data":{"label":"Wrong target"}}'
    ), '11');

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Archive rejects organisations with active children.
   */
  public function testDeleteRejectsOrganisationWithActiveChildren(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $child = $this->group(8, 'org', 'digital-team', 'Digitales Team', $jurisdiction, $organisation);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation, $child]);

    $response = $controller->deleteOrganisation(Request::create(
      '/api/admin/workspace-organisations/' . $this->uuid(7) . '?jurisdiction=musterstadt',
      'DELETE',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
    ), $this->uuid(7));

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
  }

  /**
   * Archive rejects organisations with active references.
   */
  public function testDeleteRejectsOrganisationWithReferences(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller(
      [$jurisdiction, $organisation],
      referenceHits: [
        'form_schema:field_organisation:7' => TRUE,
      ],
    );

    $response = $controller->deleteOrganisation(Request::create(
      '/api/admin/workspace-organisations/' . $this->uuid(7) . '?jurisdiction=musterstadt',
      'DELETE',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
    ), $this->uuid(7));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('organisation', $payload['error']['field']);
  }

  /**
   * Archive unpublishes leaf organisations.
   */
  public function testDeleteArchivesLeafOrganisation(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->once())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->deleteOrganisation(Request::create(
      '/api/admin/workspace-organisations/' . $this->uuid(7) . '?jurisdiction=musterstadt',
      'DELETE',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
    ), $this->uuid(7));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertFalse($this->captured['status']);
    $this->assertTrue($payload['archived']);
  }

  /**
   * Org admins cannot manage the jurisdiction organisation tree.
   */
  public function testOrganisationAdminCannotCreateSiblingOrganisation(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $controller = $this->controller(
      [$jurisdiction, $organisation],
      ['authenticated', 'department_admin'],
      [$this->membership(7, ['org-admin'])],
      ['org-admin' => TRUE],
    );

    $response = $controller->createOrganisation(Request::create(
      '/api/admin/workspace-organisations?jurisdiction=musterstadt',
      'POST',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt'],
      content: '{"data":{"label":"Bauamt","slug":"building-office"}}'
    ));

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Creates a controller with test storages.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Test groups.
   * @param string[] $roles
   *   Current user roles.
   * @param \Drupal\group\Entity\GroupRelationshipInterface[] $memberships
   *   Current user memberships.
   * @param array<string, bool> $groupRoles
   *   Group role admin flags keyed by role id.
   * @param array<string, bool> $referenceHits
   *   Reference query hits keyed by "entity_type:field_name:target_id".
   */
  private function controller(
    array $groups,
    array $roles = ['authenticated', 'administrator'],
    array $memberships = [],
    array $groupRoles = [],
    array $referenceHits = [],
  ): WorkspaceOrganisationController {
    $this->captured = [];
    $this->createdGroup = NULL;
    $groups_by_id = [];
    foreach ($groups as $group) {
      $groups_by_id[(int) $group->id()] = $group;
    }

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('loadByProperties')->willReturnCallback(
      static function (array $properties) use ($groups): array {
        return array_filter($groups, static function (GroupInterface $group) use ($properties): bool {
          foreach ($properties as $field => $expected) {
            if ($field === 'type' && $group->bundle() !== $expected) {
              return FALSE;
            }
            if ($field === 'uuid' && $group->uuid() !== $expected) {
              return FALSE;
            }
            if (in_array($field, ['field_slug', 'status', 'field_parent_org'], TRUE)) {
              $field_value = $group->get($field)->__get($field === 'field_parent_org' ? 'target_id' : 'value');
              if ((string) $field_value !== (string) $expected) {
                return FALSE;
              }
            }
          }
          return TRUE;
        });
      }
    );
    $group_storage->method('load')->willReturnCallback(
      static fn(int|string $id): ?GroupInterface => $groups_by_id[(int) $id] ?? NULL
    );
    $group_storage->method('create')->willReturnCallback(function (array $values): GroupInterface {
      $jurisdiction = $this->group(
        (int) ($values['field_jurisdiction']['target_id'] ?? 3),
        'jur',
        'musterstadt',
        'Musterstadt'
      );
      $this->createdGroup = $this->group(
        99,
        (string) $values['type'],
        (string) $values['field_slug'],
        (string) $values['label'],
        $jurisdiction
      );
      return $this->createdGroup;
    });

    $relationship_storage = $this->createMock(EntityStorageInterface::class);
    $relationship_storage->method('getQuery')->willReturn($this->query(array_keys($memberships)));
    $relationship_storage->method('loadMultiple')->willReturn($memberships);

    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('load')->willReturnCallback(
      fn(string $role_id): ?GroupRoleInterface => array_key_exists($role_id, $groupRoles)
        ? $this->groupRole($groupRoles[$role_id])
        : NULL
    );

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(
      fn(string $entity_type): EntityStorageInterface => match ($entity_type) {
        'group' => $group_storage,
        'group_relationship' => $relationship_storage,
        'group_role' => $role_storage,
        'form_schema', 'application', 'node' => $this->referenceStorage($entity_type, $referenceHits),
        default => throw new \InvalidArgumentException(sprintf('Unexpected storage "%s".', $entity_type)),
      }
    );

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(2);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->willReturn(FALSE);
    $account->method('getDisplayName')->willReturn('test-admin');

    $hierarchy = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy->method('getDescendantIds')->willReturnCallback(static fn(int $id): array => [$id]);
    $org_hierarchy = $this->createMock(OrgHierarchyResolverInterface::class);
    $org_hierarchy->method('getRootOrgId')
      ->willReturnCallback(fn(int $id): ?int => $this->rootOrgId($groups_by_id, $id));
    $org_hierarchy->method('getAncestorIds')
      ->willReturnCallback(fn(int $id): array => $this->ancestorOrgIds($groups_by_id, $id));

    return new WorkspaceOrganisationController(
      $manager,
      $account,
      $hierarchy,
      $org_hierarchy,
      new EmailValidator(),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Resolves a test org root ID through the parent chain.
   *
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups_by_id
   *   Test groups keyed by ID.
   * @param int $group_id
   *   The starting group ID.
   *
   * @return int|null
   *   The resolved root org ID, or NULL when the chain is invalid.
   */
  private function rootOrgId(array $groups_by_id, int $group_id): ?int {
    $current_id = $group_id;
    $visited = [];
    while (TRUE) {
      if (isset($visited[$current_id])) {
        return NULL;
      }
      $visited[$current_id] = TRUE;

      $current = $groups_by_id[$current_id] ?? NULL;
      if (!$current instanceof GroupInterface || $current->bundle() !== 'org') {
        return NULL;
      }

      $parent_id = $this->parentOrgId($current);
      if ($parent_id === NULL) {
        return $current_id;
      }
      $current_id = $parent_id;
    }
  }

  /**
   * Resolves test org ancestor IDs through the parent chain.
   *
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups_by_id
   *   Test groups keyed by ID.
   * @param int $group_id
   *   The starting group ID.
   *
   * @return int[]
   *   Ancestor IDs, nearest parent first.
   */
  private function ancestorOrgIds(array $groups_by_id, int $group_id): array {
    $current = $groups_by_id[$group_id] ?? NULL;
    if (!$current instanceof GroupInterface || $current->bundle() !== 'org') {
      return [];
    }

    $visited = [$group_id => TRUE];
    $ancestors = [];
    while (($parent_id = $this->parentOrgId($current)) !== NULL) {
      if (isset($visited[$parent_id])) {
        return [];
      }
      $parent = $groups_by_id[$parent_id] ?? NULL;
      if (!$parent instanceof GroupInterface || $parent->bundle() !== 'org') {
        return [];
      }
      $ancestors[] = $parent_id;
      $visited[$parent_id] = TRUE;
      $current = $parent;
    }

    return $ancestors;
  }

  /**
   * Reads the test parent org target ID.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The org group.
   *
   * @return int|null
   *   The parent org ID, or NULL when absent.
   */
  private function parentOrgId(GroupInterface $group): ?int {
    if (!$group->hasField('field_parent_org') || $group->get('field_parent_org')->isEmpty()) {
      return NULL;
    }

    $target_id = $group->get('field_parent_org')->__get('target_id');
    return $target_id === NULL ? NULL : (int) $target_id;
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
    $query->method('execute')->willReturn(array_combine($ids, $ids) ?: []);
    return $query;
  }

  /**
   * Creates an entity storage mock for reference checks.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param array<string, bool> $referenceHits
   *   Reference query hits keyed by "entity_type:field_name:target_id".
   */
  private function referenceStorage(string $entityType, array $referenceHits): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturnCallback(function () use ($entityType, $referenceHits): QueryInterface {
      $conditions = [];
      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->willReturnSelf();
      $query->method('range')->willReturnSelf();
      $query->method('condition')->willReturnCallback(
        function (string $field, mixed $value, ?string $_operator = NULL) use (&$conditions, $query): QueryInterface {
          $conditions[] = [$field, $value];
          return $query;
        }
      );
      $query->method('execute')->willReturnCallback(static function () use (&$conditions, $entityType, $referenceHits): array {
        foreach ($conditions as [$field, $value]) {
          $key = $entityType . ':' . $field . ':' . $value;
          if (!empty($referenceHits[$key])) {
            return [1 => 1];
          }
        }
        return [];
      });
      return $query;
    });
    return $storage;
  }

  /**
   * Creates a group mock with mutable field values.
   */
  private function group(
    int $id,
    string $bundle,
    string $slug,
    string $label,
    ?GroupInterface $jurisdiction = NULL,
    ?GroupInterface $parent = NULL,
  ): GroupInterface {
    $fields = [
      'status' => TRUE,
      'field_slug' => $slug,
      'field_org_email' => '',
      'field_reference_prefix' => '',
      'field_reference_pattern' => '',
      'field_parent_org' => $parent instanceof GroupInterface ? ['target_id' => $parent->id(), 'entity' => $parent] : NULL,
    ];
    if ($jurisdiction instanceof GroupInterface) {
      $fields['field_jurisdiction'] = ['target_id' => $jurisdiction->id(), 'entity' => $jurisdiction];
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('uuid')->willReturn($this->uuid($id));
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturnCallback(function () use (&$label): string {
      return $label;
    });
    $group->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $fields)
    );
    $group->method('get')->willReturnCallback(
      function (string $field_name) use (&$fields): FieldItemListInterface {
        return $this->field($fields, $field_name);
      }
    );
    $group->method('set')->willReturnCallback(
      function (string $field_name, mixed $value) use (&$fields, &$label, $group): GroupInterface {
        $this->captured[$field_name] = $value;
        if ($field_name === 'label') {
          $label = (string) $value;
        }
        else {
          $fields[$field_name] = $value;
        }
        return $group;
      }
    );

    return $group;
  }

  /**
   * Returns a copy of a group mock with a parent organisation.
   */
  private function withParent(GroupInterface $group, GroupInterface $parent): GroupInterface {
    return $this->group(
      (int) $group->id(),
      $group->bundle(),
      (string) $group->get('field_slug')->__get('value'),
      $group->label(),
      $group->get('field_jurisdiction')->__get('entity'),
      $parent,
    );
  }

  /**
   * Builds a field mock backed by a field value array.
   *
   * @param array<string, mixed> $fields
   *   Mutable field values.
   * @param string $field_name
   *   Field name to expose.
   */
  private function field(array &$fields, string $field_name): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturnCallback(
      static fn(): bool => !isset($fields[$field_name]) || $fields[$field_name] === '' || $fields[$field_name] === NULL
    );
    $field->method('__get')->willReturnCallback(
      static function (string $property) use (&$fields, $field_name): mixed {
        $value = $fields[$field_name] ?? NULL;
        if (is_array($value)) {
          return $value[$property] ?? NULL;
        }
        return match ($property) {
          'value' => $value,
          'target_id' => is_int($value) ? $value : NULL,
          default => NULL,
        };
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
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => in_array($field_name, ['gid', 'group_roles'], TRUE)
    );
    $relationship->method('get')->willReturnCallback(
      fn(string $field_name): FieldItemListInterface => match ($field_name) {
        'gid' => $this->scalarField($groupId),
        'group_roles' => $this->rolesField($roleIds),
        default => throw new \InvalidArgumentException(sprintf('Unexpected field "%s".', $field_name)),
      }
    );
    return $relationship;
  }

  /**
   * Creates a simple scalar target field mock.
   */
  private function scalarField(int|string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => match ($property) {
        'value', 'target_id' => $value,
        default => NULL,
      }
    );
    return $field;
  }

  /**
   * Creates a group roles field mock.
   *
   * @param string[] $roleIds
   *   Role IDs.
   */
  private function rolesField(array $roleIds): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn(array_map(
      static fn(string $role_id): array => ['target_id' => $role_id],
      $roleIds,
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

  /**
   * Builds a deterministic UUID for a test group ID.
   */
  private function uuid(int $id): string {
    return sprintf('00000000-0000-4000-8000-%012d', $id);
  }

}
