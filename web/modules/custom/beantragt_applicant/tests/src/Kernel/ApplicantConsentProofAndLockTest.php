<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Kernel;

use Drupal\beantragt_applicant\Entity\Applicant;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the locked fresh-copy changes and the consent proof on the profile.
 */
#[Group('beantragt_applicant')]
final class ApplicantConsentProofAndLockTest extends GroupKernelTestBase {

  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';
  private const EMAIL = 'race@example.test';
  private const IBAN = 'DE89370400440532013000';

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
    putenv(self::KEY_ENV . '=US-SMOKE-applicant-consent-lock-key-material');

    parent::setUp();

    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installEntitySchema('applicant');
    $this->installConfig([
      'user',
      'beantragt_group',
      'beantragt_applicant',
      'beantragt_application',
    ]);
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
   * A withdrawal on an older copy never restores what another one cleared.
   *
   * Two requests load the same profile. The first withdraws the bank
   * consent; the second, still holding its earlier copy, withdraws the
   * contact consent. Mutation probe: saving the passed copy instead of one
   * loaded inside the lock writes the IBAN and the bank consent back.
   */
  public function testWithdrawalOnStaleCopyKeepsConcurrentWithdrawal(): void {
    $profile_id = $this->createConsentedProfile();
    $first = $this->freshCopy($profile_id);
    $second = $this->freshCopy($profile_id);
    self::assertNotSame($first, $second);

    $this->manager()->withdrawConsent($first, 'bank', 1781431300);
    $this->manager()->withdrawConsent($second, 'contact', 1781431400);

    $stored = $this->freshCopy($profile_id);
    self::assertTrue($stored->get('field_iban')->isEmpty(), 'The IBAN came back from the stale copy.');
    self::assertTrue($stored->get('field_account_holder')->isEmpty());
    self::assertTrue($stored->get('field_bank_consent_time')->isEmpty());
    self::assertSame(1781431300, (int) $stored->get('field_bank_consent_revoked_time')->value);
    self::assertTrue($stored->get('field_phone')->isEmpty());
    self::assertSame(1781431400, (int) $stored->get('field_consent_revoked_time')->value);
    // The stale copy itself is left as the caller loaded it.
    self::assertSame(self::IBAN, $second->get('field_iban')->value);
  }

  /**
   * A profile without an email address takes the same fresh-copy path.
   *
   * Such a profile (uid match on a form without an email field) is locked by
   * its ID instead of the address; the change still lands on a copy loaded
   * inside the lock.
   */
  public function testWithdrawalWithoutEmailOnStaleCopyKeepsConcurrentWithdrawal(): void {
    $profile_id = $this->createConsentedProfile(NULL);
    $first = $this->freshCopy($profile_id);
    $second = $this->freshCopy($profile_id);

    $this->manager()->withdrawConsent($first, 'bank', 1781431300);
    $this->manager()->withdrawConsent($second, 'contact', 1781431400);

    $stored = $this->freshCopy($profile_id);
    self::assertTrue($stored->get('field_email')->isEmpty());
    self::assertTrue($stored->get('field_iban')->isEmpty(), 'The IBAN came back from the stale copy.');
    self::assertSame(1781431300, (int) $stored->get('field_bank_consent_revoked_time')->value);
    self::assertSame(1781431400, (int) $stored->get('field_consent_revoked_time')->value);
  }

