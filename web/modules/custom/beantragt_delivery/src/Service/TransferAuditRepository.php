<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\Core\Database\Connection;

/**
 * Central durable audit writer with bounded repetition of denied requests.
 */
final class TransferAuditRepository {

  /**
   * Constructs the audit writer.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Stores a closed event.
   *
   * Collapses repeated denials per actor and case/minute.
   */
  public function append(array $event): bool {
    $transaction = $this->database->startTransaction();
    try {
      if ($event['outcome'] === 'denied') {
        // Serializing on the actor makes repeat collapse atomic across
        // requests.
        if ($this->database->schema()->tableExists('users')) {
          $actor = $this->database->select('users', 'u')->fields('u', ['uid'])->condition('uid', (int) $event['uid']);
          $actor->forUpdate();
          $actor->execute()->fetchField();
        }
        $query = $this->database->select('beantragt_transfer_audit', 'a')->fields('a', ['id']);
        foreach (['uid', 'application_id', 'transfer_uuid', 'action', 'outcome', 'code'] as $key) {
          $query->condition($key, $event[$key]);
        }
        if ($query->condition('created', (int) $event['created'] - 60, '>=')->range(0, 1)->execute()->fetchField() !== FALSE) {
          return FALSE;
        }
      }
      $this->database->insert('beantragt_transfer_audit')->fields($event)->execute();
      return TRUE;
    }
    finally {
      unset($transaction);
    }
  }

}
