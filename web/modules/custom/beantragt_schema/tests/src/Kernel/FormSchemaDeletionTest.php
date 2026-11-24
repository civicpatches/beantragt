<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests form schema deletion invariants against entity storage.
 */
#[CoversClass(FormSchema::class)]
#[Group('beantragt_schema')]
final class FormSchemaDeletionTest extends KernelTestBase {

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
   * Draft schemas remain disposable when nothing references them.
   */
  public function testDraftSchemaCanBeDeleted(): void {
    $schema = $this->createSchema('draft');
    $id = (int) $schema->id();

    $schema->delete();

    self::assertNull($this->schemaStorage()->load($id));
  }

  /**
   * Published and archived schemas are retained even through direct deletion.
   */
  #[DataProvider('nonDraftSchemaStatuses')]
  public function testNonDraftSchemaCannotBeDeleted(string $status): void {
    $schema = $this->createSchema($status);
    $id = (int) $schema->id();

    try {
      $schema->delete();
      self::fail(sprintf('A %s form schema must not be deleted.', $status));
    }
    catch (EntityStorageException $exception) {
      self::assertSame('Only draft form schemas can be deleted.', $exception->getMessage());
    }

    self::assertInstanceOf(FormSchemaInterface::class, $this->schemaStorage()->load($id));
  }

  /**
   * A Drupal-published entity is retained even if its workflow says draft.
   */
  public function testDrupalPublishedDraftCannotBeDeleted(): void {
    $schema = $this->createSchema('draft', TRUE);
    $id = (int) $schema->id();

    try {
      $schema->delete();
      self::fail('A Drupal-published draft form schema must not be deleted.');
    }
    catch (EntityStorageException $exception) {
      self::assertSame('Only draft form schemas can be deleted.', $exception->getMessage());
    }

    self::assertInstanceOf(FormSchemaInterface::class, $this->schemaStorage()->load($id));
  }

  /**
   * A missing workflow value must not inherit the draft fallback on deletion.
   */
  public function testMissingWorkflowStatusCannotBeDeleted(): void {
    $schema = $this->createSchema('draft');
    $id = (int) $schema->id();
    $schema->set('field_workflow_status', []);

    try {
      $schema->delete();
      self::fail('A schema without an explicit draft workflow status must not be deleted.');
    }
    catch (EntityStorageException $exception) {
      self::assertSame('Only draft form schemas can be deleted.', $exception->getMessage());
    }

    self::assertInstanceOf(FormSchemaInterface::class, $this->schemaStorage()->load($id));
  }

  /**
   * Provides statuses that must be retained.
   *
   * @return array<string, array{0: string}>
   *   Non-draft workflow statuses.
   */
  public static function nonDraftSchemaStatuses(): array {
    return [
      'published' => ['published'],
      'archived' => ['archived'],
    ];
  }

  /**
   * Creates a minimally valid form schema with the requested workflow status.
   */
  private function createSchema(string $status, bool $published = FALSE): FormSchemaInterface {
    $schema = $this->schemaStorage()->create([
      'label' => 'Deletion guard test',
      'field_schema' => json_encode([
        'title' => 'Deletion guard test',
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
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => $status,
      'field_publication_scope' => 'intranet',
      'status' => $published,
    ]);
    $schema->save();

    assert($schema instanceof FormSchemaInterface);
    return $schema;
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
