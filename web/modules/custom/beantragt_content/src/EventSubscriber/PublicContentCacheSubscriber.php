<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restores public cache headers for anonymous editorial content routes.
 */
final class PublicContentCacheSubscriber implements EventSubscriberInterface {

  /**
   * Cache TTL for public editorial content responses.
   */
  private const MAX_AGE = 300;

  /**
   * Public content routes that intentionally carry no private data.
   */
  private const ROUTES = [
    'beantragt_content.public_pages' => TRUE,
    'beantragt_content.public_page' => TRUE,
    'beantragt_content.public_documents' => TRUE,
    'beantragt_content.public_sections' => TRUE,
  ];

  /**
   * Applies the documented shared-cache policy after Drupal finalizes headers.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route = (string) $event->getRequest()->attributes->get('_route', '');
    if (!isset(self::ROUTES[$route])) {
      return;
    }

    $response = $event->getResponse();
    if ($response->getStatusCode() !== Response::HTTP_OK) {
      return;
    }

    $response->setPublic();
    $response->setMaxAge(self::MAX_AGE);
    $response->headers->set('Cache-Control', 'public, max-age=' . self::MAX_AGE);
    $response->headers->remove('Pragma');
    $response->setExpires((new \DateTimeImmutable())->modify('+' . self::MAX_AGE . ' seconds'));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => ['onResponse', -1100],
    ];
  }

}
