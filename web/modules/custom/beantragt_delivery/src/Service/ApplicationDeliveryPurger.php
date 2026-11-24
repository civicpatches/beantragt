<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Owns erasure of every application-linked delivery store and its files.
 */
final class ApplicationDeliveryPurger {

  public const AUDIT_RETENTION_SECONDS = 31536000;

  /**
   * Explicit inventory enforced against hook_schema by a real-entity test.
   *
   * All direct application references and dependent stores must be registered.
   * Adding a table is intentionally not enough to choose its retention policy.
   */
  public const STORES = [
    'beantragt_delivery_log' => ['application_id' => 'skeleton', 'application_uuid' => 'skeleton'],
    'beantragt_transfer_payload' => ['application_id' => 'delete'],
    'beantragt_fit_case' => ['application_id' => 'delete', 'application_uuid' => 'delete'],
    'beantragt_fit_reply_ack' => ['application_id' => 'delete'],
    'beantragt_external_response' => ['application_id' => 'documents_then_delete'],
    'beantragt_external_response_document' => ['response_uuid' => 'response_dependency'],
    'beantragt_transfer_audit' => ['application_id' => 'audit', 'application_uuid' => 'audit'],
    'beantragt_transfer_plan' => ['application_id' => 'delete', 'application_uuid' => 'delete'],
  ];

  public const FILE_USAGE_MODULES = ['beantragt_delivery'];

  /**
   * Constructs the single lifecycle cleanup boundary.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ExternalResponseService $responses,
    private readonly DeliveryLogRepository $logs,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

  /**
   * Erases all private state, retaining only bounded, value-free audit history.
   */
  public function purge(ApplicationInterface $application): void {
    $this->purgeIdentity((int) $application->id(), (string) $application->uuid());
  }

