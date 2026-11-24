<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Field\FieldWidget;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\json_form_widget\FormBuilder;
use Drupal\json_form_widget\Plugin\Field\FieldWidget\JsonFormWidgetBase;
use Drupal\json_form_widget\ValueHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * JSON Form widget for editing form_schema.field_schema (the form DSL).
 *
 * Renders a structured editor for the beantragt form DSL (sections + fields)
 * driven by schema/form_schema.schema.json and schema/form_schema.ui.json.
 *
 * Known limitations:
 * - json_form_widget does not support `type: boolean` in its ValueHandler
 *   (see ValueHandler.php switch on $schema->type - boolean falls through),
 *   so boolean DSL properties like sections[].fields[].required cannot be
 *   edited via this widget. extractFormValues() preserves them from the
 *   original entity value.
 * - preserveUnsupportedProperties() walks known DSL levels
 *   (root, semantics, source, sections[], sections[].fields[],
 *   sections[].fields[].validation, analysis, and widget). Address groups and
 *   field semantics are deliberately hidden and preserved because the contrib
 *   widget cannot safely edit their nested structure. Deeper hidden metadata
 *   must be added to the visible-key lists below or it will not be preserved.
 * - A changed section.id or field.id is treated as a rename only when the old
 *   id no longer exists in the submitted list and exactly one original item
 *   with stable visible identity keys still matches. Without a stable internal
 *   item UUID, this avoids copying hidden metadata to unrelated delete-and-add
 *   replacements.
 */
#[FieldWidget(
  id: 'form_schema_json_form',
  label: new TranslatableMarkup('Form Schema JSON Form'),
  description: new TranslatableMarkup('Structured editor for the beantragt form DSL (sections + fields).'),
  field_types: ['string_long'],
)]
final class FormSchemaJsonFormWidget extends JsonFormWidgetBase {

  /**
   * The schema path relative to module root.
   */
  protected const SCHEMA_PATH = 'schema/form_schema.schema.json';

  /**
   * The UI schema path relative to module root.
   */
  protected const UI_SCHEMA_PATH = 'schema/form_schema.ui.json';

  /**
   * Keys editable by the widget at the schema root.
   */
  protected const ROOT_WIDGET_KEYS = [
    'id',
    'title',
    'description',
    'version',
    'sections',
    'semantics',
    'source',
    'delivery',
  ];

  /**
   * Keys editable by the widget under semantics.
   */
  protected const SEMANTICS_WIDGET_KEYS = [
    'category',
    'process',
    'entities',
  ];

  /**
   * Keys editable by the widget under source.
   */
  protected const SOURCE_WIDGET_KEYS = [
    'type',
    'file',
    'url',
    'label',
  ];

  /**
   * Keys editable by the widget on a section.
   */
  protected const SECTION_WIDGET_KEYS = [
    'id',
    'title',
    'description',
    'fields',
  ];

  /**
   * Keys editable by the widget on a field.
   */
  protected const FIELD_WIDGET_KEYS = [
    'id',
    'type',
    'label',
    'placeholder',
    'crm_key',
    'format',
    'validation',
    'analysis',
    'computed',
    'widget',
    'options',
  ];

  /**
   * Validation keys editable by the widget.
   */
  protected const VALIDATION_WIDGET_KEYS = [
    'minLength',
    'maxLength',
    'pattern',
  ];

  /**
   * Attachment-analysis keys editable by the legacy JSON form widget.
   */
  protected const ANALYSIS_WIDGET_KEYS = [
    'version',
    'expectedDocumentType',
    'promptSuggestion',
    'citizenPrompt',
    'staffPrompt',
    'redaction',
    'modelClass',
    'egressPolicy',
  ];

  /**
   * Address-widget keys editable by the legacy JSON form widget.
   */
  protected const ADDRESS_WIDGET_KEYS = [
    'boundary',
  ];

