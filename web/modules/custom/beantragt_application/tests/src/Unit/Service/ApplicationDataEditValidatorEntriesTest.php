<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\ApplicationDataEditValidator;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Staff edits of a repeatable section stored as entries (batch intake).
 *
 * Batch intake stores one list per non-file field of a repeatable section,
 * one element per entry. A staff edit validates each element as one value
 * of its field, keeps the number of entries and never collapses a column.
 * Neutral schema: no concrete form, field or status names.
 */
#[CoversClass(ApplicationDataEditValidator::class)]
#[Group('beantragt_application')]
final class ApplicationDataEditValidatorEntriesTest extends UnitTestCase {

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
   * Two valid entries are accepted, with both checkbox group shapes.
   */
  public function testTwoValidEntriesAreAccepted(): void {
    $stored = $this->stored();
    $this->validator->validate($this->schema(), $stored, $stored, 'bulk');

    $edited = $stored;
    $edited['text_a'][1] = 'Changed';
    $edited['count'][0] = '4';
    // A checkbox group per entry: the scalar batch intake stores, or a list.
    $edited['choices'] = [['a', 'c'], NULL];
    $this->validator->validate($this->schema(), $edited, $stored, 'bulk');
    $this->addToAssertionCount(2);
  }

  /**
   * An invalid second entry names that entry only.
   */
  public function testInvalidSecondEntryNamesTheEntry(): void {
    $edited = $this->stored();
    $edited['text_a'][1] = 5;
    $this->assertRefused('Section "entries", entry 1: Field "text_a" must be a string.', $edited);

    $edited = $this->stored();
    $edited['count'][1] = 'many';
    $this->assertRefused('Section "entries", entry 1: Field "count" must be numeric.', $edited);

    $edited = $this->stored();
    $edited['choices'][1] = 'z';
    $this->assertRefused('Section "entries", entry 1: Field "choices" contains an invalid option.', $edited);
  }

  /**
   * Batch number edits reject ambiguous decimals for the specific entry.
   */
  public function testAmbiguousNumberInEntryIsRejected(): void {
    foreach (['1.200', '1,200'] as $value) {
      $edited = $this->stored();
      $edited['count'][1] = $value;
      $this->assertRefused('Section "entries", entry 1: Field "count" must be numeric.', $edited);
    }
  }

  /**
   * Editing another entry field preserves legacy ambiguous numeric elements.
   */
  public function testStoredAmbiguousNumberInEntryIsPreserved(): void {
    foreach (['1.200', '1,200', '1,5'] as $value) {
      $stored = $this->stored();
      $stored['count'][1] = $value;
      $edited = $stored;
      $edited['text_a'][1] = 'Changed';
      $this->validator->validate($this->schema(), $edited, $stored, 'bulk');
      $this->addToAssertionCount(1);
    }

    $stored['count'][1] = 'abc';
    $edited['count'][1] = 'abc';
    $this->assertRefused('Section "entries", entry 1: Field "count" must be numeric.', $edited, $stored);
  }

  /**
   * Required is checked per entry.
   */
  public function testRequiredEmptyInOneEntry(): void {
    $edited = $this->stored();
    $edited['text_a'][0] = '  ';
    $this->assertRefused('Section "entries", entry 0: Required field "text_a" is empty.', $edited);
  }

  /**
   * A checkbox without options holds one boolean per entry.
   */
  public function testCheckboxWithoutOptionsPerEntry(): void {
    $edited = $this->stored();
    $edited['flag'][0] = 'yes';
    $this->assertRefused('Section "entries", entry 0: Field "flag" must be a boolean.', $edited);
  }

