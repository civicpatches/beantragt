<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Plugin\QueueWorker;

use Drupal\beantragt_delivery\Exception\DeliveryAlreadyAcceptedException;
use Drupal\beantragt_delivery\Exception\DeliveryLogNotFoundException;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued application delivery attempts.
 *
 * @QueueWorker(
 *   id = "beantragt_delivery_dispatch",
 *   title = @Translation("Beantragt delivery dispatch"),
 *   cron = {"time" = 30}
 * )
 */
final class DeliveryQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin identifier.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\beantragt_delivery\Service\DeliveryDispatcher $dispatcher
   *   Delivery dispatcher.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected DeliveryDispatcher $dispatcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('beantragt_delivery.dispatcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $log_id = is_array($data) ? (int) ($data['logId'] ?? 0) : 0;
    if ($log_id <= 0) {
      throw new \InvalidArgumentException('Queued delivery item has no delivery log ID.');
    }
    try {
      $result = $this->dispatcher->dispatchLog($log_id);
    }
    catch (DeliveryAlreadyAcceptedException | DeliveryLogNotFoundException) {
      return;
    }

    if ($this->dispatcher->shouldRetryLog($log_id, $result)) {
      throw new DelayedRequeueException(
        $this->dispatcher->retryDelaySeconds(),
        sprintf('Delivery log %d failed and will be retried.', $log_id),
      );
    }
  }

}
