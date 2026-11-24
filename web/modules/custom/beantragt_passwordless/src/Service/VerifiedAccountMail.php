<?php

declare(strict_types=1);

namespace Drupal\beantragt_passwordless\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Stores the verified mailbox currently bound to an account.
 */
class VerifiedAccountMail {

  /**
   * Constructs the verification store.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns the verified current mailbox, or NULL without provenance.
   */
  public function getVerifiedEmail(AccountInterface $account): ?string {
    $mail = $this->normalize((string) $account->getEmail());
    if ((int) $account->id() <= 0 || $mail === '') {
      return NULL;
    }
    $stored = $this->database->select('beantragt_verified_account_mail', 'm')
      ->fields('m', ['mail'])
      ->condition('uid', (int) $account->id())
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return is_string($stored) && hash_equals($mail, $stored) ? $mail : NULL;
  }

  /**
   * Records successful control of the account's current mailbox.
   */
  public function verify(UserInterface $account, string $email, string $source): void {
    $mail = $this->normalize($email);
    if ($mail === '' || !hash_equals($this->normalize((string) $account->getEmail()), $mail)) {
      throw new \InvalidArgumentException('A verified mailbox must equal the account mailbox.');
    }
    $this->database->merge('beantragt_verified_account_mail')
      ->keys(['uid' => (int) $account->id()])
      ->fields([
        'mail' => $mail,
        'source' => substr($source, 0, 64),
        'verified' => time(),
      ])
      ->execute();
    $this->cacheTagsInvalidator->invalidateTags(['user:' . $account->id()]);
  }

  /**
   * Drops stale provenance when the account address changes or is deleted.
   */
  public function invalidate(UserInterface $account): void {
    if ((int) $account->id() > 0) {
      $this->database->delete('beantragt_verified_account_mail')
        ->condition('uid', (int) $account->id())
        ->execute();
      $this->cacheTagsInvalidator->invalidateTags(['user:' . $account->id()]);
    }
  }

  /**
   * Normalizes an address for comparison and storage.
   */
  private function normalize(string $mail): string {
    return strtolower(trim($mail));
  }

}
