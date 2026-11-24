<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles public application idempotency keys and replay records.
 */
final class PublicApplicationIdempotencyService {

  private const IDEMPOTENCY_TTL = 900;
  private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._:-]{32,128}$/';
  private const IDEMPOTENCY_TOKEN_MARKER = '__beantragt_encrypted_idempotency_status_token';
  private const IDEMPOTENCY_TOKEN_CIPHER = 'aes-256-gcm';

  /**
   * Constructs the idempotency service.
   */
  public function __construct(
    private readonly KeyValueStoreExpirableInterface $store,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
  ) {
  }

  /**
   * Builds the optional idempotency context for this request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP request.
   * @param array<string, mixed> $body
   *   Decoded request body.
   *
   * @return array{storeKey: string|null, keyHash: string|null, payloadHash: string|null, error: array<string, mixed>|null}
   *   Normalized idempotency values and optional error.
   */
  public function context(Request $request, array $body): array {
    $key = trim((string) $request->headers->get('Idempotency-Key', ''));
    if ($key === '') {
      return [
        'storeKey' => NULL,
        'keyHash' => NULL,
        'payloadHash' => NULL,
        'error' => NULL,
      ];
    }
    if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
      return [
        'storeKey' => NULL,
        'keyHash' => NULL,
        'payloadHash' => NULL,
        'error' => [
          'code' => 'idempotency.invalid_key',
          'message' => 'Idempotency-Key must contain 32 to 128 safe characters.',
          'status' => Response::HTTP_BAD_REQUEST,
        ],
      ];
    }

    try {
      $payload_hash = $this->canonicalPayloadHash($body);
    }
    catch (\JsonException) {
      return [
        'storeKey' => NULL,
        'keyHash' => NULL,
        'payloadHash' => NULL,
        'error' => [
          'code' => 'request.invalid_json',
          'message' => 'Request body must be valid JSON.',
          'status' => Response::HTTP_BAD_REQUEST,
        ],
      ];
    }

    return [
      'storeKey' => NULL,
      'keyHash' => hash('sha256', $key),
      'payloadHash' => $payload_hash,
      'error' => NULL,
    ];
  }

  /**
   * Binds an idempotency key to endpoint, caller, form and auth context.
   */
  public function scopedContext(array $idempotency, string $request_scope, string $form_uuid, ?int $trusted_owner_id): array {
    $key_hash = $idempotency['keyHash'] ?? NULL;
    if (!is_string($key_hash)) {
      return $idempotency;
    }

    $auth_scope = $trusted_owner_id === NULL
      ? 'anonymous'
      : 'owner:' . $trusted_owner_id;
    $scope = implode(':', [
      $request_scope,
      'form:' . $form_uuid,
      $auth_scope,
      $key_hash,
    ]);
    $idempotency['storeKey'] = 'application.v1.' . hash('sha256', $scope);
    return $idempotency;
  }

  /**
   * Returns a stored replay result or conflict for a reused key.
   *
   * @return array{response: array<string, mixed>|null, topLevel: array<string, mixed>|null, status: int|null, error: array<string, mixed>|null}
   *   Decrypted replay response, status, or optional error.
   */
  public function replay(array $idempotency): array {
    $empty = [
      'response' => NULL,
      'topLevel' => NULL,
      'status' => NULL,
      'error' => NULL,
    ];

    $store_key = $idempotency['storeKey'] ?? NULL;
    $payload_hash = $idempotency['payloadHash'] ?? NULL;
    if (!is_string($store_key) || !is_string($payload_hash)) {
      return $empty;
    }

    $record = $this->store->get($store_key);
    if (!is_array($record)) {
      return $empty;
    }
    if (($record['payloadHash'] ?? NULL) !== $payload_hash) {
      return [
        ...$empty,
        'error' => [
          'code' => 'idempotency.conflict',
          'message' => 'Idempotency-Key was already used with a different request payload.',
          'status' => Response::HTTP_CONFLICT,
        ],
      ];
    }

    $response = $record['response'] ?? NULL;
    $status = $record['status'] ?? Response::HTTP_CREATED;
    if (!is_array($response) || !is_int($status)) {
      return $empty;
    }
    $response = $this->decryptResponse($response);
    if ($response === NULL) {
      return $empty;
    }

    return [
      'response' => $response,
      'topLevel' => is_array($record['topLevel'] ?? NULL) ? $record['topLevel'] : [],
      'status' => $status,
      'error' => NULL,
    ];
  }

  /**
   * Acquires the optional idempotency lock before any create side effect.
   */
  public function acquireLock(array $idempotency): string|false|null {
    $store_key = $idempotency['storeKey'] ?? NULL;
    if (!is_string($store_key)) {
      return NULL;
    }

    $lock_name = 'beantragt_application.idempotency.' . substr($store_key, -64);
    return $this->lock->acquire($lock_name, 30.0) ? $lock_name : FALSE;
  }

  /**
   * Releases an acquired idempotency lock.
   */
  public function releaseLock(string|false|null $lock_name): void {
    if (is_string($lock_name)) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Stores a successful response for the short network-retry window.
   *
   * @param array<string, mixed> $idempotency
   *   Idempotency context.
   * @param array<string, mixed> $response_data
   *   Successful response data.
   * @param int $status
   *   HTTP response status.
   * @param array<string, mixed> $top_level
   *   Top-level JSON response members preserved for replay.
   */
  public function storeResponse(array $idempotency, array $response_data, int $status, array $top_level = []): void {
    $store_key = $idempotency['storeKey'] ?? NULL;
    $payload_hash = $idempotency['payloadHash'] ?? NULL;
    if (!is_string($store_key) || !is_string($payload_hash)) {
      return;
    }

    $this->store->setWithExpire(
      $store_key,
      [
        'payloadHash' => $payload_hash,
        'response' => $this->encryptResponse($response_data),
        'topLevel' => $top_level,
        'status' => $status,
        'created' => $this->time->getRequestTime(),
      ],
      self::IDEMPOTENCY_TTL,
    );
  }

  /**
   * Encrypts bearer status tokens before writing idempotency replay records.
   *
   * @param array<string, mixed> $response_data
   *   Response data.
   *
   * @return array<string, mixed>
   *   Response data with bearer values encrypted.
   */
  private function encryptResponse(array $response_data): array {
    $status_token = $response_data['statusToken'] ?? NULL;
    if (!is_string($status_token) || $status_token === '') {
      return $response_data;
    }

    $response_data['statusToken'] = $this->encryptStatusToken($status_token);
    if (is_string($response_data['statusUrl'] ?? NULL) && str_contains($response_data['statusUrl'], $status_token)) {
      $response_data['statusUrl'] = $this->encryptStatusToken($response_data['statusUrl']);
    }
    return $response_data;
  }

  /**
   * Decrypts bearer status tokens from idempotency replay records.
   *
   * @param array<string, mixed> $response_data
   *   Stored response data.
   *
   * @return array<string, mixed>|null
   *   Decrypted response data or NULL when invalid.
   */
  private function decryptResponse(array $response_data): ?array {
    $status_token = $response_data['statusToken'] ?? NULL;
    if (is_string($status_token)) {
      return $response_data;
    }
    if (!is_array($status_token)) {
      return NULL;
    }

    $decrypted = $this->decryptStatusToken($status_token);
    if ($decrypted === NULL) {
      return NULL;
    }

    $response_data['statusToken'] = $decrypted;
    $status_url = $response_data['statusUrl'] ?? NULL;
    if (is_array($status_url)) {
      $decrypted_url = $this->decryptStatusToken($status_url);
      if ($decrypted_url === NULL) {
        return NULL;
      }
      $response_data['statusUrl'] = $decrypted_url;
    }

    return $response_data;
  }

  /**
   * Encrypts a public status bearer token for short-lived replay storage.
   *
   * @return array<string, string|bool>
   *   Authenticated encryption envelope.
   */
  private function encryptStatusToken(string $status_token): array {
    $iv_length = openssl_cipher_iv_length(self::IDEMPOTENCY_TOKEN_CIPHER);
    if ($iv_length <= 0) {
      throw new \RuntimeException('Unable to determine idempotency token IV length.');
    }

    $iv = random_bytes($iv_length);
    $tag = '';
    $ciphertext = openssl_encrypt(
      $status_token,
      self::IDEMPOTENCY_TOKEN_CIPHER,
      $this->encryptionKey(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
    );
    if ($ciphertext === FALSE) {
      throw new \RuntimeException('OpenSSL idempotency token encryption returned false.');
    }

    return [
      self::IDEMPOTENCY_TOKEN_MARKER => TRUE,
      'cipher' => self::IDEMPOTENCY_TOKEN_CIPHER,
      'iv' => base64_encode($iv),
      'tag' => base64_encode($tag),
      'value' => base64_encode($ciphertext),
    ];
  }

  /**
   * Decrypts a public status bearer token from short-lived replay storage.
   *
   * @param array<string, mixed> $envelope
   *   Authenticated encryption envelope.
   */
  private function decryptStatusToken(array $envelope): ?string {
    if (($envelope[self::IDEMPOTENCY_TOKEN_MARKER] ?? NULL) !== TRUE
      || ($envelope['cipher'] ?? NULL) !== self::IDEMPOTENCY_TOKEN_CIPHER) {
      return NULL;
    }

    $iv = base64_decode((string) ($envelope['iv'] ?? ''), TRUE);
    $tag = base64_decode((string) ($envelope['tag'] ?? ''), TRUE);
    $ciphertext = base64_decode((string) ($envelope['value'] ?? ''), TRUE);
    if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext)) {
      return NULL;
    }

    $plaintext = openssl_decrypt(
      $ciphertext,
      self::IDEMPOTENCY_TOKEN_CIPHER,
      $this->encryptionKey(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag,
    );

    return is_string($plaintext) && $plaintext !== '' ? $plaintext : NULL;
  }

  /**
   * Derives a site-local key for short-lived idempotency replay tokens.
   */
  private function encryptionKey(): string {
    $hash_salt = Settings::getHashSalt();
    if ($hash_salt === '') {
      throw new \RuntimeException('Drupal hash_salt is not configured. Cannot encrypt idempotency tokens.');
    }

    return hash_hkdf('sha256', $hash_salt, 32, 'beantragt_public_application_idempotency_v1');
  }

  /**
   * Hashes a decoded JSON payload after recursively sorting object keys.
   *
   * @param array<string, mixed> $body
   *   Decoded request body.
   *
   * @throws \JsonException
   *   When canonical JSON encoding fails.
   */
  private function canonicalPayloadHash(array $body): string {
    return hash(
      'sha256',
      json_encode(
        $this->canonicalJsonValue($body),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
      ),
    );
  }

  /**
   * Recursively normalizes decoded JSON arrays for stable hashing.
   */
  private function canonicalJsonValue(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    $normalized = [];
    foreach ($value as $key => $item) {
      $normalized[$key] = $this->canonicalJsonValue($item);
    }
    if (!array_is_list($normalized)) {
      ksort($normalized, SORT_STRING);
    }
    return $normalized;
  }

}
