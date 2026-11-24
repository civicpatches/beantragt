<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_form_schema_group\Kernel;

use Drupal\beantragt_form_schema_group\Entity\FormSchemaGroupInterface;
use Drupal\beantragt_form_schema_group\FormSchemaGroupAccessControlHandler;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\beantragt_schema\Kernel\FormWorkspaceKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Checks who can create form groups and which forms they can include.
 */
#[CoversClass(FormSchemaGroupAccessControlHandler::class)]
#[Group('beantragt_form_schema_group')]
final class FormSchemaGroupWorkspaceScopeTest extends FormWorkspaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['beantragt_form_schema_group'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('form_schema_group');
    $this->enableJsonApiWrites();
  }

  /**
   * Checks the same personal session across managed, visible and foreign areas.
   */
  public function testCreateInSelectedWorkspace(): void {
    [$jur, $org] = $this->createWorkspacePair('managed');
    $visible_org = $this->createGroup([
      'type' => 'org',
      'label' => 'Visible sibling organisation',
      'field_slug' => 'visible-sibling',
      'field_jurisdiction' => $jur->id(),
    ]);
    [$hidden_jur, $hidden_org] = $this->createWorkspacePair('hidden');
    $seeder = $this->createUser(['bypass beantragt group scope']);
    $this->setCurrentUser($seeder);
    $first = $this->createSchema($seeder, $jur, $org);
    $second = $this->createSchema($seeder, $jur, $org);
    $visible = $this->createSchema($seeder, $jur, $visible_org);
    $hidden = $this->createSchema($seeder, $hidden_jur, $hidden_org);
    foreach ([$first, $second, $visible, $hidden] as $form) {
      $form->save();
    }
    $actor = $this->createGroupAuthor();
    $org->addMember($actor, ['group_roles' => ['org-admin']]);
    $visible_org->addMember($actor);
    $session = new Session(new MockArraySessionStorage());
    $session->set('uid', $actor->id());
    $own = $this->groupPayload($jur, $org, $second->uuid(), $first->uuid());
    $this->assertCreateResponse($actor, 'form_schema_group', $own, 403, session: $session, send_csrf: FALSE);

    $forged_author = $own;
    $forged_author['relationships']['uid'] = ['data' => ['type' => 'user--user', 'id' => $seeder->uuid()]];
    $bypass = $this->createUser([
      'bypass beantragt group scope',
      'create form_schema_group entities',
      'view unpublished form_schema entities',
      'access user profiles',
    ]);
    $bypass_error = $this->assertCreateResponse($bypass, 'form_schema_group', $forged_author, 422);
    self::assertStringContainsString('author', $bypass_error['errors'][0]['detail']);
    $bypass_without_view = $this->createUser(['bypass beantragt group scope', 'create form_schema_group entities']);
    $this->assertCreateResponse($bypass_without_view, 'form_schema_group', $own, 404, 'errors.schema.notFound');
    $wrong_type = $own;
    $wrong_type['relationships']['field_schemas']['data'][1]['type'] = 'group--org';
    $missing_id = $this->container->get('uuid')->generate();
    foreach ([
      [$forged_author, 422, NULL],
      [$wrong_type, 422, NULL],
      [$this->groupPayload($jur, $org, $first->uuid(), 'virtual'), 422, NULL],
      [$this->groupPayload($jur, $visible_org, $visible->uuid()), 403, 'errors.schema.writeForbidden'],
      [$this->groupPayload($jur, NULL, $first->uuid()), 403, 'errors.schema.writeForbidden'],
      [$this->groupPayload($hidden_jur, $hidden_org, $hidden->uuid()), 404, 'errors.workspace.notFound'],
      [$this->groupPayload($jur, $org, $first->uuid(), $hidden->uuid()), 404, 'errors.schema.notFound'],
      [$this->groupPayload($jur, $org, $first->uuid(), $missing_id), 404, 'errors.schema.notFound'],
      [$this->groupPayload($jur, $org, $first->uuid(), $visible->uuid()), 422, NULL],
      [$this->groupPayload($jur, $org), 422, NULL],
    ] as [$data, $status, $code]) {
      $result = $this->assertCreateResponse($actor, 'form_schema_group', $data, $status, $code, $session);
      if ($status === 404) {
        $body = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([$hidden, $hidden_jur, $hidden_org] as $foreign) {
          self::assertStringNotContainsString($foreign->label(), $body);
          self::assertStringNotContainsString($foreign->uuid(), $body);
        }
      }
    }

    $result = $this->assertCreateResponse($actor, 'form_schema_group', $own, 201, session: $session);
    $groups = $this->entityTypeManager()->getStorage('form_schema_group')->loadByProperties(['uuid' => $result['data']['id']]);
    $group = reset($groups);
    self::assertInstanceOf(FormSchemaGroupInterface::class, $group);
    self::assertSame((int) $actor->id(), (int) $group->getOwnerId());
    self::assertSame('draft', $group->getWorkflowStatus());
    self::assertSame([(int) $second->id(), (int) $first->id()], $group->getFormSchemaIds());

    $org->removeMember($actor);
    $this->assertCreateResponse($actor, 'form_schema_group', $own, 404, 'errors.workspace.notFound', $session);
  }

  /**
   * An ancestor grants management, while each form still needs view access.
   */
  public function testInheritedManagementAndIndividualFormAccess(): void {
    [$parent, $org] = $this->createWorkspacePair('parent');
    $child = $this->createGroup([
      'type' => 'jur',
      'label' => 'Child municipality',
      'field_slug' => 'child-municipality',
      'field_parent_jurisdiction' => $parent->id(),
    ]);
    $seeder = $this->createUser(['bypass beantragt group scope']);
    $this->setCurrentUser($seeder);
    $form = $this->createSchema($seeder, $child);
    $form->save();
    $data = $this->groupPayload($child, NULL, $form->uuid());
    $organisation_form = $this->createSchema($seeder, $parent, $org);
    $organisation_form->save();

    $author = $this->createGroupAuthor();
    $parent->addMember($author, ['group_roles' => ['jur-admin']]);
    $child->addMember($author, ['group_roles' => ['jur-member']]);
    $this->assertCreateResponse($author, 'form_schema_group', $data, 201);

    // A direct municipality admin manages the organisation even with only its
    // automatic member role.
    $org->addMember($author);
    $organisation_data = $this->groupPayload($parent, $org, $organisation_form->uuid());
    $this->assertCreateResponse($author, 'form_schema_group', $organisation_data, 201);

    // The second actor manages the same target but cannot read draft forms.
    $without_view = $this->createUser(['create form_schema_group entities']);
    $child->addMember($without_view, ['group_roles' => ['jur-admin']]);
    $this->assertCreateResponse($without_view, 'form_schema_group', $data, 404, 'errors.schema.notFound');
  }

  /**
   * Storage rechecks global and workspace rights after validation.
   */
  #[TestWith([TRUE], 'revoked global create permission')]
  #[TestWith([FALSE], 'revoked group membership')]
  public function testSaveRechecksGroupAfterPermissionRevocation(bool $revoke_global): void {
    [$jur, $org] = $this->createWorkspacePair('revoked');
    $actor = $this->createGroupAuthor();
    $org->addMember($actor, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($actor);
    $form = $this->createSchema($actor, $jur, $org);
    $form->save();
    $group = $this->entityTypeManager()->getStorage('form_schema_group')->create([
      'label' => 'Group draft',
      'field_jurisdiction' => $jur->id(),
      'field_organisation' => $org->id(),
      'field_schemas' => [$form->id()],
    ]);
    self::assertCount(0, $group->validate());
    if ($revoke_global) {
      $this->revokeGlobalPermission($actor, 'create form_schema_group entities');
    }
    else {
      $org->removeMember($actor);
    }
    try {
      $group->save();
      self::fail('Saving must recheck the current create and workspace rights.');
    }
    catch (EntityStorageException) {
      self::assertSame(0, $this->rowCount('form_schema_group'));
    }
  }

  /**
   * Service and CLI callers can still combine and move matching forms.
   */
  public function testTechnicalCallersCanWriteGroup(): void {
    [$jur, $org] = $this->createWorkspacePair('service');
    [$destination_jur, $destination_org] = $this->createWorkspacePair('seed');
    $service = $this->createUser(['create form_schema_group entities', 'view unpublished form_schema entities']);
    $service->addRole('service_account');
    $service->save();
    $this->setCurrentUser($service);
    $form = $this->createSchema($service, $jur, $org);
    $form->save();
    $destination = $this->createSchema($service, $destination_jur, $destination_org);
    $destination->save();
    $response = $this->assertCreateResponse($service, 'form_schema_group', $this->groupPayload($jur, $org, $form->uuid()), 201);
    $storage = $this->entityTypeManager()->getStorage('form_schema_group');
    $groups = $storage->loadByProperties(['uuid' => $response['data']['id']]);
    $group = reset($groups);
    self::assertInstanceOf(FormSchemaGroupInterface::class, $group);
    foreach ([
      [$service, $destination_jur, $destination_org, $destination],
      [new AnonymousUserSession(), $jur, $org, $form],
    ] as [$actor, $target_jur, $target_org, $reference]) {
      $this->setCurrentUser($actor);
      $group->set('field_jurisdiction', $target_jur->id());
      $group->set('field_organisation', $target_org->id());
      $group->set('field_schemas', [$reference->id()]);
      $group->save();
      $group = $storage->loadUnchanged($group->id());
      self::assertSame((int) $target_jur->id(), (int) $group->get('field_jurisdiction')->target_id);
      self::assertSame((int) $target_org->id(), (int) $group->get('field_group')->target_id);
      self::assertSame([(int) $reference->id()], $group->getFormSchemaIds());
    }
  }

  /**
   * Group edits require management of the destination, including raw saves.
   */
  public function testWorkspaceMoveChecksDestination(): void {
    [$jur, $org] = $this->createWorkspacePair('source');
    [$other_jur, $other_org] = $this->createWorkspacePair('destination');
    [$hidden_jur] = $this->createWorkspacePair('foreign');
    $seeder = $this->createUser(['bypass beantragt group scope', 'publish form_schema entities']);
    $this->setCurrentUser($seeder);
    $original = $this->createSchema($seeder, $jur, $org);
    $original->save();
    $published = $this->createSchema($seeder, $other_jur);
    $published->set('field_schema', json_encode([
      'title' => 'Sample form',
      'sections' => [[
        'id' => 'section',
        'title' => 'Section',
        'fields' => [['id' => 'item', 'type' => 'text', 'label' => 'Item', 'privacy' => ['kind' => 'not_personal']]],
      ],
      ],
    ], JSON_THROW_ON_ERROR));
    $published->set('field_workflow_status', 'published')->setPublished()->save();
    $destination = $this->createSchema($seeder, $other_jur, $other_org);
    $destination->save();

    $actor = $this->createGroupAuthor();
    $org->addMember($actor, ['group_roles' => ['org-admin']]);
    $other_jur->addMember($actor);
    $other_org->addMember($actor, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($actor);
    $storage = $this->entityTypeManager()->getStorage('form_schema_group');
    $group = $storage->create([
      'label' => 'Source group',
      'field_jurisdiction' => $jur->id(),
      'field_organisation' => $org->id(),
      'field_schemas' => [$original->id()],
    ]);
    $group->save();
    $before = $storage->loadUnchanged($group->id())->toArray();
    self::assertTrue($group->access('update', $actor));
    self::assertTrue($published->access('view', $actor));

    foreach ([
      [$other_jur, $published, 403, 'errors.schema.writeForbidden'],
      [$hidden_jur, $original, 404, 'errors.workspace.notFound'],
    ] as [$target, $reference, $status, $code]) {
      $data = $this->groupPayload($target, NULL, $reference->uuid());
      $data['relationships']['field_organisation'] = ['data' => NULL];
      $data['relationships']['field_group'] = ['data' => NULL];
      $this->assertGroupResponse($actor, $data, $status, $code, update_uuid: $group->uuid());
      self::assertSame($before, $storage->loadUnchanged($group->id())->toArray());
    }

    $this->setCurrentUser($actor);
    $group = $storage->loadUnchanged($group->id());
    $group->set('field_jurisdiction', $other_jur->id());
    $group->set('field_organisation', NULL);
    $group->set('field_group', NULL);
    $group->set('field_schemas', [$published->id()]);
    try {
      $group->save();
      self::fail('Storage must enforce management of the destination municipality.');
    }
    catch (EntityStorageException $exception) {
      self::assertStringContainsString('not authorized', $exception->getMessage());
      self::assertSame($before, $storage->loadUnchanged($group->id())->toArray());
    }

    $this->revokeGlobalPermission($actor, 'create form_schema_group entities');
    $move = $this->groupPayload($other_jur, $other_org, $destination->uuid());
    $this->assertGroupResponse($actor, $move, 200, update_uuid: $group->uuid());
    $saved = $storage->loadUnchanged($group->id());
    self::assertSame((int) $other_org->id(), (int) $saved->get('field_organisation')->target_id);
    self::assertSame((int) $other_org->id(), (int) $saved->get('field_group')->target_id);
    self::assertSame([(int) $destination->id()], $saved->getFormSchemaIds());

    $other_jur->removeMember($actor);
    $other_jur->addMember($actor, ['group_roles' => ['jur-admin']]);
    $municipal = $this->groupPayload($other_jur, NULL, $published->uuid());
    $municipal['relationships']['field_organisation'] = ['data' => NULL];
    $this->assertGroupResponse($actor, $municipal, 200, update_uuid: $group->uuid());
    $saved = $storage->loadUnchanged($group->id());
    self::assertTrue($saved->get('field_organisation')->isEmpty());
    self::assertSame((int) $other_jur->id(), (int) $saved->get('field_group')->target_id);
    self::assertSame([(int) $published->id()], $saved->getFormSchemaIds());
  }

  /**
   * Hidden and missing forms give identical errors despite invalid scope.
   */
  public function testReferenceVisibilityWithInvalidAssignment(): void {
    [$jur, $org] = $this->createWorkspacePair('managed');
    [$other_jur, $other_org] = $this->createWorkspacePair('visible');
    [$hidden_jur, $hidden_org] = $this->createWorkspacePair('hidden');
    $seeder = $this->createUser(['bypass beantragt group scope']);
    $this->setCurrentUser($seeder);
    $own = $this->createSchema($seeder, $jur, $org);
    $readable = $this->createSchema($seeder, $other_jur, $other_org);
    $hidden = $this->createSchema($seeder, $hidden_jur);
    foreach ([$own, $readable, $hidden] as $form) {
      $form->save();
    }
    $actor = $this->createGroupAuthor();
    $org->addMember($actor, ['group_roles' => ['org-admin']]);
    $other_org->addMember($actor);
    $this->setCurrentUser($actor);
    $storage = $this->entityTypeManager()->getStorage('form_schema_group');
    $group = $storage->create([
      'label' => 'Reference group',
      'field_jurisdiction' => $jur->id(),
      'field_organisation' => $org->id(),
      'field_schemas' => [$own->id()],
    ]);
    $group->save();
    $before = $storage->loadUnchanged($group->id())->toArray();
    $missing_uuid = $this->container->get('uuid')->generate();
    foreach ([NULL, $group->uuid()] as $update_uuid) {
      $hidden_form = $this->groupPayload($jur, $other_org, $hidden->uuid());
      $missing_form = $this->groupPayload($jur, $other_org, $missing_uuid);
      $hidden_error = $this->assertGroupResponse($actor, $hidden_form, 404, 'errors.schema.notFound', update_uuid: $update_uuid);
      $missing_error = $this->assertGroupResponse($actor, $missing_form, 404, 'errors.schema.notFound', update_uuid: $update_uuid);
      self::assertSame($hidden_error, $missing_error);
      self::assertSame($before, $storage->loadUnchanged($group->id())->toArray());
    }

    $invalid = $this->groupPayload($jur, $other_org, $own->uuid());
    $invalid['relationships']['uid'] = ['data' => ['type' => 'user--user', 'id' => $missing_uuid]];
    $invalid_error = $this->assertCreateResponse($actor, 'form_schema_group', $invalid, 422, 'errors.schema.invalid');
    self::assertSame('The selected organisation must belong to the selected jurisdiction.', $invalid_error['errors'][0]['detail']);

    foreach ([
      ['field_schemas', 'form_schema--form_schema', $hidden->uuid(), 'errors.schema.notFound'],
      ['field_jurisdiction', 'group--jur', $hidden_jur->uuid(), 'errors.workspace.notFound'],
      ['field_organisation', 'group--org', $hidden_org->uuid(), 'errors.workspace.notFound'],
      ['field_group', 'group--org', $hidden_org->uuid(), 'errors.workspace.notFound'],
    ] as [$field, $type, $hidden_uuid, $reason]) {
      $path = '/jsonapi/form_schema_group/form_schema_group/' . $group->uuid() . '/relationships/' . $field;
      $expected = ['errors' => [['status' => '404', 'code' => $reason]]];
      foreach (['PATCH', 'POST', 'DELETE'] as $method) {
        foreach ([$hidden_uuid, $missing_uuid] as $uuid) {
          $identifier = ['type' => $type, 'id' => $uuid];
          $data = $field === 'field_schemas' ? [$identifier] : $identifier;
          self::assertSame($expected, $this->assertJsonApiResponse($actor, $method, $path, $data, 404));
          self::assertSame($before, $storage->loadUnchanged($group->id())->toArray());
        }
      }
    }

    // Editing just the references must validate the stored workspace too.
    foreach ([[$hidden, 404, 'errors.schema.notFound'], [$readable, 422, NULL]] as [$form, $status, $code]) {
      $identifier = ['type' => 'form_schema--form_schema', 'id' => $form->uuid()];
      $data = ['relationships' => ['field_schemas' => ['data' => [$identifier]]]];
      $this->assertGroupResponse($actor, $data, $status, $code, update_uuid: $group->uuid());
      self::assertSame($before, $storage->loadUnchanged($group->id())->toArray());
    }

    $this->revokeGlobalPermission($actor, 'view unpublished form_schema entities');
    $this->entityTypeManager()->getAccessControlHandler('form_schema')->resetCache();
    self::assertFalse($own->access('view', $actor));
    foreach ([$own->uuid(), $missing_uuid] as $uuid) {
      $data = $this->groupPayload($jur, $org, $uuid);
      $result = $this->assertGroupResponse($actor, $data, 404, update_uuid: $group->uuid());
      self::assertSame(['errors' => [['status' => '404', 'code' => 'errors.schema.notFound']]], $result);
      self::assertSame($before, $storage->loadUnchanged($group->id())->toArray());
    }

    $this->assertGroupResponse($actor, ['attributes' => ['label' => 'New title']], 200, update_uuid: $group->uuid());
    $saved = $storage->loadUnchanged($group->id());
    self::assertSame('New title', $saved->label());
    self::assertSame([(int) $own->id()], $saved->getFormSchemaIds());
  }

  /**
   * Checks the create or update response for a form group.
   */
  private function assertGroupResponse(
    UserInterface $actor,
    array $data,
    int $status,
    ?string $code = NULL,
    ?string $update_uuid = NULL,
  ): array {
    if ($update_uuid === NULL) {
      return $this->assertCreateResponse($actor, 'form_schema_group', $data, $status, $code);
    }
    $data['type'] = 'form_schema_group--form_schema_group';
    $data['id'] = $update_uuid;
    return $this->assertJsonApiResponse($actor, 'PATCH', '/jsonapi/form_schema_group/form_schema_group/' . $update_uuid, $data, $status, $code);
  }

  /**
   * Creates a department administrator with create and read permissions.
   */
  private function createGroupAuthor(): UserInterface {
    $account = $this->createUser([
      'create form_schema entities',
      'create form_schema_group entities',
      'edit own form_schema_group entities',
      'view published form_schema entities',
      'view unpublished form_schema entities',
      'view published form_schema_group entities',
    ]);
    $account->addRole('department_admin');
    $account->save();
    return $account;
  }

  /**
   * Builds a group draft with ordered JSON:API form references.
   */
  private function groupPayload(GroupInterface $jur, ?GroupInterface $org, string ...$ids): array {
    $references = array_map(
      static fn (string $id): array => ['type' => 'form_schema--form_schema', 'id' => $id],
      $ids,
    );
    return [
      'attributes' => ['label' => 'Group draft', 'field_workflow_status' => 'draft'],
      'relationships' => $this->workspaceRelationships($jur, $org) + [
        'field_schemas' => ['data' => $references],
      ],
    ];
  }

}
