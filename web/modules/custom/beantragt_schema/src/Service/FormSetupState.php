<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Service;

use Drupal\beantragt_schema\FormCompilerOrigin;
use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * What Drupal decides for the guided form setup.
 *
 * Eligibility, publish blockers, property resolution against the stored
 * schema and the `current` comparison of decisions. Nuxt displays these
 * results; it never derives them.
 */
final class FormSetupState {

  /**
   * Compile caps a section at 100 fields; larger sections are not eligible.
   */
  public const MAX_FIELDS_PER_SECTION = 100;

  /**
   * Properties a decision may refer to.
   */
  public const DECISION_PROPERTIES = [
    'wording',
    'privacy.kind',
    'sensitive',
    'format',
    'validation',
    'analysis.document',
    'analysis.expectedDocumentType',
  ];

  /**
   * Properties an information sheet recommendation may refer to.
   */
  public const RECOMMENDATION_PROPERTIES = [
    'format',
    'validation',
    'analysis.expectedDocumentType',
  ];

  /**
   * Validation keys per field type that a recommendation may set.
   */
  public const VALIDATION_KEYS = [
    'text' => ['minLength', 'maxLength'],
    'textarea' => ['minLength', 'maxLength'],
    'number' => ['min', 'max'],
  ];

  /**
   * Field type each format forces (FIELD_FORMAT_DEFAULTS in the frontend).
   *
   * A format question only exists where the format keeps the field type.
   */
  public const FORMAT_FIELD_TYPES = [
    'postal_code' => 'text',
    'iban' => 'text',
    'bic' => 'text',
    'tax_id' => 'text',
    'reference_number' => 'text',
    'url' => 'text',
    'currency' => 'number',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns why a form is not eligible for the assistant, NULL if it is.
   *
   * @return 'not_draft'|'was_published'|'not_compiled'|'section_too_large'|null
   *   Reason code.
   */
  public function ineligibleReason(FormSchemaInterface $form): ?string {
    if ($form->getSchemaStatus() !== 'draft') {
      return 'not_draft';
    }
    if ($this->wasEverPublished($form)) {
      return 'was_published';
    }
    $schema = $this->decodedSchema($form);
    if (!FormCompilerOrigin::matches($schema, $form->getSourceFilename())) {
      return 'not_compiled';
    }
    foreach ($this->sections($schema) as $section) {
      if (count($this->fields($section)) > self::MAX_FIELDS_PER_SECTION) {
        return 'section_too_large';
      }
    }
    return NULL;
  }

  /**
   * Returns every publish blocker of the stored schema, in schema order.
   *
   * Runs on the same normalized payload as the publish path
   * (ValidFormSchemaDefinitionConstraintValidator and preSave()), so the
   * list is empty exactly when the publish gate would let the schema pass.
   * Form-level refusals come first as `schema_invalid` without section and
   * field.
   *
   * @return list<array{sectionId: string|null, fieldId: string|null, code: string}>
   *   Blockers.
   */
  public function blockers(FormSchemaInterface $form): array {
    $schema_value = $form->getSchema();
    $blockers = [];
    $invalid = ['sectionId' => NULL, 'fieldId' => NULL, 'code' => 'schema_invalid'];
    if ($schema_value === NULL) {
      return [$invalid];
    }
    try {
      FormSchema::validateFormSchemaJson($schema_value);
    }
    catch (\InvalidArgumentException) {
      return [$invalid];
    }
    $normalized = FormSchema::normalizeSchemaPrivacyKinds($schema_value);
    try {
      // Same order as the publish path: a published save re-validates the
      // stored definition, then the normalized payload must have a title,
      // sections and a field.
      FormSchema::validateFormSchemaDefinitionDetails($schema_value);
      FormSchema::validatePublishedFormSchemaShape($normalized);
    }
    catch (\InvalidArgumentException) {
      $blockers[] = $invalid;
    }
    foreach (FormSchema::collectPublishBlockers($normalized) as $blocker) {
      $blockers[] = [
        'sectionId' => $blocker['sectionId'],
        'fieldId' => $blocker['fieldId'],
        'code' => $blocker['code'],
      ];
    }
    return $blockers;
  }

  /**
   * Decodes the stored schema of a form; empty array when unusable.
   *
   * @return array<string, mixed>
   *   Decoded schema object.
   */
  public function decodedSchema(FormSchemaInterface $form): array {
    $value = $form->getSchema();
    if ($value === NULL) {
      return [];
    }
    try {
      $schema = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    return is_array($schema) && !array_is_list($schema) ? $schema : [];
  }

  /**
   * Returns the section with the given id, or NULL.
   *
   * @param array<string, mixed> $schema
   *   Decoded schema.
   * @param string $screen_id
   *   Section id.
   *
   * @return array<string, mixed>|null
   *   Section.
   */
  public function section(array $schema, string $screen_id): ?array {
    foreach ($this->sections($schema) as $section) {
      if (($section['id'] ?? NULL) === $screen_id) {
        return $section;
      }
    }
    return NULL;
  }

  /**
   * Returns the field with the given id inside a section, or NULL.
   *
   * @param array<string, mixed> $section
   *   Section.
   * @param string $field_id
   *   Field id.
   *
   * @return array<string, mixed>|null
   *   Field.
   */
  public function field(array $section, string $field_id): ?array {
    foreach ($this->fields($section) as $field) {
      if (($field['id'] ?? NULL) === $field_id) {
        return $field;
      }
    }
    return NULL;
  }

  /**
   * Whether a property may be decided for a field (or the screen).
   *
   * `wording` is screen-level (no field). `analysis.*` only on file fields
   * (`analysis.document` only when the field has an analysis object),
   * `format` only on text and number, `validation` only on text, textarea
   * and number. Class and sensitive flag fit every field.
   *
   * @param string $property
   *   Property.
   * @param array<string, mixed>|null $field
   *   Field, NULL for a screen-level property.
   */
  public function propertyFits(string $property, ?array $field): bool {
    if ($property === 'wording') {
      return $field === NULL;
    }
    if ($field === NULL || !in_array($property, self::DECISION_PROPERTIES, TRUE)) {
      return FALSE;
    }
    $type = $field['type'] ?? NULL;
    return match ($property) {
      'privacy.kind', 'sensitive' => TRUE,
      'format' => in_array($type, ['text', 'number'], TRUE),
      'validation' => is_string($type) && isset(self::VALIDATION_KEYS[$type]),
      'analysis.document' => $type === 'file' && is_array($field['analysis'] ?? NULL),
      'analysis.expectedDocumentType' => $type === 'file',
      default => FALSE,
    };
  }

  /**
   * Reads the value of a property from the stored schema.
   *
   * For `validation` the value is the projection of the stored object onto
   * the given keys (missing key = NULL).
   *
   * @param array<string, mixed> $section
   *   Section.
   * @param array<string, mixed>|null $field
   *   Field, NULL for `wording`.
   * @param string $property
   *   Property.
   * @param list<string> $validation_keys
   *   Keys to project `validation` onto.
   */
  public function valueAt(array $section, ?array $field, string $property, array $validation_keys = []): mixed {
    if ($property === 'wording') {
      return array_map(static fn(array $item): array => [
        'id' => $item['id'] ?? NULL,
        'label' => $item['label'] ?? NULL,
        'placeholder' => $item['placeholder'] ?? NULL,
      ], $this->fields($section));
    }
    $field ??= [];
    $analysis = is_array($field['analysis'] ?? NULL) ? $field['analysis'] : [];
    return match ($property) {
      'privacy.kind' => is_array($field['privacy'] ?? NULL) ? ($field['privacy']['kind'] ?? NULL) : NULL,
      'sensitive' => $field['sensitive'] ?? NULL,
      'format' => $field['format'] ?? NULL,
      'validation' => self::project($field['validation'] ?? NULL, $validation_keys),
      'analysis.document' => [
        'expectedDocumentType' => $analysis['expectedDocumentType'] ?? NULL,
        'resultSchema' => $analysis['resultSchema'] ?? NULL,
      ],
      'analysis.expectedDocumentType' => $analysis['expectedDocumentType'] ?? NULL,
      default => NULL,
    };
  }

  /**
   * Whether a stored decision value still equals the schema value.
   *
   * @param array<string, mixed> $schema
   *   Decoded schema of the current revision.
   * @param array<string, mixed> $decision
   *   Decoded decision row.
   */
  public function isCurrent(array $schema, array $decision): bool {
    $section = $this->section($schema, (string) $decision['screen_id']);
    if ($section === NULL) {
      return FALSE;
    }
    $field = NULL;
    if ($decision['field_id'] !== '') {
      $field = $this->field($section, (string) $decision['field_id']);
      if ($field === NULL) {
        return FALSE;
      }
    }
    $keys = $decision['property'] === 'validation' && is_array($decision['value'])
      ? array_map('strval', array_keys($decision['value']))
      : [];
    return self::sameValue($this->valueAt($section, $field, (string) $decision['property'], $keys), $decision['value']);
  }

  /**
   * Context sources of the schema that are enabled for the editor.
   *
   * `enabledFor` defaults to the editor when absent, as in the context
   * source resolver.
   *
   * @param array<string, mixed> $schema
   *   Decoded schema.
   *
   * @return array<string, string|null>
   *   Stored version keyed by lowercase source UUID.
   */
  public function editorContextSources(array $schema): array {
    $sources = [];
    $selections = $schema['contextSources'] ?? [];
    if (!is_array($selections)) {
      return [];
    }
    foreach ($selections as $selection) {
      if (!is_array($selection) || !is_string($selection['id'] ?? NULL)) {
        continue;
      }
      $enabled_for = $selection['enabledFor'] ?? ['editor'];
      if (!is_array($enabled_for) || !in_array('editor', $enabled_for, TRUE)) {
        continue;
      }
      $sources[strtolower($selection['id'])] = is_string($selection['version'] ?? NULL) ? $selection['version'] : NULL;
    }
    return $sources;
  }

  /**
   * Compares two decoded JSON values with sorted object keys, strictly.
   */
  public static function sameValue(mixed $a, mixed $b): bool {
    return self::canonical($a) === self::canonical($b);
  }

  /**
   * Projects a validation object onto keys; a missing key is NULL.
   *
   * @param mixed $validation
   *   Stored validation object.
   * @param list<string> $keys
   *   Keys.
   *
   * @return array<string, mixed>
   *   Projection.
   */
  public static function project(mixed $validation, array $keys): array {
    $validation = is_array($validation) ? $validation : [];
    $projection = [];
    foreach ($keys as $key) {
      $projection[$key] = $validation[$key] ?? NULL;
    }
    return $projection;
  }

  /**
   * Whether any stored revision of the form was published.
   */
  private function wasEverPublished(FormSchemaInterface $form): bool {
    if ($form->isNew()) {
      return FALSE;
    }
    $ids = $this->entityTypeManager->getStorage('form_schema')->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', $form->id())
      ->condition('field_workflow_status', 'published')
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * Returns the sections of a decoded schema that are objects.
   *
   * @param array<string, mixed> $schema
   *   Decoded schema.
   *
   * @return list<array<string, mixed>>
   *   Sections.
   */
  private function sections(array $schema): array {
    $sections = $schema['sections'] ?? [];
    if (!is_array($sections)) {
      return [];
    }
    return array_values(array_filter($sections, static fn(mixed $section): bool => is_array($section)));
  }

  /**
   * Returns the fields of a section that are objects.
   *
   * @param array<string, mixed> $section
   *   Section.
   *
   * @return list<array<string, mixed>>
   *   Fields.
   */
  private function fields(array $section): array {
    $fields = $section['fields'] ?? [];
    if (!is_array($fields)) {
      return [];
    }
    return array_values(array_filter($fields, static fn(mixed $field): bool => is_array($field)));
  }

  /**
   * Sorts object keys recursively; lists keep their order.
   */
  private static function canonical(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
      $value[$key] = self::canonical($item);
    }
    return $value;
  }

}
