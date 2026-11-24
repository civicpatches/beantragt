<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_delivery\Value\WebhookSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API for outbound delivery integration settings.
 */
final class DeliverySettingsController extends ControllerBase {

  private const ADAPTERS = ['mock', 'webhook'];
  private const TOP_LEVEL_KEYS = ['enabled', 'autoEnqueue', 'defaultAdapter', 'maxAttempts', 'webhook'];
  // Older Outbox clients echo allowedHosts on every save. It is read-only.
  private const WEBHOOK_KEYS = ['targetUrl', 'secretEnv', 'timeoutSeconds', 'allowedHosts'];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $deliveryConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Reads delivery integration settings without exposing secrets.
   */
  public function get(): JsonResponse {
    return $this->settingsResponse();
  }

  /**
   * Updates delivery integration settings.
   */
  public function patch(Request $request): JsonResponse {
    $body = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($body) || array_is_list($body) || !is_array($body['data'] ?? NULL)) {
      return $this->errorResponse('delivery.settings_invalid', 'Invalid delivery settings payload.', Response::HTTP_BAD_REQUEST);
    }

    try {
      $settings = $this->normalizeSettings($body['data']);
    }
    catch (\InvalidArgumentException $e) {
      return $this->errorResponse('delivery.settings_invalid', $e->getMessage(), Response::HTTP_BAD_REQUEST);
    }

    $config = $this->deliveryConfigFactory->getEditable('beantragt_delivery.settings');
    foreach ($settings as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();

    return $this->settingsResponse();
  }

  /**
   * Builds the settings response.
   *
   * @param int $status
   *   HTTP response status.
   */
  private function settingsResponse(int $status = Response::HTTP_OK): JsonResponse {
    $config = $this->deliveryConfigFactory->get('beantragt_delivery.settings');
    $mock = $config->get('mock');
    $mock = is_array($mock) ? $mock : [];
    $webhook = WebhookSettings::fromConfig($this->deliveryConfigFactory);
    $webhook_missing = $webhook->missingForDispatch();
    $webhook_host = $webhook->targetHost();
    if ($webhook->targetUrl !== '' && $webhook_host !== '' && !in_array($webhook_host, $webhook->allowedHosts, TRUE)) {
      $webhook_missing[] = 'target_host_not_allowed';
    }
    $webhook_missing = array_values(array_unique($webhook_missing));
    $payload = [
      'data' => [
        'enabled' => (bool) $config->get('enabled'),
        'autoEnqueue' => (bool) $config->get('auto_enqueue'),
        'defaultAdapter' => (string) ($config->get('default_adapter') ?: 'mock'),
        'defaultAdapterRequiresAssignment' => $config->get('default_adapter') === 'fit_connect',
        'maxAttempts' => max(1, min(10, (int) ($config->get('max_attempts') ?: 3))),
        'adapters' => [
          'mock' => [
            'available' => TRUE,
            'target' => (string) ($mock['target'] ?? 'local-mock'),
          ],
          'webhook' => [
            'available' => $webhook_missing === [],
            'targetUrl' => $webhook->targetUrl,
            'targetHost' => $webhook_host,
            'secretEnv' => $webhook->secretEnv,
            'secretConfigured' => $webhook->secret !== '',
            'allowedHosts' => $webhook->allowedHosts,
            'timeoutSeconds' => $webhook->timeoutSeconds,
            'missing' => $webhook_missing,
          ],
          'fitConnect' => [
            'available' => FALSE,
            'status' => 'test_transport_operator_configuration',
          ],
        ],
      ],
      'meta' => [
        'resource' => 'deliverySettings',
        'scope' => 'platform',
      ],
    ];

    return $this->noStoreResponse($payload, $status);
  }

