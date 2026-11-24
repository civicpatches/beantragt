<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectJweEncrypter;
use Drupal\beantragt_delivery\Service\FitConnectReplyVerifier;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Exercises real encryption/signature verification and hostile reply inputs.
 *
 * @group beantragt_delivery
 */
final class FitConnectReplyVerifierTest extends UnitTestCase {

  private const REPLY = '11111111-1111-4111-8111-111111111111';
  private const CASE_ID = '22222222-2222-4222-8222-222222222222';
  private const ATTACHMENT = '33333333-3333-4333-8333-333333333333';
  private const EVENT = 'https://schema.fitko.de/fit-connect/events/';

  /**
   * Validates exact data, PDF, tags and the independent service receipt.
   */
  public function testValidReplyAndReceipt(): void {
    $fixture = $this->fixture();
    $result = $this->verify($fixture);
    $this->assertSame('decision', $result['data']['type']);
    $this->assertSame('released', $result['data']['releaseStatus']);
    $this->assertSame($fixture['pdf'], $result['documents'][0]['bytes']);
    $this->assertSame('bescheid.pdf', $result['documents'][0]['filename']);
    $this->assertSame(hash('sha512', $fixture['pdf']), $result['documents'][0]['sha512']);
    $this->assertSame($fixture['tags'], $result['authenticationTags']);
    $claims = $fixture['claims'];
    $claims['events'] = [self::EVENT . 'accept-reply' => ['authenticationTags' => $fixture['tags']]];
    (new FitConnectReplyVerifier())->verifyAcceptance($this->sign($claims), $fixture['jwks'], $this->settings(), self::REPLY, self::CASE_ID);
    $this->addToAssertionCount(1);
  }

  /**
   * The submission API origin is the current TEST issuer, as is the API base.
   */
  public function testAcceptsSubmissionApiOriginAsIssuer(): void {
    $fixture = $this->fixture();
    $fixture['claims']['iss'] = 'https://test.fit-connect.fitko.dev';
    $fixture['events'] = [$this->sign($fixture['claims'])];
    $this->assertSame('decision', $this->verify($fixture)['data']['type']);
  }

  /**
   * Other paths, schemes and lookalike hosts never pass as the issuer.
   */
  public function testRejectsIssuerVariantsOutsideTheApiOrigin(): void {
    foreach ([
      'https://test.fit-connect.fitko.dev/other-api',
      'http://test.fit-connect.fitko.dev',
      'https://test.fit-connect.fitko.dev.attacker.example',
      'https://test.fit-connect.fitko.dev:8443',
      'https://test.fit-connect.fitko.dev:443',
      'https://TEST.fit-connect.fitko.dev',
      'https://test.fit-connect.fitko.dev/',
      443,
      ['https://test.fit-connect.fitko.dev'],
    ] as $issuer) {
      $fixture = $this->fixture();
      $fixture['claims']['iss'] = $issuer;
      $fixture['events'] = [$this->sign($fixture['claims'])];
      $this->assertRejected($fixture);
    }
  }

