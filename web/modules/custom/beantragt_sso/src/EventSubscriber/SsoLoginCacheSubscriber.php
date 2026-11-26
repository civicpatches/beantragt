<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps SSO redirects bodyless and uncacheable.
 */
final class SsoLoginCacheSubscriber implements EventSubscriberInterface {

  /**
   * Finalizes the redirect after Drupal updates its headers and target.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    $route = $event->getRequest()->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if ($event->isMainRequest()
        && $response instanceof RedirectResponse
        && in_array($route, ['beantragt_sso.login', 'beantragt_sso.acs', 'beantragt_sso.mock_login'], TRUE)) {
      $response->headers->addCacheControlDirective('no-store');
      $response->setContent('');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Drupal's cookie authentication can rebuild the redirect body at -1000.
    return [KernelEvents::RESPONSE => ['onResponse', -1010]];
  }

}