  /**
   * Constructs the widget.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Third party settings.
   * @param \Drupal\json_form_widget\FormBuilder $builder
   *   The JSON form builder.
   * @param \Drupal\json_form_widget\ValueHandler $value_handler
   *   The JSON form value handler.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    FormBuilder $builder,
    ValueHandler $value_handler,
    protected readonly ModuleExtensionList $moduleExtensionList,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings, $builder, $value_handler);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('json_form.builder'),
      $container->get('json_form.value_handler'),
      $container->get('extension.list.module'),
      $container->get('logger.factory')->get('beantragt_schema'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state): void {
    $original_raw = $items->isEmpty() ? NULL : ($items[0]->value ?? NULL);
    $original = NULL;
    if ($original_raw) {
      $decoded = json_decode($original_raw, TRUE);
      if (is_array($decoded)) {
        $original = $decoded;
      }
    }

    parent::extractFormValues($items, $form, $form_state);

    $new_raw = $items[0]->value ?? NULL;
    if (!$new_raw) {
      return;
    }
    $new = json_decode($new_raw, TRUE);
    if (!is_array($new)) {
      return;
    }

    $normalized = $this->normalizeOptionalFieldGroups($new, $original);
    $merged = $original === NULL
      ? $normalized
      : $this->preserveUnsupportedProperties($normalized, $original);
    // JSON_PRETTY_PRINT matches the seed-scripts' encoding, so a first edit
    // after seeding does not flicker the storage representation.
    $json_options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $items->setValue([['value' => json_encode($merged, $json_options)]]);
  }

  /**
   * Removes synthetic values emitted for empty optional field groups.
   *
   * Json_form_widget renders nested objects eagerly. Integer and select
   * defaults therefore make an untouched analysis panel look populated, while
   * blank computed controls may produce an empty object. Existing profiles are
   * retained because their presence in stored JSON represents an explicit API
   * or authoring decision and must survive structured-editor round trips.
   * Partially populated computed objects deliberately remain in the extracted
   * data so the entity constraint can report their invalid shape.
   *
   * @param array $schema
   *   Newly extracted schema data.
   * @param array|null $original
   *   Stored schema data for edit forms.
   *
   * @return array
   *   Normalized schema data.
   */
  protected function normalizeOptionalFieldGroups(array $schema, ?array $original = NULL): array {
    if (!isset($schema['sections']) || !is_array($schema['sections'])) {
      return $schema;
    }

    $original_sections = is_array($original['sections'] ?? NULL)
      ? $original['sections']
      : [];

    foreach ($schema['sections'] as &$section) {
      if (!is_array($section) || !isset($section['fields']) || !is_array($section['fields'])) {
        continue;
      }

      $original_section = NULL;
      if (isset($section['id']) && is_string($section['id'])) {
        $original_section = $this->findById($original_sections, $section['id'])
          ?? $this->itemWithRemovedIdAndMatchingIdentity(
            $original_sections,
            $schema['sections'],
            $section,
            ['title'],
          );
      }
      $original_fields = is_array($original_section['fields'] ?? NULL)
        ? $original_section['fields']
        : [];

      foreach ($section['fields'] as &$field) {
        if (!is_array($field)) {
          continue;
        }

        $original_field = NULL;
        if (isset($field['id']) && is_string($field['id'])) {
          $original_field = $this->findById($original_fields, $field['id'])
            ?? $this->itemWithRemovedIdAndMatchingIdentity(
              $original_fields,
              $section['fields'],
              $field,
              ['type', 'label'],
            );
        }

        $original_computed = is_array($original_field['computed'] ?? NULL)
          ? $original_field['computed']
          : NULL;
        if (is_array($field['computed'] ?? NULL)
          && $original_computed !== NULL
          && !array_key_exists('args', $field['computed'])
          && ($original_computed['args'] ?? NULL) === []) {
          $field['computed']['args'] = [];
        }
        if (array_key_exists('computed', $field) && self::isEmptyComputedGroup($field['computed'])) {
          unset($field['computed']);
        }

        $original_analysis = is_array($original_field['analysis'] ?? NULL)
          ? $original_field['analysis']
          : NULL;
        if (is_array($field['analysis'] ?? NULL) && $original_analysis !== NULL) {
          self::removeUnstoredDefaults(
            $field['analysis'],
            $original_analysis,
            ['version' => 1],
          );
        }
        $had_analysis = $original_analysis !== NULL;
        if (!$had_analysis
          && array_key_exists('analysis', $field)
          && self::isDefaultAnalysisGroup($field['analysis'])) {
          unset($field['analysis']);
        }

        $original_address_widget = is_array($original_field['widget'] ?? NULL)
          ? $original_field['widget']
          : NULL;
        if (is_array($field['widget'] ?? NULL) && $original_address_widget !== NULL) {
          self::removeUnstoredDefaults(
            $field['widget'],
            $original_address_widget,
            ['boundary' => 'off'],
          );
        }
        $had_address_widget = $original_address_widget !== NULL;
        if (!$had_address_widget
          && array_key_exists('widget', $field)
          && self::isDefaultAddressWidgetGroup($field['widget'])) {
          unset($field['widget']);
        }
      }
      unset($field);
    }
    unset($section);

    return $schema;
  }

