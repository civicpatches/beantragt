<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Core\Site\Settings;

/**
 * Encodes unread scan positions without disclosing candidate IDs or dates.
 */
final class UnreadMessageCursor {

  private const CIPHER = 'aes-256-gcm';
  private const NONCE_LENGTH = 12;
  private const TAG_LENGTH = 16;
  private const PREFIX_LENGTH = self::NONCE_LENGTH + self::TAG_LENGTH;

  /**
   * Authenticates a scan position and its account and query context.
   *
   * @param array{int, int, int} $position
   *   The two sort values and application ID, in sort order.
   * @param string $context
   *   Serialized account and query context.
   */
  public static function encode(array $position, string $context): string {
    $nonce = random_bytes(self::NONCE_LENGTH);
    $ciphertext = openssl_encrypt(
      json_encode($position, JSON_THROW_ON_ERROR),
      self::CIPHER,
      self::key(),
      OPENSSL_RAW_DATA,
      $nonce,
      $tag,
      $context,
      self::TAG_LENGTH,
    );
    if ($ciphertext === FALSE) {
      throw new \RuntimeException('Could not encrypt the unread message cursor.');
    }
    return self::base64Url($nonce . $tag . $ciphertext);
  }

  /**
   * Decodes a cursor issued for this account and query context.
   *
   * @return array{int, int, int}|null
   *   The scan position or NULL for an invalid cursor.
   */
  public static function decode(mixed $cursor, string $context): ?array {
    if (!is_string($cursor) || strlen($cursor) > 128 || !preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor)) {
      return NULL;
    }
    $bytes = base64_decode(strtr($cursor, '-_', '+/'), TRUE);
    if ($bytes === FALSE || strlen($bytes) <= self::PREFIX_LENGTH || self::base64Url($bytes) !== $cursor) {
      return NULL;
    }
    $plaintext = openssl_decrypt(
      substr($bytes, self::PREFIX_LENGTH),
      self::CIPHER,
      self::key(),
      OPENSSL_RAW_DATA,
      substr($bytes, 0, self::NONCE_LENGTH),
      substr($bytes, self::NONCE_LENGTH, self::TAG_LENGTH),
      $context,
    );
    if ($plaintext === FALSE) {
      return NULL;
    }
    $position = json_decode($plaintext, TRUE);
    if (!is_array($position) || !array_is_list($position) || count($position) !== 3
      || !is_int($position[0]) || !is_int($position[1]) || !is_int($position[2]) || $position[2] < 1) {
      return NULL;
    }
    return $position;
  }

  /**
   * Derives a site-local cursor key independently of other encryption keys.
   */
  private static function key(): string {
    return hash_hkdf('sha256', Settings::getHashSalt(), 32, 'beantragt_unread_message_cursor_v1');
  }

  /**
   * Encodes bytes as unpadded base64url.
   */
  private static function base64Url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

}
