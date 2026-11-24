<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit\Service;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the ApplicantManager validation logic.
 *
 * @coversDefaultClass \Drupal\beantragt_applicant\Service\ApplicantManager
 * @group beantragt_applicant
 */
class ApplicantManagerTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * We use a TestableManager subclass to expose the protected validate method.
   *
   * @var \Drupal\Tests\beantragt_applicant\Unit\Service\TestableApplicantManager
   */
  protected TestableApplicantManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['hash_salt' => 'unit-test-hash-salt']);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $lock = $this->createMock(LockBackendInterface::class);
    $this->manager = new TestableApplicantManager(
      $entityTypeManager,
      $loggerFactory,
      $lock,
    );
  }

  /**
   * Tests valid email addresses pass validation.
   *
   * @covers ::validateCrmValue
   * @dataProvider validEmailProvider
   */
  public function testValidEmail(string $email): void {
    $this->assertTrue($this->manager->testValidateCrmValue('person.email', $email));
  }

  /**
   * Data provider for valid emails.
   *
   * @return array
   *   Valid email test data.
   */
  public static function validEmailProvider(): array {
    return [
      'simple' => ['user@example.com'],
      'subdomain' => ['user@sub.example.com'],
      'plus addressing' => ['user+tag@example.com'],
      'dot in local' => ['first.last@example.com'],
    ];
  }

  /**
   * Tests invalid email addresses are rejected.
   *
   * @covers ::validateCrmValue
   * @dataProvider invalidEmailProvider
   */
  public function testInvalidEmail(string $email): void {
    $this->assertFalse($this->manager->testValidateCrmValue('person.email', $email));
  }

  /**
   * Data provider for invalid emails.
   *
   * @return array
   *   Invalid email test data.
   */
  public static function invalidEmailProvider(): array {
    return [
      'no at sign' => ['not-an-email'],
      'no domain' => ['user@'],
      'no local' => ['@example.com'],
      'spaces' => ['user @example.com'],
      'double at' => ['user@@example.com'],
    ];
  }

  /**
   * Tests valid IBAN formats pass validation.
   *
   * @covers ::validateCrmValue
   * @dataProvider validIbanProvider
   */
  public function testValidIban(string $iban): void {
    $this->assertTrue($this->manager->testValidateCrmValue('person.iban', $iban));
  }

  /**
   * Data provider for valid IBANs.
   *
   * @return array
   *   Valid IBAN test data.
   */
  public static function validIbanProvider(): array {
    return [
      'german IBAN compact' => ['DE89370400440532013000'],
      'german IBAN with spaces' => ['DE89 3704 0044 0532 0130 00'],
      'dutch IBAN' => ['NL91ABNA0417164300'],
      'british IBAN' => ['GB29NWBK60161331926819'],
      'lowercase converted' => ['de89370400440532013000'],
    ];
  }

  /**
   * Tests invalid IBAN formats are rejected.
   *
   * @covers ::validateCrmValue
   * @dataProvider invalidIbanProvider
   */
  public function testInvalidIban(string $iban): void {
    $this->assertFalse($this->manager->testValidateCrmValue('person.iban', $iban));
  }

  /**
   * Data provider for invalid IBANs.
   *
   * @return array
   *   Invalid IBAN test data.
   */
  public static function invalidIbanProvider(): array {
    return [
      'too short' => ['DE89'],
      'no country code' => ['12370400440532013000'],
      'only letters' => ['ABCDEFGH'],
      'empty after country' => ['DE'],
      'special characters' => ['DE89-3704-0044'],
    ];
  }

  /**
   * Tests valid German tax ID formats.
   *
   * @covers ::validateCrmValue
   * @dataProvider validTaxIdProvider
   */
  public function testValidTaxId(string $tax_id): void {
    $this->assertTrue($this->manager->testValidateCrmValue('person.tax_id', $tax_id));
  }

  /**
   * Data provider for valid tax IDs.
   *
   * @return array
   *   Valid tax ID test data.
   */
  public static function validTaxIdProvider(): array {
    return [
      '11 digits' => ['12345678901'],
      '10 digits' => ['1234567890'],
      '13 digits' => ['1234567890123'],
      'with spaces' => ['123 456 789 01'],
      'with dashes' => ['123-456-789-01'],
      'with slashes' => ['123/456/789/01'],
    ];
  }

  /**
   * Tests invalid German tax ID formats.
   *
   * @covers ::validateCrmValue
   * @dataProvider invalidTaxIdProvider
   */
  public function testInvalidTaxId(string $tax_id): void {
    $this->assertFalse($this->manager->testValidateCrmValue('person.tax_id', $tax_id));
  }

  /**
   * Data provider for invalid tax IDs.
   *
   * @return array
   *   Invalid tax ID test data.
   */
  public static function invalidTaxIdProvider(): array {
    return [
      'too short' => ['123456789'],
      'too long' => ['12345678901234'],
      'letters' => ['1234567890A'],
      'all letters' => ['abcdefghijk'],
    ];
  }

  /**
   * Tests that unknown CRM keys always pass validation.
   *
   * @covers ::validateCrmValue
   */
  public function testUnknownKeyAlwaysValid(): void {
    $this->assertTrue($this->manager->testValidateCrmValue('person.full_name', 'Any Value'));
    $this->assertTrue($this->manager->testValidateCrmValue('person.city', ''));
    $this->assertTrue($this->manager->testValidateCrmValue('person.phone', '+49 123 456789'));
  }

  /**
   * Tests getCrmFieldMap() returns the expected mapping.
   *
   * @covers ::getCrmFieldMap
   */
  public function testGetCrmFieldMap(): void {
    $map = $this->manager->getCrmFieldMap();

    $this->assertIsArray($map);
    $this->assertArrayHasKey('person.email', $map);
    $this->assertEquals('field_email', $map['person.email']);
    $this->assertArrayHasKey('person.iban', $map);
    $this->assertEquals('field_iban', $map['person.iban']);
    $this->assertArrayHasKey('person.account_holder', $map);
    $this->assertEquals('field_account_holder', $map['person.account_holder']);
    $this->assertArrayNotHasKey('person.tax_id', $map);
    $this->assertArrayHasKey('person.address', $map);
    $this->assertEquals('field_address', $map['person.address']);
    $this->assertArrayHasKey('person.street', $map);
    $this->assertEquals('field_street', $map['person.street']);
    $this->assertArrayHasKey('person.postal_code', $map);
    $this->assertEquals('field_postal_code', $map['person.postal_code']);
    $this->assertArrayHasKey('person.city', $map);
    $this->assertEquals('field_city', $map['person.city']);
    $this->assertArrayNotHasKey('address.city', $map);
  }

  /**
   * Tests legacy address keys are normalized without overriding canonical keys.
   *
   * @covers ::normalizeCrmDataKeys
   */
  public function testNormalizeLegacyAddressKeys(): void {
    $crm_data = $this->manager->testNormalizeCrmDataKeys([
      'address.full' => 'Legacy full address',
      'address.street' => 'Legacy Street 1',
      'address.postal_code' => '12345',
      'address.city' => 'Legacy Musterstadt',
      'person.city' => 'Musterstadt',
    ]);

    $this->assertSame('Legacy full address', $crm_data['person.address']);
    $this->assertSame('Legacy Street 1', $crm_data['person.street']);
    $this->assertSame('12345', $crm_data['person.postal_code']);
    $this->assertSame('Musterstadt', $crm_data['person.city']);
  }

  /**
   * Tests unverified email matches do not overwrite protected financial data.
   *
   * @covers ::doFindOrCreate
   * @covers ::updateProfileFromCrmData
   */
  public function testUnclaimedEmailMatchDoesNotOverwriteProtectedCrmFieldsOrClaimOwner(): void {
    $profile = $this->createApplicantProfile(0, [
      'field_iban' => 'enc:VICTIM_IBAN',
      'field_account_holder' => 'enc:VICTIM_HOLDER',
      'field_full_name' => 'Victim Name',
    ]);
    $setCalls = [];
    $profile->expects($this->never())->method('setOwnerId');
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $storage = $this->createApplicantStorage($profile);

    $result = $this->manager->testDoFindOrCreate($storage, [
      'person.email' => 'victim@example.org',
      'person.full_name' => 'Attacker Supplied Name',
      'person.iban' => 'DE89370400440532013000',
      'person.account_holder' => 'Attacker Holder',
    ], 123, 'attacker-session-token', 'victim@example.org', FALSE, [], [
      'contact' => ['time' => 1781431200, 'textVersion' => '2026-10-20', 'source' => 'app-uuid-1'],
      'bank' => ['time' => 1781431200, 'textVersion' => '2026-10-20', 'source' => 'app-uuid-1'],
    ]);

    $this->assertSame($profile, $result);
    $this->assertArrayNotHasKey('field_iban', $setCalls);
    $this->assertArrayNotHasKey('field_account_holder', $setCalls);
    $this->assertArrayNotHasKey('field_session_token', $setCalls);
    // The bank details were not written, so no bank consent is stamped.
    $this->assertSame('app-uuid-1', $setCalls['field_consent_source'] ?? NULL);
    $this->assertArrayNotHasKey('field_bank_consent_time', $setCalls);
    $this->assertArrayNotHasKey('field_bank_consent_source', $setCalls);
  }

  /**
   * Tests unverified email matches may still fill missing protected fields.
   *
   * @covers ::doFindOrCreate
   * @covers ::updateProfileFromCrmData
   */
  public function testUnclaimedEmailMatchMayFillEmptyProtectedCrmFields(): void {
    $profile = $this->createApplicantProfile(0, [
      'field_iban' => '',
      'field_account_holder' => '',
    ]);
    $setCalls = [];
    $profile->expects($this->never())->method('setOwnerId');
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $storage = $this->createApplicantStorage($profile);

    $result = $this->manager->testDoFindOrCreate($storage, [
      'person.email' => 'victim@example.org',
      'person.iban' => 'DE89370400440532013000',
      'person.account_holder' => 'Ada Lovelace',
    ], 123, NULL, 'victim@example.org');

    $this->assertSame($profile, $result);
    $this->assertSame('DE89370400440532013000', $setCalls['field_iban'] ?? NULL);
    $this->assertSame('Ada Lovelace', $setCalls['field_account_holder'] ?? NULL);
  }

  /**
   * Tests verified email matches may claim and update unclaimed profiles.
   *
   * @covers ::doFindOrCreate
   * @covers ::updateProfileFromCrmData
   */
  public function testVerifiedEmailMatchMayClaimAndOverwriteProtectedCrmFields(): void {
    $profile = $this->createApplicantProfile(0, [
      'field_iban' => 'enc:OLD_IBAN',
      'field_account_holder' => 'enc:OLD_HOLDER',
    ]);
    $setCalls = [];
    $profile->expects($this->once())->method('setOwnerId')->with(123);
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $storage = $this->createApplicantStorage($profile);

    $result = $this->manager->testDoFindOrCreate($storage, [
      'person.email' => 'victim@example.org',
      'person.iban' => 'DE89370400440532013000',
      'person.account_holder' => 'Ada Lovelace',
    ], 123, NULL, 'victim@example.org', TRUE);

    $this->assertSame($profile, $result);
    $this->assertSame('DE89370400440532013000', $setCalls['field_iban'] ?? NULL);
    $this->assertSame('Ada Lovelace', $setCalls['field_account_holder'] ?? NULL);
  }

  /**
   * Tests the tax ID never reaches the applicant profile.
   */
  public function testTaxIdIsNeverWrittenToProfile(): void {
    $profile = $this->createApplicantProfile(0, ['field_tax_id' => '']);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.tax_id' => '12345678901',
    ], 123, NULL, 'victim@example.org', TRUE);

    $this->assertArrayNotHasKey('field_tax_id', $setCalls);
  }

  /**
   * Tests consent provenance is stored when CRM data is written.
   */
  public function testConsentProvenanceIsStoredWithWrittenData(): void {
    $profile = $this->createApplicantProfile(0, []);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.iban' => 'DE89370400440532013000',
    ], 123, NULL, 'victim@example.org', TRUE, [], [
      'contact' => [
        'time' => 1781431200,
        'textVersion' => '2026-10-20',
        'source' => 'app-uuid-1',
        'textHash' => str_repeat('c', 64),
        'locale' => 'en',
        'scopeLabel' => 'Citizens office Sampletown',
      ],
      'bank' => [
        'time' => 1781431300,
        'textVersion' => '2026-10-21',
        'source' => 'app-uuid-1',
        'textHash' => 'not-a-hash',
      ],
    ]);

    $this->assertSame(1781431200, $setCalls['field_consent_time'] ?? NULL);
    $this->assertSame(str_repeat('c', 64), $setCalls['field_consent_text_hash'] ?? NULL);
    $this->assertSame('en', $setCalls['field_consent_locale'] ?? NULL);
    $this->assertSame('Citizens office Sampletown', $setCalls['field_consent_scope_label'] ?? NULL);
    $this->assertArrayHasKey('field_bank_consent_scope_label', $setCalls);
    $this->assertNull($setCalls['field_bank_consent_scope_label']);
    // A malformed hash or a missing locale clears the older proof values.
    $this->assertArrayHasKey('field_bank_consent_text_hash', $setCalls);
    $this->assertNull($setCalls['field_bank_consent_text_hash']);
    $this->assertArrayHasKey('field_bank_consent_locale', $setCalls);
    $this->assertNull($setCalls['field_bank_consent_locale']);
    $this->assertSame('2026-10-20', $setCalls['field_consent_text_version'] ?? NULL);
    $this->assertSame('app-uuid-1', $setCalls['field_consent_source'] ?? NULL);
    $this->assertSame(1781431300, $setCalls['field_bank_consent_time'] ?? NULL);
    $this->assertSame('2026-10-21', $setCalls['field_bank_consent_text_version'] ?? NULL);
    $this->assertSame('app-uuid-1', $setCalls['field_bank_consent_source'] ?? NULL);
  }

  /**
   * Tests a skipped profile of another owner gets no consent stamp.
   */
  public function testClaimedProfileOfAnotherOwnerGetsNoConsentProvenance(): void {
    $profile = $this->createApplicantProfile(77, []);
    $profile->expects($this->never())->method('save');
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.full_name' => 'Attacker Supplied Name',
    ], 123, NULL, 'victim@example.org', FALSE, [], [
      'contact' => ['time' => 1781431200, 'textVersion' => '2026-10-20', 'source' => 'app-uuid-1'],
    ]);

    $this->assertArrayNotHasKey('field_full_name', $setCalls);
    $this->assertArrayNotHasKey('field_consent_time', $setCalls);
    $this->assertArrayNotHasKey('field_consent_source', $setCalls);
  }

  /**
   * Tests a new address replaces the stored address as one unit.
   *
   * Regression from the UX smoke: a profile "Marktplatz 1, 12345 Musterstadt"
   * and an application "Bauweg 12, 54321 Musterstadt" must never end up as
   * "Marktplatz 1, 54321 Musterstadt".
   *
   * @covers ::updateProfileFromCrmData
   * @covers ::writeAtomicCrmGroups
   */
  public function testAddressGroupIsReplacedAsUnit(): void {
    $stored = [
      'field_address' => 'Marktplatz 1, 12345 Musterstadt',
      'field_street' => 'Marktplatz 1',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
      'field_phone' => '0301234567',
    ];

    $full = $this->syncIntoOwnedProfile($stored, [
      'person.street' => 'Bauweg 12',
      'person.postal_code' => '54321',
      'person.city' => 'Musterstadt',
    ]);
    $this->assertSame('Bauweg 12', $full['field_street'] ?? NULL);
    $this->assertSame('54321', $full['field_postal_code'] ?? NULL);
    $this->assertSame('Musterstadt', $full['field_city'] ?? NULL);
    // The old one-line address belongs to the old unit and is cleared.
    $this->assertCleared($full, 'field_address');
    // Keys outside the group keep their per-key behaviour.
    $this->assertArrayNotHasKey('field_phone', $full);

    // A form that asks only for postal code and town leaves no old street.
    $partial = $this->syncIntoOwnedProfile($stored, [
      'person.postal_code' => '54321',
      'person.city' => 'Musterstadt',
    ]);
    $this->assertSame('54321', $partial['field_postal_code'] ?? NULL);
    $this->assertSame('Musterstadt', $partial['field_city'] ?? NULL);
    $this->assertCleared($partial, 'field_street');
    $this->assertCleared($partial, 'field_address');

    // An empty street value counts as missing, not as a reason to keep it.
    $blank = $this->syncIntoOwnedProfile($stored, [
      'person.street' => '  ',
      'person.postal_code' => '54321',
    ]);
    $this->assertCleared($blank, 'field_street');
    $this->assertCleared($blank, 'field_city');

    // Legacy address keys, normalized by findOrCreate(), join the same unit.
    $legacy = $this->syncIntoOwnedProfile($stored, $this->manager->testNormalizeCrmDataKeys([
      'address.full' => 'Bauweg 12, 54321 Musterstadt',
    ]));
    $this->assertSame('Bauweg 12, 54321 Musterstadt', $legacy['field_address'] ?? NULL);
    $this->assertCleared($legacy, 'field_street');
    $this->assertCleared($legacy, 'field_postal_code');
  }

  /**
   * A form-specific write preserves unconfirmed name and address members.
   */
  public function testFormConsentOnlyWritesProvidedMembersOfContactGroups(): void {
    $set = $this->syncIntoOwnedProfile([
      'field_street' => 'Marktplatz 1',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
      'field_full_name' => 'Ada Beispiel',
      'field_first_name' => 'Ada',
      'field_last_name' => 'Beispiel',
    ], [
      'person.postal_code' => '54321',
      'person.last_name' => 'Muster',
    ], [
      'contact' => [
        'time' => 1781431200,
        'textVersion' => '2026-11-20',
        'source' => 'app-uuid-1',
        'fields' => ['person.city', 'person.postal_code', 'person.last_name'],
      ],
    ]);
    $this->assertSame('54321', $set['field_postal_code']);
    $this->assertSame('Muster', $set['field_last_name']);
    foreach (['field_street', 'field_city', 'field_full_name', 'field_first_name'] as $unconfirmed) {
      $this->assertArrayNotHasKey($unconfirmed, $set);
    }
  }

  /**
   * Tests data without any address key leaves the stored address untouched.
   *
   * @covers ::writeAtomicCrmGroups
   */
  public function testAddressGroupIsKeptWhenIncomingDataHasNoAddress(): void {
    $set = $this->syncIntoOwnedProfile([
      'field_street' => 'Marktplatz 1',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
      'field_full_name' => 'Erika Muster',
    ], [
      'person.phone' => '0301234567',
      'person.street' => '',
    ]);

    $this->assertSame('0301234567', $set['field_phone'] ?? NULL);
    $untouched = [
      'field_address',
      'field_street',
      'field_postal_code',
      'field_city',
      'field_full_name',
      'field_first_name',
      'field_last_name',
    ];
    foreach ($untouched as $field) {
      $this->assertArrayNotHasKey($field, $set);
    }
  }

  /**
   * Tests a new name replaces the stored name as one unit.
   *
   * @covers ::updateProfileFromCrmData
   * @covers ::writeAtomicCrmGroups
   * @covers ::completeAtomicCrmGroup
   */
  public function testNameGroupIsReplacedAsUnit(): void {
    $stored = [
      'field_full_name' => 'Max Mustermann',
      'field_first_name' => 'Max',
      'field_last_name' => 'Mustermann',
    ];

    // A full name alone is not split; the old parts are cleared.
    $full = $this->syncIntoOwnedProfile($stored, [
      'person.full_name' => 'Erika Musterfrau',
    ]);
    $this->assertSame('Erika Musterfrau', $full['field_full_name'] ?? NULL);
    $this->assertCleared($full, 'field_first_name');
    $this->assertCleared($full, 'field_last_name');

    // First and last name also yield the full name of the same unit.
    $parts = $this->syncIntoOwnedProfile($stored, [
      'person.first_name' => 'Erika',
      'person.last_name' => 'Musterfrau',
    ]);
    $this->assertSame('Erika', $parts['field_first_name'] ?? NULL);
    $this->assertSame('Musterfrau', $parts['field_last_name'] ?? NULL);
    $this->assertSame('Erika Musterfrau', $parts['field_full_name'] ?? NULL);

    // A changed last name alone keeps the stored first name and rebuilds the
    // full name from both parts.
    $last = $this->syncIntoOwnedProfile($stored, [
      'person.last_name' => 'Musterfrau',
    ]);
    $this->assertSame('Musterfrau', $last['field_last_name'] ?? NULL);
    $this->assertSame('Max', $last['field_first_name'] ?? NULL);
    $this->assertSame('Max Musterfrau', $last['field_full_name'] ?? NULL);

    // Without a stored counterpart a single part never becomes the full name.
    $single = $this->syncIntoOwnedProfile([
      'field_full_name' => 'Dr. Erika Maria Muster',
    ], [
      'person.last_name' => 'Musterfrau',
    ]);
    $this->assertSame('Musterfrau', $single['field_last_name'] ?? NULL);
    $this->assertCleared($single, 'field_full_name');
    $this->assertArrayNotHasKey('field_first_name', $single);
  }

  /**
   * Tests data that only repeats stored members keeps the stored unit.
   *
   * A form that asks only for the postal code (resident parking, a
   * jurisdiction check) must not clear street and town when the citizen
   * enters the stored postal code again.
   *
   * @covers ::writeAtomicCrmGroups
   */
  public function testUnchangedPartialGroupKeepsStoredUnit(): void {
    $stored = [
      'field_address' => 'Marktplatz 1, 12345 Musterstadt',
      'field_street' => 'Marktplatz 1',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
      'field_full_name' => 'Max Mustermann',
      'field_first_name' => 'Max',
      'field_last_name' => 'Mustermann',
    ];

    $set = $this->syncIntoOwnedProfile($stored, [
      'person.postal_code' => ' 12345 ',
      'person.last_name' => 'Mustermann',
    ]);
    $this->assertSame([], $set);

    // A FIM form submits street and number apart; the application sync joins
    // them again (Application::filterCrmDataForApplicantSync()), so the stored
    // unit, including the one-line address, is confirmed and kept.
    $rejoined = $this->syncIntoOwnedProfile($stored, [
      'person.street' => 'Marktplatz 1',
      'person.postal_code' => '12345',
      'person.city' => 'Musterstadt',
    ]);
    $this->assertSame([], $rejoined);

    // One differing member makes the whole unit new again.
    $changed = $this->syncIntoOwnedProfile($stored, [
      'person.postal_code' => '12345',
      'person.city' => 'Neustadt',
    ]);
    $this->assertSame('Neustadt', $changed['field_city'] ?? NULL);
    $this->assertCleared($changed, 'field_street');
  }

  /**
   * Tests a non-string group member keeps the whole stored unit.
   *
   * @covers ::writeAtomicCrmGroups
   */
  public function testNonStringGroupMemberKeepsStoredUnit(): void {
    $set = $this->syncIntoOwnedProfile([
      'field_street' => 'Marktplatz 1',
      'field_city' => 'Musterstadt',
    ], [
      'person.street' => 'Bauweg 12',
      'person.city' => ['Musterstadt', 'Neustadt'],
    ]);
    foreach (['field_address', 'field_street', 'field_postal_code', 'field_city'] as $field) {
      $this->assertArrayNotHasKey($field, $set);
    }
  }

  /**
   * Tests no group member is a protected unverified-merge key.
   *
   * Group writes do not apply PROTECTED_UNVERIFIED_MERGE_KEYS, so a protected
   * key inside a group would bypass that guard.
   */
  public function testAtomicGroupsHoldNoProtectedMergeKeys(): void {
    $members = array_merge(...array_values(TestableApplicantManager::atomicGroups()));
    $this->assertNotSame([], $members);
    $this->assertSame([], array_intersect($members, TestableApplicantManager::protectedMergeKeys()));
  }

  /**
   * Tests an invalid group member keeps the whole stored unit.
   *
   * @covers ::writeAtomicCrmGroups
   */
  public function testInvalidGroupMemberKeepsStoredUnit(): void {
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $this->manager = new class(
      $this->createMock(EntityTypeManagerInterface::class),
      $loggerFactory,
      $this->createMock(LockBackendInterface::class),
    ) extends TestableApplicantManager {

      /**
       * {@inheritdoc}
       */
      protected function validateCrmValue(string $crm_key, string $value): bool {
        return $value !== 'INVALID' && parent::validateCrmValue($crm_key, $value);
      }

    };

    $set = $this->syncIntoOwnedProfile([
      'field_street' => 'Marktplatz 1',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
    ], [
      'person.street' => 'Bauweg 12',
      'person.postal_code' => 'INVALID',
      'person.city' => 'Musterstadt',
      'person.phone' => '0301234567',
    ]);

    $this->assertSame('0301234567', $set['field_phone'] ?? NULL);
    foreach (['field_address', 'field_street', 'field_postal_code', 'field_city'] as $field) {
      $this->assertArrayNotHasKey($field, $set);
    }
  }

  /**
   * Tests the group rules leave the claimed-profile skip in place.
   *
   * @covers ::doFindOrCreate
   */
  public function testClaimedProfileOfAnotherOwnerKeepsAddressUnit(): void {
    $profile = $this->createApplicantProfile(77, [
      'field_street' => 'Marktplatz 1',
      'field_postal_code' => '12345',
    ]);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.postal_code' => '54321',
    ], 123, NULL, 'victim@example.org', TRUE);

    $this->assertArrayNotHasKey('field_street', $setCalls);
    $this->assertArrayNotHasKey('field_postal_code', $setCalls);
  }

  /**
   * Asserts a field was explicitly cleared on the profile.
   */
  private function assertCleared(array $setCalls, string $fieldName): void {
    $this->assertArrayHasKey($fieldName, $setCalls, "$fieldName was kept instead of cleared.");
    $this->assertNull($setCalls[$fieldName]);
  }

  /**
   * Syncs CRM data into an owned, email-matched profile.
   *
   * @param array $stored
   *   Stored profile field values.
   * @param array $crmData
   *   Incoming CRM data without the email key.
   * @param array $consent
   *   Optional consent proof passed to the profile manager.
   *
   * @return array
   *   Field values set on the profile, keyed by field name.
   */
  private function syncIntoOwnedProfile(array $stored, array $crmData, array $consent = []): array {
    $profile = $this->createApplicantProfile(123, $stored);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
    ] + $crmData, 123, NULL, 'victim@example.org', TRUE, [], $consent);

    unset($setCalls['field_email']);
    return $setCalls;
  }

  /**
   * Tests first-time unverified foreign emails do not create owned profiles.
   *
   * @covers ::doFindOrCreate
   */
  public function testUnverifiedEmailCreateDoesNotClaimOwnerOrSessionToken(): void {
    $profile = $this->createApplicantProfile(0, [
      'field_email' => '',
    ]);
    $setCalls = [];
    // The owner defaults to the current user; it is reset to "unclaimed".
    $profile->expects($this->once())->method('setOwnerId')->with(0);
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $storage = $this->createApplicantStorageWithoutEmailMatch($profile);

    $result = $this->manager->testDoFindOrCreate($storage, [
      'person.email' => 'victim@example.org',
    ], 123, 'attacker-session-token', 'victim@example.org');

    $this->assertSame($profile, $result);
    $this->assertSame('victim@example.org', $setCalls['field_email'] ?? NULL);
    $this->assertArrayNotHasKey('field_session_token', $setCalls);
  }

  /**
   * Tests scoped applicant creation keeps same-email profiles tenant-local.
   *
   * @covers ::doFindOrCreate
   * @covers ::applyScopeToQuery
   * @covers ::applyScopeToProfile
   */
  public function testScopedCreateConstrainsLookupAndSetsScopeFields(): void {
    $profile = $this->createApplicantProfile(0, [
      'field_email' => '',
      'field_jurisdiction' => '',
      'field_organisation' => '',
      'field_group' => '',
    ], ['field_email', 'field_jurisdiction', 'field_organisation', 'field_group']);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(function (string $fieldName, mixed $value) use (&$conditions, $query): QueryInterface {
      $conditions[$fieldName] = $value;
      return $query;
    });
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('create')->with(['status' => TRUE])->willReturn($profile);
    $storage->expects($this->never())->method('load');

    $result = $this->manager->testDoFindOrCreate($storage, [
      'person.email' => 'victim@example.org',
    ], NULL, NULL, 'victim@example.org', FALSE, [
      'field_jurisdiction' => ['target_id' => 3],
      'field_organisation' => ['target_id' => 4],
      'field_group' => ['target_id' => 4],
    ]);

    $this->assertSame($profile, $result);
    $this->assertSame('victim@example.org', $conditions['field_email'] ?? NULL);
    $this->assertSame(4, $conditions['field_group'] ?? NULL);
    $this->assertSame(['target_id' => 3], $setCalls['field_jurisdiction'] ?? NULL);
    $this->assertSame(['target_id' => 4], $setCalls['field_organisation'] ?? NULL);
    $this->assertSame(['target_id' => 4], $setCalls['field_group'] ?? NULL);
    $this->assertSame('victim@example.org', $setCalls['field_email'] ?? NULL);
  }

  /**
   * A consent given before the stored withdrawal writes nothing.
   *
   * Scenario: an application submitted with consent at 1500, the citizen
   * withdrew at 2000 under "Meine Daten", the application is confirmed later.
   *
   * @covers ::doFindOrCreate
   * @covers ::consentWithdrawnAfter
   */
  public function testConsentBeforeWithdrawalDoesNotRefillProfile(): void {
    foreach ([1500, 2000] as $consent_time) {
      $setCalls = $this->syncWithConsent(['field_consent_revoked_time' => 2000], $consent_time);
      $this->assertArrayNotHasKey('field_full_name', $setCalls, "consent at $consent_time");
      $this->assertArrayNotHasKey('field_phone', $setCalls, "consent at $consent_time");
      $this->assertArrayNotHasKey('field_iban', $setCalls, "consent at $consent_time");
      $this->assertArrayNotHasKey('field_consent_time', $setCalls, "consent at $consent_time");
    }
  }

  /**
   * A consent given after the withdrawal syncs again and keeps the withdrawal.
   *
   * The withdrawal time must survive, otherwise an older consent confirmed
   * even later would pass.
   *
   * @covers ::doFindOrCreate
   */
  public function testConsentAfterWithdrawalSyncsAndKeepsWithdrawalTime(): void {
    $setCalls = $this->syncWithConsent(['field_consent_revoked_time' => 2000], 2001);
    $this->assertSame('Bertha Bürger', $setCalls['field_full_name'] ?? NULL);
    $this->assertSame('+49 555 1', $setCalls['field_phone'] ?? NULL);
    $this->assertSame(2001, $setCalls['field_consent_time'] ?? NULL);
    $this->assertArrayNotHasKey('field_consent_revoked_time', $setCalls);
  }

  /**
   * A withdrawn bank consent strips only the bank details.
   *
   * @covers ::doFindOrCreate
   */
  public function testWithdrawnBankConsentStripsOnlyBankDetails(): void {
    $setCalls = $this->syncWithConsent(['field_bank_consent_revoked_time' => 2000], 1500);
    $this->assertSame('Bertha Bürger', $setCalls['field_full_name'] ?? NULL);
    $this->assertArrayNotHasKey('field_iban', $setCalls);
    $this->assertArrayNotHasKey('field_account_holder', $setCalls);
    $this->assertArrayNotHasKey('field_bank_consent_time', $setCalls);
    $this->assertSame(1500, $setCalls['field_consent_time'] ?? NULL);
  }

  /**
   * A login (no consent) is not blocked by a consent withdrawal.
   *
   * @covers ::doFindOrCreate
   */
  public function testLoginSyncIgnoresConsentWithdrawal(): void {
    $profile = $this->createApplicantProfile(123, ['field_consent_revoked_time' => 2000]);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.birthdate' => '1985-04-12',
    ], 123, NULL, 'victim@example.org', TRUE, [], [], ['provider' => 'bundid', 'time' => 3000]);
    $this->assertSame('1985-04-12', $setCalls['field_birthdate'] ?? NULL);
  }

  /**
   * A login records the confirmed identity values with their hashes.
   *
   * @covers ::applyLoginConfirmation
   */
  public function testLoginConfirmationRecordsHashedIdentityValues(): void {
    $stored = [
      'field_email' => 'victim@example.org',
      'field_full_name' => 'Max Mustermann',
      'field_birthdate' => '1978-09-03',
      'field_phone' => '+49 1',
    ];
    $profile = $this->createApplicantProfile(123, $stored);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.full_name' => 'Max Mustermann',
      'person.birthdate' => '1978-09-03',
      'person.phone' => '+49 1',
    ], 123, NULL, 'victim@example.org', TRUE, [], [], ['provider' => 'bundid', 'time' => 3000]);

    $this->assertSame('bundid', $setCalls['field_login_confirmed_provider'] ?? NULL);
    $this->assertSame(3000, $setCalls['field_login_confirmed_time'] ?? NULL);
    $keys = json_decode((string) ($setCalls['field_login_confirmed_keys'] ?? ''), TRUE);
    $this->assertSame([
      'person.email' => TestableApplicantManager::hashLoginValue('victim@example.org'),
      'person.full_name' => TestableApplicantManager::hashLoginValue('Max Mustermann'),
      'person.birthdate' => TestableApplicantManager::hashLoginValue('1978-09-03'),
    ], $keys, 'Only identity keys are confirmed by a login, never the phone.');
  }

  /**
   * A consent sync never marks values as confirmed by a login.
   *
   * @covers ::applyLoginConfirmation
   */
  public function testConsentSyncSetsNoLoginConfirmation(): void {
    $setCalls = $this->syncWithConsent([], 1500);
    $this->assertArrayNotHasKey('field_login_confirmed_provider', $setCalls);
    $this->assertArrayNotHasKey('field_login_confirmed_keys', $setCalls);
  }

  /**
   * Only values that still equal the login value count as confirmed.
   *
   * @covers ::loginConfirmedKeys
   */
  public function testLoginConfirmedKeysDropValuesChangedLater(): void {
    $profile = $this->createApplicantProfile(123, [
      'field_full_name' => 'Name From A Later Application',
      'field_birthdate' => '1978-09-03',
      'field_login_confirmed_keys' => json_encode([
        'person.full_name' => TestableApplicantManager::hashLoginValue('Max Mustermann'),
        'person.birthdate' => TestableApplicantManager::hashLoginValue('1978-09-03'),
        'person.phone' => TestableApplicantManager::hashLoginValue('+49 1'),
      ]),
    ]);
    $this->assertSame(['person.birthdate'], $this->manager->loginConfirmedKeys($profile));
  }

  /**
   * Contact withdrawal clears consented data, keeps email, bank and login data.
   *
   * @covers ::withdrawConsent
   */
  public function testContactWithdrawalClearsConsentedDataOnly(): void {
    $profile = $this->createApplicantProfile(123, [
      'field_email' => 'victim@example.org',
      'field_full_name' => 'Max Mustermann',
      'field_first_name' => 'Max',
      'field_last_name' => 'Mustermann',
      'field_birthdate' => '1978-09-03',
      'field_phone' => '+49 1',
      'field_gender' => 'männlich',
      'field_nationality' => 'deutsch',
      'field_marital_status' => 'ledig',
      'field_address' => 'Bauweg 12, 12345 Musterstadt',
      'field_street' => 'Bauweg 12',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
      'field_iban' => 'DE89370400440532013000',
      'field_account_holder' => 'Max Mustermann',
      'field_tax_id' => '12345678901',
      'field_consent_time' => 1500,
      'field_login_confirmed_keys' => json_encode([
        'person.full_name' => TestableApplicantManager::hashLoginValue('Max Mustermann'),
        'person.birthdate' => TestableApplicantManager::hashLoginValue('1978-09-03'),
      ]),
    ]);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $cleared = $this->managerReloading($profile)->withdrawConsent($profile, 'contact', 2000);

    $cleared_fields = [
      'field_first_name',
      'field_last_name',
      'field_phone',
      'field_gender',
      'field_nationality',
      'field_marital_status',
      'field_address',
      'field_street',
      'field_postal_code',
      'field_city',
      'field_tax_id',
    ];
    foreach ($cleared_fields as $field) {
      $this->assertCleared($setCalls, $field);
      $this->assertContains($field, $cleared);
    }
    $kept_fields = [
      'field_email',
      'field_full_name',
      'field_birthdate',
      'field_iban',
      'field_account_holder',
      'field_bank_consent_time',
      'field_bank_consent_revoked_time',
    ];
    foreach ($kept_fields as $kept) {
      $this->assertArrayNotHasKey($kept, $setCalls, "$kept must stay.");
    }
    $this->assertCleared($setCalls, 'field_consent_time');
    $this->assertCleared($setCalls, 'field_consent_text_version');
    $this->assertCleared($setCalls, 'field_consent_source');
    $this->assertCleared($setCalls, 'field_consent_text_hash');
    $this->assertCleared($setCalls, 'field_consent_locale');
    $this->assertCleared($setCalls, 'field_consent_scope_label');
    $this->assertSame(2000, $setCalls['field_consent_revoked_time'] ?? NULL);
  }

  /**
   * Bank withdrawal clears only IBAN, account holder and bank provenance.
   *
   * @covers ::withdrawConsent
   */
  public function testBankWithdrawalClearsOnlyBankDetails(): void {
    $profile = $this->createApplicantProfile(123, [
      'field_email' => 'victim@example.org',
      'field_full_name' => 'Max Mustermann',
      'field_iban' => 'DE89370400440532013000',
      'field_account_holder' => 'Max Mustermann',
      'field_bank_consent_time' => 1500,
    ]);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $profile->expects($this->once())->method('save');

    $cleared = $this->managerReloading($profile)->withdrawConsent($profile, 'bank', 2000);

    $this->assertSame(['field_iban', 'field_account_holder'], $cleared);
    $this->assertCleared($setCalls, 'field_iban');
    $this->assertCleared($setCalls, 'field_account_holder');
    $this->assertCleared($setCalls, 'field_bank_consent_time');
    $this->assertSame(2000, $setCalls['field_bank_consent_revoked_time'] ?? NULL);
    $this->assertArrayNotHasKey('field_full_name', $setCalls);
    $this->assertArrayNotHasKey('field_consent_time', $setCalls);
    $this->assertArrayNotHasKey('field_consent_revoked_time', $setCalls);
  }

  /**
   * Withdrawal removes login hashes of values the profile no longer holds.
   *
   * Mutation probe: without pruning, the hash of the replaced name stays
   * as a reversible trace of a value that was cleared.
   *
   * @covers ::withdrawConsent
   * @covers ::pruneLoginConfirmation
   */
  public function testWithdrawalPrunesStaleLoginHashes(): void {
    $profile = $this->createApplicantProfile(123, [
      'field_email' => 'victim@example.org',
      'field_full_name' => 'Name From A Later Application',
      'field_birthdate' => '1978-09-03',
      'field_login_confirmed_provider' => 'bundid',
      'field_login_confirmed_time' => 1000,
      'field_login_confirmed_keys' => json_encode([
        'person.full_name' => TestableApplicantManager::hashLoginValue('Max Mustermann'),
        'person.birthdate' => TestableApplicantManager::hashLoginValue('1978-09-03'),
      ]),
    ]);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->managerReloading($profile)->withdrawConsent($profile, 'contact', 2000);

    $this->assertCleared($setCalls, 'field_full_name');
    $this->assertArrayNotHasKey('field_birthdate', $setCalls);
    $this->assertSame([
      'person.birthdate' => TestableApplicantManager::hashLoginValue('1978-09-03'),
    ], json_decode((string) $setCalls['field_login_confirmed_keys'], TRUE));
    $this->assertArrayNotHasKey('field_login_confirmed_provider', $setCalls);
  }

  /**
   * Without any confirmed value the whole login confirmation goes.
   *
   * @covers ::pruneLoginConfirmation
   */
  public function testWithdrawalWithoutConfirmedValuesClearsLoginConfirmation(): void {
    $profile = $this->createApplicantProfile(123, [
      'field_email' => 'victim@example.org',
      'field_full_name' => 'Name From A Later Application',
      'field_login_confirmed_provider' => 'bundid',
      'field_login_confirmed_time' => 1000,
      'field_login_confirmed_keys' => json_encode([
        'person.full_name' => TestableApplicantManager::hashLoginValue('Max Mustermann'),
      ]),
    ]);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });

    $this->managerReloading($profile)->withdrawConsent($profile, 'contact', 2000);

    $this->assertCleared($setCalls, 'field_login_confirmed_keys');
    $this->assertCleared($setCalls, 'field_login_confirmed_provider');
    $this->assertCleared($setCalls, 'field_login_confirmed_time');
  }

  /**
   * Login hashes are keyed with the site salt, not a plain SHA-256.
   *
   * @covers ::loginValueHash
   */
  public function testLoginValueHashIsKeyedWithTheSiteSalt(): void {
    $hash = TestableApplicantManager::hashLoginValue('1978-09-03');
    $this->assertNotSame(hash('sha256', '1978-09-03'), $hash);
    $this->assertSame($hash, TestableApplicantManager::hashLoginValue(' 1978-09-03 '));
    new Settings(['hash_salt' => 'another-site-salt']);
    $this->assertNotSame($hash, TestableApplicantManager::hashLoginValue('1978-09-03'));
    new Settings(['hash_salt' => 'unit-test-hash-salt']);
  }

  /**
   * Unknown consent kinds are rejected before anything changes.
   *
   * @covers ::withdrawConsent
   */
  public function testWithdrawRejectsUnknownKind(): void {
    $profile = $this->createApplicantProfile(123, ['field_email' => 'victim@example.org']);
    $profile->expects($this->never())->method('save');
    $this->expectException(\InvalidArgumentException::class);
    $this->managerReloading($profile)->withdrawConsent($profile, 'tax', 2000);
  }

  /**
   * Sync and withdrawal both stop before loading under a busy account lock.
   */
  public function testSyncWithoutEmailSharesTheWithdrawalLock(): void {
    $profile = $this->createApplicantProfile(123, []);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->never())->method('getQuery');
    $storage->expects($this->never())->method('loadUnchanged');
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->with('applicant')->willReturn($storage);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(4))->method('acquire')->with('applicant_uid_123')->willReturn(FALSE);
    $lock->expects($this->exactly(2))->method('wait')->with('applicant_uid_123');
    $lock->expects($this->never())->method('release');
    $manager = new ApplicantManager($types, $logger_factory, $lock);

    try {
      $manager->findOrCreate(['person.full_name' => 'Ada Beispiel'], 123);
      self::fail('Sync must stop while the account lock is held.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('The applicant profile is being updated; try again.', $exception->getMessage());
    }

    $this->expectExceptionMessage('The applicant profile is being updated; try again.');
    $manager->withdrawConsent($profile, 'contact', 2000);
  }

  /**
   * A verified email takes over only unclaimed profiles with that address.
   *
   * @covers ::claimUnclaimedByVerifiedEmail
   */
  public function testClaimTakesOverOnlyUnclaimedProfilesWithTheExactEmail(): void {
    $unclaimed = $this->createApplicantProfile(0, ['field_email' => 'Citizen@Example.org ']);
    $unclaimed->expects($this->once())->method('setOwnerId')->with(42);
    $unclaimed->expects($this->once())->method('save');
    // The SQL collation may match a different address; PHP compares exactly.
    $other_address = $this->createApplicantProfile(0, ['field_email' => 'citizen@example.org.evil']);
    $other_address->expects($this->never())->method('setOwnerId');
    $other_address->expects($this->never())->method('save');
    // A row that gained an owner since the query is never moved.
    $owned = $this->createApplicantProfile(7, ['field_email' => 'citizen@example.org']);
    $owned->expects($this->never())->method('setOwnerId');
    $owned->expects($this->never())->method('save');

    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(function (string $field, mixed $value) use (&$conditions, $query): QueryInterface {
      $conditions[$field] = $value;
      return $query;
    });
    $query->method('execute')->willReturn([1 => 1, 2 => 2, 3 => 3]);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')
      ->with([1 => 1, 2 => 2, 3 => 3])
      ->willReturn([1 => $unclaimed, 2 => $other_address, 3 => $owned]);

    $this->assertSame(1, $this->managerWithLock($storage)->claimUnclaimedByVerifiedEmail(42, ' CITIZEN@example.org'));
    $this->assertSame(0, $conditions['uid'] ?? NULL);
    $this->assertSame('citizen@example.org', $conditions['field_email'] ?? NULL);
  }

  /**
   * Invalid accounts or addresses never reach the profile storage.
   *
   * @covers ::claimUnclaimedByVerifiedEmail
   */
  public function testClaimIgnoresMissingAccountOrInvalidEmail(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->never())->method('getQuery');
    $manager = $this->managerWithLock($storage);
    $this->assertSame(0, $manager->claimUnclaimedByVerifiedEmail(0, 'citizen@example.org'));
    $this->assertSame(0, $manager->claimUnclaimedByVerifiedEmail(42, ''));
    $this->assertSame(0, $manager->claimUnclaimedByVerifiedEmail(42, 'not-an-email'));
  }

  /**
   * Syncs a full consented application into an owned profile.
   *
   * @return array
   *   Field values set on the profile.
   */
  private function syncWithConsent(array $stored, int $consent_time): array {
    $profile = $this->createApplicantProfile(123, $stored);
    $setCalls = [];
    $profile->method('set')->willReturnCallback(function (string $fieldName, mixed $value) use (&$setCalls, $profile): ApplicantInterface {
      $setCalls[$fieldName] = $value;
      return $profile;
    });
    $this->manager->testDoFindOrCreate($this->createApplicantStorage($profile), [
      'person.email' => 'victim@example.org',
      'person.full_name' => 'Bertha Bürger',
      'person.phone' => '+49 555 1',
      'person.iban' => 'DE89370400440532013000',
      'person.account_holder' => 'Bertha Bürger',
    ], 123, NULL, 'victim@example.org', TRUE, [], [
      'contact' => ['time' => $consent_time, 'textVersion' => '2026-10-20.2', 'source' => 'app-uuid-1'],
      'bank' => ['time' => $consent_time, 'textVersion' => '2026-10-20.2', 'source' => 'app-uuid-1'],
    ]);
    return $setCalls;
  }

  /**
   * Builds a manager whose lock is always free.
   */
  private function managerWithLock(?EntityStorageInterface $storage = NULL): TestableApplicantManager {
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    if ($storage !== NULL) {
      $entityTypeManager->method('getStorage')->with('applicant')->willReturn($storage);
    }
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    return new TestableApplicantManager($entityTypeManager, $loggerFactory, $lock);
  }

  /**
   * Builds a manager whose storage reloads the given profile inside the lock.
   */
  private function managerReloading(ApplicantInterface $profile): TestableApplicantManager {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadUnchanged')->willReturn($profile);
    return $this->managerWithLock($storage);
  }

  /**
   * Creates applicant storage returning an existing email-matched profile.
   */
  private function createApplicantStorage(ApplicantInterface $profile): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())->method('condition')->with('field_email', 'victim@example.org')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([99 => 99]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->with(99)->willReturn($profile);
    return $storage;
  }

  /**
   * Creates applicant storage that creates a profile after no email match.
   */
  private function createApplicantStorageWithoutEmailMatch(ApplicantInterface $profile): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())->method('condition')->with('field_email', 'victim@example.org')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('create')->with(['status' => TRUE])->willReturn($profile);
    $storage->expects($this->never())->method('load');
    return $storage;
  }

  /**
   * Tests the match-only lookup of a sign-in never creates a profile.
   *
   * @covers ::doFindOrCreate
   */
  public function testMatchOnlyLookupCreatesNoProfile(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects($this->never())->method('create');
    $storage->expects($this->never())->method('load');

    $result = $this->manager->testDoFindOrCreate($storage, [
      'person.email' => 'citizen@example.org',
      'person.full_name' => 'Bertha Bürger',
    ], 123, NULL, 'citizen@example.org', TRUE, [], [], ['provider' => 'bundid', 'time' => 1781431200], FALSE);

    $this->assertNull($result);
  }

  /**
   * Tests profile selection follows the scope precedence of the sync.
   *
   * @covers ::selectProfileForScope
   */
  public function testSelectProfileForScopeUsesSyncPrecedence(): void {
    $other_org = $this->createScopedProfile(['field_jurisdiction' => 5, 'field_organisation' => 8]);
    $own_org = $this->createScopedProfile(['field_jurisdiction' => 5, 'field_organisation' => 7]);
    $profiles = [$other_org, $own_org];

    $this->assertSame($own_org, $this->manager->selectProfileForScope($profiles, [
      'field_jurisdiction' => ['target_id' => 5],
      'field_organisation' => ['target_id' => 7],
    ]));
    // The organisation decides when present; the shared jurisdiction alone
    // never lets a profile of another organisation through.
    $this->assertNull($this->manager->selectProfileForScope($profiles, [
      'field_jurisdiction' => ['target_id' => 5],
      'field_organisation' => ['target_id' => 9],
    ]));
    $this->assertSame($other_org, $this->manager->selectProfileForScope($profiles, [
      'field_jurisdiction' => ['target_id' => 5],
    ]));
    $this->assertNull($this->manager->selectProfileForScope($profiles, []));
  }

  /**
   * Creates an applicant mock with entity reference scope fields.
   *
   * @param array<string, int> $targets
   *   Target ids keyed by scope field name.
   */
  private function createScopedProfile(array $targets): ApplicantInterface {
    $profile = $this->createMock(ApplicantInterface::class);
    $profile->method('hasField')->willReturn(TRUE);
    $profile->method('get')->willReturnCallback(function (string $fieldName) use ($targets): FieldItemListInterface {
      $target = $targets[$fieldName] ?? NULL;
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn($target === NULL);
      $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'target_id' ? $target : NULL);
      return $field;
    });
    return $profile;
  }

  /**
   * Creates an applicant mock with field values.
   */
  private function createApplicantProfile(int $ownerId, array $fields, array $availableFields = []): ApplicantInterface {
    $profile = $this->createMock(ApplicantInterface::class);
    $profile->method('id')->willReturn(99);
    $profile->method('getOwnerId')->willReturn($ownerId);
    $profile->method('getSessionToken')->willReturn('');
    $profile->method('hasField')->willReturnCallback(
      static fn(string $fieldName): bool => $availableFields === [] || in_array($fieldName, $availableFields, TRUE)
    );
    $profile->method('get')->willReturnCallback(function (string $fieldName) use ($fields): FieldItemListInterface {
      $value = $fields[$fieldName] ?? '';
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn($value === '' || $value === NULL);
      $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $value : NULL);
      return $field;
    });
    return $profile;
  }

}

