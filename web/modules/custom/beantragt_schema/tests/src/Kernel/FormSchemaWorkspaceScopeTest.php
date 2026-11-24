<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_group\Controller\AdminWorkspaceController;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests account-bound form schema workspace validation.
 */
#[CoversClass(FormSchemaWorkspaceScope::class)]
#[Group('beantragt_schema')]
final class FormSchemaWorkspaceScopeTest extends FormWorkspaceKernelTestBase {

  /**
   * Department administrators can create schemas in their own workspace.
   */
  public function testDepartmentAdminOwnWorkspaceHasNoViolations(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('own');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($account);

    self::assertCount(0, $this->createSchema($account, $jurisdiction, $organisation)->validate());
  }

  /**
   * A jurisdiction outside the account's memberships is rejected.
   */
  public function testForeignJurisdictionProducesViolation(): void {
    [$own_jurisdiction, $own_organisation] = $this->createWorkspacePair('own');
    [$foreign_jurisdiction] = $this->createWorkspacePair('foreign');
    $account = $this->createDepartmentAdmin();
    $own_jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $own_organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($account);

    $this->assertCreateDenial($this->createSchema($account, $foreign_jurisdiction), 'errors.workspace.notFound');
  }

  /**
   * Includes the review flag only in the caller's visible forms.
   */
  public function testAiReviewStatusRespectsWorkspaceReadAccess(): void {
    $this->enableJsonApiWrites();
    [$own_jur, $own_org] = $this->createWorkspacePair('ai-own');
    [$other_jur, $other_org] = $this->createWorkspacePair('ai-other');
    $admin = $this->createUser(['create form_schema entities', 'view unpublished form_schema entities']);
    $admin->addRole('department_admin');
    $admin->save();
    $other_admin = $this->createDepartmentAdmin();
    $own_org->addMember($admin, ['group_roles' => ['org-admin']]);
    $other_org->addMember($other_admin, ['group_roles' => ['org-admin']]);
    $forms = [];
    foreach ([[$admin, $own_jur, $own_org], [$other_admin, $other_jur, $other_org]] as [$actor, $jur, $org]) {
      $this->setCurrentUser($actor);
      $form = $this->createSchema($actor, $jur, $org);
      $form->set('field_schema', '{"source":{"type":"text"},"sections":[]}')
        ->set('field_source_filename', 'upload.txt')
        ->save();
      $forms[] = $form->uuid();
    }
    [$own, $other] = $forms;
    $base = '/jsonapi/form_schema/form_schema';
    $sparse = '?fields[form_schema--form_schema]=field_ai_review_pending';
    $response = $this->assertJsonApiResponse($admin, 'GET', $base . $sparse, [], 200);
    self::assertSame([$own], array_column($response['data'], 'id'));
    self::assertSame(['field_ai_review_pending' => TRUE], $response['data'][0]['attributes']);
    $this->assertJsonApiResponse($admin, 'GET', $base . '/' . $other . $sparse, [], 403);
  }

  /**
   * An ordinary group membership does not grant schema write authority.
   */
  public function testOrdinaryMemberCannotCreateSchema(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('member');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-member']]);
    $organisation->addMember($account, ['group_roles' => ['org-member']]);
    $this->setCurrentUser($account);

    $this->assertCreateDenial($this->createSchema($account, $jurisdiction, $organisation), 'errors.schema.writeForbidden');
  }

