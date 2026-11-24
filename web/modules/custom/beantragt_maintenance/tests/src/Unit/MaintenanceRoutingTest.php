<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_maintenance\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the maintenance route contract.
 */
#[Group('beantragt_maintenance')]
final class MaintenanceRoutingTest extends UnitTestCase {

  /**
   * All maintenance and recovery routes stay reachable during Core outages.
   */
  public function testRoutePathsAndMaintenanceAccess(): void {
    $routes = Yaml::decode((string) file_get_contents(
      dirname(__DIR__, 3) . '/beantragt_maintenance.routing.yml',
    ));

    self::assertSame('/api/platform/maintenance', $routes['beantragt_maintenance.status']['path']);
    self::assertSame(['GET'], $routes['beantragt_maintenance.status']['methods']);
    self::assertSame('/api/platform/maintenance', $routes['beantragt_maintenance.update']['path']);
    self::assertSame(['PATCH'], $routes['beantragt_maintenance.update']['methods']);
    self::assertSame(
      '/api/platform/maintenance/recovery/request-code',
      $routes['beantragt_maintenance.recovery_request_code']['path'],
    );
    self::assertSame(
      '/api/platform/maintenance/recovery/verify-code',
      $routes['beantragt_maintenance.recovery_verify_code']['path'],
    );

    foreach ($routes as $route) {
      self::assertTrue($route['options']['_maintenance_access']);
      self::assertSame('TRUE', $route['options']['no_cache']);
    }
  }

}