  /**
   * A condition on a sibling resolves inside each entry.
   */
  public function testConditionalSiblingResolvesPerEntry(): void {
    $stored = $this->stored();
    // Entry 0: flag off, detail hidden; entry 1: flag on, detail visible.
    $this->assertNull($stored['detail'][0]);
    $edited = $stored;
    $edited['detail'][1] = 'Visible in entry 1';
    $this->validator->validate($this->schema(), $edited, $stored, 'bulk');

    // A hidden element may not be changed.
    $edited = $stored;
    $edited['detail'][0] = 'Typed into a hidden entry';
    $this->assertRefused('Section "entries", entry 0: Hidden field "detail" cannot be changed.', $edited, $stored);

    // A stored hidden element may not be removed.
    $stored_hidden = $stored;
    $stored_hidden['detail'][0] = 'Kept while hidden';
    $edited = $stored_hidden;
    $edited['detail'][0] = NULL;
    $this->assertRefused('Section "entries", entry 0: Hidden field "detail" cannot be removed.', $edited, $stored_hidden);

    // Unchanged it is accepted.
    $this->validator->validate($this->schema(), $stored_hidden, $stored_hidden, 'bulk');

    // A required field that becomes hidden in one entry is skipped there.
    $edited = $stored;
    $edited['flag'][1] = FALSE;
    $edited['detail'][1] = NULL;
    $this->validator->validate($this->schema(), $edited, $stored, 'bulk');
  }

  /**
   * A condition outside the section uses the top-level value for every entry.
   */
  public function testConditionOutsideTheSectionUsesTheTopLevelValue(): void {
    $stored = $this->stored();
    $stored['topic'] = '';
    $stored['outside'] = [NULL, NULL];
    $edited = $stored;
    $edited['outside'][1] = 'Typed while the top-level field is empty';
    $this->assertRefused('Section "entries", entry 1: Hidden field "outside" cannot be changed.', $edited, $stored);
  }

  /**
   * The number of entries stays as stored.
   */
  public function testUnequalLengthsAreRefused(): void {
    $edited = $this->stored();
    $edited['text_a'][] = 'Third';
    $this->assertRefused('Section "entries" must keep 2 entries; field "text_a" has 3.', $edited);

    $edited = $this->stored();
    array_pop($edited['count']);
    $this->assertRefused('Section "entries" must keep 2 entries; field "count" has 1.', $edited);
  }

  /**
   * A scalar for a stored column would collapse the entries.
   */
  public function testScalarForStoredColumnIsRefused(): void {
    $edited = $this->stored();
    $edited['text_a'] = 'One value for all';
    $this->assertRefused('Field "text_a" of section "entries" must keep one value per entry.', $edited);

    $edited = $this->stored();
    unset($edited['count']);
    $this->assertRefused('Field "count" of section "entries" must keep one value per entry.', $edited);
  }

  /**
   * A citizen-shaped application of the same form stays valid and flat.
   */
  public function testCitizenShapedApplicationStaysFlat(): void {
    $citizen = [
      'topic' => 'Topic',
      'text_a' => 'Single',
      'count' => 2,
      'flag' => TRUE,
      'choices' => ['a', 'b'],
      'detail' => 'Shown',
      'outside' => 'Shown',
    ];
    $this->validator->validate($this->schema(), $citizen, $citizen, 'citizen');
    // Without an origin (callers before E1) the same.
    $this->validator->validate($this->schema(), $citizen, $citizen);

    // The flat rule is unchanged: a list for a text field is refused.
    $edited = $citizen;
    $edited['text_a'] = ['Smuggled', 'list'];
    $this->assertRefused('Field "text_a" must be a string.', $edited, $citizen, NULL, 'citizen');

    // A flat checkbox group keeps its own rule.
    $edited = $citizen;
    $edited['choices'] = 'a';
    $this->assertRefused('Field "choices" must be a list of options.', $edited, $citizen, NULL, 'citizen');
  }

  /**
   * Only origin "bulk" has columns: stored lists of another origin are flat.
   */
  public function testOtherOriginNeverHasColumns(): void {
    $stored = $this->stored();
    $this->assertRefused('Field "text_a" must be a string.', $stored, $stored, NULL, 'citizen');
    $this->assertRefused('Field "text_a" must be a string.', $stored, $stored, NULL, NULL);
  }

