<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Controller;

use Drupal\beantragt_group\Controller\WorkspaceSettingsController;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 * Tests workspace identity and org metadata settings.
 */
#[CoversClass(WorkspaceSettingsController::class)]
#[Group('beantragt_group')]
final class WorkspaceSettingsControllerTest extends UnitTestCase {

  /**
   * Test group storage.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Captured set() calls.
   *
   * @var array<string, mixed>
   */
  private array $captured = [];

  /**
   * Patching an organisation saves slug and reference metadata.
   */
  public function testPatchOrganisationMetadataPersistsFields(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->once())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=citizen-services',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=citizen-services'],
      content: (string) json_encode([
        'data' => [
          'label' => 'Buergerbuero Digital',
          'slug' => 'buergerbuero',
          'orgEmail' => 'bb@musterstadt.example.org',
          'referencePrefix' => 'MS-BB',
          'referencePattern' => '{ORG}-{YEAR}-{SEQ:5}',
        ],
      ])
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('Buergerbuero Digital', $this->captured['label']);
    $this->assertSame('buergerbuero', $this->captured['field_slug']);
    $this->assertSame('bb@musterstadt.example.org', $this->captured['field_org_email']);
    $this->assertSame('MS-BB', $this->captured['field_reference_prefix']);
    $this->assertSame('{ORG}-{YEAR}-{SEQ:5}', $this->captured['field_reference_pattern']);
    $this->assertSame('musterstadt/buergerbuero', $payload['workspace']['key']);
  }

  /**
   * Numeric organisation slugs are valid workspace slugs.
   */
  public function testPatchAcceptsNumericOrganisationSlug(): void {
    $jurisdiction = $this->group(3, 'jur', 'dunkelstadt', 'Dunkelstadt');
    $organisation = $this->group(7, 'org', '01', 'Amt 01', $jurisdiction);
    $organisation->expects($this->once())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=dunkelstadt&organisation=01',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=dunkelstadt&organisation=01'],
      content: '{"data":{"label":"Amt 01 Digital"}}'
    ));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('Amt 01 Digital', $this->captured['label']);
  }

  /**
   * Malformed organisation query values must not fall back to the jurisdiction.
   */
  public function testPatchRejectsMalformedOrganisationQuery(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $jurisdiction->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=bad%20slug',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=bad%20slug'],
      content: '{"data":{"label":"Should not hit jurisdiction"}}'
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('organisation', $payload['error']['field']);
  }

  /**
   * Organisation slugs must be unique inside the parent jurisdiction.
   */
  public function testPatchRejectsDuplicateOrganisationSlugInsideJurisdiction(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $duplicate = $this->group(8, 'org', 'services', 'Service', $jurisdiction);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation, $duplicate]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=citizen-services',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=citizen-services'],
      content: '{"data":{"slug":"services"}}'
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('slug', $payload['error']['field']);
  }

  /**
   * Workspace slugs cannot shadow application route segments.
   */
  public function testPatchRejectsReservedSlug(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=citizen-services',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=citizen-services'],
      content: '{"data":{"slug":"admin"}}'
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('slug', $payload['error']['field']);
  }

  /**
   * Unknown reference tokens are rejected before persistence.
   */
  public function testPatchRejectsUnsupportedReferencePatternTokens(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->never())->method('save');
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=citizen-services',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=citizen-services'],
      content: '{"data":{"referencePattern":"{ORG}-{YEAR}-{SEQ_PADDED}"}}'
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('referencePattern', $payload['error']['field']);
  }

  /**
   * Storage failures are shaped as JSON errors.
   */
  public function testPatchReturnsJsonErrorWhenSaveFails(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $organisation->expects($this->once())->method('save')->willThrowException(new \RuntimeException('storage down'));
    $controller = $this->controller([$jurisdiction, $organisation]);

    $response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=citizen-services',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=citizen-services'],
      content: '{"data":{"label":"Buergerbuero Digital"}}'
    ));
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    $this->assertSame('Workspace settings could not be saved.', $payload['error']['message']);
  }

  /**
   * Department admins can edit only their own organisation.
   */
  public function testDepartmentAdminCanPatchOwnOrganisationOnly(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $own_organisation = $this->group(7, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $sibling_organisation = $this->group(8, 'org', 'building-office', 'Bauamt', $jurisdiction);
    $own_organisation->expects($this->once())->method('save');
    $sibling_organisation->expects($this->never())->method('save');
    $controller = $this->controller(
      [$jurisdiction, $own_organisation, $sibling_organisation],
      ['authenticated', 'department_admin'],
      [$this->membership(7, ['org-admin'])],
      ['org-admin' => TRUE],
    );

    $own_response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=citizen-services',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=citizen-services'],
      content: '{"data":{"label":"Buergerbuero Digital"}}'
    ));
    $sibling_response = $controller->patchWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt&organisation=building-office',
      'PATCH',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt&organisation=building-office'],
      content: '{"data":{"label":"Bauamt Digital"}}'
    ));

    $this->assertSame(Response::HTTP_OK, $own_response->getStatusCode());
    $this->assertSame(Response::HTTP_FORBIDDEN, $sibling_response->getStatusCode());
  }

  /**
   * Non-staff users cannot read workspace settings.
   */
  public function testNonStaffAccountIsRejected(): void {
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Musterstadt');
    $controller = $this->controller([$jurisdiction], ['authenticated']);

    $response = $controller->getWorkspaceSettings(Request::create(
      '/api/admin/workspace-settings?jurisdiction=musterstadt',
      'GET',
      server: ['QUERY_STRING' => 'jurisdiction=musterstadt']
    ));

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Creates a controller with test group storage.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Groups keyed by ID.
   * @param string[] $roles
   *   Current user roles.
   * @param \Drupal\group\Entity\GroupRelationshipInterface[] $memberships
   *   Current-user membership relationships.
   * @param array<string, bool> $groupRoles
   *   Group role admin flags keyed by role id.
   */
  private function controller(
    array $groups,
    array $roles = ['authenticated', 'administrator'],
    array $memberships = [],
    array $groupRoles = [],
  ): WorkspaceSettingsController {
    $this->captured = [];
    $groups_by_id = [];
    foreach ($groups as $group) {
      $groups_by_id[(int) $group->id()] = $group;
    }

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static function (array $properties) use ($groups): array {
        return array_filter($groups, static function (GroupInterface $group) use ($properties): bool {
          if (isset($properties['type']) && $group->bundle() !== $properties['type']) {
            return FALSE;
          }
          if (isset($properties['field_slug']) && $group->get('field_slug')->__get('value') !== $properties['field_slug']) {
            return FALSE;
          }
          return TRUE;
        });
      }
    );
    $this->groupStorage->method('load')->willReturnCallback(
      static fn(int|string $id): ?GroupInterface => $groups_by_id[(int) $id] ?? NULL
    );

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
        'group' => $this->groupStorage,
        'group_relationship' => $relationship_storage,
        'group_role' => $role_storage,
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

    return new WorkspaceSettingsController(
      $manager,
      $account,
      $hierarchy,
      new EmailValidator(),
      $this->createMock(LoggerInterface::class),
    );
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
   * Creates a group mock with mutable field values.
   */
  private function group(
    int $id,
    string $bundle,
    string $slug,
    string $label,
    ?GroupInterface $jurisdiction = NULL,
  ): GroupInterface {
    $fields = [
      'field_slug' => $slug,
      'field_org_email' => '',
      'field_reference_prefix' => '',
      'field_reference_pattern' => '',
    ];
    if ($jurisdiction instanceof GroupInterface) {
      $fields['field_jurisdiction'] = ['target_id' => $jurisdiction->id(), 'entity' => $jurisdiction];
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('uuid')->willReturn('uuid-' . $id);
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
      static fn(): bool => !isset($fields[$field_name]) || $fields[$field_name] === ''
    );
    $field->method('__get')->willReturnCallback(
      static function (string $property) use (&$fields, $field_name): mixed {
        $value = $fields[$field_name] ?? NULL;
        if (is_array($value)) {
          return $value[$property] ?? NULL;
        }
        return $property === 'value' ? $value : NULL;
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

}
