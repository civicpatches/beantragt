<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Workspace fixtures and JSON:API assertions for form and form group creation.
 */
abstract class FormWorkspaceKernelTestBase extends GroupKernelTestBase {

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
    $this->installEntitySchema('application');
    $this->installSchema('file', ['file_usage']);
    $this->ensureRole('department_admin');
    $this->ensureRole('service_account');
  }

  /**
   * Enables writes against the real JSON:API routes.
   */
  protected function enableJsonApiWrites(): void {
    $this->installConfig(['jsonapi']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Builds the JSON:API relationships for a municipality or organisation.
   */
  protected function workspaceRelationships(GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): array {
    $relationships = [
      'field_jurisdiction' => ['data' => ['type' => 'group--jur', 'id' => $jurisdiction->uuid()]],
    ];
    if ($organisation) {
      $relationships['field_organisation'] = ['data' => ['type' => 'group--org', 'id' => $organisation->uuid()]];
    }
    return $relationships;
  }

  /**
   * Checks the response and the persisted row count for each POST.
   */
  protected function assertCreateResponse(
    UserInterface $account,
    string $entity_type,
    array $data,
    int $status,
    ?string $code = NULL,
    ?Session $session = NULL,
    bool $send_csrf = TRUE,
  ): array {
    $counts = [$entity_type => $this->rowCount($entity_type)];
    $revision_table = $this->entityTypeManager()->getDefinition($entity_type)->getRevisionTable();
    if ($revision_table) {
      $counts[$revision_table] = $this->rowCount($revision_table);
    }
    $data['type'] = $entity_type . '--' . $entity_type;
    $document = $this->assertJsonApiResponse($account, 'POST', "/jsonapi/$entity_type/$entity_type", $data, $status, $code, $session, $send_csrf);
    foreach ($counts as $table => $before) {
      self::assertSame($before + ($status === 201 ? 1 : 0), $this->rowCount($table), $table);
    }
    return $document;
  }

  /**
   * Issues an authenticated write and checks the serialized JSON:API response.
   */
  protected function assertJsonApiResponse(
    UserInterface $account,
    string $method,
    string $path,
    array $data,
    int $status,
    ?string $code = NULL,
    ?Session $session = NULL,
    bool $send_csrf = TRUE,
  ): array {
    $this->setCurrentUser(new AnonymousUserSession());
    $server = [
      'HTTP_ACCEPT' => 'application/vnd.api+json',
      'CONTENT_TYPE' => 'application/vnd.api+json',
    ];
    if (!$session) {
      $server['PHP_AUTH_USER'] = $account->getAccountName();
      $server['PHP_AUTH_PW'] = $account->passRaw;
      $server['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($account->getAccountName() . ':' . $account->passRaw);
    }
    $request = Request::create($path, $method, server: $server, content: json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    if ($session) {
      $request->setSession($session);
      $options = $this->container->get('session_configuration')->getOptions($request);
      $request->cookies->set($options['name'], $session->getId());
      if ($send_csrf) {
        $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      }
    }
    $response = $this->container->get('http_kernel')->handle($request);
    $body = (string) $response->getContent();
    self::assertSame($status, $response->getStatusCode(), $body);
    $document = json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
    if ($code !== NULL) {
      self::assertSame($code, $document['errors'][0]['code'] ?? NULL, $body);
    }
    return $document;
  }

  /**
   * Counts stored entities or revisions directly.
   */
  protected function rowCount(string $table): int {
    return (int) $this->container->get('database')->select($table)->countQuery()->execute()->fetchField();
  }

  /**
   * Removes a global permission while preserving group memberships.
   */
  protected function revokeGlobalPermission(UserInterface $account, string $permission): void {
    self::assertTrue($account->hasPermission($permission));
    foreach (Role::loadMultiple($account->getRoles(TRUE)) as $role) {
      if ($role->hasPermission($permission)) {
        $role->revokePermission($permission)->save();
      }
    }
    self::assertFalse($account->hasPermission($permission));
  }

  /**
   * Creates a jurisdiction and an organisation rooted in it.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: \Drupal\group\Entity\GroupInterface}
   *   The jurisdiction and organisation.
   */
  protected function createWorkspacePair(string $suffix): array {
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => "US-SMOKE Jurisdiction $suffix",
      'field_slug' => "us-smoke-jur-$suffix",
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => "US-SMOKE Organisation $suffix",
      'field_slug' => "us-smoke-org-$suffix",
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    return [$jurisdiction, $organisation];
  }

  /**
   * Creates an unsaved valid draft with optional workspace references.
   */
  protected function createSchema(
    UserInterface $owner,
    ?GroupInterface $jurisdiction = NULL,
    ?GroupInterface $organisation = NULL,
  ): FormSchemaInterface {
    $values = [
      'label' => 'US-SMOKE Workspace scope',
      'field_schema' => json_encode([
        'title' => 'US-SMOKE Workspace scope',
        'sections' => [],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'intranet',
      'uid' => $owner->id(),
      'status' => FALSE,
    ];
    if ($jurisdiction instanceof GroupInterface) {
      $values['field_jurisdiction'] = ['target_id' => $jurisdiction->id()];
    }
    if ($organisation instanceof GroupInterface) {
      $values['field_organisation'] = ['target_id' => $organisation->id()];
    }

    $schema = $this->entityTypeManager()->getStorage('form_schema')->create($values);
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Ensures a global Drupal role exists.
   */
  private function ensureRole(string $roleId): void {
    if (Role::load($roleId) === NULL) {
      Role::create([
        'id' => $roleId,
        'label' => $roleId,
      ])->save();
    }
  }

}
