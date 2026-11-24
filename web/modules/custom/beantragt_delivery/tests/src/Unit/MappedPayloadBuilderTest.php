<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_application\Service\PublicApplicationSubmissionValidator;
use Drupal\beantragt_delivery\Service\DataContractValidator;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\beantragt_delivery\Service\MappedPayloadBuilder;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests offline execution, resource bounds and value-free mapping diagnostics.
 */
#[CoversClass(MappedPayloadBuilder::class)]
#[Group('beantragt_delivery')]
final class MappedPayloadBuilderTest extends UnitTestCase {

  /**
   * Creates an offline builder reading checksum-verified fixtures.
   */
  private function builder(?string $root = NULL, ?array $manifest = NULL): MappedPayloadBuilder {
    $registry = new SyntheticDataContractRegistry(contractDirectory: $root ?? dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    if ($manifest !== NULL) {
      $registry->pinFixture($manifest);
    }
    return new MappedPayloadBuilder($registry, new IntegrationRules($registry), new DataContractValidator($registry));
  }

  /**
   * Derives synthetic source values for the shipped complete mapping fixture.
   */
  private function fixture(): array {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/us116-complete-fim-mapping.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $data = [];
    $fields = array_column($fixture['form']['sections'][0]['fields'], NULL, 'id');
    foreach ($fixture['rules']['rules'] as $rule) {
      $field = $fields[$rule['source']];
      $value = match ($field['type']) {
        'checkbox' => TRUE,
        'number' => 1.0,
        'date' => '2026-09-18',
        'select' => array_key_first($rule['valueMap']),
        default => str_contains($field['label'], 'Postleitzahl') ? '12345' : (str_contains($field['label'], 'E-Mail') ? 'synthetic@example.invalid' : 'SYNTHETIC'),
      };
      $data[$rule['source']] = $value;
    }
    return [$data, $fixture['rules']];
  }

  /**
   * Stored values mirror the actual producer, including unanswered optionals.
   */
  public function testRendererProducedReferenceSubmission(): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/us116-renderer-submission.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    // Replicates FormRenderer.vue:827-848 initializeFormData exactly. Visible
    // defaults are preserved by useFormSubmission.ts:314-323; no real submit or
    // network call was run to obtain this synthetic stored-data fixture.
    $defaults = [];
    foreach ($fixture['form']['sections'] as $section) {
      foreach ($section['fields'] as $field) {
        $defaults[$field['id']] = array_key_exists('defaultValue', $field) ? $field['defaultValue'] : match (TRUE) {
          $field['type'] === 'file' => [],
          $field['type'] === 'address' => NULL,
          $field['type'] === 'checkbox' && !empty($field['options']) => [],
          $field['type'] === 'checkbox' => FALSE,
          default => '',
        };
        if (!$field['required'] && $field['id'] !== 'synthetic_field_70') {
          $this->assertSame($defaults[$field['id']], $fixture['data'][$field['id']]);
        }
      }
    }
    $submit = new PublicApplicationSubmissionValidator();
    $fields = array_map($submit->normalizeField(...), $fixture['form']['sections'][0]['fields']);
    $submitted = $submit->sanitizeAndValidateData($fixture['data'], ['fields' => $fields]);
    $this->assertNull($submitted['error'], json_encode($submitted['error']));
    $this->assertSame('12.50', $submitted['data']['synthetic_field_70']);
    $result = $this->builder()->build($submitted['data'], $fixture['rules'], 'fim:S00000096', '1.0', $fixture['form']);
    $this->assertTrue($result['valid'], json_encode($result['errors']));
    $this->assertSame(['001', '002'], $result['payload']->{'GSynthetic078'}[0]->{'GSynthetic070'}->{'FSynthetic010'});
    $this->assertSame(12.5, $result['payload']->{'GSynthetic078'}[0]->{'GSynthetic074'}->{'FSynthetic021'});
    $this->assertStringNotContainsString(':null', $result['json']);
    $this->assertStringNotContainsString(':""', $result['json']);
  }

