<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicantProfileSwitch;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests beantragt_applicant module hooks.
 */
#[Group('beantragt_applicant')]
final class BeantragtApplicantModuleTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    include_once dirname(__DIR__, 3) . '/beantragt_applicant.module';
  }

  /**
   * Legacy stored CRM data does not bypass schema-aware applicant extraction.
   */
  public function testInsertDoesNotSyncStoredCrmDataFallback(): void {
    $manager = new class() {

      /**
       * Whether applicant synchronization was called.
       */
      public bool $called = FALSE;

      /**
       * Records unexpected applicant synchronization calls.
       */
      public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = []): object {
        $this->called = TRUE;
        return new \stdClass();
      }

    };

    $this->setSyncContainer($manager);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('hasProfileConsent')->willReturn(TRUE);
    $application->method('uuid')->willReturn('b7a1c0de-0000-4000-8000-000000000001');
    $application->method('getProfileConsentRecord')->willReturn(self::proofRecord(1781431200));
    $application->method('getApplicantCrmData')->willReturn([]);
    $application->expects($this->once())->method('getApplicantCrmData');
    $application->expects($this->never())->method('getCrmData');
    $application->expects($this->never())->method('getOwnerId');

    \beantragt_applicant_application_insert($application);

    $this->assertFalse($manager->called);
  }

  /**
   * Unconfirmed application insert does not sync applicant CRM profiles.
   */
  public function testApplicationInsertSkipsUnconfirmedApplications(): void {
    $manager = new class() {

      /**
       * Whether applicant synchronization was called.
       */
      public bool $called = FALSE;

      /**
       * Records unexpected applicant synchronization calls.
       */
      public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = []): object {
        $this->called = TRUE;
        return new \stdClass();
      }

    };

    $this->setSyncContainer($manager);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(FALSE);
    $application->expects($this->never())->method('getApplicantCrmData');
    $application->expects($this->never())->method('getOwnerId');

    \beantragt_applicant_application_insert($application);

    $this->assertFalse($manager->called);
  }

  /**
   * Application insert marks CRM email verified only when owner email matches.
   */
  public function testApplicationInsertPassesVerifiedEmailOnlyForMatchingOwnerEmail(): void {
    $manager = new class() {

      /**
       * Captured calls.
       *
       * @var array<int, array<string, mixed>>
       */
      public array $calls = [];

      /**
       * Records applicant synchronization calls.
       */
      public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = []): object {
        $this->calls[] = [
          'crm_data' => $crm_data,
          'uid' => $uid,
          'session_token' => $session_token,
          'email_verified' => $email_verified,
          'scope' => $scope,
        ];
        return new \stdClass();
      }

    };

    $this->setSyncContainer($manager);

    \beantragt_applicant_application_insert($this->createApplicationWithOwnerEmail('owner@example.org', 'Owner@Example.Org'));
    \beantragt_applicant_application_insert($this->createApplicationWithOwnerEmail('victim@example.org', 'owner@example.org'));

    // The foreign address of a signed-in account never reaches the sync.
    $this->assertCount(1, $manager->calls);
    $this->assertTrue($manager->calls[0]['email_verified']);
  }

  /**
   * A signed-in account with a foreign, unproven address creates no profile.
   *
   * Mutation probes: the own address, a form without email field and an
   * anonymous application confirmed by link (service account owner) still
   * sync; only the unproven foreign address of a citizen account is dropped.
   */
  public function testSignedInForeignAddressNeverSyncs(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager);

    $foreign = $this->createApplicationWithOwnerEmail('victim@example.org', 'attacker@example.org', [], TRUE, TRUE, [
      'person.email' => 'victim@example.org',
      'person.full_name' => 'Vera Victim',
      'person.iban' => 'DE89370400440532013000',
    ]);
    \beantragt_applicant_application_insert($foreign);
    \beantragt_applicant_beantragt_application_confirmed($foreign);
    $this->assertSame([], $manager->calls);

    \beantragt_applicant_application_insert($this->createApplicationWithOwnerEmail('attacker@example.org', 'attacker@example.org'));
    $this->assertCount(1, $manager->calls);

    \beantragt_applicant_application_insert($this->createApplicationWithOwnerEmail('', 'attacker@example.org', [], TRUE, FALSE, [
      'person.full_name' => 'Ada Lovelace',
    ]));
    $this->assertCount(2, $manager->calls);
    $this->assertFalse($manager->calls[1]['email_verified']);

    $anonymous = $this->createApplicationWithOwnerEmail('victim@example.org', 'service@example.org', [], TRUE, FALSE, NULL, NULL, NULL, [
      'authenticated',
      'service_account',
    ]);
    \beantragt_applicant_beantragt_application_confirmed($anonymous);
    $this->assertCount(3, $manager->calls);
    $this->assertSame(['person.email' => 'victim@example.org'], $manager->calls[2]['crm_data']);
    $this->assertFalse($manager->calls[2]['email_verified']);
  }

  /**
   * A matching raw account address is not mailbox proof for profile syncing.
   */
  public function testUnverifiedMatchingAccountMailNeverSyncs(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager);
    $mail = $this->createMock(VerifiedAccountMail::class);
    $mail->expects($this->once())->method('getVerifiedEmail')->willReturn(NULL);
    \Drupal::getContainer()->set('beantragt_passwordless.verified_mail', $mail);
    \beantragt_applicant_application_insert($this->createApplicationWithOwnerEmail('citizen@example.test', 'citizen@example.test'));
    $this->assertSame([], $manager->calls);
  }

  /**
   * Confirmed lifecycle hook syncs applicant CRM profiles.
   */
  public function testApplicationConfirmedSyncsApplicantProfile(): void {
    $manager = new class() {

      /**
       * Captured calls.
       *
       * @var array<int, array<string, mixed>>
       */
      public array $calls = [];

      /**
       * Records applicant synchronization calls.
       */
      public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = []): object {
        $this->calls[] = [
          'crm_data' => $crm_data,
          'uid' => $uid,
          'session_token' => $session_token,
          'email_verified' => $email_verified,
          'scope' => $scope,
        ];
        return new \stdClass();
      }

    };

    $this->setSyncContainer($manager);

    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org'),
    );

    $this->assertCount(1, $manager->calls);
    $this->assertSame(['person.email' => 'owner@example.org'], $manager->calls[0]['crm_data']);
    $this->assertTrue($manager->calls[0]['email_verified']);
  }

  /**
   * Confirming an anonymous submission only syncs the recorded field lists.
   */
  public function testConfirmationLimitsSyncToAcceptedFields(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager, TRUE, TRUE);
    $scope = ['field_jurisdiction' => 3, 'field_organisation' => 4, 'field_group' => 4];
    $contact = self::proofRecord(1781431200) + [
      'fields' => ['person.email', 'person.full_name'],
      'profileScope' => $scope,
    ];
    $bank = self::proofRecord(1781431300) + ['fields' => ['person.iban']];
    $data = [
      'person.email' => 'citizen@example.org',
      'person.full_name' => 'Ada Lovelace',
      'person.phone' => '123',
      'person.iban' => 'DE89370400440532013000',
      'person.account_holder' => 'Other person',
    ];
    $application = $this->createApplicationWithOwnerEmail(
      'citizen@example.org',
      'service@example.org',
      $scope,
      TRUE,
      TRUE,
      $data,
      $contact,
      $bank,
      ['authenticated', 'service_account'],
      FALSE,
    );
    \beantragt_applicant_application_insert($application);
    \beantragt_applicant_beantragt_application_confirmed($application);
    $this->assertSame([], $manager->calls);

    $application = $this->createApplicationWithOwnerEmail(
      'citizen@example.org',
      'service@example.org',
      $scope,
      TRUE,
      TRUE,
      $data,
      $contact,
      $bank,
      ['authenticated', 'service_account'],
      TRUE,
    );
    \beantragt_applicant_beantragt_application_confirmed($application);
    $this->assertCount(1, $manager->calls);
    $this->assertSame(
      array_intersect_key($data, array_flip(['person.email', 'person.full_name', 'person.iban'])),
      $manager->calls[0]['crm_data'],
    );
  }

  /**
   * A forwarded application logs the scope mismatch and skips profile sync.
   */
  public function testConfirmationLogsScopeMismatchWithoutPersonalData(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('notice')->with(
      'Skipped applicant profile sync because the application scope no longer matches the consent scope.',
      [],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('beantragt_applicant')->willReturn($logger);
    \Drupal::getContainer()->set('logger.factory', $factory);

    $application = $this->createApplicationWithOwnerEmail(
      'citizen@example.test',
      'citizen@example.test',
      ['field_jurisdiction' => 3, 'field_organisation' => 5, 'field_group' => 5],
      profileRecord: self::proofRecord(1781431200) + [
        'fields' => ['person.email'],
        'profileScope' => ['field_jurisdiction' => 3, 'field_organisation' => 4, 'field_group' => 4],
      ],
    );
    \beantragt_applicant_beantragt_application_confirmed($application);
    $this->assertSame([], $manager->calls);
  }

  /**
   * Application insert passes application tenant scope to applicant sync.
   */
  public function testApplicationInsertPassesTenantScope(): void {
    $manager = new class() {

      /**
       * Captured scope.
       *
       * @var array<string, array<string, int>>
       */
      public array $scope = [];

      /**
       * Records applicant synchronization scope.
       */
      public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = []): object {
        $this->scope = $scope;
        return new \stdClass();
      }

    };

    $this->setSyncContainer($manager);

    \beantragt_applicant_application_insert($this->createApplicationWithOwnerEmail(
      'owner@example.org',
      'owner@example.org',
      [
        'field_jurisdiction' => 3,
        'field_organisation' => 4,
        'field_group' => 4,
      ],
    ));

    $this->assertSame(['target_id' => 3], $manager->scope['field_jurisdiction'] ?? NULL);
    $this->assertSame(['target_id' => 4], $manager->scope['field_organisation'] ?? NULL);
    $this->assertSame(['target_id' => 4], $manager->scope['field_group'] ?? NULL);
  }

  /**
   * Without the citizen's consent neither caller creates a profile.
   */
  public function testSyncSkipsApplicationsWithoutProfileConsent(): void {
    $manager = $this->recordingManager();
    $switch = $this->setSyncContainer($manager);
    $switch->expects($this->never())->method('isEnabledForApplication');

    $application = $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], FALSE, TRUE, [
      'person.email' => 'owner@example.org',
      'person.iban' => 'DE89370400440532013000',
    ]);
    \beantragt_applicant_application_insert($application);
    \beantragt_applicant_beantragt_application_confirmed($application);

    $this->assertSame([], $manager->calls);
  }

  /**
   * A consent flag without a complete proof record does not sync.
   */
  public function testSyncSkipsConsentWithoutCompleteProofRecord(): void {
    $manager = $this->recordingManager();
    $switch = $this->setSyncContainer($manager);
    $switch->expects($this->never())->method('isEnabledForApplication');

    $incomplete = self::proofRecord(1781431200);
    unset($incomplete['textHash']);
    foreach ([[], ['time' => 0] + self::proofRecord(1), $incomplete] as $record) {
      $application = $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], TRUE, FALSE, NULL, $record);
      \beantragt_applicant_application_insert($application);
      \beantragt_applicant_beantragt_application_confirmed($application);
    }

    $this->assertSame([], $manager->calls);
  }

  /**
   * A bank consent flag without a complete proof record drops bank details.
   */
  public function testBankConsentWithoutProofRecordDropsBankDetails(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager);

    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], TRUE, TRUE, [
        'person.email' => 'owner@example.org',
        'person.iban' => 'DE89370400440532013000',
        'person.account_holder' => 'Ada Lovelace',
      ], NULL, []),
    );

    $this->assertCount(1, $manager->calls);
    $this->assertSame(['person.email' => 'owner@example.org'], $manager->calls[0]['crm_data']);
    $this->assertArrayNotHasKey('bank', $manager->calls[0]['consent']);
  }

  /**
   * Without the bank switch of the jurisdiction bank details stay out.
   *
   * Mutation probe: dropping the bank switch check in the sync lets the IBAN
   * of an older bank consent reach the profile and fails this test.
   */
  public function testBankSwitchOffDropsBankDetails(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager, TRUE, FALSE);

    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], TRUE, TRUE, [
        'person.email' => 'owner@example.org',
        'person.iban' => 'DE89370400440532013000',
        'person.account_holder' => 'Ada Lovelace',
      ]),
    );

    $this->assertCount(1, $manager->calls);
    $this->assertSame(['person.email' => 'owner@example.org'], $manager->calls[0]['crm_data']);
    $this->assertArrayNotHasKey('bank', $manager->calls[0]['consent']);
  }

  /**
   * A jurisdiction that switched profiles off stops syncing older consents.
   */
  public function testSyncSkipsWhenJurisdictionSwitchIsOff(): void {
    $manager = $this->recordingManager();
    $switch = $this->setSyncContainer($manager, FALSE);
    $switch->expects($this->exactly(2))->method('isEnabledForApplication');

    $application = $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', ['field_jurisdiction' => 3]);
    \beantragt_applicant_application_insert($application);
    \beantragt_applicant_beantragt_application_confirmed($application);

    $this->assertSame([], $manager->calls);
  }

  /**
   * IBAN and account holder reach the profile only with bank consent.
   */
  public function testBankDetailsNeedBankConsent(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager);
    $crm_data = [
      'person.email' => 'owner@example.org',
      'person.full_name' => 'Ada Lovelace',
      'person.iban' => 'DE89370400440532013000',
      'person.account_holder' => 'Ada Lovelace',
    ];

    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], TRUE, FALSE, $crm_data),
    );
    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], TRUE, TRUE, $crm_data),
    );
    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org', [], TRUE, TRUE),
    );

    $this->assertCount(3, $manager->calls);
    $this->assertSame(['person.email' => 'owner@example.org'], $manager->calls[2]['crm_data']);
    $this->assertSame([
      'person.email' => 'owner@example.org',
      'person.full_name' => 'Ada Lovelace',
    ], $manager->calls[0]['crm_data']);
    $this->assertArrayNotHasKey('bank', $manager->calls[0]['consent']);
    $this->assertSame($crm_data, $manager->calls[1]['crm_data']);
    $this->assertSame([
      'time' => 1781431300,
      'textVersion' => '2026-10-20',
      'source' => 'b7a1c0de-0000-4000-8000-000000000001',
      'textHash' => str_repeat('a', 64),
      'locale' => 'de',
      'scopeLabel' => 'Bürgeramt Musterstadt',
      'text' => 'Meine Kontakt- und Adressdaten speichern.',
    ], $manager->calls[1]['consent']['bank']);
  }

  /**
   * The consent record becomes provenance for the applicant profile.
   */
  public function testSyncPassesContactConsentProvenance(): void {
    $manager = $this->recordingManager();
    $this->setSyncContainer($manager);

    \beantragt_applicant_beantragt_application_confirmed(
      $this->createApplicationWithOwnerEmail('owner@example.org', 'owner@example.org'),
    );

    $this->assertSame([
      'contact' => [
        'time' => 1781431200,
        'textVersion' => '2026-10-20',
        'source' => 'b7a1c0de-0000-4000-8000-000000000001',
        'textHash' => str_repeat('a', 64),
        'locale' => 'de',
        'scopeLabel' => 'Bürgeramt Musterstadt',
        'text' => 'Meine Kontakt- und Adressdaten speichern.',
      ],
    ], $manager->calls[0]['consent']);
  }

  /**
   * Applicant saves fail closed while administrative cleanup owns the scope.
   */
  public function testApplicantPresaveRejectsConcurrentScopeCleanup(): void {
    $email = $this->createMock(FieldItemListInterface::class);
    $email->method('isEmpty')->willReturn(FALSE);
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('hasField')->with('field_email')->willReturn(TRUE);
    $applicant->method('get')->with('field_email')->willReturn($email);
    $scope_lock = $this->createMock(ApplicantScopeMutationLock::class);
    $scope_lock->expects($this->once())
      ->method('hasTenantScopeForApplicant')
      ->with($applicant)
      ->willReturn(TRUE);
    $scope_lock->expects($this->once())
      ->method('hasLockableIdentityForApplicant')
      ->with($applicant)
      ->willReturn(TRUE);
    $scope_lock->expects($this->once())->method('acquireForApplicant')->willReturn(FALSE);
    $scope_lock->expects($this->never())->method('release');
    $container = new ContainerBuilder();
    $container->set('beantragt_application.applicant_scope_mutation_lock', $scope_lock);
    \Drupal::setContainer($container);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('The applicant scope lock is unavailable because another mutation is in progress.');

    \beantragt_applicant_applicant_presave($applicant);
  }

  /**
   * Applicant saves report a missing jurisdiction instead of lock contention.
   */
  public function testApplicantPresaveRejectsMissingJurisdictionScope(): void {
    $email = $this->createMock(FieldItemListInterface::class);
    $email->method('isEmpty')->willReturn(FALSE);
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('hasField')->with('field_email')->willReturn(TRUE);
    $applicant->method('get')->with('field_email')->willReturn($email);
    $scope_lock = $this->createMock(ApplicantScopeMutationLock::class);
    $scope_lock->expects($this->once())
      ->method('hasTenantScopeForApplicant')
      ->with($applicant)
      ->willReturn(FALSE);
    $scope_lock->expects($this->never())->method('hasLockableIdentityForApplicant');
    $scope_lock->expects($this->never())->method('acquireForApplicant');
    $scope_lock->expects($this->never())->method('release');
    $container = new ContainerBuilder();
    $container->set('beantragt_application.applicant_scope_mutation_lock', $scope_lock);
    \Drupal::setContainer($container);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('The applicant cannot be saved without a jurisdiction scope.');

    \beantragt_applicant_applicant_presave($applicant);
  }

  /**
   * Applicant deletion keeps the lock service's legacy no-scope behavior.
   */
  public function testApplicantPredeleteAllowsApplicantWithoutLockNames(): void {
    $applicant = $this->createMock(ApplicantInterface::class);
    $scope_lock = $this->createMock(ApplicantScopeMutationLock::class);
    $scope_lock->expects($this->once())
      ->method('acquireForApplicant')
      ->with($applicant)
      ->willReturn(TRUE);
    $scope_lock->expects($this->once())
      ->method('release')
      ->with($applicant);
    $container = new ContainerBuilder();
    $container->set('beantragt_application.applicant_scope_mutation_lock', $scope_lock);
    \Drupal::setContainer($container);

    \beantragt_applicant_applicant_predelete($applicant);

    $this->addToAssertionCount(1);
  }

  /**
   * Registers the applicant manager and a profile switch stub.
   *
   * @return \Drupal\beantragt_application\Service\ApplicantProfileSwitch&\PHPUnit\Framework\MockObject\MockObject
   *   The switch stub.
   */
  private function setSyncContainer(object $manager, bool $switchOn = TRUE, bool $bankSwitchOn = TRUE): ApplicantProfileSwitch {
    $switch = $this->createMock(ApplicantProfileSwitch::class);
    $switch->method('isEnabledForApplication')->willReturn($switchOn);
    $switch->method('isBankEnabledForApplication')->willReturn($switchOn && $bankSwitchOn);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $mail = $this->createMock(VerifiedAccountMail::class);
    $mail->method('getVerifiedEmail')->willReturnCallback(
      static fn ($account): string => strtolower(trim($account->getEmail()))
    );
    $container->set('beantragt_passwordless.verified_mail', $mail);
    $container->set('beantragt_applicant.manager', $manager);
    $container->set('beantragt_application.applicant_profile_switch', $switch);
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);
    return $switch;
  }

  /**
   * Creates an applicant manager double that records findOrCreate() calls.
   */
  private function recordingManager(): object {
    return new class() {

      /**
       * Captured calls.
       *
       * @var array<int, array<string, mixed>>
       */
      public array $calls = [];

      /**
       * Records applicant synchronization calls.
       */
      public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = [], array $consent = []): object {
        $this->calls[] = [
          'crm_data' => $crm_data,
          'email_verified' => $email_verified,
          'scope' => $scope,
          'consent' => $consent,
        ];
        return new \stdClass();
      }

    };
  }

  /**
   * Builds a complete consent proof record.
   *
   * @return array<string, mixed>
   *   Consent record as stored on the application.
   */
  private static function proofRecord(int $time): array {
    return [
      'time' => $time,
      'locale' => 'de',
      'textVersion' => '2026-10-20',
      'text' => 'Meine Kontakt- und Adressdaten speichern.',
      'textHash' => str_repeat('a', 64),
      'scope' => [
        'type' => 'organisation',
        'id' => 7,
        'uuid' => 'c0ffee00-0000-4000-8000-000000000007',
        'label' => 'Bürgeramt Musterstadt',
      ],
      'source' => 'public_submit',
    ];
  }

  /**
   * Creates an application mock with CRM data, owner email and consents.
   */
  private function createApplicationWithOwnerEmail(string $crmEmail, string $ownerEmail, array $scope = [], bool $profileConsent = TRUE, bool $bankConsent = FALSE, ?array $crmData = NULL, ?array $profileRecord = NULL, ?array $bankRecord = NULL, array $ownerRoles = ['authenticated'], bool $confirmed = TRUE): ApplicationInterface {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getRoles')->willReturn($ownerRoles);
    $owner->method('getEmail')->willReturn($ownerEmail);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn($confirmed);
    $application->method('getApplicantEmail')->willReturn($crmEmail);
    $application->method('uuid')->willReturn('b7a1c0de-0000-4000-8000-000000000001');
    $application->method('hasProfileConsent')->willReturn($profileConsent);
    $application->method('getProfileConsentRecord')->willReturn($profileRecord ?? ($profileConsent ? self::proofRecord(1781431200) : []));
    $application->method('hasBankConsent')->willReturn($bankConsent);
    $application->method('getBankConsentRecord')->willReturn($bankRecord ?? ($bankConsent ? self::proofRecord(1781431300) : []));
    $application->method('getApplicantCrmData')->willReturn($crmData ?? [
      'person.email' => $crmEmail,
    ]);
    $application->method('getOwnerId')->willReturn(123);
    $application->method('getOwner')->willReturn($owner);
    $application->method('hasField')->willReturnCallback(
      static fn(string $fieldName): bool => array_key_exists($fieldName, $scope)
    );
    $application->method('get')->willReturnCallback(
      fn(string $fieldName): FieldItemListInterface => $this->createTargetField((int) ($scope[$fieldName] ?? 0))
    );
    return $application;
  }

  /**
   * Creates an entity reference field item list mock.
   */
  private function createTargetField(int $targetId): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($targetId <= 0);
    $field->method('__isset')
      ->with('target_id')
      ->willReturn(TRUE);
    $field->method('__get')
      ->with('target_id')
      ->willReturn($targetId);
    return $field;
  }

}
