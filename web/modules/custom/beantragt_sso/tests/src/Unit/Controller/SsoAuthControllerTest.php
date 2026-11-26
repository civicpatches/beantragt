<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Controller;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\beantragt_sso\Controller\SsoAuthController;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\beantragt_sso\Service\SsoClientFactory;
use Drupal\beantragt_sso\Service\SsoIdentityLinker;
use Drupal\beantragt_sso\Service\SsoLoginService;
use Drupal\beantragt_sso\Service\SsoProviderManager;
use Drupal\beantragt_sso\Service\SsoRelayStateValidator;
use Drupal\beantragt_sso\Service\SsoReplayCache;
use Drupal\beantragt_sso\Service\SsoRequestCorrelation;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests SSO auth controller redirects.
 *
 * @group beantragt_sso
 */
final class SsoAuthControllerTest extends UnitTestCase {

  /**
   * Failed ACS responses redirect to the stored target with a public code.
   */
  public function testAcsFailureRedirectsToStoredRelayState(): void {
    $logger = $this->safeFailureLogger('acs_rejected', 'warning');
    $target = 'https://beantragt.ddev.site:3001/musterstadt/meine-antraege';
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $key = str_repeat('a', 64);
    $store->method('get')->with(hash('sha256', $key))->willReturn([
      'provider' => 'bundid',
      'request_id' => 'request-id',
      'relay_state' => $target,
      'expires' => 600,
    ]);
    $store->expects($this->once())->method('delete')->with(hash('sha256', $key));
    $controller = $this->controller($logger, $store);
    $request = Request::create('/auth/sso/bundid/acs', 'POST');
    $request->cookies->set(SsoRequestCorrelation::cookieName('bundid'), $key);
    $request->setSession(new Session(new MockArraySessionStorage()));

    $response = $controller->acs($request, 'bundid');

    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame(
      $target . '?sso_error=acs_rejected',
      $response->headers->get('Location'),
    );
    $this->assertSame('', $response->getContent());
  }

  /**
   * Initiation failures never log attacker or database exception contents.
   */
  public function testInitiationLogsOnlyCodeAndCorrelation(): void {
    $logger = $this->safeFailureLogger('login_start_failed', 'error');
    $request = Request::create('/auth/sso/bundid/login?returnTo=%2Fmy-applications');
    $session = $this->createMock(Session::class);
    $session->method('set')->willThrowException(new \RuntimeException('database-error-sentinel citizen@example.test'));
    $request->setSession($session);
    $response = $this->controller($logger)->login($request, 'bundid');
    $this->assertSame('/my-applications?sso_error=login_failed', $response->headers->get('Location'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('', $response->getContent());
  }

  /**
   * Mock callback failures receive the same content-free log treatment.
   */
  public function testMockLogsOnlyCodeAndCorrelation(): void {
    $logger = $this->safeFailureLogger('mock_login_failed', 'warning');
    $request = Request::create('/auth/sso/bundid_mock/mock-login');
    $session = $this->createMock(Session::class);
    $session->method('get')->willThrowException(new \RuntimeException('hostile-saml-status database-error-sentinel citizen@example.test'));
    $request->setSession($session);
    $response = $this->mockLogin($request, $logger);
    $this->assertSame('/auth/login?sso_error=login_failed', $response->headers->get('Location'));
  }

  /**
   * Redirects expose only public categories and logs keep internal codes.
   */
  public function testTypedFailuresReachRedirect(): void {
    foreach ([
      'identity_disabled' => 'account_ineligible',
      'account_ineligible' => 'account_ineligible',
      'account_privileged' => 'account_privileged',
      'identity_busy' => 'unavailable',
      'bad_signature' => 'acs_rejected',
    ] as $code => $public_code) {
      $logger = $this->safeFailureLogger($code, 'warning');
      $request = Request::create('/auth/sso/bundid_mock/mock-login');
      $session = $this->createMock(Session::class);
      $session->method('get')->willThrowException(new SsoAuthenticationException($code, 'hostile-saml-status'));
      $request->setSession($session);
      $response = $this->mockLogin($request, $logger);
      $this->assertSame('/auth/login?sso_error=' . $public_code, $response->headers->get('Location'));
    }
  }

  /**
   * Runs a mock callback with the development opt-in enabled.
   */
  private function mockLogin(Request $request, LoggerInterface $logger): RedirectResponse {
    $previous_environment = getenv('BEANTRAGT_ENV');
    $previous_mock = getenv('BEANTRAGT_SSO_MOCK');
    putenv('BEANTRAGT_ENV=dev');
    putenv('BEANTRAGT_SSO_MOCK=true');
    try {
      return $this->controller($logger)->mockLogin($request, 'bundid_mock');
    }
    finally {
      putenv('BEANTRAGT_ENV' . ($previous_environment === FALSE ? '' : '=' . $previous_environment));
      putenv('BEANTRAGT_SSO_MOCK' . ($previous_mock === FALSE ? '' : '=' . $previous_mock));
    }
  }

  /**
   * Requires the complete log record to contain only locally safe values.
   */
  private function safeFailureLogger(string $code, string $level): LoggerInterface {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log')->with(
      $level,
      'SSO failure @code (correlation @correlation).',
      $this->callback(static fn(array $context): bool => array_keys($context) === ['@code', '@correlation']
        && $context['@code'] === $code
        && preg_match('/^[a-f0-9]{32}$/', $context['@correlation']) === 1),
    );
    return $logger;
  }

  /**
   * Builds the controller with real services until ACS validation fails.
   */
  private function controller(?LoggerInterface $logger = NULL, ?KeyValueStoreExpirableInterface $store = NULL): SsoAuthController {
    $provider_manager = new SsoProviderManager($this->configFactory());
    $request_stack = new RequestStack();
    $client_factory = new SsoClientFactory($provider_manager, $request_stack);
    $replay_cache = new SsoReplayCache(
      $this->createMock(Connection::class),
      $this->createMock(TimeInterface::class),
    );
    $identity_linker = new SsoIdentityLinker(
      $this->createMock(Connection::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ApplicantManager::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->createMock(VerifiedAccountMail::class),
      $this->createMock(LockBackendInterface::class),
    );
    $factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $factory->method('get')->willReturn($store ?? $this->createMock(KeyValueStoreExpirableInterface::class));
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $login_service = new SsoLoginService(
      $provider_manager,
      $client_factory,
      $replay_cache,
      $identity_linker,
      $this->createMock(LoggerInterface::class),
      new SsoRequestCorrelation($factory, $lock, $this->createMock(TimeInterface::class)),
    );

    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    return new SsoAuthController(
      $provider_manager,
      $client_factory,
      $login_service,
      new SsoRelayStateValidator(
        $this->configFactory(),
        $request_stack,
        $this->createMock(LoggerInterface::class),
      ),
      $logger ?? $this->createMock(LoggerInterface::class),
      $flood,
      $this->configFactory(),
    );
  }

  /**
   * Builds config for an enabled SSO provider.
   */
  private function configFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key): mixed {
        if (in_array($key, ['providers.bundid', 'providers.bundid_mock'], TRUE)) {
          return [
            'enabled' => TRUE,
            'profile' => $key === 'providers.bundid' ? 'bundid' : 'bundid_mock',
            'default_relay_path' => '/',
            'allowed_relay_hosts' => ['beantragt.ddev.site:3001'],
          ];
        }
        return NULL;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    return $factory;
  }

}
