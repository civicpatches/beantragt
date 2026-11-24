<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

/**
 * Immutable result returned by a delivery gateway.
 */
final class DeliveryResult {

  public const STATUS_ACCEPTED = 'accepted';
  public const STATUS_FAILED = 'failed';

  /**
   * Constructs a delivery result.
   *
   * @param string $status
   *   Delivery status.
   * @param string $externalId
   *   External submission ID.
   * @param string $receipt
   *   Transport receipt.
   * @param string $errorCode
   *   Safe error code.
   * @param string $errorMessage
   *   Safe error message.
   * @param array<string, mixed> $metadata
   *   Non-sensitive technical result metadata.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $externalId = '',
    public readonly string $receipt = '',
    public readonly string $errorCode = '',
    public readonly string $errorMessage = '',
    public readonly array $metadata = [],
  ) {
    if (!in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_FAILED], TRUE)) {
      throw new \InvalidArgumentException('Unsupported delivery result status.');
    }
  }

  /**
   * Creates a successful delivery result.
   *
   * @param string $external_id
   *   External submission ID.
   * @param string $receipt
   *   Transport receipt.
   * @param array<string, mixed> $metadata
   *   Non-sensitive technical result metadata.
   */
  public static function accepted(string $external_id, string $receipt, array $metadata = []): self {
    return new self(self::STATUS_ACCEPTED, $external_id, $receipt, '', '', $metadata);
  }

  /**
   * Creates a failed delivery result.
   *
   * @param string $error_code
   *   Safe error code.
   * @param string $error_message
   *   Safe error message.
   * @param array<string, mixed> $metadata
   *   Non-sensitive technical result metadata.
   */
  public static function failed(string $error_code, string $error_message, array $metadata = []): self {
    return new self(self::STATUS_FAILED, '', '', $error_code, $error_message, $metadata);
  }

  /**
   * Returns TRUE when the result is accepted.
   */
  public function isAccepted(): bool {
    return $this->status === self::STATUS_ACCEPTED;
  }

}
