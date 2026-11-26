<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Tracks pending SAML AuthnRequests across the browser's cross-site return.
 */
final class SsoRequestCorrelation {

  private const TTL = 600;

  /**
   * The expirable collection of pending requests.
   */
  private readonly KeyValueStoreExpirableInterface $store;

  /**
   * Constructs the correlation store.
   */
  public function __construct(
    KeyValueExpirableFactoryInterface $factory,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {
    $this->store = $factory->get('beantragt_sso.request_correlation');
  }

  /**
   * Stores an AuthnRequest and returns its browser correlation cookie.
   */
  public function create(string $provider, string $request_id, string $relay_state): Cookie {
    $key = bin2hex(random_bytes(32));
    $expires = $this->time->getCurrentTime() + self::TTL;
    $this->store->setWithExpire(hash('sha256', $key), [
      'provider' => $provider,
      'request_id' => $request_id,
      'relay_state' => $relay_state,
      'expires' => $expires,
    ], self::TTL);

    return self::cookie($provider, $key, $expires);
  }

  /**
   * Claims a pending request once, for the provider that created it.
   *
   * @return array{request_id: string, relay_state: string}|null
   *   The request ID and its original return target, if found and unexpired.
   */
  public function consume(string $provider, mixed $key): ?array {
    if (!is_string($key) || preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1) {
      return NULL;
    }

    $digest = hash('sha256', $key);
    $lock_name = 'beantragt_sso.correlation.' . $digest;
    if (!$this->lock->acquire($lock_name)) {
      return NULL;
    }
    try {
      $record = $this->store->get($digest);
      if (!is_array($record)) {
        return NULL;
      }
      $this->store->delete($digest);
      if ($record['provider'] !== $provider || $record['expires'] <= $this->time->getCurrentTime()) {
        return NULL;
      }
      return ['request_id' => $record['request_id'], 'relay_state' => $record['relay_state']];
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Expires the cookie on the same path with the original flags.
   */
  public static function clearedCookie(string $provider): Cookie {
    return self::cookie($provider, NULL, 1);
  }

  /**
   * Returns the host-bound correlation cookie name for a provider.
   */
  public static function cookieName(string $provider): string {
    return '__Host-beantragt_sso_corr_' . $provider;
  }

  /**
   * Builds the host-only correlation cookie with the required root path.
   */
  private static function cookie(string $provider, ?string $key, int $expires): Cookie {
    return new Cookie(
      self::cookieName($provider),
      $key,
      $expires,
      '/',
      secure: TRUE,
      httpOnly: TRUE,
      sameSite: Cookie::SAMESITE_NONE,
    );
  }

}
