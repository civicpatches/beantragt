<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\beantragt_content\Controller\AdminContentController;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tests the admin content editor API controller.
 */
#[CoversClass(AdminContentController::class)]
#[Group('beantragt_content')]
final class AdminContentControllerTest extends UnitTestCase {

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
   * FormSchema storage mock.
   */
  private EntityStorageInterface $formStorage;

  /**
   * Jurisdiction hierarchy resolver mock.
   */
  private JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * Controller under test.
   */
  private AdminContentController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupRelationshipStorage = $this->createMock(EntityStorageInterface::class);
    $this->formStorage = $this->createMock(EntityStorageInterface::class);
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $this->groupStorage,
        'node' => $this->nodeStorage,
        'group_relationship' => $this->groupRelationshipStorage,
        'form_schema' => $this->formStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $membership_cache = $this->createMock(CacheBackendInterface::class);
    $membership_cache->method('get')->willReturn(FALSE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('cache.group_memberships_chained', $membership_cache);
    \Drupal::setContainer($container);

    $this->controller = new AdminContentController(
      $entity_type_manager,
      $this->mockAccount(9, ['authenticated', 'department_admin']),
      $this->hierarchyResolver,
    );
  }

  /**
   * Organisation workspaces include global and organisation-specific content.
   */
  public function testListContentReturnsWorkspaceScopedPagesAndBoilerplates(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, 'citizen-services', $jurisdiction);
    $this->mockJurisdictionLookup($jurisdiction);

    $org_conditions = [];
    $this->groupStorage->method('getQuery')->willReturn($this->queryReturning([4], $org_conditions));
    $this->groupStorage->method('load')->willReturnCallback(
      static fn (int|string $id): ?GroupInterface => (int) $id === 3 ? $jurisdiction : ((int) $id === 4 ? $organisation : NULL)
    );

    $node_conditions = [];
    $node_query = $this->queryReturning([101, 102, 103], $node_conditions);
    $node_query->method('orConditionGroup')->willReturn($this->organisationConditionGroup(4));
    $this->nodeStorage->method('getQuery')->willReturn($node_query);
    $this->nodeStorage->method('loadMultiple')->with([101, 102, 103])->willReturn([
      101 => $this->mockPage(101, 'Kontakt', 'kontakt', '<p>Kontakt raw</p>', '<p>Kontakt verarbeitet</p>', 3, NULL),
      102 => $this->mockBoilerplate(102, 'Antwortbaustein', '<p>Hallo &amp; danke</p>', '<p>Hallo &amp; danke</p>', 3, 4),
      103 => $this->mockSection(103, 'Vor dem Ausfüllen', '<p>Roh</p>', '<p>Bitte lesen.</p>', 'form_intro', 10, 3, 4),
    ]);

