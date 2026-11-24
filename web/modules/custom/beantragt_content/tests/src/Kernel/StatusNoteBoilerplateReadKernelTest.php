<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Kernel;

use Drupal\beantragt_content\Controller\BoilerplateController;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may read the status-note text blocks of an office.
 *
 * Contract "Textbausteine lesen" of 16.11.2026: whoever may change status in
 * an office reads its status-note text blocks (the municipality's shared ones
 * and the office's own); management keeps today's access; everything else is
 * refused. Real groups, real site roles, real text-block nodes.
 */
#[Group('beantragt_content')]
final class StatusNoteBoilerplateReadKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'dynamic_page_cache',
    'file',
    'filter',
    'language',
    'node',
    'options',
    'text',
    'json_form_widget',
    'jsonapi',
    'serialization',
    'beantragt_roles_api',
    'beantragt_roles',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_workflow',
    'beantragt_content',
  ];

  /**
   * Municipality A and its offices A1 and A2; municipality B and office B1.
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface>
   */
  private array $groups = [];

  /**
   * Personas by key.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  private array $people = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'filter', 'node', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->container->get('module_handler')->loadInclude('beantragt_roles', 'install');
    beantragt_roles_install();
    // Slugs name municipalities and offices in the route and the query.
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    $this->container->get('module_handler')->loadInclude('beantragt_content', 'install');
    _beantragt_content_ensure_content_model($this->container->get('entity_type.manager'));
    $this->container->get('router.builder')->rebuild();
    $this->assertTrue(Role::load('case_worker')->hasPermission('manage workflow transitions'));

    $this->groups['A'] = $this->createGroup(['type' => 'jur', 'label' => 'Municipality A', 'field_slug' => 'read-a']);
    $this->groups['B'] = $this->createGroup(['type' => 'jur', 'label' => 'Municipality B', 'field_slug' => 'read-b']);
    $this->groups['A1'] = $this->office('read-a1', 'A');
    $this->groups['A2'] = $this->office('read-a2', 'A');
    $this->groups['B1'] = $this->office('read-b1', 'B');

    $this->boilerplate('Shared A', 'A');
    $this->boilerplate('Own A1', 'A', 'A1');
    $this->boilerplate('Own A2', 'A', 'A2');
    $this->boilerplate('Shared B', 'B');
    $this->boilerplate('Provider A', 'A', NULL, 'service_provider');

    $this->people['editor'] = $this->persona('read_editor', 'case_worker', 'A1', 'org-admin');
    $this->people['viewer'] = $this->persona('read_viewer', 'case_worker', 'A1', 'org-member');
    $this->people['officeAdmin'] = $this->persona('read_office_admin', 'department_admin', 'A1', 'org-admin');
    $this->people['municipalityAdmin'] = $this->persona('read_municipality_admin', 'department_admin', 'A', 'jur-admin');
    $this->people['municipalityEditor'] = $this->persona('read_municipality_editor', 'case_worker', 'A', 'jur-admin');
    $this->people['editorB'] = $this->persona('read_editor_b', 'case_worker', 'B1', 'org-admin');
    $this->people['citizen'] = $this->persona('read_citizen', NULL, NULL, NULL);
    $admin = $this->createUser([], 'read_platform_admin');
    $admin->addRole('administrator');
    $admin->save();
    $this->people['platformAdmin'] = $admin;
  }

  /**
   * A case worker of A1 reads the shared blocks of A and A1's own.
   */
  public function testCaseWorkerReadsSharedAndOwnBlocks(): void {
    $this->assertTrue($this->access('editor', 'read-a', ['type' => 'status_notes', 'organisation' => 'read-a1'])->isAllowed());
    $titles = $this->titles('read-a', ['type' => 'status_notes', 'organisation' => 'read-a1']);
    $this->assertSame(['Own A1', 'Shared A'], $titles);
  }

  /**
   * Other offices, other municipalities, no office, other types: refused.
   */
  public function testCaseWorkerIsRefusedOutsideItsOffice(): void {
    $a1 = (string) $this->groups['A1']->id();
    $a2 = (string) $this->groups['A2']->id();
    $refused = [
      'office A2 by slug' => ['read-a', ['type' => 'status_notes', 'organisation' => 'read-a2']],
      'office A2 by numeric id' => ['read-a', ['type' => 'status_notes', 'organisation' => $a2]],
      'office of another municipality' => ['read-b', ['type' => 'status_notes', 'organisation' => 'read-b1']],
      'own office under another municipality' => ['read-b', ['type' => 'status_notes', 'organisation' => $a1]],
      'without office' => ['read-a', ['type' => 'status_notes']],
      'service provider type' => ['read-a', ['type' => 'service_provider', 'organisation' => 'read-a1']],
      'remarks type' => ['read-a', ['type' => 'remarks', 'organisation' => 'read-a1']],
    ];
    foreach ($refused as $case => [$jurisdiction, $query]) {
      $this->assertFalse($this->access('editor', $jurisdiction, $query)->isAllowed(), $case);
    }
  }

  /**
   * Non-string query values are refused, not converted into a 400 exception.
   */
  public function testNonStringScopeIsForbidden(): void {
    foreach ([
      ['type' => ['status_notes'], 'organisation' => 'read-a1'],
      ['type' => [], 'organisation' => 'read-a1'],
      ['type' => 'status_notes', 'organisation' => ['read-a1']],
      ['type' => 'status_notes', 'organisation' => []],
    ] as $query) {
      $this->assertTrue($this->access('editor', 'read-a', $query)->isForbidden());
      $response = $this->send('editor', 'read-a', $query);
      $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }
  }

  /**
   * An office id in the path is never accepted as a municipality.
   */
  public function testOfficeInMunicipalityPathIsForbidden(): void {
    $path_group = (string) $this->groups['A1']->id();
    $query = ['type' => 'status_notes', 'organisation' => 'read-a1'];
    $this->assertTrue($this->access('editor', $path_group, $query)->isForbidden());
    $this->assertSame(403, $this->send('editor', $path_group, $query)->getStatusCode());
  }

  /**
   * Municipality status rights reach both offices through the read branch.
   */
  public function testMunicipalityCaseWorkerReadsBothOffices(): void {
    $account = $this->people['municipalityEditor'];
    $this->assertTrue($account->hasPermission('manage workflow transitions'));
    $this->assertFalse(BeantragtRoles::isManagementAccount($account));
    foreach (['A1' => 'read-a1', 'A2' => 'read-a2'] as $office => $slug) {
      $this->assertFalse($this->groups[$office]->getMember($account));
      $query = ['type' => 'status_notes', 'organisation' => $slug];
      $this->assertTrue($this->access('municipalityEditor', 'read-a', $query)->isAllowed());
      $response = $this->send('municipalityEditor', 'read-a', $query);
      $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
      $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame(['Own ' . $office, 'Shared A'], array_column($payload['data'], 'title'));
    }
    $query = ['type' => 'status_notes', 'organisation' => 'read-b1'];
    $this->assertSame(403, $this->send('municipalityEditor', 'read-b', $query)->getStatusCode());
  }

  /**
   * An office member may read status notes with the action permission.
   */
  public function testMemberMayReadWithTransitionPermission(): void {
    $query = ['type' => 'status_notes', 'organisation' => 'read-a1'];
    $this->assertTrue($this->access('viewer', 'read-a', $query)->isAllowed());
    $this->assertSame(200, $this->send('viewer', 'read-a', $query)->getStatusCode());
    Role::load('case_worker')->revokePermission('manage workflow transitions')->save();
    $this->assertSame(403, $this->send('viewer', 'read-a', $query)->getStatusCode());
    $this->assertFalse($this->access('citizen', 'read-a', $query)->isAllowed(), 'citizen');
    $this->assertFalse($this->accessFor(new AnonymousUserSession(), 'read-a', $query)->isAllowed(), 'anonymous');
  }

  /**
   * An office-only Fachadministration of A1 reads.
   */
  public function testOfficeFachadministrationReads(): void {
    $own = ['type' => 'status_notes', 'organisation' => 'read-a1'];
    $other = ['type' => 'status_notes', 'organisation' => 'read-a2'];
    $this->assertTrue($this->access('officeAdmin', 'read-a', $own)->isAllowed());
    $this->assertSame(['Own A1', 'Shared A'], $this->titles('read-a', $own));
    $this->assertFalse($this->access('officeAdmin', 'read-a', $other)->isAllowed());
  }

  /**
   * Municipality Fachadministration and platform administrator: as today.
   */
  public function testManagementKeepsTodaysAccess(): void {
    foreach (['municipalityAdmin', 'platformAdmin'] as $key) {
      $queries = [
        ['type' => 'remarks'],
        ['type' => 'status_notes', 'organisation' => 'read-a2'],
        ['type' => 'service_provider'],
      ];
      foreach ($queries as $query) {
        $this->assertTrue($this->access($key, 'read-a', $query)->isAllowed(), $key . ' ' . json_encode($query));
      }
      $office_a2 = ['type' => 'status_notes', 'organisation' => 'read-a2'];
      $this->assertSame(['Own A2', 'Shared A'], $this->titles('read-a', $office_a2));
    }
    $this->assertFalse($this->access('municipalityAdmin', 'read-b', ['type' => 'status_notes'])->isAllowed());
  }

  /**
   * Two callers in a row do not share an access decision.
   */
  public function testDecisionVariesByCallerAndQuery(): void {
    $query = ['type' => 'status_notes', 'organisation' => 'read-a1'];
    $first = $this->access('editor', 'read-a', $query);
    $second = $this->access('editorB', 'read-a', $query);
    $this->assertTrue($first->isAllowed());
    $this->assertFalse($second->isAllowed());
    foreach ([$first, $second] as $result) {
      $contexts = $result->getCacheContexts();
      foreach (['user', 'url.query_args:type', 'url.query_args:organisation'] as $context) {
        $this->assertContains($context, $contexts);
      }
    }
  }

  /**
   * Sequential HTTP requests to one URL cannot reuse another caller's list.
   */
  public function testHttpResponsesDoNotLeakBetweenCallers(): void {
    $query = ['type' => 'status_notes', 'organisation' => 'read-a1'];
    // Same URL, same container and cache bins, no cache clear between callers.
    $first = $this->send('editor', 'read-a', $query);
    $this->assertSame(200, $first->getStatusCode(), (string) $first->getContent());
    $payload = json_decode((string) $first->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['Own A1', 'Shared A'], array_column($payload['data'], 'title'));
    $this->assertTrue($first->headers->hasCacheControlDirective('private'));
    $this->assertTrue($first->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('UNCACHEABLE (no cacheability)', $first->headers->get('X-Drupal-Dynamic-Cache'));

    $second = $this->send('editorB', 'read-a', $query);
    $this->assertSame(403, $second->getStatusCode(), (string) $second->getContent());
    $this->assertStringNotContainsString('Own A1', (string) $second->getContent());
    $this->assertStringNotContainsString('Shared A', (string) $second->getContent());
  }

  /**
   * Sends a main HTTP request as the selected account through the real router.
   *
   * @param string $persona
   *   Key of the account in the test's persona list.
   * @param string $jurisdiction
   *   Slug of the municipality in the path.
   * @param array<string, mixed> $query
   *   Query arguments.
   */
  private function send(string $persona, string $jurisdiction, array $query): Response {
    $current_user = $this->container->get('current_user');
    $current_user->setAccount($this->people[$persona]);
    $request = Request::create('/api/admin/jurisdictions/' . $jurisdiction . '/boilerplates', 'GET', $query, server: ['HTTP_ACCEPT' => 'application/json']);
    try {
      return $this->container->get('http_kernel')->handle($request);
    }
    finally {
      $current_user->setAccount(new AnonymousUserSession());
    }
  }

  /**
   * Asks the access manager about the route for a persona.
   *
   * @param string $persona
   *   Persona key.
   * @param string $jurisdiction
   *   Municipality slug of the path.
   * @param array<string, mixed> $query
   *   Query arguments.
   */
  private function access(string $persona, string $jurisdiction, array $query): AccessResultInterface {
    return $this->accessFor($this->people[$persona], $jurisdiction, $query);
  }

  /**
   * Asks the access manager about the route for an account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The caller.
   * @param string $jurisdiction
   *   Municipality slug of the path.
   * @param array<string, mixed> $query
   *   Query arguments.
   */
  private function accessFor(AccountInterface $account, string $jurisdiction, array $query): AccessResultInterface {
    $request = $this->request($jurisdiction, $query);
    $result = $this->container->get('access_manager')->checkRequest($request, $account, TRUE);
    assert($result instanceof AccessResultInterface);
    return $result;
  }

  /**
   * Returns the titles the list answers for a request, sorted.
   *
   * @param string $jurisdiction
   *   Municipality slug of the path.
   * @param array<string, mixed> $query
   *   Query arguments.
   *
   * @return string[]
   *   Titles.
   */
  private function titles(string $jurisdiction, array $query): array {
    $controller = BoilerplateController::create($this->container);
    $response = $controller->listBoilerplates($this->request($jurisdiction, $query), $jurisdiction);
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $titles = array_column($payload['data'], 'title');
    sort($titles);
    return $titles;
  }

  /**
   * Builds a routed request for the text-block list.
   *
   * @param string $jurisdiction
   *   Municipality slug of the path.
   * @param array<string, mixed> $query
   *   Query arguments.
   */
  private function request(string $jurisdiction, array $query): Request {
    $request = Request::create('/api/admin/jurisdictions/' . $jurisdiction . '/boilerplates', 'GET', $query);
    $request->attributes->add($this->container->get('router.no_access_checks')->matchRequest($request));
    return $request;
  }

  /**
   * Creates an office of a municipality.
   */
  private function office(string $slug, string $municipality): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => 'Office ' . $slug,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $this->groups[$municipality]->id()],
    ]);
  }

  /**
   * Creates a published text block.
   */
  private function boilerplate(string $title, string $municipality, ?string $office = NULL, string $type = 'status_notes'): void {
    $values = [
      'type' => 'boilerplate',
      'title' => $title,
      'status' => 1,
      'body' => ['value' => '<p>' . $title . '</p>', 'format' => 'plain_text'],
      'field_boilerplate_type' => $type,
      'field_jurisdiction' => ['target_id' => $this->groups[$municipality]->id()],
    ];
    if ($office !== NULL) {
      $values['field_organisation'] = ['target_id' => $this->groups[$office]->id()];
    }
    $this->container->get('entity_type.manager')->getStorage('node')->create($values)->save();
  }

  /**
   * Creates an active persona with an optional site role and membership.
   */
  private function persona(string $name, ?string $site_role, ?string $group, ?string $group_role): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => $name . '@example.org']);
    assert($user instanceof UserInterface);
    if ($site_role !== NULL) {
      $user->addRole($site_role);
    }
    $user->activate()->save();
    if ($group !== NULL && $group_role !== NULL) {
      $this->groups[$group]->addMember($user, ['group_roles' => [$group_role]]);
    }
    return $user;
  }

}
