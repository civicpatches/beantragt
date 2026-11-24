<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer as JweSerializer;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer as JwsSerializer;

/**
 * Verifies encrypted decision replies without network access or side effects.
 */
final class FitConnectReplyVerifier {

  public const BUSINESS_SCHEMA = 'urn:beantragt:external-response:1';
  public const METADATA_SCHEMA = 'https://schema.fitko.de/fit-connect/metadata/2.1.0/metadata.schema.json';
  public const MAX_DOCUMENTS = 10;
  public const MAX_DOCUMENT_BYTES = 10000000;
  public const MAX_TOTAL_DOCUMENT_BYTES = 20000000;

  /**
   * Outcomes a reply may carry (contract 1, optional key "outcome").
   */
  public const OUTCOMES = ['approved', 'rejected', 'filed'];

  /**
   * Longest outcome label a reply may carry (optional key "outcomeLabel").
   */
  public const MAX_OUTCOME_LABEL_LENGTH = 40;
  private const EVENT_PREFIX = 'https://schema.fitko.de/fit-connect/events/';

  /**
   * Verifies a reply and returns only validated data and PDF bytes.
   */
  public function verify(array $envelope, array $attachmentJwes, array $eventSets, array $jwks, array $privateJwk, FitConnectSettings $settings, string $expectedCaseId): array {
    $settings->assertTestTransportAllowed();
    $reply_id = $this->id($envelope['replyId'] ?? NULL);
    $case_id = $this->id($envelope['caseId'] ?? NULL);
    if ($case_id !== $this->id($expectedCaseId)
      || !is_array($envelope['attachments'] ?? NULL) || !array_is_list($envelope['attachments'])
      || count($envelope['attachments']) < 1 || count($envelope['attachments']) > self::MAX_DOCUMENTS
      || count($eventSets) < 1 || count($eventSets) > 1000) {
      $this->fail();
    }
    $attachment_ids = array_map($this->id(...), $envelope['attachments']);
    if (count(array_unique($attachment_ids)) !== count($attachment_ids)) {
      $this->fail();
    }
    $this->sameKeys($attachment_ids, array_keys($attachmentJwes));
    $authentication_tags = [
      'metadata' => $this->tag($envelope['encryptedMetadata'] ?? NULL),
      'data' => $this->tag($envelope['encryptedData'] ?? NULL),
      'attachments' => [],
    ];
    foreach ($attachmentJwes as $id => $jwe) {
      $authentication_tags['attachments'][$id] = $this->tag($jwe);
    }
    $submit_count = 0;
    foreach ($eventSets as $set) {
      if (!is_string($set)) {
        $this->fail();
      }
      $claims = $this->verifySet($set, $jwks, $settings, $reply_id, $case_id);
      if (isset($claims['events'][self::EVENT_PREFIX . 'submit-reply'])) {
        $submit_count++;
        $tags = $claims['events'][self::EVENT_PREFIX . 'submit-reply']['authenticationTags'] ?? NULL;
        if (!is_array($tags) || $this->canonicalTags($tags) !== $this->canonicalTags($authentication_tags)) {
          $this->fail();
        }
      }
    }
    if ($submit_count !== 1) {
      $this->fail();
    }
    $metadata = $this->decode($this->decrypt($envelope['encryptedMetadata'], $privateJwk, 'application/json', 1000000));
    $content = $metadata['contentStructure'] ?? NULL;
    if (($metadata['$schema'] ?? NULL) !== self::METADATA_SCHEMA || !is_array($content)
      || !is_array($content['data'] ?? NULL) || !is_array($content['attachments'] ?? NULL)
      || !array_is_list($content['attachments']) || count($content['attachments']) !== count($attachment_ids)
      || ($content['data']['submissionSchema']['schemaUri'] ?? NULL) !== self::BUSINESS_SCHEMA
      || ($content['data']['submissionSchema']['mimeType'] ?? NULL) !== 'application/json') {
      $this->fail();
    }
    $data_bytes = $this->decrypt($envelope['encryptedData'], $privateJwk, 'application/json', 65536);
    $this->verifyHash($data_bytes, $content['data']['hash'] ?? NULL);
    $data = $this->decode($data_bytes);
    $keys = array_keys($data);
    $base = ['type', 'title', 'externalReference', 'releaseStatus'];
    // Exactly the base keys, the base keys plus outcome, or the base keys
    // plus outcome and outcomeLabel (contract 1, minor additions). The label
    // is the case system's own word for the outcome ("Festsetzung").
    if (!in_array('outcome', $keys, TRUE)) {
      $this->sameKeys($base, $keys);
    }
    else {
      $expected = in_array('outcomeLabel', $keys, TRUE) ? [...$base, 'outcome', 'outcomeLabel'] : [...$base, 'outcome'];
      $this->sameKeys($expected, $keys);
      if (array_key_exists('outcomeLabel', $data)) {
        $this->safeText($data['outcomeLabel'], self::MAX_OUTCOME_LABEL_LENGTH);
        // Same grammar as the connection's own words: no markup, no format
        // characters, no surrounding spaces.
        if (trim($data['outcomeLabel']) !== $data['outcomeLabel'] || preg_match('/[<>\p{C}]/u', $data['outcomeLabel'])) {
          $this->fail();
        }
      }
      if (!in_array($data['outcome'], self::OUTCOMES, TRUE)) {
        $this->fail();
      }
      // A filing receipt is never a document for the applicant.
      if ($data['outcome'] === 'filed' && $data['releaseStatus'] !== 'pending') {
        $this->fail();
      }
    }
    if (($data['type'] ?? NULL) !== 'decision'
      || !in_array($data['releaseStatus'] ?? NULL, ['pending', 'released'], TRUE)) {
      $this->fail();
    }
    $this->safeText($data['title'], 200);
    $this->safeText($data['externalReference'], 200);
    $documents = [];
    $manifest_ids = [];
    $total_bytes = 0;
    foreach ($content['attachments'] as $attachment) {
      if (!is_array($attachment)) {
        $this->fail();
      }
      $id = $this->id($attachment['attachmentId'] ?? NULL);
      if (!isset($attachmentJwes[$id]) || in_array($id, $manifest_ids, TRUE)
        || ($attachment['mimeType'] ?? NULL) !== 'application/pdf'
        || !in_array($attachment['purpose'] ?? NULL, ['form', 'attachment', 'report', 'data'], TRUE)
        || isset($attachment['fragments'])) {
        $this->fail();
      }
      $manifest_ids[] = $id;
      $filename = $attachment['filename'] ?? 'bescheid.pdf';
      $this->safeText($filename, 200);
      if (str_contains($filename, '/') || str_contains($filename, '\\') || !preg_match('/\.pdf$/iD', $filename)) {
        $this->fail();
      }
      $bytes = $this->decrypt($attachmentJwes[$id], $privateJwk, 'application/pdf', self::MAX_DOCUMENT_BYTES);
      $total_bytes += strlen($bytes);
      if ($total_bytes > self::MAX_TOTAL_DOCUMENT_BYTES || !preg_match('/^%PDF-1\.[0-9]|^%PDF-2\.0/', $bytes)
        || !str_contains(substr($bytes, -1024), '%%EOF')) {
        $this->fail();
      }
      $this->verifyHash($bytes, $attachment['hash'] ?? NULL);
      $documents[] = [
        'bytes' => $bytes,
        'filename' => $filename,
        'mimeType' => 'application/pdf',
        'sha512' => hash('sha512', $bytes),
      ];
    }
    $this->sameKeys($attachment_ids, $manifest_ids);
    return ['data' => $data, 'documents' => $documents, 'authenticationTags' => $authentication_tags];
  }

