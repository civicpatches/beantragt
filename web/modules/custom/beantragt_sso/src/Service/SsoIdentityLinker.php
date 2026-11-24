<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Links validated SSO identities to Drupal users and applicants.
 */
final class SsoIdentityLinker {
  private const ASSURANCE_RANKS = [
    'low' => 1,
    'normal' => 1,
    'substantial' => 2,
    'hoch' => 3,
    'high' => 3,
  ];

  /**
   * Constructs the SSO authenticator.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApplicantManager $applicantManager,
    private readonly LoggerInterface $logger,
    private readonly ?TimeInterface $time,
    private readonly VerifiedAccountMail $verifiedMail,
    private readonly LockBackendInterface $lock,
  ) {
  }

  /**
   * Authenticates a validated SSO subject.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $name_id
   *   SSO NameID.
   * @param array<string, array<int, mixed>> $attributes
   *   SSO attributes keyed by name.
   * @param array<string, array<int, mixed>> $friendly_attributes
   *   SSO attributes keyed by FriendlyName.
   *
   * @return array<string, mixed>
   *   Authenticated user response.
   */
  public function authenticate(
    string $provider_id,
    array $provider,
    string $name_id,
    array $attributes,
    array $friendly_attributes,
  ): array {
    $subject_hash = $this->subjectHash($provider_id, $name_id);
    $mapped = $this->mapAttributes($provider, $attributes, $friendly_attributes);
    $assurance_level = $mapped['assurance_level'] ?? NULL;
    $this->assertAssuranceLevel($provider, $assurance_level);

    if (!empty($provider['link_by_email']) && !$this->providerVerifiesEmail($provider)) {
      SsoFailureLog::log($this->logger, 'warning', 'unsafe_email_link_config', $provider_id);
      $provider['link_by_email'] = FALSE;
    }

    // The database lock is acquired before opening the transaction:
    // a waiting first login must read the winner's committed binding.
    $lock = $this->lock;
    $lock_name = 'beantragt_sso.identity.' . $subject_hash;
    if (!$lock->acquire($lock_name, 60.0)) {
      $lock->wait($lock_name, 10);
      if (!$lock->acquire($lock_name, 60.0)) {
        throw new SsoAuthenticationException('identity_busy', 'SSO identity resolution is busy.');
      }
    }
    try {
      $transaction = NULL;
      try {
        $transaction = $this->database->startTransaction();
        $user = $this->loadUserForSubject($provider_id, $subject_hash);
        $existing_identity = $user instanceof UserInterface;
        if (!$existing_identity) {
          $user = $this->resolveOrCreateUser($provider_id, $subject_hash, $provider, $mapped);
        }
        (new SsoAccountPolicy($this->entityTypeManager))->assertInteractiveLoginAllowed($user);
        if ($existing_identity) {
          $this->updateIdentity($provider_id, $subject_hash, $assurance_level);
        }
        else {
          $this->storeIdentity($provider_id, $subject_hash, (int) $user->id(), $assurance_level, $mapped['email'] ?? NULL);
        }
        // Commit the immutable identity before profile or session side effects.
        $pending_commit = $transaction;
        $transaction = NULL;
        unset($pending_commit);
      }
      catch (\Throwable $exception) {
        if ($transaction !== NULL) {
          $transaction->rollBack();
        }
        $this->entityTypeManager->getStorage('user')->resetCache();
        throw $exception;
      }
      try {
        $verified_mail = $this->verifiedMail;
        if ($this->providerVerifiesEmail($provider) && isset($mapped['email'])
          && mb_strtolower(trim((string) $user->getEmail())) === mb_strtolower(trim($mapped['email']))) {
          $verified_mail->verify($user, $mapped['email'], 'sso:' . $provider_id);
        }
        $applicant = $this->ensureApplicant($user, $mapped, $provider_id, $provider);
        user_login_finalize($user);
      }
      catch (\Throwable $exception) {
        user_logout();
        throw $exception;
      }
    }
    finally {
      $lock->release($lock_name);
    }
    $this->logger->info('SSO login for provider @provider linked to uid @uid.', [
      '@provider' => $provider_id,
      '@uid' => $user->id(),
    ]);

    return [
      'uid' => (int) $user->id(),
      'name' => $user->getAccountName(),
      'email' => $user->getEmail(),
      'roles' => $user->getRoles(),
      // Only a profile this account owns; an email match on a profile of
      // another account is skipped by the manager and must not be named.
      'applicant_id' => $applicant instanceof ApplicantInterface && (int) $applicant->getOwnerId() === (int) $user->id() ? $applicant->id() : NULL,
      'auth_provider' => $provider_id,
      'assurance_level' => $assurance_level,
    ];
  }