  /**
   * Normalizes incoming settings.
   *
   * @param array<string, mixed> $data
   *   Request data.
   *
   * @return array<string, mixed>
   *   Config key/value map.
   */
  private function normalizeSettings(array $data): array {
    $this->assertKnownKeys($data, self::TOP_LEVEL_KEYS, 'settings');

    $settings = [];
    if (array_key_exists('enabled', $data)) {
      $settings['enabled'] = $this->bool($data['enabled'], 'enabled');
    }
    if (array_key_exists('autoEnqueue', $data)) {
      $settings['auto_enqueue'] = $this->bool($data['autoEnqueue'], 'autoEnqueue');
    }
    if (array_key_exists('defaultAdapter', $data)) {
      $settings['default_adapter'] = $this->adapter($data['defaultAdapter']);
    }
    if (array_key_exists('maxAttempts', $data)) {
      $settings['max_attempts'] = $this->boundedInt($data['maxAttempts'], 'maxAttempts', 1, 10);
    }
    if (array_key_exists('webhook', $data)) {
      if (!is_array($data['webhook']) || array_is_list($data['webhook'])) {
        throw new \InvalidArgumentException('webhook must be an object.');
      }
      $this->assertKnownKeys($data['webhook'], self::WEBHOOK_KEYS, 'webhook');
      $webhook = $data['webhook'];
      if (array_key_exists('targetUrl', $webhook)) {
        $settings['webhook.target_url'] = $this->targetUrl($webhook['targetUrl']);
      }
      if (array_key_exists('secretEnv', $webhook)) {
        $settings['webhook.secret_env'] = $this->secretEnv($webhook['secretEnv']);
      }
      if (array_key_exists('timeoutSeconds', $webhook)) {
        $settings['webhook.timeout_seconds'] = $this->boundedInt($webhook['timeoutSeconds'], 'timeoutSeconds', 1, 30);
      }
    }

    return $settings;
  }

  /**
   * Rejects unknown request keys.
   *
   * @param array<string, mixed> $data
   *   Incoming object.
   * @param string[] $allowed
   *   Allowed key names.
   * @param string $scope
   *   Request object name.
   */
  private function assertKnownKeys(array $data, array $allowed, string $scope): void {
    foreach (array_keys($data) as $key) {
      if (!in_array($key, $allowed, TRUE)) {
        throw new \InvalidArgumentException($scope . ' contains an unknown key.');
      }
    }
  }

  /**
   * Normalizes a boolean field.
   */
  private function bool(mixed $value, string $field): bool {
    if (!is_bool($value)) {
      throw new \InvalidArgumentException($field . ' must be a boolean.');
    }
    return $value;
  }

  /**
   * Normalizes one delivery adapter.
   */
  private function adapter(mixed $value): string {
    if (!is_string($value) || !in_array($value, self::ADAPTERS, TRUE)) {
      throw new \InvalidArgumentException('defaultAdapter must be one of mock or webhook.');
    }
    return $value;
  }

  /**
   * Normalizes a bounded integer.
   */
  private function boundedInt(mixed $value, string $field, int $min, int $max): int {
    $int = is_int($value) ? $value : (is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : NULL);
    if ($int === NULL || $int < $min || $int > $max) {
      throw new \InvalidArgumentException(sprintf('%s must be between %d and %d.', $field, $min, $max));
    }
    return $int;
  }

  /**
   * Normalizes a webhook target URL.
   */
  private function targetUrl(mixed $value): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException('targetUrl must be a string.');
    }
    $url = trim($value);
    if ($url === '') {
      return '';
    }
    if (strlen($url) > 2048) {
      throw new \InvalidArgumentException('targetUrl is too long.');
    }
    $parts = parse_url($url);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if ($scheme !== 'https' || $host === '') {
      throw new \InvalidArgumentException('targetUrl must be an HTTPS URL with a host.');
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
      throw new \InvalidArgumentException('targetUrl must not contain credentials, query strings or fragments.');
    }
    return $url;
  }

  /**
   * Normalizes the environment variable name holding the webhook secret.
   */
  private function secretEnv(mixed $value): string {
    if (!is_string($value) || preg_match(WebhookSettings::SECRET_ENV_PATTERN, trim($value)) !== 1) {
      throw new \InvalidArgumentException('secretEnv must be an uppercase environment variable name starting with BEANTRAGT_WEBHOOK_ and ending with _SECRET.');
    }
    return trim($value);
  }

  /**
   * Returns a safe error response.
   *
   * @param string $code
   *   Safe error code.
   * @param string $message
   *   Safe error message.
   * @param int $status
   *   HTTP response status.
   */
  private function errorResponse(string $code, string $message, int $status): JsonResponse {
    return $this->noStoreResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
    ], $status);
  }

  /**
   * Returns a no-store JSON response.
   *
   * @param array<string, mixed> $payload
   *   Response payload.
   * @param int $status
   *   HTTP response status.
   */
  private function noStoreResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
