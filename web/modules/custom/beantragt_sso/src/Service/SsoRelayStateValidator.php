<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validates RelayState targets before redirects.
 */
final class SsoRelayStateValidator {

  /**
   * Constructs the RelayState validator.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Returns a safe RelayState target or the provider default.
   */
  public function sanitize(string $provider_id, mixed $relay_state, ?string $fallback = NULL): string {
    $provider = $this->provider($provider_id);
    $default = $fallback ?? $this->defaultRelayPath($provider);
    if (!is_string($relay_state) || trim($relay_state) === '') {
      return $default;
    }

    $target = trim($relay_state);
    if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
      return preg_match('/[\x00-\x1F\x7F\\\\]/', $target) ? $default : $target;
    }

    $authority = $this->absoluteRelayAuthority($target);
    if ($authority === NULL) {
      return $default;
    }

    $request = $this->requestStack->getCurrentRequest();
    [$host, $port] = $authority;
    if (($request !== NULL && $host === strtolower($request->getHost()) && $port === (int) $request->getPort())
        || $this->isAllowedRelayHost($provider_id, $target)) {
      return $target;
    }

    SsoFailureLog::log($this->logger, 'warning', 'relay_state_rejected', $provider_id);

    return $default;
  }

  /**
   * Checks an absolute target against the provider's RelayState allowlist.
   */
  public function isAllowedRelayHost(string $provider_id, string $absolute_url): bool {
    $authority = $this->absoluteRelayAuthority($absolute_url);
    if ($authority === NULL) {
      return FALSE;
    }

    [$host, $port, $default_port] = $authority;
    $provider = $this->provider($provider_id);
    foreach (($provider['allowed_relay_hosts'] ?? []) as $allowed_host) {
      $allowed_host = is_scalar($allowed_host) ? strtolower(trim((string) $allowed_host)) : '';
      if ($allowed_host === "$host:$port" || ($allowed_host === $host && $port === $default_port)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Parses a valid HTTP(S) RelayState authority.
   *
   * @return array{string, int, int}|null
   *   Lowercase host, effective port, and scheme default port, or NULL.
   */
  private function absoluteRelayAuthority(string $target): ?array {
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target)
        || preg_match('~^(https?)://([a-z0-9.-]+)(?::([0-9]{1,5}))?(?:[/?#]|$)~iD', $target, $parts) !== 1) {
      return NULL;
    }

    $host = strtolower($parts[2]);
    $is_https = strtolower($parts[1]) === 'https';
    $default_port = $is_https ? 443 : 80;
    $port = isset($parts[3]) ? (int) $parts[3] : $default_port;
    if ($port < 1 || $port > 65535 || (!$is_https && !$this->isLocalHost($host))) {
      return NULL;
    }

    return [$host, $port, $default_port];
  }

  /**
   * Allows HTTP RelayState only for local development hosts.
   */
  private function isLocalHost(string $host): bool {
    return $host === 'localhost'
        || $host === '127.0.0.1'
        || str_ends_with($host, '.ddev.site');
  }

  /**
   * Returns the configured default RelayState path.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function defaultRelayPath(array $provider): string {
    $path = is_string($provider['default_relay_path'] ?? NULL)
        ? trim($provider['default_relay_path'])
        : '/';
    return str_starts_with($path, '/') && !str_starts_with($path, '//') ? $path : '/';
  }

  /**
   * Returns a provider configuration.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  private function provider(string $provider_id): array {
    $provider = $this->configFactory
      ->get('beantragt_sso.settings')
      ->get("providers.$provider_id");
    return is_array($provider) ? $provider : [];
  }

}
