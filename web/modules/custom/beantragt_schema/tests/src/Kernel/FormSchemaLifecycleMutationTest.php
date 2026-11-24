<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests atomic form schema lifecycle mutation guards.
 */
#[CoversClass(FormSchema::class)]
#[Group('beantragt_schema')]
final class FormSchemaLifecycleMutationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'field',
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

    FieldStorageConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'form_schema',
      'type' => 'integer',
      'cardinality' => 1,
      'translatable' => FALSE,
      'settings' => [],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_jurisdiction',
      'entity_type' => 'form_schema',
      'bundle' => 'form_schema',
      'label' => 'Jurisdiction',
    ])->save();
  }

  /**
   * Published workflows remain immutable through direct entity saves.
   */
  public function testPublishedWorkflowDefinitionCannotChange(): void {
    $schema = $this->createSchema('published');
    $schema->set('field_workflow_definition', $this->workflowDefinition('in_review'));

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Only draft form schemas can change schema or workflow definitions.');
    $schema->save();
  }

  /**
   * A stale workflow edit cannot overwrite a concurrent publication.
   */
  public function testStaleWorkflowEditCannotOverwritePublication(): void {
    $schema = $this->createSchema('draft');
    $storage = $this->schemaStorage();
    $stale_workflow_editor = $storage->loadUnchanged($schema->id());
    $publisher = $storage->loadUnchanged($schema->id());
    assert($stale_workflow_editor instanceof FormSchemaInterface);
    assert($publisher instanceof FormSchemaInterface);

    $publisher->set('field_workflow_status', 'published');
    $publisher->setPublished();
    $publisher->save();

    $stale_workflow_editor->set('field_workflow_definition', $this->workflowDefinition('in_review'));

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Form schema was modified concurrently. Reload before saving.');
    $stale_workflow_editor->save();
  }

  /**
   * A stale publication cannot overwrite a concurrent workflow edit.
   */
  public function testStalePublicationCannotOverwriteWorkflowEdit(): void {
    $schema = $this->createSchema('draft');
    $storage = $this->schemaStorage();
    $stale_publisher = $storage->loadUnchanged($schema->id());
    $workflow_editor = $storage->loadUnchanged($schema->id());
    assert($stale_publisher instanceof FormSchemaInterface);
    assert($workflow_editor instanceof FormSchemaInterface);

    $workflow_editor->set('field_workflow_definition', $this->workflowDefinition('in_review'));
    $workflow_editor->save();

    $stale_publisher->set('field_workflow_status', 'published');
    $stale_publisher->setPublished();

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Form schema was modified concurrently. Reload before saving.');
    $stale_publisher->save();
  }

  /**
   * A stale workspace re-home cannot overwrite a concurrent re-home.
   */
  public function testStaleWorkspaceMutationCannotOverwriteConcurrentMutation(): void {
    $schema = $this->createSchema('draft');
    $storage = $this->schemaStorage();
    $stale_editor = $storage->loadUnchanged($schema->id());
    $concurrent_editor = $storage->loadUnchanged($schema->id());
    assert($stale_editor instanceof FormSchemaInterface);
    assert($concurrent_editor instanceof FormSchemaInterface);

    $concurrent_editor->set('field_jurisdiction', 12);
    $concurrent_editor->save();

    $stale_editor->set('field_jurisdiction', 34);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Form schema was modified concurrently. Reload before saving.');
    $stale_editor->save();
  }

  /**
   * A stale metadata edit cannot restore lifecycle fields from an old snapshot.
   */
  public function testStaleMetadataEditCannotOverwritePublication(): void {
    $schema = $this->createSchema('draft');
    $storage = $this->schemaStorage();
    $stale_metadata_editor = $storage->loadUnchanged($schema->id());
    $publisher = $storage->loadUnchanged($schema->id());
    assert($stale_metadata_editor instanceof FormSchemaInterface);
    assert($publisher instanceof FormSchemaInterface);

    $publisher->set('field_workflow_status', 'published');
    $publisher->setPublished();
    $publisher->save();

    $stale_metadata_editor->set('label', 'Stale metadata title');

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Form schema was modified concurrently. Reload before saving.');
    $stale_metadata_editor->save();
  }

  /**
   * Creates a minimally valid schema.
   */
  private function createSchema(string $status): FormSchemaInterface {
    $schema = $this->schemaStorage()->create([
      'label' => 'Lifecycle mutation guard test',
      'field_schema' => json_encode([
        'title' => 'Lifecycle mutation guard test',
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
      'field_workflow_definition' => $this->workflowDefinition('new'),
      'field_workflow_status' => $status,
      'field_publication_scope' => 'intranet',
      'status' => $status === 'published',
    ]);
    $schema->save();

    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Returns a valid workflow definition with a distinguishable description.
   */
  private function workflowDefinition(string $description): string {
    return json_encode([
      'mode' => 'catalog',
      'defaultStatus' => 'new',
      'statuses' => [
        [
          'machineName' => 'new',
          'description' => $description,
        ],
      ],
      'transitions' => [],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Returns form schema storage.
   */
  private function schemaStorage(): EntityStorageInterface {
    $entity_type_manager = $this->container->get('entity_type.manager');
    assert($entity_type_manager instanceof EntityTypeManagerInterface);
    return $entity_type_manager->getStorage('form_schema');
  }

}
