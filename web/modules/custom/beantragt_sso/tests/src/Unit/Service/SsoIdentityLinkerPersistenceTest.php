<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service {
  if (!function_exists(__NAMESPACE__ . '\\user_login_finalize')) {

    /**
     * Stubs Drupal login finalization for unit tests.
     */
    function user_login_finalize(mixed $user): void {
      $GLOBALS['sso_test_finalized'] = (int) $user->id();
    }

  }

  if (!function_exists(__NAMESPACE__ . '\\user_logout')) {

    /**
     * Stubs Drupal logout for unit tests.
     */
    function user_logout(): void {
    }

  }
}

namespace Drupal\Tests\beantragt_sso\Unit\Service {
  use Drupal\beantragt_applicant\Entity\ApplicantInterface;
  use Drupal\beantragt_applicant\Service\ApplicantManager;
  use Drupal\beantragt_sso\Service\SsoIdentityLinker;
  use Drupal\Core\Database\Connection;
  use Drupal\Core\Database\Query\Insert;
  use Drupal\Core\Database\Transaction;
  use Drupal\Core\Database\Transaction\TransactionManagerInterface;
  use Drupal\Core\Lock\LockBackendInterface;
  use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
  use Drupal\Core\Database\Query\SelectInterface;
  use Drupal\Core\Database\Query\Update;
  use Drupal\Core\Database\StatementInterface;
  use Drupal\Core\Entity\EntityStorageInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\Site\Settings;
  use Drupal\Tests\UnitTestCase;
  use Drupal\user\UserInterface;
  use Drupal\Component\Datetime\TimeInterface;
  use Psr\Log\LoggerInterface;

  /**
   * Tests identity persistence ordering.
   *
   * @group beantragt_sso
   */
  final class SsoIdentityLinkerPersistenceTest extends UnitTestCase {

    /**
     * A failing profile update never blocks the login.
     *
     * The login creates no profile and only marks an existing one, so the
     * identity row is stored and the session stays even when that marking
     * fails. Mutation probe: rethrowing the exception fails this test.
     */
    public function testFailedApplicantUpdateDoesNotBlockLogin(): void {
      new Settings(['hash_salt' => 'beantragt-sso-test']);

      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn('7');
      $user->method('getRoles')->willReturn(['authenticated']);
      $user->method('isActive')->willReturn(TRUE);
      $user->method('getEmail')->willReturn('citizen@example.test');

      $database = $this->databaseWithoutExistingIdentity();
      $merge = $this->createMock(Insert::class);

      $merge->method('fields')->willReturnSelf();
      $merge->method('execute')->willReturn(1);
      $database->expects($this->once())->method('insert')->with('beantragt_sso_identities')->willReturn($merge);

      $applicant_manager = $this->createMock(ApplicantManager::class);
      $applicant_manager->method('updateExistingFromLogin')
        ->willThrowException(new \RuntimeException('Applicant update failed.'));
      $applicant_manager->expects($this->never())->method('findOrCreate');

      $linker = new SsoIdentityLinker(
            $database,
            $this->entityTypeManagerForEmailLink($user),
            $applicant_manager,
            $this->createMock(LoggerInterface::class),
        NULL,
        $this->verifiedMail($user),
        $this->lock(),
        );

      $response = $linker->authenticate(
            'bundid',
            [
              'link_by_email' => TRUE,
              'email_verified' => TRUE,
              'create_users' => TRUE,
              'attribute_map' => [
                'email' => ['mail'],
                'last_name' => ['sn'],
              ],
            ],
            'external-subject',
            ['mail' => ['citizen@example.test'], 'sn' => ['Bürger']],
            [],
        );

      $this->assertSame(7, $response['uid']);
      $this->assertNull($response['applicant_id']);
    }

