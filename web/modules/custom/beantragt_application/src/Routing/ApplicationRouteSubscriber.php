<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Reserves application deletion for the audited endpoint with mutation locks.
 */
final class ApplicationRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['jsonapi.application--application.individual.delete', 'entity.application.delete_form'] as $name) {
      $collection->get($name)?->setRequirement('_access', 'FALSE');
    }
  }

}
