<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_delivery\Controller\IntegrationController;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Exercises production route access and tenant membership against real groups.
 */
#[Group('beantragt_delivery')]
final class IntegrationAccessTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'file', 'jsonapi', 'json_form_widget', 'serialization',
    'language', 'datetime', 'beantragt_schema', 'beantragt_workflow_status',
    'beantragt_application', 'beantragt_group', 'beantragt_notification',
    'beantragt_delivery',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('beantragt_delivery.contract_registry')->setClass(SyntheticDataContractRegistry::class);
    $container->getDefinition('access_manager.check_provider')->setPublic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('beantragt_fim_contract_directory', dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    $this->installConfig(['user', 'beantragt_group', 'beantragt_delivery']);
    $this->installEntitySchema('workflow_status');
    $this->installSchema('beantragt_delivery', [
      'beantragt_integration_connection', 'beantragt_integration_mapping',
      'beantragt_integration_mapping_version', 'beantragt_integration_assignment',
      'beantragt_integration_scope_lock',
    ]);
  }

  /**
   * Every resource checks permissions and explicit collection scope membership.
   */
  public function testResourceRoutesRejectForeignScopeAndAnonymous(): void {
    $first = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic tenant A', 'field_slug' => 'synthetic-a']);
    $second = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic tenant B', 'field_slug' => 'synthetic-b']);
    $permissions = ['administer integration connections', 'administer integration mappings'];
    $member = $this->createUser($permissions);
    $first->addMember($member, ['group_roles' => ['jur-admin']]);
    $nonmember = $this->createUser($permissions);
    $foreign = $this->createUser($permissions);
    $second->addMember($foreign, ['group_roles' => ['jur-admin']]);
    $bypass = $this->createUser([...$permissions, 'bypass beantragt group scope']);
    $unprivileged = $this->createUser();
    $first->addMember($unprivileged);
    $plain_member = $this->createUser($permissions);
    $first->addMember($plain_member, ['group_roles' => ['jur-member']]);
    $blocked = $this->createUser($permissions);
    $first->addMember($blocked, ['group_roles' => ['jur-admin']]);
    $blocked->block()->save();

    $cases = [
      [$member, 200], [$nonmember, 404], [$foreign, 404], [$bypass, 200],
      [$unprivileged, 403], [$plain_member, 404], [$blocked, 404],
      [new AnonymousUserSession(), 403],
    ];
    foreach (['connection', 'mapping', 'assignment'] as $resource) {
      foreach ($cases as [$account, $status]) {
        $request = $this->request($resource, 'list', ['scope' => (string) $first->id()]);
        $this->setCurrentUser($account);
        $allowed = $this->container->get('access_manager')->check(RouteMatch::createFromRequest($request), $account, $request);
        self::assertSame($status !== 403, $allowed);
        $response = IntegrationController::create($this->container)->handle($request);
        self::assertSame($status, $response->getStatusCode(), $resource . ' must enforce the current caller scope.');
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        if ($status === 404) {
          self::assertSame('integration.scope_not_found', $data['error']['code']);
        }
        elseif ($status === 200) {
          self::assertSame([], $data['data']);
        }
      }
    }
  }

  /**
   * Guessed UUIDs never disclose rows belonging to another real tenant.
   */
  public function testForeignResourceUuidsReturnNotFound(): void {
    $first = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic A', 'field_slug' => 'a']);
    $second = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic B', 'field_slug' => 'b']);
    $permissions = ['administer integration connections', 'administer integration mappings'];
    $member = $this->createUser($permissions);
    $first->addMember($member, ['group_roles' => ['jur-admin']]);
    $foreign = $this->createUser($permissions);
    $second->addMember($foreign, ['group_roles' => ['jur-admin']]);
    $bypass = $this->createUser([...$permissions, 'bypass beantragt group scope']);
    $plain_member = $this->createUser($permissions);
    $first->addMember($plain_member, ['group_roles' => ['jur-member']]);
    foreach (['connection', 'mapping', 'assignment'] as $resource) {
      $uuid = $this->container->get('uuid')->generate();
      $table = 'beantragt_integration_' . $resource;
      $row = [];
      foreach (IntegrationRepository::schema()[$table]['fields'] as $name => $definition) {
        $row[$name] = match ($definition['type']) {
          'int' => 1,
          'text' => '[]',
          default => 'synthetic',
        };
      }
      $row['uuid'] = $uuid;
      $row['scope_group_id'] = (int) $first->id();
      $this->container->get('database')->insert($table)->fields($row)->execute();
      foreach ([[$member, 200], [$foreign, 404], [$bypass, 200]] as [$account, $status]) {
        if ($resource === 'connection') {
          // Foreign JSON must never be decoded before scope authorization.
          $this->container->get('database')->update($table)->fields([
            'last_check_result' => $status === 404 ? '{invalid' : NULL,
          ])->condition('uuid', $uuid)->execute();
        }
        $request = $this->request($resource, 'read', []);
        $request->attributes->set('uuid', $uuid);
        $this->setCurrentUser($account);
        self::assertTrue($this->routeAccess($request, $account));
        $response = IntegrationController::create($this->container)->handle($request);
        self::assertSame($status, $response->getStatusCode());
        $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        if ($status === 404) {
          self::assertSame('integration.' . $resource . '_not_found', $data['error']['code']);
          self::assertArrayNotHasKey('data', $data);
        }
      }
      $operations = match ($resource) {
        'connection' => ['update', 'activate', 'check'],
        'mapping' => ['create_version', 'update_version', 'activate', 'retire', 'check_version'],
        'assignment' => ['update', 'activate', 'deactivate', 'replace', 'adopt_revision'],
      };
      foreach ([$foreign, $plain_member] as $account) {
        $this->setCurrentUser($account);
        foreach ($operations as $operation) {
          $request = $this->request($resource, $operation, [], ['uuid' => $uuid, 'version' => '1']);
          $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
          $response = IntegrationController::create($this->container)->handle($request);
          self::assertSame(404, $response->getStatusCode());
          $body = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
          self::assertSame('integration.' . $resource . '_not_found', $body['error']['code']);
        }
        $request = $this->request($resource, 'create', ['scope' => (string) $first->id()]);
        $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
        self::assertSame(404, IntegrationController::create($this->container)->handle($request)->getStatusCode());
      }
    }
  }

  /**
   * Cookie-authenticated mutations reject absent or invalid CSRF headers.
   */
  public function testEveryResourceMutationRequiresCsrfToken(): void {
    $account = $this->createUser(['administer integration connections', 'administer integration mappings']);
    $this->setCurrentUser($account);
    foreach (['connection', 'mapping', 'assignment'] as $resource) {
      $request = $this->request($resource, 'create', ['scope' => '1']);
      $options = $this->container->get('session_configuration')->getOptions($request);
      $request->cookies->set($options['name'], 'synthetic-session-cookie');
      self::assertFalse($this->routeAccess($request, $account));
      $request->headers->set('X-CSRF-Token', 'synthetic-invalid-token');
      self::assertFalse($this->routeAccess($request, $account));
      $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      self::assertTrue($this->routeAccess($request, $account));
    }
  }

  /**
   * Controller also requires CSRF headers for authenticated cookie-less calls.
   */
  public function testCookieLessMutationsRequireCsrfBeforeRepositoryWrites(): void {
    $scope = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic tenant', 'field_slug' => 'csrf']);
    $account = $this->createUser(['administer integration connections', 'administer integration mappings']);
    $scope->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);
    foreach (['connection', 'mapping', 'assignment'] as $resource) {
      $request = $this->request($resource, 'create', ['scope' => (string) $scope->id()]);
      self::assertSame([], $request->cookies->all());
      $controller = IntegrationController::create($this->container);
      foreach ([NULL, 'synthetic-invalid-token'] as $token) {
        if ($token !== NULL) {
          $request->headers->set('X-CSRF-Token', $token);
        }
        $response = $controller->handle($request);
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        self::assertSame('integration.csrf_invalid', $body['error']['code']);
      }
      $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
      $response = $controller->handle($request);
      self::assertSame(422, $response->getStatusCode());
      $body = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      self::assertSame('integration.input_invalid', $body['error']['code']);
    }
  }

  /**
   * Only jurisdiction administrators manage integrations, in the jurisdiction.
   *
   * Organisations keep their workflow but never own transfer definitions, so
   * an organisation scope is unknown even to the jurisdiction administrator.
   */
  public function testIntegrationAdministrationIsJurisdictionOnly(): void {
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic jurisdiction', 'field_slug' => 'parent']);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Synthetic organisation',
      'field_slug' => 'org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $permissions = ['administer integration connections', 'administer integration mappings'];
    $org_admin = $this->createUser($permissions);
    $organisation->addMember($org_admin, ['group_roles' => ['org-admin']]);
    $parent_admin = $this->createUser($permissions);
    $jurisdiction->addMember($parent_admin, ['group_roles' => ['jur-admin']]);
    $member = $this->createUser($permissions);
    $organisation->addMember($member, ['group_roles' => ['org-member']]);
    $cases = [
      [$org_admin, $organisation, 404],
      [$parent_admin, $organisation, 404],
      [$member, $organisation, 404],
      [$org_admin, $jurisdiction, 404],
      [$member, $jurisdiction, 404],
      [$parent_admin, $jurisdiction, 200],
    ];
    foreach ($cases as [$account, $scope, $status]) {
      $this->setCurrentUser($account);
      $request = $this->request('connection', 'list', ['scope' => (string) $scope->id()]);
      $response = IntegrationController::create($this->container)->handle($request);
      self::assertSame($status, $response->getStatusCode());
    }
  }

  /**
   * Either management permission grants both contract catalogs through routing.
   */
  public function testContractRoutesAcceptEitherManagementPermission(): void {
    $scope = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic catalog', 'field_slug' => 'catalog']);
    foreach (['administer integration connections', 'administer integration mappings'] as $permission) {
      $account = $this->createUser([$permission]);
      $scope->addMember($account, ['group_roles' => ['jur-admin']]);
      $this->setCurrentUser($account);
      foreach (['contracts', 'paths'] as $operation) {
        $request = $this->request('contract', $operation, ['scope' => (string) $scope->id()], [
          'key' => 'fim:S00000096',
          'contract_version' => '1.0',
        ]);
        self::assertTrue($this->routeAccess($request, $account));
        $response = IntegrationController::create($this->container)->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data['data']);
      }
    }
  }

  /**
   * Audit records successful activation and denied tenant access, never values.
   */
  public function testControllerAuditsActivationAndForeignDenial(): void {
    $events = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context) use (&$events): void {
      $events[] = json_decode($context['@event'], TRUE, 512, JSON_THROW_ON_ERROR);
    });
    $this->container->get('logger.channel.beantragt_integration_audit')->addLogger($logger);
    $scope = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic audit tenant', 'field_slug' => 'audit']);
    $admin = $this->createUser(['administer integration connections']);
    $scope->addMember($admin, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($admin);
    $this->container->get('config.factory')->getEditable('beantragt_delivery.settings')->set('credential_profiles.synthetic', [
      'label' => 'Synthetic profile',
      'client_id' => 'synthetic-client',
      'client_secret_env' => 'SYNTHETIC_PRIVATE_ENV_NAME',
      'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'metadata_schema_version' => '2.1.0',
      'allowed_scopes' => [(int) $scope->id()],
      'all_scopes' => FALSE,
    ])->save();
    $row = $this->container->get('beantragt_delivery.integration_repository')->create('connection', [
      'scope_group_id' => (int) $scope->id(),
      'label' => 'SYNTHETIC_VALUE_NOT_FOR_AUDIT',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'synthetic',
      'destination_id' => '00000000-0000-4000-8000-000000000096',
      'service_urn' => 'urn:example:synthetic',
      'service_name' => 'Synthetic service',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], (int) $admin->id());
    $request = $this->request('connection', 'activate', [], ['uuid' => $row['uuid']]);
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    self::assertSame(200, IntegrationController::create($this->container)->handle($request)->getStatusCode());
    $presentation = $this->request('connection', 'presentation', [], ['uuid' => $row['uuid']], [
      'closure_rule' => 'manual',
      'handover_label' => ['de' => 'SYNTHETIC_LABEL_NOT_FOR_AUDIT'],
    ]);
    $presentation->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    self::assertSame(200, IntegrationController::create($this->container)->handle($presentation)->getStatusCode());
    $presentation_event = array_pop($events);
    self::assertSame('presentation', $presentation_event['action']);
    // A closure-rule flip on an active connection must be attributable.
    self::assertSame(['handover_label', 'closure_rule'], $presentation_event['changed_fields']);
    self::assertStringNotContainsString('SYNTHETIC_LABEL_NOT_FOR_AUDIT', json_encode($presentation_event, JSON_THROW_ON_ERROR));
    $foreign = $this->createUser(['administer integration connections']);
    $this->setCurrentUser($foreign);
    self::assertSame(404, IntegrationController::create($this->container)->handle($request)->getStatusCode());
    self::assertCount(2, $events);
    self::assertSame('success', $events[0]['outcome']);
    self::assertSame('activate', $events[0]['action']);
    self::assertSame((int) $admin->id(), $events[0]['uid']);
    self::assertSame($row['uuid'], $events[0]['uuid']);
    self::assertSame((int) $scope->id(), $events[0]['scope_group_id']);
    self::assertSame('denied', $events[1]['outcome']);
    self::assertSame((int) $foreign->id(), $events[1]['uid']);
    self::assertSame('integration.connection_not_found', $events[1]['code']);
    $json = json_encode($events, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('SYNTHETIC_VALUE_NOT_FOR_AUDIT', $json);
    self::assertStringNotContainsString('SYNTHETIC_PRIVATE_ENV_NAME', $json);
  }

  /**
   * HTTP subrequests expose exact revisions and conceal foreign forms.
   */
  public function testHttpFormCatalogUsesExactRevisionAndTenant(): void {
    $this->installEntitySchema('form_schema');
    $first = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic catalog A', 'field_slug' => 'catalog-a']);
    $second = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic catalog B', 'field_slug' => 'catalog-b']);
    $operator = $this->createUser(['bypass beantragt group scope']);
    $this->setCurrentUser($operator);
    $schema = [
      'title' => 'Synthetic revision test',
      'sections' => [[
        'id' => 'main',
        'title' => 'Synthetic section',
        'fields' => [[
          'id' => 'synthetic_choice',
          'type' => 'select',
          'label' => 'Original label',
          'required' => TRUE,
          'options' => ['synthetic_yes', 'synthetic_no'],
        ],
        ],
      ],
      ],
    ];
    $storage = $this->container->get('entity_type.manager')->getStorage('form_schema');
    $form = $storage->create([
      'label' => 'Synthetic revision test',
      'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
      'field_jurisdiction' => ['target_id' => $first->id()],
      'field_group' => ['target_id' => $first->id()],
      'field_workflow_status' => 'draft',
    ]);
    $form->save();
    $revision_one = (int) $form->getRevisionId();
    $schema['sections'][0]['fields'][0]['label'] = 'Revised label';
    $schema['sections'][0]['fields'][0]['options'][] = 'synthetic_later';
    $form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR));
    $form->save();
    $revision_two = (int) $form->getRevisionId();
    self::assertNotSame($revision_one, $revision_two);
    $foreign_form = $storage->create([
      'label' => 'Synthetic foreign form',
      'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
      'field_jurisdiction' => ['target_id' => $second->id()],
      'field_group' => ['target_id' => $second->id()],
      'field_workflow_status' => 'draft',
    ]);
    $foreign_form->save();
    $admin = $this->createUser(['administer integration mappings']);
    $first->addMember($admin, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($admin);
    $this->container->get('router.builder')->rebuild();
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle(Request::create('/api/admin/integrations/form-schemas?scope=' . $first->id()), HttpKernelInterface::SUB_REQUEST, FALSE);
    self::assertSame(200, $response->getStatusCode());
    $catalog = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    // The intent store is absent here; the list still reports the backlog.
    self::assertSame(['total' => 1, 'truncated' => FALSE, 'pending_planning_count' => 0], $catalog['meta']);
    self::assertSame($form->uuid(), $catalog['data'][0]['uuid']);
    self::assertSame($revision_two, $catalog['data'][0]['current_revision_id']);
    self::assertSame([$revision_two, $revision_one], array_column($catalog['data'][0]['revisions'], 'revision_id'));
    foreach ([$revision_one => 'Original label', $revision_two => 'Revised label'] as $revision => $label) {
      $path = '/api/admin/integrations/form-schemas/' . $form->uuid() . '/revisions/' . $revision . '/fields';
      $response = $kernel->handle(Request::create($path), HttpKernelInterface::SUB_REQUEST, FALSE);
      self::assertSame(200, $response->getStatusCode());
      $fields = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data'];
      self::assertSame($label, $fields[0]['label']);
      self::assertSame('synthetic_choice', $fields[0]['id']);
      self::assertSame('select', $fields[0]['type']);
      self::assertTrue($fields[0]['required']);
      self::assertSame('Synthetic section', $fields[0]['section_title']);
      self::assertSame(['value' => 'synthetic_yes', 'label' => 'synthetic_yes'], $fields[0]['options'][0]);
      self::assertCount($revision === $revision_one ? 2 : 3, $fields[0]['options']);
    }
    $foreign_references = [
      [$foreign_form->uuid(), $foreign_form->getRevisionId()],
      [$form->uuid(), $foreign_form->getRevisionId()],
    ];
    foreach ($foreign_references as [$uuid, $revision]) {
      $path = '/api/admin/integrations/form-schemas/' . $uuid . '/revisions/' . $revision . '/fields';
      $response = $kernel->handle(Request::create($path), HttpKernelInterface::SUB_REQUEST, FALSE);
      self::assertSame(404, $response->getStatusCode());
      $error = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['error'];
      self::assertSame('integration.form_schema_not_found', $error['code']);
    }

    // Exercise repository revision binding against the same real SQL entities.
    $repository = $this->container->get('beantragt_delivery.integration_repository');
    $mapping = $repository->create('mapping', [
      'scope_group_id' => (int) $first->id(),
      'label' => 'Synthetic compatibility',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], (int) $admin->id());
    $version = $repository->createVersion($mapping['uuid'], [
      'form_schema_uuid' => $form->uuid(),
      'compatible_revisions' => [$revision_one],
      'rules' => ['version' => 1, 'rules' => [['source' => 'synthetic_choice', 'target' => '/synthetic']]],
    ], (int) $admin->id());
    self::assertTrue($repository->compatibility($mapping['uuid'], $version['version'], $revision_one)['compatible']);
    $changed = $repository->compatibility($mapping['uuid'], $version['version'], $revision_two);
    self::assertFalse($changed['compatible']);
    self::assertFalse($changed['registered']);
    self::assertSame('integration.source_changed', $changed['errors'][0]['code']);
    $version_path = '/api/admin/integrations/mappings/' . $mapping['uuid'] . '/versions/' . $version['version'];
    $response = $kernel->handle(Request::create($version_path), HttpKernelInterface::SUB_REQUEST, FALSE);
    self::assertSame(200, $response->getStatusCode());
    $version_response = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data'];
    self::assertSame($version['checksum'], $version_response['checksum']);
    self::assertArrayNotHasKey('uid', $version_response);
    $request = Request::create($version_path . '/check', 'POST');
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
    self::assertSame(200, $response->getStatusCode());
    $check = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data'];
    self::assertFalse($check['valid']);
    self::assertNotEmpty($check['errors']);
    self::assertSame(['required_total', 'required_covered'], array_keys($check['coverage']));
    self::assertSame($version, $repository->version($mapping['uuid'], $version['version']));
    $request = Request::create($version_path . '/compatibility/' . $revision_two);
    $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame($changed, json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['data']);
    $this->setCurrentUser($operator);
    $schema['sections'][0]['fields'][0]['id'] = 'synthetic_replacement';
    $form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR));
    $form->save();
    $missing = $repository->compatibility($mapping['uuid'], $version['version'], (int) $form->getRevisionId());
    self::assertFalse($missing['compatible']);
    self::assertSame('integration.source_missing', $missing['errors'][0]['code']);
    try {
      $repository->compatibility($mapping['uuid'], $version['version'], (int) $foreign_form->getRevisionId());
      self::fail('A foreign form revision must never satisfy the mapping UUID.');
    }
    catch (IntegrationException $exception) {
      self::assertSame(404, $exception->status);
      self::assertSame('integration.form_schema_not_found', $exception->errorCode);
    }
    $form->set('field_jurisdiction', ['target_id' => $second->id()]);
    $form->set('field_group', ['target_id' => $second->id()]);
    $form->save();
    try {
      $repository->compatibility($mapping['uuid'], $version['version'], $revision_one);
      self::fail('A historical revision must not resurrect a transferred form.');
    }
    catch (IntegrationException $exception) {
      self::assertSame(404, $exception->status);
      self::assertSame('integration.form_schema_not_found', $exception->errorCode);
    }
    self::assertSame([$revision_one], $repository->version($mapping['uuid'], $version['version'])['compatible_revisions']);
  }

  /**
   * Calls Drupal's production access services on the real route requirements.
   */
  private function routeAccess(Request $request, AccountInterface $account): bool {
    return $this->container->get('access_manager')->check(RouteMatch::createFromRequest($request), $account, $request);
  }

  /**
   * Loads route requirements and defaults from the module's actual YAML.
   */
  private function request(string $resource, string $operation, array $query, array $parameters = [], ?array $body = NULL): Request {
    $path = $this->container->get('extension.list.module')->getPath('beantragt_delivery');
    $definitions = Yaml::decode(file_get_contents($path . '/beantragt_delivery.routing.yml'));
    $name = match ($resource . ':' . $operation) {
      'contract:contracts' => 'beantragt_delivery.integration_contracts',
      'contract:paths' => 'beantragt_delivery.integration_contract_paths',
      default => 'beantragt_delivery.integration_' . $resource . '_' . $operation,
    };
    $definition = $definitions[$name];
    $route = new Route($definition['path'], $definition['defaults'], $definition['requirements'], $definition['options'], '', [], $definition['methods']);
    $routes = new RouteCollection();
    $routes->add($name, $route);
    $this->container->get('access_manager.check_provider')->setChecks($routes);
    $request_path = $definition['path'];
    foreach ($parameters as $key => $value) {
      $request_path = str_replace('{' . $key . '}', (string) $value, $request_path);
    }
    $request = $body === NULL
      ? Request::create($request_path, $definition['methods'][0])
      : Request::create($request_path, $definition['methods'][0], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['data' => $body], JSON_THROW_ON_ERROR));
    $request->query->replace($query);
    $request->attributes->add($parameters + $route->getDefaults() + ['_route' => $name, '_route_object' => $route]);
    self::assertSame('TRUE', $route->getOption('no_cache'));
    return $request;
  }

}
