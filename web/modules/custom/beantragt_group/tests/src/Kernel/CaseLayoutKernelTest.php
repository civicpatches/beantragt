<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_group\Controller\CaseLayoutController;
use Drupal\beantragt_group\Service\CaseLayout;
use Drupal\beantragt_group\Service\SharedAccountReadOnly;
use Drupal\beantragt_group\Service\SharedAccountReadOnlyInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Exercises real memberships, user.data and tenant content layout storage.
 */
#[Group('beantragt_group')]
final class CaseLayoutKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization',
    'beantragt_roles_api', 'beantragt_schema', 'beantragt_workflow_status',
    'beantragt_application', 'beantragt_group',
  ];

  /**
   * Municipality containing both test offices.
   */
  private GroupInterface $jurisdiction;

  /**
   * First office.
   */
  private GroupInterface $office;

  /**
   * Second office.
   */
  private GroupInterface $otherOffice;

  /**
   * Case worker in the first office.
   */
  private UserInterface $staff;

  /**
   * Manager of the first office.
   */
  private UserInterface $manager;

  /**
   * Whether routes have been built for the in-process HTTP kernel.
   */
  private bool $routesBuilt = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('workflow_status');
    foreach (['case_worker', 'department_admin'] as $role) {
      if (Role::load($role) === NULL) {
        Role::create(['id' => $role, 'label' => $role])->save();
      }
    }
    $this->jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Layout municipality']);
    $this->office = $this->createGroup([
      'type' => 'org',
      'label' => 'Layout office A',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $this->otherOffice = $this->createGroup([
      'type' => 'org',
      'label' => 'Layout office B',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $this->staff = $this->persona('layout_staff', 'case_worker');
    $this->manager = $this->persona('layout_manager', 'department_admin');
    $this->office->addMember($this->staff, ['group_roles' => ['org-member']]);
    $this->office->addMember($this->manager, ['group_roles' => ['org-admin']]);
  }

  /**
   * Anonymous, citizen, other office and other municipality access is denied.
   */
  public function testAccessMatrixAndManagement(): void {
    $access = $this->container->get('beantragt_group.case_layout_access');
    $citizen = $this->persona('layout_citizen');
    $this->office->addMember($citizen, ['group_roles' => ['org-member']]);
    foreach ([new AnonymousUserSession(), $citizen] as $account) {
      $this->assertFalse($access->allows($account, $this->office));
      $this->setCurrentUser($account);
      $controller = CaseLayoutController::create($this->container);
      $this->assertSame(403, $controller->preferences(Request::create('/'), (string) $this->office->id())->getStatusCode());
      $this->assertSame(403, $controller->workspace(Request::create('/', 'PATCH'), (string) $this->office->id())->getStatusCode());
    }
    $this->assertTrue($access->allows($this->staff, $this->office));
    $this->assertFalse($access->allows($this->staff, $this->office, TRUE));
    $this->assertFalse($access->allows($this->staff, $this->otherOffice));
    $this->assertFalse($access->allows($this->staff, $this->jurisdiction));
    $this->assertTrue($access->allows($this->manager, $this->office, TRUE));
    $this->assertFalse($access->allows($this->manager, $this->otherOffice, TRUE));
    $other_jur = $this->createGroup(['type' => 'jur', 'label' => 'Other municipality']);
    $this->assertFalse($access->allows($this->manager, $other_jur));
    $full = $this->persona('layout_full', 'department_admin');
    $this->jurisdiction->addMember($full, ['group_roles' => ['jur-admin']]);
    $this->assertTrue($access->allows($full, $this->office, TRUE));
    $this->assertTrue($access->allows($full, $this->jurisdiction, TRUE));
    $this->assertFalse($access->allows($full, $other_jur));
  }

  /**
   * User and workspace preferences remain isolated and reset independently.
   */
  public function testPersonalAndWorkspacePersistence(): void {
    $layout = ['version' => 1, 'left' => CaseLayout::SECTIONS, 'right' => []];
    $request = Request::create('/', 'PATCH', [], [], [], [], json_encode(['layout' => $layout]));
    $this->setCurrentUser($this->staff);
    $controller = CaseLayoutController::create($this->container);
    $this->assertSame(200, $controller->preferences($request, (string) $this->office->id())->getStatusCode());
    $this->assertSame(403, $controller->workspace($request, (string) $this->office->id())->getStatusCode());
    $this->assertSame(403, $controller->preferences($request, (string) $this->otherOffice->id())->getStatusCode());
    $personal = json_decode($controller->preferences(Request::create('/'), (string) $this->office->id())->getContent(), TRUE);
    $this->assertSame($layout, $personal['layout']);
    $this->container->get('user.data')->set(
      'beantragt_group',
      (int) $this->staff->id(),
      'case_layout.' . $this->office->id(),
      ['version' => 0],
    );
    $invalid_stored = json_decode($controller->preferences(Request::create('/'), (string) $this->office->id())->getContent(), TRUE);
    $this->assertNull($invalid_stored['layout']);
    $this->assertSame(200, $controller->preferences($request, (string) $this->office->id())->getStatusCode());

    $this->setCurrentUser($this->manager);
    $controller = CaseLayoutController::create($this->container);
    $this->assertSame(200, $controller->workspace($request, (string) $this->office->id())->getStatusCode());
    $result = json_decode($controller->preferences(Request::create('/'), (string) $this->office->id())->getContent(), TRUE);
    $this->assertNull($result['layout']);
    $this->assertSame($layout, $result['workspaceDefault']);
    $store = $this->container->get('beantragt_group.case_layout_store');
    $this->assertNull($store->get($this->otherOffice));
    $this->assertNull($store->get($this->jurisdiction));

    $invalid_payload = ['layout' => $layout, 'uid' => $this->staff->id()];
    $invalid = Request::create('/', 'PATCH', [], [], [], [], json_encode($invalid_payload));
    $this->assertSame(400, $controller->preferences($invalid, (string) $this->office->id())->getStatusCode());
    $oversized = Request::create('/', 'PATCH', [], [], [], [], str_repeat(' ', 2049));
    $this->assertSame(400, $controller->preferences($oversized, (string) $this->office->id())->getStatusCode());
    $reset = Request::create('/', 'PATCH', [], [], [], [], '{"layout":null}');
    $this->assertSame(200, $controller->workspace($reset, (string) $this->office->id())->getStatusCode());
    $this->assertNull($store->get($this->office));
    $this->setCurrentUser($this->staff);
    $controller = CaseLayoutController::create($this->container);
    $result = json_decode($controller->preferences($reset, (string) $this->office->id())->getContent(), TRUE);
    $this->assertNull($result['layout']);
  }

  /**
   * Independent office writes and resets preserve all other tenant settings.
   */
  public function testWorkspaceWritesPreserveOtherSettings(): void {
    $config = $this->container->get('beantragt_group.jurisdiction_config');
    $config->updateSection($this->jurisdiction, 'theme', ['primary' => 'red']);
    $store = $this->container->get('beantragt_group.case_layout_store');
    $left = ['version' => 1, 'left' => CaseLayout::SECTIONS, 'right' => []];
    $right = ['version' => 1, 'left' => [], 'right' => CaseLayout::SECTIONS];
    $store->set($this->office, $left);
    $store->set($this->otherOffice, $right);
    $this->assertSame($left, $store->get($this->office));
    $this->assertSame($right, $store->get($this->otherOffice));
    $store->set($this->office, NULL);
    $this->assertNull($store->get($this->office));
    $this->assertSame($right, $store->get($this->otherOffice));
    $fresh = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($this->jurisdiction->id());
    $this->assertSame(['primary' => 'red'], $config->getSection($fresh, 'theme'));
    $this->assertSame([], $this->container->get('beantragt_group.jurisdiction_config_blob_validator')->validateRaw($fresh->get('field_jur_config')->value));
    // Normal writes reject these values at the entity boundary. Inject a
    // damaged storage row to exercise defensive reads and refused repairs.
    foreach (['{"staffForms":{"caseLayouts":"invalid"}}', '[]'] as $damaged) {
      $this->container->get('database')->update('group__field_jur_config')
        ->fields(['field_jur_config_value' => $damaged])
        ->condition('entity_id', $this->jurisdiction->id())
        ->execute();
      $this->container->get('entity_type.manager')->getStorage('group')->resetCache([$this->jurisdiction->id()]);
      $this->assertNull($store->get($this->office));
      try {
        $store->set($this->office, $left);
        $this->fail('A malformed settings blob must not be overwritten: ' . $damaged);
      }
      catch (\InvalidArgumentException $exception) {
        $this->assertStringStartsWith('Invalid workspace', $exception->getMessage());
      }
    }
  }

  /**
   * Shared demo logins cannot change another visitor's layout preferences.
   */
  public function testSharedDemoAccountIsReadOnly(): void {
    $this->setCurrentUser($this->manager);
    $demo = $this->createMock(SharedAccountReadOnlyInterface::class);
    $demo->method('isReadOnly')->willReturn(TRUE);
    $controller = new CaseLayoutController(
      $this->container->get('entity_type.manager'),
      $this->manager,
      $this->container->get('user.data'),
      $this->container->get('beantragt_group.case_layout_access'),
      $this->container->get('beantragt_group.case_layout_store'),
      $demo,
    );
    $layout = ['version' => 1, 'left' => CaseLayout::SECTIONS, 'right' => []];
    $this->container->get('user.data')->set(
      'beantragt_group',
      (int) $this->manager->id(),
      'case_layout.' . $this->office->id(),
      $layout,
    );
    $response = json_decode($controller->preferences(Request::create('/'), (string) $this->office->id())->getContent(), TRUE);
    $this->assertNull($response['layout']);
    $this->assertFalse($response['writable']);
    $this->assertFalse($response['workspaceDefaultWritable']);
    $patch = Request::create('/', 'PATCH', [], [], [], [], json_encode(['layout' => $layout]));
    $this->assertSame(403, $controller->preferences($patch, (string) $this->office->id())->getStatusCode());
    $this->assertSame(403, $controller->workspace($patch, (string) $this->office->id())->getStatusCode());
  }

  /**
   * The public module resolves its own default without loading demo classes.
   */
  public function testDefaultPolicyWithoutDemoModule(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('beantragt_demo'));
    $policy = $this->container->get('beantragt_group.shared_account_read_only');
    $this->assertInstanceOf(SharedAccountReadOnly::class, $policy);
    $this->assertFalse($policy->isReadOnly($this->manager));
  }

  /**
   * Both PATCH routes declare login and the session's CSRF header token.
   */
  public function testPatchRoutesDeclareLoginAndCsrf(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (['beantragt_group.case_layout_patch', 'beantragt_group.case_layout_workspace_patch'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertSame(['PATCH'], $route->getMethods(), $name);
      $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'), $name);
      $this->assertSame('TRUE', $route->getRequirement('_user_is_logged_in'), $name);
    }
  }

  /**
   * A real PATCH without the CSRF header is refused.
   *
   * One request per test: the kernel's 403 page sub-request leaves routing
   * state behind that turns a second request into a 405.
   */
  #[DataProvider('patchSuffixes')]
  public function testPatchRoutesRequireCsrfHeader(string $suffix): void {
    $denied = $this->patch($this->office, $this->manager, $suffix, FALSE);
    $this->assertSame(403, $denied->getStatusCode());
  }

  /**
   * A real anonymous PATCH is refused even with a valid token.
   */
  #[DataProvider('patchSuffixes')]
  public function testPatchRoutesRefuseAnonymous(string $suffix): void {
    $this->assertSame(403, $this->patch($this->office, NULL, $suffix)->getStatusCode());
  }

  /**
   * The same authorized PATCH succeeds on both routes.
   */
  #[DataProvider('patchSuffixes')]
  public function testAuthorizedPatchSucceeds(string $suffix): void {
    $allowed = $this->patch($this->office, $this->manager, $suffix);
    $this->assertSame(200, $allowed->getStatusCode(), (string) $allowed->getContent());
  }

  /**
   * Personal and workspace-default PATCH routes.
   */
  public static function patchSuffixes(): array {
    return ['personal' => [''], 'workspace default' => ['/workspace']];
  }

  /**
   * A child jurisdiction's administrator cannot change its parent's default.
   */
  public function testChildAdminCannotChangeParentDefault(): void {
    $child = $this->createGroup([
      'type' => 'jur',
      'label' => 'Child municipality',
      'field_parent_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
    ]);
    $child_admin = $this->persona('layout_child_admin', 'department_admin');
    $child->addMember($child_admin, ['group_roles' => ['jur-admin']]);
    $this->assertSame(200, $this->patch($child, $child_admin, '/workspace')->getStatusCode());
    $this->assertSame(403, $this->patch($this->jurisdiction, $child_admin, '/workspace')->getStatusCode());
  }

  /**
   * An otherwise authorized manager cannot write an unpublished workspace.
   */
  public function testPatchRoutesRefuseUnpublishedWorkspace(): void {
    foreach (['', '/workspace'] as $suffix) {
      $this->assertSame(200, $this->patch($this->office, $this->manager, $suffix)->getStatusCode());
    }
    $this->office->setUnpublished()->save();
    foreach (['', '/workspace'] as $suffix) {
      $this->assertSame(403, $this->patch($this->office, $this->manager, $suffix)->getStatusCode());
    }
  }

  /**
   * An org-admin membership alone does not give case workers management.
   */
  public function testCaseWorkerOrgAdminCannotChangeDefault(): void {
    $worker_admin = $this->persona('layout_worker_admin', 'case_worker');
    $this->office->addMember($worker_admin, ['group_roles' => ['org-admin']]);
    $this->assertSame(200, $this->patch($this->office, $worker_admin)->getStatusCode());
    $this->assertSame(403, $this->patch($this->office, $worker_admin, '/workspace')->getStatusCode());
  }

  /**
   * A management role alone does not elevate an org-member membership.
   */
  public function testDepartmentAdminOrgMemberCannotChangeDefault(): void {
    $manager_member = $this->persona('layout_manager_member', 'department_admin');
    $this->office->addMember($manager_member, ['group_roles' => ['org-member']]);
    $this->assertSame(200, $this->patch($this->office, $manager_member)->getStatusCode());
    $this->assertSame(403, $this->patch($this->office, $manager_member, '/workspace')->getStatusCode());
  }

  /**
   * A real presave rejection becomes a generic conflict without a stack trace.
   */
  public function testPresaveRejectionReturnsGenericConflict(): void {
    $raw = '{"unknown_sensitive_section":{"value":"private fixture"}}';
    $this->container->get('beantragt_group.jurisdiction_config')->updateSection($this->jurisdiction, 'theme', ['primary' => 'red']);
    $this->container->get('database')->update('group__field_jur_config')
      ->fields(['field_jur_config_value' => $raw])
      ->condition('entity_id', $this->jurisdiction->id())
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    // Logging the rejection is fine; the stored private value must never
    // reach a log message or its context.
    $logged = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(function ($level, $message, array $context = []) use (&$logged): void {
      $logged[] = $message . ' ' . json_encode(array_map(static fn ($value) => is_scalar($value) ? $value : get_debug_type($value), $context));
    });
    $this->container->get('logger.factory')->addLogger($logger);
    $response = $this->patch($this->office, $this->manager, '/workspace');
    $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['error' => 'Workspace layout could not be saved.'], json_decode((string) $response->getContent(), TRUE));
    $stored = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($this->jurisdiction->id());
    $this->assertSame($raw, $stored->get('field_jur_config')->value);
    foreach ($logged as $line) {
      $this->assertStringNotContainsString('private fixture', $line);
    }
  }

  /**
   * Sends a cookie-authenticated request through routing and access checking.
   */
  private function patch(GroupInterface $workspace, ?UserInterface $account, string $suffix = '', bool $csrf = TRUE): Response {
    if (!$this->routesBuilt) {
      $this->container->get('router.builder')->rebuild();
      $this->routesBuilt = TRUE;
    }
    $request = Request::create('/api/staff/case-layout/' . $workspace->id() . $suffix, 'PATCH', server: [
      'CONTENT_TYPE' => 'application/json',
      'HTTP_ACCEPT' => 'application/json',
    ], content: json_encode(['layout' => ['version' => 1, 'left' => CaseLayout::SECTIONS, 'right' => []]], JSON_THROW_ON_ERROR));
    $session = new Session(new MockArraySessionStorage());
    if ($account !== NULL) {
      $session->set('uid', $account->id());
    }
    $request->setSession($session);
    $options = $this->container->get('session_configuration')->getOptions($request);
    $request->cookies->set($options['name'], $session->getId());
    if ($csrf) {
      $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    }
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    // A denied request renders the 403 page through a GET sub-request, which
    // leaves the shared request context on GET; reset it for every request.
    $this->container->get('router.request_context')->fromRequest($request);
    $this->container->get('router.route_provider')->reset();
    while ($this->container->get('request_stack')->getCurrentRequest()) {
      $this->container->get('request_stack')->pop();
    }
    try {
      return $this->container->get('http_kernel')->handle($request);
    }
    finally {
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    }
  }

  /**
   * Creates a human test account without granting any implicit memberships.
   */
  private function persona(string $name, ?string $role = NULL): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => $name . '@example.org']);
    assert($user instanceof UserInterface);
    if ($role !== NULL) {
      $user->addRole($role);
    }
    $user->activate()->save();
    return $user;
  }

}