  /**
   * The pinned FIM contract accepts deterministic nested single occurrences.
   */
  public function testCompletePayloadIsCanonicalAndDeterministic(): void {
    [$data, $rules] = $this->fixture();
    $builder = $this->builder();
    $result = $builder->build($data, $rules, 'fim:S00000096', '1.0');
    $this->assertTrue($result['valid'], json_encode($result['errors']));
    $this->assertSame(hash('sha256', $result['json']), $result['sha256']);
    $this->assertSame(IntegrationRules::canonical($result['payload']), $result['json']);
    $rules['rules'] = array_reverse($rules['rules']);
    $rules['occurrences'] = array_reverse($rules['occurrences']);
    $again = $builder->build(array_reverse($data, TRUE), $rules, 'fim:S00000096', '1.0');
    $this->assertSame($result['json'], $again['json']);
    $this->assertCount(1, $result['payload']->{'GSynthetic078'});
    $this->assertSame(['001'], $result['payload']->{'GSynthetic078'}[0]->{'GSynthetic070'}->{'FSynthetic010'});
  }

  /**
   * Missing required sources, invalid primitives and codes identify the source.
   */
  public function testInvalidSourcesAreSafeAndNeverReturnedAsPayload(): void {
    [$data, $rules] = $this->fixture();
    $builder = $this->builder();
    $invalid = $data;
    unset($invalid['synthetic_field_1']);
    $this->assertFailure($builder->build($invalid, $rules, 'fim:S00000096', '1.0'), 'integration.validation.required', 'synthetic_field_1');
    foreach ([123, ['SYNTHETIC SECRET']] as $value) {
      $invalid = $data;
      $invalid['synthetic_field_1'] = $value;
      $this->assertFailure($builder->build($invalid, $rules, 'fim:S00000096', '1.0'), 'integration.validation.type', 'synthetic_field_1');
    }
    $invalid = $data;
    $invalid['synthetic_field_21'] = 'SYNTHETIC SECRET';
    $this->assertFailure($builder->build($invalid, $rules, 'fim:S00000096', '1.0'), 'integration.value_unmapped', 'synthetic_field_21');
    $invalid = $data;
    $invalid['synthetic_field_3'] = 'SYNTHETIC SECRET';
    $this->assertFailure($builder->build($invalid, $rules, 'fim:S00000096', '1.0'), 'integration.validation.pattern', 'synthetic_field_3');
  }

  /**
   * Optional missing sources disappear rather than becoming null placeholders.
   */
  public function testAbsentOptionalSourceIsOmittedButFalseAndZeroRemain(): void {
    [$data, $rules] = $this->fixture();
    unset($data['synthetic_field_3']);
    $data['synthetic_field_2'] = FALSE;
    $data['synthetic_field_70'] = 0;
    $result = $this->builder()->build($data, $rules, 'fim:S00000096', '1.0');
    $this->assertTrue($result['valid'], json_encode($result['errors']));
    $contact = $result['payload']->{'GSynthetic069'}->{'GSynthetic084'};
    $this->assertFalse(property_exists($contact, 'FSynthetic046'));
    $this->assertFalse($contact->{'FSynthetic034'});
    $this->assertSame(0, $result['payload']->{'GSynthetic078'}[0]->{'GSynthetic074'}->{'FSynthetic021'});
  }

  /**
   * Numeric-looking map keys accept strings only without PHP key coercion.
   */
  public function testValueMapRequiresAnExactStringKey(): void {
    [$data, $rules] = $this->fixture();
    foreach ($rules['rules'] as &$rule) {
      if ($rule['source'] === 'synthetic_field_21') {
        $rule['valueMap'] = (object) ['1' => '001', '01' => '002'];
      }
    }
    unset($rule);
    foreach (['1' => '001', '01' => '002'] as $option => $expected) {
      $data['synthetic_field_21'] = (string) $option;
      $result = $this->builder()->build($data, $rules, 'fim:S00000096', '1.0');
      $this->assertTrue($result['valid']);
      $this->assertSame($expected, $result['payload']->{'GSynthetic078'}[0]->{'GSynthetic070'}->{'FSynthetic003'});
    }
    foreach ([1, TRUE, 1.0, 'SYNTHETIC SECRET'] as $value) {
      $data['synthetic_field_21'] = $value;
      $this->assertFailure($this->builder()->build($data, $rules, 'fim:S00000096', '1.0'), 'integration.value_unmapped', 'synthetic_field_21');
    }
  }

