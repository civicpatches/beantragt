<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Functional;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Serialization\Json;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests case access and field boundaries through Drupal HTTP routes.
 */
#[Group('beantragt_group')]
#[Group('beantragt_application')]
final class ApplicationJsonApiCrossJurisdictionAccessTest extends BrowserTestBase {

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
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
    'beantragt_workflow',
    'beantragt_comment',
    'beantragt_docgen',
    'dynamic_page_cache',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A scoped application is only readable by members of its organisation.
   */
  public function testApplicationJsonApiDetailRequiresEffectiveScopeMembership(): void {
    $jurisdiction_a = $this->createJurisdiction('jsonapi-jur-a', 'JSON:API Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('jsonapi-jur-b', 'JSON:API Jurisdiction B');
    $organisation_a = $this->createOrganisation('jsonapi-org-a', 'JSON:API Organisation A', $jurisdiction_a);
    $organisation_b = $this->createOrganisation('jsonapi-org-b', 'JSON:API Organisation B', $jurisdiction_b);

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);

    $schema_a = $this->createFormSchema($organisation_a, $jurisdiction_a);
    $application_a = $this->createApplication($schema_a, $organisation_a, $jurisdiction_a);
    $schema_b = $this->createFormSchema($organisation_b, $jurisdiction_b);
    $application_b = $this->createApplication($schema_b, $organisation_b, $jurisdiction_b);
    $jsonapi_path = '/jsonapi/application/application/' . $application_a->uuid();

    $org_a_viewer = $this->createStaffUser();
    $organisation_a->addMember($org_a_viewer, ['group_roles' => ['org-member']]);

    $org_b_viewer = $this->createStaffUser();
    $organisation_b->addMember($org_b_viewer, ['group_roles' => ['org-member']]);

    $this->drupalLogin($org_a_viewer);
    $allowed_response = Json::decode($this->drupalGet($jsonapi_path, [
      'query' => [
        'fields' => [
          'application--application' => 'field_reference_number',
        ],
      ],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('application--application', $allowed_response['data']['type']);
    $this->assertSame($application_a->uuid(), $allowed_response['data']['id']);

    $pii_field_response = Json::decode($this->drupalGet($jsonapi_path, [
      'query' => [
        'fields' => [
          'application--application' => 'field_applicant_email,field_crm_data,field_workflow_attributes',
        ],
      ],
    ]));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringNotContainsString('applicant@example.org', Json::encode($pii_field_response));
    $pii_attributes = $pii_field_response['data']['attributes'] ?? [];
    $this->assertArrayNotHasKey('field_applicant_email', $pii_attributes);
    $this->assertArrayNotHasKey('field_crm_data', $pii_attributes);
    $this->assertArrayNotHasKey('field_workflow_attributes', $pii_attributes);

    $own_collection = $this->loadApplicationCollectionByInternalId($application_a);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $own_collection['data']);
    $this->assertSame($application_a->uuid(), $own_collection['data'][0]['id']);

    $foreign_collection = $this->loadApplicationCollectionByInternalId($application_b);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], $foreign_collection['data']);
    $this->drupalLogout();

    $this->drupalLogin($org_b_viewer);
    $denied_response = Json::decode($this->drupalGet($jsonapi_path));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame('403', $denied_response['errors'][0]['status']);
    $this->assertSame('/data', $denied_response['errors'][0]['source']['pointer']);
  }

