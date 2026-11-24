<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Service;

use Drupal\beantragt_application\ApplicantContactKeys;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;

/**
 * Service for managing applicants and CRM data synchronization.
 */
class ApplicantManager {

  /**
   * Mapping of CRM keys to applicant entity field names.
   *
   * The tax ID is purpose-bound (section 139b AO) and has no mapping, so it is
   * never written to or prefilled from a profile; field_tax_id stays dormant.
   */
  protected const CRM_FIELD_MAP = [
    'person.full_name' => 'field_full_name',
    'person.first_name' => 'field_first_name',
    'person.last_name' => 'field_last_name',
    'person.birthdate' => 'field_birthdate',
    'person.email' => 'field_email',
    'person.phone' => 'field_phone',
    'person.gender' => 'field_gender',
    'person.nationality' => 'field_nationality',
    'person.marital_status' => 'field_marital_status',
    'person.iban' => 'field_iban',
    'person.account_holder' => 'field_account_holder',
    'person.address' => 'field_address',
    'person.street' => 'field_street',
    'person.postal_code' => 'field_postal_code',
    'person.city' => 'field_city',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected LockBackendInterface $lock;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * CRM keys that must not be overwritten through unverified email merges.
   *
   * @var string[]
   */
  protected const PROTECTED_UNVERIFIED_MERGE_KEYS = [
    'person.iban',
    'person.account_holder',
  ];

  /**
   * CRM keys that are written to a profile only as one unit.
   *
   * When incoming data changes any key of a group, every member is replaced
   * from that data and missing members are cleared (or derived, see
   * completeAtomicCrmGroup()), so a profile never mixes a new postal code with
   * an old street or a new first name with an old full name. Data that only
   * repeats stored members leaves the group as stored.
   * person.house_number is not a member: it has no applicant field, and the
   * application sync appends it to person.street before it reaches this
   * service (Application::filterCrmDataForApplicantSync()), so a form that
   * asks for street and number apart confirms a stored "Marktplatz 1".
   * Members must never be listed in PROTECTED_UNVERIFIED_MERGE_KEYS: group
   * writes do not apply that guard.
   *
   * @var string[][]
   */
  protected const ATOMIC_CRM_KEY_GROUPS = ApplicantContactKeys::GROUPS;

  /**
   * Scope fields in lookup precedence; the first one present decides.
   */
  protected const SCOPE_LOOKUP_ORDER = [
    'field_group',
    'field_organisation',
    'field_jurisdiction',
  ];

  /**
   * Applicant provenance field prefixes per consent kind.
   */
  protected const CONSENT_FIELD_PREFIXES = [
    'contact' => 'field_consent',
    'bank' => 'field_bank_consent',
  ];

  /**
   * Suffixes of the consent proof fields cleared by a withdrawal.
   */
  protected const CONSENT_PROOF_SUFFIXES = [
    '_time',
    '_text_version',
    '_source',
    '_text_hash',
    '_locale',
    '_scope_label',
  ];

  /**
   * Identity CRM keys a sign-in (for example BundID) can confirm.
   *
   * These values rest on the authentication, not on a consent: a consent
   * withdrawal keeps them while they still hold the value the login wrote.
   */
  public const LOGIN_IDENTITY_CRM_KEYS = [
    'person.email',
    'person.full_name',
    'person.first_name',
    'person.last_name',
    'person.birthdate',
  ];

  /**
   * Dormant profile fields outside CRM_FIELD_MAP cleared on contact withdrawal.
   *
   * The tax ID never reaches a profile through the sync (section 139b AO);
   * beantragt_applicant_update_10006 cleared older values. Kept as a safety
   * net for databases restored from before that update.
   */
  protected const CONTACT_WITHDRAWAL_EXTRA_FIELDS = [
    'field_tax_id',
  ];

  /**
   * Constructs an ApplicantManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    LockBackendInterface $lock,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('beantragt_applicant');
    $this->lock = $lock;
  }

  /**
   * Finds or creates an applicant from CRM data.
   *
   * Lookup order: email, then uid, then session_token.
   * Creates an applicant if none is found. Updates existing non-empty values.
   *
   * @param array $crm_data
   *   Associative array of CRM key => value pairs.
   * @param int|null $uid
   *   The user ID, or NULL for anonymous.
   * @param string|null $session_token
   *   The session token for anonymous identification.
   * @param bool $email_verified
   *   Whether the CRM email was verified for the provided user ID.
   * @param array $scope
   *   Optional tenant scope copied from the creating application. Supported
   *   keys are field_jurisdiction, field_organisation and field_group.
   * @param array $consent
   *   Optional consent provenance keyed by kind ("contact", "bank"), each with
   *   time, textVersion, source (application UUID), textHash, locale and the
   *   rendered text or the complete field list. Proof is updated only for
   *   CRM values the consent covers and the sync writes or confirms. A consent
   *   given at or before a withdrawal writes nothing for its kind.
   * @param array $login_confirmation
   *   Optional sign-in that confirmed the identity values in $crm_data, with
   *   provider (string) and time (int). Used by SSO logins, which rest on the
   *   authentication instead of a consent.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface
   *   The found or newly created applicant.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function findOrCreate(array $crm_data, ?int $uid = NULL, ?string $session_token = NULL, bool $email_verified = FALSE, array $scope = [], array $consent = [], array $login_confirmation = []): ApplicantInterface {
    $profile = $this->lockedFindOrCreate($crm_data, $uid, $session_token, $email_verified, $scope, $consent, $login_confirmation, TRUE);
    if (!$profile instanceof ApplicantInterface) {
      throw new \UnexpectedValueException('Applicant lookup neither found nor created a profile.');
    }
    return $profile;
  }

  /**
   * Updates the account's existing profile with values a sign-in confirmed.
   *
   * Match-only counterpart of findOrCreate() for SSO logins: the profile is
   * looked up the same way (email, then uid) and receives the confirmed
   * values and the login marker, but no profile is created. A profile needs
   * a jurisdiction scope, which a login does not have, and a login must never
   * fail because of the profile.
   *
   * @param array $crm_data
   *   Login-confirmed CRM values (identity keys only).
   * @param int $uid
   *   The signed-in account.
   * @param bool $email_verified
   *   Whether person.email in $crm_data is a mailbox-verified address.
   * @param array $login_confirmation
   *   Provider (string) and time (int) of the confirming sign-in.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface|null
   *   The updated profile, or NULL when the account has none to match.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function updateExistingFromLogin(array $crm_data, int $uid, bool $email_verified, array $login_confirmation): ?ApplicantInterface {
    if ($uid <= 0) {
      return NULL;
    }
    return $this->lockedFindOrCreate($crm_data, $uid, NULL, $email_verified, [], [], $login_confirmation, FALSE);
  }

  /**
   * Runs the profile lookup under identity locks, optionally creating one.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface|null
   *   The matched or created profile; NULL only when $create is FALSE and no
   *   profile matched.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function lockedFindOrCreate(array $crm_data, ?int $uid, ?string $session_token, bool $email_verified, array $scope, array $consent, array $login_confirmation, bool $create): ?ApplicantInterface {
    $crm_data = static::normalizeCrmDataKeys($crm_data);
    $scope = $this->normalizeScope($scope);
    $storage = $this->entityTypeManager->getStorage('applicant');
    $email = $crm_data['person.email'] ?? NULL;
    // Anonymous applications carry the service account's UID. Lock their
    // submitted address; login updates use the actual signed-in account.
    $account_id = empty($email) || $email_verified || !$create ? $uid : NULL;
    return $this->withProfileMutationLocks(
      $this->profileMutationLockNames((string) $email, $account_id),
      fn (): ?ApplicantInterface => $this->doFindOrCreate($storage, $crm_data, $uid, $session_token, $email, $email_verified, $scope, $consent, $login_confirmation, $create),
    );
  }

  /**
   * Internal find-or-create logic, called within a lock.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The applicant entity storage.
   * @param array $crm_data
   *   Associative array of CRM key => value pairs.
   * @param int|null $uid
   *   The user ID, or NULL for anonymous.
   * @param string|null $session_token
   *   The session token for anonymous identification.
   * @param string|null $email
   *   The extracted email address.
   * @param bool $email_verified
   *   Whether the CRM email was verified for the provided user ID.
   * @param array $scope
   *   Optional tenant scope copied from the creating application.
   * @param array $consent
   *   Optional consent provenance keyed by kind.
   * @param array $login_confirmation
   *   Optional confirming sign-in with provider and time.
   * @param bool $create
   *   Whether to create a profile when none matches.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface|null
   *   The found or newly created applicant; NULL only when $create is FALSE
   *   and nothing matched.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function doFindOrCreate($storage, array $crm_data, ?int $uid, ?string $session_token, ?string $email, bool $email_verified = FALSE, array $scope = [], array $consent = [], array $login_confirmation = [], bool $create = TRUE): ?ApplicantInterface {
    $profile = NULL;
    $matched_by = NULL;
    $email_is_unverified = !empty($email) && !$email_verified;

    // 1. Lookup by email.
    if (!empty($email)) {
      $results = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_email', $email);
      $this->applyScopeToQuery($results, $scope);
      $results = $results->range(0, 1)->execute();

      if (!empty($results)) {
        $storage->resetCache($results);
        $profile = $storage->load(reset($results));
        if (!$profile instanceof ApplicantInterface) {
          throw new \UnexpectedValueException('Applicant lookup returned an invalid entity.');
        }
        $matched_by = 'email';
      }
    }

    // 2. Lookup by uid.
    if ($profile === NULL && !$email_is_unverified && $uid !== NULL && $uid > 0) {
      $results = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid);
      $this->applyScopeToQuery($results, $scope);
      $results = $results->range(0, 1)->execute();

      if (!empty($results)) {
        $storage->resetCache($results);
        $profile = $storage->load(reset($results));
        if (!$profile instanceof ApplicantInterface) {
          throw new \UnexpectedValueException('Applicant lookup returned an invalid entity.');
        }
        $matched_by = 'uid';
      }
    }

    // 3. Lookup by session token (stored as SHA-256 hash).
    if ($profile === NULL && !$email_is_unverified && !empty($session_token)) {
      $token_hash = hash('sha256', $session_token);
      $results = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_session_token', $token_hash);
      $this->applyScopeToQuery($results, $scope);
      $results = $results->range(0, 1)->execute();

      if (!empty($results)) {
        $storage->resetCache($results);
        $profile = $storage->load(reset($results));
        if (!$profile instanceof ApplicantInterface) {
          throw new \UnexpectedValueException('Applicant lookup returned an invalid entity.');
        }
        $matched_by = 'session_token';
      }
    }

    if ($profile === NULL && !$create) {
      return NULL;
    }

    // 4. Create new profile if not found.
    $is_new = $profile === NULL;
    if ($is_new) {
      $profile = $storage->create([
        'status' => TRUE,
      ]);
      if (!$profile instanceof ApplicantInterface) {
        throw new \UnexpectedValueException('Applicant storage created an invalid entity.');
      }

      // The owner field defaults to the current user. Set it explicitly so a
      // profile created during someone else's request (a citizen with an
      // unverified foreign email, the service account) stays unclaimed and
      // can later be taken over through the proven address.
      $profile->setOwnerId($uid !== NULL && $uid > 0 && !$email_is_unverified ? $uid : 0);

      if (!empty($session_token) && !$email_is_unverified) {
        $profile->set('field_session_token', hash('sha256', $session_token));
      }

      // New applicant: apply all CRM data.
      $this->applyScopeToProfile($profile, $scope);
      $written = $this->updateProfileFromCrmData($profile, $crm_data, TRUE, $consent['contact']['fields'] ?? NULL);
      $this->applyConsentProvenance($profile, $consent, $written);
      $this->applyLoginConfirmation($profile, $crm_data, $login_confirmation);
    }
    else {
      // Existing applicant: only update if the applicant is unclaimed (uid=0)
      // or the requester is the applicant owner. This prevents anonymous
      // applications from overwriting data on claimed applicants.
      $profile_owner_id = (int) $profile->getOwnerId();
      $is_unclaimed = ($profile_owner_id === 0);
      $is_owner = ($uid !== NULL && $uid > 0 && $profile_owner_id === $uid);
      $is_unverified_email_match = ($matched_by === 'email' && !$email_verified);

      if (($is_unclaimed || $is_owner) && $consent !== [] && $this->consentWithdrawnAfter($profile, 'contact', $consent['contact'] ?? NULL)) {
        // A consent given at or before the withdrawal stored on this profile
        // must not refill it, for example an anonymous application submitted
        // with consent before the withdrawal and confirmed after it. Bank
        // details need the contact consent as well, so nothing is written.
        $this->logger->notice('Skipped CRM data update for applicant @id: the consent predates its withdrawal.', [
          '@id' => $profile->id(),
        ]);
      }
      elseif ($is_unclaimed || $is_owner) {
        if ($this->consentWithdrawnAfter($profile, 'bank', $consent['bank'] ?? NULL)) {
          $crm_data = array_diff_key($crm_data, array_flip(ApplicationInterface::BANK_CONSENT_CRM_KEYS));
          unset($consent['bank']);
        }
        $allow_protected_overwrite = $is_owner || !$is_unverified_email_match;
        $written = $this->updateProfileFromCrmData($profile, $crm_data, $allow_protected_overwrite, $consent['contact']['fields'] ?? NULL);
        $this->applyConsentProvenance($profile, $consent, $written);
        if ($login_confirmation === []) {
          // A consent sync may replace a login-confirmed value.
          $this->pruneLoginConfirmation($profile, $this->loginConfirmedKeys($profile));
        }
        $this->applyLoginConfirmation($profile, $crm_data, $login_confirmation);
        $this->logger->info('Updated existing applicant @id.', [
          '@id' => $profile->id(),
        ]);
      }
      else {
        $this->logger->notice('Skipped CRM data update for claimed applicant @id (owner uid @owner, requester uid @applicant).', [
          '@id' => $profile->id(),
          '@owner' => $profile_owner_id,
          '@applicant' => $uid ?? 0,
        ]);
        // This request can hold a different account lock. Saving this copy
        // could overwrite the owner's concurrent sync or withdrawal.
        return $profile;
      }
    }

    $this->applyScopeToProfile($profile, $scope);

    // Associate uid if not yet set and provided.
    if ($uid !== NULL && $uid > 0 && (int) $profile->getOwnerId() === 0 && !$email_is_unverified) {
      $profile->setOwnerId($uid);
    }

    // Associate session token (hashed) if not yet set and provided.
    if (!empty($session_token) && empty($profile->getSessionToken()) && !$email_is_unverified) {
      $profile->set('field_session_token', hash('sha256', $session_token));
    }

    $profile->save();
    if ($is_new) {
      $this->logger->info('Created new applicant @id.', ['@id' => $profile->id()]);
    }

    return $profile;
  }

  /**
   * Stores per-field proof and updates the summary stamp for each kind.
   *
   * A kind is stamped only when the update wrote data it covers: contact
   * provenance when contact values were written (any CRM value for a legacy
   * consent), bank provenance when IBAN or account holder was written. A
   * per-field proof always belongs to the kind that covers the value.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant profile.
   * @param array $consent
   *   Consent provenance keyed by kind.
   * @param string[] $written_keys
   *   CRM keys written to the profile by this update.
   */
  protected function applyConsentProvenance(ApplicantInterface $profile, array $consent, array $written_keys): void {
    if ($consent === [] || $written_keys === []) {
      return;
    }

    $keys_by_kind = [
      'contact' => array_diff($written_keys, ApplicationInterface::BANK_CONSENT_CRM_KEYS),
      'bank' => array_intersect($written_keys, ApplicationInterface::BANK_CONSENT_CRM_KEYS),
    ];
    $previous_proofs = $this->fieldConsentProofs($profile);
    $proofs = $previous_proofs;
    foreach (static::CONSENT_FIELD_PREFIXES as $kind => $prefix) {
      $entry = $consent[$kind] ?? NULL;
      if (!is_array($entry)) {
        continue;
      }
      $fields = $entry['fields'] ?? NULL;
      $covered_keys = $fields === NULL ? $keys_by_kind[$kind] : array_intersect($keys_by_kind[$kind], $fields);
      if ($covered_keys === [] && ($kind !== 'contact' || $fields !== NULL)) {
        continue;
      }
      $time = $entry['time'] ?? NULL;
      $version = $entry['textVersion'] ?? NULL;
      $source = $entry['source'] ?? NULL;
      if (!is_int($time) || $time <= 0 || !is_string($version) || $version === '' || !is_string($source) || $source === '') {
        continue;
      }
      // Hash and locale belong to this consent: a stamp without them clears
      // the ones of an older consent instead of mixing two proofs.
      $hash = $entry['textHash'] ?? NULL;
      $hash = is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : NULL;
      $locale = $entry['locale'] ?? NULL;
      $scope_label = $entry['scopeLabel'] ?? NULL;
      foreach ([
        '_time' => $time,
        '_text_version' => mb_substr($version, 0, 32),
        '_source' => mb_substr($source, 0, 64),
        '_text_hash' => $hash,
        '_locale' => is_string($locale) && $locale !== '' ? mb_substr($locale, 0, 12) : NULL,
        '_scope_label' => is_string($scope_label) && trim($scope_label) !== '' ? mb_substr($scope_label, 0, 255) : NULL,
      ] as $suffix => $value) {
        if ($profile->hasField($prefix . $suffix)) {
          $profile->set($prefix . $suffix, $value);
        }
      }

      $text = $entry['text'] ?? NULL;
      if ($hash === NULL || !is_string($locale) || $locale === ''
        || ((!is_string($text) || $text === '') && $fields === NULL)) {
        // Without a complete proof, an older proof must not stay attached to
        // the newly written values of the fields this consent covered.
        foreach ($covered_keys as $crm_key) {
          unset($proofs[static::CRM_FIELD_MAP[$crm_key]]);
        }
        continue;
      }
      $proof = [
        'kind' => $kind,
        'time' => $time,
        'textVersion' => $version,
        'source' => $source,
        'textHash' => $hash,
        'locale' => $locale,
        'scopeLabel' => $scope_label,
      ];
      if ($fields !== NULL) {
        $proof['fields'] = $fields;
      }
      if (is_string($text) && $text !== '') {
        $proof['text'] = $text;
      }
      foreach ($covered_keys as $crm_key) {
        $field_name = static::CRM_FIELD_MAP[$crm_key];
        if ($this->fieldHasValue($profile, $field_name)) {
          $proofs[$field_name] = $proof;
        }
        else {
          // Legacy atomic writes can clear a field along with an update.
          unset($proofs[$field_name]);
        }
      }
    }
    if ($proofs !== $previous_proofs) {
      $this->storeFieldConsentProofs($profile, $proofs);
    }
  }

  /**
   * Reads the profile's saved proofs, keyed by field name.
   */
  protected function fieldConsentProofs(ApplicantInterface $profile): array {
    if (!$profile->hasField(ApplicantInterface::CONSENT_PROOF_FIELD)) {
      return [];
    }
    $proofs = json_decode((string) $profile->get(ApplicantInterface::CONSENT_PROOF_FIELD)->value, TRUE);
    return is_array($proofs) ? $proofs : [];
  }

  /**
   * Writes the proof map, clearing the field when the last proof is removed.
   */
  protected function storeFieldConsentProofs(ApplicantInterface $profile, array $proofs): void {
    if ($profile->hasField(ApplicantInterface::CONSENT_PROOF_FIELD)) {
      $profile->set(
        ApplicantInterface::CONSENT_PROOF_FIELD,
        $proofs === [] ? NULL : json_encode($proofs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
      );
    }
  }

  /**
   * Returns TRUE when the profile withdrew a consent kind at or after a time.
   *
   * The withdrawal time is kept after a later consent, so an older consent
   * confirmed late (anonymous double opt-in) stays blocked. Only a consent
   * given strictly after the withdrawal counts again.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The matched applicant profile.
   * @param string $kind
   *   Consent kind: contact or bank.
   * @param mixed $entry
   *   Consent provenance of the incoming application for this kind.
   */
  protected function consentWithdrawnAfter(ApplicantInterface $profile, string $kind, mixed $entry): bool {
    $field_name = (static::CONSENT_FIELD_PREFIXES[$kind] ?? '') . '_revoked_time';
    if (!is_array($entry) || !$profile->hasField($field_name) || $profile->get($field_name)->isEmpty()) {
      return FALSE;
    }
    $revoked = (int) $profile->get($field_name)->value;
    $time = $entry['time'] ?? NULL;
    return $revoked > 0 && (!is_int($time) || $time <= $revoked);
  }

  /**
   * Records which identity values a sign-in confirmed on the profile.
   *
   * Only keys whose stored value equals the value the login delivered are
   * recorded, each with the SHA-256 hash of that value. A value replaced later
   * (for example from a consented application) no longer matches its hash and
   * so no longer counts as confirmed by the login.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant profile after the update.
   * @param array $crm_data
   *   CRM data delivered by the login.
   * @param array $login_confirmation
   *   Confirming sign-in with provider and time; empty for consent syncs.
   */
  protected function applyLoginConfirmation(ApplicantInterface $profile, array $crm_data, array $login_confirmation): void {
    $provider = $login_confirmation['provider'] ?? NULL;
    $time = $login_confirmation['time'] ?? NULL;
    if (!is_string($provider) || $provider === '' || !is_int($time) || $time <= 0
      || !$profile->hasField('field_login_confirmed_keys')) {
      return;
    }
    $confirmed = [];
    foreach (static::LOGIN_IDENTITY_CRM_KEYS as $crm_key) {
      $value = $crm_data[$crm_key] ?? NULL;
      if (!is_string($value) || trim($value) === '') {
        continue;
      }
      $stored = $this->storedCrmValue($profile, $crm_key);
      if ($stored !== '' && $stored === trim($value)) {
        $confirmed[$crm_key] = static::loginValueHash($stored);
      }
    }
    if ($confirmed === []) {
      return;
    }
    $profile->set('field_login_confirmed_provider', mb_substr($provider, 0, 64));
    $profile->set('field_login_confirmed_time', $time);
    $profile->set('field_login_confirmed_keys', json_encode($confirmed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * Returns the identity CRM keys a login confirmed with their current value.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant profile.
   *
   * @return string[]
   *   CRM keys from LOGIN_IDENTITY_CRM_KEYS whose stored value still equals
   *   the value the login confirmed.
   */
  public function loginConfirmedKeys(ApplicantInterface $profile): array {
    if (!$profile->hasField('field_login_confirmed_keys') || $profile->get('field_login_confirmed_keys')->isEmpty()) {
      return [];
    }
    $map = json_decode((string) $profile->get('field_login_confirmed_keys')->value, TRUE);
    if (!is_array($map)) {
      return [];
    }
    $keys = [];
    foreach (static::LOGIN_IDENTITY_CRM_KEYS as $crm_key) {
      $hash = $map[$crm_key] ?? NULL;
      $stored = $this->storedCrmValue($profile, $crm_key);
      if (is_string($hash) && $stored !== '' && hash_equals($hash, static::loginValueHash($stored))) {
        $keys[] = $crm_key;
      }
    }
    return $keys;
  }

  /**
   * Hashes a login-confirmed identity value.
   *
   * Keyed with the site hash salt (settings.php, not the database): a plain
   * SHA-256 of a birth date or a name is reversible by trying candidates, so
   * a database copy alone must not reveal the confirmed values.
   */
  protected static function loginValueHash(string $value): string {
    return hash_hmac('sha256', trim($value), 'beantragt_applicant.login_confirmation:' . Settings::getHashSalt());
  }

  /**
   * Keeps only the login hashes of values that are still confirmed.
   *
   * A hash whose value was replaced or cleared is a reversible trace of data
   * the profile no longer holds, so it is removed; without any confirmed
   * value the provider and login time go as well.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant profile.
   * @param string[] $confirmed_keys
   *   CRM keys that are still confirmed by the login.
   */
  protected function pruneLoginConfirmation(ApplicantInterface $profile, array $confirmed_keys): void {
    if (!$profile->hasField('field_login_confirmed_keys') || $profile->get('field_login_confirmed_keys')->isEmpty()) {
      return;
    }
    $map = json_decode((string) $profile->get('field_login_confirmed_keys')->value, TRUE);
    $map = is_array($map) ? array_intersect_key($map, array_flip($confirmed_keys)) : [];
    if ($map === []) {
      foreach (['field_login_confirmed_keys', 'field_login_confirmed_provider', 'field_login_confirmed_time'] as $field_name) {
        $profile->set($field_name, NULL);
      }
      return;
    }
    $profile->set('field_login_confirmed_keys', json_encode($map, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  }

  /**
   * Withdraws one consent kind on a citizen's own profile.
   *
   * Contact: clears every contact and identity value that the consent
   * covered, except the email address (the address of the account, needed to
   * keep the withdrawal attached to the person) and identity values a login
   * confirmed. Bank: clears IBAN and account holder. Both clear the consent
   * summary stamp and every field proof of their kind, and store the
   * withdrawal time, which blocks every consent given at or before it.
   * Applications and their consent records are not touched.
   * The change is made on a copy loaded inside the
   * profile lock (updateLockedProfile()); the passed instance stays as it
   * was, so callers reload the profile to show the result.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The citizen's own applicant profile.
   * @param string $kind
   *   Consent kind: contact or bank.
   * @param int $time
   *   Withdrawal time.
   *
   * @return string[]
   *   Names of the fields that held a value and were cleared.
   *
   * @throws \InvalidArgumentException
   *   When the kind is unknown.
   * @throws \RuntimeException
   *   When the lock is busy or the profile no longer exists.
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function withdrawConsent(ApplicantInterface $profile, string $kind, int $time): array {
    if (!isset(static::CONSENT_FIELD_PREFIXES[$kind])) {
      throw new \InvalidArgumentException('Unknown consent kind: ' . $kind . '.');
    }

    $cleared = [];
    $this->updateLockedProfile($profile, function (ApplicantInterface $fresh) use ($kind, $time, &$cleared): void {
      $login_confirmed_keys = $this->loginConfirmedKeys($fresh);
      if ($kind === 'bank') {
        $fields = array_values(array_intersect_key(static::CRM_FIELD_MAP, array_flip(ApplicationInterface::BANK_CONSENT_CRM_KEYS)));
      }
      else {
        $kept = array_merge(['person.email'], ApplicationInterface::BANK_CONSENT_CRM_KEYS, $login_confirmed_keys);
        $fields = array_merge(
          array_values(array_diff_key(static::CRM_FIELD_MAP, array_flip($kept))),
          static::CONTACT_WITHDRAWAL_EXTRA_FIELDS,
        );
      }

      foreach ($fields as $field_name) {
        if ($fresh->hasField($field_name) && $this->fieldHasValue($fresh, $field_name)) {
          $fresh->set($field_name, NULL);
          $cleared[] = $field_name;
        }
      }
      $prefix = static::CONSENT_FIELD_PREFIXES[$kind];
      foreach (static::CONSENT_PROOF_SUFFIXES as $suffix) {
        if ($fresh->hasField($prefix . $suffix)) {
          $fresh->set($prefix . $suffix, NULL);
        }
      }
      if ($fresh->hasField($prefix . '_revoked_time')) {
        $fresh->set($prefix . '_revoked_time', $time);
      }
      $proofs = $this->fieldConsentProofs($fresh);
      $remaining = array_filter($proofs, static fn (array $proof): bool => $proof['kind'] !== $kind);
      if ($remaining !== $proofs) {
        $this->storeFieldConsentProofs($fresh, $remaining);
      }
      $this->pruneLoginConfirmation($fresh, $login_confirmed_keys);
    });

    $this->logger->notice('Applicant @id withdrew the @kind consent; @count fields cleared.', [
      '@id' => $profile->id(),
      '@kind' => $kind,
      '@count' => count($cleared),
    ]);
    return $cleared;
  }

  /**
   * Sets basic fields on a citizen's own profile under the profile lock.
   *
   * The values are applied to a copy loaded inside the lock, like a
   * withdrawal, so an edit never writes back values that a concurrent
   * withdrawal or sync changed after the caller loaded its copy.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The citizen's own applicant profile as loaded by the caller.
   * @param array<string, mixed> $values
   *   Field values keyed by applicant field name.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface
   *   The saved profile as stored.
   *
   * @throws \RuntimeException
   *   When the lock is busy or the profile no longer exists.
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function updateOwnProfileFields(ApplicantInterface $profile, array $values): ApplicantInterface {
    return $this->updateLockedProfile($profile, static function (ApplicantInterface $fresh) use ($values): void {
      foreach ($values as $field_name => $value) {
        $fresh->set($field_name, $value);
      }
    });
  }

  /**
   * Changes a profile on a fresh copy loaded under its mutation locks.
   *
   * Email and account locks are shared with profile sync. Loading after
   * acquiring the locks lets each mutation build on the last saved state.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The profile as loaded by the caller.
   * @param callable(\Drupal\beantragt_applicant\Entity\ApplicantInterface): void $change
   *   Applies the change to the fresh copy.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface
   *   The saved fresh copy.
   *
   * @throws \RuntimeException
   *   When the lock is busy or the profile no longer exists.
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function updateLockedProfile(ApplicantInterface $profile, callable $change): ApplicantInterface {
    $email = (string) $profile->get('field_email')->value;
    $lock_names = $this->profileMutationLockNames($email, (int) $profile->getOwnerId());
    return $this->withProfileMutationLocks($lock_names ?: ['applicant_id_' . $profile->id()], function () use ($profile, $change): ApplicantInterface {
      $fresh = $this->entityTypeManager->getStorage('applicant')->loadUnchanged($profile->id());
      if (!$fresh instanceof ApplicantInterface || (int) $fresh->getOwnerId() !== (int) $profile->getOwnerId()) {
        throw new \RuntimeException('The applicant profile changed owner or no longer exists.');
      }
      $change($fresh);
      $fresh->save();
      return $fresh;
    });
  }

  /**
   * Returns shared lock names in acquisition order.
   */
  protected function profileMutationLockNames(string $email, ?int $uid): array {
    $email = mb_strtolower(trim($email));
    $names = $email === '' ? [] : ['applicant_' . hash('sha256', $email)];
    if ($uid !== NULL && $uid > 0) {
      $names[] = 'applicant_uid_' . $uid;
    }
    return $names;
  }

  /**
   * Runs a profile mutation only after acquiring all its identity locks.
   *
   * @param string[] $lock_names
   *   Lock names in acquisition order.
   * @param callable(): T $mutation
   *   Mutation including the profile read and save.
   *
   * @return T
   *   The result of the mutation.
   *
   * @template T
   */
  protected function withProfileMutationLocks(array $lock_names, callable $mutation): mixed {
    $acquired = [];
    try {
      foreach ($lock_names as $lock_name) {
        if (!$this->lock->acquire($lock_name, 30.0)) {
          $this->lock->wait($lock_name, 1);
          if (!$this->lock->acquire($lock_name, 30.0)) {
            throw new \RuntimeException('The applicant profile is being updated; try again.');
          }
        }
        $acquired[] = $lock_name;
      }
      return $mutation();
    }
    finally {
      foreach (array_reverse($acquired) as $lock_name) {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Lets a verified email take over unclaimed profiles with that address.
   *
   * A profile created from an application without sign-in has no owner. When
   * the citizen later proves control of the same mailbox (passwordless
   * login), those profiles become theirs so "Meine Daten" can show and
   * withdraw the consent behind them. Only profiles without owner and with
   * exactly this address move; a profile of another account never does.
   *
   * @param int $uid
   *   The account that proved the email.
   * @param string $verified_email
   *   The mailbox-proven email address.
   *
   * @return int
   *   Number of profiles taken over.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function claimUnclaimedByVerifiedEmail(int $uid, string $verified_email): int {
    $email = mb_strtolower(trim($verified_email));
    if ($uid <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return 0;
    }
    $lock_name = 'applicant_' . hash('sha256', $email);
    if (!$this->lock->acquire($lock_name, 5.0)) {
      $this->logger->warning('Skipped applicant takeover for uid @uid: the profile lock is busy.', ['@uid' => $uid]);
      return 0;
    }

    $claimed = 0;
    try {
      $storage = $this->entityTypeManager->getStorage('applicant');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', 0)
        ->condition('field_email', $email)
        ->execute();
      foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $profile) {
        if (!$profile instanceof ApplicantInterface || (int) $profile->getOwnerId() !== 0) {
          continue;
        }
        $stored = mb_strtolower(trim((string) $profile->get('field_email')->value));
        if ($stored === '' || !hash_equals($stored, $email)) {
          continue;
        }
        $profile->setOwnerId($uid);
        $profile->save();
        $claimed++;
      }
    }
    finally {
      $this->lock->release($lock_name);
    }

    if ($claimed > 0) {
      $this->logger->notice('Account @uid took over @count unclaimed applicant profiles after verifying its email.', [
        '@uid' => $uid,
        '@count' => $claimed,
      ]);
    }
    return $claimed;
  }

  /**
   * Normalizes caller-provided tenant scope values.
   *
   * @param array $scope
   *   Raw scope values.
   *
   * @return array
   *   Normalized non-empty entity reference values.
   */
  protected function normalizeScope(array $scope): array {
    $normalized = [];
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      $value = $scope[$field_name] ?? NULL;
      if (is_array($value)) {
        $value = $value['target_id'] ?? NULL;
      }
      $target_id = is_numeric($value) ? (int) $value : 0;
      if ($target_id > 0) {
        $normalized[$field_name] = ['target_id' => $target_id];
      }
    }
    return $normalized;
  }

  /**
   * Applies tenant scope constraints to an applicant lookup query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query.
   * @param array $scope
   *   Normalized tenant scope.
   */
  protected function applyScopeToQuery(QueryInterface $query, array $scope): void {
    foreach (static::SCOPE_LOOKUP_ORDER as $field_name) {
      if (isset($scope[$field_name]['target_id'])) {
        $query->condition($field_name, (int) $scope[$field_name]['target_id']);
        return;
      }
    }
  }

  /**
   * Picks the profile the sync would match for a tenant scope.
   *
   * Same rule as applyScopeToQuery(): the most specific scope field present
   * decides (legacy group, then organisation, then jurisdiction). The form
   * draws prefill and consent from the profile in that scope.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface[] $profiles
   *   Candidate profiles in preference order.
   * @param array $scope
   *   Tenant scope with field_group, field_organisation, field_jurisdiction.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface|null
   *   The first matching profile, NULL when none matches or the scope is
   *   empty.
   */
  public function selectProfileForScope(array $profiles, array $scope): ?ApplicantInterface {
    $scope = $this->normalizeScope($scope);
    foreach (static::SCOPE_LOOKUP_ORDER as $field_name) {
      if (!isset($scope[$field_name]['target_id'])) {
        continue;
      }
      $target_id = (int) $scope[$field_name]['target_id'];
      foreach ($profiles as $profile) {
        if ($profile->hasField($field_name)
          && !$profile->get($field_name)->isEmpty()
          && (int) $profile->get($field_name)->target_id === $target_id) {
          return $profile;
        }
      }
      return NULL;
    }
    return NULL;
  }

  /**
   * Copies tenant scope to the applicant profile when scope is available.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant profile.
   * @param array $scope
   *   Normalized tenant scope.
   */
  protected function applyScopeToProfile(ApplicantInterface $profile, array $scope): void {
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      if (!isset($scope[$field_name]) || !$profile->hasField($field_name)) {
        continue;
      }
      if (!$profile->get($field_name)->isEmpty()) {
        continue;
      }
      $profile->set($field_name, $scope[$field_name]);
    }
  }

  /**
   * Returns CRM data from an applicant for pre-filling forms.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant entity.
   *
   * @return array
   *   Associative array of CRM key => value pairs.
   */
  public function getCrmData(ApplicantInterface $profile): array {
    $crm_data = [];

    foreach (static::CRM_FIELD_MAP as $crm_key => $field_name) {
      $value = $profile->get($field_name)->value;
      if ($value !== NULL && $value !== '') {
        $crm_data[$crm_key] = $value;
      }
    }

    return $crm_data;
  }

  /**
   * Returns the CRM-to-field mapping.
   *
   * @return array
   *   The mapping array.
   */
  public function getCrmFieldMap(): array {
    return static::CRM_FIELD_MAP;
  }

  /**
   * Normalizes legacy CRM aliases to canonical frontend contract keys.
   *
   * @param array $crm_data
   *   Associative array of CRM key => value pairs.
   *
   * @return array
   *   CRM data with canonical keys filled from legacy aliases when needed.
   */
  protected static function normalizeCrmDataKeys(array $crm_data): array {
    foreach (ApplicantContactKeys::ALIASES as $legacy_key => $canonical_key) {
      if (!array_key_exists($canonical_key, $crm_data) && array_key_exists($legacy_key, $crm_data)) {
        $crm_data[$canonical_key] = $crm_data[$legacy_key];
      }
    }

    return $crm_data;
  }

  /**
   * Updates an applicant entity from CRM data.
   *
   * Keys outside ATOMIC_CRM_KEY_GROUPS are set only when they have non-empty
   * values in the CRM data. Grouped keys are written as a unit: see
   * writeAtomicCrmGroups(). Validates IBAN and email format before setting
   * values.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant entity to update.
   * @param array $crm_data
   *   Associative array of CRM key => value pairs.
   * @param bool $allowProtectedOverwrite
   *   Whether protected CRM fields may overwrite existing values.
   * @param list<string>|null $consented_fields
   *   Contact fields this consent may write, NULL for legacy writes.
   *
   * @return string[]
   *   CRM keys whose values were written to or cleared on the profile, plus
   *   grouped keys the incoming data confirmed unchanged.
   */
  protected function updateProfileFromCrmData(ApplicantInterface $profile, array $crm_data, bool $allowProtectedOverwrite = TRUE, ?array $consented_fields = NULL): array {
    $written = $this->writeAtomicCrmGroups($profile, $crm_data, $consented_fields);
    $grouped_keys = array_merge(...array_values(static::ATOMIC_CRM_KEY_GROUPS));
    foreach (static::CRM_FIELD_MAP as $crm_key => $field_name) {
      if (in_array($crm_key, $grouped_keys, TRUE)) {
        continue;
      }
      if (isset($crm_data[$crm_key]) && $crm_data[$crm_key] !== '') {
        $value = $crm_data[$crm_key];

        if (!$this->validateCrmValue($crm_key, $value)) {
          $this->logger->warning('Invalid CRM value for key @key, skipping.', [
            '@key' => $crm_key,
          ]);
          continue;
        }

        if (!$allowProtectedOverwrite && in_array($crm_key, static::PROTECTED_UNVERIFIED_MERGE_KEYS, TRUE) && $this->fieldHasValue($profile, $field_name)) {
          $this->logger->notice('Skipped protected CRM value overwrite for unverified applicant merge key @key.', [
            '@key' => $crm_key,
          ]);
          continue;
        }

        $profile->set($field_name, $value);
        $written[] = $crm_key;
      }
    }
    return $written;
  }

  /**
   * Writes each CRM key group that the incoming data changes as one unit.
   *
   * A group is touched when at least one member has a non-empty value. When
   * every given member equals the stored value, the group is left as stored:
   * the data confirms the profile and gives no reason to clear anything. When
   * at least one member differs, every member is set from the incoming data,
   * members without a value are derived where possible (see
   * completeAtomicCrmGroup()) and cleared otherwise. If any member value is
   * invalid or not a string, the whole group is skipped and the profile keeps
   * its previous unit.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant entity to update.
   * @param array $crm_data
   *   Associative array of CRM key => value pairs.
   * @param list<string>|null $consented_fields
   *   Contact fields this consent may write, NULL for legacy writes.
   *
   * @return string[]
   *   CRM keys set to a new value, plus keys cleared from a previous value,
   *   plus given keys of a group that the incoming data confirmed unchanged.
   */
  protected function writeAtomicCrmGroups(ApplicantInterface $profile, array $crm_data, ?array $consented_fields = NULL): array {
    $written = [];
    foreach (static::ATOMIC_CRM_KEY_GROUPS as $group => $crm_keys) {
      $values = [];
      foreach ($crm_keys as $crm_key) {
        $value = $crm_data[$crm_key] ?? NULL;
        if (is_int($value) || is_float($value)) {
          $value = (string) $value;
        }
        if ($value !== NULL && !is_string($value)) {
          $this->logger->warning('Non-string CRM value for key @key, skipping the @group group.', [
            '@key' => $crm_key,
            '@group' => $group,
          ]);
          continue 2;
        }
        $values[$crm_key] = $value !== NULL && trim($value) !== '' ? $value : NULL;
      }
      $given = array_filter($values, static fn(?string $value): bool => $value !== NULL);
      if ($given === []) {
        continue;
      }

      $unchanged = TRUE;
      foreach ($given as $crm_key => $value) {
        if (trim($value) !== $this->storedCrmValue($profile, $crm_key)) {
          $unchanged = FALSE;
          break;
        }
      }
      if ($unchanged) {
        array_push($written, ...array_keys($given));
        continue;
      }

      // A form-specific consent cannot clear or derive another group member.
      $values = $consented_fields === NULL
        ? $this->completeAtomicCrmGroup($profile, $group, $values)
        : array_intersect_key($given, array_flip($consented_fields));

      foreach ($values as $crm_key => $value) {
        if ($value !== NULL && !$this->validateCrmValue($crm_key, $value)) {
          $this->logger->warning('Invalid CRM value for key @key, skipping the @group group.', [
            '@key' => $crm_key,
            '@group' => $group,
          ]);
          continue 2;
        }
      }

      foreach ($values as $crm_key => $value) {
        $field_name = static::CRM_FIELD_MAP[$crm_key];
        if ($value !== NULL) {
          $profile->set($field_name, $value);
          $written[] = $crm_key;
        }
        elseif ($this->fieldHasValue($profile, $field_name)) {
          $profile->set($field_name, NULL);
          $written[] = $crm_key;
        }
      }
    }
    return $written;
  }

  /**
   * Fills group members that can be derived for a changed incoming unit.
   *
   * Name: first and last name together yield the full name, which the
   * profile label, "Meine Daten" and the staff view display. A single name
   * part (for example a new last name after marriage) does not refute the
   * stored other part: it is kept and the full name is rebuilt from both. A
   * single part without a stored counterpart never becomes the full name, the
   * full name is then cleared. A full name is never split into parts.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant entity with the stored values.
   * @param string $group
   *   The group name from ATOMIC_CRM_KEY_GROUPS.
   * @param array<string, string|null> $values
   *   Incoming group values keyed by CRM key, NULL when missing.
   *
   * @return array<string, string|null>
   *   The group values with derived members filled.
   */
  protected function completeAtomicCrmGroup(ApplicantInterface $profile, string $group, array $values): array {
    if ($group !== 'name' || $values['person.full_name'] !== NULL) {
      return $values;
    }
    foreach ([
      'person.first_name' => 'person.last_name',
      'person.last_name' => 'person.first_name',
    ] as $part => $counterpart) {
      if ($values[$part] !== NULL && $values[$counterpart] === NULL) {
        $stored = $this->storedCrmValue($profile, $counterpart);
        $values[$counterpart] = $stored !== '' ? $stored : NULL;
      }
    }
    if ($values['person.first_name'] !== NULL && $values['person.last_name'] !== NULL) {
      $values['person.full_name'] = trim($values['person.first_name']) . ' ' . trim($values['person.last_name']);
    }
    return $values;
  }

  /**
   * Returns the trimmed stored profile value of a CRM key.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant entity.
   * @param string $crm_key
   *   A CRM key from CRM_FIELD_MAP.
   *
   * @return string
   *   The stored value, or an empty string when the field is empty.
   */
  protected function storedCrmValue(ApplicantInterface $profile, string $crm_key): string {
    $field_name = static::CRM_FIELD_MAP[$crm_key];
    if (!$this->fieldHasValue($profile, $field_name)) {
      return '';
    }
    $value = $profile->get($field_name)->value;
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Validates a CRM value based on its key.
   *
   * @param string $crm_key
   *   The CRM key (e.g., 'person.iban', 'person.email').
   * @param string $value
   *   The value to validate.
   *
   * @return bool
   *   TRUE if the value is valid for the given key.
   */
  protected function validateCrmValue(string $crm_key, string $value): bool {
    switch ($crm_key) {
      case 'person.email':
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);

      case 'person.iban':
        // IBAN: 2 uppercase letters + 2 digits + up to 30 alphanumeric chars.
        // Strip spaces for validation.
        $clean = str_replace(' ', '', $value);
        return (bool) preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', strtoupper($clean));

      case 'person.tax_id':
        // German tax ID: 11 digits.
        $clean = str_replace([' ', '-', '/'], '', $value);
        return (bool) preg_match('/^\d{10,13}$/', $clean);

      case 'person.account_holder':
        $clean = trim($value);
        return $clean !== '' && mb_strlen($clean) <= 255;

      default:
        return TRUE;
    }
  }

  /**
   * Returns TRUE when the applicant field already has a non-empty value.
   *
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface $profile
   *   The applicant entity.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE when the field contains a non-empty value.
   */
  protected function fieldHasValue(ApplicantInterface $profile, string $field_name): bool {
    return !$profile->get($field_name)->isEmpty();
  }

}
