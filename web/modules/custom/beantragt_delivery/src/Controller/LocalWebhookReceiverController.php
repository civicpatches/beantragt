<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_delivery\Service\LocalWebhookReceiver;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local signed-webhook receiver for DDEV smoke tests.
 */
final class LocalWebhookReceiverController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly LocalWebhookReceiver $receiver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_delivery.local_webhook_receiver'),
    );
  }

  /**
   * Receives a signed webhook event.
   */
  public function receive(Request $request): JsonResponse {
    $headers = [];
    foreach ($request->headers->all() as $name => $values) {
      $headers[strtolower($name)] = (string) ($values[0] ?? '');
    }

    $result = $this->receiver->receive((string) $request->getContent(), $headers);
    $status = (int) ($result['statusCode'] ?? Response::HTTP_BAD_REQUEST);
    unset($result['statusCode']);

    $response = new JsonResponse($result, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
