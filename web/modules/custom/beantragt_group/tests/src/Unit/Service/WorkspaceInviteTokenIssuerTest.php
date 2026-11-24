<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\beantragt_group\Service\WorkspaceInviteAccessInterface;
use Drupal\beantragt_group\Service\WorkspaceInviteTokenIssuer;
use Drupal\beantragt_group\Service\WorkspaceInviteTokenIssuerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests workspace invite token issuance.
 *
 * @group beantragt_group
 */
final class WorkspaceInviteTokenIssuerTest extends UnitTestCase {

  /**
   * The schema keeps Mark-a-Spot fields and adds Beantragt scope metadata.
   */
  public function testInvitationSchemaContainsPurposeAndScopeSnapshot(): void {
    require_once dirname(__DIR__, 4) . '/beantragt_group.install';

    $schema = beantragt_group_schema()['beantragt_group_invitations'];

    $this->assertArrayHasKey('token', $schema['fields']);
    $this->assertArrayHasKey('purpose', $schema['fields']);
    $this->assertArrayHasKey('target_parent_id', $schema['fields']);
    $this->assertArrayHasKey('issuer_scope_id', $schema['fields']);
    $this->assertArrayHasKey('payload', $schema['fields']);
    $this->assertSame(['token'], $schema['unique keys']['token']);
    $this->assertSame(['email', 'purpose', 'target_parent_id'], $schema['indexes']['pending_lookup']);
  }

  /**
   * Workspace-create tokens snapshot issuer scope and insert one DB row.
   */
  public function testIssueWorkspaceCreateTokenPersistsScopeSnapshot(): void {
    $captured = [];
    $issuer = $this->mockUser(7);
    $target_parent = $this->mockGroup(20);
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabase(FALSE, $captured),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, 10),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $result = $issuer_service->issue(
      $issuer,
      $target_parent,
      ' Invitee@Example.COM ',
      WorkspaceInviteTokenIssuerInterface::PURPOSE_WORKSPACE_CREATE,
      ['jur-member', 'jur-member', '', 123],
      NULL,
      60,
      ['jurisdiction_label' => 'Stadt Musterstadt'],
    );

    $this->assertSame(123, $result['id']);
    $this->assertSame(64, strlen($result['token']));
    $this->assertSame('invitee@example.com', $result['email']);
    $this->assertSame(20, $result['target_parent_id']);
    $this->assertNull($result['group_id']);
    $this->assertSame(['jur-member'], $result['roles']);
    $this->assertSame(7, $result['invited_by']);
    $this->assertSame(10, $result['issuer_scope_id']);
    $this->assertSame(1000, $result['created']);
    $this->assertSame(1060, $result['expires']);

