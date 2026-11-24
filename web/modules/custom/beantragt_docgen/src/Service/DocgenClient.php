<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Client for the stateless docgen service.
 *
 * Drupal is the only caller of docgen; Gotenberg and veraPDF sit behind it.
 * The client never retries on its own: a busy docgen (429 or 503) becomes
 * docgen.busy with the Retry-After seconds, and the staff UI asks the person
 * to try again. There is no persistent job list in Drupal in the MVP; that
 * comes with a later batch mode. Logs carry route, status, duration and the
 * docgen request id, never document content or render data.
 *
 * Configuration (first match wins):
 * - base URL: $settings['beantragt_docgen']['base_url'], env
 *   BEANTRAGT_DOCGEN_BASE_URL;
 * - token: $settings['beantragt_docgen']['token'], env BEANTRAGT_DOCGEN_TOKEN,
 *   the file named by BEANTRAGT_DOCGEN_TOKEN_FILE, the DDEV secret file
 *   /run/secrets/BEANTRAGT_DOCGEN_TOKEN (trimmed);
 * - timeout: env BEANTRAGT_DOCGEN_TIMEOUT_SECONDS, default 210.
 */
class DocgenClient {

  public const DEFAULT_TIMEOUT_SECONDS = 210;
  public const DEFAULT_RETRY_AFTER_SECONDS = 5;
  public const MAX_DOCX_BYTES = 5 * 1024 * 1024;
  public const MAX_PDF_BYTES = 20 * 1024 * 1024;
  private const SECRET_FILE = '/run/secrets/BEANTRAGT_DOCGEN_TOKEN';
  private const MAX_RESPONSE_BYTES = 32 * 1024 * 1024;

  /**
   * Constructs the client.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel beantragt_docgen.
   * @param array{baseUrl?: string, token?: string, timeout?: float}|null $override
   *   Explicit configuration for tests.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
    private readonly ?array $override = NULL,
  ) {}

  /**
   * Returns TRUE when base URL and token are present.
   */
  public function isConfigured(): bool {
    $configuration = $this->configuration();
    return $configuration['baseUrl'] !== '' && $configuration['token'] !== '';
  }

