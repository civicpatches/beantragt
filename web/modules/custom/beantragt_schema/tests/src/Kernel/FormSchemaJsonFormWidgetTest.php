<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\Core\Render\Element;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Plugin\Field\FieldWidget\FormSchemaJsonFormWidget;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the form schema JSON widget through Drupal's entity form pipeline.
 */
#[CoversClass(FormSchemaJsonFormWidget::class)]
#[Group('beantragt_schema')]
final class FormSchemaJsonFormWidgetTest extends KernelTestBase {

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

    User::create([
      'uid' => 1,
      'name' => 'us_smoke_admin',
      'status' => 1,
    ])->save();

    EntityFormDisplay::create([
      'targetEntityType' => 'form_schema',
      'bundle' => 'form_schema',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_schema', [
      'type' => 'form_schema_json_form',
      'weight' => 0,
    ])->save();
  }

  /**
   * The rendered backend editor exposes every analysis egress policy label.
   */
  public function testAnalysisEgressPolicyOptionsIncludeExternalRedacted(): void {
    $schema = [
      'id' => 'us_smoke_analysis_options',
      'title' => 'US-SMOKE Analysis options',
      'version' => 1,
      'sections' => [
        [
          'id' => 'attachments',
          'title' => 'Attachments',
          'fields' => [
            [
              'id' => 'nachweis',
              'type' => 'file',
              'label' => 'Nachweis',
            ],
          ],
        ],
      ],
    ];
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('form_schema')
      ->create([
        'label' => 'US-SMOKE Analysis options',
        'uid' => 1,
        'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
        'field_workflow_status' => 'draft',
        'field_publication_scope' => 'internet',
        'status' => FALSE,
      ]);

    $form = $this->container->get('entity.form_builder')->getForm($entity, 'add');
    $controls = $this->elementsWithTitle($form, 'AI egress policy');

    self::assertCount(1, $controls);
    self::assertSame([
      'no_ai' => 'Keine KI-Analyse',
      'local_only' => 'Nur lokal',
      'sovereign_only' => 'Nur souverän',
      'external_redacted' => 'Extern, nur geschwärzt (ohne Einwilligung)',
      'external_allowed' => 'Extern erlaubt (Einwilligung für Sofortcheck)',
    ], array_map(
      static fn(mixed $label): string => (string) $label,
      $controls[0]['#options'],
    ));

    $redaction = $this->elementsWithTitle($form, 'Redaction before the AI check');
    self::assertCount(1, $redaction);
    // The empty option leaves the key unset; the runtime then means none.
    self::assertSame([
      '' => '- None -',
      'none' => 'Aus',
      'when_possible' => 'Wenn möglich (Textebene, keine Scans)',
    ], array_map(
      static fn(mixed $label): string => (string) $label,
      $redaction[0]['#options'],
    ));
    self::assertCount(1, $this->elementsWithTitle($form, 'Upload check instruction'));
    self::assertCount(1, $this->elementsWithTitle($form, 'Staff evaluation instruction'));
  }

  /**
   * Two untouched text fields save without optional synthetic metadata.
   */
  public function testTextFieldsSaveWithoutOptionalPanelValues(): void {
    $schema = [
      'id' => 'us_smoke_widget_kernel',
      'title' => 'US-SMOKE Widget kernel',
      'version' => 1,
      'sections' => [[
        'id' => 'details',
        'title' => 'Details',
        'fields' => [
          ['id' => 'subject', 'type' => 'text', 'label' => 'Subject'],
          ['id' => 'description', 'type' => 'text', 'label' => 'Description'],
        ],
      ],
      ],
    ];
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('form_schema')
      ->create([
        'label' => 'US-SMOKE Widget kernel',
        'uid' => 1,
        'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
        'field_workflow_status' => 'draft',
        'field_publication_scope' => 'internet',
        'status' => FALSE,
      ]);

    $form = $this->container->get('entity.form_builder')->getForm($entity, 'add');
    $function_controls = $this->elementsWithTitle($form, 'Function id');
    $argument_controls = $this->elementsWithTitle($form, 'Arguments');
    self::assertCount(2, $function_controls);
    self::assertCount(2, $argument_controls);
    foreach ([...$function_controls, ...$argument_controls] as $control) {
      self::assertFalse($control['#required'] ?? FALSE);
    }

    $form_object = $this->container->get('entity_type.manager')
      ->getFormObject('form_schema', 'add')
      ->setEntity($entity);
    $form_state = new FormState();
    $form_state->setProgrammedBypassAccessCheck(TRUE);
    $form_state->setValues([
      'op' => 'Save',
      'field_schema' => [[
        'value' => $this->widgetInput($schema),
      ],
      ],
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);

    self::assertFalse($form_state->hasAnyErrors(), json_encode($form_state->getErrors()));
    $saved = $form_object->getEntity();
    self::assertFalse($saved->isNew());
    self::assertInstanceOf(FormSchemaInterface::class, $saved);
    $stored = json_decode($saved->get('field_schema')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertCount(2, $stored['sections'][0]['fields']);
    foreach ($stored['sections'][0]['fields'] as $field) {
      self::assertArrayNotHasKey('computed', $field);
      self::assertArrayNotHasKey('analysis', $field);
      self::assertArrayNotHasKey('widget', $field);
    }

    $saved->set('field_workflow_status', 'published');
    $saved->setPublished();
    $saved->save();
    self::assertSame('published', $saved->get('field_workflow_status')->value);
    self::assertTrue($saved->isPublished());
  }

  /**
   * Returns rendered form elements with the requested title.
   *
   * @return array<int, array>
   *   Matching render arrays.
   */
  private function elementsWithTitle(array $element, string $title): array {
    $matches = [];
    if ((string) ($element['#title'] ?? '') === $title) {
      $matches[] = $element;
    }
    foreach (Element::children($element) as $key) {
      if (is_array($element[$key])) {
        $matches = array_merge(
          $matches,
          $this->elementsWithTitle($element[$key], $title),
        );
      }
    }
    return $matches;
  }

  /**
   * Builds the submitted value structure emitted by json_form_widget.
   */
  private function widgetInput(array $schema): array {
    $fields = [];
    foreach ($schema['sections'][0]['fields'] as $field) {
      $fields[] = [
        'fields' => [
          'id' => $field['id'],
          'type' => $field['type'],
          'label' => $field['label'],
          'analysis' => [
            'analysis' => [
              'version' => 1,
              'egressPolicy' => 'no_ai',
            ],
          ],
          'computed' => ['computed' => ['fn' => '']],
          'widget' => ['widget' => ['boundary' => 'off']],
        ],
      ];
    }
    return [
      'id' => $schema['id'],
      'title' => $schema['title'],
      'version' => $schema['version'],
      'sections' => [
        'sections' => [[
          'sections' => [
            'id' => $schema['sections'][0]['id'],
            'title' => $schema['sections'][0]['title'],
            'fields' => ['fields' => $fields],
          ],
        ],
        ],
      ],
    ];
  }

}
