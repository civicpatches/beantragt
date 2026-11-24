<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_maintenance\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests maintenance routes through Drupal's HTTP access stack.
 */
#[Group('beantragt_maintenance')]
final class MaintenanceAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'beantragt_maintenance',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Public status is available, while anonymous toggle writes are forbidden.
   */
  public function testPublicStatusAndAnonymousToggleDenial(): void {
    $this->drupalGet('/api/platform/maintenance');
    $this->assertSession()->statusCodeEquals(200);
    self::assertSame(
      ['maintenance' => FALSE],
      json_decode($this->getSession()->getPage()->getContent(), TRUE),
    );
    $cache_control = (string) $this->getSession()->getResponseHeader('Cache-Control');
    self::assertStringContainsString('private', $cache_control);
    self::assertStringContainsString('no-store', $cache_control);
    self::assertStringContainsString('max-age=0', $cache_control);

    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $csrf_token = $this->drupalGet('/session/token');
    $this->assertSession()->statusCodeEquals(200);
    self::assertNotSame('', $csrf_token);
    $this->drupalGet('/api/platform/maintenance');
    $this->assertSession()->statusCodeEquals(200);
    self::assertSame(
      ['maintenance' => TRUE],
      json_decode($this->getSession()->getPage()->getContent(), TRUE),
    );
    $this->container->get('state')->set('system.maintenance_mode', FALSE);

    $response = $this->getHttpClient()->request(
      'PATCH',
      $this->getAbsoluteUrl('/api/platform/maintenance'),
      [
        'body' => '{"maintenance":false}',
        'cookies' => $this->getSessionCookies(),
        'headers' => ['Content-Type' => 'application/json'],
        'http_errors' => FALSE,
      ],
    );
    self::assertSame(403, $response->getStatusCode());
  }

}
