<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Access\JsonApiReadGateAccessCheck;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests who passes the JSON:API read gate.
 */
#[CoversClass(JsonApiReadGateAccessCheck::class)]
#[Group('beantragt_schema')]
final class JsonApiReadGateAccessCheckTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $cache_contexts = $this->createMock(CacheContextsManager::class);
    $cache_contexts->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Callers and the expected outcome.
   *
   * @return array<string, array{0: string[], 1: string[], 2: bool}>
   *   Roles, permissions, whether access is allowed.
   */
  public static function callers(): array {
    return [
      'anonymous' => [['anonymous'], [], FALSE],
      'authenticated without role' => [['authenticated'], [], FALSE],
      'authenticated with an unrelated role' => [['authenticated', 'editor'], ['view own application entities'], FALSE],
      'case_worker' => [['authenticated', 'case_worker'], [], TRUE],
      'department_admin' => [['authenticated', 'department_admin'], [], TRUE],
      'administrator' => [['authenticated', 'administrator'], [], TRUE],
      'service_account' => [['authenticated', 'service_account'], [], TRUE],
      'permission only' => [['authenticated'], ['administer site configuration'], TRUE],
    ];
  }

  /**
   * Staff, service account and the permission pass; everyone else is refused.
   *
   * @param string[] $roles
   *   Role ids of the account.
   * @param string[] $permissions
   *   Permissions the account holds.
   * @param bool $allowed
   *   Whether access is allowed.
   */
  #[DataProvider('callers')]
  public function testAccess(array $roles, array $permissions, bool $allowed): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );

    $result = (new JsonApiReadGateAccessCheck())->access($account);

    $this->assertSame($allowed, $result->isAllowed());
    $this->assertSame(!$allowed, $result->isForbidden());
    $this->assertSame(['user.permissions', 'user.roles'], $this->sorted($result->getCacheContexts()));
    $this->assertSame([], $result->getCacheTags());
    $this->assertSame(-1, $result->getCacheMaxAge());
    if (!$allowed) {
      $this->assertInstanceOf(AccessResultReasonInterface::class, $result);
      $this->assertSame(JsonApiReadGateAccessCheck::REASON, $result->getReason());
      $this->assertSame('This JSON:API route is restricted to staff and service accounts.', $result->getReason());
    }
  }

  /**
   * Returns the values sorted.
   *
   * @param string[] $values
   *   Values.
   *
   * @return string[]
   *   Sorted values.
   */
  private function sorted(array $values): array {
    sort($values);
    return $values;
  }

}
