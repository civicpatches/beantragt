<?php

declare(strict_types=1);

namespace Drupal\beantragt_maintenance\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Keeps Core's CSRF-token route reachable during maintenance mode.
 */
final class MaintenanceRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('system.csrftoken');
    if ($route !== NULL) {
      $route->setOption('_maintenance_access', TRUE);
    }
  }

}
