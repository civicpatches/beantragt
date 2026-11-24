<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Aligns the group overview route with platform administration permissions.
 */
final class AdminGroupRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.group.collection');
    if ($route === NULL) {
      return;
    }

    // A plus sign expresses OR in Drupal's permission route requirement.
    $route->setRequirement(
      '_permission',
      'access group overview+administer beantragt groups+administer group',
    );
  }

}
