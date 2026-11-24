<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Plugin\QueueWorker;

use Drupal\beantragt_delivery\Service\FitConnectReplyPoller;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Polls one reply per cron queue item with durable pagination and retry state.
 *
 * @QueueWorker(
 *   id = "beantragt_delivery_replies",
 *   title = @Translation("Beantragt FIT-Connect replies"),
 *   cron = {"time" = 60}
 * )
 */
final class ReplyQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the reply queue worker.
   */
  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, protected FitConnectReplyPoller $poller) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('beantragt_delivery.fit_connect_reply_poller'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->poller->poll(1);
  }

}
