<?php

declare(strict_types=1);

namespace Drupal\beantragt_maintenance\Controller;

use Drupal\beantragt_maintenance\Service\BreakGlassOtpService;
use Drupal\beantragt_maintenance\Service\BreakGlassOtpServiceInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the global maintenance and break-glass recovery API.
 */
final class MaintenanceController extends ControllerBase {

  /**
   * Permission for normal maintenance-mode administration.
   */
  public const ADMIN_PERMISSION = 'administer beantragt maintenance';

  /**
   * Flood-control window in seconds.
   */
  private const int FLOOD_WINDOW = 3600;

  /**
   * Ceiling per address across all senders in one flood window.
   */
  private const int GLOBAL_TARGET_LIMIT = 60;

  /**
   * Constructs the maintenance controller.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly AccountInterface $currentAccount,
    private readonly LoggerChannelInterface $logger,
    private readonly BreakGlassOtpServiceInterface $breakGlassOtp,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $settingsConfigFactory,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('state'),
      $container->get('current_user'),
      $container->get('logger.channel.beantragt_maintenance'),
      $container->get('beantragt_maintenance.break_glass_otp'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Returns the public global maintenance state.
   */
  public function status(): JsonResponse {
    return $this->noStoreResponse([
      'maintenance' => $this->maintenanceActive(),
    ]);
  }

