<?php

declare(strict_types=1);

namespace Drupal\beantragt_passwordless\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\RetentionCleanupService;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes a citizen's account data and keeps the authority's record.
 *
 * "Alle meine Daten löschen" under "Meine Daten" removes what belongs to the
 * account: the Drupal user (the sign-in address), all applicant profiles with
 * their consents, bank details and login confirmations, SSO identity links
 * (through beantragt_sso's user delete hook), pending login codes,
 * submissions whose double opt-in was never confirmed and uploads that were
 * never attached to a submitted application. Confirmed applications
 * belong to the record of the authority: they stay unchanged, including the
 * contact address on the application, with their decisions, messages and
 * attachments, until the retention rules or staff remove them. Content the
 * account owned is reassigned to the anonymous user (uid 0).
 *
 * In this version beantragt.ai is always treated as the place of the record,
 * also after a confirmed FIT-Connect pickup.
 */
final class CitizenErasureService {

  /**
   * Table of SSO identity links, owned by the optional beantragt_sso module.
   */
  private const SSO_IDENTITY_TABLE = 'beantragt_sso_identities';

  /**
   * Table of pending passwordless login codes.
   */
  private const OTP_TABLE = 'beantragt_passwordless_codes';

  /**
   * Entity types whose owner moves to the anonymous user, with their columns.
   *
   * Keyed by entity type ID; values list the owner columns per table role.
   */
  private const REASSIGNED_ENTITY_TYPES = [
    'application' => ['uid', 'revision_user'],
    'application_comment' => ['uid'],
    'application_attachment' => ['uid'],
    'file' => ['uid'],
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly RetentionCleanupService $retention,
    private readonly LoggerInterface $logger,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    private readonly ?VerifiedAccountMail $verifiedMail = NULL,
  ) {}

  /**
   * Describes what an erasure would delete and what stays.
   *
   * @return array{deletes: array<string, mixed>, keeps: list<array<string, mixed>>}
   *   Machine-readable preview without localized text.
   */
  public function preview(AccountInterface $account): array {
    $uid = (int) $account->id();
    $account_email = $this->normalizedEmail($account);
    $verified_email = $this->verifiedEmail($account);
    $applications = $this->loadCitizenApplications($uid, $verified_email);
    $profiles = $this->loadCitizenProfiles($uid, $verified_email);

    $unconfirmed = array_filter($applications, static fn(ApplicationInterface $application): bool => !$application->isEmailConfirmed());
    $kept = array_filter($applications, static fn(ApplicationInterface $application): bool => $application->isEmailConfirmed());

    return [
      'deletes' => [
        'account' => $uid > 0,
        'accountEmail' => $account_email,
        'profiles' => array_values(array_map(fn(FieldableEntityInterface $profile): array => [
          'scopeLabel' => $this->profileScopeLabel($profile),
        ], $profiles)),
        'unconfirmedApplications' => count($unconfirmed),
        'unconfirmedUploads' => count($this->loadUnattachedUploads($uid)),
        'loginLinks' => $this->countSsoIdentities($uid),
      ],
      'keeps' => $this->describeKeptApplications($kept),
    ];
  }

  /**
   * Erases the account data of a citizen and keeps confirmed applications.
   *
   * The caller logs the session out afterwards.
   *
   * @return array{deleted: array<string, int|bool>, keeps: list<array<string, mixed>>}
   *   Summary of deleted items and of applications that stay.
   */
  public function erase(AccountInterface $account): array {
    $uid = (int) $account->id();
    $account_email = $this->normalizedEmail($account);
    $verified_email = $this->verifiedEmail($account);
    $applications = $this->loadCitizenApplications($uid, $verified_email);

    $unconfirmed = [];
    $kept = [];
    foreach ($applications as $id => $application) {
      if ($application->isEmailConfirmed()) {
        $kept[$id] = $application;
      }
      else {
        $unconfirmed[$id] = $application;
      }
    }
    // Describe what stays before owners change, so the summary names the
    // same Stelle and retention rule as the preview.
    $keeps = $this->describeKeptApplications($kept);

    $profiles = [];
    $login_links = 0;
    $reassigned = 0;
    $account_deleted = FALSE;
    // Uploads that never became part of a submitted application belong to
    // abandoned drafts. Load them before the owners change.
    $unattached = $this->loadUnattachedUploads($uid);
    // One transaction: a failing step (for example a user delete hook) must
    // not leave a half erased account behind. File deletion is not
    // transactional (core removes the file on disk in File::preDelete()), so
    // the steps that delete files run last, after the account is gone and
    // the steps most likely to throw have passed.
    $transaction = $this->database->startTransaction();
    try {
      $profiles = $this->loadCitizenProfiles($uid, $verified_email);
      if ($profiles !== []) {
        $this->entityTypeManager->getStorage('applicant')->delete($profiles);
      }

      $login_links = $this->countSsoIdentities($uid);
      $this->deletePendingLoginCodes($account_email);

      if ($uid > 0) {
        $reassigned = $this->reassignOwnedContent($uid);
        $user = $this->entityTypeManager->getStorage('user')->load($uid);
        if ($user instanceof UserInterface) {
          // Lets core and contrib modules reassign their own content, as the
          // "Delete the account and make its content belong to the Anonymous
          // user" cancel method does, then deletes the account. User delete
          // hooks delete SSO identity links and remove per-user audit rows.
          $this->moduleHandler->invokeAll('user_cancel', [[], $user, 'user_cancel_reassign']);
          $user->delete();
          $account_deleted = TRUE;
        }
      }

      // Never confirmed submissions are not part of any record yet. Their
      // predelete hooks remove attachments, comments and delivery data.
      if ($unconfirmed !== []) {
        $this->entityTypeManager->getStorage('application')->delete($unconfirmed);
      }
      // Abandoned draft uploads go with their files instead of staying with
      // the anonymous user.
      $this->deleteUnattachedUploads($unattached);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);

    $deleted = [
      'account' => $account_deleted,
      'profiles' => count($profiles),
      'unconfirmedApplications' => count($unconfirmed),
      'unconfirmedUploads' => count($unattached),
      'loginLinks' => $account_deleted ? $login_links : 0,
    ];
    $this->logger->info('Citizen account erasure for user @uid: account @account, @profiles profiles, @unconfirmed unconfirmed submissions, @uploads unattached uploads, @links login links deleted; @kept applications kept with the authority, @reassigned owned rows reassigned to anonymous.', [
      '@uid' => $uid,
      '@account' => $account_deleted ? 'deleted' : 'not found',
      '@profiles' => $deleted['profiles'],
      '@unconfirmed' => $deleted['unconfirmedApplications'],
      '@uploads' => count($unattached),
      '@links' => $deleted['loginLinks'],
      '@kept' => count($keeps),
      '@reassigned' => $reassigned,
    ]);

    return [
      'deleted' => $deleted,
      'keeps' => $keeps,
    ];
  }

  /**
   * Returns applications owned by uid or claimed through the account email.
   *
   * @return array<int, \Drupal\beantragt_application\Entity\ApplicationInterface>
   *   Citizen applications keyed by entity ID, confirmed or not.
   */
  public function loadCitizenApplications(int $uid, string $email): array {
    if ($uid <= 0 && $email === '') {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('application');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $or_group = $query->orConditionGroup();
    if ($uid > 0) {
      $or_group->condition('uid', $uid);
    }
    if ($email !== '') {
      $or_group->condition('field_applicant_email', $email);
      $email_hash = $this->applicantEmailLookupHash($email);
      if ($email_hash !== NULL) {
        $or_group->condition('field_applicant_email_hash', $email_hash);
      }
    }
    $ids = $query->condition($or_group)->sort('created', 'DESC')->execute();
    if ($ids === []) {
      return [];
    }

    $applications = [];
    foreach ($storage->loadMultiple($ids) as $application) {
      if ($application instanceof ApplicationInterface
        && $this->isCitizenApplication($application, $uid, $email)) {
        $applications[(int) $application->id()] = $application;
      }
    }
    return $applications;
  }

  /**
   * Returns applicant profiles of the account.
   *
   * Profiles owned by the account, and unowned profiles (uid 0) carrying the
   * account email. A profile that belongs to another account is never part
   * of this erasure, even when its email matches.
   *
   * @return array<int, \Drupal\Core\Entity\FieldableEntityInterface>
   *   Profiles keyed by entity ID.
   */
  public function loadCitizenProfiles(int $uid, string $email): array {
    if ($uid <= 0 && $email === '') {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('applicant');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $or_group = $query->orConditionGroup();
    if ($uid > 0) {
      $or_group->condition('uid', $uid);
    }
    if ($email !== '') {
      $or_group->condition(
        $query->andConditionGroup()
          ->condition('field_email', $email)
          ->condition('uid', 0)
      );
    }
    $ids = $query->condition($or_group)->execute();
    if ($ids === []) {
      return [];
    }
    $profiles = [];
    foreach ($storage->loadMultiple($ids) as $id => $profile) {
      $owner_id = $profile instanceof EntityOwnerInterface ? (int) $profile->getOwnerId() : 0;
      if ($profile instanceof FieldableEntityInterface && in_array($owner_id, [0, $uid], TRUE)) {
        $profiles[(int) $id] = $profile;
      }
    }
    return $profiles;
  }

  /**
   * Describes confirmed applications that stay with the authority.
   *
   * @param array<int, \Drupal\beantragt_application\Entity\ApplicationInterface> $applications
   *   Confirmed applications.
   *
   * @return list<array<string, mixed>>
   *   One entry per application, newest first.
   */
  private function describeKeptApplications(array $applications): array {
    $entries = [];
    foreach ($applications as $application) {
      $schema = PublicSubmission::submittedSchema($application, $this->entityTypeManager);
      $organisation = $this->referencedGroup($application, 'field_organisation');
      $jurisdiction = $this->referencedGroup($application, 'field_jurisdiction');
      $retention = $this->retention->retentionForApplication($application);
      $entries[] = [
        'id' => (string) $application->uuid(),
        'referenceNumber' => $application->getReferenceNumber() ?: strtoupper(substr((string) $application->uuid(), 0, 8)),
        'formTitle' => $schema instanceof FieldableEntityInterface ? (string) $schema->label() : '',
        'submittedAt' => $this->formatTimestamp($application->getCreatedTime()),
        'applicantEmail' => $this->applicantEmail($application),
        'stelle' => [
          'organisation' => $organisation instanceof FieldableEntityInterface ? trim((string) $organisation->label()) : NULL,
          'jurisdiction' => $jurisdiction instanceof FieldableEntityInterface ? trim((string) $jurisdiction->label()) : NULL,
          'contact' => $this->stelleContact($jurisdiction),
        ],
        'retention' => [
          'days' => $retention['days'],
          'source' => $retention['source'],
          'deleteAfter' => $retention['deleteAfter'] !== NULL ? $this->formatTimestamp($retention['deleteAfter']) : NULL,
        ],
      ];
    }
    return $entries;
  }

  /**
   * Returns the contact address stored on the application, or NULL.
   *
   * The address stays on the application after the erasure; the preview
   * shows it so the citizen sees which address the Stelle keeps using.
   */
  private function applicantEmail(ApplicationInterface $application): ?string {
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      try {
        return $this->dataProtection->protectedApplicantEmail($application);
      }
      catch (\Throwable) {
        // Falls back to the clear text field below.
      }
    }
    if (!$application->hasField('field_applicant_email') || $application->get('field_applicant_email')->isEmpty()) {
      return NULL;
    }
    return $this->nonEmptyString(strtolower((string) $application->get('field_applicant_email')->value));
  }

  /**
   * Returns uploads of the account never attached to an application.
   *
   * @return array<int, \Drupal\Core\Entity\FieldableEntityInterface>
   *   Attachment entities keyed by ID.
   */
  private function loadUnattachedUploads(int $uid): array {
    if ($uid <= 0 || !$this->entityTypeManager->hasDefinition('application_attachment')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('application_attachment');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->notExists('field_application')
      ->execute();
    if ($ids === []) {
      return [];
    }
    $uploads = [];
    foreach ($storage->loadMultiple($ids) as $id => $upload) {
      if ($upload instanceof FieldableEntityInterface
        && (!$upload->hasField('field_application') || $upload->get('field_application')->isEmpty())) {
        $uploads[(int) $id] = $upload;
      }
    }
    return $uploads;
  }

  /**
   * Deletes uploads never attached to an application, with their files.
   *
   * The attachment delete hook removes the guard derivative; the original
   * file is deleted here, as the retention cleanup of expired uploads does.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface[] $uploads
   *   Uploads from loadUnattachedUploads(), loaded before owners changed.
   */
  private function deleteUnattachedUploads(array $uploads): void {
    if ($uploads === []) {
      return;
    }
    $file_ids = [];
    foreach ($uploads as $upload) {
      if ($upload->hasField('field_file') && !$upload->get('field_file')->isEmpty()) {
        $file_ids[] = (int) $upload->get('field_file')->target_id;
      }
    }
    $this->entityTypeManager->getStorage('application_attachment')->delete($uploads);
    $file_ids = array_values(array_unique(array_filter($file_ids)));
    if ($file_ids !== []) {
      $file_storage = $this->entityTypeManager->getStorage('file');
      $files = $file_storage->loadMultiple($file_ids);
      if ($files !== []) {
        $file_storage->delete($files);
      }
    }
  }

  /**
   * Reads the public contact of the jurisdiction behind an application.
   *
   * The jurisdiction's public settings (general.contactEmail, phone,
   * website) come first, the jurisdiction contact email field second.
   *
   * @return array{email: ?string, phone: ?string, website: ?string}
   *   Contact details, each NULL when not configured.
   */
  private function stelleContact(?FieldableEntityInterface $jurisdiction): array {
    $contact = ['email' => NULL, 'phone' => NULL, 'website' => NULL];
    if (!$jurisdiction instanceof FieldableEntityInterface) {
      return $contact;
    }
    if ($jurisdiction->hasField('field_jur_config') && !$jurisdiction->get('field_jur_config')->isEmpty()) {
      $config = json_decode((string) $jurisdiction->get('field_jur_config')->value, TRUE);
      $general = is_array($config) && is_array($config['general'] ?? NULL) ? $config['general'] : [];
      $contact['email'] = $this->nonEmptyString($general['contactEmail'] ?? NULL);
      $contact['phone'] = $this->nonEmptyString($general['phone'] ?? NULL);
      $contact['website'] = $this->nonEmptyString($general['website'] ?? NULL);
    }
    if ($contact['email'] === NULL
      && $jurisdiction->hasField('field_org_email')
      && !$jurisdiction->get('field_org_email')->isEmpty()) {
      $contact['email'] = $this->nonEmptyString($jurisdiction->get('field_org_email')->value);
    }
    return $contact;
  }

  /**
   * Builds the Stelle label of a profile from organisation and jurisdiction.
   */
  private function profileScopeLabel(FieldableEntityInterface $profile): ?string {
    $labels = [];
    foreach (['field_organisation', 'field_jurisdiction'] as $field_name) {
      $group = $this->referencedGroup($profile, $field_name);
      $label = $group instanceof FieldableEntityInterface ? trim((string) $group->label()) : '';
      if ($label !== '' && !in_array($label, $labels, TRUE)) {
        $labels[] = $label;
      }
    }
    return $labels === [] ? NULL : implode(', ', $labels);
  }

  /**
   * Moves owned content of the account to the anonymous user.
   *
   * Writes the owner columns directly instead of saving each entity: entity
   * saves would run presave hooks (computed fields, data protection, label)
   * that may rewrite the record of the authority. The record must stay as it
   * was submitted and decided; only the owner reference changes.
   *
   * Deliberate exception to the rule that every application mutation creates
   * a revision: an entity save would add a
   * new revision authored by the erased account and keep its ID in the
   * history. The SQL update instead clears the account ID from uid and
   * revision_user of every stored revision, so no revision author points at
   * a deleted user. The civic.revision.v1 logs never carry owner IDs (only
   * responsibility, person, team and status references of staff), so the
   * logs need no rewrite.
   *
   * @return int
   *   Number of updated rows across all tables.
   */
  private function reassignOwnedContent(int $uid): int {
    $updated = 0;
    foreach (self::REASSIGNED_ENTITY_TYPES as $entity_type_id => $columns) {
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      if (!$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $tables = array_filter([
        $entity_type->getBaseTable(),
        $entity_type->getDataTable(),
        $entity_type->getRevisionTable(),
        $entity_type->getRevisionDataTable(),
      ]);
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uid)
        ->execute();
      $schema = $this->database->schema();
      foreach (array_unique($tables) as $table) {
        if (!$schema->tableExists($table)) {
          continue;
        }
        foreach ($columns as $column) {
          if (!$schema->fieldExists($table, $column)) {
            continue;
          }
          $updated += (int) $this->database->update($table)
            ->fields([$column => 0])
            ->condition($column, $uid)
            ->execute();
        }
      }
      if ($ids !== []) {
        $ids = array_values($ids);
        $storage->resetCache($ids);
        $tags = $entity_type->getListCacheTags();
        foreach ($ids as $id) {
          $tags = Cache::mergeTags($tags, [$entity_type_id . ':' . $id]);
        }
        $this->cacheTagsInvalidator->invalidateTags($tags);
      }
    }
    return $updated;
  }

  /**
   * Counts SSO identity links of the account.
   */
  private function countSsoIdentities(int $uid): int {
    if ($uid <= 0 || !$this->database->schema()->tableExists(self::SSO_IDENTITY_TABLE)) {
      return 0;
    }
    return (int) $this->database->select(self::SSO_IDENTITY_TABLE, 'i')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Deletes pending passwordless login codes of the account email.
   */
  private function deletePendingLoginCodes(string $email): void {
    if ($email === '' || !$this->database->schema()->tableExists(self::OTP_TABLE)) {
      return;
    }
    $this->database->delete(self::OTP_TABLE)
      ->condition('email', $email)
      ->execute();
  }

  /**
   * Returns TRUE when the application belongs to the citizen.
   *
   * Owned by the account, or a public submission of the service account
   * whose applicant email matches the verified account email.
   */
  private function isCitizenApplication(ApplicationInterface $application, int $uid, string $email): bool {
    if ($uid > 0 && (int) $application->getOwnerId() === $uid) {
      return TRUE;
    }
    if ($email === '' || !$this->isPublicServiceAccountSubmission($application)) {
      return FALSE;
    }
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      return $this->dataProtection->applicationApplicantEmailMatches($application, $email);
    }
    if (!$application->hasField('field_applicant_email') || $application->get('field_applicant_email')->isEmpty()) {
      return FALSE;
    }
    return strtolower(trim((string) $application->get('field_applicant_email')->value)) === $email;
  }

  /**
   * Returns TRUE for public service-account submissions that can be claimed.
   */
  private function isPublicServiceAccountSubmission(ApplicationInterface $application): bool {
    if (!$application->hasField('field_public_status_token_hash')
      || $application->get('field_public_status_token_hash')->isEmpty()) {
      return FALSE;
    }
    if (!BeantragtRoles::isServiceAccount($application->getOwner())) {
      return FALSE;
    }
    return PublicSubmission::isPublicInternetSchema(
      PublicSubmission::submittedSchema($application, $this->entityTypeManager)
    );
  }

  /**
   * Builds an applicant email lookup hash, or NULL when unavailable.
   */
  private function applicantEmailLookupHash(string $email): ?string {
    if (!$this->dataProtection instanceof ApplicationDataProtectionService) {
      return NULL;
    }
    try {
      return $this->dataProtection->applicantEmailLookupHash($email);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Loads a referenced group, or NULL.
   */
  private function referencedGroup(FieldableEntityInterface $entity, string $field_name): ?FieldableEntityInterface {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $group = $entity->get($field_name)->entity;
    return $group instanceof FieldableEntityInterface ? $group : NULL;
  }

  /**
   * Returns the normalized account email for display and pending-code cleanup.
   */
  private function normalizedEmail(AccountInterface $account): string {
    return strtolower(trim((string) $account->getEmail()));
  }

  /**
   * Returns a mailbox eligible for email-based ownership lookup.
   */
  private function verifiedEmail(AccountInterface $account): string {
    return $this->verifiedMail?->getVerifiedEmail($account) ?? '';
  }

  /**
   * Returns a trimmed string or NULL.
   */
  private function nonEmptyString(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Formats a Unix timestamp as ISO 8601, or an empty string.
   */
  private function formatTimestamp(int $timestamp): string {
    return $timestamp > 0 ? date(\DateTimeInterface::ATOM, $timestamp) : '';
  }

}
