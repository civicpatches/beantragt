<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Issues and claims public submission email confirmation tokens.
 */
class EmailConfirmationTokenService {

  public const STATUS_CONFIRMED = 'confirmed';
  public const STATUS_ALREADY_CONFIRMED = 'already_confirmed';
  public const STATUS_INVALID = 'invalid';

  private const TOKEN_BYTES = 48;
  private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{64,}$/';
  private const DEFAULT_TOKEN_TTL_HOURS = 72;
  private const DEFAULT_PURGE_HOURS = 168;

  /**
   * Constructs the token service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
    protected readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    protected readonly ?ModuleHandlerInterface $moduleHandler = NULL,
  ) {}

  /**
   * Returns field values for a newly pending anonymous submission.
   *
   * @return array{token: string, values: array<string, mixed>, expires: int}
   *   The plaintext token for mail only, application field values, and expiry.
   */
  public function pendingCreateValues(): array {
    $issued = $this->issueToken();

    return [
      'token' => $issued['token'],
      'expires' => $issued['expires'],
      'values' => [
        'field_email_confirmed' => FALSE,
        'field_email_confirmation_token_hash' => $issued['hash'],
        'field_email_confirmation_expires' => $issued['expires'],
        'field_email_confirmed_time' => NULL,
      ],
    ];
  }

  /**
   * Returns field values for a submission that bypasses confirmation.
   *
   * @return array<string, mixed>
   *   Application field values.
   */
  public function confirmedCreateValues(): array {
    return [
      'field_email_confirmed' => TRUE,
      'field_email_confirmation_token_hash' => NULL,
      'field_email_confirmation_expires' => NULL,
      'field_email_confirmed_time' => $this->time->getRequestTime(),
    ];
  }

  /**
   * Builds the public frontend confirmation link.
   */
  public function confirmationFrontendUrl(string $token): ?string {
    $base_url = $this->frontendBaseUrl();
    if ($base_url === '') {
      return NULL;
    }

    return $base_url . '/confirm/' . rawurlencode($token);
  }

  /**
   * Sends the confirmation request lifecycle event.
   */
  public function dispatchConfirmationRequest(
    ApplicationInterface $application,
    string $confirmationUrl,
    ?string $fallbackRecipient = NULL,
  ): void {
    if ($confirmationUrl === '') {
      return;
    }

    $this->invokeLifecycleHook('beantragt_application_confirmation_request', [
      $application,
      $confirmationUrl,
      $fallbackRecipient,
    ]);
  }

  /**
   * Claims a token atomically.
   *
   * @return array{status: 'invalid'}|array{status: 'confirmed'|'already_confirmed', application: \Drupal\beantragt_application\Entity\ApplicationInterface}
   *   Claim status and confirmed application when available.
   */
  public function claim(string $token): array {
    $token = trim($token);
    if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
      return ['status' => self::STATUS_INVALID];
    }

    $hash = $this->hashToken($token);
    $now = $this->time->getRequestTime();
    $reserved = $this->reserveTokenHash($hash, $now);
    $application = $this->loadConfirmedApplicationByTokenHash($hash, $now);
    if ($application === NULL) {
      return ['status' => self::STATUS_INVALID];
    }

