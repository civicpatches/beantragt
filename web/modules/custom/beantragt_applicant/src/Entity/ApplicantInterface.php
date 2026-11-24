<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for Applicant entities.
 */
interface ApplicantInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface {

  /**
   * Sensitive profile fields that must be encrypted at rest.
   */
  public const PROTECTED_FIELDS = [
    'field_iban',
    'field_tax_id',
    'field_account_holder',
  ];

  /**
   * Internal field holding the consent proof keyed by applicant field name.
   */
  public const CONSENT_PROOF_FIELD = 'field_consent_proof_by_field';

  /**
   * Consent provenance, withdrawal and login confirmation fields.
   *
   * Names of every field from Applicant::consentBaseFieldDefinitions(),
   * Applicant::revocationBaseFieldDefinitions() and
   * Applicant::consentProofBaseFieldDefinitions(), plus CONSENT_PROOF_FIELD.
   * A kernel test keeps them
   * in step. The complete deletion clears all of them, because the login
   * confirmation holds derivatives of identity values, the provenance fields
   * link the profile to applications and the proof fields describe a consent
   * that no longer exists once the citizen deleted all data.
   */
  public const CONSENT_AND_LOGIN_FIELDS = [
    self::CONSENT_PROOF_FIELD,
    'field_account_holder',
    'field_consent_time',
    'field_consent_text_version',
    'field_consent_source',
    'field_bank_consent_time',
    'field_bank_consent_text_version',
    'field_bank_consent_source',
    'field_consent_text_hash',
    'field_consent_locale',
    'field_consent_scope_label',
    'field_bank_consent_text_hash',
    'field_bank_consent_locale',
    'field_bank_consent_scope_label',
    'field_consent_revoked_time',
    'field_bank_consent_revoked_time',
    'field_login_confirmed_provider',
    'field_login_confirmed_time',
    'field_login_confirmed_keys',
  ];

  /**
   * Fails closed when protected applicant fields cannot be encrypted.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function assertFieldEncryptionRuntimeAvailable(): void;

  /**
   * Gets the entity creation timestamp.
   *
   * @return int
   *   The creation timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Sets the entity creation timestamp.
   *
   * @param int $timestamp
   *   The creation timestamp.
   *
   * @return static
   *   The called entity.
   */
  public function setCreatedTime(int $timestamp): static;

  /**
   * Gets the full name.
   *
   * @return string|null
   *   The full name, or NULL if not set.
   */
  public function getFullName(): ?string;

  /**
   * Gets the email address.
   *
   * @return string|null
   *   The email address, or NULL if not set.
   */
  public function getEmail(): ?string;

  /**
   * Gets the session token for anonymous identification.
   *
   * @return string|null
   *   The session token, or NULL if not set.
   */
  public function getSessionToken(): ?string;

}
