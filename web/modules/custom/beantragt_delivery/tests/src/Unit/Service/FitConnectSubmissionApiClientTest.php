<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionApiClient;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the submission transport using exclusively mocked HTTP responses.
 *
 * @group beantragt_delivery
 */
final class FitConnectSubmissionApiClientTest extends UnitTestCase {

  /**
   * JSON creation and raw attachment requests honor the exact API contract.
   */
  public function testJsonAndAttachmentRequests(): void {
    $history = [];
    $api = $this->client([new Response(201, [], '{"submissionId":"submission-1","caseId":"case-1"}'), new Response(204)], $history);
    $this->assertSame(['submissionId' => 'submission-1', 'caseId' => 'case-1'], $api->request($this->settings(), 'unit-token', 'POST', '/v2/submissions', 201, ['json' => ['destinationId' => 'unit-destination']]));
    $this->assertSame([], $api->request($this->settings(), 'unit-token', 'PUT', '/v2/submissions/submission-1/attachments/attachment-1', 204, [
      'body' => 'compact.jwe.bytes.iv.tag',
      'headers' => ['Content-Type' => 'application/jose'],
    ]));
    $this->assertSame('Bearer unit-token', $history[0]['request']->getHeaderLine('Authorization'));
    $this->assertSame('https://test.fit-connect.fitko.dev/submission-api/v2/submissions', (string) $history[0]['request']->getUri());
    $this->assertSame('compact.jwe.bytes.iv.tag', (string) $history[1]['request']->getBody());
    $this->assertSame('application/jose', $history[1]['request']->getHeaderLine('Content-Type'));
    $this->assertFalse($history[0]['options']['allow_redirects']);
    $this->assertTrue($history[0]['options']['verify']);
  }

  /**
   * Redirects and all unexpected statuses fail without exposing response data.
   */
  public function testRejectsUnexpectedResponses(): void {
    $responses = [
      new Response(302, ['Location' => 'https://attacker.example/'], 'private-body'),
      new Response(200, [], 'private-body'),
      new Response(201, [], 'private-body'),
    ];
    foreach ($responses as $response) {
      $history = [];
      $api = $this->client([$response], $history);
      try {
        $api->request($this->settings(), 'unit-token', 'POST', '/v2/submissions', 201);
        $this->fail('Expected invalid response rejection.');
      }
      catch (FitConnectTransportException $e) {
        $this->assertStringNotContainsString('private-body', $e->getMessage());
        $this->assertStringNotContainsString('unit-token', $e->getMessage());
        $this->assertNull($e->getPrevious());
        $this->assertCount(1, $history);
      }
    }
  }

  /**
   * HTTP exception chains cannot leak credential-bearing request objects.
   */
  public function testDropsUnsafeExceptionChain(): void {
    $history = [];
    $api = $this->client([new RequestException('unit-token private-data', new Request('GET', 'https://example.org'))], $history);
    try {
      $api->request($this->settings(), 'unit-token', 'GET', '/v2/destinations/unit-destination', 200);
      $this->fail('Expected request failure.');
    }
    catch (FitConnectTransportException $e) {
      $this->assertSame('fit_connect.api_request_failed', $e->errorCode);
      $this->assertNull($e->getPrevious());
      $this->assertStringNotContainsString('unit-token', (string) $e);
    }
  }

  /**
   * Production, alternate endpoints, path traversal and URL injection fail.
   */
  public function testBlocksUnsafeRequestsBeforeHttp(): void {
    $attempts = [
      [$this->settings('PROD'), '/v2/submissions', []],
      [$this->settings(base: 'https://prod.fit-connect.fitko.net/submission-api'), '/v2/submissions', []],
      [$this->settings(base: 'https://attacker.example/submission-api'), '/v2/submissions', []],
      [$this->settings(), '//attacker.example/submissions', []],
      [$this->settings(), '/v2/submissions/../secret', []],
      [$this->settings(), '/v2/submissions/%2e%2e', []],
      [$this->settings(), '/v2/submissions', ['allow_redirects' => TRUE]],
    ];
    foreach ($attempts as [$settings, $path, $options]) {
      $history = [];
      $api = $this->client([], $history);
      try {
        $api->request($settings, 'unit-token', 'POST', $path, 201, $options);
        $this->fail('Expected transport policy rejection.');
      }
      catch (FitConnectTransportException) {
        $this->assertCount(0, $history);
      }
    }
  }

  /**
   * Event projections cannot claim acceptance or expose untrusted payloads.
   */
  public function testUnverifiedEventProjection(): void {
    $payload = json_encode([
      'sub' => 'private-reference',
      'events' => [
        'https://schema.fitko.de/fit-connect/events/create-submission' => ['private' => 'data'],
        'https://schema.fitko.de/fit-connect/events/submit-submission' => [],
        'https://schema.fitko.de/fit-connect/events/accept-submission' => [],
        'https://schema.fitko.de/fit-connect/events/reject-submission' => [],
      ],
    ], JSON_THROW_ON_ERROR);
    $event = 'header.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.signature';
    $history = [];
    $api = $this->client([new Response(200, [], json_encode(['eventLog' => [$event, 'malformed', $event]], JSON_THROW_ON_ERROR))], $history);
    $this->assertSame(['eventTypes' => ['create-submission', 'submit-submission'], 'signaturesVerified' => FALSE], $api->events($this->settings(), 'unit-token', 'case-1'));
  }

  /**
   * Creates a transport backed by a mock queue and request history.
   *
   * @param array<int, mixed> $queue
   *   The response queue.
   * @param array<int, mixed> $history
   *   The recorded requests.
   */
  private function client(array $queue, array &$history): FitConnectSubmissionApiClient {
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));
    return new FitConnectSubmissionApiClient(new Client(['handler' => $stack]));
  }

  /**
   * Creates synthetic TEST settings without loading environment secrets.
   */
  private function settings(string $environment = 'TEST', string $base = FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE): FitConnectSettings {
    return new FitConnectSettings($environment, 'unit-client', 'UNIT_SECRET', 'unit-secret', FitConnectSettings::DEFAULT_TEST_TOKEN_URL, $base, FitConnectSettings::DEFAULT_TEST_DESTINATION_API_BASE, FitConnectSettings::DEFAULT_TEST_ROUTING_API_BASE, 'unit-destination', 'urn:test:service', '000000000000', 'urn:test:schema', 'application/json', '2.1.0', FALSE);
  }

}
