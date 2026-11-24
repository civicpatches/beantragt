<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\ApplicationAccessControlHandler;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the FormApplication access control handler.
 *
 * @coversDefaultClass \Drupal\beantragt_application\ApplicationAccessControlHandler
 * @group beantragt_application
 */
class ApplicationAccessControlHandlerTest extends UnitTestCase {

  /**
   * The access control handler under test.
   *
   * @var \Drupal\beantragt_application\ApplicationAccessControlHandler
   */
  protected ApplicationAccessControlHandler $handler;

  /**
   * The mocked entity type.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeInterface $entityType;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Entity storages keyed by entity type ID.
   *
   * @var array<string, \Drupal\Core\Entity\EntityStorageInterface>
   */
  private array $entityStorages = [];

  /**
   * Whether the test lookup confirms the account address.
   */
  private bool $mailIsVerified = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('invokeAll')->willReturn([]);
    $container->set('module_handler', $moduleHandler);

    $container->set('beantragt_passwordless.verified_mail', $this->verifiedMail);

    \Drupal::setContainer($container);

    $this->entityType = $this->createMock(EntityTypeInterface::class);
    $this->entityType->method('id')->willReturn('application');
    $this->entityType->method('getAdminPermission')->willReturn('administer application entities');

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('hasDefinition')->willReturnCallback(
      fn(string $entity_type_id): bool => isset($this->entityStorages[$entity_type_id])
    );
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn(string $entity_type_id): EntityStorageInterface => $this->entityStorages[$entity_type_id]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected storage %s.', $entity_type_id))
    );

    $mailbox = $this->getMockBuilder(ConfirmedApplicationMailbox::class)
      ->setConstructorArgs([
        $this->createMock(Connection::class),
        $this->entityTypeManager,
        new ApplicationDataProtectionService($this->entityTypeManager, new NullLogger()),
        new RequestStack(),
        $this->createMock(ProtectedAccessLog::class),
      ])
      ->onlyMethods(['matchingApplicationIds'])
      ->getMock();
    $mailbox->method('matchingApplicationIds')->willReturnCallback(
      fn(AccountInterface $account): array => $this->mailIsVerified && $account->getEmail() ? [1] : []
    );
    $this->handler = new ApplicationAccessControlHandler($this->entityType, $this->entityTypeManager, $mailbox);
  }

  /**
   * Creates a mock entity with the given owner ID and published status.
   *
   * @param int $owner_id
   *   The entity owner user ID.
   * @param bool $published
   *   Whether the entity is published.
   * @param array $options
   *   Optional entity settings:
   *   - applicant_email: Applicant email stored on the application.
   *   - public_status_token_hash: Public status token hash.
   *   - public_schema: Whether the referenced schema is public.
   *   - owner_roles: Role IDs on the owner account.
   *   - jurisdiction_id: Referenced jurisdiction group ID.
   *   - organisation_id: Referenced organisation group ID.
   *   - group_id: Referenced legacy group scope ID.
   *   - email_confirmed: Whether the applicant email is confirmed.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationInterface
   *   A mock application entity.
   */
  protected function createMockEntity(int $owner_id, bool $published = TRUE, array $options = []): ApplicationInterface {
    $applicant_email = $options['applicant_email'] ?? NULL;
    $public_status_token_hash = $options['public_status_token_hash'] ?? NULL;
    $public_schema = (bool) ($options['public_schema'] ?? FALSE);
    $owner_roles = $options['owner_roles'] ?? ['authenticated'];

    $entity = $this->createMock(ApplicationInterface::class);
    $entity->method('getOwnerId')->willReturn($owner_id);
    $entity->method('getOwner')->willReturn($this->ownerAccount($owner_roles));
    $entity->method('isPublished')->willReturn($published);
    $entity->method('getEntityType')->willReturn($this->entityType);
    $entity->method('getEntityTypeId')->willReturn('application');
    $entity->method('bundle')->willReturn('application');
    $entity->method('uuid')->willReturn('test-uuid');
    $entity->method('id')->willReturn(1);
    $entity->method('language')->willReturn(new Language(['id' => 'en']));
    $entity->method('getRevisionId')->willReturn(1);
    $entity->method('isDefaultRevision')->willReturn(TRUE);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn(['application:1']);
    $entity->method('getCacheMaxAge')->willReturn(-1);

    $fields = [
      'field_applicant_email' => $this->fieldWithValue($applicant_email),
      'field_public_status_token_hash' => $this->fieldWithValue($public_status_token_hash),
      'field_schema' => $this->fieldWithEntity($options['schema'] ?? $this->schemaEntity($public_schema)),
      'field_jurisdiction' => $this->fieldWithTargetId($options['jurisdiction_id'] ?? NULL),
      'field_organisation' => $this->fieldWithTargetId($options['organisation_id'] ?? NULL),
      'field_group' => $this->fieldWithTargetId($options['group_id'] ?? NULL),
      'field_email_confirmed' => $this->fieldWithValue(
        array_key_exists('email_confirmed', $options)
          ? ($options['email_confirmed'] ? '1' : '0')
          : NULL
      ),
    ];
    if (array_key_exists('schema_revision_id', $options)) {
      $fields['field_schema_revision_id'] = $this->fieldWithValue((string) $options['schema_revision_id']);
    }
    $entity->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $fields)
    );
    $entity->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name))
    );

    return $entity;
  }

  /**
   * Creates a mock account with given permissions and ID.
   *
   * @param int $uid
   *   The user ID.
   * @param array $permissions
   *   List of permission strings the account has.
   * @param array $roles
   *   List of role IDs assigned to the account.
   * @param string $email
   *   Email address returned by the account.
   *
   * @return \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked account.
   */
  protected function createMockAccount(int $uid, array $permissions = [], array $roles = [], string $email = ''): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      fn(string $perm) => in_array($perm, $permissions, TRUE)
    );
    $account->method('getRoles')->willReturn($roles);
    $account->method('getEmail')->willReturn($email);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    return $account;
  }

  /**
   * Creates a field item list exposing a scalar value.
   */
  private function fieldWithValue(?string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === NULL || trim($value) === '');
    $field->method('__get')->willReturnCallback(
      static fn(string $name): ?string => $name === 'value' ? $value : NULL
    );
    return $field;
  }

  /**
   * Creates a field item list exposing an entity reference target ID.
   */
  private function fieldWithTargetId(null|int|string $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn((int) $target_id <= 0);
    $field->method('__get')->willReturnCallback(
      static fn(string $name): null|int|string => $name === 'target_id' ? $target_id : NULL
    );
    return $field;
  }

  /**
   * Creates a field item list exposing an entity reference.
   */
  private function fieldWithEntity(?FieldableEntityInterface $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($entity === NULL);
    $field->method('__get')->willReturnCallback(
      static fn(string $name): ?FieldableEntityInterface => $name === 'entity' ? $entity : NULL
    );
    return $field;
  }

  /**
   * Creates a schema-like fieldable entity.
   */
  private function schemaEntity(bool $public): FieldableEntityInterface {
    return $public
      ? $this->schemaWith('internet', 'published')
      : $this->schemaWith('intranet', 'draft');
  }

  /**
   * Creates a schema-like fieldable entity with explicit lifecycle values.
   */
  private function schemaWith(string $publication_scope, string $workflow_status, int $id = 7): FieldableEntityInterface {
    $schema = $this->createMock(FieldableEntityInterface::class);
    $schema->method('id')->willReturn($id);
    $schema->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => in_array($field_name, ['field_publication_scope', 'field_workflow_status'], TRUE)
    );
    $schema->method('get')->willReturnCallback(function (string $field_name) use ($publication_scope, $workflow_status): FieldItemListInterface {
      return match ($field_name) {
        'field_publication_scope' => $this->fieldWithValue($publication_scope),
        'field_workflow_status' => $this->fieldWithValue($workflow_status),
        default => throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name)),
      };
    });
    return $schema;
  }

  /**
   * Registers a form schema storage that serves the given revisions.
   *
   * @param array<int, \Drupal\Core\Entity\FieldableEntityInterface> $revisions
   *   Schema revisions keyed by revision ID.
   */
  private function registerSchemaRevisions(array $revisions): void {
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('loadRevision')->willReturnCallback(
      static fn(int|string $revision_id): ?FieldableEntityInterface => $revisions[(int) $revision_id] ?? NULL
    );
    $this->entityStorages['form_schema'] = $storage;
  }

  /**
   * Creates an owner account mock.
   *
   * @param string[] $roles
   *   Owner role IDs.
   */
  private function ownerAccount(array $roles): UserInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getRoles')->willReturn($roles);
    return $owner;
  }

  /**
   * Registers group and group relationship storages.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Groups keyed by numeric ID.
   * @param array<int, array<int, string[]>> $memberships
   *   Membership role IDs keyed by group ID and user ID.
   */
  private function setGroupStorage(array $groups, array $memberships = []): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      static fn(int|string $id): ?GroupInterface => $groups[(int) $id] ?? NULL
    );

    $this->entityStorages['group'] = $storage;
    $this->entityStorages['group_relationship'] = $this->relationshipStorage($memberships);
  }

  /**
   * Creates a relationship storage mock.
   *
   * @param array<int, array<int, string[]>> $memberships
   *   Membership role IDs keyed by group ID and user ID.
   */
  private function relationshipStorage(array $memberships): EntityStorageInterface {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      function (string $field, mixed $value) use (&$conditions, $query): QueryInterface {
        $conditions[$field] = $value;
        return $query;
      }
    );
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturnCallback(
      static function () use (&$conditions, $memberships): array {
        $uid = (int) ($conditions['entity_id'] ?? 0);
        $gid = (int) ($conditions['gid'] ?? 0);
        return isset($memberships[$gid][$uid]) ? [sprintf('%d:%d', $gid, $uid)] : [];
      }
    );

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->willReturnCallback(function (int|string $id) use ($memberships): ?GroupMembershipInterface {
      [$gid, $uid] = array_map('intval', explode(':', (string) $id, 2));
      $roles = $memberships[$gid][$uid] ?? NULL;
      return $roles === NULL ? NULL : $this->relationship($roles);
    });
    return $storage;
  }

  /**
   * Creates a group relationship mock with role IDs.
   */
  private function relationship(array $role_ids): GroupMembershipInterface {
    $relationship = $this->createMock(GroupMembershipInterface::class);
    $roles = [];
    foreach ($role_ids as $role_id) {
      $role = $this->createMock(GroupRoleInterface::class);
      $role->method('isAdmin')->willReturn(in_array($role_id, ['jur-admin', 'org-admin'], TRUE));
      $roles[$role_id] = $role;
    }
    $relationship->method('getRoles')->with(FALSE)->willReturn($roles);
    return $relationship;
  }

  /**
   * Creates a group mock.
   */
  private function group(int $id, string $bundle): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    if ($bundle === 'org') {
      $group->method('hasField')->with('field_jurisdiction')->willReturn(TRUE);
      $group->method('get')->with('field_jurisdiction')->willReturn($this->fieldWithTargetId(3));
    }
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(["group:$id"]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    return $group;
  }

  /**
   * Tests admin permission grants access.
   *
   * @covers ::checkAccess
   */
  public function testAdminPermissionGrantsAccess(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(1, ['administer application entities']);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests view own application uses strict int comparison for owner check.
   *
   * This validates the security hardening: (int) cast ensures type-safe
   * comparison preventing type juggling attacks.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationStrictOwnerCheck(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['view own application entities']);
    $access = $this->handler->access($entity, 'view', $owner, TRUE);
    $this->assertTrue($access->isAllowed());

    $other = $this->createMockAccount(6, ['view own application entities']);
    $access = $this->handler->access($entity, 'view', $other, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests anonymous users cannot view applications through entity access.
   *
   * @covers ::checkAccess
   */
  public function testAnonymousViewApplicationIsForbidden(): void {
    $entity = $this->createMockEntity(2);
    $anonymous = $this->createMockAccount(0, ['view published application entities'], ['anonymous']);

    $access = $this->handler->access($entity, 'view', $anonymous, TRUE);

    $this->assertTrue($access->isForbidden());
  }

  /**
   * Tests view own application also accepts the verified account email.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationAllowsVerifiedEmailMatch(): void {
    $entity = $this->createMockEntity(2, TRUE, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'public_schema' => TRUE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(
      5,
      ['view own application entities'],
      ['authenticated'],
      'Max.Mustermann@Example.Org'
    );

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * A raw account mailbox without a verification record grants no email access.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationRejectsUnverifiedMatchingEmail(): void {
    $this->mailIsVerified = FALSE;
    $entity = $this->createMockEntity(2, TRUE, [
      'applicant_email' => 'citizen@example.test',
      'public_status_token_hash' => str_repeat('a', 64),
      'public_schema' => TRUE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(5, ['view own application entities'], ['authenticated'], 'citizen@example.test');
    $this->assertFalse($this->handler->access($entity, 'view', $account, TRUE)->isAllowed());
  }

  /**
   * Tests verified email matching is limited to public service submissions.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationRejectsNonPublicEmailMatch(): void {
    $entity = $this->createMockEntity(2, TRUE, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'public_schema' => FALSE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(
      5,
      ['view own application entities'],
      ['authenticated'],
      'max.mustermann@example.org'
    );

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests email ownership survives taking the form offline after submission.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationKeepsEmailMatchAfterFormWentToDraft(): void {
    $this->registerSchemaRevisions([14 => $this->schemaWith('internet', 'published')]);
    $entity = $this->createMockEntity(2, TRUE, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'schema' => $this->schemaWith('internet', 'draft'),
      'schema_revision_id' => 14,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(
      5,
      ['view own application entities'],
      ['authenticated'],
      'max.mustermann@example.org'
    );

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests a revision of another form never decides the channel.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationIgnoresRevisionOfAnotherForm(): void {
    $this->registerSchemaRevisions([14 => $this->schemaWith('internet', 'published', 99)]);
    $entity = $this->createMockEntity(2, TRUE, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'schema' => $this->schemaWith('internet', 'draft'),
      'schema_revision_id' => 14,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(
      5,
      ['view own application entities'],
      ['authenticated'],
      'max.mustermann@example.org'
    );

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests the submission revision, not the current form, decides the channel.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationRejectsEmailMatchForIntranetSubmission(): void {
    $this->registerSchemaRevisions([14 => $this->schemaWith('intranet', 'published')]);
    $entity = $this->createMockEntity(2, TRUE, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'schema' => $this->schemaWith('internet', 'published'),
      'schema_revision_id' => 14,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(
      5,
      ['view own application entities'],
      ['authenticated'],
      'max.mustermann@example.org'
    );

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests view own application rejects service-account ownership.
   *
   * @covers ::checkAccess
   */
  public function testViewOwnApplicationRejectsServiceAccountOwner(): void {
    $entity = $this->createMockEntity(5, TRUE, [
      'applicant_email' => 'nuxt@example.org',
      'public_status_token_hash' => str_repeat('b', 64),
      'public_schema' => TRUE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createMockAccount(
      5,
      ['view own application entities'],
      ['authenticated', 'service_account'],
      'nuxt@example.org'
    );

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests update own application.
   *
   * @covers ::checkAccess
   */
  public function testUpdateOwnApplication(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['edit own application entities']);
    $access = $this->handler->access($entity, 'update', $owner, TRUE);
    $this->assertTrue($access->isAllowed());

    $other = $this->createMockAccount(6, ['edit own application entities']);
    $access = $this->handler->access($entity, 'update', $other, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * A citizen cannot delete a submitted application, not even their own.
   *
   * @covers ::checkAccess
   */
  public function testDeleteOwnApplicationIsDenied(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['delete own application entities']);
    $access = $this->handler->access($entity, 'delete', $owner, TRUE);
    $this->assertTrue($access->isForbidden());

    $other = $this->createMockAccount(6, ['delete own application entities']);
    $access = $this->handler->access($entity, 'delete', $other, TRUE);
    $this->assertTrue($access->isForbidden());
  }

  /**
   * Tests edit any permission.
   *
   * @covers ::checkAccess
   */
  public function testEditAnyApplication(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(99, ['edit any application entities']);

    $access = $this->handler->access($entity, 'update', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests delete any permission.
   *
   * @covers ::checkAccess
   */
  public function testDeleteAnyApplication(): void {
    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ], [
      4 => [99 => ['org-admin']],
    ]);
    $entity = $this->createMockEntity(5, TRUE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
    ]);
    $account = $this->createMockAccount(99, ['delete any application entities']);

    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests tenant admins can delete unconfirmed spam applications.
   *
   * @covers ::checkAccess
   */
  public function testDeleteAnyApplicationAllowsUnconfirmedInAdminScope(): void {
    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ], [
      4 => [99 => ['org-admin']],
    ]);
    $entity = $this->createMockEntity(5, TRUE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
      'email_confirmed' => FALSE,
    ]);
    $account = $this->createMockAccount(99, ['delete any application entities']);

    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests broad delete permission cannot cross tenant boundaries.
   *
   * @covers ::checkAccess
   */
  public function testDeleteAnyApplicationRejectsForeignScope(): void {
    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ], [
      3 => [99 => ['jur-member']],
    ]);
    $entity = $this->createMockEntity(5, TRUE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
    ]);
    $account = $this->createMockAccount(99, ['delete any application entities']);

    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($access->isForbidden());
  }

  /**
   * Tests view published application.
   *
   * @covers ::checkAccess
   */
  public function testViewPublishedApplication(): void {
    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ], [
      4 => [99 => ['org-member']],
    ]);

    $entity = $this->createMockEntity(5, TRUE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
    ]);
    $account = $this->createMockAccount(99, [
      'view published application entities',
      'view own application entities',
    ]);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests view published application denies broad staff access without scope.
   *
   * @covers ::checkAccess
   */
  public function testViewPublishedApplicationRequiresTenantScope(): void {
    $entity = $this->createMockEntity(5, TRUE);
    $account = $this->createMockAccount(99, [
      'view published application entities',
      'view own application entities',
    ]);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isForbidden());
  }

  /**
   * Tests view published application denies staff outside the entity scope.
   *
   * @covers ::checkAccess
   */
  public function testViewPublishedApplicationRejectsForeignScope(): void {
    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ]);

    $entity = $this->createMockEntity(5, TRUE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
    ]);
    $account = $this->createMockAccount(99, ['view published application entities']);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isForbidden());
  }

  /**
   * Tests jurisdiction admins can view applications in child organisations.
   *
   * @covers ::checkAccess
   */
  public function testViewPublishedApplicationAllowsJurisdictionAdmin(): void {
    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ], [
      3 => [99 => ['jur-admin']],
    ]);

    $entity = $this->createMockEntity(5, TRUE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
    ]);
    $account = $this->createMockAccount(99, ['view published application entities']);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests view unpublished application.
   *
   * @covers ::checkAccess
   */
  public function testViewUnpublishedApplication(): void {
    $entity = $this->createMockEntity(5, FALSE);

    $account = $this->createMockAccount(99, ['view published application entities']);
    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isNeutral());

    $this->setGroupStorage([
      3 => $this->group(3, 'jur'),
      4 => $this->group(4, 'org'),
    ], [
      4 => [98 => ['org-member']],
    ]);
    $entity = $this->createMockEntity(5, FALSE, [
      'jurisdiction_id' => 3,
      'organisation_id' => 4,
      'group_id' => 4,
    ]);

    $this->handler->resetCache();
    $account_unp = $this->createMockAccount(98, ['view unpublished application entities']);
    $access = $this->handler->access($entity, 'view', $account_unp, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests create access with service account role and create permission.
   *
   * @covers ::checkCreateAccess
   */
  public function testCreateAccess(): void {
    $account = $this->createMockAccount(5, ['create application entities'], ['authenticated', 'service_account']);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests create access denied for regular authenticated users.
   *
   * @covers ::checkCreateAccess
   */
  public function testCreateAccessDeniedForRegularAuthenticatedUser(): void {
    $account = $this->createMockAccount(5, ['create application entities'], ['authenticated']);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests create access denied without permission.
   *
   * @covers ::checkCreateAccess
   */
  public function testCreateAccessDenied(): void {
    $account = $this->createMockAccount(5, []);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isNeutral());
  }

  /**
   * Tests anonymous ownership matching is rejected defensively.
   *
   * @covers ::checkAccess
   */
  public function testAnonymousOwnershipEdgeCase(): void {
    $entity = $this->createMockEntity(0);
    $anon = $this->createMockAccount(0, ['view own application entities']);
    $access = $this->handler->access($entity, 'view', $anon, TRUE);

    $this->assertFalse($access->isAllowed());
  }

}
