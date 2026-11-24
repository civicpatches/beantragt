<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates public application submission payload data.
 */
final class PublicApplicationSubmissionValidator {

  /**
   * Constructs the validator with the shared FormSchema scalar parser.
   */
  public function __construct(
    private readonly FormValueParser $valueParser = new FormValueParser(),
    private readonly ?TranslationInterface $translation = NULL,
  ) {}

  private const MAX_FIELDS = 200;
  private const MAX_ATTACHMENTS_PER_FIELD = 5;
  private const MAX_STRING_BYTES = 50000;
  private const MAX_ARRAY_ITEMS = 100;
  private const MAX_ARRAY_ITEM_BYTES = 1000;
  private const UNSAFE_DATA_KEYS = [
    '__proto__',
    'constructor',
    'prototype',
    '_anonymized',
    '__beantragt_encrypted',
    '__beantragt_redacted',
  ];
  private const MAX_VALIDATION_PATTERN_LENGTH = 160;
  private const VALIDATION_PATTERN_MATCH_LIMIT = 10000;
  private const VALIDATION_PATTERN_RECURSION_LIMIT = 1000;
  private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/u';
  private const FIELD_TYPES = [
    'text',
    'textarea',
    'email',
    'tel',
    'number',
    'date',
    'select',
    'radio',
    'checkbox',
    'address',
    'file',
  ];
  private const STRING_VALUE_FIELD_TYPES = [
    'text',
    'textarea',
    'email',
    'tel',
    'date',
    'select',
    'radio',
  ];
  private const RESERVED_DATA_KEYS = [
    'applicant_full_name',
    'applicant_email',
  ];

  /**
   * Normalizes one stored schema field for submission validation.
   *
   * @param array<string, mixed> $field
   *   Raw schema field.
   *
   * @return array<string, mixed>|null
   *   Normalized field or NULL when not part of the public contract.
   */
  public function normalizeField(array $field): ?array {
    $type = $field['type'] ?? NULL;
    if (!is_string($type) || !in_array($type, self::FIELD_TYPES, TRUE)) {
      return NULL;
    }
    $normalized = [
      'id' => (string) $field['id'],
      'type' => $type,
      'required' => ($field['required'] ?? FALSE) === TRUE,
      'options' => $this->stringArray($field['options'] ?? NULL),
      'format' => is_string($field['format'] ?? NULL) ? $field['format'] : NULL,
      'validation' => is_array($field['validation'] ?? NULL) ? $field['validation'] : [],
      'conditionalOn' => is_array($field['conditionalOn'] ?? NULL) ? $field['conditionalOn'] : NULL,
    ];
    foreach (['maxFiles', 'maxSizeBytes', 'maxFileSizeBytes'] as $quota_key) {
      if (array_key_exists($quota_key, $field)) {
        $normalized[$quota_key] = $field[$quota_key];
      }
    }
    if (is_string($field['crm_key'] ?? NULL)) {
      $normalized['crm_key'] = $field['crm_key'];
    }
    return $normalized;
  }

  /**
   * Normalizes stored auth policy.
   *
   * @return array{mode: string, collect: array<string, mixed>}
   *   Normalized auth policy.
   */
  public function normalizeAuth(mixed $auth): array {
    if (!is_array($auth) || array_is_list($auth)) {
      return ['mode' => 'anonymous', 'collect' => []];
    }
    $mode = is_string($auth['mode'] ?? NULL) && in_array($auth['mode'], ['anonymous', 'optional', 'required'], TRUE)
        ? $auth['mode']
        : 'required';
    $collect = [];
    if (is_array($auth['collect'] ?? NULL) && !array_is_list($auth['collect'])) {
      foreach (['fullName', 'email'] as $key) {
        $value = $auth['collect'][$key] ?? NULL;
        if (is_bool($value) || in_array($value, ['required', 'optional', 'none'], TRUE)) {
          $collect[$key] = $value;
        }
      }
    }
    return ['mode' => $mode, 'collect' => $collect];
  }

