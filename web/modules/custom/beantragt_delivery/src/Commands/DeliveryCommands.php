<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Commands;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Gateway\FitConnectDeliveryGateway;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\LocalMockReceiver;
use Drupal\beantragt_delivery\Service\LocalWebhookReceiver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for delivery outbox operations.
 */
final class DeliveryCommands extends DrushCommands {

  /**
   * Constructs the command handler.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DeliveryDispatcher $dispatcher,
    private readonly DeliveryLogRepository $logRepository,
    private readonly FitConnectDeliveryGateway $fitConnectGateway,
    private readonly LocalMockReceiver $localMockReceiver,
    private readonly LocalWebhookReceiver $localWebhookReceiver,
    private readonly ?StateInterface $state = NULL,
  ) {
    parent::__construct();
  }

  /**
   * Prints legacy policies that need an explicitly activated assignment.
   *
   * @command beantragt:delivery:legacy-policies
   * @option json Print JSON output.
   */
  public function legacyPolicies(array $options = ['json' => TRUE]): void {
    $this->printOutput(['data' => $this->state?->get('beantragt_delivery.legacy_policies', []) ?? []], (bool) $options['json']);
  }

  /**
   * Queues or processes one application delivery.
   *
   * @command beantragt:delivery:enqueue
   * @aliases beantragt-delivery-enqueue
   * @option adapter Delivery adapter, "mock" or "webhook"; FIT-Connect requires a case transfer.
   * @option process Dispatch immediately instead of only queueing.
   * @option json Print JSON output.
   * @usage drush beantragt:delivery:enqueue 123 --adapter=mock --process --json
   *   Dispatch application 123 through the mock gateway and print JSON.
   */
  public function enqueue(
    string $application,
    array $options = [
      'adapter' => 'mock',
      'process' => FALSE,
      'json' => FALSE,
    ],
  ): void {
    $entity = $this->loadApplication($application);
    if (!$entity instanceof ApplicationInterface) {
      throw new \InvalidArgumentException(sprintf('Application "%s" was not found.', $application));
    }

    if ((bool) ($options['process'] ?? FALSE)) {
      $output = $this->dispatcher->dispatchApplication($entity, (string) ($options['adapter'] ?? 'mock'));
    }
    else {
      $output = [
        'logId' => $this->dispatcher->enqueue($entity, (string) ($options['adapter'] ?? 'mock')),
        'status' => DeliveryLogRepository::STATUS_QUEUED,
      ];
    }

    $this->printOutput($output, (bool) ($options['json'] ?? FALSE));
  }

  /**
   * Dispatches an existing delivery log.
   *
   * @command beantragt:delivery:run
   * @aliases beantragt-delivery-run
   * @option json Print JSON output.
   */
  public function run(int $log_id, array $options = ['json' => FALSE]): void {
    $result = $this->dispatcher->dispatchLog($log_id);
    $this->printOutput([
      'logId' => $log_id,
      'result' => $this->dispatcher->resultToArray($result),
    ], (bool) ($options['json'] ?? FALSE));
  }

  /**
   * Lists delivery attempts for one application.
   *
   * @command beantragt:delivery:log
   * @aliases beantragt-delivery-log
   * @option json Print JSON output.
   */
  public function log(string $application, array $options = ['json' => FALSE]): void {
    $entity = $this->loadApplication($application);
    if (!$entity instanceof ApplicationInterface) {
      throw new \InvalidArgumentException(sprintf('Application "%s" was not found.', $application));
    }

    $this->printOutput($this->logRepository->listForApplication($entity), (bool) ($options['json'] ?? FALSE));
  }

  /**
   * Checks FIT-Connect transport readiness without sending an application.
   *
   * @command beantragt:delivery:fit-connect-preflight
   * @aliases beantragt-fit-connect-preflight
   * @option fetch-token Fetch an OAuth token without printing the token value.
   * @option json Print JSON output.
   */
  public function fitConnectPreflight(
    array $options = [
      'fetch-token' => FALSE,
      'json' => FALSE,
    ],
  ): void {
    $this->printOutput(
      $this->fitConnectGateway->preflight((bool) ($options['fetch-token'] ?? FALSE)),
      (bool) ($options['json'] ?? FALSE),
    );
  }

  /**
   * Lists the local mock receiver inbox.
   *
   * @command beantragt:delivery:mock-inbox
   * @aliases beantragt-delivery-mock-inbox
   * @option limit Maximum number of records to show.
   * @option clear Clear the inbox after printing it.
   * @option json Print JSON output.
   */
  public function mockInbox(
    array $options = [
      'limit' => 10,
      'clear' => FALSE,
      'json' => FALSE,
    ],
  ): void {
    $records = $this->localMockReceiver->list((int) ($options['limit'] ?? 10));
    if ((bool) ($options['clear'] ?? FALSE)) {
      $this->localMockReceiver->clear();
    }
    $this->printOutput([
      'records' => $records,
      'count' => count($records),
    ], (bool) ($options['json'] ?? FALSE));
  }

  /**
   * Lists the local signed-webhook receiver inbox.
   *
   * @command beantragt:delivery:webhook-inbox
   * @aliases beantragt-delivery-webhook-inbox
   * @option limit Maximum number of records to show.
   * @option clear Clear the inbox after printing it.
   * @option json Print JSON output.
   */
  public function webhookInbox(
    array $options = [
      'limit' => 10,
      'clear' => FALSE,
      'json' => FALSE,
    ],
  ): void {
    $records = $this->localWebhookReceiver->list((int) ($options['limit'] ?? 10));
    if ((bool) ($options['clear'] ?? FALSE)) {
      $this->localWebhookReceiver->clear();
    }
    $this->printOutput([
      'records' => $records,
      'count' => count($records),
    ], (bool) ($options['json'] ?? FALSE));
  }

  /**
   * Loads an application by numeric ID or UUID.
   */
  private function loadApplication(string $application): ?ApplicationInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    if (ctype_digit($application)) {
      $entity = $storage->load((int) $application);
      return $entity instanceof ApplicationInterface ? $entity : NULL;
    }

    $matches = $storage->loadByProperties(['uuid' => $application]);
    $entity = reset($matches);
    return $entity instanceof ApplicationInterface ? $entity : NULL;
  }

  /**
   * Prints command output.
   *
   * @param mixed[] $output
   *   Structured output.
   * @param bool $json
   *   Whether to print JSON.
   */
  private function printOutput(array $output, bool $json): void {
    if ($json) {
      $this->io()->writeln((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return;
    }

    $this->io()->writeln(print_r($output, TRUE));
  }

}
