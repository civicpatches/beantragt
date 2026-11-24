<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Gateway\WebhookDeliveryGateway;
use Drupal\beantragt_delivery\Value\WebhookSettings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stores signed local webhook events for DDEV smoke tests and demos.
 */
final class LocalWebhookReceiver {

  private const STATE_KEY = 'beantragt_delivery.local_webhook_receiver_inbox';
  private const MAX_RECORDS = 20;

  /**
   * Constructs the local webhook receiver.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?bool $enabled = NULL,
  ) {}

  /**
   * Receives one signed webhook request body.
   *
   * @param string $body
   *   Signed request body.
   * @param array<string, string> $headers
   *   Request headers with lowercase keys.
   *
   * @return array<string, mixed>
   *   Verification and receipt result.
   */
  public function receive(string $body, array $headers): array {
    if (!$this->isEnabled()) {
      return [
        'accepted' => FALSE,
        'statusCode' => 403,
        'error' => 'webhook.receiver_not_allowed',
      ];
    }

    $settings = WebhookSettings::fromConfig($this->configFactory);
    if ($settings->secret === '') {
      return [
        'accepted' => FALSE,
        'statusCode' => 500,
        'error' => 'webhook.receiver_missing_secret',
      ];
    }

    $signature = $headers['x-beantragt-signature'] ?? '';
    $expected = WebhookDeliveryGateway::sign($body, $settings->secret);
    if (!hash_equals($expected, $signature)) {
      return [
        'accepted' => FALSE,
        'statusCode' => 401,
        'error' => 'webhook.signature_invalid',
      ];
    }

    try {
      $payload = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [
        'accepted' => FALSE,
        'statusCode' => 400,
        'error' => 'webhook.payload_invalid',
      ];
    }

    if (!is_array($payload) || !is_string($payload['eventId'] ?? NULL)) {
      return [
        'accepted' => FALSE,
        'statusCode' => 400,
        'error' => 'webhook.payload_invalid',
      ];
    }

    $record = $this->record($payload, $headers);
    $inbox = $this->list(self::MAX_RECORDS);
    array_unshift($inbox, $record);
    $this->state->set(self::STATE_KEY, array_slice($inbox, 0, self::MAX_RECORDS));

    return [
      'accepted' => TRUE,
      'statusCode' => 202,
      'receipt' => $record['receipt'],
      'eventId' => $record['eventId'],
    ];
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
   * Builds a stored, non-sensitive receiver record.
   *
   * @param array<string, mixed> $payload
   *   Verified event payload.
   * @param array<string, string> $headers
   *   Request headers with lowercase keys.
   *
   * @return array<string, mixed>
   *   Receiver record.
   */
  private function record(array $payload, array $headers): array {
    $application = is_array($payload['application'] ?? NULL) ? $payload['application'] : [];
    $attachments = is_array($payload['attachments'] ?? NULL) ? $payload['attachments'] : [];
    return [
      'id' => substr(hash('sha256', implode(':', [
        (string) ($payload['eventId'] ?? ''),
        (string) ($application['uuid'] ?? ''),
        (string) $this->time->getCurrentMicroTime(),
      ])), 0, 24),
      'receipt' => 'WEBHOOK-RX-' . strtoupper(substr((string) ($payload['eventId'] ?? ''), 0, 12)),
      'receivedAt' => $this->time->getRequestTime(),
      'eventId' => (string) ($payload['eventId'] ?? ''),
      'eventType' => (string) ($payload['eventType'] ?? ''),
      'applicationUuid' => (string) ($application['uuid'] ?? ''),
      'referenceNumber' => (string) ($application['referenceNumber'] ?? ''),
      'fieldCount' => max(0, (int) ($application['fieldCount'] ?? 0)),
      'attachmentCount' => max(0, (int) ($application['attachmentCount'] ?? 0)),
      'attachments' => $attachments,
      'workspace' => is_array($payload['workspace'] ?? NULL) ? $payload['workspace'] : [],
      'signaturePrefix' => substr($headers['x-beantragt-signature'] ?? '', 0, 18),
    ];
  }

  /**
   * Returns TRUE when the receiver may persist local smoke-test records.
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
