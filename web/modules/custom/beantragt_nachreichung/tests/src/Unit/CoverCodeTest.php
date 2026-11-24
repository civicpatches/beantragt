<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_nachreichung\Unit;

use Drupal\beantragt_nachreichung\Code\CoverCode;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Cover sheet identifier: format, normalization and error detection.
 */
#[Group('beantragt_nachreichung')]
final class CoverCodeTest extends UnitTestCase {

  /**
   * Seventeen alphabet characters, a valid check, payload and grouping.
   */
  public function testFormat(): void {
    $code = CoverCode::generate();
    $this->assertSame(17, strlen($code));
    $this->assertSame(17, strspn($code, CoverCode::ALPHABET));
    $this->assertTrue(CoverCode::isValid($code));
    $payload = CoverCode::payload($code);
    $this->assertSame(21, strlen($payload));
    $this->assertMatchesRegularExpression('/^BA1-[0-9A-HJKMNP-TV-Z]{17}$/D', $payload);
    $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}-[0-9A-HJKMNP-TV-Z]$/D', CoverCode::group($code));
    $this->assertNotSame($code, CoverCode::generate());
  }

  /**
   * Manual input is normalized; anything else is NULL.
   */
  public function testNormalize(): void {
    $code = CoverCode::generate();
    $grouped = CoverCode::group($code);
    $this->assertSame($code, CoverCode::normalize(strtolower($grouped)));
    $this->assertSame($code, CoverCode::normalize(' ' . str_replace('-', ' ', $grouped) . ' '));
    $this->assertSame($code, CoverCode::normalize('BA1-' . $code));
    $this->assertSame($code, CoverCode::normalize('ba1' . $grouped));
    $body = substr('0123456789ABCDEF', 0, 16);
    $valid = $body . CoverCode::checkCharacter($body);
    $this->assertSame($valid, CoverCode::normalize(strtr($valid, ['0' => 'O', '1' => 'l'])));
    $this->assertSame($valid, CoverCode::normalize(strtr($valid, ['1' => 'I'])));
    $this->assertNull(CoverCode::normalize(''));
    $this->assertNull(CoverCode::normalize(substr($code, 0, 16)));
    $this->assertNull(CoverCode::normalize($code . '0'));
    $this->assertNull(CoverCode::normalize('U' . substr($code, 1)));
  }

  /**
   * Every single substitution and adjacent transposition is detected.
   */
  public function testEverySubstitutionAndAdjacentTranspositionIsDetected(): void {
    $alphabet = str_split(CoverCode::ALPHABET);
    for ($round = 0; $round < 200; $round++) {
      $code = CoverCode::generate();
      for ($position = 0; $position < 17; $position++) {
        foreach ($alphabet as $character) {
          if ($character === $code[$position]) {
            continue;
          }
          $changed = $code;
          $changed[$position] = $character;
          $this->assertFalse(CoverCode::isValid($changed));
        }
        if ($position < 16 && $code[$position] !== $code[$position + 1]) {
          $swapped = $code;
          $swapped[$position] = $code[$position + 1];
          $swapped[$position + 1] = $code[$position];
          $this->assertFalse(CoverCode::isValid($swapped));
        }
      }
    }
  }

  /**
   * The pair 0 and Z: Luhn mod 32 would miss this transposition.
   */
  public function testZeroAndZedTranspositionIsDetected(): void {
    foreach (['0Z', 'Z0'] as $pair) {
      for ($position = 0; $position < 15; $position++) {
        $body = substr_replace(str_repeat('7', 16), $pair, $position, 2);
        $code = $body . CoverCode::checkCharacter($body);
        $this->assertTrue(CoverCode::isValid($code));
        $swapped = substr_replace($code, strrev($pair), $position, 2);
        $this->assertFalse(CoverCode::isValid($swapped), $pair . ' at ' . $position);
      }
      // Transposition with the check character itself.
      $body = str_repeat('7', 15) . $pair[0];
      $code = $body . CoverCode::checkCharacter($body);
      if ($code[15] !== $code[16]) {
        $this->assertFalse(CoverCode::isValid(substr($code, 0, 15) . $code[16] . $code[15]));
      }
    }
  }

  /**
   * Payload and grouping refuse invalid identifiers.
   */
  public function testInvalidIdentifierIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    CoverCode::payload('0000000000000000Z');
  }

}
