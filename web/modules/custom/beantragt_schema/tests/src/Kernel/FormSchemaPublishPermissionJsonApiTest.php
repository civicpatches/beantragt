<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Plugin\Validation\Constraint\FormSchemaPublishPermissionConstraintValidator;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real JSON:API requests against the publish permission.
 *
 * Every way a form becomes more visible through JSON:API: POST of a live
 * form, PATCH of only the workflow status, PATCH of only the published flag
 * and PATCH of only the publication scope. The service account and an editor
 * without the permission get 422, a department admin passes.
 */
#[CoversClass(FormSchemaPublishPermissionConstraintValidator::class)]
#[Group('beantragt_schema')]
final class FormSchemaPublishPermissionJsonApiTest extends KernelTestBase {

  use UserCreationTrait;

  private const PASSWORD = 'synthetic-jsonapi-password';

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
   * Accounts by persona.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  private array $accounts = [];

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

    // Grants as in beantragt_application.install and beantragt_roles.install.
    $roles = [
      'service_account' => [
        'create form_schema entities',
        'edit any form_schema entities',
        'view published form_schema entities',
        'view unpublished form_schema entities',
        'record form setup recommendations',
      ],
      'form_editor' => [
        'create form_schema entities',
        'edit any form_schema entities',
        'view published form_schema entities',
        'view unpublished form_schema entities',
      ],
      'department_admin' => [
        'create form_schema entities',
        'edit any form_schema entities',
        'view published form_schema entities',
        'view unpublished form_schema entities',
        'administer form_schema entities',
        'publish form_schema entities',
      ],
    ];
    foreach ($roles as $role_id => $permissions) {
      $role = Role::create(['id' => $role_id, 'label' => $role_id]);
      foreach ($permissions as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
      $account = $this->createUser([], $role_id . '_user');
      $account->addRole($role_id);
      $account->setPassword(self::PASSWORD);
      $account->save();
      $this->accounts[$role_id] = $account;
    }

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Exposes compiler review status in list and detail across the lifecycle.
   */
  public function testAiReviewStatusSurvivesEditsUntilPublication(): void {
    $editor = $this->accounts['form_editor'];
    $publisher = $this->accounts['department_admin'];
    $form = $this->storedForm('draft', FALSE, 'intranet', 'text');
    $manual = $this->storedForm('draft', FALSE, 'intranet');
    $base = '/jsonapi/form_schema/form_schema';
    $sparse = '?fields[form_schema--form_schema]=field_ai_review_pending';
    $list = $this->request($publisher, 'GET', $base . $sparse, []);
    self::assertSame(200, $list->getStatusCode(), (string) $list->getContent());
    $rows = json_decode((string) $list->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data'];
    $by_id = array_column($rows, 'attributes', 'id');
    self::assertSame(['field_ai_review_pending' => TRUE], $by_id[$form->uuid()]);
    self::assertSame(['field_ai_review_pending' => FALSE], $by_id[$manual->uuid()]);
    self::assertSame(403, $this->patch($editor, $form, ['field_ai_review_pending' => FALSE])->getStatusCode());

    // Mutable provenance fields cannot retroactively change review status.
    $edit = $this->patch($editor, $form, [
      'label' => 'Umbenannter Entwurf',
      'field_source_filename' => '',
      'field_schema' => $this->schemaJson(),
    ]);
    self::assertSame(200, $edit->getStatusCode(), (string) $edit->getContent());
    $form = $this->reload($form);
    self::assertTrue((bool) $form->get('field_ai_review_pending')->value);
    $denied = $this->patch($editor, $form, [
      'field_workflow_status' => 'published',
      'status' => TRUE,
    ]);
    self::assertSame(422, $denied->getStatusCode());
    self::assertTrue((bool) $this->reload($form)->get('field_ai_review_pending')->value);

    $not_live = $this->patch($editor, $form, ['field_workflow_status' => 'published']);
    self::assertSame(200, $not_live->getStatusCode());
    $form = $this->reload($form);
    $stale = clone $form;
    self::assertTrue((bool) $stale->get('field_ai_review_pending')->value);
    $archived = $this->patch($publisher, $form, ['field_workflow_status' => 'archived']);
    self::assertSame(200, $archived->getStatusCode(), (string) $archived->getContent());
    $form = $this->reload($form);
    self::assertNotSame($form->getRevisionId(), $stale->getRevisionId());
    self::assertTrue((bool) $form->get('field_ai_review_pending')->value);
    try {
      $stale->set('field_workflow_status', 'published')->setPublished()->save();
      self::fail('A stale revision must not be published.');
    }
    catch (EntityStorageException $exception) {
      self::assertStringContainsString('Form schema was modified concurrently.', $exception->getMessage());
      self::assertTrue((bool) $this->reload($form)->get('field_ai_review_pending')->value);
    }

    $published = $this->patch($publisher, $form, ['field_workflow_status' => 'published', 'status' => TRUE]);
    self::assertSame(200, $published->getStatusCode(), (string) $published->getContent());
    self::assertFalse((bool) $this->reload($form)->get('field_ai_review_pending')->value);
    $reopened = $this->patch($publisher, $this->reload($form), [
      'field_workflow_status' => 'draft',
      'status' => FALSE,
    ]);
    self::assertSame(200, $reopened->getStatusCode());
    $detail = $this->request($publisher, 'GET', $base . '/' . $form->uuid() . $sparse, []);
    self::assertSame(200, $detail->getStatusCode(), (string) $detail->getContent());
    self::assertSame(['field_ai_review_pending' => FALSE], json_decode((string) $detail->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data']['attributes']);
  }

  /**
   * Backfills legacy data from the first revision and publication history.
   */
  public function testAiReviewStatusBackfillsFromHistory(): void {
    $pending = $this->storedForm('draft', FALSE, 'intranet', 'pdf');
    $reviewed = $this->storedForm('published', TRUE, 'intranet', 'image');
    $reviewed->set('field_workflow_status', 'draft')->set('status', FALSE)->save();
    $not_live = $this->storedForm('published', FALSE, 'intranet', 'text');
    $manual = $this->storedForm('draft', FALSE, 'intranet');
    $manual->set('field_schema', $this->schemaJson('text'))->set('field_source_filename', 'late.txt')->save();

    $database = $this->container->get('database');
    $database->update('form_schema')->fields(['field_ai_review_pending' => 0])->execute();
    $this->container->get('module_handler')->loadInclude('beantragt_schema', 'install');
    $sandbox = [];
    beantragt_schema_update_10012($sandbox);
    self::assertSame(1, $sandbox['#finished']);
    self::assertTrue((bool) $this->reload($pending)->get('field_ai_review_pending')->value);
    self::assertFalse((bool) $this->reload($reviewed)->get('field_ai_review_pending')->value);
    self::assertTrue((bool) $this->reload($not_live)->get('field_ai_review_pending')->value);
    self::assertFalse((bool) $this->reload($manual)->get('field_ai_review_pending')->value);
  }

  /**
   * Accounts without the permission are refused on all four paths.
   */
  public function testAccountsWithoutPermissionGet422OnEveryPath(): void {
    foreach (['service_account', 'form_editor'] as $persona) {
      $account = $this->accounts[$persona];
      foreach ($this->paths() as $path => $request) {
        [$status, $form] = $request($account);
        self::assertSame(422, $status['code'], "$persona: $path: " . $status['body']);
        self::assertStringContainsString('publish form_schema entities', $status['body'], "$persona: $path");
        if ($form instanceof FormSchemaInterface) {
          $stored = $this->reload($form);
          self::assertSame(
            $status['levelBefore'],
            FormSchemaPublishPermissionConstraintValidator::visibilityLevel($stored),
            "$persona: $path keeps the stored level",
          );
        }
      }
    }
    self::assertSame(0, $this->countLiveForms(), 'No refused POST created a form.');
  }

  /**
   * A department admin passes the same four paths.
   */
  public function testDepartmentAdminPublishesOnEveryPath(): void {
    $account = $this->accounts['department_admin'];
    foreach ($this->paths() as $path => $request) {
      [$status, $form] = $request($account);
      self::assertSame($path === 'POST live form' ? 201 : 200, $status['code'], "$path: " . $status['body']);
      if ($form instanceof FormSchemaInterface) {
        self::assertGreaterThan(
          $status['levelBefore'],
          FormSchemaPublishPermissionConstraintValidator::visibilityLevel($this->reload($form)),
          "$path raised the level",
        );
      }
    }
  }

  /**
   * The four JSON:API paths that raise visibility.
   *
   * Each callable returns the response status and body, the level before the
   * request and the stored form (NULL for the POST).
   *
   * @return array<string, callable(\Drupal\user\UserInterface): array{0: array{code: int, body: string, levelBefore: int}, 1: \Drupal\beantragt_schema\Entity\FormSchemaInterface|null}>
   *   Named requests.
   */
  private function paths(): array {
    return [
      'POST live form' => function (UserInterface $account): array {
        $response = $this->request($account, 'POST', '/jsonapi/form_schema/form_schema', [
          'data' => [
            'type' => 'form_schema--form_schema',
            'attributes' => [
              'label' => 'Hundesteuer',
              'field_schema' => $this->schemaJson(),
              'field_workflow_status' => 'published',
              'field_publication_scope' => 'internet',
              'status' => TRUE,
            ],
          ],
        ]);
        return [$this->summary($response, 0), NULL];
      },
      'PATCH workflow status only' => function (UserInterface $account): array {
        $form = $this->storedForm('draft', TRUE, 'internet');
        $response = $this->patch($account, $form, ['field_workflow_status' => 'published']);
        return [$this->summary($response, 0), $form];
      },
      'PATCH published flag only' => function (UserInterface $account): array {
        $form = $this->storedForm('published', FALSE, 'internet');
        $response = $this->patch($account, $form, ['status' => TRUE]);
        return [$this->summary($response, 0), $form];
      },
      'PATCH publication scope only' => function (UserInterface $account): array {
        $form = $this->storedForm('published', TRUE, 'intranet');
        $response = $this->patch($account, $form, ['field_publication_scope' => 'internet']);
        return [$this->summary($response, 1), $form];
      },
    ];
  }

  /**
   * Sends a JSON:API PATCH with only the given attributes.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed> $attributes
   *   Attributes.
   */
  private function patch(UserInterface $account, FormSchemaInterface $form, array $attributes): Response {
    return $this->request($account, 'PATCH', '/jsonapi/form_schema/form_schema/' . $form->uuid(), [
      'data' => [
        'type' => 'form_schema--form_schema',
        'id' => $form->uuid(),
        'attributes' => $attributes,
      ],
    ]);
  }

  /**
   * Sends one JSON:API request with Basic auth through the HTTP kernel.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Request path.
   * @param array<string, mixed> $body
   *   Request document.
   */
  private function request(UserInterface $account, string $method, string $path, array $body): Response {
    $request = Request::create($path, $method, server: [
      'HTTP_ACCEPT' => 'application/vnd.api+json',
      'CONTENT_TYPE' => 'application/vnd.api+json',
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($account->getAccountName() . ':' . self::PASSWORD),
      'PHP_AUTH_USER' => $account->getAccountName(),
      'PHP_AUTH_PW' => self::PASSWORD,
    ], content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $this->container->get('http_kernel')->handle($request);
    // Kernel requests share one container; reset the account between them.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    return $response;
  }

  /**
   * Status summary of a response.
   *
   * @return array{code: int, body: string, levelBefore: int}
   *   Status code, body and the level before the request.
   */
  private function summary(Response $response, int $level_before): array {
    return [
      'code' => $response->getStatusCode(),
      'body' => (string) $response->getContent(),
      'levelBefore' => $level_before,
    ];
  }

  /**
   * Saves a form programmatically in the given state, like seed tooling.
   */
  private function storedForm(string $workflow, bool $flag, string $scope, ?string $source = NULL): FormSchemaInterface {
    $form = $this->container->get('entity_type.manager')->getStorage('form_schema')->create([
      'label' => 'Hundesteuer',
      'field_schema' => $this->schemaJson($source),
      'field_source_filename' => $source === NULL ? NULL : 'upload.' . $source,
      'field_workflow_status' => $workflow,
      'field_publication_scope' => $scope,
      'status' => $flag,
    ]);
    assert($form instanceof FormSchemaInterface);
    $form->save();
    return $form;
  }

  /**
   * Reloads a form from storage.
   */
  private function reload(FormSchemaInterface $form): FormSchemaInterface {
    $stored = $this->container->get('entity_type.manager')->getStorage('form_schema')->loadUnchanged($form->id());
    assert($stored instanceof FormSchemaInterface);
    return $stored;
  }

  /**
   * Counts forms that are live for the internet and were created by POST.
   */
  private function countLiveForms(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('form_schema')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_workflow_status', 'published')
      ->condition('status', 1)
      ->condition('field_publication_scope', 'internet')
      ->count()
      ->execute();
  }

  /**
   * A publishable schema with one non-personal field.
   */
  private function schemaJson(?string $source = NULL): string {
    return json_encode([
      'title' => 'Hundesteuer',
      'sections' => [[
        'id' => 'dog',
        'title' => 'Hund',
        'fields' => [[
          'id' => 'rasse',
          'type' => 'text',
          'label' => 'Rasse',
          'privacy' => ['kind' => 'not_personal'],
        ],
        ],
      ],
      ],
    ] + ($source === NULL ? [] : ['source' => ['type' => $source]]), JSON_THROW_ON_ERROR);
  }

}
