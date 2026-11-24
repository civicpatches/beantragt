<?php

declare(strict_types=1);

namespace Drupal\beantragt_status\Controller;

use Drupal\beantragt_status\EventSubscriber\StatusCodeSubscriber;
use Drupal\beantragt_status\Service\StatusBuilder;
use Drupal\beantragt_status\Service\StatusOtpService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public status lookup controller.
 *
 * Returns application status and timeline by public lookup keys.
 * No PII is exposed in the response. Flood-controlled to prevent enumeration.
 */
final class StatusController extends ControllerBase {

  /**
   * Max status lookups per IP per hour.
   */
  protected const FLOOD_LIMIT = 30;

  /**
   * Flood window in seconds (1 hour).
   */
  protected const FLOOD_WINDOW = 3600;

  /**
   * Max OTP requests per IP per hour.
   */
  protected const OTP_REQUEST_FLOOD_LIMIT = 10;

  /**
   * Max OTP verification attempts per IP per hour.
   */
  protected const OTP_VERIFY_FLOOD_LIMIT = 30;

  /**
   * Max OTP requests per reference/email target per hour.
   */
  protected const OTP_TARGET_FLOOD_LIMIT = 5;

  /**
   * Public API version exposed by /api/v1 endpoints.
   */
  protected const API_VERSION = '1.0';

