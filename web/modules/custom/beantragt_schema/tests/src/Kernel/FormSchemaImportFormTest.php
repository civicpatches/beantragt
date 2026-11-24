<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Form\FormSchemaImportForm;
use Drupal\Core\Form\FormState;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests pasted JSON imports through the admin form handler.
 */
#[CoversClass(FormSchemaImportForm::class)]
#[Group('beantragt_schema')]
final class FormSchemaImportFormTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_roles_api',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * Broken JSON produces a form error and never creates an entity.
   */
  public function testBrokenJsonProducesErrorWithoutEntity(): void {
    [$account, $jurisdiction] = $this->createScopedAccount();
    $before = $this->schemaCount();
    $form_state = $this->validateImport([
      'schema_json' => '{"title":"Broken"',
      'field_jurisdiction' => $jurisdiction->id(),
    ]);

    self::assertNotEmpty($form_state->getErrors());
    $errors = $form_state->getErrors();
    self::assertStringContainsString('Syntax error', (string) reset($errors));
    self::assertSame($before, $this->schemaCount());
    self::assertSame($account->id(), $this->getCurrentUser()->id());
  }

  /**
   * JSON that violates the shipped specification is not imported.
   */
  public function testSpecificationViolationProducesErrorWithoutEntity(): void {
    [, $jurisdiction] = $this->createScopedAccount();
    $before = $this->schemaCount();
    $form_state = $this->validateImport([
      'schema_json' => json_encode([
        'title' => 'US-SMOKE Wrong version type',
        'version' => 'one',
      ], JSON_THROW_ON_ERROR),
      'field_jurisdiction' => $jurisdiction->id(),
    ]);

    $errors = implode(' ', array_map('strval', $form_state->getErrors()));
    self::assertStringContainsString('field_schema version must be an integer', $errors);
    self::assertSame($before, $this->schemaCount());
  }

  /**
   * Known typed properties must follow the bundled FormSchema specification.
   */
  public function testUnsupportedFieldFormatProducesErrorWithoutEntity(): void {
    [, $jurisdiction] = $this->createScopedAccount();
    $before = $this->schemaCount();
    $schema = json_decode($this->validJson('US-SMOKE Invalid field format'), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema['sections'][0]['fields'][0]['format'] = 'definitely_not_in_spec';
    $form_state = $this->validateImport([
      'schema_json' => json_encode($schema, JSON_THROW_ON_ERROR),
      'field_jurisdiction' => $jurisdiction->id(),
    ]);

    $errors = implode(' ', array_map('strval', $form_state->getErrors()));
    self::assertStringContainsString('Field &quot;summary&quot; has an unsupported format.', $errors);
    self::assertSame($before, $this->schemaCount());
  }

  /**
   * Empty objects allowed by the bundled specification remain valid.
   */
  public function testEmptySpecificationObjectsAreAccepted(): void {
    [, $jurisdiction] = $this->createScopedAccount();
    $schema = json_decode($this->validJson('US-SMOKE Empty specification objects'), FALSE, 512, JSON_THROW_ON_ERROR);
    $schema->i18n = (object) [];
    $schema->semantics = (object) [];
    $schema->source = (object) [];
    $schema->sections[0]->i18n = (object) ['de' => (object) []];
    $schema->sections[0]->fields[0]->i18n = (object) [];
    $form_state = $this->validateImport([
      'schema_json' => json_encode($schema, JSON_THROW_ON_ERROR),
      'field_jurisdiction' => $jurisdiction->id(),
    ]);

    self::assertSame([], $form_state->getErrors());
  }

  /**
   * An alien draft imports, while the normal publish guard still rejects it.
   */
  public function testAlienDslImportsAsDraftButCannotPublish(): void {
    [, $jurisdiction] = $this->createScopedAccount();
    $form_state = $this->validateImport([
      'schema_json' => json_encode([
        'title' => 'US-SMOKE Alien draft',
        'formDefinition' => ['pages' => []],
        'engine' => 'alien',
      ], JSON_THROW_ON_ERROR),
      'field_jurisdiction' => $jurisdiction->id(),
    ]);

    self::assertSame([], $form_state->getErrors());
    $form = [];
    $this->importForm()->submitForm($form, $form_state);
    $schema = $this->loadOnlySchema();
    self::assertSame('draft', $schema->getSchemaStatus());
    self::assertFalse($schema->isPublished());

    $schema->setSchemaStatus('published');
    $schema->setPublished();
    $messages = [];
    foreach ($schema->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    self::assertContains('Published form schemas must have a non-empty sections array.', $messages);
  }

  /**
   * Valid JSON creates a correctly owned, workspace-scoped draft.
   */
  public function testValidJsonCreatesWorkspaceScopedOwnedDraft(): void {
    [$account, $jurisdiction, $organisation] = $this->createScopedAccount();
    $form_state = $this->validateImport([
      'schema_json' => $this->validJson('US-SMOKE Imported schema'),
      'field_jurisdiction' => $jurisdiction->id(),
      'field_organisation' => $organisation->id(),
    ]);

    self::assertSame([], $form_state->getErrors());
    $form = [];
    $this->importForm()->submitForm($form, $form_state);
    $schema = $this->loadOnlySchema();

    self::assertSame('US-SMOKE Imported schema', $schema->label());
    self::assertSame('draft', $schema->getSchemaStatus());
    self::assertFalse($schema->isPublished());
    self::assertSame((int) $account->id(), (int) $schema->getOwnerId());
    self::assertSame((int) $jurisdiction->id(), (int) $schema->get('field_jurisdiction')->target_id);
    self::assertSame((int) $organisation->id(), (int) $schema->get('field_organisation')->target_id);
  }

  /**
   * A department administrator cannot import into a foreign jurisdiction.
   */
  public function testForeignJurisdictionProducesFormError(): void {
    [, , , $foreign_jurisdiction] = $this->createScopedAccount();
    $before = $this->schemaCount();
    $form_state = $this->validateImport([
      'schema_json' => $this->validJson('US-SMOKE Foreign import'),
      'field_jurisdiction' => $foreign_jurisdiction->id(),
    ]);

    $errors = array_map('strval', $form_state->getErrors());
    self::assertContains('The selected workspace could not be found.', $errors);
    self::assertSame($before, $this->schemaCount());
  }

  /**
   * An empty jurisdiction produces a required error, not an invalid target.
   */
  public function testMissingJurisdictionProducesRequiredError(): void {
    $this->createScopedAccount();
    $form_state = $this->validateImport([
      'schema_json' => $this->validJson('US-SMOKE Missing jurisdiction'),
      'field_jurisdiction' => '',
    ]);

    self::assertSame(
      ['field_jurisdiction' => 'Select a jurisdiction workspace.'],
      array_map('strval', $form_state->getErrors()),
    );
  }

  /**
   * Organisation-only admins must select their manageable organisation.
   */
  public function testOrganisationAdminGetsConsistentRequiredSelection(): void {
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'US-SMOKE Org-only parent',
      'field_slug' => 'us-smoke-org-only-parent',
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Org-only organisation',
      'field_slug' => 'us-smoke-org-only-organisation',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $account = $this->createUser(['create form_schema entities']);
    $organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($account);

    $form = $this->importForm()->buildForm([], new FormState());
    self::assertArrayHasKey('required', $form['field_organisation']['#states']);
    self::assertSame([(int) $jurisdiction->id()], array_keys($form['field_jurisdiction']['#options']));
    self::assertSame([(int) $organisation->id()], array_keys($form['field_organisation']['#options']));

    $missing_organisation = $this->validateImport([
      'schema_json' => $this->validJson('US-SMOKE Org-only missing organisation'),
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    self::assertSame(
      ['field_organisation' => 'Select an organisation for this jurisdiction workspace.'],
      array_map('strval', $missing_organisation->getErrors()),
    );

    $valid_selection = $this->validateImport([
      'schema_json' => $this->validJson('US-SMOKE Org-only valid selection'),
      'field_jurisdiction' => $jurisdiction->id(),
      'field_organisation' => $organisation->id(),
    ]);
    self::assertSame([], $valid_selection->getErrors());
  }

  /**
   * Creates a scoped account plus own and foreign workspaces.
   *
   * @return array{0: \Drupal\user\UserInterface, 1: \Drupal\group\Entity\GroupInterface, 2: \Drupal\group\Entity\GroupInterface, 3: \Drupal\group\Entity\GroupInterface}
   *   Account, own jurisdiction, own organisation, and foreign jurisdiction.
   */
  private function createScopedAccount(): array {
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'US-SMOKE Import jurisdiction',
      'field_slug' => 'us-smoke-import-jur',
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Import organisation',
      'field_slug' => 'us-smoke-import-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $foreign_jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'US-SMOKE Foreign jurisdiction',
      'field_slug' => 'us-smoke-foreign-jur',
    ]);
    $account = $this->createUser(['create form_schema entities']);
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($account);

    return [$account, $jurisdiction, $organisation, $foreign_jurisdiction];
  }

  /**
   * Runs admin import validation for submitted values.
   */
  private function validateImport(array $values): FormState {
    $form_state = new FormState();
    $form_state->setValues($values + [
      'label' => '',
      'field_organisation' => '',
    ]);
    $form = [];
    $this->importForm()->validateForm($form, $form_state);
    return $form_state;
  }

  /**
   * Returns a valid draft FormSchema JSON string.
   */
  private function validJson(string $title): string {
    return json_encode([
      'title' => $title,
      'sections' => [
        [
          'id' => 'details',
          'title' => 'Details',
          'fields' => [
            [
              'id' => 'summary',
              'type' => 'textarea',
              'label' => 'Summary',
              'required' => FALSE,
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Creates the form from the service container.
   */
  private function importForm(): FormSchemaImportForm {
    return FormSchemaImportForm::create($this->container);
  }

  /**
   * Returns the number of stored form schemas.
   */
  private function schemaCount(): int {
    return (int) $this->entityTypeManager()->getStorage('form_schema')->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Loads the only stored form schema.
   */
  private function loadOnlySchema(): FormSchemaInterface {
    $ids = $this->entityTypeManager()->getStorage('form_schema')->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    self::assertCount(1, $ids);
    $schema = $this->entityTypeManager()->getStorage('form_schema')->load(reset($ids));
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

}
