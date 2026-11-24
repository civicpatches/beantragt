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
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
   * Failed ACS responses redirect back to the SPA with a generic marker.
   */
  public function testAcsFailureRedirectsToRelayStateWithErrorMarker(): void {
    $correlation = NULL;
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())
      ->method('log')
      ->with(
        'warning',
        'SSO failure @code (correlation @correlation).',
        self::callback(static function (array $context) use (&$correlation): bool {
          $correlation = $context['@correlation'];
          return $context['@code'] === 'acs_rejected'
            && preg_match('/^[a-f0-9]{32}$/', $context['@correlation']) === 1
            && array_keys($context) === ['@code', '@correlation'];
        }),
      );
    $controller = $this->controller($logger);
    $request = Request::create('/auth/sso/bundid/acs', 'POST');
    $session = new Session(new MockArraySessionStorage());
    $session->set(
      'beantragt_sso.bundid.relay_state',
      'https://beantragt.ddev.site:3001/musterstadt/meine-antraege',
    );
    $request->setSession($session);

    $response = $controller->acs($request, 'bundid');

    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame(
      'https://beantragt.ddev.site:3001/musterstadt/meine-antraege?sso_error=1&sso_code=acs_rejected&sso_correlation=' . $correlation,
      $response->headers->get('Location'),
    );
    $this->assertFalse($session->has('beantragt_sso.bundid.relay_state'));
  }

  /**
   * Initiation failures never log attacker or database exception contents.
   */
  public function testInitiationLogsOnlyCodeAndCorrelation(): void {
    $logger = $this->safeFailureLogger('login_start_failed', 'error');
    $request = Request::create('/auth/sso/bundid/login');
    $session = $this->createMock(Session::class);
    $session->method('set')->willThrowException(new \RuntimeException('database-error-sentinel citizen@example.test'));
    $request->setSession($session);
    try {
      $this->controller($logger)->login($request, 'bundid');
      $this->fail('Initiation failure was accepted.');
    }
    catch (HttpException $exception) {
      $this->assertSame('SSO login is not available.', $exception->getMessage());
    }
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
    $this->assertMatchesRegularExpression('~^/\?sso_error=1&sso_code=mock_login_failed&sso_correlation=[a-f0-9]{32}$~', $response->headers->get('Location'));
  }

  /**
   * Typed failures keep their category in both the log and redirect.
   */
  public function testTypedFailuresReachRedirect(): void {
    foreach (['identity_disabled', 'account_ineligible', 'account_privileged', 'identity_busy', 'bad_signature'] as $code) {
      $correlation = NULL;
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($this->once())->method('log')->with('warning',
        'SSO failure @code (correlation @correlation).',
        $this->callback(static function (array $context) use ($code, &$correlation): bool {
          $correlation = $context['@correlation'];
          return $context['@code'] === $code;
        }));
      $request = Request::create('/auth/sso/bundid_mock/mock-login');
      $session = $this->createMock(Session::class);
      $session->method('get')->willThrowException(new SsoAuthenticationException($code, 'hostile-saml-status'));
      $request->setSession($session);
      $response = $this->mockLogin($request, $logger);
      $this->assertSame('/?sso_error=1&sso_code=' . $code . '&sso_correlation=' . $correlation, $response->headers->get('Location'));
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
  private function controller(?LoggerInterface $logger = NULL): SsoAuthController {
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
    $login_service = new SsoLoginService(
      $provider_manager,
      $client_factory,
      $replay_cache,
      $identity_linker,
      $this->createMock(LoggerInterface::class),
    );

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