  /**
   * Enables or disables both beantragt and Core maintenance state.
   */
  public function update(Request $request): JsonResponse {
    $payload = $this->strictJsonObject($request, ['maintenance']);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if (!is_bool($payload['maintenance'])) {
      return $this->errorResponse(
        'Request body must contain only a boolean maintenance field.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $recovery_token = $this->bearerToken($request);
    $recovery_uid = NULL;
    if ($recovery_token !== NULL) {
      if ($payload['maintenance'] !== FALSE) {
        return $this->errorResponse(
          'A recovery token may only disable maintenance mode.',
          Response::HTTP_FORBIDDEN,
        );
      }

      $proxy_response = $this->serviceProxyResponse($request);
      if ($proxy_response !== NULL) {
        return $proxy_response;
      }
      $proxy_identifier = $this->trustedProxyFloodIdentifier($request);
      if ($proxy_identifier === NULL) {
        return $this->errorResponse('Access denied.', Response::HTTP_FORBIDDEN);
      }
      $settings = $this->settingsConfigFactory->get('beantragt_passwordless.settings');
      $attempt_limit = max(1, (int) ($settings->get('verify_lockout_attempts') ?? 5));
      $lockout_duration = max(60, (int) ($settings->get('verify_lockout_duration') ?? 900));
      $lockout_event = 'beantragt_maintenance.break_glass.token.lockout';
      if (!$this->flood->isAllowed(
        $lockout_event,
        $attempt_limit,
        $lockout_duration,
        $proxy_identifier,
      )) {
        return $this->errorResponse(
          'Too many recovery-token attempts. Please try again later.',
          Response::HTTP_TOO_MANY_REQUESTS,
        );
      }

      $recovery_uid = $this->breakGlassOtp->consumeRecoveryToken($recovery_token);
      if ($recovery_uid === NULL) {
        $this->flood->register($lockout_event, $lockout_duration, $proxy_identifier);
        return $this->errorResponse('Access denied.', Response::HTTP_FORBIDDEN);
      }
      $this->flood->clear($lockout_event, $proxy_identifier);
    }

    $maintenance = $payload['maintenance'];
    // Codes and tokens are scoped to one maintenance cycle. Clearing both
    // stores prevents an unused credential from becoming valid after an
    // off/on transition within its normal TTL.
    $this->breakGlassOtp->invalidateRecoveryArtifacts();
    $this->state->set(BreakGlassOtpService::PLATFORM_MAINTENANCE_STATE, $maintenance);
    $this->state->set('system.maintenance_mode', $maintenance);
    $this->logger->notice(
      'Global maintenance mode changed to @mode by @actor.',
      [
        '@mode' => $maintenance ? 'enabled' : 'disabled',
        '@actor' => $recovery_uid !== NULL
          ? 'recovery uid ' . $recovery_uid
          : 'uid ' . (int) $this->currentAccount->id(),
      ],
    );

    return $this->noStoreResponse(['maintenance' => $maintenance]);
  }

  /**
   * Restricts updates to privileged sessions or recovery bearer requests.
   */
  public function access(Request $request, AccountInterface $account): AccessResultInterface {
    $recovery_token = $this->bearerToken($request);
    if ($recovery_token !== NULL) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    $privileged = (int) $account->id() === 1
      || $account->hasPermission(self::ADMIN_PERMISSION);
    $csrf_token = (string) $request->headers->get('X-CSRF-Token', '');
    $csrf_valid = $csrf_token !== ''
      && ($this->csrfToken->validate($csrf_token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
        || $this->csrfToken->validate($csrf_token, 'rest'));

    return ($privileged && $csrf_valid
      ? AccessResult::allowed()
      : AccessResult::forbidden())
      ->setCacheMaxAge(0);
  }

  /**
   * Requests an account-enumeration-safe maintenance recovery code.
   */
  public function requestRecoveryCode(Request $request): JsonResponse {
    $proxy_response = $this->serviceProxyResponse($request);
    if ($proxy_response !== NULL) {
      return $proxy_response;
    }

    $payload = $this->strictJsonObject($request, ['email']);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    $email = is_string($payload['email']) ? mb_strtolower(trim($payload['email'])) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $this->errorResponse('Valid email address is required.', Response::HTTP_BAD_REQUEST);
    }

    $settings = $this->settingsConfigFactory->get('beantragt_passwordless.settings');
    $location_limit = max(1, (int) ($settings->get('request_limit_per_ip') ?? 10));
    $target_limit = max(1, (int) ($settings->get('request_limit_per_email') ?? 3));
    $location_identifier = $this->trustedProxyFloodIdentifier($request);
    if ($location_identifier === NULL) {
      return $this->errorResponse('Access denied.', Response::HTTP_FORBIDDEN);
    }
    $target_identifier = 'email:' . hash('sha256', $email);
    $sender_target_identifier = $location_identifier . ':' . $target_identifier;

    if (!$this->flood->isAllowed(
      'beantragt_maintenance.break_glass.request.location',
      $location_limit,
      self::FLOOD_WINDOW,
      $location_identifier,
    ) || !$this->flood->isAllowed(
      'beantragt_maintenance.break_glass.request.target',
      $target_limit,
      self::FLOOD_WINDOW,
      $sender_target_identifier,
    ) || !$this->flood->isAllowed(
      'beantragt_maintenance.break_glass.request.global_target',
      self::GLOBAL_TARGET_LIMIT,
      self::FLOOD_WINDOW,
      $target_identifier,
    )) {
      return $this->errorResponse(
        'Too many recovery-code requests. Please try again later.',
        Response::HTTP_TOO_MANY_REQUESTS,
      );
    }

    $this->flood->register(
      'beantragt_maintenance.break_glass.request.location',
      self::FLOOD_WINDOW,
      $location_identifier,
    );
    $this->flood->register(
      'beantragt_maintenance.break_glass.request.target',
      self::FLOOD_WINDOW,
      $sender_target_identifier,
    );
    $this->flood->register(
      'beantragt_maintenance.break_glass.request.global_target',
      self::FLOOD_WINDOW,
      $target_identifier,
    );

    return $this->noStoreResponse(
      $this->breakGlassOtp->requestCode($email),
    );
  }

  /**
   * Verifies a recovery code and returns an opaque one-time token.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   */
  public function verifyRecoveryCode(Request $request): JsonResponse {
    $proxy_response = $this->serviceProxyResponse($request);
    if ($proxy_response !== NULL) {
      return $proxy_response;
    }

    $payload = $this->strictJsonObject($request, ['email', 'code']);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    $email = is_string($payload['email']) ? mb_strtolower(trim($payload['email'])) : '';
    $code = is_string($payload['code']) ? trim($payload['code']) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
      return $this->errorResponse(
        'Valid email address and six-digit code are required.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $settings = $this->settingsConfigFactory->get('beantragt_passwordless.settings');
    $attempt_limit = max(1, (int) ($settings->get('verify_lockout_attempts') ?? 5));
    $lockout_duration = max(60, (int) ($settings->get('verify_lockout_duration') ?? 900));
    $proxy_identifier = $this->trustedProxyFloodIdentifier($request);
    if ($proxy_identifier === NULL) {
      return $this->errorResponse('Access denied.', Response::HTTP_FORBIDDEN);
    }
    $identifier = $proxy_identifier . ':email:' . hash('sha256', $email);
    $lockout_event = 'beantragt_maintenance.break_glass.verify.lockout';
    $backoff_event = 'beantragt_maintenance.break_glass.verify.backoff';
    if (!$this->flood->isAllowed($lockout_event, $attempt_limit, $lockout_duration, $identifier)
      || !$this->flood->isAllowed($backoff_event, 3, 60, $identifier)) {
      return $this->errorResponse(
        'Too many recovery-code attempts. Please try again later.',
        Response::HTTP_TOO_MANY_REQUESTS,
      );
    }

    $token = $this->breakGlassOtp->verifyCode($email, $code);
    if ($token === NULL) {
      $this->flood->register($lockout_event, $lockout_duration, $identifier);
      $this->flood->register($backoff_event, 60, $identifier);
      return $this->errorResponse('Invalid or expired recovery code.', Response::HTTP_UNAUTHORIZED);
    }

    $this->flood->clear($lockout_event, $identifier);
    $this->flood->clear($backoff_event, $identifier);
    return $this->noStoreResponse([
      'success' => TRUE,
      'recoveryToken' => $token['recoveryToken'],
      'expiresIn' => $token['expiresIn'],
    ]);
  }

  /**
   * Reports whether either maintenance state flag is enabled.
   */
  private function maintenanceActive(): bool {
    return (bool) $this->state->get(BreakGlassOtpService::PLATFORM_MAINTENANCE_STATE, FALSE)
      || (bool) $this->state->get('system.maintenance_mode', FALSE);
  }

  /**
   * Reads a strict JSON object with exactly the expected keys.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param list<string> $expected_keys
   *   Keys in their required JSON order.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Decoded data, or a 400 response.
   */
  private function strictJsonObject(Request $request, array $expected_keys): array|JsonResponse {
    try {
      $payload = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->errorResponse('Request body must be valid JSON.', Response::HTTP_BAD_REQUEST);
    }

    $actual_keys = is_array($payload) ? array_keys($payload) : [];
    sort($actual_keys);
    sort($expected_keys);
    if (!is_array($payload)
      || array_is_list($payload)
      || $actual_keys !== $expected_keys) {
      return $this->errorResponse(
        'Request body has an invalid shape.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    return $payload;
  }

  /**
   * Requires a trusted Nuxt service-proxy header for anonymous mutations.
   */
  private function serviceProxyResponse(Request $request): ?JsonResponse {
    if ($this->expectedServiceAuthorizationHeader() !== ''
      && hash_equals(
        $this->expectedServiceAuthorizationHeader(),
        (string) $request->headers->get('X-Beantragt-Service-Authorization', ''),
      )) {
      return NULL;
    }
    return $this->errorResponse('Access denied.', Response::HTTP_FORBIDDEN);
  }

  /**
   * Returns the trusted per-client identifier forwarded by Nuxt.
   */
  private function trustedProxyFloodIdentifier(Request $request): ?string {
    $identifier = (string) $request->headers->get('X-Beantragt-Rate-Limit-Identifier', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $identifier)) {
      return NULL;
    }
    return 'proxy:' . $identifier;
  }

  /**
   * Builds the service secret expected from the Nuxt proxy.
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
   * Extracts a syntactically valid recovery bearer token.
   */
  private function bearerToken(Request $request): ?string {
    $authorization = (string) $request->headers->get('Authorization', '');
    return preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/', $authorization, $matches)
      ? $matches[1]
      : NULL;
  }

  /**
   * Returns an explicitly uncacheable error response.
   *
   * @param string $message
   *   Public error message.
   * @param int $status
   *   HTTP status code.
   */
  private function errorResponse(string $message, int $status): JsonResponse {
    return $this->noStoreResponse(['error' => $message], $status);
  }

  /**
   * Returns an explicitly uncacheable JSON response.
   *
   * @param array<string, mixed> $payload
   *   Response data.
   * @param int $status
   *   HTTP status code.
   */
  private function noStoreResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

}
