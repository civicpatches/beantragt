<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\beantragt_group\Routing\AdminGroupRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests the platform administrator group overview route contract.
 */
#[CoversClass(AdminGroupRouteSubscriber::class)]
#[Group('beantragt_group')]
final class AdminGroupRouteSubscriberTest extends UnitTestCase {

  /**
   * The overview accepts either Group or Beantragt administration rights.
   */
  public function testGroupOverviewPermissionAlternatives(): void {
    $route = new Route('/admin/group');
    $routes = new RouteCollection();
    $routes->add('entity.group.collection', $route);

    $subscriber = new AdminGroupRouteSubscriber();
    $method = new \ReflectionMethod($subscriber, 'alterRoutes');
    $method->invoke($subscriber, $routes);

    self::assertSame(
      'access group overview+administer beantragt groups+administer group',
      $route->getRequirement('_permission'),
    );
  }

}
