<?php

declare(strict_types=1);

namespace Drupal\beantragt_maintenance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Issues isolated maintenance recovery codes and one-time recovery tokens.
 */
final class BreakGlassOtpService implements BreakGlassOtpServiceInterface, EventSubscriberInterface {

  /**
   * State key for beantragt's explicit platform-maintenance switch.
   */
  public const PLATFORM_MAINTENANCE_STATE = 'beantragt_maintenance.platform_maintenance';

  /**
   * Dedicated expirable key-value collection for recovery codes.
   */
  public const CODE_COLLECTION = 'beantragt_maintenance_break_glass_codes';

  /**
   * Dedicated expirable key-value collection for recovery tokens.
   */
  public const TOKEN_COLLECTION = 'beantragt_maintenance_recovery_tokens';

  /**
   * Pre-computed bcrypt hash for timing-safe dummy verification.
   */
  public const DUMMY_HASH = '$2y$12$Swf5KJU7Onq1XGRI0n8hdeWHWEiR033nChaJ6yHLG7mpvl/xa32aG';

  /**
   * The Core permission required for maintenance recovery.
   */
  private const string MAINTENANCE_ACCESS_PERMISSION = 'access site in maintenance mode';

  /**
   * Lifetime of a recovery token in seconds.
   */
  private const int TOKEN_LIFETIME = 300;

  /**
   * Total wrong guesses allowed per code across all clients.
   */
  private const int MAX_CODE_ATTEMPTS = 25;

  /**
   * Generic text returned for every valid recovery-code request.
   */
  private const string GENERIC_REQUEST_MESSAGE = 'If the account is eligible, a recovery code has been sent.';

  /**
   * Recovery mails held in memory until the response is sent.
   *
   * @var list<array{user: \Drupal\user\UserInterface, code: string, lifetime: int, code_hash: string}>
   */
  private array $pendingMails = [];