  /**
   * Maps SSO attributes to beantragt CRM keys.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param array<string, array<int, mixed>> $attributes
   *   Attributes keyed by SSO attribute name.
   * @param array<string, array<int, mixed>> $friendly_attributes
   *   Attributes keyed by FriendlyName.
   *
   * @return array<string, string>
   *   Canonical mapped values.
   */
  public function mapAttributes(array $provider, array $attributes, array $friendly_attributes = []): array {
    $map = is_array($provider['attribute_map'] ?? NULL) ? $provider['attribute_map'] : [];
    $result = [];
    foreach ($map as $target => $names) {
      if (!is_array($names)) {
        continue;
      }
      $value = $this->firstAttributeValue($names, $attributes, $friendly_attributes);
      if ($value !== NULL && $value !== '') {
        $result[(string) $target] = $value;
      }
    }

    if (empty($result['full_name'])) {
      $full_name = trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
      if ($full_name !== '') {
        $result['full_name'] = $full_name;
      }
    }

    if (!empty($result['email']) && !filter_var($result['email'], FILTER_VALIDATE_EMAIL)) {
      unset($result['email']);
    }

    return $result;
  }

  /**
   * Resolves an existing identity mapping.
   */
  private function loadUserForSubject(string $provider_id, string $subject_hash): ?UserInterface {
    $identity = $this->database->select('beantragt_sso_identities', 'i')
      ->fields('i', ['uid', 'disabled', 'link_method'])
      ->condition('provider', $provider_id)
      ->condition('subject_hash', $subject_hash)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if ($identity === FALSE) {
      return NULL;
    }
    if (!empty($identity['disabled'])) {
      throw new SsoAuthenticationException('identity_disabled', 'SSO identity is disabled.');
    }
    $user = $this->entityTypeManager->getStorage('user')->load((int) $identity['uid']);
    if (!$user instanceof UserInterface) {
      throw new SsoAuthenticationException('account_ineligible', 'SSO identity account no longer exists.');
    }
    $policy = new SsoAccountPolicy($this->entityTypeManager);
    $policy->assertInteractiveLoginAllowed($user);
    if ($identity['link_method'] !== 'manual' && $policy->requiresManualBinding($user)) {
      throw new SsoAuthenticationException('account_privileged', 'Privileged users require an explicit administrative SSO binding.');
    }
    return $user;
  }

  /**
   * Resolves by email when allowed, otherwise creates an identity-scoped user.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param string $subject_hash
   *   Hashed external subject.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param array<string, string> $mapped
   *   Mapped attributes.
   */
  private function resolveOrCreateUser(
    string $provider_id,
    string $subject_hash,
    array $provider,
    array $mapped,
  ): UserInterface {
    $email = $this->providerVerifiesEmail($provider) ? ($mapped['email'] ?? NULL) : NULL;
    $link_by_email = (bool) ($provider['link_by_email'] ?? FALSE);
    if ($link_by_email && $email !== NULL) {
      $existing = $this->loadUserByMail($email);
      if ($existing instanceof UserInterface) {
        $this->assertEmailLinkAllowed($existing);
        return $existing;
      }
    }

    if (array_key_exists('create_users', $provider) && empty($provider['create_users'])) {
      throw new \RuntimeException('SSO auto-registration is disabled for this provider.');
    }

    $username = 'sso.' . $provider_id . '.' . substr($subject_hash, 0, 16);
    $mail = $this->usableMail($provider_id, $subject_hash, $email, $link_by_email);
    $existing = $this->loadUserByName($username);
    if ($existing instanceof UserInterface) {
      // A username alone is not evidence that a privileged account was linked.
      $this->assertEmailLinkAllowed($existing);
      return $existing;
    }

    $user = User::create([
      'name' => $username,
      'mail' => $mail,
      'status' => 1,
      'roles' => ['authenticated'],
    ]);
    $user->save();

    return $user;
  }

