<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\WorkspaceInviteAccess;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests workspace invite scope access.
 *
 * @group beantragt_group
 */
final class WorkspaceInviteAccessTest extends UnitTestCase {

  /**
   * A user without the inviter role cannot issue workspace invites.
   */
  public function testUserNeedsWorkspaceInviterRole(): void {
    $access = new WorkspaceInviteAccess(
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->mockConfigFactory('jur'),
    );

    $this->assertFalse($access->canIssue(
      $this->mockIssuer(FALSE, NULL),
      $this->mockJurisdiction(10)
    ));
  }

  /**
   * An empty inviter scope requires the platform administration permission.
   */
  public function testEmptyScopeRequiresAdminPermission(): void {
    $access = new WorkspaceInviteAccess(
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->mockConfigFactory('jur'),
    );

    // Empty scope without the admin permission is denied (not fail-open).
    $this->assertFalse($access->canIssue(
      $this->mockIssuer(TRUE, NULL, FALSE),
      $this->mockJurisdiction(10)
    ));

    // Empty scope with the admin permission may issue for any jurisdiction.
    $this->assertTrue($access->canIssue(
      $this->mockIssuer(TRUE, NULL, TRUE),
      $this->mockJurisdiction(10)
    ));
  }

  /**
   * Missing scope field configuration denies global invite access.
   */
  public function testMissingScopeFieldCannotIssueGlobalInvites(): void {
    $access = new WorkspaceInviteAccess(
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->mockConfigFactory('jur'),
    );

    $issuer = $this->createMock(UserInterface::class);
    $issuer->method('hasRole')
      ->with(WorkspaceInviteAccess::ROLE_ID)
      ->willReturn(TRUE);
    $issuer->method('hasField')
      ->with(WorkspaceInviteAccess::SCOPE_FIELD)
      ->willReturn(FALSE);

    $this->assertFalse($access->canIssue(
      $issuer,
      $this->mockJurisdiction(10)
    ));
  }

  /**
   * Invite scope includes the jurisdiction and its descendants.
   */
  #[DataProvider('invitableJurisdictionProvider')]
  public function testScopedIssuerCanIssueWithinScope(int $jurisdiction_id): void {
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->expects($this->once())
      ->method('getDescendantIds')
      ->with(20)
      ->willReturn([20, 30]);

    $access = new WorkspaceInviteAccess($resolver, $this->mockConfigFactory('jur'));

    $this->assertTrue($access->canIssue(
      $this->mockIssuer(TRUE, 20),
      $this->mockJurisdiction($jurisdiction_id)
    ));
  }

  /**
   * Supplies the jurisdiction id for each case.
   *
   * @return array<string, array{int}>
   *   Named test cases.
   */
  public static function invitableJurisdictionProvider(): array {
    return [
      'own jurisdiction' => [20],
      'descendant jurisdiction' => [30],
    ];
  }

  /**
   * A scoped inviter cannot issue invites outside the scope subtree.
   */
  public function testScopedIssuerCannotIssueOutsideScope(): void {
    $resolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $resolver->expects($this->once())
      ->method('getDescendantIds')
      ->with(20)
      ->willReturn([20, 30]);

    $access = new WorkspaceInviteAccess($resolver, $this->mockConfigFactory('jur'));

    $this->assertFalse($access->canIssue(
      $this->mockIssuer(TRUE, 20),
      $this->mockJurisdiction(40)
    ));
  }

  /**
   * A target parent must be a jurisdiction group.
   */
  public function testTargetParentMustBeJurisdiction(): void {
    $access = new WorkspaceInviteAccess(
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->mockConfigFactory('jur'),
    );

    $this->assertFalse($access->canIssue(
      $this->mockIssuer(TRUE, NULL, TRUE),
      $this->mockGroup(10, 'org')
    ));
  }

  /**
   * The jurisdiction bundle comes from beantragt_group settings.
   */
  public function testTargetParentUsesConfiguredJurisdictionBundle(): void {
    $access = new WorkspaceInviteAccess(
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->mockConfigFactory('workspace'),
    );

    $this->assertTrue($access->canIssue(
      $this->mockIssuer(TRUE, NULL, TRUE),
      $this->mockGroup(10, 'workspace')
    ));
  }

  /**
   * Builds a user mock with the workspace inviter contract.
   */
  private function mockIssuer(bool $hasRole, ?int $scopeId, bool $isAdmin = FALSE): UserInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($scopeId === NULL);
    $field->method('getValue')->willReturn($scopeId === NULL ? [] : [['target_id' => $scopeId]]);

    $issuer = $this->createMock(UserInterface::class);
    $issuer->method('hasRole')
      ->with(WorkspaceInviteAccess::ROLE_ID)
      ->willReturn($hasRole);
    $issuer->method('hasField')
      ->with(WorkspaceInviteAccess::SCOPE_FIELD)
      ->willReturn(TRUE);
    $issuer->method('get')
      ->with(WorkspaceInviteAccess::SCOPE_FIELD)
      ->willReturn($field);
    $issuer->method('hasPermission')
      ->with('administer beantragt groups')
      ->willReturn($isAdmin);

    return $issuer;
  }

  /**
   * Builds a jurisdiction group mock.
   */
  private function mockJurisdiction(int $id): GroupInterface {
    return $this->mockGroup($id, 'jur');
  }

  /**
   * Builds a group mock with a configurable bundle.
   */
  private function mockGroup(int $id, string $bundle): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);

    return $group;
  }

  /**
   * Builds a config factory mock for the jurisdiction group type.
   */
  private function mockConfigFactory(string $jurisdictionGroupType): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn($jurisdictionGroupType);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_group.settings')
      ->willReturn($config);

    return $factory;
  }

}