  /**
   * Manual drafts and catalogue imports share this user-bound JSON:API POST.
   */
  #[TestWith([FALSE], 'personal basic auth')]
  #[TestWith([TRUE], 'personal session cookie and CSRF')]
  public function testDraftPostRequiresManagementOfTheTargetWorkspace(bool $use_cookie): void {
    $this->enableJsonApiWrites();
    [$managed_jur, $managed_org] = $this->createWorkspacePair('managed');
    [$visible_jur, $visible_org] = $this->createWorkspacePair('visible');
    [$foreign_jur, $foreign_org] = $this->createWorkspacePair('outside');
    [$other_jur] = $this->createWorkspacePair('other-managed');
    $admin = $this->createUser(['create form_schema entities', 'view unpublished form_schema entities']);
    $admin->addRole('department_admin');
    $admin->save();
    $managed_org->addMember($admin, ['group_roles' => ['org-admin']]);
    $other_jur->addMember($admin, ['group_roles' => ['jur-admin']]);
    $visible_jur->addMember($admin);
    $visible_org->addMember($admin);

    $session = NULL;
    if ($use_cookie) {
      $session = new Session(new MockArraySessionStorage());
      $session->set('uid', $admin->id());
    }
    $data = [
      'attributes' => [
        'label' => 'Workspace draft',
        'field_schema' => '{"sections":[]}',
        'status' => FALSE,
        'field_workflow_status' => 'draft',
      ],
      'relationships' => $this->workspaceRelationships($managed_jur, $managed_org),
    ];
    if ($session) {
      $csrf = $this->assertCreateResponse($admin, 'form_schema', $data, 403, session: $session, send_csrf: FALSE);
      self::assertStringContainsString('X-CSRF-Token request header is missing', $csrf['errors'][0]['detail']);
    }
    foreach ([
      [$visible_jur, $visible_org, 403, 'errors.schema.writeForbidden'],
      [$managed_jur, NULL, 403, 'errors.schema.writeForbidden'],
      [$foreign_jur, $foreign_org, 404, 'errors.workspace.notFound'],
    ] as [$jurisdiction, $organisation, $status, $code]) {
      $denied = $data;
      $denied['relationships'] = $this->workspaceRelationships($jurisdiction, $organisation);
      $error = $this->assertCreateResponse($admin, 'form_schema', $denied, $status, $code, $session);
      if ($status === 404) {
        $error_json = json_encode($error, JSON_THROW_ON_ERROR);
        foreach ([$foreign_jur, $foreign_org] as $foreign) {
          self::assertStringNotContainsString($foreign->label(), $error_json);
          self::assertStringNotContainsString($foreign->uuid(), $error_json);
        }
      }
    }
    $missing = $data;
    $missing['relationships']['field_organisation']['data']['id'] = $this->container->get('uuid')->generate();
    $this->assertCreateResponse($admin, 'form_schema', $missing, 404, 'errors.workspace.notFound', $session);
    $mismatched = $data;
    $mismatched['relationships'] = $this->workspaceRelationships($other_jur, $managed_org);
    $this->assertCreateResponse($admin, 'form_schema', $mismatched, 422, session: $session);
    $forged_author = $data;
    $forged_author['relationships']['uid'] = ['data' => ['type' => 'user--user', 'id' => $this->createUser()->uuid()]];
    $this->assertCreateResponse($admin, 'form_schema', $forged_author, 422, session: $session);
    $bypass = $this->createUser(['bypass beantragt group scope', 'create form_schema entities', 'access user profiles']);
    $bypass_error = $this->assertCreateResponse($bypass, 'form_schema', $forged_author, 422);
    self::assertStringContainsString('author', $bypass_error['errors'][0]['detail']);
    $invalid = $data;
    $invalid['attributes']['label'] = '';
    $this->assertCreateResponse($admin, 'form_schema', $invalid, 422, session: $session);

    $created = $this->assertCreateResponse($admin, 'form_schema', $data, 201, session: $session);
    $forms = $this->entityTypeManager()->getStorage('form_schema')->loadByProperties(['uuid' => $created['data']['id']]);
    $form = reset($forms);
    self::assertInstanceOf(FormSchemaInterface::class, $form);
    self::assertSame((int) $admin->id(), (int) $form->getOwnerId());
    self::assertFalse($form->isPublished());
    self::assertSame('draft', $form->getSchemaStatus());
    self::assertSame((int) $managed_org->id(), (int) $form->get('field_organisation')->target_id);

    $managed_org->removeMember($admin);
    $this->assertCreateResponse($admin, 'form_schema', $data, 404, 'errors.workspace.notFound', $session);
  }

  /**
   * A validated draft cannot be saved after losing the global create right.
   */
  public function testSaveRechecksGlobalPermission(): void {
    [$jur, $org] = $this->createWorkspacePair('revoked-create');
    $admin = $this->createDepartmentAdmin();
    $org->addMember($admin, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($admin);
    $schema = $this->createSchema($admin, $jur, $org);
    self::assertCount(0, $schema->validate());

    $this->revokeGlobalPermission($admin, 'create form_schema entities');
    try {
      $schema->save();
      self::fail('A workspace manager still needs the global create right.');
    }
    catch (EntityStorageException) {
      self::assertSame(0, $this->rowCount('form_schema'));
      self::assertSame(0, $this->rowCount('form_schema_revision'));
    }
  }

  /**
   * Jurisdiction administrators may manage child organisations.
   */
  public function testJurisdictionAdminCanCreateForChildOrganisation(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('parent-admin');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);

    self::assertCount(0, $this->createSchema($account, $jurisdiction, $organisation)->validate());
  }

