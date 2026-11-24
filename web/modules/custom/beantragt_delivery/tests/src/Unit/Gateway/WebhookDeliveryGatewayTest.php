<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Gateway;

use Drupal\beantragt_delivery\Gateway\WebhookDeliveryGateway;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests signed webhook delivery.
 *
 * @group beantragt_delivery
 */
#[BackupGlobals(TRUE)]
final class WebhookDeliveryGatewayTest extends UnitTestCase {

  /**
   * Signed webhook delivery posts a non-sensitive event payload.
   */
  public function testWebhookDeliveryAccepted(): void {
    $env_snapshot = getenv('BEANTRAGT_WEBHOOK_SECRET');
    putenv('BEANTRAGT_WEBHOOK_SECRET=test-webhook-secret');
    $history = [];
    try {
      $gateway = new WebhookDeliveryGateway(
        $this->configFactory([
          'target_url' => 'https://receiver.example/hooks/beantragt',
          'secret_env' => 'BEANTRAGT_WEBHOOK_SECRET',
          'timeout_seconds' => 7,
        ]),
        $this->client([
          new Response(202, ['Content-Type' => 'application/json'], '{"accepted":true}'),
        ], $history),
        $this->time(),
      );

      $result = $gateway->deliver($this->envelope());
    }
    finally {
      $env_snapshot === FALSE
        ? putenv('BEANTRAGT_WEBHOOK_SECRET')
        : putenv('BEANTRAGT_WEBHOOK_SECRET=' . $env_snapshot);
    }

    $this->assertSame(DeliveryResult::STATUS_ACCEPTED, $result->status);
    $this->assertStringStartsWith('webhook:', $result->externalId);
    $this->assertStringStartsWith('WEBHOOK-', $result->receipt);
    $this->assertSame('webhook', $result->metadata['gateway']);
    $this->assertSame(202, $result->metadata['statusCode']);

    $this->assertCount(1, $history);
    $this->assertFalse($history[0]['options']['allow_redirects']);
    $this->assertTrue($history[0]['options']['verify']);
    $request = $history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://receiver.example/hooks/beantragt', (string) $request->getUri());
    $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    $this->assertSame('application.submitted', $request->getHeaderLine('X-Beantragt-Event-Type'));

    $body = (string) $request->getBody();
    $payload = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('application.submitted', $payload['eventType']);
    $this->assertSame('6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce', $payload['application']['uuid']);
    $this->assertSame('MS-2026-00010', $payload['application']['referenceNumber']);
    $this->assertSame(2, $payload['application']['fieldCount']);
    $this->assertSame(1, $payload['application']['attachmentCount']);
    $this->assertSame('musterstadt', $payload['workspace']['jurisdiction']['slug']);
    $this->assertSame('e85b56a6-ec90-4a4f-8173-786e7d29b54f', $payload['attachments'][0]['id']);
    $this->assertArrayNotHasKey('filename', $payload['attachments'][0]);
    $this->assertArrayNotHasKey('values', $payload);
    $this->assertStringNotContainsString('Bruno API Smoke', $body);
    $this->assertSame(
      WebhookDeliveryGateway::sign($body, 'test-webhook-secret'),
      $request->getHeaderLine('X-Beantragt-Signature'),
    );
  }

  /**
   * Webhooks fail closed without target, secret or allowed host.
   */
  public function testWebhookMissingConfigurationFailsClosed(): void {
    $env_snapshot = getenv('BEANTRAGT_WEBHOOK_SECRET');
    putenv('BEANTRAGT_WEBHOOK_SECRET');
    $history = [];
    try {
      $gateway = new WebhookDeliveryGateway(
        $this->configFactory([]),
        $this->client([], $history),
        $this->time(),
      );

      $result = $gateway->deliver($this->envelope());
    }
    finally {
      $env_snapshot === FALSE
        ? putenv('BEANTRAGT_WEBHOOK_SECRET')
        : putenv('BEANTRAGT_WEBHOOK_SECRET=' . $env_snapshot);
    }

    $this->assertSame(DeliveryResult::STATUS_FAILED, $result->status);
    $this->assertSame('webhook.missing_configuration', $result->errorCode);
    $this->assertContains('target_url', $result->metadata['missing']);
    $this->assertContains('secret', $result->metadata['missing']);
    $this->assertContains('allowed_hosts', $result->metadata['missing']);
    $this->assertArrayNotHasKey('secret', $result->metadata);
    $this->assertArrayNotHasKey('secretEnv', $result->metadata);
  }

  /**
   * Target hosts must be explicitly allowlisted.
   */
  public function testWebhookTargetHostMustBeAllowlisted(): void {
    $env_snapshot = getenv('BEANTRAGT_WEBHOOK_SECRET');
    putenv('BEANTRAGT_WEBHOOK_SECRET=test-webhook-secret');
    $history = [];
    try {
      $gateway = new WebhookDeliveryGateway(
        $this->configFactory([
          'target_url' => 'https://attacker.example/hooks',
          'secret_env' => 'BEANTRAGT_WEBHOOK_SECRET',
          'allowed_hosts' => ['receiver.example', 'attacker.example'],
        ]),
        $this->client([
          new Response(202, [], ''),
        ], $history),
        $this->time(),
      );

      $result = $gateway->deliver($this->envelope());
    }
    finally {
      $env_snapshot === FALSE
        ? putenv('BEANTRAGT_WEBHOOK_SECRET')
        : putenv('BEANTRAGT_WEBHOOK_SECRET=' . $env_snapshot);
    }

    $this->assertSame(DeliveryResult::STATUS_FAILED, $result->status);
    $this->assertSame('webhook.target_host_not_allowed', $result->errorCode);
    $this->assertCount(0, $history);
  }

