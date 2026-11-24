<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Controller;

use Drupal\beantragt_group\Controller\WorkspaceInviteController;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\WorkspaceInviteTokenIssuerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests workspace invite controller grant validation.
 *
 * @group beantragt_group
 */
#[Group('beantragt_group')]
final class WorkspaceInviteControllerTest extends UnitTestCase {

  /**
   * Valid grant roles must belong to the invite membership target group type.
   */
  public function testValidatedGrantRolesAcceptsMatchingGroupType(): void {
    $controller = $this->createController([
      'org-member' => $this->createGroupRole('org'),
    ]);

    $roles = $this->invokePrivate($controller, 'validatedGrantRoles', [
      ['roles' => ['org-member', 'org-member', '', 123]],
      $this->createGroup('org'),
    ]);

    $this->assertSame(['org-member'], $roles);
  }

  /**
   * Mismatched invite roles are rejected before a token is claimed.
   */
  public function testValidatedGrantRolesRejectsMismatchedGroupType(): void {
    $controller = $this->createController([
      'org-member' => $this->createGroupRole('org'),
    ]);

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Invite role "org-member" does not belong to group type "jur".');

    $this->invokePrivate($controller, 'validatedGrantRoles', [
      ['roles' => ['org-member']],
      $this->createGroup('jur'),
    ]);
  }

  /**
   * Roles removed from the current allowlist are rejected at claim time.
   */
  public function testValidatedGrantRolesRejectsNonGrantableRole(): void {
    $verified = $this->createMock(VerifiedAccountMail::class);
    $verified->method('getVerifiedEmail')->willReturn('invitee@example.org');
    $controller = $this->createController(
      [
        'org-member' => $this->createGroupRole('org'),
      ],
      [],
      ['jur-member'],
    );

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Invite role "org-member" is not grantable through workspace invites.');

    $this->invokePrivate($controller, 'validatedGrantRoles', [
      ['roles' => ['org-member']],
      $this->createGroup('org'),
    ]);
  }

  /**
   * Workspace invites must grant at least one concrete group role.
   */
  public function testValidatedGrantRolesRejectsEmptyRoles(): void {
    $controller = $this->createController();

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Invite must grant at least one role.');

    $this->invokePrivate($controller, 'validatedGrantRoles', [
      ['roles' => []],
      $this->createGroup('jur'),
    ]);
  }

  /**
   * Invite creation fails before issuing a token when no frontend is set.
   */
  public function testCreateInviteFailsClosedWithoutFrontendBaseUrl(): void {
    $issuer = $this->createUser(42, 'admin@example.org');
    $parent = $this->createGroup('jur', 9);
    $token_issuer = $this->createMock(WorkspaceInviteTokenIssuerInterface::class);
    $token_issuer->expects($this->never())->method('issue');
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(42);

    $controller = $this->createController(
      ['jur-member' => $this->createGroupRole('jur')],
      [9 => $parent],
      users: [42 => $issuer],
      tokenIssuer: $token_issuer,
      currentUser: $current_user,
      frontendBaseUrl: '',
    );
    $request = Request::create('/api/workspace-invites', 'POST', content: json_encode([
      'target_parent_id' => 9,
      'email' => 'invitee@example.org',
      'roles' => ['jur-member'],
    ], JSON_THROW_ON_ERROR));

    $response = $controller->createInvite($request);

    $this->assertSame(503, $response->getStatusCode());
    $this->assertStringContainsString('beantragt_group.settings:frontend_base_url', (string) $response->getContent());
  }

  /**
   * Claim URLs use the configured frontend and encode bearer tokens.
   */
  public function testBuildClaimUrlUsesConfiguredFrontendAndEncodedToken(): void {
    $controller = $this->createController(frontendBaseUrl: 'https://frontend.example/base/');

    $url = $this->invokePrivate($controller, 'buildClaimUrl', [
      'a/b+c=',
      'https://frontend.example/base',
    ]);

    $this->assertSame('https://frontend.example/base/workspace/invite?token=a%2Fb%2Bc%3D', $url);
  }

  /**
   * Workspace invite links allow HTTP only for the exact loopback hosts.
   */
  public function testResolveFrontendBaseUrlAllowsOnlyLoopbackHttp(): void {
    foreach (['localhost', '127.0.0.1', '[::1]'] as $host) {
      $url = 'http://' . $host . ':3000/base';
      $controller = $this->createController(frontendBaseUrl: $url . '/');
      $this->assertSame($url, $this->invokePrivate($controller, 'resolveFrontendBaseUrl', []));
    }

    foreach ([
      'http://frontend.example',
      'http://localhost.example',
      'http://localhost.',
      'http://127.0.0.2',
      'http://localhost@frontend.example',
      'http://user:password@localhost',
      'ftp://localhost',
    ] as $url) {
      $controller = $this->createController(frontendBaseUrl: $url);
      $this->assertNull($this->invokePrivate($controller, 'resolveFrontendBaseUrl', []));
    }
  }

