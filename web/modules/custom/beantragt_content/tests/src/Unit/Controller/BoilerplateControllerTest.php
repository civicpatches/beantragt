<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_content\Controller\BoilerplateController;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the admin boilerplate API controller.
 */
#[CoversClass(BoilerplateController::class)]
#[Group('beantragt_content')]
final class BoilerplateControllerTest extends UnitTestCase {

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Node storage mock.
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * Group relationship storage mock.
   */
  private EntityStorageInterface $groupRelationshipStorage;

  /**
   * Jurisdiction hierarchy resolver mock.
   */
  private JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Controller under test.
   */
  private BoilerplateController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupRelationshipStorage = $this->createMock(EntityStorageInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $this->groupStorage,
        'node' => $this->nodeStorage,
        'group_relationship' => $this->groupRelationshipStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $membership_cache = $this->createMock(CacheBackendInterface::class);
    $membership_cache->method('get')->willReturn(FALSE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('cache.group_memberships_chained', $membership_cache);
    \Drupal::setContainer($container);

    $this->controller = new BoilerplateController(
      $entity_type_manager,
      $this->mockAccount(9, ['authenticated', 'department_admin']),
      $this->hierarchyResolver,
      new ApplicationCaseScope($entity_type_manager),
    );
  }

  /**
   * Organisation requests include global and org-scoped boilerplates.
   */
  public function testListBoilerplatesReturnsOrgScopedTemplatesWithPrivateHeaders(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, 'mayors-office', $jurisdiction);
    $this->mockJurisdictionLookup($jurisdiction);

    $org_conditions = [];
    $this->groupStorage->method('getQuery')->willReturn($this->queryReturning([4], $org_conditions));
    $this->groupStorage->method('load')->willReturnCallback(
      static fn (int|string $id): ?GroupInterface => (int) $id === 3 ? $jurisdiction : ((int) $id === 4 ? $organisation : NULL)
    );

    $node_conditions = [];
    $node_query = $this->queryReturning([101, 102], $node_conditions);
    $node_query->method('orConditionGroup')->willReturn($this->organisationConditionGroup(4));
    $this->nodeStorage->method('getQuery')->willReturn($node_query);
    $this->nodeStorage->method('loadMultiple')->with([101, 102])->willReturn([
      101 => $this->mockBoilerplate(101, 'Global reply', '<p>Global &amp; text</p>', '<p>Global &amp; text</p>'),
      102 => $this->mockBoilerplate(102, 'OB reply', '<p>OB raw</p>', '<p>OB processed</p>'),
    ]);

    $response = $this->controller->listBoilerplates(
      Request::create('/api/admin/jurisdictions/musterstadt/boilerplates?organisation=mayors-office'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
    $this->assertSame(2, count($payload['data']));
    $this->assertSame('Global reply', $payload['data'][0]['title']);
    $this->assertSame('<p>Global &amp; text</p>', $payload['data'][0]['body']['value']);
    $this->assertSame('Global & text', $payload['data'][0]['text']);
    $this->assertSame('OB processed', $payload['data'][1]['text']);
    $this->assertContains(['field_jurisdiction.target_id', 3], $org_conditions);
    $this->assertSame([
      ['type', 'boilerplate'],
      ['status', 1],
      ['field_boilerplate_type', 'status_notes'],
      ['field_jurisdiction.target_id', 3],
    ], $node_conditions);
  }

  /**
   * Requests without an organisation only return global boilerplates.
   */
  public function testListBoilerplatesWithoutOrganisationFiltersToGlobalTemplates(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);

    $node_conditions = [];
    $node_query = $this->queryReturning([101], $node_conditions);
    $node_query->expects($this->once())->method('notExists')->with('field_organisation')->willReturnSelf();
    $this->nodeStorage->method('getQuery')->willReturn($node_query);
    $this->nodeStorage->method('loadMultiple')->with([101])->willReturn([
      101 => $this->mockBoilerplate(101, 'Global reply', '<p>Global</p>', '<p>Global</p>'),
    ]);

    $response = $this->controller->listBoilerplates(
      Request::create('/api/admin/jurisdictions/musterstadt/boilerplates'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
    $this->assertSame(1, count($payload['data']));
    $this->assertSame('Global reply', $payload['data'][0]['title']);
    $this->assertSame([
      ['type', 'boilerplate'],
      ['status', 1],
      ['field_boilerplate_type', 'status_notes'],
      ['field_jurisdiction.target_id', 3],
    ], $node_conditions);
  }

  /**
   * Unknown boilerplate types are rejected before querying nodes.
   */
  public function testUnknownTypeReturnsPrivateBadRequest(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listBoilerplates(
      Request::create('/api/admin/jurisdictions/musterstadt/boilerplates?type=unknown'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
    $this->assertSame('Unknown boilerplate type.', $payload['error']['message']);
  }

  /**
   * Administrator role grants access after jurisdiction resolution.
   */
  public function testAccessCheckAllowsAdministrator(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $result = $this->controller->accessCheck(
      $this->mockAccount(44, ['authenticated', 'administrator']),
      'musterstadt',
    );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Department admins can access descendant jurisdictions they administer.
   */
  public function testAccessCheckAllowsDepartmentAdminForManagedDescendant(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $managed = $this->mockGroup('jur', 2, 'regionalverband');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->mockMemberships([
      $this->mockMembership($managed, [$this->mockRole('jur-admin', TRUE)]),
    ]);
    $this->hierarchyResolver->method('getDescendantIds')->with(2)->willReturn([2, 3, 4]);

    $result = $this->controller->accessCheck(
      $this->mockAccount(44, ['authenticated', 'department_admin']),
      'musterstadt',
    );

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Case workers cannot access management endpoints with group-admin roles.
   */
  public function testAccessCheckRejectsCaseWorkerWithAdminMembership(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $managed = $this->mockGroup('jur', 2, 'regionalverband');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->mockMemberships([
      $this->mockMembership($managed, [$this->mockRole('jur-admin', TRUE)]),
    ]);
    $this->hierarchyResolver->method('getDescendantIds')->with(2)->willReturn([2, 3, 4]);

    $result = $this->controller->accessCheck(
      $this->mockAccount(44, ['authenticated', 'case_worker']),
      'musterstadt',
    );

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Department admins without group-admin membership fail closed.
   */
  public function testAccessCheckRejectsDepartmentAdminWithoutAdminMembership(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $managed = $this->mockGroup('jur', 2, 'regionalverband');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->mockMemberships([
      $this->mockMembership($managed, [$this->mockRole('jur-member', FALSE)]),
    ]);
    $this->hierarchyResolver->expects($this->never())->method('getDescendantIds');

    $result = $this->controller->accessCheck(
      $this->mockAccount(44, ['authenticated', 'department_admin']),
      'musterstadt',
    );

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Department admins cannot access jurisdictions outside their descendants.
   */
  public function testAccessCheckRejectsDepartmentAdminOutsideManagedScope(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $managed = $this->mockGroup('jur', 2, 'regionalverband');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->mockMemberships([
      $this->mockMembership($managed, [$this->mockRole('jur-admin', TRUE)]),
    ]);
    $this->hierarchyResolver->method('getDescendantIds')->with(2)->willReturn([2, 4]);

    $result = $this->controller->accessCheck(
      $this->mockAccount(44, ['authenticated', 'department_admin']),
      'musterstadt',
    );

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Unknown jurisdictions fail closed in access checks.
   */
  public function testAccessCheckForUnknownJurisdictionIsForbidden(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);

    $result = $this->controller->accessCheck(
      $this->mockAccount(44, ['authenticated', 'administrator']),
      'missing',
    );

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Wires the jurisdiction slug resolver.
   */
  private function mockJurisdictionLookup(GroupInterface $jurisdiction): void {
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn (array $properties): array => $properties === [
        'type' => 'jur',
        'field_slug' => 'musterstadt',
        'status' => 1,
      ] ? [$jurisdiction] : []
    );
  }

  /**
   * Builds a query mock that records condition calls.
   *
   * @param int[] $ids
   *   Entity IDs returned by execute().
   * @param array<int, array{0: string, 1: mixed}> $conditions
   *   Captured simple condition calls.
   */
  private function queryReturning(array $ids, array &$conditions): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$args) use (&$conditions, $query): QueryInterface {
        if (isset($args[0], $args[1]) && is_string($args[0])) {
          $conditions[] = [$args[0], $args[1]];
        }
        return $query;
      }
    );
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Builds the OR condition group for global plus org-specific templates.
   */
  private function organisationConditionGroup(int $organisation_id): ConditionInterface {
    $condition = $this->createMock(ConditionInterface::class);
    $condition->expects($this->once())->method('notExists')->with('field_organisation')->willReturnSelf();
    $condition->expects($this->once())->method('condition')->with('field_organisation.target_id', $organisation_id)->willReturnSelf();
    return $condition;
  }

  /**
   * Wires static GroupMembership::loadByUser() for access tests.
   *
   * @param \Drupal\group\Entity\GroupMembershipInterface[] $memberships
   *   Memberships returned for the current account.
   */
  private function mockMemberships(array $memberships): void {
    $conditions = [];
    $query = $this->queryReturning(array_keys($memberships), $conditions);
    $this->groupRelationshipStorage->method('getQuery')->willReturn($query);
    $this->groupRelationshipStorage->method('loadMultiple')->with(array_keys($memberships))->willReturn($memberships);
  }

  /**
   * Builds a group membership mock.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Group returned by the membership.
   * @param \Drupal\group\Entity\GroupRoleInterface[] $roles
   *   Roles returned by the membership.
   */
  private function mockMembership(GroupInterface $group, array $roles): GroupMembershipInterface {
    $membership = $this->createMock(GroupMembershipInterface::class);
    $membership->method('getGroup')->willReturn($group);
    $membership->method('getRoles')->with(FALSE)->willReturn($roles);
    return $membership;
  }

  /**
   * Builds a group role mock.
   */
  private function mockRole(string $id, bool $admin): GroupRoleInterface {
    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('id')->willReturn($id);
    $role->method('isAdmin')->willReturn($admin);
    return $role;
  }

  /**
   * Builds a group mock.
   */
  private function mockGroup(string $bundle, int $id, string $slug, ?GroupInterface $jurisdiction = NULL): GroupInterface {
    $fields = [
      'field_slug' => $this->fieldValue($slug),
      'field_jurisdiction' => $jurisdiction instanceof GroupInterface ? $this->fieldTarget((int) $jurisdiction->id()) : $this->emptyField(),
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($bundle === 'jur' ? 'Stadt Musterstadt' : 'OB-Amt');
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $group;
  }

  /**
   * Builds a boilerplate node mock.
   */
  private function mockBoilerplate(int $id, string $title, string $value, string $processed): NodeInterface {
    $fields = [
      'body' => $this->bodyField($value, $processed, 'basic_html'),
      'field_boilerplate_type' => $this->fieldValue('status_notes'),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn('9f4f4f2a-e062-47a6-b8b1-4c4aa7db' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
    $node->method('label')->willReturn($title);
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $node->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $node;
  }

  /**
   * Builds a text body field list.
   */
  private function bodyField(string $value, string $processed, string $format): FieldItemListInterface {
    $item = new \stdClass();
    $item->value = $value;
    $item->processed = $processed;
    $item->format = $format;

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '' && $processed === '');
    $field->method('first')->willReturn($item);
    return $field;
  }

  /**
   * Builds a scalar field list.
   */
  private function fieldValue(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an entity-reference target field list.
   */
  private function fieldTarget(int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => $property === 'target_id',
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'target_id' ? $target_id : NULL,
    );
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

  /**
   * Builds an account mock.
   *
   * @param int $id
   *   User ID.
   * @param string[] $roles
   *   Role IDs.
   */
  private function mockAccount(int $id, array $roles): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('getRoles')->willReturn($roles);
    return $account;
  }

  /**
   * Asserts private no-store response headers.
   */
  private function assertPrivateNoStore(Response $response): void {
    $cache_control = (string) $response->headers->get('Cache-Control');
    $this->assertStringContainsString('private', $cache_control);
    $this->assertStringContainsString('no-store', $cache_control);
    $this->assertSame('no-cache', $response->headers->get('Pragma'));
  }

}
