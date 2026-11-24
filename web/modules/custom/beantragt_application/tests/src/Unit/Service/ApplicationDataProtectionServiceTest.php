<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests application field_data protection.
 */
#[CoversClass(ApplicationDataProtectionService::class)]
#[Group('beantragt_application')]
final class ApplicationDataProtectionServiceTest extends UnitTestCase {

  /**
   * Original key environment value.
   */
  private string|false $originalKey;

  /**
   * Logger mock.
   */
  private LoggerInterface $logger;

  /**
   * Service under test.
   */
  private ApplicationDataProtectionService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('a', 32));

    $this->logger = $this->createMock(LoggerInterface::class);
    $this->service = new ApplicationDataProtectionService(
          $this->createMock(EntityTypeManagerInterface::class),
          $this->logger,
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY', $this->originalKey);
    parent::tearDown();
  }

  /**
   * Every extracted value is encrypted without changing readable metadata.
   */
  public function testAnalysisResultRoundTripAndIdempotence(): void {
    $result = ['confidence' => 0.9, 'extracted' => []];
    foreach ([2340.5, 42, TRUE, FALSE, NULL, 'text'] as $index => $value) {
      $result['extracted'][] = ['key' => 'field_' . $index, 'label' => 'Label', 'confidence' => 0.8, 'value' => $value];
    }
    $protected = $this->service->protectAnalysisResult($result);
    foreach ($protected['extracted'] as $index => $item) {
      $this->assertTrue($this->service->isEncryptedValue($item['value']));
      $this->assertSame($result['extracted'][$index]['key'], $item['key']);
      $this->assertSame('Label', $item['label']);
      $this->assertSame(0.8, $item['confidence']);
    }
    $this->assertSame($protected, $this->service->protectAnalysisResult($protected));
    $this->assertSame($result, $this->service->revealAnalysisResult($protected));
  }

  /**
   * Analysis padding hides short value lengths without changing field_data.
   */
  public function testAnalysisPaddingPreservesTypesAndLegacyEnvelopes(): void {
    $result = ['extracted' => []];
    foreach ([TRUE, FALSE, NULL, 2340.5] as $index => $value) {
      $result['extracted'][] = ['key' => 'value_' . $index, 'value' => $value];
    }
    $protected = $this->service->protectAnalysisResult($result);
    foreach ($protected['extracted'] as $item) {
      $this->assertSame(32, strlen(base64_decode($item['value']['ciphertext'], TRUE)));
      $this->assertSame(44, strlen($item['value']['ciphertext']));
    }
    $this->assertSame($result, $this->service->revealAnalysisResult($protected));

    // The default encryptValue path is unchanged and old envelopes still read.
    $encrypt = new \ReflectionMethod($this->service, 'encryptValue');
    foreach ([TRUE, FALSE, NULL, 2340.5] as $value) {
      $legacy = $encrypt->invoke($this->service, $value);
      $this->assertSame(strlen(json_encode($value)), strlen(base64_decode($legacy['ciphertext'], TRUE)));
      $legacy_result = ['extracted' => [['key' => 'legacy', 'value' => $legacy]]];
      $this->assertSame($legacy_result, $this->service->protectAnalysisResult($legacy_result));
      $this->assertSame($value, $this->service->revealAnalysisResult($legacy_result)['extracted'][0]['value']);
    }
  }

  /**
   * Authenticated ciphertext cannot be revealed after modification.
   */
  public function testAnalysisResultRejectsTamperedCiphertext(): void {
    $protected = $this->service->protectAnalysisResult(['extracted' => [['key' => 'income', 'value' => 2340.5]]]);
    $protected['extracted'][0]['value']['ciphertext'] = base64_encode('tampered');
    $this->expectException(\RuntimeException::class);
    $this->service->revealAnalysisResult($protected);
  }

  /**
   * Missing configuration fails even when no plaintext values remain.
   */
  public function testAnalysisProtectionRequiresKey(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    $this->expectException(\RuntimeException::class);
    $this->service->protectAnalysisResult([]);
  }

  /**
   * Reveals go to the dedicated audit table, not to dblog.
   */
  public function testLogProtectedRevealWritesAccessEntry(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn('0f6061fa-92c0-47f4-87af-226de71d2aed');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(8);
    $access_log = $this->createMock(ProtectedAccessLog::class);
    $access_log->expects($this->once())->method('record')->with($application, $account, 'staff_api');
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');
    $service = new ApplicationDataProtectionService(
      $this->createMock(EntityTypeManagerInterface::class),
      $logger,
      $access_log,
    );

    $service->logProtectedReveal($application, $account, 'staff_api');
  }

  /**
   * Sensitive schema fields are encrypted and can be revealed.
   */
  public function testProtectDataEncryptsSensitiveFields(): void {
    $protected = $this->service->protectData(
          [
            'full_name' => 'Max Mustermann',
            'diagnosis' => 'IfSG-Meldung',
          ], $this->schema(
              [
              ['id' => 'full_name', 'label' => 'Name'],
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );

    $this->assertTrue($this->service->isEncryptedValue($protected['full_name']));
    $this->assertTrue($this->service->isEncryptedValue($protected['diagnosis']));
    $this->assertNotSame('IfSG-Meldung', $protected['diagnosis']);

    $revealed = $this->service->revealData($protected);
    $this->assertSame('Max Mustermann', $revealed['full_name']);
    $this->assertSame('IfSG-Meldung', $revealed['diagnosis']);
  }

  /**
   * Financial classification and identifier semantics protect text fields.
   */
  #[DataProvider('financialFields')]
  public function testFinancialFields(array $field, bool $protected = TRUE): void {
    $field += ['id' => 'value', 'type' => 'text', 'sensitive' => FALSE];
    $this->assertSame($protected ? [$field['id']] : [], $this->service->protectedFieldIds($this->schema([$field])));
  }

  /**
   * Provides financial field contexts and neighbouring non-tax concepts.
   */
  public static function financialFields(): iterable {
    yield 'financial' => [['privacy' => ['kind' => 'financial']]];
    yield 'IBAN field ID' => [['id' => 'iban_empfaenger']];
    yield 'tax ID field ID' => [['id' => 'steuer_id']];
    yield 'steuerliche identifikationsnummer' => [['label' => 'Steuerliche   Identifikationsnummer']];
    yield 'steuer identifikationsnummer' => [['label' => 'Steuer-Identifikationsnummer']];
    yield 'identifikationsnummer' => [['label' => 'Identifikationsnummer']];
    yield 'idnr' => [['label' => 'IdNr.']];
    yield 'steuer id' => [['label' => 'Steuer ID']];
    yield 'Steuernummer with tax ID semantics' => [['label' => 'Steuernummer nach § 139b AO']];
    yield 'separate Steuernummer concept' => [['label' => 'Steuernummer'], FALSE];
    yield 'generic number' => [['id' => 'nummer', 'label' => 'Nummer'], FALSE];
  }

  /**
   * Existing encrypted values are not encrypted again.
   */
  public function testProtectDataDoesNotDoubleEncrypt(): void {
    $protected = $this->service->protectData(
          [
            'diagnosis' => 'IfSG-Meldung',
          ], $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );
    $again = $this->service->protectData(
          $protected, $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );

    $this->assertSame($protected, $again);
  }

  /**
   * Redaction hides encrypted values without decrypting them.
   */
  public function testRedactDataHidesEncryptedValues(): void {
    $protected = $this->service->protectData(
          [
            'diagnosis' => 'IfSG-Meldung',
          ], $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );

    $redacted = $this->service->redactData($protected);
    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $redacted['diagnosis']
      );
  }

  /**
   * Schema-level redaction hides plaintext sensitive fields.
   */
  public function testRedactApplicationDataHidesPlaintextSensitiveFields(): void {
    $application = $this->createApplicationForProtection(
          '{"full_name":"Max Mustermann","diagnosis":"IfSG-Meldung"}',
          $this->schema(
              [
              ['id' => 'full_name', 'label' => 'Name'],
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => 'true'],
              ]
          ),
      );

    $redacted = $this->service->redactApplicationData($application);

    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $redacted['full_name']
      );
    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $redacted['diagnosis']
      );
  }

  /**
   * File-field metadata is sensitive unless explicitly marked otherwise.
   */
  public function testFileFieldsAreSensitiveByDefault(): void {
    $schema = $this->schema(
          [
            ['id' => 'full_name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis'],
            ['id' => 'public_upload', 'type' => 'file', 'label' => 'Oeffentliche Anlage', 'sensitive' => FALSE],
          ]
      );

    $this->assertSame(['evidence'], $this->service->sensitiveFieldIds($schema));
  }

  /**
   * File-only sensitive schemas do not trigger identity linkage protection.
   */
  public function testFileOnlySensitiveFieldsDoNotProtectIdentityLinkage(): void {
    $schema = $this->schema(
          [
            ['id' => 'full_name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'email', 'type' => 'email', 'label' => 'E-Mail', 'crm_key' => 'person.email'],
            ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis'],
          ]
      );

    $protected = $this->service->protectData(
          [
            'full_name' => 'Max Mustermann',
            'email' => 'max@example.test',
            'evidence' => [['filename' => 'scan.pdf']],
          ],
          $schema,
      );

    $this->assertSame('Max Mustermann', $protected['full_name']);
    $this->assertSame('max@example.test', $protected['email']);
    $this->assertTrue($this->service->isEncryptedValue($protected['evidence']));
  }

  /**
   * German contact and address field names are protected as identity linkage.
   */
  public function testProtectDataEncryptsGermanIdentityLinkageFields(): void {
    $protected = $this->service->protectData(
          [
            'familienname' => 'Mustermann',
            'telefonnummer' => '+49 30 23125 12345',
            'strasse_hausnummer' => 'Musterstrasse 1',
            'postleitzahl' => '12345',
            'diagnosis' => 'IfSG-Meldung',
          ],
          $this->schema(
              [
              ['id' => 'familienname', 'label' => 'Familienname'],
              ['id' => 'telefonnummer', 'label' => 'Telefonnummer'],
              ['id' => 'strasse_hausnummer', 'label' => 'Strasse und Hausnummer'],
              ['id' => 'postleitzahl', 'label' => 'Postleitzahl'],
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );

    $this->assertTrue($this->service->isEncryptedValue($protected['familienname']));
    $this->assertTrue($this->service->isEncryptedValue($protected['telefonnummer']));
    $this->assertTrue($this->service->isEncryptedValue($protected['strasse_hausnummer']));
    $this->assertTrue($this->service->isEncryptedValue($protected['postleitzahl']));
    $this->assertTrue($this->service->isEncryptedValue($protected['diagnosis']));
  }

  /**
   * Redaction hides plaintext attachment metadata mirrored into field_data.
   */
  public function testRedactApplicationDataHidesPlaintextFileMetadata(): void {
    $application = $this->createApplicationForProtection(
          '{"full_name":"Max Mustermann","evidence":[{"filename":"scan.pdf","attachmentId":"1fded50b-9249-4af7-88a5-4ea2245a10bb"}]}',
          $this->schema(
              [
              ['id' => 'full_name', 'type' => 'text', 'label' => 'Name'],
              ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis'],
              ]
          ),
      );

    $redacted = $this->service->redactApplicationData($application);

    $this->assertSame('Max Mustermann', $redacted['full_name']);
    $this->assertSame(
          [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ], $redacted['evidence']
      );
  }

  /**
   * Hex key material may be longer than 32 bytes.
   */
  public function testLongHexKeyMaterialIsAccepted(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('a', 128));

    $protected = $this->service->protectData(
          [
            'diagnosis' => 'IfSG-Meldung',
          ], $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );

    $this->assertTrue($this->service->isEncryptedValue($protected['diagnosis']));
  }

  /**
   * Missing key material fails closed for sensitive values.
   */
  public function testProtectDataFailsClosedWithoutKey(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    $this->logger->expects($this->once())->method('error');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Application field_data encryption failed. Refusing to store plaintext sensitive data.');

    $this->service->protectData(
          [
            'diagnosis' => 'IfSG-Meldung',
          ], $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          )
      );
  }

  /**
   * Empty sensitive values are not encrypted and do not require a key.
   */
  public function testEmptySensitiveValuesDoNotRequireKey(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');

    $protected = $this->service->protectData(
          [
            'diagnosis' => '',
            'details' => NULL,
          ], $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ['id' => 'details', 'label' => 'Details', 'sensitive' => TRUE],
              ]
          )
      );

    $this->assertSame('', $protected['diagnosis']);
    $this->assertNull($protected['details']);
  }

  /**
   * Applicant email lookup hashes are keyed and deterministic.
   */
  public function testApplicantEmailLookupHashNormalizesEmail(): void {
    $hash = $this->service->applicantEmailLookupHash(' Max@Example.Test ');

    $this->assertIsString($hash);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    $this->assertSame($hash, $this->service->applicantEmailLookupHash('max@example.test'));
    $this->assertNotSame(hash('sha256', 'max@example.test'), $hash);
  }

  /**
   * Protected applicant emails can be resolved from encrypted field_data.
   */
  public function testProtectedApplicantEmailResolvesFromEncryptedFieldData(): void {
    $schema = $this->schema(
          [
            ['id' => 'email', 'type' => 'email', 'label' => 'E-Mail', 'crm_key' => 'person.email'],
            ['id' => 'diagnosis', 'type' => 'text', 'label' => 'Diagnose', 'sensitive' => TRUE],
          ]
      );
    $protected = $this->service->protectData(
          [
            'email' => 'Max@Example.Test',
            'diagnosis' => 'IfSG-Meldung',
          ],
          $schema,
      );
    $application = $this->createApplicationForProtection(
          json_encode($protected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
          $schema,
      );

    $this->assertSame('max@example.test', $this->service->protectedApplicantEmail($application));
  }

  /**
   * Protected identity linkage derives hashes, clears cleartext side channels.
   */
  public function testProtectApplicationIdentityLinkageHashesAndClearsSidechannels(): void {
    $schema_entity = $this->createMock(FormSchemaInterface::class);
    $schema_entity->method('getSchema')->willReturn(json_encode($this->schema(
          [
            ['id' => 'applicant_email', 'type' => 'email', 'label' => 'E-Mail', 'crm_key' => 'person.email'],
            ['id' => 'diagnosis', 'type' => 'text', 'label' => 'Diagnose', 'sensitive' => TRUE],
          ]
      ), JSON_THROW_ON_ERROR));

    $schema_field = $this->fieldList(['entity' => $schema_entity]);
    $email_field = $this->fieldList(['value' => ' Max@Example.Test ']);
    $writes = [];
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => in_array($field_name, [
        'field_schema',
        'field_applicant_email',
        'field_applicant_email_hash',
        'field_crm_data',
      ], TRUE));
    $application->method('get')
      ->willReturnCallback(static fn (string $field_name): FieldItemListInterface => match ($field_name) {
        'field_schema' => $schema_field,
        'field_applicant_email' => $email_field,
        default => throw new \LogicException('Unexpected field ' . $field_name),
      });
    $application->method('getSchemaVersion')->willReturn(0);
    $application->method('set')
      ->willReturnCallback(
              function (string $field_name, mixed $value) use (&$writes, $application): ApplicationInterface {
                  $writes[$field_name] = $value;
                  return $application;
              }
          );

    $this->service->protectApplicationIdentityLinkage($application);

    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $writes['field_applicant_email_hash']);
    $this->assertSame($this->service->applicantEmailLookupHash('max@example.test'), $writes['field_applicant_email_hash']);
    $this->assertNull($writes['field_applicant_email']);
    $this->assertNull($writes['field_crm_data']);
  }

  /**
   * Invalid schemas fail closed instead of storing plaintext field_data.
   */
  public function testProtectDataJsonFailsClosedForInvalidSchema(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Application field_data protection failed. Application schema is not valid JSON.');

    $this->service->protectDataJson('{"diagnosis":"IfSG-Meldung"}', 'not-json');
  }

  /**
   * Unreadable schemas are treated as sensitive for access decisions.
   */
  public function testSchemaHasSensitiveFieldsJsonFailsClosedForInvalidSchema(): void {
    $this->assertTrue($this->service->schemaHasSensitiveFieldsJson('not-json'));
  }

  /**
   * Only the exact anonymized marker bypasses field_data encryption.
   */
  public function testAnonymizedMarkerWithExtraDataStillEncrypts(): void {
    $application = $this->createApplicationForProtection(
          '{"_anonymized":true,"diagnosis":"IfSG-Meldung"}',
          $this->schema(
              [
              ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
              ]
          ),
      );

    $application->expects($this->once())
      ->method('set')
      ->with(
              'field_data',
              $this->callback(
                  function (string $value): bool {
                      $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
                      return ($decoded['_anonymized'] ?? NULL) === TRUE
                      && $this->service->isEncryptedValue($decoded['diagnosis'] ?? NULL);
                  }
              ),
          )
      ->willReturnSelf();

    $this->service->protectApplication($application);
  }

  /**
   * Builds a minimal form schema.
   *
   * @param array<int, array<string, mixed>> $fields
   *   Field definitions.
   *
   * @return array<string, mixed>
   *   Form schema.
   */
  private function schema(array $fields): array {
    return [
      'sections' => [
      [
        'fields' => $fields,
      ],
      ],
    ];
  }

  /**
   * Builds an application mock for protectApplication().
   *
   * @param string $data_json
   *   Stored application field_data JSON.
   * @param array<string, mixed> $schema
   *   Decoded schema.
   */
  private function createApplicationForProtection(string $data_json, array $schema): ApplicationInterface {
    $data_field = $this->createMock(FieldItemListInterface::class);
    $data_field->method('isEmpty')->willReturn(FALSE);
    $data_field->method('__isset')
      ->with('value')
      ->willReturn(TRUE);
    $data_field->method('__get')
      ->with('value')
      ->willReturn($data_json);

    $schema_entity = $this->createMock(FormSchemaInterface::class);
    $schema_entity->method('getSchema')->willReturn(json_encode($schema, JSON_THROW_ON_ERROR));

    $schema_field = $this->createMock(FieldItemListInterface::class);
    $schema_field->method('isEmpty')->willReturn(FALSE);
    $schema_field->method('__isset')
      ->with('entity')
      ->willReturn(TRUE);
    $schema_field->method('__get')
      ->with('entity')
      ->willReturn($schema_entity);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getData')->willReturn($data_json);
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => in_array($field_name, ['field_data', 'field_schema'], TRUE));
    $application->method('get')
      ->willReturnCallback(
              static fn (string $field_name): FieldItemListInterface => match ($field_name) {
                'field_data' => $data_field,
                'field_schema' => $schema_field,
                default => $data_field,
              }
            );
    $application->method('getSchemaVersion')->willReturn(0);

    return $application;
  }

  /**
   * Creates a field item list mock exposing magic field properties.
   */
  private function fieldList(array $properties, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('__isset')->willReturnCallback(
      static fn (string $name): bool => array_key_exists($name, $properties)
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $name): mixed => $properties[$name] ?? NULL
    );
    return $field;
  }

  /**
   * Restores an environment variable.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }
    putenv($name . '=' . $value);
  }

}