    /**
     * Pre-linked staff identities may authenticate privileged users safely.
     */
    public function testPreLinkedStaffIdentityAllowsPrivilegedUser(): void {
      new Settings(['hash_salt' => 'beantragt-sso-test']);

      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn('11');
      $user->method('getAccountName')->willReturn('synthetic_staff');
      $user->method('getEmail')->willReturn('staff@example.test');
      $user->method('getRoles')->willReturn(['authenticated', 'department_admin']);
      $user->method('isActive')->willReturn(TRUE);

      $database = $this->databaseWithExistingIdentity('11');
      $database->expects($this->never())->method('insert');

      $update = $this->createMock(Update::class);
      $update->expects($this->once())->method('fields')->willReturnSelf();
      $update->expects($this->exactly(2))->method('condition')->willReturnSelf();
      $update->expects($this->once())->method('execute')->willReturn(1);
      $database->method('update')
        ->with('beantragt_sso_identities')
        ->willReturn($update);

      $applicant = $this->createMock(ApplicantInterface::class);
      $applicant->method('id')->willReturn('99');
      $applicant_manager = $this->createMock(ApplicantManager::class);
      $applicant_manager->method('updateExistingFromLogin')->willReturn($applicant);

      $linker = new SsoIdentityLinker(
        $database,
        $this->entityTypeManagerForPreLinkedUser($user),
        $applicant_manager,
        $this->createMock(LoggerInterface::class),
        NULL,
        $this->verifiedMail($user),
        $this->lock(),
      );

      $response = $linker->authenticate(
        'staff_mock',
        [
          'link_by_email' => FALSE,
          'create_users' => FALSE,
          'minimum_assurance_level' => 'high',
          'attribute_map' => [
            'email' => ['email'],
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'full_name' => ['full_name'],
            'assurance_level' => ['assurance_level'],
          ],
        ],
        'synthetic-staff-subject',
        [
          'email' => ['staff@example.test'],
          'first_name' => ['Fachbereich'],
          'last_name' => ['Bürgerbüro'],
          'full_name' => ['Fachbereich Bürgerbüro'],
          'assurance_level' => ['high'],
        ],
        [],
      );

      $this->assertSame(11, $response['uid']);
      $this->assertSame('staff_mock', $response['auth_provider']);
      $this->assertSame('high', $response['assurance_level']);
      $this->assertContains('department_admin', $response['roles']);
    }

    /**
     * The login anchor carries only login-confirmed identity values.
     *
     * The values are marked with the provider (legal basis: the
     * authentication), never with a consent, so a consent withdrawal keeps
     * them. Address or contact data never come from the login.
     */
    public function testLoginAnchorIsMarkedAsConfirmedByTheProvider(): void {
      new Settings(['hash_salt' => 'beantragt-sso-test']);

      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn('11');
      $user->method('getAccountName')->willReturn('citizen_11');
      $user->method('getEmail')->willReturn('citizen-11@example.test');
      $user->method('getRoles')->willReturn(['authenticated']);
      $user->method('isActive')->willReturn(TRUE);

      $database = $this->databaseWithExistingIdentity('11');
      $update = $this->createMock(Update::class);
      $update->method('fields')->willReturnSelf();
      $update->method('condition')->willReturnSelf();
      $update->method('execute')->willReturn(1);
      $database->method('update')->willReturn($update);

      $applicant = $this->createMock(ApplicantInterface::class);
      $applicant->method('id')->willReturn('99');
      $applicant_manager = $this->createMock(ApplicantManager::class);
      $applicant_manager->expects($this->never())->method('findOrCreate');
      $applicant_manager->expects($this->once())
        ->method('updateExistingFromLogin')
        ->willReturnCallback(function (array $crm, int $uid, bool $verified, array $login) use ($applicant): ApplicantInterface {
          $this->assertSame([
            'person.email' => 'citizen-11@example.test',
            'person.full_name' => 'Max Mustermann',
            'person.first_name' => 'Max',
            'person.last_name' => 'Mustermann',
            'person.birthdate' => '1978-09-03',
          ], $crm);
          $this->assertSame(11, $uid);
          $this->assertTrue($verified);
          $this->assertSame('bundid', $login['provider'] ?? NULL);
          $this->assertSame(1781431200, $login['time'] ?? NULL);
          return $applicant;
        });
      $applicant_manager->expects($this->once())
        ->method('claimUnclaimedByVerifiedEmail')
        ->with(11, 'citizen-11@example.test')
        ->willReturn(1);
      $time = $this->createMock(TimeInterface::class);
      $time->method('getRequestTime')->willReturn(1781431200);

      $linker = new SsoIdentityLinker(
        $database,
        $this->entityTypeManagerForPreLinkedUser($user),
        $applicant_manager,
        $this->createMock(LoggerInterface::class),
        NULL,
        $this->verifiedMail($user),
        $this->lock(),
        $time,
      );
      $linker->authenticate(
        'bundid',
        [
          'email_verified' => TRUE,
          'attribute_map' => [
            'email' => ['email'],
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'birthdate' => ['birthdate'],
            'street' => ['street'],
          ],
        ],
        'bundid-subject-11',
        [
          'email' => ['citizen-11@example.test'],
          'first_name' => ['Max'],
          'last_name' => ['Mustermann'],
          'birthdate' => ['1978-09-03'],
          'street' => ['Bauweg 12'],
        ],
        [],
      );
    }

