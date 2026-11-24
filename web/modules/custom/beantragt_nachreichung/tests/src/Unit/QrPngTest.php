<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_nachreichung\Unit;

use Drupal\beantragt_nachreichung\Code\CoverCode;
use Drupal\beantragt_nachreichung\Qr\QrPng;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * QR PNG: size, no alpha, deterministic, only cover sheet payloads.
 */
#[Group('beantragt_nachreichung')]
final class QrPngTest extends UnitTestCase {

  /**
   * Square of 198 px, RGB without alpha, the same bytes for one payload.
   */
  public function testPng(): void {
    $payload = CoverCode::payload(CoverCode::generate());
    $png = QrPng::render($payload);
    $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    $size = getimagesizefromstring($png);
    $this->assertIsArray($size);
    $this->assertSame([QrPng::SIZE_PX, QrPng::SIZE_PX], [$size[0], $size[1]]);
    // IHDR colour type: 0 grey or 2 RGB, never 4 or 6 (alpha).
    $this->assertContains(ord($png[25]), [0, 2]);
    $this->assertSame($png, QrPng::render($payload));
  }

  /**
   * Anything but BA1- plus 17 alphabet characters is refused.
   */
  public function testForeignPayloadIsRefused(): void {
    $foreign = [
      'https://example.org',
      'BA1-' . str_repeat('I', 17),
      'BA2-' . str_repeat('0', 17),
      'BA1-' . str_repeat('0', 16),
    ];
    foreach ($foreign as $payload) {
      try {
        QrPng::render($payload);
        $this->fail('Rendered a foreign payload.');
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
