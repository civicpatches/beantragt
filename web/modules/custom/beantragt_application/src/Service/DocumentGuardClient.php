<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Strict authenticated client for the local document guard contract.
 */
final class DocumentGuardClient implements DocumentGuardClientInterface {

  private const DEFAULT_DDEV_BASE_URL = 'http://rag-service:8080';
  private const LOCAL_HTTP_HOSTS = ['localhost', 'localhost.localdomain', 'rag-service'];
  private const MAX_DOCUMENT_BYTES = 10485760;
  private const MIN_GUARD_TIMEOUT_SECONDS = 50.0;
  private const MAX_RESPONSE_BYTES = 15728640;
  private const ENTITY_PROFILES = [
    self::ENTITY_PROFILE_FULL,
    self::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
  ];
  private const RENDER_FORMATS = [
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
  ];
  private const RENDER_SIGNATURES = [
    'image/jpeg' => "\xFF\xD8\xFF",
    'image/png' => "\x89PNG\r\n\x1A\n",
  ];
  private const MAX_RENDER_PAGES = 8;
  private const MAX_RENDER_PAGE_BYTES = 4194304;
  private const MAX_RENDER_TOTAL_BYTES = 8388608;
  private const MAX_ERROR_BODY_BYTES = 4096;
  private const EMPTY_READ_PAUSE_MICROSECONDS = 5000;
  private const RENDER_ERROR_CODES = [
    'document_guard.encrypted_pdf',
    'document_guard.invalid_format',
    'document_guard.invalid_pdf',
    'document_guard.render_failed',
    'document_guard.render_output_limit',
    'document_guard.too_large',
  ];
  private const CODES_MEDIA_TYPES = [
    'application/pdf' => '%PDF-',
    'image/png' => "\x89PNG\r\n\x1A\n",
    'image/jpeg' => "\xFF\xD8\xFF",
  ];
  private const CODES_MAX_PAGES = 30;
  private const CODES_MAX_RESPONSE_BYTES = 65536;
  private const CODES_MAX_ENTRIES = 120;
  private const CODES_MAX_PER_PAGE = 4;
  private const CODE_PAYLOAD_PATTERN = '/^BA1-[0-9A-HJKMNP-TV-Z]{17}$/D';
  private const SPLIT_MAX_RANGES = 30;
  private const SPLIT_MAX_RESPONSE_BYTES = 25165824;
  private const SPLIT_MAX_PART_BYTES = 10485760;
  private const SPLIT_MAX_TOTAL_BYTES = 16777216;
  private const MIN_CODES_TIMEOUT_SECONDS = 90.0;
  private const CODES_ERROR_CODES = [
    'document_guard.encrypted_pdf',
    'document_guard.invalid_format',
    'document_guard.invalid_image',
    'document_guard.invalid_pdf',
    'document_guard.too_large',
  ];
  private const SPLIT_ERROR_CODES = [
    'document_guard.encrypted_pdf',
    'document_guard.invalid_pdf',
    'document_guard.invalid_ranges',
    'document_guard.split_output_limit',
    'document_guard.too_large',
    'document_guard.too_many_pages',
  ];
  private const VALID_STATUSES = ['clean', 'redacted', 'needs_review', 'rejected'];
  private const VALID_REASONS = [
    'annotations_present',
    'derivative_too_large',
    'embedded_files_present',
    'embedded_image_without_ocr',
    'empty_text_extraction',
    'encrypted_pdf',
    'guard_analyzer_unavailable',
    'guard_redactor_unavailable',
    'image_without_ocr',
    'insufficient_text_extraction',
    'interactive_form_present',
    'invalid_image',
    'invalid_pdf',
    'links_present',
    'redaction_density_exceeded',
    'redaction_failed',
    'redaction_location_failed',
    'redaction_timeout',
    'redaction_verification_failed',
    'source_page_limit',
    'source_too_large',
    'source_unreadable',
    'unsupported_media_type',
    'vector_graphics_without_ocr',
  ];
  private const VALID_ADVISORIES = [
    'annotations_present',
    'embedded_image_without_ocr',
    'interactive_form_present',
    'links_present',
    'vector_graphics_without_ocr',
  ];
  private const VALID_FINDING_CATEGORIES = [
    'bic',
    'commercial_register',
    'credit_card',
    'date_of_birth',
    'date_time',
    'driver_license',
    'email',
    'health_insurance',
    'iban',
    'id_card',
    'ip_address',
    'location',
    'medical_license',
    'person_name',
    'phone',
    'postal_address',
    'postal_code',
    'social_security',
    'tax_id',
    'tax_number',
    'vat_id',
    'vehicle_registration',
  ];

