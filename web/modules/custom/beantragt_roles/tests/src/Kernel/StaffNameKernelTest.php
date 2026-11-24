<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_roles\Kernel;

use Symfony\Component\HttpFoundation\Response;
use Drupal\beantragt_roles\StaffName;
use Drupal\beantragt_schema\Access\JsonApiReadGateAccessCheck;
use Drupal\beantragt_roles\StaffNameFields;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff names: display name, field access and label visibility.
 */
#[Group('beantragt_roles')]
final class StaffNameKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'field',
    'file',
    'jsonapi',
    'json_form_widget',
    'options',
    'serialization',
    'system',
    'user',
    'beantragt_roles_api',
    // Owns the JSON:API read gate; a dependency of beantragt_roles.
    'beantragt_schema',
    'beantragt_roles',
  ];

  /**
   * A case worker with an invented name.
   */
  private UserInterface $caseWorker;

  /**
   * A second case worker with an invented name.
   */
  private UserInterface $colleague;

  /**
   * A citizen account.
   */
  private UserInterface $citizen;

  /**
   * A service account.
   */
  private UserInterface $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    $messages = StaffNameFields::ensure($this->container->get('entity_type.manager'));
    $this->assertNotSame([], $messages);
    $this->assertSame([], StaffNameFields::ensure($this->container->get('entity_type.manager')), 'Field setup is idempotent.');

    foreach (['case_worker', 'department_admin', 'administrator', 'service_account'] as $role_id) {
      Role::create(['id' => $role_id, 'label' => $role_id])->save();
    }
    // Keep uid 1 out of the personas below.
    $this->createUser([], 'system-account');

    $this->caseWorker = $this->createNamedUser('sachbearbeitung_test', ['case_worker'], 'Aylin', 'Demir');
    $this->colleague = $this->createNamedUser('sachbearbeitung_kollege', ['case_worker'], 'Jonas', 'Becker');
    $this->citizen = $this->createNamedUser('buerger_test', [], NULL, NULL);
    $this->service = $this->createNamedUser('service_test', ['service_account'], NULL, NULL);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The display name is "First Last", one part alone, or the login name.
   */
  public function testDisplayName(): void {
    $this->assertSame('Aylin Demir', $this->caseWorker->getDisplayName());
    $this->assertSame('sachbearbeitung_test', $this->caseWorker->getAccountName());
    $this->assertSame('buerger_test', $this->citizen->getDisplayName());

    $system = $this->createNamedUser('admin_test', ['administrator'], NULL, 'Systemadministration');
    $this->assertSame('Systemadministration', $system->getDisplayName());

    // Session accounts (current user) resolve through the stored entity.
    $session = new UserSession([
      'uid' => (int) $this->caseWorker->id(),
      'name' => 'sachbearbeitung_test',
      'roles' => ['authenticated', 'case_worker'],
    ]);
    $this->assertSame('Aylin Demir', $session->getDisplayName());

    $this->assertSame('Anna Maria Beispiel', StaffName::compose("  Anna\tMaria ", 'Beispiel'));
    $this->assertSame('Beispiel script', StaffName::compose(NULL, 'Beispiel<script>'));
    $this->assertSame(StaffName::MAX_LENGTH, mb_strlen(StaffName::compose(str_repeat('ä', 80), NULL)));
  }

  /**
   * Markup characters are rejected on save validation.
   */
  public function testNameValidationRejectsMarkup(): void {
    $this->caseWorker->set(StaffName::FIRST_NAME_FIELD, 'Aylin<b>');
    $violations = $this->caseWorker->validate()->getByField(StaffName::FIRST_NAME_FIELD);
    $this->assertCount(1, $violations);

    $this->caseWorker->set(StaffName::FIRST_NAME_FIELD, str_repeat('a', StaffName::MAX_LENGTH + 1));
    $this->assertGreaterThan(0, $this->caseWorker->validate()->getByField(StaffName::FIRST_NAME_FIELD)->count());

    $this->caseWorker->set(StaffName::FIRST_NAME_FIELD, "Anne-Marie O'Neill");
    $this->assertCount(0, $this->caseWorker->validate()->getByField(StaffName::FIRST_NAME_FIELD));
  }

  /**
   * Name fields are visible to staff and the account itself only.
   */
  public function testFieldAccess(): void {
    $first = $this->caseWorker->get(StaffName::FIRST_NAME_FIELD);
    $anonymous = new AnonymousUserSession();

    $this->assertFalse($first->access('view', $anonymous));
    $this->assertFalse($first->access('view', $this->citizen));
    $this->assertFalse($first->access('view', $this->service));
    $this->assertTrue($first->access('view', $this->colleague));
    $this->assertTrue($first->access('view', $this->caseWorker));

    $this->assertTrue($first->access('edit', $this->caseWorker), 'Staff maintain their own name.');
    $this->assertFalse($first->access('edit', $this->colleague));
    $this->assertFalse($first->access('edit', $this->citizen));
    $user_admin = $this->createUser(['administer users']);
    $this->assertTrue($first->access('edit', $user_admin));

    $own_citizen = $this->citizen->get(StaffName::LAST_NAME_FIELD);
    $this->assertTrue($own_citizen->access('view', $this->citizen));
    $this->assertFalse($own_citizen->access('edit', $this->citizen), 'Citizens have no staff name to maintain.');
  }

  /**
   * The user label, which carries the name, is hidden from citizens.
   */
  public function testLabelAccess(): void {
    $anonymous = new AnonymousUserSession();
    $this->assertFalse($this->caseWorker->access('view label', $anonymous));
    $this->assertFalse($this->caseWorker->access('view label', $this->citizen));
    $this->assertFalse($this->colleague->access('view label', $this->citizen));
    $this->assertTrue($this->caseWorker->access('view label', $this->colleague));
    $this->assertTrue($this->caseWorker->access('view label', $this->caseWorker));
    $this->assertTrue($this->caseWorker->access('view label', $this->service));
    $this->assertTrue($this->citizen->access('view label', $this->citizen));
  }

  /**
   * Role based decisions are cached per role set, identity checks per user.
   */
  public function testAccessCacheContexts(): void {
    $staff_label = $this->caseWorker->access('view label', $this->colleague, TRUE);
    $this->assertContains('user.roles', $staff_label->getCacheContexts());
    $this->assertNotContains('user', $staff_label->getCacheContexts());
    $this->assertContains('user:' . $this->caseWorker->id(), $staff_label->getCacheTags());

    $this->assertContains('user', $this->caseWorker->access('view label', $this->citizen, TRUE)->getCacheContexts());
    $this->assertContains('user', $this->citizen->access('view label', $this->citizen, TRUE)->getCacheContexts());

    $first = $this->caseWorker->get(StaffName::FIRST_NAME_FIELD);
    $staff_view = $first->access('view', $this->colleague, TRUE);
    $this->assertTrue($staff_view->isAllowed());
    $this->assertContains('user.roles', $staff_view->getCacheContexts());
    $this->assertNotContains('user', $staff_view->getCacheContexts());
    $this->assertContains('user', $first->access('view', $this->citizen, TRUE)->getCacheContexts());

    $user_admin = $this->createUser(['administer users']);
    $admin_edit = $first->access('edit', $user_admin, TRUE);
    $this->assertTrue($admin_edit->isAllowed());
    $this->assertContains('user.permissions', $admin_edit->getCacheContexts());
    $this->assertNotContains('user', $admin_edit->getCacheContexts());
    $this->assertContains('user', $first->access('edit', $this->caseWorker, TRUE)->getCacheContexts());
  }

  /**
   * JSON:API never prints staff names to anonymous visitors or citizens.
   *
   * The user list itself is behind the JSON:API read gate: anonymous
   * callers get the Basic challenge, citizens the fixed refusal. On those two
   * refusals "no staff names" cannot fail any more (contract 5.2 keeps it);
   * the statuses and reasons carry the list checks, the individual routes
   * below carry the name checks.
   */
  public function testJsonApiHidesStaffNames(): void {
    $anonymous = $this->jsonApiGet('/jsonapi/user/user', NULL);
    $this->assertSame(401, $anonymous->getStatusCode(), (string) $anonymous->getContent());
    $this->assertStringStartsWith('Basic realm=', (string) $anonymous->headers->get('WWW-Authenticate'));
    $this->assertNoStaffNames((string) $anonymous->getContent());

    $single = $this->jsonApiGet('/jsonapi/user/user/' . $this->caseWorker->uuid(), NULL);
    $this->assertNoStaffNames((string) $single->getContent());

    $citizen = $this->jsonApiGet('/jsonapi/user/user', $this->citizen);
    $this->assertSame(403, $citizen->getStatusCode(), (string) $citizen->getContent());
    $this->assertStringContainsString(json_encode(JsonApiReadGateAccessCheck::REASON, JSON_THROW_ON_ERROR), (string) $citizen->getContent());
    $this->assertNoStaffNames((string) $citizen->getContent());

    $staff = $this->jsonApiGet('/jsonapi/user/user/' . $this->colleague->uuid(), $this->caseWorker);
    $this->assertSame(200, $staff->getStatusCode(), (string) $staff->getContent());
    $this->assertStringContainsString('Jonas Becker', (string) $staff->getContent());
  }

  /**
   * The account form shows both name fields.
   */
  public function testAccountFormShowsNameFields(): void {
    $display = EntityFormDisplay::load('user.user.default');
    $this->assertNotNull($display);
    $this->assertNotNull($display->getComponent(StaffName::FIRST_NAME_FIELD));
    $this->assertNotNull($display->getComponent(StaffName::LAST_NAME_FIELD));
  }

  /**
   * Creates an active user with roles and optional name parts.
   *
   * @param string $name
   *   Login name.
   * @param string[] $roles
   *   Role ids.
   * @param string|null $first
   *   First name.
   * @param string|null $last
   *   Last name.
   */
  private function createNamedUser(string $name, array $roles, ?string $first, ?string $last): UserInterface {
    $user = $this->createUser([], $name);
    foreach ($roles as $role) {
      $user->addRole($role);
    }
    $user->set(StaffName::FIRST_NAME_FIELD, $first);
    $user->set(StaffName::LAST_NAME_FIELD, $last);
    $user->setPassword('synthetic-staff-name-password');
    $user->save();
    return $user;
  }

  /**
   * Issues a JSON:API GET, optionally with basic auth.
   */
  private function jsonApiGet(string $path, ?UserInterface $account): Response {
    $headers = ['HTTP_ACCEPT' => 'application/vnd.api+json'];
    if ($account !== NULL) {
      $headers['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($account->getAccountName() . ':synthetic-staff-name-password');
      $headers['PHP_AUTH_USER'] = $account->getAccountName();
      $headers['PHP_AUTH_PW'] = 'synthetic-staff-name-password';
    }
    return $this->container->get('http_kernel')->handle(Request::create($path, 'GET', server: $headers));
  }

  /**
   * Asserts that no invented staff name appears in a response body.
   */
  private function assertNoStaffNames(string $body): void {
    foreach (['Aylin', 'Demir', 'Jonas', 'Becker'] as $part) {
      $this->assertStringNotContainsString($part, $body);
    }
  }

}
