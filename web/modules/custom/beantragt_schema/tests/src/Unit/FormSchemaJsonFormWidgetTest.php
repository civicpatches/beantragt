<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Plugin\Field\FieldWidget\FormSchemaJsonFormWidget;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the preserve-unsupported-properties logic of the JSON form widget.
 *
 * The widget is final and requires Drupal services to instantiate. These
 * tests reach into the protected method via Reflection so we exercise the
 * merge logic without the full container.
 */
#[CoversClass(FormSchemaJsonFormWidget::class)]
#[Group('beantragt_schema')]
class FormSchemaJsonFormWidgetTest extends UnitTestCase {

  /**
   * Calls preserveUnsupportedProperties via Reflection.
   *
   * The class is `final` and its constructor needs Drupal services; we side-
   * step both by instantiating without the constructor and invoking the
   * protected method directly. preserveUnsupportedProperties uses only the
   * static helper and an instance method (findById) that has no state
   * dependencies, so this is safe.
   */
  protected function preserve(array $new, array $original): array {
    static $instance = NULL;
    static $reflectionMethod = NULL;
    if ($instance === NULL) {
      $reflection = new \ReflectionClass(FormSchemaJsonFormWidget::class);
      $instance = $reflection->newInstanceWithoutConstructor();
      $reflectionMethod = $reflection->getMethod('preserveUnsupportedProperties');
      $reflectionMethod->setAccessible(TRUE);
    }
    return $reflectionMethod->invoke($instance, $new, $original);
  }

