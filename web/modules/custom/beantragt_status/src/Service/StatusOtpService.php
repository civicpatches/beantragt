<?php

declare(strict_types=1);

namespace Drupal\beantragt_status\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\PrivateKey;
use Psr\Log\LoggerInterface;

/**
 * Issues one-time codes for public reference-number status lookup.
 *
 * This deliberately does not reuse the passwordless login verifier because
 * status lookup must not create users or establish a Drupal session.
 */
final class StatusOtpService {

  /**
   * The number of digits in a public status OTP.
   */
  private const CODE_LENGTH = 6;

  /**
   * OTP lifetime in seconds.
   */
  private const CODE_LIFETIME = 600;

  /**
   * Maximum verification attempts per issued code.
   */
  private const MAX_ATTEMPTS = 5;

  /**
   * Keep issued codes and the fixed dummy hash at the same bcrypt cost.
   */
  private const HASH_COST = 12;

  /**
   * Fixed bcrypt hash used when no eligible code exists.
   */
  private const DUMMY_HASH = '$2y$12$Swf5KJU7Onq1XGRI0n8hdeWHWEiR033nChaJ6yHLG7mpvl/xa32aG';

  /**
   * Constructs a StatusOtpService object.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly PrivateKey $privateKey,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Requests a code for the given reference and applicant email.
   *
   * @return array{success: bool, expiresIn?: int, codeLength?: int, error?: string}
   *   The request result.
   */
  public function requestCode(
    string $referenceNumber,
    string $email,
    string $langcode = '',
    ?int $jurisdictionId = NULL,
  ): array {
    $this->cleanupExpiredCodes();

    $reference_hash = $this->referenceHash($referenceNumber);
    $email_hash = $this->emailHash($email);

    $code = $this->generateCode();
    $now = time();
    $expires = $now + self::CODE_LIFETIME;

    try {
      $new_id = $this->database->insert('beantragt_status_otp_codes')
        ->fields([
          'reference_hash' => $reference_hash,
          'email_hash' => $email_hash,
          'code' => password_hash($code, PASSWORD_BCRYPT, ['cost' => self::HASH_COST]),
          'attempts' => 0,
          'created' => $now,
          'expires' => $expires,
          'verified' => 0,
        ])
        ->execute();

      // Insert first so racing requests can only invalidate older codes.
      $this->database->update('beantragt_status_otp_codes')
        ->fields(['verified' => 2])
        ->condition('reference_hash', $reference_hash)
        ->condition('email_hash', $email_hash)
        ->condition('verified', 0)
        ->condition('id', $new_id, '<')
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store public status OTP: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'error' => 'Failed to generate status code',
      ];
    }

    if (!$this->sendCode($referenceNumber, $email, $code, $langcode, $jurisdictionId)) {
      return [
        'success' => FALSE,
        'error' => 'Failed to send status code',
      ];
    }

    return [
      'success' => TRUE,
      'expiresIn' => self::CODE_LIFETIME,
      'codeLength' => self::CODE_LENGTH,
    ];
  }

  /**
   * Verifies a status lookup code without logging in a user.
   */
  public function verifyCode(string $referenceNumber, string $email, string $code): bool {
    $transaction = $this->database->startTransaction('status_otp_verify');

    try {
      $reference_hash = $this->referenceHash($referenceNumber);
      $email_hash = $this->emailHash($email);
      $now = time();
      $record = $this->database->select('beantragt_status_otp_codes', 'c')
        ->fields('c')
        ->condition('reference_hash', $reference_hash)
        ->condition('email_hash', $email_hash)
        ->condition('verified', 0)
        ->condition('attempts', self::MAX_ATTEMPTS, '<')
        ->condition('expires', $now, '>=')
        ->orderBy('id', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchObject();

      // Exactly one bcrypt operation, even for absent, expired or capped codes.
      $matches = password_verify($code, $record === FALSE ? self::DUMMY_HASH : $record->code);
      if ($record === FALSE) {
        return FALSE;
      }

      if (!$matches) {
        // Charge the entire target, including pending rows left by races.
        $this->database->update('beantragt_status_otp_codes')
          ->expression('attempts', 'attempts + 1')
          ->condition('reference_hash', $reference_hash)
          ->condition('email_hash', $email_hash)
          ->condition('verified', 0)
          ->condition('attempts', self::MAX_ATTEMPTS, '<')
          ->execute();

        return FALSE;
      }

      $claimed = $this->database->update('beantragt_status_otp_codes')
        ->expression('attempts', 'attempts + 1')
        ->fields(['verified' => 1])
        ->condition('id', $record->id)
        ->condition('verified', 0)
        ->condition('attempts', self::MAX_ATTEMPTS, '<')
        ->execute();

      return $claimed === 1;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Status OTP verification failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Builds a non-PII flood identifier for the reference/email target.
   */
  public function floodIdentifier(string $referenceNumber, string $email): string {
    return $this->contextHash(
      'flood',
      strtoupper(trim($referenceNumber)) . ':' . strtolower(trim($email)),
    );
  }

  /**
   * Deletes expired codes after a short grace period.
   */
  public function cleanupExpiredCodes(): void {
    $this->database->delete('beantragt_status_otp_codes')
      ->condition('expires', time() - 3600, '<')
      ->execute();
  }

  /**
   * Sends the status code email.
   */
  private function sendCode(
    string $referenceNumber,
    string $email,
    string $code,
    string $langcode = '',
    ?int $jurisdictionId = NULL,
  ): bool {
    if ($langcode === '') {
      $langcode = $this->languageManager->getCurrentLanguage()->getId();
    }

    $params = [
      'code' => $code,
      'expires_in' => (int) (self::CODE_LIFETIME / 60),
      'platform_name' => $this->configFactory->get('system.site')->get('name') ?: 'beantragt.ai',
      'reference_number' => $referenceNumber,
      'jurisdiction_id' => $jurisdictionId,
    ];

    try {
      $result = $this->mailManager->mail(
        'beantragt_status',
        'status_lookup_code',
        $email,
        $langcode,
        $params,
        NULL,
        TRUE,
      );

      return (bool) ($result['result'] ?? FALSE);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send public status OTP email: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Generates a fixed-length numeric OTP.
   */
  private function generateCode(): string {
    $min = 10 ** (self::CODE_LENGTH - 1);
    $max = (10 ** self::CODE_LENGTH) - 1;

    return (string) random_int($min, $max);
  }

  /**
   * Hashes a normalized reference number for lookup.
   */
  private function referenceHash(string $referenceNumber): string {
    return $this->contextHash('reference', strtoupper(trim($referenceNumber)));
  }

  /**
   * Hashes a normalized email address for lookup.
   */
  private function emailHash(string $email): string {
    return $this->contextHash('email', strtolower(trim($email)));
  }

  /**
   * Hashes low-entropy lookup material with Drupal's private key.
   */
  private function contextHash(string $type, string $value): string {
    return hash_hmac('sha256', $type . ':' . $value, $this->privateKey->get());
  }

}
