<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\PublicSubmissionReceipt;
use Drupal\beantragt_application\Service\EmailConfirmationTokenService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public double opt-in confirmation endpoints.
 */
final class EmailConfirmationController extends ControllerBase {

  private const RESEND_LIMIT = 3;
  private const CONFIRM_IP_LIMIT = 30;
  private const RESEND_WINDOW = 3600;
  private const MAX_BODY_BYTES = 8192;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly EmailConfirmationTokenService $confirmationTokens,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_application.email_confirmation_tokens'),
      $container->get('flood'),
    );
  }

  /**
   * Confirms an anonymous public application submission.
   */
  public function confirm(Request $request): JsonResponse {
    $body = $this->jsonBody($request);
    $token = is_array($body) && is_string($body['token'] ?? NULL)
      ? trim($body['token'])
      : '';

    $ip_response = $this->floodResponse(
      'beantragt_application.confirm.ip',
      $this->clientIpIdentifier($request),
      self::CONFIRM_IP_LIMIT,
    );
    if ($ip_response instanceof JsonResponse) {
      return $ip_response;
    }

    $result = $this->confirmationTokens->claim($token);
    if ($result['status'] === EmailConfirmationTokenService::STATUS_INVALID) {
      return $this->invalidConfirmationResponse();
    }

    $application = $result['application'];
    $jurisdiction = PublicSubmissionReceipt::group($application, 'field_jurisdiction', 'jur');
    $jurisdiction_slug = $this->groupSlug($jurisdiction);
    $payload = [
      'status' => $result['status'],
      'referenceNumber' => $application->getReferenceNumber(),
      'jurisdictionSlug' => $jurisdiction_slug,
    ];

    $organisation = PublicSubmissionReceipt::group($application, 'field_organisation', 'org');
    if ($jurisdiction_slug !== NULL && $organisation !== NULL
      && PublicSubmissionReceipt::organisationBelongsToJurisdiction($organisation, $jurisdiction)) {
      $organisation_slug = $this->groupSlug($organisation);
      if ($organisation_slug !== NULL) {
        $payload['organisationSlug'] = $organisation_slug;
      }
    }

    $payload['receipt'] = PublicSubmissionReceipt::build($application);
    return $this->noStore(new JsonResponse($payload));
  }

  /**
   * Returns a group slug safe for use in a public path.
   */
  private function groupSlug(?GroupInterface $group): ?string {
    if ($group === NULL || !$group->hasField('field_slug')) {
      return NULL;
    }

    $slug = strtolower(trim((string) $group->get('field_slug')->value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Accepts a confirmation resend request without revealing matches.
   */
  public function resend(Request $request): JsonResponse {
    $body = $this->jsonBody($request);
    $reference = is_array($body) && is_string($body['reference'] ?? NULL)
      ? trim($body['reference'])
      : '';
    $email = is_array($body) && is_string($body['email'] ?? NULL)
      ? trim($body['email'])
      : '';

    $ip_response = $this->floodResponse(
      'beantragt_application.confirm_resend.ip',
      $this->clientIpIdentifier($request),
      self::RESEND_LIMIT,
    );
    if ($ip_response instanceof JsonResponse) {
      return $ip_response;
    }

    $email_response = $this->floodResponse(
      'beantragt_application.confirm_resend.email',
      $this->emailFloodIdentifier($email),
      self::RESEND_LIMIT,
    );
    if ($email_response instanceof JsonResponse) {
      return $email_response;
    }

    if ($reference !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $this->confirmationTokens->resend($reference, $email);
    }

    return $this->acceptedResponse();
  }

  /**
   * Applies one flood bucket.
   */
  private function floodResponse(string $event, string $identifier, int $limit): ?JsonResponse {
    if (!$this->flood->isAllowed($event, $limit, self::RESEND_WINDOW, $identifier)) {
      return $this->noStore(new JsonResponse([
        'error' => [
          'code' => 'rate_limit.exceeded',
        ],
      ], Response::HTTP_TOO_MANY_REQUESTS));
    }

    $this->flood->register($event, self::RESEND_WINDOW, $identifier);
    return NULL;
  }

  /**
   * Parses a small JSON request body.
   *
   * @return array<string, mixed>|null
   *   Decoded JSON object, or NULL.
   */
  private function jsonBody(Request $request): ?array {
    $content = $request->getContent();
    if (strlen($content) > self::MAX_BODY_BYTES) {
      return NULL;
    }
    try {
      $decoded = json_decode($content, TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }

    return is_array($decoded) && !array_is_list($decoded) ? $decoded : NULL;
  }

  /**
   * Builds the generic invalid confirmation response.
   */
  private function invalidConfirmationResponse(): JsonResponse {
    return $this->noStore(new JsonResponse([
      'error' => [
        'code' => 'confirmation.invalid',
      ],
    ], Response::HTTP_NOT_FOUND));
  }

  /**
   * Builds the generic accepted resend response.
   */
  private function acceptedResponse(): JsonResponse {
    return $this->noStore(new JsonResponse(['status' => 'accepted']));
  }

  /**
   * Returns a stable per-client flood identifier.
   *
   * Requests reach this controller through the Nuxt service proxy, so
   * getClientIp() resolves to the fixed proxy peer for every citizen and would
   * collapse all callers into one global bucket. When the proxy signs the
   * request it forwards a per-client X-Beantragt-Rate-Limit-Identifier that is
   * honored here, mirroring the sibling public controllers. Direct
   * (non-proxied) requests fall back to the socket IP.
   */
  private function clientIpIdentifier(Request $request): string {
    return $this->trustedProxyFloodIdentifier($request)
      ?? 'ip:' . ($request->getClientIp() ?? 'unknown');
  }

  /**
   * Returns a per-client flood identifier signed by the trusted Nuxt proxy.
   */
  private function trustedProxyFloodIdentifier(Request $request): ?string {
    if (!$this->isTrustedServiceRequest($request)) {
      return NULL;
    }

    $identifier = (string) $request->headers->get('X-Beantragt-Rate-Limit-Identifier', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $identifier)) {
      return NULL;
    }

    return 'proxy:' . $identifier;
  }

  /**
   * Returns TRUE when the request was signed by the Nuxt service proxy.
   */
  private function isTrustedServiceRequest(Request $request): bool {
    $expected = $this->expectedServiceAuthorizationHeader();
    $provided = (string) $request->headers->get('X-Beantragt-Service-Authorization', '');
    return $expected !== '' && hash_equals($expected, $provided);
  }

  /**
   * Builds the service secret value expected from the Nuxt proxy.
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
   * Returns a privacy-preserving email flood identifier.
   */
  private function emailFloodIdentifier(string $email): string {
    $email = strtolower(trim($email));
    return hash('sha256', $email);
  }

  /**
   * Adds private no-store headers.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