  /**
   * Constructs a StatusController.
   *
   * @param \Drupal\beantragt_status\Service\StatusBuilder $statusBuilder
   *   The status builder service.
   * @param \Drupal\beantragt_status\Service\StatusOtpService $statusOtp
   *   The status OTP service.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood control service.
   * @param \Drupal\beantragt_status\EventSubscriber\StatusCodeSubscriber $statusCodeRequests
   *   Defers matching and sending codes until after the response.
   */
  public function __construct(
    protected readonly StatusBuilder $statusBuilder,
    protected readonly StatusOtpService $statusOtp,
    protected readonly FloodInterface $flood,
    protected readonly StatusCodeSubscriber $statusCodeRequests,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_status.status_builder'),
      $container->get('beantragt_status.status_otp'),
      $container->get('flood'),
      $container->get('beantragt_status.status_code_requests'),
    );
  }

  /**
   * Returns the status of an application by reference number.
   *
   * @param string $reference_number
   *   The application reference number (e.g. BEA-2026-00001).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status data, 404 if not found, or 429 if rate limited.
   */
  public function getStatus(string $reference_number, Request $request): JsonResponse {
    $flood_response = $this->floodResponse(
      $request,
      'beantragt_status.lookup',
      self::FLOOD_LIMIT,
      $this->trustedProxyFloodIdentifier($request),
    );
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    return $this->noStore(new JsonResponse(
      ['error' => 'Application not found.'],
      Response::HTTP_NOT_FOUND,
    ));
  }

  /**
   * Requests an OTP for manual status lookup by reference number and email.
   *
   * @param string $reference_number
   *   The application reference number.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Generic JSON response. It does not reveal whether the application exists.
   */
  public function requestReferenceCode(string $reference_number, Request $request): JsonResponse {
    $proxy_response = $this->serviceProxyResponse($request);
    if ($proxy_response !== NULL) {
      return $proxy_response;
    }

    $flood_response = $this->floodResponse(
      $request,
      'beantragt_status.otp_request',
      self::OTP_REQUEST_FLOOD_LIMIT,
      $this->trustedProxyFloodIdentifier($request),
    );
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $data = $this->jsonBody($request);
    $email = isset($data['email']) && is_string($data['email']) ? trim($data['email']) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Valid email address is required.'],
        Response::HTTP_BAD_REQUEST,
      ));
    }

    $target_flood_response = $this->floodResponse(
      $request,
      'beantragt_status.otp_target',
      self::OTP_TARGET_FLOOD_LIMIT,
      $this->statusOtp->floodIdentifier($reference_number, $email),
    );
    if ($target_flood_response !== NULL) {
      return $target_flood_response;
    }

    $this->statusCodeRequests->enqueue($reference_number, $email, $this->mailLangcode($data));

    return $this->noStore(new JsonResponse([
      'success' => TRUE,
      'message' => 'If the reference and email match, a status code has been sent.',
      'codeLength' => 6,
    ]));
  }

  /**
   * Verifies an OTP and returns public status data.
   *
   * @param string $reference_number
   *   The application reference number.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status data, 401 for invalid code, or 429 if limited.
   */
  public function verifyReferenceCode(string $reference_number, Request $request): JsonResponse {
    $proxy_response = $this->serviceProxyResponse($request);
    if ($proxy_response !== NULL) {
      return $proxy_response;
    }

    $flood_response = $this->floodResponse(
      $request,
      'beantragt_status.otp_verify',
      self::OTP_VERIFY_FLOOD_LIMIT,
      $this->trustedProxyFloodIdentifier($request),
    );
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $data = $this->jsonBody($request);
    $email = isset($data['email']) && is_string($data['email']) ? trim($data['email']) : '';
    $code = isset($data['code']) && is_string($data['code']) ? trim($data['code']) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Valid email address is required.'],
        Response::HTTP_BAD_REQUEST,
      ));
    }

    if (!preg_match('/^\d{6}$/', $code)) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Valid status code is required.'],
        Response::HTTP_BAD_REQUEST,
      ));
    }

    if (!$this->statusOtp->verifyCode($reference_number, $email, $code)) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Invalid or expired status code.'],
        Response::HTTP_UNAUTHORIZED,
      ));
    }

    $result = $this->statusBuilder->buildByReferenceAndApplicantEmail($reference_number, $email);
    if ($result === NULL) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      ));
    }

    $result['timeline'] = $this->timelinePayloadV1($result['timeline'] ?? []);
    return $this->noStore(new JsonResponse($result));
  }

  /**
   * Returns the status of an application by public status token.
   *
   * @param string $public_status_token
   *   The public status token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status data, 404 if not found, or 429 if rate limited.
   */
  public function getApplicationStatus(string $public_status_token, Request $request): JsonResponse {
    $lookup = $this->publicStatusLookupContext($public_status_token, $request);
    if ($lookup === NULL) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      ));
    }

    return $this->statusResponse(
      $request,
      fn(): ?array => $this->statusBuilder->buildByPublicStatusToken(
        $lookup['token'],
        $lookup['schemaId'],
        $lookup['jurisdiction'],
        $lookup['organisation'],
      ),
    );
  }

  /**
   * Returns v1 public status data by public status token.
   *
   * @param string $public_status_token
   *   The public status token.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   v1 data envelope, v1 error envelope, or 429 if rate limited.
   */
  public function getApplicationStatusV1(string $public_status_token, Request $request): JsonResponse {
    $lookup = $this->publicStatusLookupContext($public_status_token, $request);
    if ($lookup === NULL) {
      return $this->publicApiErrorResponse(
        'status.not_found',
        'Status not found.',
        Response::HTTP_NOT_FOUND,
      );
    }

    return $this->statusResponseV1(
      $request,
      fn(): ?array => $this->statusBuilder->buildByPublicStatusToken(
        $lookup['token'],
        $lookup['schemaId'],
        $lookup['jurisdiction'],
        $lookup['organisation'],
      ),
    );
  }

  /**
   * Applies flood control before running the status lookup.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param callable $build
   *   Callback that performs the actual status lookup.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status data, 404 if not found, or 429 if rate limited.
   */
  private function statusResponse(Request $request, callable $build): JsonResponse {
    $flood_response = $this->floodResponse(
      $request,
      'beantragt_status.lookup',
      self::FLOOD_LIMIT,
      $this->trustedProxyFloodIdentifier($request),
    );
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    /** @var array<string, mixed>|null $result */
    $result = $build();
    if ($result === NULL) {
      return $this->noStore(new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      ));
    }

    $result['timeline'] = $this->timelinePayloadV1($result['timeline'] ?? []);
    return $this->noStore(new JsonResponse($result));
  }

  /**
   * Applies flood control before returning a v1 status response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param callable $build
   *   Callback that performs the actual status lookup.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   v1 data envelope, v1 error envelope, or 429 if rate limited.
   */
  private function statusResponseV1(Request $request, callable $build): JsonResponse {
    $flood_response = $this->floodResponse(
      $request,
      'beantragt_status.lookup',
      self::FLOOD_LIMIT,
      $this->trustedProxyFloodIdentifier($request),
      TRUE,
    );
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    /** @var array<string, mixed>|null $result */
    $result = $build();
    if ($result === NULL) {
      return $this->publicApiErrorResponse(
        'status.not_found',
        'Status not found.',
        Response::HTTP_NOT_FOUND,
      );
    }

    return $this->noStore(new JsonResponse([
      'data' => $this->publicStatusPayloadV1($result),
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'applicationStatus',
      ],
    ]));
  }

  /**
   * Applies flood control before a public status action.
   */
  private function floodResponse(Request $request, string $event, int $limit, ?string $identifier = NULL, bool $api_v1 = FALSE): ?JsonResponse {
    $identifier ??= $request->getClientIp() ?? 'unknown';

    if (!$this->flood->isAllowed($event, $limit, self::FLOOD_WINDOW, $identifier)) {
      if ($api_v1) {
        return $this->publicApiErrorResponse(
          'rate_limit.exceeded',
          'Too many requests. Please try again later.',
          Response::HTTP_TOO_MANY_REQUESTS,
        );
      }

      return $this->noStore(new JsonResponse(
        ['error' => 'Too many requests. Please try again later.'],
        Response::HTTP_TOO_MANY_REQUESTS,
      ));
    }

    $this->flood->register($event, self::FLOOD_WINDOW, $identifier);

    return NULL;
  }

  /**
   * Returns a Nuxt-proxied flood identifier after service-header validation.
   */
  private function trustedProxyFloodIdentifier(Request $request): ?string {
    $expected = $this->expectedServiceAuthorizationHeader();
    $provided = (string) $request->headers->get('X-Beantragt-Service-Authorization', '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
      return NULL;
    }

    $identifier = (string) $request->headers->get('X-Beantragt-Rate-Limit-Identifier', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $identifier)) {
      return NULL;
    }

    return 'proxy:' . $identifier;
  }

  /**
   * Reads and validates public status lookup context.
   *
   * @param string $public_status_token
   *   Public status token from the route.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Incoming request.
   *
   * @return array{token: string, schemaId: string, jurisdiction: string|null, organisation: string|null}|null
   *   Validated lookup context, or NULL when it must fail closed.
   */
  private function publicStatusLookupContext(string $public_status_token, Request $request): ?array {
    $token = trim($public_status_token);
    if (!preg_match('/^[A-Za-z0-9_-]{32,96}$/', $token)) {
      return NULL;
    }

    $schema_id = trim((string) $request->query->get('schemaId', ''));
    if ($schema_id === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $schema_id)) {
      return NULL;
    }

    $jurisdiction = $this->slugQueryValue($request, 'jurisdiction');
    $organisation = $this->slugQueryValue($request, 'organisation');
    if ($jurisdiction === FALSE || $organisation === FALSE) {
      return NULL;
    }

    return [
      'token' => $token,
      'schemaId' => $schema_id,
      'jurisdiction' => $jurisdiction,
      'organisation' => $organisation,
    ];
  }

  /**
   * Reads a sanitized slug query parameter.
   *
   * @return string|null|false
   *   The normalized slug, NULL when absent, or FALSE when invalid.
   */
  private function slugQueryValue(Request $request, string $name): string|null|false {
    $query = $request->query->all();
    $raw_value = $query[$name] ?? '';
    if (!is_string($raw_value)) {
      return FALSE;
    }

    $value = trim($raw_value);
    if ($value === '') {
      return NULL;
    }

    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $value) ? $value : FALSE;
  }

  /**
   * Requires Nuxt service-account proxying for status OTP mutations.
   */
  private function serviceProxyResponse(Request $request): ?JsonResponse {
    $expected = $this->expectedServiceAuthorizationHeader();
    $provided = (string) $request->headers->get('X-Beantragt-Service-Authorization', '');
    if ($expected !== '' && hash_equals($expected, $provided)) {
      return NULL;
    }

    return $this->noStore(new JsonResponse(
      ['error' => 'Access denied.'],
      Response::HTTP_FORBIDDEN,
    ));
  }

  /**
   * Builds the service secret value expected from the Nuxt proxy.
   *
   * The value intentionally travels in a custom header so Drupal's Basic Auth
   * provider does not consume the request before the public controller runs.
   */
  private function expectedServiceAuthorizationHeader(): string {
    $user = getenv('NUXT_DRUPAL_SERVICE_USER') ?: 'nuxt_service';
    $password = getenv('NUXT_DRUPAL_SERVICE_PASSWORD') ?: '';
    if ($password === '') {
      return '';
    }

    return 'Basic ' . base64_encode($user . ':' . $password);
  }

  /**
   * Marks a public status response as non-cacheable.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    // Status lookups can reveal case metadata. Do not store responses in
    // browser caches, shared caches, or screenshots of HTTP cache tooling.
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

    return $response;
  }

  /**
   * Builds the v1 public status payload.
   *
   * @param array<string, mixed> $status
   *   Legacy public status data from StatusBuilder.
   *
   * @return array<string, mixed>
   *   Public v1 status data.
   */
  private function publicStatusPayloadV1(array $status): array {
    return [
      'referenceNumber' => $this->stringValue($status, 'reference_number'),
      'status' => $this->stringValue($status, 'status'),
      'statusLabel' => $this->stringValue($status, 'status_label'),
      'statusColor' => $this->stringValue($status, 'status_color'),
      'statusIcon' => $this->stringValue($status, 'status_icon'),
      'formTitle' => $this->stringValue($status, 'form_title'),
      'organisationName' => $this->nullableStringValue($status, 'org_name'),
      'submittedAt' => $this->stringValue($status, 'submitted_at'),
      'updatedAt' => $this->stringValue($status, 'updated_at'),
      'timeline' => $this->timelinePayloadV1($status['timeline'] ?? []),
    ];
  }

  /**
   * Builds a v1 public API error response.
   */
  private function publicApiErrorResponse(string $code, string $message, int $status): JsonResponse {
    return $this->noStore(new JsonResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
      ],
    ], $status));
  }

  /**
   * Returns a string status value.
   *
   * @param array<string, mixed> $data
   *   Source data.
   * @param string $key
   *   Source key.
   *
   * @return string
   *   Scalar value converted to string, or an empty string.
   */
  private function stringValue(array $data, string $key): string {
    return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
  }

  /**
   * Returns a nullable string status value.
   *
   * @param array<string, mixed> $data
   *   Source data.
   * @param string $key
   *   Source key.
   *
   * @return string|null
   *   Scalar value converted to string, or NULL.
   */
  private function nullableStringValue(array $data, string $key): ?string {
    if (!array_key_exists($key, $data) || $data[$key] === NULL || !is_scalar($data[$key])) {
      return NULL;
    }

    return (string) $data[$key];
  }

  /**
   * Normalizes timeline entries for the v1 public status contract.
   *
   * @param mixed $timeline
   *   Legacy timeline data.
   *
   * @return array<int, array<string, string|null>>
   *   Public v1 timeline data.
   */
  private function timelinePayloadV1(mixed $timeline): array {
    if (!is_array($timeline)) {
      return [];
    }

    $items = [];
    foreach ($timeline as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $item = [
        'status' => isset($entry['status']) && is_scalar($entry['status']) ? (string) $entry['status'] : '',
        'label' => isset($entry['label']) && is_scalar($entry['label']) ? (string) $entry['label'] : '',
        'color' => isset($entry['color']) && is_scalar($entry['color']) ? (string) $entry['color'] : '',
        'icon' => isset($entry['icon']) && is_scalar($entry['icon']) ? (string) $entry['icon'] : '',
        'timestamp' => isset($entry['timestamp']) && is_scalar($entry['timestamp']) ? (string) $entry['timestamp'] : '',
      ];
      if ($item['status'] === '' || $item['timestamp'] === '') {
        continue;
      }
      if (array_key_exists('actor', $entry)) {
        $actor = is_scalar($entry['actor']) ? (string) $entry['actor'] : '';
        $item['actor'] = $actor === 'staff' ? 'staff' : NULL;
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * Decodes a JSON request body.
   *
   * @return array<string, mixed>
   *   The decoded body, or an empty array for invalid JSON.
   */
  private function jsonBody(Request $request): array {
    $content = $request->getContent();
    if ($content === '') {
      return [];
    }

    $data = json_decode($content, TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Language of the status code mail.
   *
   * The UI sends its locale; Symfony's request locale is always "en" here
   * because Drupal does not set it.
   *
   * @param array<string, mixed> $data
   *   Decoded request body.
   */
  private function mailLangcode(array $data): string {
    $requested = $data['langcode'] ?? NULL;
    if (in_array($requested, ['de', 'en'], TRUE)) {
      return $requested;
    }
    $current = $this->languageManager()->getCurrentLanguage()->getId();
    return in_array($current, ['de', 'en'], TRUE) ? $current : 'de';
  }

}
