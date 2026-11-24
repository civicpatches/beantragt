<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Service;

/**
 * Validates a published default without creating or changing a citizen answer.
 */
final class PublicFieldDefault {

  /**
   * Returns a supported primitive default, or NULL when it is invalid.
   *
   * @param array<string, mixed> $field
   *   The already projected public field definition.
   * @param mixed $value
   *   Candidate default from the stored schema.
   *
   * @return string|int|float|bool|null
   *   Valid default with its original type and spelling.
   */
  public static function project(array $field, mixed $value): string|int|float|bool|null {
    $type = $field['type'];
    $validation = is_array($field['validation'] ?? NULL) ? $field['validation'] : [];
    $finite = static fn(mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float) $value);
    if ($type === 'checkbox') {
      return empty($field['options']) && is_bool($value) ? $value : NULL;
    }
    if ($type === 'number') {
      if (!is_int($value) && !is_float($value) && !is_string($value)) {
        return NULL;
      }
      if (is_string($value)) {
        if (strlen($value) > 10000) {
          return NULL;
        }
        $text = trim($value);
        if (strlen($text) > 10000 || !preg_match('/^[+-]?(?:[0-9]+(?:[.,][0-9]*)?|[.,][0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $text)) {
          return NULL;
        }
        if (preg_match('/^[+-]?([0-9]{1,3})[.,][0-9]{3}$/D', $text, $group) && trim($group[1], '0') !== '') {
          return NULL;
        }
        $number = (float) str_replace(',', '.', $text);
        $parts = preg_split('/[eE]/', str_replace(',', '.', $text));
        $mantissa = $parts[0];
        $digits = str_replace(['.', '+', '-'], '', $mantissa);
        if ($number == 0 && trim($digits, '0') !== '') {
          return NULL;
        }
        $fraction = str_contains($mantissa, '.') ? substr($mantissa, strpos($mantissa, '.') + 1) : '';
        $scale = strlen($fraction) - (int) ($parts[1] ?? 0);
        $integer = $scale <= 0 || trim(substr($digits, -(int) min($scale, strlen($digits))), '0') === '';
      }
      else {
        // JSON integers outside JavaScript's exact range change in the browser.
        if (floor($value) == $value && abs($value) > 9007199254740991) {
          return NULL;
        }
        $number = $value;
        $integer = floor($value) == $value;
      }
      if (!is_finite((float) $number)) {
        return NULL;
      }
      $min = $finite($validation['min'] ?? NULL) ? $validation['min'] : (($field['format'] ?? NULL) === 'currency' ? 0 : NULL);
      if (($min !== NULL && $number < $min)
        || ($finite($validation['max'] ?? NULL) && $number > $validation['max'])) {
        return NULL;
      }
      $step = $validation['step'] ?? NULL;
      $base = $validation['min'] ?? 0;
      if ($finite($step) && $step > 0 && floor($step) == $step && $finite($base) && floor($base) == $base && !$integer) {
        return NULL;
      }
      $effective_step = $finite($step) ? $step : (($field['format'] ?? NULL) === 'currency' ? 0.01 : NULL);
      if ($effective_step !== NULL && $effective_step > 0) {
        $remainder = abs(($number - ($finite($validation['min'] ?? NULL) ? $validation['min'] : 0)) / $effective_step);
        if (!is_finite($remainder) || abs($remainder - round($remainder)) > 0.0000001) {
          return NULL;
        }
      }
      return $value;
    }
    if (($field['required'] ?? FALSE) === TRUE && is_string($value) && preg_match('/^\s*$/uD', $value) === 1) {
      return NULL;
    }
    if (!in_array($type, ['text', 'textarea', 'email', 'tel', 'date', 'select', 'radio'], TRUE)
      || !is_string($value) || strlen($value) > 40000 || mb_strlen($value) > 10000) {
      return NULL;
    }
    $trimmed = trim($value);
    $length = mb_strlen($trimmed);
    if ($trimmed !== '' && !self::validFormat($field, $trimmed)) {
      return NULL;
    }
    if ($trimmed !== '' && is_string($validation['pattern'] ?? NULL) && !self::validPattern($validation['pattern'], $trimmed)) {
      return NULL;
    }
    if (($finite($validation['minLength'] ?? NULL) && $length < $validation['minLength'])
      || ($finite($validation['maxLength'] ?? NULL) && $length > $validation['maxLength'])) {
      return NULL;
    }
    if (in_array($type, ['select', 'radio'], TRUE) && !in_array($value, $field['options'] ?? [], TRUE)) {
      return NULL;
    }
    if ($type === 'date' && (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $date)
      || !checkdate((int) $date[2], (int) $date[3], (int) $date[1]))) {
      return NULL;
    }
    return $value;
  }

  /**
   * Defaults use only the same bounded patterns the citizen renderer runs.
   */
  private static function validPattern(string $pattern, string $value): bool {
    if (trim($pattern) === '') {
      return TRUE;
    }
    $allowed = [
      '^[^\\s@]{1,64}@[^\\s@]{1,190}\\.[^\\s@]{2,63}$',
      '^[+0-9()\\-/\\s]{6,20}$',
      '^\\d{4}-\\d{2}-\\d{2}$',
      '^[0-9]{5}$',
      '^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{11,30}$',
      '^[A-Za-z]{6}[A-Za-z0-9]{2}[A-Za-z0-9]{0,3}$',
      '^[0-9]{11}$',
    ];
    if (!in_array($pattern, $allowed, TRUE)) {
      return FALSE;
    }
    return preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', $value) === 1;
  }

  /**
   * Mirrors the public submission type and built-in format checks.
   */
  private static function validFormat(array $field, string $value): bool {
    if ($field['type'] === 'email') {
      return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $value) === 1;
    }
    if ($field['type'] === 'tel') {
      return preg_match('/^[+0-9()\-\/\s]{6,20}$/u', $value) === 1;
    }
    return match ($field['format'] ?? NULL) {
      'postal_code' => preg_match('/^[0-9]{5}$/D', $value) === 1,
      'iban' => preg_match('/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{11,30}$/D', $value) === 1,
      'bic' => preg_match('/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/D', $value) === 1,
      'tax_id' => preg_match('/^[0-9]{11}$/D', $value) === 1,
      'url' => filter_var($value, FILTER_VALIDATE_URL) !== FALSE
      && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], TRUE),
      default => TRUE,
    };
  }

}
