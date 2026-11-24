<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Validates administrative application data edits against a form schema.
 */
final class ApplicationDataEditValidator {

  private const UNSAFE_DATA_KEYS = ['__proto__', 'constructor', 'prototype', '_anonymized'];
  private const STRING_FIELD_TYPES = ['text', 'textarea', 'email', 'tel', 'date', 'select', 'radio'];
  private const SUPPORTED_FIELD_TYPES = [
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
  private const SUPPORTED_FIELD_FORMATS = [
    'postal_code',
    'iban',
    'bic',
    'tax_id',
    'currency',
    'reference_number',
    'url',
  ];
  private const FIELD_TYPE_ALIASES = [
    'textinput' => 'text',
    'textfield' => 'text',
    'string' => 'text',
    'input' => 'text',
    'shorttext' => 'text',
    'longtext' => 'textarea',
    'multiline' => 'textarea',
    'paragraph' => 'textarea',
    'emailaddress' => 'email',
    'e_mail' => 'email',
    'mail' => 'email',
    'telephone' => 'tel',
    'phone' => 'tel',
    'phonenumber' => 'tel',
    'mobile' => 'tel',
    'mobilenumber' => 'tel',
    'numeric' => 'number',
    'integer' => 'number',
    'decimal' => 'number',
    'datepicker' => 'date',
    'datetime' => 'date',
    'dropdown' => 'select',
    'selectbox' => 'select',
    'combobox' => 'select',
    'radiogroup' => 'radio',
    'singlechoice' => 'radio',
    'checkboxgroup' => 'checkbox',
    'multiselect' => 'checkbox',
    'url' => 'text',
    'uri' => 'text',
    'website' => 'text',
    'webseite' => 'text',
    'homepage' => 'text',
    'link' => 'text',
    'webaddress' => 'text',
    'internetadresse' => 'text',
    'upload' => 'file',
    'attachment' => 'file',
    'documentupload' => 'file',
  ];
  private const FIELD_FORMAT_ALIASES = [
    'postalcode' => 'postal_code',
    'zipcode' => 'postal_code',
    'zip' => 'postal_code',
    'plz' => 'postal_code',
    'iban' => 'iban',
    'bic' => 'bic',
    'swift' => 'bic',
    'taxid' => 'tax_id',
    'steuerid' => 'tax_id',
    'steueridentifikationsnummer' => 'tax_id',
    'currency' => 'currency',
    'amount' => 'currency',
    'betrag' => 'currency',
    'referencenumber' => 'reference_number',
    'referenznummer' => 'reference_number',
    'referenz' => 'reference_number',
    'aktenzeichen' => 'reference_number',
    'kassenzeichen' => 'reference_number',
    'vorgangsnummer' => 'reference_number',
    'url' => 'url',
    'uri' => 'url',
    'website' => 'url',
    'webseite' => 'url',
    'homepage' => 'url',
    'link' => 'url',
    'webaddress' => 'url',
    'internetadresse' => 'url',
  ];
  private const FIELD_TYPE_VALIDATION_DEFAULTS = [
    'email' => [
      'pattern' => '^[^\s@]+@[^\s@]+\.[^\s@]+$',
      'maxLength' => 254,
    ],
    'tel' => [
      'pattern' => '^[+0-9()\-/\s]{6,20}$',
      'minLength' => 6,
      'maxLength' => 20,
    ],
    'date' => [
      'pattern' => '^\d{4}-\d{2}-\d{2}$',
    ],
  ];
  private const FIELD_FORMAT_DEFAULTS = [
    'postal_code' => [
      'type' => 'text',
      'validation' => [
        'pattern' => '^[0-9]{5}$',
        'minLength' => 5,
        'maxLength' => 5,
      ],
    ],
    'iban' => [
      'type' => 'text',
      'validation' => [
        'pattern' => '^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{11,30}$',
        'minLength' => 15,
        'maxLength' => 34,
      ],
    ],
    'bic' => [
      'type' => 'text',
      'validation' => [
        'pattern' => '^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$',
        'minLength' => 8,
        'maxLength' => 11,
      ],
    ],
    'tax_id' => [
      'type' => 'text',
      'validation' => [
        'pattern' => '^[0-9]{11}$',
        'minLength' => 11,
        'maxLength' => 11,
      ],
    ],
    'currency' => [
      'type' => 'number',
      'validation' => [
        'min' => 0,
        'step' => 0.01,
      ],
    ],
    'reference_number' => [
      'type' => 'text',
      'validation' => [
        'maxLength' => 64,
      ],
    ],
    'url' => [
      'type' => 'text',
      'validation' => [
        'maxLength' => 2048,
      ],
    ],
  ];
  private const MAX_VALIDATION_PATTERN_LENGTH = 160;
  private const VALIDATION_PATTERN_MATCH_LIMIT = 10000;
  private const VALIDATION_PATTERN_RECURSION_LIMIT = 1000;

  /**
   * Rejects object keys that can mutate prototypes in JavaScript clients.
   *
   * @param array<string, mixed> $data
   *   Submitted application data.
   */
  public function assertSafeDataShape(array $data): void {
    foreach ($data as $key => $value) {
      if (in_array($key, self::UNSAFE_DATA_KEYS, TRUE)) {
        throw new BadRequestHttpException('Application data contains an invalid key.');
      }
      if (is_array($value)) {
        $this->assertSafeNestedValue($value);
      }
    }
  }

  /**
   * Canonicalizes structured values before administrative persistence.
   *
   * @return array<string, mixed>
   *   Normalized application data.
   */
  public function normalizeData(FormSchemaInterface $schema_entity, array $data): array {
    $fields = $this->schemaFields($this->decodeSchema($schema_entity));
    foreach ($fields as $field_id => $field) {
      if (($field['type'] ?? NULL) !== 'address' || !array_key_exists($field_id, $data)) {
        continue;
      }
      if (is_array($data[$field_id]) && array_is_list($data[$field_id])) {
        // An entry column of a repeatable section: one address per entry.
        foreach ($data[$field_id] as $index => $element) {
          $normalized = $this->normalizeAddressValue($element);
          if ($normalized !== NULL) {
            $data[$field_id][$index] = $normalized;
          }
        }
        continue;
      }
      $normalized = $this->normalizeAddressValue($data[$field_id]);
      if ($normalized !== NULL) {
        $data[$field_id] = $normalized;
      }
    }
    return $data;
  }

  /**
   * Validates submitted data against a form schema.
   *
   * Every visible field is validated with its value. Entry columns of a
   * batch application (see entrySections()) are validated entry by entry:
   * each element as one value of its field, conditions on a sibling inside
   * the entry, the hidden-field rule per element, and the number of entries
   * as stored. Refusals there name the section and the zero-based entry
   * index: 'Section "<id>", entry <i>: <message>'.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $schema_entity
   *   Schema revision used for this edit.
   * @param array<string, mixed> $data
   *   Submitted application data.
   * @param array<string, mixed> $original_data
   *   Existing stored application data.
   * @param string|null $origin
   *   The application's origin (field_origin, read-only, set at intake).
   *   Only "bulk" has entry columns; NULL validates every field flat.
   */
  public function validate(FormSchemaInterface $schema_entity, array $data, array $original_data, ?string $origin = NULL): void {
    $schema = $this->decodeSchema($schema_entity);
    $fields = $this->schemaFields($schema);
    $schema_field_ids = array_fill_keys(array_keys($fields), TRUE);

    $this->assertUnknownKeysPreserved($data, $original_data, $schema_field_ids);

    $entry_field_ids = [];
    // Section of each other member of a section with entries (file fields,
    // fields stored flat): their condition may point at a column.
    $entry_scope = [];
    foreach ($this->entrySections($schema, $fields, $original_data, $origin) as $section) {
      $this->validateEntrySection($section['id'], $section['fields'], $section['length'], $fields, $data, $original_data);
      $entry_field_ids += array_fill_keys($section['fields'], TRUE);
      $entry_scope += array_fill_keys(array_diff($section['members'], $section['fields']), $section);
    }

    foreach ($fields as $field_id => $field) {
      if (isset($entry_field_ids[$field_id])) {
        continue;
      }
      $is_visible = $this->sectionFieldIsVisible($field, $data, $entry_scope[$field_id] ?? NULL);
      $was_visible = $this->sectionFieldIsVisible($field, $original_data, $entry_scope[$field_id] ?? NULL);
      $has_value = array_key_exists($field_id, $data);

      if (!$is_visible) {
        if (!$has_value) {
          if (!$was_visible && array_key_exists($field_id, $original_data)) {
            throw new UnprocessableEntityHttpException(sprintf('Hidden field "%s" cannot be removed.', $field_id));
          }
          continue;
        }
        if (!(!$was_visible
              && array_key_exists($field_id, $original_data)
              && $this->stableJson($data[$field_id]) === $this->stableJson($original_data[$field_id]))
        ) {
          throw new UnprocessableEntityHttpException(sprintf('Hidden field "%s" cannot be changed.', $field_id));
        }
        continue;
      }

      if ($this->isFileField($field)) {
        $this->validateUnchangedFileField($field, $has_value, $data, $original_data, $was_visible);
        continue;
      }

      $this->validateFieldValue(
        $field,
        $has_value ? $data[$field_id] : NULL,
        $has_value && array_key_exists($field_id, $original_data) && $data[$field_id] === $original_data[$field_id],
      );
    }
  }

  /**
   * A visible file field: the staff data edit never changes attachments.
   *
   * Accepted only when the value equals the stored one (both absent, or the
   * same JSON, order included); anything else is refused. Attachments come
   * from the citizen or the batch intake, never from this path. A required
   * file field that this edit makes visible must already hold its value; one
   * that was visible before keeps whatever it holds, so an incomplete
   * application stays editable.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param bool $has_value
   *   Whether the request carries the field.
   * @param array<string, mixed> $data
   *   Submitted application data.
   * @param array<string, mixed> $original_data
   *   Existing stored application data.
   * @param bool $was_visible
   *   Whether the field was visible for the stored data.
   */
  private function validateUnchangedFileField(array $field, bool $has_value, array $data, array $original_data, bool $was_visible): void {
    $field_id = (string) ($field['id'] ?? '');
    $stored = array_key_exists($field_id, $original_data);
    $unchanged = $has_value === $stored
      && (!$has_value || $this->stableJson($data[$field_id]) === $this->stableJson($original_data[$field_id]));
    if (!$unchanged) {
      throw new UnprocessableEntityHttpException(sprintf('File field "%s" cannot be changed by a data edit.', $field_id));
    }
    if (!$was_visible) {
      $this->validateFieldValue($field, $has_value ? $data[$field_id] : NULL);
    }
  }

  /**
   * Returns the entry columns of a batch application, per section.
   *
   * Batch intake stores one list per non-file field of a repeatable section,
   * one element per entry (BulkIntakeService::create()); a citizen
   * submission stores one value per field. A field is an entry column if and
   * only if the application's origin is "bulk", the field belongs to a
   * section with "repeatable": true, it is no file field, and its stored
   * value is a list. This holds for every field type, a checkbox with
   * options included. For any other origin nothing is a column. The decision
   * reads the stored data only; submitted data cannot create a column.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   * @param array<string, array<string, mixed>> $fields
   *   Normalized schema fields keyed by id.
   * @param array<string, mixed> $original_data
   *   Existing stored application data.
   * @param string|null $origin
   *   The application's origin.
   *
   * @return list<array{id: string, fields: list<string>, members: list<string>, length: int}>
   *   Section id, its entry column ids, all its field ids and the stored
   *   number of entries (the length of its first column).
   */
  private function entrySections(array $schema, array $fields, array $original_data, ?string $origin): array {
    if ($origin !== 'bulk') {
      return [];
    }
    $sections = [];
    foreach (is_array($schema['sections'] ?? NULL) ? $schema['sections'] : [] as $section) {
      if (!is_array($section) || ($section['repeatable'] ?? FALSE) !== TRUE || !is_array($section['fields'] ?? NULL)) {
        continue;
      }
      $ids = [];
      $members = [];
      $length = NULL;
      foreach ($section['fields'] as $raw) {
        $field_id = is_array($raw) ? ($raw['id'] ?? NULL) : NULL;
        if (!is_string($field_id) || !isset($fields[$field_id])) {
          continue;
        }
        $members[] = $field_id;
        // A file field is never a column (batch intake skips the raw type
        // "file"; an alias counts the same here).
        if ($this->isFileField($fields[$field_id])) {
          continue;
        }
        $stored = $original_data[$field_id] ?? NULL;
        if (!is_array($stored) || !array_is_list($stored)) {
          continue;
        }
        $ids[] = $field_id;
        $length ??= count($stored);
      }
      if ($length !== NULL) {
        $sections[] = [
          'id' => is_string($section['id'] ?? NULL) ? $section['id'] : '',
          'fields' => $ids,
          'members' => $members,
          'length' => $length,
        ];
      }
    }
    return $sections;
  }

  /**
   * Visibility of a section field that is no entry column.
   *
   * A file field (or a field stored flat) of a section with entries whose
   * condition points at a column of that section is visible if and only if
   * at least one entry meets the condition, each element compared as
   * fieldIsVisible() compares a value. That is batch intake's rule: a proof
   * of the section is needed once per member that meets its condition
   * (BulkFormProjection::proofs()). Every other condition is evaluated as
   * before.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param array<string, mixed> $data
   *   Application data.
   * @param array{id: string, fields: list<string>, members: list<string>, length: int}|null $section
   *   The field's section with entries, or NULL.
   */
  private function sectionFieldIsVisible(array $field, array $data, ?array $section): bool {
    $dependency = is_array($field['conditionalOn'] ?? NULL) ? ($field['conditionalOn']['fieldId'] ?? NULL) : NULL;
    if ($section === NULL || !is_string($dependency) || !in_array($dependency, $section['fields'], TRUE)) {
      return $this->fieldIsVisible($field, $data);
    }
    for ($index = 0; $index < $section['length']; $index++) {
      if ($this->fieldIsVisible($field, $this->entryData($data, $section['fields'], $index))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Validates one repeatable section stored as entries.
   *
   * @param string $section_id
   *   Section id, for messages.
   * @param list<string> $field_ids
   *   Entry column ids of the section (stored as lists).
   * @param int $length
   *   Stored number of entries.
   * @param array<string, array<string, mixed>> $fields
   *   Normalized schema fields keyed by id.
   * @param array<string, mixed> $data
   *   Submitted application data.
   * @param array<string, mixed> $original_data
   *   Existing stored application data.
   */
  private function validateEntrySection(string $section_id, array $field_ids, int $length, array $fields, array $data, array $original_data): void {
    // Shape first: one value per entry, as many entries as stored.
    foreach ($field_ids as $field_id) {
      $value = $data[$field_id] ?? NULL;
      if (is_array($value) && array_is_list($value)) {
        if (count($value) !== $length) {
          throw new UnprocessableEntityHttpException(sprintf('Section "%s" must keep %d entries; field "%s" has %d.', $section_id, $length, $field_id, count($value)));
        }
        continue;
      }
      // A scalar or a missing value would collapse the stored entries.
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" of section "%s" must keep one value per entry.', $field_id, $section_id));
    }

    for ($index = 0; $index < $length; $index++) {
      $entry = $this->entryData($data, $field_ids, $index);
      $stored_entry = $this->entryData($original_data, $field_ids, $index);
      foreach ($field_ids as $field_id) {
        try {
          $this->validateEntryElement($fields[$field_id], $entry[$field_id], $stored_entry[$field_id], $entry, $stored_entry);
        }
        catch (UnprocessableEntityHttpException $exception) {
          throw new UnprocessableEntityHttpException(sprintf('Section "%s", entry %d: %s', $section_id, $index, $exception->getMessage()), $exception);
        }
      }
    }
  }

  /**
   * The data one entry sees: top-level values, the section's own elements.
   *
   * @param array<string, mixed> $data
   *   Application data.
   * @param list<string> $field_ids
   *   Field ids of the section.
   * @param int $index
   *   Entry index.
   *
   * @return array<string, mixed>
   *   Data with each section field replaced by its element (NULL when the
   *   field holds no list or no such element).
   */
  private function entryData(array $data, array $field_ids, int $index): array {
    foreach ($field_ids as $field_id) {
      $value = $data[$field_id] ?? NULL;
      $data[$field_id] = is_array($value) && array_is_list($value) ? ($value[$index] ?? NULL) : NULL;
    }
    return $data;
  }

  /**
   * Validates one element of an entry column.
   *
   * The hidden-field rule of validate() per element, with an empty element
   * (NULL) in place of an absent value; a visible element is one value of
   * its field. A checkbox with options also accepts the single option
   * string batch intake stores per entry.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   * @param mixed $element
   *   Submitted element.
   * @param mixed $stored_element
   *   Stored element.
   * @param array<string, mixed> $entry
   *   Submitted data as the entry sees it.
   * @param array<string, mixed> $stored_entry
   *   Stored data as the entry sees it.
   */
  private function validateEntryElement(array $field, mixed $element, mixed $stored_element, array $entry, array $stored_entry): void {
    $field_id = (string) ($field['id'] ?? '');
    if (!$this->fieldIsVisible($field, $entry)) {
      $was_visible = $this->fieldIsVisible($field, $stored_entry);
      if ($element === NULL) {
        if (!$was_visible && $stored_element !== NULL) {
          throw new UnprocessableEntityHttpException(sprintf('Hidden field "%s" cannot be removed.', $field_id));
        }
        return;
      }
      if ($was_visible || $stored_element === NULL || $this->stableJson($element) !== $this->stableJson($stored_element)) {
        throw new UnprocessableEntityHttpException(sprintf('Hidden field "%s" cannot be changed.', $field_id));
      }
      return;
    }
    if ($this->isCheckboxGroup($field) && is_string($element) && trim($element) !== '') {
      $element = [$element];
    }
    $this->validateFieldValue($field, $element, $element === $stored_element);
  }

  /**
   * Whether a field is a checkbox with options (its value is a list).
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   */
  private function isCheckboxGroup(array $field): bool {
    return ($field['type'] ?? NULL) === 'checkbox' && $this->fieldOptions($field) !== [];
  }

  /**
   * Returns TRUE if a schema entity marks any field sensitive.
   */
  public function schemaHasSensitiveFields(FormSchemaInterface $schema_entity): bool {
    foreach ($this->schemaFields($this->decodeSchema($schema_entity)) as $field) {
      if ($this->isSensitiveSchemaField($field) || self::requiresFinancialProtection($field)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Decodes the referenced form schema JSON.
   *
   * @return array<string, mixed>
   *   Decoded form schema.
   */
  private function decodeSchema(FormSchemaInterface $schema_entity): array {
    $schema_json = $schema_entity->getSchema();
    if ($schema_json === NULL || trim($schema_json) === '') {
      throw new UnprocessableEntityHttpException('Application schema is empty.');
    }

    try {
      $schema = json_decode($schema_json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new UnprocessableEntityHttpException('Application schema is not valid JSON.', $exception);
    }

    if (!is_array($schema) || array_is_list($schema)) {
      throw new UnprocessableEntityHttpException('Application schema must be a JSON object.');
    }
    return $schema;
  }

  /**
   * Returns TRUE when a schema field is marked sensitive.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   */
  private function isSensitiveSchemaField(array $field): bool {
    $default_sensitive = $this->isFileField($field);
    if (!array_key_exists('sensitive', $field)) {
      return $default_sensitive;
    }

    $sensitive = $field['sensitive'];
    if (is_bool($sensitive)) {
      return $sensitive;
    }
    if (is_string($sensitive) || is_int($sensitive)) {
      $parsed = filter_var($sensitive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      return $parsed ?? $default_sensitive;
    }
    return $default_sensitive;
  }

  /**
   * Returns schema fields keyed by safe field id.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   *
   * @return array<string, array<string, mixed>>
   *   Schema fields.
   */
  private function schemaFields(array $schema): array {
    $fields_by_id = [];
    $sections = $schema['sections'] ?? [];
    if (!is_array($sections)) {
      return $fields_by_id;
    }

    foreach ($sections as $section) {
      if (!is_array($section)) {
        continue;
      }
      $fields = $section['fields'] ?? [];
      if (!is_array($fields)) {
        continue;
      }
      foreach ($fields as $field) {
        if (!is_array($field)) {
          continue;
        }
        $field_id = $field['id'] ?? NULL;
        if (!is_string($field_id) || $field_id === '' || in_array($field_id, self::UNSAFE_DATA_KEYS, TRUE)) {
          throw new UnprocessableEntityHttpException('Application schema contains an invalid field id.');
        }
        $field['id'] = $field_id;
        $fields_by_id[$field_id] = $this->normalizeSchemaField($field);
      }
    }

    return $fields_by_id;
  }

  /**
   * Normalizes a schema field to the same persisted contract as Nuxt.
   *
   * @param array<string, mixed> $field
   *   Raw schema field.
   *
   * @return array<string, mixed>
   *   Normalized schema field.
   */
  private function normalizeSchemaField(array $field): array {
    $normalized_type = $this->normalizeFieldType($field['type'] ?? NULL, $field);
    // A declared upload (type "file" or an alias) stays one: no format,
    // inferred from the label ("Kosten", "IBAN", "Link") or declared, may
    // turn it into a number or text field, so isFileField() and with it the
    // file guard and the entry-column rule always see it.
    $normalized_format = $normalized_type === 'file'
      ? NULL
      : $this->normalizeFieldFormat($field['format'] ?? NULL, $field);
    $preferred_type = $normalized_format !== NULL
        ? (string) self::FIELD_FORMAT_DEFAULTS[$normalized_format]['type']
        : $normalized_type;

    $field['type'] = $preferred_type;
    if ($normalized_format !== NULL) {
      $field['format'] = $normalized_format;
    }
    else {
      unset($field['format']);
    }

    $type_defaults = self::FIELD_TYPE_VALIDATION_DEFAULTS[$preferred_type] ?? [];
    $format_defaults = $normalized_format !== NULL
        ? (self::FIELD_FORMAT_DEFAULTS[$normalized_format]['validation'] ?? [])
        : [];
    $explicit_validation = $this->normalizeFieldValidation($field['validation'] ?? NULL);
    $validation = array_merge($type_defaults, $format_defaults, $explicit_validation);
    if ($validation !== []) {
      $field['validation'] = $validation;
    }
    else {
      unset($field['validation']);
    }

    if (isset($field['options']) && is_array($field['options'])) {
      $field['options'] = array_values(array_map('strval', $field['options']));
    }
    else {
      unset($field['options']);
    }

    return $field;
  }

  /**
   * Normalizes a schema field type.
   *
   * @param mixed $type
   *   Raw field type.
   * @param array<string, mixed> $field
   *   Raw schema field.
   */
  private function normalizeFieldType(mixed $type, array $field): string {
    $normalized_type = $this->normalizeToken($type);
    if (isset(self::FIELD_TYPE_ALIASES[$normalized_type])) {
      return self::FIELD_TYPE_ALIASES[$normalized_type];
    }

    if (in_array($normalized_type, self::SUPPORTED_FIELD_TYPES, TRUE)) {
      return $normalized_type === 'text'
            ? ($this->inferFieldType($field) ?? $normalized_type)
            : $normalized_type;
    }

    return $this->inferFieldType($field)
        ?? (isset($field['options']) && is_array($field['options']) && $field['options'] !== [] ? 'select' : 'text');
  }

  /**
   * Normalizes a schema field format.
   *
   * @param mixed $format
   *   Raw field format.
   * @param array<string, mixed> $field
   *   Raw schema field.
   */
  private function normalizeFieldFormat(mixed $format, array $field): ?string {
    $normalized_format = $this->normalizeToken($format);
    if (isset(self::FIELD_FORMAT_ALIASES[$normalized_format])) {
      return self::FIELD_FORMAT_ALIASES[$normalized_format];
    }

    if (in_array($normalized_format, self::SUPPORTED_FIELD_FORMATS, TRUE)) {
      return $normalized_format;
    }

    return $this->inferFieldFormat($field);
  }

  /**
   * Normalizes free-form schema tokens.
   *
   * @param mixed $value
   *   Raw token value.
   */
  private function normalizeToken(mixed $value): string {
    $token = strtolower(trim((string) ($value ?? '')));
    return (string) preg_replace('/[^a-z0-9]+/', '', $token);
  }

  /**
   * Builds the field context used for type and format inference.
   *
   * @param array<string, mixed> $field
   *   Raw schema field.
   */
  private static function buildFieldContext(array $field): string {
    $parts = [];
    foreach (['type', 'format', 'crm_key', 'id', 'label', 'placeholder'] as $key) {
      $value = $field[$key] ?? NULL;
      if (is_string($value) && trim($value) !== '') {
        $parts[] = $value;
      }
    }
    return strtolower(implode(' ', $parts));
  }

  /**
   * Infers a field type from CRM keys and labels.
   *
   * @param array<string, mixed> $field
   *   Raw schema field.
   */
  private function inferFieldType(array $field): ?string {
    $context = self::buildFieldContext($field);
    if ($context === '') {
      return NULL;
    }

    if (($field['crm_key'] ?? NULL) === 'person.email'
          || str_contains($context, '@')
          || preg_match('/\b(e[- ]?mail|email)\b/u', $context)
      ) {
      return 'email';
    }

    if (($field['crm_key'] ?? NULL) === 'person.phone'
          || preg_match('/\b(telefon|telefonnummer|handy|mobil|mobile|phone|tel)\b/u', $context)
      ) {
      return 'tel';
    }

    if (($field['crm_key'] ?? NULL) === 'person.birthdate'
          || preg_match('/\b(geburtsdatum|date|datum|birthdate)\b/u', $context)
      ) {
      return 'date';
    }

    return NULL;
  }

  /**
   * Infers a field format from CRM keys and labels.
   *
   * @param array<string, mixed> $field
   *   Raw schema field.
   */
  private function inferFieldFormat(array $field): ?string {
    $context = self::buildFieldContext($field);
    if ($context === '') {
      return NULL;
    }

    if (($field['crm_key'] ?? NULL) === 'person.postal_code'
          || preg_match('/\b(plz|postleitzahl|postal code|zipcode|zip)\b/u', $context)
      ) {
      return 'postal_code';
    }
    if (($financial = self::financialFieldFormat($field)) !== NULL) {
      return $financial;
    }
    if (preg_match('/\b(bic|swift)\b/u', $context)) {
      return 'bic';
    }
    if (preg_match('/\b(aktenzeichen|kassenzeichen|referenznummer|reference number|referenz|vorgangsnummer)\b/u', $context)) {
      return 'reference_number';
    }
    if (preg_match('/\b(url|uri|webseite|website|homepage|link|internetadresse|web address)\b/u', $context)) {
      return 'url';
    }
    if (preg_match('/\b(betrag|gebuehr|gebühr|kosten|summe|euro|eur|beitrag)\b/u', $context)) {
      return 'currency';
    }

    return NULL;
  }

  /**
   * Checks whether a financial field must be protected at rest and on edit.
   */
  public static function requiresFinancialProtection(array $field): bool {
    $privacy = $field['privacy'] ?? NULL;
    return (in_array($field['type'] ?? NULL, ['text', 'textarea'], TRUE)
      && is_array($privacy) && ($privacy['kind'] ?? NULL) === 'financial')
      || self::financialFieldFormat($field) !== NULL;
  }

  /**
   * Detects financial formats without inferring one from classification.
   */
  private static function financialFieldFormat(array $field): ?string {
    $format = $field['format'] ?? NULL;
    if (is_string($format)) {
      $alias = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $format));
      $format = self::FIELD_FORMAT_ALIASES[$alias] ?? NULL;
      if (in_array($format, ['iban', 'tax_id'], TRUE)) {
        return $format;
      }
    }

    $context = (string) preg_replace('/[\s_-]+/u', ' ', self::buildFieldContext($field));
    if (($field['crm_key'] ?? NULL) === 'person.iban' || preg_match('/\biban\b/u', $context)) {
      return 'iban';
    }
    // Section 139b AO identifies the personal tax ID even if labelled as a
    // Steuernummer, which normally denotes a different identifier.
    $personal_tax_number = preg_match('/\bsteuer ?nummer\b/u', $context)
      && preg_match('/\b139 ?b ao\b/u', $context);
    if (($field['crm_key'] ?? NULL) === 'person.tax_id'
          || preg_match('/\b(steuer ?id|steuer(?:liche)? ?identifikationsnummer|identifikationsnummer|idnr|tax id)\b/u', $context)
          || $personal_tax_number
      ) {
      return 'tax_id';
    }
    return NULL;
  }

  /**
   * Normalizes explicit schema validation rules.
   *
   * @param mixed $validation
   *   Raw validation configuration.
   *
   * @return array<string, int|float|string>
   *   Normalized validation rules.
   */
  private function normalizeFieldValidation(mixed $validation): array {
    if (!is_array($validation)) {
      return [];
    }

    $normalized = [];
    if (isset($validation['pattern']) && is_string($validation['pattern'])) {
      $pattern = trim($validation['pattern']);
      if ($pattern !== '' && strlen($pattern) <= self::MAX_VALIDATION_PATTERN_LENGTH) {
        $normalized['pattern'] = $pattern;
      }
    }

    foreach (['minLength', 'maxLength'] as $key) {
      $value = $this->normalizeValidationNumber($validation[$key] ?? NULL, 0, TRUE);
      if ($value !== NULL) {
        $normalized[$key] = (int) $value;
      }
    }
    foreach (['min', 'max'] as $key) {
      $value = $this->normalizeValidationNumber($validation[$key] ?? NULL);
      if ($value !== NULL) {
        $normalized[$key] = $value;
      }
    }
    $step = $this->normalizeValidationNumber($validation['step'] ?? NULL, 0);
    if ($step !== NULL) {
      $normalized['step'] = $step;
    }

    return $normalized;
  }

  /**
   * Normalizes numeric validation values.
   *
   * @param mixed $value
   *   Raw numeric value.
   * @param float|null $min
   *   Optional minimum value.
   * @param bool $integer
   *   Whether to truncate the value to an integer.
   */
  private function normalizeValidationNumber(mixed $value, ?float $min = NULL, bool $integer = FALSE): int|float|null {
    if (!is_int($value) && !is_float($value)) {
      return NULL;
    }
    if (!is_finite((float) $value)) {
      return NULL;
    }

    $normalized = $integer ? (int) $value : (float) $value;
    if ($min !== NULL && $normalized < $min) {
      return $integer ? (int) $min : $min;
    }
    return $normalized;
  }

  /**
   * Ensures schema-unknown stored keys cannot be added, changed, or deleted.
   *
   * @param array<string, mixed> $data
   *   Submitted data.
   * @param array<string, mixed> $original_data
   *   Existing stored data.
   * @param array<string, bool> $schema_field_ids
   *   Known schema field IDs.
   */
  private function assertUnknownKeysPreserved(array $data, array $original_data, array $schema_field_ids): void {
    foreach ($original_data as $key => $value) {
      if (isset($schema_field_ids[$key])) {
        continue;
      }
      if (!array_key_exists($key, $data) || $this->stableJson($data[$key]) !== $this->stableJson($value)) {
        throw new UnprocessableEntityHttpException(sprintf('Unknown stored field "%s" cannot be changed.', $key));
      }
    }

    foreach ($data as $key => $value) {
      if (isset($schema_field_ids[$key])) {
        continue;
      }
      if (!array_key_exists($key, $original_data) || $this->stableJson($original_data[$key]) !== $this->stableJson($value)) {
        throw new UnprocessableEntityHttpException(sprintf('Unknown submitted field "%s" is not allowed.', $key));
      }
    }
  }

  /**
   * Returns TRUE when a field is visible for the provided data.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   * @param array<string, mixed> $data
   *   Application data.
   */
  private function fieldIsVisible(array $field, array $data): bool {
    $condition = $field['conditionalOn'] ?? NULL;
    if (!is_array($condition)) {
      return TRUE;
    }

    $dependency_id = $condition['fieldId'] ?? NULL;
    if (!is_string($dependency_id) || $dependency_id === '' || in_array($dependency_id, self::UNSAFE_DATA_KEYS, TRUE)) {
      return TRUE;
    }

    // The shared rule of every PHP evaluator (list operands, empty values).
    return FormValueParser::conditionMatches($condition, $data);
  }

  /**
   * Whether a field is an upload field: the one test of this validator.
   *
   * Decided from the declared type alone: "file" or an alias (upload,
   * attachment, documentupload), before any label- or format-based
   * normalization (normalizeSchemaField() keeps a declared upload a file
   * field; inference never yields "file"). Used for the entry-column rule,
   * the file rule of validate() and the sensitive default. Stored schemas
   * carry "file" only (FormSchema::ALLOWED_FIELD_TYPES at save), the test
   * the rest of the product uses.
   *
   * @param array<string, mixed> $field
   *   Normalized schema field.
   */
  private function isFileField(array $field): bool {
    return ($field['type'] ?? NULL) === 'file';
  }

  /**
   * Validates a single visible schema field value.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   * @param mixed $value
   *   Submitted field value.
   * @param bool $unchanged
   *   Whether the value equals the stored element.
   */
  private function validateFieldValue(array $field, mixed $value, bool $unchanged = FALSE): void {
    $field_id = (string) ($field['id'] ?? '');
    $type = is_string($field['type'] ?? NULL) ? (string) $field['type'] : 'text';
    $required = (bool) ($field['required'] ?? FALSE);
    $options = $this->fieldOptions($field);

    if ($this->fieldValueIsEmpty($value)) {
      if ($required) {
        throw new UnprocessableEntityHttpException(sprintf('Required field "%s" is empty.', $field_id));
      }
      return;
    }

    if ($type === 'checkbox' && $required && $options === [] && $value !== TRUE) {
      throw new UnprocessableEntityHttpException(sprintf('Required checkbox field "%s" must be checked.', $field_id));
    }

    if ($options !== []) {
      $this->validateOptionsValue($field_id, $type, $value, $options);
    }

    if (in_array($type, self::STRING_FIELD_TYPES, TRUE) && !is_string($value)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a string.', $field_id));
    }

    if ($type === 'checkbox' && $options === [] && !is_bool($value)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a boolean.', $field_id));
    }

    if ($type === 'address' && !$this->isValidAddressValue($value)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a structured address.', $field_id));
    }

    // Preserve comma and dot numeric values when another field is edited.
    // New values must satisfy the shared parser's unambiguous-number contract.
    if ($type === 'number' && ($unchanged ? !$this->isNumericValue($value) : (new FormValueParser())->parse(['type' => 'number'], $value)['issue'] !== NULL)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be numeric.', $field_id));
    }

    if ($type === 'email' && is_string($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be an email address.', $field_id));
    }

    if ($type === 'date' && is_string($value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be an ISO date.', $field_id));
    }

    if (($field['format'] ?? NULL) === 'url' && is_string($value) && !$this->validUrlFormat($value)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a valid URL.', $field_id));
    }

    $this->validateFieldRules($field, $value);
  }

  /**
   * Returns TRUE for the canonical structured address value.
   */
  private function isValidAddressValue(mixed $value): bool {
    $normalized = $this->normalizeAddressValue($value);
    return $normalized !== NULL && $this->stableJson($normalized) === $this->stableJson($value);
  }

  /**
   * Normalizes a structured address to the public submission contract.
   *
   * @return array<string, int|float|string>|null
   *   Canonical address or NULL for an invalid value.
   */
  private function normalizeAddressValue(mixed $value): ?array {
    if (!is_array($value) || array_is_list($value)) {
      return NULL;
    }
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
        return NULL;
      }
      if (in_array($key, $string_keys, TRUE) && !is_string($item)) {
        return NULL;
      }
      if (in_array($key, ['lat', 'lng'], TRUE) && !is_int($item) && !is_float($item)) {
        return NULL;
      }
    }

    $normalized = [];
    foreach (array_slice($string_keys, 0, -1) as $key) {
      $part = preg_replace('/\s+/u', ' ', trim((string) ($value[$key] ?? '')));
      if (is_string($part) && $part !== '') {
        $normalized[$key] = $key === 'countryCode'
          ? strtoupper(substr($part, 0, 2))
          : substr($part, 0, 200);
      }
    }
    $formatted = preg_replace('/\s+/u', ' ', trim((string) ($value['formatted'] ?? '')));
    if (!is_string($formatted) || $formatted === '') {
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
      if ($coordinate !== NULL) {
        if (!is_finite((float) $coordinate) || (float) $coordinate < $min || (float) $coordinate > $max) {
          return NULL;
        }
        $normalized[$key] = $coordinate;
      }
    }
    return $normalized;
  }

  /**
   * Returns normalized field options.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   *
   * @return string[]
   *   Allowed option values.
   */
  private function fieldOptions(array $field): array {
    $options = $field['options'] ?? [];
    if (!is_array($options)) {
      return [];
    }
    return array_values(array_map('strval', $options));
  }

  /**
   * Validates select, radio, and checkbox-group values.
   *
   * @param string $field_id
   *   Field ID being validated.
   * @param string $type
   *   Field type.
   * @param mixed $value
   *   Submitted field value.
   * @param string[] $options
   *   Allowed option values.
   */
  private function validateOptionsValue(string $field_id, string $type, mixed $value, array $options): void {
    if ($type === 'checkbox') {
      if (!is_array($value) || !array_is_list($value)) {
        throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a list of options.', $field_id));
      }
      foreach ($value as $item) {
        if (!is_string($item) || !in_array($item, $options, TRUE)) {
          throw new UnprocessableEntityHttpException(sprintf('Field "%s" contains an invalid option.', $field_id));
        }
      }
      return;
    }

    if (($type === 'select' || $type === 'radio') && !in_array((string) $value, $options, TRUE)) {
      throw new UnprocessableEntityHttpException(sprintf('Field "%s" contains an invalid option.', $field_id));
    }
  }

  /**
   * Validates scalar validation rules.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   * @param mixed $value
   *   Submitted field value.
   */
  private function validateFieldRules(array $field, mixed $value): void {
    $validation = $field['validation'] ?? NULL;
    if (!is_array($validation)) {
      return;
    }

    $field_id = (string) ($field['id'] ?? '');
    if (is_string($value)) {
      $trimmed = trim($value);
      if (isset($validation['minLength']) && is_numeric($validation['minLength']) && mb_strlen($trimmed) < (int) $validation['minLength']) {
        throw new UnprocessableEntityHttpException(sprintf('Field "%s" is too short.', $field_id));
      }
      if (isset($validation['maxLength']) && is_numeric($validation['maxLength']) && mb_strlen($trimmed) > (int) $validation['maxLength']) {
        throw new UnprocessableEntityHttpException(sprintf('Field "%s" is too long.', $field_id));
      }
      if (isset($validation['pattern']) && is_string($validation['pattern'])) {
        $pattern = trim($validation['pattern']);
        if ($pattern !== '' && $this->matchValidationPattern($pattern, $trimmed) !== TRUE) {
          throw new UnprocessableEntityHttpException(sprintf('Field "%s" does not match the required pattern.', $field_id));
        }
      }
    }

    if ($this->isNumericValue($value)) {
      $number = is_int($value) || is_float($value) ? (float) $value : (float) str_replace(',', '.', (string) $value);
      if (isset($validation['min']) && is_numeric($validation['min']) && $number < (float) $validation['min']) {
        throw new UnprocessableEntityHttpException(sprintf('Field "%s" is below the minimum.', $field_id));
      }
      if (isset($validation['max']) && is_numeric($validation['max']) && $number > (float) $validation['max']) {
        throw new UnprocessableEntityHttpException(sprintf('Field "%s" is above the maximum.', $field_id));
      }
      if (isset($validation['step']) && is_numeric($validation['step']) && (float) $validation['step'] > 0.0) {
        $base = isset($validation['min']) && is_numeric($validation['min']) ? (float) $validation['min'] : 0.0;
        $remainder = abs(($number - $base) / (float) $validation['step']);
        if (abs($remainder - round($remainder)) > 0.0000001) {
          throw new UnprocessableEntityHttpException(sprintf('Field "%s" does not match the required step.', $field_id));
        }
      }
    }
  }

  /**
   * Matches a schema validation pattern with bounded PCRE work.
   */
  private function matchValidationPattern(string $pattern, string $value): ?bool {
    if (strlen($pattern) > self::MAX_VALIDATION_PATTERN_LENGTH) {
      return NULL;
    }

    $regex = sprintf(
          '/(*LIMIT_MATCH=%d)(*LIMIT_RECURSION=%d)%s/u',
          self::VALIDATION_PATTERN_MATCH_LIMIT,
          self::VALIDATION_PATTERN_RECURSION_LIMIT,
          str_replace('/', '\/', $pattern),
      );
    $result = @preg_match($regex, $value);
    return $result === FALSE ? NULL : $result === 1;
  }

  /**
   * Returns TRUE when a submitted value is empty.
   */
  private function fieldValueIsEmpty(mixed $value): bool {
    if ($value === NULL) {
      return TRUE;
    }
    if (is_string($value)) {
      return trim($value) === '';
    }
    if (is_array($value)) {
      return $value === [];
    }
    if (is_bool($value)) {
      return $value === FALSE;
    }
    return FALSE;
  }

  /**
   * Returns TRUE for finite numeric input values.
   */
  private function isNumericValue(mixed $value): bool {
    if (is_int($value) || is_float($value)) {
      return is_finite((float) $value);
    }
    if (!is_string($value)) {
      return FALSE;
    }
    return is_numeric(str_replace(',', '.', $value));
  }

  /**
   * Validates the same simple public URL shape as the Nuxt form validator.
   *
   * @param string $value
   *   URL value.
   */
  private function validUrlFormat(string $value): bool {
    $candidate = trim($value);
    if ($candidate === '' || preg_match('/\s/u', $candidate)) {
      return FALSE;
    }

    if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $candidate)) {
      $candidate = 'https://' . $candidate;
    }

    $parts = parse_url($candidate);
    if (!is_array($parts)) {
      return FALSE;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
      return FALSE;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }

    $host = rtrim((string) ($parts['host'] ?? ''), '.');
    $labels = $host !== '' ? explode('.', $host) : [];
    if (count($labels) < 2) {
      return FALSE;
    }
    foreach ($labels as $label) {
      if (!preg_match('/^[\p{L}0-9](?:[\p{L}0-9-]{0,61}[\p{L}0-9])?$/u', $label)) {
        return FALSE;
      }
    }

    if (isset($parts['port']) && $parts['port'] < 1) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Encodes a value for stable equality checks.
   */
  private function stableJson(mixed $value): string {
    try {
      return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
    catch (\JsonException) {
      return '';
    }
  }

  /**
   * Recursively checks nested arrays.
   *
   * @param array<mixed> $value
   *   Nested submitted value.
   */
  private function assertSafeNestedValue(array $value): void {
    foreach ($value as $key => $nested) {
      if (is_string($key) && in_array($key, ['__proto__', 'constructor', 'prototype'], TRUE)) {
        throw new BadRequestHttpException('Application data contains an invalid key.');
      }
      if (is_array($nested)) {
        $this->assertSafeNestedValue($nested);
      }
    }
  }

}