/**
 * Testable subclass that exposes the protected validateCrmValue method.
 */
class TestableApplicantManager extends ApplicantManager {

  /**
   * Public wrapper for the protected validateCrmValue method.
   *
   * @param string $crm_key
   *   The CRM key.
   * @param string $value
   *   The value to validate.
   *
   * @return bool
   *   The validation result.
   */
  public function testValidateCrmValue(string $crm_key, string $value): bool {
    return $this->validateCrmValue($crm_key, $value);
  }

  /**
   * Public wrapper for CRM key normalization.
   *
   * @param array $crm_data
   *   The CRM data.
   *
   * @return array
   *   The normalized CRM data.
   */
  public function testNormalizeCrmDataKeys(array $crm_data): array {
    return static::normalizeCrmDataKeys($crm_data);
  }

  /**
   * Exposes the atomic CRM key groups.
   *
   * @return string[][]
   *   The groups.
   */
  public static function atomicGroups(): array {
    return static::ATOMIC_CRM_KEY_GROUPS;
  }

  /**
   * Exposes the protected unverified-merge keys.
   *
   * @return string[]
   *   The keys.
   */
  public static function protectedMergeKeys(): array {
    return static::PROTECTED_UNVERIFIED_MERGE_KEYS;
  }

  /**
   * Public wrapper for protected doFindOrCreate.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The applicant storage.
   * @param array $crm_data
   *   CRM data.
   * @param int|null $uid
   *   User ID.
   * @param string|null $session_token
   *   Session token.
   * @param string|null $email
   *   Applicant email.
   * @param bool $email_verified
   *   Whether the CRM email is verified for the provided user ID.
   * @param array $scope
   *   Optional jurisdiction and organisation scope values.
   * @param array $consent
   *   Optional consent provenance keyed by kind.
   * @param array $login_confirmation
   *   Optional confirming sign-in with provider and time.
   * @param bool $create
   *   Whether to create a profile when none matches.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface|null
   *   The applicant profile, NULL when $create is FALSE and none matched.
   */
  public function testDoFindOrCreate(EntityStorageInterface $storage, array $crm_data, ?int $uid, ?string $session_token, ?string $email, bool $email_verified = FALSE, array $scope = [], array $consent = [], array $login_confirmation = [], bool $create = TRUE): ?ApplicantInterface {
    return $this->doFindOrCreate($storage, $crm_data, $uid, $session_token, $email, $email_verified, $scope, $consent, $login_confirmation, $create);
  }

  /**
   * Exposes the login value hash.
   */
  public static function hashLoginValue(string $value): string {
    return static::loginValueHash($value);
  }

}