  /**
   * Generic field writes cannot replace the dedicated case actions.
   */
  public function testCaseWorkerWritesOnlyThroughTheAuthorizedRoutes(): void {
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $jurisdiction = $this->createJurisdiction('write-jur', 'Municipality A');
    $office = $this->createOrganisation('write-a1', 'Office A1', $jurisdiction);
    $sibling = $this->createOrganisation('write-a2', 'Office A2', $jurisdiction);
    $this->createDefaultWorkflowStatus($office);
    $this->createDefaultWorkflowStatus($sibling);
    $application = $this->createApplication($this->createFormSchema($office, $jurisdiction), $office, $jurisdiction);
    $account = $this->createStaffUser();
    $office->addMember($account, ['group_roles' => ['org-member']]);

    $path = '/jsonapi/application/application/' . $application->uuid();
    $case = '/api/admin/applications/' . $application->id();
    $type = 'application--application';
    $resource = ['type' => $type, 'id' => $application->uuid()];
    $other_status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'In review',
      'field_group' => $office->id(),
      'field_machine_name' => 'in_review',
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
    ]);
    $other_status->save();
    $first = $application->get('field_workflow_status')->entity;
    $first->set('field_allowed_next', [$other_status->id()])->save();
    $relationships = [
      'field_organisation' => ['data' => ['type' => 'group--org', 'id' => $sibling->uuid()]],
      'field_workflow_status' => [
        'data' => [
          'type' => 'workflow_status--workflow_status',
          'id' => $other_status->uuid(),
        ],
      ],
    ];
    $this->drupalLogin($account);
    $csrf = $this->drupalGet('/session/token');
    $workflow = Json::decode($this->drupalGet($case . '/workflow'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertFalse($workflow['can_change_status']);
    $this->assertSame(200, $this->patchCase($path, $csrf, ['data' => $resource + ['attributes' => ['label' => 'Edited case']]], TRUE));

    $attributes = [
      'field_due_date' => '2040-11-22',
      'field_workflow_attributes' => '{"private":"value"}',
      'field_reference_number' => 'TEST-NEW-REF',
      'field_crm_data' => '{"person.full_name":"Fixture"}',
      'field_schema_version' => $application->getSchemaVersion() + 1,
      'field_schema_revision_id' => (int) $application->get('field_schema_revision_id')->value + 1,
      'field_receipt_form_title' => 'Changed receipt title',
      'field_proof_assignments' => '{"fixture":[]}',
    ];
    foreach ($attributes as $field => $value) {
      $this->assertSame(403, $this->patchCase($path, $csrf, ['data' => $resource + ['attributes' => [$field => $value]]], TRUE), $field);
    }
    foreach (['field_origin', 'field_delivery_route', 'field_delivery_record', 'field_schema_version'] as $field) {
      $access = $application->get($field)->access('edit', $account, TRUE);
      $this->assertTrue($access->isForbidden(), $field);
      $this->assertContains('application:' . $application->id(), $access->getCacheTags(), $field);
    }
    foreach ($relationships as $field => $data) {
      $this->assertSame(403, $this->patchCase($path, $csrf, ['data' => $resource + ['relationships' => [$field => $data]]], TRUE), $field);
      $this->assertSame(403, $this->patchCase($path . '/relationships/' . $field, $csrf, $data, TRUE), $field);
    }
    foreach (['/deadline', '/status', '/status-rollback', '/status-correction'] as $route) {
      $this->assertSame(403, $this->patchCase($case . $route, $csrf, []), $route);
    }

    $form_url = $application->toUrl('edit-form');
    $this->drupalGet($form_url);
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    foreach ([
      'field_due_date[0][value][date]',
      'field_organisation[0][target_id]',
      'field_workflow_status[0][target_id]',
    ] as $field) {
      $this->assertSession()->fieldNotExists($field);
    }
    $fields = ['op' => 'Save', 'label[0][value]' => 'Form edit'];
    foreach (['form_id', 'form_build_id', 'form_token'] as $field) {
      $fields[$field] = $page->find('css', 'input[name="' . $field . '"]')->getValue();
    }
    $response = $this->getHttpClient()->post($this->buildUrl($form_url), [
      RequestOptions::COOKIES => $this->getSessionCookies(),
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::ALLOW_REDIRECTS => FALSE,
      RequestOptions::FORM_PARAMS => $fields + [
        'field_due_date' => [['value' => ['date' => '2040-11-22']]],
        'field_organisation' => [['target_id' => 'Office A2 (' . $sibling->id() . ')']],
        'field_workflow_status' => [['target_id' => 'In review (' . $other_status->id() . ')']],
      ],
    ]);
    $this->assertSame(303, $response->getStatusCode());
    $saved = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertSame((int) $office->id(), (int) $saved->get('field_organisation')->target_id);
    $this->assertSame((int) $first->id(), (int) $saved->get('field_workflow_status')->target_id);
    $this->assertTrue($saved->get('field_due_date')->isEmpty());

    Role::load('case_worker')->grantPermission('manage workflow transitions')->save();
    $this->assertSame(200, $this->patchCase($case . '/deadline', $csrf, [
      'expected_changed' => $saved->getChangedTime(),
      'due_date' => '2040-11-22',
    ]));
    $saved = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertSame(200, $this->patchCase($case . '/status', $csrf, [
      'expected_changed' => $saved->getChangedTime(),
      'expected_status_id' => (int) $first->id(),
      'target_status_id' => (int) $other_status->id(),
    ]));
    $this->assertSame(403, $this->patchCase($path . '/relationships/field_workflow_status', $csrf, [
      'data' => ['type' => 'workflow_status--workflow_status', 'id' => $first->uuid()],
    ], TRUE));

    $saved = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $delegation = [
      'target_org_uuid' => $sibling->uuid(),
      'expected_changed' => $saved->getChangedTime(),
      'expected_revision_id' => $saved->getRevisionId(),
      'note' => 'Delegation fixture',
    ];
    $this->assertSame(403, $this->writeCase('POST', $case . '/delegate', $csrf, $delegation));
    $this->drupalLogout();

    $delegate = $this->createStaffUser(['delegate applications']);
    $office->addMember($delegate, ['group_roles' => ['org-member']]);
    $this->drupalLogin($delegate);
    $csrf = $this->drupalGet('/session/token');
    $this->assertSame(403, $this->patchCase($path . '/relationships/field_organisation', $csrf, $relationships['field_organisation'], TRUE));
    $response = $this->caseRequest('POST', $case . '/delegate', $csrf, $delegation);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Only the audited route accepts permanent application deletion.
   */
  public function testGenericApplicationDeletionIsDeniedWithDeletePermission(): void {
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $jurisdiction = $this->createJurisdiction('delete-jur', 'Municipality A');
    $office = $this->createOrganisation('delete-org', 'Office A1', $jurisdiction);
    $this->createDefaultWorkflowStatus($office);
    $application = $this->createApplication($this->createFormSchema($office, $jurisdiction), $office, $jurisdiction);
    $account = $this->createStaffUser(['delete any application entities']);
    $office->addMember($account, ['group_roles' => ['org-member']]);
    $this->assertTrue($application->access('delete', $account));

    $this->drupalLogin($account);
    $csrf = $this->drupalGet('/session/token');
    $jsonapi_path = '/jsonapi/application/application/' . $application->uuid();
    $this->assertSame(403, $this->writeCase('DELETE', $jsonapi_path, $csrf, [], TRUE));
    $this->drupalGet($application->toUrl('delete-form'));
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($jsonapi_path);
    $this->assertSession()->statusCodeEquals(200);

    $case = '/api/admin/applications/' . $application->id();
    $this->assertSame(428, $this->writeCase('DELETE', $case, $csrf, []));
  }

  /**
   * Warm allow and deny decisions follow membership, roles and tenant scope.
   */
  public function testCaseRouteScopeAndMembershipChanges(): void {
    $a = $this->createJurisdiction('revoke-a', 'Municipality A');
    $b = $this->createJurisdiction('revoke-b', 'Municipality B');
    $office = $this->createOrganisation('revoke-a1', 'Office A1', $a);
    $sibling = $this->createOrganisation('revoke-a2', 'Office A2', $a);
    $foreign = $this->createOrganisation('revoke-b1', 'Office B1', $b);
    $parent = $this->createOrganisation('revoke-parent', 'Parent office', $a);
    $child = $this->createOrganisation('revoke-child', 'Child office', $a);
    $office->set('field_parent_org', $parent->id())->save();
    $child->set('field_parent_org', $office->id())->save();
    $this->createDefaultWorkflowStatus($office);
    $application = $this->createApplication($this->createFormSchema($office, $a), $office, $a);
    $case = '/api/admin/applications/' . $application->id();
    $other_cases = [];
    foreach ([$sibling, $a, $foreign] as $scope) {
      $jurisdiction = $scope === $foreign ? $b : $a;
      $this->createDefaultWorkflowStatus($scope);
      $other = $this->createApplication($this->createFormSchema($scope, $jurisdiction), $scope, $jurisdiction);
      $other_cases[] = [$other, $scope === $foreign ? 403 : 200];
    }
    $account = $this->createStaffUser();
    Role::load('case_worker')->grantPermission('manage workflow transitions')->save();
    $this->drupalLogin($account);
    foreach ([$sibling, $foreign, $parent, $child, $a] as $group) {
      $group->addMember($account, ['group_roles' => [$group->bundle() . '-member']]);
      $this->drupalGet($case . '/data');
      $this->assertSession()->statusCodeEquals(403);
      $this->assertFalse($application->access('update', $account));
      $group->removeMember($account);
    }
    $office->addMember($account, ['group_roles' => ['org-member']]);
    $tag = 'group_relationship_list:plugin:group_membership:entity:' . $account->id();
    $allowed = $application->access('update', $account, TRUE);
    $this->assertTrue($allowed->isAllowed());
    $this->assertContains($tag, $allowed->getCacheTags());
    $membership = GroupMembership::loadSingle($office, $account);
    $membership->removeRole('org-member');
    $this->assertFalse($application->access('update', $account));
    $membership->addRole('org-member');
    $this->assertTrue($application->access('update', $account));
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(200);
    $settings = '/api/admin/workspace-settings';
    $office_query = ['query' => ['jurisdiction' => 'revoke-a', 'organisation' => 'revoke-a1']];
    $invitations = '/api/workspace-invites';
    $invite_query = ['query' => ['target_parent_id' => $a->id()]];
    $this->drupalGet($settings, $office_query);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($invitations, $invite_query);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('/admin/content/applications');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertArrayNotHasKey('base', $this->container->get('views.views_data')->get('application')['table'] ?? []);
    $office->removeMember($account);
    $denied = $application->access('update', $account, TRUE);
    $this->assertTrue($denied->isForbidden());
    $this->assertContains($tag, $denied->getCacheTags());
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(403);

    $office->addMember($account, ['group_roles' => ['org-admin']]);
    $this->drupalGet($case . '/workflow');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($settings, $office_query);
    $this->assertSession()->statusCodeEquals(403);
    $office->removeMember($account);
    $a->addMember($account, ['group_roles' => ['jur-admin']]);
    foreach ($other_cases as [$other, $status]) {
      $this->drupalGet('/api/admin/applications/' . $other->id() . '/data');
      $this->assertSession()->statusCodeEquals($status);
    }
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(200);
    if (!Role::load('department_admin')) {
      Role::create(['id' => 'department_admin', 'label' => 'Department admin'])->save();
    }
    $account->addRole('department_admin')->save();
    $this->drupalGet($settings, $office_query);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($settings, ['query' => ['jurisdiction' => 'revoke-b', 'organisation' => 'revoke-b1']]);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($invitations, $invite_query);
    $this->assertSession()->statusCodeEquals(403);
    $account->removeRole('department_admin')->save();
    $a->removeMember($account);

    $collection_admin = $this->createStaffUser(['administer application entities']);
    $office->addMember($collection_admin, ['group_roles' => ['org-member']]);
    $this->drupalLogout();
    $this->drupalLogin($collection_admin);
    $this->drupalGet('/admin/content/applications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($application->label());
    foreach ($other_cases as [$other]) {
      $this->assertSession()->pageTextNotContains($other->label());
    }
    $office->removeMember($collection_admin);
    $this->drupalGet('/admin/content/applications');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains($application->label());

    $this->drupalLogout();
    $office->addMember($account, ['group_roles' => ['org-member']]);
    $this->drupalLogin($account);
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(200);
    $account->removeRole('case_worker')->save();
    $this->assertTrue($account->hasPermission('edit any application entities'));
    $this->assertFalse($application->access('update', $account));
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(403);

    $account->addRole('case_worker')->save();
    $account->block()->save();
    $this->assertFalse($application->access('update', $account));
    $this->drupalGet($case . '/data');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Case actions keep their independent permissions on the HTTP routes.
   */
  public function testCaseScopeDoesNotGrantIndependentActionPermissions(): void {
    $jurisdiction = $this->createJurisdiction('action-jur', 'Municipality A');
    $office = $this->createOrganisation('action-a1', 'Office A1', $jurisdiction);
    $this->createDefaultWorkflowStatus($office);
    $application = $this->createApplication($this->createFormSchema($office, $jurisdiction), $office, $jurisdiction);
    $case = '/api/admin/applications/' . $application->id();
    $comments = '/api/comments/' . $application->uuid();
    $actions = [
      ['create docgen documents', $case . '/docgen/documents', 422, 'release docgen documents'],
      ['release docgen documents', $case . '/docgen/release', 422, 'create docgen documents'],
      [
        'approve application dispatch',
        $case . '/transfers/0b1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d/approve-dispatch',
        404,
        NULL,
      ],
    ];
    foreach ($actions as [$permission, $path, $allowed_status, $read_permission]) {
      $permissions = ['manage application deliveries'];
      if ($read_permission !== NULL) {
        $permissions[] = $read_permission;
      }
      $reader = $this->createStaffUser($permissions);
      $writer = $this->createStaffUser([...$permissions, $permission]);
      $office->addMember($reader, ['group_roles' => ['org-member']]);
      $office->addMember($writer, ['group_roles' => ['org-member']]);
      $input = match ($permission) {
        'release docgen documents' => ['data' => ['documents' => []]],
        'approve application dispatch' => new \stdClass(),
        default => ['data' => []],
      };
      foreach ([[$reader, 403], [$writer, $allowed_status]] as [$staff, $status]) {
        $this->drupalLogin($staff);
        $response = $this->caseRequest('POST', $path, $this->drupalGet('/session/token'), $input);
        $this->assertSame($status, $response->getStatusCode(), $permission . ': ' . (string) $response->getBody());
        $this->drupalLogout();
      }
    }

    $worker = $this->createStaffUser(['view application_comment entities', 'create application_comment entities']);
    $office->addMember($worker, ['group_roles' => ['org-member']]);
    $this->drupalLogin($worker);
    $csrf = $this->drupalGet('/session/token');
    $this->assertSame(201, $this->writeCase('POST', $comments, $csrf, [
      'body' => 'Internal fixture',
      'visibility' => 'internal',
    ]));
    $this->assertStringContainsString('Internal fixture', $this->drupalGet($comments));
    $this->assertSession()->statusCodeEquals(200);
    $office->removeMember($worker);
    $this->drupalGet($comments);
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Sends a JSON or JSON:API PATCH with the logged-in user's CSRF token.
   */
  private function patchCase(string $path, string $csrf, array $document, bool $jsonapi = FALSE): int {
    return $this->writeCase('PATCH', $path, $csrf, $document, $jsonapi);
  }

  /**
   * Sends a mutation through the browser's session.
   */
  private function writeCase(string $method, string $path, string $csrf, array|object $document, bool $jsonapi = FALSE): int {
    return $this->caseRequest($method, $path, $csrf, $document, $jsonapi)->getStatusCode();
  }

  /**
   * Sends an HTTP request and retains its error envelope for assertions.
   */
  private function caseRequest(string $method, string $path, string $csrf, array|object $document, bool $jsonapi = FALSE): ResponseInterface {
    $content_type = $jsonapi ? 'application/vnd.api+json' : 'application/json';
    return $this->getHttpClient()->request($method, $this->buildUrl($path), [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::COOKIES => $this->getSessionCookies(),
      RequestOptions::HEADERS => [
        'Accept' => $content_type,
        'Content-Type' => $content_type,
        'X-CSRF-Token' => $csrf,
      ],
      RequestOptions::JSON => $document,
    ]);
  }

  /**
   * Loads the application collection filtered by Drupal's internal entity ID.
   *
   * @return array<string, mixed>
   *   Decoded JSON:API response body.
   */
  private function loadApplicationCollectionByInternalId(ApplicationInterface $application): array {
    return Json::decode($this->drupalGet('/jsonapi/application/application', [
      'query' => [
        'filter' => [
          'drupal_internal__id' => $application->id(),
        ],
        'fields' => [
          'application--application' => 'field_reference_number',
        ],
      ],
    ]));
  }

  /**
   * Creates a staff user with broad application read permissions.
   *
   * @param string[] $extra_permissions
   *   Extra site permissions for the action under test.
   */
  private function createStaffUser(array $extra_permissions = []): UserInterface {
    $account = $this->createUser([
      'view published application entities',
      'edit any application entities',
      ...$extra_permissions,
    ]);
    // Staff pass the JSON:API read gate by role.
    if (!Role::load('case_worker')) {
      Role::create(['id' => 'case_worker', 'label' => 'case_worker'])->save();
    }
    assert($account instanceof UserInterface);
    $account->addRole('case_worker');
    $account->save();
    return $account;
  }

  /**
   * Creates a jurisdiction group.
   */
  private function createJurisdiction(string $slug, string $label): GroupInterface {
    return $this->createGroup([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
    ]);
  }

  /**
   * Creates an organisation group owned by one jurisdiction.
   */
  private function createOrganisation(string $slug, string $label, GroupInterface $jurisdiction): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $label,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
  }

  /**
   * Creates the minimal default workflow status required by applications.
   */
  private function createDefaultWorkflowStatus(GroupInterface $organisation): void {
    $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Eingegangen',
      'field_group' => ['target_id' => $organisation->id()],
      'field_machine_name' => 'eingegangen',
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => TRUE,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ])->save();
  }

  /**
   * Creates a published form schema in one organisation scope.
   */
  private function createFormSchema(GroupInterface $organisation, GroupInterface $jurisdiction): FormSchemaInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Tenant JSON:API smoke',
      'field_schema' => json_encode([
        'title' => 'Tenant JSON:API smoke',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              [
                'id' => 'message',
                'type' => 'text',
                'label' => 'Message',
                'required' => FALSE,
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => $organisation->bundle() === 'org' ? $organisation->id() : NULL,
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $schema->save();
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Creates a submitted application in one organisation scope.
   */
  private function createApplication(FormSchemaInterface $schema, GroupInterface $organisation, GroupInterface $jurisdiction): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['message' => 'Hello'], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'applicant@example.org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => $organisation->bundle() === 'org' ? $organisation->id() : NULL,
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

}
