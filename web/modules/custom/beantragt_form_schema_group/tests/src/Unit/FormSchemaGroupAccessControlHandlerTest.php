<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_form_schema_group\Unit;

use Drupal\beantragt_form_schema_group\FormSchemaGroupAccessControlHandler;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the form schema group access control handler.
 */
#[CoversClass(FormSchemaGroupAccessControlHandler::class)]
#[Group('beantragt_form_schema_group')]
class FormSchemaGroupAccessControlHandlerTest extends UnitTestCase {

  /**
   * The access control handler under test.
   */
  protected FormSchemaGroupAccessControlHandler $handler;

  /**
   * The mocked entity type.
   */
  protected EntityTypeInterface $entityType;

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

    \Drupal::setContainer($container);

    $this->entityType = $this->createMock(EntityTypeInterface::class);
    $this->entityType->method('id')->willReturn('form_schema_group');
    $this->entityType->method('getAdminPermission')->willReturn('administer form_schema_group entities');

    $this->handler = new FormSchemaGroupAccessControlHandler($this->entityType);
  }

  /**
   * Creates a form schema group entity mock.
   */
  protected function createMockEntity(int $owner_id, bool $published = TRUE): EntityInterface {
    $entityType = $this->entityType;
    // phpcs:disable
    return new class($owner_id, $published, $entityType) implements EntityInterface {

      use \Drupal\Core\Cache\CacheableDependencyTrait;

      protected int $ownerId;
      protected bool $published;
      protected EntityTypeInterface $entityType;

      public function __construct(int $ownerId, bool $published, EntityTypeInterface $entityType) {
        $this->ownerId = $ownerId;
        $this->published = $published;
        $this->entityType = $entityType;
      }

      public function getOwnerId() { return $this->ownerId; }
      public function isPublished() { return $this->published; }
      public function getEntityType() { return $this->entityType; }
      public function getEntityTypeId() { return 'form_schema_group'; }
      public function bundle() { return 'form_schema_group'; }
      public function uuid() { return 'test-uuid'; }
      public function getCacheContexts() { return []; }
      public function getCacheTags() { return ['form_schema_group:1']; }
      public function getCacheMaxAge() { return -1; }

      // Required EntityInterface stubs.
      public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) { return FALSE; }
      public function id() { return 1; }
      public function label() { return 'Test'; }
      public function language() { return new \Drupal\Core\Language\Language(['id' => 'en']); }
      public function isNew() { return FALSE; }
      public function enforceIsNew($value = TRUE) { return $this; }
      public function getTypedData() { return NULL; }
      public function getConfigDependencyKey() { return ''; }
      public function getConfigDependencyName() { return ''; }
      public function getConfigTarget() { return ''; }
      public function delete() {}
      public function save() { return 0; }
      public function createDuplicate() { return $this; }
      public function toArray() { return []; }
      public function hasLinkTemplate($key) { return FALSE; }
      public function toLink($text = NULL, $rel = 'canonical', array $options = []) { return NULL; }
      public function toUrl($rel = 'canonical', array $options = []) { return NULL; }
      public function uriRelationships() { return []; }
      public static function load($id) { return NULL; }
      public static function loadMultiple(?array $ids = NULL) { return []; }
      public static function create(array $values = []) { return NULL; }
      public static function preCreate(\Drupal\Core\Entity\EntityStorageInterface $storage, array &$values) {}
      public function postCreate(\Drupal\Core\Entity\EntityStorageInterface $storage) {}
      public static function preDelete(\Drupal\Core\Entity\EntityStorageInterface $storage, array $entities) {}
      public static function postDelete(\Drupal\Core\Entity\EntityStorageInterface $storage, array $entities) {}
      public static function postLoad(\Drupal\Core\Entity\EntityStorageInterface $storage, array &$entities) {}
      public function preSave(\Drupal\Core\Entity\EntityStorageInterface $storage) {}
      public function postSave(\Drupal\Core\Entity\EntityStorageInterface $storage, $update = TRUE) {}
      public function referencedEntities() { return []; }
      public function getOriginalId() { return 1; }
      public function getCacheTagsToInvalidate() { return []; }
      public function setOriginalId($id) { return $this; }
      public function getOriginal(): ?static { return NULL; }
      public function setOriginal(?\Drupal\Core\Entity\EntityInterface $original): static { return $this; }
      public function addCacheContexts(array $cache_contexts) { return $this; }
      public function addCacheTags(array $cache_tags) { return $this; }
      public function mergeCacheMaxAge($max_age) { return $this; }
      public function addCacheableDependency($other_object) { return $this; }
    };
    // phpcs:enable
  }

  /**
   * Creates a mock account.
   */
  protected function createMockAccount(int $uid, array $permissions = [], array $roles = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission) => in_array($permission, $permissions, TRUE),
    );
    $default_roles = $uid > 0 ? ['authenticated'] : ['anonymous'];
    $account->method('getRoles')->willReturn(array_values(array_unique([
      ...$default_roles,
      ...$roles,
    ])));
    $account->method('isAuthenticated')->willReturn($uid > 0);
    return $account;
  }

  /**
   * Tests admin permission grants access for all operations.
   */
  public function testAdminHasFullAccess(): void {
    $entity = $this->createMockEntity(5);
    $admin = $this->createMockAccount(1, ['administer form_schema_group entities']);

    foreach (['view', 'update', 'delete'] as $operation) {
      $access = $this->handler->access($entity, $operation, $admin, TRUE);
      $this->assertTrue($access->isAllowed(), "Admin should have {$operation} access.");
    }
  }

  /**
   * Tests published view access.
   */
  public function testViewPublished(): void {
    $entity = $this->createMockEntity(5, TRUE);
    $account = $this->createMockAccount(99, ['view published form_schema_group entities']);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests unpublished view access requires the unpublished permission.
   */
  public function testViewUnpublished(): void {
    $entity = $this->createMockEntity(5, FALSE);

    $without = $this->createMockAccount(99, ['view published form_schema_group entities']);
    $access = $this->handler->access($entity, 'view', $without, TRUE);
    $this->assertTrue($access->isNeutral());

    $this->handler->resetCache();
    $with = $this->createMockAccount(98, ['view unpublished form_schema_group entities']);
    $access = $this->handler->access($entity, 'view', $with, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests owner-scoped update access.
   */
  public function testEditOwnGroup(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['edit own form_schema_group entities']);
    $access = $this->handler->access($entity, 'update', $owner, TRUE);
    $this->assertTrue($access->isAllowed());

    $other = $this->createMockAccount(6, ['edit own form_schema_group entities']);
    $access = $this->handler->access($entity, 'update', $other, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests edit any access.
   */
  public function testEditAnyGroup(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(99, ['edit any form_schema_group entities']);

    $access = $this->handler->access($entity, 'update', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests owner-scoped delete access.
   */
  public function testDeleteOwnGroup(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['delete own form_schema_group entities']);
    $access = $this->handler->access($entity, 'delete', $owner, TRUE);
    $this->assertTrue($access->isAllowed());

    $other = $this->createMockAccount(6, ['delete own form_schema_group entities']);
    $access = $this->handler->access($entity, 'delete', $other, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests delete any access.
   */
  public function testDeleteAnyGroup(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(99, ['delete any form_schema_group entities']);

    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests create access for the Nuxt service account.
   */
  public function testCreateAccessAllowsServiceAccount(): void {
    $account = $this->createMockAccount(
      5,
      ['create form_schema_group entities'],
      ['service_account'],
    );
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests create access for scope-bypass platform administrators.
   */
  public function testCreateAccessAllowsScopeBypassAdministrator(): void {
    $account = $this->createMockAccount(1, [
      'administer form_schema_group entities',
      'bypass beantragt group scope',
    ]);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * The create permission admits a person to the scoped entity check.
   */
  public function testCreateAccessAllowsDepartmentAdminWithoutScopeBypass(): void {
    $account = $this->createMockAccount(5, [
      'create form_schema_group entities',
    ], ['department_admin']);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Entity administration alone does not grant personal creation.
   */
  public function testAdministerPermissionDoesNotReplaceCreatePermission(): void {
    $account = $this->createMockAccount(5, ['administer form_schema_group entities']);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests create access is denied without the required permission.
   */
  public function testCreateAccessDeniedWithoutPermission(): void {
    $account = $this->createMockAccount(5, []);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertFalse($access->isAllowed());
  }

}
