<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Gateway;

use Drupal\beantragt_delivery\Gateway\MockDeliveryGateway;
use Drupal\beantragt_delivery\Service\LocalMockReceiver;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the local mock delivery gateway.
 *
 * @group beantragt_delivery
 */
final class MockDeliveryGatewayTest extends UnitTestCase {

  /**
   * Mock deliveries produce deterministic receipts.
   */
  public function testMockDeliveryAccepted(): void {
    $gateway = new MockDeliveryGateway(NULL, TRUE);
    $envelope = new DeliveryEnvelope(
      10,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00010',
      'd5974725-034f-4c53-bc44-058de7e31951',
      'Hundesteuer',
      1,
      hash('sha256', '{"field":"value"}'),
      1,
      0,
    );

    $first = $gateway->deliver($envelope);
    $second = $gateway->deliver($envelope);

    $this->assertSame(DeliveryResult::STATUS_ACCEPTED, $first->status);
    $this->assertStringStartsWith('mock:', $first->externalId);
    $this->assertStringStartsWith('MOCK-', $first->receipt);
    $this->assertSame($first->externalId, $second->externalId);
    $this->assertSame($first->receipt, $second->receipt);
    $this->assertSame('mock', $first->metadata['gateway']);
  }

  /**
   * Mock deliveries write a non-sensitive receiver record when configured.
   */
  public function testMockDeliveryWritesReceiverInbox(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn([]);
    $state->expects($this->once())
      ->method('set')
      ->with(
        'beantragt_delivery.local_mock_receiver_inbox',
        $this->callback(static function (array $records): bool {
          return count($records) === 1
            && ($records[0]['applicationUuid'] ?? '') === '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce'
            && ($records[0]['attachmentCount'] ?? -1) === 1
            && ($records[0]['attachments'][0]['id'] ?? '') === 'e85b56a6-ec90-4a4f-8173-786e7d29b54f'
            && !isset($records[0]['attachments'][0]['filename'])
            && !isset($records[0]['attachments'][0]['fieldId'])
            && !isset($records[0]['attachments'][0]['mimeType']);
        }),
      );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1780000000);
    $time->method('getCurrentMicroTime')->willReturn(1780000000.123);
    $receiver = new LocalMockReceiver($state, $time, TRUE);

    $gateway = new MockDeliveryGateway($receiver, TRUE);
    $result = $gateway->deliver(new DeliveryEnvelope(
      10,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00010',
      'd5974725-034f-4c53-bc44-058de7e31951',
      'Hundesteuer',
      1,
      hash('sha256', '{"field":"value"}'),
      1,
      1,
      [],
      [
        [
          'id' => 'e85b56a6-ec90-4a4f-8173-786e7d29b54f',
          'fieldId' => 'nachweis',
          'filename' => 'nachweis.pdf',
          'mimeType' => 'application/pdf',
          'size' => 415,
        ],
      ],
    ));

    $this->assertSame(DeliveryResult::STATUS_ACCEPTED, $result->status);
    $this->assertSame(1, $result->metadata['receiver']['attachmentCount']);
    $this->assertSame('e85b56a6-ec90-4a4f-8173-786e7d29b54f', $result->metadata['receiver']['attachments'][0]['id']);
    $this->assertArrayNotHasKey('filename', $result->metadata['receiver']['attachments'][0]);
    $this->assertArrayNotHasKey('dataHash', $result->metadata['receiver']);
  }

  /**
   * The mock gateway stays fail-closed when disabled.
   */
  public function testMockDeliveryFailsWhenDisabled(): void {
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->never())->method('set');
    $time = $this->createMock(TimeInterface::class);
    $receiver = new LocalMockReceiver($state, $time, TRUE);

    $gateway = new MockDeliveryGateway($receiver, FALSE);
    $result = $gateway->deliver(new DeliveryEnvelope(
      10,
      '6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce',
      'MS-2026-00010',
      'd5974725-034f-4c53-bc44-058de7e31951',
      'Hundesteuer',
      1,
      hash('sha256', '{"field":"value"}'),
      1,
      0,
    ));

    $this->assertSame(DeliveryResult::STATUS_FAILED, $result->status);
    $this->assertSame('delivery.mock_not_allowed', $result->errorCode);
    $this->assertArrayNotHasKey('receiver', $result->metadata);
  }

}
