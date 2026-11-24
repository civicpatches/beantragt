<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Plugin\QueueWorker;

use Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued submitted attachment analysis jobs.
 *
 * @QueueWorker(
 *   id = "beantragt_application_attachment_analysis",
 *   title = @Translation("Beantragt attachment analysis"),
 *   cron = {"time" = 30}
 * )
 */
final class AttachmentAnalysisQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher $dispatcher
   *   Runs the analysis of one queued attachment.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected AttachmentAnalysisDispatcher $dispatcher,
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
      $container->get('beantragt_application.attachment_analysis_dispatcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $attachment_uuid = is_array($data) ? (string) ($data['attachmentId'] ?? '') : '';
    if ($attachment_uuid === '') {
      throw new \InvalidArgumentException('Queued attachment analysis item has no attachment ID.');
    }

    // Items queued before the story-aware dispatcher carry no story; the
    // dispatcher then derives it from the stored profile.
    $story = is_string($data['story'] ?? NULL) ? $data['story'] : NULL;
    // A staff rerun item carries the run id its route started.
    $rerun_run_id = is_string($data['analysisRunId'] ?? NULL) ? $data['analysisRunId'] : NULL;
    $result = $this->dispatcher->processQueuedAttachment($attachment_uuid, $story, $rerun_run_id);
    if ($result->retry) {
      throw new DelayedRequeueException(
        $this->dispatcher->retryDelaySeconds(),
        sprintf('Attachment analysis %s failed and will be retried.', $attachment_uuid),
      );
    }
  }

}
