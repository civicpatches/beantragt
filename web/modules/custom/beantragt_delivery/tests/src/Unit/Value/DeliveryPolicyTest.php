<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Value;

use Drupal\beantragt_delivery\Value\DeliveryPolicy;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests form-level delivery policy normalization.
 */
#[CoversClass(DeliveryPolicy::class)]
#[Group('beantragt_delivery')]
final class DeliveryPolicyTest extends UnitTestCase {

  /**
   * Pass-through queues automatically.
   */
  public function testPassThroughQueuesAutomatically(): void {
    $policy = DeliveryPolicy::fromArray([
      'processingMode' => DeliveryPolicy::PROCESSING_PASS_THROUGH,
      'adapter' => DeliveryPolicy::ADAPTER_FIT_CONNECT,
    ]);

    $this->assertSame(DeliveryPolicy::PROCESSING_PASS_THROUGH, $policy->processingMode);
    $this->assertSame(DeliveryPolicy::ADAPTER_FIT_CONNECT, $policy->adapter('mock'));
    $this->assertTrue($policy->shouldAutoEnqueue());
  }

  /**
   * Webhook is a first-class pass-through adapter.
   */
  public function testWebhookAdapter(): void {
    $policy = DeliveryPolicy::fromArray([
      'processingMode' => DeliveryPolicy::PROCESSING_PASS_THROUGH,
      'adapter' => DeliveryPolicy::ADAPTER_WEBHOOK,
    ]);

    $this->assertSame(DeliveryPolicy::ADAPTER_WEBHOOK, $policy->adapter('mock'));
    $this->assertTrue($policy->shouldAutoEnqueue());
  }

  /**
   * Self-contained defaults to no automatic delivery.
   */
  public function testSelfContainedDefaultsToNoAutomaticDelivery(): void {
    $policy = DeliveryPolicy::fromArray([
      'processingMode' => DeliveryPolicy::PROCESSING_SELF_CONTAINED,
      'adapter' => DeliveryPolicy::ADAPTER_MOCK,
    ]);

    $this->assertSame(DeliveryPolicy::PROCESSING_SELF_CONTAINED, $policy->processingMode);
    $this->assertFalse($policy->shouldAutoEnqueue());
  }

  /**
   * Unknown keys are ignored by the value object.
   */
  public function testUnknownKeysDoNotEnableAutoEnqueue(): void {
    $policy = DeliveryPolicy::fromArray([
      'processingMode' => DeliveryPolicy::PROCESSING_SELF_CONTAINED,
      'unexpected' => 'auto',
    ]);

    $this->assertFalse($policy->shouldAutoEnqueue());
    $this->assertSame('mock', $policy->adapter('mock'));
  }

}
