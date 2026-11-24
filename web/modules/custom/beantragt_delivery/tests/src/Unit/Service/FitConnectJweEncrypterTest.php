<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectJweEncrypter;
use Drupal\Tests\UnitTestCase;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Verifies actual JWE cryptography and key validation without stored secrets.
 *
 * @group beantragt_delivery
 */
final class FitConnectJweEncrypterTest extends UnitTestCase {

  /**
   * Exact binary payloads survive encryption and the header prohibits zip.
   */
  public function testRoundTrip(): void {
    $private = JWKFactory::createRSAKey(4096, ['kid' => 'unit-key', 'alg' => 'RSA-OAEP-256']);
    $public = $private->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $encrypter = new FitConnectJweEncrypter();
    foreach (['application/json' => '{"name":"Müller"}', 'application/pdf' => "%PDF-1.7\n\0\xff\x01"] as $mime_type => $bytes) {
      $serialized = $encrypter->encrypt($bytes, $public, $mime_type);
      $this->assertCount(5, explode('.', $serialized));
      $jwe = (new CompactSerializer())->unserialize($serialized);
      $this->assertSame([
        'alg' => 'RSA-OAEP-256',
        'enc' => 'A256GCM',
        'kid' => 'unit-key',
        'cty' => $mime_type,
      ], $jwe->getSharedProtectedHeader());
      $decrypter = new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
      $this->assertTrue($decrypter->decryptUsingKey($jwe, new JWK($private->all()), 0));
      $this->assertSame($bytes, $jwe->getPayload());
      $this->assertNotSame($serialized, $encrypter->encrypt($bytes, $public, $mime_type));
    }
  }

  /**
   * Invalid algorithms, operations, key IDs and undersized moduli are rejected.
   */
  public function testInvalidKeysFailClosed(): void {
    $key = [
      'kty' => 'RSA',
      'alg' => 'RSA-OAEP-256',
      'kid' => 'unit-key',
      'key_ops' => ['wrapKey'],
      'n' => rtrim(strtr(base64_encode("\x80" . str_repeat("\x01", 511)), '+/', '-_'), '='),
      'e' => 'AQAB',
    ];
    $encrypter = new FitConnectJweEncrypter();
    $encrypter->validateKey($key, 'unit-key');
    $invalid = [
      ['kty' => 'EC'],
      ['alg' => 'RSA-OAEP'],
      ['key_ops' => ['encrypt']],
      ['key_ops' => NULL],
      ['kid' => 'other-key'],
      ['n' => 'invalid+encoding'],
      ['e' => ''],
      [
        'n' => rtrim(strtr(base64_encode(str_repeat("\0", 257) . str_repeat("\xff", 256)), '+/', '-_'), '='),
      ],
      [
        'n' => rtrim(strtr(base64_encode("\x7f" . str_repeat("\xff", 511)), '+/', '-_'), '='),
      ],
    ];
    foreach ($invalid as $change) {
      try {
        $encrypter->validateKey(array_replace($key, $change), 'unit-key');
        $this->fail('Expected invalid key to be rejected.');
      }
      catch (FitConnectTransportException $e) {
        $this->assertContains($e->errorCode, [
          'fit_connect.invalid_encryption_key',
          'fit_connect.encryption_key_too_small',
        ]);
        $this->assertNull($e->getPrevious());
      }
    }
  }

}