  /**
   * Checkbox groups only: with origin bulk a stored list is a column.
   */
  public function testCheckboxGroupOnlySectionOfBatchApplication(): void {
    $schema = $this->schemaOf([
      ['id' => 'choices', 'type' => 'checkbox', 'label' => 'Choices', 'options' => ['a', 'b']],
    ]);
    // The scalar column batch intake writes today.
    $stored = ['topic' => 'Topic', 'choices' => ['a', NULL]];
    $this->validator->validate($schema, $stored, $stored, 'bulk');
    $this->validator->validate($schema, ['topic' => 'Topic', 'choices' => [['a', 'b'], 'b']], $stored, 'bulk');
    $this->assertRefused('Section "entries", entry 1: Field "choices" contains an invalid option.', [
      'topic' => 'Topic',
      'choices' => ['a', 'z'],
    ], $stored, $schema);
    $this->assertRefused('Section "entries" must keep 2 entries; field "choices" has 3.', [
      'topic' => 'Topic',
      'choices' => ['a', 'b', 'a'],
    ], $stored, $schema);

    // The same stored list of a citizen application is one flat value.
    $citizen = ['topic' => 'Topic', 'choices' => ['a', 'b']];
    $this->validator->validate($schema, $citizen, $citizen, 'citizen');
    $this->assertRefused('Field "choices" contains an invalid option.', [
      'topic' => 'Topic',
      'choices' => [['a'], ['b']],
    ], $citizen, $schema, 'citizen');
  }

  /**
   * A batch field whose stored value is no list is flat for that field.
   */
  public function testBatchFieldWithoutStoredListIsFlat(): void {
    $stored = $this->stored();
    $stored['count'] = 3;
    $this->validator->validate($this->schema(), $stored, $stored, 'bulk');
    // A list sent for it is refused by the flat rule, no column appears.
    $edited = $stored;
    $edited['count'] = [3, 4];
    $this->assertRefused('Field "count" must be numeric.', $edited, $stored);
  }

  /**
   * File fields of the section are never columns.
   */
  public function testFileFieldStaysFlat(): void {
    $stored = $this->stored();
    $stored['proof'] = ['uuid-1'];
    $this->validator->validate($this->schema(), $stored, $stored, 'bulk');
    $this->addToAssertionCount(1);
  }

  /**
   * A file field whose condition points at a column: hidden in every entry.
   *
   * Batch intake asks a proof of the section once per entry that meets its
   * condition; with no such entry it is hidden and not required.
   */
  public function testConditionalFileFieldHiddenWhenNoEntryMeetsIt(): void {
    $stored = ['topic' => 'Topic', 'member' => ['One'], 'flag' => [FALSE], 'proof' => []];
    $this->validator->validate($this->fileSchema(), $stored, $stored, 'bulk');
    $edited = $stored;
    $edited['member'][0] = 'Changed';
    $this->validator->validate($this->fileSchema(), $edited, $stored, 'bulk');
    // Hidden: a stored attachment list stays as it is.
    $stored_proof = $stored;
    $stored_proof['proof'] = [['attachmentId' => 'a1']];
    $edited = $stored_proof;
    $edited['proof'] = [['attachmentId' => 'a2']];
    $this->assertRefused('Hidden field "proof" cannot be changed.', $edited, $stored_proof, $this->fileSchema());
    $edited = $stored_proof;
    unset($edited['proof']);
    $this->assertRefused('Hidden field "proof" cannot be removed.', $edited, $stored_proof, $this->fileSchema());
  }

  /**
   * One of two entries meets the condition: visible and required.
   */
  public function testConditionalFileFieldVisibleWhenOneEntryMeetsIt(): void {
    $stored = [
      'topic' => 'Topic',
      'member' => ['One', 'Two'],
      'flag' => [FALSE, TRUE],
      'proof' => [['attachmentId' => 'a1']],
    ];
    $this->validator->validate($this->fileSchema(), $stored, $stored, 'bulk');
    // A visible file field is never changed by this path, also not emptied.
    $edited = $stored;
    $edited['proof'] = [];
    $this->assertRefused('File field "proof" cannot be changed by a data edit.', $edited, $stored, $this->fileSchema());
    // The edit itself can switch the condition off; then nothing is required.
    $edited['flag'][1] = FALSE;
    $this->assertRefused('Hidden field "proof" cannot be changed.', $edited, $stored, $this->fileSchema());
    unset($edited['proof']);
    $this->validator->validate($this->fileSchema(), $edited, $stored, 'bulk');
  }

