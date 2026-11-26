<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads and validates configured SSO providers.
 */
final class SsoProviderManager {
  private const DEV_ENVIRONMENTS = ['dev', 'local', 'ddev', 'development'];

  /**
   * Constructs the provider manager.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Returns a configured provider.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  public function provider(string $provider_id): array {
    $this->assertValidProviderId($provider_id);

    $provider = $this->configFactory
      ->get('beantragt_sso.settings')
      ->get("providers.$provider_id");
    if (!is_array($provider)) {
      throw new NotFoundHttpException('unavailable');
    }

    return $provider;
  }

  /**
   * Returns an enabled provider.
   *
   * @return array<string, mixed>
   *   Provider configuration.
   */
  public function enabledProvider(string $provider_id): array {
    $provider = $this->provider($provider_id);
    if (empty($provider['enabled'])) {
      throw new AccessDeniedHttpException('unavailable');
    }
    return $provider;
  }

  /**
   * Returns TRUE when this provider uses a mock profile.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  public function isMockProvider(array $provider): bool {
    return in_array($provider['profile'] ?? NULL, ['bundid_mock', 'sso_mock'], TRUE)
        || !empty($provider['mock']);
  }

  /**
   * Throws when a mock provider is used without explicit development opt-in.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  public function assertMockAllowed(array $provider): void {
    if ($this->isMockProvider($provider) && !$this->mockAllowed()) {
      throw new SsoAuthenticationException('mock_not_allowed', 'SSO mock providers are disabled in this environment.');
    }
  }

  /**
   * Returns TRUE when this host is an explicit dev or ddev context.
   *
   * This is independent of BEANTRAGT_SSO_MOCK: it only answers whether the
   * environment is a non-production one where an enabled mock provider is a
   * tolerable misconfiguration rather than a production login bypass.
   */
  public function isDevContext(): bool {
    $environment = strtolower((string) getenv('BEANTRAGT_ENV'));
    if ($environment === 'production') {
      return FALSE;
    }
    if (getenv('IS_DDEV_PROJECT') === 'true') {
      return TRUE;
    }

    return in_array($environment, self::DEV_ENVIRONMENTS, TRUE);
  }

  /**
   * Returns TRUE when dev-only mock providers may run.
   */
  public function mockAllowed(): bool {
    $explicit = getenv('BEANTRAGT_SSO_MOCK');
    return is_string($explicit) && in_array(strtolower($explicit), ['1', 'true', 'yes'], TRUE)
      && $this->isDevContext();
  }

  /**
   * Checks whether an explicit ACS URL targets this provider on this host.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $host
   *   Host of the status or login request.
   */
  public function hasMatchingAcsUrl(string $provider_id, array $provider, string $host): bool {
    $url = $provider['sp_acs_url'] ?? '';
    if (!is_string($url)) {
      return FALSE;
    }
    $url = trim($url);
    if ($url === '') {
      return TRUE;
    }
    $parts = parse_url($url);
    return $host !== '' && strcasecmp($parts['host'] ?? '', $host) === 0
      && ($parts['path'] ?? '') === "/auth/sso/$provider_id/acs";
  }

  /**
   * Validates provider IDs before they reach config or route consumers.
   */
  private function assertValidProviderId(string $provider_id): void {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $provider_id)) {
      throw new NotFoundHttpException('unavailable');
    }
  }

}
