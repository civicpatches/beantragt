<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Gateway;

use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\beantragt_delivery\Value\WebhookSettings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Sends signed, non-sensitive application events to an external webhook.
 */
final class WebhookDeliveryGateway implements DeliveryGatewayInterface {

  public const EVENT_TYPE_APPLICATION_SUBMITTED = 'application.submitted';

  /**
   * Constructs the webhook gateway.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'webhook';
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(DeliveryEnvelope $envelope): DeliveryResult {
    $settings = WebhookSettings::fromConfig($this->configFactory);
    $metadata = [
      'gateway' => $this->id(),
      ...$settings->toSafeMetadata(),
    ];

    $missing = $settings->missingForDispatch();
    if ($missing !== []) {
      return DeliveryResult::failed(
        'webhook.missing_configuration',
        'Webhook configuration is incomplete.',
        [
          ...$metadata,
          'missing' => $missing,
        ],
      );
    }

    $target_guard = $this->validateTarget($settings);
    if ($target_guard instanceof DeliveryResult) {
      return $target_guard;
    }

    $payload = $this->payload($envelope);
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signature = self::sign($body, $settings->secret);
    $headers = [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
      'X-Beantragt-Event-Id' => $payload['eventId'],
      'X-Beantragt-Event-Type' => $payload['eventType'],
      'X-Beantragt-Timestamp' => (string) $payload['timestamp'],
      'X-Beantragt-Signature' => $signature,
    ];

    try {
      $response = $this->httpClient->request('POST', $settings->targetUrl, [
        RequestOptions::BODY => $body,
        RequestOptions::HEADERS => $headers,
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::ALLOW_REDIRECTS => FALSE,
        RequestOptions::VERIFY => TRUE,
        RequestOptions::TIMEOUT => $settings->timeoutSeconds,
      ]);
    }
    catch (GuzzleException $e) {
      return DeliveryResult::failed(
        'webhook.transport_error',
        'Webhook request failed.',
        [
          ...$metadata,
          'eventId' => $payload['eventId'],
          'exceptionClass' => $e::class,
        ],
      );
    }

    $status_code = $response->getStatusCode();
    if ($status_code < 200 || $status_code >= 300) {
      return DeliveryResult::failed(
        'webhook.rejected',
        'Webhook endpoint rejected the event.',
        [
          ...$metadata,
          'eventId' => $payload['eventId'],
          'statusCode' => $status_code,
        ],
      );
    }

    return DeliveryResult::accepted(
      'webhook:' . $payload['eventId'],
      'WEBHOOK-' . strtoupper(substr($payload['eventId'], 0, 12)),
      [
        ...$metadata,
        'eventId' => $payload['eventId'],
        'statusCode' => $status_code,
      ],
    );
  }

  /**
   * Computes the HMAC signature header value.
   */
  public static function sign(string $body, string $secret): string {
    return 'sha256=' . hash_hmac('sha256', $body, $secret);
  }

  /**
   * Builds the outbound event payload.
   *
   * @return array<string, mixed>
   *   Event payload without raw form values or file data.
   */
  private function payload(DeliveryEnvelope $envelope): array {
    return [
      'eventId' => substr(hash('sha256', implode(':', [
        $envelope->applicationUuid,
        $envelope->dataHash,
        (string) $envelope->schemaVersion,
        self::EVENT_TYPE_APPLICATION_SUBMITTED,
      ])), 0, 32),
      'eventType' => self::EVENT_TYPE_APPLICATION_SUBMITTED,
      'timestamp' => $this->time->getRequestTime(),
      'application' => [
        'uuid' => $envelope->applicationUuid,
        'referenceNumber' => $envelope->referenceNumber,
        'dataHash' => $envelope->dataHash,
        'fieldCount' => $envelope->fieldCount,
        'attachmentCount' => $envelope->attachmentCount,
      ],
      'schema' => array_filter([
        'uuid' => $envelope->schemaUuid,
        'label' => $envelope->schemaLabel,
        'version' => $envelope->schemaVersion,
      ], static fn(mixed $value): bool => $value !== '' && $value !== 0),
      'workspace' => $envelope->workspace,
      'attachments' => $envelope->attachmentManifest,
    ];
  }

  /**
   * Validates target URL policy before sending the signed request.
   */
  private function validateTarget(WebhookSettings $settings): ?DeliveryResult {
    $parts = parse_url($settings->targetUrl);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $host = $settings->targetHost();
    $metadata = [
      'gateway' => $this->id(),
      ...$settings->toSafeMetadata(),
    ];

    // HTTP is reserved for an explicitly enabled local DDEV test receiver.
    $local_http = $scheme === 'http'
      && getenv('IS_DDEV_PROJECT') === 'true'
      && getenv('BEANTRAGT_WEBHOOK_ALLOW_HTTP_LOCAL') === '1';
    if (($scheme !== 'https' && !$local_http) || $host === '') {
      return DeliveryResult::failed(
        'webhook.target_url_not_allowed',
        'Webhook target URL must use HTTPS with a host.',
        $metadata,
      );
    }

    if (!in_array($host, $settings->allowedHosts, TRUE)) {
      return DeliveryResult::failed(
        'webhook.target_host_not_allowed',
        'Webhook target host is not allowlisted.',
        $metadata,
      );
    }

    return NULL;
  }

}
