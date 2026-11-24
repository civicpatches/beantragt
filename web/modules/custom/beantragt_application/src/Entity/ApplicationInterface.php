<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Entity;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for Application entities.
 */
interface ApplicationInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, RevisionLogInterface {

  /**
   * CRM keys that reach the applicant profile only with bank consent.
   */
  public const BANK_CONSENT_CRM_KEYS = [
    'person.iban',
    'person.account_holder',
  ];

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
   * Gets the application data as JSON string.
   *
   * @return string|null
   *   The JSON data string, or NULL if not set.
   */
  public function getData(): ?string;

  /**
   * Sets the application data.
   *
   * @param string $data
   *   The JSON data string.
   *
   * @return static
   *   The called entity.
   */
  public function setData(string $data): static;

  /**
   * Gets the CRM-tagged data as JSON string.
   *
   * @return string|null
   *   The CRM JSON data string, or NULL if not set.
   */
  public function getCrmData(): ?string;

  /**
   * Gets CRM data for applicant profile synchronization.
   *
   * Unlike application.field_crm_data, this may include allowed profile keys
   * that are stored only on the encrypted applicant profile, such as IBAN and
   * account holder. Schema fields marked sensitive are never included. The
   * tax ID never reaches the applicant profile. Callers must drop
   * BANK_CONSENT_CRM_KEYS unless bank consent has been verified.
   *
   * @param bool $includeEncryptedBankData
   *   Reveal encrypted bank details after checking the bank consent and switch.
   *
   * @return array
   *   CRM data keyed by schema crm_key.
   */
  public function getApplicantCrmData(bool $includeEncryptedBankData = FALSE): array;

  /**
   * Refreshes CRM data from a specific form schema revision.
   *
   * @param string $data
   *   The application data JSON string.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $schema
   *   The schema revision used for extraction.
   *
   * @return static
   *   The called entity.
   */
  public function refreshCrmDataFromSchema(string $data, FormSchemaInterface $schema): static;

  /**
   * Gets the workflow status machine name.
   *
   * Resolves the referenced workflow_status entity's machine name. Defaults
   * to "new" when no status is referenced.
   *
   * @return string
   *   The workflow status machine name (e.g. new, in_review, approved).
   */
  public function getWorkflowStatus(): string;

  /**
   * Gets the referenced workflow_status entity id.
   *
   * @return int|null
   *   The workflow_status entity id, or NULL if none is referenced.
   */
  public function getWorkflowStatusId(): ?int;

  /**
   * Sets the workflow status by machine name.
   *
   * Resolves the machine name to the workflow_status entity owned by this
   * application's organisation. A no-op when no matching status is found.
   *
   * @param string $status
   *   The workflow status machine name.
   *
   * @return static
   *   The called entity.
   */
  public function setWorkflowStatus(string $status): static;

  /**
   * Sets the workflow status by workflow_status entity id.
   *
   * @param int $status_id
   *   The workflow_status entity id.
   *
   * @return static
   *   The called entity.
   */
  public function setWorkflowStatusId(int $status_id): static;

  /**
   * Allows the next save to reset the workflow status from revision history.
   *
   * This is reserved for audited administrative rollback actions. Normal
   * status changes still go through the configured workflow transitions.
   *
   * @return static
   *   The called entity.
   */
  public function allowWorkflowStatusReset(): static;

  /**
   * Whether the current save is an administrative status reset.
   *
   * @return bool
   *   TRUE when transition validation may be bypassed for this save.
   */
  public function isWorkflowStatusResetAllowed(): bool;

  /**
   * Marks the next save as an automatic status change by the system.
   *
   * The reason is a stable code such as "beantragt_comment:auto_request_info".
   * It lives on this object only, so subscribers can recognise the change
   * after the save without parsing the revision log.
   *
   * @param string $reason
   *   The reason code.
   *
   * @return static
   *   The called entity.
   */
  public function markSystemStatusChange(string $reason): static;

  /**
   * Gets the reason code of an automatic status change for this save.
   *
   * @return string|null
   *   The reason code, or NULL for a change by a person.
   */
  public function getSystemStatusChange(): ?string;

  /**
   * Forgets the automatic status change reason after its save is handled.
   *
   * The marker applies to one save only. Without the reset, a later manual
   * status change saved on the same object would pass as automatic.
   *
   * @return static
   *   The called entity.
   */
  public function clearSystemStatusChange(): static;

  /**
   * Gets the reference number.
   *
   * @return string|null
   *   The reference number, or NULL if not yet generated.
   */
  public function getReferenceNumber(): ?string;

  /**
   * Gets the schema version used at application time.
   *
   * @return int
   *   The schema version number.
   */
  public function getSchemaVersion(): int;

  /**
   * Gets how the application came in: "citizen" or "bulk".
   *
   * Read-only, set at intake (field_origin). Batch intake stores the fields
   * of a repeatable section as one list per field, one element per entry.
   *
   * @return string|null
   *   The origin, or NULL when the field holds none.
   */
  public function getOrigin(): ?string;

  /**
   * Gets the applicant email.
   *
   * @return string|null
   *   The applicant email, or NULL if not set.
   */
  public function getApplicantEmail(): ?string;

  /**
   * Sets the one-time public status URL for the submit request.
   *
   * This value is request-local and not persisted. It exists so insert hooks
   * can use the plaintext public status token without storing it.
   *
   * @param string|null $url
   *   The absolute public status URL, or NULL to clear it.
   *
   * @return static
   *   The called entity.
   */
  public function setSubmitStatusUrl(?string $url): static;

  /**
   * Gets the request-local public status URL for the submit request.
   *
   * @return string|null
   *   The absolute public status URL, or NULL if none is available.
   */
  public function getSubmitStatusUrl(): ?string;

  /**
   * Returns whether the applicant email has been confirmed.
   *
   * Applications created by authenticated submissions are confirmed at create
   * time. Anonymous public submissions remain unconfirmed until the one-time
   * confirmation token is claimed.
   *
   * @return bool
   *   TRUE when the application may enter staff-facing workflows.
   */
  public function isEmailConfirmed(): bool;

  /**
   * Returns the stored delivery route of the application.
   *
   * @return string
   *   portal, postal or bundid; portal when empty or unknown.
   */
  public function getDeliveryRoute(): string;

  /**
   * Returns the delivery route record.
   *
   * @return array<int, array<string, mixed>>
   *   route_chosen and route_changed events, oldest first; empty when none
   *   or malformed.
   */
  public function getDeliveryRecord(): array;

  /**
   * Returns whether the citizen consented to reusing contact data.
   *
   * The flag is only TRUE when the consent was effective at submission, that
   * is when the jurisdiction had applicant profiles enabled.
   *
   * @return bool
   *   TRUE when the application may create or update an applicant profile.
   */
  public function hasProfileConsent(): bool;

  /**
   * Returns the stored proof of the contact data consent.
   *
   * @return array<string, mixed>
   *   Consent record (time, locale, textVersion, text, textHash, scope,
   *   source), or an empty array when no consent was given.
   */
  public function getProfileConsentRecord(): array;

  /**
   * Returns whether the citizen consented to reusing bank details.
   *
   * @return bool
   *   TRUE when IBAN and account holder may reach the applicant profile.
   */
  public function hasBankConsent(): bool;

  /**
   * Returns the stored proof of the bank details consent.
   *
   * @return array<string, mixed>
   *   Consent record with the same shape as the contact consent record, or an
   *   empty array when no consent was given.
   */
  public function getBankConsentRecord(): array;

}