  /**
   * String bounds use bytes before evaluating expensive contract patterns.
   */
  public function testMappedStringByteBoundPrecedesSchemaValidation(): void {
    [$data, $rules] = $this->fixture();
    $data['synthetic_field_3'] = str_repeat('ä', 513);
    $result = $this->builder()->build($data, $rules, 'fim:S00000096', '1.0');
    $this->assertFailure($result, 'integration.mapped_string_too_large', 'synthetic_field_3');
    $this->assertNotContains('integration.validation.pattern', array_column($result['errors'], 'code'));
  }

  /**
   * The aggregate bound stops validation even with individually safe values.
   */
  public function testTotalPayloadBoundPrecedesValidation(): void {
    $schema = ['type' => 'object', 'properties' => []];
    $data = [];
    $rules = ['version' => 1, 'rules' => []];
    for ($index = 0; $index < 300; $index++) {
      $name = 'synthetic_' . $index;
      $schema['properties'][$name] = ['type' => 'string', 'pattern' => '^never$'];
      $data[$name] = str_repeat('x', 1024);
      $rules['rules'][] = ['source' => $name, 'target' => '/' . $name];
    }
    $this->withSchema($schema, function (MappedPayloadBuilder $builder) use ($data, $rules): void {
      $result = $builder->build($data, $rules, 'fim:S00000096', '1.0');
      $this->assertFalse($result['valid']);
      $this->assertSame(['integration.payload_too_large'], array_column($result['errors'], 'code'));
    });
  }

  /**
   * Contract postcode semantics are not silently tightened by the executor.
   */
  public function testOnlyContractConstraintsApplyAndErrorsAreBounded(): void {
    [$data, $rules] = $this->fixture();
    $data['synthetic_field_15'] = '12345';
    $this->assertTrue($this->builder()->build($data, $rules, 'fim:S00000096', '1.0')['valid']);
    foreach ($data as &$value) {
      $value = ['SYNTHETIC SECRET'];
    }
    unset($value);
    $result = $this->builder()->build($data, $rules, 'fim:S00000096', '1.0');
    $this->assertCount(50, $result['errors']);
    $this->assertStringNotContainsString('SYNTHETIC SECRET', json_encode($result));
  }

  /**
   * Empty required objects survive while absent optional subtrees disappear.
   */
  public function testEmptyObjectShapesAndNestedPrimitiveArrays(): void {
    $schema = [
      'type' => 'object',
      'required' => ['empty', 'rows'],
      'properties' => [
        'empty' => ['type' => 'object'],
        'optional' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
        'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]],
      ],
    ];
    $rules = [
      'version' => 1,
      'rules' => [
        ['source' => 'synthetic', 'target' => '/rows/*/*'],
        ['source' => 'absent', 'target' => '/optional/value'],
      ],
      'occurrences' => [
        ['target' => '/rows', 'mode' => 'single'],
        ['target' => '/rows/*', 'mode' => 'single'],
      ],
    ];
    $this->withSchema($schema, function (MappedPayloadBuilder $builder) use ($rules): void {
      $result = $builder->build(['synthetic' => 'SYNTHETIC'], $rules, 'fim:S00000096', '1.0');
      $this->assertTrue($result['valid'], json_encode($result['errors']));
      $this->assertSame('{"empty":{},"rows":[["SYNTHETIC"]]}', $result['json']);
      $missing = $builder->build([], $rules, 'fim:S00000096', '1.0');
      $this->assertFailure($missing, 'integration.validation.required', 'synthetic');
      $this->assertSame('/rows', $missing['errors'][0]['pointer']);
    });
  }

