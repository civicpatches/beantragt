<?php

declare(strict_types=1);

namespace Drupal\beantragt_passwordless\Service;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for generating and validating OTP codes.
 */
class OtpService {

  /**
   * Default OTP code length.
   */
  const DEFAULT_CODE_LENGTH = 6;

  /**
   * Backward-compatible constant for existing tests and integrations.
   */
  const CODE_LENGTH = self::DEFAULT_CODE_LENGTH;

  /**
   * Pre-computed bcrypt hash for timing-safe dummy verification.
   *
   * Uses the explicit issue-code cost factor 12 to match
   * the timing of real password_verify() calls and prevent email
   * enumeration via response time differences.
   */
  private const DUMMY_HASH = '$2y$12$Swf5KJU7Onq1XGRI0n8hdeWHWEiR033nChaJ6yHLG7mpvl/xa32aG';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The applicant manager.
   *
   * @var \Drupal\beantragt_applicant\Service\ApplicantManager
   */
  protected ApplicantManager $applicantManager;

  /**
   * The session manager, when session cleanup is available.
   */
  protected ?SessionManagerInterface $sessionManager;

  /**
   * The verified mailbox provenance store.
   */
  protected ?VerifiedAccountMail $verifiedMail;

  /**
   * Constructs an OtpService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\beantragt_applicant\Service\ApplicantManager $applicant_manager
   *   The applicant manager.
   * @param \Drupal\Core\Session\SessionManagerInterface|null $session_manager
   *   The session manager, when session cleanup is available.
   * @param \Drupal\beantragt_passwordless\Service\VerifiedAccountMail|null $verified_mail
   *   The verified mailbox store; omitted only by non-verifying demo login.
   */
  public function __construct(
    Connection $database,
    MailManagerInterface $mail_manager,
    AccountProxyInterface $current_user,
    LoggerInterface $logger,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    ApplicantManager $applicant_manager,
    ?SessionManagerInterface $session_manager = NULL,
    ?VerifiedAccountMail $verified_mail = NULL,
  ) {
    $this->database = $database;
    $this->mailManager = $mail_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
    $this->applicantManager = $applicant_manager;
    $this->sessionManager = $session_manager;
    $this->verifiedMail = $verified_mail;
  }

  /**
   * Generate an OTP code.
   *
   * Uses random_int for cryptographically secure random number generation.
   *
   * @return string
   *   The OTP code.
   */
  protected function generateCode(): string {
    $length = $this->getCodeLength();
    $min = 10 ** ($length - 1);
    $max = (10 ** $length) - 1;
    return (string) random_int($min, $max);
  }

  /**
   * Gets the configured OTP code length.
   *
   * @return int
   *   The configured code length, constrained to 4-9 digits.
   */
  public function getCodeLength(): int {
    $config = $this->configFactory->get('beantragt_passwordless.settings');
    $length = (int) ($config->get('code_length') ?? self::DEFAULT_CODE_LENGTH);
    return max(4, min(9, $length));
  }