  /**
   * Altered signatures, bindings, keys, event multiplicity and tags fail shut.
   */
  public function testRejectsEventAndEnvelopeTampering(): void {
    $base = $this->fixture();
    $mutations = [
      static function (array &$f): void {
        $f['envelope']['caseId'] = self::REPLY;
      },
      static function (array &$f): void {
        $f['envelope']['attachments'][] = self::ATTACHMENT;
      },
      static function (array &$f): void {
        $f['attachments'] = [];
      },
      static function (array &$f): void {
        $f['events'][] = $f['events'][0];
      },
      static function (array &$f): void {
        $f['events'] = [];
      },
      static function (array &$f): void {
        $parts = explode('.', $f['events'][0]);
        $parts[2][0] = $parts[2][0] === 'A' ? 'B' : 'A';
        $f['events'][0] = implode('.', $parts);
      },
      static function (array &$f): void {
        $f['jwks']['keys'][0]['alg'] = 'RS512';
      },
      static function (array &$f): void {
        $f['jwks']['keys'][] = $f['jwks']['keys'][0];
      },
      static function (array &$f): void {
        $f['private']['kid'] = 'wrong-private-key';
      },
      function (array &$f): void {
        $f['claims']['iss'] = 'https://attacker.example';
        $f['events'] = [$this->sign($f['claims'])];
      },
      function (array &$f): void {
        $f['claims']['sub'] = 'reply:' . self::CASE_ID;
        $f['events'] = [$this->sign($f['claims'])];
      },
      function (array &$f): void {
        $f['claims']['txn'] = 'case:' . self::REPLY;
        $f['events'] = [$this->sign($f['claims'])];
      },
      function (array &$f): void {
        $f['claims']['iat'] = time() + 3600;
        $f['events'] = [$this->sign($f['claims'])];
      },
      function (array &$f): void {
        $f['claims']['events'][self::EVENT . 'submit-reply']['authenticationTags']['metadata'] = str_repeat('A', 22);
        $f['events'] = [$this->sign($f['claims'])];
      },
      function (array &$f): void {
        $f['claims']['events'][self::EVENT . 'submit-reply']['authenticationTags']['attachments'] = [];
        $f['events'] = [$this->sign($f['claims'])];
      },
    ];
    foreach ($mutations as $mutation) {
      $fixture = $base;
      $mutation($fixture);
      $this->assertRejected($fixture);
    }
  }

  /**
   * Even genuinely encrypted and signed malformed content must be rejected.
   */
  public function testRejectsAuthenticatedInvalidContent(): void {
    foreach ([
      static function (array &$metadata, array &$data, string &$pdf): void {
        $metadata['$schema'] = 'https://attacker.example/schema';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $metadata['contentStructure']['data']['submissionSchema']['schemaUri'] = 'urn:unsupported';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $metadata['contentStructure']['data']['hash']['content'] = str_repeat('0', 128);
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $metadata['contentStructure']['attachments'][0]['hash']['content'] = str_repeat('0', 128);
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $metadata['contentStructure']['attachments'][0]['filename'] = '../private.pdf';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $metadata['contentStructure']['attachments'][0]['mimeType'] = 'text/html';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['releaseStatus'] = 'approved';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['title'] = ['not text'];
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['externalReference'] = str_repeat('x', 201);
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['untrustedExtra'] = TRUE;
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $pdf = '<html>Not a PDF</html>';
      },
    ] as $mutate) {
      $this->assertRejected($this->fixture($mutate));
    }
  }

