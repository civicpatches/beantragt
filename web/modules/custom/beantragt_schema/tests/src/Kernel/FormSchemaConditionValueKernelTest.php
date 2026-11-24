<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A field condition with an empty value is refused when a save brings it.
 *
 * The cases cover JSON:API requests through the
 * HTTP kernel, as the Nuxt proxy sends them; the programmatic save of the
 * import and seed paths; a stored empty value carried along stays editable.
 */
#[Group('beantragt_schema')]
final class FormSchemaConditionValueKernelTest extends KernelTestBase {

  use UserCreationTrait;

  private const PASSWORD = 'synthetic-condition-password';

  private const TOKEN = '[schema.condition_value_empty pointer=/sections/0/fields/1/conditionalOn/value field=detail]';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'serialization',
    'basic_auth',
    'jsonapi',
    'json_form_widget',
    'beantragt_roles_api',
    'beantragt_schema',
  ];

  /**
   * The editor account.
   */
  private UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'jsonapi']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('form_schema');
    $this->installSchema('file', ['file_usage']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    // Burn uid 1: it bypasses every permission check.
    $this->createUser([], 'root');
    $role = Role::create(['id' => 'form_editor', 'label' => 'form_editor']);
    foreach ([
      'create form_schema entities',
      'edit any form_schema entities',
      'view published form_schema entities',
      'view unpublished form_schema entities',
    ] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $editor = $this->createUser([], 'editor');
    $editor->addRole('form_editor');
    $editor->setPassword(self::PASSWORD);
    $editor->save();
    $this->editor = $editor;
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * POST and PATCH with an empty or blank value answer 422 with the token.
   */
  public function testJsonApiRefusesAnEmptyConditionValue(): void {
    foreach (['', '   '] as $value) {
      $response = $this->request('POST', '/jsonapi/form_schema/form_schema', [
        'data' => [
          'type' => 'form_schema--form_schema',
          'attributes' => [
            'label' => 'Neutral form',
            'field_schema' => $this->schemaJson(['fieldId' => 'topic', 'value' => $value]),
            'field_workflow_status' => 'draft',
          ],
        ],
      ]);
      $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
      $error = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['errors'][0];
      $this->assertSame(
        'Entity is not valid: A field condition needs a value; to show the field for any answer, leave the value out. ' . self::TOKEN,
        $error['detail'],
      );
      $this->assertSame('/data', $error['source']['pointer']);
    }
    $this->assertSame(0, $this->countForms());

    // "Any answer" (no value) and a real value save.
    foreach ([['fieldId' => 'topic'], ['fieldId' => 'topic', 'value' => 'a']] as $condition) {
      $response = $this->request('POST', '/jsonapi/form_schema/form_schema', [
        'data' => [
          'type' => 'form_schema--form_schema',
          'attributes' => [
            'label' => 'Neutral form',
            'field_schema' => $this->schemaJson($condition),
            'field_workflow_status' => 'draft',
          ],
        ],
      ]);
      $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    // A PATCH that brings the empty value in is refused as well.
    $form = $this->storage()->loadByProperties(['label' => 'Neutral form']);
    $form = reset($form);
    assert($form instanceof FormSchemaInterface);
    $response = $this->request('PATCH', '/jsonapi/form_schema/form_schema/' . $form->uuid(), [
      'data' => [
        'type' => 'form_schema--form_schema',
        'id' => $form->uuid(),
        'attributes' => ['field_schema' => $this->schemaJson(['fieldId' => 'topic', 'value' => ''])],
      ],
    ]);
    $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $detail = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['errors'][0]['detail'];
    $this->assertStringEndsWith(self::TOKEN, $detail);
  }

  /**
   * A programmatic save (import, seed, compile result) is refused too.
   */
  public function testProgrammaticSaveRefusesAnEmptyConditionValue(): void {
    $form = $this->storage()->create([
      'label' => 'Neutral form',
      'field_schema' => $this->schemaJson(['fieldId' => 'topic', 'value' => '']),
      'field_workflow_status' => 'draft',
    ]);
    try {
      $form->save();
      $this->fail('An empty condition value was saved.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString(self::TOKEN, $exception->getMessage());
    }
    $this->assertSame(0, $this->countForms());
  }

  /**
   * A stored empty value carried along unchanged does not block an edit.
   *
   * Existing definitions are not rewritten. Changing that condition, or
   * adding another one with an empty value, is refused.
   */
  public function testStoredEmptyValueStaysEditable(): void {
    $form = $this->storage()->create([
      'label' => 'Neutral form',
      'field_schema' => $this->schemaJson(['fieldId' => 'topic']),
      'field_workflow_status' => 'draft',
    ]);
    $form->save();
    // Written past save validation to exercise stored definitions.
    $legacy = $this->schemaJson(['fieldId' => 'topic', 'value' => '']);
    $database = $this->container->get('database');
    $database->update('form_schema')->fields(['field_schema' => $legacy])->condition('id', $form->id())->execute();
    $database->update('form_schema_revision')->fields(['field_schema' => $legacy])->condition('id', $form->id())->execute();
    $this->storage()->resetCache([$form->id()]);

    // Another field's label changes: saves, the stored condition stays.
    $schema = json_decode($legacy, TRUE);
    $schema['sections'][0]['fields'][0]['label'] = 'Topic, renamed';
    $form = $this->storage()->loadUnchanged($form->id());
    assert($form instanceof FormSchemaInterface);
    $form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR));
    $this->assertCount(0, $form->validate());
    $form->save();
    $stored = json_decode((string) $this->storage()->loadUnchanged($form->id())->get('field_schema')->value, TRUE);
    $this->assertSame(['fieldId' => 'topic', 'value' => ''], $stored['sections'][0]['fields'][1]['conditionalOn']);

    // Changing that condition to another empty spelling is refused.
    $schema['sections'][0]['fields'][1]['conditionalOn']['value'] = ' ';
    $form = $this->storage()->loadUnchanged($form->id());
    $form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR));
    $violations = $form->validate();
    $this->assertCount(1, $violations);
    $this->assertStringContainsString(self::TOKEN, (string) $violations[0]->getMessage());
  }

  /**
   * Sends one JSON:API request as the editor through the HTTP kernel.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Request path.
   * @param array<string, mixed> $body
   *   Request document.
   */
  private function request(string $method, string $path, array $body): Response {
    $request = Request::create($path, $method, server: [
      'HTTP_ACCEPT' => 'application/vnd.api+json',
      'CONTENT_TYPE' => 'application/vnd.api+json',
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($this->editor->getAccountName() . ':' . self::PASSWORD),
      'PHP_AUTH_USER' => $this->editor->getAccountName(),
      'PHP_AUTH_PW' => self::PASSWORD,
    ], content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $this->container->get('http_kernel')->handle($request);
    // Kernel requests share one container; reset the account between them.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    return $response;
  }

  /**
   * A definition whose second field carries the given condition.
   *
   * @param array<string, mixed> $condition
   *   The conditionalOn of the field "detail".
   */
  private function schemaJson(array $condition): string {
    return json_encode([
      'title' => 'Neutral form',
      'sections' => [[
        'id' => 'main',
        'title' => 'Main',
        'fields' => [
          ['id' => 'topic', 'type' => 'text', 'label' => 'Topic'],
          ['id' => 'detail', 'type' => 'text', 'label' => 'Detail', 'conditionalOn' => $condition],
        ],
      ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * The form storage.
   */
  private function storage(): EntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('form_schema');
  }

  /**
   * Counts stored forms.
   */
  private function countForms(): int {
    return (int) $this->storage()->getQuery()->accessCheck(FALSE)->count()->execute();
  }

}