  /**
   * Workspace invite bases reject query strings and fragments.
   */
  public function testResolveFrontendBaseUrlRejectsQueryAndFragment(): void {
    foreach (['https://frontend.example?tenant=x', 'https://frontend.example#app'] as $frontend_base_url) {
      $controller = $this->createController(frontendBaseUrl: $frontend_base_url);
      $this->assertNull($this->invokePrivate($controller, 'resolveFrontendBaseUrl', []));
    }
  }

  /**
   * Workspace-create invites grant on the parent when no child group is set.
   */
  public function testMembershipTargetFallsBackToParentJurisdiction(): void {
    $target = $this->createGroup('jur', 9);
    $controller = $this->createController([], [9 => $target]);

    $resolved = $this->invokePrivate($controller, 'membershipTarget', [
      [
        'target_parent_id' => 9,
        'group_id' => NULL,
      ],
    ]);

    $this->assertSame($target, $resolved);
  }

  /**
   * Member invites grant on the explicit target group.
   */
  public function testMembershipTargetPrefersExplicitGroup(): void {
    $parent = $this->createGroup('jur', 9);
    $target = $this->createGroup('org', 12, 9);
    $controller = $this->createController([], [9 => $parent, 12 => $target]);

    $resolved = $this->invokePrivate($controller, 'membershipTarget', [
      [
        'target_parent_id' => 9,
        'group_id' => 12,
      ],
    ]);

    $this->assertSame($target, $resolved);
  }

  /**
   * Explicit target groups must stay inside the target parent jurisdiction.
   */
  public function testMembershipTargetRejectsForeignExplicitGroup(): void {
    $parent = $this->createGroup('jur', 9);
    $target = $this->createGroup('org', 12, 99);
    $controller = $this->createController([], [9 => $parent, 12 => $target]);

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Invite target group is outside target parent scope.');

    $this->invokePrivate($controller, 'membershipTarget', [
      [
        'target_parent_id' => 9,
        'group_id' => 12,
      ],
    ]);
  }

  /**
   * Already claimed token details stay private for other logged-in users.
   */
  public function testClaimInviteRejectsDifferentClaimantBeforeReturningDetails(): void {
    $token = str_repeat('a', 64);
    $claimant = $this->createUser(42, 'attacker@example.org');
    $token_issuer = $this->createMock(WorkspaceInviteTokenIssuerInterface::class);
    $token_issuer->method('inspect')->with($token)->willReturn([
      'status' => 'claimed',
      'invite' => [
        'email' => 'victim@example.org',
        'roles' => ['jur-member'],
      ],
    ]);
    $token_issuer->expects($this->never())->method('claim');

    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(42);

    $controller = $this->createController(
      users: [42 => $claimant],
      tokenIssuer: $token_issuer,
      currentUser: $current_user,
    );

    $response = $controller->claimInvite($token, Request::create('/api/workspace-invites/claim/' . $token, 'POST'));

    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringNotContainsString('victim@example.org', (string) $response->getContent());
  }

  /**
   * Matched claim responses do not expose internal invite metadata.
   */
  public function testClaimStatusResponseRedactsInternalInviteMetadata(): void {
    $controller = $this->createController();

    $response = $this->invokePrivate($controller, 'tokenStatusResponse', [
      [
        'status' => 'claimed',
        'invite' => [
          'id' => 7,
          'purpose' => 'workspace_create',
          'email' => 'invitee@example.org',
          'target_parent_id' => 9,
          'roles' => ['jur-member'],
          'invited_by' => 6,
          'issuer_scope_id' => 3,
          'created' => 1781431200,
          'expires' => 1782036000,
        ],
      ],
    ]);

    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame('claimed', $payload['status']);
    $this->assertSame('invitee@example.org', $payload['invite']['email']);
    $this->assertArrayNotHasKey('roles', $payload['invite']);
    $this->assertArrayNotHasKey('invited_by', $payload['invite']);
    $this->assertArrayNotHasKey('issuer_scope_id', $payload['invite']);
  }