  /**
   * A citizen application (scalar sibling) behaves as before.
   */
  public function testConditionalFileFieldOfCitizenApplicationIsUnchanged(): void {
    $citizen = ['topic' => 'Topic', 'member' => 'One', 'flag' => FALSE, 'proof' => []];
    $this->validator->validate($this->fileSchema(), $citizen, $citizen, 'citizen');
    $edited = $citizen;
    $edited['flag'] = TRUE;
    $this->assertRefused('Required field "proof" is empty.', $edited, $citizen, $this->fileSchema(), 'citizen');
  }

  /**
   * The data of the case found in the browser: one entry, two proofs.
   *
   * Two text columns, two checkbox columns as batch intake writes them
   * (booleans), two conditional required file fields: one empty and hidden,
   * one filled and visible. Changing a text of the entry saves.
   */
  public function testBatchCaseWithHiddenEmptyProofSaves(): void {
    $schema = $this->schemaOf([
      ['id' => 'text_a', 'type' => 'text', 'label' => 'Text A', 'required' => TRUE],
      ['id' => 'text_b', 'type' => 'text', 'label' => 'Text B', 'required' => TRUE],
      ['id' => 'flag_a', 'type' => 'checkbox', 'label' => 'Flag A'],
      ['id' => 'flag_b', 'type' => 'checkbox', 'label' => 'Flag B'],
      [
        'id' => 'proof_a',
        'type' => 'file',
        'label' => 'Proof A',
        'required' => TRUE,
        'conditionalOn' => ['fieldId' => 'flag_a', 'value' => TRUE],
      ],
      [
        'id' => 'proof_b',
        'type' => 'file',
        'label' => 'Proof B',
        'required' => TRUE,
        'conditionalOn' => ['fieldId' => 'flag_b', 'value' => TRUE],
      ],
    ]);
    $stored = [
      'topic' => 'Topic',
      'text_a' => ['First'],
      'text_b' => ['Before'],
      'flag_a' => [FALSE],
      'flag_b' => [TRUE],
      'proof_a' => [],
      'proof_b' => [['attachmentId' => 'a1', 'name' => 'file-a.pdf']],
    ];
    $edited = $stored;
    $edited['text_b'][0] = 'After';
    $this->validator->validate($schema, $edited, $stored, 'bulk');
    $this->addToAssertionCount(1);
  }

  /**
   * A visible file field accepts only its stored value, for every origin.
   */
  public function testVisibleFileFieldIsNeverChanged(): void {
    $schema = $this->schemaOf([
      ['id' => 'text_a', 'type' => 'text', 'label' => 'Text A'],
      ['id' => 'proof', 'type' => 'file', 'label' => 'Proof'],
    ]);
    foreach (['citizen', 'bulk', NULL] as $origin) {
      $stored = ['topic' => 'Topic', 'text_a' => 'One', 'proof' => [['attachmentId' => 'a1'], ['attachmentId' => 'a2']]];
      // Unchanged: accepted.
      $this->validator->validate($schema, $stored, $stored, $origin);
      foreach ([
        [['attachmentId' => 'a3']],
        [],
        [['attachmentId' => 'a2'], ['attachmentId' => 'a1']],
        'a1',
      ] as $value) {
        $edited = $stored;
        $edited['proof'] = $value;
        $this->assertRefused('File field "proof" cannot be changed by a data edit.', $edited, $stored, $schema, $origin);
      }
      // Left out while stored: that would remove the references.
      $edited = $stored;
      unset($edited['proof']);
      $this->assertRefused('File field "proof" cannot be changed by a data edit.', $edited, $stored, $schema, $origin);
      // Nothing stored and nothing sent: accepted; an empty list sent: refused.
      $without = ['topic' => 'Topic', 'text_a' => 'One'];
      $this->validator->validate($schema, $without, $without, $origin);
      $this->assertRefused('File field "proof" cannot be changed by a data edit.', $without + ['proof' => []], $without, $schema, $origin);
    }
  }

