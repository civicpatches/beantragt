<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Value;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\beantragt_delivery\Exception\FitConnectTransportException;

/**
 * Runtime FIT-Connect configuration with secret-safe projections.
 */
final class FitConnectSettings {

  public const DEFAULT_TEST_TOKEN_URL = 'https://auth-testing.fit-connect.fitko.dev/token';
  public const DEFAULT_TEST_SUBMISSION_API_BASE = 'https://test.fit-connect.fitko.dev/submission-api';
  public const DEFAULT_TEST_DESTINATION_API_BASE = 'https://test.fit-connect.fitko.dev/destination-api';
  public const DEFAULT_TEST_ROUTING_API_BASE = 'https://routing-api-testing.fit-connect.fitko.dev';
  public const DEFAULT_SCHEMA_MIME_TYPE = 'application/json';
  public const DEFAULT_METADATA_SCHEMA_VERSION = '2.1.0';
  private const DEFAULT_CLIENT_SECRET_ENV = 'FIT_CONNECT_CLIENT_SECRET';
  private const ENV_VAR_NAME_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';

  /**
   * Constructs the settings value object.
   */
  public function __construct(
    public readonly string $environment,
    public readonly string $clientId,
    public readonly string $clientSecretEnv,
    public readonly string $clientSecret,
    public readonly string $tokenUrl,
    public readonly string $submissionApiBase,
    public readonly string $destinationApiBase,
    public readonly string $routingApiBase,
    public readonly string $destinationId,
    public readonly string $serviceUrn,
    public readonly string $ars,
    public readonly string $schemaUri,
    public readonly string $schemaMimeType,
    public readonly string $metadataSchemaVersion,
    public readonly bool $useRoutingApi,
    public readonly bool $replyEnabled = FALSE,
    public readonly string $replyProcessStandard = 'urn:beantragt:process:decision:1',
    public readonly bool $trustExternalRelease = FALSE,
  ) {}

  /**
   * Builds settings from Drupal config and environment fallbacks.
   */
  public static function fromConfig(ConfigFactoryInterface $config_factory): self {
    $config = $config_factory->get('beantragt_delivery.settings')->get('fit_connect') ?? [];
    $config = is_array($config) ? $config : [];
    $client_secret_env = self::safeEnvName(self::configValue($config, 'client_secret_env'));

    return new self(
      strtoupper(self::configValue($config, 'environment', 'FIT_CONNECT_ENVIRONMENT') ?: 'TEST'),
      self::configValue($config, 'client_id', 'FIT_CONNECT_CLIENT_ID'),
      $client_secret_env,
      self::envValue($client_secret_env),
      self::configValue($config, 'token_url', 'FIT_CONNECT_TOKEN_URL') ?: self::DEFAULT_TEST_TOKEN_URL,
      self::configValue($config, 'submission_api_base', 'FIT_CONNECT_SUBMISSION_API_BASE') ?: self::DEFAULT_TEST_SUBMISSION_API_BASE,
      self::configValue($config, 'destination_api_base') ?: self::DEFAULT_TEST_DESTINATION_API_BASE,
      self::configValue($config, 'routing_api_base') ?: self::DEFAULT_TEST_ROUTING_API_BASE,
      self::configValue($config, 'destination_id', 'FIT_CONNECT_DESTINATION_ID'),
      self::configValue($config, 'service_urn', 'FIT_CONNECT_SERVICE_URN'),
      self::configValue($config, 'ars', 'FIT_CONNECT_ARS'),
      self::configValue($config, 'schema_uri', 'FIT_CONNECT_SCHEMA_URI'),
      self::configValue($config, 'schema_mime_type') ?: self::DEFAULT_SCHEMA_MIME_TYPE,
      self::configValue($config, 'metadata_schema_version') ?: self::DEFAULT_METADATA_SCHEMA_VERSION,
      (bool) ($config['use_routing_api'] ?? FALSE),
      (bool) ($config['reply_enabled'] ?? FALSE),
      self::configValue($config, 'reply_process_standard') ?: 'urn:beantragt:process:decision:1',
      (bool) ($config['trust_external_release'] ?? FALSE),
    );
  }

