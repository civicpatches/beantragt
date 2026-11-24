<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\DeliveryPolicyResolver;
use Drupal\beantragt_delivery\Value\DeliveryPolicy;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests resolving delivery policy from an application schema.
 */
#[CoversClass(DeliveryPolicyResolver::class)]
#[Group('beantragt_delivery')]
final class DeliveryPolicyResolverTest extends UnitTestCase {

  /**
   * Resolves pass-through delivery from the referenced FormSchema.
   */
  public function testResolvesPassThroughPolicyFromSchema(): void {
    $resolver = new DeliveryPolicyResolver();
    $application = $this->applicationWithSchema([
      'delivery' => [
        'processingMode' => 'pass_through',
        'adapter' => 'fit_connect',
      ],
    ]);

    $policy = $resolver->resolve($application);

    $this->assertInstanceOf(DeliveryPolicy::class, $policy);
    $this->assertSame(DeliveryPolicy::PROCESSING_PASS_THROUGH, $policy->processingMode);
    $this->assertSame(DeliveryPolicy::ADAPTER_FIT_CONNECT, $policy->adapter('mock'));
    $this->assertTrue($policy->shouldAutoEnqueue());
  }

  /**
   * Resolves webhook delivery from the referenced FormSchema.
   */
  public function testResolvesWebhookPolicyFromSchema(): void {
    $resolver = new DeliveryPolicyResolver();
    $application = $this->applicationWithSchema([
      'delivery' => [
        'processingMode' => 'pass_through',
        'adapter' => 'webhook',
      ],
    ]);

    $policy = $resolver->resolve($application);

    $this->assertInstanceOf(DeliveryPolicy::class, $policy);
    $this->assertSame(DeliveryPolicy::ADAPTER_WEBHOOK, $policy->adapter('mock'));
    $this->assertTrue($policy->shouldAutoEnqueue());
  }

  /**
   * Missing delivery policy keeps legacy global dispatcher behavior.
   */
  public function testMissingDeliveryPolicyResolvesNull(): void {
    $resolver = new DeliveryPolicyResolver();

    $this->assertNull($resolver->resolve($this->applicationWithSchema([
      'sections' => [],
    ])));
  }

  /**
   * Empty present delivery policy disables automatic delivery.
   */
  public function testEmptyDeliveryPolicyResolvesFailClosedPolicy(): void {
    $resolver = new DeliveryPolicyResolver();

    $policy = $resolver->resolve($this->applicationWithSchema([
      'delivery' => [],
    ]));

    $this->assertInstanceOf(DeliveryPolicy::class, $policy);
    $this->assertSame(DeliveryPolicy::PROCESSING_SELF_CONTAINED, $policy->processingMode);
    $this->assertFalse($policy->shouldAutoEnqueue());
  }

  /**
   * Malformed schema JSON disables automatic delivery fail-closed.
   */
  public function testMalformedSchemaJsonResolvesNull(): void {
    $resolver = new DeliveryPolicyResolver();
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('getSchema')->willReturn('{broken');

    $policy = $resolver->resolve($this->applicationWithSchemaEntity($schema));

    $this->assertInstanceOf(DeliveryPolicy::class, $policy);
    $this->assertSame(DeliveryPolicy::PROCESSING_SELF_CONTAINED, $policy->processingMode);
    $this->assertFalse($policy->shouldAutoEnqueue());
  }

  /**
   * Unsupported present delivery policy disables automatic delivery.
   */
  public function testUnsupportedDeliveryPolicyResolvesDefaultPolicy(): void {
    $resolver = new DeliveryPolicyResolver();

    $policy = $resolver->resolve($this->applicationWithSchema([
      'delivery' => [
        'adapter' => 'email',
      ],
    ]));

    $this->assertInstanceOf(DeliveryPolicy::class, $policy);
    $this->assertSame(DeliveryPolicy::PROCESSING_SELF_CONTAINED, $policy->processingMode);
    $this->assertFalse($policy->shouldAutoEnqueue());
  }

  /**
   * Unknown present delivery keys disable automatic delivery.
   */
  public function testUnknownDeliveryPolicyKeysResolveDefaultPolicy(): void {
    $resolver = new DeliveryPolicyResolver();

    $policy = $resolver->resolve($this->applicationWithSchema([
      'delivery' => [
        'clientSecret' => 'must-not-live-in-schema',
      ],
    ]));

    $this->assertInstanceOf(DeliveryPolicy::class, $policy);
    $this->assertSame(DeliveryPolicy::PROCESSING_SELF_CONTAINED, $policy->processingMode);
    $this->assertFalse($policy->shouldAutoEnqueue());
  }

  /**
   * Builds an application mock with a JSON schema array.
   *
   * @param array<string, mixed> $schema_data
   *   Schema fixture.
   */
  private function applicationWithSchema(array $schema_data): ApplicationInterface {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('getSchema')->willReturn(json_encode($schema_data, JSON_THROW_ON_ERROR));
    return $this->applicationWithSchemaEntity($schema);
  }

  /**
   * Builds an application mock with a schema entity reference.
   */
  private function applicationWithSchemaEntity(FormSchemaInterface $schema): ApplicationInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'entity' ? $schema : NULL,
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->with('field_schema')->willReturn(TRUE);
    $application->method('get')->with('field_schema')->willReturn($field);
    return $application;
  }

}
