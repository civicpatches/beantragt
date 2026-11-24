<?php

declare(strict_types=1);

namespace Drupal\beantragt_passwordless\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\beantragt_passwordless\Service\OtpService;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides JSON API endpoints for passwordless OTP authentication.
 */
class PasswordlessAuthController extends ControllerBase {

  /**
   * Public API version for v1 auth envelopes.
   */
  private const string API_VERSION = '1.0';

  /**
   * Session key for the public v1 passwordless challenge.
   */
  private const string V1_CHALLENGE_SESSION_KEY = 'beantragt_passwordless.v1_challenge';

  /**
   * Public v1 auth challenge lifetime in seconds.
   */
  private const int V1_CHALLENGE_TTL = 600;

  /**
   * The OTP service.
   *
   * @var \Drupal\beantragt_passwordless\Service\OtpService
   */
  protected OtpService $otpService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected FloodInterface $flood;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The session configuration.
   *
   * @var \Drupal\Core\Session\SessionConfigurationInterface
   */
  protected SessionConfigurationInterface $sessionConfiguration;

  /**
   * The CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected CsrfTokenGenerator $csrfToken;

  /**
   * Constructs a PasswordlessAuthController object.
   *
   * @param \Drupal\beantragt_passwordless\Service\OtpService $otp_service
   *   The OTP service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\SessionConfigurationInterface $session_configuration
   *   The session configuration.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   */
  public function __construct(
    OtpService $otp_service,
    AccountInterface $current_user,
    FloodInterface $flood,
    ConfigFactoryInterface $config_factory,
    SessionConfigurationInterface $session_configuration,
    CsrfTokenGenerator $csrf_token,
  ) {
    $this->otpService = $otp_service;
    $this->currentUser = $current_user;
    $this->flood = $flood;
    $this->configFactory = $config_factory;
    $this->sessionConfiguration = $session_configuration;
    $this->csrfToken = $csrf_token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_passwordless.otp'),
      $container->get('current_user'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('session_configuration'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Request OTP code endpoint.
   *
   * POST /auth/request-code
   * Body: { "email": "user@example.com" }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with status.
   */
  public function requestCode(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['email'])) {
      return new JsonResponse([
        'error' => 'Email is required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $email = strtolower(trim($data['email']));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse([
        'error' => 'Invalid email format',
      ], Response::HTTP_BAD_REQUEST);
    }

    $config = $this->configFactory->get('beantragt_passwordless.settings');
    $request_limit_per_email = $config->get('request_limit_per_email') ?? 3;
    $request_limit_per_ip = $config->get('request_limit_per_ip') ?? 10;

    if (!$this->flood->isAllowed('passwordless.request_code', $request_limit_per_email, 3600, $email)) {
      return new JsonResponse([
        'error' => 'Too many code requests. Please try again in an hour.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    $ip = $request->getClientIp();
    if (!$this->flood->isAllowed('passwordless.request_code.ip', $request_limit_per_ip, 3600, $ip)) {
      return new JsonResponse([
        'error' => 'Too many requests from your location. Please try again later.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    $langcode = isset($data['langcode']) && in_array($data['langcode'], ['de', 'en'], TRUE)
      ? (string) $data['langcode']
      : '';
    $jurisdiction_slug = isset($data['jurisdiction']) && is_string($data['jurisdiction'])
      ? strtolower(trim($data['jurisdiction']))
      : '';
    if ($jurisdiction_slug !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $jurisdiction_slug) !== 1) {
      $jurisdiction_slug = '';
    }

    try {
      $result = $jurisdiction_slug === ''
        ? $this->otpService->requestCode($email, $langcode)
        : $this->otpService->requestCode($email, $langcode, $jurisdiction_slug);

      if ($result['success']) {
        $this->flood->register('passwordless.request_code', 3600, $email);
        $this->flood->register('passwordless.request_code.ip', 3600, $ip);

        return new JsonResponse([
          'success' => TRUE,
          'message' => $result['message'],
          'expiresIn' => $result['expiresIn'],
          'codeLength' => $result['codeLength'] ?? OtpService::DEFAULT_CODE_LENGTH,
          'challengeId' => $this->createV1Challenge($request, $email),
        ]);
      }

      return new JsonResponse([
        'error' => $result['error'] ?? 'Failed to send verification code',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    catch (\Exception $e) {
      $this->getLogger('beantragt_passwordless')->error('Failed to send OTP: @class', [
        '@class' => $e::class,
      ]);

      return new JsonResponse([
        'error' => 'An error occurred while sending verification code',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Verify OTP code endpoint.
   *
   * POST /auth/verify-code
   * Body: { "email": "user@example.com", "code": "123456" }
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with authentication status.
   */
  public function verifyCode(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    if (empty($data['email']) || empty($data['code'])) {
      return new JsonResponse([
        'error' => 'Email and code are required',
      ], Response::HTTP_BAD_REQUEST);
    }

    $email = strtolower(trim($data['email']));
    $code = trim($data['code']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse([
        'error' => 'Invalid email format',
      ], Response::HTTP_BAD_REQUEST);
    }

    if (!$this->validateV1Challenge($request, $email)) {
      return new JsonResponse([
        'error' => 'A valid passwordless challenge from /auth/request-code is required.',
      ], Response::HTTP_FORBIDDEN);
    }

    if (!ctype_digit($code) || strlen($code) !== $this->otpService->getCodeLength()) {
      $this->clearV1Challenge($request);
      return new JsonResponse([
        'error' => 'Invalid code format',
      ], Response::HTTP_BAD_REQUEST);
    }

    $ip = $request->getClientIp();
    $identifier = $email . ':' . $ip;

    $config = $this->configFactory->get('beantragt_passwordless.settings');
    $verify_lockout_attempts = $config->get('verify_lockout_attempts') ?? 5;
    $verify_lockout_duration = $config->get('verify_lockout_duration') ?? 900;

    if (!$this->flood->isAllowed('passwordless.verify.lockout', $verify_lockout_attempts, $verify_lockout_duration, $identifier)) {
      $minutes = ceil($verify_lockout_duration / 60);
      $this->clearV1Challenge($request);
      return new JsonResponse([
        'error' => "Too many failed attempts. Account temporarily locked. Please try again in {$minutes} minutes.",
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Check exponential backoff (3+ attempts in 1 minute).
    if (!$this->flood->isAllowed('passwordless.verify.backoff', 3, 60, $identifier)) {
      $this->clearV1Challenge($request);
      return new JsonResponse([
        'error' => 'Too many attempts. Please wait a moment before trying again.',
      ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    try {
      $result = $this->otpService->verifyCode($email, $code);

      if ($result['success']) {
        $user_payload = is_array($result['user'] ?? NULL) ? $result['user'] : [];
        $this->markPasswordlessLogin($request, $user_payload);
        $user_payload['auth_provider'] = 'passwordless';
        $user_payload['assurance_level'] = NULL;

        $this->flood->clear('passwordless.verify.lockout', $identifier);
        $this->flood->clear('passwordless.verify.backoff', $identifier);
        $this->clearV1Challenge($request);

        return new JsonResponse([
          'success' => TRUE,
          'message' => $result['message'],
          'user' => $user_payload,
        ]);
      }

      $this->flood->register('passwordless.verify.lockout', $verify_lockout_duration, $identifier);
      $this->flood->register('passwordless.verify.backoff', 60, $identifier);
      $this->clearV1Challenge($request);

      return new JsonResponse([
        'error' => $result['error'] ?? 'Invalid or expired code',
      ], Response::HTTP_UNAUTHORIZED);
    }
    catch (\Exception $e) {
      $this->getLogger('beantragt_passwordless')->error('Failed to verify OTP: @class', [
        '@class' => $e::class,
      ]);
      $this->clearV1Challenge($request);

      return new JsonResponse([
        'error' => 'An error occurred during authentication',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Logout endpoint.
   *
   * POST /auth/logout.
   *
   * Validates the CSRF token from X-CSRF-Token header before processing
   * the logout to prevent cross-site logout attacks.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with logout status.
   */
  public function logout(Request $request): JsonResponse {
    // Validate CSRF token to prevent cross-site logout attacks.
    // The Nuxt frontend must send the X-CSRF-Token header obtained
    // from /session/token.
    if ($this->currentUser->isAuthenticated()) {
      $csrf_token = $request->headers->get('X-CSRF-Token');
      if (empty($csrf_token)
        || (!$this->csrfToken->validate($csrf_token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
          && !$this->csrfToken->validate($csrf_token, 'rest'))) {
        return new JsonResponse([
          'error' => 'CSRF token validation failed',
        ], Response::HTTP_FORBIDDEN);
      }
    }

    // Get session cookie name before logout destroys the session.
    $session_config = $this->sessionConfiguration;
    $session_options = $session_config->getOptions($request);
    $session_name = $session_options['name'] ?? session_name();

    if ($this->currentUser->isAuthenticated()) {
      user_logout();
    }

    $response = new JsonResponse([
      'success' => TRUE,
      'message' => 'Logged out successfully',
    ]);

    // Explicitly expire the session cookie to ensure browser deletes it.
    // This is necessary because HttpOnly cookies can't be deleted by
    // JavaScript.
    // SameSite=Lax is more secure than None for the expiration cookie.
    $cookie_domain = $session_options['cookie_domain'] ?? '';
    $response->headers->setCookie(
      new Cookie(
        $session_name,
        '',
        1,
        '/',
        $cookie_domain,
        TRUE,
        TRUE,
        FALSE,
        Cookie::SAMESITE_LAX
      )
    );

    return $response;
  }

  /**
   * User status endpoint.
   *
   * GET /auth/status.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with user status.
   */
  public function status(Request $request): JsonResponse {
    $account = $this->currentUser;

    if ($account->isAuthenticated()) {
      $user = $this->entityTypeManager()
        ->getStorage('user')
        ->load($account->id());
      $auth_context = $this->authContext($request, (int) $account->id());

      return new JsonResponse([
        'authenticated' => TRUE,
        'user' => [
          'uid' => $account->id(),
          'uuid' => $user?->uuid(),
          'name' => $account->getAccountName(),
          'email' => $account->getEmail(),
          'roles' => $account->getRoles(),
          'auth_provider' => $auth_context['provider'],
          'assurance_level' => $auth_context['assurance_level'],
        ],
      ]);
    }

    return new JsonResponse([
      'authenticated' => FALSE,
    ]);
  }

  /**
   * Public v1 passwordless code request endpoint.
   *
   * POST /api/v1/auth/request-code.
   */
  public function requestCodeV1(Request $request): JsonResponse {
    $email = $this->emailFromRequestBody($request);
    if ($email !== NULL && $this->emailHasRestrictedPublicAuthProfile($email)) {
      return $this->unsupportedPublicAuthProfileResponse();
    }

    $legacy_response = $this->requestCode($request);
    $legacy_payload = $this->decodeJsonResponse($legacy_response);
    $status = $legacy_response->getStatusCode();
    $challenge_id = $email !== NULL ? $this->createV1Challenge($request, $email) : NULL;

    if ($status < Response::HTTP_BAD_REQUEST && ($legacy_payload['success'] ?? FALSE) === TRUE) {
      return $this->v1PrivateResponse([
        'data' => [
          'type' => 'auth_code_request',
          'delivery' => 'email',
          'expiresIn' => (int) ($legacy_payload['expiresIn'] ?? 0),
          'codeLength' => (int) ($legacy_payload['codeLength'] ?? OtpService::DEFAULT_CODE_LENGTH),
          'challengeId' => $challenge_id,
        ],
        'meta' => [
          'apiVersion' => self::API_VERSION,
          'resource' => 'authCodeRequest',
        ],
      ], $status);
    }

    $message = $this->legacyErrorMessage($legacy_payload, 'Authentication code request failed.');
    $code = $this->authErrorCode('request_code', $status, $message);
    $details = $challenge_id !== NULL && $code === 'auth.rate_limited'
      ? ['challengeId' => $challenge_id]
      : [];
    return $this->v1ErrorResponse(
      $code,
      $message,
      $status,
      $details,
    );
  }

  /**
   * Public v1 passwordless code verification endpoint.
   *
   * POST /api/v1/auth/verify-code.
   */
  public function verifyCodeV1(Request $request): JsonResponse {
    $email = $this->emailFromRequestBody($request);
    if ($email !== NULL && $this->emailHasRestrictedPublicAuthProfile($email)) {
      return $this->unsupportedPublicAuthProfileResponse();
    }
    if (!$this->validateV1Challenge($request, $email)) {
      return $this->v1ErrorResponse(
        'auth.challenge_invalid',
        'A valid passwordless challenge from /api/v1/auth/request-code is required.',
        Response::HTTP_FORBIDDEN,
      );
    }

    $legacy_response = $this->verifyCode($request);
    $legacy_payload = $this->decodeJsonResponse($legacy_response);
    $status = $legacy_response->getStatusCode();

    if ($status < Response::HTTP_BAD_REQUEST && ($legacy_payload['success'] ?? FALSE) === TRUE) {
      $user_payload = is_array($legacy_payload['user'] ?? NULL) ? $legacy_payload['user'] : [];
      if ($this->roleListHasRestrictedPublicAuthProfile($user_payload['roles'] ?? [])) {
        if ($this->currentUser->isAuthenticated()) {
          user_logout();
        }
        return $this->unsupportedPublicAuthProfileResponse();
      }
      $this->clearV1Challenge($request);
      return $this->v1PrivateResponse([
        'data' => [
          'type' => 'auth_session',
          'authenticated' => TRUE,
          'user' => $this->publicUserProfile($request, $user_payload),
        ],
        'meta' => [
          'apiVersion' => self::API_VERSION,
          'resource' => 'authSession',
        ],
      ], $status);
    }

    $message = $this->legacyErrorMessage($legacy_payload, 'Authentication failed.');
    return $this->v1ErrorResponse(
      $this->authErrorCode('verify_code', $status, $message),
      $message,
      $status,
    );
  }

  /**
   * Public v1 auth session status endpoint.
   *
   * GET /api/v1/auth/status.
   */
  public function statusV1(Request $request): JsonResponse {
    $legacy_response = $this->status($request);
    $legacy_payload = $this->decodeJsonResponse($legacy_response);
    $user_payload = is_array($legacy_payload['user'] ?? NULL) ? $legacy_payload['user'] : [];
    $authenticated = ($legacy_payload['authenticated'] ?? FALSE) === TRUE;

    if ($authenticated && $this->roleListHasRestrictedPublicAuthProfile($user_payload['roles'] ?? [])) {
      return $this->unsupportedPublicAuthProfileResponse();
    }

    return $this->v1PrivateResponse([
      'data' => [
        'type' => 'auth_session',
        'authenticated' => $authenticated,
        'user' => $authenticated ? $this->publicUserProfile($request, $user_payload) : NULL,
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'authSession',
      ],
    ], $legacy_response->getStatusCode());
  }

  /**
   * Public v1 logout endpoint.
   *
   * POST /api/v1/auth/logout.
   */
  public function logoutV1(Request $request): JsonResponse {
    $legacy_response = $this->logout($request);
    $legacy_payload = $this->decodeJsonResponse($legacy_response);
    $status = $legacy_response->getStatusCode();

    if ($status < Response::HTTP_BAD_REQUEST && ($legacy_payload['success'] ?? FALSE) === TRUE) {
      $response = $this->v1PrivateResponse([
        'data' => [
          'type' => 'auth_session',
          'authenticated' => FALSE,
          'user' => NULL,
        ],
        'meta' => [
          'apiVersion' => self::API_VERSION,
          'resource' => 'authSession',
        ],
      ], $status);
      $this->copyCookies($legacy_response, $response);

      return $response;
    }

    $message = $this->legacyErrorMessage($legacy_payload, 'Logout failed.');
    return $this->v1ErrorResponse(
      $this->authErrorCode('logout', $status, $message),
      $message,
      $status,
    );
  }

  /**
   * Returns the current citizen auth context for the active session.
   *
   * Only sessions marked by a known citizen login flow get a provider. Plain
   * Drupal sessions stay "unknown" so schema-level provider checks fail closed.
   *
   * @return array{provider: string, assurance_level: string|null}
   *   Public auth context for Nuxt.
   */
  private function authContext(Request $request, int $uid): array {
    $provider = 'unknown';
    $assurance_level = NULL;
    $time = 0;

    if ($request->hasSession()) {
      $passwordless_login = $request->getSession()->get('beantragt_passwordless.last_login');
      if (is_array($passwordless_login) && (int) ($passwordless_login['uid'] ?? 0) === $uid) {
        $provider = 'passwordless';
        $time = (int) ($passwordless_login['time'] ?? 0);
      }

      $last_login = $request->getSession()->get('beantragt_sso.last_login');
      if (is_array($last_login) && (int) ($last_login['uid'] ?? 0) === $uid) {
        $session_provider = $last_login['provider'] ?? NULL;
        $session_time = (int) ($last_login['time'] ?? 0);
        if ($session_time >= $time && is_string($session_provider) && preg_match('/^[a-zA-Z0-9_-]+$/', $session_provider) === 1) {
          $provider = $session_provider;
          $assurance_level = NULL;
          $session_assurance = $last_login['assurance_level'] ?? NULL;
          if (is_string($session_assurance) && trim($session_assurance) !== '') {
            $assurance_level = $session_assurance;
          }
        }
      }
    }

    return [
      'provider' => $provider,
      'assurance_level' => $assurance_level,
    ];
  }

  /**
   * Marks the active Drupal session as passwordless-authenticated.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current HTTP request.
   * @param array<string, mixed> $user_payload
   *   User payload returned by the OTP service.
   */
  private function markPasswordlessLogin(Request $request, array $user_payload): void {
    if (!$request->hasSession()) {
      return;
    }

    $uid = (int) ($user_payload['uid'] ?? 0);
    if ($uid <= 0) {
      return;
    }

    $session = $request->getSession();
    $session->remove('beantragt_sso.last_login');
    $session->set('beantragt_passwordless.last_login', [
      'provider' => 'passwordless',
      'uid' => $uid,
      'time' => time(),
    ]);
  }

  /**
   * Builds the public v1 user projection without numeric Drupal IDs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param array<string, mixed> $legacy_user
   *   Legacy auth user payload.
   *
   * @return array<string, mixed>
   *   Public v1 user profile.
   */
  private function publicUserProfile(Request $request, array $legacy_user): array {
    $uid = (int) ($legacy_user['uid'] ?? $this->currentUser->id());
    $auth_context = $uid > 0
      ? $this->authContext($request, $uid)
      : ['provider' => 'unknown', 'assurance_level' => NULL];
    $uuid = $this->nullableString($legacy_user['uuid'] ?? NULL) ?? $this->loadUserUuid($uid);

    $roles = is_array($legacy_user['roles'] ?? NULL) ? $legacy_user['roles'] : [];
    $roles = array_values(array_filter(
      array_map(static fn(mixed $role): string => is_string($role) ? $role : '', $roles),
      static fn(string $role): bool => $role !== '',
    ));

    return [
      'type' => 'user',
      'id' => $uuid,
      'name' => (string) ($legacy_user['name'] ?? $this->currentUser->getAccountName()),
      'email' => (string) ($legacy_user['email'] ?? $this->currentUser->getEmail()),
      'roles' => $roles,
      'authProvider' => (string) ($legacy_user['auth_provider'] ?? $auth_context['provider']),
      'assuranceLevel' => $this->nullableString($legacy_user['assurance_level'] ?? $auth_context['assurance_level']),
    ];
  }

  /**
   * Returns a nullable scalar string.
   */
  private function nullableString(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Loads a user's UUID for legacy auth payloads that only contain uid.
   */
  private function loadUserUuid(int $uid): ?string {
    if ($uid <= 0) {
      return NULL;
    }

    try {
      $user = $this->entityTypeManager()
        ->getStorage('user')
        ->load($uid);
    }
    catch (\Throwable) {
      return NULL;
    }

    if ($user === NULL) {
      return NULL;
    }

    $uuid = $user->uuid();
    return is_string($uuid) && $uuid !== '' ? $uuid : NULL;
  }

  /**
   * Extracts a normalized email from a JSON request body.
   */
  private function emailFromRequestBody(Request $request): ?string {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return NULL;
    }

    $email = $data['email'] ?? NULL;
    if (!is_string($email)) {
      return NULL;
    }

    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL;
  }

  /**
   * Returns TRUE when an email belongs to a non-citizen auth profile.
   */
  private function emailHasRestrictedPublicAuthProfile(string $email): bool {
    try {
      $users = $this->entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['mail' => $email]);
    }
    catch (\Throwable) {
      return TRUE;
    }

    if (empty($users)) {
      return FALSE;
    }

    $user = reset($users);
    return $this->roleListHasRestrictedPublicAuthProfile($user->getRoles());
  }

  /**
   * Returns TRUE when a role list is outside the citizen public v1 profile.
   */
  private function roleListHasRestrictedPublicAuthProfile(mixed $roles): bool {
    if (!is_array($roles)) {
      return TRUE;
    }

    foreach ($roles as $role) {
      if (!is_string($role)) {
        continue;
      }
      if (in_array($role, BeantragtRoles::STAFF_ROLES, TRUE)
        || in_array($role, BeantragtRoles::SERVICE_ACCOUNT_ROLES, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Creates a server-side public v1 auth challenge bound to this session.
   */
  private function createV1Challenge(Request $request, string $email): ?string {
    if (!$request->hasSession()) {
      return NULL;
    }

    $challenge_id = bin2hex(random_bytes(32));
    $request->getSession()->set(self::V1_CHALLENGE_SESSION_KEY, [
      'id' => hash('sha256', $challenge_id),
      'email' => hash('sha256', $email),
      'created' => time(),
    ]);

    return $challenge_id;
  }

  /**
   * Validates the public v1 auth challenge for verify-code.
   */
  private function validateV1Challenge(Request $request, ?string $email): bool {
    if ($email === NULL || !$request->hasSession()) {
      return FALSE;
    }

    $data = json_decode($request->getContent(), TRUE);
    $challenge_id = is_array($data) && is_string($data['challengeId'] ?? NULL)
      ? trim($data['challengeId'])
      : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $challenge_id)) {
      return FALSE;
    }

    $challenge = $request->getSession()->get(self::V1_CHALLENGE_SESSION_KEY);
    if (!is_array($challenge)) {
      return FALSE;
    }

    $created = (int) ($challenge['created'] ?? 0);
    $stored_id = is_string($challenge['id'] ?? NULL) ? $challenge['id'] : '';
    $stored_email = is_string($challenge['email'] ?? NULL) ? $challenge['email'] : '';

    return $created >= time() - self::V1_CHALLENGE_TTL
      && hash_equals($stored_id, hash('sha256', $challenge_id))
      && hash_equals($stored_email, hash('sha256', $email));
  }

  /**
   * Clears the public v1 auth challenge after successful verification.
   */
  private function clearV1Challenge(Request $request): void {
    if ($request->hasSession()) {
      $request->getSession()->remove(self::V1_CHALLENGE_SESSION_KEY);
    }
  }

  /**
   * Decodes a JsonResponse payload.
   *
   * @return array<string, mixed>
   *   Decoded JSON object, or an empty array if the response is not JSON.
   */
  private function decodeJsonResponse(JsonResponse $response): array {
    $payload = json_decode((string) $response->getContent(), TRUE);
    return is_array($payload) ? $payload : [];
  }

  /**
   * Extracts the legacy error message.
   *
   * @param array<string, mixed> $payload
   *   Legacy response payload.
   * @param string $fallback
   *   Fallback message.
   */
  private function legacyErrorMessage(array $payload, string $fallback): string {
    $message = $payload['error'] ?? $payload['message'] ?? NULL;
    return is_string($message) && trim($message) !== '' ? $message : $fallback;
  }

  /**
   * Maps legacy auth errors into stable public v1 codes.
   */
  private function authErrorCode(string $operation, int $status, string $message): string {
    $normalized = strtolower($message);

    if (str_contains($normalized, 'email is required')) {
      return 'auth.email_required';
    }
    if (str_contains($normalized, 'email and code are required')) {
      return 'auth.credentials_required';
    }
    if (str_contains($normalized, 'invalid email')) {
      return 'auth.email_invalid';
    }
    if (str_contains($normalized, 'invalid code format')) {
      return 'auth.code_invalid';
    }
    if (str_contains($normalized, 'csrf')) {
      return 'auth.csrf_invalid';
    }
    if (str_contains($normalized, 'challenge')) {
      return 'auth.challenge_invalid';
    }
    if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
      return 'auth.rate_limited';
    }
    if ($status === Response::HTTP_UNAUTHORIZED) {
      return 'auth.invalid_code';
    }

    return match ($operation) {
      'request_code' => 'auth.request_failed',
      'verify_code' => 'auth.verify_failed',
      'logout' => 'auth.logout_failed',
      default => 'auth.failed',
    };
  }

  /**
   * Creates a private no-store public v1 JSON response.
   *
   * @param array<string, mixed> $payload
   *   Response payload.
   * @param int $status
   *   HTTP status code.
   */
  private function v1PrivateResponse(array $payload, int $status): JsonResponse {
    $response = new JsonResponse($payload, $status);
    return $this->noStore($response);
  }

  /**
   * Creates a public v1 error response.
   */
  private function v1ErrorResponse(string $code, string $message, int $status, array $details = []): JsonResponse {
    $error = [
      'code' => $code,
      'message' => $message,
    ];
    if ($details !== []) {
      $error['details'] = $details;
    }

    return $this->v1PrivateResponse([
      'error' => [
        ...$error,
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
      ],
    ], $status);
  }

  /**
   * Returns the stable error for non-citizen public v1 auth profiles.
   */
  private function unsupportedPublicAuthProfileResponse(): JsonResponse {
    return $this->v1ErrorResponse(
      'auth.unsupported_profile',
      'This auth profile is not supported by Public API v1.',
      Response::HTTP_FORBIDDEN,
    );
  }

  /**
   * Marks the JSON response as private and not cacheable.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Copies cookies from an inner response to a projected response.
   */
  private function copyCookies(JsonResponse $from, JsonResponse $to): void {
    foreach ($from->headers->getCookies() as $cookie) {
      $to->headers->setCookie($cookie);
    }
  }

}
