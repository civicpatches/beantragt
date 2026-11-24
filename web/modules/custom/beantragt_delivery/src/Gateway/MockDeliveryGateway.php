<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Gateway;

use Drupal\beantragt_delivery\Service\LocalMockReceiver;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;

/**
 * Deterministic local gateway for demos, tests and CI.
 */
final class MockDeliveryGateway implements DeliveryGatewayInterface {

  public function __construct(
    private readonly ?LocalMockReceiver $receiver = NULL,
    private readonly ?bool $enabled = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'mock';
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(DeliveryEnvelope $envelope): DeliveryResult {
    if (!$this->isEnabled()) {
      return DeliveryResult::failed(
        'delivery.mock_not_allowed',
        'Mock delivery is only available in non-production environments.',
        ['gateway' => $this->id()],
      );
    }

    $receipt_hash = hash('sha256', implode(':', [
      $envelope->applicationUuid,
      $envelope->dataHash,
      (string) $envelope->fieldCount,
      (string) $envelope->attachmentCount,
    ]));

    $receiver_record = $this->receiver?->receive($envelope);

    return DeliveryResult::accepted(
      'mock:' . substr($receipt_hash, 0, 24),
      'MOCK-' . strtoupper(substr($receipt_hash, 0, 12)),
      array_filter([
        'gateway' => $this->id(),
        'fieldCount' => $envelope->fieldCount,
        'attachmentCount' => $envelope->attachmentCount,
        'receiver' => is_array($receiver_record) ? [
          'id' => $receiver_record['id'] ?? '',
          'receivedAt' => $receiver_record['receivedAt'] ?? 0,
          'fieldCount' => $receiver_record['fieldCount'] ?? 0,
          'attachmentCount' => $receiver_record['attachmentCount'] ?? 0,
          'attachments' => $receiver_record['attachments'] ?? [],
        ] : NULL,
      ], static fn(mixed $value): bool => $value !== NULL),
    );
  }

  /**
   * Returns TRUE when the mock gateway may accept delivery attempts.
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