    $this->assertSame('workspace_create', $captured['purpose']);
    $this->assertSame('invitee@example.com', $captured['email']);
    $this->assertSame(20, $captured['target_parent_id']);
    $this->assertNull($captured['group_id']);
    $this->assertSame('["jur-member"]', $captured['roles']);
    $this->assertSame(10, $captured['issuer_scope_id']);
    $this->assertSame('{"jurisdiction_label":"Stadt Musterstadt"}', $captured['payload']);
    // The stored token column holds the HMAC digest, never the raw token.
    $this->assertSame(
      hash_hmac('sha256', $result['token'], 'test-private-key-secret'),
      $captured['token'],
    );
    $this->assertNotSame($result['token'], $captured['token']);
  }

  /**
   * Member invites require an explicit target group.
   */
  public function testMemberInviteRequiresTargetGroup(): void {
    $captured = [];
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabase(FALSE, $captured, 0),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Member invite tokens require a target group.');

    $issuer_service->issue(
      $this->mockUser(7),
      $this->mockGroup(20),
      'invitee@example.com',
      WorkspaceInviteTokenIssuerInterface::PURPOSE_MEMBER_INVITE,
    );
  }

  /**
   * Issuers outside the target parent scope are denied before insert.
   */
  public function testDeniedIssuerCannotCreateToken(): void {
    $captured = [];
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabase(FALSE, $captured, 0),
      $this->mockTime(1000),
      $this->mockAccess(FALSE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $this->expectException(AccessDeniedHttpException::class);

    $issuer_service->issue(
      $this->mockUser(7),
      $this->mockGroup(20),
      'invitee@example.com',
    );
  }

  /**
   * Duplicate pending tokens for the same scope are blocked.
   */
  public function testDuplicatePendingInviteIsRejected(): void {
    $captured = [];
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabase(TRUE, $captured, 0),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('A pending invitation for this email, purpose, and target parent already exists.');

    $issuer_service->issue(
      $this->mockUser(7),
      $this->mockGroup(20),
      'invitee@example.com',
    );
  }

  /**
   * Roles outside the configured allowlist are rejected before insert.
   */
  public function testNonGrantableRoleIsRejected(): void {
    $captured = [];
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabase(FALSE, $captured, 0),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(['jur-member']),
      $this->mockEntityTypeManager(['jur-member']),
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Role "org-admin" is not grantable through workspace invites.');

    $issuer_service->issue(
      $this->mockUser(7),
      $this->mockGroup(20),
      'invitee@example.com',
      WorkspaceInviteTokenIssuerInterface::PURPOSE_WORKSPACE_CREATE,
      ['org-admin'],
    );
  }

  /**
   * Grantable role IDs without a group_role config entity are rejected.
   */
  public function testGrantableRoleMustExistAsGroupRole(): void {
    $captured = [];
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabase(FALSE, $captured, 0),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(['jur-member']),
      $this->mockEntityTypeManager([]),
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Role "jur-member" does not exist.');

    $issuer_service->issue(
      $this->mockUser(7),
      $this->mockGroup(20),
      'invitee@example.com',
      WorkspaceInviteTokenIssuerInterface::PURPOSE_WORKSPACE_CREATE,
      ['jur-member'],
    );
  }

  /**
   * Pending invite listing omits tokens and normalizes stored JSON roles.
   */
  public function testListPendingNormalizesRows(): void {
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabaseForList([
        [
          'id' => '5',
          'purpose' => 'workspace_create',
          'email' => 'invitee@example.com',
          'target_parent_id' => '20',
          'group_id' => NULL,
          'roles' => '["jur-admin",123]',
          'invited_by' => '7',
          'issuer_scope_id' => '10',
          'created' => '1000',
          'expires' => '604900',
        ],
      ]),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, 10),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $rows = $issuer_service->listPending($this->mockUser(7), $this->mockGroup(20));

    $this->assertSame([
      [
        'id' => 5,
        'purpose' => 'workspace_create',
        'email' => 'invitee@example.com',
        'target_parent_id' => 20,
        'group_id' => NULL,
        'roles' => ['jur-admin'],
        'invited_by' => 7,
        'issuer_scope_id' => 10,
        'payload' => [],
        'created' => 1000,
        'expires' => 604900,
        'claimed' => NULL,
      ],
    ], $rows);
  }

  /**
   * Revoking a pending invite deletes the matching row.
   */
  public function testRevokeDeletesPendingInvite(): void {
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabaseForRevoke(TRUE, 1),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $this->assertTrue($issuer_service->revoke(
      $this->mockUser(7),
      $this->mockGroup(20),
      5,
    ));
  }

  /**
   * Revoking a missing or expired invite returns FALSE.
   */
  public function testRevokeReturnsFalseWhenInviteMissing(): void {
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabaseForRevoke(FALSE, 0),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $this->assertFalse($issuer_service->revoke(
      $this->mockUser(7),
      $this->mockGroup(20),
      5,
    ));
  }

  /**
   * Inspecting a pending token returns normalized invite data.
   */
  public function testInspectPendingToken(): void {
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabaseForTokenLookup([
        'id' => '6',
        'purpose' => 'workspace_create',
        'email' => 'invitee@example.com',
        'target_parent_id' => '20',
        'group_id' => NULL,
        'roles' => '[]',
        'invited_by' => '7',
        'issuer_scope_id' => NULL,
        'payload' => '{"target_slug":"stadt-musterstadt"}',
        'created' => '1000',
        'expires' => '604900',
        'claimed' => NULL,
      ]),
      $this->mockTime(1000),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $result = $issuer_service->inspect(str_repeat('a', 64));

    $this->assertSame('pending', $result['status']);
    $this->assertSame('stadt-musterstadt', $result['invite']['payload']['target_slug']);
  }

  /**
   * Claiming a pending token atomically marks it claimed.
   */
  public function testClaimPendingToken(): void {
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabaseForClaim(1, [
        'id' => '6',
        'purpose' => 'workspace_create',
        'email' => 'invitee@example.com',
        'target_parent_id' => '20',
        'group_id' => NULL,
        'roles' => '[]',
        'invited_by' => '7',
        'issuer_scope_id' => NULL,
        'payload' => NULL,
        'created' => '1000',
        'expires' => '604900',
        'claimed' => '1001',
      ]),
      $this->mockTime(1001),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $result = $issuer_service->claim(str_repeat('b', 64));

    $this->assertSame('claimed', $result['status']);
    $this->assertSame(1001, $result['invite']['claimed']);
  }

  /**
   * Claiming an already claimed token returns a stable status.
   */
  public function testClaimAlreadyClaimedToken(): void {
    $issuer_service = new WorkspaceInviteTokenIssuer(
      $this->mockDatabaseForClaim(0, [
        'id' => '6',
        'purpose' => 'workspace_create',
        'email' => 'invitee@example.com',
        'target_parent_id' => '20',
        'group_id' => NULL,
        'roles' => '[]',
        'invited_by' => '7',
        'issuer_scope_id' => NULL,
        'payload' => NULL,
        'created' => '1000',
        'expires' => '604900',
        'claimed' => '1001',
      ]),
      $this->mockTime(1002),
      $this->mockAccess(TRUE, NULL),
      $this->mockPrivateKey(),
      $this->mockConfigFactory(),
      $this->mockEntityTypeManager(),
    );

    $result = $issuer_service->claim(str_repeat('c', 64));

    $this->assertSame('already_claimed', $result['status']);
  }

  /**
   * Builds a database mock for duplicate check and insert.
   *
   * @param bool $duplicate
   *   Whether the duplicate-check query should find an existing row.
   * @param array<string, mixed> $captured
   *   Captured insert fields.
   * @param int $insertCount
   *   Expected number of insert calls.
   */
  private function mockDatabase(bool $duplicate, array &$captured, int $insertCount = 1): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($duplicate ? 99 : FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('isNull')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $insert = $this->createMock(Insert::class);
    $insert->expects($this->exactly($insertCount))
      ->method('fields')
      ->willReturnCallback(static function (array $fields) use (&$captured, $insert): Insert {
        $captured = $fields;
        return $insert;
      });
    $insert->expects($this->exactly($insertCount))
      ->method('execute')
      ->willReturn(123);

    $database = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select', 'insert']);
    // @phpstan-ignore-next-line method.deprecated
    $database = $database->getMockForAbstractClass();
    $database->method('select')
      ->with('beantragt_group_invitations', 'i')
      ->willReturn($select);
    $database->expects($this->exactly($insertCount))
      ->method('insert')
      ->with('beantragt_group_invitations')
      ->willReturn($insert);

    return $database;
  }

  /**
   * Builds a database mock for pending invite listing.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Rows returned by the list query.
   */
  private function mockDatabaseForList(array $rows): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn($rows);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('isNull')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select']);
    // @phpstan-ignore-next-line method.deprecated
    $database = $database->getMockForAbstractClass();
    $database->method('select')
      ->with('beantragt_group_invitations', 'i')
      ->willReturn($select);

    return $database;
  }

  /**
   * Builds a database mock for pending invite revocation.
   */
  private function mockDatabaseForRevoke(bool $rowExists, int $deleteCount): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($rowExists ? ['id' => 5] : FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('isNull')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $delete = $this->createMock(Delete::class);
    $delete->expects($this->exactly($rowExists ? 1 : 0))
      ->method('condition')
      ->with('id', 5)
      ->willReturnSelf();
    $delete->expects($this->exactly($rowExists ? 1 : 0))
      ->method('execute')
      ->willReturn($deleteCount);

    $database = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select', 'delete']);
    // @phpstan-ignore-next-line method.deprecated
    $database = $database->getMockForAbstractClass();
    $database->method('select')
      ->with('beantragt_group_invitations', 'i')
      ->willReturn($select);
    $database->expects($this->exactly($rowExists ? 1 : 0))
      ->method('delete')
      ->with('beantragt_group_invitations')
      ->willReturn($delete);

    return $database;
  }

  /**
   * Builds a database mock for token lookup.
   *
   * @param array<string, mixed>|false $row
   *   Token row or FALSE.
   */
  private function mockDatabaseForTokenLookup(array|false $row): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($row);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select']);
    // @phpstan-ignore-next-line method.deprecated
    $database = $database->getMockForAbstractClass();
    $database->method('select')
      ->with('beantragt_group_invitations', 'i')
      ->willReturn($select);

    return $database;
  }

  /**
   * Builds a database mock for token claiming.
   *
   * @param int $affectedRows
   *   Number of rows affected by the atomic claim update.
   * @param array<string, mixed>|false $row
   *   Token row after update or lookup.
   */
  private function mockDatabaseForClaim(int $affectedRows, array|false $row): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAssoc')->willReturn($row);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('isNull')->willReturnSelf();
    $update->method('execute')->willReturn($affectedRows);

    $database = $this->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['select', 'update']);
    // @phpstan-ignore-next-line method.deprecated
    $database = $database->getMockForAbstractClass();
    $database->method('update')
      ->with('beantragt_group_invitations')
      ->willReturn($update);
    $database->method('select')
      ->with('beantragt_group_invitations', 'i')
      ->willReturn($select);

    return $database;
  }

  /**
   * Builds an invite access mock.
   */
  private function mockAccess(bool $allowed, ?int $scopeId): WorkspaceInviteAccessInterface {
    $access = $this->createMock(WorkspaceInviteAccessInterface::class);
    $access->method('canIssue')->willReturn($allowed);
    $access->method('issuerScopeId')->willReturn($scopeId);

    return $access;
  }

  /**
   * Builds a private key mock returning a fixed HMAC secret.
   */
  private function mockPrivateKey(): PrivateKey {
    $private_key = $this->createMock(PrivateKey::class);
    $private_key->method('get')->willReturn('test-private-key-secret');

    return $private_key;
  }

  /**
   * Builds a config factory mock exposing the grantable role allowlist.
   *
   * @param string[] $grantableRoles
   *   The grantable role IDs.
   */
  private function mockConfigFactory(array $grantableRoles = ['jur-member', 'org-member']): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('grantable_roles')
      ->willReturn($grantableRoles);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_group.settings')
      ->willReturn($config);

    return $factory;
  }

  /**
   * Builds an entity type manager mock for group_role existence checks.
   *
   * @param string[] $existingRoles
   *   Role IDs that resolve to a loadable group_role entity.
   */
  private function mockEntityTypeManager(array $existingRoles = ['jur-member', 'org-member']): EntityTypeManagerInterface {
    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('load')
      ->willReturnCallback(function (string $id) use ($existingRoles): ?GroupRoleInterface {
        return in_array($id, $existingRoles, TRUE)
          ? $this->createMock(GroupRoleInterface::class)
          : NULL;
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('group_role')
      ->willReturn($role_storage);

    return $entity_type_manager;
  }

  /**
   * Builds a fixed time service.
   */
  private function mockTime(int $timestamp): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($timestamp);

    return $time;
  }

  /**
   * Builds a saved user mock.
   */
  private function mockUser(int $id): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn((string) $id);

    return $user;
  }

  /**
   * Builds a saved group mock.
   */
  private function mockGroup(int $id): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);

    return $group;
  }

}