  /**
   * Returns missing fields for token fetch.
   *
   * @return string[]
   *   Missing setting names.
   */
  public function missingForTokenFetch(): array {
    return array_values(array_filter([
      $this->clientId === '' ? 'client_id' : NULL,
      $this->clientSecret === '' ? 'client_secret' : NULL,
      $this->tokenUrl === '' ? 'token_url' : NULL,
    ]));
  }

  /**
   * Returns missing fields for real transport dispatch.
   *
   * @return string[]
   *   Missing setting names.
   */
  public function missingForSubmission(): array {
    $missing = [
      ...$this->missingForTokenFetch(),
      $this->submissionApiBase === '' ? 'submission_api_base' : NULL,
      $this->serviceUrn === '' ? 'service_urn' : NULL,
      $this->ars === '' ? 'ars' : NULL,
      $this->schemaUri === '' ? 'schema_uri' : NULL,
    ];

    if (!$this->useRoutingApi && $this->destinationId === '') {
      $missing[] = 'destination_id';
    }

    return array_values(array_filter($missing));
  }

  /**
   * Restricts this implementation to the proven TEST transport contract.
   */
  public function assertTestTransportAllowed(): void {
    if ($this->environment !== 'TEST'
      || $this->submissionApiBase !== self::DEFAULT_TEST_SUBMISSION_API_BASE
      || $this->tokenUrl !== self::DEFAULT_TEST_TOKEN_URL
      || str_contains($this->destinationApiBase, 'prod.fit-connect.fitko.net')
      || str_contains($this->routingApiBase, 'prod.fit-connect.fitko.net')) {
      throw new FitConnectTransportException('fit_connect.test_only', 'Only the FIT-Connect TEST endpoints are supported.');
    }
    if ($this->useRoutingApi || $this->metadataSchemaVersion !== '2.1.0' || $this->schemaMimeType !== 'application/json') {
      throw new FitConnectTransportException('fit_connect.unsupported_contract', 'FIT-Connect requires a fixed destination, metadata 2.1.0 and JSON data.');
    }
  }

  /**
   * Returns secret-free metadata for logs and diagnostics.
   *
   * @return array<string, mixed>
   *   Safe metadata.
   */
  public function toSafeMetadata(): array {
    return [
      'environment' => $this->environment,
      'tokenUrlConfigured' => $this->tokenUrl !== '',
      'submissionApiBaseConfigured' => $this->submissionApiBase !== '',
      'destinationApiBaseConfigured' => $this->destinationApiBase !== '',
      'routingApiBaseConfigured' => $this->routingApiBase !== '',
      'destinationConfigured' => $this->destinationId !== '',
      'clientConfigured' => $this->clientId !== '',
      'clientSecretConfigured' => $this->clientSecret !== '',
      'clientSecretEnvConfigured' => $this->clientSecretEnv !== '',
      'serviceUrnConfigured' => $this->serviceUrn !== '',
      'arsConfigured' => $this->ars !== '',
      'schemaUriConfigured' => $this->schemaUri !== '',
      'schemaMimeType' => $this->schemaMimeType,
      'metadataSchemaVersion' => $this->metadataSchemaVersion,
      'useRoutingApi' => $this->useRoutingApi,
      'replyEnabled' => $this->replyEnabled,
      'trustExternalRelease' => $this->trustExternalRelease,
    ];
  }

  /**
   * Reads a config value with optional environment fallback.
   *
   * @param array<string, mixed> $config
   *   Gateway configuration.
   * @param string $key
   *   Configuration key.
   * @param string|null $env
   *   Optional environment fallback name.
   */
  private static function configValue(array $config, string $key, ?string $env = NULL): string {
    $value = trim((string) ($config[$key] ?? ''));
    if ($value !== '' || $env === NULL) {
      return $value;
    }

    return self::envValue($env);
  }

  /**
   * Reads an environment value.
   */
  private static function envValue(string $env): string {
    $env_value = getenv($env);
    return is_string($env_value) ? trim($env_value) : '';
  }

  /**
   * Returns a safe env var name or the default.
   */
  private static function safeEnvName(string $name): string {
    $name = trim($name);
    if ($name === '' || !preg_match(self::ENV_VAR_NAME_PATTERN, $name)) {
      return self::DEFAULT_CLIENT_SECRET_ENV;
    }

    return $name;
  }

}