  /**
   * A citizen edit on an older copy keeps a concurrent withdrawal.
   *
   * Mutation probe: setting the edit on the passed copy and saving it would
   * restore the address and the contact consent cleared in between.
   */
  public function testProfileEditOnStaleCopyKeepsConcurrentWithdrawal(): void {
    $profile_id = $this->createConsentedProfile();
    $editing = $this->freshCopy($profile_id);
    $withdrawing = $this->freshCopy($profile_id);

    $this->manager()->withdrawConsent($withdrawing, 'contact', 1781431400);
    $saved = $this->manager()->updateOwnProfileFields($editing, ['field_phone' => '030 23125 999']);

    $stored = $this->freshCopy($profile_id);
    self::assertSame('030 23125 999', $stored->get('field_phone')->value);
    self::assertSame('030 23125 999', $saved->get('field_phone')->value);
    self::assertTrue($stored->get('field_street')->isEmpty(), 'The stale copy restored the cleared address.');
    self::assertTrue($stored->get('field_consent_time')->isEmpty());
    self::assertSame(1781431400, (int) $stored->get('field_consent_revoked_time')->value);
  }

  /**
   * The profile keeps hash, locale and Stelle of its consent text alone.
   *
   * The proof is written by the sync from the consent provenance alone, so
   * it does not depend on the source application still existing; the
   * withdrawal clears it with the rest of the provenance.
   */
  public function testConsentProofStaysOnProfileAndGoesWithWithdrawal(): void {
    $jurisdiction = $this->createJurisdiction();
    $contact_hash = hash('sha256', 'contact consent text');
    $bank_hash = hash('sha256', 'bank consent text');
    $profile = $this->manager()->findOrCreate([
      'person.email' => 'proof@example.test',
      'person.full_name' => 'Petra Proof',
      'person.iban' => self::IBAN,
    ], NULL, NULL, FALSE, ['field_jurisdiction' => ['target_id' => (int) $jurisdiction->id()]], [
      'contact' => [
        'time' => 1781431200,
        'textVersion' => '2026-10-20.3',
        'source' => 'deleted-application-uuid',
        'textHash' => $contact_hash,
        'locale' => 'en',
        'scopeLabel' => 'Citizens office Sampletown',
      ],
      'bank' => [
        'time' => 1781431200,
        'textVersion' => '2026-10-20.2',
        'source' => 'deleted-application-uuid',
        'textHash' => $bank_hash,
        'locale' => 'en',
        'scopeLabel' => 'Citizens office Sampletown',
      ],
    ]);

    $stored = $this->freshCopy((int) $profile->id());
    self::assertSame($contact_hash, $stored->get('field_consent_text_hash')->value);
    self::assertSame('en', $stored->get('field_consent_locale')->value);
    self::assertSame($bank_hash, $stored->get('field_bank_consent_text_hash')->value);
    self::assertSame('en', $stored->get('field_bank_consent_locale')->value);
    self::assertSame('Citizens office Sampletown', $stored->get('field_consent_scope_label')->value);
    self::assertSame('Citizens office Sampletown', $stored->get('field_bank_consent_scope_label')->value);
    self::assertSame('2026-10-20.3', $stored->get('field_consent_text_version')->value);

    $this->manager()->withdrawConsent($stored, 'bank', 1781431300);
    $stored = $this->freshCopy((int) $profile->id());
    self::assertTrue($stored->get('field_bank_consent_text_hash')->isEmpty());
    self::assertTrue($stored->get('field_bank_consent_locale')->isEmpty());
    self::assertTrue($stored->get('field_bank_consent_scope_label')->isEmpty());
    self::assertSame('Citizens office Sampletown', $stored->get('field_consent_scope_label')->value);
    self::assertSame($contact_hash, $stored->get('field_consent_text_hash')->value, 'The bank withdrawal keeps the contact proof.');
  }

