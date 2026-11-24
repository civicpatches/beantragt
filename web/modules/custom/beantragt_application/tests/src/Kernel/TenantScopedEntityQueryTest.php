<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\UserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests tenant-scoped entity query behavior against real entities.
 */
#[Group('beantragt_application')]
final class TenantScopedEntityQueryTest extends GroupKernelTestBase {

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
  ];

  /**
   * The service under test.
   */
  private TenantScopedEntityQuery $tenantScopedQuery;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);

    // Fixture setup writes scoped form schemas outside the behavior under
    // test. Use the same explicit bypass as trusted seed tooling.
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
    $this->tenantScopedQuery = $this->container->get('beantragt_application.tenant_scoped_entity_query');
  }

  /**
   * Organisation members only receive rows from their organisation scope.
   */
  public function testOrganisationMemberQueryReturnsOwnOrganisationRows(): void {
    [$jurisdiction_a, $organisation_a] = $this->createWorkspacePair('scope-jur-a', 'scope-org-a');
    [$jurisdiction_b, $organisation_b] = $this->createWorkspacePair('scope-jur-b', 'scope-org-b');

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $application_a = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
    );
    $application_b = $this->createApplication(
      $this->createFormSchema($organisation_b, $jurisdiction_b),
      $organisation_b,
      $jurisdiction_b,
    );

    $viewer = $this->createStaffUser();
    $organisation_a->addMember($viewer, ['group_roles' => ['org-member']]);

    $this->assertSame(
      [(int) $application_a->id()],
      $this->applicationQueryIds($viewer),
    );
    $this->assertNotContains((int) $application_b->id(), $this->applicationQueryIds($viewer));
  }

  /**
   * Legacy field_group-only rows remain visible to their effective group.
   */
  public function testLegacyFieldGroupOnlyRowsRemainVisible(): void {
    [$jurisdiction_a, $organisation_a] = $this->createWorkspacePair('legacy-jur-a', 'legacy-org-a');
    [$jurisdiction_b, $organisation_b] = $this->createWorkspacePair('legacy-jur-b', 'legacy-org-b');

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $application_a = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
    );
    $application_b = $this->createApplication(
      $this->createFormSchema($organisation_b, $jurisdiction_b),
      $organisation_b,
      $jurisdiction_b,
    );
    $this->clearExplicitScopeFields($application_a);
    $this->clearExplicitScopeFields($application_b);

    $viewer = $this->createStaffUser();
    $organisation_a->addMember($viewer, ['group_roles' => ['org-member']]);

    $this->assertSame(
      [(int) $application_a->id()],
      $this->applicationQueryIds($viewer),
    );
  }

  /**
   * A legacy office row needs the recorded jurisdiction for inherited access.
   */
  public function testJurisdictionWorkspaceScopeRequiresRecordedJurisdictionForLegacyOfficeRows(): void {
    [$jurisdiction_a, $organisation_a] = $this->createWorkspacePair('legacy-wide-jur-a', 'legacy-wide-org-a');
    [$jurisdiction_b, $organisation_b] = $this->createWorkspacePair('legacy-wide-jur-b', 'legacy-wide-org-b');

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $application_a = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
    );
    $application_b = $this->createApplication(
      $this->createFormSchema($organisation_b, $jurisdiction_b),
      $organisation_b,
      $jurisdiction_b,
    );
    $this->clearExplicitScopeFields($application_a);
    $this->clearExplicitScopeFields($application_b);

    $admin = $this->createStaffUser();
    $jurisdiction_a->addMember($admin, ['group_roles' => ['jur-admin']]);

    $this->assertSame([], $this->applicationQueryIds($admin, $jurisdiction_a));
    $this->container->get('database')->update('application')
      ->fields(['field_jurisdiction' => $jurisdiction_a->id()])
      ->condition('id', $application_a->id())->execute();
    $this->assertSame(
      [(int) $application_a->id()],
      $this->applicationQueryIds($admin, $jurisdiction_a),
    );
  }

  /**
   * Jurisdiction admins receive org-scoped and jurisdiction-direct rows.
   */
  public function testJurisdictionAdminQueryIncludesDescendantOrganisationRows(): void {
    [$jurisdiction_a, $organisation_a] = $this->createWorkspacePair('admin-jur-a', 'admin-org-a');
    [$jurisdiction_b, $organisation_b] = $this->createWorkspacePair('admin-jur-b', 'admin-org-b');

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $this->createDefaultWorkflowStatus($jurisdiction_a);
    $application_a = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
    );
    $direct_application = $this->createJurisdictionApplication($jurisdiction_a);
    $application_b = $this->createApplication(
      $this->createFormSchema($organisation_b, $jurisdiction_b),
      $organisation_b,
      $jurisdiction_b,
    );

    $admin = $this->createStaffUser();
    $jurisdiction_a->addMember($admin, ['group_roles' => ['jur-admin']]);

    $ids = $this->applicationQueryIds($admin);
    $expected = [
      (int) $application_a->id(),
      (int) $direct_application->id(),
    ];
    sort($expected);
    $this->assertSame($expected, $ids);
    $this->assertNotContains((int) $application_b->id(), $ids);
  }

  /**
   * Service accounts intentionally bypass tenant query scoping.
   */
  public function testServiceAccountQueryBypassesTenantScope(): void {
    [$jurisdiction_a, $organisation_a] = $this->createWorkspacePair('svc-jur-a', 'svc-org-a');
    [$jurisdiction_b, $organisation_b] = $this->createWorkspacePair('svc-jur-b', 'svc-org-b');

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $application_a = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
    );
    $application_b = $this->createApplication(
      $this->createFormSchema($organisation_b, $jurisdiction_b),
      $organisation_b,
      $jurisdiction_b,
    );

    $service_account = new UserSession([
      'uid' => 999,
      'roles' => ['authenticated', 'service_account'],
    ]);

    $expected = [
      (int) $application_a->id(),
      (int) $application_b->id(),
    ];
    sort($expected);
    $this->assertSame($expected, $this->applicationQueryIds($service_account));
  }

  /**
   * Administrative cleanup can include unconfirmed rows without scope leaks.
   */
  public function testWorkspaceScopeCanIncludeUnconfirmedApplications(): void {
    [$jurisdiction_a, $organisation_a] = $this->createWorkspacePair(
      'cleanup-jur-a',
      'cleanup-org-a',
    );
    [$jurisdiction_b, $organisation_b] = $this->createWorkspacePair(
      'cleanup-jur-b',
      'cleanup-org-b',
    );

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $confirmed = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
    );
    $unconfirmed = $this->createApplication(
      $this->createFormSchema($organisation_a, $jurisdiction_a),
      $organisation_a,
      $jurisdiction_a,
      FALSE,
    );
    $foreign = $this->createApplication(
      $this->createFormSchema($organisation_b, $jurisdiction_b),
      $organisation_b,
      $jurisdiction_b,
      FALSE,
    );

    $admin = $this->createStaffUser();
    $organisation_a->addMember($admin, ['group_roles' => ['org-admin']]);

    $default_query = $this->tenantScopedQuery->getQuery('application', $admin);
    $this->tenantScopedQuery->applyWorkspaceGroupScope(
      $default_query,
      'application',
      $organisation_a,
    );
    $this->assertSame(
      [(int) $confirmed->id()],
      array_map('intval', array_values($default_query->execute())),
    );

    $cleanup_query = $this->entityTypeManager()
      ->getStorage('application')
      ->getQuery()
      ->accessCheck(FALSE);
    $this->tenantScopedQuery->applyAccountScope(
      $cleanup_query,
      'application',
      $admin,
    );
    $this->tenantScopedQuery->applyWorkspaceGroupScope(
      $cleanup_query,
      'application',
      $organisation_a,
      TRUE,
    );
    $cleanup_ids = array_map(
      'intval',
      array_values($cleanup_query->sort('id')->execute()),
    );
    $expected = [(int) $confirmed->id(), (int) $unconfirmed->id()];
    sort($expected);

    $this->assertSame($expected, $cleanup_ids);
    $this->assertNotContains((int) $foreign->id(), $cleanup_ids);
  }

  /**
   * Executes the service-scoped application query for an account.
   */
  private function applicationQueryIds(AccountInterface $account, ?GroupInterface $jurisdiction = NULL): array {
    $query = $this->tenantScopedQuery
      ->getQuery('application', $account)
      ->sort('id');
    if ($jurisdiction instanceof GroupInterface) {
      $this->tenantScopedQuery->applyWorkspaceScope($query, 'application', $jurisdiction);
    }
    $ids = $query->execute();
    $ids = array_map('intval', array_values($ids));
    sort($ids);
    return $ids;
  }

  /**
   * Creates a jurisdiction and organisation pair.
   *
   * @return array{0:\Drupal\group\Entity\GroupInterface, 1:\Drupal\group\Entity\GroupInterface}
   *   Jurisdiction and organisation.
   */
  private function createWorkspacePair(string $jurisdictionSlug, string $organisationSlug): array {
    $jurisdiction = $this->createJurisdiction($jurisdictionSlug, ucfirst($jurisdictionSlug));
    $organisation = $this->createOrganisation($organisationSlug, ucfirst($organisationSlug), $jurisdiction);
    return [$jurisdiction, $organisation];
  }

  /**
   * Creates a staff user.
   */
  private function createStaffUser(): UserInterface {
    return $this->createUser([
      'view published application entities',
      'edit any application entities',
    ]);
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
  private function createDefaultWorkflowStatus(GroupInterface $group): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Eingegangen',
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => 'eingegangen',
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => TRUE,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Creates a published form schema in one organisation scope.
   */
  private function createFormSchema(GroupInterface $organisation, GroupInterface $jurisdiction): FormSchemaInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Tenant scoped query smoke',
      'field_schema' => json_encode([
        'title' => 'Tenant scoped query smoke',
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
      'field_organisation' => ['target_id' => $organisation->id()],
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
  private function createApplication(
    FormSchemaInterface $schema,
    GroupInterface $organisation,
    GroupInterface $jurisdiction,
    bool $emailConfirmed = TRUE,
  ): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['message' => 'Hello'], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'applicant@example.org',
      'field_email_confirmed' => $emailConfirmed,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Creates a submitted application directly in one jurisdiction scope.
   */
  private function createJurisdictionApplication(GroupInterface $jurisdiction): ApplicationInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Jurisdiction direct scoped query smoke',
      'field_schema' => json_encode([
        'title' => 'Jurisdiction direct scoped query smoke',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              [
                'id' => 'message',
                'type' => 'text',
                'label' => 'Message',
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $schema->save();

    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['message' => 'Hello jurisdiction'], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'direct@example.org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Simulates rows that predate the explicit jurisdiction/organisation fields.
   */
  private function clearExplicitScopeFields(ApplicationInterface $application): void {
    $database = $this->container->get('database');
    foreach (['application', 'application_revision'] as $table) {
      $database->update($table)
        ->fields([
          'field_jurisdiction' => NULL,
          'field_organisation' => NULL,
        ])
        ->condition('id', $application->id())
        ->execute();
    }
  }

}
