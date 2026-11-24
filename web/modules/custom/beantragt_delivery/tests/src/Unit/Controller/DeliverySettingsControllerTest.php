<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Controller;

use Drupal\beantragt_delivery\Controller\DeliverySettingsController;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests delivery integration settings normalization.
 */
#[CoversClass(DeliverySettingsController::class)]
#[Group('beantragt_delivery')]
#[BackupGlobals(TRUE)]
final class DeliverySettingsControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_WEBHOOK_SECRET');
    parent::tearDown();
  }

  /**
   * Settings are returned without exposing secret values.
   */
  public function testGetSettingsDoesNotExposeSecret(): void {
    putenv('BEANTRAGT_WEBHOOK_SECRET=super-secret');

    $controller = new DeliverySettingsController($this->configFactory($this->settings()));
    $response = $controller->get();
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame('webhook', $payload['data']['defaultAdapter']);
    $this->assertTrue($payload['data']['adapters']['webhook']['secretConfigured']);
    $this->assertSame('receiver.example', $payload['data']['adapters']['webhook']['targetHost']);
    $this->assertStringNotContainsString('super-secret', (string) $response->getContent());
  }

  /**
   * Legacy FIT config stays readable but cannot be written as the default.
   */
  public function testLegacyFitDefaultIsReadableButRejectedForNewWrites(): void {
    $settings = $this->settings();
    $settings['default_adapter'] = 'fit_connect';
    $editable = $this->editableConfig($settings);
    $controller = new DeliverySettingsController($this->configFactory($settings, $editable));
    $response = $controller->get();
    self::assertSame(200, $response->getStatusCode());
    self::assertSame('fit_connect', json_decode($response->getContent(), TRUE)['data']['defaultAdapter']);
    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings', 'PATCH', [], [], [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['data' => ['defaultAdapter' => 'fit_connect']], JSON_THROW_ON_ERROR),
    ));
    self::assertSame(400, $response->getStatusCode());
    self::assertSame('delivery.settings_invalid', json_decode($response->getContent(), TRUE)['error']['code']);
    self::assertFalse($editable->saved);
    self::assertSame([], $editable->written);
    $schema = Yaml::decode((string) file_get_contents(dirname(__DIR__, 4) . '/config/schema/beantragt_delivery.schema.yml'));
    self::assertSame(['mock', 'webhook'], $schema['beantragt_delivery.settings']['mapping']['default_adapter']['constraints']['Choice']);
  }

  /**
   * Route access keeps platform settings separate from form delivery policies.
   */
  public function testSettingsRoutesUseDedicatedPermission(): void {
    $routing = Yaml::decode((string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_delivery.routing.yml'));

    $this->assertSame(
      'administer application delivery settings',
      $routing['beantragt_delivery.admin_delivery_settings']['requirements']['_permission'] ?? NULL,
    );
    $this->assertSame(
      'administer application delivery settings',
      $routing['beantragt_delivery.admin_delivery_settings_update']['requirements']['_permission'] ?? NULL,
    );
    $this->assertSame(
      'TRUE',
      $routing['beantragt_delivery.admin_delivery_settings_update']['requirements']['_csrf_request_header_token'] ?? NULL,
    );
  }

  /**
   * Readiness mirrors the dispatch target-host allowlist check.
   */
  public function testGetSettingsMarksMismatchedWebhookHostUnavailable(): void {
    putenv('BEANTRAGT_WEBHOOK_SECRET=super-secret');
    $settings = $this->settings();
    $GLOBALS['config']['beantragt_delivery.settings']['webhook']['allowed_hosts'] = ['Other.Example'];

    $controller = new DeliverySettingsController($this->configFactory($settings));
    $response = $controller->get();
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertFalse($payload['data']['adapters']['webhook']['available']);
    $this->assertSame(['other.example'], $payload['data']['adapters']['webhook']['allowedHosts']);
    $this->assertContains('target_host_not_allowed', $payload['data']['adapters']['webhook']['missing']);
  }

  /**
   * Invalid stored secret names and allowlists do not authorize dispatch.
   */
  #[DataProvider('invalidStoredWebhookSecretNames')]
  public function testGetSettingsIgnoresLegacyWebhookConfiguration(string $name): void {
    $settings = $this->settings();
    $settings['webhook']['secret_env'] = $name;
    $previous = getenv($name);
    putenv($name . '=https://receiver.example/hooks/beantragt');
    try {
      $controller = new DeliverySettingsController($this->configFactory($settings));
      $webhook = json_decode($controller->get()->getContent(), TRUE)['data']['adapters']['webhook'];
      self::assertFalse($webhook['secretConfigured']);
      self::assertSame('', $webhook['secretEnv']);
      self::assertSame([], $webhook['allowedHosts']);
      self::assertContains('secret', $webhook['missing']);
      self::assertContains('allowed_hosts', $webhook['missing']);
    }
    finally {
      putenv($name . ($previous === FALSE ? '' : '=' . $previous));
    }
  }

  /**
   * Provides invalid names that may be present in active config.
   */
  public static function invalidStoredWebhookSecretNames(): array {
    return [
      ['BEANTRAGT_TEST_LEGACY_WEBHOOK_SECRET'],
      ['BEANTRAGT_WEBHOOK_TARGET_URL'],
    ];
  }

  /**
   * The settings writer rejects environment names outside the secret pattern.
   *
   * @param array<string, string> $webhook
   *   The invalid webhook settings.
   */
  #[DataProvider('invalidWebhookSettings')]
  public function testPatchRejectsInvalidWebhookSettings(array $webhook): void {
    $editable = $this->editableConfig($this->settings());
    $controller = new DeliverySettingsController($this->configFactory($this->settings(), $editable));
    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings', 'PATCH', [], [], [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['data' => ['enabled' => TRUE, 'webhook' => $webhook]], JSON_THROW_ON_ERROR),
    ));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    self::assertFalse($editable->saved);
    self::assertSame([], $editable->written);
  }

  /**
   * Disallowed settings values and fields.
   */
  public static function invalidWebhookSettings(): array {
    return [
      'outside prefix' => [['secretEnv' => 'SYNTHETIC_PLATFORM_SECRET']],
      'missing name' => [['secretEnv' => 'BEANTRAGT_WEBHOOK_']],
      'target URL variable' => [['secretEnv' => 'BEANTRAGT_WEBHOOK_TARGET_URL']],
    ];
  }

  /**
   * Patch normalizes bounded delivery settings.
   */
  public function testPatchSettings(): void {
    $GLOBALS['config']['beantragt_delivery.settings']['webhook']['allowed_hosts'] = ['operator.example'];
    $editable = $this->editableConfig($this->settings());
    $controller = new DeliverySettingsController($this->configFactory($this->settings(), $editable));

    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'data' => [
          'enabled' => TRUE,
          'autoEnqueue' => TRUE,
          'defaultAdapter' => 'webhook',
          'maxAttempts' => 5,
          'webhook' => [
            'targetUrl' => 'https://receiver.example/hooks/beantragt',
            'secretEnv' => 'BEANTRAGT_WEBHOOK_SECRET',
            'allowedHosts' => ['other.example'],
            'timeoutSeconds' => 9,
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(TRUE, $editable->written['enabled']);
    $this->assertSame(TRUE, $editable->written['auto_enqueue']);
    $this->assertSame('webhook', $editable->written['default_adapter']);
    $this->assertSame(5, $editable->written['max_attempts']);
    $this->assertSame('https://receiver.example/hooks/beantragt', $editable->written['webhook.target_url']);
    $this->assertSame('BEANTRAGT_WEBHOOK_SECRET', $editable->written['webhook.secret_env']);
    $this->assertSame(9, $editable->written['webhook.timeout_seconds']);
    $this->assertArrayNotHasKey('webhook.allowed_hosts', $editable->written);
    $this->assertTrue($editable->saved);
    $payload = json_decode($response->getContent(), TRUE);
    self::assertSame(['operator.example'], $payload['data']['adapters']['webhook']['allowedHosts']);
  }

  /**
   * Target URLs may not smuggle credentials or query tokens.
   */
  public function testPatchRejectsWebhookQueryString(): void {
    $editable = $this->editableConfig($this->settings());
    $controller = new DeliverySettingsController($this->configFactory($this->settings(), $editable));

    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'data' => [
          'webhook' => [
            'targetUrl' => 'https://receiver.example/hooks?token=secret',
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('delivery.settings_invalid', $payload['error']['code']);
    $this->assertFalse($editable->saved);
  }

  /**
   * Administrative settings never persist unencrypted webhook targets.
   */
  public function testPatchRejectsHttpTarget(): void {
    $editable = $this->editableConfig($this->settings());
    $controller = new DeliverySettingsController($this->configFactory($this->settings(), $editable));
    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings', 'PATCH', [], [], [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode(['data' => ['webhook' => ['targetUrl' => 'http://receiver.example/hooks']]], JSON_THROW_ON_ERROR),
    ));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    self::assertSame('delivery.settings_invalid', json_decode($response->getContent(), TRUE)['error']['code']);
    self::assertFalse($editable->saved);
    self::assertSame([], $editable->written);
  }

  /**
   * Unknown top-level keys are rejected instead of silently ignored.
   */
  public function testPatchRejectsUnknownTopLevelKey(): void {
    $editable = $this->editableConfig($this->settings());
    $controller = new DeliverySettingsController($this->configFactory($this->settings(), $editable));

    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'data' => [
          'enabled' => TRUE,
          'futureSecret' => 'not-accepted',
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('delivery.settings_invalid', $payload['error']['code']);
    $this->assertFalse($editable->saved);
  }

  /**
   * Unknown webhook keys are rejected instead of silently ignored.
   */
  public function testPatchRejectsUnknownWebhookKey(): void {
    $editable = $this->editableConfig($this->settings());
    $controller = new DeliverySettingsController($this->configFactory($this->settings(), $editable));

    $response = $controller->patch(Request::create(
      '/api/admin/delivery/settings',
      'PATCH',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      json_encode([
        'data' => [
          'webhook' => [
            'targetUrl' => 'https://receiver.example/hooks',
            'token' => 'not-accepted',
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ));

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('delivery.settings_invalid', $payload['error']['code']);
    $this->assertFalse($editable->saved);
  }

  /**
   * Builds base settings.
   *
   * @return array<string, mixed>
   *   Delivery settings.
   */
  private function settings(): array {
    return [
      'enabled' => FALSE,
      'auto_enqueue' => FALSE,
      'default_adapter' => 'webhook',
      'max_attempts' => 3,
      'mock' => [
        'target' => 'local-mock',
      ],
      'webhook' => [
        'target_url' => 'https://receiver.example/hooks/beantragt',
        'secret_env' => 'BEANTRAGT_WEBHOOK_SECRET',
        'allowed_hosts' => ['receiver.example'],
        'timeout_seconds' => 5,
      ],
    ];
  }

  /**
   * Builds a config factory.
   *
   * @param array<string, mixed> $settings
   *   Immutable settings.
   * @param \Drupal\Tests\beantragt_delivery\Unit\Controller\MutableDeliveryConfig|null $editable
   *   Optional writable configuration spy.
   */
  private function configFactory(array $settings, ?MutableDeliveryConfig $editable = NULL): ConfigFactoryInterface {
    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(static fn(string $key): mixed => $settings[$key] ?? NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_delivery.settings')
      ->willReturn($immutable);
    $factory->method('getEditable')
      ->with('beantragt_delivery.settings')
      ->willReturn($editable ?? $this->editableConfig($settings));
    return $factory;
  }

  /**
   * Builds a writable config spy.
   *
   * @param array<string, mixed> $settings
   *   Initial settings.
   */
  private function editableConfig(array $settings): MutableDeliveryConfig {
    return new MutableDeliveryConfig($settings);
  }

}

/**
 * Minimal writable config spy for controller unit tests.
 */
final class MutableDeliveryConfig extends Config {

  /**
   * Written values.
   *
   * @var array<string, mixed>
   */
  public array $written = [];

  /**
   * Whether the configuration was saved.
   */
  public bool $saved = FALSE;

  /**
   * Constructs the spy.
   *
   * @param array<string, mixed> $data
   *   Initial config data.
   */
  public function __construct(array $data) {
    $this->data = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function get($key = ''): mixed {
    if ($key === '') {
      return $this->data;
    }
    return $this->data[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value): static {
    $this->written[(string) $key] = $value;
    $this->data[(string) $key] = $value;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function save($has_trusted_data = FALSE): static {
    $this->saved = TRUE;
    return $this;
  }

}
