<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_schema\Access\JsonApiReadGateAccessCheck;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read gate and the membership rule meet on one membership.
 *
 * Neither strand pins this alone (demo candidate, backend integration BAC-8):
 * a JSON:API relationship write on a membership is a gated route (the read
 * gate refuses a citizen first) that ends in entity update (the membership
 * rule refuses an editor case worker). The membership list is a gated
 * collection: refused to a citizen, open to staff. Sent through the HTTP
 * kernel with Basic auth, as the read gate's own kernel test does.
 */
#[Group('beantragt_group')]
#[Group('beantragt_schema')]
final class MembershipJsonApiGateAndRuleKernelTest extends GroupKernelTestBase {

  /**
   * The Basic auth password of the fixture accounts.
   */
  private const PASSWORD = 'synthetic-membership-gate-password';

  /**
   * The JSON:API media type.
   */
  private const API_JSON = 'application/vnd.api+json';

  /**
   * The membership rule's fixed reason.
   */
  private const RULE_REASON = 'Memberships and groups are written only by product code or a platform administrator.';

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
   * Office A1.
   */
  private GroupInterface $office;

  /**
   * The colleague's membership in A1 (org-member).
   */
  private GroupMembershipInterface $colleagueMembership;

  /**
   * Editor case worker: case_worker, org-admin in A1.
   */
  private UserInterface $editor;

  /**
   * A citizen without any role or membership.
   */
  private UserInterface $citizen;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['user', 'system', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    // The product runs JSON:API in write mode; read-only mode would filter
    // the write routes out before the gate.
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    if (Role::load('case_worker') === NULL) {
      Role::create(['id' => 'case_worker', 'label' => 'case_worker'])->save();
    }

    $municipality = $this->createGroup(['type' => 'jur', 'label' => 'Gate Municipality', 'field_slug' => 'gate-town']);
    $this->office = $this->createGroup([
      'type' => 'org',
      'label' => 'Gate Office',
      'field_slug' => 'gate-office',
      'field_jurisdiction' => ['target_id' => $municipality->id()],
    ]);
    $this->editor = $this->account('gate_editor', 'case_worker');
    $this->office->addMember($this->editor, ['group_roles' => ['org-admin']]);
    $colleague = $this->account('gate_colleague', 'case_worker');
    $this->office->addMember($colleague, ['group_roles' => ['org-member']]);
    $this->citizen = $this->account('gate_citizen', NULL);
    $membership = GroupMembership::loadSingle($this->office, $colleague);
    $this->assertInstanceOf(GroupMembershipInterface::class, $membership);
    $this->colleagueMembership = $membership;
  }

  /**
   * A relationship write on a membership: the gate, then the rule.
   */
  public function testRelationshipWriteOnMembershipMeetsGateThenRule(): void {
    $url = '/jsonapi/group_relationship/org-group_membership/' . $this->colleagueMembership->uuid() . '/relationships/group_roles';
    $role = $this->container->get('entity_type.manager')->getStorage('group_role')->load('org-admin');
    $this->assertNotNull($role);
    $document = json_encode(['data' => [['type' => 'group_role--group_role', 'id' => $role->uuid()]]], JSON_THROW_ON_ERROR);

    $citizen = $this->send('PATCH', $url, $this->citizen, $document);
    $this->assertSame(403, $citizen->getStatusCode(), (string) $citizen->getContent());
    $this->assertSame(JsonApiReadGateAccessCheck::REASON, $this->detail($citizen), 'The citizen meets the read gate.');

    $editor = $this->send('PATCH', $url, $this->editor, $document);
    $this->assertSame(403, $editor->getStatusCode(), (string) $editor->getContent());
    $this->assertNotSame(JsonApiReadGateAccessCheck::REASON, $this->detail($editor), 'The editor passes the gate as staff.');
    $this->assertStringContainsString(self::RULE_REASON, $this->detail($editor), 'The editor meets the membership rule.');

    $this->assertSame(['org-member'], $this->colleagueRoles(), 'The roles are unchanged.');
  }

  /**
   * The membership list is blocked even when staff pass the read gate.
   */
  public function testMembershipListMeetsTheGate(): void {
    $url = '/jsonapi/group_relationship/org-group_membership';

    $citizen = $this->send('GET', $url, $this->citizen);
    $this->assertSame(403, $citizen->getStatusCode(), (string) $citizen->getContent());
    $this->assertSame(JsonApiReadGateAccessCheck::REASON, $this->detail($citizen));

    foreach ([$url, $url . '?' . http_build_query(['filter' => ['id' => $this->colleagueMembership->uuid()]])] as $path) {
      $editor = $this->send('GET', $path, $this->editor);
      $this->assertSame(403, $editor->getStatusCode(), (string) $editor->getContent());
      $this->assertNotSame(JsonApiReadGateAccessCheck::REASON, $this->detail($editor));
      $document = json_decode((string) $editor->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertArrayNotHasKey('data', $document);
      $this->assertArrayNotHasKey('omitted', $document['meta'] ?? []);
      // Core echoes the request URL in the error's via link.
      unset($document['errors'][0]['links']['via']);
      $this->assertStringNotContainsString($this->colleagueMembership->uuid(), json_encode($document, JSON_THROW_ON_ERROR));
    }
  }

  /**
   * Sends a JSON:API request through the HTTP kernel with Basic auth.
   */
  private function send(string $method, string $url, UserInterface $account, ?string $content = NULL): Response {
    $server = [
      'HTTP_ACCEPT' => self::API_JSON,
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($account->getAccountName() . ':' . self::PASSWORD),
      'PHP_AUTH_USER' => $account->getAccountName(),
      'PHP_AUTH_PW' => self::PASSWORD,
    ];
    if ($content !== NULL) {
      $server['CONTENT_TYPE'] = self::API_JSON;
    }
    $request = Request::create($url, $method, server: $server, content: $content);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $response = $this->container->get('http_kernel')->handle($request);
    // Kernel requests share one container; reset the account between them.
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    return $response;
  }

  /**
   * The detail of the single error of a JSON:API error document.
   */
  private function detail(Response $response): string {
    $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertCount(1, $document['errors'] ?? [], (string) $response->getContent());
    return (string) ($document['errors'][0]['detail'] ?? '');
  }

  /**
   * The colleague's group roles, loaded fresh.
   *
   * @return list<string>
   *   Sorted group role IDs.
   */
  private function colleagueRoles(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('group_relationship');
    $storage->resetCache();
    $membership = $storage->load($this->colleagueMembership->id());
    $this->assertInstanceOf(GroupMembershipInterface::class, $membership);
    $roles = array_keys($membership->getRoles(FALSE));
    sort($roles);
    return $roles;
  }

  /**
   * Creates an account with a password and an optional site role.
   */
  private function account(string $name, ?string $role): UserInterface {
    $account = $this->createUser([], $name);
    assert($account instanceof UserInterface);
    if ($role !== NULL) {
      $account->addRole($role);
    }
    $account->setPassword(self::PASSWORD)->save();
    return $account;
  }

}
