<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\ApplicationDataEditValidator;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Tests administrative application data edit validation.
 */
#[CoversClass(ApplicationDataEditValidator::class)]
#[Group('beantragt_application')]
final class ApplicationDataEditValidatorTest extends UnitTestCase {

  /**
   * Service under test.
   */
  private ApplicationDataEditValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->validator = new ApplicationDataEditValidator();
  }

  /**
   * Nested prototype mutation keys are rejected before validation.
   */
  public function testAssertSafeDataShapeRejectsNestedPrototypeKeys(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Application data contains an invalid key.');

    $this->validator->assertSafeDataShape(
      [
        'applicant' => [
          '__proto__' => [
            'polluted' => TRUE,
          ],
        ],
      ],
    );
  }

  /**
   * Preserved unknown data and unchanged hidden values are accepted.
   */
  public function testValidateAllowsPreservedUnknownAndHiddenValues(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'has_note', 'type' => 'checkbox', 'label' => 'Has note'],
        [
          'id' => 'note',
          'type' => 'textarea',
          'label' => 'Note',
          'conditionalOn' => [
            'fieldId' => 'has_note',
            'value' => TRUE,
          ],
        ],
        ['id' => 'homepage', 'type' => 'url', 'label' => 'Homepage'],
      ],
    );

    $this->validator->validate(
      $schema,
      [
        'has_note' => FALSE,
        'note' => 'Previously hidden',
        'homepage' => 'example.org',
        'legacy_pdf_field' => ['keep' => TRUE],
      ],
      [
        'has_note' => FALSE,
        'note' => 'Previously hidden',
        'homepage' => 'https://example.org',
        'legacy_pdf_field' => ['keep' => TRUE],
      ],
    );

    $this->addToAssertionCount(1);
  }

  /**
   * Schema-unknown stored keys cannot be changed by edit submissions.
   */
  public function testValidateRejectsChangedUnknownStoredKey(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'destination', 'type' => 'text', 'label' => 'Ziel'],
      ],
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Unknown stored field "legacy_pdf_field" cannot be changed.');

    $this->validator->validate(
      $schema,
      ['destination' => 'Dunkelstadt', 'legacy_pdf_field' => 'changed'],
      ['destination' => 'Musterstadt', 'legacy_pdf_field' => 'keep'],
    );
  }

  /**
   * Hidden schema-known values that were already hidden cannot be deleted.
   */
  public function testValidateRejectsHiddenStoredFieldDeletion(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'has_note', 'type' => 'checkbox', 'label' => 'Has note'],
        [
          'id' => 'note',
          'type' => 'textarea',
          'label' => 'Note',
          'conditionalOn' => [
            'fieldId' => 'has_note',
            'value' => TRUE,
          ],
        ],
      ],
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Hidden field "note" cannot be removed.');

    $this->validator->validate(
      $schema,
      ['has_note' => FALSE],
      ['has_note' => FALSE, 'note' => 'Previously hidden'],
    );
  }

  /**
   * URL schema aliases are normalized and validated.
   */
  public function testValidateRejectsInvalidInferredUrlFormat(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'homepage', 'type' => 'url', 'label' => 'Homepage'],
      ],
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Field "homepage" must be a valid URL.');

    $this->validator->validate(
      $schema,
      ['homepage' => 'javascript://example.com'],
      ['homepage' => 'https://example.org'],
    );
  }

  /**
   * Staff number edits reject the same ambiguous notation as submissions.
   */
  #[DataProvider('ambiguousNumberStrings')]
  public function testValidateRejectsAmbiguousNumberStrings(string $value): void {
    $schema = $this->schemaEntity([
      ['id' => 'quantity', 'type' => 'number', 'label' => 'Quantity'],
    ]);
    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Field "quantity" must be numeric.');
    $this->validator->validate($schema, ['quantity' => $value], ['quantity' => '1200']);
  }

  /**
   * Decimal strings which could also be grouped integers.
   */
  public static function ambiguousNumberStrings(): array {
    return [
      'dot' => ['1.200'],
      'comma' => ['1,200'],
      'negative' => ['-12.345'],
      'positive' => ['+123,456'],
      'trimmed' => [' 1.200 '],
    ];
  }

  /**
   * Unchanged legacy numbers do not block editing another visible field.
   */
  public function testValidatePreservesStoredAmbiguousNumber(): void {
    $schema = $this->schemaEntity([
      ['id' => 'quantity', 'type' => 'number', 'label' => 'Quantity'],
      ['id' => 'note', 'type' => 'text', 'label' => 'Note'],
    ]);
    $stored = ['quantity' => '1.200', 'note' => 'Before'];
    $edited = ['quantity' => '1.200', 'note' => 'After'];
    $this->validator->validate($schema, $edited, $stored);
    self::assertSame($edited, $this->validator->normalizeData($schema, $edited));
  }

  /**
   * Stored comma numbers do not block editing another field.
   */
  public function testValidatePreservesStoredCommaNumbers(): void {
    $schema = $this->schemaEntity([
      ['id' => 'quantity', 'type' => 'number', 'label' => 'Quantity'],
      ['id' => 'note', 'type' => 'text', 'label' => 'Note'],
    ]);
    foreach (['1,200', '1,5'] as $value) {
      $stored = ['quantity' => $value, 'note' => 'Before'];
      $edited = ['quantity' => $value, 'note' => 'After'];
      $this->validator->validate($schema, $edited, $stored);
      self::assertSame($edited, $this->validator->normalizeData($schema, $edited));
    }
  }

  /**
   * Preserving a value never bypasses the original numeric requirement.
   */
  public function testValidateRejectsStoredNonNumericValue(): void {
    $schema = $this->schemaEntity([
      ['id' => 'quantity', 'type' => 'number', 'label' => 'Quantity'],
    ]);
    $stored = ['quantity' => 'abc'];
    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Field "quantity" must be numeric.');
    $this->validator->validate($schema, $stored, $stored);
  }

  /**
   * Unambiguous numbers remain valid without changing their stored shape.
   */
  public function testValidateAcceptsUnambiguousNumbers(): void {
    $schema = $this->schemaEntity([
      ['id' => 'quantity', 'type' => 'number', 'label' => 'Quantity'],
    ]);
    foreach ([1200, 1.2, 9.995, '1200', '1200.50', '1200,50', '0.125', '0,125', '1.200e3'] as $value) {
      $data = ['quantity' => $value];
      $this->validator->validate($schema, $data, ['quantity' => 0]);
      self::assertSame($data, $this->validator->normalizeData($schema, $data));
    }
  }

  /**
   * Structured address values remain objects during administrative edits.
   */
  public function testValidateAcceptsStructuredAddress(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'subject_address', 'type' => 'address', 'label' => 'Adresse'],
      ],
    );
    $address = [
      'street' => 'Rathausstraße',
      'houseNumber' => '1',
      'postcode' => '12345',
      'city' => 'Musterstadt',
      'formatted' => 'Rathausstraße 1, 12345 Musterstadt',
      'lat' => 50.5432,
      'lng' => 7.6543,
    ];

    $this->validator->validate($schema, ['subject_address' => $address], ['subject_address' => $address]);
    $this->addToAssertionCount(1);
  }

  /**
   * Administrative address edits are canonicalized before validation.
   */
  public function testNormalizeDataCanonicalizesStructuredAddress(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'subject_address', 'type' => 'address', 'label' => 'Adresse'],
      ],
    );
    $data = $this->validator->normalizeData($schema, [
      'subject_address' => [
        'street' => '  Rathausstraße  ',
        'houseNumber' => '1',
        'postcode' => '12345',
        'city' => 'Musterstadt',
        'countryCode' => 'de',
        'formatted' => '',
      ],
    ]);

    $this->assertSame([
      'street' => 'Rathausstraße',
      'houseNumber' => '1',
      'postcode' => '12345',
      'city' => 'Musterstadt',
      'countryCode' => 'DE',
      'formatted' => 'Rathausstraße 1, 12345 Musterstadt',
    ], $data['subject_address']);
    $this->validator->validate($schema, $data, $data);
  }

  /**
   * Non-canonical address objects are rejected during administrative edits.
   */
  public function testValidateRejectsAddressProviderPayload(): void {
    $schema = $this->schemaEntity(
      [
        ['id' => 'subject_address', 'type' => 'address', 'label' => 'Adresse'],
      ],
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Field "subject_address" must be a structured address.');
    $this->validator->validate(
      $schema,
      [
        'subject_address' => [
          'formatted' => 'Rathausstraße 1, 12345 Musterstadt',
          'raw' => ['provider' => 'must not persist'],
        ],
      ],
      ['subject_address' => ['formatted' => 'Rathausstraße 1, 12345 Musterstadt']],
    );
  }

  /**
   * String-like sensitive flags are honored for protected-data access checks.
   */
  public function testSchemaHasSensitiveFieldsReadsStringBooleanFlags(): void {
    $this->assertTrue(
      $this->validator->schemaHasSensitiveFields(
        $this->schemaEntity(
          [
            ['id' => 'diagnosis', 'type' => 'text', 'label' => 'Diagnose', 'sensitive' => 'true'],
          ],
        ),
      ),
    );
    $this->assertFalse(
      $this->validator->schemaHasSensitiveFields(
        $this->schemaEntity(
          [
            ['id' => 'public_note', 'type' => 'text', 'label' => 'Oeffentliche Notiz'],
          ],
        ),
      ),
    );
  }

  /**
   * File fields are sensitive by default unless explicitly marked otherwise.
   */
  public function testSchemaHasSensitiveFieldsProtectsFileFieldsByDefault(): void {
    $this->assertTrue(
      $this->validator->schemaHasSensitiveFields(
        $this->schemaEntity(
          [
            ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis'],
          ],
        ),
      ),
    );
    $this->assertFalse(
      $this->validator->schemaHasSensitiveFields(
        $this->schemaEntity(
          [
            ['id' => 'public_upload', 'type' => 'file', 'label' => 'Oeffentliche Anlage', 'sensitive' => FALSE],
          ],
        ),
      ),
    );
    $this->assertTrue(
      $this->validator->schemaHasSensitiveFields(
        $this->schemaEntity(
          [
            ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis', 'sensitive' => 'maybe'],
          ],
        ),
      ),
    );
  }

  /**
   * Creates a form schema entity mock.
   *
   * @param array<int, array<string, mixed>> $fields
   *   Schema fields.
   */
  private function schemaEntity(array $fields): FormSchemaInterface {
    $schema_entity = $this->createMock(FormSchemaInterface::class);
    $schema_entity->method('getSchema')
      ->willReturn(
        json_encode(
          [
            'title' => 'Test',
            'sections' => [
              [
                'id' => 'basis',
                'title' => 'Basis',
                'fields' => $fields,
              ],
            ],
          ],
          JSON_THROW_ON_ERROR,
        ),
      );

    return $schema_entity;
  }

}