  /**
   * Bounds submitted data to the public field map.
   *
   * @param array<string, mixed> $data
   *   Raw data object.
   *
   * @return array{data: array<string, mixed>, error: array<string, mixed>|null}
   *   Bounded data and optional error.
   */
  public function boundedSubmittedData(array $data): array {
    if (count($data) > self::MAX_FIELDS) {
      return [
        'data' => [],
        'error' => [
          'code' => 'application.too_many_fields',
          'message' => 'Application data contains too many fields.',
          'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        ],
      ];
    }

    $reserved = array_fill_keys(self::RESERVED_DATA_KEYS, TRUE);
    $out = [];
    foreach ($data as $key => $value) {
      if (isset($reserved[$key])) {
        continue;
      }
      $error = $this->submittedValueLimitError($key, $value);
      if ($error !== NULL) {
        return ['data' => [], 'error' => $error];
      }
      $out[$key] = $value;
    }
    return ['data' => $out, 'error' => NULL];
  }

  /**
   * Sanitizes and validates submitted values against the public fields.
   *
   * @param array<string, mixed> $data
   *   Raw submitted data.
   * @param array<string, mixed> $context
   *   Submission context.
   * @param array<string, mixed> $attachment_claims_by_field
   *   Validated attachment claim metadata keyed by field id.
   *
   * @return array{data: array<string, mixed>, error: array<string, mixed>|null}
   *   Sanitized data and optional error.
   */
  public function sanitizeAndValidateData(array $data, array $context, array $attachment_claims_by_field = []): array {
    $sanitized = [];
    $field_lookup = [];
    $parsed = [];
    foreach ($context['fields'] as $field) {
      $field_lookup[$field['id']] = $field;
      if ($field['type'] === 'file') {
        if (isset($attachment_claims_by_field[$field['id']])) {
          $sanitized[$field['id']] = $attachment_claims_by_field[$field['id']];
        }
      }
      elseif (array_key_exists($field['id'], $data)) {
        $value = $data[$field['id']];
        $parsed[$field['id']] = $this->valueParser->parse($field, $value);
        $sanitized[$field['id']] = $field['type'] === 'address'
          ? ($this->normalizeAddressValue($value) ?? $value)
          : $parsed[$field['id']]['value'];
      }
    }

    foreach ($field_lookup as $field) {
      if (!$this->isFieldVisible($field, $sanitized)) {
        unset($sanitized[$field['id']]);
        continue;
      }
      $value = $sanitized[$field['id']] ?? NULL;
      $issue = $parsed[$field['id']]['issue'] ?? $this->validateFieldValue($field, $value, $parsed[$field['id']]['number'] ?? NULL);
      if ($issue !== NULL) {
        return [
          'data' => $sanitized,
          'error' => [
            'code' => 'application.validation_failed',
            'message' => $this->issueMessage($issue),
            'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'details' => [
              'field' => $field['id'],
              'issue' => $issue,
            ],
          ],
        ];
      }
    }

    return ['data' => $sanitized, 'error' => NULL];
  }