  /**
   * Redirects never send the signed payload beyond the approved endpoint.
   */
  #[DataProvider('redirectStatuses')]
  public function testRedirectsAreRejected(int $status): void {
    $old = getenv('BEANTRAGT_WEBHOOK_SECRET');
    putenv('BEANTRAGT_WEBHOOK_SECRET=' . bin2hex(random_bytes(32)));
    $history = [];
    try {
      $gateway = new WebhookDeliveryGateway($this->configFactory([
        'target_url' => 'https://receiver.example/hook',
      ]), $this->client([
        new Response($status, ['Location' => 'https://unapproved.example/hook']),
        new Response(202),
      ], $history), $this->time());
      $result = $gateway->deliver($this->envelope());
      self::assertSame('webhook.rejected', $result->errorCode);
      self::assertSame($status, $result->metadata['statusCode']);
      self::assertCount(1, $history);
      self::assertFalse($history[0]['options']['allow_redirects']);
    }
    finally {
      putenv('BEANTRAGT_WEBHOOK_SECRET' . ($old === FALSE ? '' : '=' . $old));
    }
  }

  /**
   * Redirect status codes, including those that preserve the POST body.
   */
  public static function redirectStatuses(): array {
    return [[301], [302], [303], [307], [308]];
  }

  /**
   * HTTP requires both a local environment and an explicit test opt-in.
   */
  #[DataProvider('httpEnvironments')]
  public function testHttpRequiresLocalOptIn(string $local, string $opt_in, bool $allowed): void {
    $values = [
      'IS_DDEV_PROJECT' => $local,
      'BEANTRAGT_WEBHOOK_ALLOW_HTTP_LOCAL' => $opt_in,
      'BEANTRAGT_WEBHOOK_SECRET' => bin2hex(random_bytes(32)),
    ];
    $old = [];
    foreach ($values as $key => $value) {
      $old[$key] = getenv($key);
      putenv($key . '=' . $value);
    }
    $history = [];
    try {
      $gateway = new WebhookDeliveryGateway($this->configFactory([
        'target_url' => 'http://receiver.example/hook',
      ]), $this->client([new Response(202)], $history), $this->time());
      $result = $gateway->deliver($this->envelope());
      self::assertSame($allowed ? DeliveryResult::STATUS_ACCEPTED : DeliveryResult::STATUS_FAILED, $result->status);
      self::assertCount($allowed ? 1 : 0, $history);
      if (!$allowed) {
        self::assertSame('webhook.target_url_not_allowed', $result->errorCode);
      }
    }
    finally {
      foreach ($old as $key => $value) {
        putenv($key . ($value === FALSE ? '' : '=' . $value));
      }
    }
  }

  /**
   * Production cannot opt in to HTTP using the switch alone.
   */
  public static function httpEnvironments(): array {
    return [['', '', FALSE], ['', '1', FALSE], ['true', '', FALSE], ['true', '1', TRUE]];
  }

  /**
   * Builds a Guzzle client with history middleware.
   *
   * @param array<int, mixed> $queue
   *   Mock handler queue.
   * @param array<int, mixed> $history
   *   Request history.
   */
  private function client(array $queue, array &$history): Client {
    $handler_stack = HandlerStack::create(new MockHandler($queue));
    $handler_stack->push(Middleware::history($history));
    return new Client(['handler' => $handler_stack, 'verify' => FALSE]);
  }

  /**
   * Builds a config factory mock.
   *
   * @param array<string, mixed> $webhook
   *   Webhook settings.
   */
  private function configFactory(array $webhook): ConfigFactoryInterface {
    if ($webhook !== []) {
      $GLOBALS['config']['beantragt_delivery.settings']['webhook']['allowed_hosts'] = ['receiver.example'];
    }
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'webhook' ? $webhook : NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('beantragt_delivery.settings')->willReturn($config);
    return $factory;
  }

  /**
   * Builds a stable time service.
   */
  private function time(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1780000000);
    return $time;
  }

  /**
   * Builds a delivery envelope.
   */
  private function envelope(): DeliveryEnvelope {
    return new DeliveryEnvelope(
      10,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00010',
      'd5974725-034f-4c53-bc44-058de7e31951',
      'Hundesteuer',
      1,
      hash('sha256', '{"name":"Bruno API Smoke","message":"Hello"}'),
      2,
      1,
      [
        'jurisdiction' => [
          'uuid' => '9281a891-1641-4c4e-9d16-a1f6db44a460',
          'slug' => 'musterstadt',
          'label' => 'Stadt Musterstadt',
        ],
      ],
      [
        [
          'id' => 'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
          'fieldId' => 'nachweis',
          'filename' => 'nachweis.pdf',
          'mimeType' => 'application/pdf',
          'size' => 415,
        ],
      ],
    );
  }

}