  /**
   * Returns a mail address safe for a newly created SSO user.
   */
  private function usableMail(string $provider_id, string $subject_hash, ?string $email, bool $link_by_email): string {
    if ($email !== NULL) {
      $existing = $this->loadUserByMail($email);
      if (!$existing instanceof UserInterface || $link_by_email) {
        return $email;
      }
    }

    return sprintf('%s.%s@sso.local.invalid', $provider_id, substr($subject_hash, 0, 20));
  }

  /**
   * Prevents first-login email linking into privileged accounts.
   */
  private function assertEmailLinkAllowed(UserInterface $user): void {
    (new SsoAccountPolicy($this->entityTypeManager))->assertEmailLinkAllowed($user);
  }

  /**
   * Enforces an optional provider minimum assurance level.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string|null $assurance_level
   *   Mapped assurance level from the SSO assertion.
   */
  private function assertAssuranceLevel(array $provider, ?string $assurance_level): void {
    $minimum = $provider['minimum_assurance_level'] ?? '';
    $minimum = is_scalar($minimum) ? strtolower(trim((string) $minimum)) : '';
    if ($minimum === '') {
      return;
    }

    $actual = $assurance_level !== NULL ? strtolower(trim($assurance_level)) : '';
    if ($actual === '') {
      throw new \RuntimeException('SSO assertion does not include the required assurance level.');
    }

    $minimum_rank = self::ASSURANCE_RANKS[$minimum] ?? NULL;
    $actual_rank = self::ASSURANCE_RANKS[$actual] ?? NULL;
    if ($minimum_rank !== NULL && $actual_rank !== NULL) {
      if ($actual_rank < $minimum_rank) {
        throw new \RuntimeException('SSO assurance level is too low.');
      }
      return;
    }

    if ($actual !== $minimum) {
      throw new \RuntimeException('SSO assurance level does not match the configured minimum.');
    }
  }

