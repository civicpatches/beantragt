<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Service\FormValueParser;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Opis\JsonSchema\Validator;

/**
 * Validates mapping definitions without transforming applicant data.
 */
final class IntegrationRules {

  /**
   * Constructs the rules validator.
   */
  public function __construct(private readonly DataContractRegistry $registry) {}

  /**
   * Checks the bounded, closed mapping meta-schema before storage.
   */
  public function validate(array $rules): void {
    $rules = $this->normalize($rules);
    $json = self::canonical($rules);
    if (strlen($json) > 262144) {
      throw new IntegrationException('integration.input_too_large');
    }
    $schema = json_decode((string) file_get_contents(__DIR__ . '/../../contracts/mapping-rules.schema.json'));
    $data = json_decode($json);
    if (!(new Validator())->validate($data, $schema)->isValid()) {
      throw new IntegrationException('integration.rules_invalid');
    }
    $targets = [];
    foreach ($rules['rules'] as $rule) {
      if (isset($targets[$rule['target']])) {
        throw new IntegrationException('integration.duplicate_target', 422, [
        [
          'pointer' => $rule['target'],
          'code' => 'integration.duplicate_target',
        ],
        ]);
      }
      $targets[$rule['target']] = TRUE;
    }
  }

  /**
   * Preserves grammar-defined map objects, also for non-HTTP PHP callers.
   */
  public function normalize(array $rules): array {
    foreach (is_array($rules['rules'] ?? NULL) ? $rules['rules'] : [] as $index => $rule) {
      if (is_array($rule) && isset($rule['valueMap']) && is_array($rule['valueMap'])) {
        $rules['rules'][$index]['valueMap'] = (object) $rule['valueMap'];
      }
    }
    return $rules;
  }

  /**
   * Decodes stored rules without coercing JSON list and object shapes.
   */
  public function decode(string $json): array {
    $object = json_decode($json, FALSE, 32, JSON_THROW_ON_ERROR);
    if (!$object instanceof \stdClass || !is_array($object->rules ?? NULL)
      || (isset($object->occurrences) && !is_array($object->occurrences))) {
      throw new IntegrationException('integration.rules_invalid');
    }
    foreach ($object->rules as $rule) {
      if ($rule instanceof \stdClass && property_exists($rule, 'valueMap') && !$rule->valueMap instanceof \stdClass) {
        throw new IntegrationException('integration.rules_invalid');
      }
    }
    $rules = (array) $object;
    foreach (['rules', 'occurrences'] as $key) {
      if (isset($rules[$key])) {
        $rules[$key] = array_map(static fn($rule) => $rule instanceof \stdClass ? (array) $rule : $rule, $rules[$key]);
      }
    }
    $this->validate($rules);
    return $this->normalize($rules);
  }

  /**
   * Returns only diagnostics for existing repository callers.
   */
  public function check(array $rules, array $form, string $key, string $version): array {
    return $this->report($rules, $form, $key, $version)['errors'];
  }