  /**
   * Constructs the break-glass OTP service.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly LockBackendInterface $lock,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RecoveryCryptoInterface $crypto,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @phpstan-impure
   */
  public function isAvailable(): bool {
    return (bool) $this->state->get(self::PLATFORM_MAINTENANCE_STATE, FALSE)
      || (bool) $this->state->get('system.maintenance_mode', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function requestCode(string $email): array {
    $lifetime = $this->codeLifetime();
    $result = [
      'success' => TRUE,
      'message' => self::GENERIC_REQUEST_MESSAGE,
      'expiresIn' => $lifetime,
      'codeLength' => 6,
    ];

    if (!$this->isAvailable()) {
      $this->performDummyRequestWork();
      return $result;
    }

    $user = $this->loadEligibleUserByEmail($email);
    if ($user === NULL) {
      $this->performDummyRequestWork();
      return $result;
    }

    $key = $this->emailKey($email);
    $lock_name = self::CODE_COLLECTION . ':' . $key;
    if (!$this->lock->acquire($lock_name, 10.0)) {
      $this->performDummyRequestWork();
      return $result;
    }

    try {
      $store = $this->codeStore();
      $existing = $store->get($key);
      if ($this->isAvailable() && $this->isEligible($user)
        && (!$this->isCodeRecord($existing) || $existing['expires'] <= $this->time->getCurrentTime()
          || $store->get('mail_failed:' . $existing['code']) === TRUE)) {
        $code = $this->crypto->generateCode();
        $code_hash = $this->crypto->hashCode($code);
        $record = [
          'uid' => (int) $user->id(),
          'email' => $this->canonicalizeEmail($user->getEmail()),
          'code' => $code_hash,
          'attempts' => 0,
          'expires' => $this->time->getRequestTime() + $lifetime,
        ];
        $store->setWithExpire($key, $record, $lifetime);
        $this->pendingMails[] = compact('user', 'code', 'lifetime', 'code_hash');
        return $result;
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Break-glass recovery-code request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $result;
    }
    finally {
      $this->lock->release($lock_name);
    }

    // Keep dummy hashing off the lock shared with code verification.
    $this->performDummyRequestWork();
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyCode(string $email, string $code): ?array {
    if (!$this->isAvailable()) {
      $this->performDummyVerifyWork();
      return NULL;
    }

    $key = $this->emailKey($email);
    $lock_name = self::CODE_COLLECTION . ':' . $key;
    if (!$this->lock->acquire($lock_name, 10.0)) {
      $this->performDummyVerifyWork();
      return NULL;
    }

    try {
      $store = $this->codeStore();
      $record = $store->get($key);
      if (!$this->isCodeRecord($record)) {
        $this->performDummyVerifyWork();
        return NULL;
      }

      if ($record['expires'] <= $this->time->getCurrentTime()) {
        $store->delete($key);
        $this->performDummyVerifyWork();
        return NULL;
      }

      if (!$this->crypto->verifyCode($code, $record['code'])) {
        // The controller separately limits guesses per proxy client.
        $record['attempts'] = ($record['attempts'] ?? 0) + 1;
        if ($record['attempts'] >= self::MAX_CODE_ATTEMPTS || $record['expires'] <= $this->time->getCurrentTime()) {
          $store->delete($key);
        }
        else {
          // Drupal's expirable store computes expiry from the request time.
          $remaining = $record['expires'] - $this->time->getRequestTime();
          $store->setWithExpire($key, $record, $remaining);
        }
        return NULL;
      }

      $store->delete($key);
      $user = $this->entityTypeManager->getStorage('user')->load($record['uid']);
      if (!$user instanceof UserInterface
        || !hash_equals($record['email'], $this->canonicalizeEmail($user->getEmail()))
        || !$this->isAvailable()
        || !$this->isEligible($user)) {
        return NULL;
      }

      return $this->issueRecoveryToken($user);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Break-glass recovery-code verification failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isRecoveryTokenValid(string $token): bool {
    return $this->loadEligibleTokenRecord($token) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function consumeRecoveryToken(string $token): ?int {
    $key = $this->tokenKey($token);
    $lock_name = self::TOKEN_COLLECTION . ':' . $key;
    if (!$this->lock->acquire($lock_name, 10.0)) {
      return NULL;
    }

    try {
      $record = $this->loadEligibleTokenRecord($token);
      if ($record === NULL) {
        return NULL;
      }
      $this->tokenStore()->delete($key);
      return $record['uid'];
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateRecoveryArtifacts(): void {
    $this->codeStore()->deleteAll();
    $this->tokenStore()->deleteAll();
    $this->pendingMails = [];
  }

  /**
   * Sends pending recovery mails after the HTTP response has been sent.
   */
  public function onTerminate(): void {
    $pending = $this->pendingMails;
    $this->pendingMails = [];
    foreach ($pending as $mail) {
      if (!$this->sendCode($mail['user'], $mail['code'], $mail['lifetime'])) {
        // A late failure only affects this issuance's salted hash. The live
        // record may have expired or been replaced while mail was in flight.
        try {
          $this->codeStore()->setWithExpire('mail_failed:' . $mail['code_hash'], TRUE, $mail['lifetime']);
        }
        catch (\Throwable) {
          $this->logger->error('Could not record break-glass recovery-code mail failure.');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  /**
   * Sends a recovery code without exposing mail failures to the caller.
   */
  private function sendCode(UserInterface $user, string $code, int $lifetime): bool {
    try {
      $result = $this->mailManager->mail(
        'beantragt_maintenance',
        'break_glass_code',
        $user->getEmail(),
        $user->getPreferredLangcode(),
        [
          'code' => $code,
          'expires_in' => (int) ceil($lifetime / 60),
          'platform_name' => $this->configFactory->get('system.site')->get('name') ?: 'beantragt.ai',
        ],
        NULL,
        TRUE,
      );
      // Core reports a deliberate cancellation as NULL.
      if ($result['result'] === FALSE) {
        $this->logger->error('Break-glass recovery-code mail transport reported failure.');
        return FALSE;
      }
      return TRUE;
    }
    catch (\Throwable) {
      $this->logger->error('Break-glass recovery-code mail could not be sent.');
      return FALSE;
    }
  }

  /**
   * Issues an opaque, one-time token bound to the eligible user.
   *
   * @return array{recoveryToken: string, expiresIn: int}
   *   The plaintext token and its lifetime.
   */
  private function issueRecoveryToken(UserInterface $user): array {
    $token = $this->crypto->generateToken();
    $record = [
      'uid' => (int) $user->id(),
      'expires' => time() + self::TOKEN_LIFETIME,
    ];
    $this->tokenStore()->setWithExpire(
      $this->tokenKey($token),
      $record,
      self::TOKEN_LIFETIME,
    );

    return [
      'recoveryToken' => $token,
      'expiresIn' => self::TOKEN_LIFETIME,
    ];
  }

  /**
   * Loads a currently eligible token record.
   *
   * @return array{uid: int, expires: int}|null
   *   The token record, or NULL if it is invalid.
   */
  private function loadEligibleTokenRecord(string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token) || !$this->isAvailable()) {
      return NULL;
    }

    $record = $this->tokenStore()->get($this->tokenKey($token));
    if (!is_array($record)
      || !isset($record['uid'], $record['expires'])
      || !is_int($record['uid'])
      || !is_int($record['expires'])
      || $record['expires'] < time()) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($record['uid']);
    return $user instanceof UserInterface && $this->isEligible($user) ? $record : NULL;
  }

  /**
   * Finds an active account with Core's maintenance-access permission.
   */
  private function loadEligibleUserByEmail(string $email): ?UserInterface {
    $users = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $this->canonicalizeEmail($email)]);
    $user = reset($users);
    return $user instanceof UserInterface && $this->isEligible($user) ? $user : NULL;
  }

  /**
   * Checks the exact account requirements for break-glass recovery.
   */
  private function isEligible(UserInterface $user): bool {
    return $user->isActive()
      && $user->hasPermission(self::MAINTENANCE_ACCESS_PERMISSION);
  }

  /**
   * Validates a stored recovery-code record.
   *
   * @phpstan-assert-if-true array{uid: int, email: string, code: string, expires: int, attempts?: int} $record
   */
  private function isCodeRecord(mixed $record): bool {
    return is_array($record)
      && isset($record['uid'], $record['email'], $record['code'], $record['expires'])
      && is_int($record['uid'])
      && is_string($record['email'])
      && is_string($record['code'])
      && is_int($record['expires'])
      && (!array_key_exists('attempts', $record) || is_int($record['attempts']));
  }

  /**
   * Gets the passwordless-configured OTP lifetime with a safe lower bound.
   */
  private function codeLifetime(): int {
    return max(60, (int) ($this->configFactory->get('beantragt_passwordless.settings')->get('code_lifetime') ?? 600));
  }

  /**
   * Gets the isolated recovery-code store.
   */
  private function codeStore(): KeyValueStoreExpirableInterface {
    return $this->keyValueExpirable->get(self::CODE_COLLECTION);
  }

  /**
   * Gets the isolated recovery-token store.
   */
  private function tokenStore(): KeyValueStoreExpirableInterface {
    return $this->keyValueExpirable->get(self::TOKEN_COLLECTION);
  }

  /**
   * Builds a non-reversible code-store key from an email address.
   */
  private function emailKey(string $email): string {
    return hash('sha256', $this->canonicalizeEmail($email));
  }

  /**
   * Builds a non-reversible token-store key.
   */
  private function tokenKey(string $token): string {
    return hash('sha256', $token);
  }

  /**
   * Normalizes email casing for lookups and record binding.
   */
  private function canonicalizeEmail(string $email): string {
    return mb_strtolower(trim($email));
  }

  /**
   * Equalizes ineligible request work against real code hashing.
   */
  private function performDummyRequestWork(): void {
    $this->crypto->hashCode('000000');
  }

  /**
   * Equalizes missing-code verification against a real code check.
   */
  private function performDummyVerifyWork(): void {
    $this->crypto->verifyCode('000000', self::DUMMY_HASH);
  }

}