  /**
   * Verifies the server's receipt after accepting a durably persisted reply.
   */
  public function verifyAcceptance(string $set, array $jwks, FitConnectSettings $settings, string $replyId, string $caseId, ?array $expectedTags = NULL): void {
    $settings->assertTestTransportAllowed();
    $claims = $this->verifySet($set, $jwks, $settings, $this->id($replyId), $this->id($caseId));
    if (!isset($claims['events'][self::EVENT_PREFIX . 'accept-reply'])) {
      $this->fail();
    }
    $tags = $this->canonicalTags($claims['events'][self::EVENT_PREFIX . 'accept-reply']['authenticationTags'] ?? []);
    if ($expectedTags !== NULL && $tags !== $this->canonicalTags($expectedTags)) {
      $this->fail();
    }
  }

  /**
   * Validates fixed SET claims and a signature from the trusted JWKS only.
   */
  private function verifySet(string $set, array $jwks, FitConnectSettings $settings, string $reply_id, string $case_id): array {
    try {
      if (strlen($set) > 65536 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $set)) {
        $this->fail();
      }
      $jws = (new JwsSerializer())->unserialize($set);
      $header = $jws->getSignature(0)->getProtectedHeader();
      if (($header['typ'] ?? NULL) !== 'secevent+jwt' || ($header['alg'] ?? NULL) !== 'PS512'
        || !is_string($header['kid'] ?? NULL) || $header['kid'] === ''
        || array_diff(array_keys($header), ['typ', 'alg', 'kid']) !== []) {
        $this->fail();
      }
      $matches = [];
      foreach ($jwks['keys'] ?? [] as $key) {
        if (is_array($key) && ($key['kid'] ?? NULL) === $header['kid']) {
          $matches[] = $key;
        }
      }
      if (count($matches) !== 1) {
        $this->fail();
      }
      $key = $matches[0];
      if (($key['kty'] ?? NULL) !== 'RSA' || ($key['alg'] ?? NULL) !== 'PS512'
        || ($key['key_ops'] ?? NULL) !== ['verify'] || ($key['e'] ?? NULL) !== 'AQAB'
        || !is_string($key['n'] ?? NULL) || $this->bits($key['n']) < 3072) {
        $this->fail();
      }
      $public = new JWK(array_intersect_key($key, array_flip(['kty', 'n', 'e', 'alg', 'kid', 'key_ops'])));
      if (!(new JWSVerifier(new AlgorithmManager([new PS512()])))->verifyWithKey($jws, $public, 0)) {
        $this->fail();
      }
      $claims = $this->decode($jws->getPayload() ?? '');
      if (!$this->trustedIssuer($claims['iss'] ?? NULL, $settings)
        || !is_string($claims['sub'] ?? NULL) || strtolower($claims['sub']) !== 'reply:' . $reply_id
        || !is_string($claims['txn'] ?? NULL) || strtolower($claims['txn']) !== 'case:' . $case_id
        || !is_int($claims['iat'] ?? NULL) || $claims['iat'] < 1 || $claims['iat'] > time() + 300
        || !is_array($claims['events'] ?? NULL) || count($claims['events']) !== 1) {
        $this->fail();
      }
      $this->id($claims['jti'] ?? NULL);
      $event = array_key_first($claims['events']);
      $allowed_events = [
        'create-reply', 'submit-reply', 'notify-reply',
        'accept-reply', 'reject-reply', 'delete-reply',
      ];
      if (!in_array($event, array_map(static fn(string $name): string => self::EVENT_PREFIX . $name, $allowed_events), TRUE)
        || !is_array($claims['events'][$event])) {
        $this->fail();
      }
      if (isset($claims['$schema']) && (!is_string($claims['$schema']) || !preg_match('#^https://schema\.fitko\.de/fit-connect/set-payload/1\.[0-2]\.[0-9]+/set-payload\.schema\.json$#D', $claims['$schema']))) {
        $this->fail();
      }
      return $claims;
    }
    catch (\Throwable) {
      $this->fail();
    }
  }

  /**
   * Authenticates and decrypts a compact JWE with the exact case key.
   */
  private function decrypt(string $serialized, array $private, string $mime, int $max_bytes): string {
    try {
      $this->tag($serialized);
      $jwe = (new JweSerializer())->unserialize($serialized);
      $header = $jwe->getSharedProtectedHeader();
      if (($header['alg'] ?? NULL) !== 'RSA-OAEP-256' || ($header['enc'] ?? NULL) !== 'A256GCM'
        || ($header['cty'] ?? NULL) !== $mime || !is_string($private['kid'] ?? NULL) || $private['kid'] === ''
        || ($header['kid'] ?? NULL) !== $private['kid']
        || array_diff(array_keys($header), ['alg', 'enc', 'cty', 'kid']) !== []
        || ($private['kty'] ?? NULL) !== 'RSA' || ($private['alg'] ?? NULL) !== 'RSA-OAEP-256'
        || ($private['e'] ?? NULL) !== 'AQAB' || !is_string($private['n'] ?? NULL) || $this->bits($private['n']) < 4096
        || !is_string($private['d'] ?? NULL)) {
        $this->fail();
      }
      // Public wrapKey metadata must not prohibit the private unwrap operation.
      $private_parameters = ['kty', 'n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi', 'alg', 'kid'];
      $key = new JWK(array_intersect_key($private, array_flip($private_parameters)));
      $decrypter = new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256GCM()]));
      if (!$decrypter->decryptUsingKey($jwe, $key, 0)) {
        $this->fail();
      }
      $bytes = $jwe->getPayload();
      if (!is_string($bytes) || strlen($bytes) > $max_bytes) {
        $this->fail();
      }
      return $bytes;
    }
    catch (\Throwable) {
      $this->fail();
    }
  }

  /**
   * Extracts the authenticated tag only from bounded compact JWE syntax.
   */
  private function tag(mixed $serialized): string {
    if (!is_string($serialized) || strlen($serialized) > FitConnectReplyApiClient::MAX_ATTACHMENT_BYTES
      || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.([A-Za-z0-9_-]{22})$/D', $serialized, $matches)) {
      $this->fail();
    }
    return $matches[1];
  }

  /**
   * Normalizes tag-map ordering, never weakening identity or completeness.
   */
  private function canonicalTags(array $tags): array {
    $this->sameKeys(['metadata', 'data', 'attachments'], array_keys($tags));
    if (!is_array($tags['attachments'])) {
      $this->fail();
    }
    foreach ([$tags['metadata'], $tags['data'], ...array_values($tags['attachments'])] as $tag) {
      if (!is_string($tag) || !preg_match('/^[A-Za-z0-9_-]{22}$/D', $tag)) {
        $this->fail();
      }
    }
    foreach (array_keys($tags['attachments']) as $id) {
      $this->id($id);
    }
    ksort($tags['attachments']);
    return ['metadata' => $tags['metadata'], 'data' => $tags['data'], 'attachments' => $tags['attachments']];
  }

  /**
   * Validates an exact plaintext hash.
   */
  private function verifyHash(string $bytes, mixed $hash): void {
    if (!is_array($hash) || ($hash['type'] ?? NULL) !== 'sha512' || !is_string($hash['content'] ?? NULL)
      || !preg_match('/^[a-f0-9]{128}$/D', $hash['content']) || !hash_equals(hash('sha512', $bytes), $hash['content'])) {
      $this->fail();
    }
  }

  /**
   * Decodes a UTF-8 JSON object with bounded nesting.
   */
  private function decode(string $bytes): array {
    try {
      $value = json_decode($bytes, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable) {
      $this->fail();
    }
    if (!is_array($value) || array_is_list($value)) {
      $this->fail();
    }
    return $value;
  }

  /**
   * Requires safe, bounded human-readable text.
   */
  private function safeText(mixed $value, int $max): void {
    if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
      $this->fail();
    }
  }

  /**
   * Compares complete unordered identifier/key sets.
   */
  private function sameKeys(array $expected, array $actual): void {
    sort($expected);
    sort($actual);
    if ($expected !== $actual) {
      $this->fail();
    }
  }

  /**
   * Validates and normalizes UUIDs.
   */
  private function id(mixed $id): string {
    if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) {
      $this->fail();
    }
    return strtolower($id);
  }

  /**
   * Counts significant RSA modulus bits, rejecting malformed encodings.
   */
  private function bits(string $modulus): int {
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $modulus)) {
      return 0;
    }
    $decoded = base64_decode(strtr($modulus, '-_', '+/'), TRUE);
    $decoded = $decoded === FALSE ? '' : ltrim($decoded, "\0");
    if ($decoded === '') {
      return 0;
    }
    $bits = (strlen($decoded) - 1) * 8;
    for ($first = ord($decoded[0]); $first > 0; $first >>= 1) {
      $bits++;
    }
    return $bits;
  }

  /**
   * Accepts the submission API base or exactly its origin as SET issuer.
   *
   * Both forms name the same pinned host. The submission API key set still
   * determines whether the signature is valid.
   */
  private function trustedIssuer(mixed $issuer, FitConnectSettings $settings): bool {
    if (!is_string($issuer)) {
      return FALSE;
    }
    $base = $settings->submissionApiBase;
    $parts = parse_url($base);
    $origin = is_array($parts) && isset($parts['scheme'], $parts['host'])
      ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
      : NULL;
    return $issuer === $base || ($origin !== NULL && $issuer === $origin);
  }

  /**
   * Rejects untrusted input without returning content or cryptographic details.
   */
  private function fail(): never {
    throw new FitConnectTransportException('fit_connect.reply_verification_failed', 'FIT-Connect reply verification failed.');
  }

}
