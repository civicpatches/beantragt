<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\EventSubscriber;

use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a refused save under the case lock with JSON in every format.
 *
 * The admin routes declare no format, so core would render an HTML error
 * page. Clients need the stable code to tell "retry shortly" from a failure.
 * The staff data edit route answers its other refusals (400, 409, 422, 428)
 * as JSON too, so the Nuxt proxy can read the reason.
 */
final class ApplicationLockedExceptionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Drupal's exception logger runs at 50; response rendering runs below 40.
    return [KernelEvents::EXCEPTION => ['onException', 40]];
  }

  /**
   * The staff data edit route, whose refusals are answered as JSON as well.
   */
  public const DATA_EDIT_ROUTE = 'beantragt_application.admin_data_update';

  /**
   * Refusal statuses of the data edit route answered as JSON.
   *
   * 403 and 404 come from access checking and routing and stay with core.
   */
  private const DATA_EDIT_STATUSES = [400, 409, 422, 428];

  /**
   * Leaves every other exception to core.
   */
  public function onException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if ($exception instanceof HttpExceptionInterface
      && !$exception instanceof ApplicationLockedHttpException
      && in_array($exception->getStatusCode(), self::DATA_EDIT_STATUSES, TRUE)
      && $event->getRequest()->attributes->get('_route') === self::DATA_EDIT_ROUTE) {
      // The message names fields, sections and entries, never a value; the
      // Nuxt helper (upstreamDrupalErrorDetail) reads error.message.
      $response = new JsonResponse([
        'message' => $exception->getMessage(),
        'error' => ['message' => $exception->getMessage()],
      ], $exception->getStatusCode(), $exception->getHeaders());
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      $event->setResponse($response);
      return;
    }
    if (!$exception instanceof ApplicationLockedHttpException) {
      return;
    }
    $response = new JsonResponse([
      'message' => $exception->getMessage(),
      'error' => ['code' => ApplicationLockedHttpException::ERROR_CODE],
    ], $exception->getStatusCode(), $exception->getHeaders());
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $event->setResponse($response);
  }

}
