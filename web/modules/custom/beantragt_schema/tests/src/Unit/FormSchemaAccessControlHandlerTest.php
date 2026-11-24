<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\FormSchemaAccessControlHandler;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the FormSchema access control handler.
 */
#[CoversClass(FormSchemaAccessControlHandler::class)]
#[Group('beantragt_schema')]
class FormSchemaAccessControlHandlerTest extends UnitTestCase {

  /**
   * The access control handler under test.
   */
  protected FormSchemaAccessControlHandler $handler;

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
    $this->entityType->method('id')->willReturn('form_schema');
    $this->entityType->method('getAdminPermission')->willReturn('administer form_schema entities');

    $this->handler = new FormSchemaAccessControlHandler($this->entityType);
  }

  /**
   * Creates a mock form schema entity.
   */
  protected function createMockEntity(int $owner_id, bool $published = TRUE): object {
    $entityType = $this->entityType;
    // phpcs:disable
    $entity = new class($owner_id, $published, $entityType) implements \Drupal\Core\Entity\EntityInterface {

      use \Drupal\Core\Cache\CacheableDependencyTrait;

      protected int $ownerId;
      protected bool $published;
      protected \Drupal\Core\Entity\EntityTypeInterface $entityType;

      public function __construct(int $ownerId, bool $published, \Drupal\Core\Entity\EntityTypeInterface $entityType) {
        $this->ownerId = $ownerId;
        $this->published = $published;
        $this->entityType = $entityType;
      }

      public function getOwnerId() { return $this->ownerId; }
      public function isPublished() { return $this->published; }
      public function getEntityType() { return $this->entityType; }
      public function getEntityTypeId() { return 'form_schema'; }
      public function bundle() { return 'form_schema'; }
      public function uuid() { return 'test-uuid'; }
      public function getCacheContexts() { return []; }
      public function getCacheTags() { return ['form_schema:1']; }
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
    return $entity;
  }

  /**
   * Creates a mock account.
   */
  protected function createMockAccount(int $uid, array $permissions = [], array $roles = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      fn(string $perm) => in_array($perm, $permissions, TRUE)
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
    $admin = $this->createMockAccount(1, ['administer form_schema entities']);

    foreach (['view', 'update', 'delete'] as $operation) {
      $access = $this->handler->access($entity, $operation, $admin, TRUE);
      $this->assertTrue($access->isAllowed(), "Admin should have {$operation} access.");
    }
  }

  /**
   * Tests view published form schema.
   */
  public function testViewPublished(): void {
    $entity = $this->createMockEntity(5, TRUE);
    $account = $this->createMockAccount(99, ['view published form_schema entities']);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests view unpublished form schema.
   */
  public function testViewUnpublished(): void {
    $entity = $this->createMockEntity(5, FALSE);

    $without = $this->createMockAccount(99, ['view published form_schema entities']);
    $access = $this->handler->access($entity, 'view', $without, TRUE);
    $this->assertTrue($access->isNeutral());

    $this->handler->resetCache();
    $with = $this->createMockAccount(98, ['view unpublished form_schema entities']);
    $access = $this->handler->access($entity, 'view', $with, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests edit own form schema with strict owner check.
   */
  public function testEditOwnSchema(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['edit own form_schema entities']);
    $access = $this->handler->access($entity, 'update', $owner, TRUE);
    $this->assertTrue($access->isAllowed());

    $other = $this->createMockAccount(6, ['edit own form_schema entities']);
    $access = $this->handler->access($entity, 'update', $other, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests edit any form schema.
   */
  public function testEditAnySchema(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(99, ['edit any form_schema entities']);

    $access = $this->handler->access($entity, 'update', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests delete own form schema.
   */
  public function testDeleteOwnSchema(): void {
    $entity = $this->createMockEntity(5);

    $owner = $this->createMockAccount(5, ['delete own form_schema entities']);
    $access = $this->handler->access($entity, 'delete', $owner, TRUE);
    $this->assertTrue($access->isAllowed());

    $other = $this->createMockAccount(6, ['delete own form_schema entities']);
    $access = $this->handler->access($entity, 'delete', $other, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests delete any form schema.
   */
  public function testDeleteAnySchema(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(99, ['delete any form_schema entities']);

    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests create access for the Nuxt service account.
   */
  public function testCreateAccessAllowsServiceAccount(): void {
    $account = $this->createMockAccount(
      5,
      ['create form_schema entities'],
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
      'administer form_schema entities',
      'bypass beantragt group scope',
    ]);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests create access allows department admins with the entity permission.
   */
  public function testCreateAccessAllowsDepartmentAdminWithoutScopeBypass(): void {
    $account = $this->createMockAccount(5, [
      'administer form_schema entities',
      'create form_schema entities',
    ], ['department_admin']);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Tests no access without any permissions.
   */
  public function testNoPermissionsNeutral(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(99, []);

    $access = $this->handler->access($entity, 'view', $account, TRUE);
    $this->assertTrue($access->isNeutral());

    $access = $this->handler->access($entity, 'update', $account, TRUE);
    $this->assertFalse($access->isAllowed());

    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertFalse($access->isAllowed());
  }

  /**
   * Tests unknown operation returns neutral.
   */
  public function testUnknownOperationNeutral(): void {
    $entity = $this->createMockEntity(5);
    $account = $this->createMockAccount(5, []);

    $access = $this->handler->access($entity, 'unknown_op', $account, TRUE);
    $this->assertTrue($access->isNeutral());
  }

  /**
   * Tests that non-owners cannot edit without edit any permission.
   */
  public function testNonOwnerWithoutEditAnyIsDenied(): void {
    // Entity owned by uid 10.
    $entity = $this->createMockEntity(10);

    // Another authenticated user (uid 99) who only has 'edit own'.
    $account = $this->createMockAccount(99, ['edit own form_schema entities']);
    $access = $this->handler->access($entity, 'update', $account, TRUE);
    $this->assertFalse(
      $access->isAllowed(),
      // phpcs:ignore
      'Non-owner with only "edit own" must not update another user\'s schema.'
    );
  }

  /**
   * Tests non-owner delete is denied without delete any permission.
   */
  public function testNonOwnerWithoutDeleteAnyIsDenied(): void {
    // Entity owned by uid 10.
    $entity = $this->createMockEntity(10);

    // Another authenticated user (uid 99) who only has 'delete own'.
    $account = $this->createMockAccount(99, ['delete own form_schema entities']);
    $access = $this->handler->access($entity, 'delete', $account, TRUE);
    $this->assertFalse(
      $access->isAllowed(),
      // phpcs:ignore
      'Non-owner with only "delete own" must not delete another user\'s schema.'
    );
  }

  /**
   * Tests create access is denied without the required permission.
   */
  public function testCreateAccessDeniedWithoutPermission(): void {
    $account = $this->createMockAccount(5, []);
    $access = $this->handler->createAccess(NULL, $account, [], TRUE);
    $this->assertFalse(
      $access->isAllowed(),
      'Account without "create form_schema entities" must not create schemas.'
    );
  }

}