  /**
   * An empty required file field that was visible keeps the edit possible.
   *
   * An application accepted without its proof stays editable; the edit
   * cannot add an attachment anyway. A required file field the edit makes
   * visible must already hold one.
   */
  public function testRequiredEmptyFileFieldDoesNotBlockTheEdit(): void {
    $schema = $this->schemaOf([
      ['id' => 'text_a', 'type' => 'text', 'label' => 'Text A'],
      ['id' => 'proof', 'type' => 'file', 'label' => 'Proof', 'required' => TRUE],
    ]);
    $stored = ['topic' => 'Topic', 'text_a' => 'One', 'proof' => []];
    $edited = $stored;
    $edited['text_a'] = 'Two';
    $this->validator->validate($schema, $edited, $stored, 'citizen');
    $this->validator->validate($schema, $edited, $stored, 'bulk');
    $this->addToAssertionCount(2);
  }

  /**
   * A key outside the schema added by the request is refused.
   */
  public function testAddedUnknownKeyIsRefused(): void {
    $edited = $this->stored();
    $edited['extra_key'] = 'value';
    $this->assertRefused('Unknown submitted field "extra_key" is not allowed.', $edited);
  }

  /**
   * An element that becomes hidden must be sent empty, not with its value.
   */
  public function testNewlyHiddenElementWithOldValueIsRefused(): void {
    $stored = $this->stored();
    $edited = $stored;
    $edited['flag'][1] = FALSE;
    $this->assertRefused('Section "entries", entry 1: Hidden field "detail" cannot be changed.', $edited, $stored);
  }

  /**
   * Declared uploads whose label or format would normalize them otherwise.
   *
   * Each case triggers one branch of the format or type normalization (the
   * label words of inferFieldFormat(), a CRM key, a declared format, a type
   * alias). The field stays a file field: guarded, and never a column.
   *
   * @return iterable<string, array{0: array<string, mixed>}>
   *   The upload field without its id.
   */
  public static function disguisedUploads(): iterable {
    yield 'label postal code' => [['type' => 'file', 'label' => 'PLZ']];
    yield 'label iban' => [['type' => 'file', 'label' => 'IBAN']];
    yield 'label tax id' => [['type' => 'file', 'label' => 'Steuer-ID']];
    yield 'label bic' => [['type' => 'file', 'label' => 'BIC']];
    yield 'label reference' => [['type' => 'file', 'label' => 'Aktenzeichen']];
    yield 'label url' => [['type' => 'file', 'label' => 'Link']];
    yield 'label amount' => [['type' => 'file', 'label' => 'Kosten']];
    yield 'crm key' => [['type' => 'file', 'label' => 'Proof', 'crm_key' => 'person.iban']];
    yield 'declared format' => [['type' => 'file', 'label' => 'Proof', 'format' => 'url']];
    yield 'alias with amount label' => [['type' => 'upload', 'label' => 'Betrag']];
  }

  /**
   * A disguised upload is guarded like any file field.
   */
  #[DataProvider('disguisedUploads')]
  public function testDisguisedUploadIsGuarded(array $upload): void {
    $schema = $this->schemaOf([
      ['id' => 'text_a', 'type' => 'text', 'label' => 'Text A'],
      ['id' => 'proof'] + $upload,
    ]);
    $stored = ['topic' => 'Topic', 'text_a' => 'One', 'proof' => [['attachmentId' => 'a1']]];
    $edited = $stored;
    $edited['text_a'] = 'Two';
    $this->validator->validate($schema, $edited, $stored, 'citizen');
    foreach ([[], [['attachmentId' => 'a2']]] as $value) {
      $changed = $stored;
      $changed['proof'] = $value;
      $this->assertRefused('File field "proof" cannot be changed by a data edit.', $changed, $stored, $schema, 'citizen');
    }
    $left_out = $stored;
    unset($left_out['proof']);
    $this->assertRefused('File field "proof" cannot be changed by a data edit.', $left_out, $stored, $schema, 'citizen');
  }

