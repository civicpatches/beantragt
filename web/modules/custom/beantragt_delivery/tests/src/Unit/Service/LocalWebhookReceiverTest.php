<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Gateway\WebhookDeliveryGateway;
use Drupal\beantragt_delivery\Service\LocalWebhookReceiver;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the local signed-webhook receiver.
 *
 * @group beantragt_delivery
 */
final class LocalWebhookReceiverTest extends UnitTestCase {

  /**
   * Valid HMAC signatures write non-sensitive receiver records.
   */
  public function testReceiveValidSignedWebhook(): void {
    $env_snapshot = getenv('BEANTRAGT_WEBHOOK_SECRET');
    putenv('BEANTRAGT_WEBHOOK_SECRET=test-webhook-secret');
    try {
      $body = json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
      $state = $this->createMock(StateInterface::class);
      $state->method('get')->willReturn([]);
      $state->expects($this->once())
        ->method('set')
        ->with(
          'beantragt_delivery.local_webhook_receiver_inbox',
          $this->callback(static function (array $records): bool {
            return count($records) === 1
              && ($records[0]['eventId'] ?? '') === 'evt-123'
              && ($records[0]['applicationUuid'] ?? '') === '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce'
              && ($records[0]['fieldCount'] ?? -1) === 2
              && ($records[0]['attachmentCount'] ?? -1) === 1
              && ($records[0]['attachments'][0]['id'] ?? '') === 'e85b56a6-ec90-4a4f-8173-786e7d29b54f'
              && !isset($records[0]['attachments'][0]['filename'])
              && !isset($records[0]['application']['dataHash']);
          }),
        );

      $receiver = new LocalWebhookReceiver($state, $this->time(), $this->configFactory(), TRUE);
      $result = $receiver->receive($body, [
        'x-beantragt-signature' => WebhookDeliveryGateway::sign($body, 'test-webhook-secret'),
      ]);
    }
    finally {
      $env_snapshot === FALSE
        ? putenv('BEANTRAGT_WEBHOOK_SECRET')
        : putenv('BEANTRAGT_WEBHOOK_SECRET=' . $env_snapshot);
    }

    $this->assertTrue($result['accepted']);
    $this->assertSame(202, $result['statusCode']);
    $this->assertSame('evt-123', $result['eventId']);
    $this->assertStringStartsWith('WEBHOOK-RX-', $result['receipt']);
  }

  /**
   * Invalid signatures are rejected without writing state.
   */
  public function testInvalidSignatureRejected(): void {
    $env_snapshot = getenv('BEANTRAGT_WEBHOOK_SECRET');
    putenv('BEANTRAGT_WEBHOOK_SECRET=test-webhook-secret');
    try {
      $body = json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
      $state = $this->createMock(StateInterface::class);
      $state->expects($this->never())->method('set');

      $receiver = new LocalWebhookReceiver($state, $this->time(), $this->configFactory(), TRUE);
      $result = $receiver->receive($body, [
        'x-beantragt-signature' => 'sha256=wrong',
      ]);
    }
    finally {
      $env_snapshot === FALSE
        ? putenv('BEANTRAGT_WEBHOOK_SECRET')
        : putenv('BEANTRAGT_WEBHOOK_SECRET=' . $env_snapshot);
    }

    $this->assertFalse($result['accepted']);
    $this->assertSame(401, $result['statusCode']);
    $this->assertSame('webhook.signature_invalid', $result['error']);
  }

  /**
   * Builds a config factory mock.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'webhook' ? [
      'secret_env' => 'BEANTRAGT_WEBHOOK_SECRET',
      'target_url' => 'https://receiver.example/hooks',
      'allowed_hosts' => ['receiver.example'],
    ] : NULL);

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
    $time->method('getCurrentMicroTime')->willReturn(1780000000.123);
    return $time;
  }

  /**
   * Builds a signed event payload fixture.
   *
   * @return array<string, mixed>
   *   Webhook event payload.
   */
  private function payload(): array {
    return [
      'eventId' => 'evt-123',
      'eventType' => 'application.submitted',
      'timestamp' => 1780000000,
      'application' => [
        'uuid' => '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
        'referenceNumber' => 'MS-2026-00010',
        'fieldCount' => 2,
        'attachmentCount' => 1,
      ],
      'attachments' => [
        [
          'id' => 'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
          'size' => 415,
        ],
      ],
      'workspace' => [
        'jurisdiction' => [
          'slug' => 'musterstadt',
        ],
      ],
    ];
  }

}