    /**
     * Without a released email equal to the account mail nothing is claimed.
     *
     * A synthetic account mail must never take over profiles. Mutation
     * probe: claiming unconditionally makes this test fail. A profile of
     * another account is not named as applicant_id.
     */
    public function testLoginWithoutConfirmedEmailClaimsNothing(): void {
      new Settings(['hash_salt' => 'beantragt-sso-test']);

      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn('11');
      $user->method('getAccountName')->willReturn('citizen_11');
      $user->method('getEmail')->willReturn('sso-11@sso.invalid');
      $user->method('getRoles')->willReturn(['authenticated']);
      $user->method('isActive')->willReturn(TRUE);

      $database = $this->databaseWithExistingIdentity('11');
      $update = $this->createMock(Update::class);
      $update->method('fields')->willReturnSelf();
      $update->method('condition')->willReturnSelf();
      $update->method('execute')->willReturn(1);
      $database->method('update')->willReturn($update);

      $applicant = $this->createMock(ApplicantInterface::class);
      $applicant->method('id')->willReturn('99');
      $applicant->method('getOwnerId')->willReturn(55);
      $applicant_manager = $this->createMock(ApplicantManager::class);
      $applicant_manager->method('updateExistingFromLogin')->willReturn($applicant);
      $applicant_manager->expects($this->never())->method('claimUnclaimedByVerifiedEmail');

      $linker = new SsoIdentityLinker(
        $database,
        $this->entityTypeManagerForPreLinkedUser($user),
        $applicant_manager,
        $this->createMock(LoggerInterface::class),
        NULL,
        $this->verifiedMail($user),
        $this->lock(),
      );
      $response = $linker->authenticate(
        'bundid',
        ['attribute_map' => ['email' => ['email'], 'last_name' => ['last_name']]],
        'bundid-subject-11',
        ['email' => ['other@example.test'], 'last_name' => ['Mustermann']],
        [],
      );

      $this->assertNull($response['applicant_id']);
    }

    /**
     * A provider that does not verify the mailbox never matches by email.
     *
     * The released email equals the account mail (as for every new SSO
     * account), but without email_verified it is only a claim: no profile
     * is matched or taken over through it. Mutation probe: dropping the
     * provider check makes this test fail.
     */
    public function testProviderWithoutEmailVerificationNeitherMatchesNorClaimsByEmail(): void {
      new Settings(['hash_salt' => 'beantragt-sso-test']);

      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn('11');
      $user->method('getAccountName')->willReturn('citizen_11');
      $user->method('getEmail')->willReturn('victim@example.test');
      $user->method('getRoles')->willReturn(['authenticated']);
      $user->method('isActive')->willReturn(TRUE);

      $database = $this->databaseWithExistingIdentity('11');
      $update = $this->createMock(Update::class);
      $update->method('fields')->willReturnSelf();
      $update->method('condition')->willReturnSelf();
      $update->method('execute')->willReturn(1);
      $database->method('update')->willReturn($update);

      $applicant_manager = $this->createMock(ApplicantManager::class);
      $applicant_manager->expects($this->once())
        ->method('updateExistingFromLogin')
        ->willReturnCallback(function (array $crm, int $uid, bool $verified): ?ApplicantInterface {
          $this->assertArrayNotHasKey('person.email', $crm);
          $this->assertFalse($verified);
          return NULL;
        });
      $applicant_manager->expects($this->never())->method('claimUnclaimedByVerifiedEmail');

      $linker = new SsoIdentityLinker(
        $database,
        $this->entityTypeManagerForPreLinkedUser($user),
        $applicant_manager,
        $this->createMock(LoggerInterface::class),
        NULL,
        $this->verifiedMail($user),
        $this->lock(),
      );
      $response = $linker->authenticate(
        'keycloak',
        ['attribute_map' => ['email' => ['email'], 'last_name' => ['last_name']]],
        'keycloak-subject-11',
        ['email' => ['victim@example.test'], 'last_name' => ['Angreifer']],
        [],
      );

      $this->assertNull($response['applicant_id']);
    }

