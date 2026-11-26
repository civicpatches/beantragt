<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Service;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\beantragt_sso\Service\SsoClientFactory;
use Drupal\beantragt_sso\Service\SsoIdentityLinker;
use Drupal\beantragt_sso\Service\SsoLoginService;
use Drupal\beantragt_sso\Service\SsoProviderManager;
use Drupal\beantragt_sso\Service\SsoReplayCache;
use Drupal\beantragt_sso\Service\SsoRequestCorrelation;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\ValidationError;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests SSO login coordination.
 *
 * @group beantragt_sso
 */
final class SsoLoginServiceTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_SSO_MOCK');
    putenv('IS_DDEV_PROJECT');
    parent::tearDown();
  }

  /**
   * ACS processing rejects SP-initiated responses when the request id is gone.
   */
  public function testAcsRequiresStoredRequestId(): void {
    $service = $this->loginService();
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'SAMLResponse' => 'dummy-response',
      'RelayState' => '/meine-antraege',
    ]);
    $session = new Session(new MockArraySessionStorage());

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Missing SSO request correlation.');
    $service->processAcs('bundid', $request, $session, NULL);
  }

  /**
   * Only dev HTTP callbacks without a cookie can consume a legacy session ID.
   */
  #[DataProvider('sessionFallbackCases')]
  public function testSessionFallback(string $environment, string $scheme, bool $with_cookie, bool $allowed): void {
    $previous = getenv('BEANTRAGT_ENV');
    putenv('BEANTRAGT_ENV=' . $environment);
    try {
      $service = $this->loginService();
      $request = Request::create($scheme . '://localhost/auth/sso/bundid/acs', 'POST');
      if ($with_cookie) {
        $request->cookies->set(SsoRequestCorrelation::cookieName('bundid'), '');
      }
      $session = new Session(new MockArraySessionStorage());
      $session->set('beantragt_sso.bundid.request_id', 'request-123');
      $session->set('beantragt_sso.bundid.relay_state', '/meine-antraege');
      $expected = $allowed ? ['request_id' => 'request-123', 'relay_state' => '/meine-antraege'] : NULL;
      $this->assertSame($expected, $service->consumeRequestCorrelation('bundid', $request, $session));
      $this->assertNull($service->consumeRequestCorrelation('bundid', $request, $session));
    }
    finally {
      putenv('BEANTRAGT_ENV' . ($previous === FALSE ? '' : '=' . $previous));
    }
  }

  /**
   * Returns dev and production fallback cases.
   */
  public static function sessionFallbackCases(): array {
    return [
      'dev http' => ['dev', 'http', FALSE, TRUE],
      'dev http with invalid cookie' => ['dev', 'http', TRUE, FALSE],
      'dev https' => ['dev', 'https', FALSE, FALSE],
      'production http' => ['production', 'http', FALSE, FALSE],
      'production https' => ['production', 'https', FALSE, FALSE],
    ];
  }

  /**
   * Authenticated responses with empty IDs are rejected explicitly.
   */
  public function testAcsRejectsEmptyMessageOrAssertionId(): void {
    $auth = new class extends Auth {

      /**
       * Skips the parent constructor; the stub needs no real SAML settings.
       */
      public function __construct() {
      }

      /**
       * {@inheritdoc}
       */
      public function processResponse($requestId = NULL) {
      }

      /**
       * {@inheritdoc}
       */
      public function getErrors() {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function isAuthenticated() {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function getLastMessageId() {
        return '';
      }

      /**
       * {@inheritdoc}
       */
      public function getLastAssertionId() {
        return '';
      }

    };

    $service = $this->loginService($auth);
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'SAMLResponse' => 'valid-but-idless',
      'RelayState' => '/meine-antraege',
    ]);
    $session = new Session(new MockArraySessionStorage());

    $exception = NULL;
    try {
      $service->processAcs('bundid', $request, $session, 'request-123');
    }
    catch (\Throwable $caught) {
      $exception = $caught;
    }

    $this->assertInstanceOf(AccessDeniedHttpException::class, $exception);
    $this->assertSame('SSO response is missing required identifiers.', $exception->getMessage());
  }

  /**
   * The SAML library's response text is never propagated beyond the service.
   */
  public function testAcsDoesNotExposeSamlFailureReason(): void {
    $auth = new class extends Auth {

      /**
       * Skips the parent constructor; the stub needs no real SAML settings.
       */
      public function __construct() {
      }

      /**
       * {@inheritdoc}
       */
      public function processResponse($requestId = NULL) {
      }

      /**
       * {@inheritdoc}
       */
      public function getErrors() {
        return ['invalid_response'];
      }

      /**
       * {@inheritdoc}
       */
      public function getLastErrorReason() {
        return 'hostile-saml-status database-error-sentinel';
      }

    };
    $service = $this->loginService($auth);
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'SAMLResponse' => 'malformed',
    ]);
    $session = new Session(new MockArraySessionStorage());

    try {
      $service->processAcs('bundid', $request, $session, 'request-123');
      self::fail('Expected malformed SAML response to be rejected.');
    }
    catch (AccessDeniedHttpException $exception) {
      self::assertSame('Invalid SSO response.', $exception->getMessage());
      self::assertStringNotContainsString('hostile-saml-status', $exception->getMessage());
      self::assertStringNotContainsString('database-error-sentinel', $exception->getMessage());
    }
  }

  /**
   * Signature rejection has a bounded category without the library reason.
   */
  public function testBadSignatureHasSafeFailureCode(): void {
    foreach ([ValidationError::NO_SIGNATURE_FOUND, ValidationError::INVALID_SIGNATURE,
      ValidationError::NO_SIGNED_MESSAGE, ValidationError::NO_SIGNED_ASSERTION,
    ] as $error_code) {
      $auth = $this->getMockBuilder(Auth::class)->disableOriginalConstructor()->getMock();
      $auth->method('getErrors')->willReturn(['invalid_response']);
      $auth->method('getLastErrorException')->willReturn(new ValidationError('hostile-saml-status', $error_code));
      $service = $this->loginService($auth);
      $request = Request::create('/auth/sso/bundid/acs', 'POST', ['SAMLResponse' => 'invalid']);
      $session = new Session(new MockArraySessionStorage());
      try {
        $service->processAcs('bundid', $request, $session, 'request-123');
        $this->fail('Bad signature was accepted.');
      }
      catch (SsoAuthenticationException $exception) {
        $this->assertSame('bad_signature', $exception->failureCode);
        $this->assertSame('Invalid SSO signature.', $exception->getMessage());
      }
    }
  }

  /**
   * Dev mock login starts at a visible mock identity provider page.
   */
  public function testMockLoginStartsAtVisibleMockIdp(): void {
    putenv('BEANTRAGT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');

    $service = $this->loginService();
    $login = $service->startLogin('bundid_mock', '/meine-antraege', Request::create('/auth/sso/bundid_mock/login'));

    $this->assertSame('/auth/sso/bundid_mock/mock-idp?RelayState=%2Fmeine-antraege', $login['url']);
    $this->assertNull($login['cookie']);
  }

  /**
   * Builds the service with an optional SAML toolkit stub.
   */
  private function loginService(?Auth $auth = NULL): SsoLoginService {
    $providerManager = new SsoProviderManager($this->configFactory());
    $clientFactory = new SsoClientFactory($providerManager, new RequestStack());
    if ($auth !== NULL) {
      $clientFactory = $this->createMock(SsoClientFactory::class);
      $clientFactory->method('auth')->willReturn($auth);
    }

    $replayCache = new SsoReplayCache(
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
    );
    $identityLinker = new SsoIdentityLinker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ApplicantManager::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->createMock(VerifiedAccountMail::class),
      $this->createMock(LockBackendInterface::class),
    );

    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(KeyValueStoreExpirableInterface::class));
    return new SsoLoginService(
      $providerManager,
      $clientFactory,
      $replayCache,
      $identityLinker,
      $this->createMock(LoggerInterface::class),
      new SsoRequestCorrelation($factory, $this->createMock(LockBackendInterface::class), $this->createMock(TimeInterface::class)),
    );
  }

  /**
   * Builds config for an enabled real SSO provider.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        if ($key === 'providers.bundid') {
          return [
            'enabled' => TRUE,
            'profile' => 'bundid',
          ];
        }
        if ($key === 'providers.bundid_mock') {
          return [
            'enabled' => TRUE,
            'profile' => 'bundid_mock',
            'mock' => TRUE,
          ];
        }
        if ($key === 'system.site') {
          return [
            'uuid' => 'test-site-uuid',
          ];
        }
        return NULL;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->willReturn($config);

    return $factory;
  }

}
