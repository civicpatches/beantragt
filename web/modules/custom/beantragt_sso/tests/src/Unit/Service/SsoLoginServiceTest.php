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
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\ValidationError;
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
    $service->processAcs('bundid', $request, $session);
  }

  /**
   * Failed ACS attempts consume the stored request id.
   */
  public function testFailedAcsConsumesStoredRequestId(): void {
    $service = $this->loginService();
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'SAMLResponse' => 'not-valid-saml',
      'RelayState' => '/meine-antraege',
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('beantragt_sso.bundid.request_id', 'request-123');

    $exception = NULL;
    try {
      $service->processAcs('bundid', $request, $session);
    }
    catch (\Throwable $caught) {
      $exception = $caught;
    }

    $this->assertInstanceOf(\Throwable::class, $exception);
    $this->assertFalse($session->has('beantragt_sso.bundid.request_id'));
  }

  /**
   * Malformed ACS posts also consume the stored request id.
   */
  public function testMalformedAcsConsumesStoredRequestId(): void {
    $service = $this->loginService();
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'RelayState' => '/meine-antraege',
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('beantragt_sso.bundid.request_id', 'request-123');

    $exception = NULL;
    try {
      $service->processAcs('bundid', $request, $session);
    }
    catch (\Throwable $caught) {
      $exception = $caught;
    }

    $this->assertInstanceOf(\Throwable::class, $exception);
    $this->assertFalse($session->has('beantragt_sso.bundid.request_id'));
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

    $service = $this->loginServiceWithAuth($auth);
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'SAMLResponse' => 'valid-but-idless',
      'RelayState' => '/meine-antraege',
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('beantragt_sso.bundid.request_id', 'request-123');

    $exception = NULL;
    try {
      $service->processAcs('bundid', $request, $session);
    }
    catch (\Throwable $caught) {
      $exception = $caught;
    }

    $this->assertInstanceOf(AccessDeniedHttpException::class, $exception);
    $this->assertSame('SSO response is missing required identifiers.', $exception->getMessage());
    $this->assertFalse($session->has('beantragt_sso.bundid.request_id'));
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
    $service = $this->loginServiceWithAuth($auth);
    $request = Request::create('/auth/sso/bundid/acs', 'POST', [
      'SAMLResponse' => 'malformed',
    ]);
    $session = new Session(new MockArraySessionStorage());
    $session->set('beantragt_sso.bundid.request_id', 'request-123');

    try {
      $service->processAcs('bundid', $request, $session);
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
      $service = $this->loginServiceWithAuth($auth);
      $request = Request::create('/auth/sso/bundid/acs', 'POST', ['SAMLResponse' => 'invalid']);
      $session = new Session(new MockArraySessionStorage());
      $session->set('beantragt_sso.bundid.request_id', 'request-123');
      try {
        $service->processAcs('bundid', $request, $session);
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
    $session = new Session(new MockArraySessionStorage());

    $this->assertSame(
      '/auth/sso/bundid_mock/mock-idp?RelayState=%2Fmeine-antraege',
      $service->startLogin('bundid_mock', '/meine-antraege', $session),
    );
  }

  /**
   * ACS redirects use the RelayState captured at login start.
   */
  public function testRelayStateIsConsumedFromStartedLogin(): void {
    putenv('BEANTRAGT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');

    $service = $this->loginService();
    $session = new Session(new MockArraySessionStorage());

    $service->startLogin('bundid_mock', '/meine-antraege', $session);

    $this->assertSame('/meine-antraege', $service->consumeRelayState('bundid_mock', $session));
    $this->assertSame('/', $service->consumeRelayState('bundid_mock', $session));
  }

  /**
   * Builds the service with real collaborators that are not reached here.
   */
  private function loginService(): SsoLoginService {
    $providerManager = new SsoProviderManager($this->configFactory());
    $clientFactory = new SsoClientFactory($providerManager, new RequestStack());
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

    return new SsoLoginService(
      $providerManager,
      $clientFactory,
      $replayCache,
      $identityLinker,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds the service with a client factory that returns a stub Auth.
   *
   * @param \OneLogin\Saml2\Auth $auth
   *   Pre-built Auth stub returned for any provider.
   */
  private function loginServiceWithAuth(Auth $auth): SsoLoginService {
    $providerManager = new SsoProviderManager($this->configFactory());
    $clientFactory = $this->getMockBuilder(SsoClientFactory::class)
      ->setConstructorArgs([$providerManager, new RequestStack()])
      ->onlyMethods(['auth'])
      ->getMock();
    $clientFactory->method('auth')->willReturn($auth);

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

    return new SsoLoginService(
      $providerManager,
      $clientFactory,
      $replayCache,
      $identityLinker,
      $this->createMock(LoggerInterface::class),
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