  /**
   * Stores a new identity mapping.
   */
  private function storeIdentity(string $provider_id, string $subject_hash, int $uid, ?string $assurance_level, ?string $email_claim): void {
    $now = time();
    $this->database->insert('beantragt_sso_identities')
      ->fields([
        'provider' => $provider_id,
        'subject_hash' => $subject_hash,
        'disabled' => 0,
        'link_method' => 'login',
        'email_claim' => $email_claim,
        'uid' => $uid,
        'assurance_level' => $assurance_level,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Updates mutable identity metadata.
   */
  private function updateIdentity(string $provider_id, string $subject_hash, ?string $assurance_level): void {
    $this->database->update('beantragt_sso_identities')
      ->fields([
        'assurance_level' => $assurance_level,
        'changed' => time(),
      ])
      ->condition('provider', $provider_id)
      ->condition('subject_hash', $subject_hash)
      ->execute();
  }

  /**
   * Marks the account's existing applicant profile with the login.
   *
   * The login is an identity anchor: its legal basis is the authentication,
   * not a consent, so it may confirm identity values (email, name, birth
   * date) on a profile the account already owns or takes over, marked with
   * the provider. It does not create a profile: a profile needs the
   * jurisdiction scope of an application, which a login does not have, and
   * the login must never fail because of the profile. Contact and address
   * data from applications still need the jurisdiction switch and the
   * citizen's consent.
   *
   * Email matching uses the account's current recorded mailbox proof. A
   * verifying provider can establish that proof; an already-linked provider
   * that does not verify mail cannot replace or invalidate it. Without proof
   * profiles are matched by UID alone. Account linking is a separate decision.
   *
   * @param \Drupal\user\UserInterface $user
   *   Authenticated Drupal user.
   * @param array<string, string> $mapped
   *   Mapped SSO attributes.
   * @param string $provider_id
   *   Provider machine name that confirmed the values.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function ensureApplicant(UserInterface $user, array $mapped, string $provider_id, array $provider = []): ?ApplicantInterface {
    $crm = [];
    $verified_mail = $this->verifiedMail;
    $email = $verified_mail->getVerifiedEmail($user);
    $email_verified = $email !== NULL;
    if ($email_verified) {
      $crm['person.email'] = $email;
    }
    if (!empty($mapped['full_name'])) {
      $crm['person.full_name'] = $mapped['full_name'];
    }
    if (!empty($mapped['first_name'])) {
      $crm['person.first_name'] = $mapped['first_name'];
    }
    if (!empty($mapped['last_name'])) {
      $crm['person.last_name'] = $mapped['last_name'];
    }
    if (!empty($mapped['birthdate'])) {
      $crm['person.birthdate'] = $mapped['birthdate'];
    }

    $applicant = NULL;
    if ($crm !== [] && !BeantragtRoles::isServiceAccount($user)) {
      try {
        $applicant = $this->applicantManager->updateExistingFromLogin($crm, (int) $user->id(), $email_verified, [
          'provider' => $provider_id,
          'time' => $this->time instanceof TimeInterface ? $this->time->getRequestTime() : time(),
        ]);
      }
      catch (\Throwable $exception) {
        SsoFailureLog::log($this->logger, 'error', 'profile_update_failed', $provider_id);
      }
    }
    if ($email_verified) {
      $this->claimUnclaimedProfiles($user, $crm['person.email']);
    }
    return $applicant;
  }

  /**
   * Tells whether a provider verifies the mailbox of the email it releases.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   */
  private function providerVerifiesEmail(array $provider): bool {
    return ($provider['email_verified'] ?? FALSE) === TRUE;
  }

  /**
   * Takes over every unclaimed profile under the login-confirmed address.
   *
   * Runs only with the current verified account mailbox, so neither a
   * synthetic account mail nor an unverified provider claim can claim. The
   * passwordless login does the same (OtpService); here it also covers
   * profiles at further Stellen. A failure is logged and never blocks the
   * login.
   */
  private function claimUnclaimedProfiles(UserInterface $user, string $email): void {
    if (BeantragtRoles::isServiceAccount($user)) {
      return;
    }
    try {
      $this->applicantManager->claimUnclaimedByVerifiedEmail((int) $user->id(), $email);
    }
    catch (\Throwable $exception) {
      SsoFailureLog::log($this->logger, 'error', 'profile_claim_failed', '');
    }
  }

  /**
   * Finds the first scalar attribute value by configured names.
   *
   * @param array<int, mixed> $names
   *   Candidate SSO attribute names.
   * @param array<string, array<int, mixed>> $attributes
   *   Attributes keyed by name.
   * @param array<string, array<int, mixed>> $friendly_attributes
   *   Attributes keyed by FriendlyName.
   */
  private function firstAttributeValue(array $names, array $attributes, array $friendly_attributes): ?string {
    foreach ($names as $name) {
      if (!is_scalar($name)) {
        continue;
      }
      $key = (string) $name;
      foreach ([$attributes[$key] ?? NULL, $friendly_attributes[$key] ?? NULL] as $values) {
        if (!is_array($values) || $values === []) {
          continue;
        }
        $value = reset($values);
        if (is_scalar($value) && trim((string) $value) !== '') {
          return trim((string) $value);
        }
      }
    }

    return NULL;
  }

  /**
   * Hashes the external subject without storing raw NameIDs.
   */
  private function subjectHash(string $provider_id, string $name_id): string {
    try {
      $hash_salt = Settings::getHashSalt();
    }
    catch (\RuntimeException $exception) {
      throw new \RuntimeException('SSO identity hashing requires a private Drupal hash salt.', 0, $exception);
    }
    if (trim($hash_salt) === '') {
      throw new \RuntimeException('SSO identity hashing requires a private Drupal hash salt.');
    }

    return hash_hmac(
      'sha256',
      $provider_id . "\n" . $name_id,
      $hash_salt,
    );
  }

  /**
   * Loads a user by email.
   */
  private function loadUserByMail(string $email): ?UserInterface {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
    $user = reset($users);
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Loads a user by account name.
   */
  private function loadUserByName(string $name): ?UserInterface {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name]);
    $user = reset($users);
    return $user instanceof UserInterface ? $user : NULL;
  }

}