  /**
   * Contract 1 accepts the optional outcome key; the old form stays valid.
   */
  public function testOutcomeIsOptionalAndValidated(): void {
    $legacy = $this->verify($this->fixture());
    $this->assertArrayNotHasKey('outcome', $legacy['data']);
    $this->assertSame(['type', 'title', 'externalReference', 'releaseStatus'], array_keys($legacy['data']));
    foreach (FitConnectReplyVerifier::OUTCOMES as $outcome) {
      $result = $this->verify($this->fixture(static function (array &$metadata, array &$data, string &$pdf) use ($outcome): void {
        $data['outcome'] = $outcome;
        $data['releaseStatus'] = 'pending';
      }));
      $this->assertSame($outcome, $result['data']['outcome']);
      $this->assertSame('decision', $result['data']['type']);
    }
    $this->assertSame('approved', $this->verify($this->fixture(static function (array &$metadata, array &$data, string &$pdf): void {
      $data['outcome'] = 'approved';
    }))['data']['outcome']);
    foreach ([
      // Unknown outcome.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'granted';
      },
      // Not a string.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = NULL;
      },
      // A sixth key next to the outcome.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['reason'] = 'Free text';
      },
      // A filing receipt is never released to the applicant.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'filed';
        $data['releaseStatus'] = 'released';
      },
      // The type stays decision.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'filed';
        $data['releaseStatus'] = 'pending';
        $data['type'] = 'filing_receipt';
      },
    ] as $mutate) {
      $this->assertRejected($this->fixture($mutate));
    }
  }

  /**
   * The case system may name the outcome in its own words, only with one.
   */
  public function testOutcomeLabelIsOptionalAndValidated(): void {
    $result = $this->verify($this->fixture(static function (array &$metadata, array &$data, string &$pdf): void {
      $data['outcome'] = 'approved';
      $data['outcomeLabel'] = 'Festsetzung';
    }));
    $this->assertSame('Festsetzung', $result['data']['outcomeLabel']);
    foreach ([
      // A label without an outcome.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcomeLabel'] = 'Festsetzung';
      },
      // Empty, too long, not a string, control characters.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = ' ';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = str_repeat('a', FitConnectReplyVerifier::MAX_OUTCOME_LABEL_LENGTH + 1);
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = ['Festsetzung'];
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = "Fest\nsetzung";
      },
      // Markup and surrounding spaces, like connection words.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = '<b>Festsetzung</b>';
      },
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = ' Festsetzung';
      },
      // Still no free keys next to outcome and label.
      static function (array &$metadata, array &$data, string &$pdf): void {
        $data['outcome'] = 'approved';
        $data['outcomeLabel'] = 'Festsetzung';
        $data['reason'] = 'Free text';
      },
    ] as $mutate) {
      $this->assertRejected($this->fixture($mutate));
    }
  }

  /**
   * A valid submit event cannot masquerade as an acceptance receipt.
   */
  public function testRejectsWrongReceiptEvent(): void {
    $fixture = $this->fixture();
    $this->expectException(FitConnectTransportException::class);
    (new FitConnectReplyVerifier())->verifyAcceptance($fixture['events'][0], $fixture['jwks'], $this->settings(), self::REPLY, self::CASE_ID);
  }

  /**
   * Verifies one fixture with the public API.
   */
  private function verify(array $f): array {
    return (new FitConnectReplyVerifier())->verify($f['envelope'], $f['attachments'], $f['events'], $f['jwks'], $f['private'], $this->settings(), self::CASE_ID);
  }

  /**
   * Asserts errors cannot expose document contents or cryptographic internals.
   */
  private function assertRejected(array $fixture): void {
    try {
      $this->verify($fixture);
      $this->fail('Expected reply rejection.');
    }
    catch (FitConnectTransportException $e) {
      $this->assertSame('fit_connect.reply_verification_failed', $e->errorCode);
      $this->assertNull($e->getPrevious());
    }
  }

  /**
   * Builds a synthetic complete reply; mutation occurs before encryption.
   */
  private function fixture(?callable $mutate = NULL): array {
    $private = $this->encryptionKey();
    $public = $private->toPublic()->all();
    $public['key_ops'] = ['wrapKey'];
    $pdf = "%PDF-1.7\nSynthetic decision\n%%EOF\n";
    $data = [
      'type' => 'decision',
      'title' => 'Synthetischer Bescheid',
      'externalReference' => 'DEMO-115',
      'releaseStatus' => 'released',
    ];
    $metadata = [
      '$schema' => FitConnectReplyVerifier::METADATA_SCHEMA,
      'contentStructure' => [
        'data' => [
          'submissionSchema' => [
            'schemaUri' => FitConnectReplyVerifier::BUSINESS_SCHEMA,
            'mimeType' => 'application/json',
          ],
          'hash' => ['type' => 'sha512', 'content' => hash('sha512', json_encode($data, JSON_THROW_ON_ERROR))],
        ],
        'attachments' => [[
          'attachmentId' => self::ATTACHMENT,
          'purpose' => 'report',
          'filename' => 'bescheid.pdf',
          'mimeType' => 'application/pdf',
          'hash' => ['type' => 'sha512', 'content' => hash('sha512', $pdf)],
        ],
        ],
      ],
    ];
    if ($mutate !== NULL) {
      $previous_data = $data;
      $previous_pdf = $pdf;
      $mutate($metadata, $data, $pdf);
      if ($data !== $previous_data) {
        $metadata['contentStructure']['data']['hash']['content'] = hash('sha512', json_encode($data, JSON_THROW_ON_ERROR));
      }
      if ($pdf !== $previous_pdf) {
        $metadata['contentStructure']['attachments'][0]['hash']['content'] = hash('sha512', $pdf);
      }
    }
    $encrypter = new FitConnectJweEncrypter();
    $envelope = [
      'replyId' => self::REPLY,
      'caseId' => self::CASE_ID,
      'attachments' => [self::ATTACHMENT],
      'encryptedMetadata' => $encrypter->encrypt(json_encode($metadata, JSON_THROW_ON_ERROR), $public),
      'encryptedData' => $encrypter->encrypt(json_encode($data, JSON_THROW_ON_ERROR), $public),
    ];
    $attachments = [self::ATTACHMENT => $encrypter->encrypt($pdf, $public, 'application/pdf')];
    $tags = [
      'metadata' => explode('.', $envelope['encryptedMetadata'])[4],
      'data' => explode('.', $envelope['encryptedData'])[4],
      'attachments' => [self::ATTACHMENT => explode('.', $attachments[self::ATTACHMENT])[4]],
    ];
    $claims = [
      '$schema' => 'https://schema.fitko.de/fit-connect/set-payload/1.2.2/set-payload.schema.json',
      'iss' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'sub' => 'reply:' . self::REPLY,
      'txn' => 'case:' . self::CASE_ID,
      'jti' => '44444444-4444-4444-8444-444444444444',
      'iat' => time(),
      'events' => [self::EVENT . 'submit-reply' => ['authenticationTags' => $tags]],
    ];
    $signing_public = $this->signingKey()->toPublic()->all();
    $signing_public['key_ops'] = ['verify'];
    return [
      'envelope' => $envelope,
      'attachments' => $attachments,
      'events' => [$this->sign($claims)],
      'jwks' => ['keys' => [$signing_public]],
      'private' => $private->all(),
      'claims' => $claims,
      'tags' => $tags,
      'pdf' => $pdf,
    ];
  }

  /**
   * Signs service events with a freshly generated ephemeral test key.
   */
  private function sign(array $claims): string {
    $jws = (new JWSBuilder(new AlgorithmManager([new PS512()])))
      ->create()->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))
      ->addSignature($this->signingKey(), ['typ' => 'secevent+jwt', 'alg' => 'PS512', 'kid' => 'unit-signing-key'])->build();
    return (new CompactSerializer())->serialize($jws, 0);
  }

  /**
   * Reuses a runtime-only encryption key across tampering examples.
   */
  private function encryptionKey(): JWK {
    static $key;
    if ($key === NULL) {
      $key = JWKFactory::createRSAKey(4096, ['kid' => 'unit-encryption-key', 'alg' => 'RSA-OAEP-256']);
    }
    return $key;
  }

  /**
   * Reuses a runtime-only signing key across tampering examples.
   */
  private function signingKey(): JWK {
    static $key;
    if ($key === NULL) {
      $key = JWKFactory::createRSAKey(3072, ['kid' => 'unit-signing-key', 'alg' => 'PS512']);
    }
    return $key;
  }

  /**
   * Returns TEST configuration without environment reads.
   */
  private function settings(): FitConnectSettings {
    return new FitConnectSettings('TEST', 'unit-client', 'UNIT_SECRET', 'unit-secret', FitConnectSettings::DEFAULT_TEST_TOKEN_URL, FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE, FitConnectSettings::DEFAULT_TEST_DESTINATION_API_BASE, FitConnectSettings::DEFAULT_TEST_ROUTING_API_BASE, self::CASE_ID, 'urn:test:service', '000000000000', 'urn:test:schema', 'application/json', '2.1.0', FALSE);
  }

}
