<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests tenant access against real application entities.
 */
#[Group('beantragt_group')]
#[Group('beantragt_application')]
final class ApplicationCrossJurisdictionAccessTest extends GroupKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('file', ['file_usage']);
    $this->ensureRole('department_admin');
  }

  /**
   * Application entity access stays scoped to the effective organisation.
   */
  public function testApplicationAccessRequiresEffectiveScopeMembership(): void {
    $jurisdiction_a = $this->createJurisdiction('jur-a', 'Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('jur-b', 'Jurisdiction B');
    $organisation_a = $this->createOrganisation('org-a', 'Organisation A', $jurisdiction_a);
    $organisation_b = $this->createOrganisation('org-b', 'Organisation B', $jurisdiction_b);

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);

    $schema_a = $this->createFormSchema($organisation_a, $jurisdiction_a);
    $application_a = $this->createApplication($schema_a, $organisation_a, $jurisdiction_a);

    $org_a_viewer = $this->createStaffUser();
    $organisation_a->addMember($org_a_viewer, ['group_roles' => ['org-member']]);

    $org_a_editor = $this->createStaffUser();
    $organisation_a->addMember($org_a_editor, ['group_roles' => ['org-admin']]);

    $org_b_admin = $this->createStaffUser();
    $organisation_b->addMember($org_b_admin, ['group_roles' => ['org-admin']]);

    $this->assertTrue($application_a->access('view', $org_a_viewer), 'An organisation member can view applications in its own organisation.');
    $this->assertFalse($application_a->access('update', $org_a_viewer), 'An organisation member cannot update applications even with global edit permission.');
    $this->assertTrue($application_a->access('update', $org_a_editor), 'An organisation admin can update applications in its own organisation.');
    $this->assertTrue($application_a->access('delete', $org_a_editor), 'An organisation admin can delete applications in its own organisation.');
    $this->assertFalse($application_a->access('view', $org_b_admin), 'A different organisation administrator cannot view applications across jurisdictions.');
    $this->assertFalse($application_a->access('update', $org_b_admin), 'A different organisation administrator cannot update applications across jurisdictions.');
    $this->assertFalse($application_a->access('delete', $org_b_admin), 'A different organisation administrator cannot delete applications across jurisdictions.');
  }

  /**
   * Application revisions keep tenant scope and access boundaries.
   */
  public function testApplicationRevisionsPersistScopeFields(): void {
    $jurisdiction_a = $this->createJurisdiction('revision-jur-a', 'Revision Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('revision-jur-b', 'Revision Jurisdiction B');
    $organisation_a = $this->createOrganisation('revision-org-a', 'Revision Organisation A', $jurisdiction_a);
    $organisation_b = $this->createOrganisation('revision-org-b', 'Revision Organisation B', $jurisdiction_b);

    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $review_status = $this->createWorkflowStatus($organisation_a, 'in_review', FALSE);

    $schema_a = $this->createFormSchema($organisation_a, $jurisdiction_a);
    $application_a = $this->createApplication($schema_a, $organisation_a, $jurisdiction_a);
    $initial_revision_id = (int) $application_a->getRevisionId();

    $application_a->setNewRevision(TRUE);
    $application_a->setWorkflowStatusId((int) $review_status->id());
    $application_a->setRevisionLogMessage('Moved to review');
    $application_a->save();
    $review_revision_id = (int) $application_a->getRevisionId();

    $application_storage = $this->entityTypeManager()->getStorage('application');
    assert($application_storage instanceof RevisionableStorageInterface);

    $initial_revision = $application_storage->loadRevision($initial_revision_id);
    $review_revision = $application_storage->loadRevision($review_revision_id);
    assert($initial_revision instanceof ApplicationInterface);
    assert($review_revision instanceof ApplicationInterface);

    foreach ([$initial_revision, $review_revision] as $revision) {
      $this->assertSame((int) $jurisdiction_a->id(), (int) $revision->get('field_jurisdiction')->target_id);
      $this->assertSame((int) $organisation_a->id(), (int) $revision->get('field_organisation')->target_id);
      $this->assertSame((int) $organisation_a->id(), (int) $revision->get('field_group')->target_id);
    }

    $org_a_viewer = $this->createStaffUser();
    $organisation_a->addMember($org_a_viewer, ['group_roles' => ['org-member']]);

    $org_b_viewer = $this->createStaffUser();
    $organisation_b->addMember($org_b_viewer, ['group_roles' => ['org-member']]);

    $this->assertTrue($initial_revision->access('view', $org_a_viewer));
    $this->assertTrue($review_revision->access('view', $org_a_viewer));
    $this->assertFalse($initial_revision->access('view', $org_b_viewer));
    $this->assertFalse($review_revision->access('view', $org_b_viewer));
  }

  /**
   * Scope normalization after FormSchema::preSave still fences revision state.
   */
  public function testLateWorkspaceNormalizationCreatesSchemaRevisionFence(): void {
    $schema = $this->createUnscopedFormSchema();
    $initial_revision_id = (int) $schema->getRevisionId();
    $initial_version = $schema->getVersion();

    $jurisdiction = $this->createJurisdiction('late-scope-jur', 'Late Scope Jurisdiction');
    $schema->set('label', 'Late scope normalization smoke');
    $this->saveTrustedFixture($schema);

    self::assertGreaterThan($initial_revision_id, (int) $schema->getRevisionId());
    // A workspace change fences the revision but keeps the content version.
    self::assertSame($initial_version, $schema->getVersion());
    self::assertSame((int) $jurisdiction->id(), (int) $schema->get('field_jurisdiction')->target_id);
    self::assertSame((int) $jurisdiction->id(), (int) $schema->get('field_group')->target_id);
    self::assertTrue($schema->get('field_organisation')->isEmpty());
  }

  /**
   * Jurisdiction-direct public forms use the jurisdiction status catalogue.
   */
  public function testJurisdictionDirectApplicationUsesJurisdictionDefaultStatus(): void {
    $jurisdiction = $this->createJurisdiction('direct-jur', 'Direct Jurisdiction');
    $status = $this->createDefaultWorkflowStatus($jurisdiction);
    $schema = $this->createJurisdictionFormSchema($jurisdiction);

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

    $this->assertSame((int) $status->id(), (int) $application->get('field_workflow_status')->target_id);
    $this->assertSame((int) $jurisdiction->id(), (int) $application->get('field_jurisdiction')->target_id);
    $this->assertTrue($application->get('field_organisation')->isEmpty());
  }

  /**
   * Schema scope is available to the lock before group presave normalizes it.
   */
  public function testNewApplicationCanInheritScopeFromSchema(): void {
    $jurisdiction = $this->createJurisdiction('inherited-jur', 'Inherited Jurisdiction');
    $status = $this->createDefaultWorkflowStatus($jurisdiction);
    $schema = $this->createJurisdictionFormSchema($jurisdiction);

    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['message' => 'Inherited scope'], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'inherited@example.org',
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);

    $this->assertSame((int) $status->id(), (int) $application->get('field_workflow_status')->target_id);
    $this->assertSame((int) $jurisdiction->id(), (int) $application->get('field_jurisdiction')->target_id);
    $this->assertSame((int) $jurisdiction->id(), (int) $application->get('field_group')->target_id);
    $this->assertTrue($application->get('field_organisation')->isEmpty());
  }

  /**
   * Unmanaged cross-jurisdiction targets fail validation and presave.
   */
  public function testUnmanagedCrossJurisdictionRehomingIsRejected(): void {
    [$jurisdiction_a, $jurisdiction_b, , $organisation_b, $application] = $this->rehomingFixture();
    $manager = $this->createJurisdictionManager($jurisdiction_a);
    $this->container->get('current_user')->setAccount($manager);

    $application->set('field_jurisdiction', ['target_id' => $jurisdiction_b->id()]);
    $application->set('field_organisation', ['target_id' => $organisation_b->id()]);
    $application->set('field_group', ['target_id' => $organisation_b->id()]);

    $violations = $application->validate();
    self::assertSame(1, $violations->count());
    self::assertStringContainsString('Jurisdiction B', (string) $violations[0]->getMessage());

    $this->expectException(AccessDeniedHttpException::class);
    beantragt_group_application_presave($application);
  }

  /**
   * An organisation-only foreign move yields a field-local violation.
   */
  public function testForeignOrganisationOnlyRehomingIsRejected(): void {
    [$jurisdiction_a, , , $organisation_b, $application] = $this->rehomingFixture();
    $manager = $this->createJurisdictionManager($jurisdiction_a);
    $this->container->get('current_user')->setAccount($manager);

    $application->set('field_organisation', ['target_id' => $organisation_b->id()]);

    $violations = $application->validate();
    self::assertSame(1, $violations->count());
    self::assertStringContainsString('Organisation B', (string) $violations[0]->getMessage());
    self::assertStringStartsWith('field_organisation', $violations[0]->getPropertyPath());

    $this->expectException(AccessDeniedHttpException::class);
    beantragt_group_application_presave($application);
  }

  /**
   * Scope added by normalization is checked by the final presave guard.
   */
  public function testDefaultScopeNormalizationCannotBypassAuthorization(): void {
    [$jurisdiction_a, , $organisation_a, $organisation_b, $application] = $this->rehomingFixture();
    $manager = $this->createJurisdictionManager($jurisdiction_a);
    $this->container->get('current_user')->setAccount($manager);

    // Leave one organisation so the existing single-tenant default fallback
    // selects the foreign target after all explicit scope fields are cleared.
    $organisation_a->delete();

    $application->set('field_schema', NULL);
    $application->set('field_jurisdiction', NULL);
    $application->set('field_organisation', NULL);
    $application->set('field_group', NULL);

    try {
      beantragt_group_application_presave($application);
    }
    catch (AccessDeniedHttpException) {
      self::addToAssertionCount(1);
      return;
    }

    self::assertSame((int) $organisation_b->id(), (int) $application->get('field_organisation')->target_id);
    self::fail(sprintf('Default scope %s must not bypass authorization.', $organisation_b->label()));
  }

  /**
   * A target-jurisdiction manager may re-home an existing application.
   */
  public function testManagedCrossJurisdictionRehomingIsAllowed(): void {
    [, $jurisdiction_b, , $organisation_b, $application] = $this->rehomingFixture();
    $manager = $this->createJurisdictionManager($jurisdiction_b);
    $this->container->get('current_user')->setAccount($manager);

    $application->set('field_jurisdiction', ['target_id' => $jurisdiction_b->id()]);
    $application->set('field_organisation', ['target_id' => $organisation_b->id()]);
    $application->set('field_group', ['target_id' => $organisation_b->id()]);

    self::assertSame(0, $application->validate()->count());
    $application->save();
    self::assertSame((int) $jurisdiction_b->id(), (int) $application->get('field_jurisdiction')->target_id);
    self::assertSame((int) $organisation_b->id(), (int) $application->get('field_organisation')->target_id);
  }

  /**
   * Delegate-style organisation changes inside one jurisdiction stay valid.
   */
  public function testSameJurisdictionOrganisationDelegationIsAllowed(): void {
    [$jurisdiction_a, , , , $application] = $this->rehomingFixture();
    $organisation_a2 = $this->createOrganisation('org-a2', 'Organisation A2', $jurisdiction_a);
    $manager = $this->createJurisdictionManager($jurisdiction_a);
    $this->container->get('current_user')->setAccount($manager);

    // ApplicationAssignmentController::delegate() only changes this field;
    // the existing normalizer keeps field_group consistent during save.
    $application->set('field_organisation', ['target_id' => $organisation_a2->id()]);

    self::assertSame(0, $application->validate()->count());
    $application->save();
    self::assertSame((int) $organisation_a2->id(), (int) $application->get('field_organisation')->target_id);
    self::assertSame((int) $organisation_a2->id(), (int) $application->get('field_group')->target_id);
  }

  /**
   * Creates a staff user with broad application permissions.
   */
  private function createStaffUser(): UserInterface {
    return $this->createUser([
      'view published application entities',
      'edit any application entities',
      'delete any application entities',
    ]);
  }

  /**
   * Creates two tenant scopes and one application in the first tenant.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: \Drupal\group\Entity\GroupInterface, 2: \Drupal\group\Entity\GroupInterface, 3: \Drupal\group\Entity\GroupInterface, 4: \Drupal\beantragt_application\Entity\ApplicationInterface}
   *   Jurisdictions, organisations, and the source application.
   */
  private function rehomingFixture(): array {
    $jurisdiction_a = $this->createJurisdiction('jur-a', 'Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('jur-b', 'Jurisdiction B');
    $organisation_a = $this->createOrganisation('org-a', 'Organisation A', $jurisdiction_a);
    $organisation_b = $this->createOrganisation('org-b', 'Organisation B', $jurisdiction_b);
    $this->createDefaultWorkflowStatus($organisation_a);
    $this->createDefaultWorkflowStatus($organisation_b);
    $schema = $this->createFormSchema($organisation_a, $jurisdiction_a);
    $application = $this->createApplication($schema, $organisation_a, $jurisdiction_a);

    return [$jurisdiction_a, $jurisdiction_b, $organisation_a, $organisation_b, $application];
  }

  /**
   * Creates a department admin with a jurisdiction admin membership.
   */
  private function createJurisdictionManager(GroupInterface $jurisdiction): UserInterface {
    $user = $this->createStaffUser();
    $user->addRole('department_admin');
    $user->save();
    $jurisdiction->addMember($user, ['group_roles' => ['jur-admin']]);
    return $user;
  }

  /**
   * Ensures a Drupal user role exists in the kernel test site.
   */
  private function ensureRole(string $role_id): void {
    if (Role::load($role_id) !== NULL) {
      return;
    }
    Role::create([
      'id' => $role_id,
      'label' => $role_id,
    ])->save();
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
  private function createDefaultWorkflowStatus(GroupInterface $organisation): WorkflowStatusInterface {
    return $this->createWorkflowStatus($organisation, 'eingegangen', TRUE);
  }

  /**
   * Creates a workflow status owned by a jurisdiction or organisation.
   */
  private function createWorkflowStatus(GroupInterface $group, string $machineName, bool $isDefault): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => $isDefault ? 'Eingegangen' : 'In Bearbeitung',
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => $machineName,
      'field_color' => $isDefault ? '#2563eb' : '#0f766e',
      'field_icon' => $isDefault ? 'i-lucide-inbox' : 'i-lucide-pencil',
      'field_weight' => $isDefault ? 0 : 10,
      'field_is_default' => $isDefault,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => $isDefault ? 'received' : 'in_progress']],
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Saves fixture scaffolding with the explicit bypass trusted tooling uses.
   *
   * The form schema scaffolding is not the behavior under test here, and the
   * group test base signs in an account without any group roles. Saving it
   * under the bypass keeps the workspace-scope invariant intact for the
   * assertions that follow.
   */
  private function saveTrustedFixture(FormSchemaInterface $schema): void {
    $current_user = $this->container->get('current_user');
    $previous = $current_user->getAccount();
    $trusted = $this->createUser(['bypass beantragt group scope']);
    $current_user->setAccount($trusted);
    try {
      // New forms are authored by the account that saves them.
      if ($schema->isNew()) {
        $schema->setOwnerId((int) $trusted->id());
      }
      $schema->save();
    }
    finally {
      $current_user->setAccount($previous);
    }
  }

  /**
   * Creates a published form schema in one organisation scope.
   */
  private function createFormSchema(GroupInterface $organisation, GroupInterface $jurisdiction): FormSchemaInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Tenant access smoke',
      'field_schema' => json_encode([
        'title' => 'Tenant access smoke',
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
    $this->saveTrustedFixture($schema);
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Creates a legacy unscoped form before its first workspace exists.
   */
  private function createUnscopedFormSchema(): FormSchemaInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Unscoped normalization smoke',
      'field_schema' => json_encode([
        'title' => 'Unscoped normalization smoke',
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
      'status' => TRUE,
    ]);
    $this->saveTrustedFixture($schema);
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Creates a published jurisdiction-direct form schema.
   */
  private function createJurisdictionFormSchema(GroupInterface $jurisdiction): FormSchemaInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Jurisdiction direct smoke',
      'field_schema' => json_encode([
        'title' => 'Jurisdiction direct smoke',
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
      'field_publication_scope' => 'public',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $this->saveTrustedFixture($schema);
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
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

}
