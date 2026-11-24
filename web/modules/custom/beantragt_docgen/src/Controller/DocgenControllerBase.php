<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Controller;

use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Shared envelope, CSRF, body and error handling of the docgen staff API.
 *
 * Success: {"data": ...}. Error: {"error": {"code": "docgen.*", "details":
 * {...}}} with a stable code for the UI's i18n keys. docgen.busy carries the
 * header Retry-After and details.retryAfter (seconds); Drupal never retries
 * on its own. The routes only require a session; CSRF, permission and scope
 * are checked here and in DocgenScopeAccess, so every refusal uses the
 * envelope.
 */
abstract class DocgenControllerBase extends ControllerBase {

  /**
   * Body limit of JSON requests without documents.
   */
  protected const MAX_JSON_BYTES = 65536;

  /**
   * Body limit of upload requests (5 MB DOCX in base64 plus envelope).
   */
  protected const MAX_UPLOAD_BYTES = 7 * 1024 * 1024 + 65536;

  /**
   * Per-person limits of operations that run a docgen conversion.
   *
   * Drafts (/render), previews (/finalize, LibreOffice plus veraPDF), sample
   * templates (/render) and DOCX uploads (malware scan plus docgen /inspect,
   * up to 7 MiB each) are the expensive calls; the shared docgen
   * stack serves every tenant of the server. The proxy's per-IP limit does
   * not separate people behind one municipal NAT, these limits do.
   * Operation => [attempts, window in seconds].
   */
  protected const FLOOD_LIMITS = [
    'create' => [30, 600],
    'preview' => [30, 600],
    'sample' => [20, 600],
    'upload' => [20, 600],
  ];

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected readonly DocgenScopeAccess $scopeAccess,
    protected readonly AccountProxyInterface $account,
    protected readonly CsrfTokenGenerator $csrfToken,
    protected readonly LoggerInterface $logger,
    protected readonly FloodInterface $flood,
  ) {}

  /**
   * Runs an operation and maps every failure to the error envelope.
   */
  protected function handle(Request $request, callable $operation, int $status = 200): Response {
    try {
      if (!$this->account->isAuthenticated()) {
        throw new DocgenApiException('docgen.access_denied', 403);
      }
      if (!$request->isMethodSafe()) {
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if ($token === '' || !$this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)) {
          throw new DocgenApiException('docgen.csrf_invalid', 403);
        }
      }
      $result = $operation();
      if ($result instanceof Response) {
        return $result;
      }
      return $this->json(['data' => $result], $status);
    }
    catch (DocgenApiException $exception) {
      $details = $exception->details;
      $headers = [];
      if ($exception->retryAfter !== NULL) {
        $details['retryAfter'] = $exception->retryAfter;
        $headers['Retry-After'] = (string) $exception->retryAfter;
      }
      return $this->json(['error' => ['code' => $exception->errorCode, 'details' => (object) $details]], $exception->status, $headers);
    }
    catch (\Throwable $exception) {
      // Database exceptions carry the query and its arguments (titles,
      // hashes); only the class goes to the log. Other messages are cut.
      $message = $exception instanceof DatabaseException
        ? '(database error, details withheld)'
        : mb_substr(preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? '', 0, 300);
      $this->logger->error('docgen staff API failed: @class @message', [
        '@class' => $exception::class,
        '@message' => $message,
      ]);
      return $this->json([
        'error' => ['code' => 'docgen.internal_error', 'details' => (object) []],
      ], 500);
    }
  }

  /**
   * Counts one attempt of an expensive operation for the current person.
   *
   * Beyond the limit: 429 docgen.rate_limited with Retry-After (the window).
   */
  protected function throttle(string $operation): void {
    [$threshold, $window] = self::FLOOD_LIMITS[$operation];
    $event = 'beantragt_docgen.' . $operation;
    $identifier = 'uid:' . $this->account->id();
    if (!$this->flood->isAllowed($event, $threshold, $window, $identifier)) {
      throw new DocgenApiException('docgen.rate_limited', 429, [], $window);
    }
    $this->flood->register($event, $window, $identifier);
  }

  /**
   * Reads the {"data": {...}} envelope of a JSON request.
   *
   * @return array<string, mixed>
   *   The data object.
   */
  protected function body(Request $request, int $max_bytes = self::MAX_JSON_BYTES): array {
    $length = (int) $request->headers->get('Content-Length', '0');
    if ($length > $max_bytes) {
      throw new DocgenApiException('docgen.input_too_large', 413);
    }
    $raw = (string) $request->getContent();
    if (strlen($raw) > $max_bytes) {
      throw new DocgenApiException('docgen.input_too_large', 413);
    }
    if ($raw === '') {
      return [];
    }
    if ($request->getContentTypeFormat() !== 'json') {
      throw new DocgenApiException('docgen.json_required', 415);
    }
    try {
      $decoded = json_decode($raw, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new DocgenApiException('docgen.invalid_json', 400);
    }
    if (!is_array($decoded) || array_keys($decoded) !== ['data'] || !is_array($decoded['data']) || ($decoded['data'] !== [] && array_is_list($decoded['data']))) {
      throw new DocgenApiException('docgen.input_invalid', 400);
    }
    return $decoded['data'];
  }

  /**
   * Requires only the listed keys in a body.
   */
  protected function allowKeys(array $data, array $allowed): void {
    $unknown = array_diff(array_keys($data), $allowed);
    if ($unknown !== []) {
      throw new DocgenApiException('docgen.unknown_key', 400, ['keys' => array_values($unknown)]);
    }
  }

  /**
   * Reads a required or optional string field.
   */
  protected function string(array $data, string $key, bool $required = TRUE, int $max = 255): ?string {
    $value = $data[$key] ?? NULL;
    if ($value === NULL && !$required) {
      return NULL;
    }
    if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $max) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => $key]);
    }
    return $value;
  }

  /**
   * Decodes the base64 file content of an upload body.
   */
  protected function content(array $data): string {
    $value = $data['content'] ?? NULL;
    if (!is_string($value) || $value === '') {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'content']);
    }
    $bytes = base64_decode($value, TRUE);
    if (!is_string($bytes) || $bytes === '') {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'content']);
    }
    return $bytes;
  }

  /**
   * Resolves the required ?scope=<group id> of collection routes.
   */
  protected function scopeFromQuery(Request $request, array $allowed_query = ['scope']): GroupInterface {
    $query = $request->query->all();
    if (array_diff(array_keys($query), $allowed_query) !== []) {
      throw new DocgenApiException('docgen.unknown_key', 400);
    }
    $raw = $query['scope'] ?? NULL;
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) {
      throw new DocgenApiException('docgen.scope_required', 400);
    }
    return $this->scopeAccess->loadScope((int) $raw) ?? throw new DocgenApiException('docgen.scope_not_found', 404);
  }

  /**
   * Serves a private document as an attachment.
   */
  protected function download(FileInterface $file, string $mime, string $fallback): BinaryFileResponse {
    $response = new BinaryFileResponse($file->getFileUri());
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFilename(), $fallback);
    $response->headers->set('Content-Type', $mime);
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
    return $response;
  }

  /**
   * Serves generated bytes as an attachment.
   */
  protected function downloadBytes(string $bytes, string $filename, string $mime, string $fallback): Response {
    $response = new Response($bytes, 200, [
      'Content-Type' => $mime,
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
      'Content-Security-Policy' => "default-src 'none'; sandbox",
    ]);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, $fallback));
    return $response;
  }

  /**
   * Emits the private JSON envelope.
   */
  protected function json(array $data, int $status = 200, array $headers = []): JsonResponse {
    return new JsonResponse($data, $status, $headers + [
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