  /**
   * Request an OTP code for email authentication.
   *
   * Generates a new OTP code and sends it via email. The code is stored
   * as a bcrypt hash in the database for security.
   *
   * @param string $email
   *   The email address.
   * @param string $langcode
   *   The language code for the email.
   * @param string $jurisdiction_slug
   *   Optional jurisdiction slug for tenant mail branding.
   *
   * @return array
   *   Result array with status and message.
   */
  public function requestCode(string $email, string $langcode = '', string $jurisdiction_slug = ''): array {
    // Clean up expired codes first.
    $this->cleanupExpiredCodes();

    $config = $this->configFactory->get('beantragt_passwordless.settings');
    $code_lifetime = $config->get('code_lifetime') ?? 600;

    if (!$this->shouldIssueCode($email)) {
      $this->logger->notice('Passwordless code request suppressed for identity @identity because auto-registration is disabled.', [
        '@identity' => $this->emailLogIdentifier($email),
      ]);
      // Dummy bcrypt to equalize timing against the legitimate path that
      // calls password_hash() below. Prevents email enumeration via
      // response time differences.
      password_verify('000000', self::DUMMY_HASH);
      return [
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => $code_lifetime,
        'codeLength' => $this->getCodeLength(),
      ];
    }

    $code = $this->generateCode();
    $now = time();
    $expires = $now + $code_lifetime;

    // Hash the code before storing it. This prevents plaintext OTP
    // exposure if the database is compromised.
    $code_hash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
      $new_id = $this->database->insert('beantragt_passwordless_codes')
        ->fields([
          'email' => $email,
          'code' => $code_hash,
          'attempts' => 0,
          'created' => $now,
          'expires' => $expires,
          'verified' => 0,
        ])
        ->execute();

      // Insert first: an older racing request must not invalidate a newer code.
      $this->database->update('beantragt_passwordless_codes')
        ->fields(['verified' => 2])
        ->condition('email', $email)
        ->condition('verified', 0)
        ->condition('id', $new_id, '<')
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store OTP code: @class', [
        '@class' => $e::class,
      ]);
      return [
        'success' => FALSE,
        'error' => 'Failed to generate verification code',
      ];
    }

    // Send the plaintext code via email (user needs to read it).
    $sent = $this->sendCode($email, $code, $code_lifetime, $langcode, $jurisdiction_slug);

    if ($sent) {
      return [
        'success' => TRUE,
        'message' => 'Verification code sent to your email',
        'expiresIn' => $code_lifetime,
        'codeLength' => $this->getCodeLength(),
      ];
    }

    return [
      'success' => FALSE,
      'error' => 'Failed to send verification email',
    ];
  }

  /**
   * Returns TRUE when a request should generate and send an OTP code.
   */
  private function shouldIssueCode(string $email): bool {
    $config = $this->configFactory->get('beantragt_passwordless.settings');
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    if (!empty($users)) {
      return $this->hasActiveUser($users);
    }

    return (bool) $config->get('auto_register');
  }

  /**
   * Returns TRUE when at least one loaded user account is active.
   *
   * @param array<mixed> $users
   *   Loaded user entities.
   *
   * @return bool
   *   TRUE when an active account was found.
   */
  private function hasActiveUser(array $users): bool {
    foreach ($users as $user) {
      if ($user instanceof UserInterface && $user->isActive()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Verify an OTP code.
   *
   * The entire verification process runs inside a database transaction
   * to prevent race condition brute force attacks where parallel requests
   * could bypass the attempt counter.
   *
   * @param string $email
   *   The email address.
   * @param string $code
   *   The OTP code.
   *
   * @return array
   *   Result array with status, message, and optional user data.
   */
  public function verifyCode(string $email, string $code): array {
    $config = $this->configFactory->get('beantragt_passwordless.settings');
    $max_attempts = $config->get('max_attempts') ?? 3;

    // Wrap the entire verification in a transaction to prevent race
    // conditions where parallel requests could bypass attempt limits.
    $transaction = $this->database->startTransaction('otp_verify');

    try {
      // SQLite ignores SELECT FOR UPDATE. Never pretend that an unsupported
      // driver serializes guesses before the expensive code verification.
      if (!in_array($this->database->databaseType(), ['mysql', 'pgsql'], TRUE)) {
        throw new \RuntimeException('OTP verification requires database row locking.');
      }
      // Lock the identity's pending rows in one consistent order first.
      // The subsequent all-row counter update must not invert lock order
      // between workers selecting different records during a code request.
      $locking_query = $this->database->select('beantragt_passwordless_codes', 'c')
        ->fields('c', ['id'])
        ->condition('email', $email)
        ->condition('verified', 0)
        ->orderBy('id', 'ASC');
      $locking_query->forUpdate();
      $pending_ids = $locking_query->execute()->fetchCol();
      if ($pending_ids === []) {
        // Under REPEATABLE READ an empty lookup may hold a gap lock. Release
        // it before dummy bcrypt so unrelated code requests are not blocked.
        $transaction = NULL;
        $this->verifyCodeHash($code, self::DUMMY_HASH);
        return ['success' => FALSE, 'error' => 'Invalid verification code'];
      }
      $now = time();
      $eligible_query = $this->database->select('beantragt_passwordless_codes', 'c')
        ->fields('c')
        ->condition('email', $email)
        ->condition('verified', 0)
        ->condition('attempts', $max_attempts, '<')
        ->condition('expires', $now, '>=')
        ->orderBy('id', 'DESC')
        ->range(0, 1);
      $eligible_query->forUpdate();
      $record = $eligible_query->execute()->fetchObject();

      // One bcrypt operation on every path. The row lock precedes this call,
      // so concurrent guesses cannot all verify against an old attempt count.
      $matches = $this->verifyCodeHash($code, $record === FALSE ? self::DUMMY_HASH : $record->code);
      if ($record === FALSE) {
        return ['success' => FALSE, 'error' => 'Invalid verification code'];
      }
      if (!$matches) {
        $this->database->update('beantragt_passwordless_codes')
          ->expression('attempts', 'attempts + 1')
          ->condition('email', $email)
          ->condition('verified', 0)
          ->condition('attempts', $max_attempts, '<')
          ->execute();
        return ['success' => FALSE, 'error' => 'Invalid verification code'];
      }

      $reserved = $this->database->update('beantragt_passwordless_codes')
        ->expression('attempts', 'attempts + 1')
        ->fields(['verified' => 1])
        ->condition('id', $record->id)
        ->condition('verified', 0)
        ->condition('attempts', $max_attempts, '<')
        ->condition('expires', time(), '>=')
        ->execute();

      if ($reserved !== 1) {
        $this->logger->notice('OTP code claim failed for code id @id.', ['@id' => $record->id]);
        return ['success' => FALSE, 'error' => 'Invalid verification code'];
      }

      // Load or create the user. Finalize login only after post-auth work has
      // completed so a failed applicant update cannot leave a session behind.
      $user = $this->authenticateUser($email, FALSE);

      if ($user) {
        $this->assertApplicantEncryptionRuntimeAvailable();
        // Commit the OTP reservation and any new user before the session is
        // mutated. A commit failure must never leave an authenticated session.
        $this->commitAndCompleteAuthentication($transaction, $user);

        return [
          'success' => TRUE,
          'message' => 'Authentication successful',
          'user' => [
            'uid' => $user->id(),
            'uuid' => $user->uuid(),
            'name' => $user->getAccountName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'applicant_id' => NULL,
          ],
        ];
      }

      $failed_transaction = $transaction;
      $transaction = NULL;
      $failed_transaction->rollBack();
      return [
        'success' => FALSE,
        'error' => 'Failed to authenticate user',
      ];
    }
    catch (\Throwable $e) {
      if ($transaction !== NULL) {
        $transaction->rollBack();
      }
      $this->logger->error('OTP verification failed with exception: @class', [
        '@class' => $e::class,
      ]);
      throw $e;
    }
  }

  /**
   * Verifies a real or dummy hash with the same password algorithm.
   */
  protected function verifyCodeHash(string $code, string $hash): bool {
    return password_verify($code, $hash);
  }

  /**
   * Identifies an email in logs without retaining the address.
   */
  private function emailLogIdentifier(string $email): string {
    return hash_hmac('sha256', 'passwordless-email:' . strtolower(trim($email)), Settings::getHashSalt());
  }

  /**
   * Commits authentication state before creating a user session.
   *
   * The caller's transaction reference is cleared before destruction performs
   * the sole commit. An explicit commit followed by destructor cleanup can
   * mask a root commit failure with a second transaction exception.
   *
   * @param \Drupal\Core\Database\Transaction|null $transaction
   *   The active OTP transaction, cleared before commit is attempted.
   * @param \Drupal\user\Entity\User $user
   *   The authenticated user.
   */
  protected function commitAndCompleteAuthentication(?Transaction &$transaction, User $user): void {
    if ($transaction === NULL) {
      throw new \LogicException('The OTP transaction is unavailable.');
    }

    $pending_commit = $transaction;
    $transaction = NULL;
    unset($pending_commit);
    $this->completeAuthentication($user);
  }

  /**
   * Authenticate or create user and log them in.
   *
   * @param string $email
   *   The email address.
   * @param bool $finalize_login
   *   Whether to finalize the login session.
   *
   * @return \Drupal\user\Entity\User|null
   *   The user entity or NULL on failure.
   */
  protected function authenticateUser(string $email, bool $finalize_login = TRUE): ?User {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    if (!empty($users)) {
      /** @var \Drupal\user\Entity\User $user */
      $user = reset($users);
      if (!$user->isActive()) {
        $this->logger->notice('Login attempt for blocked user @uid.', [
          '@uid' => $user->id(),
        ]);
        return NULL;
      }
    }
    else {
      $config = $this->configFactory->get('beantragt_passwordless.settings');
      $auto_register = $config->get('auto_register') ?? FALSE;

      if (!$auto_register) {
        $this->logger->notice('Login attempt for unknown identity @identity. Auto-registration is disabled.', [
          '@identity' => $this->emailLogIdentifier($email),
        ]);
        return NULL;
      }

      try {
        $user = User::create([
          'name' => $email,
          'mail' => $email,
          'status' => 1,
          'roles' => ['authenticated'],
        ]);
        $user->save();

        $this->logger->info('Created new user account @uid', [
          '@uid' => $user->id(),
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to create user: @class', [
          '@class' => $e::class,
        ]);
        return NULL;
      }
    }

    if ($finalize_login) {
      $this->finalizeLogin($user);
    }

    return $user;
  }

  /**
   * Finalizes an authenticated session and takes over unclaimed profiles.
   *
   * Passwordless authentication has no tenant context, so it never creates
   * an applicant profile; creation stays lazy until a scoped application flow
   * is entered. The login proves control of the mailbox, so profiles created
   * from applications without sign-in under exactly this address and without
   * an owner become the account's own (see
   * ApplicantManager::claimUnclaimedByVerifiedEmail()). The takeover is a
   * secondary effect: a failure is logged and never blocks the login.
   *
   * @param \Drupal\user\Entity\User $user
   *   The authenticated user entity.
   */
  protected function completeAuthentication(User $user): void {
    $this->verifiedMail?->verify($user, (string) $user->getEmail(), 'passwordless_otp');
    $this->finalizeLogin($user);
    $this->claimUnclaimedApplicants($user);
  }

  /**
   * Takes over unclaimed applicant profiles with the proven email address.
   */
  protected function claimUnclaimedApplicants(User $user): void {
    try {
      if (BeantragtRoles::isServiceAccount($user)) {
        return;
      }
      $email = $this->verifiedMail?->getVerifiedEmail($user) ?? '';
      if ($email === '') {
        return;
      }
      $this->applicantManager->claimUnclaimedByVerifiedEmail((int) $user->id(), $email);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Applicant takeover after passwordless login failed for uid @uid: @class', [
        '@uid' => (int) $user->id(),
        '@class' => $exception::class,
      ]);
    }
  }

  /**
   * Verifies applicant encryption without persisting an unscoped profile.
   */
  protected function assertApplicantEncryptionRuntimeAvailable(): void {
    $applicant = $this->entityTypeManager
      ->getStorage('applicant')
      ->create(['status' => TRUE]);
    if (!$applicant instanceof ApplicantInterface) {
      throw new \UnexpectedValueException('Applicant storage created an invalid encryption probe.');
    }
    $applicant->assertFieldEncryptionRuntimeAvailable();
  }

  /**
   * Finalizes a Drupal user session after post-authentication checks pass.
   */
  protected function finalizeLogin(User $user): void {
    try {
      $this->performLoginFinalize($user);
    }
    catch (\Throwable $exception) {
      $this->clearFailedLogin();
      throw $exception;
    }
  }

  /**
   * Runs Drupal's login finalizer behind a testable boundary.
   */
  protected function performLoginFinalize(User $user): void {
    user_login_finalize($user);
  }

  /**
   * Clears account and session state after partial login finalization.
   */
  protected function clearFailedLogin(): void {
    try {
      $this->sessionManager?->destroy();
    }
    finally {
      $this->currentUser->setAccount(new AnonymousUserSession());
    }
  }

  /**
   * Send OTP code via email.
   *
   * @param string $email
   *   The email address.
   * @param string $code
   *   The OTP code (plaintext, for the email body).
   * @param int $code_lifetime
   *   The code lifetime in seconds.
   * @param string $langcode
   *   The language code for the email.
   * @param string $jurisdiction_slug
   *   Optional jurisdiction slug for tenant mail branding.
   *
   * @return bool
   *   TRUE if email was sent successfully.
   */
  protected function sendCode(
    string $email,
    string $code,
    int $code_lifetime,
    string $langcode = '',
    string $jurisdiction_slug = '',
  ): bool {
    // Determine language: explicit > current > default.
    if (empty($langcode)) {
      $langcode = $this->languageManager->getCurrentLanguage()->getId();
    }

    $params = [
      'code' => $code,
      'email' => $email,
      'expires_in' => (int) ($code_lifetime / 60),
      'platform_name' => $this->configFactory->get('system.site')->get('name') ?: 'beantragt.ai',
      'jurisdiction_slug' => $jurisdiction_slug,
    ];

    try {
      $result = $this->mailManager->mail(
        'beantragt_passwordless',
        'verification_code',
        $email,
        $langcode,
        $params,
        NULL,
        TRUE
      );

      return $result['result'] ?? FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send OTP email: @class', [
        '@class' => $e::class,
      ]);
      return FALSE;
    }
  }

  /**
   * Clean up expired OTP codes.
   *
   * Removes codes that have expired more than 1 hour ago.
   * This is called internally and also via cron (hook_cron).
   */
  public function cleanupExpiredCodes(): void {
    $this->database->delete('beantragt_passwordless_codes')
      ->condition('expires', time() - 3600, '<')
      ->execute();
  }

}
