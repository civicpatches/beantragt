<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectAccessTokenClient;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the FIT-Connect OAuth token client.
 *
 * @group beantragt_delivery
 */
final class FitConnectAccessTokenClientTest extends UnitTestCase {

  /**
   * Token fetch uses client credentials and exposes no token in safe metadata.
   */
  public function testFetchToken(): void {
    $history = [];
    $client = $this->client([
      new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => 'secret-access-token',
        'token_type' => 'Bearer',
        'expires_in' => 299,
      ], JSON_THROW_ON_ERROR)),
    ], $history);

    $token = (new FitConnectAccessTokenClient($client))->fetchToken($this->settings());

    $this->assertSame('secret-access-token', $token->accessToken);
    $this->assertSame([
      'tokenType' => 'Bearer',
      'expiresIn' => 299,
      'accessTokenFetched' => TRUE,
    ], $token->toSafeMetadata());
    $this->assertCount(1, $history);
    $request = $history[0]['request'];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('https://auth-testing.fit-connect.fitko.dev/token', (string) $request->getUri());
    $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    $this->assertStringContainsString('grant_type=client_credentials', (string) $request->getBody());
    $this->assertSame('', $request->getHeaderLine('Authorization'));
    parse_str((string) $request->getBody(), $form);
    $this->assertSame([
      'grant_type' => 'client_credentials',
      'client_id' => 'sender-client',
      'client_secret' => 'sender-secret',
    ], $form);
    $this->assertFalse($history[0]['options']['allow_redirects']);
  }

  /**
   * Token endpoint rejections become safe transport exceptions.
   */
  public function testTokenRejection(): void {
    $history = [];
    $client = $this->client([
      new Response(401, ['Content-Type' => 'application/json'], '{"error":"invalid_client"}'),
    ], $history);

    try {
      (new FitConnectAccessTokenClient($client))->fetchToken($this->settings());
      $this->fail('Expected token rejection exception.');
    }
    catch (FitConnectTransportException $e) {
      $this->assertSame('fit_connect.token_rejected', $e->errorCode);
      $this->assertSame(401, $e->statusCode);
      $this->assertStringNotContainsString('sender-secret', $e->getMessage());
    }
  }

  /**
   * Token fetch refuses non-FITKO token URLs before sending credentials.
   */
  public function testTokenUrlPolicyBlocksCredentialExfiltration(): void {
    $history = [];
    $client = $this->client([
      new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"unused"}'),
    ], $history);

    try {
      (new FitConnectAccessTokenClient($client))->fetchToken($this->settings(token_url: 'https://attacker.example/token'));
      $this->fail('Expected token URL policy exception.');
    }
    catch (FitConnectTransportException $e) {
      $this->assertSame('fit_connect.token_url_not_allowed', $e->errorCode);
      $this->assertSame(0, $e->statusCode);
      $this->assertCount(0, $history);
    }
  }

  /**
   * Builds a Guzzle client with history middleware.
   *
   * @param array<int, mixed> $queue
   *   Mock handler queue.
   * @param array<int, mixed> $history
   *   Request history.
   */
  private function client(array $queue, array &$history): Client {
    $handler_stack = HandlerStack::create(new MockHandler($queue));
    $handler_stack->push(Middleware::history($history));
    return new Client(['handler' => $handler_stack]);
  }

  /**
   * Builds complete token settings.
   */
  private function settings(string $token_url = 'https://auth-testing.fit-connect.fitko.dev/token'): FitConnectSettings {
    return new FitConnectSettings(
      'TEST',
      'sender-client',
      'FIT_CONNECT_CLIENT_SECRET',
      'sender-secret',
      $token_url,
      'https://test.fit-connect.fitko.dev/submission-api',
      'https://test.fit-connect.fitko.dev/destination-api',
      'https://routing-api-testing.fit-connect.fitko.dev',
      'destination-123',
      'urn:de:fim:leika:leistung:512431',
      '000000000000',
      'urn:beantragt:dev:musterantrag-bescheid:v1',
      'application/json',
      '2.1.0',
      FALSE,
    );
  }

}
