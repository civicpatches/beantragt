<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Service;

use Drupal\beantragt_sso\Service\SsoRelayStateValidator;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests RelayState sanitizing.
 *
 * @group beantragt_sso
 */
final class SsoRelayStateValidatorTest extends UnitTestCase {

  /**
   * Local relative paths are safe.
   */
  public function testAllowsLocalRelativePath(): void {
    $validator = $this->validator();

    $this->assertSame('/musterstadt/meine-antraege', $validator->sanitize('bundid', '/musterstadt/meine-antraege'));
  }

  /**
   * Protocol-relative and control-character targets fall back.
   */
  public function testRejectsUnsafeRelativeTargets(): void {
    $validator = $this->validator();

    $this->assertSame('/fallback', $validator->sanitize('bundid', '//evil.example/admin'));
    $this->assertSame('/fallback', $validator->sanitize('bundid', "/safe\nLocation: https://evil.example"));
  }

  /**
   * Absolute URLs require an allowlisted host.
   */
  public function testRejectsUnlistedAbsoluteHost(): void {
    $validator = $this->validator();

    $this->assertSame('/fallback', $validator->sanitize('bundid', 'https://evil.example/admin'));
  }

  /**
   * The redirect recheck requires an explicit allowlist match.
   */
  public function testAllowedRelayHostRequiresExplicitConfiguration(): void {
    $request_stack = new RequestStack();
    $request_stack->push(Request::create('https://unlisted.example.test/'));
    $validator = $this->validator([
      'allowed_relay_hosts' => [' UI.EXAMPLE.TEST:8443 '],
    ], $request_stack);

    $this->assertFalse($validator->isAllowedRelayHost('bundid', 'https://unlisted.example.test/return'));
    $this->assertFalse($validator->isAllowedRelayHost('bundid', 'https://ui.example.test:443/return'));
    $this->assertTrue($validator->isAllowedRelayHost('bundid', 'HTTPS://ui.example.test:8443/return'));
  }

  /**
   * Rejects malformed ports even when the host itself is allowed.
   */
  public function testRejectsInvalidAbsoluteAuthorities(): void {
    $validator = $this->validator([
      'allowed_relay_hosts' => ['ui.example.test'],
    ]);

    foreach ([':8443;', ':8443-', ':8443_', ':8443:1/x', ':0', ':65536', ':000001'] as $suffix) {
      $target = 'https://ui.example.test' . $suffix;
      $this->assertSame('/fallback', $validator->sanitize('bundid', $target), $target);
    }
    foreach ([1, 65535] as $port) {
      $target = "https://ui.example.test:$port";
      $this->assertSame('/fallback', $validator->sanitize('bundid', $target));
    }
  }

  /**
   * A rejected target never discloses its hostile contents in logs.
   */
  public function testRejectedRelayHasContentFreeLog(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log')->with(
      'warning', 'SSO failure @code (correlation @correlation).',
      $this->callback(static fn(array $context): bool => array_keys($context) === ['@code', '@correlation']
        && $context['@code'] === 'relay_state_rejected'
        && preg_match('/^[a-f0-9]{32}$/', $context['@correlation']) === 1),
    );
    $validator = $this->validator([], NULL, $logger);
    $this->assertSame('/fallback', $validator->sanitize('bundid', 'https://hostile-saml-status.example.test/?email=victim@example.test'));
  }

  /**
   * Configured host:port values are accepted.
   */
  public function testAllowsConfiguredHostWithPort(): void {
    $validator = $this->validator([
      'allowed_relay_hosts' => ['beantragt.ddev.site:3001'],
    ]);

    $this->assertSame(
      'https://beantragt.ddev.site:3001/musterstadt/meine-antraege',
      $validator->sanitize('bundid', 'https://beantragt.ddev.site:3001/musterstadt/meine-antraege')
    );
  }

  /**
   * HTTP absolute URLs are rejected for non-local allowlisted hosts.
   */
  public function testRejectsHttpDowngradeForAllowedPublicHost(): void {
    $validator = $this->validator([
      'allowed_relay_hosts' => ['frontend.example.org'],
    ]);

    $this->assertSame(
      '/fallback',
      $validator->sanitize('bundid', 'http://frontend.example.org/musterstadt/meine-antraege')
    );
  }

  /**
   * The current request host is accepted automatically.
   */
  public function testAllowsCurrentRequestHost(): void {
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('https://beantragt.ddev.site/current'));
    $validator = $this->validator([], $requestStack);

    $this->assertSame(
      'https://beantragt.ddev.site/meine-antraege',
      $validator->sanitize('bundid', 'https://beantragt.ddev.site/meine-antraege')
    );
    $this->assertSame('/fallback', $validator->sanitize('bundid', 'https://beantragt.ddev.site:8443/return'));

    $requestStack->push(Request::create('https://beantragt.ddev.site:8443/current'));
    $target = 'https://beantragt.ddev.site:8443/return';
    $this->assertSame($target, $validator->sanitize('bundid', $target));
    $this->assertSame('/fallback', $validator->sanitize('bundid', 'https://beantragt.ddev.site/return'));
  }

  /**
   * Host-only entries permit only the scheme's default port.
   */
  public function testAllowlistUsesEffectivePort(): void {
    $validator = $this->validator([
      'allowed_relay_hosts' => ['ui.example.test', 'localhost', 'second.example.test:443'],
    ]);
    foreach (['https://ui.example.test', 'https://ui.example.test:443', 'http://localhost',
      'http://localhost:80', 'https://second.example.test', 'https://second.example.test:443',
    ] as $target) {
      $this->assertTrue($validator->isAllowedRelayHost('bundid', $target), $target);
    }
    foreach (['https://ui.example.test:80', 'https://ui.example.test:8443', 'http://localhost:3001',
      'https://localhost:80', 'https://second.example.test:8443',
    ] as $target) {
      $this->assertFalse($validator->isAllowedRelayHost('bundid', $target), $target);
    }
  }

  /**
   * Invalid defaults fall back to root.
   */
  public function testInvalidDefaultFallsBackToRoot(): void {
    $validator = $this->validator([
      'default_relay_path' => '//evil.example',
    ]);

    $this->assertSame('/', $validator->sanitize('bundid', 'https://evil.example/admin'));
  }

  /**
   * Builds the validator with static provider config.
   *
   * @param array<string, mixed> $provider
   *   Provider config overrides.
   * @param \Symfony\Component\HttpFoundation\RequestStack|null $requestStack
   *   Request stack override.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Logger override.
   */
  private function validator(array $provider = [], ?RequestStack $requestStack = NULL, ?LoggerInterface $logger = NULL): SsoRelayStateValidator {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('providers.bundid')
      ->willReturn($provider + [
        'default_relay_path' => '/fallback',
        'allowed_relay_hosts' => [],
      ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_sso.settings')
      ->willReturn($config);

    return new SsoRelayStateValidator(
      $factory,
      $requestStack ?? new RequestStack(),
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

}