  /**
   * The update installs the proof fields once.
   */
  public function testUpdate10007InstallsConsentProofFields(): void {
    $updates = \Drupal::entityDefinitionUpdateManager();
    $names = array_keys(Applicant::consentProofBaseFieldDefinitions());
    self::assertSame([
      'field_consent_text_hash',
      'field_consent_locale',
      'field_consent_scope_label',
      'field_bank_consent_text_hash',
      'field_bank_consent_locale',
      'field_bank_consent_scope_label',
    ], $names);
    foreach ($names as $name) {
      $definition = $updates->getFieldStorageDefinition($name, 'applicant');
      self::assertInstanceOf(FieldStorageDefinitionInterface::class, $definition);
      $updates->uninstallFieldStorageDefinition($definition);
      self::assertNull($updates->getFieldStorageDefinition($name, 'applicant'));
    }

    require_once dirname(__DIR__, 3) . '/beantragt_applicant.install';
    $message = beantragt_applicant_update_10007();

    self::assertSame('Applicant consent proof fields installed: ' . implode(', ', $names) . '.', $message);
    foreach ($names as $name) {
      self::assertInstanceOf(FieldStorageDefinitionInterface::class, $updates->getFieldStorageDefinition($name, 'applicant'));
    }
    self::assertSame('Applicant consent proof fields installed: none.', beantragt_applicant_update_10007());
  }

  /**
   * The new proof storage is internal and installing it twice is safe.
   */
  public function testUpdate10008InstallsInternalProofByField(): void {
    $updates = \Drupal::entityDefinitionUpdateManager();
    $name = ApplicantInterface::CONSENT_PROOF_FIELD;
    $definition = $updates->getFieldStorageDefinition($name, 'applicant');
    self::assertInstanceOf(BaseFieldDefinition::class, $definition);
    self::assertTrue($definition->isInternal());
    $updates->uninstallFieldStorageDefinition($definition);
    self::assertNull($updates->getFieldStorageDefinition($name, 'applicant'));

    require_once dirname(__DIR__, 3) . '/beantragt_applicant.install';
    beantragt_applicant_update_10008();
    beantragt_applicant_update_10008();

    $installed = $updates->getFieldStorageDefinition($name, 'applicant');
    self::assertInstanceOf(BaseFieldDefinition::class, $installed);
    self::assertSame('string_long', $installed->getType());
    self::assertTrue($installed->isInternal());
  }

  /**
   * Creates a profile with contact and bank data and both consents.
   */
  private function createConsentedProfile(?string $email = self::EMAIL): int {
    $jurisdiction = $this->createJurisdiction();
    $profile = $this->storage()->create([
      'field_email' => $email,
      'field_full_name' => 'Rita Race',
      'field_phone' => '030 23125 1',
      'field_street' => 'Bauweg 12',
      'field_postal_code' => '12345',
      'field_city' => 'Musterstadt',
      'field_iban' => self::IBAN,
      'field_account_holder' => 'Rita Race',
      'field_consent_time' => 1781431200,
      'field_consent_text_version' => '2026-10-20.3',
      'field_consent_source' => 'application-uuid',
      'field_bank_consent_time' => 1781431200,
      'field_bank_consent_text_version' => '2026-10-20.2',
      'field_bank_consent_source' => 'application-uuid',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'uid' => 0,
      'status' => TRUE,
    ]);
    $profile->save();
    return (int) $profile->id();
  }

  /**
   * Loads an independent copy of a profile, bypassing every cache.
   */
  private function freshCopy(int $id): ApplicantInterface {
    $profile = $this->storage()->loadUnchanged($id);
    assert($profile instanceof ApplicantInterface);
    return $profile;
  }

  /**
   * Creates the jurisdiction required by the applicant scope lock.
   */
  private function createJurisdiction(): GroupInterface {
    static $count = 0;
    $count++;
    return $this->createGroup([
      'type' => 'jur',
      'label' => 'US-SMOKE Consent Lock Jurisdiction ' . $count,
      'field_slug' => 'us-smoke-consent-lock-' . $count,
    ]);
  }

  /**
   * Returns the applicant manager.
   */
  private function manager(): ApplicantManager {
    $manager = $this->container->get('beantragt_applicant.manager');
    assert($manager instanceof ApplicantManager);
    return $manager;
  }

  /**
   * Returns the applicant storage.
   */
  private function storage(): EntityStorageInterface {
    return $this->container->get('entity_type.manager')->getStorage('applicant');
  }

}