  /**
   * Calls normalizeOptionalFieldGroups via Reflection.
   */
  protected function normalize(array $schema, ?array $original = NULL): array {
    $reflection = new \ReflectionClass(FormSchemaJsonFormWidget::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('normalizeOptionalFieldGroups');
    $method->setAccessible(TRUE);
    return $method->invoke($instance, $schema, $original);
  }

  /**
   * Calls removeUnsupportedRootProperties via Reflection.
   */
  protected function hideUnsupportedRootProperties(object $schema): object {
    $reflection = new \ReflectionClass(FormSchemaJsonFormWidget::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('removeUnsupportedRootProperties');
    $method->setAccessible(TRUE);
    $method->invoke($instance, $schema);
    return $schema;
  }

  /**
   * Calls removeUnsupportedAnalysisProperties via Reflection.
   */
  protected function hideUnsupportedAnalysisProperties(object $schema): object {
    $reflection = new \ReflectionClass(FormSchemaJsonFormWidget::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('removeUnsupportedAnalysisProperties');
    $method->setAccessible(TRUE);
    $method->invoke($instance, $schema);
    return $schema;
  }

  /**
   * Calls removeOptionalObjectRequiredness via Reflection.
   */
  protected function makeOptionalObjectRequirementsConditional(object $schema): object {
    $reflection = new \ReflectionClass(FormSchemaJsonFormWidget::class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('removeOptionalObjectRequiredness');
    $method->setAccessible(TRUE);
    $method->invoke($instance, $schema);
    return $schema;
  }

  /**
   * Tests computed controls are optional in the widget-only schema.
   */
  public function testComputedControlsAreNotUnconditionallyRequired(): void {
    $schema = json_decode(json_encode([
      'properties' => [
        'sections' => [
          'items' => [
            'properties' => [
              'fields' => [
                'items' => [
                  'properties' => [
                    'computed' => [
                      'type' => 'object',
                      'required' => ['fn', 'args'],
                      'properties' => [
                        'fn' => ['type' => 'string'],
                        'args' => ['type' => 'array'],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]));

    $cleaned = $this->makeOptionalObjectRequirementsConditional($schema);
    $computed = $cleaned->properties->sections->items->properties->fields->items->properties->computed;

    self::assertObjectNotHasProperty('required', $computed);
    self::assertObjectHasProperty('fn', $computed->properties);
    self::assertObjectHasProperty('args', $computed->properties);
  }

  /**
   * Tests untouched optional panels do not enter newly extracted JSON.
   */
  public function testSyntheticOptionalPanelValuesAreDroppedFromExtraction(): void {
    $schema = [
      'title' => 'US-SMOKE Widget save',
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [
          [
            'id' => 'subject',
            'type' => 'text',
            'label' => 'Subject',
            'computed' => ['fn' => '', 'args' => []],
            'analysis' => ['version' => 1, 'egressPolicy' => 'no_ai'],
            'widget' => ['boundary' => 'off'],
          ],
          [
            'id' => 'description',
            'type' => 'text',
            'label' => 'Description',
            'computed' => ['args' => ['']],
            'analysis' => [
              'enabled' => FALSE,
              'version' => 1,
              'expectedDocumentType' => '',
              'promptSuggestion' => '',
              'citizenPrompt' => '',
              'staffPrompt' => '',
              'redaction' => '',
              'modelClass' => '',
              'egressPolicy' => 'no_ai',
            ],
            'widget' => [
              'autocomplete' => TRUE,
              'map' => FALSE,
              'boundary' => 'off',
            ],
          ],
        ],
      ],
      ],
    ];

    $normalized = $this->normalize($schema);

    foreach ($normalized['sections'][0]['fields'] as $field) {
      self::assertArrayNotHasKey('computed', $field);
      self::assertArrayNotHasKey('analysis', $field);
      self::assertArrayNotHasKey('widget', $field);
    }
  }

  /**
   * Tests malformed optional groups are retained for entity validation.
   */
  public function testMalformedOptionalGroupsAreNotTreatedAsDefaults(): void {
    $schema = [
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [[
          'id' => 'subject',
          'type' => 'text',
          'label' => 'Subject',
          'computed' => ['fn' => 123, 'args' => []],
          'analysis' => ['version' => 1],
          'widget' => [],
        ],
        ],
      ],
      ],
    ];

    $normalized = $this->normalize($schema);
    $field = $normalized['sections'][0]['fields'][0];

    self::assertSame(['fn' => 123, 'args' => []], $field['computed']);
    self::assertSame(['version' => 1], $field['analysis']);
    self::assertSame([], $field['widget']);
  }

  /**
   * Tests API-authored computed and analysis data survives a widget save.
   */
  public function testComputedAndAnalysisProfilesSurviveWidgetRoundTrip(): void {
    $original = [
      'title' => 'US-SMOKE Preserve profiles',
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [
          [
            'id' => 'amount',
            'type' => 'number',
            'label' => 'Amount',
          ],
          [
            'id' => 'total',
            'type' => 'number',
            'label' => 'Total',
            'computed' => [
              'fn' => 'calculateTotal',
              'args' => ['amount'],
            ],
          ],
          [
            'id' => 'attachment',
            'type' => 'file',
            'label' => 'Attachment',
            'analysis' => [
              'version' => 2,
              'expectedDocumentType' => 'Invoice',
              'promptSuggestion' => 'Extract the invoice total.',
              'modelClass' => 'local_vision',
              'egressPolicy' => 'local_only',
              'enabled' => TRUE,
              'resultSchema' => ['type' => 'object'],
            ],
          ],
        ],
      ],
      ],
    ];
    $widget_output = $original;
    unset($widget_output['sections'][0]['fields'][2]['analysis']['enabled']);
    unset($widget_output['sections'][0]['fields'][2]['analysis']['resultSchema']);

    $normalized = $this->normalize($widget_output, $original);
    $merged = $this->preserve($normalized, $original);

    self::assertSame($original, $merged);
  }

  /**
   * Tests story prompts and redaction survive a structured editor save.
   */
  public function testAnalysisStoryKeysSurviveWidgetRoundTrip(): void {
    $original = [
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [[
          'id' => 'payslip',
          'type' => 'file',
          'label' => 'Gehaltsabrechnung',
          'analysis' => [
            'version' => 1,
            'expectedDocumentType' => 'Gehaltsabrechnung',
            'citizenPrompt' => 'Prüfe, ob der Zeitraum erkennbar ist.',
            'staffPrompt' => 'Lies das Bruttoentgelt aus.',
            'redaction' => 'when_possible',
            'modelClass' => 'openai_compatible',
            'egressPolicy' => 'sovereign_only',
            'enabled' => TRUE,
            'resultSchema' => ['type' => 'object'],
          ],
        ],
        ],
      ],
      ],
    ];
    // The contrib widget drops the boolean and the hidden result schema.
    $widget_output = $original;
    unset($widget_output['sections'][0]['fields'][0]['analysis']['enabled']);
    unset($widget_output['sections'][0]['fields'][0]['analysis']['resultSchema']);

    $normalized = $this->normalize($widget_output, $original);
    $merged = $this->preserve($normalized, $original);

    self::assertSame($original, $merged);
  }

  /**
   * Tests a cleared check instruction stays cleared after a widget save.
   */
  public function testClearedStoryPromptIsNotRestored(): void {
    $original = [
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [[
          'id' => 'payslip',
          'type' => 'file',
          'label' => 'Gehaltsabrechnung',
          'analysis' => [
            'citizenPrompt' => 'Prüfe, ob der Zeitraum erkennbar ist.',
            'staffPrompt' => 'Lies das Bruttoentgelt aus.',
            'redaction' => 'when_possible',
            'egressPolicy' => 'sovereign_only',
          ],
        ],
        ],
      ],
      ],
    ];
    $widget_output = $original;
    unset($widget_output['sections'][0]['fields'][0]['analysis']['staffPrompt']);
    unset($widget_output['sections'][0]['fields'][0]['analysis']['redaction']);

    $merged = $this->preserve($this->normalize($widget_output, $original), $original);
    $analysis = $merged['sections'][0]['fields'][0]['analysis'];

    self::assertSame('Prüfe, ob der Zeitraum erkennbar ist.', $analysis['citizenPrompt']);
    self::assertArrayNotHasKey('staffPrompt', $analysis);
    self::assertArrayNotHasKey('redaction', $analysis);
  }

  /**
   * Tests every profile key is either widget-visible or deliberately hidden.
   */
  public function testAnalysisWidgetKeysCoverTheProfileContract(): void {
    $visible = (new \ReflectionClassConstant(FormSchemaJsonFormWidget::class, 'ANALYSIS_WIDGET_KEYS'))->getValue();
    // The contrib widget cannot render booleans, and the result schema is
    // hidden on purpose; preserveUnsupportedProperties() restores both.
    $expected = array_values(array_diff(FormSchema::ATTACHMENT_ANALYSIS_PROFILE_KEYS, ['enabled', 'resultSchema']));
    sort($visible);
    sort($expected);

    self::assertSame($expected, $visible);
  }

  /**
   * Tests hidden address-widget booleans survive a widget round trip.
   */
  public function testAddressWidgetBooleansSurviveWidgetRoundTrip(): void {
    $original = [
      'sections' => [[
        'id' => 'location',
        'title' => 'Location',
        'fields' => [[
          'id' => 'address',
          'type' => 'address',
          'label' => 'Address',
          'widget' => [
            'autocomplete' => FALSE,
            'map' => TRUE,
            'boundary' => 'advisory',
          ],
        ],
        ],
      ],
      ],
    ];
    $widget_output = $original;
    unset($widget_output['sections'][0]['fields'][0]['widget']['autocomplete']);
    unset($widget_output['sections'][0]['fields'][0]['widget']['map']);

    $normalized = $this->normalize($widget_output, $original);
    $merged = $this->preserve($normalized, $original);

    $widget = $merged['sections'][0]['fields'][0]['widget'];
    self::assertCount(3, $widget);
    self::assertFalse($widget['autocomplete']);
    self::assertTrue($widget['map']);
    self::assertSame('advisory', $widget['boundary']);
  }

  /**
   * Tests widget defaults do not expand sparse API-authored profiles.
   */
  public function testUnstoredVisibleDefaultsStayOmittedOnWidgetRoundTrip(): void {
    $original = [
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [
          [
            'id' => 'attachment',
            'type' => 'file',
            'label' => 'Attachment',
            'analysis' => ['egressPolicy' => 'local_only'],
          ],
          [
            'id' => 'address',
            'type' => 'address',
            'label' => 'Address',
            'widget' => [
              'autocomplete' => FALSE,
              'map' => TRUE,
            ],
          ],
        ],
      ],
      ],
    ];
    $widget_output = $original;
    $widget_output['sections'][0]['fields'][0]['analysis']['version'] = 1;
    $widget_output['sections'][0]['fields'][1]['widget'] = ['boundary' => 'off'];

    $normalized = $this->normalize($widget_output, $original);
    $merged = $this->preserve($normalized, $original);

    self::assertSame($original, $merged);
  }

  /**
   * Tests computed functions without arguments survive contrib extraction.
   */
  public function testZeroArgumentComputedSpecSurvivesWidgetRoundTrip(): void {
    $original = [
      'title' => 'US-SMOKE Zero argument computed',
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [[
          'id' => 'generated',
          'type' => 'text',
          'label' => 'Generated',
          'computed' => [
            'fn' => 'generateValue',
            'args' => [],
          ],
        ],
        ],
      ],
      ],
    ];
    // json_form_widget omits the falsey empty args array during extraction.
    $widget_output = $original;
    unset($widget_output['sections'][0]['fields'][0]['computed']['args']);

