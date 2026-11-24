<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use GuzzleHttp\ClientInterface;

/**
 * Sends authenticated requests exclusively to the FIT-Connect TEST API.
 */
final class FitConnectSubmissionApiClient {

  /**
   * Constructs the API client.
   */
  public function __construct(private readonly ClientInterface $httpClient) {}

  /**
   * Sends one request without redirects or unsafe exception details.
   *
   * @param \Drupal\beantragt_delivery\Value\FitConnectSettings $settings
   *   The TEST transport configuration.
   * @param string $token
   *   The in-memory bearer token.
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   An allowed API resource path.
   * @param int $expected_status
   *   The exact required response status.
   * @param array<string, mixed> $options
   *   JSON or raw body and optional content type headers.
   *
   * @return array<string, mixed>
   *   The decoded response, or an empty array for an empty response.
   */
  public function request(FitConnectSettings $settings, string $token, string $method, string $path, int $expected_status, array $options = []): array {
    $settings->assertTestTransportAllowed();
    $segment = '[A-Za-z0-9_-]+';
    $pattern = '#^/v2/(?:destinations/' . $segment . '(?:/keys/' . $segment . ')?|submissions(?:/' . $segment . '(?:/attachments/' . $segment . ')?)?|cases/' . $segment . '/events)$#D';
    if (!in_array($method, ['GET', 'POST', 'PUT'], TRUE) || !preg_match($pattern, $path)) {
      throw new FitConnectTransportException('fit_connect.api_path_not_allowed', 'FIT-Connect API path or method is not allowed.');
    }
    if (array_diff(array_keys($options), ['json', 'body', 'headers']) !== []) {
      throw new FitConnectTransportException('fit_connect.api_options_not_allowed', 'FIT-Connect API request options are not allowed.');
    }
    $headers = [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ];
    if (isset($options['headers']['Content-Type'])) {
      $headers['Content-Type'] = $options['headers']['Content-Type'];
    }
    $options['headers'] = $headers;
    $options['allow_redirects'] = FALSE;
    $options['http_errors'] = FALSE;
    $options['verify'] = TRUE;
    $options['auth'] = NULL;
    $options['connect_timeout'] = 5;
    $options['timeout'] = 60;

    try {
      $response = $this->httpClient->request($method, $settings->submissionApiBase . $path, $options);
      $status = $response->getStatusCode();
      $body = (string) $response->getBody();
    }
    catch (\Throwable) {
      // Guzzle exceptions may contain bearer credentials and submitted bodies.
      throw new FitConnectTransportException('fit_connect.api_request_failed', 'FIT-Connect API request failed.');
    }
    if ($status !== $expected_status) {
      throw new FitConnectTransportException('fit_connect.api_rejected', 'FIT-Connect API returned an unexpected status.', $status);
    }
    if ($status === 204 || $body === '') {
      return [];
    }
    $payload = json_decode($body, TRUE);
    if (!is_array($payload)) {
      throw new FitConnectTransportException('fit_connect.api_invalid_response', 'FIT-Connect API returned an invalid response.', $status);
    }
    return $payload;
  }

  /**
   * Projects event names without storing tokens or trusting their signatures.
   *
   * @return array{eventTypes: list<string>, signaturesVerified: bool}
   *   Informational sender events, never evidence of recipient acceptance.
   */
  public function events(FitConnectSettings $settings, string $token, string $case_id): array {
    $response = $this->request($settings, $token, 'GET', '/v2/cases/' . rawurlencode($case_id) . '/events', 200);
    $types = [];
    $event_log = $response['eventLog'] ?? [];
    if (!is_array($event_log)) {
      throw new FitConnectTransportException('fit_connect.events_invalid_response', 'FIT-Connect event log is invalid.');
    }
    foreach ($event_log as $signed_event) {
      if (!is_string($signed_event)) {
        continue;
      }
      $parts = explode('.', $signed_event);
      if (count($parts) !== 3) {
        continue;
      }
      $decoded = base64_decode(strtr($parts[1], '-_', '+/'), TRUE);
      $payload = $decoded === FALSE ? NULL : json_decode($decoded, TRUE);
      if (!is_array($payload) || !is_array($payload['events'] ?? NULL)) {
        continue;
      }
      foreach (array_keys($payload['events']) as $uri) {
        $name = basename((string) $uri);
        if (in_array($name, ['create-submission', 'submit-submission'], TRUE)) {
          $types[] = $name;
        }
      }
    }
    return [
      'eventTypes' => array_values(array_unique($types)),
      'signaturesVerified' => FALSE,
    ];
  }

}
