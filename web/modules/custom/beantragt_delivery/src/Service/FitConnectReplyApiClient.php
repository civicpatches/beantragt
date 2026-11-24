<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use GuzzleHttp\ClientInterface;

/**
 * Receives replies through bounded, authenticated FIT-Connect TEST requests.
 */
final class FitConnectReplyApiClient {

  public const MAX_ATTACHMENT_BYTES = 15000000;
  private const MAX_JSON_BYTES = 4000000;

  /**
   * Constructs the client.
   */
  public function __construct(private readonly ClientInterface $httpClient) {}

  /**
   * Lists one bounded page without consuming any reply.
   */
  public function listReplies(FitConnectSettings $settings, string $token, int $offset = 0, int $limit = 50): array {
    if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 500) {
      $this->fail();
    }
    $page = $this->json($settings, $token, '/v2/replies?offset=' . $offset . '&limit=' . $limit);
    $this->validatePage($page, 'replies', $offset, $limit);
    foreach ($page['replies'] as $reply) {
      if (!is_array($reply)) {
        $this->fail();
      }
      $this->id($reply['replyId'] ?? NULL);
      $this->id($reply['caseId'] ?? NULL);
    }
    return $page;
  }

  /**
   * Downloads one reply envelope.
   */
  public function getReply(FitConnectSettings $settings, string $token, string $replyId): array {
    return $this->json($settings, $token, '/v2/replies/' . $this->id($replyId));
  }

  /**
   * Retrieves the destination binding of a case.
   */
  public function getCase(FitConnectSettings $settings, string $token, string $caseId): array {
    $case = $this->json($settings, $token, '/v2/cases/' . $this->id($caseId));
    if (strtolower($this->id($case['caseId'] ?? NULL)) !== strtolower($caseId)) {
      $this->fail();
    }
    $this->id($case['destinationId'] ?? NULL);
    return $case;
  }

  /**
   * Downloads an encrypted attachment within the size limit.
   */
  public function getAttachment(FitConnectSettings $settings, string $token, string $replyId, string $attachmentId): string {
    return $this->request($settings, $token, '/v2/replies/' . $this->id($replyId) . '/attachments/' . $this->id($attachmentId), 'application/jose', self::MAX_ATTACHMENT_BYTES);
  }

  /**
   * Retrieves all reply SETs with bounded pagination.
   */
  public function events(FitConnectSettings $settings, string $token, string $replyId): array {
    $path = '/v2/replies/' . $this->id($replyId) . '/events';
    $events = [];
    $offset = 0;
    for ($page_number = 0; $page_number < 20; $page_number++) {
      $page = $this->json($settings, $token, $path . '?offset=' . $offset . '&limit=50');
      $this->validatePage($page, 'eventLog', $offset, 50);
      foreach ($page['eventLog'] as $event) {
        if (!is_string($event) || strlen($event) > 65536 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $event)) {
          $this->fail();
        }
        $events[] = $event;
      }
      $offset += $page['count'];
      if ($offset >= $page['totalCount']) {
        return $events;
      }
      if ($page['count'] === 0) {
        $this->fail();
      }
    }
    $this->fail();
  }

  /**
   * Retrieves only the configured TEST service's public signing keys.
   */
  public function keys(FitConnectSettings $settings, string $token): array {
    $keys = $this->json($settings, $token, '/.well-known/jwks.json');
    if (!is_array($keys['keys'] ?? NULL) || !array_is_list($keys['keys']) || count($keys['keys']) > 100) {
      $this->fail();
    }
    return $keys;
  }

  /**
   * Confirms durable reception, returning the service-signed acceptance SET.
   */
  public function accept(FitConnectSettings $settings, string $token, string $replyId, array $authenticationTags): string {
    return $this->request($settings, $token, '/v2/replies/' . $this->id($replyId) . '/accept', 'application/jose', 65536, ['authenticationTags' => $authenticationTags]);
  }

  /**
   * Decodes a bounded JSON object.
   */
  private function json(FitConnectSettings $settings, string $token, string $path): array {
    $body = $this->request($settings, $token, $path, 'application/json', self::MAX_JSON_BYTES);
    try {
      $value = json_decode($body, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable) {
      $this->fail();
    }
    if (!is_array($value) || array_is_list($value)) {
      $this->fail();
    }
    return $value;
  }

  /**
   * Executes an exact TEST request, never exposing remote exception details.
   */
  private function request(FitConnectSettings $settings, string $token, string $path, string $accept, int $max_bytes, ?array $payload = NULL): string {
    $settings->assertTestTransportAllowed();
    $options = [
      'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => $accept],
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'verify' => TRUE,
      'auth' => NULL,
      'connect_timeout' => 5,
      'timeout' => 60,
      'read_timeout' => 15,
      'stream' => TRUE,
    ];
    if ($payload !== NULL) {
      $options['json'] = $payload;
    }
    try {
      $response = $this->httpClient->request($payload === NULL ? 'GET' : 'PUT', $settings->submissionApiBase . $path, $options);
      if ($response->getStatusCode() !== 200) {
        $response->getBody()->close();
        throw new FitConnectTransportException('fit_connect.reply_api_rejected', 'FIT-Connect reply API rejected the request.', $response->getStatusCode());
      }
      $stream = $response->getBody();
      $body = '';
      try {
        while (!$stream->eof()) {
          $chunk = $stream->read(min(8192, $max_bytes + 1 - strlen($body)));
          if ($chunk === '') {
            break;
          }
          $body .= $chunk;
          if (strlen($body) > $max_bytes) {
            $this->fail();
          }
        }
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && (!ctype_digit($length) || (int) $length !== strlen($body))) {
          $this->fail();
        }
      }
      finally {
        $stream->close();
      }
      return $body;
    }
    catch (FitConnectTransportException $e) {
      throw $e;
    }
    catch (\Throwable) {
      throw new FitConnectTransportException('fit_connect.reply_api_failed', 'FIT-Connect reply request failed.');
    }
  }

  /**
   * Checks pagination so truncated logs cannot pass verification.
   */
  private function validatePage(array $page, string $key, int $offset, int $limit): void {
    if (!is_array($page[$key] ?? NULL) || !array_is_list($page[$key])
      || !is_int($page['count'] ?? NULL) || $page['count'] !== count($page[$key]) || $page['count'] > $limit
      || ($page['offset'] ?? NULL) !== $offset || !is_int($page['totalCount'] ?? NULL)
      || $page['totalCount'] < $offset + $page['count']) {
      $this->fail();
    }
  }

  /**
   * Allows UUID path segments only.
   */
  private function id(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value)) {
      $this->fail();
    }
    return $value;
  }

  /**
   * Rejects malformed input without including remote data.
   */
  private function fail(): never {
    throw new FitConnectTransportException('fit_connect.reply_api_invalid', 'FIT-Connect reply API data is invalid or exceeds limits.');
  }

}
