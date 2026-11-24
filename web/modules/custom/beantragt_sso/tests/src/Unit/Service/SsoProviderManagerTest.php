<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Service;

use Drupal\beantragt_sso\Service\SsoProviderManager;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests SSO provider gating and mock enablement.
 *
 * @group beantragt_sso
 */
final class SsoProviderManagerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_SSO_MOCK');
    putenv('BEANTRAGT_DEMO_MODE');
    putenv('BEANTRAGT_ENV');
    putenv('IS_DDEV_PROJECT');
    parent::tearDown();
  }

  /**
   * Admin-saved checkbox values such as 1 still count as enabled.
   */
  public function testEnabledProviderAcceptsTruthyConfigValue(): void {
    $manager = $this->manager([
      'bundid' => [
        'enabled' => 1,
        'profile' => 'bundid',
      ],
    ]);

    $this->assertSame('bundid', $manager->enabledProvider('bundid')['profile']);
  }

  /**
   * Disabled providers deny login starts.
   */
  public function testDisabledProviderDeniesAccess(): void {
    $manager = $this->manager([
      'bundid' => [
        'enabled' => FALSE,
        'profile' => 'bundid',
      ],
    ]);

    $this->expectException(AccessDeniedHttpException::class);
    $manager->enabledProvider('bundid');
  }

  /**
   * Invalid provider ids never reach config lookup.
   */
  public function testInvalidProviderIdThrowsNotFound(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->manager([])->provider('../bundid');
  }

  /**
   * Mock profiles are treated as mock providers.
   */
  public function testMockProfilesAreMockProviders(): void {
    $manager = $this->manager([]);

    $this->assertTrue($manager->isMockProvider([
      'profile' => 'bundid_mock',
    ]));
    $this->assertTrue($manager->isMockProvider([
      'profile' => 'sso_mock',
    ]));
    $this->assertFalse($manager->isMockProvider([
      'profile' => 'bundid',
    ]));
  }

  /**
   * Mock providers fail closed without an explicit SSO mock flag.
   */
  public function testMockLoginRequiresExplicitMockFlag(): void {
    putenv('BEANTRAGT_DEMO_MODE=true');
    putenv('BEANTRAGT_ENV=dev');

    $this->assertFalse($this->manager([])->mockAllowed());
  }

  /**
   * Production disables mock login without explicit public demo mode.
   */
  public function testMockLoginDeniedInProduction(): void {
    putenv('BEANTRAGT_SSO_MOCK=true');
    putenv('BEANTRAGT_ENV=production');

    $this->assertFalse($this->manager([])->mockAllowed());
  }

  /**
   * The production gate overrides a DDEV marker and an explicit mock opt-in.
   */
  public function testProductionWinsOverDdev(): void {
    putenv('BEANTRAGT_ENV=production');
    putenv('IS_DDEV_PROJECT=true');
    putenv('BEANTRAGT_SSO_MOCK=true');
    $manager = $this->manager([]);
    self::assertFalse($manager->isDevContext());
    $this->expectException(AccessDeniedHttpException::class);
    $manager->assertMockAllowed(['profile' => 'bundid_mock']);
  }

  /**
   * Demo mode alone must not enable the mock provider in production.
   */
  public function testMockLoginDeniedForProductionDemoMode(): void {
    putenv('BEANTRAGT_SSO_MOCK=true');
    putenv('BEANTRAGT_DEMO_MODE=true');
    putenv('BEANTRAGT_ENV=production');

    $this->assertFalse($this->manager([])->mockAllowed());
  }

  /**
   * Dev environments can opt into the mock explicitly.
   */
  public function testMockLoginAllowedForExplicitDevOptIn(): void {
    putenv('BEANTRAGT_SSO_MOCK=true');
    putenv('BEANTRAGT_ENV=dev');

    $this->assertTrue($this->manager([])->mockAllowed());
  }

  /**
   * DDEV can opt into the mock explicitly.
   */
  public function testMockLoginAllowedForExplicitDdevOptIn(): void {
    putenv('BEANTRAGT_SSO_MOCK=true');
    putenv('IS_DDEV_PROJECT=true');

    $this->assertTrue($this->manager([])->mockAllowed());
  }

  /**
   * AssertMockAllowed throws for mock providers when the gate is closed.
   */
  public function testAssertMockAllowedDeniesClosedGate(): void {
    $manager = $this->manager([]);

    $this->expectException(AccessDeniedHttpException::class);
    $manager->assertMockAllowed([
      'profile' => 'bundid_mock',
      'mock' => TRUE,
    ]);
  }

  /**
   * Builds a provider manager with static provider config.
   *
   * @param array<string, array<string, mixed>> $providers
   *   Providers keyed by machine name.
   */
  private function manager(array $providers): SsoProviderManager {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnCallback(static function (string $key) use ($providers): mixed {
        if (!str_starts_with($key, 'providers.')) {
          return NULL;
        }
        $provider_id = substr($key, strlen('providers.'));
        return $providers[$provider_id] ?? NULL;
      });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_sso.settings')
      ->willReturn($config);

    return new SsoProviderManager($factory);
  }

}
