<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Functional;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Serialization\Json;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\Entity\Role;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the unconfirmed staff list and the boundaries of its read access.
 */
#[Group('beantragt_application')]
final class StaffApplicationConfirmationFilterTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'dblog',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_workflow',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The list exposes only an authorized summary, and case actions stay closed.
   */
  public function testUnconfirmedListOnlyExposesScopedReadOnlyInformation(): void {
    $jurisdiction = $this->createGroup(['type' => 'jur', 'field_slug' => 'own-jurisdiction']);
    $foreign = $this->createGroup(['type' => 'jur', 'field_slug' => 'foreign-jurisdiction']);
    $own = $this->createOrganisation($jurisdiction, 'own-office');
    $sibling = $this->createOrganisation($jurisdiction, 'sibling-office');
    $other = $this->createOrganisation($foreign, 'foreign-office');

    $permissions = [
      'view published application entities',
      'view protected application data',
      'assign applications',
      'edit any application entities',
      'delete any application entities',
    ];
    $this->createRole($permissions, 'case_worker');
    $staff = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    $own->addMember($staff, ['group_roles' => ['org-admin']]);
    $jurisdiction_admin = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    GroupRole::create([
      'id' => 'jur-intake-lead',
      'label' => 'Intake lead',
      'group_type' => 'jur',
      'scope' => 'individual',
      'admin' => TRUE,
    ])->save();
    $jurisdiction->addMember($jurisdiction_admin, ['group_roles' => ['jur-intake-lead']]);
    $platform_admin = $this->drupalCreateUser([
      'administer application entities',
      'bypass beantragt group scope',
    ], NULL, FALSE, ['roles' => ['case_worker']]);

    $pending = $this->createApplication($own);
    $confirmed = $this->createApplication($own, confirmed: TRUE);
    $unpublished = $this->createApplication($own, published: FALSE);
    $protected = $this->createApplication($own, protected: TRUE);
    $sibling_pending = $this->createApplication($sibling);
    $this->createApplication($other);

    $endpoint = '/api/v1/staff/applications';
    $scope = ['jurisdiction' => 'own-jurisdiction', 'organisation' => 'own-office'];
    $filter = ['confirmation' => 'unconfirmed'];
    $this->assertApiError($endpoint, $scope + $filter, 401, 'auth.required');

    $this->drupalLogin($staff);
    $matches = $this->getJson($endpoint, $scope + $filter);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString('no-store', $this->getSession()->getResponseHeader('Cache-Control'));
    $this->assertCount(2, $matches['data']);
    $this->assertSame(2, $matches['meta']['count']);
    $this->assertSame([], $matches['meta']['workflowStatuses']);
    $this->assertSame('{}', Json::encode($matches['meta']['events']));
    $rows = array_column($matches['data'], NULL, 'id');
    $this->assertEqualsCanonicalizing([$pending->uuid(), $protected->uuid()], array_keys($rows));
    $this->assertArrayNotHasKey($unpublished->uuid(), $rows);
    $summary = $rows[$pending->uuid()];
    $this->assertEqualsCanonicalizing([
      'id', 'referenceNumber', 'form', 'workspace', 'submittedAt',
      'applicantName', 'confirmationEmail', 'emailConfirmed',
    ], array_keys($summary));
    $this->assertSame($pending->getReferenceNumber(), $summary['referenceNumber']);
    $this->assertSame('Ada Lovelace', $summary['applicantName']);
    $this->assertSame('pending@example.org', $summary['confirmationEmail']);
    $this->assertFalse($summary['emailConfirmed']);
    $this->assertNull($rows[$protected->uuid()]['applicantName']);
    $this->assertNull($rows[$protected->uuid()]['confirmationEmail']);
    $this->assertStringNotContainsString('private answer', Json::encode($matches));
    $this->assertStringNotContainsString('private attachment', Json::encode($matches));
    $this->assertStringNotContainsString(str_repeat('a', 64), Json::encode($matches));

    $page = $this->getJson($endpoint, $scope + $filter + ['limit' => 1, 'sort' => 'created']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $page['data']);
    $this->assertTrue($page['meta']['page']['hasMore']);
    $this->assertSame(1, $page['meta']['page']['nextOffset']);
    $form_matches = $this->getJson($endpoint, $scope + $filter + ['form' => $summary['form']['id']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$pending->uuid()], array_column($form_matches['data'], 'id'));

    $regular = $this->getJson($endpoint, $scope);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$confirmed->uuid()], array_column($regular['data'], 'id'));
    $this->assertTrue($regular['data'][0]['emailConfirmed']);
    $this->assertSame($regular['data'][0]['form']['title'], $summary['form']['title']);
    $this->assertSame($pending->get('field_schema')->entity->uuid(), $summary['form']['id']);
    $this->assertSame($regular['data'][0]['workspace'], $summary['workspace']);
    $this->assertEqualsCanonicalizing([$confirmed->uuid()], array_keys((array) $regular['meta']['events']));

    foreach ([[], $filter] as $query) {
      $counts = $this->getJson($endpoint . '/counts', $scope + $query);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSame(1, $counts['data']['total']);
      $this->assertSame([$confirmed->get('field_schema')->entity->uuid() => 1], (array) $counts['data']['applicationsByForm']);
      $report = $this->getJson($endpoint . '/report', $scope + $query);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSame(1, $report['data']['totals']['applications']);
    }

    foreach (['confirmed', '', ['unconfirmed']] as $invalid) {
      $this->assertApiError($endpoint, $scope + ['confirmation' => $invalid], 400, 'confirmation.invalid');
    }
    $this->assertApiError($endpoint, $scope + $filter + ['include' => 'submittedData'], 400, 'include.confirmation_conflict');
    $this->assertApiError($endpoint, $filter, 400, 'workspace.required');
    $this->assertApiError($endpoint, ['jurisdiction' => 'unknown'] + $filter, 404, 'workspace.not_found');
    $this->assertApiError($endpoint, ['jurisdiction' => 'own-jurisdiction'] + $filter, 403, 'workspace.forbidden');
    $this->assertApiError($endpoint, ['jurisdiction' => 'own-jurisdiction', 'organisation' => 'sibling-office'] + $filter, 403, 'workspace.forbidden');
    $this->assertApiError($endpoint, ['jurisdiction' => 'foreign-jurisdiction', 'organisation' => 'foreign-office'] + $filter, 403, 'workspace.forbidden');

    $this->assertApiError($endpoint . '/' . $pending->uuid(), $scope + $filter, 404, 'application.not_found');
    $this->assertApiError('/api/internal/staff/applications/' . $pending->uuid() . '/events', $scope, 404, 'application.not_found');
    $this->drupalGet('/jsonapi/application/application/' . $pending->uuid(), [], ['Accept' => 'application/vnd.api+json']);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertFalse($pending->access('update', $staff));
    $this->assertTrue($pending->access('delete', $staff));

    // A valid session, CSRF token and workspace are present for both cases.
    $csrf = $this->drupalGet('/session/token');
    $attachment_uuid = '0a179dd2-63d6-4230-95fd-e3f84df9c148';
    foreach ([$pending, $confirmed] as $application) {
      $case_path = '/api/admin/applications/' . $application->id();
      $confirmed_case = $application->isEmailConfirmed();
      $this->assertSame($confirmed_case ? 200 : 403, $this->caseRequestStatus('PATCH', $case_path . '/assignee', $csrf, [
        'assignee_uuid' => NULL,
        'expected_changed' => $application->getChangedTime(),
        'expected_revision_id' => $application->getRevisionId(),
      ]));
      $this->assertSame($confirmed_case ? 404 : 403, $this->caseRequestStatus('POST', $case_path . '/attachments/' . $attachment_uuid . '/analysis/staff', $csrf, []));
    }

    $this->drupalLogout();
    $this->drupalLogin($jurisdiction_admin);
    $jurisdiction_matches = $this->getJson($endpoint, ['jurisdiction' => 'own-jurisdiction'] + $filter);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEqualsCanonicalizing([$pending->uuid(), $protected->uuid(), $sibling_pending->uuid()], array_column($jurisdiction_matches['data'], 'id'));
    $sibling_matches = $this->getJson($endpoint, [
      'jurisdiction' => 'own-jurisdiction',
      'organisation' => 'sibling-office',
    ] + $filter);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$sibling_pending->uuid()], array_column($sibling_matches['data'], 'id'));
    $counts = $this->getJson($endpoint . '/counts', ['jurisdiction' => 'own-jurisdiction']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(1, $counts['data']['total']);

    $this->assertFalse($pending->access('view', $platform_admin));
    $this->assertFalse($pending->access('update', $platform_admin));
    $this->assertTrue($pending->access('delete', $platform_admin));
    $confirmed_access = $confirmed->access('view', $platform_admin, TRUE);
    $this->assertTrue($confirmed_access->isAllowed());
    $this->assertContains('application:' . $confirmed->id(), $confirmed_access->getCacheTags());
    $this->drupalLogout();
    $this->drupalLogin($platform_admin);
    $this->drupalGet('/jsonapi/application/application/' . $pending->uuid(), [], ['Accept' => 'application/vnd.api+json']);
    $this->assertSession()->statusCodeEquals(403);
    $csrf = $this->drupalGet('/session/token');
    $analysis_path = '/api/admin/applications/' . $pending->id() . '/attachments/' . $attachment_uuid . '/analysis/staff';
    $this->assertSame(403, $this->caseRequestStatus('POST', $analysis_path, $csrf, []));
  }

  /**
   * Staff list and detail share the reveal decision and audit channel.
   */
  public function testCaseWorkerProtectedDataAndExportProjection(): void {
    $jurisdiction = $this->createGroup(['type' => 'jur', 'field_slug' => 'protected-town']);
    $office = $this->createOrganisation($jurisdiction, 'protected-office');
    $sibling = $this->createOrganisation($jurisdiction, 'protected-sibling');
    $this->createRole([
      'view published application entities',
      'edit any application entities',
      'edit application data entities',
    ], 'case_worker');
    $staff = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    $office->addMember($staff, ['group_roles' => ['org-member']]);
    $application = $this->createApplication($office, confirmed: TRUE, protected: TRUE);
    $outside = $this->createApplication($sibling, confirmed: TRUE, protected: TRUE);
    $form = $application->get('field_schema')->entity;
    assert($form instanceof FormSchemaInterface);
    $pin = (int) $application->get('field_schema_revision_id')->value;
    $this->assertSame((int) $form->getRevisionId(), $pin);
    $schema = Json::decode($form->getSchema());
    $schema['sections'][0]['fields'][1]['sensitive'] = FALSE;
    $form->set('field_workflow_status', 'draft')->save();
    $form->setSchema(Json::encode($schema))->save();
    $form->set('field_workflow_status', 'published')->save();
    $this->assertNotSame($pin, (int) $form->getRevisionId());
    $database = $this->container->get('database');
    // Simulate a legacy plaintext answer so only the submitted schema marks
    // the stored value as protected.
    $database->update('application')
      ->fields(['field_data' => Json::encode(['answer' => 'private answer'])])
      ->condition('id', $application->id())->execute();

    $this->drupalLogin($staff);
    $path = '/api/admin/applications/' . $application->id() . '/data';
    $list_path = '/api/v1/staff/applications';
    $detail_path = $list_path . '/' . $application->uuid();
    $scope = [
      'jurisdiction' => 'protected-town',
      'organisation' => 'protected-office',
    ];
    $expansion = $scope + [
      'include' => 'submittedData',
      'form' => $form->uuid(),
    ];
    $detail = $this->getJson($path, []);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($detail['meta']['protected_data_redacted']);
    $this->assertFalse($detail['meta']['can_edit_data']);
    $this->assertSame('protected', $detail['meta']['edit_data_blocked_reason']);
    $this->assertStringNotContainsString('private answer', Json::encode($detail['data']));
    $csrf = $this->drupalGet('/session/token');
    $this->assertSame(403, $this->caseRequestStatus('PATCH', $path, $csrf, [
      'data' => $detail['data'],
      'expected_changed' => $detail['changed'],
      'reason' => 'Fixture correction',
    ]));
    $rows = $this->getJson($list_path, $expansion)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$application->uuid()], array_column($rows, 'id'));
    $this->assertFalse($rows[0]['canChangeStatus']);
    $this->assertSame([], $rows[0]['status']['allowedNextIds']);
    $this->assertFalse($rows[0]['canDelete']);
    $redacted = ['answer' => [ApplicationDataProtectionService::REDACTED_MARKER => TRUE]];
    $this->assertSame($redacted, $rows[0]['submittedData']);
    $staff_detail = $this->getJson($detail_path, $scope);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($staff_detail['meta']['protectedDataRedacted']);
    $this->assertSame($redacted, $staff_detail['data']['submittedData']);
    $this->assertSame([], $this->protectedRevealChannels($application, (int) $staff->id()));
    $this->assertApiError($list_path, ['organisation' => 'protected-sibling'] + $scope, 403, 'workspace.forbidden');
    $this->getJson($list_path . '/' . $outside->uuid(), $scope);
    $this->assertSession()->statusCodeEquals(404);

    $role = Role::load('case_worker');
    $role->grantPermission('view protected application data')->save();
    $rows = $this->getJson($list_path, $expansion)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(['answer' => 'private answer'], $rows[0]['submittedData']);
    $this->assertSame(['staff_api'], $this->protectedRevealChannels($application, (int) $staff->id()));

    // Expire the first entry so that detail must record its own reveal.
    $database->update(ProtectedAccessLog::TABLE)->fields(['created' => 0])
      ->condition('application_uuid', $application->uuid())->execute();
    $staff_detail = $this->getJson($detail_path, $scope);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertFalse($staff_detail['meta']['protectedDataRedacted']);
    $this->assertSame($rows[0]['submittedData'], $staff_detail['data']['submittedData']);
    $this->assertSame(['staff_api', 'staff_api'], $this->protectedRevealChannels($application, (int) $staff->id()));
    $detail = $this->getJson($path, []);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertFalse($detail['meta']['protected_data_redacted']);
    $this->assertSame('private answer', $detail['data']['answer']);

    // Keep office membership and Drupal permission, but remove case scope.
    $membership = $office->getMember($staff);
    $this->assertNotFalse($membership);
    $membership->removeRole('org-member');
    $database->update(ProtectedAccessLog::TABLE)->fields(['created' => 0])
      ->condition('application_uuid', $application->uuid())->execute();
    $rows = $this->getJson($list_path, $expansion)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$application->uuid()], array_column($rows, 'id'));
    $this->assertSame($redacted, $rows[0]['submittedData']);
    $staff_detail = $this->getJson($detail_path, $scope);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($staff_detail['meta']['protectedDataRedacted']);
    $this->assertSame($redacted, $staff_detail['data']['submittedData']);
    $this->assertSame(['staff_api', 'staff_api'], $this->protectedRevealChannels($application, (int) $staff->id()));
    $this->assertSame([], $this->protectedRevealChannels($outside, (int) $staff->id()));
  }

  /**
   * Staff with a reveal permission still need case scope for identity data.
   */
  public function testProtectedIdentityRequiresCaseScope(): void {
    $jurisdiction = $this->createGroup(['type' => 'jur', 'field_slug' => 'identity-town']);
    $office = $this->createOrganisation($jurisdiction, 'identity-office');
    $application = $this->createApplication($office, confirmed: TRUE, protected: TRUE);
    // Model a stored identity linkage left over from before data protection.
    $this->container->get('database')->update('application')->fields([
      'field_crm_data' => Json::encode(['person.full_name' => 'Ada Lovelace']),
      'field_applicant_email' => 'identity@example.org',
    ])->condition('id', $application->id())->execute();

    $this->createRole(['view published application entities', 'view protected application data'], 'department_admin');
    $reader = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['department_admin']]);
    $office->addMember($reader, ['group_roles' => ['org-member']]);
    $this->drupalLogin($reader);
    $endpoint = '/api/v1/staff/applications';
    $scope = ['jurisdiction' => 'identity-town', 'organisation' => 'identity-office'];
    $rows = $this->getJson($endpoint, $scope)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$application->uuid()], array_column($rows, 'id'));
    $this->assertArrayNotHasKey('crmData', $rows[0]);
    $this->assertArrayNotHasKey('applicant', $rows[0]);
    $detail = $this->getJson($endpoint . '/' . $application->uuid(), $scope)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertArrayNotHasKey('crmData', $detail);
    $this->assertArrayNotHasKey('applicant', $detail);

    $office->getMember($reader)->addRole('org-admin');
    $row = $this->getJson($endpoint, $scope)['data'][0];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(['person.full_name' => 'Ada Lovelace'], $row['crmData']);
    $this->assertSame(['email' => 'identity@example.org'], $row['applicant']);
  }

  /**
   * Legacy scope takes precedence and an office move never transfers access.
   */
  public function testUnconfirmedLegacyOfficeScopeRespectsRecordedJurisdiction(): void {
    $old = $this->createGroup(['type' => 'jur', 'field_slug' => 'legacy-old-town']);
    $new = $this->createGroup(['type' => 'jur', 'field_slug' => 'legacy-new-town']);
    $office = $this->createOrganisation($old, 'legacy-office');
    $application = $this->createApplication($office);
    $this->container->get('database')->update('application')->fields(['field_organisation' => NULL])
      ->condition('id', $application->id())->execute();

    $this->createRole(['view published application entities'], 'case_worker');
    $old_admin = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    $new_admin = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    $member = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    $old->addMember($old_admin, ['group_roles' => ['jur-admin']]);
    $new->addMember($new_admin, ['group_roles' => ['jur-admin']]);
    $office->addMember($member, ['group_roles' => ['org-member']]);
    $endpoint = '/api/v1/staff/applications';
    $filter = ['confirmation' => 'unconfirmed'];
    $old_scope = ['jurisdiction' => 'legacy-old-town'] + $filter;
    $new_scope = ['jurisdiction' => 'legacy-new-town', 'organisation' => 'legacy-office'] + $filter;

    $this->drupalLogin($old_admin);
    $rows = $this->getJson($endpoint, $old_scope)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$application->uuid()], array_column($rows, 'id'));
    $this->assertSame('legacy-office', $rows[0]['workspace']['organisation']['slug']);

    $office->set('field_jurisdiction', $new->id())->save();
    $this->assertSame([], $this->getJson($endpoint, $old_scope)['data']);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();
    $this->drupalLogin($new_admin);
    $this->assertSame([], $this->getJson($endpoint, $new_scope)['data']);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalLogout();
    $this->drupalLogin($member);
    $rows = $this->getJson($endpoint, $new_scope)['data'];
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([$application->uuid()], array_column($rows, 'id'));
  }

  /**
   * The session and CSRF-protected delete checks the version and logs success.
   */
  public function testCaseWorkerCanDeleteThroughAuditedRoute(): void {
    $jurisdiction = $this->createGroup(['type' => 'jur', 'field_slug' => 'delete-town']);
    $office = $this->createOrganisation($jurisdiction, 'delete-office');
    $this->createRole(['delete any application entities', 'view published application entities'], 'case_worker');
    $staff = $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['case_worker']]);
    $office->addMember($staff, ['group_roles' => ['org-member']]);
    $application = $this->createApplication($office, confirmed: TRUE);
    $this->drupalLogin($staff);
    $csrf = $this->drupalGet('/session/token');
    $path = '/api/admin/applications/' . $application->id();

    $this->assertSame(409, $this->caseRequestStatus('DELETE', $path, $csrf, [
      'expected_changed' => $application->getChangedTime() - 1,
    ]));
    $storage = $this->entityTypeManager()->getStorage('application');
    $this->assertNotNull($storage->loadUnchanged($application->id()));
    $this->assertSame(200, $this->caseRequestStatus('DELETE', $path, $csrf, [
      'expected_changed' => $application->getChangedTime(),
    ]));
    $this->assertNull($storage->loadUnchanged($application->id()));

    $entries = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['variables'])
      ->condition('type', 'beantragt_application')
      ->condition('message', 'Application @uuid was permanently deleted by uid @uid in jurisdiction @jurisdiction and organisation @organisation.')
      ->execute()->fetchCol();
    $this->assertCount(1, $entries);
    $this->assertSame([
      '@uuid' => $application->uuid(),
      '@uid' => (int) $staff->id(),
      '@jurisdiction' => (int) $jurisdiction->id(),
      '@organisation' => (int) $office->id(),
    ], unserialize($entries[0], ['allowed_classes' => FALSE]));
  }

  /**
   * Creates a workspace with a parent jurisdiction.
   */
  private function createOrganisation(GroupInterface $jurisdiction, string $slug): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'field_slug' => $slug,
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
  }

  /**
   * Creates a staff list fixture with submitted data and confirmation state.
   */
  private function createApplication(GroupInterface $organisation, bool $confirmed = FALSE, bool $published = TRUE, bool $protected = FALSE): ApplicationInterface {
    $jurisdiction_id = $organisation->get('field_jurisdiction')->target_id;
    $workspace = [
      'field_jurisdiction' => $jurisdiction_id,
      'field_organisation' => $organisation->id(),
      'field_group' => $organisation->id(),
    ];
    $form = $this->entityTypeManager()->getStorage('form_schema')->create($workspace + [
      'label' => 'Application form',
      'field_schema' => Json::encode([
        'title' => 'Application form',
        'sections' => [
          [
            'id' => 'applicant',
            'title' => 'Applicant',
            'fields' => [
              [
                'id' => 'name',
                'label' => 'Name',
                'type' => 'text',
                'crm_key' => 'person.full_name',
                'privacy' => ['kind' => 'identity'],
              ],
              [
                'id' => 'answer',
                'label' => 'Answer',
                'type' => 'text',
                'sensitive' => $protected,
                'privacy' => ['kind' => 'other_personal'],
              ],
              ['id' => 'upload', 'label' => 'Upload', 'type' => 'file', 'privacy' => ['kind' => 'other_personal']],
            ],
          ],
        ],
      ]),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'status' => TRUE,
    ]);
    $form->save();
    $application = $this->entityTypeManager()->getStorage('application')->create($workspace + [
      'field_schema' => $form->id(),
      'field_data' => Json::encode([
        'name' => 'Ada Lovelace',
        'answer' => 'private answer',
        'upload' => ['id' => 'private attachment'],
      ]),
      'field_applicant_email' => 'pending@example.org',
      'field_email_confirmed' => $confirmed,
      'field_email_confirmation_token_hash' => $confirmed ? NULL : str_repeat('a', 64),
      'status' => $published,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Sends a GET using the current browser session.
   */
  private function getJson(string $path, array $query): array {
    $body = $this->drupalGet($path, ['query' => $query], ['Accept' => 'application/json']);
    // Decode objects separately so that empty JSON objects remain distinct.
    $data = Json::decode($body);
    if (isset($data['meta']['events'])) {
      $data['meta']['events'] = json_decode($body)->meta->events;
    }
    return $data;
  }

  /**
   * Checks the API error status and code over HTTP.
   */
  private function assertApiError(string $path, array $query, int $status, string $code): void {
    $payload = $this->getJson($path, $query);
    $this->assertSession()->statusCodeEquals($status);
    $this->assertSame($code, $payload['error']['code']);
  }

  /**
   * Returns the channels audited for this case and account.
   */
  private function protectedRevealChannels(ApplicationInterface $application, int $uid): array {
    return $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'log')
      ->fields('log', ['channel'])
      ->condition('application_uuid', $application->uuid())
      ->condition('uid', $uid)
      ->condition('subject_type', 'application')
      ->orderBy('id')->execute()->fetchCol();
  }

  /**
   * Sends a case mutation with the session's CSRF token.
   */
  private function caseRequestStatus(string $method, string $path, string $csrf, array $body): int {
    return $this->getHttpClient()->request($method, $this->buildUrl($path), [
      RequestOptions::HTTP_ERRORS => FALSE,
      RequestOptions::COOKIES => $this->getSessionCookies(),
      RequestOptions::HEADERS => [
        'Accept' => 'application/json',
        'X-CSRF-Token' => $csrf,
      ],
      RequestOptions::JSON => $body,
    ])->getStatusCode();
  }

}
