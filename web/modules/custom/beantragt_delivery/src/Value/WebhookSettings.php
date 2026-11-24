<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Normalized webhook delivery configuration.
 */
final class WebhookSettings {

  /**
   * Accepted names for environment variables holding webhook secrets.
   */
  public const string SECRET_ENV_PATTERN = '/^BEANTRAGT_WEBHOOK_(?:[A-Z0-9_]{0,103}_)?SECRET$/D';

  /**
   * Constructs webhook settings.
   *
   * @param string $targetUrl
   *   Webhook target URL.
   * @param string $secretEnv
   *   Secret environment variable name.
   * @param string $secret
   *   Runtime secret.
   * @param string[] $allowedHosts
   *   Lowercase exact host allowlist.
   * @param int $timeoutSeconds
   *   Request timeout.
   */
  public function __construct(
    public readonly string $targetUrl,
    public readonly string $secretEnv,
    public readonly string $secret,
    public readonly array $allowedHosts,
    public readonly int $timeoutSeconds,
  ) {}

  /**
   * Builds settings from Drupal config and environment overrides.
   */
  public static function fromConfig(ConfigFactoryInterface $config_factory): self {
    $config = $config_factory->get('beantragt_delivery.settings')->get('webhook') ?? [];
    $config = is_array($config) ? $config : [];

    $target_url = trim((string) (getenv('BEANTRAGT_WEBHOOK_TARGET_URL') ?: ($config['target_url'] ?? '')));
    $secret_env = trim((string) ($config['secret_env'] ?? 'BEANTRAGT_WEBHOOK_SECRET'));
    if ($secret_env === '') {
      $secret_env = 'BEANTRAGT_WEBHOOK_SECRET';
    }

    if (preg_match(self::SECRET_ENV_PATTERN, $secret_env) !== 1) {
      $secret_env = '';
    }
    $secret = $secret_env === '' ? '' : (string) (getenv($secret_env) ?: '');
    // Read the settings.php override directly. The merged config can retain
    // entries from a longer list written through the settings API.
    $allowed_hosts = self::normalizeAllowedHosts($GLOBALS['config']['beantragt_delivery.settings']['webhook']['allowed_hosts'] ?? []);
    $timeout = max(1, min(30, (int) ($config['timeout_seconds'] ?? 5)));

    return new self($target_url, $secret_env, $secret, $allowed_hosts, $timeout);
  }

  /**
   * Returns fields missing for a signed webhook dispatch.
   *
   * @return string[]
   *   Missing field names.
   */
  public function missingForDispatch(): array {
    $missing = [];
    if ($this->targetUrl === '') {
      $missing[] = 'target_url';
    }
    if ($this->secret === '') {
      $missing[] = 'secret';
    }
    if ($this->allowedHosts === []) {
      $missing[] = 'allowed_hosts';
    }
    return $missing;
  }

  /**
   * Returns secret-safe metadata for logs and diagnostics.
   *
   * @return array<string, mixed>
   *   Metadata without the signing secret or secret env var name.
   */
  public function toSafeMetadata(): array {
    return [
      'targetUrlConfigured' => $this->targetUrl !== '',
      'targetHost' => $this->targetHost(),
      'secretConfigured' => $this->secret !== '',
      'allowedHosts' => $this->allowedHosts,
      'timeoutSeconds' => $this->timeoutSeconds,
    ];
  }

  /**
   * Returns the configured target host, if parseable.
   */
  public function targetHost(): string {
    $host = parse_url($this->targetUrl, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
  }

  /**
   * Normalizes configured allowed hosts.
   *
   * @param mixed $raw
   *   Raw config value.
   *
   * @return string[]
   *   Lowercase exact hosts.
   */
  private static function normalizeAllowedHosts(mixed $raw): array {
    if (!is_array($raw)) {
      return [];
    }

    $hosts = [];
    foreach ($raw as $host) {
      $host = strtolower(trim((string) $host));
      if ($host !== '' && !str_contains($host, '/') && !str_contains($host, ':')) {
        $hosts[] = $host;
      }
    }

    return array_values(array_unique($hosts));
  }

}