  /**
   * Claim and membership writes are rolled back together on grant failure.
   */
  public function testClaimInviteRollsBackWhenMembershipGrantFails(): void {
    $token = str_repeat('b', 64);
    $claimant = $this->createUser(42, 'invitee@example.org');
    $parent = $this->createGroup('jur', 9);
    $target = $this->createGroup('org', 12, 9, new \RuntimeException('addMember failed'));
    $invite = [
      'email' => 'invitee@example.org',
      'target_parent_id' => 9,
      'group_id' => 12,
      'roles' => ['org-member'],
    ];

    $token_issuer = $this->createMock(WorkspaceInviteTokenIssuerInterface::class);
    $token_issuer->method('inspect')->with($token)->willReturn([
      'status' => 'pending',
      'invite' => $invite,
    ]);
    $token_issuer->method('claim')->with($token)->willReturn([
      'status' => 'claimed',
      'invite' => $invite,
    ]);

    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(42);

    $database = $this->createMock(Connection::class);
    $transaction_manager = $this->createMock(TransactionManagerInterface::class);
    $transaction_manager->expects($this->once())->method('rollback');
    $database->method('transactionManager')->willReturn($transaction_manager);
    $database->method('startTransaction')->with('workspace_invite_claim')->willReturn(
      new Transaction($database, 'workspace_invite_claim', 'tx1'),
    );

    $verified = $this->createMock(VerifiedAccountMail::class);
    $verified->method('getVerifiedEmail')->willReturn('invitee@example.org');
    $controller = $this->createController(
      [
        'org-member' => $this->createGroupRole('org'),
      ],
      [9 => $parent, 12 => $target],
      users: [42 => $claimant],
      tokenIssuer: $token_issuer,
      currentUser: $current_user,
      database: $database,
      verifiedMail: $verified,
    );

    $this->installEmptyMembershipContainer();
    $verified = $this->createMock(VerifiedAccountMail::class);
    $verified->method('getVerifiedEmail')->willReturn('invitee@example.org');
    \Drupal::getContainer()->set('beantragt_passwordless.verified_mail', $verified);
    try {
      $response = $controller->claimInvite($token, Request::create('/api/workspace-invites/claim/' . $token, 'POST'));
    }
    finally {
      \Drupal::unsetContainer();
    }

    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringContainsString('Invitation could not be claimed.', (string) $response->getContent());
  }

  /**
   * Raw account mail cannot accept an invitation without mailbox provenance.
   */
  public function testInvitationRequiresVerifiedCurrentAddress(): void {
    $claimant = $this->createUser(42, 'invitee@example.test');
    $verified = $this->createMock(VerifiedAccountMail::class);
    $verified->method('getVerifiedEmail')->willReturnOnConsecutiveCalls(NULL, 'invitee@example.test');
    $controller = $this->createController(verifiedMail: $verified);
    $container = new ContainerBuilder();
    $container->set('beantragt_passwordless.verified_mail', $verified);
    \Drupal::setContainer($container);
    $arguments = [['email' => 'invitee@example.test'], $claimant];
    $this->assertFalse($this->invokePrivate($controller, 'inviteEmailMatchesClaimant', $arguments));
    $this->assertTrue($this->invokePrivate($controller, 'inviteEmailMatchesClaimant', $arguments));
  }

  /**
   * Creates a controller with mocked entity storages.
   *
   * @param array<string, \Drupal\group\Entity\GroupRoleInterface> $roles
   *   Group role mocks keyed by role ID.
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Group mocks keyed by group ID.
   * @param string[] $grantableRoles
   *   Currently configured workspace-invite grantable role IDs.
   * @param array<int, \Drupal\user\UserInterface> $users
   *   User mocks keyed by user ID.
   * @param \Drupal\beantragt_group\Service\WorkspaceInviteTokenIssuerInterface|null $tokenIssuer
   *   Token issuer mock override.
   * @param \Drupal\Core\Session\AccountInterface|null $currentUser
   *   Current account mock override.
   * @param \Drupal\Core\Database\Connection|null $database
   *   Database connection mock override.
   * @param string|null $frontendBaseUrl
   *   Configured frontend base URL.
   * @param \Drupal\beantragt_passwordless\Service\VerifiedAccountMail|null $verifiedMail
   *   Verified account mailbox service.
   */
  private function createController(
    array $roles = [],
    array $groups = [],
    array $grantableRoles = ['jur-member', 'org-member'],
    array $users = [],
    ?WorkspaceInviteTokenIssuerInterface $tokenIssuer = NULL,
    ?AccountInterface $currentUser = NULL,
    ?Connection $database = NULL,
    ?string $frontendBaseUrl = NULL,
    ?VerifiedAccountMail $verifiedMail = NULL,
  ): WorkspaceInviteController {
    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('load')->willReturnCallback(
      static fn(string $id): ?GroupRoleInterface => $roles[$id] ?? NULL
    );

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnCallback(
      static fn(int|string $id): ?GroupInterface => $groups[(int) $id] ?? NULL
    );

    $user_storage = $this->createMock(EntityStorageInterface::class);
    $user_storage->method('load')->willReturnCallback(
      static fn(int|string $id): ?UserInterface => $users[(int) $id] ?? NULL
    );

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      static fn(string $entity_type): EntityStorageInterface => match ($entity_type) {
        'group_role' => $role_storage,
        'group' => $group_storage,
        'user' => $user_storage,
        default => throw new \InvalidArgumentException(sprintf('Unexpected storage "%s".', $entity_type)),
      }
    );