  /**
   * Validates one field value and returns the first issue code.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param mixed $value
   *   Submitted field value.
   * @param int|float|null $number
   *   Numeric representation already parsed from the submitted value.
   */
  private function validateFieldValue(array $field, mixed $value, int|float|null $number = NULL): ?string {
    if ($this->isEmptyValue($value)) {
      return $field['required'] ? 'required' : NULL;
    }
    if ($field['type'] === 'file') {
      if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ATTACHMENTS_PER_FIELD) {
        return 'format';
      }
      foreach ($value as $item) {
        if (!is_array($item)
          || !is_string($item['attachmentId'] ?? NULL)
          || !is_string($item['filename'] ?? NULL)) {
          return 'format';
        }
      }
      return NULL;
    }
    if ($field['type'] === 'address') {
      return $this->normalizeAddressValue($value) === NULL ? 'format' : NULL;
    }
    if (in_array($field['type'], ['select', 'radio'], TRUE) && $field['options'] === []) {
      return 'options_missing';
    }
    if (in_array($field['type'], self::STRING_VALUE_FIELD_TYPES, TRUE) && !is_string($value)) {
      return 'format';
    }
    if ($field['options'] !== []) {
      $allowed = array_fill_keys($field['options'], TRUE);
      if ($field['type'] === 'checkbox') {
        if (!is_array($value) || !array_is_list($value)
          || array_filter($value, static fn(mixed $item): bool => !is_string($item) || !isset($allowed[$item])) !== []) {
          return 'option';
        }
      }
      elseif (in_array($field['type'], ['select', 'radio'], TRUE) && (!is_string($value) || !isset($allowed[$value]))) {
        return 'option';
      }
    }
    if (is_string($value)) {
      $trimmed = trim($value);
      if (!$this->validFieldFormat($field, $trimmed)) {
        return 'format';
      }
      foreach ($this->stringValidationIssues($field, $trimmed) as $issue) {
        return $issue;
      }
    }
    if ($field['type'] === 'number') {
      return $number === NULL ? 'number_format' : $this->numberValidationIssue($field, $number);
    }
    return NULL;
  }

  /**
   * Returns TRUE when a string value passes default type and format checks.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param string $value
   *   Submitted string value.
   */
  private function validFieldFormat(array $field, string $value): bool {
    if ($field['type'] === 'email') {
      return preg_match(self::EMAIL_PATTERN, $value) === 1;
    }
    if ($field['type'] === 'tel') {
      return preg_match('/^[+0-9()\-\/\s]{6,20}$/u', $value) === 1;
    }
    return match ($field['format']) {
      'postal_code' => preg_match('/^[0-9]{5}$/', $value) === 1,
      'iban' => preg_match('/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{11,30}$/', $value) === 1,
      'bic' => preg_match('/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/', $value) === 1,
      'tax_id' => preg_match('/^[0-9]{11}$/', $value) === 1,
      'url' => filter_var($value, FILTER_VALIDATE_URL) !== FALSE
      && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], TRUE),
      default => TRUE,
    };
  }

  /**
   * Returns the first string validation issue code.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param string $value
   *   Submitted string value.
   *
   * @return string[]
   *   Issue codes.
   */
  private function stringValidationIssues(array $field, string $value): array {
    $validation = is_array($field['validation']) ? $field['validation'] : [];
    $issues = [];
    if (is_numeric($validation['minLength'] ?? NULL) && mb_strlen($value) < (int) $validation['minLength']) {
      $issues[] = 'minLength';
    }
    if (is_numeric($validation['maxLength'] ?? NULL) && mb_strlen($value) > (int) $validation['maxLength']) {
      $issues[] = 'maxLength';
    }
    if (is_string($validation['pattern'] ?? NULL) && strlen($validation['pattern']) <= self::MAX_VALIDATION_PATTERN_LENGTH) {
      if ($this->validationPatternMatches($validation['pattern'], $value) !== TRUE) {
        $issues[] = $field['format'] ? 'format' : 'pattern';
      }
    }
    return $issues;
  }

  /**
   * Runs a schema validation pattern with bounded PCRE resources.
   */
  private function validationPatternMatches(string $pattern, string $value): bool {
    $regex = sprintf(
      '/(*LIMIT_MATCH=%d)(*LIMIT_RECURSION=%d)%s/u',
      self::VALIDATION_PATTERN_MATCH_LIMIT,
      self::VALIDATION_PATTERN_RECURSION_LIMIT,
      str_replace('/', '\/', $pattern),
    );
    set_error_handler(static fn(): bool => TRUE);
    $matched = preg_match($regex, $value);
    restore_error_handler();
    return $matched === 1;
  }

  /**
   * Returns the first numeric validation issue code.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param int|float $number
   *   Parsed numeric value.
   */
  private function numberValidationIssue(array $field, int|float $number): ?string {
    $validation = is_array($field['validation']) ? $field['validation'] : [];
    $min = is_numeric($validation['min'] ?? NULL) ? (float) $validation['min'] : ($field['format'] === 'currency' ? 0.0 : NULL);
    if ($min !== NULL && $number < $min) {
      return 'min';
    }
    if (is_numeric($validation['max'] ?? NULL) && $number > (float) $validation['max']) {
      return 'max';
    }
    $step = is_numeric($validation['step'] ?? NULL) ? (float) $validation['step'] : ($field['format'] === 'currency' ? 0.01 : NULL);
    if ($step !== NULL && $step > 0) {
      $base = $min ?? 0.0;
      $remainder = abs(($number - $base) / $step);
      if (abs($remainder - round($remainder)) > 0.0000001) {
        return 'step';
      }
    }
    return NULL;
  }

  /**
   * Checks conditional field visibility.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param array<string, mixed> $data
   *   Sanitized submission data.
   */
  private function isFieldVisible(array $field, array $data): bool {
    return FormValueParser::conditionMatches($field['conditionalOn'], $data);
  }

  /**
   * Checks whether a submitted value is empty.
   */
  private function isEmptyValue(mixed $value): bool {
    return FormValueParser::isAbsent($value);
  }

  /**
   * Gives citizens an actionable translated explanation for scalar syntax.
   */
  private function issueMessage(string $issue): string {
    if ($issue === 'number_ambiguous') {
      return $this->translation !== NULL
        ? (string) $this->translation->translate('This number is ambiguous. Enter it without thousands separators, for example 1234 or 12,50. For three decimal places, use an exponent, for example 1,234e0.')
        : 'This number is ambiguous. Enter it without thousands separators, for example 1234 or 12,50. For three decimal places, use an exponent, for example 1,234e0.';
    }
    if ($issue === 'number_format') {
      return $this->translation !== NULL
        ? (string) $this->translation->translate('Enter a finite number with one decimal comma or decimal point and no thousands separators, for example 12,50.')
        : 'Enter a finite number with one decimal comma or decimal point and no thousands separators, for example 12,50.';
    }
    if ($issue === 'date') {
      return $this->translation !== NULL
        ? (string) $this->translation->translate('Enter a valid calendar date in YYYY-MM-DD format.')
        : 'Enter a valid calendar date in YYYY-MM-DD format.';
    }
    return $this->translation !== NULL
      ? (string) $this->translation->translate('Application data failed validation.')
      : 'Application data failed validation.';
  }

  /**
   * Builds a limit error for a submitted data value.
   */
  private function submittedValueLimitError(string|int $field, mixed $value): ?array {
    if (!is_string($field) || in_array($field, self::UNSAFE_DATA_KEYS, TRUE)) {
      return [
        'code' => 'application.invalid_key',
        'message' => 'Application data contains an invalid key.',
        'status' => Response::HTTP_BAD_REQUEST,
        'details' => ['field' => (string) $field],
      ];
    }
    if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
      return [
        'code' => 'application.field_too_large',
        'message' => 'Application field value is too large.',
        'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        'details' => ['field' => (string) $field],
      ];
    }
    if (!is_array($value)) {
      return NULL;
    }
    if (!array_is_list($value)) {
      if ($this->isBoundedAddressObject($value)) {
        return NULL;
      }
      return [
        'code' => 'application.object_unsupported',
        'message' => 'Application object values are not supported.',
        'status' => Response::HTTP_BAD_REQUEST,
        'details' => ['field' => (string) $field],
      ];
    }
    if (count($value) > self::MAX_ARRAY_ITEMS) {
      return [
        'code' => 'application.array_too_large',
        'message' => 'Application array field contains too many values.',
        'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        'details' => ['field' => (string) $field],
      ];
    }
    foreach ($value as $item) {
      if (is_array($item)) {
        return [
          'code' => 'application.array_item_invalid',
          'message' => 'Application array field values must be scalar.',
          'status' => Response::HTTP_BAD_REQUEST,
          'details' => ['field' => (string) $field],
        ];
      }
      if (is_string($item) && strlen($item) > self::MAX_ARRAY_ITEM_BYTES) {
        return [
          'code' => 'application.array_item_too_large',
          'message' => 'Application array field value is too large.',
          'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
          'details' => ['field' => (string) $field],
        ];
      }
    }
    return NULL;
  }

  /**
   * Returns TRUE for a bounded canonical address object.
   *
   * This shape check happens before the schema is loaded. Other object values
   * remain unsupported and address fields are validated again against their
   * schema type before persistence.
   */
  private function isBoundedAddressObject(array $value): bool {
    $string_keys = [
      'street',
      'houseNumber',
      'postcode',
      'city',
      'district',
      'state',
      'country',
      'countryCode',
      'formatted',
    ];
    $allowed = array_fill_keys([...$string_keys, 'lat', 'lng'], TRUE);
    foreach ($value as $key => $item) {
      if (!is_string($key) || !isset($allowed[$key])) {
        return FALSE;
      }
      if (in_array($key, $string_keys, TRUE)) {
        if (!is_string($item) || strlen($item) > ($key === 'formatted' ? 500 : 200)) {
          return FALSE;
        }
      }
      elseif (!is_int($item) && !is_float($item)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Normalizes the allowlisted structured address value.
   *
   * @return array<string, int|float|string>|null
   *   Canonical address value, or NULL when no displayable address exists.
   */
  private function normalizeAddressValue(mixed $value): ?array {
    if (!is_array($value) || array_is_list($value) || !$this->isBoundedAddressObject($value)) {
      return NULL;
    }

    $normalized = [];
    foreach (['street', 'houseNumber', 'postcode', 'city', 'district', 'state', 'country', 'countryCode'] as $key) {
      $part = trim((string) ($value[$key] ?? ''));
      if ($part !== '') {
        $normalized[$key] = $key === 'countryCode'
          ? strtoupper(substr($part, 0, 2))
          : $part;
      }
    }
    $formatted = trim((string) ($value['formatted'] ?? ''));
    if ($formatted === '') {
      $street = trim(implode(' ', array_filter([
        $normalized['street'] ?? NULL,
        $normalized['houseNumber'] ?? NULL,
      ])));
      $locality = trim(implode(' ', array_filter([
        $normalized['postcode'] ?? NULL,
        $normalized['city'] ?? NULL,
      ])));
      $formatted = implode(', ', array_filter([$street, $locality]));
    }
    if ($formatted === '') {
      return NULL;
    }
    $normalized['formatted'] = substr($formatted, 0, 500);

    foreach (['lat' => [-90.0, 90.0], 'lng' => [-180.0, 180.0]] as $key => [$min, $max]) {
      $coordinate = $value[$key] ?? NULL;
      if ((is_int($coordinate) || is_float($coordinate)) && is_finite((float) $coordinate)
        && (float) $coordinate >= $min && (float) $coordinate <= $max) {
        $normalized[$key] = $coordinate;
      }
    }
    return $normalized;
  }

  /**
   * Reads a string array.
   *
   * @return string[]
   *   String values.
   */
  private function stringArray(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item)));
  }

}
