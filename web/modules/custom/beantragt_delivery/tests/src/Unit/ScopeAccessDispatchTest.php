<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies dispatch approval uses permission plus editable case membership.
 */
#[Group('beantragt_delivery')]
final class ScopeAccessDispatchTest extends UnitTestCase {

  /**
   * Approval must never inherit an unrelated administrative permission.
   */
  #[DataProvider('approvalCases')]
  public function testDispatchAuthorization(array $permissions, bool $active, bool $update, bool $member, bool $expected): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn($active);
    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->with('7')->willReturn($user);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('12');
    $group->method('bundle')->willReturn('jur');
    $membership = $this->createMock(GroupMembershipInterface::class);
    $membership->method('getRoles')->willReturn([]);
    $membership_storage = $this->createMock(EntityStorageInterface::class);
    $membership_storage->method('load')->willReturn($membership);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['user', $user_storage], ['group_relationship', $membership_storage]]);
    $group_field = $this->createMock(FieldItemListInterface::class);
    $group_field->method('isEmpty')->willReturn(FALSE);
    $group_field->method('__isset')->willReturn(TRUE);
    $group_field->method('__get')->with('entity')->willReturn($group);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(0);
    $application->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_jurisdiction');
    $application->method('get')->with('field_jurisdiction')->willReturn($group_field);
    $application->method('access')->willReturnCallback(static fn(string $operation): bool => $operation === 'view' || $update);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn((object) ['data' => $member ? [12 => 24] : []]);
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $container->set('cache.group_memberships_chained', $cache);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $access = new ScopeAccess($manager);
    self::assertSame($expected, $access->canApproveDispatch($application, $account));
    self::assertSame($active && $member && (in_array('approve application dispatch', $permissions, TRUE) || in_array('manage application deliveries', $permissions, TRUE)), $access->transferAccess($application, $account)->isAllowed());
    self::assertFalse(method_exists($access, 'allows'));
  }

  /**
   * Supplies independent permission, account and tenant gates.
   */
  public static function approvalCases(): array {
    return [
      'case worker member without management permission' => [['approve application dispatch'], TRUE, TRUE, TRUE, TRUE],
      'missing dispatch permission' => [['manage application deliveries'], TRUE, TRUE, TRUE, FALSE],
      'citizen' => [[], TRUE, TRUE, TRUE, FALSE],
      'foreign tenant' => [['approve application dispatch'], TRUE, TRUE, FALSE, FALSE],
      'blocked account' => [['approve application dispatch'], FALSE, TRUE, TRUE, FALSE],
      'case view only' => [['approve application dispatch'], TRUE, FALSE, TRUE, FALSE],
      'administrator needs dispatch permission' => [['administer application entities'], TRUE, TRUE, TRUE, FALSE],
    ];
  }

}