  /**
   * Handles erased or missing entities with the same complete registry.
   */
  private function purgeIdentity(int $id, string $uuid): void {
    if ($this->database->schema()->tableExists('file_usage')) {
      $file_ids = $this->database->select('file_usage', 'f')->fields('f', ['fid'])
        ->condition('module', self::FILE_USAGE_MODULES, 'IN')->condition('type', 'application')->condition('id', $id)->execute()->fetchCol();
      $files = $this->entities->getStorage('file');
      $files->delete($files->loadMultiple($file_ids));
    }
    $this->responses->cleanupForApplicationIdentity($id, $uuid);
    $log_ids = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])->condition('application_id', $id)->execute()->fetchCol();
    if ($this->database->schema()->tableExists('queue')) {
      // Serialized queue items are bounded to this module's named queue.
      $items = $this->database->select('queue', 'q')->fields('q', ['item_id', 'data'])->condition('name', DeliveryDispatcher::QUEUE_NAME)->execute();
      foreach ($items as $item) {
        $data = @unserialize($item->data, ['allowed_classes' => FALSE]);
        if (is_array($data) && ((int) ($data['applicationId'] ?? 0) === $id || in_array((int) ($data['logId'] ?? 0), array_map('intval', $log_ids), TRUE))) {
          $this->database->delete('queue')->condition('item_id', $item->item_id)->execute();
        }
      }
    }
    foreach (self::STORES as $table => $columns) {
      if (($columns['application_id'] ?? '') === 'delete' && $this->database->schema()->tableExists($table)) {
        $this->database->delete($table)->condition('application_id', $id)->execute();
      }
    }
    $this->logs->anonymizeForApplicationId($id);
  }

  /**
   * Deletes useless payload copies for accepted, permanent or superseded rows.
   */
  public function purgeTerminalPayload(int $log_id): void {
    $row = $this->logs->load($log_id);
    if ($row === NULL || empty($row['payload_reference'])) {
      return;
    }
    $superseded = (bool) $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])->condition('replaces_uuid', $row['uuid'])->range(0, 1)->execute()->fetchField();
    $permanent = $row['status'] === DeliveryLogRepository::STATUS_FAILED
      && TransferFailures::classify(TransferFailures::code($row)) === 'permanent';
    if ($row['status'] !== DeliveryLogRepository::STATUS_ACCEPTED && !$permanent && !$superseded) {
      return;
    }
    $this->database->delete('beantragt_transfer_payload')->condition('uuid', $row['payload_reference'])->execute();
    $this->database->update('beantragt_delivery_log')->fields(['payload_reference' => NULL])->condition('id', $log_id)->execute();
  }

  /**
   * Bounded store-driven repair, never a LIKE scan of application field_data.
   */
  public function cleanup(): void {
    $this->cleanupUnreferencedPayloads();
    $this->responses->cleanupStaleOrphanFiles();
    $cursor = (int) $this->state->get('beantragt_delivery.purge_cursor', 0);
    $ids = [];
    foreach (self::STORES as $table => $columns) {
      if (!isset($columns['application_id']) || !$this->database->schema()->tableExists($table)) {
        continue;
      }
      $query = $this->database->select($table, 's')->fields('s', ['application_id'])->distinct()->condition('application_id', $cursor, '>');
      if ($table === 'beantragt_delivery_log') {
        $query->condition($query->orConditionGroup()->isNotNull('payload_reference')->isNotNull('connection_snapshot')->isNotNull('validation_errors')->condition('metadata', '{}', '<>')->condition('last_error', 'integration.application_anonymised', '<>'));
      }
      elseif ($table === 'beantragt_transfer_audit') {
        $query->isNotNull('uid');
      }
      $ids = array_merge($ids, $query->orderBy('application_id')->range(0, 100)->execute()->fetchCol());
    }
    $ids = array_unique(array_map('intval', $ids));
    sort($ids, SORT_NUMERIC);
    $ids = array_slice($ids, 0, 100);
    $storage = $this->entities->getStorage('application');
    $storage->resetCache($ids);
    $applications = $storage->loadMultiple($ids);
    foreach ($ids as $id) {
      $application = $applications[$id] ?? NULL;
      if (!$application instanceof ApplicationInterface) {
        $uuid = (string) $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['application_uuid'])->condition('application_id', $id)->range(0, 1)->execute()->fetchField();
        $this->purgeIdentity($id, $uuid);
      }
      elseif ((json_decode($application->getData() ?? '', TRUE)['_anonymized'] ?? NULL) === TRUE) {
        $this->purge($application);
      }
    }
    $this->state->set('beantragt_delivery.purge_cursor', $ids === [] ? 0 : max($ids));
    $payload_cursor = (int) $this->state->get('beantragt_delivery.payload_cleanup_cursor', 0);
    $payload_logs = $this->database->select('beantragt_delivery_log', 'd')->fields('d', ['id'])->isNotNull('payload_reference')->condition('id', $payload_cursor, '>')
      ->condition('status', [DeliveryLogRepository::STATUS_ACCEPTED, DeliveryLogRepository::STATUS_FAILED], 'IN')->orderBy('id')->range(0, 100)->execute()->fetchCol();
    foreach ($payload_logs as $id) {
      $this->purgeTerminalPayload((int) $id);
    }
    $this->state->set('beantragt_delivery.payload_cleanup_cursor', $payload_logs === [] ? 0 : (int) max($payload_logs));
    $this->logs->cleanupAudit();
    $this->database->delete('beantragt_delivery_log')->condition('last_error', 'integration.application_anonymised')
      ->condition('changed', $this->time->getCurrentTime() - self::AUDIT_RETENTION_SECONDS, '<')->execute();
  }

  /**
   * Sweeps bounded, old staging blobs that never gained a transfer reference.
   */
  public function cleanupUnreferencedPayloads(): void {
    $query = $this->database->select('beantragt_transfer_payload', 'p');
    $query->leftJoin('beantragt_delivery_log', 'd', 'd.payload_reference = p.uuid');
    $ids = $query->fields('p', ['uuid'])->isNull('d.id')
      ->condition('p.created', $this->time->getCurrentTime() - 86400, '<')->range(0, 100)->execute()->fetchCol();
    if ($ids !== []) {
      $this->database->delete('beantragt_transfer_payload')->condition('uuid', $ids, 'IN')->execute();
    }
  }

}
