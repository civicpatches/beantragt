<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_group\Commands\FachadministrationCommands;
use Drupal\beantragt_group\Service\FachadministrationGrant;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The explicit grant of a Fachadministration and its operator command.
 *
 * Neutral groups, invented addresses. The command runs only here, never
 * against an account of an instance.
 */
#[Group('beantragt_group')]
final class FachadministrationGrantKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
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
   * Municipality A.
   */
  private GroupInterface $municipalityA;

  /**
   * Municipality B.
   */
  private GroupInterface $municipalityB;

  /**
   * Office A1 of municipality A.
   */
  private GroupInterface $officeA1;

  /**
   * Office A2 of municipality A.
   */
  private GroupInterface $officeA2;

  /**
   * Office B1 of municipality B.
   */
  private GroupInterface $officeB1;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
    // Deleting a group (the orphan office case) cleans up file usage.
    $this->installSchema('file', ['file_usage']);
    // The command resolves groups by slug; the field comes from the install.
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    foreach (['department_admin', 'case_worker', 'service_account'] as $role_id) {
      if (Role::load($role_id) === NULL) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }

    $this->municipalityA = $this->createMunicipality('grant-a', 'Municipality A');
    $this->municipalityB = $this->createMunicipality('grant-b', 'Municipality B');
    $this->officeA1 = $this->createOffice('office-one', 'Office A1', $this->municipalityA);
    $this->officeA2 = $this->createOffice('office-two', 'Office A2', $this->municipalityA);
    // Same office slug as A1 in the other municipality: slugs repeat.
    $this->officeB1 = $this->createOffice('office-one', 'Office B1', $this->municipalityB);
  }

  /**
   * A municipality grant gives jur-admin there and the site role.
   */
  public function testMunicipalityGrantGivesJurAdminAndSiteRole(): void {
    $account = $this->createAccount('grant_full');

    $changes = $this->grant()->grantMunicipality($account, $this->municipalityA, 'test');

    $this->assertSame(['site role department_admin', 'membership jur-admin'], $changes);
    $this->assertTrue($this->reload($account)->hasRole('department_admin'));
    $this->assertMemberships($account, ['Municipality A' => ['jur-admin']]);
    $this->assertSame([(int) $this->municipalityA->id()], $this->grant()->reachedMunicipalityIds($account));
  }

  /**
   * An office grant gives org-admin in that office and nowhere else.
   */
  public function testOfficeGrantGivesOrgAdminOnlyThere(): void {
    $account = $this->createAccount('grant_limited');

    $changes = $this->grant()->grantOffice($account, $this->officeA1, 'test');

    $this->assertSame(['site role department_admin', 'membership org-admin'], $changes);
    $this->assertTrue($this->reload($account)->hasRole('department_admin'));
    $this->assertMemberships($account, ['Office A1' => ['org-admin']]);
    foreach ([$this->municipalityA, $this->officeA2, $this->municipalityB, $this->officeB1] as $group) {
      $this->assertFalse(GroupMembership::loadSingle($group, $account), (string) $group->label());
    }
  }

  /**
   * A group of the wrong type is refused by the type check itself.
   *
   * The message is asserted: without the type check an office passed as a
   * municipality, or a municipality passed as an office, would be granted.
   */
  public function testWrongGroupTypeIsRefused(): void {
    $account = $this->createAccount('grant_wrong_type');

    foreach ([
      'is not a municipality' => fn () => $this->grant()->grantMunicipality($account, $this->officeA1, 'test'),
      'is not an office' => fn () => $this->grant()->grantOffice($account, $this->municipalityA, 'test'),
    ] as $expected => $call) {
      try {
        $call();
        $this->fail('Wrong group type accepted: ' . $expected);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($expected, $e->getMessage());
      }
    }
    $this->assertFalse($this->reload($account)->hasRole('department_admin'));
    $this->assertMemberships($account, []);
  }

  /**
   * An account with the site role is refused a second municipality.
   */
  public function testSecondMunicipalityIsRefusedWithoutTheFlag(): void {
    $full = $this->createAccount('grant_second_full');
    $this->grant()->grantMunicipality($full, $this->municipalityA, 'test');
    $limited = $this->createAccount('grant_second_limited');
    $this->grant()->grantOffice($limited, $this->officeA1, 'test');

    foreach ([
      'full, municipality B' => fn () => $this->grant()->grantMunicipality($full, $this->municipalityB, 'test'),
      'full, office B1' => fn () => $this->grant()->grantOffice($full, $this->officeB1, 'test'),
      'limited, municipality B' => fn () => $this->grant()->grantMunicipality($limited, $this->municipalityB, 'test'),
      'limited, office B1' => fn () => $this->grant()->grantOffice($limited, $this->officeB1, 'test', FALSE, TRUE),
    ] as $case => $call) {
      try {
        $call();
        $this->fail('Second municipality accepted: ' . $case);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('another municipality', $e->getMessage(), $case);
      }
    }
    $this->assertMemberships($full, ['Municipality A' => ['jur-admin']]);
    $this->assertMemberships($limited, ['Office A1' => ['org-admin']]);

    // Inside the own municipality no flag is needed once the site role is
    // held: an office next to the municipality, the municipality above the
    // office.
    $this->grant()->grantOffice($full, $this->officeA2, 'test');
    $this->grant()->grantMunicipality($limited, $this->municipalityA, 'test');
    $this->assertMemberships($full, ['Municipality A' => ['jur-admin'], 'Office A2' => ['org-admin']]);
    $this->assertMemberships($limited, ['Municipality A' => ['jur-admin'], 'Office A1' => ['org-admin']]);

    // With the flag the operator may grant a second one by name.
    $this->assertSame(['membership jur-admin'], $this->grant()->grantMunicipality($full, $this->municipalityB, 'test', TRUE));
    $this->assertSame(
      [(int) $this->municipalityA->id(), (int) $this->municipalityB->id()],
      $this->grant()->reachedMunicipalityIds($full),
    );
  }

  /**
   * Promoting an editor of two offices names both offices.
   */
  public function testPromotingEditorOfTwoOfficesNeedsWithExistingOffices(): void {
    $account = $this->createAccount('grant_two_offices', ['case_worker']);
    $this->officeA1->addMember($account, ['group_roles' => ['org-admin']]);
    $this->officeA2->addMember($account, ['group_roles' => ['org-admin']]);
    $before = $this->membershipSnapshot($account);

    try {
      $this->grant()->grantOffice($account, $this->officeA1, 'test');
      $this->fail('Office grant accepted while the account is editor of a second office.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('same municipality', $e->getMessage());
      $this->assertStringContainsString('with-existing-offices', $e->getMessage());
      $this->assertStringContainsString('office "Office A2"', $e->getMessage());
      $this->assertStringNotContainsString('office "Office A1"', $e->getMessage(), 'The target is not outside its own reach.');
    }
    $this->assertNothingChanged($account, $before, ['case_worker']);

    // The other municipality's option does not stand in for it.
    $this->expectRefusal(fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test', TRUE));
    $this->assertNothingChanged($account, $before, ['case_worker']);

    // With the option the operator names the widening, once.
    $this->assertSame(['site role department_admin'], $this->grant()->grantOffice($account, $this->officeA1, 'test', FALSE, TRUE));
    $this->assertMemberships($account, ['Office A1' => ['org-admin'], 'Office A2' => ['org-admin']]);
  }

  /**
   * A municipality grant covers memberships in that municipality's offices.
   */
  public function testMunicipalityGrantAcceptsMembershipsInsideItsMunicipality(): void {
    $account = $this->createAccount('grant_inside', ['case_worker']);
    $this->officeA1->addMember($account, ['group_roles' => ['org-admin']]);
    $this->officeA2->addMember($account, ['group_roles' => ['org-member']]);

    $this->assertSame(
      ['site role department_admin', 'membership jur-admin'],
      $this->grant()->grantMunicipality($account, $this->municipalityA, 'test'),
    );
    $this->assertMemberships($account, [
      'Municipality A' => ['jur-admin'],
      'Office A1' => ['org-admin'],
      'Office A2' => ['org-member'],
    ]);
  }

  /**
   * Promotion across municipalities requires confirmation for both offices.
   */
  public function testPromotingEditorWithOfficeInOtherMunicipalityNeedsTheFlag(): void {
    $account = $this->createAccount('grant_two_towns', ['case_worker']);
    $this->officeA1->addMember($account, ['group_roles' => ['org-admin']]);
    $this->officeB1->addMember($account, ['group_roles' => ['org-admin']]);
    $before = $this->membershipSnapshot($account);

    foreach ([
      'office grant' => fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test'),
      'office grant with existing offices' => fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test', FALSE, TRUE),
      'municipality grant' => fn () => $this->grant()->grantMunicipality($account, $this->municipalityA, 'test'),
    ] as $case => $call) {
      $message = $this->expectRefusal($call, $case);
      $this->assertStringContainsString('another municipality', $message, $case);
      $this->assertStringContainsString('office "Office B1"', $message, $case);
      $this->assertNothingChanged($account, $before, ['case_worker']);
    }

    $this->assertSame(['site role department_admin', 'membership jur-admin'], $this->grant()->grantMunicipality($account, $this->municipalityA, 'test', TRUE));
    $this->assertSame(
      [(int) $this->municipalityA->id(), (int) $this->municipalityB->id()],
      $this->grant()->reachedMunicipalityIds($account),
    );
  }

  /**
   * A plain membership counts for reach; the refusal names every group.
   */
  public function testPlainMembershipCountsForReach(): void {
    $account = $this->createAccount('grant_plain', ['case_worker']);
    $this->officeA2->addMember($account, ['group_roles' => ['org-member']]);
    $this->officeB1->addMember($account, ['group_roles' => ['org-member']]);
    $before = $this->membershipSnapshot($account);

    $message = $this->expectRefusal(fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test'));
    $this->assertStringContainsString('office "Office B1"', $message);
    $this->assertStringContainsString('office "Office A2"', $message);
    $this->assertStringContainsString('additional-municipality', $message);
    $this->assertStringContainsString('with-existing-offices', $message);
    $this->assertNothingChanged($account, $before, ['case_worker']);
    $this->assertSame(
      [(int) $this->municipalityA->id(), (int) $this->municipalityB->id()],
      $this->grant()->reachedMunicipalityIds($account),
    );
  }

  /**
   * A membership in an office without a municipality always refuses.
   *
   * Built the way it arises: the presave constraint refuses an office without
   * a municipality, so the office's municipality is deleted afterwards and
   * its reference points nowhere.
   */
  public function testOfficeWithoutMunicipalityAlwaysRefuses(): void {
    $municipality_c = $this->createMunicipality('grant-c', 'Municipality C');
    $office_c1 = $this->createOffice('office-c1', 'Office C1', $municipality_c);
    $account = $this->createAccount('grant_orphan', ['case_worker']);
    $office_c1->addMember($account, ['group_roles' => ['org-member']]);
    $municipality_c->delete();
    $office_c1 = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($office_c1->id());
    $this->assertInstanceOf(GroupInterface::class, $office_c1);
    $this->assertFalse($office_c1->get('field_jurisdiction')->isEmpty(), 'The reference still exists.');
    $before = $this->membershipSnapshot($account);

    foreach ([
      'municipality grant, both options' => fn () => $this->grant()->grantMunicipality($account, $this->municipalityA, 'test', TRUE),
      'office grant, both options' => fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test', TRUE, TRUE),
    ] as $case => $call) {
      $message = $this->expectRefusal($call, $case);
      $this->assertStringContainsString('without a municipality', $message, $case);
      $this->assertStringContainsString('office "Office C1"', $message, $case);
      $this->assertNothingChanged($account, $before, ['case_worker']);
    }
    $message = $this->expectRefusal(fn () => $this->grant()->grantOffice($account, $office_c1, 'test', TRUE, TRUE));
    $this->assertStringContainsString('belongs to no municipality', $message);
  }

  /**
   * The reach rule applies only when the grant adds the site role.
   *
   * An account that already holds department_admin can already reach its
   * offices. The grant needs no with-existing-offices confirmation. The rule
   * for adding a second municipality still applies.
   */
  public function testAccountHoldingTheSiteRoleIsNotSubjectToTheReachRule(): void {
    $account = $this->createAccount('grant_has_role', ['department_admin']);
    $this->officeA2->addMember($account, ['group_roles' => ['org-admin']]);

    $this->assertSame(['membership org-admin'], $this->grant()->grantOffice($account, $this->officeA1, 'test'));
    $this->assertMemberships($account, ['Office A1' => ['org-admin'], 'Office A2' => ['org-admin']]);

    $message = $this->expectRefusal(fn () => $this->grant()->grantMunicipality($account, $this->municipalityB, 'test'));
    $this->assertStringContainsString('another municipality', $message);
    $this->assertMemberships($account, ['Office A1' => ['org-admin'], 'Office A2' => ['org-admin']]);
  }

  /**
   * A repeated call changes nothing, also for a flagged second municipality.
   */
  public function testRepeatedGrantChangesNothing(): void {
    $account = $this->createAccount('grant_repeat');
    $this->grant()->grantMunicipality($account, $this->municipalityA, 'test');
    $this->grant()->grantMunicipality($account, $this->municipalityB, 'test', TRUE);
    $this->grant()->grantOffice($account, $this->officeA1, 'test');
    $before = $this->membershipSnapshot($account);

    $this->assertSame([], $this->grant()->grantMunicipality($account, $this->municipalityA, 'test'));
    $this->assertSame([], $this->grant()->grantMunicipality($account, $this->municipalityB, 'test'));
    $this->assertSame([], $this->grant()->grantOffice($account, $this->officeA1, 'test'));

    $this->assertSame($before, $this->membershipSnapshot($account));
  }

  /**
   * Existing memberships, group roles and site roles survive a grant.
   */
  public function testExistingMembershipsAndRolesSurvive(): void {
    $account = $this->createAccount('grant_survive', ['case_worker']);
    $this->municipalityA->addMember($account, ['group_roles' => ['jur-member']]);
    $this->officeA2->addMember($account, ['group_roles' => ['org-member']]);
    $this->officeB1->addMember($account, ['group_roles' => ['org-member']]);
    $before = $this->membershipSnapshot($account);

    // The existing membership in A gains the admin role in place; B1 is named
    // explicitly.
    $this->assertSame(['site role department_admin', 'group role jur-admin'], $this->grant()->grantMunicipality($account, $this->municipalityA, 'test', TRUE));

    $reloaded = $this->reload($account);
    $this->assertTrue($reloaded->hasRole('case_worker'));
    $this->assertTrue($reloaded->hasRole('department_admin'));
    $this->assertMemberships($account, [
      'Municipality A' => ['jur-admin', 'jur-member'],
      'Office A2' => ['org-member'],
      'Office B1' => ['org-member'],
    ]);
    $after = $this->membershipSnapshot($account);
    foreach ($before as $label => $row) {
      $this->assertSame($row['id'], $after[$label]['id'], 'Same membership entity: ' . $label);
    }
  }

  /**
   * Anonymous, uid 1 and the service account are never granted.
   */
  public function testProtectedAccountsAreRefused(): void {
    $uid1 = User::load(1) ?? $this->createUser([], 'grant_uid1', FALSE, ['uid' => 1]);
    $this->assertInstanceOf(UserInterface::class, $uid1);
    $service = $this->createAccount('grant_service', ['service_account']);

    $this->expectRefusal(fn () => $this->grant()->grantMunicipality(User::getAnonymousUser(), $this->municipalityA, 'test'), 'anonymous');
    foreach (['uid 1' => $uid1, 'service account' => $service] as $case => $account) {
      $before = $this->membershipSnapshot($account);
      $had_role = $account->hasRole('department_admin');
      $this->expectRefusal(fn () => $this->grant()->grantMunicipality($account, $this->municipalityA, 'test'), $case);
      $this->assertSame($before, $this->membershipSnapshot($account), $case);
      $this->assertSame($had_role, $this->reload($account)->hasRole('department_admin'), $case);
    }
  }

  /**
   * A failure after the first write rolls the site role back.
   *
   * The office is a stand-in for Office A1 whose addMember() throws, so the
   * site role is already saved when the second write fails.
   */
  public function testFailureAfterTheFirstWriteRollsBack(): void {
    $account = $this->createAccount('grant_rollback');
    $failing = $this->standInForOfficeA1();
    $failing->method('addMember')->willThrowException(new \RuntimeException('Forced failure after the first write.'));

    try {
      $this->grant()->grantOffice($account, $failing, 'test');
      $this->fail('The forced failure did not surface.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Forced failure after the first write.', $e->getMessage());
    }

    $this->assertFalse($this->reload($account)->hasRole('department_admin'), 'The saved site role was rolled back.');
    $this->assertMemberships($account, []);
    // The object passed in still carries the role in memory: a caller must
    // reload it after a failed grant.
    $this->assertTrue($account->hasRole('department_admin'));
  }

  /**
   * A write that returns without error but leaves no membership is caught.
   *
   * The read-back after the writes refuses and rolls the site role back.
   */
  public function testReadBackCatchesMissingMembership(): void {
    $account = $this->createAccount('grant_read_back');
    $silent = $this->standInForOfficeA1();
    // addMember() returns normally and writes nothing.
    $silent->expects($this->once())->method('addMember');

    try {
      $this->grant()->grantOffice($account, $silent, 'test');
      $this->fail('The missing membership was not noticed.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('is not in place after the grant', $e->getMessage());
    }

    $this->assertFalse($this->reload($account)->hasRole('department_admin'), 'The saved site role was rolled back.');
    $this->assertMemberships($account, []);
  }

  /**
   * A missing site role refuses before anything is written.
   */
  public function testMissingSiteRoleIsRefused(): void {
    $account = $this->createAccount('grant_no_role');
    Role::load('department_admin')->delete();

    $message = $this->expectRefusal(fn () => $this->grant()->grantMunicipality($account, $this->municipalityA, 'test'));

    $this->assertStringContainsString('The site role department_admin does not exist', $message);
    $this->assertMemberships($account, []);
  }

  /**
   * Plain membership in the target municipality waives nothing.
   *
   * For an account that holds the site role, only an admin membership in the
   * target's municipality means the operator accepted it already.
   */
  public function testPlainMembershipInTheTargetMunicipalityDoesNotWaiveTheFlag(): void {
    $account = $this->createAccount('grant_d1', ['department_admin']);
    $this->municipalityA->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->officeB1->addMember($account, ['group_roles' => ['org-member']]);
    $before = $this->membershipSnapshot($account);

    $message = $this->expectRefusal(fn () => $this->grant()->grantMunicipality($account, $this->municipalityB, 'test'));
    $this->assertStringContainsString('no admin membership in the target municipality', $message);
    $this->assertStringContainsString('municipality "Municipality A" (id ' . $this->municipalityA->id() . ') jur-admin', $message);
    $this->assertSame($before, $this->membershipSnapshot($account));

    $this->assertSame(['membership jur-admin'], $this->grant()->grantMunicipality($account, $this->municipalityB, 'test', TRUE));
  }

  /**
   * An admin membership in the target municipality needs no flag.
   */
  public function testAdminMembershipInTheTargetMunicipalityNeedsNoFlag(): void {
    $account = $this->createAccount('grant_d1_admin', ['department_admin']);
    $this->municipalityA->addMember($account, ['group_roles' => ['jur-admin']]);
    $this->officeB1->addMember($account, ['group_roles' => ['org-admin']]);

    $this->assertSame(['membership jur-admin'], $this->grant()->grantMunicipality($account, $this->municipalityB, 'test'));
  }

  /**
   * For an office grant the municipality group is never an office.
   *
   * With the site role a jur-admin there is the full Fachadministration, and
   * even a jur-member reaches the municipality's own entities; the refusal
   * names the roles and points to the municipality grant.
   */
  public function testOfficeGrantRefusesMembershipInTheMunicipalityGroup(): void {
    foreach (['jur-member', 'jur-admin'] as $role) {
      $account = $this->createAccount('grant_d4_' . str_replace('-', '_', $role), ['case_worker']);
      $this->municipalityA->addMember($account, ['group_roles' => [$role]]);
      $before = $this->membershipSnapshot($account);

      foreach ([FALSE, TRUE] as $with_existing_offices) {
        $message = $this->expectRefusal(fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test', TRUE, $with_existing_offices), $role);
        $this->assertStringContainsString('municipality group itself', $message, $role);
        // The message says why and names both ways out, so an
        // invited jur-member is no silent dead end.
        $this->assertStringContainsString('a plain membership there becomes reach over the cases filed directly under the municipality', $message, $role);
        $this->assertStringContainsString('use the municipality grant if the full role is meant', $message, $role);
        $this->assertStringContainsString('have the operator remove this membership first (no command removes memberships today)', $message, $role);
        $this->assertStringContainsString('municipality "Municipality A" (id ' . $this->municipalityA->id() . ') ' . $role, $message, $role);
        $this->assertNothingChanged($account, $before, ['case_worker']);
      }
    }
  }

  /**
   * A membership without any group role counts and is named as such.
   */
  public function testMembershipWithoutGroupRoleCounts(): void {
    $account = $this->createAccount('grant_no_group_role', ['case_worker']);
    $this->officeA2->addMember($account);
    $before = $this->membershipSnapshot($account);
    $this->assertSame(['Office A2' => ['id' => $before['Office A2']['id'], 'roles' => []]], $before);

    $message = $this->expectRefusal(fn () => $this->grant()->grantOffice($account, $this->officeA1, 'test'));

    $this->assertStringContainsString('office "Office A2" (id ' . $this->officeA2->id() . ') (no group role)', $message);
    $this->assertNothingChanged($account, $before, ['case_worker']);
  }

  /**
   * The command prints a blocked account as such.
   */
  public function testCommandPrintsBlockedAccount(): void {
    $account = $this->createAccount('command_blocked');
    $account->block()->save();

    [$exit, $output] = $this->runCommand('command_blocked@example.org', 'grant-a');

    $this->assertSame(0, $exit, $output);
    $this->assertStringContainsString('(uid ' . $account->id() . '), BLOCKED, site roles: department_admin', $output);
  }

  /**
   * A grant is logged with actor, account uid, group, role and reach.
   */
  public function testGrantIsLoggedWithActorAccountGroupAndRole(): void {
    $logger = new class() extends AbstractLogger {

      /**
       * Interpolated messages.
       *
       * @var list<string>
       */
      public array $lines = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->lines[] = $level . ': ' . strtr((string) $message, array_map('strval', $context));
      }

    };
    $grant = new FachadministrationGrant(
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
      $this->container->get('current_user'),
      $logger,
    );
    $account = $this->createAccount('grant_logged', ['department_admin'], 'grant.logged@example.org');
    $this->officeA2->addMember($account, ['group_roles' => ['org-admin']]);

    $grant->grantOffice($account, $this->officeA1, 'operator test');
    $grant->grantOffice($account, $this->officeA1, 'operator test');

    $this->assertCount(1, $logger->lines);
    $line = $logger->lines[0];
    $this->assertStringStartsWith('notice: ', $line);
    foreach ([
      'operator test',
      'account uid ' . $account->id() . ' ',
      'received org-admin in office "Office A1"',
      'reach after the grant: office "Office A1" (id ' . $this->officeA1->id() . ') org-admin; office "Office A2" (id ' . $this->officeA2->id() . ') org-admin',
    ] as $part) {
      $this->assertStringContainsString($part, $line);
    }
    $this->assertStringNotContainsString('grant_logged', $line, 'No account name in the log.');
    $this->assertStringNotContainsString('example.org', $line, 'No address in the log.');
  }

  /**
   * The command grants both kinds and prints the reach afterwards.
   */
  public function testCommandGrantsAndPrintsMemberships(): void {
    $full = $this->createAccount('command_full');
    $limited = $this->createAccount('command_limited');
    $editor = $this->createAccount('command_editor', ['case_worker']);
    $this->officeA1->addMember($editor, ['group_roles' => ['org-admin']]);
    $this->officeA2->addMember($editor, ['group_roles' => ['org-admin']]);

    [$exit, $output] = $this->runCommand('command_full@example.org', 'grant-a');
    $this->assertSame(0, $exit, $output);
    $this->assertStringContainsString('Granted: site role department_admin, membership jur-admin.', $output);
    $this->assertStringContainsString('active, site roles: department_admin', $output);
    $this->assertStringContainsString('municipality "Municipality A" (id ' . $this->municipalityA->id() . ') jur-admin, with every office of it', $output);
    $this->assertMemberships($full, ['Municipality A' => ['jur-admin']]);

    // The office slug resolves inside the named municipality only.
    [$exit, $output] = $this->runCommand('command_limited@example.org', 'grant-a', 'office-one');
    $this->assertSame(0, $exit, $output);
    $this->assertStringContainsString('office "Office A1" (id ' . $this->officeA1->id() . ') org-admin', $output);
    $this->assertStringContainsString('Office A1 [office-one]', $output);
    $this->assertMemberships($limited, ['Office A1' => ['org-admin']]);

    [$exit, $output] = $this->runCommand('command_limited@example.org', 'grant-a', 'office-one');
    $this->assertSame(0, $exit, $output);
    $this->assertStringContainsString('Nothing changed', $output);

    [$exit, $output] = $this->runCommand('command_full@example.org', 'grant-b');
    $this->assertSame(1, $exit, $output);
    $this->assertStringContainsString('another municipality', $output);
    [$exit, $output] = $this->runCommand('command_full@example.org', 'grant-b', NULL, TRUE);
    $this->assertSame(0, $exit, $output);
    $this->assertMemberships($full, ['Municipality A' => ['jur-admin'], 'Municipality B' => ['jur-admin']]);

    // The new option travels from the command to the service.
    [$exit, $output] = $this->runCommand('command_editor@example.org', 'grant-a', 'office-one');
    $this->assertSame(1, $exit, $output);
    $this->assertStringContainsString('with-existing-offices', $output);
    [$exit, $output] = $this->runCommand('command_editor@example.org', 'grant-a', 'office-one', FALSE, TRUE);
    $this->assertSame(0, $exit, $output);
    $this->assertStringContainsString('office "Office A2" (id ' . $this->officeA2->id() . ') org-admin', $output);
  }

  /**
   * Unknown, empty or ambiguous names fail closed and change nothing.
   */
  public function testCommandFailsClosedOnUnknownNames(): void {
    $account = $this->createAccount('command_closed');
    $this->createAccount('command_twin_one', [], 'twin@example.org');
    $this->createAccount('command_twin_two', [], 'twin@example.org');
    $this->createMunicipality('grant-twin', 'Twin municipality one');
    $this->createMunicipality('grant-twin', 'Twin municipality two');

    foreach ([
      'unknown address' => ['nobody@example.org', 'grant-a'],
      'empty address' => ['', 'grant-a'],
      'two accounts with the address' => ['twin@example.org', 'grant-a'],
      'unknown municipality' => ['command_closed@example.org', 'no-such-town'],
      'empty municipality' => ['command_closed@example.org', ''],
      'two municipalities with the slug' => ['command_closed@example.org', 'grant-twin'],
      'office slug of another municipality only' => ['command_closed@example.org', 'grant-b', 'office-two'],
      'unknown office' => ['command_closed@example.org', 'grant-a', 'no-such-office'],
      'office slug given as municipality' => ['command_closed@example.org', 'office-one'],
      'office given but empty' => ['command_closed@example.org', 'grant-a', ''],
      'office given but blank' => ['command_closed@example.org', 'grant-a', '  '],
      'with-existing-offices on a municipality grant' => ['command_closed@example.org', 'grant-a', NULL, FALSE, TRUE],
    ] as $case => $arguments) {
      [$exit, $output] = $this->runCommand(...$arguments);
      $this->assertSame(1, $exit, $case);
      $this->assertStringContainsString('Refused, nothing changed', $output, $case);
    }
    [, $output] = $this->runCommand('command_closed@example.org', 'grant-a', '');
    $this->assertStringContainsString('given but empty', $output);
    $this->assertFalse($this->reload($account)->hasRole('department_admin'));
    $this->assertMemberships($account, []);
  }

  /**
   * A stand-in for Office A1 whose addMember() a test configures.
   *
   * Type, id, label and fields come from the real office.
   *
   * @return \Drupal\group\Entity\GroupInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The stand-in.
   */
  private function standInForOfficeA1(): GroupInterface {
    $real = $this->officeA1;
    $stand_in = $this->createMock(GroupInterface::class);
    $stand_in->method('isNew')->willReturn(FALSE);
    $stand_in->method('bundle')->willReturn('org');
    $stand_in->method('id')->willReturn($real->id());
    $stand_in->method('label')->willReturn($real->label());
    $stand_in->method('hasField')->willReturnCallback(static fn (string $name): bool => $real->hasField($name));
    $stand_in->method('get')->willReturnCallback(static fn (string $name) => $real->get($name));
    return $stand_in;
  }

  /**
   * The grant service from the container.
   */
  private function grant(): FachadministrationGrant {
    $grant = $this->container->get('beantragt_group.fachadministration_grant');
    assert($grant instanceof FachadministrationGrant);
    return $grant;
  }

  /**
   * Runs a call that must be refused and returns the refusal message.
   */
  private function expectRefusal(callable $call, string $case = ''): string {
    try {
      $call();
    }
    catch (\InvalidArgumentException $e) {
      return $e->getMessage();
    }
    $this->fail('Grant accepted: ' . $case);
  }

  /**
   * Asserts memberships and site roles are as before a refused grant.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param array<string, array{id: int, roles: list<string>}> $before
   *   The snapshot taken before.
   * @param list<string> $site_roles
   *   The site roles besides authenticated.
   */
  private function assertNothingChanged(UserInterface $account, array $before, array $site_roles): void {
    $this->assertSame($before, $this->membershipSnapshot($account));
    $roles = $this->reload($account)->getRoles(TRUE);
    sort($roles);
    $this->assertSame($site_roles, $roles);
  }

  /**
   * Runs the command class with buffered output.
   *
   * @return array{0: int, 1: string}
   *   Exit code and output.
   */
  private function runCommand(string $mail, string $municipality, ?string $office = NULL, bool $additional = FALSE, bool $with_existing_offices = FALSE): array {
    $commands = new FachadministrationCommands($this->grant(), $this->container->get('entity_type.manager'));
    $output = new BufferedOutput();
    $commands->setOutput($output);
    $exit = $commands->grant($mail, $municipality, $office, [
      'additional-municipality' => $additional,
      'with-existing-offices' => $with_existing_offices,
    ]);
    return [$exit, $output->fetch()];
  }

  /**
   * Asserts the account's memberships by group label and group roles.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param array<string, list<string>> $expected
   *   Group label => sorted group role IDs.
   */
  private function assertMemberships(UserInterface $account, array $expected): void {
    $actual = array_map(static fn (array $row): array => $row['roles'], $this->membershipSnapshot($account));
    ksort($expected);
    $this->assertSame($expected, $actual);
  }

  /**
   * Memberships of the account: label => membership id and sorted roles.
   *
   * @return array<string, array{id: int, roles: list<string>}>
   *   The snapshot, sorted by label.
   */
  private function membershipSnapshot(UserInterface $account): array {
    $rows = [];
    foreach (GroupMembership::loadByUser($account) as $membership) {
      $roles = array_keys($membership->getRoles(FALSE));
      sort($roles);
      $rows[(string) $membership->getGroup()->label()] = ['id' => (int) $membership->id(), 'roles' => $roles];
    }
    ksort($rows);
    return $rows;
  }

  /**
   * Reloads an account from storage.
   */
  private function reload(UserInterface $account): UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$account->id()]);
    $reloaded = $storage->load($account->id());
    assert($reloaded instanceof UserInterface);
    return $reloaded;
  }

  /**
   * Creates an active account with optional site roles.
   *
   * @param string $name
   *   Account name; the address defaults to <name>@example.org.
   * @param list<string> $site_roles
   *   Site roles to add.
   * @param string|null $mail
   *   An explicit address.
   */
  private function createAccount(string $name, array $site_roles = [], ?string $mail = NULL): UserInterface {
    $user = $this->createUser([], $name, FALSE, ['mail' => $mail ?? $name . '@example.org']);
    assert($user instanceof UserInterface);
    foreach ($site_roles as $role) {
      $user->addRole($role);
    }
    $user->activate()->save();
    return $user;
  }

  /**
   * Creates a municipality (jurisdiction group).
   */
  private function createMunicipality(string $slug, string $label): GroupInterface {
    return $this->createGroup([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
    ]);
  }

  /**
   * Creates an office (organisation group) of one municipality.
   */
  private function createOffice(string $slug, string $label, GroupInterface $municipality): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $label,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $municipality->id()],
    ]);
  }

}