  /**
   * Reads docgen health (no token needed, still sent when available).
   *
   * @return array{status: string, gotenberg: string, verapdf: string}
   *   Health of docgen and its two upstreams.
   */
  public function health(): array {
    $configuration = $this->configuration();
    if ($configuration['baseUrl'] === '') {
      throw new DocgenApiException('docgen.not_configured', 503);
    }
    try {
      $response = $this->httpClient->request('GET', $configuration['baseUrl'] . '/health', [
        RequestOptions::TIMEOUT => 10,
        RequestOptions::CONNECT_TIMEOUT => 5,
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::ALLOW_REDIRECTS => FALSE,
        RequestOptions::HEADERS => ['Accept' => 'application/json'],
      ]);
    }
    catch (GuzzleException) {
      throw new DocgenApiException('docgen.unavailable', 503);
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    $read = static fn (string $key): string => is_array($body) && is_string($body[$key] ?? NULL) ? $body[$key] : 'unknown';
    return [
      'status' => $read('status'),
      'gotenberg' => $read('gotenberg'),
      'verapdf' => $read('verapdf'),
    ];
  }

  /**
   * Lists the placeholders, loops and template errors of a template.
   *
   * @return array{placeholders: string[], loops: string[], errors: array<int, array{id: string, message: string, tag: ?string}>}
   *   Inspection result.
   */
  public function inspect(string $template): array {
    $body = $this->post('/inspect', ['template' => base64_encode($template)]);
    $strings = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, static fn (mixed $item): bool => is_string($item) && $item !== '' && strlen($item) <= 512)) : [];
    return [
      'placeholders' => $strings($body['placeholders'] ?? NULL),
      'loops' => $strings($body['loops'] ?? NULL),
      'errors' => self::templateErrors($body['errors'] ?? NULL),
    ];
  }

  /**
   * Renders a template with flat render data.
   *
   * @param string $template
   *   Template bytes.
   * @param array<string, mixed> $data
   *   Render data (dotted keys, see RenderDataBuilder).
   *
   * @return array{docx: string, sha256: string, missing: string[]}
   *   Rendered DOCX bytes, their SHA-256 and the missing placeholders.
   */
  public function render(string $template, array $data): array {
    $body = $this->post('/render', ['template' => base64_encode($template), 'data' => (object) $data]);
    $docx = $this->decodeDocument($body['docx'] ?? NULL, self::MAX_DOCX_BYTES);
    $sha256 = hash('sha256', $docx);
    if (!is_string($body['sha256'] ?? NULL) || !hash_equals($sha256, strtolower($body['sha256']))) {
      throw new DocgenApiException('docgen.integrity_failed', 502);
    }
    $missing = is_array($body['missing'] ?? NULL) ? array_values(array_filter($body['missing'], 'is_string')) : [];
    return ['docx' => $docx, 'sha256' => $sha256, 'missing' => $missing];
  }

  /**
   * Converts a DOCX into PDF/A-2b and validates it with veraPDF.
   *
   * @return array{pdf: string, sha256: string, docxSha256: string, validation: array{profile: string, compliant: bool, failedRules: int, failedChecks: int, summary: string}}
   *   PDF bytes, hashes and the validation result.
   */
  public function finalize(string $docx): array {
    $body = $this->post('/finalize', ['docx' => base64_encode($docx)]);
    $pdf = $this->decodeDocument($body['pdf'] ?? NULL, self::MAX_PDF_BYTES);
    $sha256 = hash('sha256', $pdf);
    $docx_sha256 = hash('sha256', $docx);
    if (!is_string($body['sha256'] ?? NULL) || !hash_equals($sha256, strtolower($body['sha256']))
      || !is_string($body['docxSha256'] ?? NULL) || !hash_equals($docx_sha256, strtolower($body['docxSha256']))
      || !str_starts_with($pdf, '%PDF-')) {
      throw new DocgenApiException('docgen.integrity_failed', 502);
    }
    $validation = is_array($body['validation'] ?? NULL) ? $body['validation'] : [];
    $removed = (int) ($body['sanitized']['removedExternalRelationships'] ?? 0);
    if ($removed > 0) {
      // A draft rendered from a sanitized template has no external
      // relationships; a positive count means a changed or replaced draft.
      $this->logger->notice('docgen removed @count external relationships from a draft on finalize.', ['@count' => $removed]);
    }
    return [
      'pdf' => $pdf,
      'sha256' => $sha256,
      'docxSha256' => $docx_sha256,
      'validation' => [
        'profile' => is_string($validation['profile'] ?? NULL) ? $validation['profile'] : '',
        'compliant' => ($validation['compliant'] ?? FALSE) === TRUE && ($validation['profile'] ?? '') === '2b',
        'failedRules' => (int) ($validation['failedRules'] ?? 0),
        'failedChecks' => (int) ($validation['failedChecks'] ?? 0),
        'summary' => mb_substr(is_string($validation['summary'] ?? NULL) ? $validation['summary'] : '', 0, 2000),
      ],
    ];
  }

  /**
   * Sends one authenticated JSON request and maps every failure to a code.
   *
   * @return array<string, mixed>
   *   Decoded JSON object.
   */
  private function post(string $route, array $payload): array {
    $configuration = $this->configuration();
    if ($configuration['baseUrl'] === '' || $configuration['token'] === '') {
      throw new DocgenApiException('docgen.not_configured', 503);
    }
    $started = microtime(TRUE);
    try {
      $response = $this->httpClient->request('POST', $configuration['baseUrl'] . $route, [
        RequestOptions::TIMEOUT => $configuration['timeout'],
        RequestOptions::CONNECT_TIMEOUT => 10,
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::ALLOW_REDIRECTS => FALSE,
        RequestOptions::HEADERS => [
          'Authorization' => 'Bearer ' . $configuration['token'],
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ],
        RequestOptions::BODY => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]);
    }
    catch (GuzzleException $exception) {
      $this->log($route, 0, $started, NULL);
      // A timeout while docgen queues conversions is load, not an outage.
      $timeout = str_contains(strtolower($exception->getMessage()), 'timed out');
      throw new DocgenApiException($timeout ? 'docgen.busy' : 'docgen.unavailable', 503, [], $timeout ? self::DEFAULT_RETRY_AFTER_SECONDS : NULL);
    }
    $status = $response->getStatusCode();
    $this->log($route, $status, $started, $response);
    $raw = (string) $response->getBody();
    if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
      throw new DocgenApiException('docgen.integrity_failed', 502);
    }
    $body = json_decode($raw, TRUE);
    $body = is_array($body) ? $body : [];
    if ($status === 200) {
      return $body;
    }
    throw $this->failure($status, $body, $response);
  }

  /**
   * Maps a docgen error answer to a staff API error.
   */
  private function failure(int $status, array $body, ResponseInterface $response): DocgenApiException {
    $code = is_string($body['error'] ?? NULL) ? $body['error'] : '';
    if ($status === 429 || $status === 503) {
      $header = trim($response->getHeaderLine('Retry-After'));
      $retry = ctype_digit($header) ? (int) $header : self::DEFAULT_RETRY_AFTER_SECONDS;
      return new DocgenApiException('docgen.busy', 503, [], max(1, min(300, $retry)));
    }
    if ($status === 413) {
      return new DocgenApiException('docgen.too_large', 413, ['reason' => self::safeCode($code)]);
    }
    if ($status === 422 && $code === 'template_invalid') {
      return new DocgenApiException('docgen.template_invalid', 422, ['errors' => self::templateErrors($body['details']['errors'] ?? NULL)]);
    }
    if ($status === 422) {
      return new DocgenApiException('docgen.docx_rejected', 422, ['reason' => self::safeCode($code)]);
    }
    if ($status === 502) {
      return new DocgenApiException('docgen.conversion_failed', 502, ['reason' => self::safeCode($code)]);
    }
    if ($status === 401) {
      $this->logger->error('docgen rejected the token of this Drupal (401). Check BEANTRAGT_DOCGEN_TOKEN and the docgen token table.');
    }
    return new DocgenApiException('docgen.unavailable', 502, ['reason' => self::safeCode($code)]);
  }

  /**
   * Decodes a strict base64 document within a byte limit.
   */
  private function decodeDocument(mixed $value, int $max_bytes): string {
    if (!is_string($value) || $value === '' || strlen($value) > (int) ceil($max_bytes / 3) * 4 + 4) {
      throw new DocgenApiException('docgen.integrity_failed', 502);
    }
    $bytes = base64_decode($value, TRUE);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $max_bytes) {
      throw new DocgenApiException('docgen.integrity_failed', 502);
    }
    return $bytes;
  }

  /**
   * Normalizes template errors (template text only, never render data).
   *
   * @return array<int, array{id: string, message: string, tag: ?string}>
   *   At most 50 errors.
   */
  public static function templateErrors(mixed $errors): array {
    $out = [];
    foreach (is_array($errors) ? array_slice($errors, 0, 50) : [] as $error) {
      if (!is_array($error)) {
        continue;
      }
      $out[] = [
        'id' => self::safeCode(is_string($error['id'] ?? NULL) ? $error['id'] : 'template_error'),
        'message' => mb_substr(is_string($error['message'] ?? NULL) ? $error['message'] : '', 0, 300),
        'tag' => is_string($error['tag'] ?? NULL) ? mb_substr($error['tag'], 0, 200) : NULL,
      ];
    }
    return $out;
  }

  /**
   * Keeps only machine codes from upstream answers.
   */
  private static function safeCode(string $code): string {
    return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) ? $code : 'unknown';
  }

  /**
   * Logs one call without content.
   */
  private function log(string $route, int $status, float $started, ?ResponseInterface $response): void {
    $request_id = $response?->getHeaderLine('X-Request-Id') ?? '';
    $context = [
      '@route' => $route,
      '@status' => $status === 0 ? 'no response' : (string) $status,
      '@ms' => (string) (int) round((microtime(TRUE) - $started) * 1000),
      '@id' => preg_match('/^[A-Za-z0-9-]{1,64}$/', $request_id) ? $request_id : '-',
    ];
    if ($status === 200) {
      $this->logger->info('docgen @route @status in @ms ms (request @id).', $context);
      return;
    }
    $this->logger->warning('docgen @route @status in @ms ms (request @id).', $context);
  }

  /**
   * Resolves base URL, token and timeout.
   *
   * @return array{baseUrl: string, token: string, timeout: float}
   *   Configuration; empty strings when absent.
   */
  public function configuration(): array {
    if ($this->override !== NULL) {
      return [
        'baseUrl' => rtrim((string) ($this->override['baseUrl'] ?? ''), '/'),
        'token' => trim((string) ($this->override['token'] ?? '')),
        'timeout' => (float) ($this->override['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS),
      ];
    }
    $settings = Settings::get('beantragt_docgen', []);
    $settings = is_array($settings) ? $settings : [];
    $base_url = (string) ($settings['base_url'] ?? (getenv('BEANTRAGT_DOCGEN_BASE_URL') ?: ''));
    $base_url = rtrim(trim($base_url), '/');
    if ($base_url !== '' && !preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#', $base_url)) {
      $this->logger->error('BEANTRAGT_DOCGEN_BASE_URL must be scheme, host and optional port.');
      $base_url = '';
    }
    $token = (string) ($settings['token'] ?? (getenv('BEANTRAGT_DOCGEN_TOKEN') ?: ''));
    if (trim($token) === '') {
      $file = (string) (getenv('BEANTRAGT_DOCGEN_TOKEN_FILE') ?: self::SECRET_FILE);
      $token = is_file($file) && is_readable($file) ? (string) file_get_contents($file, FALSE, NULL, 0, 4096) : '';
    }
    $timeout = (int) (getenv('BEANTRAGT_DOCGEN_TIMEOUT_SECONDS') ?: self::DEFAULT_TIMEOUT_SECONDS);
    return [
      'baseUrl' => $base_url,
      'token' => trim($token),
      'timeout' => (float) max(5, min(600, $timeout)),
    ];
  }

}
