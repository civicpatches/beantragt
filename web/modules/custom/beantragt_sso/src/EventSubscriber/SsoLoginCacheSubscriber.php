<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Preserves no-store on login, acs, and mock_login redirects.
 */
final class SsoLoginCacheSubscriber implements EventSubscriberInterface {

  /**
   * Restores no-store after Drupal applies its default no-cache header.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    $route = $event->getRequest()->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if ($event->isMainRequest()
        && $response instanceof RedirectResponse
        && in_array($route, ['beantragt_sso.login', 'beantragt_sso.acs', 'beantragt_sso.mock_login'], TRUE)) {
      $response->headers->addCacheControlDirective('no-store');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after Drupal's FinishResponseSubscriber (priority 0).
    return [KernelEvents::RESPONSE => ['onResponse', -10]];
  }

}