    /**
     * Supplies a verified-address facade for persistence scenarios.
     */
    private function verifiedMail(UserInterface $user): VerifiedAccountMail {
      $verified = $this->createMock(VerifiedAccountMail::class);
      $verified->method('getVerifiedEmail')->willReturnCallback(function () use ($user): ?string {
        return str_starts_with((string) $user->getEmail(), 'citizen') ? $user->getEmail() : NULL;
      });
      return $verified;
    }

    /**
     * Supplies a successfully acquired identity lock.
     */
    private function lock(): LockBackendInterface {
      $lock = $this->createMock(LockBackendInterface::class);
      $lock->method('acquire')->willReturn(TRUE);
      return $lock;
    }

    /**
     * Builds database mocks that return no existing identity.
     */
    private function databaseWithoutExistingIdentity(): Connection {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchAssoc')->willReturn(FALSE);

      $select = $this->createMock(SelectInterface::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('range')->willReturnSelf();
      $select->method('execute')->willReturn($statement);

      $database = $this->createMock(Connection::class);
      $database->method('select')->willReturn($select);
      $database->method('transactionManager')->willReturn($this->createMock(TransactionManagerInterface::class));
      $database->method('startTransaction')->willReturnCallback(static fn(): Transaction => new Transaction($database, 'sso', 'sso-test'));

      return $database;
    }

    /**
     * Builds database mocks that resolve an existing identity.
     */
    private function databaseWithExistingIdentity(string $uid): Connection {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchAssoc')->willReturn(['uid' => $uid, 'disabled' => 0, 'link_method' => 'manual']);

      $select = $this->createMock(SelectInterface::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('range')->willReturnSelf();
      $select->method('execute')->willReturn($statement);

      $database = $this->createMock(Connection::class);
      $database->method('select')->willReturn($select);
      $database->method('transactionManager')->willReturn($this->createMock(TransactionManagerInterface::class));
      $database->method('startTransaction')->willReturnCallback(static fn(): Transaction => new Transaction($database, 'sso', 'sso-test'));

      return $database;
    }

    /**
     * Builds entity storage mocks that resolve the user by email.
     */
    private function entityTypeManagerForEmailLink(UserInterface $user): EntityTypeManagerInterface {
      $user_storage = $this->createMock(EntityStorageInterface::class);
      $user_storage->method('loadByProperties')
        ->with(['mail' => 'citizen@example.test'])
        ->willReturn([$user]);

      $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
      $role_storage = $this->createMock(EntityStorageInterface::class);
      $role_storage->method('loadMultiple')->willReturn([]);
      $entity_type_manager->method('getStorage')->willReturnMap([
        ['user', $user_storage], ['user_role', $role_storage],
      ]);

      return $entity_type_manager;
    }

    /**
     * Builds entity storage mocks that resolve a pre-linked user by uid.
     */
    private function entityTypeManagerForPreLinkedUser(UserInterface $user): EntityTypeManagerInterface {
      $user_storage = $this->createMock(EntityStorageInterface::class);
      $user_storage->method('load')
        ->with(11)
        ->willReturn($user);

      $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
      $role_storage = $this->createMock(EntityStorageInterface::class);
      $role_storage->method('loadMultiple')->willReturn([]);
      $entity_type_manager->method('getStorage')->willReturnMap([
        ['user', $user_storage], ['user_role', $role_storage],
      ]);

      return $entity_type_manager;
    }

  }
}
