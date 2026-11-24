<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Form\FormSchemaImportForm;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the import form without the optional beantragt_group module.
 */
#[CoversClass(FormSchemaImportForm::class)]
#[Group('beantragt_schema')]
final class FormSchemaImportStandaloneTest extends KernelTestBase {

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
  }

  /**
   * A standalone installation imports drafts without workspace controls.
   */
  public function testImportWithoutGroupScope(): void {
    $import_form = FormSchemaImportForm::create($this->container);
    $form_state = new FormState();
    $form = $import_form->buildForm([], $form_state);

    self::assertArrayNotHasKey('field_jurisdiction', $form);
    self::assertArrayNotHasKey('field_organisation', $form);
    self::assertFalse($form['actions']['submit']['#disabled']);

    $form_state->setValues([
      'label' => '',
      'schema_json' => json_encode([
        'title' => 'US-SMOKE Standalone import',
        'sections' => [],
      ], JSON_THROW_ON_ERROR),
    ]);
    $import_form->validateForm($form, $form_state);
    self::assertSame([], $form_state->getErrors());
    $import_form->submitForm($form, $form_state);

    $ids = $this->container->get('entity_type.manager')
      ->getStorage('form_schema')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    self::assertCount(1, $ids);
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('form_schema')
      ->load(reset($ids));
    self::assertInstanceOf(FormSchemaInterface::class, $entity);
    self::assertSame('draft', $entity->getSchemaStatus());
    self::assertFalse($entity->isPublished());
  }

}
