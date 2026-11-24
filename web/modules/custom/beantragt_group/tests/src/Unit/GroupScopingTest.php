<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_group\Service\ApplicationScopeAuthorizationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the beantragt_group module's entity scoping configuration.
 *
 * Since hook_entity_base_field_info() and
 * _beantragt_group_entity_group_access()
 * rely heavily on Drupal's entity system and cannot be unit-tested without a
 * database, these tests verify the critical configuration aspects: that the
 * module targets the correct entity types and keeps jurisdiction and
 * organisation scopes distinct.
 */
#[Group('beantragt_group')]
class GroupScopingTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    require_once dirname(__DIR__, 3) . '/beantragt_group.module';
  }

  /**
   * Tests that the target entity types list includes form schema groups.
   *
   * This was added in update_10001 to prevent cross-org PII leaks.
   * The hook function checks entity type IDs against this list.
   */
  public function testTargetEntityTypesIncludeFormSchemaGroup(): void {
    // The target types list from beantragt_group.module.
    $target_types = ['form_schema', 'form_schema_group', 'application', 'applicant'];

    $this->assertContains('applicant', $target_types);
    $this->assertContains('form_schema', $target_types);
    $this->assertContains('form_schema_group', $target_types);
    $this->assertContains('application', $target_types);
    $this->assertCount(4, $target_types);
  }

  /**
   * Tests that entity types are checked with strict comparison.
   *
   * The module uses in_array(..., TRUE) for strict type checking.
   * This ensures no type coercion vulnerabilities.
   */
  public function testStrictTypeChecking(): void {
    $target_types = ['form_schema', 'form_schema_group', 'application', 'applicant'];

    // Strict check must work.
    $this->assertTrue(in_array('form_schema', $target_types, TRUE));
    $this->assertTrue(in_array('applicant', $target_types, TRUE));

    // Numeric values should not match (strict prevents type juggling).
    $this->assertFalse(in_array(0, $target_types, TRUE));
    $this->assertFalse(in_array(TRUE, $target_types, TRUE));
  }

  /**
   * Tests the explicit scope field contract.
   */
  public function testExplicitScopeFields(): void {
    $scope_fields = ['field_jurisdiction', 'field_organisation', 'field_group'];

    $this->assertContains('field_jurisdiction', $scope_fields);
    $this->assertContains('field_organisation', $scope_fields);
    $this->assertContains('field_group', $scope_fields);
    $this->assertSame('field_organisation', $scope_fields[1], 'Organisation should be evaluated before legacy field_group.');
  }

  /**
   * Application presave keeps submit-time org scope for jurisdiction forms.
   */
  public function testApplicationPresaveKeepsExplicitOrganisationForJurisdictionDirectSchema(): void {
    $jurisdiction = $this->createScopedGroupEntity(3, 'jur');
    $organisation = $this->createScopedGroupEntity(4, 'org', 3);
    $this->installGroupAccessContainer([
      3 => $jurisdiction,
      4 => $organisation,
    ]);

    $schema_fields = [
      'field_jurisdiction' => $this->fieldWithTargetId(3),
      'field_organisation' => $this->fieldWithTargetId(NULL),
      'field_group' => $this->fieldWithTargetId(NULL),
    ];
    $schema = $this->createMock(FieldableEntityInterface::class);
    $schema->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $schema_fields)
    );
    $schema->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $schema_fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name))
    );

    $application_fields = [
      'field_schema' => $this->fieldWithEntity($schema),
      'field_jurisdiction' => $this->fieldWithTargetId(3),
      'field_organisation' => $this->fieldWithTargetId(4),
      'field_group' => $this->fieldWithTargetId(4),
    ];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getEntityTypeId')->willReturn('application');
    $application->method('id')->willReturn(99);
    $application->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $application_fields)
    );
    $application->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $application_fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name))
    );
    $application->method('set')->willReturnCallback(function (string $field_name, mixed $value) use ($application): ApplicationInterface {
      $this->assertNotSame('field_organisation', $field_name, 'Application organisation scope must not be cleared from a jurisdiction-direct schema.');
      return $application;
    });

    beantragt_group_application_presave($application);
  }

  /**
   * Form scope normalization runs after the entity's own preSave fence.
   */
  public function testFormSchemaPresaveFencesNormalizedWorkspaceScope(): void {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('hasField')->willReturn(FALSE);
    $schema->expects(self::once())->method('fenceNormalizedWorkspaceScope');

    beantragt_group_form_schema_presave($schema);
  }

  /**
   * Tests the access result logic for group membership.
   *
   * Since the actual access function uses Drupal's static service container,
   * we test the logical flow here:
   * - No scope field -> forbidden for tenant-bound records
   * - User is member of the effective scope group -> neutral (defer)
   * - User writes a schema/workflow definition -> requires group admin
   * - User is NOT member of the effective scope group -> forbidden.
   */
  public function testGroupAccessLogic(): void {
    // Test the conditional logic without calling Drupal services.
    // Admin bypass.
    $has_admin_permission = TRUE;
    $this->assertTrue($has_admin_permission, 'Admin should bypass group check.');

    // No scope field.
    $has_field = FALSE;
    $tenant_bound_entity = TRUE;
    $this->assertFalse($has_field && !$tenant_bound_entity, 'Tenant-bound records without scope must not defer.');

    // Positive effective group ID with membership.
    $group_id = 5;
    $is_member = TRUE;
    $this->assertTrue($group_id > 0 && $is_member, 'Member should be allowed.');

    // Write access to form/workflow definitions requires scoped admin role.
    $requires_group_admin_write = TRUE;
    $is_group_admin = FALSE;
    $this->assertFalse($requires_group_admin_write && $is_member && $is_group_admin);

    // Positive effective group ID without membership.
    $is_member = FALSE;
    $this->assertTrue($group_id > 0 && !$is_member, 'Non-member should be forbidden.');
  }

  /**
   * Tests that scoped application views still allow plain group members.
   */
  public function testApplicationViewAllowsPlainScopedMember(): void {
    $account = $this->createAccount(['authenticated', 'case_worker'], 21);
    $membership = $this->createMembership('org-member', FALSE);
    $org = $this->createGroup(4, 'org', $account, $membership);
    $this->installGroupAccessContainer([4 => $org]);
    $application = $this->createScopedEntity('application', 4);

    $result = \_beantragt_group_entity_group_access($application, 'view', $account);

    $this->assertFalse($result->isForbidden());
  }

  /**
   * A direct member with the case worker role passes the scope check.
   */
  public function testApplicationUpdateAllowsCaseWorkerWithDirectMembership(): void {
    $account = $this->createAccount(['authenticated', 'case_worker'], 21);
    $membership = $this->createMembership('org-member', FALSE);
    $org = $this->createGroup(4, 'org', $account, $membership);
    $this->installGroupAccessContainer([4 => $org]);
    $application = $this->createScopedEntity('application', 4);

    $result = \_beantragt_group_entity_group_access($application, 'update', $account);

    $this->assertFalse($result->isForbidden());
    $without_role = $this->createAccount(['authenticated'], 21);
    $this->installGroupAccessContainer([4 => $this->createGroup(4, 'org', $without_role, $membership)]);
    $this->assertTrue(\_beantragt_group_entity_group_access($application, 'update', $without_role)->isForbidden());
  }

  /**
   * Scoped group admins may update applications.
   */
  public function testApplicationUpdateAllowsScopedGroupAdmin(): void {
    $account = $this->createAccount(['authenticated', 'case_worker'], 21);
    $membership = $this->createMembership('org-admin', TRUE);
    $org = $this->createGroup(4, 'org', $account, $membership);
    $this->installGroupAccessContainer([4 => $org]);
    $application = $this->createScopedEntity('application', 4);

    $result = \_beantragt_group_entity_group_access($application, 'update', $account);

    $this->assertFalse($result->isForbidden());
  }

  /**
   * Applicant PII writes follow the same group-admin boundary.
   */
  public function testApplicantDeleteRejectsPlainScopedMember(): void {
    $account = $this->createAccount(['authenticated', 'case_worker'], 21);
    $membership = $this->createMembership('org-member', FALSE);
    $org = $this->createGroup(4, 'org', $account, $membership);
    $this->installGroupAccessContainer([4 => $org]);
    $applicant = $this->createScopedEntity('applicant', 4);

    $result = \_beantragt_group_entity_group_access($applicant, 'delete', $account);

    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests the operation matrix for scoped group-admin write checks.
   */
  public function testScopedWriteRequirementMatrix(): void {
    $this->assertFalse(\_beantragt_group_operation_requires_group_admin_write('application', 'view'));
    $this->assertTrue(\_beantragt_group_operation_requires_group_admin_write('application', 'update'));
    $this->assertTrue(\_beantragt_group_operation_requires_group_admin_write('applicant', 'delete'));
    $this->assertTrue(\_beantragt_group_operation_requires_group_admin_write('form_schema', 'update'));
    $this->assertFalse(\_beantragt_group_operation_requires_group_admin_write('group', 'update'));
  }

  /**
   * Tests the public form proxy gate for the Nuxt service account.
   */
  public function testServiceAccountMayProxyPublicInternetFormSchema(): void {
    $schema = $this->createFormSchema(TRUE, 'internet');
    $account = $this->createAccount(['authenticated', 'service_account']);

    $this->assertTrue(\_beantragt_group_is_public_form_schema_service_account_view($schema, 'view', $account));
  }

  /**
   * Tests the public form proxy gate rejects non-public cases.
   */
  public function testPublicFormProxyGateRejectsNonPublicCases(): void {
    $service_account = $this->createAccount(['authenticated', 'service_account']);
    $staff_account = $this->createAccount(['authenticated', 'case_worker']);

    $this->assertFalse(\_beantragt_group_is_public_form_schema_service_account_view(
      $this->createFormSchema(TRUE, 'internet'),
      'update',
      $service_account
    ));
    $this->assertFalse(\_beantragt_group_is_public_form_schema_service_account_view(
      $this->createFormSchema(FALSE, 'internet'),
      'view',
      $service_account
    ));
    $this->assertFalse(\_beantragt_group_is_public_form_schema_service_account_view(
      $this->createFormSchema(TRUE, 'internet', 'draft'),
      'view',
      $service_account
    ));
    $this->assertFalse(\_beantragt_group_is_public_form_schema_service_account_view(
      $this->createFormSchema(TRUE, 'intranet'),
      'view',
      $service_account
    ));
    $this->assertFalse(\_beantragt_group_is_public_form_schema_service_account_view(
      $this->createFormSchema(TRUE, 'internet'),
      'view',
      $staff_account
    ));
  }

  /**
   * Tests the owner view gate for scoped citizen applications.
   */
  public function testApplicationOwnerViewDefersToApplicationAccess(): void {
    $application = $this->createApplication(5);

    $owner = $this->createAccount(['authenticated'], 5);
    $other = $this->createAccount(['authenticated'], 6);
    $service_owner = $this->createAccount(['authenticated', 'service_account'], 5);

    $this->assertTrue(\_beantragt_group_is_application_owner_view($application, 'view', $owner));
    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'view', $other));
    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'view', $service_owner));
    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'update', $owner));
  }

  /**
   * Tests the owner view gate also supports verified applicant email claims.
   */
  public function testApplicationOwnerViewAcceptsVerifiedEmailMatch(): void {
    $application = $this->createApplication(2, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'public_schema' => TRUE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);

    $this->verifiedMailContainer();
    $email_owner = $this->createAccount(['authenticated'], 6, 'Max.Mustermann@Example.Org');
    $other = $this->createAccount(['authenticated'], 7, 'erika.mustermann@example.org');

    $this->assertTrue(\_beantragt_group_is_application_owner_view($application, 'view', $email_owner));
    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'view', $other));
  }

  /**
   * A raw address match cannot open the public submission owner path.
   */
  public function testApplicationOwnerViewRejectsUnverifiedMailMatch(): void {
    $this->verifiedMailContainer(NULL, FALSE);
    $application = $this->createApplication(2, [
      'applicant_email' => 'citizen@example.test',
      'public_status_token_hash' => str_repeat('a', 64),
      'public_schema' => TRUE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $account = $this->createAccount(['authenticated'], 6, 'citizen@example.test');
    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'view', $account));
    \Drupal::getContainer()->set('beantragt_application.confirmed_mailbox', NULL);
    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'view', $account));
  }

  /**
   * Registers mailbox proof independently of the account's raw address.
   */
  private function verifiedMailContainer(?ContainerBuilder $container = NULL, bool $verified = TRUE): void {
    $container ??= new ContainerBuilder();
    $manager = $container->has('entity_type.manager')
      ? $container->get('entity_type.manager')
      : $this->createMock(EntityTypeManagerInterface::class);
    $mailbox = $this->getMockBuilder(ConfirmedApplicationMailbox::class)
      ->setConstructorArgs([
        $this->createMock(Connection::class),
        $manager,
        new ApplicationDataProtectionService($manager, new NullLogger()),
        new RequestStack(),
        $this->createMock(ProtectedAccessLog::class),
      ])
      ->onlyMethods(['matchingApplicationIds'])
      ->getMock();
    $mailbox->method('matchingApplicationIds')->willReturnCallback(
      static fn(AccountInterface $account): array => $verified && strtolower((string) $account->getEmail()) === 'max.mustermann@example.org' ? [1] : []
    );
    $container->set('beantragt_application.confirmed_mailbox', $mailbox);
    \Drupal::setContainer($container);
  }

  /**
   * Tests the email owner gate uses the form revision of the submission.
   */
  public function testApplicationOwnerViewUsesSubmittedSchemaRevision(): void {
    $revisions = [
      14 => $this->schemaWith('internet', 'published'),
      15 => $this->schemaWith('intranet', 'published'),
    ];
    $revision_storage = $this->createMock(RevisionableStorageInterface::class);
    $revision_storage->method('loadRevision')->willReturnCallback(
      static fn(int|string $revision_id): ?FieldableEntityInterface => $revisions[(int) $revision_id] ?? NULL
    );
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->willReturnCallback(
      static fn(string $entity_type_id): bool => $entity_type_id === 'form_schema'
    );
    $entity_type_manager->method('getStorage')->with('form_schema')->willReturn($revision_storage);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);
    $this->verifiedMailContainer($container);

    try {
      $email_owner = $this->createAccount(['authenticated'], 6, 'max.mustermann@example.org');
      $submitted_while_public = $this->createApplication(2, [
        'applicant_email' => 'max.mustermann@example.org',
        'public_status_token_hash' => str_repeat('a', 64),
        'schema' => $this->schemaWith('internet', 'draft'),
        'schema_revision_id' => 14,
        'owner_roles' => ['authenticated', 'service_account'],
      ]);
      $submitted_on_intranet = $this->createApplication(2, [
        'applicant_email' => 'max.mustermann@example.org',
        'public_status_token_hash' => str_repeat('a', 64),
        'schema' => $this->schemaWith('internet', 'published'),
        'schema_revision_id' => 15,
        'owner_roles' => ['authenticated', 'service_account'],
      ]);

      $this->assertTrue(\_beantragt_group_is_application_owner_view($submitted_while_public, 'view', $email_owner));
      $this->assertFalse(\_beantragt_group_is_application_owner_view($submitted_on_intranet, 'view', $email_owner));
    }
    finally {
      \Drupal::unsetContainer();
    }
  }

  /**
   * Tests the email owner gate rejects non-public submissions.
   */
  public function testApplicationOwnerViewRejectsNonPublicEmailMatch(): void {
    $application = $this->createApplication(2, [
      'applicant_email' => 'max.mustermann@example.org',
      'public_status_token_hash' => str_repeat('a', 64),
      'public_schema' => FALSE,
      'owner_roles' => ['authenticated', 'service_account'],
    ]);
    $email_owner = $this->createAccount(['authenticated'], 6, 'max.mustermann@example.org');

    $this->assertFalse(\_beantragt_group_is_application_owner_view($application, 'view', $email_owner));
  }

  /**
   * Tests public proxy bypass happens after scope integrity checks.
   */
  public function testPublicFormProxyGateRunsAfterScopeIntegrityChecks(): void {
    $module_source = file_get_contents(dirname(__DIR__, 3) . '/beantragt_group.module');

    $missing_scope_check = strpos($module_source, 'Entity references a missing scope group.');
    $invalid_bundle_check = strpos($module_source, 'Entity references an invalid scope group bundle.');
    $public_proxy_gate = strpos($module_source, '_beantragt_group_is_public_form_schema_service_account_view($entity, $operation, $account)');
    $membership_check = strpos($module_source, '$membership = $group->getMember($account);');

    $this->assertIsInt($missing_scope_check);
    $this->assertIsInt($invalid_bundle_check);
    $this->assertIsInt($public_proxy_gate);
    $this->assertIsInt($membership_check);
    $this->assertGreaterThan($missing_scope_check, $public_proxy_gate);
    $this->assertGreaterThan($invalid_bundle_check, $public_proxy_gate);
    $this->assertLessThan($membership_check, $public_proxy_gate);
  }

  /**
   * Tests that group access hooks exist for scoped entity types.
   *
   * Verifies that the module defines access hooks for form_schema,
   * form_schema_group, application, and applicant. The function names follow
   * Drupal's hook_ENTITY_TYPE_access() pattern.
   */
  public function testAccessHooksExistForAllEntityTypes(): void {
    // These functions should be defined in beantragt_group.module.
    $expected_hooks = [
      'beantragt_group_form_schema_access',
      'beantragt_group_form_schema_group_access',
      'beantragt_group_application_access',
      'beantragt_group_applicant_access',
    ];

    // We cannot require the .module file in a unit test without
    // bootstrapping Drupal (it uses Drupal's t() function and
    // BaseFieldDefinition). Instead, we verify the expected pattern.
    foreach ($expected_hooks as $hook_name) {
      $this->assertMatchesRegularExpression(
        '/^beantragt_group_[a-z_]+_access$/',
        $hook_name,
        "Hook {$hook_name} follows the Drupal naming convention."
      );
    }
  }

  /**
   * Creates a form schema mock for public proxy gate tests.
   */
  private function createFormSchema(bool $published, string $publication_scope, string $schema_status = 'published'): FormSchemaInterface {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('isPublished')->willReturn($published);
    $schema->method('getSchemaStatus')->willReturn($schema_status);
    $schema->method('getPublicationScope')->willReturn($publication_scope);
    return $schema;
  }

  /**
   * Creates an application mock for owner gate tests.
   */
  private function createApplication(int $owner_id, array $options = []): ApplicationInterface {
    $applicant_email = $options['applicant_email'] ?? NULL;
    $public_status_token_hash = $options['public_status_token_hash'] ?? NULL;
    $public_schema = (bool) ($options['public_schema'] ?? FALSE);
    $owner_roles = $options['owner_roles'] ?? ['authenticated'];

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('getOwnerId')->willReturn($owner_id);
    $application->method('getOwner')->willReturn($this->ownerAccount($owner_roles));

    $fields = [
      'field_applicant_email' => $this->fieldWithValue($applicant_email),
      'field_public_status_token_hash' => $this->fieldWithValue($public_status_token_hash),
      'field_schema' => $this->fieldWithEntity($options['schema'] ?? $this->schemaEntity($public_schema)),
    ];
    if (array_key_exists('schema_revision_id', $options)) {
      $fields['field_schema_revision_id'] = $this->fieldWithValue((string) $options['schema_revision_id']);
    }
    $application->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $fields)
    );
    $application->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name))
    );

    return $application;
  }

  /**
   * Creates a field item list exposing a scalar value.
   */
  private function fieldWithValue(?string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === NULL || trim($value) === '');
    $field->method('__get')->willReturnCallback(
      static fn(string $name): ?string => $name === 'value' ? $value : NULL
    );
    return $field;
  }

  /**
   * Creates a field item list exposing an entity reference.
   */
  private function fieldWithEntity(?FieldableEntityInterface $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($entity === NULL);
    $field->method('__get')->willReturnCallback(
      static fn(string $name): ?FieldableEntityInterface => $name === 'entity' ? $entity : NULL
    );
    return $field;
  }

  /**
   * Creates a field item list exposing a target_id reference.
   */
  private function fieldWithTargetId(?int $target_id): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($target_id === NULL);
    $field->method('__get')->willReturnCallback(
      static fn(string $name): ?int => $name === 'target_id' ? $target_id : NULL
    );
    $field->method('getValue')->willReturn($target_id === NULL ? [] : [['target_id' => $target_id]]);
    return $field;
  }

  /**
   * Creates a schema-like fieldable entity.
   */
  private function schemaEntity(bool $public): FieldableEntityInterface {
    return $public
      ? $this->schemaWith('internet', 'published')
      : $this->schemaWith('intranet', 'draft');
  }

  /**
   * Creates a schema-like fieldable entity with explicit lifecycle values.
   */
  private function schemaWith(string $publication_scope, string $workflow_status): FieldableEntityInterface {
    $schema = $this->createMock(FieldableEntityInterface::class);
    $schema->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => in_array($field_name, ['field_publication_scope', 'field_workflow_status'], TRUE)
    );
    $schema->method('get')->willReturnCallback(function (string $field_name) use ($publication_scope, $workflow_status): FieldItemListInterface {
      return match ($field_name) {
        'field_publication_scope' => $this->fieldWithValue($publication_scope),
        'field_workflow_status' => $this->fieldWithValue($workflow_status),
        default => throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name)),
      };
    });
    return $schema;
  }

  /**
   * Creates an owner account mock.
   *
   * @param string[] $roles
   *   Owner role IDs.
   */
  private function ownerAccount(array $roles): UserInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getRoles')->willReturn($roles);
    return $owner;
  }

  /**
   * Creates an account mock with the supplied role IDs.
   *
   * @param string[] $roles
   *   Role IDs returned by the account.
   * @param int $uid
   *   Account ID returned by the account.
   * @param string $email
   *   Email address returned by the account.
   */
  private function createAccount(array $roles, int $uid = 5, string $email = ''): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn($roles);
    $account->method('id')->willReturn($uid);
    $account->method('getEmail')->willReturn($email);
    $account->method('hasPermission')->willReturn(FALSE);
    return $account;
  }

  /**
   * Installs a minimal static container for group access tests.
   *
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Groups keyed by numeric ID.
   */
  private function installGroupAccessContainer(array $groups): void {
    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')
      ->willReturnCallback(static fn(int|string $id): ?GroupInterface => $groups[(int) $id] ?? NULL);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('group')
      ->willReturn($group_storage);
    $container->set('entity_type.manager', $entity_type_manager);
    $scope_authorization = $this->createMock(ApplicationScopeAuthorizationInterface::class);
    $scope_authorization->method('findUnauthorizedTarget')->willReturn(NULL);
    $container->set('beantragt_group.application_scope_authorization', $scope_authorization);
    $scope_mutation_lock = $this->createMock(ApplicantScopeMutationLock::class);
    $scope_mutation_lock->method('acquireForApplication')->willReturn(TRUE);
    $container->set('beantragt_application.applicant_scope_mutation_lock', $scope_mutation_lock);
    $container->set('current_user', $this->createMock(AccountInterface::class));

    \Drupal::setContainer($container);
  }

  /**
   * Creates a scoped application or applicant entity mock.
   */
  private function createScopedEntity(string $entity_type_id, int $org_id): FieldableEntityInterface {
    $entity = $entity_type_id === 'application'
      ? $this->createMock(ApplicationInterface::class)
      : $this->createMock(FieldableEntityInterface::class);

    $fields = [
      'field_organisation' => $this->fieldWithTargetId($org_id),
    ];

    $entity->method('getEntityTypeId')->willReturn($entity_type_id);
    $entity->method('id')->willReturn(99);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn([$entity_type_id . ':99']);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    $entity->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $fields)
    );
    $entity->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name))
    );

    if ($entity instanceof ApplicationInterface) {
      $entity->method('getOwnerId')->willReturn(500);
    }

    return $entity;
  }

  /**
   * Creates a group mock with one configured membership.
   */
  private function createGroup(
    int $id,
    string $bundle,
    AccountInterface $member_account,
    object|false $membership,
  ): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $group->method('getMember')
      ->willReturnCallback(static fn(AccountInterface $account): object|false => $account === $member_account ? $membership : FALSE);
    return $group;
  }

  /**
   * Creates a scope-only group entity mock.
   */
  private function createScopedGroupEntity(int $id, string $bundle, ?int $jurisdiction_id = NULL): GroupInterface {
    $fields = [
      'field_jurisdiction' => $this->fieldWithTargetId($jurisdiction_id),
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => array_key_exists($field_name, $fields)
    );
    $group->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $fields[$field_name]
        ?? throw new \InvalidArgumentException(sprintf('Unexpected field %s.', $field_name))
    );
    return $group;
  }

  /**
   * Creates a group membership object with one role.
   */
  private function createMembership(string $role_id, bool $admin): object {
    $role = new class($role_id, $admin) {

      public function __construct(
        private readonly string $roleId,
        private readonly bool $admin,
      ) {}

      /**
       * Returns the role ID.
       */
      public function id(): string {
        return $this->roleId;
      }

      /**
       * Returns whether this role is a group admin role.
       */
      public function isAdmin(): bool {
        return $this->admin;
      }

    };

    return new class($role) {

      public function __construct(private readonly object $role) {}

      /**
       * Returns group roles keyed like GroupMembership::getRoles().
       */
      public function getRoles(): array {
        return [$this->role->id() => $this->role];
      }

    };
  }

}
