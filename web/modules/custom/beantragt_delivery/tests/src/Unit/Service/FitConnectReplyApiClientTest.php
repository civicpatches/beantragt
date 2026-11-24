<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectReplyApiClient;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Verifies bounded reply transport contracts without external requests.
 *
 * @group beantragt_delivery
 */
final class FitConnectReplyApiClientTest extends UnitTestCase {

  private const ID = '11111111-1111-4111-8111-111111111111';

  /**
   * Lists, downloads and accepts with correct verbs and raw JOSE response.
   */
  public function testRequests(): void {
    $history = [];
    $page = [
      'count' => 1,
      'offset' => 0,
      'totalCount' => 1,
      'replies' => [['replyId' => self::ID, 'caseId' => self::ID]],
    ];
    $api = $this->client([
      $this->response($page),
      $this->response(['replyId' => self::ID]),
      $this->response(['caseId' => self::ID, 'destinationId' => self::ID]),
      new Response(200, [], 'encrypted.attachment'),
      $this->response(['keys' => []]),
      new Response(200, [], 'signed.acceptance.set'),
    ], $history);
    $settings = $this->settings();
    $this->assertSame($page, $api->listReplies($settings, 'secret'));
    $this->assertSame(['replyId' => self::ID], $api->getReply($settings, 'secret', self::ID));
    $this->assertSame(self::ID, $api->getCase($settings, 'secret', self::ID)['destinationId']);
    $this->assertSame('encrypted.attachment', $api->getAttachment($settings, 'secret', self::ID, self::ID));
    $this->assertSame(['keys' => []], $api->keys($settings, 'secret'));
    $tags = ['metadata' => 'm', 'data' => 'd', 'attachments' => []];
    $this->assertSame('signed.acceptance.set', $api->accept($settings, 'secret', self::ID, $tags));
    $this->assertSame('PUT', $history[5]['request']->getMethod());
    $this->assertSame(['authenticationTags' => $tags], json_decode((string) $history[5]['request']->getBody(), TRUE));
    $this->assertSame('application/jose', $history[5]['request']->getHeaderLine('Accept'));
    $this->assertStringEndsWith('/.well-known/jwks.json', (string) $history[4]['request']->getUri());
    foreach ($history as $request) {
      $this->assertFalse($request['options']['allow_redirects']);
      $this->assertTrue($request['options']['verify']);
      $this->assertTrue($request['options']['stream']);
      $this->assertSame('Bearer secret', $request['request']->getHeaderLine('Authorization'));
    }
  }

  /**
   * Follows bounded event pagination rather than trusting a partial log.
   */
  public function testEventsPagination(): void {
    $history = [];
    $api = $this->client([
      $this->response(['count' => 1, 'offset' => 0, 'totalCount' => 2, 'eventLog' => ['a.b.c']]),
      $this->response(['count' => 1, 'offset' => 1, 'totalCount' => 2, 'eventLog' => ['d.e.f']]),
    ], $history);
    $this->assertSame(['a.b.c', 'd.e.f'], $api->events($this->settings(), 'secret', self::ID));
    $this->assertStringEndsWith('?offset=1&limit=50', (string) $history[1]['request']->getUri());
  }

  /**
   * Rejects unsafe endpoints, malformed paging, redirects and large bodies.
   */
  public function testInvalidRequests(): void {
    $history = [];
    $api = $this->client([], $history);
    foreach ([
      fn() => $api->getReply($this->settings(), 'secret', '../other'),
      fn() => $api->listReplies($this->settings('PROD'), 'secret'),
      fn() => $api->listReplies($this->settings(), 'secret', -1),
    ] as $request) {
      try {
        $request();
        $this->fail('Expected local rejection.');
      }
      catch (FitConnectTransportException) {
        $this->assertSame([], $history);
      }
    }
    foreach ([
      new Response(302, ['Location' => 'https://attacker.example'], 'private-data'),
      new Response(200, [], 'private-data'),
      new Response(200, [], str_repeat('x', 4000001)),
      $this->response(['count' => 0, 'offset' => 0, 'totalCount' => 1, 'eventLog' => []]),
      $this->response(['count' => 1, 'offset' => 5, 'totalCount' => 6, 'eventLog' => ['a.b.c']]),
    ] as $response) {
      $history = [];
      $api = $this->client([$response], $history);
      try {
        $api->events($this->settings(), 'secret', self::ID);
        $this->fail('Expected remote response rejection.');
      }
      catch (FitConnectTransportException $e) {
        $this->assertStringNotContainsString('private-data', $e->getMessage());
        $this->assertNull($e->getPrevious());
        $this->assertCount(1, $history);
      }
    }
  }

  /**
   * Creates the synthetic HTTP client.
   */
  private function client(array $queue, array &$history): FitConnectReplyApiClient {
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));
    return new FitConnectReplyApiClient(new Client(['handler' => $stack]));
  }

  /**
   * Encodes one mock JSON response.
   */
  private function response(array $value): Response {
    return new Response(200, [], json_encode($value, JSON_THROW_ON_ERROR));
  }

  /**
   * Creates TEST settings without reading environment values.
   */
  private function settings(string $environment = 'TEST'): FitConnectSettings {
    return new FitConnectSettings($environment, 'unit-client', 'UNIT_SECRET', 'unit-secret', FitConnectSettings::DEFAULT_TEST_TOKEN_URL, FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE, FitConnectSettings::DEFAULT_TEST_DESTINATION_API_BASE, FitConnectSettings::DEFAULT_TEST_ROUTING_API_BASE, self::ID, 'urn:test:service', '000000000000', 'urn:test:schema', 'application/json', '2.1.0', FALSE);
  }

}
