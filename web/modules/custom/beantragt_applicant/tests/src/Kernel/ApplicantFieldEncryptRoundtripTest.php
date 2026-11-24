<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Kernel;

use Drupal\beantragt_applicant\Entity\Applicant;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\ProfileConsentOffer;
use Drupal\beantragt_application\ProfileConsentScope;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountInterface;
use Drupal\encrypt\EncryptionProfileInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\field_encrypt\Entity\FieldEncryptEntityType;
use Drupal\field_encrypt\ProcessEntities;
use Drupal\group\Entity\GroupInterface;
use Drupal\jsonapi\Exception\EntityAccessDeniedHttpException;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\key\KeyInterface;
use Drupal\user\Entity\Role;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use ColinODell\PsrTestLogger\TestLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests applicant Field Encrypt persistence and deployment-key rotation.
 */
#[Group('beantragt_applicant')]
final class ApplicantFieldEncryptRoundtripTest extends GroupKernelTestBase {

  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';
  private const KEY_A = 'US-SMOKE-applicant-encryption-key-A-material';
  private const KEY_B = 'US-SMOKE-applicant-encryption-key-B-material';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'key',
    'encrypt',
    'real_aes',
    'field_encrypt',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
    'beantragt_applicant',
    'beantragt_passwordless',
    'beantragt_applicant_encryption_test',
  ];

  /**
   * Original application data key environment value.
   */
  private string|false $originalApplicationDataKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalApplicationDataKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . self::KEY_A);

    parent::setUp();

    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_passwordless', ['beantragt_passwordless_codes', 'beantragt_verified_account_mail']);
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);

    // The entity table must exist before Field Encrypt installs its dynamic
    // encrypted_field_storage base field from beantragt_applicant config.
    $this->installEntitySchema('applicant');
    $this->installConfig([
      'user',
      'beantragt_group',
      'beantragt_applicant',
      'beantragt_application',
    ]);

    // hook_install() does not run in kernel tests. Mirror its default profile.
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'beantragt_applicant')
      ->set('make_entities_uncacheable', TRUE)
      ->save(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalApplicationDataKey === FALSE) {
      putenv(self::KEY_ENV);
    }
    else {
      putenv(self::KEY_ENV . '=' . $this->originalApplicationDataKey);
    }

    parent::tearDown();
  }

  /**
   * Both session binding paths persist digests and legacy bindings expire.
   */
  public function testApplicantSessionTokenDigestAndLegacyInvalidation(): void {
    $scope = ['field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()]];
    $manager = $this->container->get('beantragt_applicant.manager');
    $database = $this->container->get('database');
    $read_token = static fn ($id): mixed => $database->select('applicant', 'a')
      ->fields('a', ['field_session_token'])->condition('id', $id)->execute()->fetchField();

    $token = 'synthetic-original-session-binding';
    $profile = $manager->findOrCreate([], session_token: $token, scope: $scope);
    $id = $profile->id();
    $this->assertSame(hash('sha256', $token), $read_token($id));
    $this->assertSame($id, $manager->findOrCreate([], session_token: $token, scope: $scope)->id());

    $mail = ['person.email' => 'session-binding@example.test'];
    $by_email = $manager->findOrCreate($mail, email_verified: TRUE, scope: $scope);
    $this->assertNull($read_token($by_email->id()));
    $mail_token = 'synthetic-second-session-binding';
    $this->assertSame($by_email->id(), $manager->findOrCreate($mail, session_token: $mail_token, email_verified: TRUE, scope: $scope)->id());
    $this->assertSame(hash('sha256', $mail_token), $read_token($by_email->id()));

    // Old raw tokens can look exactly like a digest.
    $legacy_token = str_repeat('a', 64);
    $database->update('applicant')->fields(['field_session_token' => $legacy_token])
      ->condition('id', $id)->execute();
    $this->assertSame($legacy_token, $read_token($id));
    $this->container->get('module_handler')->loadInclude('beantragt_applicant', 'install');
    beantragt_applicant_update_10009();
    $this->assertNull($read_token($id));
    $this->assertNull($read_token($by_email->id()));
    $replacement = $manager->findOrCreate([], session_token: $legacy_token, scope: $scope);
    $this->assertNotSame($id, $replacement->id());
    $this->assertSame(hash('sha256', $legacy_token), $read_token($replacement->id()));
  }

  /**
   * Sensitive applicant fields roundtrip while raw storage remains encrypted.
   */
  public function testApplicantSensitiveFieldsAreEncryptedAtRest(): void {
    $iban = 'DE02120300000000202051';
    $tax_id = '65929970489';
    $account_holder = 'US-SMOKE Kontoinhaberin';
    $jurisdiction = $this->createJurisdiction();

    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Applicant Encryption',
      'field_email' => 'us-smoke-encryption@example.test',
      'field_iban' => $iban,
      'field_tax_id' => $tax_id,
      'field_account_holder' => $account_holder,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $applicant->save();
    assert($applicant instanceof ApplicantInterface);

    $storage = $this->applicantStorage();
    $storage->resetCache([$applicant->id()]);
    $reloaded = $storage->load($applicant->id());
    assert($reloaded instanceof ApplicantInterface);

    self::assertSame($iban, $reloaded->get('field_iban')->value);
    self::assertSame($tax_id, $reloaded->get('field_tax_id')->value);
    self::assertSame($account_holder, $reloaded->get('field_account_holder')->value);

    $row = $this->container->get('database')
      ->select('applicant', 'applicant')
      ->fields('applicant', [
        'field_iban',
        'field_tax_id',
        'field_account_holder',
        'encrypted_field_storage__value',
      ])
      ->condition('id', $applicant->id())
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($row);
    self::assertSame(ProcessEntities::ENCRYPTED_VALUE, $row['field_iban']);
    self::assertSame(ProcessEntities::ENCRYPTED_VALUE, $row['field_tax_id']);
    self::assertSame(ProcessEntities::ENCRYPTED_VALUE, $row['field_account_holder']);
    self::assertNotSame($iban, $row['field_iban']);
    self::assertNotSame($tax_id, $row['field_tax_id']);
    self::assertStringStartsNotWith('enc2:', (string) $row['field_iban']);
    self::assertStringStartsNotWith('enc2:', (string) $row['field_tax_id']);

    $encrypted_blob = (string) $row['encrypted_field_storage__value'];
    self::assertNotSame('', $encrypted_blob);
    self::assertStringNotContainsString($iban, $encrypted_blob);
    self::assertStringNotContainsString($tax_id, $encrypted_blob);
    self::assertStringNotContainsString($account_holder, $encrypted_blob);
  }

  /**
   * Applicants without protected values remain saveable.
   */
  public function testApplicantWithoutSensitiveValuesCanBeSaved(): void {
    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Applicant Without Sensitive Values',
      'field_email' => 'us-smoke-no-sensitive-values@example.test',
      'field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()],
      'status' => TRUE,
    ]);

    $applicant->save();
    assert($applicant instanceof ApplicantInterface);

    self::assertFalse($applicant->isNew());
    self::assertTrue($applicant->get('field_iban')->isEmpty());
    self::assertTrue($applicant->get('field_tax_id')->isEmpty());
  }

  /**
   * Applicant saves fail with an accurate error when tenant scope is absent.
   */
  public function testApplicantWithoutJurisdictionScopeCannotBeSaved(): void {
    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Unscoped Applicant',
      'field_email' => 'us-smoke-unscoped@example.test',
      'status' => TRUE,
    ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('The applicant cannot be saved without a jurisdiction scope.');
    $applicant->save();
  }

  /**
   * Tenant scope is mandatory even when an applicant has no email identity.
   */
  public function testApplicantWithoutEmailStillRequiresJurisdictionScope(): void {
    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Unscoped Applicant Without Email',
      'status' => TRUE,
    ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('The applicant cannot be saved without a jurisdiction scope.');
    $applicant->save();
  }

  /**
   * Ciphertext created by one deployment key cannot decrypt with another.
   */
  public function testDeploymentKeyRotationIsCryptographicallyIsolated(): void {
    $encryption = $this->container->get('encryption');
    assert($encryption instanceof EncryptServiceInterface);

    $plaintext_a = 'US-SMOKE rotation plaintext A';
    $profile_a = $this->freshEncryptionProfile();
    $ciphertext_a = $encryption->encrypt($plaintext_a, $profile_a);

    putenv(self::KEY_ENV . '=' . self::KEY_B);
    $profile_b = $this->freshEncryptionProfile();
    $this->assertCiphertextDoesNotDecryptTo($ciphertext_a, $plaintext_a, $profile_b);

    $plaintext_b = 'US-SMOKE rotation plaintext B';
    $ciphertext_b = $encryption->encrypt($plaintext_b, $profile_b);

    putenv(self::KEY_ENV . '=' . self::KEY_A);
    $profile_a = $this->freshEncryptionProfile();
    $this->assertCiphertextDoesNotDecryptTo($ciphertext_b, $plaintext_b, $profile_a);
  }

  /**
   * Runtime requirements accept the registered hook and working profile.
   */
  public function testRuntimeEncryptionGuardPasses(): void {
    include_once DRUPAL_ROOT . '/modules/custom/beantragt_applicant/beantragt_applicant.install';

    $requirements = \beantragt_applicant_requirements('runtime');

    self::assertArrayNotHasKey('beantragt_applicant_encryption_key', $requirements);
    self::assertArrayNotHasKey('beantragt_applicant_field_encrypt_hook', $requirements);
    self::assertArrayNotHasKey('beantragt_applicant_field_encrypt_profile', $requirements);
    self::assertArrayNotHasKey('beantragt_applicant_field_encrypt_mapping', $requirements);
    self::assertArrayNotHasKey('beantragt_applicant_encryption_probe', $requirements);
  }

  /**
   * Applicant saves fail closed when another encryption profile is active.
   */
  public function testApplicantSaveRejectsEncryptionProfileDrift(): void {
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'unexpected_profile')
      ->save(TRUE);

    include_once DRUPAL_ROOT . '/modules/custom/beantragt_applicant/beantragt_applicant.install';
    $requirements = \beantragt_applicant_requirements('runtime');
    self::assertArrayHasKey('beantragt_applicant_field_encrypt_profile', $requirements);

    $jurisdiction = $this->createJurisdiction();
    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Encryption Profile Drift',
      'field_email' => 'us-smoke-profile-drift@example.test',
      'field_iban' => 'DE02120300000000202051',
      'field_tax_id' => '65929970489',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Restore the beantragt_applicant encryption profile');
    $applicant->save();
  }

  /**
   * Applicant saves fail cleanly when the configured profile was deleted.
   */
  public function testApplicantSaveRejectsDeletedEncryptionProfile(): void {
    $profile_storage = $this->entityTypeManager()
      ->getStorage('encryption_profile');
    $profile = $profile_storage->load('beantragt_applicant');
    assert($profile instanceof EncryptionProfileInterface);
    $this->config('encrypt.profile.beantragt_applicant')->delete();
    $profile_storage->resetCache(['beantragt_applicant']);
    self::assertNull($profile_storage->load('beantragt_applicant'));

    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Deleted Encryption Profile',
      'field_email' => 'us-smoke-deleted-profile@example.test',
      'field_iban' => 'DE02120300000000202051',
      'field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()],
      'status' => TRUE,
    ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Restore the beantragt_applicant encryption profile');
    $applicant->save();
  }

  /**
   * Applicant saves fail closed when another module disables encryption.
   */
  public function testApplicantSaveRejectsEncryptionHookDenial(): void {
    $this->container->get('state')
      ->set('beantragt_applicant_encryption_test.deny', TRUE);

    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Encryption Hook Denial',
      'field_email' => 'us-smoke-hook-denial@example.test',
      'field_tax_id' => '65929970489',
      'field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()],
      'status' => TRUE,
    ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Encryption cannot be disabled for protected applicant fields.');
    $applicant->save();
  }

  /**
   * The actual encryption result is checked after a changing hook decision.
   */
  public function testApplicantSaveRejectsLateEncryptionHookDenial(): void {
    $state = $this->container->get('state');
    $state->set('beantragt_applicant_encryption_test.deny_second', TRUE);

    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Late Encryption Hook Denial',
      'field_email' => 'us-smoke-late-hook-denial@example.test',
      'field_iban' => 'DE02120300000000202051',
      'field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()],
      'status' => TRUE,
    ]);

    try {
      $applicant->save();
      self::fail('A late Field Encrypt hook denial must reject the applicant save.');
    }
    catch (EntityStorageException $exception) {
      self::assertSame(
        'Applicant field encryption is unavailable. Protected applicant fields were not encrypted before storage.',
        $exception->getMessage(),
      );
      self::assertSame(2, $state->get('beantragt_applicant_encryption_test.calls'));
    }
  }

  /**
   * Applicant saves fail cleanly when a protected field is undefined.
   */
  public function testApplicantSaveRejectsMissingProtectedFieldDefinition(): void {
    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Missing Protected Field Definition',
      'field_email' => 'us-smoke-missing-field-definition@example.test',
      'field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()],
      'status' => TRUE,
    ]);
    assert($applicant instanceof ApplicantInterface);

    $definitions = $applicant->getFieldDefinitions();
    unset($definitions['field_iban']);
    $property = new \ReflectionProperty(ContentEntityBase::class, 'fieldDefinitions');
    $property->setValue($applicant, $definitions);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Verify the Field Encrypt mapping before saving applicant data.');
    $applicant->save();
  }

  /**
   * Applicant saves fail closed when protected-field mapping is unavailable.
   */
  public function testApplicantSaveRejectsMissingFieldEncryptMapping(): void {
    $mapping = $this->entityTypeManager()
      ->getStorage('field_encrypt_entity_type')
      ->load('applicant');
    assert($mapping instanceof FieldEncryptEntityType);
    $mapping->delete();

    $jurisdiction = $this->createJurisdiction();
    $applicant = $this->applicantStorage()->create([
      'field_full_name' => 'US-SMOKE Missing Encryption Mapping',
      'field_email' => 'us-smoke-missing-mapping@example.test',
      'field_iban' => 'DE02120300000000202051',
      'field_tax_id' => '65929970489',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Applicant field encryption is unavailable.');
    $applicant->save();
  }

  /**
   * An applicant encryption failure does not roll back application insert.
   */
  public function testApplicationInsertSurvivesApplicantEncryptionFailure(): void {
    $this->setCurrentUser($this->serviceAccountUser());
    $jurisdiction = $this->createJurisdiction(TRUE);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Applicant Failure Organisation',
      'field_slug' => 'us-smoke-applicant-failure-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createApplicantSyncSchema($organisation, $jurisdiction);

    $this->container->set('beantragt_applicant.manager', new class() {

      /**
       * Simulates the fail-closed applicant encryption guard.
       */
      public function findOrCreate(): never {
        throw new EntityStorageException('Restore the beantragt_applicant encryption profile before saving protected applicant data.');
      }

    });
    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('beantragt_applicant')
      ->addLogger($logger);

    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode([
        'full_name' => 'Ada Antrag',
        'email' => 'ada@example.org',
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $workflow_status->id()],
      'field_email_confirmed' => TRUE,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ] + $this->profileConsentValues());
    $application->save();
    assert($application instanceof ApplicationInterface);

    self::assertFalse($application->isNew());
    self::assertNotNull($this->entityTypeManager()->getStorage('application')->load($application->id()));
    self::assertSame([], $this->applicantStorage()->getQuery()->accessCheck(FALSE)->execute());
    self::assertTrue($logger->hasRecordThatContains(
      'Applicant CRM sync failed after application insert',
    ), json_encode($logger->records, JSON_THROW_ON_ERROR));
  }

  /**
   * Only applications with consent in a switched-on jurisdiction sync.
   */
  public function testApplicationInsertSyncsProfileOnlyWithConsent(): void {
    $this->setCurrentUser($this->serviceAccountUser());
    $jurisdiction = $this->createJurisdiction(TRUE);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Applicant Consent Organisation',
      'field_slug' => 'us-smoke-applicant-consent-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createApplicantSyncSchema($organisation, $jurisdiction);
    $create = function (string $email, array $consent) use ($schema, $workflow_status, $jurisdiction, $organisation): ApplicationInterface {
      $application = $this->entityTypeManager()->getStorage('application')->create([
        'field_schema' => ['target_id' => $schema->id()],
        'field_data' => json_encode(['full_name' => 'Ada Antrag', 'email' => $email], JSON_THROW_ON_ERROR),
        'field_workflow_status' => ['target_id' => $workflow_status->id()],
        'field_email_confirmed' => TRUE,
        'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
        'field_organisation' => ['target_id' => $organisation->id()],
        'field_group' => ['target_id' => $organisation->id()],
        'status' => TRUE,
      ] + $consent);
      $application->save();
      assert($application instanceof ApplicationInterface);
      return $application;
    };

    $without = $create('without-consent@example.test', []);
    self::assertFalse($without->hasProfileConsent());
    self::assertSame([], $this->applicantStorage()->getQuery()->accessCheck(FALSE)->execute());

    $with = $create('with-consent@example.test', $this->profileConsentValues());
    self::assertTrue($with->hasProfileConsent());
    self::assertSame('2026-10-20', $with->getProfileConsentRecord()['textVersion']);
    $ids = $this->applicantStorage()->getQuery()->accessCheck(FALSE)->execute();
    self::assertCount(1, $ids);
    $applicant = $this->applicantStorage()->load(reset($ids));
    assert($applicant instanceof ApplicantInterface);
    self::assertSame('with-consent@example.test', $applicant->get('field_email')->value);
    self::assertSame(1781431200, (int) $applicant->get('field_consent_time')->value);
    self::assertSame('2026-10-20', $applicant->get('field_consent_text_version')->value);
    self::assertSame($with->uuid(), $applicant->get('field_consent_source')->value);
    self::assertTrue($applicant->get('field_bank_consent_time')->isEmpty());
  }

  /**
   * Bank details reach the encrypted profile only with the bank consent.
   */
  public function testApplicationInsertSyncsBankDetailsOnlyWithBankConsent(): void {
    $this->setCurrentUser($this->serviceAccountUser());
    $jurisdiction = $this->createJurisdiction(TRUE);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Applicant Bank Organisation',
      'field_slug' => 'us-smoke-applicant-bank-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createApplicantSyncSchema($organisation, $jurisdiction, TRUE);
    $iban = 'DE89370400440532013000';
    $holder = 'US-SMOKE Kontoinhaberin';
    $create = function (string $email, bool $bank) use ($schema, $workflow_status, $jurisdiction, $organisation, $iban, $holder): ApplicationInterface {
      $application = $this->entityTypeManager()->getStorage('application')->create([
        'field_schema' => ['target_id' => $schema->id()],
        'field_data' => json_encode([
          'full_name' => 'Ada Antrag',
          'email' => $email,
          'iban' => $iban,
          'account_holder' => $holder,
        ], JSON_THROW_ON_ERROR),
        'field_workflow_status' => ['target_id' => $workflow_status->id()],
        'field_email_confirmed' => TRUE,
        'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
        'field_organisation' => ['target_id' => $organisation->id()],
        'field_group' => ['target_id' => $organisation->id()],
        'status' => TRUE,
      ] + $this->profileConsentValues($bank));
      $application->save();
      assert($application instanceof ApplicationInterface);
      return $application;
    };

    $with_bank = $create('with-bank@example.test', TRUE);
    $without_bank = $create('without-bank@example.test', FALSE);

    self::assertStringNotContainsString($iban, $with_bank->getData());
    self::assertStringNotContainsString($iban, $without_bank->getData());
    self::assertStringNotContainsString($holder, $with_bank->getData());
    self::assertStringNotContainsString($holder, $without_bank->getData());
    $with_profile = $this->applicantByEmail('with-bank@example.test');
    self::assertSame($iban, $with_profile->get('field_iban')->value);
    self::assertSame($holder, $with_profile->get('field_account_holder')->value);
    self::assertSame(1781431200, (int) $with_profile->get('field_bank_consent_time')->value);
    self::assertSame($with_bank->uuid(), $with_profile->get('field_bank_consent_source')->value);
    $proofs = json_decode($with_profile->get(ApplicantInterface::CONSENT_PROOF_FIELD)->value, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('bank', $proofs['field_iban']['kind']);
    self::assertSame('bank', $proofs['field_account_holder']['kind']);
    self::assertSame('contact', $proofs['field_full_name']['kind']);
    self::assertSame($with_bank->getBankConsentRecord()['text'], $proofs['field_iban']['text']);
    self::assertArrayNotHasKey('fields', $proofs['field_iban']);
    $row = $this->container->get('database')
      ->select('applicant', 'applicant')
      ->fields('applicant', ['field_iban', 'field_account_holder'])
      ->condition('id', $with_profile->id())
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($row);
    self::assertSame(ProcessEntities::ENCRYPTED_VALUE, $row['field_iban']);
    self::assertSame(ProcessEntities::ENCRYPTED_VALUE, $row['field_account_holder']);

    $without_profile = $this->applicantByEmail('without-bank@example.test');
    self::assertTrue($without_profile->get('field_iban')->isEmpty());
    self::assertTrue($without_profile->get('field_account_holder')->isEmpty());
    self::assertTrue($without_profile->get('field_bank_consent_time')->isEmpty());
    self::assertSame($without_bank->uuid(), $without_profile->get('field_consent_source')->value);

    $this->container->get('beantragt_applicant.manager')->withdrawConsent($with_profile, 'bank', 1781431300);
    $withdrawn = $this->applicantByEmail('with-bank@example.test');
    $remaining = json_decode($withdrawn->get(ApplicantInterface::CONSENT_PROOF_FIELD)->value, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(array_diff_key($proofs, ['field_iban' => TRUE, 'field_account_holder' => TRUE]), $remaining);
  }

  /**
   * A narrower application keeps the proof for fields its consent omits.
   */
  public function testPerFieldConsentProofSurvivesNarrowerConsentAndSourceDeletion(): void {
    $this->installEntitySchema('application_attachment');
    $this->setCurrentUser($this->serviceAccountUser());
    $citizen = $this->createUser();
    $jurisdiction = $this->createJurisdiction(TRUE);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Bürgerbüro Musterstadt',
      'field_slug' => 'consent-proof-office',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $scope = [
      'field_jurisdiction' => (int) $jurisdiction->id(),
      'field_organisation' => (int) $organisation->id(),
      'field_group' => (int) $organisation->id(),
    ];
    $name = [
      'id' => 'full_name',
      'type' => 'text',
      'label' => 'Name',
      'crm_key' => 'person.full_name',
      'privacy' => ['kind' => 'identity'],
    ];
    $phone = [
      'id' => 'phone',
      'type' => 'tel',
      'label' => 'Phone',
      'crm_key' => 'person.phone',
      'privacy' => ['kind' => 'contact'],
    ];
    $submit = function (array $fields, array $data, int $time) use ($organisation, $jurisdiction, $citizen, $workflow_status, $scope): ApplicationInterface {
      $form = $this->createApplicantSyncSchema($organisation, $jurisdiction, contactFields: $fields);
      $offer = ProfileConsentOffer::build(
        $form,
        json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR),
        ProfileConsentScope::formGroups($form),
        $this->container->get('beantragt_application.applicant_profile_switch'),
        'de',
      )['contact'];
      self::assertNotNull($offer);
      $application = $this->entityTypeManager()->getStorage('application')->create([
        'uid' => $citizen->id(),
        'field_schema' => ['target_id' => $form->id()],
        'field_data' => json_encode($data, JSON_THROW_ON_ERROR),
        'field_workflow_status' => ['target_id' => $workflow_status->id()],
        'field_email_confirmed' => TRUE,
        'field_profile_consent' => TRUE,
        'field_profile_consent_record' => json_encode($offer + [
          'time' => $time,
          'source' => 'public_submit',
          'profileScope' => $scope,
        ], JSON_THROW_ON_ERROR),
        'status' => TRUE,
      ] + $scope);
      $application->save();
      assert($application instanceof ApplicationInterface);
      return $application;
    };

    $first = $submit([$name, $phone], ['full_name' => 'Ada Antrag', 'phone' => '030 555 01'], 1781431200);
    $first_id = $first->id();
    $first_uuid = $first->uuid();
    $first_consent = $first->getProfileConsentRecord();
    $second = $submit([$name], ['full_name' => 'Ada Beispiel'], 1781431300);
    $second_consent = $second->getProfileConsentRecord();
    self::assertSame(['person.full_name', 'person.phone'], $first_consent['fields']);
    self::assertSame(['person.full_name'], $second_consent['fields']);

    $first->delete();
    self::assertNull($this->entityTypeManager()->getStorage('application')->loadUnchanged($first_id));
    $profiles = $this->applicantStorage()->loadByProperties(['uid' => $citizen->id()]);
    self::assertCount(1, $profiles);
    $stored = $this->applicantStorage()->loadUnchanged(array_key_first($profiles));
    assert($stored instanceof ApplicantInterface);
    self::assertSame('030 555 01', $stored->get('field_phone')->value);
    self::assertSame('Ada Beispiel', $stored->get('field_full_name')->value);
    $this->assertConsentProofUnavailableThroughJsonApi($stored);
    $proofs = json_decode($stored->get(ApplicantInterface::CONSENT_PROOF_FIELD)->value, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame(['field_full_name', 'field_phone'], array_keys($proofs));
    $phone_proof = $proofs['field_phone'];
    self::assertSame($first_uuid, $phone_proof['source']);
    self::assertSame($second->uuid(), $proofs['field_full_name']['source']);
    self::assertSame($second_consent['textHash'], $proofs['field_full_name']['textHash']);
    foreach (['time', 'textVersion', 'textHash', 'locale', 'fields', 'text'] as $key) {
      self::assertSame($first_consent[$key], $phone_proof[$key], $key);
    }
    self::assertSame($phone_proof['textHash'], hash('sha256', implode('|', [
      $phone_proof['textVersion'],
      $phone_proof['kind'],
      $phone_proof['locale'],
      $phone_proof['text'],
    ])));

    $this->container->get('beantragt_applicant.manager')->withdrawConsent($stored, 'contact', 1781431400);
    $withdrawn = $this->applicantStorage()->loadUnchanged($stored->id());
    assert($withdrawn instanceof ApplicantInterface);
    self::assertTrue($withdrawn->get(ApplicantInterface::CONSENT_PROOF_FIELD)->isEmpty());
    self::assertTrue($withdrawn->get('field_full_name')->isEmpty());
    self::assertTrue($withdrawn->get('field_phone')->isEmpty());
  }

  /**
   * Even a scope bypass account cannot read or write the proof via JSON:API.
   */
  private function assertConsentProofUnavailableThroughJsonApi(ApplicantInterface $profile): void {
    $field_name = ApplicantInterface::CONSENT_PROOF_FIELD;
    $resource_type = $this->container->get('jsonapi.resource_type.repository')->get('applicant', 'applicant');
    $normalization = $this->container->get('jsonapi.serializer')->normalize(
      ResourceObject::createFromEntity($resource_type, $profile),
      'api_json',
      [
        'account' => $this->container->get('current_user'),
        'resource_type' => $resource_type,
        'sparse_fieldset' => [$resource_type->getTypeName() => ['field_full_name', $field_name]],
      ],
    );
    $attributes = $normalization->getNormalization()['attributes'];
    self::assertSame('Ada Beispiel', $attributes['field_full_name']);
    self::assertArrayNotHasKey($field_name, $attributes);

    $resource = $this->container->get('jsonapi.entity_resource');
    $data = ['type' => $resource_type->getTypeName(), 'attributes' => [$field_name => '{}']];
    foreach (['POST', 'PATCH'] as $method) {
      $body = $method === 'PATCH' ? $data + ['id' => $profile->uuid()] : $data;
      $request = Request::create('/', $method, content: json_encode(['data' => $body], JSON_THROW_ON_ERROR));
      try {
        if ($method === 'POST') {
          $resource->createIndividual($resource_type, $request);
        }
        else {
          $resource->patchIndividual($resource_type, $profile, $request);
        }
        self::fail($method . ' accepted the internal consent proof.');
      }
      catch (EntityAccessDeniedHttpException $exception) {
        self::assertSame(403, $exception->getStatusCode());
        self::assertStringContainsString($field_name, $exception->getMessage());
      }
    }
  }

  /**
   * A switched-off jurisdiction stops the sync despite a stored consent.
   */
  public function testApplicationInsertSkipsSyncWhenJurisdictionSwitchIsOff(): void {
    $this->setCurrentUser($this->serviceAccountUser());
    $jurisdiction = $this->createJurisdiction();
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Applicant Switch Organisation',
      'field_slug' => 'us-smoke-applicant-switch-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createApplicantSyncSchema($organisation, $jurisdiction);
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['full_name' => 'Ada Antrag', 'email' => 'switch-off@example.test'], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $workflow_status->id()],
      'field_email_confirmed' => TRUE,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ] + $this->profileConsentValues());
    $application->save();
    assert($application instanceof ApplicationInterface);

    self::assertTrue($application->hasProfileConsent());
    self::assertSame([], $this->applicantStorage()->getQuery()->accessCheck(FALSE)->execute());
  }

  /**
   * A saved profile never mixes an old street with a new postal code.
   */
  public function testSavedProfileReplacesAddressAndNameAsUnits(): void {
    $email = 'us-smoke-address-unit@example.test';
    $scope = ['field_jurisdiction' => ['target_id' => $this->createJurisdiction()->id()]];
    // A signed-in citizen owns the profile and submits all three times.
    $this->setCurrentUser($this->serviceAccountUser());
    $uid = (int) $this->container->get('current_user')->id();
    self::assertGreaterThan(0, $uid);
    $manager = $this->container->get('beantragt_applicant.manager');
    $applicant = $manager->findOrCreate([
      'person.email' => $email,
      'person.full_name' => 'Max Mustermann',
      'person.street' => 'Marktplatz 1',
      'person.postal_code' => '12345',
      'person.city' => 'Musterstadt',
    ], $uid, NULL, TRUE, $scope);
    self::assertSame($uid, (int) $applicant->getOwnerId());

    $manager->findOrCreate([
      'person.email' => $email,
      'person.first_name' => 'Erika',
      'person.last_name' => 'Musterfrau',
      'person.street' => 'Bauweg 12',
      'person.postal_code' => '54321',
      'person.city' => 'Musterstadt',
    ], $uid, NULL, TRUE, $scope);
    $this->applicantStorage()->resetCache();
    $applicant = $this->applicantByEmail($email);
    self::assertSame('Bauweg 12', $applicant->get('field_street')->value);
    self::assertSame('54321', $applicant->get('field_postal_code')->value);
    self::assertSame('Erika Musterfrau', $applicant->get('field_full_name')->value);
    self::assertSame('Erika Musterfrau', $applicant->label());

    $manager->findOrCreate([
      'person.email' => $email,
      'person.postal_code' => '99999',
      'person.city' => 'Neustadt',
    ], $uid, NULL, TRUE, $scope);
    $this->applicantStorage()->resetCache();
    $applicant = $this->applicantByEmail($email);
    self::assertTrue($applicant->get('field_street')->isEmpty());
    self::assertSame('99999', $applicant->get('field_postal_code')->value);
    self::assertSame('Neustadt', $applicant->get('field_city')->value);
    self::assertSame('Erika', $applicant->get('field_first_name')->value);

    // Repeating the stored first name reads the stored (encrypted) unit and
    // keeps it.
    $manager->findOrCreate([
      'person.email' => $email,
      'person.first_name' => 'Erika',
    ], $uid, NULL, TRUE, $scope);
    $this->applicantStorage()->resetCache();
    $applicant = $this->applicantByEmail($email);
    self::assertSame('Musterfrau', $applicant->get('field_last_name')->value);
    self::assertSame('Erika Musterfrau', $applicant->get('field_full_name')->value);
    self::assertSame('99999', $applicant->get('field_postal_code')->value);
  }

  /**
   * Takeover, withdrawal and the withdrawal gate on real entities.
   *
   * An application without sign-in creates an unclaimed profile; the proven
   * email takes it over; the contact and bank withdrawals clear the profile
   * values but leave the application and its consent record unchanged; an
   * older consent then writes nothing, a newer one syncs again while the
   * withdrawal time stays.
   */
  public function testWithdrawalClearsProfileKeepsApplicationsAndBlocksOlderConsents(): void {
    $this->setCurrentUser($this->serviceAccountUser());
    $jurisdiction = $this->createJurisdiction(TRUE);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Applicant Withdrawal Organisation',
      'field_slug' => 'us-smoke-applicant-withdrawal-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createApplicantSyncSchema($organisation, $jurisdiction, TRUE);
    $email = 'withdraw@example.test';
    $citizen = $this->createUser([], 'withdraw-citizen', FALSE, ['mail' => $email]);
    // The SSO contract requires mailbox provenance for address-based sync.
    $this->container->get('beantragt_passwordless.verified_mail')->verify($citizen, $email, 'test');
    $create = function (string $name, int $consent_time, ?int $owner = NULL) use ($schema, $workflow_status, $jurisdiction, $organisation, $email): ApplicationInterface {
      $values = [
        'field_schema' => ['target_id' => $schema->id()],
        'field_data' => json_encode([
          'full_name' => $name,
          'email' => $email,
          'iban' => 'DE89370400440532013000',
          'account_holder' => $name,
        ], JSON_THROW_ON_ERROR),
        'field_workflow_status' => ['target_id' => $workflow_status->id()],
        'field_email_confirmed' => TRUE,
        'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
        'field_organisation' => ['target_id' => $organisation->id()],
        'field_group' => ['target_id' => $organisation->id()],
        'status' => TRUE,
      ] + $this->profileConsentValues(TRUE, $consent_time);
      if ($owner !== NULL) {
        $values['uid'] = $owner;
      }
      $application = $this->entityTypeManager()->getStorage('application')->create($values);
      $application->save();
      assert($application instanceof ApplicationInterface);
      return $application;
    };
    /** @var \Drupal\beantragt_applicant\Service\ApplicantManager $manager */
    $manager = $this->container->get('beantragt_applicant.manager');

    $first = $create('Ada Antrag', 1781431200);
    $record_before = $first->get('field_profile_consent_record')->value;
    $profile = $this->applicantByEmail($email);
    self::assertSame(0, (int) $profile->getOwnerId());

    self::assertSame(1, $manager->claimUnclaimedByVerifiedEmail((int) $citizen->id(), ' WITHDRAW@example.test'));
    self::assertSame(0, $manager->claimUnclaimedByVerifiedEmail((int) $this->createUser()->id(), $email), 'A claimed profile never moves to another account.');
    $this->applicantStorage()->resetCache();
    $profile = $this->applicantByEmail($email);
    self::assertSame((int) $citizen->id(), (int) $profile->getOwnerId());

    $manager->withdrawConsent($profile, 'contact', 1781431300);
    $this->applicantStorage()->resetCache();
    $profile = $this->applicantByEmail($email);
    self::assertTrue($profile->get('field_full_name')->isEmpty());
    self::assertSame($email, $profile->get('field_email')->value);
    self::assertTrue($profile->get('field_consent_time')->isEmpty());
    self::assertSame(1781431300, (int) $profile->get('field_consent_revoked_time')->value);
    self::assertSame('DE89370400440532013000', $profile->get('field_iban')->value, 'The bank consent is withdrawn on its own.');

    $manager->withdrawConsent($profile, 'bank', 1781431300);
    $this->applicantStorage()->resetCache();
    $profile = $this->applicantByEmail($email);
    self::assertTrue($profile->get('field_iban')->isEmpty());
    self::assertTrue($profile->get('field_account_holder')->isEmpty());
    self::assertSame(1781431300, (int) $profile->get('field_bank_consent_revoked_time')->value);

    $this->entityTypeManager()->getStorage('application')->resetCache();
    $first = $this->entityTypeManager()->getStorage('application')->load($first->id());
    assert($first instanceof ApplicationInterface);
    self::assertTrue($first->hasProfileConsent());
    self::assertTrue($first->hasBankConsent());
    self::assertSame($record_before, $first->get('field_profile_consent_record')->value);

    // Consent given before the withdrawal (for example confirmed late).
    $create('Older Consent', 1781431250, (int) $citizen->id());
    $this->applicantStorage()->resetCache();
    $profile = $this->applicantByEmail($email);
    self::assertTrue($profile->get('field_full_name')->isEmpty());
    self::assertTrue($profile->get('field_iban')->isEmpty());

    // A new consent after the withdrawal counts again.
    $create('Newer Consent', 1781431400, (int) $citizen->id());
    $this->applicantStorage()->resetCache();
    $profile = $this->applicantByEmail($email);
    self::assertSame('Newer Consent', $profile->get('field_full_name')->value);
    self::assertSame('DE89370400440532013000', $profile->get('field_iban')->value);
    self::assertSame(1781431400, (int) $profile->get('field_consent_time')->value);
    self::assertSame(1781431300, (int) $profile->get('field_consent_revoked_time')->value);
  }

  /**
   * A signed-in account with an unproven foreign address creates no profile.
   *
   * Only anonymous submissions (service account owner) confirm the address
   * by link; the profile would otherwise sit unclaimed under the victim's
   * address and be taken over by the victim at the next login.
   */
  public function testSignedInForeignAddressCreatesNoProfile(): void {
    $this->setCurrentUser($this->serviceAccountUser());
    $jurisdiction = $this->createJurisdiction(TRUE);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'US-SMOKE Applicant Foreign Address Organisation',
      'field_slug' => 'us-smoke-applicant-foreign-address-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createApplicantSyncSchema($organisation, $jurisdiction, TRUE);
    $attacker = $this->createUser([], 'foreign-attacker', FALSE, ['mail' => 'attacker@example.test']);
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'uid' => $attacker->id(),
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode([
        'full_name' => 'Vera Victim',
        'email' => 'victim@example.test',
        'iban' => 'DE89370400440532013000',
        'account_holder' => 'Vera Victim',
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => ['target_id' => $workflow_status->id()],
      'field_email_confirmed' => TRUE,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ] + $this->profileConsentValues(TRUE));
    $application->save();
    assert($application instanceof ApplicationInterface);
    self::assertTrue($application->hasProfileConsent());

    self::assertSame([], $this->applicantStorage()->getQuery()->accessCheck(FALSE)->execute());
    /** @var \Drupal\beantragt_applicant\Service\ApplicantManager $manager */
    $manager = $this->container->get('beantragt_applicant.manager');
    $victim = $this->createUser([], 'foreign-victim', FALSE, ['mail' => 'victim@example.test']);
    self::assertSame(0, $manager->claimUnclaimedByVerifiedEmail((int) $victim->id(), 'victim@example.test'));
  }

  /**
   * The erasure field list names every consent and login confirmation field.
   */
  public function testConsentAndLoginFieldListMatchesDefinitions(): void {
    $expected = array_keys(Applicant::consentBaseFieldDefinitions() + Applicant::revocationBaseFieldDefinitions() + Applicant::consentProofBaseFieldDefinitions());
    $expected[] = ApplicantInterface::CONSENT_PROOF_FIELD;
    $actual = ApplicantInterface::CONSENT_AND_LOGIN_FIELDS;
    sort($expected);
    sort($actual);
    self::assertSame($expected, $actual);
  }

  /**
   * Creates a service account user that may bypass group scope checks.
   *
   * The service account owns anonymous submissions.
   */
  private function serviceAccountUser(): AccountInterface {
    if (Role::load('service_account') === NULL) {
      Role::create(['id' => 'service_account', 'label' => 'Service account'])->save();
    }
    $user = $this->createUser(['bypass beantragt group scope']);
    $user->addRole('service_account');
    $user->save();
    return $user;
  }

  /**
   * Loads the single applicant profile with an email address.
   */
  private function applicantByEmail(string $email): ApplicantInterface {
    $ids = $this->applicantStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email', $email)
      ->execute();
    self::assertCount(1, $ids);
    $applicant = $this->applicantStorage()->load(reset($ids));
    assert($applicant instanceof ApplicantInterface);
    return $applicant;
  }

  /**
   * Reloads the profile and refreshes its environment-backed key value.
   */
  private function freshEncryptionProfile(): EncryptionProfileInterface {
    $key_storage = $this->entityTypeManager()->getStorage('key');
    $key_storage->resetCache(['beantragt_applicant']);
    $key = $key_storage->load('beantragt_applicant');
    assert($key instanceof KeyInterface);
    $key->getKeyValue(TRUE);

    $profile_storage = $this->entityTypeManager()->getStorage('encryption_profile');
    $profile_storage->resetCache(['beantragt_applicant']);
    $profile = $profile_storage->load('beantragt_applicant');
    assert($profile instanceof EncryptionProfileInterface);
    return $profile;
  }

  /**
   * Asserts ciphertext cannot reveal the expected text under another key.
   */
  private function assertCiphertextDoesNotDecryptTo(
    string $ciphertext,
    string $plaintext,
    EncryptionProfileInterface $profile,
  ): void {
    $encryption = $this->container->get('encryption');
    assert($encryption instanceof EncryptServiceInterface);

    try {
      $decrypted = $encryption->decrypt($ciphertext, $profile);
    }
    catch (\Throwable) {
      self::addToAssertionCount(1);
      return;
    }

    self::assertNotSame($plaintext, $decrypted);
  }

  /**
   * Creates the jurisdiction required by the applicant scope lock.
   */
  private function createJurisdiction(bool $profilesEnabled = FALSE): GroupInterface {
    $values = [
      'type' => 'jur',
      'label' => 'US-SMOKE Applicant Encryption Jurisdiction',
      'field_slug' => 'us-smoke-applicant-encryption',
    ];
    if ($profilesEnabled) {
      $values['field_jur_config'] = json_encode([
        'applicant_profile' => ['enabled' => TRUE, 'scope' => 'organisation', 'bankConsent' => TRUE],
      ], JSON_THROW_ON_ERROR);
    }
    return $this->createGroup($values);
  }

  /**
   * Returns application values that carry an effective contact consent.
   *
   * @return array<string, mixed>
   *   Consent field values.
   */
  private function profileConsentValues(bool $bank = FALSE, int $time = 1781431200): array {
    $record = json_encode([
      'time' => $time,
      'locale' => 'de',
      'textVersion' => '2026-10-20',
      'text' => 'Kernel test consent',
      'textHash' => str_repeat('a', 64),
      'scope' => ['type' => 'org', 'id' => 0, 'uuid' => '', 'label' => 'US-SMOKE'],
      'source' => 'public_submit',
    ], JSON_THROW_ON_ERROR);
    return [
      'field_profile_consent' => TRUE,
      'field_profile_consent_record' => $record,
      'field_bank_consent' => $bank,
      'field_bank_consent_record' => $bank ? $record : NULL,
    ];
  }

  /**
   * Creates the default application workflow status for one organisation.
   */
  private function createDefaultWorkflowStatus(GroupInterface $group): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Eingegangen',
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => 'eingegangen',
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => TRUE,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Creates a form whose submitted identity is eligible for applicant sync.
   */
  private function createApplicantSyncSchema(GroupInterface $organisation, GroupInterface $jurisdiction, bool $withBankFields = FALSE, ?array $contactFields = NULL): FormSchemaInterface {
    $fields = $contactFields ?? [
      [
        'id' => 'full_name',
        'type' => 'text',
        'label' => 'Full name',
        'crm_key' => 'person.full_name',
        'privacy' => ['kind' => 'identity'],
      ],
      [
        'id' => 'email',
        'type' => 'email',
        'label' => 'Email',
        'crm_key' => 'person.email',
        'privacy' => ['kind' => 'contact'],
      ],
    ];
    if ($withBankFields) {
      $fields[] = [
        'id' => 'iban',
        'type' => 'text',
        'label' => 'IBAN',
        'crm_key' => 'person.iban',
        'privacy' => ['kind' => 'financial'],
      ];
      $fields[] = [
        'id' => 'account_holder',
        'type' => 'text',
        'label' => 'Account holder',
        'crm_key' => 'person.account_holder',
        'privacy' => ['kind' => 'financial'],
      ];
    }
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'US-SMOKE Applicant Sync Failure',
      'field_schema' => json_encode([
        'id' => 'applicant_sync_failure',
        'title' => 'Applicant sync failure',
        'sections' => [[
          'id' => 'person',
          'title' => 'Person',
          'fields' => $fields,
        ],
        ],
      ] + ($contactFields === NULL ? [] : [
        'auth' => ['collect' => ['fullName' => 'none', 'email' => 'none']],
      ]), JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $schema->save();
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Returns applicant entity storage.
   */
  private function applicantStorage(): EntityStorageInterface {
    return $this->entityTypeManager()->getStorage('applicant');
  }

}
