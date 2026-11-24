<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * What Drupal's own surfaces let a group member write.
 *
 * Contract "memberships and groups are written only by product code". Before
 * the rule (commit A) an office admin could, on the Group module's pages and
 * on JSON:API: open the add form, move the own membership into another
 * municipality's office (gid, 200), raise and delete colleagues (200, 204),
 * delete the office group (204), and the create route crashed in Group's
 * create check. Since the rule every membership write and every group
 * delete is refused (403) whatever group role the account holds; reads and
 * the members page are unchanged. Neutral groups, invented addresses; the
 * PHPUnit test site only.
 */
#[Group('beantragt_group')]
final class MembershipWriteSurfacesTest extends BrowserTestBase {

  use GroupTestTrait;

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
    'views',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Municipality A.
   */
  private GroupInterface $municipalityA;

  /**
   * Office A1 of municipality A.
   */
  private GroupInterface $officeA1;

  /**
   * Office B1 of municipality B.
   */
  private GroupInterface $officeB1;

  /**
   * Editor case worker: case_worker, org-admin in A1.
   */
  private UserInterface $editor;

  /**
   * A colleague: case_worker, org-member in A1.
   */
  private UserInterface $colleague;

  /**
   * Full Fachadministration of A: department_admin, jur-admin in A.
   */
  private UserInterface $full;

  /**
   * A citizen without any membership.
   */
  private UserInterface $citizen;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save(TRUE);
    foreach (['case_worker', 'department_admin'] as $role_id) {
      if (Role::load($role_id) === NULL) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }

    $this->municipalityA = $this->createMunicipality('surface-a', 'Surface Municipality A');
    $municipality_b = $this->createMunicipality('surface-b', 'Surface Municipality B');
    $this->officeA1 = $this->createOffice('surface-a1', 'Surface Office A1', $this->municipalityA);
    $this->officeB1 = $this->createOffice('surface-b1', 'Surface Office B1', $municipality_b);

