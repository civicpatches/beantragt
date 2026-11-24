<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\Serializer\CompactSerializer;

/**
 * Encrypts FIT-Connect payloads using RSA-OAEP-256 and A256GCM without zip.
 */
final class FitConnectJweEncrypter {

  /**
   * Checks the destination encryption key before any submission is created.
   *
   * @param array<string, mixed> $jwk
   *   The destination's public JWK.
   * @param string $kid
   *   The encryption key ID announced by the destination.
   */
  public function validateKey(array $jwk, string $kid): void {
    if (($jwk['kty'] ?? NULL) !== 'RSA' || ($jwk['alg'] ?? NULL) !== 'RSA-OAEP-256'
      || !is_array($jwk['key_ops'] ?? NULL) || !in_array('wrapKey', $jwk['key_ops'], TRUE)
      || $kid === '' || ($jwk['kid'] ?? NULL) !== $kid
      || !is_string($jwk['n'] ?? NULL) || !is_string($jwk['e'] ?? NULL)) {
      throw new FitConnectTransportException('fit_connect.invalid_encryption_key', 'FIT-Connect destination encryption key is invalid.');
    }
    $modulus = $this->decodeInteger($jwk['n']);
    $exponent = $this->decodeInteger($jwk['e']);
    if ($modulus === '' || $exponent === '') {
      throw new FitConnectTransportException('fit_connect.invalid_encryption_key', 'FIT-Connect destination encryption key is malformed.');
    }
    // Count significant bits, so leading zero bytes cannot bypass the minimum.
    $bits = (strlen($modulus) - 1) * 8;
    $first = ord($modulus[0]);
    while ($first > 0) {
      $bits++;
      $first >>= 1;
    }
    if ($bits < 4096) {
      throw new FitConnectTransportException('fit_connect.encryption_key_too_small', 'FIT-Connect encryption requires an RSA key of at least 4096 bits.');
    }
  }

  /**
   * Encrypts exact bytes to compact JWE serialization.
   *
   * @param string $bytes
   *   The exact plaintext bytes.
   * @param array<string, mixed> $jwk
   *   The validated destination public JWK.
   * @param string $cty
   *   The plaintext MIME type.
   */
  public function encrypt(string $bytes, array $jwk, string $cty = 'application/json'): string {
    $this->validateKey($jwk, is_string($jwk['kid'] ?? NULL) ? $jwk['kid'] : '');
    try {
      // Pass only public key material to the crypto library.
      $key = new JWK(array_intersect_key($jwk, array_flip(['kty', 'n', 'e', 'alg', 'kid', 'key_ops'])));
      $builder = new JWEBuilder(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
      $jwe = $builder->create()
        ->withPayload($bytes)
        ->withSharedProtectedHeader([
          'alg' => 'RSA-OAEP-256',
          'enc' => 'A256GCM',
          'kid' => $jwk['kid'],
          'cty' => $cty,
        ])
        ->addRecipient($key)
        ->build();
      return (new CompactSerializer())->serialize($jwe, 0);
    }
    catch (\Throwable) {
      throw new FitConnectTransportException('fit_connect.encryption_failed', 'FIT-Connect payload encryption failed.');
    }
  }

  /**
   * Decodes a base64url unsigned integer, ignoring leading zero bytes.
   */
  private function decodeInteger(string $value): string {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
      return '';
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), TRUE);
    return $decoded === FALSE ? '' : ltrim($decoded, "\0");
  }

}
