<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\AttachmentScanService;
use Drupal\beantragt_application\Service\AttachmentScanServiceFactory;
use Drupal\beantragt_application\Service\ClamAvAttachmentScanService;
use PHPUnit\Framework\TestCase;

/**
 * Covers attachment scanner selection from environment configuration.
 */
final class AttachmentScanServiceFactoryTest extends TestCase {

  /**
   * Original environment snapshot.
   *
   * @var array<string, string|false>
   */
  private array $env;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->env = [];
    foreach ($this->envNames() as $name) {
      $this->env[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->env as $name => $value) {
      if ($value === FALSE) {
        putenv($name);
      }
      else {
        putenv($name . '=' . $value);
      }
    }
    parent::tearDown();
  }

  /**
   * DDEV default mode keeps deterministic local EICAR scanning.
   */
  public function testDdevDefaultsToDeterministicScanner(): void {
    putenv('IS_DDEV_PROJECT=true');

    $scanner = (new AttachmentScanServiceFactory())->create();

    $this->assertInstanceOf(AttachmentScanService::class, $scanner);
  }

  /**
   * Production-like default mode requires strict ClamAV.
   */
  public function testProductionDefaultsToClamAvScanner(): void {
    putenv('BEANTRAGT_ENV=production');

    $scanner = (new AttachmentScanServiceFactory())->create();

    $this->assertInstanceOf(ClamAvAttachmentScanService::class, $scanner);
  }

  /**
   * Strict ClamAV mode returns the ClamAV adapter.
   */
  public function testCreatesClamAvScannerWhenConfigured(): void {
    putenv('BEANTRAGT_ATTACHMENT_SCANNER=clamav');
    putenv('BEANTRAGT_CLAMAV_HOST=clamav');
    putenv('BEANTRAGT_CLAMAV_PORT=3310');
    putenv('BEANTRAGT_CLAMAV_TIMEOUT_SECONDS=3');

    $scanner = (new AttachmentScanServiceFactory())->create();

    $this->assertInstanceOf(ClamAvAttachmentScanService::class, $scanner);
  }

  /**
   * Unknown local modes fall back to the deterministic scanner.
   */
  public function testUnknownLocalModeFallsBackToDeterministicScanner(): void {
    putenv('IS_DDEV_PROJECT=true');
    putenv('BEANTRAGT_ATTACHMENT_SCANNER=maybe');

    $scanner = (new AttachmentScanServiceFactory())->create();

    $this->assertInstanceOf(AttachmentScanService::class, $scanner);
  }

  /**
   * Unknown production modes fall back to strict ClamAV.
   */
  public function testUnknownProductionModeFallsBackToClamAvScanner(): void {
    putenv('BEANTRAGT_ENV=production');
    putenv('BEANTRAGT_ATTACHMENT_SCANNER=maybe');

    $scanner = (new AttachmentScanServiceFactory())->create();

    $this->assertInstanceOf(ClamAvAttachmentScanService::class, $scanner);
  }

  /**
   * Environment variables touched by the factory.
   *
   * @return list<string>
   *   Environment variable names.
   */
  private function envNames(): array {
    return [
      'BEANTRAGT_ATTACHMENT_SCANNER',
      'BEANTRAGT_CLAMAV_HOST',
      'BEANTRAGT_CLAMAV_PORT',
      'BEANTRAGT_CLAMAV_TIMEOUT_SECONDS',
      'BEANTRAGT_ENV',
      'IS_DDEV_PROJECT',
    ];
  }

}
