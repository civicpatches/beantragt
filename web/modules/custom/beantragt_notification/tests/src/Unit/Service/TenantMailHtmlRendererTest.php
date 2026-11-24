<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Service;

use Drupal\beantragt_notification\Service\TenantMailHtmlRenderer;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests deterministic HTML mail content projection.
 *
 * @coversDefaultClass \Drupal\beantragt_notification\Service\TenantMailHtmlRenderer
 * @group beantragt_notification
 */
final class TenantMailHtmlRendererTest extends UnitTestCase {

  /**
   * Tenant footer, OTP code and safe CTA are projected without text drift.
   */
  public function testRendersOtpAndKeepsPlainFallback(): void {
    $captured = NULL;
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->expects($this->once())
      ->method('renderInIsolation')
      ->willReturnCallback(function (array $build) use (&$captured): string {
        $captured = $build;
        return '<html>rendered</html>';
      });

    $service = new TenantMailHtmlRenderer($renderer);
    $result = $service->render([
      'footer' => 'Stadt Musterstadt',
    ], [
      'langcode' => 'de',
      'subject' => 'Ihr Code',
      'body' => ["Ihr Code lautet 123456.\n\nE-Mail-Adresse bestätigen: https://example.org/continue"],
      'params' => ['code' => '123456'],
    ]);

    $this->assertSame('<html>rendered</html>', $result['html']);
    $this->assertStringContainsString('Ihr Code lautet 123456.', $result['plain']);
    $this->assertStringEndsWith("-- \nStadt Musterstadt\n", $result['plain']);
    $this->assertSame('123456', $captured['#code']);
    $this->assertSame('https://example.org/continue', $captured['#cta_url']);
    $this->assertStringNotContainsString('123456', (string) $captured['#body']);
    $this->assertStringNotContainsString('https://example.org/continue', (string) $captured['#body']);
    $this->assertStringNotContainsString('E-Mail-Adresse bestätigen:', (string) $captured['#body']);
  }

  /**
   * Unsafe schemes never become HTML action links.
   */
  public function testRejectsUnsafeCtaScheme(): void {
    $captured = NULL;
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')
      ->willReturnCallback(function (array $build) use (&$captured): string {
        $captured = $build;
        return '<html>rendered</html>';
      });

    $service = new TenantMailHtmlRenderer($renderer);
    $service->render(['footer' => ''], [
      'body' => ['javascript:alert(1)'],
      'params' => ['claim_url' => 'javascript:alert(1)'],
    ]);

    $this->assertSame('', $captured['#cta_url']);
  }

}
