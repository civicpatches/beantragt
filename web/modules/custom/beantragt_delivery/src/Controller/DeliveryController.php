<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Service\TransferHistoryProjection;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin delivery outbox API.
 */
final class DeliveryController extends ControllerBase {

  /**
   * Common disclosure boundary for both history routes.
   */
  private readonly TransferHistoryProjection $history;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly DeliveryLogRepository $logRepository,
    private readonly DeliveryDispatcher $dispatcher,
    private readonly ScopeAccess $scopeAccess,
    ?TransferHistoryProjection $history = NULL,
  ) {
    $this->history = $history ?? new TransferHistoryProjection($this->scopeAccess);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_delivery.log_repository'),
      $container->get('beantragt_delivery.dispatcher'),
      $container->get('beantragt_delivery.scope_access'),
      $container->get('beantragt_delivery.history_projection'),
    );
  }

  /**
   * Checks delivery permission and tenant scope membership.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->scopeAccess->access($application, $account);
  }

  /**
   * Lists delivery attempts for an application.
   */
  public function listDeliveries(ApplicationInterface $application): JsonResponse {
    return $this->noStoreResponse([
      'data' => array_map(fn(array $row): array => $this->history->neutral($application, $row) ?? array_intersect_key($row, array_flip([
        'id', 'uuid', 'applicationId', 'applicationUuid', 'adapter', 'target', 'status', 'attempts', 'metadata', 'externalId', 'receipt', 'lastError', 'created', 'changed', 'processed',
      ])), $this->logRepository->listForApplication($application, 20, TRUE)),
      'meta' => [
        'resource' => 'applicationDeliveries',
        'applicationUuid' => (string) $application->uuid(),
      ],
    ]);
  }

  /**
   * Queues or immediately dispatches a mock delivery.
   */
  public function enqueueMock(ApplicationInterface $application, Request $request): JsonResponse {
    if (!$this->mockDeliveryAllowed()) {
      return $this->noStoreResponse([
        'error' => [
          'code' => 'delivery.mock_not_allowed',
          'message' => 'Mock delivery is only available in non-production environments.',
        ],
      ], Response::HTTP_FORBIDDEN);
    }

    $body = json_decode((string) $request->getContent(), TRUE);
    $process = is_array($body) && ($body['process'] ?? FALSE) === TRUE;

    try {
      return $this->enqueueMockResult($application, $process);
    }
    catch (\InvalidArgumentException) {
      return $this->noStoreResponse([
        'error' => ['code' => 'integration.assignment_controls_delivery'],
      ], Response::HTTP_CONFLICT);
    }
  }

  /**
   * Executes the environment-guarded mock request.
   */
  private function enqueueMockResult(ApplicationInterface $application, bool $process): JsonResponse {
    if ($process) {
      return $this->noStoreResponse([
        'data' => $this->dispatcher->dispatchApplication($application, 'mock'),
        'meta' => [
          'resource' => 'applicationDelivery',
          'applicationUuid' => (string) $application->uuid(),
        ],
      ], Response::HTTP_CREATED);
    }

    $log_id = $this->dispatcher->enqueue($application, 'mock');
    return $this->noStoreResponse([
      'data' => [
        'logId' => $log_id,
        'status' => DeliveryLogRepository::STATUS_QUEUED,
      ],
      'meta' => [
        'resource' => 'applicationDelivery',
        'applicationUuid' => (string) $application->uuid(),
      ],
    ], Response::HTTP_ACCEPTED);
  }

  /**
   * Returns a no-store JSON response.
   *
   * @param array<string, mixed> $payload
   *   Response payload.
   * @param int $status
   *   HTTP status code.
   */
  private function noStoreResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * Returns TRUE when the local mock adapter may mutate delivery state.
   */
  private function mockDeliveryAllowed(): bool {
    $environment = strtolower((string) (getenv('BEANTRAGT_ENV') ?: ''));
    if ($environment !== '') {
      return in_array($environment, ['dev', 'local', 'ddev', 'development', 'test', 'testing'], TRUE);
    }

    return getenv('IS_DDEV_PROJECT') === 'true';
  }

}