    $hierarchy_resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $hierarchy_resolver->method('isSameOrDescendantById')->willReturnCallback(
      static fn(int $candidate_id, int $scope_id): bool => $candidate_id === $scope_id
    );
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);

    return new WorkspaceInviteController(
      $entity_type_manager,
      $tokenIssuer ?? $this->createMock(WorkspaceInviteTokenIssuerInterface::class),
      $hierarchy_resolver,
      $database ?? $this->createMock(Connection::class),
      $this->createMock(MailManagerInterface::class),
      $this->createConfigFactory($grantableRoles, $frontendBaseUrl),
      $currentUser ?? $this->createMock(AccountInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $flood,
      $verifiedMail,
    );
  }

  /**
   * Creates a group mock with a fixed bundle.
   */
  private function createGroup(
    string $bundle,
    int $id = 1,
    ?int $jurisdictionId = NULL,
    ?\Throwable $addMemberException = NULL,
  ): GroupInterface {
    $jurisdiction_field = $this->createMock(FieldItemListInterface::class);
    $jurisdiction_field->method('isEmpty')->willReturn($jurisdictionId === NULL || $jurisdictionId <= 0);
    $jurisdiction_field->method('__get')->with('target_id')->willReturn($jurisdictionId ?? 0);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('id')->willReturn((string) $id);
    $group->method('hasField')->willReturnCallback(
      static fn(string $field_name): bool => $field_name === 'field_jurisdiction' && $jurisdictionId !== NULL
    );
    $group->method('get')->willReturnCallback(
      static fn(string $field_name): FieldItemListInterface => $field_name === 'field_jurisdiction'
        ? $jurisdiction_field
        : throw new \InvalidArgumentException(sprintf('Unexpected field "%s".', $field_name))
    );
    if ($addMemberException) {
      $group->expects($this->once())->method('addMember')->willThrowException($addMemberException);
    }
    return $group;
  }

  /**
   * Creates a group role mock with a fixed group type ID.
   */
  private function createGroupRole(string $group_type): GroupRoleInterface {
    $role = $this->createMock(GroupRoleInterface::class);
    $role->method('getGroupTypeId')->willReturn($group_type);
    return $role;
  }

  /**
   * Creates a user mock with fixed ID and email.
   */
  private function createUser(int $id, string $email): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($id);
    $user->method('getEmail')->willReturn($email);
    return $user;
  }

  /**
   * Creates the config factory needed by invite validation helpers.
   *
   * @param string[] $grantableRoles
   *   Workspace invite grantable roles.
   * @param string|null $frontendBaseUrl
   *   Configured frontend base URL.
   */
  private function createConfigFactory(array $grantableRoles, ?string $frontendBaseUrl = NULL): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => match ($key) {
        'jurisdiction_group_type' => 'jur',
        'organisation_group_type' => 'org',
        'grantable_roles' => $grantableRoles,
        'frontend_base_url' => $frontendBaseUrl,
        default => NULL,
      }
    );

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);
    return $config_factory;
  }

  /**
   * Installs enough Drupal container state for GroupMembership::loadSingle().
   */
  private function installEmptyMembershipContainer(): void {
    $group_relationship_storage = $this->createMock(EntityStorageInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('group_relationship')
      ->willReturn($group_relationship_storage);

    $cache_backend = $this->createMock(CacheBackendInterface::class);
    $cache_backend->method('get')->willReturn((object) ['data' => []]);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('cache.group_memberships_chained', $cache_backend);
    \Drupal::setContainer($container);
  }

  /**
   * Invokes a private controller helper.
   *
   * @param \Drupal\beantragt_group\Controller\WorkspaceInviteController $controller
   *   Controller under test.
   * @param string $method
   *   Private method name.
   * @param mixed[] $args
   *   Arguments passed to the private method.
   */
  private function invokePrivate(WorkspaceInviteController $controller, string $method, array $args): mixed {
    $reflection = new \ReflectionMethod($controller, $method);
    $reflection->setAccessible(TRUE);

    return $reflection->invokeArgs($controller, $args);
  }

}
