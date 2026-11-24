<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\beantragt_workflow_status\Service\WorkflowStatusManager;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The status list says why a status cannot be deleted; a refusal says which.
 *
 * Real JSON:API requests through the HTTP kernel (basic auth), as the Nuxt
 * proxy sends them. Neutral catalogue: the seeded statuses of one
 * organisation plus x (free), y (a transition leads to it), z (a form
 * lists it), w (an application uses it).
 */
#[Group('beantragt_workflow')]
final class WorkflowStatusDeleteBlockersKernelTest extends GroupKernelTestBase {

  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

  private const PASSWORD = 'synthetic-blocker-password';

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
    'beantragt_schema',
    'beantragt_workflow',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * Original application data key environment value.
   */
  private string|false $originalKey;

  /**
   * The organisation owning the catalogue.
   */
  private GroupInterface $organisation;

  /**
   * The jurisdiction of the organisation.
   */
  private GroupInterface $jurisdiction;

  /**
   * The spare statuses by machine name.
   *
   * @var array<string, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface>
   */
  private array $spare = [];

  /**
   * The form that lists z.
   */
  private FormSchemaInterface $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . str_repeat('k', 32));
    parent::setUp();
    $this->installConfig(['user', 'jsonapi', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('file', ['file_usage']);
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $this->setCurrentUser($this->createUser([
      'bypass beantragt group scope',
      'manage workflow transitions',
      'administer application entities',
      'administer workflow_status entities',
    ]));
    $this->jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Neutral jurisdiction',
      'field_slug' => 'neutral-jur',
    ]);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Neutral organisation',
      'field_slug' => 'neutral-org',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    foreach (['x', 'y', 'z', 'w'] as $weight => $name) {
      $status = $storage->create([
        'label' => $name,
        'field_group' => ['target_id' => $this->organisation->id()],
        'field_machine_name' => $name,
        'field_weight' => 60 + $weight,
      ]);
      $status->save();
      assert($status instanceof WorkflowStatusInterface);
      $this->spare[$name] = $status;
    }
    // A transition from w leads to y.
    $this->spare['w']->set('field_allowed_next', [['target_id' => $this->spare['y']->id()]])->save();
    $this->form = $this->createForm([
      'mode' => 'override',
      'defaultStatus' => 'w',
      'statuses' => [['machineName' => 'w'], ['machineName' => 'z']],
    ]);
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $this->form->id()],
      'field_data' => json_encode(['note' => 'synthetic'], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $this->spare['w']->id()],
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $this->organisation->id()],
      'field_group' => ['target_id' => $this->organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? self::KEY_ENV : self::KEY_ENV . '=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * The closed list of codes is pinned; the client parses exactly these.
   */
  public function testCodeListIsClosed(): void {
    $this->assertSame([
      'default',
      'application_reference',
      'application_revision_reference',
      'transition_reference',
      'form_workflow_reference',
    ], WorkflowStatusManager::DELETE_BLOCKER_CODES);
    $this->assertSame(
      'Workflow status is protected from deletion. [delete_blockers=transition_reference,form_workflow_reference]',
      WorkflowStatusManager::deleteBlockerReason(['form_workflow_reference', 'missing_id', 'transition_reference']),
    );
    // Without a code of the closed list there is no token, never an empty one.
    $this->assertSame('Workflow status is protected from deletion.', WorkflowStatusManager::deleteBlockerReason(['missing_id']));
    $this->assertSame('Workflow status is protected from deletion.', WorkflowStatusManager::deleteBlockerReason([]));
  }

  /**
   * Every status of the list carries what the delete check would answer.
   */
  public function testListCarriesTheBlockersOfTheDeleteCheck(): void {
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $listed = $this->listedBlockers($admin);
    $this->assertSame([], $listed['x']);
    $this->assertSame(['transition_reference'], $listed['y']);
    $this->assertSame(['form_workflow_reference'], $listed['z']);
    $this->assertSame(['application_reference', 'application_revision_reference', 'form_workflow_reference'], $listed['w']);
    $this->assertSameAsTheDeleteCheck($listed);

    // The form drops z in the same process: the next list and the check agree.
    $without_z = ['mode' => 'override', 'defaultStatus' => 'w', 'statuses' => [['machineName' => 'w']]];
    $this->form->set('field_workflow_definition', json_encode($without_z, JSON_THROW_ON_ERROR))->save();
    $listed = $this->listedBlockers($admin);
    $this->assertSame([], $listed['z']);
    $this->assertSameAsTheDeleteCheck($listed);
  }

  /**
   * The forms are asked once per request, not once per status.
   */
  public function testFormsAreAskedOncePerList(): void {
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    // Each request starts with a fresh service in production; a kernel test
    // shares one container, so forget the index before each request.
    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $manager->resetFormWorkflowIndex();
    Database::startLog('one', 'default');
    $this->request($admin, 'GET', '/jsonapi/workflow_status/workflow_status/' . $this->spare['z']->uuid() . '?fields[workflow_status--workflow_status]=delete_blockers');
    $one = $this->formQueries(Database::getLog('one', 'default'));
    $manager->resetFormWorkflowIndex();
    Database::startLog('all', 'default');
    $listed = $this->listedBlockers($admin);
    $all = $this->formQueries(Database::getLog('all', 'default'));
    $this->assertGreaterThan(10, count($listed));
    $this->assertGreaterThan(0, $one);
    $this->assertSame($one, $all);
  }

  /**
   * A form row scoped only by field_group still protects the status.
   *
   * The row (no organisation, no jurisdiction, field_group = the
   * organisation) is written past the presave normalization, as an old row
   * or a write outside the entity API would leave it. Its workflow lists q.
   */
  public function testRowScopedOnlyByGroupStillProtects(): void {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'q',
      'field_group' => ['target_id' => $this->organisation->id()],
      'field_machine_name' => 'q',
      'field_weight' => 70,
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    $form = $this->createForm(['mode' => 'override', 'defaultStatus' => 'q', 'statuses' => [['machineName' => 'q']]]);
    $storage = $this->entityTypeManager()->getStorage('form_schema');
    assert($storage instanceof SqlEntityStorageInterface);
    $mapping = $storage->getTableMapping();
    $values = [
      $mapping->getColumnNames('field_organisation')['target_id'] => NULL,
      $mapping->getColumnNames('field_jurisdiction')['target_id'] => NULL,
      $mapping->getColumnNames('field_group')['target_id'] => $this->organisation->id(),
    ];
    $database = $this->container->get('database');
    $database->update('form_schema')->fields($values)->condition('id', $form->id())->execute();
    $database->update('form_schema_revision')->fields($values)->condition('id', $form->id())->execute();
    $storage->resetCache([$form->id()]);
    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $manager->resetFormWorkflowIndex();

    $this->assertContains('form_workflow_reference', $manager->deleteBlockers($status));
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $response = $this->request($admin, 'DELETE', '/jsonapi/workflow_status/workflow_status/' . $status->uuid());
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringContainsString('[delete_blockers=form_workflow_reference]', (string) $response->getContent());
  }

  /**
   * The index one group builds serves every other group of the tenant.
   *
   * A form of a second organisation lists that organisation's own status.
   * The index is built by the first organisation's status; the second
   * organisation's status still shows form_workflow_reference, in the
   * manager and on the page across all groups.
   */
  public function testSecondOrganisationIsSeenByTheTenantIndex(): void {
    $second = $this->createGroup([
      'type' => 'org',
      'label' => 'Second organisation',
      'field_slug' => 'second-org',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $own = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 's',
      'field_group' => ['target_id' => $second->id()],
      'field_machine_name' => 's',
      'field_weight' => 70,
    ]);
    $own->save();
    assert($own instanceof WorkflowStatusInterface);
    $this->createForm(['mode' => 'override', 'statuses' => [['machineName' => 's']]], $second);

    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $manager->resetFormWorkflowIndex();
    // The first organisation's status builds the tenant index.
    $this->assertSame([], $manager->deleteBlockers($this->spare['x']));
    $this->assertContains('form_workflow_reference', $manager->deleteBlockers($own));

    $manager->resetFormWorkflowIndex();
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $response = $this->request($admin, 'GET', '/jsonapi/workflow_status/workflow_status?' . http_build_query([
      'fields[workflow_status--workflow_status]' => 'field_machine_name,delete_blockers',
      'sort' => 'field_weight',
      'page[limit]' => 50,
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $listed = [];
    foreach (json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data'] as $resource) {
      $listed[$resource['id']] = $resource['attributes']['delete_blockers'];
    }
    $this->assertSame([], $listed[$this->spare['x']->uuid()]);
    $this->assertSame(['form_workflow_reference'], $listed[$own->uuid()]);
  }

  /**
   * The response of a created status carries its blockers, computed.
   *
   * Not an empty list from the validation of the new entity: a form may
   * already list the new machine name.
   */
  public function testCreatedStatusResponseIsComputed(): void {
    $this->createForm(['mode' => 'override', 'statuses' => [['machineName' => 'pre_listed']]]);
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $response = $this->request($admin, 'POST', '/jsonapi/workflow_status/workflow_status', [
      'data' => [
        'type' => 'workflow_status--workflow_status',
        'attributes' => ['label' => 'pre_listed', 'field_machine_name' => 'pre_listed', 'field_weight' => 80],
        'relationships' => [
          'field_group' => ['data' => ['type' => 'group--org', 'id' => $this->organisation->uuid()]],
        ],
      ],
    ]);
    $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    $attributes = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data']['attributes'];
    $this->assertSame(['form_workflow_reference'], $attributes['delete_blockers']);
  }

  /**
   * A list across groups asks the forms once per request, not per group.
   */
  public function testFormsAreAskedOncePerRequestAcrossGroups(): void {
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $second = $this->createGroup([
      'type' => 'org',
      'label' => 'Second organisation',
      'field_slug' => 'second-org',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $manager->resetFormWorkflowIndex();
    Database::startLog('single', 'default');
    $this->request($admin, 'GET', '/jsonapi/workflow_status/workflow_status/' . $this->spare['z']->uuid() . '?fields[workflow_status--workflow_status]=delete_blockers');
    $single = $this->formQueries(Database::getLog('single', 'default'));
    $manager->resetFormWorkflowIndex();
    Database::startLog('groups', 'default');
    $response = $this->request($admin, 'GET', '/jsonapi/workflow_status/workflow_status?' . http_build_query([
      'fields[workflow_status--workflow_status]' => 'field_machine_name,field_group,delete_blockers',
      'page[limit]' => 50,
    ]));
    $groups = $this->formQueries(Database::getLog('groups', 'default'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $seen = [];
    foreach (json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data'] as $resource) {
      $seen[$resource['relationships']['field_group']['data']['meta']['drupal_internal__target_id']] = TRUE;
    }
    // The jurisdiction and both organisations are on the page.
    $this->assertEqualsCanonicalizing(
      [(int) $this->jurisdiction->id(), (int) $this->organisation->id(), (int) $second->id()],
      array_map('intval', array_keys($seen)),
    );
    // The query budget stays at three for one status or a page of three
    // groups. Field-level reads avoid entity loads.
    $this->assertSame($single, $groups, sprintf('one status: %d, three groups: %d', $single, $groups));
  }

  /**
   * Only a request that may see the field and asks for it pays for it.
   *
   * A sparse request that does not name delete_blockers never computes it;
   * a request without sparse fieldset does (JSON:API serializes every field
   * the reader may see): absent is not empty.
   */
  public function testOnlyAskingRequestsComputeTheField(): void {
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $path = '/jsonapi/workflow_status/workflow_status/' . $this->spare['z']->uuid();

    $manager->resetFormWorkflowIndex();
    Database::startLog('unnamed', 'default');
    $response = $this->request($admin, 'GET', $path . '?fields[workflow_status--workflow_status]=field_machine_name');
    $this->assertSame(0, $this->formQueries(Database::getLog('unnamed', 'default')));
    $this->assertArrayNotHasKey('delete_blockers', json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data']['attributes']);

    $manager->resetFormWorkflowIndex();
    Database::startLog('full', 'default');
    $response = $this->request($admin, 'GET', $path);
    $this->assertGreaterThan(0, $this->formQueries(Database::getLog('full', 'default')));
    $this->assertSame(['form_workflow_reference'], json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data']['attributes']['delete_blockers']);
  }

  /**
   * Saving a status computes no blockers (only a read does).
   */
  public function testStatusSaveAsksNothing(): void {
    $this->container->get('beantragt_workflow_status.status_manager')->resetFormWorkflowIndex();
    $status = $this->reloadStatus('x');
    $this->assertNotNull($status);
    Database::startLog('save', 'default');
    $status->set('label', 'x2')->save();
    $asked = array_filter(Database::getLog('save', 'default'), static fn(array $entry): bool => str_contains((string) $entry['query'], 'form_schema')
      || str_contains((string) $entry['query'], 'application'));
    $this->assertSame([], array_values(array_map(static fn(array $entry): string => (string) $entry['query'], $asked)));
  }

  /**
   * Only definition administrators read the field; nobody writes it.
   */
  public function testOnlyAdministratorsReadTheField(): void {
    $case_worker = $this->account('case_worker', ['manage workflow transitions', 'bypass beantragt group scope']);
    $response = $this->request($case_worker, 'GET', '/jsonapi/workflow_status/workflow_status/' . $this->spare['z']->uuid());
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $attributes = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data']['attributes'];
    $this->assertArrayHasKey('field_machine_name', $attributes);
    $this->assertArrayNotHasKey('delete_blockers', $attributes);

    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $this->assertFalse($this->spare['z']->get('delete_blockers')->access('edit', $admin));
    $response = $this->request($admin, 'PATCH', '/jsonapi/workflow_status/workflow_status/' . $this->spare['x']->uuid(), [
      'data' => [
        'type' => 'workflow_status--workflow_status',
        'id' => $this->spare['x']->uuid(),
        // JSON:API accepts an unchanged read-only value; a changed one not.
        'attributes' => ['delete_blockers' => ['default']],
      ],
    ]);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * A refused delete names its blockers; other refusals carry no token.
   */
  public function testRefusedDeleteNamesItsBlockers(): void {
    $admin = $this->account('admin', ['administer workflow_status entities', 'bypass beantragt group scope']);
    $response = $this->request($admin, 'DELETE', '/jsonapi/workflow_status/workflow_status/' . $this->spare['z']->uuid());
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $body = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('403', $body['errors'][0]['status']);
    $this->assertSame('Workflow status is protected from deletion. [delete_blockers=form_workflow_reference]', $body['errors'][0]['detail']);
    $this->assertNotNull($this->reloadStatus('z'));

    // No group write access: 403 without the token.
    $outsider = $this->account('outsider', ['administer workflow_status entities']);
    $response = $this->request($outsider, 'DELETE', '/jsonapi/workflow_status/workflow_status/' . $this->spare['x']->uuid());
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertStringNotContainsString('[delete_blockers=', (string) $response->getContent());
    $this->assertNotNull($this->reloadStatus('x'));

    // Without blockers the same request deletes.
    $response = $this->request($admin, 'DELETE', '/jsonapi/workflow_status/workflow_status/' . $this->spare['x']->uuid());
    $this->assertSame(204, $response->getStatusCode(), (string) $response->getContent());
    $this->assertNull($this->reloadStatus('x'));
  }

  /**
   * Reads the organisation's list through JSON:API, blockers by machine name.
   *
   * @return array<string, string[]>
   *   Codes per machine name.
   */
  private function listedBlockers(UserInterface $account): array {
    $query = http_build_query([
      'filter[field_group.id]' => $this->organisation->uuid(),
      'fields[workflow_status--workflow_status]' => 'field_machine_name,delete_blockers',
    ]);
    $response = $this->request($account, 'GET', '/jsonapi/workflow_status/workflow_status?' . $query);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $listed = [];
    foreach (json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['data'] as $resource) {
      $listed[$resource['attributes']['field_machine_name']] = $resource['attributes']['delete_blockers'];
    }
    $this->assertArrayHasKey('x', $listed);
    return $listed;
  }

  /**
   * The listed codes equal a fresh answer of the delete check.
   *
   * @param array<string, string[]> $listed
   *   Codes per machine name.
   */
  private function assertSameAsTheDeleteCheck(array $listed): void {
    $manager = $this->container->get('beantragt_workflow_status.status_manager');
    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $storage->resetCache();
    foreach ($storage->loadByProperties(['field_group' => $this->organisation->id()]) as $status) {
      assert($status instanceof WorkflowStatusInterface);
      $manager->resetFormWorkflowIndex();
      $fresh = array_values(array_intersect(WorkflowStatusManager::DELETE_BLOCKER_CODES, $manager->deleteBlockers($status)));
      $this->assertSame($fresh, $listed[$status->getMachineName()] ?? NULL, $status->getMachineName());
    }
  }

  /**
   * Counts logged queries on the form tables.
   *
   * @param array<int, array<string, mixed>> $log
   *   The database log.
   */
  private function formQueries(array $log): int {
    return count(array_filter($log, static fn(array $entry): bool => str_contains((string) $entry['query'], 'form_schema')));
  }

  /**
   * Creates an account with a password for basic auth.
   *
   * @param string $name
   *   Account name.
   * @param string[] $permissions
   *   Permissions.
   */
  private function account(string $name, array $permissions): UserInterface {
    $account = $this->createUser($permissions, $name);
    assert($account instanceof UserInterface);
    // Staff pass the JSON:API read gate by role; the permissions above
    // stay what the assertions are about.
    if (!Role::load('case_worker')) {
      Role::create(['id' => 'case_worker', 'label' => 'case_worker'])->save();
    }
    $account->addRole('case_worker');
    $account->setPassword(self::PASSWORD)->save();
    return $account;
  }

  /**
   * Sends a JSON:API request through the HTTP kernel.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account (basic auth).
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path with query.
   * @param array<string, mixed>|null $body
   *   JSON:API document, if any.
   */
  private function request(UserInterface $account, string $method, string $path, ?array $body = NULL): Response {
    $request = Request::create($path, $method, server: [
      'HTTP_ACCEPT' => 'application/vnd.api+json',
      'CONTENT_TYPE' => 'application/vnd.api+json',
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($account->getAccountName() . ':' . self::PASSWORD),
      'PHP_AUTH_USER' => $account->getAccountName(),
      'PHP_AUTH_PW' => self::PASSWORD,
    ], content: $body === NULL ? NULL : json_encode($body, JSON_THROW_ON_ERROR));
    $response = $this->container->get('http_kernel')->handle($request);
    // Kernel requests share one container; reset the account between them.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    return $response;
  }

  /**
   * Loads a spare status fresh.
   */
  private function reloadStatus(string $name): ?WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->loadUnchanged($this->spare[$name]->id());
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Creates a form of the organisation with an own workflow.
   *
   * @param array<string, mixed> $workflow
   *   The workflow definition.
   * @param \Drupal\group\Entity\GroupInterface|null $organisation
   *   The organisation; NULL for the fixture's organisation.
   */
  private function createForm(array $workflow, ?GroupInterface $organisation = NULL): FormSchemaInterface {
    $organisation ??= $this->organisation;
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Neutral form',
      'field_schema' => json_encode([
        'title' => 'Neutral form',
        'sections' => [[
          'id' => 'main',
          'title' => 'Main',
          'fields' => [[
            'id' => 'note',
            'type' => 'text',
            'label' => 'Note',
            'privacy' => ['kind' => 'other_personal'],
          ],
          ],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_definition' => json_encode($workflow, JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => FALSE,
    ]);
    $form->save();
    assert($form instanceof FormSchemaInterface);
    return $form;
  }

}