  /**
   * A disguised upload in a batch section is no entry column.
   *
   * First in the section, a column would set the number of entries from its
   * attachment count and refuse every edit.
   */
  #[DataProvider('disguisedUploads')]
  public function testDisguisedUploadIsNoColumn(array $upload): void {
    $schema = $this->schemaOf([
      ['id' => 'proof'] + $upload,
      ['id' => 'text_a', 'type' => 'text', 'label' => 'Text A'],
    ]);
    $stored = ['topic' => 'Topic', 'proof' => [['attachmentId' => 'a1']], 'text_a' => ['One', 'Two']];
    $edited = $stored;
    $edited['text_a'][1] = 'Changed';
    $this->validator->validate($schema, $edited, $stored, 'bulk');
    $this->addToAssertionCount(1);
  }

  /**
   * A schema whose repeatable section has a conditional required file field.
   */
  private function fileSchema(): FormSchemaInterface {
    return $this->schemaOf([
      ['id' => 'member', 'type' => 'text', 'label' => 'Member'],
      ['id' => 'flag', 'type' => 'checkbox', 'label' => 'Flag'],
      [
        'id' => 'proof',
        'type' => 'file',
        'label' => 'Proof',
        'required' => TRUE,
        'conditionalOn' => ['fieldId' => 'flag', 'value' => TRUE],
      ],
    ]);
  }

  /**
   * Asserts a 422 with the exact message.
   */
  private function assertRefused(string $message, array $edited, ?array $stored = NULL, ?FormSchemaInterface $schema = NULL, ?string $origin = 'bulk'): void {
    try {
      $this->validator->validate($schema ?? $this->schema(), $edited, $stored ?? $this->stored(), $origin);
      $this->fail('Expected refusal: ' . $message);
    }
    catch (UnprocessableEntityHttpException $exception) {
      $this->assertSame($message, $exception->getMessage());
    }
  }

  /**
   * Stored data of a batch application with two entries.
   *
   * @return array<string, mixed>
   *   Columns in the shape of batch intake.
   */
  private function stored(): array {
    return [
      'topic' => 'Topic',
      'text_a' => ['First', 'Second'],
      'count' => [1, '2'],
      'flag' => [FALSE, TRUE],
      'choices' => ['a', NULL],
      'detail' => [NULL, 'Shown in entry 1'],
      'outside' => ['Top-level set', NULL],
    ];
  }

  /**
   * The neutral schema: one plain section and one repeatable section.
   */
  private function schema(): FormSchemaInterface {
    return $this->schemaOf([
      ['id' => 'text_a', 'type' => 'text', 'label' => 'Text A', 'required' => TRUE],
      ['id' => 'count', 'type' => 'number', 'label' => 'Count'],
      ['id' => 'flag', 'type' => 'checkbox', 'label' => 'Flag'],
      ['id' => 'choices', 'type' => 'checkbox', 'label' => 'Choices', 'options' => ['a', 'b', 'c']],
      [
        'id' => 'detail',
        'type' => 'text',
        'label' => 'Detail',
        'required' => TRUE,
        'conditionalOn' => ['fieldId' => 'flag', 'value' => TRUE],
      ],
      [
        'id' => 'outside',
        'type' => 'text',
        'label' => 'Outside',
        'conditionalOn' => ['fieldId' => 'topic'],
      ],
      ['id' => 'proof', 'type' => 'file', 'label' => 'Proof'],
    ]);
  }

  /**
   * A schema with a plain section and a repeatable section of these fields.
   *
   * @param list<array<string, mixed>> $entry_fields
   *   Fields of the repeatable section.
   */
  private function schemaOf(array $entry_fields): FormSchemaInterface {
    $schema_entity = $this->createMock(FormSchemaInterface::class);
    $schema_entity->method('getSchema')->willReturn(json_encode([
      'title' => 'Test',
      'sections' => [
        [
          'id' => 'main',
          'title' => 'Main',
          'fields' => [['id' => 'topic', 'type' => 'text', 'label' => 'Topic']],
        ],
        [
          'id' => 'entries',
          'title' => 'Entries',
          'repeatable' => TRUE,
          'fields' => $entry_fields,
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    return $schema_entity;
  }

}
