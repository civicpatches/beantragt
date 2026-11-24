<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Code;

/**
 * The cover sheet identifier: 80 random bits plus one check character.
 *
 * Alphabet: Crockford base32 without I, L, O and U. The check character is
 * a polynomial check over GF(32) with the field polynomial x^5 + x^2 + 1,
 * not Luhn mod 32: Luhn mod 32 misses the adjacent transposition of 0 and Z,
 * this check detects every single substitution and every adjacent
 * transposition, including transpositions with the check character.
 *
 * The identifier is never stored in clear (Variant A, see CoverCodeHasher)
 * and never written to logs, events or API answers.
 */
final class CoverCode {

  public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  public const LENGTH = 17;
  public const PREFIX = 'BA1-';
  public const PAYLOAD_PATTERN = '/^BA1-[0-9A-HJKMNP-TV-Z]{17}$/D';

  /**
   * Returns a new identifier of 16 random characters and the check character.
   */
  public static function generate(): string {
    $bits = '';
    foreach (str_split(random_bytes(10)) as $byte) {
      $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $code = '';
    foreach (str_split($bits, 5) as $chunk) {
      $code .= self::ALPHABET[bindec($chunk)];
    }
    return $code . self::checkCharacter($code);
  }

  /**
   * Returns the check character for 16 alphabet characters.
   */
  public static function checkCharacter(string $body): string {
    if (strlen($body) !== self::LENGTH - 1 || strspn($body, self::ALPHABET) !== strlen($body)) {
      throw new \InvalidArgumentException('The identifier body needs 16 alphabet characters.');
    }
    return self::ALPHABET[self::mul2(self::accumulate($body))];
  }

  /**
   * TRUE for 17 alphabet characters with a valid check character.
   */
  public static function isValid(string $code): bool {
    return strlen($code) === self::LENGTH
      && strspn($code, self::ALPHABET) === self::LENGTH
      && self::accumulate($code) === 0;
  }

  /**
   * Normalizes manual input; NULL when it is no valid identifier.
   *
   * Uppercase, without spaces and dashes, O read as 0, I and L read as 1,
   * the prefix BA1 is optional.
   */
  public static function normalize(string $input): ?string {
    $code = strtr(strtoupper(str_replace([' ', '-', "\t"], '', $input)), ['O' => '0', 'I' => '1', 'L' => '1']);
    if (strlen($code) === self::LENGTH + 3 && str_starts_with($code, 'BA1')) {
      $code = substr($code, 3);
    }
    return self::isValid($code) ? $code : NULL;
  }

  /**
   * The QR content: prefix plus identifier, 21 alphanumeric characters.
   */
  public static function payload(string $code): string {
    self::assertValid($code);
    return self::PREFIX . $code;
  }

  /**
   * The identifier in groups of four for print, like XXXX-XXXX-XXXX-XXXX-X.
   */
  public static function group(string $code): string {
    self::assertValid($code);
    return implode('-', str_split($code, 4));
  }

  /**
   * Throws unless the code is a valid identifier.
   */
  private static function assertValid(string $code): void {
    if (!self::isValid($code)) {
      throw new \InvalidArgumentException('Invalid cover sheet identifier.');
    }
  }

  /**
   * Horner evaluation over GF(32): i = mul2(i) XOR value(c) per character.
   */
  private static function accumulate(string $characters): int {
    $interim = 0;
    foreach (str_split($characters) as $character) {
      $value = strpos(self::ALPHABET, $character);
      if ($value === FALSE) {
        return -1;
      }
      $interim = self::mul2($interim) ^ $value;
    }
    return $interim;
  }

  /**
   * Multiplies by x in GF(32) with the field polynomial x^5 + x^2 + 1.
   */
  private static function mul2(int $value): int {
    $value <<= 1;
    return ($value & 0x20) !== 0 ? $value ^ 0x25 : $value;
  }

}
