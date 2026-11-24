<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

/**
 * Form-level delivery policy for submitted applications.
 */
final class DeliveryPolicy {

  public const PROCESSING_SELF_CONTAINED = 'self_contained';
  public const PROCESSING_PASS_THROUGH = 'pass_through';

  public const ADAPTER_MOCK = 'mock';
  public const ADAPTER_WEBHOOK = 'webhook';
  public const ADAPTER_FIT_CONNECT = 'fit_connect';

  /**
   * Constructs a delivery policy.
   */
  public function __construct(
    public readonly string $processingMode = self::PROCESSING_SELF_CONTAINED,
    public readonly ?string $adapter = NULL,
  ) {}

  /**
   * Builds a normalized policy from schema JSON.
   *
   * @param array<string, mixed> $policy
   *   Raw delivery policy object from the FormSchema DSL.
   */
  public static function fromArray(array $policy): self {
    $processing_mode = self::stringChoice(
      $policy['processingMode'] ?? NULL,
      [self::PROCESSING_SELF_CONTAINED, self::PROCESSING_PASS_THROUGH],
      self::PROCESSING_SELF_CONTAINED,
    );
    $adapter = self::stringChoice(
      $policy['adapter'] ?? NULL,
      [self::ADAPTER_MOCK, self::ADAPTER_WEBHOOK, self::ADAPTER_FIT_CONNECT],
      NULL,
    );

    return new self($processing_mode, $adapter);
  }

  /**
   * Returns TRUE when this policy should queue automatically.
   */
  public function shouldAutoEnqueue(): bool {
    return $this->processingMode === self::PROCESSING_PASS_THROUGH;
  }

  /**
   * Returns the policy adapter or a configured fallback.
   */
  public function adapter(string $fallback): string {
    return $this->adapter ?? $fallback;
  }

  /**
   * Reads one bounded string choice.
   *
   * @param mixed $value
   *   Raw value.
   * @param string[] $allowed
   *   Allowed values.
   * @param string|null $fallback
   *   Fallback value.
   */
  private static function stringChoice(mixed $value, array $allowed, ?string $fallback): ?string {
    if (is_string($value) && in_array($value, $allowed, TRUE)) {
      return $value;
    }
    return $fallback;
  }

}
