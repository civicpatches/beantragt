<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit;

use Drupal\beantragt_applicant\ApplicantAccessControlHandler;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests applicant entity access decisions.
 *
 * @coversDefaultClass \Drupal\beantragt_applicant\ApplicantAccessControlHandler
 * @group beantragt_applicant
 */
final class ApplicantAccessControlHandlerTest extends UnitTestCase {

  /**
   * The access handler under test.
   */
  private TestableApplicantAccessControlHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('id')->willReturn('applicant');
    $entity_type->method('getAdminPermission')->willReturn('administer applicant entities');
    $this->handler = new TestableApplicantAccessControlHandler($entity_type);
  }

  /**
   * Staff view permission alone does not expose applicant profiles.
   */
  public function testStaffBroadViewPermissionDoesNotAllowApplicantView(): void {
    $applicant = $this->applicant(owner_id: 33);
    $account = $this->account(7, [
      'view published applicant entities',
      'view own applicant entities',
    ]);

    $this->assertFalse($this->handler->check($applicant, 'view', $account)->isAllowed());
  }

  /**
   * Applicant owners can still view their own profile.
   */
  public function testOwnerCanViewOwnApplicantProfile(): void {
    $applicant = $this->applicant(owner_id: 7);
    $account = $this->account(7, ['view own applicant entities']);

    $this->assertTrue($this->handler->check($applicant, 'view', $account)->isAllowed());
  }

  /**
   * Entity administrators retain full applicant access.
   */
  public function testAdminPermissionAllowsApplicantView(): void {
    $applicant = $this->applicant(owner_id: 33);
    $account = $this->account(7, ['administer applicant entities']);

    $this->assertTrue($this->handler->check($applicant, 'view', $account)->isAllowed());
  }

  /**
   * Creates an applicant mock.
   */
  private function applicant(int $owner_id): ApplicantInterface {
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('isPublished')->willReturn(TRUE);
    $applicant->method('getOwnerId')->willReturn((string) $owner_id);
    $applicant->method('getCacheContexts')->willReturn([]);
    $applicant->method('getCacheTags')->willReturn([]);
    $applicant->method('getCacheMaxAge')->willReturn(-1);
    return $applicant;
  }

  /**
   * Creates an account mock.
   *
   * @param int $uid
   *   User ID for the account.
   * @param string[] $permissions
   *   Permissions granted to the account.
   */
  private function account(int $uid, array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, $permissions, TRUE)
    );
    return $account;
  }

}

/**
 * Exposes protected access checks for unit tests.
 */
final class TestableApplicantAccessControlHandler extends ApplicantAccessControlHandler {

  /**
   * Exposes the protected checkAccess method.
   */
  public function check(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    return $this->checkAccess($entity, $operation, $account);
  }

}
