<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Controller\DeliveryController;
use Drupal\beantragt_delivery\Controller\ExternalResponseController;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Enforces actual user identity and separate release authorization.
 */
#[Group('beantragt_delivery')]
final class ExternalResponseControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $cache = $this->createMock(CacheContextsManager::class);
    $cache->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache);
    \Drupal::setContainer($container);
  }

  /**
   * Keeps viewing and releasing responses separate across caller identities.
   */
  #[DataProvider('accessCases')]
  public function testAccess(bool $authenticated, array $roles, bool $view, bool $update, bool $admin, bool $expected_view, bool $expected_release): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn($authenticated);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $admin && $permission === 'administer application entities');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(0);
    $application->method('access')->willReturnCallback(static function (string $operation, mixed $account, bool $result = FALSE) use ($view, $update): mixed {
      $allowed = $operation === 'view' ? $view : $update;
      return $result ? AccessResult::allowedIf($allowed) : $allowed;
    });
    $responses = (new \ReflectionClass(ExternalResponseService::class))->newInstanceWithoutConstructor();
    $deliveries = new DeliveryController(
      (new \ReflectionClass(DeliveryLogRepository::class))->newInstanceWithoutConstructor(),
      (new \ReflectionClass(DeliveryDispatcher::class))->newInstanceWithoutConstructor(),
      new ScopeAccess($this->createMock(EntityTypeManagerInterface::class)),
    );
    $controller = new ExternalResponseController($responses, $account, $deliveries);
    self::assertSame($expected_view, $controller->access($application, $account)->isAllowed());
    self::assertSame($expected_release, $controller->releaseAccess($application, $account)->isAllowed());
    if (!$expected_view) {
      foreach (['listResponses' => [], 'download' => ['response-id', 'document-id'], 'release' => ['response-id']] as $method => $arguments) {
        try {
          $controller->{$method}($application, ...$arguments);
          self::fail('Foreign application existence leaked through ' . $method);
        }
        catch (NotFoundHttpException $exception) {
          self::assertSame(404, $exception->getStatusCode());
        }
      }
    }
    elseif (!$expected_release) {
      try {
        $controller->release($application, 'response-id');
        self::fail('Viewing an application conferred release permission.');
      }
      catch (AccessDeniedHttpException $exception) {
        self::assertSame(403, $exception->getStatusCode());
      }
    }

  }

  /**
   * Supplies applicant, staff, anonymous and foreign access cases.
   */
  public static function accessCases(): array {
    return [
      'anonymous' => [FALSE, [], TRUE, TRUE, FALSE, FALSE, FALSE],
      'service identity' => [TRUE, ['service_account'], TRUE, TRUE, TRUE, FALSE, FALSE],
      'other citizen or tenant' => [TRUE, ['authenticated'], FALSE, FALSE, FALSE, FALSE, FALSE],
      'applicant' => [TRUE, ['authenticated'], TRUE, TRUE, FALSE, TRUE, FALSE],
      'admin release' => [TRUE, ['administrator'], TRUE, TRUE, TRUE, TRUE, TRUE],
      'no update access' => [TRUE, ['administrator'], TRUE, FALSE, TRUE, TRUE, FALSE],
    ];
  }

}
