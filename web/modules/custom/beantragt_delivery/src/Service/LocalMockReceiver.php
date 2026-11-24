<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stores local mock-delivery receipts for smoke tests and demos.
 */
final class LocalMockReceiver {

  private const STATE_KEY = 'beantragt_delivery.local_mock_receiver_inbox';
  private const MAX_RECORDS = 20;

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ?bool $enabled = NULL,
  ) {}

  /**
   * Receives one non-sensitive delivery envelope.
   *
   * @return array<string, mixed>|null
   *   Stored receiver record, or NULL when disabled.
   */
  public function receive(DeliveryEnvelope $envelope): ?array {
    if (!$this->isEnabled()) {
      return NULL;
    }

    $received_at = $this->time->getRequestTime();
    $inbox = $this->list(self::MAX_RECORDS);
    $record = [
      'id' => substr(hash('sha256', implode(':', [
        $envelope->applicationUuid,
        $envelope->dataHash,
        (string) $envelope->fieldCount,
        (string) $envelope->attachmentCount,
        (string) $this->time->getCurrentMicroTime(),
      ])), 0, 24),
      'receivedAt' => $received_at,
      'applicationUuid' => $envelope->applicationUuid,
      'referenceNumber' => $envelope->referenceNumber,
      'schema' => array_filter([
        'id' => $envelope->schemaUuid,
        'label' => $envelope->schemaLabel,
        'version' => $envelope->schemaVersion,
      ], static fn(mixed $value): bool => $value !== '' && $value !== 0),
      'fieldCount' => $envelope->fieldCount,
      'attachmentCount' => $envelope->attachmentCount,
      'attachments' => $envelope->attachmentManifest,
      'workspace' => $envelope->workspace,
    ];

    array_unshift($inbox, $record);
    $this->state->set(self::STATE_KEY, array_slice($inbox, 0, self::MAX_RECORDS));

    return $record;
  }

  /**
   * Lists stored receiver records.
   *
   * @return array<int, array<string, mixed>>
   *   Receiver inbox, newest first.
   */
  public function list(int $limit = 10): array {
    $raw = $this->state->get(self::STATE_KEY, []);
    $records = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    return array_slice($records, 0, max(1, min(self::MAX_RECORDS, $limit)));
  }

  /**
   * Clears the local receiver inbox.
   */
  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Returns TRUE when the local receiver may persist smoke-test records.
   */
  private function isEnabled(): bool {
    if ($this->enabled !== NULL) {
      return $this->enabled;
    }

    $environment = strtolower((string) (getenv('BEANTRAGT_ENV') ?: ''));
    if ($environment !== '') {
      return in_array($environment, ['dev', 'local', 'ddev', 'development', 'test', 'testing'], TRUE);
    }

    return getenv('IS_DDEV_PROJECT') === 'true';
  }

}
