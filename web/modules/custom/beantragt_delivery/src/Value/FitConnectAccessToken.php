<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

/**
 * Non-secret metadata for a fetched FIT-Connect access token.
 */
final class FitConnectAccessToken {

  /**
   * Constructs token metadata.
   */
  public function __construct(
    public readonly string $accessToken,
    public readonly string $tokenType,
    public readonly int $expiresIn,
  ) {
    if ($this->accessToken === '') {
      throw new \InvalidArgumentException('Access token is required.');
    }
    if ($this->tokenType === '') {
      throw new \InvalidArgumentException('Token type is required.');
    }
  }

  /**
   * Returns safe metadata without the token value.
   *
   * @return array<string, mixed>
   *   Safe token metadata.
   */
  public function toSafeMetadata(): array {
    return [
      'tokenType' => $this->tokenType,
      'expiresIn' => $this->expiresIn,
      'accessTokenFetched' => TRUE,
    ];
  }

}
