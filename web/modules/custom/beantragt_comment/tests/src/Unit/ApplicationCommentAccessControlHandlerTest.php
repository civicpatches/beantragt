<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_comment\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_comment\ApplicationCommentAccessControlHandler;
use Drupal\beantragt_comment\Entity\ApplicationCommentInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Messages on submitted applications stay with the authority.
 */
#[CoversClass(ApplicationCommentAccessControlHandler::class)]
#[Group('beantragt_comment')]
final class ApplicationCommentAccessControlHandlerTest extends UnitTestCase {

  /**
   * Permissions a citizen held before messages became part of the record.
   */
  private const CITIZEN_PERMISSIONS = [
    'view application_comment entities',
    'delete own application_comment entities',
  ];

  /**
   * The access control handler under test.
   */
  private ApplicationCommentAccessControlHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('invokeAll')->willReturn([]);
    $container->set('module_handler', $module_handler);
    \Drupal::setContainer($container);

    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('id')->willReturn('application_comment');
    $entity_type->method('getAdminPermission')->willReturn('administer application_comment entities');
    $this->handler = new ApplicationCommentAccessControlHandler($entity_type);
  }

  /**
   * A citizen cannot delete own messages on a submitted application.
   */
  public function testDeleteOwnMessageOnSubmittedApplicationIsForbidden(): void {
    $comment = $this->comment(5, $this->application(5, TRUE));
    $access = $this->handler->access($comment, 'delete', $this->account(5, self::CITIZEN_PERMISSIONS), TRUE);
    $this->assertTrue($access->isForbidden());
  }

  /**
   * Own messages on an unconfirmed submission can still go with it.
   */
  public function testDeleteOwnMessageOnUnconfirmedApplicationIsAllowed(): void {
    $comment = $this->comment(5, $this->application(5, FALSE));
    $access = $this->handler->access($comment, 'delete', $this->account(5, self::CITIZEN_PERMISSIONS), TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Staff with "delete any" keep their delete access.
   */
  public function testDeleteAnyMessageOnSubmittedApplicationIsAllowed(): void {
    $comment = $this->comment(5, $this->application(5, TRUE));
    $access = $this->handler->access($comment, 'delete', $this->account(9, ['delete any application_comment entities'], ['case_worker']), TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Anonymous never matches messages reassigned to uid 0.
   */
  public function testAnonymousCannotAccessMessagesOfErasedAccounts(): void {
    $comment = $this->comment(0, $this->application(0, TRUE));
    $anonymous = $this->account(0, [
      ...self::CITIZEN_PERMISSIONS,
      'delete any application_comment entities',
    ]);

    foreach (['view', 'delete'] as $operation) {
      $access = $this->handler->access($comment, $operation, $anonymous, TRUE);
      $this->assertTrue($access->isForbidden(), $operation);
    }
  }

  /**
   * The application owner still reads the messages of the own application.
   */
  public function testOwnerCanViewMessages(): void {
    $comment = $this->comment(9, $this->application(5, TRUE));
    $access = $this->handler->access($comment, 'view', $this->account(5, self::CITIZEN_PERMISSIONS), TRUE);
    $this->assertTrue($access->isAllowed());
  }

  /**
   * Builds a comment mock on an application.
   */
  private function comment(int $owner_id, ApplicationInterface $application): ApplicationCommentInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $name): mixed => $name === 'entity' ? $application : NULL
    );

    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->method('getOwnerId')->willReturn($owner_id);
    $comment->method('getEntityTypeId')->willReturn('application_comment');
    $comment->method('bundle')->willReturn('application_comment');
    $comment->method('uuid')->willReturn('comment-uuid-' . $owner_id);
    $comment->method('id')->willReturn(1);
    $comment->method('language')->willReturn(new Language(['id' => 'en']));
    $comment->method('getCacheContexts')->willReturn([]);
    $comment->method('getCacheTags')->willReturn(['application_comment:1']);
    $comment->method('getCacheMaxAge')->willReturn(-1);
    $comment->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_application');
    $comment->method('get')->willReturnCallback(
      static fn(string $name): FieldItemListInterface => $name === 'field_application'
        ? $field
        : throw new \InvalidArgumentException($name)
    );
    return $comment;
  }

  /**
   * Builds an application mock.
   */
  private function application(int $owner_id, bool $confirmed): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getOwnerId')->willReturn($owner_id);
    $application->method('isEmailConfirmed')->willReturn($confirmed);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn(['application:1']);
    $application->method('getCacheMaxAge')->willReturn(-1);
    return $application;
  }

  /**
   * Builds an account mock.
   *
   * @param int $uid
   *   User ID, 0 for anonymous.
   * @param string[] $permissions
   *   Granted permissions.
   * @param string[] $roles
   *   Role IDs.
   */
  private function account(int $uid, array $permissions, array $roles = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn($uid > 0);
    $account->method('isAnonymous')->willReturn($uid === 0);
    $account->method('getRoles')->willReturn($uid > 0 ? ['authenticated', ...$roles] : ['anonymous']);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $permissions, TRUE)
    );
    return $account;
  }

}