  /**
   * Renderer defaults omit whole optional groups and identify absent requireds.
   */
  public function testRendererAbsenceAndOptionalSingleOccurrences(): void {
    $schema = [
      'type' => 'object',
      'properties' => [
        'group' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]],
        'rows' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'required' => ['value'],
            'properties' => ['value' => ['type' => 'string']],
          ],
        ],
      ],
    ];
    $rules = [
      'version' => 1,
      'rules' => [
        ['source' => 'value', 'target' => '/group/value'],
        ['source' => 'value', 'target' => '/rows/*/value'],
      ],
      'occurrences' => [['target' => '/rows', 'mode' => 'single']],
    ];
    $this->withSchema($schema, function (MappedPayloadBuilder $builder) use ($rules): void {
      foreach ([NULL, '', " \t\n", [], "\u{00a0}"] as $value) {
        $result = $builder->build(['value' => $value], $rules, 'fim:S00000096', '1.0');
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('{}', $result['json']);
      }
    });
    $schema['required'] = ['rows'];
    $this->withSchema($schema, function (MappedPayloadBuilder $builder) use ($rules): void {
      foreach ([NULL, '', ' ', []] as $value) {
        $this->assertFailure($builder->build(['value' => $value], $rules, 'fim:S00000096', '1.0'), 'integration.validation.required', 'value');
      }
    });
  }

  /**
   * Declared types normalize only renderer representations.
   */
  public function testDeclaredNormalization(): void {
    foreach (['number', 'integer', 'string'] as $target) {
      $this->withSchema(['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => $target]]], function (MappedPayloadBuilder $builder) use ($target): void {
        $form = ['sections' => [['fields' => [['id' => 'value', 'type' => 'number', 'required' => TRUE]]]]];
        if ($target === 'integer') {
          $form['sections'][0]['fields'][0]['validation']['step'] = 1;
        }
        $rules = ['version' => 1, 'rules' => [['source' => 'value', 'target' => '/value']]];
        $result = $builder->build(['value' => '001.00'], $rules, 'fim:S00000096', '1.0', $form);
        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame($target === 'string' ? '001.00' : ($target === 'integer' ? 1 : 1.0), $result['payload']->value);
        foreach (['1.234,50', '1,234.50', '1.000', '1,000', 'NaN', '1e999', TRUE] as $invalid) {
          $this->assertFailure($builder->build(['value' => $invalid], $rules, 'fim:S00000096', '1.0', $form), 'integration.validation.number_invalid', 'value');
        }
        $localized = $builder->build(['value' => " \t12,50 "], $rules, 'fim:S00000096', '1.0', $form);
        if ($target !== 'integer') {
          $this->assertTrue($localized['valid']);
          $this->assertSame($target === 'string' ? '12.50' : 12.5, $localized['payload']->value);
        }
        if ($target === 'integer') {
          $precise = $builder->build(['value' => '9007199254740993.0'], $rules, 'fim:S00000096', '1.0', $form);
          $this->assertSame(9007199254740993, $precise['payload']->value);
          foreach (['1.1', '1.00000000000000000001', '1e-100', '9223372036854775808'] as $invalid) {
            $this->assertFailure($builder->build(['value' => $invalid], $rules, 'fim:S00000096', '1.0', $form), 'integration.validation.integer_invalid', 'value');
          }
        }
      });
    }
    $this->withSchema(['type' => 'object', 'properties' => ['value' => ['type' => 'string', 'format' => 'date']]], function (MappedPayloadBuilder $builder): void {
      $form = ['sections' => [['fields' => [['id' => 'value', 'type' => 'date']]]]];
      $rules = ['version' => 1, 'rules' => [['source' => 'value', 'target' => '/value']]];
      $this->assertTrue($builder->build(['value' => '2024-02-29'], $rules, 'fim:S00000096', '1.0', $form)['valid']);
      foreach (['2026-02-29', '2026-9-19', '2026-09-19T00:00:00Z', '19.09.2026'] as $invalid) {
        $this->assertFailure($builder->build(['value' => $invalid], $rules, 'fim:S00000096', '1.0', $form), 'integration.validation.date_invalid', 'value');
      }
    });
  }

  /**
   * Every declared field type and target type agrees at check and execution.
   */
  public function testStaticAndRuntimeTypeMatrix(): void {
    $meta = json_decode(file_get_contents(dirname(__DIR__, 4) . '/beantragt_schema/schema/form_schema.schema.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $types = $meta['properties']['sections']['items']['properties']['fields']['items']['properties']['type']['enum'];
    $types = [...$types, 'integer', 'checkbox_group', 'select_multiple'];
    foreach (['string', 'number', 'integer', 'boolean', 'object', 'array'] as $target) {
      $path = ['type' => $target];
      if ($target === 'array') {
        $path['items'] = ['type' => 'string', 'enum' => ['A', 'B']];
      }
      $this->withSchema(['type' => 'object', 'properties' => ['value' => $path]], function (MappedPayloadBuilder $builder) use ($types, $target): void {
        $checker = (new \ReflectionProperty($builder, 'rules'))->getValue($builder);
        foreach ($types as $type) {
          $field = ['id' => 'value', 'type' => $type];
          if ($type === 'number' && $target === 'integer') {
            $field['validation']['step'] = 1;
          }
          $multiple = in_array($type, ['checkbox_group', 'select_multiple'], TRUE);
          if ($multiple) {
            $field['type'] = $type === 'checkbox_group' ? 'checkbox' : 'select';
            $field['multiple'] = $type === 'select_multiple';
          }
          $value = match ($type) {
            'checkbox' => FALSE,
            'number', 'integer' => '0',
            'date' => '2026-09-19',
            'checkbox_group', 'select_multiple' => ['b', 'a'],
            default => 'a',
          };
          $rule = ['source' => 'value', 'target' => '/value'];
          if (in_array($type, ['select', 'radio'], TRUE) || $multiple) {
            $field['options'] = ['a', 'b'];
            [$first, $second] = match ($target) {
              'number' => [1.5, 2.5],
              'integer' => [1, 2],
              'boolean' => [TRUE, FALSE],
              default => ['A', 'B'],
            };
            $rule['valueMap'] = (object) ['a' => $first, 'b' => $second];
          }
          $form = ['sections' => [['fields' => [$field]]]];
          $rules = ['version' => 1, 'rules' => [$rule]];
          $valid = $checker->check($rules, $form, 'fim:S00000096', '1.0') === [];
          $result = $builder->build(['value' => $value], $rules, 'fim:S00000096', '1.0', $form);
          $this->assertSame($valid, $result['valid'], $type . ' -> ' . $target . ': ' . json_encode($result['errors']));
          if ($multiple && $target === 'array') {
            $this->assertTrue($valid);
            $this->assertSame(['A', 'B'], $result['payload']->value);
            $again = $builder->build(['value' => ['a', 'b']], $rules, 'fim:S00000096', '1.0', $form);
            $this->assertSame($result['json'], $again['json']);
            $invalid = $builder->build(['value' => ['a', 'unknown']], $rules, 'fim:S00000096', '1.0', $form);
            $this->assertFailure($invalid, 'integration.value_unmapped', 'value');
            unset($rules['rules'][0]['valueMap']->b);
            $this->assertContains('integration.value_map_incomplete', array_column($checker->check($rules, $form, 'fim:S00000096', '1.0'), 'code'));
          }
          elseif ($multiple) {
            $this->assertFalse($valid);
          }
        }
      });
    }
  }

  /**
   * Integer targets require a source domain enforced at public submission.
   */
  public function testIntegerTargetsRequireDeclaredIntegralSourceDomain(): void {
    $schema = ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']]];
    $this->withSchema($schema, function (MappedPayloadBuilder $builder): void {
      $checker = (new \ReflectionProperty($builder, 'rules'))->getValue($builder);
      $submit = new PublicApplicationSubmissionValidator();
      $rules = ['version' => 1, 'rules' => [['source' => 'value', 'target' => '/value']]];
      foreach ([[], ['step' => 0.5], ['step' => 1, 'min' => 0.5], ['step' => 0], ['step' => -1]] as $validation) {
        $field = ['id' => 'value', 'type' => 'number', 'validation' => $validation];
        $form = ['sections' => [['fields' => [$field]]]];
        $this->assertContains('integration.type_incompatible', array_column($checker->check($rules, $form, 'fim:S00000096', '1.0'), 'code'));
      }
      foreach ([['step' => 1], ['step' => 2, 'min' => 0], ['step' => 2, 'min' => -2]] as $validation) {
        $field = ['id' => 'value', 'type' => 'number', 'validation' => $validation];
        $form = ['sections' => [['fields' => [$field]]]];
        $this->assertSame([], $checker->check($rules, $form, 'fim:S00000096', '1.0'));
        $structure = ['fields' => [$submit->normalizeField($field)]];
        foreach (['12,50', '1.00000000000000000001', '9007199254740993.1'] as $raw) {
          $result = $submit->sanitizeAndValidateData(['value' => $raw], $structure);
          $this->assertSame(422, $result['error']['status']);
          $this->assertSame('step', $result['error']['details']['issue']);
        }
        $accepted = $submit->sanitizeAndValidateData(['value' => ' 12,00 '], $structure);
        $this->assertNull($accepted['error']);
        $built = $builder->build($accepted['data'], $rules, 'fim:S00000096', '1.0', $form);
        $this->assertTrue($built['valid']);
        $this->assertSame(12, $built['payload']->value);
      }
    });
  }

  /**
   * Real submit acceptance guarantees compatible mapping representations.
   */
  public function testAcceptedPublicSubmissionsBuildForEveryMappableFieldType(): void {
    $meta = json_decode(file_get_contents(dirname(__DIR__, 4) . '/beantragt_schema/schema/form_schema.schema.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $types = $meta['properties']['sections']['items']['properties']['fields']['items']['properties']['type']['enum'];
    $submit = new PublicApplicationSubmissionValidator();
    $seen = [];
    foreach (['string', 'number', 'integer', 'boolean', 'array', 'object'] as $target) {
      $path = ['type' => $target];
      if ($target === 'array') {
        $path['items'] = ['type' => 'string', 'enum' => ['A', 'B']];
      }
      $this->withSchema(['type' => 'object', 'properties' => ['value' => $path]], function (MappedPayloadBuilder $builder) use ($types, $target, $submit, &$seen): void {
        $checker = (new \ReflectionProperty($builder, 'rules'))->getValue($builder);
        foreach ([...$types, 'checkbox_group'] as $type) {
          $field = ['id' => 'value', 'type' => $type === 'checkbox_group' ? 'checkbox' : $type];
          if ($type === 'number' && $target === 'integer') {
            $field['validation'] = ['min' => 0, 'step' => 1];
          }
          $rule = ['source' => 'value', 'target' => '/value'];
          if (in_array($type, ['select', 'radio', 'checkbox_group'], TRUE)) {
            $field['options'] = ['a', 'b'];
            $rule['valueMap'] = (object) match ($target) {
              'number' => ['a' => 1.5, 'b' => 2.5],
              'integer' => ['a' => 1, 'b' => 2],
              'boolean' => ['a' => TRUE, 'b' => FALSE],
              default => ['a' => 'A', 'b' => 'B'],
            };
          }
          $form = ['sections' => [['fields' => [$field]]]];
          $rules = ['version' => 1, 'rules' => [$rule]];
          $mappable = $checker->check($rules, $form, 'fim:S00000096', '1.0') === [];
          if (in_array($type, ['file', 'address'], TRUE)) {
            $this->assertFalse($mappable, $type . ' must remain explicitly unsupported by mapping.');
          }
          $cases = match ($type) {
            'number' => [
              0, 1.0, 12.5, '12,50', ' 12 ', "\u{00a0}12,50\u{00a0}",
              '0,125', '1,234e0', '9007199254740993.0',
              '1.00000000000000000001', '1.234,50', '1,234.50',
              '1.000', '1,000', '1e999', '1e-999', TRUE,
            ],
            'date' => ['2024-02-29', '2026-09-19', '2026-02-31', '2025-02-29', ' 2026-09-19 ', 123],
            'email' => ['synthetic@example.invalid', 'invalid', 123],
            'tel' => ['+49 12345678', 'invalid', FALSE],
            'select', 'radio' => ['a', 'b', 'unknown', 1, FALSE],
            'checkbox' => [TRUE, FALSE, 'true', 'false', 0, 1],
            'checkbox_group' => [['b', 'a'], ['a', 'b'], ['b', 'b', 'a'], ['unknown'], 'a'],
            'address' => [['city' => 'SYNTHETIC'], 'invalid'],
            'file' => [[]],
            default => ['SYNTHETIC', 0, FALSE],
          };
          foreach ([...$cases, NULL, '', ' ', []] as $raw) {
            $bounded = $submit->boundedSubmittedData(['value' => $raw]);
            if ($bounded['error'] !== NULL) {
              continue;
            }
            $normalized_field = $submit->normalizeField($field);
            $this->assertNotNull($normalized_field, $type);
            $accepted = $submit->sanitizeAndValidateData($bounded['data'], ['fields' => [$normalized_field]]);
            if ($accepted['error'] !== NULL) {
              continue;
            }
            $seen[$type] = TRUE;
            if ($mappable) {
              $built = $builder->build($accepted['data'], $rules, 'fim:S00000096', '1.0', $form);
              $this->assertTrue($built['valid'], $type . ' -> ' . $target . ': ' . json_encode($built['errors']));
              $again = $builder->build($accepted['data'], $rules, 'fim:S00000096', '1.0', $form);
              $this->assertSame($built['json'], $again['json']);
            }
          }
        }
      });
    }
    $this->assertSame([], array_values(array_diff([...$types, 'checkbox_group'], array_keys($seen))));
  }

  /**
   * Invalid UTF-8 never leaves candidate data or encoding exception messages.
   */
  public function testEncodingFailureIsSanitized(): void {
    [$data, $rules] = $this->fixture();
    $data['synthetic_field_1'] = "\xFF";
    $result = $this->builder()->build($data, $rules, 'fim:S00000096', '1.0');
    $this->assertFalse($result['valid']);
    $this->assertNull($result['json']);
    $this->assertSame(['integration.payload_encoding_invalid'], array_column($result['errors'], 'code'));
  }

  /**
   * Asserts that failures contain only diagnostics, with no candidate payload.
   */
  private function assertFailure(array $result, string $code, string $source): void {
    $this->assertFalse($result['valid']);
    $this->assertNull($result['payload']);
    $this->assertNull($result['json']);
    $this->assertNull($result['sha256']);
    $this->assertNotEmpty(array_filter($result['errors'], static fn(array $error): bool => $error['code'] === $code && ($error['source'] ?? NULL) === $source));
    $this->assertStringNotContainsString('SYNTHETIC SECRET', json_encode($result['errors']));
  }

  /**
   * Runs a synthetic edge-case schema with its own matching checksum manifest.
   */
  private function withSchema(array $schema, callable $callback): void {
    $root = sys_get_temp_dir() . '/beantragt-mapped-' . bin2hex(random_bytes(8));
    $dir = $root . '/fim/S00000096/1.0';
    mkdir($dir, 0700, TRUE);
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/synthetic-contracts/fim/S00000096/1.0/manifest.json'), TRUE, 32, JSON_THROW_ON_ERROR);
    $schema['$id'] = $manifest['sourceUri'];
    $schema['$schema'] = 'https://json-schema.org/draft/2020-12/schema';
    $json = json_encode($schema, JSON_THROW_ON_ERROR);
    $manifest['sha256'] = hash('sha256', $json);
    file_put_contents($dir . '/schema.json', $json);
    file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
      $callback($this->builder($root, $manifest));
    }
    finally {
      unlink($dir . '/schema.json');
      unlink($dir . '/manifest.json');
      for ($path = $dir; strlen($path) >= strlen($root); $path = dirname($path)) {
        rmdir($path);
      }
    }
  }

}