  /**
   * Parent jurisdiction administrators may manage descendant workspaces.
   */
  public function testParentJurisdictionAdminCanCreateForDescendant(): void {
    $parent = $this->createGroup([
      'type' => 'jur',
      'label' => 'US-SMOKE Parent jurisdiction',
      'field_slug' => 'us-smoke-parent-jurisdiction',
    ]);
    $child = $this->createGroup([
      'type' => 'jur',
      'label' => 'US-SMOKE Child jurisdiction',
      'field_slug' => 'us-smoke-child-jurisdiction',
      'field_parent_jurisdiction' => ['target_id' => $parent->id()],
    ]);
    $account = $this->createDepartmentAdmin();
    $parent->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);

    $options = $this->container
      ->get('beantragt_schema.workspace_scope')
      ->workspaceOptions($account);

    self::assertArrayHasKey((int) $parent->id(), $options['jurisdictions']);
    self::assertArrayHasKey((int) $child->id(), $options['jurisdictions']);
    self::assertNotContains((int) $child->id(), $options['organisation_required_jurisdictions']);
    self::assertCount(0, $this->createSchema($account, $child)->validate());
  }

  /**
   * Every non-exempt form schema requires an explicit jurisdiction.
   */
  public function testMissingJurisdictionProducesViolation(): void {
    $account = $this->createDepartmentAdmin();
    $this->setCurrentUser($account);

    self::assertSame(
      ['A jurisdiction workspace is required.'],
      $this->violationMessages($this->createSchema($account)),
    );
  }

  /**
   * An organisation must belong to the selected jurisdiction.
   */
  public function testOrganisationOutsideJurisdictionProducesViolation(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('own');
    [, $foreign_organisation] = $this->createWorkspacePair('foreign');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $foreign_organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($account);

    self::assertSame(
      ['The selected organisation must belong to the selected jurisdiction.'],
      $this->violationMessages($this->createSchema($account, $jurisdiction, $foreign_organisation)),
    );
  }

  /**
   * Service and permission bypass accounts remain exempt from scope checks.
   */
  public function testServiceAccountAndPermissionBypassRemainExempt(): void {
    $service_account = $this->createUser();
    $service_account->addRole('service_account');
    $service_account->save();
    $this->setCurrentUser($service_account);
    self::assertCount(0, $this->createSchema($service_account)->validate());

    $bypass_account = $this->createUser(['bypass beantragt group scope']);
    $this->setCurrentUser($bypass_account);
    self::assertCount(0, $this->createSchema($bypass_account)->validate());
  }

  /**
   * Exempt accounts still cannot store contradictory workspace references.
   */
  public function testExemptAccountRejectsMismatchedWorkspaces(): void {
    [$jurisdiction] = $this->createWorkspacePair('exempt-own');
    [, $foreign_organisation] = $this->createWorkspacePair('exempt-foreign');
    $account = $this->createUser(['bypass beantragt group scope']);
    $this->setCurrentUser($account);

    self::assertSame(
      ['The selected organisation must belong to the selected jurisdiction.'],
      $this->violationMessages($this->createSchema($account, $jurisdiction, $foreign_organisation)),
    );
  }

  /**
   * New schemas cannot target an inactive workspace.
   */
  public function testInactiveJurisdictionProducesViolation(): void {
    [$jurisdiction] = $this->createWorkspacePair('inactive');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $jurisdiction->setUnpublished();
    $jurisdiction->save();
    $this->setCurrentUser($account);

    $this->assertCreateDenial($this->createSchema($account, $jurisdiction), 'errors.workspace.notFound');
  }

  /**
   * Updating a schema cannot move it into a foreign workspace.
   */
  public function testUpdateMoveToForeignWorkspaceIsRejected(): void {
    [$own_jurisdiction, $own_organisation] = $this->createWorkspacePair('own');
    [$foreign_jurisdiction] = $this->createWorkspacePair('foreign');
    $account = $this->createDepartmentAdmin();
    $own_jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $own_organisation->addMember($account, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($account);

    $schema = $this->createSchema($account, $own_jurisdiction, $own_organisation);
    $schema->save();
    $schema->set('field_jurisdiction', ['target_id' => $foreign_jurisdiction->id()]);
    $schema->set('field_organisation', NULL);

    self::assertSame(
      ['You are not authorized to manage one or more selected workspaces.'],
      $this->violationMessages($schema),
    );

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('You are not authorized to manage one or more selected workspaces.');
    $schema->save();
  }

  /**
   * Assigning an organisation keeps the form version but adds a revision.
   *
   * Transfer rules cover a form version with identical schema bytes, so the
   * municipality's rules stay valid when its form moves into an organisation.
   */
  public function testOrganisationAssignmentKeepsFormVersion(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('assign');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);

    $schema = $this->createSchema($account, $jurisdiction);
    $schema->save();
    $version = $schema->getVersion();
    $revision = (int) $schema->getRevisionId();

    $schema->set('field_organisation', ['target_id' => $organisation->id()]);
    $schema->set('field_group', ['target_id' => $organisation->id()]);
    self::assertSame([], $this->violationMessages($schema));
    $schema->save();

    $stored = $this->entityTypeManager()->getStorage('form_schema')->loadUnchanged($schema->id());
    assert($stored instanceof FormSchemaInterface);
    self::assertSame($version, $stored->getVersion());
    self::assertGreaterThan($revision, (int) $stored->getRevisionId());
    self::assertSame((int) $organisation->id(), (int) $stored->get('field_organisation')->target_id);

    // A content change still advances the version.
    $stored->set('field_schema', json_encode(['title' => 'Changed', 'sections' => []], JSON_THROW_ON_ERROR));
    $stored->save();
    self::assertSame($version + 1, $stored->getVersion());
  }

  /**
   * Cases without an organisation block the first assignment of their form.
   *
   * They would copy the form's organisation on their next save and become
   * readable for its members without anyone deciding so.
   */
  public function testCasesWithoutOrganisationBlockAssignment(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('cases');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);

    $schema = $this->createSchema($account, $jurisdiction);
    $schema->save();
    $this->container->get('database')->insert('application')->fields([
      'uuid' => $this->container->get('uuid')->generate(),
      'uid' => $account->id(),
      'status' => 1,
      'field_schema' => $schema->id(),
    ])->execute();

    $schema->set('field_organisation', ['target_id' => $organisation->id()]);
    $schema->set('field_group', ['target_id' => $organisation->id()]);
    self::assertSame([FormSchemaWorkspaceScope::UNASSIGNED_APPLICATIONS_MESSAGE], $this->violationMessages($schema));

    $this->container->get('database')->update('application')
      ->fields(['field_organisation' => $organisation->id()])
      ->condition('field_schema', $schema->id())
      ->execute();
    self::assertSame([], $this->violationMessages($schema));
  }

  /**
   * Synchronization saves bypass interactive scope authorization.
   */
  public function testSyncingSaveSkipsScopeAuthorization(): void {
    [$own_jurisdiction, $own_organisation] = $this->createWorkspacePair('sync-own');
    [$foreign_jurisdiction, $foreign_organisation] = $this->createWorkspacePair('sync-foreign');
    $owner = $this->createDepartmentAdmin();
    $own_jurisdiction->addMember($owner, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($owner);
    $schema = $this->createSchema($owner, $own_jurisdiction, $own_organisation);
    $schema->save();

    $unauthorized_account = $this->createDepartmentAdmin();
    $this->setCurrentUser($unauthorized_account);
    $schema->setSyncing(TRUE);
    $schema->set('field_jurisdiction', ['target_id' => $foreign_jurisdiction->id()]);
    $schema->set('field_organisation', ['target_id' => $foreign_organisation->id()]);

    self::assertSame([], $this->violationMessages($schema));
    $schema->save();
    self::assertSame((int) $foreign_jurisdiction->id(), (int) $schema->get('field_jurisdiction')->target_id);
    self::assertSame((int) $foreign_organisation->id(), (int) $schema->get('field_organisation')->target_id);
  }

  /**
   * Unchanged scope does not block unrelated saves by a system actor.
   */
  public function testUnchangedScopeSaveSkipsActorAuthorization(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('unchanged');
    $owner = $this->createDepartmentAdmin();
    $jurisdiction->addMember($owner, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($owner);
    $schema = $this->createSchema($owner, $jurisdiction, $organisation);
    $schema->save();

    $unauthorized_account = $this->createDepartmentAdmin();
    $this->setCurrentUser($unauthorized_account);
    $schema->set('label', 'US-SMOKE Unchanged scope resave');

    self::assertSame([], $this->violationMessages($schema));
    $schema->save();
    self::assertSame('US-SMOKE Unchanged scope resave', $schema->label());
  }

  /**
   * A contradictory legacy group cannot bypass explicit workspace scope.
   */
  public function testForeignLegacyGroupProducesViolation(): void {
    [$jurisdiction, $organisation] = $this->createWorkspacePair('legacy-own');
    [, $foreign_organisation] = $this->createWorkspacePair('legacy-foreign');
    $account = $this->createDepartmentAdmin();
    $jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);
    $schema = $this->createSchema($account, $jurisdiction, $organisation);
    $schema->set('field_group', ['target_id' => $foreign_organisation->id()]);

    $this->assertCreateDenial($schema, 'errors.workspace.notFound');
  }

  /**
   * An unchanged inactive legacy target may be normalized during a valid move.
   */
  public function testChangedExplicitScopeRepairsUnchangedInactiveLegacyTarget(): void {
    [$old_jurisdiction, $old_organisation] = $this->createWorkspacePair('repair-old');
    [$new_jurisdiction, $new_organisation] = $this->createWorkspacePair('repair-new');
    $account = $this->createDepartmentAdmin();
    $old_jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $new_jurisdiction->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($account);
    $schema = $this->createSchema($account, $old_jurisdiction, $old_organisation);
    $schema->save();
    self::assertSame((int) $old_organisation->id(), (int) $schema->get('field_group')->target_id);

    $old_organisation->setUnpublished();
    $old_organisation->save();
    $schema->set('field_jurisdiction', ['target_id' => $new_jurisdiction->id()]);
    $schema->set('field_organisation', ['target_id' => $new_organisation->id()]);

    self::assertSame([], $this->violationMessages($schema));
    $schema->save();
    self::assertSame((int) $new_organisation->id(), (int) $schema->get('field_group')->target_id);
  }

  /**
   * Organisation admins receive a parent that requires an organisation.
   */
  public function testOrganisationAdminWorkspaceOptionsRequireOrganisation(): void {
    // The production install hook attaches the slug fields to both bundles.
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->entityTypeManager());
    [$jurisdiction, $organisation] = $this->createWorkspacePair('org-options');
    $account = $this->createDepartmentAdmin();
    $organisation->addMember($account, ['group_roles' => ['org-admin']]);

    $options = $this->container
      ->get('beantragt_schema.workspace_scope')
      ->workspaceOptions($account);

    self::assertSame([(int) $jurisdiction->id()], array_keys($options['jurisdictions']));
    self::assertSame([(int) $organisation->id()], array_keys($options['organisations']));
    self::assertSame([(int) $jurisdiction->id()], $options['organisation_required_jurisdictions']);
    self::assertTrue($options['scope_required']);

    // The catalogue gives the compiler one decision per visible workspace.
    [, $readable] = $this->createWorkspacePair('read-only');
    $readable->addMember($account);
    $this->setCurrentUser($account);
    $response = AdminWorkspaceController::create($this->container)->listWorkspaces();
    $payload = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $capabilities = array_column($payload['workspaces'], 'canCreateFormSchema', 'id');
    self::assertArrayHasKey($organisation->uuid(), $capabilities, (string) $response->getContent());
    self::assertArrayHasKey($readable->uuid(), $capabilities, (string) $response->getContent());
    self::assertTrue($capabilities[$organisation->uuid()]);
    self::assertFalse($capabilities[$readable->uuid()]);
    self::assertArrayNotHasKey($jurisdiction->uuid(), $capabilities);
  }

  /**
   * Creates a department administrator with schema creation permission.
   */
  private function createDepartmentAdmin(): UserInterface {
    $account = $this->createUser(['create form_schema entities']);
    $account->addRole('department_admin');
    $account->save();
    return $account;
  }

  /**
   * Requires the machine-readable reason on the entity-level violation.
   */
  private function assertCreateDenial(FormSchemaInterface $schema, string $code): void {
    $violations = $schema->validate();
    self::assertCount(1, $violations);
    self::assertSame('', $violations[0]->getPropertyPath());
    self::assertSame($code, $violations[0]->getCode());
  }

  /**
   * Returns rendered entity-level violation messages.
   *
   * @return string[]
   *   Violation messages.
   */
  private function violationMessages(FormSchemaInterface $schema): array {
    $messages = [];
    foreach ($schema->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
      self::assertSame('', $violation->getPropertyPath());
    }
    return $messages;
  }

}