  /**
   * Reports static completeness without producing or inventing payload values.
   */
  public function report(array $rules, array $form, string $key, string $version): array {
    $rules = $this->normalize($rules);
    $this->validate($rules);
    $fields = $this->fields($form);
    $paths = array_column($this->registry->paths($key, $version), NULL, 'pointer');
    $mapped = array_column($rules['rules'], NULL, 'target');
    $occurrences = [];
    $errors = [];
    foreach ($rules['occurrences'] ?? [] as $occurrence) {
      $pointer = $occurrence['target'];
      if (isset($occurrences[$pointer])) {
        $errors[] = ['pointer' => $pointer, 'code' => 'integration.occurrence_duplicate'];
      }
      $occurrences[$pointer] = TRUE;
      if (($paths[$pointer]['type'] ?? NULL) !== 'array') {
        $errors[] = ['pointer' => $pointer, 'code' => 'integration.occurrence_target_invalid'];
      }
      elseif (($paths[$pointer]['minItems'] ?? 0) > 1) {
        $errors[] = ['pointer' => $pointer, 'code' => 'integration.occurrence_min_items'];
      }
      elseif (($paths[$pointer]['maxItems'] ?? NULL) === 0) {
        $errors[] = ['pointer' => $pointer, 'code' => 'integration.occurrence_max_items'];
      }
    }
    // Every target and declaration makes its ancestor objects present. A single
    // occurrence explicitly creates its one item; it supplies no field values.
    $present = ['' => TRUE];
    foreach ([...array_keys($mapped), ...array_keys($occurrences)] as $pointer) {
      if (isset($occurrences[$pointer])) {
        $present[$pointer . '/*'] = TRUE;
      }
      do {
        $present[$pointer] = TRUE;
        $pointer = substr($pointer, 0, (int) strrpos($pointer, '/'));
      } while ($pointer !== '');
    }
    $required_paths = [];
    $required_total = 0;
    $required_covered = 0;
    foreach ($paths as $pointer => $path) {
      $parent = substr($pointer, 0, (int) strrpos($pointer, '/'));
      $is_item = str_ends_with($pointer, '/*');
      $parent_present = isset($present[$parent]) || (($required_paths[$parent] ?? FALSE) && ($paths[$parent]['type'] ?? NULL) === 'object');
      $required = $pointer === '' || ($is_item
        ? isset($occurrences[$parent])
        : (($path['local_required'] ?? FALSE) && $parent_present));
      $required_paths[$pointer] = $required;
      $direct_item = $is_item && isset($mapped[$parent]);
      $covered = $direct_item || (in_array($path['type'], ['object', 'array'], TRUE)
        ? isset($present[$pointer]) : isset($mapped[$pointer]));
      if ($required && $pointer !== '') {
        $required_total++;
        $required_covered += (int) $covered;
        if (!$covered) {
          $errors[] = ['pointer' => $pointer, 'code' => 'integration.required_target_missing'];
        }
      }
      if ($path['type'] === 'array' && ($required || $covered) && !isset($occurrences[$pointer]) && !isset($mapped[$pointer])) {
        $errors[] = ['pointer' => $pointer, 'code' => 'integration.repeating_source_unsupported'];
      }
      if (($path['unsupported'] ?? []) !== [] && ($required || $covered)) {
        $errors[] = ['pointer' => $pointer, 'code' => 'integration.target_unsupported'];
      }
    }
    foreach ($mapped as $pointer => $rule) {
      $path = $paths[$pointer] ?? NULL;
      $field = $fields[$rule['source']] ?? NULL;
      $multiple = $field !== NULL && $this->isMultiple($field);
      $value_path = $multiple ? ($paths[$pointer . '/*'] ?? []) : $path;
      $code = NULL;
      foreach (array_keys($mapped) as $other) {
        if ($other !== $pointer && str_starts_with($other, $pointer . '/')) {
          $errors[] = ['pointer' => $pointer, 'code' => 'integration.target_overlap'];
          break;
        }
      }
      if ($path === NULL) {
        $code = 'integration.target_unknown';
      }
      elseif ($path['type'] === 'array' && (!$multiple || isset($occurrences[$pointer]) || !isset($value_path['enum']) || !in_array($value_path['type'], [
        'string', 'number', 'integer', 'boolean',
      ], TRUE))) {
        $code = 'integration.repeating_source_unsupported';
      }
      elseif ($field === NULL) {
        $code = 'integration.source_missing';
      }
      elseif (($required_paths[$pointer] ?? FALSE) && (($field['required'] ?? FALSE) !== TRUE || isset($field['conditions']) || isset($field['conditional']) || isset($field['conditionalOn']) || isset($field['_section_condition']))) {
        $code = 'integration.required_source_unguaranteed';
      }
      elseif (!$this->compatibleType($field, (array) $path['type'], isset($rule['valueMap'])) || (($path['format'] ?? NULL) === 'date' && $field['type'] !== 'date')) {
        $code = 'integration.type_incompatible';
      }
      elseif (($value_path['enum'] ?? NULL) !== NULL || isset($rule['valueMap']) || $multiple) {
        $options = $field['options'] ?? [];
        $map = isset($rule['valueMap']) ? (array) $rule['valueMap'] : [];
        if ((!in_array($field['type'], [
          'select',
          'radio',
        ], TRUE) && !$multiple) || $options === [] || array_diff($options, array_keys($map)) !== [] || array_diff(array_keys($map), $options) !== []) {
          $code = 'integration.value_map_incomplete';
        }
        else {
          foreach ($map as $value) {
            $type = match (gettype($value)) {
              'integer' => 'integer',
              'double' => 'number',
              'boolean' => 'boolean',
              default => 'string',
            };
            $valid_type = in_array($type, (array) $value_path['type'], TRUE) || ($type === 'integer' && in_array('number', (array) $value_path['type'], TRUE));
            if (!$valid_type || (($value_path['enum'] ?? NULL) !== NULL && !in_array($value, $value_path['enum'], TRUE))) {
              $code = 'integration.value_map_invalid';
              break;
            }
          }
        }
      }
      if ($code !== NULL) {
        $errors[] = ['pointer' => $pointer, 'code' => $code];
      }
    }
    return [
      'valid' => $errors === [],
      'errors' => array_slice($errors, 0, 100),
      'coverage' => ['required_total' => $required_total, 'required_covered' => $required_covered],
    ];
  }