    $response = $this->controller->listContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content?organisation=citizen-services'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
    $this->assertSame(3, count($payload['data']));
    $this->assertSame('boilerplate', $payload['data'][0]['kind']);
    $this->assertSame('Hallo & danke', $payload['data'][0]['boilerplate']['text']);
    $this->assertSame(4, $payload['data'][0]['scope']['organisationInternalId']);
    $this->assertSame('content_section', $payload['data'][1]['kind']);
    $this->assertSame('form_intro', $payload['data'][1]['section']['placement']);
    $this->assertSame(10, $payload['data'][1]['section']['order']);
    $this->assertSame('<p>Bitte lesen.</p>', $payload['data'][1]['body']['processed']);
    $this->assertSame('page', $payload['data'][2]['kind']);
    $this->assertSame('/pages/kontakt', $payload['data'][2]['page']['path']);
    $this->assertSame('<p>Kontakt raw</p>', $payload['data'][2]['body']['value']);
    $this->assertSame('<p>Kontakt verarbeitet</p>', $payload['data'][2]['body']['processed']);
    $this->assertSame([
      ['type', 'org'],
      ['field_slug', 'citizen-services'],
      ['field_jurisdiction.target_id', 3],
    ], $org_conditions);
    $this->assertSame([
      ['type', ['page', 'boilerplate', 'content_section']],
      ['field_jurisdiction.target_id', 3],
    ], $node_conditions);
  }

  /**
   * Unknown content kinds are rejected before querying nodes.
   */
  public function testUnknownKindReturnsPrivateBadRequest(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content?kind=secret'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
    $this->assertSame('Unknown content kind.', $payload['error']['message']);
  }

  /**
   * Invalid update UUIDs fail closed without touching node queries.
   */
  public function testUpdateRejectsMalformedContentUuidBeforeNodeLookup(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->updateContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content/not-a-uuid', 'PATCH', [], [], [], [], '{}'),
      'musterstadt',
      'not-a-uuid',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
    $this->assertSame('Content not found.', $payload['error']['message']);
  }

  /**
   * Cookie-authenticated mutation routes require Drupal CSRF validation.
   */
  public function testMutatingRoutesRequireCsrfToken(): void {
    $routes = Yaml::decode((string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_content.routing.yml'));

    $this->assertSame(
      'TRUE',
      $routes['beantragt_content.admin_content_create']['requirements']['_csrf_request_header_token'] ?? NULL,
    );
    $this->assertSame(
      'TRUE',
      $routes['beantragt_content.admin_content_update']['requirements']['_csrf_request_header_token'] ?? NULL,
    );
  }

  /**
   * Oversized body content is rejected instead of being truncated silently.
   */
  public function testCreateRejectsOversizedBodyBeforeSave(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);

    $node = $this->mockMutablePage(201, 'Kontakt', 3, NULL);
    $node->expects($this->never())->method('validate');
    $node->expects($this->never())->method('save');
    $this->nodeStorage->method('create')->willReturn($node);

    $response = $this->controller->createContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content', 'POST', [], [], [], [], json_encode([
        'kind' => 'page',
        'title' => 'Kontakt',
        'body' => ['value' => str_repeat('x', 50001)],
      ], JSON_THROW_ON_ERROR)),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('Body is too large.', $payload['error']['message']);
    $this->assertSame('body', $payload['error']['field']);
  }

  /**
   * Entity validation violations are returned before saving.
   */
  public function testUpdateReturnsValidationViolationBeforeSave(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);

    $node = $this->mockMutablePage(201, 'Kontakt', 3, NULL);
    $node->method('validate')->willReturn(new ConstraintViolationList([
      new ConstraintViolation('Slug already exists.', NULL, [], NULL, 'field_public_slug', 'kontakt'),
    ]));
    $node->expects($this->never())->method('save');

    $conditions = [];
    $this->nodeStorage->method('getQuery')->willReturn($this->queryReturning([201], $conditions));
    $this->nodeStorage->method('load')->with('201')->willReturn($node);

    $response = $this->controller->updateContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content/8f4f4f2a-e062-47a6-b8b1-4c4aa7db0201', 'PATCH', [], [], [], [], json_encode([
        'title' => 'Kontakt',
        'body' => ['value' => '<p>Kontakt</p>'],
      ], JSON_THROW_ON_ERROR)),
      'musterstadt',
      '8f4f4f2a-e062-47a6-b8b1-4c4aa7db0201',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('Slug already exists.', $payload['error']['message']);
    $this->assertSame('field_public_slug', $payload['error']['field']);
    $this->assertContains(['uuid', '8f4f4f2a-e062-47a6-b8b1-4c4aa7db0201'], $conditions);
  }

  /**
   * Content-section creation stores placement, form binding and order.
   */
  public function testCreateContentSectionStoresPlacementFormAndOrder(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, 'citizen-services', $jurisdiction);
    $form = $this->mockForm(
      55,
      '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
      'Bauantrag',
      $jurisdiction,
      $organisation,
    );
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->willReturnCallback(
      static fn (int|string $id): ?GroupInterface => (int) $id === 3 ? $jurisdiction : ((int) $id === 4 ? $organisation : NULL)
    );
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17'])
      ->willReturn([$form]);

    $node = $this->mockMutableSection(301, 'Vor dem Ausfüllen', 3, 4, $form);
    $node->method('validate')->willReturn(new ConstraintViolationList());
    $node->expects($this->once())->method('save');
    $this->nodeStorage->method('create')->willReturn($node);

    $response = $this->controller->createContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content', 'POST', [], [], [], [], json_encode([
        'kind' => 'section',
        'title' => 'Vor dem Ausfüllen',
        'status' => TRUE,
        'organisation' => 4,
        'body' => ['value' => '<p>Bitte lesen.</p>'],
        'placement' => 'form_sidebar',
        'formId' => '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
        'order' => 30,
      ], JSON_THROW_ON_ERROR)),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $this->assertSame('content_section', $payload['data']['kind']);
    $this->assertSame('form_sidebar', $payload['data']['section']['placement']);
    $this->assertSame(30, $payload['data']['section']['order']);
    $this->assertSame('4ebef1d0-c8d4-49fa-9957-0338b8fcbe17', $payload['data']['section']['form']['id']);
    $this->assertSame(4, $payload['data']['scope']['organisationInternalId']);
  }

  /**
   * Retired footer placements fail before entity validation.
   */
  public function testCreateContentSectionRejectsFooterBeforeSave(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->with(3)->willReturn($jurisdiction);

    $node = $this->mockMutableSection(301, 'Footer', 3, NULL);
    $node->expects($this->never())->method('validate');
    $node->expects($this->never())->method('save');
    $this->nodeStorage->method('create')->willReturn($node);

    $response = $this->controller->createContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content', 'POST', [], [], [], [], json_encode([
        'kind' => 'section',
        'title' => 'Footer',
        'placement' => 'footer',
      ], JSON_THROW_ON_ERROR)),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('Unknown placement.', $payload['error']['message']);
    $this->assertSame('placement', $payload['error']['field']);
  }

  /**
   * Form bindings must belong to the selected organisation scope.
   */
  public function testCreateContentSectionRejectsFormOutsideOrganisationScope(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, 'citizen-services', $jurisdiction);
    $other_organisation = $this->mockGroup('org', 5, 'building-office', $jurisdiction);
    $form = $this->mockForm(
      55,
      '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
      'Bauantrag',
      $jurisdiction,
      $other_organisation,
    );
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->willReturnCallback(
      static fn (int|string $id): ?GroupInterface => match ((int) $id) {
        3 => $jurisdiction,
        4 => $organisation,
        5 => $other_organisation,
        default => NULL,
      }
    );
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17'])
      ->willReturn([$form]);

    $node = $this->mockMutableSection(301, 'Vor dem Ausfüllen', 3, 4);
    $node->expects($this->never())->method('validate');
    $node->expects($this->never())->method('save');
    $this->nodeStorage->method('create')->willReturn($node);

    $response = $this->controller->createContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content', 'POST', [], [], [], [], json_encode([
        'kind' => 'section',
        'title' => 'Vor dem Ausfüllen',
        'organisation' => 4,
        'placement' => 'form_intro',
        'formId' => '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
      ], JSON_THROW_ON_ERROR)),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertSame('Form not found.', $payload['error']['message']);
    $this->assertSame('formId', $payload['error']['field']);
  }

  /**
   * Existing content sections can clear a form binding.
   */
  public function testUpdateContentSectionCanClearFormBinding(): void {
    $jurisdiction = $this->mockGroup('jur', 3, 'musterstadt');
    $organisation = $this->mockGroup('org', 4, 'citizen-services', $jurisdiction);
    $form = $this->mockForm(
      55,
      '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
      'Bauantrag',
      $jurisdiction,
      $organisation,
    );
    $this->mockJurisdictionLookup($jurisdiction);
    $this->groupStorage->method('load')->willReturnCallback(
      static fn (int|string $id): ?GroupInterface => (int) $id === 3 ? $jurisdiction : ((int) $id === 4 ? $organisation : NULL)
    );

    $node = $this->mockMutableSection(301, 'Vor dem Ausfüllen', 3, 4, $form);
    $node->method('validate')->willReturn(new ConstraintViolationList());
    $node->expects($this->once())->method('save');

    $conditions = [];
    $this->nodeStorage->method('getQuery')->willReturn($this->queryReturning([301], $conditions));
    $this->nodeStorage->method('load')->with('301')->willReturn($node);

    $response = $this->controller->updateContent(
      Request::create('/api/admin/jurisdictions/musterstadt/content/8f4f4f2a-e062-47a6-b8b1-4c4aa7db0301', 'PATCH', [], [], [], [], json_encode([
        'kind' => 'section',
        'formId' => NULL,
      ], JSON_THROW_ON_ERROR)),
      'musterstadt',
      '8f4f4f2a-e062-47a6-b8b1-4c4aa7db0301',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertNull($payload['data']['section']['form']);
    $this->assertContains(['uuid', '8f4f4f2a-e062-47a6-b8b1-4c4aa7db0301'], $conditions);
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
   * Department admins outside the managed hierarchy fail closed.
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
   * Builds a query mock that records simple condition calls.
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
   * Builds the OR condition group for global plus org-specific content.
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
    $group->method('label')->willReturn($bundle === 'jur' ? 'Stadt Musterstadt' : 'Bürgerbüro');
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $group;
  }

  /**
   * Builds a page node mock.
   */
  private function mockPage(int $id, string $title, string $slug, string $value, string $processed, int $jurisdiction_id, ?int $organisation_id): NodeInterface {
    $fields = [
      'body' => $this->bodyField($value, $processed, 'basic_html'),
      'field_jurisdiction' => $this->fieldTarget($jurisdiction_id),
      'field_organisation' => $organisation_id ? $this->fieldTarget($organisation_id) : $this->emptyField(),
      'field_page_key' => $this->fieldValue('content'),
      'field_public_slug' => $this->fieldValue($slug),
      'field_footer_visible' => $this->fieldValue(TRUE),
      'field_page_weight' => $this->fieldValue(20),
    ];

    return $this->mockNode($id, 'page', $title, $fields);
  }

  /**
   * Builds a boilerplate node mock.
   */
  private function mockBoilerplate(int $id, string $title, string $value, string $processed, int $jurisdiction_id, ?int $organisation_id): NodeInterface {
    $fields = [
      'body' => $this->bodyField($value, $processed, 'basic_html'),
      'field_jurisdiction' => $this->fieldTarget($jurisdiction_id),
      'field_organisation' => $organisation_id ? $this->fieldTarget($organisation_id) : $this->emptyField(),
      'field_boilerplate_type' => $this->fieldValue('status_notes'),
    ];

    return $this->mockNode($id, 'boilerplate', $title, $fields);
  }

  /**
   * Builds a content-section node mock.
   */
  private function mockSection(int $id, string $title, string $value, string $processed, string $placement, int $weight, int $jurisdiction_id, ?int $organisation_id): NodeInterface {
    $fields = [
      'body' => $this->bodyField($value, $processed, 'basic_html'),
      'field_jurisdiction' => $this->fieldTarget($jurisdiction_id),
      'field_organisation' => $organisation_id ? $this->fieldTarget($organisation_id) : $this->emptyField(),
      'field_content_placement' => $this->fieldValue($placement),
      'field_content_weight' => $this->fieldValue($weight),
      'field_form_schema' => $this->emptyField(),
    ];

    return $this->mockNode($id, 'content_section', $title, $fields);
  }

  /**
   * Builds a node mock.
   *
   * @param int $id
   *   The node ID.
   * @param string $bundle
   *   The node bundle.
   * @param string $title
   *   The node title.
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $fields
   *   Field list mocks keyed by field name.
   */
  private function mockNode(int $id, string $bundle, string $title, array $fields): NodeInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn('8f4f4f2a-e062-47a6-b8b1-4c4aa7db' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
    $node->method('bundle')->willReturn($bundle);
    $node->method('label')->willReturn($title);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('language')->willReturn($language);
    $node->method('getChangedTime')->willReturn(1781953200);
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $node->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $node;
  }

  /**
   * Builds a page node mock suitable for create/update mutation paths.
   */
  private function mockMutablePage(int $id, string $title, int $jurisdiction_id, ?int $organisation_id): NodeInterface {
    $fields = [
      'body' => $this->bodyField('', '', 'basic_html'),
      'field_jurisdiction' => $this->fieldTarget($jurisdiction_id),
      'field_organisation' => $organisation_id ? $this->fieldTarget($organisation_id) : $this->emptyField(),
      'field_page_key' => $this->fieldValue('content'),
      'field_public_slug' => $this->fieldValue('kontakt'),
      'field_footer_visible' => $this->fieldValue(TRUE),
      'field_page_weight' => $this->fieldValue(20),
    ];

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn('8f4f4f2a-e062-47a6-b8b1-4c4aa7db' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
    $node->method('bundle')->willReturn('page');
    $node->method('label')->willReturn($title);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('language')->willReturn($language);
    $node->method('getChangedTime')->willReturn(1781953200);
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $node->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);
    $node->method('set')->willReturnSelf();
    $node->method('setTitle')->willReturnSelf();
    $node->method('setPublished')->willReturnSelf();
    $node->method('setUnpublished')->willReturnSelf();
    $node->method('setNewRevision')->willReturnSelf();
    $node->method('setRevisionLogMessage')->willReturnSelf();

    return $node;
  }

  /**
   * Builds a content-section node mock suitable for mutation paths.
   */
  private function mockMutableSection(int $id, string $title, int $jurisdiction_id, ?int $organisation_id, ?FormSchemaInterface $form = NULL): NodeInterface {
    $state = [
      'title' => $title,
      'published' => TRUE,
      'body' => [
        'value' => '',
        'processed' => '',
        'format' => 'basic_html',
      ],
      'field_jurisdiction' => ['target_id' => $jurisdiction_id],
      'field_organisation' => $organisation_id ? ['target_id' => $organisation_id] : [],
      'field_content_placement' => 'form_intro',
      'field_content_weight' => 10,
      'field_form_schema' => $form instanceof FormSchemaInterface ? [
        'target_id' => (int) $form->id(),
        'entity' => $form,
      ] : [],
    ];

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn('8f4f4f2a-e062-47a6-b8b1-4c4aa7db' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
    $node->method('bundle')->willReturn('content_section');
    $node->method('label')->willReturnCallback(static function () use (&$state): string {
      return (string) $state['title'];
    });
    $node->method('isPublished')->willReturnCallback(static function () use (&$state): bool {
      return (bool) $state['published'];
    });
    $node->method('language')->willReturn($language);
    $node->method('getChangedTime')->willReturn(1781953200);
    $node->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, [
        'body',
        'field_jurisdiction',
        'field_organisation',
        'field_content_placement',
        'field_content_weight',
        'field_form_schema',
      ], TRUE)
    );
    $node->method('get')->willReturnCallback(function (string $field) use (&$state): FieldItemListInterface {
      return match ($field) {
        'body' => $this->bodyField(
          (string) $state['body']['value'],
          (string) ($state['body']['processed'] ?: $state['body']['value']),
          (string) $state['body']['format'],
        ),
        'field_jurisdiction' => $this->fieldTarget((int) $state['field_jurisdiction']['target_id']),
        'field_organisation' => isset($state['field_organisation']['target_id'])
          ? $this->fieldTarget((int) $state['field_organisation']['target_id'])
          : $this->emptyField(),
        'field_content_placement' => $this->fieldValue($state['field_content_placement']),
        'field_content_weight' => $this->fieldValue($state['field_content_weight']),
        'field_form_schema' => isset($state['field_form_schema']['target_id'], $state['field_form_schema']['entity'])
          ? $this->entityReference((int) $state['field_form_schema']['target_id'], $state['field_form_schema']['entity'])
          : $this->emptyField(),
        default => $this->emptyField(),
      };
    });
    $node->method('set')->willReturnCallback(static function (string $field, mixed $value) use (&$state, $node, $form): NodeInterface {
      if ($field === 'body' && is_array($value)) {
        $state['body'] = [
          'value' => (string) ($value['value'] ?? ''),
          'processed' => (string) ($value['value'] ?? ''),
          'format' => (string) ($value['format'] ?? 'basic_html'),
        ];
      }
      elseif ($field === 'field_jurisdiction' && is_array($value)) {
        $state['field_jurisdiction'] = ['target_id' => (int) ($value['target_id'] ?? 0)];
      }
      elseif ($field === 'field_organisation') {
        $state['field_organisation'] = is_array($value) && isset($value['target_id'])
          ? ['target_id' => (int) $value['target_id']]
          : [];
      }
      elseif ($field === 'field_form_schema') {
        $state['field_form_schema'] = is_array($value) && isset($value['target_id']) && $form instanceof FormSchemaInterface
          ? ['target_id' => (int) $value['target_id'], 'entity' => $form]
          : [];
      }
      elseif ($field === 'field_content_placement') {
        $state['field_content_placement'] = (string) $value;
      }
      elseif ($field === 'field_content_weight') {
        $state['field_content_weight'] = (int) $value;
      }
      return $node;
    });
    $node->method('setTitle')->willReturnCallback(static function (string $new_title) use (&$state, $node): NodeInterface {
      $state['title'] = $new_title;
      return $node;
    });
    $node->method('setPublished')->willReturnCallback(static function () use (&$state, $node): NodeInterface {
      $state['published'] = TRUE;
      return $node;
    });
    $node->method('setUnpublished')->willReturnCallback(static function () use (&$state, $node): NodeInterface {
      $state['published'] = FALSE;
      return $node;
    });
    $node->method('setNewRevision')->willReturnSelf();
    $node->method('setRevisionLogMessage')->willReturnSelf();

    return $node;
  }

  /**
   * Builds a form schema mock with public scope references.
   */
  private function mockForm(int $id, string $uuid, string $label, GroupInterface $jurisdiction, ?GroupInterface $organisation): FormSchemaInterface {
    $fields = [
      'field_jurisdiction' => $this->entityReference((int) $jurisdiction->id(), $jurisdiction),
      'field_organisation' => $organisation instanceof GroupInterface ? $this->entityReference((int) $organisation->id(), $organisation) : $this->emptyField(),
      'field_group' => $this->emptyField(),
    ];

    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn($id);
    $form->method('uuid')->willReturn($uuid);
    $form->method('label')->willReturn($label);
    $form->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $form->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => $fields[$field] ?? $this->emptyField());
    return $form;
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
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '' || $value === NULL);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => $property === 'value',
    );
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
   * Builds an entity-reference field with target ID and loaded entity.
   */
  private function entityReference(int $target_id, object $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => in_array($property, ['target_id', 'entity'], TRUE),
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => match ($property) {
        'target_id' => $target_id,
        'entity' => $entity,
        default => NULL,
      },
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
