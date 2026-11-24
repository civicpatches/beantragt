<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\EventSubscriber;

use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sanitizes integration errors after core has logged the original exception.
 */
final class IntegrationExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the integration error boundary.
   */
  public function __construct(private readonly IntegrationAudit $audit) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Drupal's exception logger runs at 50; response rendering runs below 40.
    return [KernelEvents::EXCEPTION => ['onException', 40]];
  }

  /**
   * Leaves other modules' routes and full server-side exception logs untouched.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');
    if (!str_starts_with($route, 'beantragt_delivery.integration_') && !str_starts_with($route, 'beantragt_delivery.transfer_')) {
      return;
    }
    $exception = $event->getThrowable();
    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    $code = match ($status) {
      400 => 'integration.bad_request',
      401, 403 => 'integration.access_denied',
      404 => 'integration.route_not_found',
      405 => 'integration.method_not_allowed',
      406, 415 => 'integration.json_required',
      413 => 'integration.input_too_large',
      429 => 'integration.rate_limited',
      default => $status < 500 ? 'integration.request_invalid' : 'integration.internal_error',
    };
    if (!$request->attributes->get('_integration_audited')) {
      $this->audit->record($request, NULL, in_array($status, [401, 403, 404], TRUE) ? 'denied' : 'failed', code: $code);
    }
    $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
    $response = new JsonResponse(['error' => ['code' => $code, 'details' => []]], $status, $headers);
    $response->headers->set('Cache-Control', 'private, no-store');
    $event->setResponse($response);
  }

}
