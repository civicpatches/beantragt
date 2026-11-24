<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Kernel;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies page cache protection on the public SSO routes.
 */
#[Group('beantragt_sso')]
final class SsoRouteCacheKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'beantragt_passwordless', 'beantragt_sso'];

  /**
   * Provides unused dependencies of the SSO and passwordless services.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('beantragt_applicant.manager', ApplicantManager::class)->setSynthetic(TRUE);
    $container->register('beantragt_application.retention_cleanup')->setSynthetic(TRUE);
  }

  /**
   * Page Cache uses the route option even when the response has max-age zero.
   */
  public function testPublicRoutesDisablePageCache(): void {
    $this->container->get('router.builder')->rebuild();
    $routes = $this->container->get('router.route_provider');
    foreach (['login', 'acs', 'mock_login', 'mock_idp', 'metadata'] as $name) {
      $route = $routes->getRouteByName('beantragt_sso.' . $name);
      self::assertContains($route->getOption('no_cache'), [TRUE, 'TRUE'], $name);
    }
  }

}
