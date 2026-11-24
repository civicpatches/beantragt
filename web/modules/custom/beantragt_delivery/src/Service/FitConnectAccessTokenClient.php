<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Value\FitConnectAccessToken;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches OAuth tokens for FIT-Connect without logging token values.
 */
final class FitConnectAccessTokenClient {

  private const ALLOWED_TOKEN_HOSTS = [
    'TEST' => ['auth-testing.fit-connect.fitko.dev'],
    'STAGE' => ['auth-refz.fit-connect.fitko.net'],
    'PROD' => ['auth-prod.fit-connect.fitko.net'],
  ];

  /**
   * Constructs the client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Fetches a client credentials token.
   *
   * The returned token value must never be logged or sent to the browser.
   */
  public function fetchToken(FitConnectSettings $settings): FitConnectAccessToken {
    $missing = $settings->missingForTokenFetch();
    if ($missing !== []) {
      throw new FitConnectTransportException(
        'fit_connect.missing_configuration',
        'FIT-Connect token configuration is incomplete: ' . implode(', ', $missing),
      );
    }

    $policy_error = $this->tokenUrlPolicyError($settings);
    if ($policy_error !== NULL) {
      throw new FitConnectTransportException(
        'fit_connect.token_url_not_allowed',
        $policy_error,
      );
    }

    try {
      $response = $this->httpClient->request('POST', $settings->tokenUrl, [
        'auth' => NULL,
        'form_params' => [
          'grant_type' => 'client_credentials',
          'client_id' => $settings->clientId,
          'client_secret' => $settings->clientSecret,
        ],
        'headers' => [
          'Accept' => 'application/json',
        ],
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'verify' => TRUE,
        'connect_timeout' => 5,
        'timeout' => 10,
      ]);
    }
    catch (GuzzleException) {
      throw new FitConnectTransportException(
        'fit_connect.token_request_failed',
        'FIT-Connect token request failed.',
      );
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new FitConnectTransportException(
        'fit_connect.token_rejected',
        'FIT-Connect token endpoint rejected the request.',
        $status,
      );
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload)) {
      throw new FitConnectTransportException(
        'fit_connect.token_invalid_response',
        'FIT-Connect token endpoint returned invalid JSON.',
        $status,
      );
    }

    $token = trim((string) ($payload['access_token'] ?? ''));
    $token_type = trim((string) ($payload['token_type'] ?? 'Bearer'));
    $expires_in = (int) ($payload['expires_in'] ?? 0);

    if ($token === '') {
      throw new FitConnectTransportException(
        'fit_connect.token_invalid_response',
        'FIT-Connect token endpoint did not return an access token.',
        $status,
      );
    }

    return new FitConnectAccessToken($token, $token_type, $expires_in);
  }

  /**
   * Validates that credentials are sent only to known FIT-Connect token hosts.
   */
  private function tokenUrlPolicyError(FitConnectSettings $settings): ?string {
    $parts = parse_url($settings->tokenUrl);
    if (!is_array($parts)) {
      return 'FIT-Connect token URL is invalid.';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($scheme !== 'https' || $host === '' || $path !== '/token' || $port !== 443
      || isset($parts['user']) || isset($parts['pass'])) {
      return 'FIT-Connect token URL must be an HTTPS /token endpoint on an allowed FITKO host.';
    }

    $environment = strtoupper($settings->environment);
    $allowed_hosts = self::ALLOWED_TOKEN_HOSTS[$environment] ?? [];
    if (!in_array($host, $allowed_hosts, TRUE)) {
      return sprintf('FIT-Connect token URL is not allowed for environment %s.', $environment);
    }

    return NULL;
  }

}
