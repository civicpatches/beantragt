<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Plugin\Validation\Constraint\ValidFormSchemaDefinitionConstraint;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests entity validation of stored form schema definitions.
 *
 * The ValidFormSchemaDefinition entity constraint must surface the same
 * guards that FormSchema::preSave() enforces as exceptions, so JSON:API
 * clients get a 422 with a meaningful detail instead of a 500.
 */
#[CoversClass(FormSchema::class)]
#[Group('beantragt_schema')]
final class FormSchemaValidationConstraintTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'serialization',
    'jsonapi',
    'json_form_widget',
    'beantragt_roles_api',
    'beantragt_schema',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('form_schema');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Broken JSON fails entity validation instead of only exploding in preSave.
   */
  public function testBrokenJsonProducesViolation(): void {
    $schema = $this->createUnsavedSchema('draft', '{"title":"Broken"');

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(['The field_schema value is not valid JSON.'], $messages);
  }

  /**
   * A field without a supported type fails entity validation.
   */
  public function testFieldWithoutTypeProducesViolation(): void {
    $schema = $this->createUnsavedSchema('draft', json_encode([
      'title' => 'Missing field type',
      'sections' => [
        [
          'id' => 'details',
          'title' => 'Details',
          'fields' => [
            ['id' => 'summary', 'label' => 'Summary'],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(['Field &quot;summary&quot; has an unsupported field type.'], $messages);
  }

  /**
   * A sections value that is not a JSON array fails entity validation.
   */
  public function testSectionsAsStringProducesViolation(): void {
    $schema = $this->createUnsavedSchema('draft', json_encode([
      'title' => 'Sections as string',
      'sections' => 'not-a-list',
    ], JSON_THROW_ON_ERROR));

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(['The field_schema sections value must be a JSON array.'], $messages);
  }

  /**
   * Publishing unclassified personal data fails entity validation.
   */
  public function testPublishedUnclassifiedPersonalDataProducesViolation(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Unclassified personal data',
      'sections' => [
        [
          'id' => 'person',
          'title' => 'Person',
          'fields' => [
            ['id' => 'geburtsname', 'type' => 'text', 'label' => 'Geburtsname'],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(
      ['Field &quot;Geburtsname&quot; must have a privacy.kind classification before publishing.'],
      $messages,
    );
  }

  /**
   * A place word alone is no personal data; a place inside an address is.
   */
  public function testPlaceWordIsPersonalOnlyInsideAnAddress(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Dienstreise',
      'sections' => [
        [
          'id' => 'reise',
          'title' => 'Angaben zur Dienstreise',
          'fields' => [
            ['id' => 'reiseziel', 'type' => 'text', 'label' => 'Reiseziel'],
            ['id' => 'hotel_ort', 'type' => 'text', 'label' => 'in (Ort)'],
            ['id' => 'stadt', 'type' => 'text', 'label' => 'Stadt'],
            ['id' => 'ihr_ort', 'type' => 'text', 'label' => 'Ihr Ort'],
            [
              'id' => 'ort_semantik',
              'type' => 'text',
              'label' => 'Ort A',
              'semantics' => ['concept' => 'address.city'],
            ],
            ['id' => 'strasse', 'type' => 'text', 'label' => 'Straße', 'privacy' => ['kind' => 'contact']],
            ['id' => 'hausnummer', 'type' => 'text', 'label' => 'Hausnummer', 'privacy' => ['kind' => 'contact']],
            [
              'id' => 'plz',
              'type' => 'text',
              'label' => 'PLZ',
              'format' => 'postal_code',
              'privacy' => ['kind' => 'contact'],
            ],
            ['id' => 'ort_gruppe', 'type' => 'text', 'label' => 'Ort B'],
          ],
          'addressGroups' => [
            [
              'id' => 'anschrift',
              'fields' => [
                'street' => 'strasse',
                'houseNumber' => 'hausnummer',
                'postcode' => 'plz',
                'city' => 'ort_gruppe',
              ],
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(
      ['Field &quot;Ihr Ort&quot; must have a privacy.kind classification before publishing.'],
      $messages,
    );

    // With "Ihr Ort" classified, the next unclassified address part is named.
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Dienstreise',
      'sections' => [
        [
          'id' => 'reise',
          'title' => 'Angaben zur Dienstreise',
          'fields' => [
            ['id' => 'reiseziel', 'type' => 'text', 'label' => 'Reiseziel'],
            [
              'id' => 'ort_semantik',
              'type' => 'text',
              'label' => 'Ort A',
              'semantics' => ['concept' => 'address.city'],
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    self::assertSame(
      ['Field &quot;Ort A&quot; must have a privacy.kind classification before publishing.'],
      $this->schemaViolationMessages($schema),
    );

    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Dienstreise',
      'sections' => [
        [
          'id' => 'reise',
          'title' => 'Hotel',
          'fields' => [
            ['id' => 'strasse', 'type' => 'text', 'label' => 'Straße', 'privacy' => ['kind' => 'contact']],
            ['id' => 'hausnummer', 'type' => 'text', 'label' => 'Hausnummer', 'privacy' => ['kind' => 'contact']],
            [
              'id' => 'plz',
              'type' => 'text',
              'label' => 'PLZ',
              'format' => 'postal_code',
              'privacy' => ['kind' => 'contact'],
            ],
            ['id' => 'ort_gruppe', 'type' => 'text', 'label' => 'Ort B'],
            ['id' => 'reiseziel', 'type' => 'text', 'label' => 'Reiseziel'],
          ],
          'addressGroups' => [
            [
              'id' => 'anschrift',
              'fields' => [
                'street' => 'strasse',
                'houseNumber' => 'hausnummer',
                'postcode' => 'plz',
                'city' => 'ort_gruppe',
              ],
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    self::assertSame(
      ['Field &quot;Ort B&quot; must have a privacy.kind classification before publishing.'],
      $this->schemaViolationMessages($schema),
    );
  }

  /**
   * Author-controlled labels are parameters, not trusted message templates.
   */
  public function testAuthorControlledLabelIsEscapedAsViolationParameter(): void {
    $label = '<img src=x onerror=alert(1)>';
    $detail = sprintf(
      'Field "%s" must have a privacy.kind classification before publishing.',
      $label,
    );
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Unsafe author label',
      'sections' => [
        [
          'id' => 'person',
          'title' => 'Person',
          'fields' => [
            ['id' => 'geburtsname', 'type' => 'text', 'label' => $label],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    $violations = $this->schemaViolations($schema);

    self::assertCount(1, $violations);
    $violation = $violations[0];
    self::assertInstanceOf(TranslatableMarkup::class, $violation->getMessage());
    self::assertSame('@detail', $violation->getMessageTemplate());
    self::assertSame(['@detail' => $detail], $violation->getParameters());
    self::assertSame(
      'Field &quot;&lt;img src=x onerror=alert(1)&gt;&quot; must have a privacy.kind classification before publishing.',
      (string) $violation->getMessage(),
    );
  }

  /**
   * A published spec-foreign schema without sections is a ghost schema.
   */
  public function testPublishedAlienSchemaWithoutTitleProducesViolation(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'formDefinition' => ['pages' => []],
      'engine' => 'alien',
    ], JSON_THROW_ON_ERROR));

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(['Published form schemas must have a non-empty title.'], $messages);
  }

  /**
   * A published schema without a non-empty sections array is a ghost schema.
   */
  public function testPublishedSchemaWithoutSectionsProducesViolation(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Sectionless ghost',
      'sections' => [],
    ], JSON_THROW_ON_ERROR));

    $messages = $this->schemaViolationMessages($schema);

    self::assertSame(['Published form schemas must have a non-empty sections array.'], $messages);
  }

  /**
   * The ghost schema guard also blocks direct saves in preSave().
   */
  public function testPublishedSchemaWithoutSectionsCannotBeSaved(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'formDefinition' => ['pages' => []],
      'engine' => 'alien',
    ], JSON_THROW_ON_ERROR));

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Published form schemas must have a non-empty title.');
    $schema->save();
  }

  /**
   * A published schema whose sections are all empty is a ghost schema.
   */
  public function testPublishedSchemaWithOnlyEmptySectionsIsRejected(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Empty section ghost',
      'sections' => [
        [
          'id' => 'intro',
          'title' => 'Introduction',
          'fields' => [],
        ],
        [
          'id' => 'details',
          'title' => 'Details',
          'fields' => [],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    self::assertSame(
      ['Published form schemas must contain at least one field.'],
      $this->schemaViolationMessages($schema),
    );

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Published form schemas must contain at least one field.');
    $schema->save();
  }

  /**
   * A published schema without a definition cannot bypass the shared guard.
   */
  public function testPublishedSchemaWithoutDefinitionIsRejected(): void {
    $schema = $this->schemaStorage()->create([
      'label' => 'Missing definition ghost',
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'intranet',
      'status' => TRUE,
    ]);
    assert($schema instanceof FormSchemaInterface);

    self::assertSame(
      ['Published form schemas must contain at least one field.'],
      $this->schemaViolationMessages($schema),
    );

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Published form schemas must contain at least one field.');
    $schema->save();
  }

  /**
   * Empty descriptive sections are allowed beside a populated section.
   */
  public function testPublishedSchemaWithEmptyAndPopulatedSectionsIsValid(): void {
    $schema = $this->createUnsavedSchema('published', json_encode([
      'title' => 'Descriptive introduction',
      'sections' => [
        [
          'id' => 'intro',
          'title' => 'Introduction',
          'fields' => [],
        ],
        [
          'id' => 'details',
          'title' => 'Details',
          'fields' => [
            [
              'id' => 'summary',
              'type' => 'textarea',
              'label' => 'Summary',
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    self::assertSame([], $this->schemaViolationMessages($schema));
    $schema->save();
    self::assertFalse($schema->isNew());
  }

  /**
   * Draft schemas may keep all sections empty while being authored.
   */
  public function testDraftSchemaWithOnlyEmptySectionsIsValid(): void {
    $schema = $this->createUnsavedSchema('draft', json_encode([
      'title' => 'Unfinished draft',
      'sections' => [
        [
          'id' => 'intro',
          'title' => 'Introduction',
          'fields' => [],
        ],
        [
          'id' => 'details',
          'title' => 'Details',
          'fields' => [],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    self::assertSame([], $this->schemaViolationMessages($schema));
    $schema->save();
    self::assertFalse($schema->isNew());
  }

  /**
   * Publishing a stored legacy draft revalidates its unchanged definition.
   */
  public function testLegacyInvalidDraftCannotBePublishedWithoutSchemaChange(): void {
    $schema = $this->createStoredLegacySchema('draft', $this->schemaWithoutFieldType());
    $schema->set('field_workflow_status', 'published');
    $schema->set('status', TRUE);

    self::assertSame(
      ['Field &quot;summary&quot; has an unsupported field type.'],
      $this->schemaViolationMessages($schema),
    );

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Field "summary" has an unsupported field type.');
    $schema->save();
  }

  /**
   * Status-only JSON:API filtering keeps entity-level publish violations.
   */
  public function testStatusOnlyPublishViolationSurvivesJsonApiFieldFilter(): void {
    // The publisher holds the publish permission, so the only violation left
    // is the publish permission constraint on the definition.
    $this->setUpCurrentUser([], ['publish form_schema entities']);
    $schema = $this->createStoredLegacySchema('draft', $this->schemaWithoutFieldType());
    $schema->set('field_workflow_status', 'published');
    $schema->set('status', TRUE);

    $violations = $schema->validate();
    $violations->filterByFields(array_diff(
      array_keys($schema->getFieldDefinitions()),
      ['field_workflow_status', 'status'],
    ));

    self::assertCount(1, $violations);
    self::assertSame('', $violations[0]->getPropertyPath());
    self::assertInstanceOf(
      ValidFormSchemaDefinitionConstraint::class,
      $violations[0]->getConstraint(),
    );
    self::assertSame(
      'Field &quot;summary&quot; has an unsupported field type.',
      (string) $violations[0]->getMessage(),
    );
  }

  /**
   * A stored legacy published definition remains downgradeable for repair.
   */
  public function testLegacyInvalidPublishedSchemaCanBeDowngraded(): void {
    $schema = $this->createStoredLegacySchema('published', $this->schemaWithoutFieldType());
    $schema->set('field_workflow_status', 'draft');
    $schema->set('status', FALSE);

    self::assertSame([], $this->schemaViolationMessages($schema));
    $schema->save();
    self::assertSame('draft', $schema->get('field_workflow_status')->value);
  }

  /**
   * An unchanged legacy UUID root ID does not block unrelated updates.
   */
  public function testUnchangedLegacyUuidRootIdRemainsSavable(): void {
    $definition = json_decode($this->validSchemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $definition['id'] = '4df64a48-2dbb-4710-b451-dccfd30367cf';
    $schema = $this->createStoredLegacySchema(
      'published',
      json_encode($definition, JSON_THROW_ON_ERROR),
    );
    $schema->set('label', 'Legacy UUID label update');

    self::assertSame([], $this->schemaViolationMessages($schema));
    $schema->save();
    self::assertSame('Legacy UUID label update', $schema->label());

    $definition['id'] = 'bed3dadb-e0f7-44fb-9ec9-db76a3022248';
    $schema->set('field_schema', json_encode($definition, JSON_THROW_ON_ERROR));
    self::assertSame(
      ['Schema id must match ^[a-z][a-z0-9_]*$.'],
      $this->schemaViolationMessages($schema),
    );
  }

  /**
   * The editor's entity UUID projection is ignored as client identity noise.
   */
  public function testEntityUuidRootIdProjectionRemainsSavable(): void {
    $definition = json_decode($this->validSchemaJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    $definition['id'] = 'existing_machine_id';
    $schema = $this->createStoredLegacySchema(
      'draft',
      json_encode($definition, JSON_THROW_ON_ERROR),
    );

    $definition['id'] = $schema->uuid();
    $schema->set('field_schema', json_encode($definition, JSON_THROW_ON_ERROR));

    self::assertSame([], $this->schemaViolationMessages($schema));
    $schema->save();
    self::assertFalse($schema->isNew());
  }

  /**
   * A valid schema can be saved as a draft or published.
   */
  #[DataProvider('validSchemaStatusProvider')]
  public function testValidSchemaHasNoViolations(string $status): void {
    $schema = $this->createUnsavedSchema($status, $this->validSchemaJson());

    self::assertSame([], $this->schemaViolationMessages($schema));
    $schema->save();
    self::assertFalse($schema->isNew());
  }

  /**
   * Supplies the status for each case.
   *
   * @return array<string, array{string}>
   *   Named test cases.
   */
  public static function validSchemaStatusProvider(): array {
    return [
      'draft' => ['draft'],
      'published' => ['published'],
    ];
  }

  /**
   * Returns a definition that is valid for draft and published schemas.
   */
  private function validSchemaJson(): string {
    return json_encode([
      'title' => 'Valid constraint fixture',
      'sections' => [
        [
          'id' => 'person',
          'title' => 'Person',
          'fields' => [
            [
              'id' => 'geburtsname',
              'type' => 'text',
              'label' => 'Geburtsname',
              'privacy' => ['kind' => 'identity'],
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Returns an otherwise valid definition whose field has no type.
   */
  private function schemaWithoutFieldType(): string {
    return json_encode([
      'title' => 'Legacy invalid definition',
      'sections' => [
        [
          'id' => 'main',
          'title' => 'Main',
          'fields' => [
            ['id' => 'summary', 'label' => 'Summary'],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Simulates a definition persisted before the current validation contract.
   */
  private function createStoredLegacySchema(string $status, string $schema_json): FormSchemaInterface {
    $schema = $this->createUnsavedSchema($status, $this->validSchemaJson());
    $schema->save();

    $database = $this->container->get('database');
    foreach (['form_schema', 'form_schema_revision'] as $table) {
      $database->update($table)
        ->fields(['field_schema' => $schema_json])
        ->condition('id', $schema->id())
        ->execute();
    }

    $storage = $this->schemaStorage();
    $storage->resetCache([$schema->id()]);
    $legacy_schema = $storage->loadUnchanged($schema->id());
    assert($legacy_schema instanceof FormSchemaInterface);
    return $legacy_schema;
  }

  /**
   * Creates an unsaved schema entity with the given definition.
   */
  private function createUnsavedSchema(string $status, string $schema_json): FormSchemaInterface {
    $schema = $this->schemaStorage()->create([
      'label' => 'Validation constraint test',
      'field_schema' => $schema_json,
      'field_workflow_status' => $status,
      'field_publication_scope' => 'intranet',
      'status' => $status === 'published',
    ]);

    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Returns messages from the form schema definition constraint.
   *
   * @return string[]
   *   Violation messages.
   */
  private function schemaViolationMessages(FormSchemaInterface $schema): array {
    return array_map(
      static fn(ConstraintViolationInterface $violation): string => (string) $violation->getMessage(),
      $this->schemaViolations($schema),
    );
  }

  /**
   * Returns violations from the form schema definition constraint.
   *
   * @return \Symfony\Component\Validator\ConstraintViolationInterface[]
   *   Constraint violations.
   */
  private function schemaViolations(FormSchemaInterface $schema): array {
    return array_values(array_filter(
      iterator_to_array($schema->validate()),
      static fn(ConstraintViolationInterface $violation): bool => $violation->getConstraint() instanceof ValidFormSchemaDefinitionConstraint,
    ));
  }

  /**
   * Returns the form schema entity storage.
   */
  private function schemaStorage(): EntityStorageInterface {
    $entity_type_manager = $this->container->get('entity_type.manager');
    assert($entity_type_manager instanceof EntityTypeManagerInterface);
    return $entity_type_manager->getStorage('form_schema');
  }

}
