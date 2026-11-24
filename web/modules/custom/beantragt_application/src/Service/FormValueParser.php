<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Parses FormSchema scalar values identically at submission and delivery.
 */
final class FormValueParser {

  /**
   * Evaluates conditional visibility exactly as the citizen form renderer.
   *
   * The one rule of every PHP evaluator (citizen submission, batch intake,
   * staff data edit), a port of isConditionMet() in
   * frontend/app/utils/form-conditional.ts, because the renderer decides what
   * the citizen saw. A dependency sent as a number (int or float) is first
   * turned into the text JavaScript's String() prints, because the page holds
   * a number field as text: 0 and "0" decide alike. Then:
   * - no "value" key: met when the dependency is a non-empty list, a string
   *   that is not blank after a JavaScript trim(), or else truthy in the
   *   JavaScript sense (FALSE, NULL and a missing key are not met; a number
   *   is stored as text, so 0 is met);
   * - a boolean value: the dependency's JavaScript truthiness (every list is
   *   truthy, also an empty one; "0" is truthy);
   * - a string value: String(dependency) === value, with JavaScript's
   *   String() (a list joins its elements with ",", NULL is "null", a
   *   missing key "undefined", TRUE "true", numbers as JavaScript prints
   *   them);
   * - any other value (a number, NULL, a list): treated as absent, so the
   *   not-empty rule applies. The citizen's page never sees such a value: the
   *   public schema projections keep "value" only when it is a string or a
   *   boolean (frontend/server/api/schema/[id].get.ts conditionalProjection(),
   *   PublicFormApiController::conditionalProjection()), and the page then
   *   evaluates the not-empty rule.
   * A condition without a string fieldId restricts nothing. The case table
   * shared with the frontend is tests/fixtures/condition-cases.json.
   */
  public static function conditionMatches(mixed $conditional, array $data): bool {
    if (!is_array($conditional) || !is_string($conditional['fieldId'] ?? NULL)) {
      return TRUE;
    }
    $field_id = $conditional['fieldId'];
    $defined = array_key_exists($field_id, $data);
    $dependency = $defined ? $data[$field_id] : NULL;
    // The citizen's page holds a number field as text (FormRenderer.vue
    // renders it as a text input and stores the typed string), so a number
    // is judged as the text JavaScript's String() prints: 0 and "0" decide
    // a condition alike, whatever spelling a request or manifest uses.
    if (is_int($dependency) || is_float($dependency)) {
      $dependency = self::jsString($dependency);
    }
    $expected = $conditional['value'] ?? NULL;
    if (!is_string($expected) && !is_bool($expected)) {
      if (is_array($dependency)) {
        return !array_is_list($dependency) || $dependency !== [];
      }
      if (is_string($dependency)) {
        return self::jsTrim($dependency) !== '';
      }
      return self::jsTruthy($dependency);
    }
    if (is_bool($expected)) {
      return $expected ? self::jsTruthy($dependency) : !self::jsTruthy($dependency);
    }
    return ($defined ? self::jsString($dependency) : 'undefined') === $expected;
  }

  /**
   * JavaScript truthiness of a decoded JSON value.
   */
  private static function jsTruthy(mixed $value): bool {
    return match (TRUE) {
      $value === NULL => FALSE,
      is_bool($value) => $value,
      is_int($value) => $value !== 0,
      is_float($value) => $value != 0.0 && !is_nan($value),
      is_string($value) => $value !== '',
      // Arrays and objects are truthy in JavaScript, also when empty.
      default => TRUE,
    };
  }

  /**
   * JavaScript String() of a decoded JSON value.
   *
   * A JSON object (an array with keys) is "[object Object]"; an empty array
   * cannot tell [] from {} after decoding and is read as [].
   */
  private static function jsString(mixed $value): string {
    if ($value === NULL) {
      return 'null';
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
      return (string) $value;
    }
    if (is_float($value)) {
      return self::jsNumberString($value);
    }
    if (is_string($value)) {
      return $value;
    }
    if (is_array($value) && array_is_list($value)) {
      // Array.prototype.join: NULL elements become "".
      return implode(',', array_map(
        static fn(mixed $item): string => $item === NULL ? '' : self::jsString($item),
        $value,
      ));
    }
    return '[object Object]';
  }