  /**
   * Removes defaults the eager widget added to an existing optional object.
   */
  protected static function removeUnstoredDefaults(array &$submitted, array $original, array $defaults): void {
    foreach ($defaults as $key => $default) {
      if (!array_key_exists($key, $original)
        && array_key_exists($key, $submitted)
        && $submitted[$key] === $default) {
        unset($submitted[$key]);
      }
    }
  }

  /**
   * Checks whether a computed group contains no authored value.
   */
  protected static function isEmptyComputedGroup(mixed $computed): bool {
    if (!is_array($computed)) {
      return FALSE;
    }

    if (array_key_exists('fn', $computed)) {
      if (!is_string($computed['fn']) || trim($computed['fn']) !== '') {
        return FALSE;
      }
    }

    $arguments = $computed['args'] ?? [];
    if (!is_array($arguments)) {
      return FALSE;
    }
    foreach ($arguments as $argument) {
      if (!is_string($argument) || trim($argument) !== '') {
        return FALSE;
      }
    }

    $known_keys = ['fn', 'args'];
    return array_diff(array_keys($computed), $known_keys) === [];
  }

  /**
   * Checks whether analysis contains only eager widget defaults.
   */
  protected static function isDefaultAnalysisGroup(mixed $analysis): bool {
    if (!is_array($analysis)) {
      return FALSE;
    }
    if (($analysis['egressPolicy'] ?? NULL) !== 'no_ai') {
      return FALSE;
    }

    $defaults = [
      'enabled' => FALSE,
      'version' => 1,
      'expectedDocumentType' => '',
      'promptSuggestion' => '',
      'citizenPrompt' => '',
      'staffPrompt' => '',
      'redaction' => '',
      'modelClass' => '',
      'egressPolicy' => 'no_ai',
    ];
    foreach ($analysis as $key => $value) {
      if (!array_key_exists($key, $defaults) || $value !== $defaults[$key]) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks whether an address widget contains only eager widget defaults.
   */
  protected static function isDefaultAddressWidgetGroup(mixed $widget): bool {
    if (!is_array($widget)) {
      return FALSE;
    }
    if (($widget['boundary'] ?? NULL) !== 'off') {
      return FALSE;
    }

    $defaults = [
      'autocomplete' => TRUE,
      'map' => FALSE,
      'boundary' => 'off',
    ];
    foreach ($widget as $key => $value) {
      if (!array_key_exists($key, $defaults) || $value !== $defaults[$key]) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Re-merges DSL properties that json_form_widget cannot round-trip.
   *
   * The contrib widget only re-serializes properties declared in the meta
   * schema. To prevent data loss, this copies back only keys that are not
   * editable by the widget. Widget-visible keys are never restored, so users
   * can intentionally clear labels, descriptions, placeholders, validation
   * rules, and similar rendered inputs.
   *
   * Scope of preservation:
   * - Top-level keys (e.g. `workflow`, future additions)
   * - `semantics.*` hidden sub-keys (attachments, legal_references, etc.)
   * - `source.*` sub-keys (fileName, mediaType, schemaId, version, importedAt)
   * - `sections[].*` by id (repeatable, maxInstances, etc.)
   * - `sections[].fields[].*` by id (required, sensitive, conditionalOn,
   *   defaultValue, etc.)
   * - Hidden validation keys, for example min, max and step
   *
   * @param array $new
   *   The newly-extracted JSON structure (post-parent).
   * @param array $original
   *   The previous JSON structure (pre-edit) from the entity.
   *
   * @return array
   *   The merged structure.
   */
  protected function preserveUnsupportedProperties(array $new, array $original): array {
    self::mergeHiddenKeys($new, $original, self::ROOT_WIDGET_KEYS);

    $this->preserveNestedObject(
      $new,
      $original,
      'semantics',
      self::SEMANTICS_WIDGET_KEYS,
    );

    $this->preserveNestedObject(
      $new,
      $original,
      'source',
      self::SOURCE_WIDGET_KEYS,
    );

    if (!empty($new['sections']) && is_array($new['sections'])
      && !empty($original['sections']) && is_array($original['sections'])) {
      foreach ($new['sections'] as &$section) {
        if (!is_array($section) || !isset($section['id']) || !is_string($section['id']) || $section['id'] === '') {
          continue;
        }
        $original_section = $this->findById($original['sections'], $section['id'])
          ?? $this->itemWithRemovedIdAndMatchingIdentity(
            $original['sections'],
            $new['sections'],
            $section,
            ['title'],
          );
        if ($original_section === NULL) {
          continue;
        }

        self::mergeHiddenKeys(
          $section,
          $original_section,
          self::SECTION_WIDGET_KEYS,
        );

        if (!empty($section['fields']) && is_array($section['fields'])
          && !empty($original_section['fields']) && is_array($original_section['fields'])) {
          foreach ($section['fields'] as &$field) {
            if (!is_array($field) || !isset($field['id']) || !is_string($field['id']) || $field['id'] === '') {
              continue;
            }
            $original_field = $this->findById($original_section['fields'], $field['id'])
              ?? $this->itemWithRemovedIdAndMatchingIdentity(
                $original_section['fields'],
                $section['fields'],
                $field,
                ['type', 'label'],
              );
            if ($original_field === NULL) {
              continue;
            }

            self::mergeHiddenKeys($field, $original_field, self::FIELD_WIDGET_KEYS);
            $this->preserveNestedObject(
              $field,
              $original_field,
              'validation',
              self::VALIDATION_WIDGET_KEYS,
            );
            $this->preserveNestedObject(
              $field,
              $original_field,
              'analysis',
              self::ANALYSIS_WIDGET_KEYS,
              FALSE,
            );
            $this->preserveNestedObject(
              $field,
              $original_field,
              'widget',
              self::ADDRESS_WIDGET_KEYS,
              FALSE,
            );
          }
          unset($field);
        }
      }
      unset($section);
    }

    return $new;
  }

  /**
   * Preserves a nested object if it contains hidden keys.
   *
   * @param array $target
   *   Newly extracted parent object, modified in place.
   * @param array $source
   *   Original parent object.
   * @param string $key
   *   Nested object key.
   * @param string[] $visible_keys
   *   Keys editable through the widget.
   * @param bool $restore_when_missing
   *   Whether to restore hidden keys when the submitted object is absent.
   */
  protected function preserveNestedObject(
    array &$target,
    array $source,
    string $key,
    array $visible_keys,
    bool $restore_when_missing = TRUE,
  ): void {
    if (!isset($source[$key]) || !is_array($source[$key])) {
      return;
    }

    if (isset($target[$key]) && is_array($target[$key])) {
      self::mergeHiddenKeys($target[$key], $source[$key], $visible_keys);
      return;
    }

    $hidden = $restore_when_missing
      ? self::hiddenKeys($source[$key], $visible_keys)
      : [];
    if ($hidden !== []) {
      $target[$key] = $hidden;
    }
  }

  /**
   * Copies hidden keys present in $source but missing from $target.
   *
   * @param array $target
   *   The array to merge missing keys into. Modified in place.
   * @param array $source
   *   The donor array.
   * @param array $visible_keys
   *   Keys the widget renders. These are never restored from the original.
   */
  protected static function mergeHiddenKeys(array &$target, array $source, array $visible_keys): void {
    foreach ($source as $key => $value) {
      if (in_array($key, $visible_keys, TRUE)) {
        continue;
      }
      if (!array_key_exists($key, $target)) {
        $target[$key] = $value;
      }
    }
  }

  /**
   * Returns keys from $source that are not rendered by the widget.
   */
  protected static function hiddenKeys(array $source, array $visible_keys): array {
    $hidden = [];
    foreach ($source as $key => $value) {
      if (!in_array($key, $visible_keys, TRUE)) {
        $hidden[$key] = $value;
      }
    }
    return $hidden;
  }

  /**
   * Find a list item by its 'id' property.
   */
  protected function findById(array $items, string $id): ?array {
    foreach ($items as $item) {
      if (is_array($item) && ($item['id'] ?? NULL) === $id) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Returns a rename candidate whose original id disappeared from the list.
   */
  protected function itemWithRemovedIdAndMatchingIdentity(
    array $items,
    array $new_items,
    array $new_item,
    array $identity_keys,
  ): ?array {
    if (!$this->newIdentityIsUnique($new_items, $new_item, $identity_keys)) {
      return NULL;
    }

    $new_ids = $this->idsInList($new_items);
    $matches = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }
      $candidate_id = $item['id'] ?? NULL;
      if (!is_string($candidate_id) || $candidate_id === '' || isset($new_ids[$candidate_id])) {
        continue;
      }
      if ($this->identityMatches($item, $new_item, $identity_keys)) {
        $matches[] = $item;
      }
    }

    return count($matches) === 1 ? $matches[0] : NULL;
  }

  /**
   * Returns known item IDs in a list, keyed by id.
   */
  protected function idsInList(array $items): array {
    $ids = [];
    foreach ($items as $item) {
      if (is_array($item) && isset($item['id']) && is_string($item['id']) && $item['id'] !== '') {
        $ids[$item['id']] = TRUE;
      }
    }
    return $ids;
  }

  /**
   * Checks if a new item's visible identity is unique in the submitted list.
   */
  protected function newIdentityIsUnique(array $new_items, array $new_item, array $identity_keys): bool {
    $matches = 0;
    foreach ($new_items as $item) {
      if (is_array($item) && $this->identityMatches($item, $new_item, $identity_keys)) {
        $matches++;
      }
    }
    return $matches === 1;
  }

  /**
   * Checks stable widget-visible identity keys for rename matching.
   */
  protected function identityMatches(array $candidate, array $new_item, array $identity_keys): bool {
    foreach ($identity_keys as $key) {
      if (!array_key_exists($key, $candidate) || !array_key_exists($key, $new_item)) {
        return FALSE;
      }
      if ($candidate[$key] !== $new_item[$key]) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveSchema(FormStateInterface $form_state): object {
    $schema_file = $this->getModulePath() . '/' . self::SCHEMA_PATH;
    if (!file_exists($schema_file)) {
      $this->logger->error('Schema file not found: @path', ['@path' => $schema_file]);
      return $this->emptySchema();
    }
    $contents = file_get_contents($schema_file);
    if ($contents === FALSE) {
      $this->logger->error('Could not read schema file: @path', ['@path' => $schema_file]);
      return $this->emptySchema();
    }
    $schema = json_decode($contents);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->error('Invalid JSON in schema file: @error', ['@error' => json_last_error_msg()]);
      return $this->emptySchema();
    }
    $cleaned = (object) [
      'type' => $schema->type ?? 'object',
      'properties' => $schema->properties ?? (object) [],
    ];
    if (isset($schema->title)) {
      $cleaned->title = $schema->title;
    }
    if (isset($schema->required)) {
      $cleaned->required = $schema->required;
    }
    $this->removeUnsupportedRootProperties($cleaned);
    $this->removeUnsupportedI18nProperties($cleaned);
    $this->removeUnsupportedAddressMetadata($cleaned);
    $this->removeUnsupportedAnalysisProperties($cleaned);
    $this->addAnalysisEgressPolicyLabels($cleaned);
    $this->addAnalysisRedactionLabels($cleaned);
    $this->removeOptionalObjectRequiredness($cleaned);
    return $cleaned;
  }

  /**
   * Hides root metadata that the legacy structured widget cannot render.
   *
   * Nuxt owns context-source selection and precheck review. Both values remain
   * part of the canonical DSL and are restored by
   * preserveUnsupportedProperties() after the visible widget values are read.
   */
  protected function removeUnsupportedRootProperties(object $schema): void {
    if (!isset($schema->properties) || !is_object($schema->properties)) {
      return;
    }
    unset(
      $schema->properties->contextSources,
      $schema->properties->precheckSchema,
    );
  }

  /**
   * Hides nested address metadata the structured widget cannot safely edit.
   *
   * The values remain part of the canonical DSL and are restored by
   * preserveUnsupportedProperties() after the visible form values are read.
   */
  protected function removeUnsupportedAddressMetadata(object $schema): void {
    $section_properties = $schema->properties->sections->items->properties ?? NULL;
    if (!is_object($section_properties)) {
      return;
    }
    unset($section_properties->addressGroups);

    $field_properties = $section_properties->fields->items->properties ?? NULL;
    if (is_object($field_properties)) {
      unset($field_properties->semantics);
    }
  }

  /**
   * Hides analysis data that the structured widget cannot safely edit.
   *
   * The json_form_widget module assumes every object has explicit properties.
   * The arbitrary result subschema intentionally uses additionalProperties and
   * therefore caused repeated warnings on every schema edit form. The value is
   * restored by preserveUnsupportedProperties() after visible values are read.
   */
  protected function removeUnsupportedAnalysisProperties(object $schema): void {
    $field_properties = $schema->properties->sections->items->properties->fields->items->properties ?? NULL;
    if (!is_object($field_properties)) {
      return;
    }

    $analysis_properties = $field_properties->analysis->properties ?? NULL;
    if (is_object($analysis_properties)) {
      unset($analysis_properties->resultSchema);
    }
  }

  /**
   * Adds display labels to the egress-policy values rendered as a select.
   */
  protected function addAnalysisEgressPolicyLabels(object $schema): void {
    $egress_policy = $schema->properties->sections->items->properties->fields->items->properties->analysis->properties->egressPolicy ?? NULL;
    if (!is_object($egress_policy) || !isset($egress_policy->enum) || !is_array($egress_policy->enum)) {
      return;
    }

    $labels = [];
    foreach ($egress_policy->enum as $policy) {
      if (!is_string($policy)) {
        return;
      }
      $labels[] = match ($policy) {
        'no_ai' => $this->t('Keine KI-Analyse'),
        'local_only' => $this->t('Nur lokal'),
        'sovereign_only' => $this->t('Nur souverän'),
        'external_redacted' => $this->t('Extern, nur geschwärzt (ohne Einwilligung)'),
        'external_allowed' => $this->t('Extern erlaubt (Einwilligung für Sofortcheck)'),
        default => $policy,
      };
    }
    $egress_policy->enumNames = $labels;
  }

  /**
   * Adds display labels to the redaction values rendered as a select.
   */
  protected function addAnalysisRedactionLabels(object $schema): void {
    $redaction = $schema->properties->sections->items->properties->fields->items->properties->analysis->properties->redaction ?? NULL;
    if (!is_object($redaction) || !isset($redaction->enum) || !is_array($redaction->enum)) {
      return;
    }

    $labels = [];
    foreach ($redaction->enum as $mode) {
      if (!is_string($mode)) {
        return;
      }
      $labels[] = match ($mode) {
        'none' => $this->t('Aus'),
        'when_possible' => $this->t('Wenn möglich (Textebene, keine Scans)'),
        default => $mode,
      };
    }
    $redaction->enumNames = $labels;
  }

  /**
   * Removes conditional object requirements from the widget-only schema.
   *
   * The canonical meta-schema requires computed.fn and computed.args whenever
   * the optional computed object exists. json_form_widget cannot express that
   * conditional relationship and instead marks both controls unconditionally
   * required. Entity validation continues to enforce the canonical rule after
   * extraction.
   */
  protected function removeOptionalObjectRequiredness(object $schema): void {
    $field_properties = $schema->properties->sections->items->properties->fields->items->properties ?? NULL;
    if (!is_object($field_properties)) {
      return;
    }

    if (isset($field_properties->computed) && is_object($field_properties->computed)) {
      unset($field_properties->computed->required);
    }
  }

  /**
   * Removes i18n objects from the structured widget schema.
   *
   * The meta-schema documents i18n for API contracts, but the current
   * json_form_widget cannot edit arbitrary locale maps. Leaving these object
   * nodes in the widget schema would extract empty values and could overwrite
   * existing translations before preserveUnsupportedProperties() can restore
   * them.
   */
  protected function removeUnsupportedI18nProperties(object $schema): void {
    if (!isset($schema->properties) || !is_object($schema->properties)) {
      return;
    }
    unset($schema->properties->i18n);
    foreach ($schema->properties as $property) {
      if (is_object($property)) {
        $this->removeUnsupportedI18nProperties($property);
        if (isset($property->items) && is_object($property->items)) {
          $this->removeUnsupportedI18nProperties($property->items);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveUiSchema(FormStateInterface $form_state): ?object {
    $ui_schema_file = $this->getModulePath() . '/' . self::UI_SCHEMA_PATH;
    if (!file_exists($ui_schema_file)) {
      $this->logger->warning('UI Schema file not found: @path', ['@path' => $ui_schema_file]);
      return NULL;
    }
    $contents = file_get_contents($ui_schema_file);
    if ($contents === FALSE) {
      $this->logger->warning('Could not read UI schema file: @path', ['@path' => $ui_schema_file]);
      return NULL;
    }
    $ui_schema = json_decode($contents);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->warning('Invalid JSON in UI schema file: @error', ['@error' => json_last_error_msg()]);
      return NULL;
    }
    return $ui_schema;
  }

  /**
   * Returns an empty fallback schema.
   */
  protected function emptySchema(): object {
    return (object) ['properties' => (object) [], 'type' => 'object'];
  }

  /**
   * Returns the absolute path of the beantragt_schema module.
   */
  protected function getModulePath(): string {
    return $this->moduleExtensionList->getPath('beantragt_schema');
  }

}
