<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_docgen\Service\RenderDataBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\Attributes\Group;

/**
 * Value formatting and the canonical data hash.
 */
#[Group('beantragt_docgen')]
final class RenderDataTest extends UnitTestCase {

  /**
   * Original installation settings, restored after every test.
   */
  private array $originalSettings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    try {
      Settings::getInstance();
      $this->originalSettings = Settings::getAll();
    }
    catch (\BadMethodCallException) {
      $this->originalSettings = [];
    }
    new Settings(['hash_salt' => bin2hex(random_bytes(32))] + $this->originalSettings);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings($this->originalSettings);
    parent::tearDown();
  }

  /**
   * Stored values become template text; empty values stay missing.
   */
  public function testFormat(): void {
    self::assertSame('', RenderDataBuilder::clerkName('agent.nachforderung'));
    self::assertSame("Miete\nEinkommen", RenderDataBuilder::format("Miete\nEinkommen", 'textarea'));
    self::assertNull(RenderDataBuilder::format(NULL, 'text'));
    self::assertNull(RenderDataBuilder::format('', 'text'));
    self::assertNull(RenderDataBuilder::format([], 'text'));
    self::assertNull(RenderDataBuilder::format([ApplicationDataProtectionService::REDACTED_MARKER => TRUE], 'text'));
    self::assertSame('Ja', RenderDataBuilder::format(TRUE, 'checkbox'));
    self::assertSame('Nein', RenderDataBuilder::format(FALSE, 'checkbox'));
    self::assertSame('01.11.2026', RenderDataBuilder::format('2026-11-01', 'date'));
    self::assertSame('2026-11-01', RenderDataBuilder::format('2026-11-01', 'text'));
    self::assertSame('42', RenderDataBuilder::format(42, 'number'));
    self::assertSame('A, B', RenderDataBuilder::format(['A', 'B'], 'select'));
    self::assertSame('nachweis.pdf', RenderDataBuilder::format([['filename' => 'nachweis.pdf', 'size' => 10]], 'file'));
    self::assertSame('{antrag.x}', RenderDataBuilder::format('{antrag.x}', 'text'));
  }

  /**
   * Fingerprints cannot be enumerated without this installation's key.
   */
  public function testFingerprintUsesServerKey(): void {
    $data = ['answer' => 'yes'];
    $first = RenderDataBuilder::hash($data);
    self::assertNotSame(hash('sha256', json_encode($data)), $first);
    self::assertNotSame(hash_hmac('sha256', json_encode($data), Settings::getHashSalt()), $first);
    new Settings(['hash_salt' => bin2hex(random_bytes(32))]);
    self::assertNotSame($first, RenderDataBuilder::hash($data));
  }

  /**
   * The hash ignores key order and changes with any value.
   */
  public function testHashIsCanonical(): void {
    $a = RenderDataBuilder::hash(['b' => '2', 'a' => '1', 'liste' => [['y' => 1, 'x' => 2]]]);
    $b = RenderDataBuilder::hash(['a' => '1', 'liste' => [['x' => 2, 'y' => 1]], 'b' => '2']);
    self::assertSame($a, $b);
    self::assertNotSame($a, RenderDataBuilder::hash(['a' => '1', 'b' => '3', 'liste' => [['x' => 2, 'y' => 1]]]));
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
  }

  /**
   * Number formatting follows the language and the existing currency hint.
   */
  public function testLocalizedNumbers(): void {
    self::assertSame('1200,5', RenderDataBuilder::format(1200.5, 'number'));
    self::assertSame('2026', RenderDataBuilder::format('2026', 'number'));
    self::assertSame('1200.5', RenderDataBuilder::format(1200.5, 'number', '', 'en_GB'));
    self::assertSame('12.000,5', RenderDataBuilder::format(12000.5, 'number'));
    self::assertSame('12,000.5', RenderDataBuilder::format(12000.5, 'number', '', 'en_GB'));
    self::assertSame('12,1234567894', RenderDataBuilder::format('12.1234567894', 'number'));
    self::assertSame("1.200,50\u{00A0}€", RenderDataBuilder::format('1200.50', 'number', 'currency'));
    self::assertSame('€1,200.50', RenderDataBuilder::format(1200.5, 'number', 'currency', 'en_GB'));
    self::assertSame("-12,50\u{00A0}€", RenderDataBuilder::format(-12.5, 'number', 'currency'));
    self::assertSame("0,00\u{00A0}€", RenderDataBuilder::format(0, 'number', 'currency'));
    self::assertSame('12.345.678.901.234.567', RenderDataBuilder::format('12345678901234567', 'number'));
    self::assertSame("9.007.199.254.740.992,01\u{00A0}€", RenderDataBuilder::format('9007199254740992.01', 'number', 'currency'));
    self::assertSame('0,12345678901234567890', RenderDataBuilder::format('0.12345678901234567890', 'number'));
    self::assertSame('0,0000000001', RenderDataBuilder::format('1e-10', 'number'));
    self::assertSame("10,00\u{00A0}€", RenderDataBuilder::format(9.995, 'number', 'currency'));
    self::assertSame('001234', RenderDataBuilder::format('001234', 'text'));
    self::assertSame('001234', RenderDataBuilder::format('001234', 'number', 'postal_code'));
    self::assertSame('1200 Euro', RenderDataBuilder::format('1200 Euro', 'text'));
    self::assertSame('1200 Euro', RenderDataBuilder::format('1200 Euro', 'number', 'currency'));
    self::assertNull(RenderDataBuilder::format(INF, 'number'));
    self::assertNull(RenderDataBuilder::format(NAN, 'number'));
    self::assertNull(RenderDataBuilder::format([ApplicationDataProtectionService::REDACTED_MARKER => TRUE], 'number', 'currency'));
  }

  /**
   * Ambiguous stored strings are never reinterpreted as decimal amounts.
   */
  public function testAmbiguousNumbersRemainRaw(): void {
    foreach (['1.200', '1,200', '-12.345', '+123,456', ' 1.200 '] as $value) {
      self::assertSame($value, RenderDataBuilder::format($value, 'number'));
      self::assertSame($value, RenderDataBuilder::format($value, 'number', 'currency'));
    }
    self::assertSame('0,125', RenderDataBuilder::format('0.125', 'number'));
    self::assertSame("0,13\u{00A0}€", RenderDataBuilder::format('0,125', 'number', 'currency'));
  }

  /**
   * Addresses use postal parts and never append coordinates or metadata.
   */
  public function testPostalAddress(): void {
    $address = [
      'street' => 'Beispielweg',
      'houseNumber' => '4a',
      'postcode' => '01234',
      'city' => 'Beispielstadt',
      'lat' => 50.1,
      'lng' => 7.2,
      'countryCode' => 'DE',
      'formatted' => 'Duplicated geocoder display',
    ];
    self::assertSame('Beispielweg 4a, 01234 Beispielstadt', RenderDataBuilder::format($address, 'address'));
    $partial = ['city' => ' Beispielstadt ', 'country' => 'Deutschland'];
    self::assertSame('Beispielstadt, Deutschland', RenderDataBuilder::format($partial, 'address'));
    self::assertSame('Beispielweg 4a, Beispielstadt', RenderDataBuilder::format(['formatted' => " Beispielweg 4a,\nBeispielstadt "], 'address'));
    self::assertSame('Beispielweg 4a', RenderDataBuilder::format('Beispielweg 4a', 'address'));
    self::assertNull(RenderDataBuilder::format(['lat' => 50.1, 'lng' => 7.2], 'address'));
    self::assertNull(RenderDataBuilder::format($address + [ApplicationDataProtectionService::REDACTED_MARKER => TRUE], 'address'));
  }

}
