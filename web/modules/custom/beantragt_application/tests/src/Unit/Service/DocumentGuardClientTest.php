<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\AttachmentAnalysisSourcePreparer;
use Drupal\beantragt_application\Service\DocumentGuardClient;
use Drupal\beantragt_application\Service\DocumentGuardClientInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\StreamInterface;

/**
 * Tests the authenticated document guard boundary.
 */
#[CoversClass(DocumentGuardClient::class)]
#[Group('beantragt_application')]
final class DocumentGuardClientTest extends UnitTestCase {

  /**
   * Environment values restored after each test.
   *
   * @var array<string, string|false>
   */
  private array $environment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ([
      'BEANTRAGT_RAG_SERVICE_BASE_URL',
      'BEANTRAGT_RAG_SERVICE_TENANT',
      'BEANTRAGT_RAG_SERVICE_API_KEY',
      'BEANTRAGT_RAG_SERVICE_ALLOW_HTTP',
      'BEANTRAGT_RAG_SERVICE_TIMEOUT_SECONDS',
      'BEANTRAGT_DOCUMENT_GUARD_LOCAL_HOSTS',
      'IS_DDEV_PROJECT',
      'BEANTRAGT_ENV',
    ] as $name) {
      $this->environment[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $name => $value) {
      $value === FALSE ? putenv($name) : putenv($name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * A valid derivative is authenticated and verified byte for byte.
   */
  public function testCreateDerivativeUsesTenantAuthAndValidatesDerivative(): void {
    $this->configure();
    $source = '%PDF- original';
    $derivative = '%PDF- derivative';
    $report = $this->report('redacted', [
      'mediaType' => 'application/pdf',
      'size' => strlen($derivative),
      'sha256' => hash('sha256', $derivative),
    ]);
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use ($source, $report, $derivative): Response {
        $this->assertSame('POST', $method);
        $this->assertSame('http://rag-service:8080/v1/document-guard/derivatives', $url);
        $this->assertSame('Bearer test-rag-service-key-with-at-least-thirty-two-bytes', $options[RequestOptions::HEADERS]['Authorization']);
        $this->assertSame('kommune-a', $options[RequestOptions::HEADERS]['X-Beantragt-Tenant']);
        $this->assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        $this->assertFalse($options[RequestOptions::HTTP_ERRORS]);
        $this->assertSame('', $options[RequestOptions::PROXY]);
        // The shared retrieval timeout of 30 s is raised to the guard floor.
        $this->assertSame(50.0, $options[RequestOptions::TIMEOUT]);
        $this->assertSame('document', $options[RequestOptions::MULTIPART][0]['name']);
        $this->assertSame($source, $options[RequestOptions::MULTIPART][0]['contents']);
        $this->assertSame('application/pdf', $options[RequestOptions::MULTIPART][0]['headers']['Content-Type']);
        return new Response(200, [], json_encode([
          'report' => $report,
          'derivativeBase64' => base64_encode($derivative),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
      });

    $result = (new DocumentGuardClient($client))->createDerivative($source, 'application/pdf');

    $this->assertSame('redacted', $result['status']);
    $this->assertSame($derivative, $result['derivative']);
    $this->assertSame('application/pdf', $result['mediaType']);
    $this->assertSame('full', $result['entityProfile']);
    $this->assertSame(
      json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
      $result['reportJson'],
    );
  }

  /**
   * Missing tenant and key configuration fails before any request.
   */
  public function testMissingConfigurationFailsClosed(): void {
    putenv('BEANTRAGT_RAG_SERVICE_BASE_URL=https://rag.example.test');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_configuration');
    (new DocumentGuardClient($client))->createDerivative('%PDF source', 'application/pdf');
  }

  /**
   * A mismatched derivative hash rejects the complete envelope.
   */
  public function testMismatchedDerivativeFailsClosed(): void {
    $this->configure();
    $derivative = '%PDF- derivative';
    $report = $this->report('clean', [
      'mediaType' => 'application/pdf',
      'size' => strlen($derivative),
      'sha256' => str_repeat('0', 64),
    ]);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], json_encode([
      'report' => $report,
      'derivativeBase64' => base64_encode($derivative),
    ], JSON_THROW_ON_ERROR)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_envelope');
    (new DocumentGuardClient($client))->createDerivative('%PDF source', 'application/pdf');
  }

  /**
   * Unknown report fields are rejected before PII-free persistence.
   */
  public function testUnknownFindingFieldsFailClosed(): void {
    $this->configure();
    $source = '%PDF- original';
    $derivative = '%PDF- derivative';
    $report = $this->report('redacted', [
      'mediaType' => 'application/pdf',
      'size' => strlen($derivative),
      'sha256' => hash('sha256', $derivative),
    ]);
    $report['findings'][0]['value'] = 'alice@example.org';
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], json_encode([
      'report' => $report,
      'derivativeBase64' => base64_encode($derivative),
    ], JSON_THROW_ON_ERROR)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_envelope');
    (new DocumentGuardClient($client))->createDerivative($source, 'application/pdf');
  }

  /**
   * Oversized declared responses fail before the body is allocated.
   */
  public function testOversizedResponseFailsClosedBeforeBodyRead(): void {
    $this->configure();
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [
      'Content-Length' => '15728641',
    ], '{}'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_envelope');
    (new DocumentGuardClient($client))->createDerivative('%PDF- original', 'application/pdf');
  }

  /**
   * Original bytes cannot be configured to leave for a public HTTPS host.
   */
  public function testPublicHttpsGuardHostFailsClosed(): void {
    $this->configure();
    putenv('BEANTRAGT_RAG_SERVICE_BASE_URL=https://rag.example.test');
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_configuration');
    (new DocumentGuardClient($client))->createDerivative('%PDF- original', 'application/pdf');
  }

  /**
   * The entity profile travels as its own part; the full set is the default.
   */
  public function testCreateDerivativeSendsTheEntityProfile(): void {
    $this->configure();
    $source = '%PDF- original';
    $sent = [];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use (&$sent): Response {
        $this->assertSame('document', $options[RequestOptions::MULTIPART][0]['name']);
        $this->assertSame('entityProfile', $options[RequestOptions::MULTIPART][1]['name']);
        $sent[] = $options[RequestOptions::MULTIPART][1]['contents'];
        $report = $this->report('needs_review', []);
        $report['reasons'] = ['empty_text_extraction'];
        $report['derivative'] = NULL;
        return new Response(200, [], json_encode([
          'report' => $report,
          'derivativeBase64' => NULL,
        ], JSON_THROW_ON_ERROR));
      });
    $guard = new DocumentGuardClient($client);

    $full = $guard->createDerivative($source, 'application/pdf');
    $reduced = $guard->createDerivative($source, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN);

    $this->assertSame(['full', 'analysis_sovereign'], $sent);
    $this->assertSame('full', $full['entityProfile']);
    $this->assertSame('analysis_sovereign', $reduced['entityProfile']);
  }

  /**
   * An unknown entity profile fails before any request.
   */
  public function testUnknownEntityProfileFailsBeforeRequest(): void {
    $this->configure();
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_entity_profile');
    (new DocumentGuardClient($client))->createDerivative('%PDF- original', 'application/pdf', 'none');
  }

  /**
   * Rendered pages are authenticated, decoded and verified in order.
   */
  public function testRenderPagesReturnsVerifiedPages(): void {
    $this->configure();
    $source = '%PDF- original';
    $first = "\xFF\xD8\xFF\xE0page-one\xFF\xD9";
    $second = "\xFF\xD8\xFF\xE0page-two\xFF\xD9";
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use ($source, $first, $second): Response {
        $this->assertSame('POST', $method);
        $this->assertSame('http://rag-service:8080/v1/document-guard/pages', $url);
        $this->assertSame('Bearer test-rag-service-key-with-at-least-thirty-two-bytes', $options[RequestOptions::HEADERS]['Authorization']);
        $this->assertSame('kommune-a', $options[RequestOptions::HEADERS]['X-Beantragt-Tenant']);
        $this->assertFalse($options[RequestOptions::ALLOW_REDIRECTS]);
        $this->assertSame('', $options[RequestOptions::PROXY]);
        $this->assertSame(50.0, $options[RequestOptions::TIMEOUT]);
        $this->assertSame('document', $options[RequestOptions::MULTIPART][0]['name']);
        $this->assertSame($source, $options[RequestOptions::MULTIPART][0]['contents']);
        $this->assertSame('application/pdf', $options[RequestOptions::MULTIPART][0]['headers']['Content-Type']);
        $this->assertSame(['name' => 'format', 'contents' => 'jpeg'], $options[RequestOptions::MULTIPART][1]);
        $this->assertSame([
          'name' => 'max_pages',
          'contents' => (string) AttachmentAnalysisSourcePreparer::PAGE_LIMIT,
        ], $options[RequestOptions::MULTIPART][2]);
        return new Response(200, [], json_encode([
          'pages' => [
            ['page' => 1, 'mediaType' => 'image/jpeg', 'data' => base64_encode($first)],
            ['page' => 2, 'mediaType' => 'image/jpeg', 'data' => base64_encode($second)],
          ],
          'pageCount' => 5,
          'truncated' => TRUE,
        ], JSON_THROW_ON_ERROR));
      });

    $result = (new DocumentGuardClient($client))->renderPages($source);

    $this->assertSame([
      'pages' => [
        ['page' => 1, 'mediaType' => 'image/jpeg', 'data' => $first],
        ['page' => 2, 'mediaType' => 'image/jpeg', 'data' => $second],
      ],
      'pageCount' => 5,
      'truncated' => TRUE,
    ], $result);
  }

  /**
   * A streamed body that pauses between packets is read to its end.
   */
  public function testRenderPagesWaitsForSlowStreamedBody(): void {
    $this->configure();
    $page = "\xFF\xD8\xFF\xE0page-one\xFF\xD9";
    $json = json_encode([
      'pages' => [['page' => 1, 'mediaType' => 'image/jpeg', 'data' => base64_encode($page)]],
      'pageCount' => 1,
      'truncated' => FALSE,
    ], JSON_THROW_ON_ERROR);
    $body = new class(Utils::streamFor($json)) implements StreamInterface {
      use StreamDecoratorTrait;

      /**
       * The decorated stream.
       */
      private StreamInterface $stream;

      /**
       * Number of reads so far.
       */
      private int $reads = 0;

      /**
       * Every other read finds no packet yet, like a socket mid-transfer.
       */
      public function read($length): string {
        return $this->reads++ % 2 === 0 ? '' : $this->stream->read(min($length, 16));
      }

    };
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], $body));

    $result = (new DocumentGuardClient($client))->renderPages('%PDF- original');

    $this->assertSame([['page' => 1, 'mediaType' => 'image/jpeg', 'data' => $page]], $result['pages']);
  }

  /**
   * A peer that trickles bytes past the request timeout is cut off.
   */
  public function testRenderPagesFailsClosedOnTricklingBodyPastTimeout(): void {
    $this->configure();
    $body = new class(Utils::streamFor(str_repeat('x', 1024))) implements StreamInterface {
      use StreamDecoratorTrait;

      /**
       * The decorated stream.
       */
      private StreamInterface $stream;

      /**
       * Every read returns one byte, so the empty-read branch never runs.
       */
      public function read($length): string {
        return $this->stream->read(1);
      }

    };
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], $body));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentMicroTime')->willReturnOnConsecutiveCalls(1000.0, 1001.0, 2000.0);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_envelope');

    (new DocumentGuardClient($client, $time))->renderPages('%PDF- original');
  }

  /**
   * Any inconsistency in the render envelope rejects the whole response.
   *
   * @param array<string, mixed> $envelope
   *   Tampered render envelope.
   */
  #[DataProvider('tamperedRenderEnvelopes')]
  public function testRenderPagesFailsClosedOnTamperedEnvelope(array $envelope): void {
    $this->configure();
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturn(new Response(200, [], json_encode($envelope, JSON_THROW_ON_ERROR)));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('document_guard.invalid_envelope');
    (new DocumentGuardClient($client))->renderPages('%PDF- original');
  }

  /**
   * Tampered render envelopes.
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   Cases.
   */
  public static function tamperedRenderEnvelopes(): array {
    $page = static fn (int $number, string $data = "\xFF\xD8\xFF\xE0ok\xFF\xD9", string $media_type = 'image/jpeg'): array => [
      'page' => $number,
      'mediaType' => $media_type,
      'data' => base64_encode($data),
    ];
    return [
      'empty' => [['pages' => [], 'pageCount' => 0, 'truncated' => FALSE]],
      'page gap' => [['pages' => [$page(2)], 'pageCount' => 2, 'truncated' => TRUE]],
      'truncated mismatch' => [['pages' => [$page(1)], 'pageCount' => 3, 'truncated' => FALSE]],
      'count below pages' => [['pages' => [$page(1), $page(2)], 'pageCount' => 1, 'truncated' => FALSE]],
      'bad signature' => [['pages' => [$page(1, 'GIF89a')], 'pageCount' => 1, 'truncated' => FALSE]],
      'media type mismatch' => [[
        'pages' => [$page(1, "\x89PNG\r\n\x1A\n", 'image/png')],
        'pageCount' => 1,
        'truncated' => FALSE,
      ],
      ],
      'invalid base64' => [[
        'pages' => [['page' => 1, 'mediaType' => 'image/jpeg', 'data' => '***']],
        'pageCount' => 1,
        'truncated' => FALSE,
      ],
      ],
      'extra page key' => [['pages' => [$page(1) + ['sha256' => 'x']], 'pageCount' => 1, 'truncated' => FALSE]],
      'extra envelope key' => [['pages' => [$page(1)], 'pageCount' => 1, 'truncated' => FALSE, 'debug' => 'x']],
      'too many pages' => [[
        'pages' => array_map($page, range(1, 9)),
        'pageCount' => 9,
        'truncated' => FALSE,
      ],
      ],
    ];
  }

  /**
   * Render rejections surface only allowlisted codes; outages fail closed.
   */
  public function testRenderPagesMapsRejectionsToStableCodes(): void {
    $this->configure();
    $responses = [
      new Response(422, [], '{"error":{"code":"document_guard.encrypted_pdf","message":"x"}}'),
      new Response(422, [], '{"error":{"code":"internal.leak","message":"x"}}'),
      new Response(413, [], '{"error":{"code":"document_guard.too_large"}}'),
      new Response(503, [], '{"error":{"code":"document_guard.unavailable"}}'),
      new Response(504, [], '{"error":{"code":"document_guard.render_timeout"}}'),
    ];
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnOnConsecutiveCalls(...$responses);
    $guard = new DocumentGuardClient($client);

    $codes = [];
    for ($attempt = 0; $attempt < count($responses); $attempt++) {
      try {
        $guard->renderPages('%PDF- original');
        $codes[] = 'no exception';
      }
      catch (\RuntimeException $exception) {
        $codes[] = $exception->getMessage();
      }
    }

    $this->assertSame([
      'document_guard.encrypted_pdf',
      'document_guard.render_failed',
      'document_guard.too_large',
      'document_guard.unavailable',
      'document_guard.unavailable',
    ], $codes);
  }

  /**
   * Non-PDF input and unknown formats never leave Drupal.
   */
  public function testRenderPagesRejectsInvalidInputBeforeRequest(): void {
    $this->configure();
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $guard = new DocumentGuardClient($client);

    foreach ([['GIF89a', 'jpeg'], ['%PDF- original', 'gif'], ['', 'png']] as [$document, $format]) {
      try {
        $guard->renderPages($document, $format);
        $this->fail('Invalid render input was accepted.');
      }
      catch (\RuntimeException $exception) {
        $this->assertSame('document_guard.source_invalid', $exception->getMessage());
      }
    }
  }

  /**
   * Codes come back exactly as sent, with the long timeout and the own path.
   */
  public function testReadCodesReturnsValidatedCodes(): void {
    $this->configure();
    $answer = [
      'pageCount' => 5,
      'tooManyPages' => FALSE,
      'codes' => [
      ['page' => 1, 'payload' => 'BA1-ABCDEFGHJKMNPQRST'],
      ['page' => 4, 'payload' => 'BA1-0123456789ABCDEFG'],
      ],
    ];
    $sent = [];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use (&$sent, $answer): Response {
        $sent = [
          $method,
          $url,
          $options[RequestOptions::TIMEOUT],
          $options[RequestOptions::MULTIPART][0]['headers']['Content-Type'],
        ];
        return new Response(200, [], json_encode($answer));
      });
    $result = (new DocumentGuardClient($client))->readCodes('%PDF- envelope', 'application/pdf');
    $this->assertSame($answer, $result);
    $this->assertSame(['POST', 'http://rag-service:8080/v1/document-guard/codes', 90.0, 'application/pdf'], $sent);
  }

  /**
   * Every deviation from the codes contract fails closed.
   */
  #[DataProvider('tamperedCodes')]
  public function testReadCodesFailsClosed(mixed $answer, string $media_type = 'application/pdf'): void {
    $this->expectExceptionMessage('document_guard.invalid_envelope');
    DocumentGuardClient::validatedCodes($answer, $media_type);
  }

  /**
   * Tampered codes answers.
   *
   * @return array<string, array<int, mixed>>
   *   Cases.
   */
  public static function tamperedCodes(): array {
    $code = static fn(int $page, string $payload): array => ['page' => $page, 'payload' => $payload];
    $valid = 'BA1-ABCDEFGHJKMNPQRST';
    return [
      'extra key' => [['pageCount' => 1, 'tooManyPages' => FALSE, 'codes' => [], 'name' => 'x']],
      'extra code key' => [['pageCount' => 1, 'tooManyPages' => FALSE, 'codes' => [$code(1, $valid) + ['text' => 'x']]]],
      'foreign payload' => [['pageCount' => 1, 'tooManyPages' => FALSE, 'codes' => [$code(1, 'https://example.org')]]],
      'short payload' => [['pageCount' => 1, 'tooManyPages' => FALSE, 'codes' => [$code(1, 'BA1-ABCDEFGHJKMNPQRS')]]],
      'page above count' => [['pageCount' => 2, 'tooManyPages' => FALSE, 'codes' => [$code(3, $valid)]]],
      'page zero' => [['pageCount' => 2, 'tooManyPages' => FALSE, 'codes' => [$code(0, $valid)]]],
      'flag wrong' => [['pageCount' => 31, 'tooManyPages' => FALSE, 'codes' => []]],
      'codes with too many pages' => [['pageCount' => 31, 'tooManyPages' => TRUE, 'codes' => [$code(1, $valid)]]],
      'unsorted' => [['pageCount' => 3, 'tooManyPages' => FALSE, 'codes' => [$code(2, $valid), $code(1, $valid)]]],
      'duplicate' => [['pageCount' => 3, 'tooManyPages' => FALSE, 'codes' => [$code(1, $valid), $code(1, $valid)]]],
      'image with two pages' => [['pageCount' => 2, 'tooManyPages' => FALSE, 'codes' => []], 'image/jpeg'],
      'no object' => ['codes'],
    ];
  }

  /**
   * Rejections map to allowlisted codes; 503 and 504 become unavailable.
   */
  public function testReadCodesMapsRejectionsAndRefusesBadInput(): void {
    $this->configure();
    $responses = [
      new Response(422, [], '{"error":{"code":"document_guard.encrypted_pdf"}}'),
      new Response(422, [], '{"error":{"code":"document_guard.invalid_image"}}'),
      new Response(422, [], '{"error":{"code":"internal.leak"}}'),
      new Response(503, [], '{"error":{"code":"document_guard.unavailable"}}'),
      new Response(504, [], '{"error":{"code":"document_guard.codes_timeout"}}'),
      new Response(200, ['Content-Length' => '70000'], '{}'),
    ];
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnOnConsecutiveCalls(...$responses);
    $guard = new DocumentGuardClient($client);
    $codes = [];
    for ($attempt = 0; $attempt < count($responses); $attempt++) {
      try {
        $guard->readCodes("\xFF\xD8\xFF photo", 'image/jpeg');
        $codes[] = 'no exception';
      }
      catch (\RuntimeException $exception) {
        $codes[] = $exception->getMessage();
      }
    }
    $this->assertSame([
      'document_guard.encrypted_pdf',
      'document_guard.invalid_image',
      'document_guard.invalid_envelope',
      'document_guard.unavailable',
      'document_guard.unavailable',
      'document_guard.invalid_envelope',
    ], $codes);
    $never = $this->createMock(ClientInterface::class);
    $never->expects($this->never())->method('request');
    foreach ([['%PDF- x', 'image/png'], ['GIF89a', 'image/gif'], ['', 'application/pdf']] as [$document, $type]) {
      try {
        (new DocumentGuardClient($never))->readCodes($document, $type);
        $this->fail('Invalid codes input was accepted.');
      }
      catch (\RuntimeException $exception) {
        $this->assertSame('document_guard.source_invalid', $exception->getMessage());
      }
    }
  }

  /**
   * Split parts are decoded and checked; an output limit retries per range.
   */
  public function testSplitPdfChecksPartsAndRetriesPerRange(): void {
    $this->configure();
    $part = static fn(int $from, int $to, string $data): array => [
      'from' => $from,
      'to' => $to,
      'pageCount' => $to - $from + 1,
      'sha256' => hash('sha256', $data),
      'data' => base64_encode($data),
    ];
    $responses = [
      new Response(413, [], '{"error":{"code":"document_guard.split_output_limit"}}'),
      new Response(200, [], json_encode(['pageCount' => 4, 'parts' => [$part(1, 2, '%PDF- one')]])),
      new Response(200, [], json_encode(['pageCount' => 4, 'parts' => [$part(3, 4, '%PDF- two')]])),
    ];
    $bodies = [];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->exactly(3))->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options) use (&$bodies, &$responses): Response {
        $bodies[] = $options[RequestOptions::MULTIPART][1]['contents'];
        return array_shift($responses);
      });
    $parts = (new DocumentGuardClient($client))->splitPdf('%PDF- envelope', [[1, 2], [3, 4]]);
    $this->assertSame(['[[1,2],[3,4]]', '[[1,2]]', '[[3,4]]'], $bodies);
    $this->assertSame(['%PDF- one', '%PDF- two'], array_column($parts, 'data'));
    $this->assertSame([2, 2], array_column($parts, 'pageCount'));
  }

  /**
   * A part without a PDF header, a wrong hash or a wrong range fails closed.
   */
  #[DataProvider('tamperedParts')]
  public function testSplitPdfFailsClosed(array $part): void {
    $this->expectExceptionMessage('document_guard.invalid_envelope');
    DocumentGuardClient::validatedSplit(['pageCount' => 3, 'parts' => [$part]], [[1, 2]]);
  }

  /**
   * Tampered split parts.
   *
   * @return array<string, array<int, array<string, mixed>>>
   *   Cases.
   */
  public static function tamperedParts(): array {
    $good = [
      'from' => 1,
      'to' => 2,
      'pageCount' => 2,
      'sha256' => hash('sha256', '%PDF- a'),
      'data' => base64_encode('%PDF- a'),
    ];
    return [
      'no pdf header' => [['sha256' => hash('sha256', 'GIF89a'), 'data' => base64_encode('GIF89a')] + $good],
      'hash mismatch' => [['sha256' => hash('sha256', '%PDF- b')] + $good],
      'wrong range' => [['to' => 3, 'pageCount' => 3] + $good],
      'wrong page count' => [['pageCount' => 1] + $good],
      'extra key' => [$good + ['name' => 'x.pdf']],
      'broken base64' => [['data' => '***'] + $good],
    ];
  }

  /**
   * Overlapping or out-of-range ranges never leave Drupal.
   */
  public function testSplitPdfRefusesInvalidRangesBeforeRequest(): void {
    $this->configure();
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    foreach ([[], [[1, 2], [2, 3]], [[0, 1]], [[2, 1]], [[1, 31]], array_fill(0, 31, [1, 1]), [['1', 2]]] as $ranges) {
      try {
        (new DocumentGuardClient($client))->splitPdf('%PDF- envelope', $ranges);
        $this->fail('Invalid ranges were accepted.');
      }
      catch (\RuntimeException $exception) {
        $this->assertSame('document_guard.source_invalid', $exception->getMessage());
      }
    }
  }

  /**
   * Configures deterministic non-secret test values.
   */
  private function configure(): void {
    putenv('BEANTRAGT_RAG_SERVICE_BASE_URL=http://rag-service:8080');
    putenv('BEANTRAGT_RAG_SERVICE_TENANT=kommune-a');
    putenv('BEANTRAGT_RAG_SERVICE_API_KEY=test-rag-service-key-with-at-least-thirty-two-bytes');
    putenv('BEANTRAGT_RAG_SERVICE_ALLOW_HTTP=true');
    putenv('BEANTRAGT_RAG_SERVICE_TIMEOUT_SECONDS=30');
  }

  /**
   * Builds a complete PII-free report fixture.
   *
   * @param string $status
   *   Guard status.
   * @param array<string, mixed> $derivative
   *   Derivative metadata.
   *
   * @return array<string, mixed>
   *   Report fixture.
   */
  private function report(string $status, array $derivative): array {
    return [
      'guardVersion' => '1',
      'status' => $status,
      'reasons' => [],
      'advisories' => [],
      'redactionDensity' => 0.1,
      'source' => [
        'mediaType' => 'application/pdf',
        'size' => 14,
        'pages' => 1,
      ],
      'extraction' => [
        'engine' => 'pdfminer.six',
        'textChars' => 10,
        'hasTextLayer' => TRUE,
      ],
      'derivative' => $derivative,
      'findings' => $status === 'redacted' ? [[
        'category' => 'email',
        'count' => 1,
        'pages' => [1],
      ],
      ] : [],
    ];
  }

}
