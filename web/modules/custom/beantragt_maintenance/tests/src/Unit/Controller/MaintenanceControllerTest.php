<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_maintenance\Unit\Controller;

use Drupal\beantragt_maintenance\Controller\MaintenanceController;
use Drupal\beantragt_maintenance\Service\BreakGlassOtpService;
use Drupal\beantragt_maintenance\Service\BreakGlassOtpServiceInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the global maintenance API controller contract.
 */
#[CoversClass(MaintenanceController::class)]
#[Group('beantragt_maintenance')]
final class MaintenanceControllerTest extends UnitTestCase {

  /**
   * Original Nuxt service user environment value.
   */
  private string|false $originalServiceUser;

  /**
   * Original Nuxt service password environment value.
   */
  private string|false $originalServicePassword;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalServiceUser = getenv('NUXT_DRUPAL_SERVICE_USER');
    $this->originalServicePassword = getenv('NUXT_DRUPAL_SERVICE_PASSWORD');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_USER', $this->originalServiceUser);
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_PASSWORD', $this->originalServicePassword);
    parent::tearDown();
  }

  /**
   * Status combines the dedicated and Core flags into the exact public shape.
   */
  public function testStatusShapeAndNoStoreHeader(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnMap([
      [BreakGlassOtpService::PLATFORM_MAINTENANCE_STATE, FALSE, FALSE],
      ['system.maintenance_mode', FALSE, TRUE],
    ]);

    $response = $this->controller(state: $state)->status();

    self::assertSame(['maintenance' => TRUE], $this->payload($response));
    self::assertTrue($response->headers->hasCacheControlDirective('private'));
    self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
  }

  /**
   * Nonprivileged accounts cannot pass the toggle access check.
   */
  public function testToggleAccessDeniesNonprivilegedAccount(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $account->method('hasPermission')
      ->with(MaintenanceController::ADMIN_PERMISSION)
      ->willReturn(FALSE);

    $result = $this->controller(account: $account)->access(
      Request::create('/api/platform/maintenance', 'PATCH'),
      $account,
    );

    self::assertTrue($result->isForbidden());
  }

  /**
   * Toggle input rejects unknown keys and non-boolean values.
   */
  public function testToggleRejectsInvalidShapes(): void {
    $controller = $this->controller();
    $requests = [
      Request::create('/api/platform/maintenance', 'PATCH', content: '{"maintenance":true,"extra":false}'),
      Request::create('/api/platform/maintenance', 'PATCH', content: '{"maintenance":"false"}'),
      Request::create('/api/platform/maintenance', 'PATCH', content: 'not-json'),
    ];

    foreach ($requests as $request) {
      $response = $controller->update($request);
      self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
      self::assertArrayHasKey('error', $this->payload($response));
      self::assertTrue($response->headers->hasCacheControlDirective('private'));
      self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
      self::assertSame('0', $response->headers->getCacheControlDirective('max-age'));
    }
  }

  /**
   * A one-time recovery token can only disable both maintenance flags.
   */
  public function testRecoveryTokenDisablesBothFlags(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $token = str_repeat('A', 43);
    $proxy_identifier = str_repeat('c', 32);
    $expected_identifier = 'proxy:' . $proxy_identifier;
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with(
        'beantragt_maintenance.break_glass.token.lockout',
        5,
        900,
        $expected_identifier,
      )
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('clear')
      ->with('beantragt_maintenance.break_glass.token.lockout', $expected_identifier);
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->once())
      ->method('consumeRecoveryToken')
      ->with($token)
      ->willReturn(42);
    $break_glass->expects($this->once())->method('invalidateRecoveryArtifacts');
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->exactly(2))
      ->method('set')
      ->willReturnCallback(static function (string $key, bool $value): void {
        self::assertContains($key, [
          BreakGlassOtpService::PLATFORM_MAINTENANCE_STATE,
          'system.maintenance_mode',
        ]);
        self::assertFalse($value);
      });
    $request = Request::create(
      '/api/platform/maintenance',
      'PATCH',
      server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $proxy_identifier,
      ],
      content: '{"maintenance":false}',
    );

    $response = $this->controller(
      $state,
      breakGlass: $break_glass,
      flood: $flood,
    )->update($request);

    self::assertSame(['maintenance' => FALSE], $this->payload($response));
  }

  /**
   * Failed token attempts register Flood and a locked bucket returns 429.
   */
  public function testRecoveryTokenFailureRegistersFloodAndLockoutReturns429(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $token = str_repeat('B', 43);
    $proxy_identifier = str_repeat('d', 32);
    $expected_identifier = 'proxy:' . $proxy_identifier;
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(6))
      ->method('isAllowed')
      ->with(
        'beantragt_maintenance.break_glass.token.lockout',
        5,
        900,
        $expected_identifier,
      )
      ->willReturnOnConsecutiveCalls(TRUE, TRUE, TRUE, TRUE, TRUE, FALSE);
    $flood->expects($this->exactly(5))
      ->method('register')
      ->with('beantragt_maintenance.break_glass.token.lockout', 900, $expected_identifier);
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->exactly(5))
      ->method('consumeRecoveryToken')
      ->with($token)
      ->willReturn(NULL);
    $request = Request::create(
      '/api/platform/maintenance',
      'PATCH',
      server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $proxy_identifier,
      ],
      content: '{"maintenance":false}',
    );
    $controller = $this->controller(breakGlass: $break_glass, flood: $flood);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
      $failed_response = $controller->update($request);
      self::assertSame(Response::HTTP_FORBIDDEN, $failed_response->getStatusCode());
    }
    $locked_response = $controller->update($request);

    self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $locked_response->getStatusCode());
  }

  /**
   * Recovery bearer requests require a valid trusted proxy identifier.
   */
  public function testRecoveryTokenRequiresTrustedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $token = str_repeat('C', 43);
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->never())->method('consumeRecoveryToken');
    $controller = $this->controller(
      breakGlass: $break_glass,
      flood: $flood,
    );
    foreach (['', 'not-a-valid-identifier'] as $proxy_identifier) {
      $server = [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      ];
      if ($proxy_identifier !== '') {
        $server['HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER'] = $proxy_identifier;
      }
      $request = Request::create(
        '/api/platform/maintenance',
        'PATCH',
        server: $server,
        content: '{"maintenance":false}',
      );

      $response = $controller->update($request);
      self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
  }

  /**
   * Recovery bearer requests require authenticated service-proxy headers.
   */
  public function testRecoveryTokenRequiresServiceProxyAuthorization(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $token = str_repeat('D', 43);
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->never())->method('consumeRecoveryToken');
    $request = Request::create(
      '/api/platform/maintenance',
      'PATCH',
      server: [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => str_repeat('e', 32),
      ],
      content: '{"maintenance":false}',
    );

    $response = $this->controller(
      breakGlass: $break_glass,
      flood: $flood,
    )->update($request);

    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Privileged cookie and CSRF updates do not use recovery-token Flood gates.
   */
  public function testAdminCookieUpdateDoesNotUseRecoveryTokenFlood(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $csrf_token = $this->createMock(CsrfTokenGenerator::class);
    $csrf_token->expects($this->once())
      ->method('validate')
      ->with('valid-csrf-token', CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      ->willReturn(TRUE);
    $state = $this->createMock(StateInterface::class);
    $state->expects($this->exactly(2))->method('set');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $flood->expects($this->never())->method('register');
    $flood->expects($this->never())->method('clear');
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->never())->method('consumeRecoveryToken');
    $break_glass->expects($this->once())->method('invalidateRecoveryArtifacts');
    $request = Request::create(
      '/api/platform/maintenance',
      'PATCH',
      server: ['HTTP_X_CSRF_TOKEN' => 'valid-csrf-token'],
      content: '{"maintenance":true}',
    );

    $controller = $this->controller(
      $state,
      account: $account,
      breakGlass: $break_glass,
      flood: $flood,
      csrfToken: $csrf_token,
    );

    self::assertTrue($controller->access($request, $account)->isAllowed());
    $response = $controller->update($request);

    self::assertSame(['maintenance' => TRUE], $this->payload($response));
  }

  /**
   * Recovery request flood control uses the trusted proxy identifier.
   */
  public function testRecoveryFloodUsesTrustedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $proxy_identifier = str_repeat('a', 32);
    $sender = 'proxy:' . $proxy_identifier;
    $target = 'email:' . hash('sha256', 'operator@example.com');
    $expected = [
      'beantragt_maintenance.break_glass.request.location' => [10, $sender],
      'beantragt_maintenance.break_glass.request.target' => [3, $sender . ':' . $target],
      'beantragt_maintenance.break_glass.request.global_target' => [60, $target],
    ];
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(3))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use ($expected): bool {
        self::assertSame(3600, $window);
        self::assertSame($expected[$event], [$threshold, $identifier]);
        return TRUE;
      });
    $registered = [];
    $flood->expects($this->exactly(3))->method('register')
      ->willReturnCallback(static function (string $event, int $window, string $identifier) use (&$registered): void {
        self::assertSame(3600, $window);
        $registered[$event] = $identifier;
      });
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->once())
      ->method('requestCode')
      ->with('operator@example.com')
      ->willReturn([
        'success' => TRUE,
        'message' => 'If the account is eligible, a recovery code has been sent.',
        'expiresIn' => 600,
        'codeLength' => 6,
      ]);
    $request = Request::create(
      '/api/platform/maintenance/recovery/request-code',
      'POST',
      server: [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $proxy_identifier,
      ],
      content: '{"email":"operator@example.com"}',
    );

    $response = $this->controller(
      breakGlass: $break_glass,
      flood: $flood,
    )->requestRecoveryCode($request);

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame(array_map(static fn (array $entry): string => $entry[1], $expected), $registered);
  }

  /**
   * Recovery verification also scopes Flood attempts to the proxy client.
   */
  public function testRecoveryVerifyFloodUsesTrustedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
    $proxy_identifier = str_repeat('b', 32);
    $expected_identifier = 'proxy:' . $proxy_identifier
      . ':email:' . hash('sha256', 'operator@example.com');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static function (string $event, int $threshold, int $window, string $identifier) use ($expected_identifier): bool {
        self::assertSame($expected_identifier, $identifier);
        if ($event === 'beantragt_maintenance.break_glass.verify.lockout') {
          self::assertSame(5, $threshold);
          self::assertSame(900, $window);
        }
        else {
          self::assertSame('beantragt_maintenance.break_glass.verify.backoff', $event);
          self::assertSame(3, $threshold);
          self::assertSame(60, $window);
        }
        return TRUE;
      });
    $flood->expects($this->exactly(2))
      ->method('clear')
      ->with($this->isType('string'), $expected_identifier);
    $break_glass = $this->createMock(BreakGlassOtpServiceInterface::class);
    $break_glass->expects($this->once())
      ->method('verifyCode')
      ->with('operator@example.com', '123456')
      ->willReturn([
        'recoveryToken' => str_repeat('T', 43),
        'expiresIn' => 300,
      ]);
    $request = Request::create(
      '/api/platform/maintenance/recovery/verify-code',
      'POST',
      server: [
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $proxy_identifier,
      ],
      content: '{"code":"123456","email":"operator@example.com"}',
    );

    $response = $this->controller(
      breakGlass: $break_glass,
      flood: $flood,
    )->verifyRecoveryCode($request);

    self::assertSame([
      'success' => TRUE,
      'recoveryToken' => str_repeat('T', 43),
      'expiresIn' => 300,
    ], $this->payload($response));
  }

  /**
   * Creates a controller with isolated defaults.
   */
  private function controller(
    ?StateInterface $state = NULL,
    ?AccountInterface $account = NULL,
    ?BreakGlassOtpServiceInterface $breakGlass = NULL,
    ?FloodInterface $flood = NULL,
    ?CsrfTokenGenerator $csrfToken = NULL,
  ): MaintenanceController {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['request_limit_per_ip', 10],
      ['request_limit_per_email', 3],
      ['verify_lockout_attempts', 5],
      ['verify_lockout_duration', 900],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($settings);

    return new MaintenanceController(
      $state ?? $this->createMock(StateInterface::class),
      $account ?? $this->createMock(AccountInterface::class),
      $this->createMock(LoggerChannelInterface::class),
      $breakGlass ?? $this->createMock(BreakGlassOtpServiceInterface::class),
      $flood ?? $this->createMock(FloodInterface::class),
      $config_factory,
      $csrfToken ?? $this->createMock(CsrfTokenGenerator::class),
    );
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   Decoded response data.
   */
  private function payload(Response $response): array {
    return json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Restores an environment variable.
   */
  private function restoreEnv(string $name, string|false $value): void {
    putenv($value === FALSE ? $name : $name . '=' . $value);
  }

}
