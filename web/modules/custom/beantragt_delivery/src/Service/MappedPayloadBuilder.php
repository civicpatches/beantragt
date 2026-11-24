<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Service\FormValueParser;

/**
 * Executes immutable primitive mappings without IO or application mutation.
 */
final class MappedPayloadBuilder {

  public const MAX_STRING_BYTES = 1024;
  public const MAX_PAYLOAD_BYTES = 262144;
  private const MAX_ERRORS = 50;

  /**
   * Constructs the offline mapping executor.
   */
  public function __construct(
    private readonly DataContractRegistry $registry,
    private readonly IntegrationRules $rules,
    private readonly DataContractValidator $validator,
    private readonly FormValueParser $valueParser = new FormValueParser(),
  ) {}

  /**
   * Returns canonical validated data, or value-free errors and no payload.
   *
   * The caller supplies decrypted data from the pinned application revision.
   * String and total JSON bounds precede contract validation because the pinned
   * contract contains unanchored patterns with potentially quadratic work. No
   * extra postcode or format rule is imposed beyond that published contract.
   */
  public function build(array $data, array $rules, string $contract_key, string $contract_version, array $form = []): array {
    $rules = $this->rules->normalize($rules);
    $this->rules->validate($rules);
    $paths = array_column($this->registry->paths($contract_key, $contract_version), NULL, 'pointer');
    $mapped = array_column($rules['rules'], NULL, 'target');
    $occurrences = array_column($rules['occurrences'] ?? [], NULL, 'target');
    $values = [];
    $present = ['' => TRUE];
    $errors = [];
    $fields = $this->rules->fields($form);
    if ($form !== []) {
      $errors = $this->rules->check($rules, $form, $contract_key, $contract_version);
      if ($errors !== []) {
        return $this->failure(array_map(fn(array $error): array => $this->error($error['pointer'], 'type', $error['code'], $mapped[$error['pointer']]['source'] ?? NULL), $errors));
      }
    }
    foreach ($mapped as $pointer => $rule) {
      $source = $rule['source'];
      $field = $fields[$source] ?? NULL;
      $multiple = $field !== NULL && $this->rules->isMultiple($field);
      if (!isset($paths[$pointer]) || $paths[$pointer]['type'] === 'object' || ($paths[$pointer]['type'] === 'array' && !$multiple)) {
        $errors[] = $this->error($pointer, 'type', 'integration.target_unsupported', $source);
        continue;
      }
      if (!array_key_exists($source, $data) || self::isAbsent($data[$source])) {
        continue;
      }
      $value = $data[$source];
      if ($multiple) {
        if (!is_array($value) || !array_is_list($value)) {
          $errors[] = $this->error($pointer, 'type', 'integration.validation.type', $source);
          continue;
        }
        $selected = [];
        foreach ($value as $option) {
          if (!is_string($option) || !property_exists($rule['valueMap'], $option) || !in_array($option, $field['options'], TRUE)) {
            $errors[] = $this->error($pointer, 'enum', 'integration.value_unmapped', $source);
            continue;
          }
          if (strlen($option) > self::MAX_STRING_BYTES) {
            $errors[] = $this->error($pointer, 'maxLength', 'integration.mapped_string_too_large', $source);
          }
          $selected[$option] = TRUE;
        }
        $values[$pointer] = [];
        foreach ($field['options'] as $option) {
          if (isset($selected[$option])) {
            $mapped_value = $rule['valueMap']->{$option};
            if (is_string($mapped_value) && strlen($mapped_value) > self::MAX_STRING_BYTES) {
              $errors[] = $this->error($pointer, 'maxLength', 'integration.mapped_string_too_large', $source);
            }
            $values[$pointer][] = $mapped_value;
          }
        }
        continue;
      }
      if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
        $errors[] = $this->error($pointer, 'maxLength', 'integration.mapped_string_too_large', $source);
        continue;
      }
      if (isset($rule['valueMap'])) {
        // Object properties preserve numeric-looking string keys. Integers,
        // booleans and null are never cast into option names.
        if (!is_string($value) || !property_exists($rule['valueMap'], $value)) {
          $errors[] = $this->error($pointer, 'enum', 'integration.value_unmapped', $source);
          continue;
        }
        $value = $rule['valueMap']->{$value};
      }
      elseif ($field !== NULL) {
        [$value, $code] = $this->normalizeValue($value, $field, $paths[$pointer]);
        if ($code !== NULL) {
          $errors[] = $this->error($pointer, 'type', $code, $source);
          continue;
        }
      }
      if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
        $errors[] = $this->error($pointer, 'type', 'integration.validation.type', $source);
        continue;
      }
      if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
        $errors[] = $this->error($pointer, 'maxLength', 'integration.mapped_string_too_large', $source);
        continue;
      }
      $values[$pointer] = $value;
    }
    foreach (array_keys($values) as $pointer) {
      do {
        $present[$pointer] = TRUE;
        $pointer = substr($pointer, 0, (int) strrpos($pointer, '/'));
      } while ($pointer !== '');
    }
    foreach ($occurrences as $pointer => $occurrence) {
      if (($paths[$pointer]['type'] ?? NULL) !== 'array') {
        $errors[] = $this->error($pointer, 'type', 'integration.occurrence_target_invalid');
      }
    }
    $children = [];
    foreach ($paths as $pointer => $path) {
      if ($pointer !== '') {
        $parent = substr($pointer, 0, (int) strrpos($pointer, '/'));
        $children[$parent][] = $pointer;
      }
    }
    [, $payload] = $this->node('', TRUE, $paths, $children, $mapped, $values, $present, $occurrences, $errors);
    if ($errors !== []) {
      return $this->failure($errors);
    }
    try {
      $json = IntegrationRules::canonical($payload);
    }
    catch (\JsonException) {
      return $this->failure([$this->error('', NULL, 'integration.payload_encoding_invalid')]);
    }
    if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
      return $this->failure([$this->error('', 'maxLength', 'integration.payload_too_large')]);
    }
    foreach ($this->validator->validate($payload, $contract_key, $contract_version) as $error) {
      foreach ($mapped as $pointer => $rule) {
        if ($this->concrete($pointer) === $error['pointer']) {
          $error['source'] = $rule['source'];
          break;
        }
      }
      $errors[] = $error;
    }
    return $errors !== [] ? $this->failure($errors) : [
      'valid' => TRUE,
      'payload' => json_decode($json, FALSE, 128, JSON_THROW_ON_ERROR),
      'json' => $json,
      'sha256' => hash('sha256', $json),
      'errors' => [],
    ];
  }

  /**
   * Builds a schema node while preserving object and single-array semantics.
   */
  private function node(string $pointer, bool $required, array $paths, array $children, array $mapped, array $values, array $present, array $occurrences, array &$errors): array {
    if (!$required && !isset($present[$pointer])) {
      return [FALSE, NULL];
    }
    if (array_key_exists($pointer, $values)) {
      return [TRUE, $values[$pointer]];
    }
    $type = $paths[$pointer]['type'];
    if ($type === 'object') {
      $object = new \stdClass();
      foreach ($children[$pointer] ?? [] as $child) {
        [$exists, $value] = $this->node($child, $paths[$child]['local_required'], $paths, $children, $mapped, $values, $present, $occurrences, $errors);
        if ($exists) {
          $name = substr($child, strlen($pointer) + 1);
          $object->{str_replace(['~1', '~0'], ['/', '~'], $name)} = $value;
        }
      }
      return [$required || get_object_vars($object) !== [], $object];
    }
    if ($type === 'array') {
      if (!isset($present[$pointer])) {
        $source = $mapped[$pointer]['source'] ?? NULL;
        foreach ($mapped as $target => $rule) {
          if (str_starts_with($target, $pointer . '/')) {
            $source = $rule['source'];
            break;
          }
        }
        $errors[] = $this->error($pointer, 'required', 'integration.validation.required', $source);
        return [FALSE, NULL];
      }
      if (!isset($occurrences[$pointer])) {
        $errors[] = $this->error($pointer, 'items', 'integration.repeating_source_unsupported');
        return [FALSE, NULL];
      }
      [, $item] = $this->node($pointer . '/*', TRUE, $paths, $children, $mapped, $values, $present, $occurrences, $errors);
      return [TRUE, [$item]];
    }
    if (!array_key_exists($pointer, $values)) {
      $errors[] = $this->error($pointer, 'required', 'integration.validation.required', $mapped[$pointer]['source'] ?? NULL);
      return [FALSE, NULL];
    }
    return [TRUE, $values[$pointer]];
  }

  /**
   * Defines renderer absence once; zero and false remain actual values.
   */
  public static function isAbsent(mixed $value): bool {
    return FormValueParser::isAbsent($value);
  }

  /**
   * Converts only values whose declared source and target justify conversion.
   */
  private function normalizeValue(mixed $value, array $field, array $path): array {
    $parsed = $this->valueParser->parse($field, $value);
    if ($parsed['issue'] !== NULL) {
      $code = match ($parsed['issue']) {
        'number_format', 'number_ambiguous' => 'integration.validation.number_invalid',
        'step' => 'integration.validation.integer_invalid',
        'date' => 'integration.validation.date_invalid',
        default => 'integration.validation.type',
      };
      return [NULL, $code];
    }
    if (in_array($field['type'], ['number', 'integer'], TRUE)) {
      if ($path['type'] === 'string') {
        // Canonical decimal text preserves the submitted digits and scale.
        return [is_string($parsed['value']) ? $parsed['value'] : IntegrationRules::canonical($parsed['value']), NULL];
      }
      if ($path['type'] === 'integer') {
        return [$parsed['integer'], $parsed['integer'] === NULL ? 'integration.validation.integer_invalid' : NULL];
      }
      return [$parsed['number'], NULL];
    }
    return [$parsed['value'], NULL];
  }

  /**
   * Converts explicit single-occurrence template paths to payload pointers.
   */
  private function concrete(string $pointer): string {
    return preg_replace('~/\*(?=/|$)~', '/0', $pointer) ?? $pointer;
  }

  /**
   * Constructs diagnostics containing identifiers only, never source values.
   */
  private function error(string $pointer, ?string $keyword, string $code, ?string $source = NULL): array {
    $error = [
      'pointer' => $this->concrete($pointer),
      'keyword' => $keyword,
      'code' => $code,
    ];
    if ($source !== NULL) {
      $error['source'] = $source;
    }
    return $error;
  }

  /**
   * Discards all payload material when mapping or validation fails.
   */
  private function failure(array $errors): array {
    return [
      'valid' => FALSE,
      'payload' => NULL,
      'json' => NULL,
      'sha256' => NULL,
      'errors' => array_slice($errors, 0, self::MAX_ERRORS),
    ];
  }

}