  /**
   * A float as JavaScript's Number.prototype.toString() prints it.
   *
   * The shortest round-trip digits (the smallest precision whose printed
   * value parses back to the same float), then ECMAScript's layout: plain
   * digits for decimal exponents from -6 to 20, else exponent notation as
   * "1e+21" or "1.5e-7". Integral floats print without ".0"; -0 is "0".
   */
  private static function jsNumberString(float $value): string {
    if (is_nan($value)) {
      return 'NaN';
    }
    if (is_infinite($value)) {
      return $value > 0 ? 'Infinity' : '-Infinity';
    }
    if ($value == 0.0) {
      return '0';
    }
    $sign = $value < 0 ? '-' : '';
    $absolute = abs($value);
    $printed = sprintf('%.16e', $absolute);
    for ($precision = 0; $precision <= 16; $precision++) {
      $candidate = sprintf('%.' . $precision . 'e', $absolute);
      if ((float) $candidate === $absolute) {
        $printed = $candidate;
        break;
      }
    }
    [$mantissa, $exponent] = explode('e', $printed);
    $digits = rtrim(str_replace('.', '', $mantissa), '0');
    $digits = $digits === '' ? '0' : $digits;
    $count = strlen($digits);
    // Decimal point position: value = 0.digits * 10^$point.
    $point = (int) $exponent + 1;
    if ($count <= $point && $point <= 21) {
      return $sign . $digits . str_repeat('0', $point - $count);
    }
    if (0 < $point && $point <= 21) {
      return $sign . substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
    if (-6 < $point && $point <= 0) {
      return $sign . '0.' . str_repeat('0', -$point) . $digits;
    }
    $shown = $point - 1;
    $tail = $count > 1 ? '.' . substr($digits, 1) : '';
    return $sign . $digits[0] . $tail . 'e' . ($shown < 0 ? '-' : '+') . abs($shown);
  }

  /**
   * JavaScript String.prototype.trim(): Unicode white space and line ends.
   */
  private static function jsTrim(string $value): string {
    $space = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    $trimmed = preg_replace('/^[' . $space . ']+|[' . $space . ']+$/uD', '', $value);
    // Invalid UTF-8 cannot come from JSON; keep the value as it is then.
    return $trimmed ?? $value;
  }

  /**
   * Recognizes renderer defaults; zero and false are actual answers.
   */
  public static function isAbsent(mixed $value): bool {
    return $value === NULL || $value === [] || (is_string($value) && preg_match('/^\s*$/uD', $value) === 1);
  }

  /**
   * Returns canonical storage, numeric representations and an issue.
   *
   * Numeric strings retain digits and scale, with outer whitespace removed and
   * a decimal comma replaced by a dot. JSON numbers retain their type.
   * Grouping separators are unsupported: a lone three-digit suffix following
   * one to three nonzero integer digits is ambiguous without an exponent.
   */
  public function parse(array $field, mixed $value): array {
    $result = ['value' => $value, 'number' => NULL, 'integer' => NULL, 'issue' => NULL];
    if (self::isAbsent($value)) {
      return $result;
    }
    if (in_array($field['type'], ['number', 'integer'], TRUE)) {
      $parsed = $this->number($value);
      if ($parsed['issue'] === NULL && self::hasIntegerDomain($field) && $parsed['integer'] === NULL) {
        $parsed['issue'] = 'step';
      }
      return $parsed;
    }
    if ($field['type'] === 'date') {
      if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
        $result['issue'] = 'date';
      }
    }
    if ($field['type'] === 'checkbox' && empty($field['options']) && !is_bool($value)) {
      $result['issue'] = 'format';
    }
    return $result;
  }

  /**
   * Parses decimal notation without locale guessing or integer rounding.
   */
  private function number(mixed $value): array {
    $result = ['value' => $value, 'number' => NULL, 'integer' => NULL, 'issue' => 'number_format'];
    if (is_int($value) || is_float($value)) {
      if (is_finite((float) $value)) {
        $result['number'] = $value;
        $result['integer'] = is_int($value) ? $value : (floor($value) === $value && $value < PHP_INT_MAX && $value >= PHP_INT_MIN ? (int) $value : NULL);
        $result['issue'] = NULL;
      }
      return $result;
    }
    if (!is_string($value) || strlen($value) > 50000) {
      return $result;
    }
    $canonical = preg_replace('/^\s+|\s+$/u', '', $value);
    if ($canonical === NULL || !preg_match('/^[+-]?(?:[0-9]+(?:[.,][0-9]*)?|[.,][0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $canonical)) {
      return $result;
    }
    if (preg_match('/^[+-]?([0-9]{1,3})[.,][0-9]{3}$/D', $canonical, $group) && trim($group[1], '0') !== '') {
      $result['issue'] = 'number_ambiguous';
      return $result;
    }
    $canonical = str_replace(',', '.', $canonical);
    if (!is_numeric($canonical)) {
      return $result;
    }
    $number = $canonical + 0;
    if (!is_finite((float) $number)) {
      return $result;
    }
    $parts = preg_split('/[eE]/', $canonical);
    $digits = str_replace(['.', '+', '-'], '', $parts[0]);
    // Reject underflow to zero rather than accepting a changed quantity.
    if ($number == 0 && trim($digits, '0') !== '') {
      return $result;
    }
    $fraction = str_contains($parts[0], '.') ? substr($parts[0], strpos($parts[0], '.') + 1) : '';
    $exponent = max(-50000, min(50000, (int) ($parts[1] ?? 0)));
    $scale = strlen($fraction) - $exponent;
    if ($scale <= 0 || trim(substr($digits, -$scale), '0') === '') {
      $whole = $scale > 0 ? substr($digits, 0, max(0, strlen($digits) - $scale)) : $digits . str_repeat('0', -$scale);
      $whole = ltrim($whole, '0');
      $negative = str_starts_with($canonical, '-');
      $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
      if (strlen($whole) < strlen($limit) || (strlen($whole) === strlen($limit) && strcmp($whole, $limit) <= 0)) {
        $result['integer'] = (int) (($negative ? '-' : '') . ($whole === '' ? '0' : $whole));
        if (abs($result['integer']) > 9007199254740991) {
          $number = $result['integer'];
        }
      }
    }
    return ['value' => $canonical, 'number' => $number, 'integer' => $result['integer'], 'issue' => NULL];
  }

  /**
   * Checks whether declared number constraints guarantee integral answers.
   */
  public static function hasIntegerDomain(array $field): bool {
    if ($field['type'] === 'integer') {
      return TRUE;
    }
    $step = $field['validation']['step'] ?? NULL;
    $base = $field['validation']['min'] ?? 0;
    return is_numeric($step) && is_numeric($base) && is_finite((float) $step) && is_finite((float) $base)
      && (float) $step > 0 && floor((float) $step) === (float) $step && floor((float) $base) === (float) $base;
  }

}
