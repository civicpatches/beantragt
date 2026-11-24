<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Contract of the Drupal side docgen client.
 */
#[Group('beantragt_docgen')]
final class DocgenClientTest extends UnitTestCase {

  /**
   * Requests sent by the client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * Busy answers become docgen.busy with Retry-After, without a retry.
   */
  public function testBusyIsReportedNotRetried(): void {
    $cases = [
      [503, 'docgen_busy', '7', 7],
      [429, 'tenant_busy', '', DocgenClient::DEFAULT_RETRY_AFTER_SECONDS],
      [503, 'docgen_busy', '9999', 300],
    ];
    foreach ($cases as [$status, $code, $header, $expected]) {
      $this->history = [];
      $headers = $header === '' ? [] : ['Retry-After' => $header];
      $client = $this->client([new Response($status, $headers, json_encode(['error' => $code]))]);
      try {
        $client->inspect('docx');
        self::fail('Busy docgen did not throw.');
      }
      catch (DocgenApiException $exception) {
        self::assertSame('docgen.busy', $exception->errorCode);
        self::assertSame(503, $exception->status);
        self::assertSame($expected, $exception->retryAfter);
      }
      self::assertCount(1, $this->history, 'The client retried on its own.');
    }
  }

  /**
   * Token, route and JSON body of a call.
   */
  public function testInspectSendsBearerTokenAndBase64(): void {
    $client = $this->client([
      $this->jsonResponse([
        'placeholders' => ['heute', 7],
        'loops' => [],
        'errors' => [['id' => 'unclosed_loop', 'message' => 'x', 'tag' => 'a']],
      ]),
    ]);
    $result = $client->inspect('DOCX-BYTES');
    self::assertSame(['heute'], $result['placeholders']);
    self::assertSame('unclosed_loop', $result['errors'][0]['id']);
    $request = $this->history[0]['request'];
    self::assertSame('http://docgen:3000/inspect', (string) $request->getUri());
    self::assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
    $body = json_decode((string) $request->getBody(), TRUE);
    self::assertSame(['template' => base64_encode('DOCX-BYTES')], $body);
  }

  /**
   * Render checks the returned hash; a mismatch is an integrity failure.
   */
  public function testRenderVerifiesHash(): void {
    $docx = 'PK rendered';
    $client = $this->client([
      $this->jsonResponse([
        'docx' => base64_encode($docx),
        'sha256' => hash('sha256', $docx),
        'missing' => ['antrag.x'],
      ]),
    ]);
    $result = $client->render('template', ['heute' => '21.10.2026']);
    self::assertSame($docx, $result['docx']);
    self::assertSame(['antrag.x'], $result['missing']);
    $body = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    self::assertSame(['heute' => '21.10.2026'], $body['data']);

    $client = $this->client([
      $this->jsonResponse([
        'docx' => base64_encode($docx),
        'sha256' => str_repeat('0', 64),
        'missing' => [],
      ]),
    ]);
    $this->expectExceptionObject(new DocgenApiException('docgen.integrity_failed', 502));
    $client->render('template', []);
  }

  /**
   * Finalize requires matching hashes and a 2b profile for compliance.
   */
  public function testFinalizeMapsValidation(): void {
    $docx = 'PK draft';
    $pdf = "%PDF-1.7\nbody";
    $answer = fn (array $validation): Response => $this->jsonResponse([
      'pdf' => base64_encode($pdf),
      'sha256' => hash('sha256', $pdf),
      'docxSha256' => hash('sha256', $docx),
      'validation' => $validation + ['failedRules' => 0, 'failedChecks' => 0, 'summary' => 'ok'],
    ]);
    $client = $this->client([
      $answer(['profile' => '2b', 'compliant' => TRUE]),
      $answer(['profile' => '2u', 'compliant' => TRUE]),
      $answer(['profile' => '2b', 'compliant' => FALSE, 'failedRules' => 2, 'failedChecks' => 5]),
    ]);
    self::assertTrue($client->finalize($docx)['validation']['compliant']);
    self::assertFalse($client->finalize($docx)['validation']['compliant']);
    $failed = $client->finalize($docx)['validation'];
    self::assertFalse($failed['compliant']);
    self::assertSame(2, $failed['failedRules']);
  }

  /**
   * Error answers map to stable codes without upstream text.
   */
  public function testErrorMapping(): void {
    $template_errors = ['errors' => [['id' => 'unclosed_loop', 'message' => 'm', 'tag' => 't']]];
    $cases = [
      [
        $this->jsonResponse(['error' => 'template_invalid', 'details' => $template_errors], 422),
        'docgen.template_invalid',
        422,
      ],
      [$this->jsonResponse(['error' => 'docx_macro_or_template'], 422), 'docgen.docx_rejected', 422],
      [$this->jsonResponse(['error' => 'docx_too_large'], 413), 'docgen.too_large', 413],
      [$this->jsonResponse(['error' => 'converter_failed'], 502), 'docgen.conversion_failed', 502],
      [$this->jsonResponse(['error' => 'unauthorized'], 401), 'docgen.unavailable', 502],
      [
        new ConnectException('Connection refused', new Request('POST', 'http://docgen:3000/inspect')),
        'docgen.unavailable',
        503,
      ],
    ];
    foreach ($cases as [$answer, $code, $status]) {
      try {
        $this->client([$answer])->inspect('docx');
        self::fail('No error for ' . $code);
      }
      catch (DocgenApiException $exception) {
        self::assertSame($code, $exception->errorCode);
        self::assertSame($status, $exception->status);
      }
    }
  }

  /**
   * Without token or URL nothing is sent.
   */
  public function testNotConfigured(): void {
    $client = new DocgenClient(
      new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
      $this->createMock(LoggerInterface::class),
      ['baseUrl' => 'http://docgen:3000', 'token' => ' '],
    );
    self::assertFalse($client->isConfigured());
    $this->expectExceptionObject(new DocgenApiException('docgen.not_configured', 503));
    $client->inspect('docx');
  }

  /**
   * Builds a JSON response.
   */
  private function jsonResponse(array $body, int $status = 200): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * Builds a client with queued answers and request history.
   */
  private function client(array $answers): DocgenClient {
    $stack = HandlerStack::create(new MockHandler($answers));
    $stack->push(Middleware::history($this->history));
    return new DocgenClient(
      new Client(['handler' => $stack]),
      $this->createMock(LoggerInterface::class),
      ['baseUrl' => 'http://docgen:3000/', 'token' => " secret-token\n"],
    );
  }

}