  /**
   * Collects fields by their immutable machine identifiers.
   */
  public function fields(array $form): array {
    $fields = [];
    foreach ($form['sections'] ?? [] as $section) {
      foreach ($section['fields'] ?? [] as $field) {
        if (isset($field['id']) && is_string($field['id'])) {
          if (isset($fields[$field['id']])) {
            throw new IntegrationException('integration.source_ambiguous');
          }
          foreach ([
            'conditionalOn',
            'conditions',
            'conditional',
            '_section_condition',
          ] as $condition) {
            if (isset($section[$condition])) {
              $field['_section_condition'][$condition] = $section[$condition];
            }
          }
          $fields[$field['id']] = $field;
        }
      }
    }
    return $fields;
  }

  /**
   * Describes changes affecting values or their accepted domain.
   */
  public function compatibility(array $rules, array $original, array $candidate): array {
    $before = $this->fields($original);
    $after = $this->fields($candidate);
    $errors = [];
    foreach ($rules['rules'] as $rule) {
      $id = $rule['source'];
      if (!isset($after[$id])) {
        $errors[] = ['pointer' => $rule['target'], 'source' => $id, 'code' => 'integration.source_missing'];
      }
      elseif (!isset($before[$id]) || self::canonical($this->signature($before[$id])) !== self::canonical($this->signature($after[$id]))) {
        $errors[] = ['pointer' => $rule['target'], 'source' => $id, 'code' => 'integration.source_changed'];
      }
    }
    return array_slice($errors, 0, 100);
  }

  /**
   * Returns the source semantics that must stay pinned to a revision.
   */
  private function signature(array $field): array {
    return array_intersect_key($field, array_flip([
      'type',
      'options',
      'multiple',
      'required',
      'validation',
      'format',
      'computed',
      'semantics',
      'crm_key',
      'conditions',
      'conditional',
      'conditionalOn',
      '_section_condition',
    ]));
  }

  /**
   * Allows only lossless primitive assignments; composites need a later slice.
   */
  public function compatibleType(array $field, array $types, bool $mapped): bool {
    if ($this->isMultiple($field)) {
      return $mapped && in_array('array', $types, TRUE);
    }
    if ($mapped && in_array($field['type'], ['select', 'radio'], TRUE)) {
      return TRUE;
    }
    $type = match ($field['type']) {
      'text', 'textarea', 'email', 'tel', 'date', 'select', 'radio' => 'string',
      'checkbox' => 'boolean',
      'number', 'integer' => 'number',
      default => 'unsupported',
    };
    return in_array($type, $types, TRUE) || ($type === 'number' && (in_array('string', $types, TRUE)
      || (in_array('integer', $types, TRUE) && FormValueParser::hasIntegerDomain($field))));
  }

  /**
   * Identifies declared multi-choice fields, never inferred from stored values.
   */
  public function isMultiple(array $field): bool {
    return ($field['type'] === 'checkbox' && !empty($field['options']))
      || ($field['type'] === 'select' && ($field['multiple'] ?? FALSE) === TRUE);
  }

  /**
   * Produces recursively key-sorted JSON, preserving array order.
   */
  public static function canonical(mixed $value): string {
    return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
  }

  /**
   * Normalizes object ordering without changing list semantics.
   */
  private static function sort(mixed $value): mixed {
    if (is_object($value)) {
      $properties = get_object_vars($value);
      ksort($properties, SORT_STRING);
      return (object) array_map(self::sort(...), $properties);
    }
    if (is_array($value)) {
      if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
      }
      return array_map(self::sort(...), $value);
    }
    return $value;
  }

}