    $this->editor = $this->createPersona('surface_editor', 'case_worker');
    $this->officeA1->addMember($this->editor, ['group_roles' => ['org-admin']]);
    $this->colleague = $this->createPersona('surface_colleague', 'case_worker');
    $this->officeA1->addMember($this->colleague, ['group_roles' => ['org-member']]);
    $this->full = $this->createPersona('surface_full', 'department_admin');
    $this->municipalityA->addMember($this->full, ['group_roles' => ['jur-admin']]);
    $this->citizen = $this->createPersona('surface_citizen', NULL);
  }

  /**
   * The editor reads the member page; every write page is refused.
   *
   * The member list is a View behind the group permission "administer
   * members", a read that stays governed by the read gate.
   */
  public function testEditorCaseWorkerOnTheGroupPages(): void {
    $colleague_membership = $this->membership($this->officeA1, $this->colleague);
    $this->drupalLogin($this->editor);

    $this->drupalGet('group/' . $this->officeA1->id() . '/members');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('group/' . $this->officeA1->id() . '/content/add/group_membership');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('group/' . $this->officeA1->id() . '/content/' . $colleague_membership->id() . '/edit');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('group/' . $this->officeA1->id() . '/content/' . $colleague_membership->id() . '/delete');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('group/' . $this->officeA1->id() . '/delete');
    $this->assertSession()->statusCodeEquals(403);
    // Another office: no group permission there.
    $this->drupalGet('group/' . $this->officeB1->id() . '/content/add/group_membership');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The two places the rule leaves open on purpose, pinned as they are.
   *
   * Contract section 10 ("Beides später"): Group's leave form asks the group
   * permission "leave group", not entity access, and removes only the
   * caller's own membership; editing a group through Drupal's own form is
   * not part of the rule. A later contract changes these on purpose. Only
   * the forms are opened here, nothing is submitted.
   */
  public function testOpenPlacesStayAsTheyAre(): void {
    $this->drupalLogin($this->editor);

    $this->drupalGet('group/' . $this->officeA1->id() . '/leave');
    $this->assertSession()->statusCodeEquals(200);
    // Joining another office needs "join group" as an outsider; no outsider
    // role holds it.
    $this->drupalGet('group/' . $this->officeB1->id() . '/join');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('group/' . $this->officeA1->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Editors cannot move their own membership to another group.
   */
  public function testEditorCannotMoveTheOwnMembershipWithGid(): void {
    $membership = $this->membership($this->officeA1, $this->editor);
    $this->drupalLogin($this->editor);

    $response = $this->jsonApi('PATCH', $this->membershipPath($membership), [
      'data' => [
        'type' => 'group_relationship--org-group_membership',
        'id' => $membership->uuid(),
        'relationships' => [
          'gid' => ['data' => ['type' => 'group--org', 'id' => $this->officeB1->uuid()]],
        ],
      ],
    ]);

    $this->assertSame(403, $response['status'], $response['body']);
    $this->assertSame(['org-admin'], array_keys($this->membership($this->officeA1, $this->editor)->getRoles(FALSE)), 'Still in A1.');
    $this->assertFalse(GroupMembership::loadSingle($this->officeB1, $this->reloadUser($this->editor)), 'Not in B1.');
  }

  /**
   * The editor can neither raise, delete nor create a membership.
   *
   * Before the rule: 200, 204, and a crash in Group's create check.
   */
  public function testEditorCannotWriteTheOfficeMembershipsOverJsonApi(): void {
    $colleague_membership = $this->membership($this->officeA1, $this->colleague);
    $this->drupalLogin($this->editor);

    $raise = $this->jsonApi('PATCH', $this->membershipPath($colleague_membership), [
      'data' => [
        'type' => 'group_relationship--org-group_membership',
        'id' => $colleague_membership->uuid(),
        'relationships' => [
          'group_roles' => ['data' => [['type' => 'group_role--group_role', 'id' => $this->groupRoleUuid('org-admin')]]],
        ],
      ],
    ]);
    $this->assertSame(403, $raise['status'], $raise['body']);
    $this->assertSame(['org-member'], array_keys($this->membership($this->officeA1, $this->colleague)->getRoles(FALSE)));

    $delete = $this->jsonApi('DELETE', $this->membershipPath($colleague_membership));
    $this->assertSame(403, $delete['status'], $delete['body']);
    $this->membership($this->officeA1, $this->colleague);

    // The forbidden create hook keeps Group's own create check from running,
    // so the empty JSON:API context no longer crashes it.
    $count_before = $this->membershipCount();
    $create = $this->jsonApi('POST', '/jsonapi/group_relationship/org-group_membership', [
      'data' => ['type' => 'group_relationship--org-group_membership'],
    ]);
    $this->assertSame(403, $create['status'], $create['body']);
    $this->assertSame($count_before, $this->membershipCount(), 'Nothing was created.');
  }

  /**
   * The editor can no longer delete the own office group.
   *
   * Before the rule: 204, and the office was gone. A platform administrator
   * still may (the product's own hook allows it, unchanged).
   */
  public function testOnlyPlatformAdministratorDeletesAnOfficeGroup(): void {
    $this->drupalLogin($this->editor);
    $office_id = (int) $this->officeA1->id();

    $delete = $this->jsonApi('DELETE', '/jsonapi/group/org/' . $this->officeA1->uuid());

    $this->assertSame(403, $delete['status'], $delete['body']);
    $this->assertNotNull(\Drupal::entityTypeManager()->getStorage('group')->loadUnchanged($office_id));
    $this->drupalLogout();

    $platform = $this->drupalCreateUser([], 'surface_platform_delete', TRUE);
    $this->assertInstanceOf(UserInterface::class, $platform);
    $this->drupalLogin($platform);
    $this->drupalGet('group/' . $office_id . '/delete');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * The municipality admin writes no office membership; others write none.
   */
  public function testOtherAccountsOnTheOfficeMemberships(): void {
    $colleague_membership = $this->membership($this->officeA1, $this->colleague);
    $body = [
      'data' => [
        'type' => 'group_relationship--org-group_membership',
        'id' => $colleague_membership->uuid(),
        'relationships' => [
          'group_roles' => ['data' => [['type' => 'group_role--group_role', 'id' => $this->groupRoleUuid('org-admin')]]],
        ],
      ],
    ];
    $platform = $this->drupalCreateUser([], 'surface_platform', TRUE);
    $this->assertInstanceOf(UserInterface::class, $platform);

    $answers = [];
    foreach ([
      'full Fachadministration of A (jur-admin, no office membership)' => $this->full,
      'citizen without membership' => $this->citizen,
      'platform administrator without membership' => $platform,
    ] as $who => $account) {
      $this->drupalLogin($account);
      $answers[$who] = $this->jsonApi('PATCH', $this->membershipPath($colleague_membership), $body)['status'];
      $this->drupalLogout();
    }

    $this->assertSame([
      'full Fachadministration of A (jur-admin, no office membership)' => 403,
      'citizen without membership' => 403,
      'platform administrator without membership' => 403,
    ], $answers);
    $this->assertSame(['org-member'], array_keys($this->membership($this->officeA1, $this->colleague)->getRoles(FALSE)));
  }

  /**
   * The full Fachadministration writes no membership of its municipality.
   *
   * Before the rule: add form 200, PATCH 200. The rule knows no exception by
   * group role.
   */
  public function testFullFachadministrationCannotWriteTheMunicipalityMemberships(): void {
    $member = $this->createPersona('surface_jur_member', 'case_worker');
    $this->municipalityA->addMember($member, ['group_roles' => ['jur-member']]);
    $membership = $this->membership($this->municipalityA, $member);
    $this->drupalLogin($this->full);

    $this->drupalGet('group/' . $this->municipalityA->id() . '/content/add/group_membership');
    $this->assertSession()->statusCodeEquals(403);
    $raise = $this->jsonApi('PATCH', $this->membershipPath($membership), [
      'data' => [
        'type' => 'group_relationship--jur-group_membership',
        'id' => $membership->uuid(),
        'relationships' => [
          'group_roles' => ['data' => [['type' => 'group_role--group_role', 'id' => $this->groupRoleUuid('jur-admin')]]],
        ],
      ],
    ]);

    $this->assertSame(403, $raise['status'], $raise['body']);
    $this->assertSame(['jur-member'], array_keys($this->membership($this->municipalityA, $member)->getRoles(FALSE)));
  }

  /**
   * The editor can still read individual office memberships.
   */
  public function testEditorReadsIndividualOfficeMemberships(): void {
    $own = $this->membership($this->officeA1, $this->editor);
    $this->drupalLogin($this->editor);

    $single = $this->jsonApi('GET', $this->membershipPath($own));
    $this->assertSame(200, $single['status'], $single['body']);
    $colleague = $this->membership($this->officeA1, $this->colleague);
    $single = $this->jsonApi('GET', $this->membershipPath($colleague));
    $this->assertSame(200, $single['status'], $single['body']);
    $this->assertSame($colleague->uuid(), Json::decode($single['body'])['data']['id']);
  }

  /**
   * Sends a JSON:API request with the session cookie and a CSRF token.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path below the site.
   * @param array<string, mixed>|null $document
   *   The JSON:API document, if any.
   *
   * @return array{status: int, body: string}
   *   Status code and body.
   */
  private function jsonApi(string $method, string $path, ?array $document = NULL): array {
    $token = $this->drupalGet('session/token');
    $options = [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::COOKIES => $this->getSessionCookies(),
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
        'X-CSRF-Token' => $token,
      ],
    ];
    if ($document !== NULL) {
      $options[RequestOptions::BODY] = Json::encode($document);
    }
    $response = $this->getHttpClient()->request($method, $this->buildUrl($path), $options);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
  }

  /**
   * The JSON:API path of a membership.
   */
  private function membershipPath(GroupMembershipInterface $membership): string {
    return '/jsonapi/group_relationship/' . $membership->bundle() . '/' . $membership->uuid();
  }

  /**
   * The membership of an account in a group, loaded fresh.
   */
  private function membership(GroupInterface $group, UserInterface $account): GroupMembershipInterface {
    \Drupal::entityTypeManager()->getStorage('group_relationship')->resetCache();
    $membership = GroupMembership::loadSingle($group, $this->reloadUser($account));
    $this->assertInstanceOf(GroupMembershipInterface::class, $membership);
    return $membership;
  }

  /**
   * The account, loaded fresh.
   */
  private function reloadUser(UserInterface $account): UserInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $storage->resetCache([$account->id()]);
    \Drupal::entityTypeManager()->getStorage('group_relationship')->resetCache();
    $user = $storage->load($account->id());
    assert($user instanceof UserInterface);
    return $user;
  }

  /**
   * Every membership on the test site.
   */
  private function membershipCount(): int {
    return (int) \Drupal::entityTypeManager()->getStorage('group_relationship')->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->count()
      ->execute();
  }

  /**
   * The UUID of a group role.
   */
  private function groupRoleUuid(string $role_id): string {
    $role = \Drupal::entityTypeManager()->getStorage('group_role')->load($role_id);
    $this->assertNotNull($role, $role_id);
    return (string) $role->uuid();
  }

  /**
   * Creates an active account, optionally with one site role.
   */
  private function createPersona(string $name, ?string $site_role): UserInterface {
    $user = $this->drupalCreateUser([], $name);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->setEmail($name . '@example.org');
    if ($site_role !== NULL) {
      $user->addRole($site_role);
    }
    $user->save();
    return $user;
  }

  /**
   * Creates a municipality (jurisdiction group).
   */
  private function createMunicipality(string $slug, string $label): GroupInterface {
    return $this->createGroup(['type' => 'jur', 'label' => $label, 'field_slug' => $slug]);
  }

  /**
   * Creates an office (organisation group) of one municipality.
   */
  private function createOffice(string $slug, string $label, GroupInterface $municipality): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $label,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $municipality->id()],
    ]);
  }

}
