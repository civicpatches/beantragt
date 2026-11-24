<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\PublicApplicationSubmissionValidator;
use Drupal\beantragt_application\Service\FormValueParser;
use Drupal\beantragt_schema\Service\PublicFieldDefault;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests public application submission payload validation.
 */
#[CoversClass(PublicApplicationSubmissionValidator::class)]
#[Group('beantragt_application')]
final class PublicApplicationSubmissionValidatorTest extends UnitTestCase {

  /**
   * Service under test.
   */
  private PublicApplicationSubmissionValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->validator = new PublicApplicationSubmissionValidator();
  }

  /**
   * Every accepted projection fixture must also pass actual submission rules.
   */
  public function testAcceptedPublicDefaultsAreSubmittable(): void {
    $path = __DIR__ . '/../../../../../beantragt_schema/tests/fixtures/public-field-default-cases.json';
    $cases = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
      if (!$case['valid']) {
        continue;
      }
      $value = PublicFieldDefault::project($case['field'], $case['value']);
      self::assertNotNull($value, $case['name']);
      $field = $this->validator->normalizeField(['id' => 'answer'] + $case['field']);
      self::assertNotNull($field, $case['name']);
      $result = $this->validator->sanitizeAndValidateData(['answer' => $value], ['fields' => [$field]]);
      self::assertNull($result['error'], $case['name']);
    }
  }

  /**
   * Submitted reserved keys are stripped and protection markers are rejected.
   */
  public function testBoundedSubmittedDataStripsReservedAndRejectsMarkers(): void {
    $bounded = $this->validator->boundedSubmittedData(
      [
        'full_name' => 'Ada Lovelace',
        'applicant_email' => 'must-not-be-trusted@example.org',
      ],
    );

    $this->assertSame(['full_name' => 'Ada Lovelace'], $bounded['data']);
    $this->assertNull($bounded['error']);

    $invalid = $this->validator->boundedSubmittedData(
      [
        '__beantragt_redacted' => TRUE,
      ],
    );

    $this->assertSame([], $invalid['data']);
    $this->assertSame('application.invalid_key', $invalid['error']['code']);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $invalid['error']['status']);
  }

  /**
   * Object values and unbounded arrays are rejected before persistence.
   */
  public function testBoundedSubmittedDataRejectsObjectAndOversizedArrayValues(): void {
    $object_value = $this->validator->boundedSubmittedData(
      [
        'full_name' => [
          '__beantragt_encrypted' => TRUE,
        ],
      ],
    );
    $this->assertSame('application.object_unsupported', $object_value['error']['code']);

    $oversized_array = $this->validator->boundedSubmittedData(
      [
        'topics' => array_fill(0, 101, 'A'),
      ],
    );
    $this->assertSame('application.array_too_large', $oversized_array['error']['code']);
    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $oversized_array['error']['status']);
  }

  /**
   * A repeatable section cannot be submitted as a list of objects.
   */
  public function testBoundedSubmittedDataRejectsNestedRepeatableSection(): void {
    $result = $this->validator->boundedSubmittedData([
      'account_holders' => [['iban_empfaenger' => 'DE00000000000000000000']],
    ]);
    $this->assertSame([], $result['data']);
    $this->assertSame('application.array_item_invalid', $result['error']['code']);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $result['error']['status']);
  }

  /**
   * Structured addresses are bounded, normalized, and validated as one field.
   */
  public function testStructuredAddressSubmissionContract(): void {
    $address = [
      'street' => 'Rathausstraße',
      'houseNumber' => '1',
      'postcode' => '12345',
      'city' => 'Musterstadt',
      'formatted' => 'Rathausstraße 1, 12345 Musterstadt',
      'lat' => 50.5432,
      'lng' => 7.6543,
    ];
    $bounded = $this->validator->boundedSubmittedData(['subject_address' => $address]);
    $this->assertNull($bounded['error']);

    $field = $this->validator->normalizeField([
      'id' => 'subject_address',
      'type' => 'address',
      'required' => TRUE,
    ]);
    $this->assertNotNull($field);
    $this->assertSame('address', $field['type']);
    $result = $this->validator->sanitizeAndValidateData(
      ['subject_address' => $address],
      ['fields' => [$field]],
    );
    $this->assertNull($result['error']);
    $this->assertSame($address, $result['data']['subject_address']);

    $unsafe = $this->validator->boundedSubmittedData([
      'subject_address' => $address + ['raw' => ['provider' => 'must not persist']],
    ]);
    $this->assertSame('application.object_unsupported', $unsafe['error']['code']);
  }

  /**
   * Address-shaped objects cannot bypass checkbox list validation.
   */
  public function testCheckboxOptionsRequireListValue(): void {
    $field = $this->validator->normalizeField([
      'id' => 'topics',
      'type' => 'checkbox',
      'options' => ['Musterstadt'],
    ]);
    $this->assertNotNull($field);

    $bounded = $this->validator->boundedSubmittedData([
      'topics' => ['city' => 'Musterstadt'],
    ]);
    $this->assertNull($bounded['error']);

    $result = $this->validator->sanitizeAndValidateData(
      $bounded['data'],
      ['fields' => [$field]],
    );
    $this->assertSame('application.validation_failed', $result['error']['code']);
    $this->assertSame('topics', $result['error']['details']['field']);
    $this->assertSame('option', $result['error']['details']['issue']);
  }

  /**
   * Conditional fields are removed when their dependency is not matched.
   */
  public function testSanitizeAndValidateDataRemovesHiddenConditionalFields(): void {
    $result = $this->validator->sanitizeAndValidateData(
      [
        'kind' => 'B',
        'details' => 'Only for A',
      ],
      [
        'fields' => [
          [
            'id' => 'kind',
            'type' => 'select',
            'required' => FALSE,
            'options' => ['A', 'B'],
            'format' => NULL,
            'validation' => [],
            'conditionalOn' => NULL,
          ],
          [
            'id' => 'details',
            'type' => 'textarea',
            'required' => FALSE,
            'options' => [],
            'format' => NULL,
            'validation' => [],
            'conditionalOn' => [
              'fieldId' => 'kind',
              'value' => 'A',
            ],
          ],
        ],
      ],
    );

    $this->assertSame(['kind' => 'B'], $result['data']);
    $this->assertNull($result['error']);
  }

  /**
   * Invalid validation patterns fail closed.
   */
  public function testSanitizeAndValidateDataRejectsInvalidValidationPattern(): void {
    $result = $this->validator->sanitizeAndValidateData(
      ['code' => 'anything'],
      [
        'fields' => [
          [
            'id' => 'code',
            'type' => 'text',
            'required' => FALSE,
            'options' => [],
            'format' => NULL,
            'validation' => [
              'pattern' => '*',
            ],
            'conditionalOn' => NULL,
          ],
        ],
      ],
    );

    $this->assertSame('application.validation_failed', $result['error']['code']);
    $this->assertSame('code', $result['error']['details']['field']);
    $this->assertSame('pattern', $result['error']['details']['issue']);
  }

  /**
   * Select and radio fields without options fail closed.
   */
  public function testSanitizeAndValidateDataRejectsSelectWithoutOptions(): void {
    $result = $this->validator->sanitizeAndValidateData(
      ['kind' => 'A'],
      [
        'fields' => [
          [
            'id' => 'kind',
            'type' => 'select',
            'required' => FALSE,
            'options' => [],
            'format' => NULL,
            'validation' => [],
            'conditionalOn' => NULL,
          ],
        ],
      ],
    );

    $this->assertSame('application.validation_failed', $result['error']['code']);
    $this->assertSame('options_missing', $result['error']['details']['issue']);
  }

  /**
   * Schema fields and auth policy are normalized to the public contract.
   */
  public function testNormalizeFieldAndAuth(): void {
    $field = $this->validator->normalizeField(
      [
        'id' => 'topics',
        'type' => 'checkbox',
        'required' => TRUE,
        'options' => ['A', 1, 'B'],
        'format' => 123,
        'validation' => [
          'maxFiles' => 2,
        ],
        'conditionalOn' => [
          'fieldId' => 'kind',
        ],
        'crm_key' => 'case.topic',
      ],
    );

    $this->assertSame('topics', $field['id']);
    $this->assertSame('checkbox', $field['type']);
    $this->assertTrue($field['required']);
    $this->assertSame(['A', 'B'], $field['options']);
    $this->assertNull($field['format']);
    $this->assertSame('case.topic', $field['crm_key']);

    $auth = $this->validator->normalizeAuth(
      [
        'mode' => 'optional',
        'collect' => [
          'fullName' => 'required',
          'email' => 'bogus',
        ],
      ],
    );
    $this->assertSame(['mode' => 'optional', 'collect' => ['fullName' => 'required']], $auth);
  }

  /**
   * German numeric inputs are stored canonically, without losing their scale.
   */
  public function testCanonicalNumberSubmissionAndAmbiguityMessages(): void {
    $field = $this->validator->normalizeField(['id' => 'amount', 'type' => 'number']);
    foreach ([
      ['12,50', '12.50'],
      [" \t12,50\n", '12.50'],
      ["\u{00a0}12\u{00a0}", '12'],
      ['0,125', '0.125'],
      ['1234,567', '1234.567'],
      ['1,234e0', '1.234e0'],
      [12.5, 12.5],
      [0, 0],
    ] as [$raw, $canonical]) {
      $result = $this->validator->sanitizeAndValidateData(['amount' => $raw], ['fields' => [$field]]);
      $this->assertNull($result['error'], json_encode($result['error']));
      $this->assertSame($canonical, $result['data']['amount']);
    }
    foreach (['1.234,50', '1,234.50', '1.000', '1,000', '12.345', '123,456', 'NaN', 'INF', '1e999', '1e-999', TRUE] as $raw) {
      $result = $this->validator->sanitizeAndValidateData(['amount' => $raw], ['fields' => [$field]]);
      $this->assertSame('application.validation_failed', $result['error']['code']);
      $this->assertSame(422, $result['error']['status']);
      $this->assertStringContainsString('number', $result['error']['message']);
    }
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->once())->method('translate')->with($this->stringContains('ambiguous'))
      ->willReturn('Bitte die Zahl ohne Tausendertrennzeichen eingeben.');
    $validator = new PublicApplicationSubmissionValidator(new FormValueParser(), $translation);
    $result = $validator->sanitizeAndValidateData(['amount' => '1.000'], ['fields' => [$field]]);
    $this->assertSame('number_ambiguous', $result['error']['details']['issue']);
    $this->assertSame('Bitte die Zahl ohne Tausendertrennzeichen eingeben.', $result['error']['message']);
  }

  /**
   * Invalid calendar dates fail before storage; checkbox values stay booleans.
   */
  public function testCalendarDatesAndBooleanStorage(): void {
    $date = $this->validator->normalizeField(['id' => 'date', 'type' => 'date']);
    foreach (['2026-02-31', '2025-02-29', '2026-13-01', '2026-9-19', '2026-09-19T00:00:00Z'] as $raw) {
      $result = $this->validator->sanitizeAndValidateData(['date' => $raw], ['fields' => [$date]]);
      $this->assertSame('date', $result['error']['details']['issue']);
    }
    $this->assertNull($this->validator->sanitizeAndValidateData(['date' => '2024-02-29'], ['fields' => [$date]])['error']);
    $checkbox = $this->validator->normalizeField(['id' => 'agree', 'type' => 'checkbox']);
    foreach ([TRUE, FALSE] as $raw) {
      $result = $this->validator->sanitizeAndValidateData(['agree' => $raw], ['fields' => [$checkbox]]);
      $this->assertNull($result['error']);
      $this->assertSame($raw, $result['data']['agree']);
    }
    foreach (['true', 'false', 1, 0] as $raw) {
      $this->assertSame('format', $this->validator->sanitizeAndValidateData(['agree' => $raw], ['fields' => [$checkbox]])['error']['details']['issue']);
    }
  }

}