    if ($reserved === 1) {
      $this->dispatchConfirmed($application);
    }
    return [
      'status' => $reserved === 1 ? self::STATUS_CONFIRMED : self::STATUS_ALREADY_CONFIRMED,
      'application' => $application,
    ];
  }

  /**
   * Resends a confirmation request when the reference/email pair matches.
   *
   * This method intentionally returns no public status. The caller must always
   * send the same accepted response.
   */
  public function resend(string $referenceNumber, string $email): void {
    $application = $this->loadUnconfirmedApplicationByReference($referenceNumber);
    $normalized_email = $this->normalizeEmail($email);
    if (!$application instanceof ApplicationInterface
      || $normalized_email === NULL
      || !$this->applicantEmailMatches($application, $normalized_email)) {
      $this->issueToken();
      return;
    }

    $issued = $this->issueToken();
    if ($this->replaceTokenHash((int) $application->id(), $issued['hash'], $issued['expires']) !== 1) {
      return;
    }

    $url = $this->confirmationFrontendUrl($issued['token']);
    if ($url !== NULL) {
      // Protected supplemental addresses may exist only as a lookup hash.
      // The request address is a safe fallback after the stored match above.
      $this->dispatchConfirmationRequest($application, $url, $normalized_email);
    }
  }

  /**
   * Returns TRUE when the application is confirmed.
   */
  public function isConfirmed(ApplicationInterface $application): bool {
    return $application->isEmailConfirmed();
  }

  /**
   * Returns the configured purge window in hours.
   */
  public function purgeHours(): int {
    $hours = $this->configFactory
      ->get('beantragt_application.settings')
      ->get('confirmation_purge_hours');
    return $this->boundedHours($hours, self::DEFAULT_PURGE_HOURS);
  }

  /**
   * Returns the configured token TTL in hours.
   */
  protected function tokenTtlHours(): int {
    $hours = $this->configFactory
      ->get('beantragt_application.settings')
      ->get('confirmation_token_ttl_hours');
    return $this->boundedHours($hours, self::DEFAULT_TOKEN_TTL_HOURS);
  }

  /**
   * Issues a plaintext token and hashed storage values.
   *
   * @return array{token: string, hash: string, expires: int}
   *   Token tuple.
   */
  protected function issueToken(): array {
    $token = $this->base64Url(random_bytes(self::TOKEN_BYTES));
    return [
      'token' => $token,
      'hash' => $this->hashToken($token),
      'expires' => $this->time->getRequestTime() + ($this->tokenTtlHours() * 3600),
    ];
  }

  /**
   * Atomically reserves a pending token hash.
   */
  protected function reserveTokenHash(string $hash, int $now): int {
    $transaction = $this->database->startTransaction('email_confirmation_claim');
    $reserved_base_row = FALSE;

    try {
      $reserved = (int) $this->database->update('application')
        ->fields([
          'field_email_confirmed' => 1,
          'field_email_confirmed_time' => $now,
          'changed' => $now,
        ])
        ->condition('field_email_confirmation_token_hash', $hash)
        ->condition('field_email_confirmed', 0)
        ->condition('field_email_confirmation_expires', $now, '>=')
        ->execute();

      if ($reserved === 0) {
        unset($transaction);
        return $reserved;
      }
      if ($reserved !== 1) {
        $this->logger->error('Email confirmation token claim matched @count application rows; rolling back the claim.', [
          '@count' => $reserved,
        ]);
        $this->rollBack($transaction);
        return 0;
      }
      $reserved_base_row = TRUE;

      $revision = $this->currentRevisionIdentityByTokenHash($hash);
      if ($revision === NULL) {
        $this->logger->error('Email confirmation token claim succeeded but the application revision could not be found.');
        $this->rollBack($transaction);
        return 0;
      }

      $mirrored = $this->mirrorCurrentRevisionFields(
        $revision['id'],
        $revision['revision_id'],
        [
          'field_email_confirmed' => 1,
          'field_email_confirmed_time' => $now,
          'changed' => $now,
        ],
      );
      if ($mirrored !== 1) {
        $this->logger->error('Email confirmation token claim succeeded but the current application revision was not mirrored.');
        $this->rollBack($transaction);
        return 0;
      }
      $this->resetApplicationCache($revision['id']);
      unset($transaction);

      return $reserved;
    }
    catch (\Throwable $e) {
      $this->rollBack($transaction);
      if ($reserved_base_row) {
        $this->logger->error('Email confirmation token claim failed after reserving the application row: @message', [
          '@message' => $e->getMessage(),
        ]);
        return 0;
      }
      throw $e;
    }
  }

  /**
   * Replaces the active token hash for an unconfirmed application.
   */
  protected function replaceTokenHash(int $applicationId, string $hash, int $expires): int {
    if ($applicationId <= 0) {
      return 0;
    }

    $now = $this->time->getRequestTime();
    $transaction = $this->database->startTransaction('email_confirmation_replace');
    $updated_base_row = FALSE;

    try {
      $updated = (int) $this->database->update('application')
        ->fields([
          'field_email_confirmation_token_hash' => $hash,
          'field_email_confirmation_expires' => $expires,
          'changed' => $now,
        ])
        ->condition('id', $applicationId)
        ->condition('field_email_confirmed', 0)
        ->execute();

      if ($updated === 0) {
        unset($transaction);
        return $updated;
      }
      if ($updated !== 1) {
        $this->logger->error('Email confirmation token replacement matched @count application rows; rolling back the replacement.', [
          '@count' => $updated,
        ]);
        $this->rollBack($transaction);
        return 0;
      }
      $updated_base_row = TRUE;

      $revision = $this->currentRevisionIdentityByApplicationId($applicationId);
      if ($revision === NULL) {
        $this->logger->error('Email confirmation token replacement succeeded but the application revision could not be found.');
        $this->rollBack($transaction);
        return 0;
      }

      $mirrored = $this->mirrorCurrentRevisionFields(
        $revision['id'],
        $revision['revision_id'],
        ['changed' => $now],
      );
      if ($mirrored !== 1) {
        $this->logger->error('Email confirmation token replacement succeeded but the current application revision was not mirrored.');
        $this->rollBack($transaction);
        return 0;
      }
      $this->resetApplicationCache($revision['id']);
      unset($transaction);

      return $updated;
    }
    catch (\Throwable $e) {
      $this->rollBack($transaction);
      if ($updated_base_row) {
        $this->logger->error('Email confirmation token replacement failed after updating the application row: @message', [
          '@message' => $e->getMessage(),
        ]);
        return 0;
      }
      throw $e;
    }
  }

  /**
   * Loads the current revision identity for a token hash.
   *
   * @return array{id: int, revision_id: int}|null
   *   Application id and current revision id.
   */
  protected function currentRevisionIdentityByTokenHash(string $hash): ?array {
    $record = $this->database->select('application', 'a')
      ->fields('a', ['id', 'revision_id'])
      ->condition('field_email_confirmation_token_hash', $hash)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $this->normalizeRevisionIdentity($record);
  }

  /**
   * Loads the current revision identity for an application id.
   *
   * @return array{id: int, revision_id: int}|null
   *   Application id and current revision id.
   */
  protected function currentRevisionIdentityByApplicationId(int $applicationId): ?array {
    if ($applicationId <= 0) {
      return NULL;
    }

    $record = $this->database->select('application', 'a')
      ->fields('a', ['id', 'revision_id'])
      ->condition('id', $applicationId)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $this->normalizeRevisionIdentity($record);
  }

  /**
   * Mirrors raw base writes for revision columns on the current revision row.
   *
   * @param int $applicationId
   *   Application id.
   * @param int $revisionId
   *   Current revision id.
   * @param array<string, mixed> $fields
   *   Base table fields keyed by column name.
   */
  protected function mirrorCurrentRevisionFields(int $applicationId, int $revisionId, array $fields): int {
    if ($applicationId <= 0 || $revisionId <= 0) {
      return 0;
    }

    $revision_fields = [];
    $schema = $this->database->schema();
    foreach ($fields as $field_name => $value) {
      if ($schema->fieldExists('application_revision', $field_name)) {
        $revision_fields[$field_name] = $value;
      }
    }
    if ($revision_fields === []) {
      return 0;
    }

    return (int) $this->database->update('application_revision')
      ->fields($revision_fields)
      ->condition('id', $applicationId)
      ->condition('revision_id', $revisionId)
      ->execute();
  }

  /**
   * Resets cached application entities after raw SQL writes.
   */
  protected function resetApplicationCache(int $applicationId): void {
    if ($applicationId <= 0) {
      return;
    }

    $this->entityTypeManager
      ->getStorage('application')
      ->resetCache([$applicationId]);
  }

  /**
   * Rolls back an open transaction.
   */
  private function rollBack(?Transaction $transaction): void {
    if ($transaction instanceof Transaction) {
      $transaction->rollBack();
    }
  }

  /**
   * Normalizes a database row containing an application revision identity.
   *
   * @param array<string, mixed>|false|null $record
   *   Database row.
   *
   * @return array{id: int, revision_id: int}|null
   *   Application id and current revision id.
   */
  private function normalizeRevisionIdentity(array|false|null $record): ?array {
    if (!is_array($record)) {
      return NULL;
    }

    $application_id = (int) ($record['id'] ?? 0);
    $revision_id = (int) ($record['revision_id'] ?? 0);
    if ($application_id <= 0 || $revision_id <= 0) {
      return NULL;
    }

    return [
      'id' => $application_id,
      'revision_id' => $revision_id,
    ];
  }

  /**
   * Loads a confirmed application with a still-valid token.
   */
  protected function loadConfirmedApplicationByTokenHash(string $hash, int $now): ?ApplicationInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmation_token_hash', $hash)
      ->condition('field_email_confirmed', TRUE)
      ->condition('field_email_confirmation_expires', $now, '>=')
      ->range(0, 2);
    $ids = $query->execute();
    if (count($ids) !== 1) {
      return NULL;
    }

    $storage->resetCache($ids);
    $application = $storage->load(reset($ids));
    // Recheck after loading the entity in case the row changed since lookup.
    if (!$application instanceof ApplicationInterface
      || !$this->isConfirmed($application)
      || !hash_equals($hash, (string) $application->get('field_email_confirmation_token_hash')->value)
      || (int) $application->get('field_email_confirmation_expires')->value < $now) {
      return NULL;
    }
    return $application;
  }

  /**
   * Loads an unconfirmed application by exact reference number.
   */
  protected function loadUnconfirmedApplicationByReference(string $referenceNumber): ?ApplicationInterface {
    $referenceNumber = strtoupper(trim($referenceNumber));
    if ($referenceNumber === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('application');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_reference_number', $referenceNumber)
      ->condition('field_email_confirmed', FALSE)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $application = $storage->load(reset($ids));
    return $application instanceof ApplicationInterface ? $application : NULL;
  }

  /**
   * Dispatches the confirmed lifecycle hook.
   */
  protected function dispatchConfirmed(ApplicationInterface $application): void {
    $this->invokeLifecycleHook('beantragt_application_confirmed', [$application]);
  }

  /**
   * Invokes a lifecycle hook when a module handler is available.
   *
   * @param string $hook
   *   Hook name without the module prefix.
   * @param array<int, mixed> $arguments
   *   Hook arguments.
   */
  protected function invokeLifecycleHook(string $hook, array $arguments): void {
    if (!$this->moduleHandler instanceof ModuleHandlerInterface) {
      return;
    }

    try {
      $this->moduleHandler->invokeAllWith(
        $hook,
        function (callable $implementation, string $module) use ($hook, $arguments): void {
          try {
            call_user_func_array($implementation, $arguments);
          }
          catch (\Throwable $e) {
            $this->logger->error('Email confirmation hook @hook in @module failed: @message', [
              '@hook' => $hook,
              '@module' => $module,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Email confirmation hook @hook failed: @message', [
        '@hook' => $hook,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Checks a normalized applicant email against stored clear/protected data.
   */
  protected function applicantEmailMatches(ApplicationInterface $application, string $email): bool {
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      return $this->dataProtection->applicationApplicantEmailMatches($application, $email);
    }

    $stored = $this->normalizeEmail((string) ($application->getApplicantEmail() ?? ''));
    return $stored !== NULL && hash_equals($stored, $email);
  }

  /**
   * Hashes a token for storage or lookup.
   */
  protected function hashToken(string $token): string {
    return hash('sha256', $token);
  }

  /**
   * Encodes bytes as unpadded base64url.
   */
  private function base64Url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

  /**
   * Normalizes a configurable hour value.
   */
  private function boundedHours(mixed $value, int $default): int {
    if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
      $value = (int) trim($value);
    }
    if (!is_int($value)) {
      return $default;
    }
    return max(1, min(87600, $value));
  }

  /**
   * Resolves a validated frontend base URL.
   */
  private function frontendBaseUrl(): string {
    $base_url = trim((string) ($this->configFactory->get('beantragt_group.settings')->get('frontend_base_url') ?? ''));
    if ($base_url === '') {
      return '';
    }

    $parts = parse_url($base_url);
    if (!is_array($parts)
      || empty($parts['scheme'])
      || empty($parts['host'])
      || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], TRUE)) {
      return '';
    }

    return rtrim($base_url, '/');
  }

  /**
   * Normalizes an email address.
   */
  private function normalizeEmail(string $email): ?string {
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL;
  }

}
