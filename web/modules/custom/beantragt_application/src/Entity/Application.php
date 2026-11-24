<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Entity;

use Drupal\beantragt_application\ApplicantContactKeys;
use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\beantragt_application\ProfileConsentFields;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\WorkflowStatusResolver;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Application entity.
 *
 * Stores filled-out form data submitted against a FormSchema.
 *
 * @ContentEntityType(
 *   id = "application",
 *   label = @Translation("Application"),
 *   label_collection = @Translation("Applications"),
 *   label_singular = @Translation("application"),
 *   label_plural = @Translation("applications"),
 *   label_count = @PluralTranslation(
 *     singular = "@count application",
 *     plural = "@count applications",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\beantragt_application\ApplicationListBuilder",
 *     "form" = {
 *       "default" = "Drupal\beantragt_application\Form\ApplicationForm",
 *       "add" = "Drupal\beantragt_application\Form\ApplicationForm",
 *       "edit" = "Drupal\beantragt_application\Form\ApplicationForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\beantragt_application\ApplicationAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "application",
 *   revision_table = "application_revision",
 *   show_revision_ui = TRUE,
 *   admin_permission = "administer application entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "revision" = "revision_id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "uid",
 *     "published" = "status",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/applications/{application}",
 *     "add-form" = "/admin/content/applications/add",
 *     "edit-form" = "/admin/content/applications/{application}/edit",
 *     "delete-form" = "/admin/content/applications/{application}/delete",
 *     "collection" = "/admin/content/applications",
 *   },
 * )
 */
class Application extends ContentEntityBase implements ApplicationInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * Maximum age of a pending analysis that still blocks administrative delete.
   */
  private const PENDING_ANALYSIS_DELETE_GRACE_SECONDS = 3600;
  use EntityPublishedTrait;
  use RevisionLogEntityTrait;

  /**
   * Tracks whether postSave label update is in progress.
   *
   * @var bool
   */
  protected static bool $labelUpdateInProgress = FALSE;

  /**
   * Deletion locks held by the current request, keyed by application ID.
   *
   * @var array<int, string>
   */
  protected static array $deletionLocks = [];

  /**
   * Analysis egress locks held by application deletion, keyed by entity ID.
   *
   * @var array<int, string>
   */
  protected static array $analysisLocks = [];

  /**
   * CRM keys allowed to persist on application.field_crm_data.
   *
   * @var string[]
   */
  protected const APPLICATION_STORAGE_CRM_KEYS = [
    'person.full_name',
    'person.first_name',
    'person.last_name',
    'person.email',
    'person.phone',
    'person.address',
    'person.street',
    'person.postal_code',
    'person.city',
  ];

  /**
   * CRM keys allowed to feed applicant profile synchronization.
   *
   * The tax ID is purpose-bound (section 139b AO) and never reaches the
   * profile. IBAN and account holder additionally need bank consent, see
   * ApplicationInterface::BANK_CONSENT_CRM_KEYS.
   *
   * @var string[]
   */
  public const APPLICANT_SYNC_CRM_KEYS = [
    'person.full_name',
    'person.first_name',
    'person.last_name',
    'person.birthdate',
    'person.email',
    'person.phone',
    'person.gender',
    'person.nationality',
    'person.marital_status',
    'person.iban',
    'person.account_holder',
    'person.address',
    'person.street',
    'person.postal_code',
    'person.city',
  ];

  /**
   * Returns the lock name shared with outbound delivery processing.
   */
  public static function deletionLockName(int $applicationId): string {
    return sprintf('beantragt_application.delete.%d', $applicationId);
  }

  /**
   * Returns the lock that prevents deletion during attachment AI egress.
   */
  public static function analysisLockName(int $applicationId): string {
    return sprintf('beantragt_application.analysis.%d', $applicationId);
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);

    $lock = \Drupal::lock();
    /** @var \Drupal\beantragt_application\Service\ApplicationDependentMutationLock $mutation_lock */
    $mutation_lock = \Drupal::service('beantragt_application.dependent_mutation_lock');
    $acquired = [];
    $analysis_acquired = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof ApplicationInterface) {
        throw new \UnexpectedValueException('Application deletion received an unexpected entity type.');
      }
      /** @var \Drupal\beantragt_application\Service\ApplicantScopeMutationLock $scope_lock */
      $scope_lock = \Drupal::service('beantragt_application.applicant_scope_mutation_lock');
      if (!$scope_lock->acquireForApplication($entity)) {
        throw new ApplicationDeletionLockedException('The applicant scope is currently being mutated. Retry deletion after it has finished.');
      }
      // SqlContentEntityStorage starts its transaction before predelete.
      $scope_lock->release($entity);

      $application_id = (int) $entity->id();
      if ($application_id <= 0) {
        continue;
      }
      if (!$mutation_lock->isHeld($entity)) {
        $lock_name = self::deletionLockName($application_id);
        if (!$lock->acquire($lock_name, 300.0)) {
          foreach (array_merge($acquired, $analysis_acquired) as $acquired_lock) {
            $lock->release($acquired_lock);
          }
          throw new ApplicationDeletionLockedException('Application delivery or mutation is currently in progress. Retry deletion after it has finished.');
        }
        $acquired[$application_id] = $lock_name;
      }

      $analysis_lock_name = self::analysisLockName($application_id);
      if (!$lock->acquire($analysis_lock_name, 300.0)) {
        foreach (array_merge($acquired, $analysis_acquired) as $acquired_lock) {
          $lock->release($acquired_lock);
        }
        throw new ApplicationDeletionLockedException('Application attachment analysis is currently in progress. Retry deletion after it has finished.');
      }
      $analysis_acquired[$application_id] = $analysis_lock_name;

      if (self::hasPendingAttachmentAnalysis($application_id)) {
        foreach (array_merge($acquired, $analysis_acquired) as $acquired_lock) {
          $lock->release($acquired_lock);
        }
        throw new ApplicationDeletionLockedException('Application attachment analysis is still pending. Retry deletion after it has finished.');
      }
    }

    self::$deletionLocks += $acquired;
    self::$analysisLocks += $analysis_acquired;
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    try {
      parent::postDelete($storage, $entities);
      foreach ($entities as $entity) {
        if (!$entity instanceof ApplicationInterface) {
          continue;
        }
        $jurisdiction_id = $entity->hasField('field_jurisdiction')
          ? (int) ($entity->get('field_jurisdiction')->target_id ?? 0)
          : 0;
        $organisation_id = $entity->hasField('field_organisation')
          ? (int) ($entity->get('field_organisation')->target_id ?? 0)
          : 0;
        \Drupal::logger('beantragt_application')->notice('Application @uuid was permanently deleted by uid @uid in jurisdiction @jurisdiction and organisation @organisation.', [
          '@uuid' => (string) $entity->uuid(),
          '@uid' => (int) \Drupal::currentUser()->id(),
          '@jurisdiction' => $jurisdiction_id,
          '@organisation' => $organisation_id,
        ]);
      }
    }
    finally {
      $locks = [];
      foreach ($entities as $entity) {
        $application_id = (int) $entity->id();
        if (isset(self::$deletionLocks[$application_id])) {
          $locks[] = self::$deletionLocks[$application_id];
          unset(self::$deletionLocks[$application_id]);
        }
        if (isset(self::$analysisLocks[$application_id])) {
          $locks[] = self::$analysisLocks[$application_id];
          unset(self::$analysisLocks[$application_id]);
        }
      }
      if ($locks !== []) {
        $database = \Drupal::database();
        if ($database->inTransaction()) {
          try {
            $database->transactionManager()->addPostTransactionCallback(
              static function (bool $success) use ($locks): void {
                self::releaseLifecycleLocks($locks);
              },
            );
          }
          catch (\Throwable $exception) {
            self::releaseLifecycleLocks($locks);
            throw $exception;
          }
        }
        else {
          self::releaseLifecycleLocks($locks);
        }
      }
    }
  }

  /**
   * Releases delete lifecycle locks after the root transaction completes.
   *
   * @param string[] $lockNames
   *   Lock names to release.
   */
  private static function releaseLifecycleLocks(array $lockNames): void {
    $lock = \Drupal::lock();
    foreach ($lockNames as $lock_name) {
      $lock->release($lock_name);
    }
  }

  /**
   * Returns whether durable attachment analysis work is still pending.
   */
  private static function hasPendingAttachmentAnalysis(int $applicationId): bool {
    $manager = \Drupal::entityTypeManager();
    if (!$manager->hasDefinition('application_attachment')) {
      return FALSE;
    }
    $ids = $manager->getStorage('application_attachment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', $applicationId)
      ->condition('field_analysis_status', 'pending')
      ->condition(
        'changed',
        \Drupal::time()->getRequestTime() - self::PENDING_ANALYSIS_DELETE_GRACE_SECONDS,
        '>=',
      )
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * Maximum length for supplemental applicant full names.
   */
  private const SUPPLEMENTAL_FULL_NAME_MAX_LENGTH = 200;

  /**
   * Maximum length for supplemental applicant email addresses.
   */
  private const SUPPLEMENTAL_EMAIL_MAX_LENGTH = 254;

  /**
   * Whether CRM data was explicitly refreshed for this save operation.
   */
  protected bool $crmDataExplicitlyRefreshed = FALSE;

  /**
   * Whether transition validation may be bypassed for an admin rollback save.
   */
  protected bool $workflowStatusResetAllowed = FALSE;

  /**
   * Reason code of an automatic status change for the next save, if any.
   */
  protected ?string $systemStatusChange = NULL;

  /**
   * Request-local public status URL for the initial submit notification.
   */
  protected ?string $submitStatusUrl = NULL;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    // Keep the previous state for every application mutation, including trusted
    // programmatic writers that do not opt into revisions themselves.
    if (!$this->isNew() && !$this->isNewRevision()) {
      $this->setNewRevision(TRUE);
      $this->setRevisionUserId((int) \Drupal::currentUser()->id());
      $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $original = $this->getOriginal();
      if ($original instanceof ApplicationInterface
        && $this->getRevisionLogMessage() === $original->getRevisionLogMessage()) {
        $this->setRevisionLogMessage('');
      }
    }

    parent::preSave($storage);

    $data_value = $this->get('field_data')->value;
    self::assertValidFieldDataJsonShape($data_value);
    // Erasure has no remaining source values from which CRM could be derived.
    // Only the exact marker plus an empty CRM field satisfies this exception.
    if (json_decode((string) $data_value, TRUE) === ['_anonymized' => TRUE]
      && $this->get('field_crm_data')->isEmpty()) {
      $this->crmDataExplicitlyRefreshed = TRUE;
    }
    $workflow_attributes_value = $this->get('field_workflow_attributes')->value ?? NULL;
    if ($workflow_attributes_value !== NULL && !json_validate($workflow_attributes_value)) {
      throw new \InvalidArgumentException('The field_workflow_attributes value is not valid JSON.');
    }
    if ($workflow_attributes_value !== NULL) {
      $decoded_shape = json_decode((string) $workflow_attributes_value);
      if (!$decoded_shape instanceof \stdClass) {
        throw new \InvalidArgumentException('The field_workflow_attributes value must be a JSON object.');
      }
    }
    if (!$this->isNew()
          && $this->workflowAttributesChanged()
          && !$this->workflowStatusChanged()
      ) {
      throw new \InvalidArgumentException('Workflow attributes can only change during a workflow status transition.');
    }

    // Reference number generation is handled by
    // beantragt_application_application_presave() in the .module file
    // to avoid static \Drupal::service() calls in the entity class.
    $schema_label = '';
    $schema_entity = $this->get('field_schema')->entity;
    if ($schema_entity instanceof FormSchemaInterface) {
      $schema_label = $schema_entity->label();
    }
    $id = $this->id() ?: '?';
    $this->set('label', "Application #{$id} - {$schema_label}");

    if ($this->isNew() && $schema_entity instanceof FormSchemaInterface && empty($this->get('field_schema_version')->value)) {
      $this->set('field_schema_version', $schema_entity->getVersion());
    }

    if ($this->isNew() && $schema_entity instanceof FormSchemaInterface) {
      // Capture the default revision at save time. Same-version schema content
      // is immutable; lifecycle-only revisions may share the same content.
      $this->set('field_schema_revision_id', $this->getSchemaVersion() === $schema_entity->getVersion() ? $schema_entity->getRevisionId() : NULL);
    }
    elseif (!$this->isNew() && $this->getOriginal() instanceof ApplicationInterface
      && (int) $this->get('field_schema_revision_id')->value !== (int) $this->getOriginal()->get('field_schema_revision_id')->value) {
      throw new \InvalidArgumentException('The submitted schema revision is immutable.');
    }
    if ($this->isNew()) {
      $this->set('field_receipt_form_title', $this->get('field_schema_revision_id')->value
        && PublicSubmission::isPublicInternetSchema($schema_entity) ? $schema_label : NULL);
    }
    elseif ($this->getOriginal() instanceof ApplicationInterface) {
      $this->set('field_receipt_form_title', $this->getOriginal()->get('field_receipt_form_title')->value);
    }

    if (self::fieldDataChangeRequiresExplicitCrmRefresh(
          $this->isNew(),
          $data_value !== NULL && $this->fieldDataChanged(),
          $this->crmDataExplicitlyRefreshed,
      )
      ) {
      throw new \InvalidArgumentException('Application field_data changes must refresh CRM data from the submitted schema revision.');
    }

    if ($data_value !== NULL
          && $schema_entity instanceof FormSchemaInterface
          && self::shouldAutoExtractCrmDataOnPreSave($this->isNew(), $this->crmDataExplicitlyRefreshed)
      ) {
      $this->extractCrmData($data_value, $schema_entity);
    }
  }

  /**
   * Checks whether stored field data changed on this save.
   */
  private function fieldDataChanged(): bool {
    $original = $this->original ?? NULL;
    if (!$original instanceof ApplicationInterface) {
      return FALSE;
    }

    return ($original->get('field_data')->value ?? NULL)
        !== ($this->get('field_data')->value ?? NULL);
  }

  /**
   * Validates that stored field_data is JSON object-shaped.
   */
  private static function assertValidFieldDataJsonShape(?string $data_value): void {
    if ($data_value === NULL) {
      return;
    }
    if (!json_validate($data_value)) {
      throw new \InvalidArgumentException('The field_data value is not valid JSON.');
    }
    if (!(json_decode($data_value) instanceof \stdClass)) {
      throw new \InvalidArgumentException('The field_data value must be a JSON object.');
    }
  }

  /**
   * Returns TRUE when field_data changed without a schema-revision refresh.
   */
  private static function fieldDataChangeRequiresExplicitCrmRefresh(bool $is_new, bool $field_data_changed, bool $crm_data_refreshed): bool {
    return !$is_new && $field_data_changed && !$crm_data_refreshed;
  }

  /**
   * Returns TRUE when preSave should derive CRM data.
   */
  private static function shouldAutoExtractCrmDataOnPreSave(bool $is_new, bool $crm_data_refreshed): bool {
    return $is_new && !$crm_data_refreshed;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);

    if (!$update && !static::$labelUpdateInProgress) {
      $schema_label = '';
      $schema_entity = $this->get('field_schema')->entity;
      if ($schema_entity) {
        $schema_label = $schema_entity->label();
      }
      $new_label = "Application #{$this->id()} - {$schema_label}";
      $this->set('label', $new_label);

      static::$labelUpdateInProgress = TRUE;
      try {
        $this->setNewRevision(TRUE);
        $this->setRevisionUserId(0);
        $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        RevisionLog::setEvent($this, 'label_generated');
        $storage->save($this);
      }
      finally {
        static::$labelUpdateInProgress = FALSE;
      }
    }
  }

  /**
   * Checks whether stored workflow attributes changed on this save.
   */
  private function workflowAttributesChanged(): bool {
    $original = $this->original ?? NULL;
    if (!$original instanceof ApplicationInterface) {
      return FALSE;
    }

    return ($original->get('field_workflow_attributes')->value ?? NULL)
        !== ($this->get('field_workflow_attributes')->value ?? NULL);
  }

  /**
   * Checks whether the workflow status reference changed on this save.
   */
  private function workflowStatusChanged(): bool {
    $original = $this->original ?? NULL;
    if (!$original instanceof ApplicationInterface) {
      return FALSE;
    }

    $old_status_id = $original->get('field_workflow_status')->target_id ?? NULL;
    $new_status_id = $this->get('field_workflow_status')->target_id ?? NULL;
    return (string) $old_status_id !== (string) $new_status_id;
  }

  /**
   * Extracts CRM-tagged field values from submitted data.
   *
   * @param string $data_json
   *   The submitted field_data JSON string.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $schema_entity
   *   The referenced FormSchema entity.
   */
  protected function extractCrmData(string $data_json, FormSchemaInterface $schema_entity): void {
    $data = json_decode($data_json, TRUE);
    if (!is_array($data)) {
      return;
    }

    $schema_json = $schema_entity->get('field_schema')->value;
    if ($schema_json === NULL) {
      return;
    }

    $schema = json_decode($schema_json, TRUE);
    if (!is_array($schema)) {
      return;
    }

    $crm_data = ApplicationDataProtectionService::schemaRequiresProtectedIdentityLinkage($schema)
      ? []
      : static::filterCrmDataForApplicationStorage(static::extractCrmDataFromSubmittedData($data, $schema));

    $this->set(
          'field_crm_data',
          $crm_data === []
          ? NULL
          : json_encode($crm_data, JSON_UNESCAPED_UNICODE),
      );
  }

  /**
   * {@inheritdoc}
   */
  public function refreshCrmDataFromSchema(string $data, FormSchemaInterface $schema): static {
    $this->extractCrmData($data, $schema);
    $this->crmDataExplicitlyRefreshed = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicantCrmData(bool $includeEncryptedBankData = FALSE): array {
    $data_json = $this->getData();
    if ($data_json === NULL || $data_json === '') {
      return [];
    }

    $data = json_decode($data_json, TRUE);
    if (!is_array($data)) {
      return [];
    }

    $contact = $this->getProfileConsentRecord();
    $scoped = array_key_exists('fields', $contact);
    if (isset($contact['id']) && !$scoped) {
      return [];
    }
    $schema_entity = PublicSubmission::submittedSchema($this, $this->entityTypeManager());
    $submitted_revision = (int) $this->get('field_schema_revision_id')->value;
    if (!$schema_entity instanceof FormSchemaInterface
      || ($submitted_revision > 0 && (int) $schema_entity->getRevisionId() !== $submitted_revision)
      || $schema_entity->getVersion() !== $this->getSchemaVersion()) {
      return [];
    }
    if ($scoped && (
      (int) $schema_entity->getRevisionId() !== ($contact['formRevision'] ?? NULL)
      || $schema_entity->uuid() !== ($contact['formUuid'] ?? NULL)
    )) {
      return [];
    }

    $schema_json = $schema_entity->get('field_schema')->value;
    if ($schema_json === NULL) {
      return [];
    }

    $schema = json_decode($schema_json, TRUE);
    if (!is_array($schema)) {
      return [];
    }

    if (ApplicationDataProtectionService::schemaRequiresProtectedIdentityLinkage($schema)) {
      return [];
    }

    if ($scoped) {
      $bank = $this->getBankConsentRecord();
      $fields = array_merge(
        is_array($contact['fields']) ? $contact['fields'] : [],
        is_array($bank['fields'] ?? NULL) ? $bank['fields'] : [],
      );
      // Supplemental email is stored as the application's confirmation
      // address. The scoped extractor only reads it if the form offered it.
      $data['applicant_email'] ??= $this->getApplicantEmail();
      $crm_data = ProfileConsentFields::extract($schema, $data, $fields);
    }
    else {
      $crm_data = static::filterCrmDataForApplicantSync(static::extractCrmDataFromSubmittedData($data, $schema));
    }

    $encrypted_bank_data = $includeEncryptedBankData
      ? array_filter(array_intersect_key($crm_data, array_flip(self::BANK_CONSENT_CRM_KEYS)), 'is_array')
      : [];
    if ($encrypted_bank_data !== []) {
      /** @var \Drupal\beantragt_application\Service\ApplicationDataProtectionService $protection */
      $protection = \Drupal::service('beantragt_application.data_protection');
      $crm_data = array_replace($crm_data, $protection->revealData($encrypted_bank_data));
    }
    return $crm_data;
  }

  /**
   * Extracts CRM-tagged submitted values from decoded data and schema arrays.
   *
   * @param array $data
   *   Decoded application field_data.
   * @param array $schema
   *   Decoded form schema.
   * @param bool $supplemental_fallback
   *   Whether to accept synthetic identity outside the supplied schema.
   *
   * @return array
   *   CRM data keyed by schema crm_key. Schema fields marked sensitive are
   *   excluded before callers apply their surface-specific allowlist.
   */
  public static function extractCrmDataFromSubmittedData(array $data, array $schema, bool $supplemental_fallback = TRUE): array {
    $crm_data = [];
    $blocked_supplemental_crm_keys = [];
    $sections = is_array($schema['sections'] ?? NULL) ? $schema['sections'] : [];
    foreach ($sections as $section) {
      if (!is_array($section)) {
        continue;
      }
      $fields = is_array($section['fields'] ?? NULL) ? $section['fields'] : [];
      foreach ($fields as $field) {
        if (!is_array($field)) {
          continue;
        }
        $field_id = $field['id'] ?? NULL;
        $crm_key = $field['crm_key'] ?? NULL;
        if (self::isSensitiveSchemaField($field)) {
          if ($field_id === 'applicant_full_name' || $crm_key === 'person.full_name') {
            $blocked_supplemental_crm_keys['person.full_name'] = TRUE;
          }
          if ($field_id === 'applicant_email' || $crm_key === 'person.email') {
            $blocked_supplemental_crm_keys['person.email'] = TRUE;
          }
          continue;
        }
        if (is_string($field_id) && is_string($crm_key) && array_key_exists($field_id, $data)) {
          $value = $data[$field_id];
          if (($field['type'] ?? NULL) === 'address' && is_array($value)) {
            $formatted = trim((string) ($value['formatted'] ?? ''));
            if ($formatted !== '') {
              $crm_data[$crm_key] = $formatted;
            }
            if ($crm_key === 'person.address') {
              foreach ([
                'street' => 'person.street',
                'postcode' => 'person.postal_code',
                'city' => 'person.city',
              ] as $part => $part_crm_key) {
                $part_value = trim((string) ($value[$part] ?? ''));
                if ($part_value !== '' && !array_key_exists($part_crm_key, $crm_data)) {
                  $crm_data[$part_crm_key] = $part_value;
                }
              }
            }
            continue;
          }
          $crm_data[$crm_key] = $value;
        }
      }
    }

    return $supplemental_fallback ? static::addSupplementalIdentityToCrmData(
          $crm_data,
          $data,
          array_keys($blocked_supplemental_crm_keys),
      ) : $crm_data;
  }

  /**
   * Decides which profile consents a form schema can make effective.
   *
   * Single backend rule for the submission API and for any route that tells
   * the frontend whether to offer the consent checkboxes. Contact consent
   * needs at least one eligible applicant field. Bank consent additionally
   * needs a non-sensitive field with crm_key person.iban.
   *
   * @param array $schema
   *   Decoded form schema.
   *
   * @return array{contact: bool, bank: bool}
   *   Eligibility per consent kind.
   */
  public static function profileConsentEligibility(array $schema): array {
    $fields = ProfileConsentFields::byKind($schema);
    return ['contact' => $fields['contact'] !== [], 'bank' => $fields['bank'] !== []];
  }

  /**
   * Keeps only CRM values safe for the application summary copy.
   *
   * @param array $crm_data
   *   CRM data keyed by schema crm_key.
   *
   * @return array
   *   CRM data safe for application.field_crm_data.
   */
  public static function filterCrmDataForApplicationStorage(array $crm_data): array {
    return self::filterCrmDataToAllowedKeys($crm_data, static::APPLICATION_STORAGE_CRM_KEYS);
  }

  /**
   * Keeps only CRM values that may update applicant profiles.
   *
   * The profile keeps street and house number in one line ("Marktplatz 1").
   * A form that asks for them apart sends person.house_number next to
   * person.street; the number is appended to the street for the sync, so the
   * profile keeps its line and prefill can split it again. The key itself
   * never reaches the profile.
   *
   * @param array $crm_data
   *   CRM data keyed by schema crm_key.
   *
   * @return array
   *   CRM data safe for applicant profile synchronization.
   */
  public static function filterCrmDataForApplicantSync(array $crm_data): array {
    return self::filterCrmDataToAllowedKeys(
      self::foldHouseNumberIntoStreet($crm_data),
      static::APPLICANT_SYNC_CRM_KEYS,
    );
  }

  /**
   * Appends person.house_number to person.street when it is not there yet.
   *
   * A number without a street adds nothing; a street that already ends with
   * the number (the citizen typed both into the street field) stays as is.
   *
   * @param array $crm_data
   *   CRM data keyed by schema crm_key.
   *
   * @return array
   *   CRM data with the house number folded into the street.
   */
  private static function foldHouseNumberIntoStreet(array $crm_data): array {
    $street = $crm_data['person.street'] ?? NULL;
    $number = $crm_data[ApplicantContactKeys::HOUSE_NUMBER] ?? NULL;
    if (is_int($number)) {
      $number = (string) $number;
    }
    if (!is_string($street) || !is_string($number)) {
      return $crm_data;
    }
    $street = trim($street);
    $number = trim($number);
    if ($street === '' || $number === '') {
      return $crm_data;
    }
    $combined = ApplicantContactKeys::streetLine($street, $number);
    if ($combined !== $street) {
      $crm_data['person.street'] = $combined;
    }
    return $crm_data;
  }

  /**
   * Filters CRM data to an allowlist and normalizes the email value.
   *
   * @param array $crm_data
   *   CRM data keyed by schema crm_key.
   * @param string[] $allowed_keys
   *   Allowed CRM keys for the target storage surface.
   *
   * @return array
   *   Filtered CRM data.
   */
  private static function filterCrmDataToAllowedKeys(array $crm_data, array $allowed_keys): array {
    $allowed = array_flip($allowed_keys);
    $filtered = array_intersect_key($crm_data, $allowed);
    return self::normalizeCrmEmailData($filtered);
  }

  /**
   * Determines whether a schema field is marked as sensitive.
   */
  public static function isSensitiveSchemaField(array $field): bool {
    $sensitive = $field['sensitive'] ?? FALSE;
    if (is_bool($sensitive)) {
      return $sensitive;
    }
    if (is_string($sensitive) || is_int($sensitive)) {
      return filter_var($sensitive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === TRUE;
    }
    return FALSE;
  }

  /**
   * Adds frontend supplemental applicant fields to CRM data when needed.
   *
   * Public chat and low-friction forms may collect applicant identity outside
   * the stored form schema. Those values are stored in field_data under stable
   * synthetic keys and should still feed the applicant profile.
   *
   * @param array $crm_data
   *   CRM data extracted from schema-tagged fields.
   * @param array $application_data
   *   Submitted application field_data.
   * @param string[] $blocked_supplemental_crm_keys
   *   Supplemental CRM keys blocked by sensitive schema fields.
   *
   * @return array
   *   CRM data enriched with supplemental applicant identity.
   */
  public static function addSupplementalIdentityToCrmData(array $crm_data, array $application_data, array $blocked_supplemental_crm_keys = []): array {
    $crm_data = self::normalizeCrmEmailData($crm_data);
    $blocked_supplemental_crm_keys = array_fill_keys($blocked_supplemental_crm_keys, TRUE);

    if (!array_key_exists('person.full_name', $crm_data)
          && !array_key_exists('person.full_name', $blocked_supplemental_crm_keys)
      ) {
      $full_name = self::normalizedSupplementalIdentityValue(
            $application_data['applicant_full_name'] ?? NULL,
            self::SUPPLEMENTAL_FULL_NAME_MAX_LENGTH,
        );
      if ($full_name !== NULL) {
        $crm_data['person.full_name'] = $full_name;
      }
    }

    if (!array_key_exists('person.email', $crm_data)
          && !array_key_exists('person.email', $blocked_supplemental_crm_keys)
      ) {
      $email = self::normalizedSupplementalIdentityValue(
            $application_data['applicant_email'] ?? NULL,
            self::SUPPLEMENTAL_EMAIL_MAX_LENGTH,
        );
      $normalized_email = self::normalizedCrmEmail($email);
      if ($normalized_email !== NULL) {
        $crm_data['person.email'] = $normalized_email;
      }
    }

    return $crm_data;
  }

  /**
   * Normalizes a synthetic applicant identity value from field_data.
   */
  private static function normalizedSupplementalIdentityValue(mixed $value, int $max_length): ?string {
    if (!is_string($value)) {
      return NULL;
    }

    $normalized = trim($value);
    if ($normalized === '') {
      return NULL;
    }
    if (mb_strlen($normalized) > $max_length) {
      return NULL;
    }
    return $normalized;
  }

  /**
   * Normalizes application-safe CRM email data.
   */
  private static function normalizeCrmEmailData(array $crm_data): array {
    if (!array_key_exists('person.email', $crm_data)) {
      return $crm_data;
    }

    $email = self::normalizedCrmEmail($crm_data['person.email']);
    if ($email === NULL) {
      unset($crm_data['person.email']);
      return $crm_data;
    }

    $crm_data['person.email'] = $email;
    return $crm_data;
  }

  /**
   * Normalizes a CRM email value or returns NULL for invalid input.
   */
  private static function normalizedCrmEmail(mixed $value): ?string {
    $email = self::normalizedSupplementalIdentityValue(
          $value,
          self::SUPPLEMENTAL_EMAIL_MAX_LENGTH,
      );
    if ($email === NULL || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return NULL;
    }

    return strtolower($email);
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(): ?string {
    return $this->get('field_data')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setData(string $data): static {
    $this->set('field_data', $data);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCrmData(): ?string {
    return $this->get('field_crm_data')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflowStatus(): string {
    return WorkflowStatusResolver::machineName($this);
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflowStatusId(): ?int {
    if ($this->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }
    $target_id = (int) $this->get('field_workflow_status')->target_id;
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setWorkflowStatus(string $status): static {
    // Backward-compatible string API: resolve the machine name to the
    // workflow_status entity owned by this application's organisation.
    $resolved = $this->resolveStatusEntityIdByMachineName($status);
    if ($resolved !== NULL) {
      $this->set('field_workflow_status', ['target_id' => $resolved]);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setWorkflowStatusId(int $status_id): static {
    $this->set('field_workflow_status', ['target_id' => $status_id]);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function allowWorkflowStatusReset(): static {
    $this->workflowStatusResetAllowed = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isWorkflowStatusResetAllowed(): bool {
    return $this->workflowStatusResetAllowed;
  }

  /**
   * {@inheritdoc}
   */
  public function markSystemStatusChange(string $reason): static {
    $this->systemStatusChange = $reason;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSystemStatusChange(): ?string {
    return $this->systemStatusChange;
  }

  /**
   * {@inheritdoc}
   */
  public function clearSystemStatusChange(): static {
    $this->systemStatusChange = NULL;
    return $this;
  }

  /**
   * Resolves a status machine name to a workflow_status id for this workspace.
   *
   * @param string $machine_name
   *   The status machine name (e.g. "returned").
   *
   * @return int|null
   *   The matching workflow_status entity id, or NULL if none is found.
   */
  protected function resolveStatusEntityIdByMachineName(string $machine_name): ?int {
    if ($machine_name === ''
          || !\Drupal::entityTypeManager()->hasDefinition('workflow_status')
      ) {
      return NULL;
    }

    $workflow_group_id = NULL;
    if (function_exists('_beantragt_application_resolve_workflow_group_id')) {
      $workflow_group_id = _beantragt_application_resolve_workflow_group_id($this);
    }
    if ($workflow_group_id === NULL) {
      return NULL;
    }

    $ids = \Drupal::entityTypeManager()->getStorage('workflow_status')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $workflow_group_id)
      ->condition('field_machine_name', $machine_name)
      ->range(0, 1)
      ->execute();

    return empty($ids) ? NULL : (int) reset($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceNumber(): ?string {
    return $this->get('field_reference_number')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaVersion(): int {
    return (int) $this->get('field_schema_version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOrigin(): ?string {
    if (!$this->hasField('field_origin')) {
      return NULL;
    }
    $origin = $this->get('field_origin')->value;
    return is_string($origin) && $origin !== '' ? $origin : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicantEmail(): ?string {
    return $this->get('field_applicant_email')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSubmitStatusUrl(?string $url): static {
    $this->submitStatusUrl = $url !== NULL && trim($url) !== '' ? trim($url) : NULL;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmitStatusUrl(): ?string {
    return $this->submitStatusUrl;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmailConfirmed(): bool {
    return !$this->hasField('field_email_confirmed')
      || $this->get('field_email_confirmed')->isEmpty()
      || (bool) $this->get('field_email_confirmed')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDeliveryRoute(): string {
    $route = $this->hasField('field_delivery_route') ? (string) ($this->get('field_delivery_route')->value ?? '') : '';
    return in_array($route, DeliveryRoute::ROUTES, TRUE) ? $route : DeliveryRoute::PORTAL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDeliveryRecord(): array {
    if (!$this->hasField('field_delivery_record') || $this->get('field_delivery_record')->isEmpty()) {
      return [];
    }
    $record = json_decode((string) $this->get('field_delivery_record')->value, TRUE);
    if (!is_array($record) || !array_is_list($record)) {
      return [];
    }
    return array_values(array_filter($record, static fn (mixed $event): bool => is_array($event) && is_string($event['event'] ?? NULL)));
  }

  /**
   * {@inheritdoc}
   */
  public function hasProfileConsent(): bool {
    return $this->consentFlag('field_profile_consent');
  }

  /**
   * {@inheritdoc}
   */
  public function getProfileConsentRecord(): array {
    return $this->consentRecord('field_profile_consent', 'field_profile_consent_record');
  }

  /**
   * {@inheritdoc}
   */
  public function hasBankConsent(): bool {
    return $this->consentFlag('field_bank_consent');
  }

  /**
   * {@inheritdoc}
   */
  public function getBankConsentRecord(): array {
    return $this->consentRecord('field_bank_consent', 'field_bank_consent_record');
  }

  /**
   * Reads a consent flag; a missing or empty field means no consent.
   */
  private function consentFlag(string $field_name): bool {
    return $this->hasField($field_name)
      && !$this->get($field_name)->isEmpty()
      && (bool) $this->get($field_name)->value;
  }

  /**
   * Decodes a consent record, only while its consent flag is set.
   *
   * @return array<string, mixed>
   *   The decoded record or an empty array.
   */
  private function consentRecord(string $flag_field, string $record_field): array {
    if (!$this->consentFlag($flag_field)
      || !$this->hasField($record_field)
      || $this->get($record_field)->isEmpty()) {
      return [];
    }
    $record = json_decode((string) $this->get($record_field)->value, TRUE);
    return is_array($record) && !array_is_list($record) ? $record : [];
  }

  /**
   * Returns the four consent base fields for install and update hooks.
   *
   * @return array<string, \Drupal\Core\Field\BaseFieldDefinition>
   *   Field definitions keyed by field name.
   */
  public static function consentBaseFieldDefinitions(): array {
    $fields = [];
    foreach ([
      'field_profile_consent' => 'contact data',
      'field_bank_consent' => 'bank details',
    ] as $field_name => $subject) {
      $fields[$field_name] = BaseFieldDefinition::create('boolean')
        ->setLabel(t('Consent to reuse @subject', ['@subject' => $subject]))
        ->setDescription(t('Whether the citizen consented to storing @subject in the applicant profile. Only set when the consent was effective at submission.', ['@subject' => $subject]))
        ->setRevisionable(TRUE)
        ->setDefaultValue(FALSE)
        ->setReadOnly(TRUE);
      $fields[$field_name . '_record'] = BaseFieldDefinition::create('string_long')
        ->setLabel(t('Consent record for @subject', ['@subject' => $subject]))
        ->setDescription(t('JSON proof of the consent: time, locale, text version, rendered text, text hash, scope and source.'))
        ->setRevisionable(TRUE)
        ->setReadOnly(TRUE);
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type)
      + static::ownerBaseFieldDefinitions($entity_type)
      + static::publishedBaseFieldDefinitions($entity_type)
      + static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('Auto-generated label: "Application #[id] - [schema title]".'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions(
              'view', [
                'label' => 'hidden',
                'type' => 'string',
                'weight' => -10,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_origin'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Origin'))->setSetting('allowed_values', ['citizen' => 'citizen', 'bulk' => 'bulk'])
      ->setDefaultValue('citizen')->setRevisionable(TRUE)->setReadOnly(TRUE);

    // Server-managed: written by the public and bulk intake and the staff
    // delivery API only (field access denies every API edit).
    $fields['field_delivery_route'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Delivery route'))
      ->setDescription(t('How the authority answers: in the citizen portal, by post or into the BundID postbox.'))
      ->setSetting('allowed_values', array_combine(DeliveryRoute::ROUTES, DeliveryRoute::ROUTES))
      ->setDefaultValue(DeliveryRoute::PORTAL)->setRevisionable(TRUE)->setReadOnly(TRUE);
    $fields['field_delivery_record'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Delivery route record'))
      ->setDescription(t('JSON list of route events: route_chosen at intake, route_changed per staff change.'))
      ->setRevisionable(TRUE)->setReadOnly(TRUE);

    $fields['field_schema'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Form Schema'))
      ->setDescription(t('Reference to the form_schema entity this application is for.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'form_schema')
      ->setDisplayOptions(
              'form', [
                'type' => 'entity_reference_autocomplete',
                'weight' => -8,
                'settings' => [
                  'match_operator' => 'CONTAINS',
                  'size' => '60',
                  'placeholder' => '',
                ],
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'above',
                'type' => 'entity_reference_label',
                'weight' => -8,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_schema_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Submitted schema revision'))
      ->setDescription(t('Immutable default form revision captured at creation for the submitted schema version.'))
      ->setRevisionable(TRUE)
      ->setReadOnly(TRUE);

    $fields['field_receipt_form_title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Submitted public form title'))
      ->setSetting('max_length', 255)
      ->setReadOnly(TRUE)
      ->setInternal(TRUE);

    $fields['field_schema_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Schema Version'))
      ->setDescription(t('The version of the schema that was used at application time.'))
      ->setRevisionable(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'number_integer',
                'weight' => -7,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Application Data'))
      ->setDescription(t('JSON blob containing all submitted field values (key-value pairs matching schema field IDs).'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions(
              'form', [
                'type' => 'string_textarea',
                'weight' => -5,
                'settings' => [
                  'rows' => 20,
                ],
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'above',
                'type' => 'basic_string',
                'weight' => -5,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_crm_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('CRM Data'))
      ->setDescription(t('JSON blob containing CRM-tagged field values extracted from the application.'))
      ->setRevisionable(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'above',
                'type' => 'basic_string',
                'weight' => -4,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_proof_assignments'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Confirmed proof assignments'))
      ->setRevisionable(TRUE)
      ->setInternal(TRUE)
      ->setReadOnly(TRUE);

    $fields['field_workflow_attributes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Workflow attributes'))
      ->setDescription(t('JSON object containing internal process attributes captured for the current workflow revision.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions(
              'form', [
                'type' => 'string_textarea',
                'weight' => -3,
                'settings' => [
                  'rows' => 6,
                ],
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'above',
                'type' => 'basic_string',
                'weight' => -3,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_workflow_status'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Workflow Status'))
      ->setDescription(t("The processing status of this application. References a per-group workflow_status entity. The default is resolved at create time from the application's effective workspace in presave."))
      ->setRequired(FALSE)
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'workflow_status')
      ->setSetting('handler', 'default')
      ->setCardinality(1)
      ->setDisplayOptions(
              'form', [
                'type' => 'entity_reference_autocomplete',
                'weight' => 0,
                'settings' => [
                  'match_operator' => 'CONTAINS',
                  'size' => '60',
                  'placeholder' => '',
                ],
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'entity_reference_label',
                'weight' => 0,
              ]
          )
      ->setDisplayConfigurable('view', TRUE)
      ->addConstraint('ApplicationStatusInGroup');

    $fields['field_due_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Due date'))
      ->setDescription(t('Internal deadline or follow-up date for back-office processing.'))
      ->setRevisionable(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions(
              'form', [
                'type' => 'datetime_default',
                'weight' => 1,
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'datetime_default',
                'weight' => 1,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_applicant_email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Applicant Email'))
      ->setDescription(t('Optional email address of the applicant.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions(
              'form', [
                'type' => 'email_default',
                'weight' => 2,
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'email_mailto',
                'weight' => 2,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_applicant_email_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Applicant Email Lookup Hash'))
      ->setDescription(t('Keyed lookup hash for matching applicant email addresses without storing them as cleartext.'))
      ->setRevisionable(TRUE)
      ->setReadOnly(TRUE)
      ->setSetting('max_length', 64);

    $fields['field_reference_number'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Reference Number'))
      ->setDescription(t('Auto-generated reference number from configurable pattern.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->setReadOnly(TRUE)
      ->addConstraint('UniqueField')
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'string',
                'weight' => -9,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_public_status_token_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Public Status Token Hash'))
      ->setDescription(t('SHA-256 hash of the random public status token. The plaintext token is only returned once after submission.'))
      ->setSetting('max_length', 64);

    $fields['field_email_confirmed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Applicant email confirmed'))
      ->setDescription(t('Whether the applicant email has completed double opt-in.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setReadOnly(TRUE);

    $fields['field_email_confirmation_token_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Email confirmation token hash'))
      ->setDescription(t('SHA-256 hash of the active or claimed email confirmation token.'))
      ->setSetting('max_length', 64)
      ->setReadOnly(TRUE);

    $fields['field_email_confirmation_expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Email confirmation expires'))
      ->setDescription(t('Unix timestamp when the active email confirmation token expires.'))
      ->setReadOnly(TRUE);

    $fields['field_email_confirmed_time'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Applicant email confirmed time'))
      ->setDescription(t('Unix timestamp when the applicant email was confirmed.'))
      ->setReadOnly(TRUE);

    $fields += static::consentBaseFieldDefinitions();

    $fields['uid']
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created the application.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions(
              'form', [
                'type' => 'entity_reference_autocomplete',
                'weight' => 15,
                'settings' => [
                  'match_operator' => 'CONTAINS',
                  'size' => '60',
                  'placeholder' => '',
                ],
              ]
          )
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'author',
                'weight' => 15,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setDefaultValue(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions(
              'form', [
                'type' => 'boolean_checkbox',
                'weight' => 20,
                'settings' => [
                  'display_label' => TRUE,
                ],
              ]
          )
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the application was created.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions(
              'view', [
                'label' => 'inline',
                'type' => 'timestamp',
                'weight' => 20,
              ]
          )
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the application was last edited.'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}
