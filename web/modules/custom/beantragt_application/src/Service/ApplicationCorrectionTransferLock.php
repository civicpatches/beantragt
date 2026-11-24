<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Checks whether a transfer prevents correcting an application's data.
 */
final class ApplicationCorrectionTransferLock {

  /**
   * Constructs the transfer check shared by data and proof corrections.
   */
  public function __construct(
    private readonly ?Connection $database,
    private readonly ModuleHandlerInterface $modules,
  ) {}

  /**
   * Checks for a planned, attempted or frozen transfer under the case lock.
   */
  public function blocksCorrection(int $application_id): bool {
    if ($this->database === NULL) {
      return TRUE;
    }
    if (!$this->database->schema()->tableExists('beantragt_delivery_log')) {
      // Only sites without the optional delivery module have no outbox.
      return $this->modules->moduleExists('beantragt_delivery');
    }
    $query = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])
      ->condition('application_id', $application_id);
    $planned = $query->orConditionGroup()
      ->condition('status', [
        DeliveryLogRepository::STATUS_AWAITING_APPROVAL,
        DeliveryLogRepository::STATUS_QUEUED,
        DeliveryLogRepository::STATUS_PROCESSING,
        DeliveryLogRepository::STATUS_ACCEPTED,
      ], 'IN')
      ->condition('attempts', 0, '>')
      ->condition('payload_sha256', '', '<>');
    return $query->condition($planned)->range(0, 1)->execute()->fetchField() !== FALSE;
  }

}
