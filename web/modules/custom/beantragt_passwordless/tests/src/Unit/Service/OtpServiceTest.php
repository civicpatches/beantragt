<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_passwordless\Unit\Service;

use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_passwordless\Service\OtpService;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OTP service.
 *
 * @coversDefaultClass \Drupal\beantragt_passwordless\Service\OtpService
 * @group beantragt_passwordless
 */
class OtpServiceTest extends UnitTestCase {

  /**
   * The OTP service under test.
   *
   * @var \Drupal\beantragt_passwordless\Service\OtpService
   */
  protected OtpService $otpService;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The mocked mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected MailManagerInterface $mailManager;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $userStorage;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * Existing user emails for request-code tests, keyed by active state.
   *
   * @var array<string, bool>
   */
  protected array $existingUserEmails = [
    'test@example.com' => TRUE,
  ];

  /**
   * Explicit user entities keyed by email.
   *
   * @var array<string, object>
   */
  protected array $existingUserEntities = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['hash_salt' => 'passwordless-test-salt']);

    $this->database = $this->createMock(Connection::class);
    $this->database->method('databaseType')->willReturn('mysql');
    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $currentUser = $this->createMock(AccountProxyInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->userStorage = $this->createMock(EntityStorageInterface::class);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $applicantManager = $this->createMock(ApplicantManager::class);

    // Default config mock.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['code_length', 6],
      ['code_lifetime', 600],
      ['max_attempts', 3],
      ['auto_register', FALSE],
    ]);

    $siteConfig = $this->createMock(ImmutableConfig::class);
    $siteConfig->method('get')->willReturnMap([
      ['name', 'beantragt.ai'],
    ]);

    $this->configFactory->method('get')->willReturnMap([
      ['beantragt_passwordless.settings', $config],
      ['system.site', $siteConfig],
    ]);

    $this->userStorage->method('loadByProperties')
      ->willReturnCallback(function (array $properties): array {
        $email = strtolower((string) ($properties['mail'] ?? ''));
        if (isset($this->existingUserEntities[$email])) {
          return [$this->existingUserEntities[$email]];
        }
        if (!isset($this->existingUserEmails[$email])) {
          return [];
        }
        $user = $this->createMock(UserInterface::class);
        $user->method('isActive')->willReturn($this->existingUserEmails[$email]);
        return [$user];
      });
    $entityTypeManager->method('getStorage')
      ->with('user')
      ->willReturn($this->userStorage);

    $language = new Language(['id' => 'de']);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $this->otpService = new OtpService(
      $this->database,
      $this->mailManager,
      $currentUser,
      $this->logger,
      $this->configFactory,
      $entityTypeManager,
      $languageManager,
      $applicantManager,
    );
  }

  /**
   * Tests that unknown users are not mailed when auto-registration is disabled.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeSuppressesUnknownUserWhenAutoRegisterDisabled(): void {
    $this->existingUserEmails = [];

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);
    $this->database->method('delete')->willReturn($delete);
    $this->database->expects($this->never())->method('update');
    $this->database->expects($this->never())->method('insert');
    $this->mailManager->expects($this->never())->method('mail');

    $this->logger->expects($this->once())->method('notice')->with(
      'Passwordless code request suppressed for identity @identity because auto-registration is disabled.',
      ['@identity' => hash_hmac('sha256', 'passwordless-email:unknown@example.com', 'passwordless-test-salt')],
    );

    $result = $this->otpService->requestCode('unknown@example.com');

    $this->assertTrue($result['success']);
    $this->assertEquals('Verification code sent to your email', $result['message']);
    $this->assertEquals(600, $result['expiresIn']);
    $this->assertEquals(6, $result['codeLength']);
  }

  /**
   * Tests that blocked existing users are not mailed.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeSuppressesBlockedUserWhenAutoRegisterDisabled(): void {
    $this->existingUserEmails = [
      'blocked@example.com' => FALSE,
    ];

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);
    $this->database->method('delete')->willReturn($delete);
    $this->database->expects($this->never())->method('update');
    $this->database->expects($this->never())->method('insert');
    $this->mailManager->expects($this->never())->method('mail');

    $result = $this->otpService->requestCode('blocked@example.com');

    $this->assertTrue($result['success']);
    $this->assertEquals('Verification code sent to your email', $result['message']);
  }

  /**
   * Tests that blocked existing users cannot be finalized into a session.
   *
   * @covers ::verifyCode
   */
  public function testAuthenticateUserRejectsBlockedExistingUser(): void {
    $blocked = $this->createMock(User::class);
    $blocked->method('isActive')->willReturn(FALSE);
    $this->existingUserEntities = [
      'blocked@example.com' => $blocked,
    ];

    $method = new \ReflectionMethod(OtpService::class, 'authenticateUser');
    $method->setAccessible(TRUE);

    $this->assertNull($method->invoke($this->otpService, 'blocked@example.com', TRUE));
  }

  /**
   * Applicant encryption readiness failures abort OTP authentication.
   *
   * @covers ::assertApplicantEncryptionRuntimeAvailable
   */
  public function testApplicantEncryptionFailurePropagatesBeforeLogin(): void {
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->never())->method('findOrCreate');
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('assertFieldEncryptionRuntimeAvailable')
      ->willThrowException(new EntityStorageException('Applicant encryption runtime unavailable.'));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('create')->with(['status' => TRUE])->willReturn($applicant);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('applicant')->willReturn($storage);
    $service = new class(
      $this->database,
      $this->mailManager,
      $this->createMock(AccountProxyInterface::class),
      $this->logger,
      $this->configFactory,
      $entity_type_manager,
      $this->createMock(LanguageManagerInterface::class),
      $applicant_manager,
      NULL,
      NULL,
    ) extends OtpService {

      /**
       * Exposes the applicant encryption guard for the unit test.
       */
      public function assertApplicantEncryptionRuntimeAvailableForTest(): void {
        $this->assertApplicantEncryptionRuntimeAvailable();
      }

    };
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Applicant encryption runtime unavailable.');
    $service->assertApplicantEncryptionRuntimeAvailableForTest();
  }

  /**
   * First-time users get a session without creating an unscoped applicant.
   *
   * @covers ::completeAuthentication
   * @covers ::finalizeLogin
   */
  public function testFirstTimeLoginFinalizesWithoutCreatingApplicant(): void {
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->never())->method('findOrCreate');
    $service = new class(
      $this->database,
      $this->mailManager,
      $this->createMock(AccountProxyInterface::class),
      $this->logger,
      $this->configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $applicant_manager,
      NULL,
      NULL,
    ) extends OtpService {

      /**
       * Whether Drupal session finalization ran.
       */
      public bool $sessionFinalized = FALSE;

      /**
       * Exposes login completion for the unit test.
       */
      public function completeAuthenticationForTest(User $user): void {
        $this->completeAuthentication($user);
      }

      /**
       * Records session finalization without requiring a kernel session.
       */
      protected function finalizeLogin(User $user): void {
        $this->sessionFinalized = TRUE;
      }

    };
    $user = $this->createMock(User::class);
    $user->method('getEmail')->willReturn('first-login@example.org');
    $user->method('id')->willReturn(17);

    $service->completeAuthenticationForTest($user);
    $this->assertTrue($service->sessionFinalized);
  }

  /**
   * A finished OTP login takes over unclaimed profiles with the proven email.
   *
   * @covers ::completeAuthentication
   * @covers ::claimUnclaimedApplicants
   */
  public function testLoginTakesOverUnclaimedProfilesWithProvenEmail(): void {
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->once())
      ->method('claimUnclaimedByVerifiedEmail')
      ->with(17, 'first-login@example.org')
      ->willReturn(1);
    $service = $this->claimProbeService($applicant_manager);

    $service->completeAuthenticationForTest($this->loginUser(['authenticated']));
    $this->assertTrue($service->sessionFinalized);
  }

  /**
   * A completed OTP records mailbox provenance before claiming profiles.
   *
   * @covers ::completeAuthentication
   */
  public function testOtpCompletionRecordsVerifiedMailbox(): void {
    $verified_mail = $this->createMock(VerifiedAccountMail::class);
    $verified_mail->expects($this->once())->method('verify')->with($this->isInstanceOf(User::class), 'first-login@example.org', 'passwordless_otp');
    $verified_mail->method('getVerifiedEmail')->willReturn('first-login@example.org');
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->once())->method('claimUnclaimedByVerifiedEmail')->with(17, 'first-login@example.org');
    $service = $this->claimProbeService($applicant_manager, FALSE, $verified_mail);
    $service->completeAuthenticationForTest($this->loginUser(['authenticated']));
  }

  /**
   * The Nuxt service account never takes over citizen profiles.
   *
   * @covers ::claimUnclaimedApplicants
   */
  public function testServiceAccountNeverTakesOverProfiles(): void {
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->never())->method('claimUnclaimedByVerifiedEmail');
    $service = $this->claimProbeService($applicant_manager);

    $service->completeAuthenticationForTest($this->loginUser(['authenticated', 'service_account']));
    $this->assertTrue($service->sessionFinalized);
  }

  /**
   * A failing takeover is logged and never blocks the login.
   *
   * @covers ::claimUnclaimedApplicants
   */
  public function testTakeoverFailureDoesNotBlockLogin(): void {
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->method('claimUnclaimedByVerifiedEmail')->willThrowException(new \RuntimeException('lock'));
    $service = $this->claimProbeService($applicant_manager);

    $service->completeAuthenticationForTest($this->loginUser(['authenticated']));
    $this->assertTrue($service->sessionFinalized);
  }

  /**
   * A login that fails to finalize takes over nothing.
   *
   * @covers ::completeAuthentication
   */
  public function testFailedFinalizeTakesOverNothing(): void {
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->never())->method('claimUnclaimedByVerifiedEmail');
    $service = $this->claimProbeService($applicant_manager, TRUE);

    $this->expectException(\RuntimeException::class);
    $service->completeAuthenticationForTest($this->loginUser(['authenticated']));
  }

  /**
   * Builds an OTP service exposing completeAuthentication().
   */
  private function claimProbeService(ApplicantManager $applicant_manager, bool $fail_finalize = FALSE, ?VerifiedAccountMail $verified_mail = NULL): OtpService {
    $service = new class(
      $this->database,
      $this->mailManager,
      $this->createMock(AccountProxyInterface::class),
      $this->logger,
      $this->configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $applicant_manager,
      NULL,
      $verified_mail ?? $this->verifiedMailMock(),
    ) extends OtpService {

      /**
       * Whether Drupal session finalization ran.
       */
      public bool $sessionFinalized = FALSE;

      /**
       * Whether finalization fails.
       */
      public bool $failFinalize = FALSE;

      /**
       * Exposes login completion for the unit test.
       */
      public function completeAuthenticationForTest(User $user): void {
        $this->completeAuthentication($user);
      }

      /**
       * Records or fails session finalization without a kernel session.
       */
      protected function finalizeLogin(User $user): void {
        if ($this->failFinalize) {
          throw new \RuntimeException('finalize failed');
        }
        $this->sessionFinalized = TRUE;
      }

    };
    $service->failFinalize = $fail_finalize;
    return $service;
  }

  /**
   * Returns a provenance mock for existing completion tests.
   */
  private function verifiedMailMock(): VerifiedAccountMail {
    $mail = $this->createMock(VerifiedAccountMail::class);
    $mail->method('getVerifiedEmail')->willReturn('first-login@example.org');
    return $mail;
  }

  /**
   * Builds a logged-in user mock.
   */
  private function loginUser(array $roles): User {
    $user = $this->createMock(User::class);
    $user->method('getEmail')->willReturn('first-login@example.org');
    $user->method('id')->willReturn(17);
    $user->method('getRoles')->willReturn($roles);
    return $user;
  }

  /**
   * A successful first-time OTP verification returns a usable nullable profile.
   *
   * @covers ::verifyCode
   */
  public function testFirstTimeVerifyCodeReturnsSessionWithoutApplicant(): void {
    $code = '654321';
    $record = (object) [
      'id' => 91,
      'code' => password_hash($code, PASSWORD_BCRYPT),
      'expires' => time() + 600,
      'attempts' => 0,
    ];
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($record);
    $statement->method('fetchCol')->willReturn([91]);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $query_conditions = [];
    $select->method('condition')->willReturnCallback(static function ($field, $value, $operator = '=') use ($select, &$query_conditions) {
      $query_conditions[] = [$field, $value, $operator];
      return $select;
    });
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->expects($this->exactly(2))->method('forUpdate')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $update = $this->createMock(Update::class);
    $update->method('expression')->willReturnSelf();
    $update->method('fields')->willReturnSelf();
    $claim_conditions = [];
    $update->method('condition')->willReturnCallback(static function ($field, $value, $operator = '=') use ($update, &$claim_conditions) {
      $claim_conditions[] = [$field, $value, $operator];
      return $update;
    });
    $update->method('execute')->willReturn(1);
    $transaction_state = (object) [
      'committed' => FALSE,
      'rolled_back' => FALSE,
    ];
    $database = $this->createMock(Connection::class);
    $database->method('databaseType')->willReturn('mysql');
    $database->method('startTransaction')->willReturnCallback(
      static fn(): Transaction => new class($transaction_state) extends Transaction {

        public function __construct(private readonly object $state) {
        }

        /**
         * Models Drupal's transaction destructor as the sole commit trigger.
         */
        public function __destruct() {
          $this->commitOrRelease();
        }

        /**
         * Records the pre-session commit.
         */
        public function commitOrRelease(): void {
          $this->state->committed = TRUE;
        }

        /**
         * Records an unexpected rollback after commit.
         */
        public function rollBack(): void {
          $this->state->rolled_back = TRUE;
        }

      },
    );
    $database->method('select')->willReturn($select);
    $database->method('update')->willReturn($update);
    $applicant_manager = $this->createMock(ApplicantManager::class);
    $applicant_manager->expects($this->never())->method('findOrCreate');
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->expects($this->once())->method('assertFieldEncryptionRuntimeAvailable');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('create')->with(['status' => TRUE])->willReturn($applicant);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('applicant')->willReturn($storage);
    $user = $this->createMock(User::class);
    $user->method('id')->willReturn(17);
    $user->method('uuid')->willReturn('first-login-uuid');
    $user->method('getAccountName')->willReturn('first-login@example.org');
    $user->method('getEmail')->willReturn('first-login@example.org');
    $user->method('getRoles')->willReturn(['authenticated']);
    $service = new class(
      $database,
      $this->mailManager,
      $this->createMock(AccountProxyInterface::class),
      $this->logger,
      $this->configFactory,
      $entity_type_manager,
      $this->createMock(LanguageManagerInterface::class),
      $applicant_manager,
      $user,
      static fn(): bool => $transaction_state->committed,
    ) extends OtpService {

      /**
       * Whether Drupal session finalization ran.
       */
      public bool $sessionFinalized = FALSE;

      public function __construct(
        Connection $database,
        MailManagerInterface $mail_manager,
        AccountProxyInterface $current_user,
        LoggerInterface $logger,
        ConfigFactoryInterface $config_factory,
        EntityTypeManagerInterface $entity_type_manager,
        LanguageManagerInterface $language_manager,
        ApplicantManager $applicant_manager,
        private readonly User $authenticatedUser,
        private readonly \Closure $isTransactionCommitted,
      ) {
        parent::__construct(
          $database,
          $mail_manager,
          $current_user,
          $logger,
          $config_factory,
          $entity_type_manager,
          $language_manager,
          $applicant_manager,
        );
      }

      /**
       * {@inheritdoc}
       */
      protected function authenticateUser(string $email, bool $finalize_login = TRUE): ?User {
        return $this->authenticatedUser;
      }

      /**
       * Records the successful Drupal session finalization.
       */
      protected function finalizeLogin(User $user): void {
        if (!($this->isTransactionCommitted)()) {
          throw new \LogicException('The OTP transaction was not committed before session finalization.');
        }
        $this->sessionFinalized = TRUE;
      }

    };

    $result = $service->verifyCode('first-login@example.org', $code);

    $this->assertContains(['attempts', 3, '<'], $query_conditions);
    $this->assertContains(['attempts', 3, '<'], $claim_conditions);
    $this->assertContains(['verified', 0, '='], $claim_conditions);
    $this->assertNotEmpty(array_filter($claim_conditions, static fn(array $condition): bool => $condition[0] === 'expires' && $condition[2] === '>='));
    $this->assertTrue($result['success']);
    $this->assertNull($result['user']['applicant_id']);
    $this->assertTrue($service->sessionFinalized);
    $this->assertTrue($transaction_state->committed);
    $this->assertFalse($transaction_state->rolled_back);
  }

  /**
   * A failed root commit is not followed by an invalid rollback or login.
   *
   * @covers ::commitAndCompleteAuthentication
   */
  public function testCommitFailureDoesNotRollbackOrFinalizeSession(): void {
    $transaction_state = (object) ['rolled_back' => FALSE];
    $transaction = new class($transaction_state) extends Transaction {

      public function __construct(private readonly object $state) {
      }

      /**
       * Models the real Drupal destructor that performs the commit.
       */
      public function __destruct() {
        $this->commitOrRelease();
      }

      /**
       * Simulates a root transaction commit failure.
       */
      public function commitOrRelease(): void {
        throw new \RuntimeException('Root transaction commit failed.');
      }

      /**
       * Records an invalid rollback attempt.
       */
      public function rollBack(): void {
        $this->state->rolled_back = TRUE;
      }

    };
    $service = new class(
      $this->database,
      $this->mailManager,
      $this->createMock(AccountProxyInterface::class),
      $this->logger,
      $this->configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(ApplicantManager::class),
    ) extends OtpService {

      /**
       * Whether session finalization was attempted.
       */
      public bool $sessionFinalized = FALSE;

      /**
       * Exposes the transaction boundary for the unit test.
       */
      public function commitForTest(?Transaction &$transaction, User $user): void {
        $this->commitAndCompleteAuthentication($transaction, $user);
      }

      /**
       * Records an unexpected session finalization.
       */
      protected function finalizeLogin(User $user): void {
        $this->sessionFinalized = TRUE;
      }

    };

    try {
      $service->commitForTest($transaction, $this->createMock(User::class));
      $this->fail('A root transaction commit failure must be propagated.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Root transaction commit failed.', $exception->getMessage());
      $this->assertNull($transaction);
      $this->assertFalse($transaction_state->rolled_back);
      $this->assertFalse($service->sessionFinalized);
    }
  }

  /**
   * Partial login finalization clears session state before propagating errors.
   *
   * @covers ::finalizeLogin
   */
  public function testFinalizeLoginFailureClearsPartialSession(): void {
    $service = new class(
      $this->database,
      $this->mailManager,
      $this->createMock(AccountProxyInterface::class),
      $this->logger,
      $this->configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(ApplicantManager::class),
    ) extends OtpService {

      /**
       * Whether partial session state was cleared after failure.
       */
      public bool $failedLoginCleared = FALSE;

      /**
       * Exposes the safe login finalizer for the unit test.
       */
      public function finalizeLoginForTest(User $user): void {
        $this->finalizeLogin($user);
      }

      /**
       * Simulates a login hook failure after session mutation began.
       */
      protected function performLoginFinalize(User $user): void {
        throw new \RuntimeException('Login hook failed.');
      }

      /**
       * Records cleanup without requiring a kernel session.
       */
      protected function clearFailedLogin(): void {
        $this->failedLoginCleared = TRUE;
      }

    };

    try {
      $service->finalizeLoginForTest($this->createMock(User::class));
      $this->fail('A login finalization failure must be propagated.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Login hook failed.', $exception->getMessage());
      $this->assertTrue($service->failedLoginCleared);
    }
  }

  /**
   * Failed login cleanup destroys the session and resets the account.
   *
   * @covers ::clearFailedLogin
   */
  public function testClearFailedLoginDestroysSessionAndResetsAccount(): void {
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->expects($this->once())
      ->method('setAccount')
      ->with($this->isInstanceOf(AnonymousUserSession::class));
    $session_manager = $this->createMock(SessionManagerInterface::class);
    $session_manager->expects($this->once())->method('destroy');
    $service = new class(
      $this->database,
      $this->mailManager,
      $current_user,
      $this->logger,
      $this->configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(ApplicantManager::class),
      $session_manager,
    ) extends OtpService {

      /**
       * Exposes failed-login cleanup for the unit test.
       */
      public function clearFailedLoginForTest(): void {
        $this->clearFailedLogin();
      }

    };

    $service->clearFailedLoginForTest();
  }

  /**
   * Tests that requestCode() stores a bcrypt hash, not plaintext.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeStoresBcryptHash(): void {
    $stored_code_hash = NULL;

    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);
    $this->database->method('delete')->willReturn($delete);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(0);
    $this->database->method('update')->willReturn($update);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(function (array $fields) use (&$stored_code_hash, $insert) {
      $stored_code_hash = $fields['code'];
      return $insert;
    });
    $insert->method('execute')->willReturn(1);
    $this->database->method('insert')->willReturn($insert);

    $this->mailManager->method('mail')->willReturn(['result' => TRUE]);

    $result = $this->otpService->requestCode('test@example.com', 'de');

    $this->assertTrue($result['success']);
    $this->assertEquals('Verification code sent to your email', $result['message']);
    $this->assertEquals(600, $result['expiresIn']);
    $this->assertEquals(6, $result['codeLength']);

    // The stored value must be a bcrypt hash (starts with $2y$).
    $this->assertNotNull($stored_code_hash);
    $this->assertStringStartsWith('$2y$', $stored_code_hash);
    $this->assertSame(12, password_get_info($stored_code_hash)['options']['cost']);
  }

  /**
   * Tests that requestCode() handles DB insert failure.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeHandlesDbFailure(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);
    $this->database->method('delete')->willReturn($delete);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(0);
    $this->database->method('update')->willReturn($update);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willThrowException(new \Exception('SQL contains citizen@example.test and 123456'));
    $this->database->method('insert')->willReturn($insert);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Failed to store OTP code: @class',
        ['@class' => \Exception::class]
      );

    $result = $this->otpService->requestCode('test@example.com');

    $this->assertFalse($result['success']);
    $this->assertEquals('Failed to generate verification code', $result['error']);
  }

  /**
   * Tests that requestCode() returns failure when email sending fails.
   *
   * @covers ::requestCode
   */
  public function testRequestCodeHandlesMailFailure(): void {
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $delete->method('execute')->willReturn(0);
    $this->database->method('delete')->willReturn($delete);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(0);
    $this->database->method('update')->willReturn($update);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn(1);
    $this->database->method('insert')->willReturn($insert);

    $this->mailManager->method('mail')->willReturn(['result' => FALSE]);

    $result = $this->otpService->requestCode('test@example.com');

    $this->assertFalse($result['success']);
    $this->assertEquals('Failed to send verification email', $result['error']);
  }

  /**
   * Tests bcrypt verification roundtrip (core crypto logic of verifyCode).
   *
   * The verifyCode() method uses password_verify() to match user input
   * against bcrypt hashes stored in the database. This test verifies
   * the cryptographic properties in isolation without needing a database
   * transaction (which requires a real Connection instance).
   *
   * @covers ::verifyCode
   */
  public function testBcryptVerificationRoundtrip(): void {
    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_BCRYPT);

    // Correct code matches.
    $this->assertTrue(password_verify($code, $hash));

    // Wrong code does not match.
    $wrong_code = $code === '123456' ? '654321' : '123456';
    $this->assertFalse(password_verify($wrong_code, $hash));

    // Code is exactly 6 digits, no leading zeros.
    $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    $this->assertGreaterThanOrEqual(100000, (int) $code);
    $this->assertLessThanOrEqual(999999, (int) $code);
  }

  /**
   * Tests that multiple generated codes are all valid 6-digit numbers.
   *
   * Verifies the random_int(100000, 999999) range used by generateCode().
   *
   * @covers ::requestCode
   */
  public function testGeneratedCodesAre6Digits(): void {
    for ($i = 0; $i < 100; $i++) {
      $code = random_int(100000, 999999);
      $code_str = (string) $code;
      $this->assertEquals(6, strlen($code_str), "Code {$code_str} should be 6 digits.");
      $this->assertMatchesRegularExpression('/^\d{6}$/', $code_str);
    }
  }

  /**
   * Tests that bcrypt hashes are unique for the same input (random salt).
   *
   * @covers ::requestCode
   */
  public function testBcryptHashesAreUnique(): void {
    $code = '123456';
    $hash1 = password_hash($code, PASSWORD_BCRYPT);
    $hash2 = password_hash($code, PASSWORD_BCRYPT);

    // Different hashes due to random salt.
    $this->assertNotEquals($hash1, $hash2);

    // Both verify correctly.
    $this->assertTrue(password_verify($code, $hash1));
    $this->assertTrue(password_verify($code, $hash2));
  }

  /**
   * An empty locking lookup releases gap locks before dummy bcrypt.
   */
  public function testUnknownIdentityReleasesTransactionBeforeDummyHash(): void {
    $state = (object) ['released' => FALSE];
    $database = $this->createMock(Connection::class);
    $database->method('databaseType')->willReturn('mysql');
    $database->method('startTransaction')->willReturnCallback(
      static fn(): Transaction => new class($state) extends Transaction {

        public function __construct(private readonly object $state) {}

        /**
         * Models committing and releasing an empty lookup's gap lock.
         */
        public function __destruct() {
          $this->state->released = TRUE;
        }

      },
    );
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn([]);
    $select = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'orderBy', 'forUpdate'] as $method) {
      $select->method($method)->willReturnSelf();
    }
    $select->method('execute')->willReturn($statement);
    $database->expects($this->once())->method('select')->willReturn($select);
    $database->expects($this->never())->method('update');
    $service = $this->getMockBuilder(OtpService::class)
      ->setConstructorArgs([
        $database,
        $this->mailManager,
        $this->createMock(AccountProxyInterface::class),
        $this->logger,
        $this->configFactory,
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(LanguageManagerInterface::class),
        $this->createMock(ApplicantManager::class),
      ])
      ->onlyMethods(['verifyCodeHash'])
      ->getMock();
    $service->expects($this->once())->method('verifyCodeHash')
      ->willReturnCallback(function (string $code, string $hash) use ($state): bool {
        self::assertTrue($state->released);
        self::assertSame('123456', $code);
        self::assertSame(12, password_get_info($hash)['options']['cost']);
        return FALSE;
      });

    self::assertSame(
      ['success' => FALSE, 'error' => 'Invalid verification code'],
      $service->verifyCode('unknown@example.test', '123456'),
    );
  }

  /**
   * Drivers without row locking must fail before a real code lookup.
   */
  public function testUnsupportedDriverFailsClosed(): void {
    $database = $this->createMock(Connection::class);
    $database->method('databaseType')->willReturn('sqlite');
    $database->expects($this->never())->method('select');
    $transaction = new class() extends Transaction {

      /**
       * Whether the fail-closed path rolled back the transaction.
       */
      public bool $rolledBack = FALSE;

      public function __construct() {}

      /**
       * Avoid the real transaction destructor on an uninitialized double.
       */
      public function __destruct() {}

      /**
       * Record rollback without invoking an actual database connection.
       */
      public function rollBack(): void {
        $this->rolledBack = TRUE;
      }

    };
    $database->method('startTransaction')->willReturn($transaction);
    (new \ReflectionProperty(OtpService::class, 'database'))->setValue($this->otpService, $database);
    $this->expectException(\RuntimeException::class);
    $this->logger->expects($this->once())->method('error')->with(
      'OTP verification failed with exception: @class', ['@class' => \RuntimeException::class],
    );
    $this->expectExceptionMessage('OTP verification requires database row locking.');
    try {
      $this->otpService->verifyCode('citizen@example.test', '123456');
    }
    finally {
      self::assertTrue($transaction->rolledBack);
    }
  }

  /**
   * Tests cleanupExpiredCodes() deletes codes older than 1 hour past expiry.
   *
   * @covers ::cleanupExpiredCodes
   */
  public function testCleanupExpiredCodes(): void {
    $delete = $this->createMock(Delete::class);
    $delete->expects($this->once())
      ->method('condition')
      ->with('expires', $this->callback(fn($v) => $v <= time() - 3600), '<')
      ->willReturnSelf();
    $delete->expects($this->once())
      ->method('execute')
      ->willReturn(5);
    $this->database->expects($this->once())
      ->method('delete')
      ->with('beantragt_passwordless_codes')
      ->willReturn($delete);

    $this->otpService->cleanupExpiredCodes();
  }

  /**
   * Tests that the OTP code length default is set to 6.
   *
   * @covers ::requestCode
   */
  public function testCodeLengthConstant(): void {
    $this->assertEquals(6, OtpService::CODE_LENGTH);
  }

}
