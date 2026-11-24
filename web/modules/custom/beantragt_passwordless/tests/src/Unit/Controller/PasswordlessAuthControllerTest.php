<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_passwordless\Unit\Controller;

use Drupal\beantragt_passwordless\Controller\PasswordlessAuthController;
use Drupal\beantragt_passwordless\Service\OtpService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests passwordless controller behavior.
 *
 * @coversDefaultClass \Drupal\beantragt_passwordless\Controller\PasswordlessAuthController
 * @group beantragt_passwordless
 */
final class PasswordlessAuthControllerTest extends UnitTestCase {

  /**
   * Environment values to restore after each test.
   *
   * The PHPUnit process is shared with later tests, including functional
   * tests that read NUXT_DRUPAL_SERVICE_USER, so nothing may be left unset.
   *
   * @var array<string, string|false>
   */
  private array $previousEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['NUXT_DRUPAL_SERVICE_USER', 'NUXT_DRUPAL_SERVICE_PASSWORD', 'IS_DDEV_PROJECT'] as $name) {
      $this->previousEnv[$name] = getenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->previousEnv as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Direct legacy verify-code calls require the session-bound v1 challenge.
   *
   * @covers ::verifyCode
   */
  public function testLegacyVerifyCodeRequiresSessionChallenge(): void {
    $this->installUserStorage();

    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->never())->method('verifyCode');

    $request = $this->jsonRequest('/auth/verify-code', [
      'email' => 'citizen@example.org',
      'code' => '123456',
    ]);
    $this->attachSession($request);

    $response = $this->controller($otp)
      ->verifyCode($request);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertStringContainsString('passwordless challenge', $payload['error']);
  }

  /**
   * Direct legacy verify-code calls consume a valid v1 challenge on failure.
   *
   * @covers ::verifyCode
   */
  public function testLegacyVerifyCodeClearsSessionChallengeAfterOtpFailure(): void {
    $otp = $this->createMock(OtpService::class);
    $otp->method('getCodeLength')->willReturn(6);
    $otp->expects($this->once())
      ->method('verifyCode')
      ->with('citizen@example.org', '123456')
      ->willReturn([
        'success' => FALSE,
        'error' => 'Invalid or expired code',
      ]);

    $challenge_id = str_repeat('d', 64);
    $request = $this->jsonRequest('/auth/verify-code', [
      'email' => 'citizen@example.org',
      'code' => '123456',
      'challengeId' => $challenge_id,
    ]);
    $this->attachSession($request, 'citizen@example.org', $challenge_id);

    $response = $this->controller($otp)
      ->verifyCode($request);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    $this->assertFalse($request->getSession()->has('beantragt_passwordless.v1_challenge'));
  }

  /**
   * Database errors cannot put OTPs or email addresses in controller logs.
   *
   * @covers ::verifyCode
   */
  public function testVerifyCodeLogsOnlyExceptionClass(): void {
    $otp = $this->createMock(OtpService::class);
    $otp->method('getCodeLength')->willReturn(6);
    $otp->method('verifyCode')->willThrowException(new \RuntimeException('SQL contains citizen@example.org and 123456'));
    $challenge_id = str_repeat('e', 64);
    $request = $this->jsonRequest('/auth/verify-code', [
      'email' => 'citizen@example.org',
      'code' => '123456',
      'challengeId' => $challenge_id,
    ]);
    $this->attachSession($request, 'citizen@example.org', $challenge_id);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error')->with(
      'Failed to verify OTP: @class', ['@class' => \RuntimeException::class],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('beantragt_passwordless')->willReturn($logger);
    $controller = $this->controller($otp);
    $controller->setLoggerFactory($factory);

    $response = $controller->verifyCode($request);

    self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    self::assertSame(['error' => 'An error occurred during authentication'], json_decode((string) $response->getContent(), TRUE));
    self::assertFalse($request->getSession()->has('beantragt_passwordless.v1_challenge'));
  }

  /**
   * Tenant-scoped code requests forward a validated jurisdiction slug.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeForwardsJurisdictionSlug(): void {
    $this->installUserStorage();
    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->once())
      ->method('requestCode')
      ->with('citizen@example.org', 'de', 'musterstadt')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Verification code ready.',
        'expiresIn' => 600,
        'codeLength' => 6,
      ]);

    $request = $this->jsonRequest('/auth/request-code', [
      'email' => 'citizen@example.org',
      'langcode' => 'de',
      'jurisdiction' => 'musterstadt',
    ]);
    $this->attachSession($request);

    $response = $this->controller($otp)->requestCode($request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Public v1 code requests use the standard envelope.
   *
   * @covers ::requestCodeV1
   */
  public function testRequestCodeV1ReturnsPublicEnvelope(): void {
    $this->installUserStorage();
    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->once())
      ->method('requestCode')
      ->with('citizen@example.org', 'de')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Verification code ready.',
        'expiresIn' => 600,
        'codeLength' => 6,
      ]);

    $request = $this->jsonRequest('/api/v1/auth/request-code', [
      'email' => 'citizen@example.org',
      'langcode' => 'de',
    ]);
    $this->attachSession($request);

    $response = $this->controller($otp)
      ->requestCodeV1($request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth_code_request', $payload['data']['type']);
    $this->assertSame('email', $payload['data']['delivery']);
    $this->assertSame(600, $payload['data']['expiresIn']);
    $this->assertSame(6, $payload['data']['codeLength']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['data']['challengeId']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertSame('authCodeRequest', $payload['meta']['resource']);
    $this->assertArrayNotHasKey('success', $payload);
  }

  /**
   * Public v1 invalid email responses use stable error codes.
   *
   * @covers ::requestCodeV1
   */
  public function testRequestCodeV1InvalidEmailUsesErrorEnvelope(): void {
    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->never())->method('requestCode');

    $response = $this->controller($otp)
      ->requestCodeV1($this->jsonRequest('/api/v1/auth/request-code', [
        'email' => 'not an email',
      ]));

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth.email_invalid', $payload['error']['code']);
    $this->assertSame('Invalid email format', $payload['error']['message']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Public v1 code requests reject staff or service-account profiles.
   *
   * @covers ::requestCodeV1
   */
  public function testRequestCodeV1RejectsPrivilegedProfiles(): void {
    $this->installUserStorage(rolesByEmail: [
      'staff@example.org' => ['authenticated', 'department_admin'],
    ]);

    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->never())->method('requestCode');

    $request = $this->jsonRequest('/api/v1/auth/request-code', [
      'email' => 'staff@example.org',
    ]);
    $this->attachSession($request);

    $response = $this->controller($otp)
      ->requestCodeV1($request);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Public v1 verification projects only external user identifiers.
   *
   * @covers ::verifyCodeV1
   */
  public function testVerifyCodeV1ReturnsSessionWithoutNumericIds(): void {
    $this->installUserStorage();
    $otp = $this->createMock(OtpService::class);
    $otp->method('getCodeLength')->willReturn(6);
    $otp->expects($this->once())
      ->method('verifyCode')
      ->with('citizen@example.org', '123456')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Authentication successful',
        'user' => [
          'uid' => 123,
          'uuid' => '0fd39160-68e0-42aa-a5ea-f22c4545f877',
          'name' => 'citizen@example.org',
          'email' => 'citizen@example.org',
          'roles' => ['authenticated'],
          'applicant_id' => 456,
        ],
      ]);

    $challenge_id = str_repeat('a', 64);
    $request = $this->jsonRequest('/api/v1/auth/verify-code', [
      'email' => 'citizen@example.org',
      'code' => '123456',
      'challengeId' => $challenge_id,
    ]);
    $this->attachSession($request, 'citizen@example.org', $challenge_id);

    $response = $this->controller($otp)
      ->verifyCodeV1($request);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth_session', $payload['data']['type']);
    $this->assertTrue($payload['data']['authenticated']);
    $this->assertSame('0fd39160-68e0-42aa-a5ea-f22c4545f877', $payload['data']['user']['id']);
    $this->assertSame('citizen@example.org', $payload['data']['user']['email']);
    $this->assertSame(['authenticated'], $payload['data']['user']['roles']);
    $this->assertSame('passwordless', $payload['data']['user']['authProvider']);
    $this->assertNull($payload['data']['user']['assuranceLevel']);
    $this->assertSame('authSession', $payload['meta']['resource']);

    $json = (string) $response->getContent();
    $this->assertStringNotContainsString('uid', $json);
    $this->assertStringNotContainsString('applicant_id', $json);
    $this->assertStringNotContainsString('"123"', $json);
    $this->assertStringNotContainsString('"456"', $json);
  }

  /**
   * Public v1 verification normalizes mixed-case email challenges.
   *
   * @covers ::requestCodeV1
   * @covers ::verifyCodeV1
   */
  public function testVerifyCodeV1AcceptsMixedCaseEmailChallenge(): void {
    $this->installUserStorage();

    $otp = $this->createMock(OtpService::class);
    $otp->method('getCodeLength')->willReturn(6);
    $otp->expects($this->once())
      ->method('requestCode')
      ->with('citizen@example.org', '')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Verification code ready.',
        'expiresIn' => 600,
        'codeLength' => 6,
      ]);
    $otp->expects($this->once())
      ->method('verifyCode')
      ->with('citizen@example.org', '123456')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Authentication successful',
        'user' => [
          'uid' => 123,
          'uuid' => '0fd39160-68e0-42aa-a5ea-f22c4545f877',
          'name' => 'citizen@example.org',
          'email' => 'citizen@example.org',
          'roles' => ['authenticated'],
        ],
      ]);

    $request_code = $this->jsonRequest('/api/v1/auth/request-code', [
      'email' => 'Citizen@Example.ORG',
    ]);
    $this->attachSession($request_code);

    $code_response = $this->controller($otp)
      ->requestCodeV1($request_code);
    $code_payload = json_decode((string) $code_response->getContent(), TRUE);

    $verify = $this->jsonRequest('/api/v1/auth/verify-code', [
      'email' => 'Citizen@Example.ORG',
      'code' => '123456',
      'challengeId' => $code_payload['data']['challengeId'],
    ]);
    $verify->setSession($request_code->getSession());

    $response = $this->controller($otp)
      ->verifyCodeV1($verify);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth_session', $payload['data']['type']);
  }

  /**
   * Public v1 verification backfills UUIDs for legacy auth payloads.
   *
   * @covers ::verifyCodeV1
   */
  public function testVerifyCodeV1BackfillsUserUuidFromStorage(): void {
    $this->installUserStorage('123', '0fd39160-68e0-42aa-a5ea-f22c4545f877');

    $otp = $this->createMock(OtpService::class);
    $otp->method('getCodeLength')->willReturn(6);
    $otp->expects($this->once())
      ->method('verifyCode')
      ->willReturn([
        'success' => TRUE,
        'message' => 'Authentication successful',
        'user' => [
          'uid' => 123,
          'name' => 'citizen@example.org',
          'email' => 'citizen@example.org',
          'roles' => ['authenticated'],
          'applicant_id' => 456,
        ],
      ]);

    $challenge_id = str_repeat('b', 64);
    $request = $this->jsonRequest('/api/v1/auth/verify-code', [
      'email' => 'citizen@example.org',
      'code' => '123456',
      'challengeId' => $challenge_id,
    ]);
    $this->attachSession($request, 'citizen@example.org', $challenge_id);

    $response = $this->controller($otp)
      ->verifyCodeV1($request);

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('0fd39160-68e0-42aa-a5ea-f22c4545f877', $payload['data']['user']['id']);
    $this->assertStringNotContainsString('applicant_id', (string) $response->getContent());
  }

  /**
   * Public v1 verification requires the session-bound challenge.
   *
   * @covers ::verifyCodeV1
   */
  public function testVerifyCodeV1RejectsMissingChallengeBeforeOtp(): void {
    $this->installUserStorage();

    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->never())->method('verifyCode');

    $request = $this->jsonRequest('/api/v1/auth/verify-code', [
      'email' => 'citizen@example.org',
      'code' => '123456',
    ]);
    $this->attachSession($request);

    $response = $this->controller($otp)
      ->verifyCodeV1($request);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth.challenge_invalid', $payload['error']['code']);
  }

  /**
   * Public v1 verification rejects privileged profiles before OTP verify.
   *
   * @covers ::verifyCodeV1
   */
  public function testVerifyCodeV1RejectsPrivilegedProfilesBeforeOtp(): void {
    $this->installUserStorage(rolesByEmail: [
      'staff@example.org' => ['authenticated', 'case_worker'],
    ]);

    $otp = $this->createMock(OtpService::class);
    $otp->expects($this->never())->method('verifyCode');

    $challenge_id = str_repeat('c', 64);
    $request = $this->jsonRequest('/api/v1/auth/verify-code', [
      'email' => 'staff@example.org',
      'code' => '123456',
      'challengeId' => $challenge_id,
    ]);
    $this->attachSession($request, 'staff@example.org', $challenge_id);

    $response = $this->controller($otp)
      ->verifyCodeV1($request);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Public v1 status returns an unauthenticated session envelope.
   *
   * @covers ::statusV1
   */
  public function testStatusV1AnonymousUsesSessionEnvelope(): void {
    $this->installUserStorage();
    $otp = $this->createMock(OtpService::class);

    $response = $this->controller($otp)
      ->statusV1(Request::create('/api/v1/auth/status', 'GET'));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth_session', $payload['data']['type']);
    $this->assertFalse($payload['data']['authenticated']);
    $this->assertNull($payload['data']['user']);
    $this->assertSame('authSession', $payload['meta']['resource']);
  }

  /**
   * Public v1 status rejects privileged authenticated profiles.
   *
   * @covers ::statusV1
   */
  public function testStatusV1RejectsPrivilegedProfiles(): void {
    $this->installUserStorage('123', '0fd39160-68e0-42aa-a5ea-f22c4545f877');
    $otp = $this->createMock(OtpService::class);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn(123);
    $account->method('getAccountName')->willReturn('staff@example.org');
    $account->method('getEmail')->willReturn('staff@example.org');
    $account->method('getRoles')->willReturn(['authenticated', 'department_admin']);

    $response = $this->controller($otp, $account)
      ->statusV1(Request::create('/api/v1/auth/status', 'GET'));

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
    $this->assertStringNotContainsString('department_admin', (string) $response->getContent());
  }

  /**
   * Public v1 logout returns JSON errors for missing CSRF tokens.
   *
   * @covers ::logoutV1
   */
  public function testLogoutV1MissingCsrfUsesErrorEnvelope(): void {
    $otp = $this->createMock(OtpService::class);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(FALSE);

    $response = $this->controller($otp, $account, csrf: $csrf)
      ->logoutV1(Request::create('/api/v1/auth/logout', 'POST'));

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('auth.csrf_invalid', $payload['error']['code']);
    $this->assertSame('CSRF token validation failed', $payload['error']['message']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Creates a JSON request with deterministic client IP.
   *
   * @param string $path
   *   Request path.
   * @param array<string, mixed> $payload
   *   Request payload.
   */
  private function jsonRequest(string $path, array $payload): Request {
    return Request::create(
      $path,
      'POST',
      [],
      [],
      [],
      [
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '198.51.100.10',
      ],
      json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Installs enough container state for ControllerBase::entityTypeManager().
   */
  private function installUserStorage(
    ?string $uid = NULL,
    ?string $uuid = NULL,
    array $rolesByEmail = [],
  ): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('uuid')->willReturn($uuid ?? '0fd39160-68e0-42aa-a5ea-f22c4545f877');
    $user->method('getRoles')->willReturn(['authenticated']);

    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')
      ->willReturnCallback(
        static fn(int|string|null $loadedUid): ?UserInterface => $uid !== NULL && (string) $loadedUid === $uid ? $user : NULL,
      );
    $user_storage->method('loadByProperties')
      ->willReturnCallback(function (array $properties) use ($rolesByEmail): array {
        $email = strtolower((string) ($properties['mail'] ?? ''));
        if (!isset($rolesByEmail[$email])) {
          return [];
        }

        $matched_user = $this->createMock(UserInterface::class);
        $matched_user->method('uuid')->willReturn('0fd39160-68e0-42aa-a5ea-f22c4545f877');
        $matched_user->method('getRoles')->willReturn($rolesByEmail[$email]);
        return [$matched_user];
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('user')
      ->willReturn($user_storage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Attaches a session and optionally stores a matching v1 challenge.
   */
  private function attachSession(Request $request, ?string $email = NULL, ?string $challengeId = NULL): void {
    $session = new Session(new MockArraySessionStorage());
    if ($email !== NULL && $challengeId !== NULL) {
      $session->set('beantragt_passwordless.v1_challenge', [
        'id' => hash('sha256', $challengeId),
        'email' => hash('sha256', strtolower($email)),
        'created' => time(),
      ]);
    }
    $request->setSession($session);
  }

  /**
   * Builds the controller with permissive rate-limit config.
   */
  private function controller(
    OtpService $otp,
    ?AccountInterface $account = NULL,
    ?FloodInterface $flood = NULL,
    ?CsrfTokenGenerator $csrf = NULL,
  ): PasswordlessAuthController {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => match ($key) {
        'request_limit_per_email' => 3,
        'request_limit_per_ip' => 10,
        'verify_lockout_attempts' => 5,
        'verify_lockout_duration' => 900,
        default => NULL,
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('beantragt_passwordless.settings')
      ->willReturn($config);

    $flood ??= $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    if ($account === NULL) {
      $account = $this->createMock(AccountInterface::class);
      $account->method('isAuthenticated')->willReturn(FALSE);
      $account->method('id')->willReturn(0);
      $account->method('getAccountName')->willReturn('');
      $account->method('getEmail')->willReturn('');
    }

    return new PasswordlessAuthController(
      $otp,
      $account,
      $flood,
      $config_factory,
      $this->createMock(SessionConfigurationInterface::class),
      $csrf ?? $this->createMock(CsrfTokenGenerator::class),
    );
  }

}