  /**
   * Validated operator configuration.
   *
   * @var array{baseUrl: string, tenant: string, apiKey: string, timeout: float, disableProxy: bool}|null
   */
  private ?array $configuration = NULL;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ?TimeInterface $time = NULL,
  ) {}

  /**
   * Returns the current time in seconds with microseconds.
   */
  private function now(): float {
    return $this->time?->getCurrentMicroTime() ?? microtime(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function createDerivative(string $document, string $media_type, string $entity_profile = self::ENTITY_PROFILE_FULL): array {
    if ($document === '' || strlen($document) > self::MAX_DOCUMENT_BYTES) {
      throw new \RuntimeException('document_guard.source_invalid');
    }
    if (!in_array($media_type, ['application/octet-stream', 'application/pdf', 'image/jpeg', 'image/png'], TRUE)) {
      throw new \RuntimeException('document_guard.source_invalid');
    }
    if (!in_array($entity_profile, self::ENTITY_PROFILES, TRUE)) {
      throw new \RuntimeException('document_guard.invalid_entity_profile');
    }

    $response = $this->post('/v1/document-guard/derivatives', [
      [
        'name' => 'document',
        'contents' => $document,
        'filename' => 'document.bin',
        'headers' => ['Content-Type' => $media_type],
      ],
      [
        'name' => 'entityProfile',
        'contents' => $entity_profile,
      ],
    ]);

    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new \RuntimeException('document_guard.unavailable');
    }
    $body = $this->boundedResponseBody($response);

    try {
      $envelope = json_decode($body, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('document_guard.invalid_envelope', 0, $exception);
    }
    if (!is_array($envelope)
      || !$this->hasExactKeys($envelope, ['derivativeBase64', 'report'])
      || !is_array($envelope['report'] ?? NULL)
      || !array_key_exists('derivativeBase64', $envelope)) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $report = $envelope['report'];
    $status = $this->validateReport($report);
    if ($report['source']['mediaType'] !== $media_type
      || $report['source']['size'] !== strlen($document)) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $derivative_base64 = $envelope['derivativeBase64'];
    $derivative_metadata = $report['derivative'];
    $has_derivative = in_array($status, ['clean', 'redacted'], TRUE);
    if ($has_derivative !== (is_string($derivative_base64) && is_array($derivative_metadata))) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }
    if (!$has_derivative && ($derivative_base64 !== NULL || $derivative_metadata !== NULL)) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $derivative = NULL;
    $derivative_media_type = NULL;
    if ($has_derivative) {
      $derivative = base64_decode($derivative_base64, TRUE);
      $derivative_media_type = $derivative_metadata['mediaType'] ?? NULL;
      $expected_size = $derivative_metadata['size'] ?? NULL;
      $expected_sha256 = $derivative_metadata['sha256'] ?? NULL;
      if (!is_string($derivative)
        || $derivative === ''
        || strlen($derivative) > self::MAX_DOCUMENT_BYTES
        || !str_starts_with($derivative, '%PDF-')
        || $derivative_media_type !== 'application/pdf'
        || !is_int($expected_size)
        || $expected_size !== strlen($derivative)
        || !is_string($expected_sha256)
        || preg_match('/^[a-f0-9]{64}$/', $expected_sha256) !== 1
        || !hash_equals($expected_sha256, hash('sha256', $derivative))) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
    }

    try {
      $report_json = json_encode(
        $report,
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('document_guard.invalid_envelope', 0, $exception);
    }

    return [
      'status' => $status,
      'reportJson' => $report_json,
      'derivative' => $derivative,
      'mediaType' => $derivative_media_type,
      'entityProfile' => $entity_profile,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderPages(string $document, string $format = 'jpeg', int $max_pages = AttachmentAnalysisSourcePreparer::PAGE_LIMIT): array {
    $media_type = self::RENDER_FORMATS[$format] ?? NULL;
    if ($max_pages < 1 || $max_pages > self::MAX_RENDER_PAGES
      || $media_type === NULL
      || $document === ''
      || strlen($document) > self::MAX_DOCUMENT_BYTES
      || !str_starts_with($document, '%PDF-')) {
      throw new \RuntimeException('document_guard.source_invalid');
    }

    $response = $this->post('/v1/document-guard/pages', [
      [
        'name' => 'document',
        'contents' => $document,
        'filename' => 'document.pdf',
        'headers' => ['Content-Type' => 'application/pdf'],
      ],
      [
        'name' => 'format',
        'contents' => $format,
      ],
      [
        'name' => 'max_pages',
        'contents' => (string) $max_pages,
      ],
    ]);

    $status = $response->getStatusCode();
    if ($status === 413 || $status === 422) {
      throw new \RuntimeException($this->renderErrorCode($response));
    }
    if ($status < 200 || $status >= 300) {
      throw new \RuntimeException('document_guard.unavailable');
    }
    $body = $this->boundedResponseBody($response);
    try {
      $envelope = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('document_guard.invalid_envelope', 0, $exception);
    }
    if (!is_array($envelope)
      || !$this->hasExactKeys($envelope, ['pageCount', 'pages', 'truncated'])
      || !is_array($envelope['pages'])
      || !array_is_list($envelope['pages'])
      || $envelope['pages'] === []
      || count($envelope['pages']) > $max_pages
      || !is_int($envelope['pageCount'])
      || $envelope['pageCount'] < count($envelope['pages'])
      || !is_bool($envelope['truncated'])
      || $envelope['truncated'] !== ($envelope['pageCount'] > count($envelope['pages']))) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $pages = [];
    $total_bytes = 0;
    foreach ($envelope['pages'] as $index => $page) {
      if (!is_array($page)
        || !$this->hasExactKeys($page, ['data', 'mediaType', 'page'])
        || $page['page'] !== $index + 1
        || $page['mediaType'] !== $media_type
        || !is_string($page['data'])) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $data = base64_decode($page['data'], TRUE);
      if (!is_string($data)
        || $data === ''
        || strlen($data) > self::MAX_RENDER_PAGE_BYTES
        || !str_starts_with($data, self::RENDER_SIGNATURES[$media_type])) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $total_bytes += strlen($data);
      if ($total_bytes > self::MAX_RENDER_TOTAL_BYTES) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $pages[] = [
        'page' => $index + 1,
        'mediaType' => $media_type,
        'data' => $data,
      ];
    }

    return [
      'pages' => $pages,
      'pageCount' => $envelope['pageCount'],
      'truncated' => $envelope['truncated'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function readCodes(string $document, string $media_type): array {
    $signature = self::CODES_MEDIA_TYPES[$media_type] ?? NULL;
    if ($signature === NULL || $document === '' || strlen($document) > self::MAX_DOCUMENT_BYTES
      || !str_starts_with($document, $signature)) {
      throw new \RuntimeException('document_guard.source_invalid');
    }
    $timeout = max($this->configuration()['timeout'], self::MIN_CODES_TIMEOUT_SECONDS);
    $response = $this->post('/v1/document-guard/codes', [
      [
        'name' => 'document',
        'contents' => $document,
        'filename' => 'document',
        'headers' => ['Content-Type' => $media_type],
      ],
    ], $timeout);
    $status = $response->getStatusCode();
    if ($status === 413 || $status === 422) {
      throw new \RuntimeException($this->errorCode($response, self::CODES_ERROR_CODES, 'document_guard.invalid_envelope'));
    }
    if ($status < 200 || $status >= 300) {
      // 503 and 504 (busy, crash, timeout) are retryable for the caller.
      throw new \RuntimeException('document_guard.unavailable');
    }
    $body = $this->boundedResponseBody($response, self::CODES_MAX_RESPONSE_BYTES, $timeout);
    try {
      $envelope = json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('document_guard.invalid_envelope', 0, $exception);
    }
    return self::validatedCodes($envelope, $media_type);
  }

  /**
   * Checks a codes answer fail-closed; exposed for the unit test only.
   *
   * @return array{pageCount: int, tooManyPages: bool, codes: list<array{page: int, payload: string}>}
   *   The validated answer.
   */
  public static function validatedCodes(mixed $envelope, string $media_type): array {
    if (!is_array($envelope)
      || array_is_list($envelope) && $envelope !== []
      || !self::exactKeys($envelope, ['codes', 'pageCount', 'tooManyPages'])
      || !is_int($envelope['pageCount']) || $envelope['pageCount'] < 1
      || !is_bool($envelope['tooManyPages'])
      || $envelope['tooManyPages'] !== ($envelope['pageCount'] > self::CODES_MAX_PAGES)
      || ($media_type !== 'application/pdf' && $envelope['pageCount'] !== 1)
      || !is_array($envelope['codes']) || !array_is_list($envelope['codes'])
      || count($envelope['codes']) > self::CODES_MAX_ENTRIES
      || ($envelope['tooManyPages'] && $envelope['codes'] !== [])) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }
    $last = min($envelope['pageCount'], self::CODES_MAX_PAGES);
    $codes = [];
    $perPage = [];
    $seen = [];
    $previous = NULL;
    foreach ($envelope['codes'] as $code) {
      if (!is_array($code) || !self::exactKeys($code, ['page', 'payload'])
        || !is_int($code['page']) || $code['page'] < 1 || $code['page'] > $last
        || !is_string($code['payload']) || preg_match(self::CODE_PAYLOAD_PATTERN, $code['payload']) !== 1) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $key = $code['page'] . '|' . $code['payload'];
      $order = [$code['page'], $code['payload']];
      if (isset($seen[$key]) || ($previous !== NULL && $order < $previous)
        || ($perPage[$code['page']] = ($perPage[$code['page']] ?? 0) + 1) > self::CODES_MAX_PER_PAGE) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $seen[$key] = TRUE;
      $previous = $order;
      $codes[] = ['page' => $code['page'], 'payload' => $code['payload']];
    }
    return [
      'pageCount' => $envelope['pageCount'],
      'tooManyPages' => $envelope['tooManyPages'],
      'codes' => $codes,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function splitPdf(string $document, array $ranges): array {
    if ($document === '' || strlen($document) > self::MAX_DOCUMENT_BYTES || !str_starts_with($document, '%PDF-')
      || !self::validRanges($ranges)) {
      throw new \RuntimeException('document_guard.source_invalid');
    }
    try {
      return $this->splitOnce($document, $ranges);
    }
    catch (\RuntimeException $exception) {
      if ($exception->getMessage() !== 'document_guard.split_output_limit' || count($ranges) < 2) {
        throw $exception;
      }
    }
    // One part was too large for one answer: one request per range, once.
    $parts = [];
    foreach ($ranges as $range) {
      $parts[] = $this->splitOnce($document, [$range])[0];
    }
    return $parts;
  }

  /**
   * One split request and its fail-closed validation.
   *
   * @param string $document
   *   PDF bytes.
   * @param list<array{0: int, 1: int}> $ranges
   *   Checked ranges.
   *
   * @return list<array{from: int, to: int, pageCount: int, sha256: string, data: string}>
   *   Decoded parts.
   */
  private function splitOnce(string $document, array $ranges): array {
    $timeout = max($this->configuration()['timeout'], self::MIN_CODES_TIMEOUT_SECONDS);
    $response = $this->post('/v1/document-guard/split', [
      [
        'name' => 'document',
        'contents' => $document,
        'filename' => 'document.pdf',
        'headers' => ['Content-Type' => 'application/pdf'],
      ],
      [
        'name' => 'ranges',
        'contents' => json_encode(array_map(static fn(array $range): array => [(int) $range[0], (int) $range[1]], $ranges), JSON_THROW_ON_ERROR),
      ],
    ], $timeout);
    $status = $response->getStatusCode();
    if ($status === 413 || $status === 422) {
      throw new \RuntimeException($this->errorCode($response, self::SPLIT_ERROR_CODES, 'document_guard.invalid_envelope'));
    }
    if ($status < 200 || $status >= 300) {
      throw new \RuntimeException('document_guard.unavailable');
    }
    $body = $this->boundedResponseBody($response, self::SPLIT_MAX_RESPONSE_BYTES, $timeout);
    try {
      $envelope = json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('document_guard.invalid_envelope', 0, $exception);
    }
    return self::validatedSplit($envelope, $ranges);
  }

  /**
   * Checks a split answer fail-closed; exposed for the unit test only.
   *
   * @param mixed $envelope
   *   Decoded answer.
   * @param list<array{0: int, 1: int}> $ranges
   *   The ranges sent.
   *
   * @return list<array{from: int, to: int, pageCount: int, sha256: string, data: string}>
   *   Decoded parts in request order.
   */
  public static function validatedSplit(mixed $envelope, array $ranges): array {
    if (!is_array($envelope) || !self::exactKeys($envelope, ['pageCount', 'parts'])
      || !is_int($envelope['pageCount']) || $envelope['pageCount'] < 1 || $envelope['pageCount'] > self::CODES_MAX_PAGES
      || !is_array($envelope['parts']) || !array_is_list($envelope['parts'])
      || count($envelope['parts']) !== count($ranges)) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }
    $parts = [];
    $total = 0;
    foreach ($envelope['parts'] as $index => $part) {
      [$from, $to] = [(int) $ranges[$index][0], (int) $ranges[$index][1]];
      if (!is_array($part) || !self::exactKeys($part, ['data', 'from', 'pageCount', 'sha256', 'to'])
        || $part['from'] !== $from || $part['to'] !== $to || $to > $envelope['pageCount']
        || $part['pageCount'] !== $to - $from + 1
        || !is_string($part['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $part['sha256']) !== 1
        || !is_string($part['data'])) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $data = base64_decode($part['data'], TRUE);
      if (!is_string($data) || $data === '' || strlen($data) > self::SPLIT_MAX_PART_BYTES
        || !str_starts_with($data, '%PDF-') || !hash_equals($part['sha256'], hash('sha256', $data))) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $total += strlen($data);
      if ($total > self::SPLIT_MAX_TOTAL_BYTES) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $parts[] = [
        'from' => $from,
        'to' => $to,
        'pageCount' => $part['pageCount'],
        'sha256' => $part['sha256'],
        'data' => $data,
      ];
    }
    return $parts;
  }

  /**
   * Checks 1 to 30 integer ranges within 1..30, not overlapping.
   *
   * @param array $ranges
   *   Candidate ranges.
   */
  private static function validRanges(array $ranges): bool {
    if ($ranges === [] || !array_is_list($ranges) || count($ranges) > self::SPLIT_MAX_RANGES) {
      return FALSE;
    }
    $pages = [];
    foreach ($ranges as $range) {
      if (!is_array($range) || !array_is_list($range) || count($range) !== 2
        || !is_int($range[0]) || !is_int($range[1]) || $range[0] < 1 || $range[0] > $range[1] || $range[1] > self::CODES_MAX_PAGES) {
        return FALSE;
      }
      foreach (range($range[0], $range[1]) as $page) {
        if (isset($pages[$page])) {
          return FALSE;
        }
        $pages[$page] = TRUE;
      }
    }
    return TRUE;
  }

  /**
   * Exact key set check for static validators.
   *
   * @param array $value
   *   The object.
   * @param list<string> $expected
   *   Sorted keys.
   */
  private static function exactKeys(array $value, array $expected): bool {
    $keys = array_keys($value);
    sort($keys);
    return $keys === $expected;
  }

  /**
   * Maps a 4xx response of the codes or split route to an allowlisted code.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   * @param list<string> $allowed
   *   Codes passed through.
   * @param string $fallback
   *   Code for anything else.
   */
  private function errorCode(ResponseInterface $response, array $allowed, string $fallback): string {
    $stream = $response->getBody();
    $body = '';
    while (!$stream->eof() && strlen($body) <= self::MAX_ERROR_BODY_BYTES) {
      $chunk = $stream->read(self::MAX_ERROR_BODY_BYTES + 1 - strlen($body));
      if ($chunk === '') {
        break;
      }
      $body .= $chunk;
    }
    try {
      $decoded = strlen($body) > self::MAX_ERROR_BODY_BYTES ? NULL : json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $fallback;
    }
    $code = is_array($decoded) && is_array($decoded['error'] ?? NULL) ? ($decoded['error']['code'] ?? NULL) : NULL;
    return is_string($code) && in_array($code, $allowed, TRUE) ? $code : $fallback;
  }

  /**
   * Sends one authenticated multipart request to the local guard service.
   *
   * @param string $path
   *   Service path below the base URL.
   * @param list<array<string, mixed>> $multipart
   *   Guzzle multipart parts.
   * @param float|null $timeout
   *   Request timeout, default the configured one.
   */
  private function post(string $path, array $multipart, ?float $timeout = NULL): ResponseInterface {
    $configuration = $this->configuration();
    if ($timeout !== NULL) {
      $configuration['timeout'] = $timeout;
    }
    $options = [
      RequestOptions::ALLOW_REDIRECTS => FALSE,
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::HEADERS => [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $configuration['apiKey'],
        'X-Beantragt-Tenant' => $configuration['tenant'],
      ],
      RequestOptions::MULTIPART => $multipart,
      RequestOptions::STREAM => TRUE,
      RequestOptions::TIMEOUT => $configuration['timeout'],
      RequestOptions::CONNECT_TIMEOUT => min(10.0, $configuration['timeout']),
    ];
    if ($configuration['disableProxy']) {
      $options[RequestOptions::PROXY] = '';
    }

    try {
      return $this->httpClient->request('POST', $configuration['baseUrl'] . $path, $options);
    }
    catch (GuzzleException $exception) {
      throw new \RuntimeException('document_guard.unavailable', 0, $exception);
    }
  }

  /**
   * Maps a 4xx render response to an allowlisted stable code.
   */
  private function renderErrorCode(ResponseInterface $response): string {
    $stream = $response->getBody();
    $body = '';
    while (!$stream->eof() && strlen($body) <= self::MAX_ERROR_BODY_BYTES) {
      $chunk = $stream->read(self::MAX_ERROR_BODY_BYTES + 1 - strlen($body));
      if ($chunk === '') {
        break;
      }
      $body .= $chunk;
    }
    if (strlen($body) > self::MAX_ERROR_BODY_BYTES) {
      return 'document_guard.render_failed';
    }
    try {
      $decoded = json_decode($body, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return 'document_guard.render_failed';
    }
    $code = is_array($decoded) && is_array($decoded['error'] ?? NULL)
      ? ($decoded['error']['code'] ?? NULL)
      : NULL;
    return is_string($code) && in_array($code, self::RENDER_ERROR_CODES, TRUE)
      ? $code
      : 'document_guard.render_failed';
  }

  /**
   * Reads a guard response without allowing unbounded memory allocation.
   */
  private function boundedResponseBody(ResponseInterface $response, int $maximum = self::MAX_RESPONSE_BYTES, ?float $timeout = NULL): string {
    $content_length = $response->getHeaderLine('Content-Length');
    if ($content_length !== ''
      && (!ctype_digit($content_length) || (int) $content_length > $maximum)) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $body = '';
    $stream = $response->getBody();
    // A streamed body returns no bytes while the next packet is still on the
    // way; only the end of the stream ends the read. The request timeout
    // bounds the whole read, also against a peer that trickles bytes.
    $deadline = $this->now() + ($timeout ?? $this->configuration()['timeout']);
    while (!$stream->eof()) {
      if ($this->now() > $deadline) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $remaining = $maximum + 1 - strlen($body);
      if ($remaining <= 0) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      $chunk = $stream->read(min(65536, $remaining));
      if ($chunk === '') {
        usleep(self::EMPTY_READ_PAUSE_MICROSECONDS);
        continue;
      }
      $body .= $chunk;
    }
    if ($body === '' || strlen($body) > $maximum) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }
    return $body;
  }

  /**
   * Validates the complete PII-free v1 report projection.
   *
   * @param array<string, mixed> $report
   *   Decoded report.
   */
  private function validateReport(array $report): string {
    if (!$this->hasExactKeys($report, [
      'advisories',
      'derivative',
      'extraction',
      'findings',
      'guardVersion',
      'reasons',
      'redactionDensity',
      'source',
      'status',
    ])) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }
    $status = $report['status'];
    $density = $report['redactionDensity'];
    if ($report['guardVersion'] !== '1'
      || !is_string($status)
      || !in_array($status, self::VALID_STATUSES, TRUE)
      || (!is_int($density) && !is_float($density))
      || $density < 0
      || $density > 1
      || !$this->validEnumList($report['reasons'], self::VALID_REASONS, 24)
      || !$this->validEnumList($report['advisories'], self::VALID_ADVISORIES, 8)
      || !is_array($report['source'])
      || !$this->hasExactKeys($report['source'], ['mediaType', 'pages', 'size'])
      || !in_array($report['source']['mediaType'] ?? NULL, [
        'application/octet-stream',
        'application/pdf',
        'image/jpeg',
        'image/png',
      ], TRUE)
      || !is_int($report['source']['size'] ?? NULL)
      || $report['source']['size'] < 0
      || $report['source']['size'] > self::MAX_DOCUMENT_BYTES
      || !is_int($report['source']['pages'] ?? NULL)
      || $report['source']['pages'] < 0
      || !is_array($report['extraction'])
      || !$this->hasExactKeys($report['extraction'], ['engine', 'hasTextLayer', 'textChars'])
      || ($report['extraction']['engine'] ?? NULL) !== 'pdfminer.six'
      || !is_int($report['extraction']['textChars'] ?? NULL)
      || $report['extraction']['textChars'] < 0
      || !is_bool($report['extraction']['hasTextLayer'] ?? NULL)
      || !is_array($report['findings'])
      || !array_is_list($report['findings'])
      || count($report['findings']) > 64) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $categories = [];
    foreach ($report['findings'] as $finding) {
      if (!is_array($finding)
        || !$this->hasExactKeys($finding, ['category', 'count', 'pages'])
        || !is_string($finding['category'] ?? NULL)
        || !in_array($finding['category'], self::VALID_FINDING_CATEGORIES, TRUE)
        || !is_int($finding['count'] ?? NULL)
        || $finding['count'] <= 0
        || !is_array($finding['pages'] ?? NULL)
        || !array_is_list($finding['pages'])
        || $finding['pages'] === []
        || !$this->isSortedUnique($finding['pages'])) {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
      foreach ($finding['pages'] as $page) {
        if (!is_int($page) || $page < 1 || $page > $report['source']['pages']) {
          throw new \RuntimeException('document_guard.invalid_envelope');
        }
      }
      $categories[] = $finding['category'];
    }
    if (!$this->isSortedUnique($categories)) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    $has_derivative = in_array($status, ['clean', 'redacted'], TRUE);
    $derivative = $report['derivative'];
    if ($has_derivative) {
      if (!is_array($derivative)
        || !$this->hasExactKeys($derivative, ['mediaType', 'sha256', 'size'])
        || ($derivative['mediaType'] ?? NULL) !== 'application/pdf'
        || !is_int($derivative['size'] ?? NULL)
        || $derivative['size'] < 1
        || $derivative['size'] > self::MAX_DOCUMENT_BYTES
        || !is_string($derivative['sha256'] ?? NULL)
        || preg_match('/^[a-f0-9]{64}$/', $derivative['sha256']) !== 1
        || $report['reasons'] !== []
        || $report['source']['mediaType'] !== 'application/pdf') {
        throw new \RuntimeException('document_guard.invalid_envelope');
      }
    }
    elseif ($derivative !== NULL || $report['reasons'] === []) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }

    if (($status === 'clean'
        && ($report['extraction']['hasTextLayer'] !== TRUE
          || $report['extraction']['textChars'] <= 0
          || $report['findings'] !== []))
      || ($status === 'redacted' && $report['findings'] === [])) {
      throw new \RuntimeException('document_guard.invalid_envelope');
    }
    return $status;
  }

  /**
   * Validates one bounded sorted list of contract enum values.
   *
   * @param mixed $value
   *   Candidate list.
   * @param string[] $allowed
   *   Allowed values.
   * @param int $maximum
   *   Maximum number of list entries.
   */
  private function validEnumList(mixed $value, array $allowed, int $maximum): bool {
    if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
      return FALSE;
    }
    foreach ($value as $item) {
      if (!is_string($item) || !in_array($item, $allowed, TRUE)) {
        return FALSE;
      }
    }
    return $this->isSortedUnique($value);
  }

  /**
   * Returns TRUE when an array has exactly the expected string keys.
   *
   * @param array<mixed> $value
   *   Candidate object.
   * @param string[] $expected
   *   Expected keys.
   */
  private function hasExactKeys(array $value, array $expected): bool {
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    return $actual === $expected;
  }

  /**
   * Returns TRUE for sorted arrays without duplicates.
   *
   * @param array<mixed> $values
   *   Candidate values.
   */
  private function isSortedUnique(array $values): bool {
    $sorted = $values;
    sort($sorted);
    return $values === $sorted && count($values) === count(array_unique($values, SORT_REGULAR));
  }

  /**
   * Loads and validates immutable server-side configuration.
   *
   * @return array{baseUrl: string, tenant: string, apiKey: string, timeout: float, disableProxy: bool}
   *   Client configuration.
   */
  private function configuration(): array {
    if ($this->configuration !== NULL) {
      return $this->configuration;
    }
    $base_url = rtrim(trim((string) (getenv('BEANTRAGT_RAG_SERVICE_BASE_URL') ?: '')), '/');
    if ($base_url === '' && $this->isDevelopmentEnvironment()) {
      $base_url = self::DEFAULT_DDEV_BASE_URL;
    }
    $tenant = strtolower(trim((string) (getenv('BEANTRAGT_RAG_SERVICE_TENANT') ?: '')));
    $api_key = trim((string) (getenv('BEANTRAGT_RAG_SERVICE_API_KEY') ?: ''));
    $timeout_raw = trim((string) (getenv('BEANTRAGT_RAG_SERVICE_TIMEOUT_SECONDS') ?: '30'));
    $parts = parse_url($base_url);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $allow_http = $this->environmentBoolean('BEANTRAGT_RAG_SERVICE_ALLOW_HTTP');
    if (!is_array($parts)
      || !in_array($scheme, ['http', 'https'], TRUE)
      || $host === ''
      || isset($parts['user'])
      || isset($parts['pass'])
      || isset($parts['query'])
      || isset($parts['fragment'])
      || !in_array($host, $this->localHosts(), TRUE)
      || ($scheme === 'http' && !$allow_http)
      || preg_match('/^[a-z0-9][a-z0-9._-]{1,126}[a-z0-9]$/', $tenant) !== 1
      || strlen($api_key) < 32
      || strlen($api_key) > 512
      || preg_match('/\s/', $api_key) === 1
      || !is_numeric($timeout_raw)) {
      throw new \RuntimeException('document_guard.invalid_configuration');
    }
    $timeout = (float) $timeout_raw;
    if ($timeout < 1.0 || $timeout > 120.0) {
      throw new \RuntimeException('document_guard.invalid_configuration');
    }
    // Redacting a multi-page scan regularly takes longer than the 30 s that
    // suit context retrieval, which shares this variable. The guard runs in
    // the queue only, so it waits at least 50 s.
    $timeout = max(self::MIN_GUARD_TIMEOUT_SECONDS, $timeout);

    return $this->configuration = [
      'baseUrl' => $base_url,
      'tenant' => $tenant,
      'apiKey' => $api_key,
      'timeout' => $timeout,
      'disableProxy' => TRUE,
    ];
  }

  /**
   * Returns the explicit local guard-service hostname allowlist.
   *
   * @return string[]
   *   Allowed lowercase hostnames.
   */
  private function localHosts(): array {
    $configured = trim((string) (getenv('BEANTRAGT_DOCUMENT_GUARD_LOCAL_HOSTS') ?: ''));
    $hosts = self::LOCAL_HTTP_HOSTS;
    if ($configured !== '') {
      $hosts = array_merge($hosts, array_map('trim', explode(',', strtolower($configured))));
    }
    $hosts = array_values(array_unique(array_filter($hosts)));
    foreach ($hosts as $host) {
      if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) !== 1) {
        throw new \RuntimeException('document_guard.invalid_configuration');
      }
    }
    return $hosts;
  }

  /**
   * Returns TRUE for local development runtime.
   */
  private function isDevelopmentEnvironment(): bool {
    $environment = strtolower(trim((string) (getenv('BEANTRAGT_ENV') ?: '')));
    return getenv('IS_DDEV_PROJECT') === 'true'
      || in_array($environment, ['dev', 'local', 'ddev', 'development'], TRUE);
  }

  /**
   * Reads one strict boolean environment flag.
   */
  private function environmentBoolean(string $name): bool {
    return filter_var(getenv($name) ?: FALSE, FILTER_VALIDATE_BOOLEAN);
  }

}