    $normalized = $this->normalize($widget_output, $original);
    $merged = $this->preserve($normalized, $original);

    self::assertSame($original, $merged);
  }

  /**
   * Tests clearing a stored computed group does not silently restore it.
   */
  public function testStoredComputedGroupCanBeCleared(): void {
    $original = [
      'title' => 'US-SMOKE Clear computed',
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [[
          'id' => 'generated',
          'type' => 'text',
          'label' => 'Generated',
          'computed' => [
            'fn' => 'generateValue',
            'args' => [],
          ],
        ],
        ],
      ],
      ],
    ];
    $widget_output = $original;
    $widget_output['sections'][0]['fields'][0]['computed'] = [
      'fn' => '',
      'args' => [],
    ];

    $normalized = $this->normalize($widget_output, $original);
    $merged = $this->preserve($normalized, $original);

    self::assertArrayNotHasKey('computed', $merged['sections'][0]['fields'][0]);
  }

  /**
   * Tests partially authored computed data reaches entity validation.
   */
  public function testPartialComputedGroupProducesValidationError(): void {
    $schema = [
      'title' => 'US-SMOKE Partial computed',
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [
          [
            'id' => 'source',
            'type' => 'text',
            'label' => 'Source',
          ],
          [
            'id' => 'derived',
            'type' => 'text',
            'label' => 'Derived',
            'computed' => ['fn' => 'copyValue'],
          ],
        ],
      ],
      ],
    ];

    $normalized = $this->normalize($schema);

    self::assertSame(
      ['fn' => 'copyValue'],
      $normalized['sections'][0]['fields'][1]['computed'],
    );
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Field "derived" computed spec args must be a JSON array.');
    FormSchema::validateFormSchemaDefinition(json_encode($normalized, JSON_THROW_ON_ERROR));
  }

  /**
   * Tests arguments without a function id produce a validation error.
   */
  public function testComputedArgumentsWithoutFunctionProduceValidationError(): void {
    $schema = [
      'title' => 'US-SMOKE Missing computed function',
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [
          [
            'id' => 'source',
            'type' => 'text',
            'label' => 'Source',
          ],
          [
            'id' => 'derived',
            'type' => 'text',
            'label' => 'Derived',
            'computed' => ['args' => ['source']],
          ],
        ],
      ],
      ],
    ];

    $normalized = $this->normalize($schema);

    self::assertSame(
      ['args' => ['source']],
      $normalized['sections'][0]['fields'][1]['computed'],
    );
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Field "derived" computed spec must have a non-empty string fn.');
    FormSchema::validateFormSchemaDefinition(json_encode($normalized, JSON_THROW_ON_ERROR));
  }

  /**
   * Tests Nuxt-owned root objects never reach the legacy JSON form renderer.
   */
  public function testContextAndPrecheckObjectsAreHiddenFromLegacyWidget(): void {
    $schema = (object) [
      'properties' => (object) [
        'title' => (object) ['type' => 'string'],
        'contextSources' => (object) ['$ref' => '#/definitions/contextSources'],
        'precheckSchema' => (object) ['$ref' => '#/definitions/precheckSchema'],
      ],
    ];

    $cleaned = $this->hideUnsupportedRootProperties($schema);

    self::assertObjectHasProperty('title', $cleaned->properties);
    self::assertObjectNotHasProperty('contextSources', $cleaned->properties);
    self::assertObjectNotHasProperty('precheckSchema', $cleaned->properties);
  }

  /**
   * Tests arbitrary result schemas never reach the legacy object renderer.
   */
  public function testResultSchemaIsHiddenFromLegacyWidget(): void {
    $schema = json_decode(json_encode([
      'properties' => [
        'sections' => [
          'items' => [
            'properties' => [
              'fields' => [
                'items' => [
                  'properties' => [
                    'analysis' => [
                      'properties' => [
                        'version' => ['type' => 'integer'],
                        'resultSchema' => [
                          'type' => 'object',
                          'additionalProperties' => TRUE,
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]));

    $cleaned = $this->hideUnsupportedAnalysisProperties($schema);
    $properties = $cleaned->properties->sections->items->properties->fields->items->properties->analysis->properties;

    self::assertObjectHasProperty('version', $properties);
    self::assertObjectNotHasProperty('resultSchema', $properties);
  }

  /**
   * Tests hidden analysis values survive structured editor saves.
   */
  public function testHiddenAnalysisValuesArePreserved(): void {
    $original = [
      'sections' => [
        [
          'id' => 'attachments',
          'title' => 'Anlagen',
          'fields' => [
            [
              'id' => 'plan',
              'type' => 'file',
              'label' => 'Plan',
              'analysis' => [
                'enabled' => TRUE,
                'version' => 1,
                'resultSchema' => ['type' => 'object', 'required' => ['boundary']],
                'egressPolicy' => 'local_only',
              ],
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 'attachments',
          'title' => 'Anlagen',
          'fields' => [
            [
              'id' => 'plan',
              'type' => 'file',
              'label' => 'Plan',
              'analysis' => [
                'version' => 2,
                'egressPolicy' => 'sovereign_only',
              ],
            ],
          ],
        ],
      ],
    ];

    $merged = $this->preserve($new, $original);
    $analysis = $merged['sections'][0]['fields'][0]['analysis'];

    self::assertTrue($analysis['enabled']);
    self::assertSame(['type' => 'object', 'required' => ['boundary']], $analysis['resultSchema']);
    self::assertSame(2, $analysis['version']);
    self::assertSame('sovereign_only', $analysis['egressPolicy']);
  }

  /**
   * Tests that widget-known properties round-trip through unchanged.
   */
  public function testKnownPropertiesRoundtrip(): void {
    $original = [
      'id' => 'demo',
      'title' => 'Demo',
      'sections' => [
        [
          'id' => 's1',
          'title' => 'Section One',
          'fields' => [
            ['id' => 'f1', 'type' => 'text', 'label' => 'F1'],
          ],
        ],
      ],
    ];
    // Simulate widget output identical to original.
    $new = $original;
    $merged = $this->preserve($new, $original);
    self::assertSame($original, $merged);
  }

  /**
   * Tests attachment analysis is treated as widget-visible field metadata.
   */
  public function testAnalysisProfileCanBeClearedByWidgetOutput(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'nachweis',
              'type' => 'file',
              'label' => 'Nachweis',
              'analysis' => [
                'enabled' => TRUE,
                'egressPolicy' => 'no_ai',
              ],
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'nachweis', 'type' => 'file', 'label' => 'Nachweis'],
          ],
        ],
      ],
    ];

    $merged = $this->preserve($new, $original);
    self::assertArrayNotHasKey('analysis', $merged['sections'][0]['fields'][0]);
  }

  /**
   * Tests hidden address metadata survives a structured editor round-trip.
   */
  public function testAddressGroupsAndFieldSemanticsArePreserved(): void {
    $original = [
      'sections' => [[
        'id' => 'location',
        'title' => 'Ort',
        'fields' => [[
          'id' => 'street',
          'type' => 'text',
          'label' => 'Straße',
          'semantics' => ['concept' => 'address.street'],
        ],
        ],
        'addressGroups' => [[
          'id' => 'location_search',
          'fields' => [
            'street' => 'street',
            'houseNumber' => 'house_number',
            'postcode' => 'postcode',
            'city' => 'city',
          ],
        ],
        ],
      ],
      ],
    ];
    $new = [
      'sections' => [[
        'id' => 'location',
        'title' => 'Ort',
        'fields' => [[
          'id' => 'street',
          'type' => 'text',
          'label' => 'Straße und Weg',
        ],
        ],
      ],
      ],
    ];

    $merged = $this->preserve($new, $original);

    self::assertSame($original['sections'][0]['addressGroups'], $merged['sections'][0]['addressGroups']);
    self::assertSame(['concept' => 'address.street'], $merged['sections'][0]['fields'][0]['semantics']);
    self::assertSame('Straße und Weg', $merged['sections'][0]['fields'][0]['label']);
  }

  /**
   * Tests that renaming a field.id preserves hidden field properties.
   */
  public function testRenameFieldPreservesUnsupportedPropertiesByDelta(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'old_id',
              'type' => 'text',
              'label' => 'X',
              'required' => TRUE,
              'sensitive' => TRUE,
              'conditionalOn' => ['fieldId' => 'a', 'value' => 'b'],
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'new_id', 'type' => 'text', 'label' => 'X'],
          ],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame('new_id', $merged['sections'][0]['fields'][0]['id']);
    self::assertTrue($merged['sections'][0]['fields'][0]['required']);
    self::assertTrue($merged['sections'][0]['fields'][0]['sensitive']);
    self::assertSame(
      ['fieldId' => 'a', 'value' => 'b'],
      $merged['sections'][0]['fields'][0]['conditionalOn'],
    );
  }

  /**
   * Tests that a same-delta field keeps hidden data for stable identity keys.
   */
  public function testSameDeltaFieldPreservesHiddenDataForStableIdentity(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'old_id',
              'type' => 'text',
              'label' => 'Same',
              'sensitive' => TRUE,
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'new_id', 'type' => 'text', 'label' => 'Same'],
          ],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame('new_id', $merged['sections'][0]['fields'][0]['id']);
    self::assertTrue($merged['sections'][0]['fields'][0]['sensitive']);
  }

  /**
   * Tests that field renames still preserve hidden data after reordering.
   */
  public function testRenamedReorderedFieldPreservesHiddenDataByDisappearedId(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'old_id',
              'type' => 'text',
              'label' => 'Renamed field',
              'required' => TRUE,
              'sensitive' => TRUE,
            ],
            [
              'id' => 'stable_id',
              'type' => 'email',
              'label' => 'Stable field',
              'required' => TRUE,
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'stable_id', 'type' => 'email', 'label' => 'Stable field'],
            ['id' => 'new_id', 'type' => 'text', 'label' => 'Renamed field'],
          ],
        ],
      ],
    ];

    $merged = $this->preserve($new, $original);

    self::assertSame('stable_id', $merged['sections'][0]['fields'][0]['id']);
    self::assertTrue($merged['sections'][0]['fields'][0]['required']);
    self::assertSame('new_id', $merged['sections'][0]['fields'][1]['id']);
    self::assertTrue($merged['sections'][0]['fields'][1]['required']);
    self::assertTrue($merged['sections'][0]['fields'][1]['sensitive']);
  }

  /**
   * Tests ambiguous rename identities do not duplicate hidden data.
   */
  public function testAmbiguousRenameIdentityDoesNotDuplicateHiddenData(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'old_id',
              'type' => 'text',
              'label' => 'Same visible identity',
              'sensitive' => TRUE,
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'new_a', 'type' => 'text', 'label' => 'Same visible identity'],
            ['id' => 'new_b', 'type' => 'text', 'label' => 'Same visible identity'],
          ],
        ],
      ],
    ];

    $merged = $this->preserve($new, $original);

    self::assertArrayNotHasKey('sensitive', $merged['sections'][0]['fields'][0]);
    self::assertArrayNotHasKey('sensitive', $merged['sections'][0]['fields'][1]);
  }

  /**
   * Tests that same-delta replacements do not inherit hidden data.
   */
  public function testSameDeltaReplacementDoesNotInheritUnsupportedProperties(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'old_id',
              'type' => 'text',
              'label' => 'Old',
              'sensitive' => TRUE,
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'new_id', 'type' => 'text', 'label' => 'New'],
          ],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame('new_id', $merged['sections'][0]['fields'][0]['id']);
    self::assertArrayNotHasKey('sensitive', $merged['sections'][0]['fields'][0]);
  }

  /**
   * Tests that inserting before an existing field does not inherit hidden data.
   */
  public function testInsertedFieldBeforeExistingIdDoesNotInheritUnsupportedProperties(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'old_id',
              'type' => 'text',
              'label' => 'Old',
              'sensitive' => TRUE,
            ],
          ],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'inserted_id', 'type' => 'text', 'label' => 'Inserted'],
            ['id' => 'old_id', 'type' => 'text', 'label' => 'Old'],
          ],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame('inserted_id', $merged['sections'][0]['fields'][0]['id']);
    self::assertArrayNotHasKey('sensitive', $merged['sections'][0]['fields'][0]);
    self::assertTrue($merged['sections'][0]['fields'][1]['sensitive']);
  }

  /**
   * Tests that renaming a section.id preserves hidden section properties.
   */
  public function testRenameSectionPreservesUnsupportedPropertiesByDelta(): void {
    $original = [
      'sections' => [
        [
          'id' => 'old_section',
          'title' => 'S1',
          'repeatable' => TRUE,
          'fields' => [],
        ],
      ],
    ];
    $new = [
      'sections' => [
        [
          'id' => 'new_section',
          'title' => 'S1',
          'fields' => [],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame('new_section', $merged['sections'][0]['id']);
    self::assertTrue($merged['sections'][0]['repeatable']);
  }

  /**
   * Tests that a deleted section stays deleted.
   */
  public function testDeletedSectionStaysDeleted(): void {
    $original = [
      'sections' => [
        ['id' => 's1', 'title' => 'Keep', 'fields' => []],
        ['id' => 's2', 'title' => 'Delete', 'fields' => []],
      ],
    ];
    $new = [
      'sections' => [
        ['id' => 's1', 'title' => 'Keep', 'fields' => []],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertCount(1, $merged['sections']);
    self::assertSame('s1', $merged['sections'][0]['id']);
  }

  /**
   * Tests that hidden validation keys survive when validation disappears.
   */
  public function testHiddenValidationKeysAreRestored(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'f1',
              'type' => 'text',
              'label' => 'F',
              'validation' => ['min' => 0, 'max' => 10, 'step' => 0.01],
            ],
          ],
        ],
      ],
    ];
    // Widget did not render the validation block; new has no 'validation' key.
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            ['id' => 'f1', 'type' => 'text', 'label' => 'F'],
          ],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame(
      ['min' => 0, 'max' => 10, 'step' => 0.01],
      $merged['sections'][0]['fields'][0]['validation'],
    );
  }

  /**
   * Tests that visible validation keys can be changed or removed.
   */
  public function testVisibleValidationKeysAreNotRestored(): void {
    $original = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'f1',
              'type' => 'text',
              'label' => 'F',
              'validation' => [
                'minLength' => 3,
                'maxLength' => 100,
                'pattern' => '^x',
                'min' => 0,
              ],
            ],
          ],
        ],
      ],
    ];
    // Widget rendered visible validation keys. minLength was changed,
    // maxLength was kept, pattern was intentionally removed. Hidden min stays.
    $new = [
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'f1',
              'type' => 'text',
              'label' => 'F',
              'validation' => ['minLength' => 5, 'maxLength' => 100],
            ],
          ],
        ],
      ],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame(5, $merged['sections'][0]['fields'][0]['validation']['minLength']);
    self::assertSame(100, $merged['sections'][0]['fields'][0]['validation']['maxLength']);
    self::assertSame(0, $merged['sections'][0]['fields'][0]['validation']['min']);
    self::assertArrayNotHasKey(
      'pattern',
      $merged['sections'][0]['fields'][0]['validation'],
    );
  }

  /**
   * Tests that sections without 'id' are skipped without crashing.
   */
  public function testSectionWithoutIdIsSkipped(): void {
    $original = [
      'sections' => [
        ['id' => 's1', 'title' => 'has id', 'repeatable' => TRUE, 'fields' => []],
      ],
    ];
    $new = [
      'sections' => [
        ['title' => 'no id', 'fields' => []],
        ['id' => 's1', 'title' => 'has id', 'fields' => []],
      ],
    ];
    $merged = $this->preserve($new, $original);
    // First section is the orphan, untouched.
    self::assertArrayNotHasKey('repeatable', $merged['sections'][0]);
    // Second section gets the boolean property restored.
    self::assertTrue($merged['sections'][1]['repeatable']);
  }

  /**
   * Tests that hidden top-level keys survive.
   */
  public function testTopLevelKeyPreservation(): void {
    $original = [
      'id' => 'demo',
      'title' => 'D',
      'workflow' => ['mode' => 'catalog', 'statuses' => [['machineName' => 'open']]],
      'sections' => [],
    ];
    // Widget didn't roundtrip workflow (not in meta-schema).
    $new = [
      'id' => 'demo',
      'title' => 'D',
      'sections' => [],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame(['mode' => 'catalog', 'statuses' => [['machineName' => 'open']]], $merged['workflow']);
  }

  /**
   * Tests that delivery policy is treated as an editable root property.
   */
  public function testDeliveryPolicyCanBeEditedAndCleared(): void {
    $original = [
      'id' => 'demo',
      'title' => 'D',
      'delivery' => [
        'processingMode' => 'pass_through',
        'adapter' => 'fit_connect',
      ],
      'sections' => [],
    ];

    $changed = [
      'id' => 'demo',
      'title' => 'D',
      'delivery' => [
        'processingMode' => 'self_contained',
        'adapter' => 'mock',
      ],
      'sections' => [],
    ];
    $merged_changed = $this->preserve($changed, $original);
    self::assertSame($changed['delivery'], $merged_changed['delivery']);

    $cleared = [
      'id' => 'demo',
      'title' => 'D',
      'sections' => [],
    ];
    $merged_cleared = $this->preserve($cleared, $original);
    self::assertArrayNotHasKey('delivery', $merged_cleared);
  }

  /**
   * Tests that i18n maps survive structured editor saves.
   */
  public function testI18nMapsArePreserved(): void {
    $original = [
      'title' => 'Hund anmelden',
      'i18n' => [
        'en' => ['title' => 'Dog registration'],
      ],
      'sections' => [
        [
          'id' => 'basis',
          'title' => 'Angaben',
          'i18n' => [
            'en' => ['title' => 'Details'],
          ],
          'fields' => [
            [
              'id' => 'tierart',
              'type' => 'select',
              'label' => 'Tierart',
              'options' => ['Hund', 'Katze'],
              'i18n' => [
                'en' => [
                  'label' => 'Pet type',
                  'options' => ['Hund' => 'Dog', 'Katze' => 'Cat'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
    $new = [
      'title' => 'Hund anmelden',
      'sections' => [
        [
          'id' => 'basis',
          'title' => 'Angaben',
          'fields' => [
            [
              'id' => 'tierart',
              'type' => 'select',
              'label' => 'Tierart',
              'options' => ['Hund', 'Katze'],
            ],
          ],
        ],
      ],
    ];

    $merged = $this->preserve($new, $original);

    self::assertSame(['en' => ['title' => 'Dog registration']], $merged['i18n']);
    self::assertSame(['en' => ['title' => 'Details']], $merged['sections'][0]['i18n']);
    self::assertSame(
      [
        'en' => [
          'label' => 'Pet type',
          'options' => ['Hund' => 'Dog', 'Katze' => 'Cat'],
        ],
      ],
      $merged['sections'][0]['fields'][0]['i18n'],
    );
  }

  /**
   * Tests that hidden semantics and source sub-keys are restored.
   */
  public function testSemanticsAndSourceSubKeys(): void {
    $original = [
      'semantics' => [
        'category' => 'env',
        'attachments' => [['key' => 'id', 'label' => 'L', 'required' => TRUE]],
        'legal_references' => ['BGB Paragraph 1'],
      ],
      'source' => [
        'type' => 'fim-json-schema',
        'fileName' => 's.pdf',
        'schemaId' => 'X1',
      ],
      'sections' => [],
    ];
    $new = [
      'semantics' => ['category' => 'env'],
      'source' => ['type' => 'fim-json-schema'],
      'sections' => [],
    ];
    $merged = $this->preserve($new, $original);
    self::assertSame([['key' => 'id', 'label' => 'L', 'required' => TRUE]], $merged['semantics']['attachments']);
    self::assertSame(['BGB Paragraph 1'], $merged['semantics']['legal_references']);
    self::assertSame('s.pdf', $merged['source']['fileName']);
    self::assertSame('X1', $merged['source']['schemaId']);
  }

  /**
   * Tests that widget-visible keys can be cleared.
   */
  public function testVisibleKeysCanBeCleared(): void {
    $original = [
      'description' => 'Root description',
      'semantics' => [
        'category' => 'environment',
        'attachments' => [['key' => 'plan', 'label' => 'Plan']],
      ],
      'source' => [
        'label' => 'Source label',
        'fileName' => 'seed.pdf',
      ],
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'description' => 'Section description',
          'repeatable' => TRUE,
          'fields' => [
            [
              'id' => 'f1',
              'type' => 'text',
              'label' => 'F',
              'placeholder' => 'Old placeholder',
              'required' => TRUE,
              'validation' => ['pattern' => '^x', 'min' => 0],
            ],
          ],
        ],
      ],
    ];
    $new = [
      'semantics' => [],
      'source' => [],
      'sections' => [
        [
          'id' => 's1',
          'title' => 'S1',
          'fields' => [
            [
              'id' => 'f1',
              'type' => 'text',
              'label' => 'F',
            ],
          ],
        ],
      ],
    ];

    $merged = $this->preserve($new, $original);

    self::assertArrayNotHasKey('description', $merged);
    self::assertArrayNotHasKey('category', $merged['semantics']);
    self::assertSame([['key' => 'plan', 'label' => 'Plan']], $merged['semantics']['attachments']);
    self::assertArrayNotHasKey('label', $merged['source']);
    self::assertSame('seed.pdf', $merged['source']['fileName']);
    self::assertArrayNotHasKey('description', $merged['sections'][0]);
    self::assertTrue($merged['sections'][0]['repeatable']);
    self::assertArrayNotHasKey('placeholder', $merged['sections'][0]['fields'][0]);
    self::assertTrue($merged['sections'][0]['fields'][0]['required']);
    self::assertArrayNotHasKey(
      'pattern',
      $merged['sections'][0]['fields'][0]['validation'],
    );
    self::assertSame(0, $merged['sections'][0]['fields'][0]['validation']['min']);
  }

}
