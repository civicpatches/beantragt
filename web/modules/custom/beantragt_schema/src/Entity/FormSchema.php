<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Entity;

use Drupal\beantragt_schema\FormCompilerOrigin;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Form Schema entity.
 *
 * Stores AI-generated form definitions with JSON schema data.
 *
 * @ContentEntityType(
 *   id = "form_schema",
 *   label = @Translation("Form Schema"),
 *   label_collection = @Translation("Form Schemas"),
 *   label_singular = @Translation("form schema"),
 *   label_plural = @Translation("form schemas"),
 *   label_count = @PluralTranslation(
 *     singular = "@count form schema",
 *     plural = "@count form schemas",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\beantragt_schema\FormSchemaListBuilder",
 *     "form" = {
 *       "default" = "Drupal\beantragt_schema\Form\FormSchemaForm",
 *       "add" = "Drupal\beantragt_schema\Form\FormSchemaForm",
 *       "edit" = "Drupal\beantragt_schema\Form\FormSchemaForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\beantragt_schema\FormSchemaAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "form_schema",
 *   revision_table = "form_schema_revision",
 *   show_revision_ui = TRUE,
 *   admin_permission = "administer form_schema entities",
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
 *     "canonical" = "/admin/content/form-schemas/{form_schema}",
 *     "add-form" = "/admin/content/form-schemas/add",
 *     "edit-form" = "/admin/content/form-schemas/{form_schema}/edit",
 *     "delete-form" = "/admin/content/form-schemas/{form_schema}/delete",
 *     "collection" = "/admin/content/form-schemas",
 *   },
 *   constraints = {
 *     "ValidFormSchemaDefinition" = {},
 *     "FormSchemaWorkspaceScope" = {},
 *     "FormSchemaPublishPermission" = {},
 *   },
 * )
 */
class FormSchema extends ContentEntityBase implements FormSchemaInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;
  use RevisionLogEntityTrait;

  /**
   * Maximum supported regex pattern length for field validation rules.
   */
  protected const MAX_VALIDATION_PATTERN_LENGTH = 160;

  /**
   * Machine-name shape used for schema, section, and field identifiers.
   */
  protected const MACHINE_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

  /**
   * Maximum length shared by Drupal, JSON Schema, and Nuxt machine IDs.
   */
  protected const MAX_MACHINE_ID_LENGTH = 100;

  /**
   * Input shapes that may drive citizen precheck dialogue.
   */
  protected const PRECHECK_DIALOGUE_SAFE_FIELD_TYPES = [
    'text',
    'textarea',
    'number',
    'date',
    'select',
    'radio',
    'checkbox',
  ];

  /**
   * Identifier-like formats that must never drive citizen precheck dialogue.
   */
  protected const PRECHECK_BLOCKED_FIELD_FORMATS = [
    'postal_code',
    'iban',
    'bic',
    'tax_id',
    'reference_number',
    'url',
  ];

  /**
   * Personal-data concepts that citizen precheck questions must never request.
   */
  protected const PRECHECK_PERSONAL_DATA_QUESTION_PATTERN = '/(?:\b(?:wie|welches)\s+alt(?:er)?\s+(?:sind|bist|haben|hast)\s+(?:sie|du)\b|\bwann\s+(?:wurden\s+sie|wurdest\s+du)\s+geboren\b|\bsind\s+sie\s+verheiratet\b|\bbist\s+du\s+verheiratet\b|\b(?:ihr|dein|euer)\s+(?:lebens)?alter\b|\b(?:how\s+old\s+are\s+you|what\s+is\s+your\s+age|when\s+were\s+you\s+born|are\s+you\s+married|(?:your|applicant\'?s)\s+age)\b|\b(?:name\w*|vorname\w*|nachname\w*|geburts(?:datum|ort)?\w*|geschlecht\w*|familienstand\w*|nationalit[aä]t\w*|staatsangeh[oö]rigkeit\w*|einkommen\w*|verm[oö]gen\w*|gesundheit\w*|behinder\w*|schwanger\w*|religion\w*|ethni\w*|herkunft\w*|rasse\w*|politisch\w*|gewerkschaft\w*|biometr\w*|genetisch\w*|sexuell\w*|straf\w*|vorstraf\w*|anschrift\w*|adresse\w*|postleitzahl\w*|e-?mail\w*|telefon\w*|iban\w*|konto\w*|steuer(?:identifikationsnummer|-?id|nummer)?\w*|kennzeichen\w*|aktenzeichen\w*|vorgangsnummer\w*|antragsnummer\w*|kundennummer\w*|passnummer\w*|ausweisnummer\w*|sozialversicherungsnummer\w*|rentenversicherungsnummer\w*|krankenversichertennummer\w*|versicherungsnummer\w*|birth(?:date|place)?\w*|gender\w*|marital\s+status\w*|nationality\w*|citizenship\w*|income\w*|wealth\w*|health\w*|disab\w*|pregnan\w*|religio\w*|ethni\w*|race\w*|politic\w*|union\w*|biometr\w*|genetic\w*|sexual\w*|criminal\w*|conviction\w*|address\w*|postcode\w*|postal\s+code\w*|e-?mail\w*|phone\w*|iban\w*|account\w*|tax\s*(?:id|number)\w*|licen[cs]e\s+plate\w*|case\s+number\w*|reference\s+number\w*|application\s+number\w*|customer\s+number\w*|passport\s+number\w*|identity\s+document\s+number\w*|social\s+security\s+number\w*|national\s+insurance\s+number\w*|insurance\s+number\w*)\b)/iu';

  /**
   * Personal-data concepts encoded in criterion metadata.
   */
  protected const PRECHECK_PERSONAL_DATA_METADATA_PATTERN = '/(?:\b(?:applicant|applicant\'?s|antragsteller|antragstellende|citizen|bürger|buerger|person|user)[ _\.\/:-]*(?:age|alter|birth|geburt|marital|familienstand|gender|geschlecht|nationality|nationalität|nationalitaet|citizenship|staatsangehörigkeit|staatsangehoerigkeit|income|einkommen|health|gesundheit|address|anschrift|adresse|contact|kontakt)\b|\b(?:age|alter|birth|geburt|marital|familienstand|gender|geschlecht|nationality|nationalität|nationalitaet|citizenship|staatsangehörigkeit|staatsangehoerigkeit|income|einkommen|health|gesundheit)[ _\.\/:-]*(?:of|von)?[ _\.\/:-]*(?:applicant|antragsteller|citizen|bürger|buerger|person|user)\b)/iu';

  /**
   * Natural-language paraphrases of disallowed personal-data questions.
   */
  protected const PRECHECK_PERSONAL_DATA_PARAPHRASE_PATTERN = '/(?:\bin\s+welchem\s+jahr\s+(?:sind|wurden)\s+sie\s+geboren\b|\bwelcher\s+altersgruppe\b|\bleben\s+sie\s+in\s+einer\s+ehe\b|\bwelche\s+staatsbürgerschaft\b|\baus\s+welchem\s+land\s+stammen\s+sie\b|\bwhat\s+year\s+were\s+you\s+born\b|\bwhich\s+age\s+group\b|\bdo\s+you\s+live\s+in\s+a\s+marriage\b|\bwhat\s+citizenship\b|\bwhich\s+country\s+are\s+you\s+from\b)/iu';

  /**
   * Instruction-shaped text that must stay data and never reach the model.
   */
  protected const PRECHECK_INSTRUCTION_INJECTION_PATTERN = '/(?:\b(?:ignoriere|ignorieren|missachte|missachten|vergiss|überschreibe|ueberschreibe)\b.{0,80}\b(?:vorherige|bisherige|system|entwickler)\b.{0,50}\b(?:anweisung(?:en)?|nachricht(?:en)?|prompt)\b|\b(?:ignore|disregard|forget|override)\b.{0,80}\b(?:previous|prior|system|developer)\b.{0,50}\b(?:instructions?|messages?|prompts?)\b|\b(?:systemprompt|entwicklernachricht|developer\s+message|system\s+prompt)\b)/iu';

  /**
   * Field types supported by the form renderer and schema editor.
   */
  protected const ALLOWED_FIELD_TYPES = [
    'text',
    'email',
    'date',
    'tel',
    'checkbox',
    'textarea',
    'number',
    'select',
    'radio',
    'address',
    'file',
  ];

  /**
   * Format hints published by the bundled FormSchema specification.
   */
  protected const ALLOWED_FIELD_FORMATS = [
    'postal_code',
    'iban',
    'bic',
    'tax_id',
    'currency',
    'reference_number',
    'url',
  ];

  /**
   * Supported public submission authentication modes.
   */
  protected const ALLOWED_AUTH_MODES = [
    'anonymous',
    'optional',
    'required',
  ];

  /**
   * Supported public submission authentication providers.
   */
  protected const ALLOWED_AUTH_PROVIDERS = [
    'passwordless',
    'bundid',
  ];

  /**
   * Supported supplemental identity collection requirements.
   */
  protected const ALLOWED_IDENTITY_REQUIREMENTS = [
    'required',
    'optional',
    'none',
  ];

  /**
   * Privacy classifications accepted in form schema fields.
   */
  protected const ALLOWED_PRIVACY_KINDS = [
    'not_personal',
    'contact',
    'identity',
    'financial',
    'special_category',
    'criminal_offense',
    'other_personal',
  ];

  /**
   * CRM keys that make a field personal data by policy.
   */
  protected const PERSONAL_DATA_POLICY_CRM_KEYS = [
    'person.full_name',
    'person.first_name',
    'person.last_name',
    'person.birthdate',
    'person.address',
    'person.street',
    'person.house_number',
    'person.postal_code',
    'person.city',
    'person.phone',
    'person.email',
    'person.iban',
    'person.account_holder',
    'person.gender',
    'person.nationality',
    'person.marital_status',
    'person.tax_id',
    'company.contact_person',
  ];

  /**
   * Supported application processing modes.
   */
  protected const ALLOWED_PROCESSING_MODES = [
    'self_contained',
    'pass_through',
  ];

  /**
   * Supported delivery adapters.
   */
  protected const ALLOWED_DELIVERY_ADAPTERS = [
    'mock',
    'webhook',
    'fit_connect',
  ];

  /**
   * Keys supported in the schema-level delivery policy.
   */
  protected const ALLOWED_DELIVERY_KEYS = [
    'processingMode',
    'adapter',
  ];

  /**
   * AI egress policies supported by attachment analysis profiles.
   */
  protected const ALLOWED_ATTACHMENT_ANALYSIS_EGRESS_POLICIES = [
    'no_ai',
    'local_only',
    'sovereign_only',
    'external_redacted',
    'external_allowed',
  ];

  /**
   * Model classes supported by attachment analysis profiles.
   */
  protected const ALLOWED_ATTACHMENT_ANALYSIS_MODEL_CLASSES = [
    'local_ocr',
    'local_vision',
    'ollama',
    'openai_compatible',
    'openai',
    'claude_sonnet',
  ];

  /**
   * Every key an attachment analysis profile may carry.
   *
   * Mirrors FORM_ATTACHMENT_ANALYSIS_PROFILE_KEYS in the Nuxt app and the
   * analysis properties of schema/form_schema.schema.json; parity tests on
   * both sides keep the three lists aligned.
   */
  public const ATTACHMENT_ANALYSIS_PROFILE_KEYS = [
    'enabled',
    'version',
    'expectedDocumentType',
    'promptSuggestion',
    'citizenPrompt',
    'staffPrompt',
    'redaction',
    'resultSchema',
    'modelClass',
    'egressPolicy',
  ];

  /**
   * Profile keys holding authoring text, each length-checked on save.
   *
   * A parity test keeps this list equal to the string properties with a
   * maxLength in schema/form_schema.schema.json.
   */
  public const ATTACHMENT_ANALYSIS_TEXT_KEYS = [
    'expectedDocumentType',
    'promptSuggestion',
    'citizenPrompt',
    'staffPrompt',
  ];

  /**
   * Redaction modes before a sovereign or local analysis run.
   *
   * A missing key means 'none' at runtime.
   */
  protected const ALLOWED_ATTACHMENT_ANALYSIS_REDACTION_MODES = [
    'none',
    'when_possible',
  ];

  /**
   * Max string length for attachment analysis authoring hints.
   */
  protected const MAX_ATTACHMENT_ANALYSIS_TEXT_LENGTH = 5000;

  /**
   * Max serialized JSON length for attachment analysis result schemas.
   */
  protected const MAX_ATTACHMENT_ANALYSIS_RESULT_SCHEMA_LENGTH = 10000;

  /**
   * Egress policies that may carry personal, purpose-bound analysis values.
   */
  protected const PURPOSE_BOUND_ANALYSIS_EGRESS_POLICIES = ['sovereign_only', 'local_only'];

  /**
   * Maximum number of local assistant sources selectable for one form.
   */
  protected const MAX_CONTEXT_SOURCES = 24;

  /**
   * Context source kinds supported by the stored form schema contract.
   */
  protected const ALLOWED_CONTEXT_SOURCE_TYPES = [
    'public_document',
    'form_attachment',
  ];

  /**
   * Assistant surfaces that may opt into a selected context source.
   */
  protected const ALLOWED_CONTEXT_SOURCE_USAGES = [
    'editor',
    'citizen_assistant',
  ];

  /**
   * Metadata keys accepted for one context source selection.
   */
  protected const ALLOWED_CONTEXT_SOURCE_KEYS = [
    'type',
    'id',
    'title',
    'placement',
    'version',
    'changed',
    'enabledFor',
    'requiredCitation',
  ];

  /**
   * Maximum number of reviewed factual criteria in one form precheck.
   */
  protected const MAX_PRECHECK_CRITERIA = 32;

  /**
   * Supported runtime fact types for deterministic citizen prechecks.
   */
  protected const ALLOWED_PRECHECK_FACT_TYPES = [
    'boolean',
    'number',
    'text',
    'enum',
  ];

  /**
   * Supported effects of one factual precheck criterion.
   */
  protected const ALLOWED_PRECHECK_AFFECTS = [
    'procedure_fit',
    'obligation',
    'both',
  ];

  /**
   * Review states for generated or manually reviewed precheck criteria.
   */
  protected const ALLOWED_PRECHECK_REVIEW_STATUSES = [
    'suggested',
    'approved',
    'rejected',
  ];

  /**
   * Permission required to configure pass-through or external delivery.
   */
  protected const ADMINISTER_DELIVERY_POLICIES_PERMISSION = 'administer application delivery policies';

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    /** @var \Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope $workspace_scope */
    $workspace_scope = \Drupal::service('beantragt_schema.workspace_scope');
    $scope_violation = $workspace_scope->violationMessage($this, \Drupal::currentUser());
    if ($scope_violation !== NULL) {
      throw new EntityStorageException($scope_violation);
    }

    $this->assertLifecycleMutationIsCurrent($storage);
    $this->createLifecycleStatusRevision($storage);

    $schema_value = $this->get('field_schema')->value;
    if ($schema_value !== NULL) {
      self::validateFormSchemaJson($schema_value);
    }
    if ($schema_value !== NULL) {
      $original_schema_value = NULL;
      if (!$this->isNew()) {
        $original = $this->getOriginal() ?? $storage->loadUnchanged($this->id());
        if ($original instanceof FormSchemaInterface) {
          $original_schema_value = $original->get('field_schema')->value;
        }
      }
      self::validateFormSchemaRootId(
        $schema_value,
        $original_schema_value,
        $this->isNew(),
        $this->uuid(),
      );
      // Validate new or changed definitions and every published definition.
      // A payload stored under an older contract must still be downgradeable
      // to draft, but it must never bypass validation on publication.
      if ($schema_value !== $original_schema_value
        || ($this->get('field_workflow_status')->value ?? NULL) === 'published') {
        self::validateFormSchemaDefinitionDetails($schema_value);
      }
      if ($schema_value !== $original_schema_value) {
        self::validateConditionValues($schema_value, $original_schema_value);
      }
      if (($this->get('field_workflow_status')->value ?? NULL) === 'published') {
        $schema_value = self::normalizeSchemaPrivacyKinds($schema_value);
        $this->set('field_schema', $schema_value);
      }
      self::validateDeliveryPolicyPermission($schema_value, $original_schema_value);
      if (($this->get('field_workflow_status')->value ?? NULL) === 'published') {
        self::validatePublishedFormSchemaShape($schema_value);
        self::validatePublishableFormSchemaDefinition($schema_value);
      }
    }
    elseif (($this->get('field_workflow_status')->value ?? NULL) === 'published') {
      self::validatePublishedFormSchemaShape(NULL);
    }
    $this->updateAiReviewPending($storage, $schema_value);
    $workflow_definition = $this->get('field_workflow_definition')->value ?? NULL;
    if ($workflow_definition !== NULL) {
      if (!json_validate($workflow_definition)) {
        throw new \InvalidArgumentException('The field_workflow_definition value is not valid JSON.');
      }
      $decoded_workflow = json_decode((string) $workflow_definition, TRUE);
      if (!is_array($decoded_workflow) || array_is_list($decoded_workflow)) {
        throw new \InvalidArgumentException('The field_workflow_definition value must be a JSON object.');
      }
    }

    // Auto-increment version when the schema or workflow changes. Compare
    // decoded JSON so that cosmetic re-encoding (whitespace, key order) by a
    // structured editor does not bump the version on no-op saves. A workspace
    // change keeps the content version, like a lifecycle status change: the
    // citizen fills the same form, and transfer rules covering this version
    // stay valid when the form moves into an organisation. It still needs a
    // revision for the concurrency guard and the assistant context.
    if (!$this->isNew()) {
      $original = $this->getOriginal() ?? $storage->loadUnchanged($this->id());
      if ($original instanceof FormSchemaInterface) {
        $schema_changed = !self::jsonEquals($original->get('field_schema')->value, $schema_value);
        $workflow_changed = !self::jsonEquals($original->get('field_workflow_definition')->value, $workflow_definition);
        if ($schema_changed || $workflow_changed) {
          $this->bumpVersionAndCreateRevision(
            $original,
            'Schema or workflow definition changed.',
          );
        }
        elseif (self::workspaceScopeChanged($original, $this)) {
          $this->forceRevision('Workspace changed.');
        }
      }
    }
  }

  /**
   * Records compiler provenance on creation and clears it on publication.
   */
  private function updateAiReviewPending(EntityStorageInterface $storage, ?string $schema_value): void {
    if ($this->isNew()) {
      $pending = FormCompilerOrigin::matches(
        json_decode($schema_value ?? '', TRUE),
        $this->getSourceFilename(),
      );
    }
    else {
      $original = $this->getOriginal() ?? $storage->loadUnchanged($this->id());
      $pending = $original instanceof FormSchemaInterface
        && (bool) $original->get('field_ai_review_pending')->value;
    }
    $published = $this->isPublished() && $this->getSchemaStatus() === 'published';
    $this->set('field_ai_review_pending', $pending && !$published);
  }

  /**
   * Serializes lifecycle-sensitive updates and rejects stale entity snapshots.
   *
   * The row lock is held by SqlContentEntityStorage's surrounding save
   * transaction. This prevents a workflow PATCH and a publish operation from
   * both passing their preflight checks and then overwriting each other.
   */
  private function assertLifecycleMutationIsCurrent(EntityStorageInterface $storage): void {
    if ($this->isNew()) {
      return;
    }

    $original = $this->getOriginal() ?? $storage->loadUnchanged($this->id());
    if (!$original instanceof FormSchemaInterface) {
      throw new EntityStorageException('The original form schema revision could not be loaded.');
    }

    $schema_changed = !self::jsonEquals(
      $original->get('field_schema')->value,
      $this->get('field_schema')->value,
    );
    $workflow_changed = !self::jsonEquals(
      $original->get('field_workflow_definition')->value,
      $this->get('field_workflow_definition')->value,
    );
    $status_changed = $original->get('field_workflow_status')->value
      !== $this->get('field_workflow_status')->value;
    $workspace_changed = self::workspaceScopeChanged($original, $this);

    $query = \Drupal::database()->select('form_schema', 'form_schema');
    $query->fields('form_schema', [
      'revision_id',
      'field_workflow_status',
    ]);
    $query->condition('id', $this->id());
    $query->forUpdate();
    $persisted = $query->execute()->fetchAssoc();

    if (!is_array($persisted)
      || (int) ($persisted['revision_id'] ?? 0) !== (int) $this->getRevisionId()) {
      throw new EntityStorageException('Form schema was modified concurrently. Reload before saving.');
    }

    $expected_revision = \Drupal::request()->headers->get('X-Beantragt-Expected-Revision');
    if ($expected_revision !== NULL
      && (!ctype_digit($expected_revision)
        || (int) $expected_revision <= 0
        || (int) $persisted['revision_id'] !== (int) $expected_revision)) {
      throw new EntityStorageException('Form schema was modified concurrently. Reload before saving.');
    }

    if (!$schema_changed && !$workflow_changed && !$status_changed && !$workspace_changed) {
      return;
    }

    if (($schema_changed || $workflow_changed)
      && ($persisted['field_workflow_status'] ?? NULL) !== 'draft') {
      throw new EntityStorageException('Only draft form schemas can change schema or workflow definitions.');
    }
  }

  /**
   * Creates an audit revision when the lifecycle or the visibility changes.
   *
   * Status transitions do not advance the semantic form version, but they
   * must advance the storage revision used by the concurrent-update guard.
   * The same holds for the published flag and the publication scope: a save
   * validated against the old visibility must fail the revision fence so it
   * cannot restore published/internet values after a concurrent withdrawal.
   */
  private function createLifecycleStatusRevision(EntityStorageInterface $storage): void {
    if ($this->isNew()) {
      return;
    }

    $original = $this->getOriginal() ?? $storage->loadUnchanged($this->id());
    if (!$original instanceof FormSchemaInterface) {
      return;
    }

    if ($original->get('field_workflow_status')->value !== $this->get('field_workflow_status')->value) {
      $this->forceRevision('Form schema lifecycle status changed.');
      return;
    }
    if ($original->isPublished() !== $this->isPublished()
      || $original->getPublicationScope() !== $this->getPublicationScope()) {
      $this->forceRevision('Form schema visibility changed.');
    }
  }

  /**
   * Fences a scope normalization that runs after this entity's preSave().
   *
   * Beantragt Group normalizes compatibility and explicit workspace fields in
   * hook_entity_presave(), which executes after ContentEntityBase::preSave().
   * Without this second, idempotent fence the persisted workspace can differ
   * from the generation revision used by context status and retrieval.
   */
  public function fenceNormalizedWorkspaceScope(): void {
    if ($this->isNew()) {
      return;
    }
    $original = $this->getOriginal();
    if (!$original instanceof FormSchemaInterface
      || !self::workspaceScopeChanged($original, $this)) {
      return;
    }
    $this->forceRevision('Workspace scope normalized.');
  }

  /**
   * Advances the semantic form version and forces a revision exactly once.
   *
   * A schema/workflow update may already have crossed this fence during
   * preSave(). Taking the maximum avoids a second version increment when the
   * later group normalization also changes the workspace boundary.
   */
  private function bumpVersionAndCreateRevision(FormSchemaInterface $original, string $log_message): void {
    $current_version = (int) $this->get('field_version')->value;
    $original_version = $original->getVersion();
    $this->set('field_version', max($current_version, $original_version + 1));
    $this->forceRevision($log_message);
  }

  /**
   * Forces a new revision by the current user, keeping an explicit log.
   */
  private function forceRevision(string $log_message): void {
    $this->setNewRevision(TRUE);
    $this->setRevisionUserId((int) \Drupal::currentUser()->id());
    $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    if ($this->getRevisionLogMessage() === '') {
      $this->setRevisionLogMessage($log_message);
    }
  }

  /**
   * Returns whether the effective workspace boundary changed.
   */
  protected static function workspaceScopeChanged(FormSchemaInterface $original, FormSchemaInterface $current): bool {
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      if ($original->hasField($field_name) !== $current->hasField($field_name)) {
        return TRUE;
      }
      if ($current->hasField($field_name)
        && $original->get($field_name)->getValue() !== $current->get($field_name)->getValue()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Compares two JSON-bearing strings by their decoded structure.
   *
   * Order-insensitive for associative arrays (cosmetic key reordering by a
   * structured editor does not trigger a version bump), but strict on scalar
   * types (a value flip "1" -> 1 IS treated as a change). Falls back to raw
   * string comparison when either side is not valid JSON.
   */
  protected static function jsonEquals(?string $a, ?string $b): bool {
    if ($a === $b) {
      return TRUE;
    }
    if ($a === NULL || $b === NULL) {
      return FALSE;
    }
    try {
      $decoded_a = json_decode($a, FALSE, 512, JSON_THROW_ON_ERROR);
      $decoded_b = json_decode($b, FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $a === $b;
    }

    return self::normalizeJsonForComparison($decoded_a) === self::normalizeJsonForComparison($decoded_b);
  }

  /**
   * Normalizes decoded JSON while preserving JSON object versus array types.
   */
  protected static function normalizeJsonForComparison(mixed $value): mixed {
    if ($value instanceof \stdClass) {
      $members = get_object_vars($value);
      ksort($members);
      foreach ($members as $key => $member) {
        $members[$key] = self::normalizeJsonForComparison($member);
      }
      return [
        '__json_type' => 'object',
        'members' => $members,
      ];
    }

    if (is_array($value)) {
      return [
        '__json_type' => 'array',
        'items' => array_map(
          static fn(mixed $item): mixed => self::normalizeJsonForComparison($item),
          $value,
        ),
      ];
    }

    return $value;
  }

  /**
   * Validates that a raw field_schema value is well-formed JSON.
   *
   * Shared by preSave() and the ValidFormSchemaDefinition entity constraint
   * so the storage guard and JSON:API validation reject the same payloads.
   */
  public static function validateFormSchemaJson(string $schema_value): void {
    if (!json_validate($schema_value)) {
      throw new \InvalidArgumentException('The field_schema value is not valid JSON.');
    }
  }

  /**
   * Validates the minimal shape every published form schema must have.
   *
   * A published schema without a non-empty title and a non-empty sections
   * array would be accepted as a ghost schema: it is publishable but the
   * public API projection returns NULL and citizens get a 404. Shared by
   * preSave() and the ValidFormSchemaDefinition entity constraint.
   */
  public static function validatePublishedFormSchemaShape(?string $schema_value): void {
    if ($schema_value === NULL) {
      throw new \InvalidArgumentException('Published form schemas must contain at least one field.');
    }

    try {
      $schema = json_decode($schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return;
    }

    if (!is_array($schema) || array_is_list($schema)) {
      throw new \InvalidArgumentException('The field_schema value must be a JSON object.');
    }
    $title = $schema['title'] ?? NULL;
    if (!is_string($title) || trim($title) === '') {
      throw new \InvalidArgumentException('Published form schemas must have a non-empty title.');
    }
    $sections = $schema['sections'] ?? NULL;
    if (!is_array($sections) || !array_is_list($sections) || $sections === []) {
      throw new \InvalidArgumentException('Published form schemas must have a non-empty sections array.');
    }

    foreach ($sections as $section) {
      if (is_array($section)
        && isset($section['fields'])
        && is_array($section['fields'])
        && $section['fields'] !== []) {
        return;
      }
    }

    throw new \InvalidArgumentException('Published form schemas must contain at least one field.');
  }

  /**
   * Validates schema DSL details that Drupal's raw JSON field cannot enforce.
   */
  public static function validateFormSchemaDefinition(
    string $schema_value,
    ?string $original_schema_value = NULL,
    bool $is_new = TRUE,
  ): void {
    self::validateFormSchemaRootId($schema_value, $original_schema_value, $is_new);
    self::validateFormSchemaDefinitionDetails($schema_value);
  }

  /**
   * Enforces machine IDs only for new or changed root id values.
   */
  public static function validateFormSchemaRootId(
    string $schema_value,
    ?string $original_schema_value = NULL,
    bool $is_new = TRUE,
    ?string $entity_uuid = NULL,
  ): void {
    try {
      $schema = json_decode($schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return;
    }

    if (!is_array($schema) || array_is_list($schema)) {
      throw new \InvalidArgumentException('The field_schema value must be a JSON object.');
    }

    $original_id = NULL;
    $original_has_id = FALSE;
    if (!$is_new && $original_schema_value !== NULL) {
      try {
        $original_schema = json_decode($original_schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
        if (is_array($original_schema) && !array_is_list($original_schema) && array_key_exists('id', $original_schema)) {
          $original_has_id = TRUE;
          $original_id = $original_schema['id'];
        }
      }
      catch (\JsonException) {
        // The shared JSON guard reports malformed current definitions. A
        // malformed stored original must not make a changed ID look unchanged.
      }
    }
    $is_editor_uuid_projection = $entity_uuid !== NULL
      && ($schema['id'] ?? NULL) === $entity_uuid;
    if (array_key_exists('id', $schema)
      && ($is_new || !$original_has_id || $schema['id'] !== $original_id)
      // The editor projects the entity UUID as fallback identity into the DSL.
      && !$is_editor_uuid_projection) {
      self::validateMachineId($schema['id'], 'Schema id');
    }

  }

  /**
   * Code of a field condition whose value is empty.
   */
  public const CONDITION_VALUE_EMPTY = 'schema.condition_value_empty';

  /**
   * Refuses a field condition with an empty value the save brings in.
   *
   * A condition value that is a string blank after trimming means "show
   * when the answer is empty", which the citizen's page and the server judge
   * differently when the answer is missing. The form editor writes
   * "show when answered" without a value and never
   * writes an empty one. Refused only where the save brings the condition in
   * (a field whose conditionalOn differs from the stored definition, or a
   * new definition); a stored one carried along unchanged is left as it is,
   * so existing forms stay editable. The message carries a token the editor
   * reads: "[schema.condition_value_empty pointer=<JSON pointer> field=<id>]".
   *
   * @param string $schema_value
   *   The definition to save.
   * @param string|null $original_schema_value
   *   The stored definition, NULL for a new form.
   *
   * @throws \InvalidArgumentException
   *   For the first such condition.
   */
  public static function validateConditionValues(string $schema_value, ?string $original_schema_value): void {
    $stored = [];
    foreach (self::conditionsByField($original_schema_value) as $field_id => [, $condition]) {
      $stored[$field_id] = $condition;
    }
    foreach (self::conditionsByField($schema_value) as $field_id => [$pointer, $condition]) {
      $value = $condition['value'] ?? NULL;
      if (!is_string($value) || preg_match('/^[\s\p{Zs}\x{FEFF}]*$/u', $value) !== 1) {
        continue;
      }
      if (array_key_exists($field_id, $stored) && $stored[$field_id] === $condition) {
        continue;
      }
      throw new \InvalidArgumentException(sprintf(
        'A field condition needs a value; to show the field for any answer, leave the value out. [%s pointer=%s/conditionalOn/value field=%s]',
        self::CONDITION_VALUE_EMPTY,
        $pointer,
        $field_id,
      ));
    }
  }

  /**
   * The conditions of a definition by field id, with the field's pointer.
   *
   * @return array<string, array{0: string, 1: array<string, mixed>}>
   *   JSON pointer of the field and its conditionalOn, per field id.
   */
  private static function conditionsByField(?string $schema_value): array {
    if ($schema_value === NULL) {
      return [];
    }
    $schema = json_decode($schema_value, TRUE);
    if (!is_array($schema) || !is_array($schema['sections'] ?? NULL)) {
      return [];
    }
    $conditions = [];
    foreach ($schema['sections'] as $section_index => $section) {
      foreach (is_array($section) && is_array($section['fields'] ?? NULL) ? $section['fields'] : [] as $field_index => $field) {
        if (!is_array($field) || !is_array($field['conditionalOn'] ?? NULL)) {
          continue;
        }
        $field_id = is_string($field['id'] ?? NULL) ? $field['id'] : '#' . $section_index . '/' . $field_index;
        $conditions[$field_id] ??= ['/sections/' . $section_index . '/fields/' . $field_index, $field['conditionalOn']];
      }
    }
    return $conditions;
  }

  /**
   * Validates definition properties that are independent of entity history.
   */
  public static function validateFormSchemaDefinitionDetails(string $schema_value): void {
    try {
      $schema = json_decode($schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return;
    }

    if (!is_array($schema) || array_is_list($schema)) {
      throw new \InvalidArgumentException('The field_schema value must be a JSON object.');
    }

    if (array_key_exists('title', $schema)) {
      self::validateNonEmptyString($schema['title'], 'Schema title');
    }
    if (array_key_exists('description', $schema) && !is_string($schema['description'])) {
      throw new \InvalidArgumentException('The field_schema description value must be a string.');
    }
    if (array_key_exists('version', $schema)
      && (!is_int($schema['version']) || $schema['version'] < 1)) {
      throw new \InvalidArgumentException('The field_schema version must be an integer greater than or equal to 1.');
    }
    if (array_key_exists('i18n', $schema)) {
      self::validateLocalizedTextMap(
        $schema['i18n'],
        'Schema i18n',
        ['title', 'description', 'label', 'placeholder'],
      );
    }
    if (array_key_exists('semantics', $schema)) {
      self::validateRootSemantics($schema['semantics']);
    }
    if (array_key_exists('source', $schema)) {
      self::validateRootSource($schema['source']);
    }

    if (array_key_exists('auth', $schema)) {
      self::validateAuthPolicy($schema['auth']);
    }
    if (array_key_exists('delivery', $schema)) {
      self::validateDeliveryPolicy($schema['delivery']);
    }
    if (array_key_exists('contextSources', $schema)) {
      self::validateContextSources($schema['contextSources']);
    }
    if (array_key_exists('precheckSchema', $schema)) {
      self::validatePrecheckSchema(
        $schema['precheckSchema'],
        $schema['contextSources'] ?? [],
        $schema['sections'] ?? [],
      );
    }

    if (!array_key_exists('sections', $schema)) {
      return;
    }

    if (!is_array($schema['sections']) || !array_is_list($schema['sections'])) {
      throw new \InvalidArgumentException('The field_schema sections value must be a JSON array.');
    }

    $section_ids = [];
    $field_ids = [];
    $field_types = [];
    $analysis_compare_references = [];
    $address_group_ids = [];
    $address_group_field_ids = [];
    foreach ($schema['sections'] as $section) {
      if (!is_array($section)) {
        throw new \InvalidArgumentException('Each form schema section must be a JSON object.');
      }

      $section_id = self::validateMachineId($section['id'] ?? NULL, 'Section id');
      if (isset($section_ids[$section_id])) {
        throw new \InvalidArgumentException(sprintf(
          'Duplicate section id "%s" in field_schema.',
          $section_id,
        ));
      }
      $section_ids[$section_id] = TRUE;

      self::validateNonEmptyString($section['title'] ?? NULL, sprintf('Section "%s" title', $section_id));
      if (array_key_exists('description', $section) && !is_string($section['description'])) {
        throw new \InvalidArgumentException(sprintf(
          'Section "%s" description must be a string.',
          $section_id,
        ));
      }
      if (array_key_exists('i18n', $section)) {
        self::validateLocalizedTextMap(
          $section['i18n'],
          sprintf('Section "%s" i18n', $section_id),
          ['title', 'description', 'label', 'placeholder'],
        );
      }

      if (!array_key_exists('fields', $section) || !is_array($section['fields']) || !array_is_list($section['fields'])) {
        throw new \InvalidArgumentException(sprintf(
          'Section "%s" fields value must be a JSON array.',
          $section_id,
        ));
      }

      $section_field_types = [];
      foreach ($section['fields'] as $field) {
        if (!is_array($field)) {
          throw new \InvalidArgumentException(sprintf(
            'Each field in section "%s" must be a JSON object.',
            $section_id,
          ));
        }

        $field_id = self::validateMachineId($field['id'] ?? NULL, sprintf('Field id in section "%s"', $section_id));
        if (isset($field_ids[$field_id]) || isset($address_group_ids[$field_id])) {
          throw new \InvalidArgumentException(sprintf(
            'Duplicate field id "%s" in field_schema.',
            $field_id,
          ));
        }
        $field_ids[$field_id] = TRUE;

        if (!array_key_exists('type', $field) || !is_string($field['type']) || !in_array($field['type'], self::ALLOWED_FIELD_TYPES, TRUE)) {
          throw new \InvalidArgumentException(sprintf(
            'Field "%s" has an unsupported field type.',
            $field_id,
          ));
        }
        $section_field_types[$field_id] = $field['type'];
        // Repeatable sections keep answers per instance, so a single compared
        // value cannot be resolved there.
        if (($section['repeatable'] ?? FALSE) !== TRUE) {
          $field_types[$field_id] = $field['type'];
        }
        self::validateNonEmptyString($field['label'] ?? NULL, sprintf('Field "%s" label', $field_id));

        foreach (['placeholder', 'crm_key'] as $string_property) {
          if (array_key_exists($string_property, $field) && !is_string($field[$string_property])) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" %s must be a string.',
              $field_id,
              $string_property,
            ));
          }
        }
        if (array_key_exists('format', $field)
          && (!is_string($field['format']) || !in_array($field['format'], self::ALLOWED_FIELD_FORMATS, TRUE))) {
          throw new \InvalidArgumentException(sprintf(
            'Field "%s" has an unsupported format.',
            $field_id,
          ));
        }
        if (array_key_exists('i18n', $field)) {
          self::validateLocalizedTextMap(
            $field['i18n'],
            sprintf('Field "%s" i18n', $field_id),
            ['label', 'placeholder', 'options'],
            TRUE,
          );
        }

        if (array_key_exists('semantics', $field)) {
          self::validateFieldSemantics($field['semantics'], $field_id);
        }

        if (array_key_exists('analysis', $field) && $field['type'] !== 'file') {
          throw new \InvalidArgumentException(sprintf(
            'Field "%s" analysis profile is only supported on file fields.',
            $field_id,
          ));
        }
        if (array_key_exists('analysis', $field)) {
          self::validateAttachmentAnalysisProfile($field['analysis'], $field_id);
          // A personal value read from the attachment is protected like the
          // attachment: the file field must not be declared non-sensitive.
          if (self::isExplicitlyNonSensitive($field['sensitive'] ?? NULL) && self::hasPurposeBoundAnalysisValue($field['analysis'])) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" reads purpose-bound values and cannot be marked non-sensitive.',
              $field_id,
            ));
          }
          foreach (self::attachmentAnalysisCompareReferences($field['analysis']) as [$key, $referenced_id]) {
            $analysis_compare_references[] = [$field_id, $key, $referenced_id];
          }
        }

        if (array_key_exists('widget', $field)) {
          self::validateAddressWidget($field['widget'], $field_id, $field['type']);
        }

        if (array_key_exists('options', $field)) {
          self::validateStringList($field['options'], sprintf('Field "%s" options', $field_id));
        }

        // Structural validation for the `computed` spec.
        // Module decoupling: beantragt_schema does NOT depend on
        // beantragt_compute's plugin manager. Only the shape is validated here
        // (object with non-empty string fn, args array of strings, and every
        // arg must reference an existing field id in the same schema). The
        // check that `fn` maps to a registered ComputeFunction plugin stays at
        // evaluate-time in beantragt_compute by design.
        if (array_key_exists('computed', $field)) {
          $computed = $field['computed'];
          if (!is_array($computed) || array_is_list($computed)) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" computed spec must be a JSON object.',
              $field_id,
            ));
          }
          if (!array_key_exists('fn', $computed) || !is_string($computed['fn']) || $computed['fn'] === '') {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" computed spec must have a non-empty string fn.',
              $field_id,
            ));
          }
          if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $computed['fn']) !== 1) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" computed spec fn is invalid.',
              $field_id,
            ));
          }
          if (!array_key_exists('args', $computed) || !is_array($computed['args']) || !array_is_list($computed['args'])) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" computed spec args must be a JSON array.',
              $field_id,
            ));
          }
          foreach ($computed['args'] as $arg) {
            if (!is_string($arg) || $arg === '') {
              throw new \InvalidArgumentException(sprintf(
                'Field "%s" computed spec args must be an array of non-empty strings.',
                $field_id,
              ));
            }
            if (!array_key_exists($arg, $field_ids)) {
              throw new \InvalidArgumentException(sprintf(
                'Field "%s" computed spec arg "%s" does not reference an existing field id in the same schema.',
                $field_id,
                $arg,
              ));
            }
          }
        }

        if (!array_key_exists('validation', $field)) {
          continue;
        }
        if (!is_array($field['validation']) || ($field['validation'] !== [] && array_is_list($field['validation']))) {
          throw new \InvalidArgumentException(sprintf(
            'Field "%s" validation value must be a JSON object.',
            $field_id,
          ));
        }
        foreach (['minLength', 'maxLength'] as $length_property) {
          if (array_key_exists($length_property, $field['validation'])
            && !is_int($field['validation'][$length_property])) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" validation %s must be an integer.',
              $field_id,
              $length_property,
            ));
          }
        }
        if (array_key_exists('pattern', $field['validation'])) {
          if (!is_string($field['validation']['pattern'])) {
            throw new \InvalidArgumentException(sprintf(
              'Field "%s" validation pattern must be a string.',
              $field_id,
            ));
          }

          self::validateValidationPattern($field['validation']['pattern']);
        }
      }

      if (array_key_exists('addressGroups', $section)) {
        self::validateAddressGroups(
          $section['addressGroups'],
          $section_id,
          $section_field_types,
          $field_ids,
          $address_group_ids,
          $address_group_field_ids,
        );
      }
    }

    // A purpose-bound value is shown to staff next to the applicant's own
    // answer, so the referenced field must exist and hold a number.
    foreach ($analysis_compare_references as [$file_field_id, $key, $referenced_id]) {
      if (!in_array($field_types[$referenced_id] ?? NULL, ['number'], TRUE)) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis value "%s" must compare with an existing number field outside repeatable sections, "%s" is none.',
          $file_field_id,
          $key,
          $referenced_id,
        ));
      }
    }
  }

  /**
   * Validates address widget options.
   */
  protected static function validateAddressWidget(mixed $widget, string $field_id, string $field_type): void {
    if ($field_type !== 'address') {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" widget is only supported on address fields.',
        $field_id,
      ));
    }
    if (!is_array($widget) || array_is_list($widget)) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" address widget must be a JSON object.',
        $field_id,
      ));
    }
    foreach (array_keys($widget) as $key) {
      if (!in_array($key, ['autocomplete', 'map', 'boundary'], TRUE)) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" address widget contains an unsupported option.',
          $field_id,
        ));
      }
    }
    if (array_key_exists('autocomplete', $widget) && !is_bool($widget['autocomplete'])) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" address widget autocomplete option must be a boolean.',
        $field_id,
      ));
    }
    if (array_key_exists('map', $widget) && !is_bool($widget['map'])) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" address widget map option must be a boolean.',
        $field_id,
      ));
    }
    if (array_key_exists('boundary', $widget) && !in_array($widget['boundary'], ['off', 'advisory'], TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" address widget boundary option must be off or advisory.',
        $field_id,
      ));
    }
  }

  /**
   * Validates stable field semantics used by integration adapters.
   */
  protected static function validateFieldSemantics(mixed $semantics, string $field_id): void {
    if (!is_array($semantics) || array_is_list($semantics)) {
      throw new \InvalidArgumentException(sprintf('Field "%s" semantics must be a JSON object.', $field_id));
    }
    foreach (array_keys($semantics) as $key) {
      if (!in_array($key, ['concept', 'sourcePath'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('Field "%s" semantics contains an unsupported key.', $field_id));
      }
    }
    if (array_key_exists('concept', $semantics)
      && (!is_string($semantics['concept']) || preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $semantics['concept']) !== 1)) {
      throw new \InvalidArgumentException(sprintf('Field "%s" semantics concept is invalid.', $field_id));
    }
    if (array_key_exists('sourcePath', $semantics)) {
      self::validateNonEmptyString($semantics['sourcePath'], sprintf('Field "%s" semantics sourcePath', $field_id));
      if (mb_strlen($semantics['sourcePath']) > 1000) {
        throw new \InvalidArgumentException(sprintf('Field "%s" semantics sourcePath is too long.', $field_id));
      }
    }
  }

  /**
   * Validates a localized-text map from the bundled specification.
   *
   * @param mixed $translations
   *   Locale-keyed translated values.
   * @param string $context
   *   Error-message context.
   * @param string[] $allowed_properties
   *   Allowed properties in each translated value.
   * @param bool $allow_options
   *   Whether array- or object-shaped string options are supported.
   */
  protected static function validateLocalizedTextMap(
    mixed $translations,
    string $context,
    array $allowed_properties,
    bool $allow_options = FALSE,
  ): void {
    if (!is_array($translations) || ($translations !== [] && array_is_list($translations))) {
      throw new \InvalidArgumentException("$context must be a JSON object.");
    }
    foreach ($translations as $locale => $translation) {
      if (!is_array($translation) || ($translation !== [] && array_is_list($translation))) {
        throw new \InvalidArgumentException(sprintf(
          '%s locale "%s" must be a JSON object.',
          $context,
          (string) $locale,
        ));
      }
      foreach ($translation as $property => $value) {
        if (!in_array($property, $allowed_properties, TRUE)) {
          throw new \InvalidArgumentException(sprintf(
            '%s locale "%s" contains an unsupported property.',
            $context,
            (string) $locale,
          ));
        }
        if ($allow_options && $property === 'options') {
          if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
              '%s locale "%s" options must be an array or object of strings.',
              $context,
              (string) $locale,
            ));
          }
          foreach ($value as $option) {
            if (!is_string($option)) {
              throw new \InvalidArgumentException(sprintf(
                '%s locale "%s" options must contain only strings.',
                $context,
                (string) $locale,
              ));
            }
          }
        }
        elseif (!is_string($value)) {
          throw new \InvalidArgumentException(sprintf(
            '%s locale "%s" %s must be a string.',
            $context,
            (string) $locale,
            (string) $property,
          ));
        }
      }
    }
  }

  /**
   * Validates known root semantics properties from the bundled specification.
   */
  protected static function validateRootSemantics(mixed $semantics): void {
    if (!is_array($semantics) || ($semantics !== [] && array_is_list($semantics))) {
      throw new \InvalidArgumentException('The field_schema semantics value must be a JSON object.');
    }
    foreach (['category', 'process'] as $property) {
      if (array_key_exists($property, $semantics) && !is_string($semantics[$property])) {
        throw new \InvalidArgumentException("The field_schema semantics $property value must be a string.");
      }
    }
    if (array_key_exists('entities', $semantics)) {
      self::validateStringList($semantics['entities'], 'The field_schema semantics entities value');
    }
  }

  /**
   * Validates known root source properties from the bundled specification.
   */
  protected static function validateRootSource(mixed $source): void {
    if (!is_array($source) || ($source !== [] && array_is_list($source))) {
      throw new \InvalidArgumentException('The field_schema source value must be a JSON object.');
    }
    foreach (['type', 'file', 'url', 'label'] as $property) {
      if (array_key_exists($property, $source) && !is_string($source[$property])) {
        throw new \InvalidArgumentException("The field_schema source $property value must be a string.");
      }
    }
  }

  /**
   * Validates UI-only address groups and their canonical field references.
   *
   * @param mixed $groups
   *   Address group definitions.
   * @param string $section_id
   *   Current section id.
   * @param array<string, string> $section_field_types
   *   Field ids and types in the current section.
   * @param array<string, bool> $all_field_ids
   *   All field ids encountered so far.
   * @param array<string, bool> $address_group_ids
   *   Address group ids encountered across the schema.
   * @param array<string, bool> $address_group_field_ids
   *   Canonical fields already assigned to another address group.
   */
  protected static function validateAddressGroups(
    mixed $groups,
    string $section_id,
    array $section_field_types,
    array $all_field_ids,
    array &$address_group_ids,
    array &$address_group_field_ids,
  ): void {
    if (!is_array($groups) || !array_is_list($groups)) {
      throw new \InvalidArgumentException(sprintf('Section "%s" addressGroups must be a JSON array.', $section_id));
    }

    $component_keys = ['street', 'houseNumber', 'postcode', 'city'];
    foreach ($groups as $index => $group) {
      if (!is_array($group) || array_is_list($group)) {
        throw new \InvalidArgumentException(sprintf('Address group %d in section "%s" must be a JSON object.', $index, $section_id));
      }
      foreach (array_keys($group) as $key) {
        if (!in_array($key, ['id', 'label', 'fields', 'autocomplete', 'map', 'boundary'], TRUE)) {
          throw new \InvalidArgumentException(sprintf('Address group %d in section "%s" contains an unsupported key.', $index, $section_id));
        }
      }

      $group_id = self::validateMachineId($group['id'] ?? NULL, sprintf('Address group id in section "%s"', $section_id));
      if (isset($address_group_ids[$group_id]) || isset($all_field_ids[$group_id])) {
        throw new \InvalidArgumentException(sprintf('Duplicate or conflicting address group id "%s" in field_schema.', $group_id));
      }
      $address_group_ids[$group_id] = TRUE;
      if (array_key_exists('label', $group)) {
        self::validateNonEmptyString($group['label'], sprintf('Address group "%s" label', $group_id));
      }

      $components = $group['fields'] ?? NULL;
      $provided_component_keys = is_array($components) && !array_is_list($components)
        ? array_keys($components)
        : [];
      sort($provided_component_keys);
      $expected_component_keys = $component_keys;
      sort($expected_component_keys);
      if (!is_array($components) || array_is_list($components) || $provided_component_keys !== $expected_component_keys) {
        throw new \InvalidArgumentException(sprintf(
          'Address group "%s" fields must contain exactly street, houseNumber, postcode, and city.',
          $group_id,
        ));
      }
      $group_references = [];
      foreach ($component_keys as $component) {
        $referenced_id = $components[$component];
        if (!is_string($referenced_id) || !isset($section_field_types[$referenced_id])) {
          throw new \InvalidArgumentException(sprintf(
            'Address group "%s" component "%s" must reference a field in the same section.',
            $group_id,
            $component,
          ));
        }
        if ($section_field_types[$referenced_id] !== 'text') {
          throw new \InvalidArgumentException(sprintf(
            'Address group "%s" component "%s" must reference a text field.',
            $group_id,
            $component,
          ));
        }
        if (isset($group_references[$referenced_id]) || isset($address_group_field_ids[$referenced_id])) {
          throw new \InvalidArgumentException(sprintf('Address group field "%s" may only be referenced once.', $referenced_id));
        }
        $group_references[$referenced_id] = TRUE;
        $address_group_field_ids[$referenced_id] = TRUE;
      }

      foreach (['autocomplete', 'map'] as $option) {
        if (array_key_exists($option, $group) && !is_bool($group[$option])) {
          throw new \InvalidArgumentException(sprintf('Address group "%s" %s option must be a boolean.', $group_id, $option));
        }
      }
      if (array_key_exists('boundary', $group) && !in_array($group['boundary'], ['off', 'advisory'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('Address group "%s" boundary option must be off or advisory.', $group_id));
      }
    }
  }

  /**
   * Validates local assistant source selection metadata.
   *
   * Runtime code deliberately trusts only the type and UUID. Titles,
   * versions, placement, publication state, and tenant scope are always
   * re-derived from the referenced Drupal entity.
   */
  protected static function validateContextSources(mixed $sources): void {
    if (!is_array($sources) || !array_is_list($sources)) {
      throw new \InvalidArgumentException('The field_schema contextSources value must be a JSON array.');
    }
    if (count($sources) > self::MAX_CONTEXT_SOURCES) {
      throw new \InvalidArgumentException(sprintf(
        'The field_schema contextSources value may contain at most %d entries.',
        self::MAX_CONTEXT_SOURCES,
      ));
    }

    $seen = [];
    foreach ($sources as $index => $source) {
      if (!is_array($source) || array_is_list($source)) {
        throw new \InvalidArgumentException(sprintf('Context source %d must be a JSON object.', $index));
      }
      foreach (array_keys($source) as $key) {
        if (!is_string($key) || !in_array($key, self::ALLOWED_CONTEXT_SOURCE_KEYS, TRUE)) {
          throw new \InvalidArgumentException(sprintf('Context source %d contains an unsupported key.', $index));
        }
      }

      $type = $source['type'] ?? NULL;
      if (!is_string($type) || !in_array($type, self::ALLOWED_CONTEXT_SOURCE_TYPES, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Context source %d has an unsupported type.', $index));
      }
      $id = $source['id'] ?? NULL;
      if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) !== 1) {
        throw new \InvalidArgumentException(sprintf('Context source %d must have a valid UUID.', $index));
      }
      $key = strtolower($id);
      if (isset($seen[$key])) {
        throw new \InvalidArgumentException(sprintf('Context source %d duplicates an existing UUID.', $index));
      }
      $seen[$key] = TRUE;

      self::validateBoundedContextSourceString($source, 'title', 240, $index, TRUE);
      self::validateBoundedContextSourceString($source, 'placement', 80, $index);
      self::validateBoundedContextSourceString($source, 'version', 120, $index);

      if (array_key_exists('changed', $source)
        && (!is_int($source['changed']) || $source['changed'] < 0)) {
        throw new \InvalidArgumentException(sprintf('Context source %d changed must be a non-negative integer.', $index));
      }
      if (array_key_exists('requiredCitation', $source) && !is_bool($source['requiredCitation'])) {
        throw new \InvalidArgumentException(sprintf('Context source %d requiredCitation must be a boolean.', $index));
      }
      if (array_key_exists('enabledFor', $source)) {
        $usages = $source['enabledFor'];
        if (!is_array($usages) || !array_is_list($usages) || $usages === []) {
          throw new \InvalidArgumentException(sprintf('Context source %d enabledFor must be a non-empty JSON array.', $index));
        }
        $unique_usages = [];
        foreach ($usages as $usage) {
          if (!is_string($usage) || !in_array($usage, self::ALLOWED_CONTEXT_SOURCE_USAGES, TRUE)) {
            throw new \InvalidArgumentException(sprintf('Context source %d enabledFor contains an unsupported usage.', $index));
          }
          if (isset($unique_usages[$usage])) {
            throw new \InvalidArgumentException(sprintf('Context source %d enabledFor contains a duplicate usage.', $index));
          }
          $unique_usages[$usage] = TRUE;
        }
      }
    }
  }

  /**
   * Validates versioned, reviewed and evidence-bound precheck criteria.
   *
   * @param mixed $precheck
   *   Precheck schema value.
   * @param mixed $context_sources
   *   Selected, tenant-scoped context source metadata from the same schema.
   * @param mixed $sections
   *   Form sections containing optional canonical fact-field mappings.
   */
  protected static function validatePrecheckSchema(mixed $precheck, mixed $context_sources, mixed $sections): void {
    if (!is_array($precheck) || array_is_list($precheck)) {
      throw new \InvalidArgumentException('The field_schema precheckSchema value must be a JSON object.');
    }
    foreach (array_keys($precheck) as $key) {
      if (!is_string($key) || !in_array($key, ['version', 'criteria'], TRUE)) {
        throw new \InvalidArgumentException('The field_schema precheckSchema contains an unsupported key.');
      }
    }
    if (($precheck['version'] ?? NULL) !== 1) {
      throw new \InvalidArgumentException('The field_schema precheckSchema version must be 1.');
    }
    if (!isset($precheck['criteria']) || !is_array($precheck['criteria']) || !array_is_list($precheck['criteria'])) {
      throw new \InvalidArgumentException('The field_schema precheckSchema criteria value must be a JSON array.');
    }
    if (count($precheck['criteria']) > self::MAX_PRECHECK_CRITERIA) {
      throw new \InvalidArgumentException(sprintf(
        'The field_schema precheckSchema may contain at most %d criteria.',
        self::MAX_PRECHECK_CRITERIA,
      ));
    }

    $selected_source_ids = [];
    if (is_array($context_sources) && array_is_list($context_sources)) {
      foreach ($context_sources as $source) {
        if (!is_array($source) || !is_string($source['id'] ?? NULL)) {
          continue;
        }
        if (!in_array('citizen_assistant', $source['enabledFor'] ?? [], TRUE)) {
          continue;
        }
        $selected_source_ids[strtolower($source['id'])] = TRUE;
      }
    }

    $fields_by_id = [];
    if (is_array($sections) && array_is_list($sections)) {
      foreach ($sections as $section) {
        if (!is_array($section) || !is_array($section['fields'] ?? NULL) || !array_is_list($section['fields'])) {
          continue;
        }
        foreach ($section['fields'] as $field) {
          if (!is_array($field) || !is_string($field['id'] ?? NULL)) {
            continue;
          }
          $fields_by_id[$field['id']] = $field;
        }
      }
    }

    $criterion_ids = [];
    $fact_keys = [];
    foreach ($precheck['criteria'] as $index => $criterion) {
      if (!is_array($criterion) || array_is_list($criterion)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d must be a JSON object.', $index));
      }
      $allowed_keys = [
        'id',
        'factKey',
        'fieldId',
        'privacy',
        'factType',
        'label',
        'question',
        'affects',
        'order',
        'reviewStatus',
        'evidence',
        'unit',
        'options',
      ];
      foreach (array_keys($criterion) as $key) {
        if (!is_string($key) || !in_array($key, $allowed_keys, TRUE)) {
          throw new \InvalidArgumentException(sprintf('Precheck criterion %d contains an unsupported key.', $index));
        }
      }

      $criterion_id = self::validateMachineId(
        $criterion['id'] ?? NULL,
        sprintf('Precheck criterion %d id', $index),
        self::MAX_MACHINE_ID_LENGTH,
      );
      $fact_key = self::validateMachineId(
        $criterion['factKey'] ?? NULL,
        sprintf('Precheck criterion %d factKey', $index),
        self::MAX_MACHINE_ID_LENGTH,
      );
      $field_id = NULL;
      if (array_key_exists('fieldId', $criterion)) {
        $field_id = self::validateMachineId(
          $criterion['fieldId'],
          sprintf('Precheck criterion %d fieldId', $index),
          self::MAX_MACHINE_ID_LENGTH,
        );
      }
      $criterion_privacy_kind = NULL;
      if (array_key_exists('privacy', $criterion)) {
        $criterion_privacy = $criterion['privacy'];
        if (
          !is_array($criterion_privacy)
          || array_is_list($criterion_privacy)
          || array_keys($criterion_privacy) !== ['kind']
          || ($criterion_privacy['kind'] ?? NULL) !== 'not_personal'
        ) {
          throw new \InvalidArgumentException(sprintf(
            'Precheck criterion %d privacy must explicitly classify one non-personal fact.',
            $index,
          ));
        }
        if ($field_id !== NULL) {
          throw new \InvalidArgumentException(sprintf(
            'Precheck criterion %d cannot set criterion privacy when a canonical field is assigned.',
            $index,
          ));
        }
        $criterion_privacy_kind = 'not_personal';
      }
      if (isset($criterion_ids[$criterion_id]) || isset($fact_keys[$fact_key])) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d duplicates an id or factKey.', $index));
      }
      $criterion_ids[$criterion_id] = TRUE;
      $fact_keys[$fact_key] = TRUE;

      if (!is_string($criterion['factType'] ?? NULL) || !in_array($criterion['factType'], self::ALLOWED_PRECHECK_FACT_TYPES, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d has an unsupported factType.', $index));
      }
      if (!is_string($criterion['affects'] ?? NULL) || !in_array($criterion['affects'], self::ALLOWED_PRECHECK_AFFECTS, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d has an unsupported affects value.', $index));
      }
      if (!is_int($criterion['order'] ?? NULL) || $criterion['order'] < 0) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d order must be a non-negative integer.', $index));
      }
      if (!is_string($criterion['reviewStatus'] ?? NULL) || !in_array($criterion['reviewStatus'], self::ALLOWED_PRECHECK_REVIEW_STATUSES, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d has an unsupported reviewStatus.', $index));
      }
      self::validatePrecheckLocalizedText($criterion['label'] ?? NULL, $index, 'label');
      self::validatePrecheckLocalizedText($criterion['question'] ?? NULL, $index, 'question');
      if (self::precheckCriterionRequestsPersonalData($criterion)) {
        throw new \InvalidArgumentException(sprintf(
          'Precheck criterion %d must not request personal data.',
          $index,
        ));
      }
      if ($criterion['reviewStatus'] === 'approved'
        && (!isset($criterion['label']['en']) || !isset($criterion['question']['en']))) {
        throw new \InvalidArgumentException(sprintf(
          'Precheck criterion %d requires German and English text before approval.',
          $index,
        ));
      }
      if ($criterion['reviewStatus'] === 'approved' && is_string($field_id)) {
        $bound_field = $fields_by_id[$field_id] ?? NULL;
        if (
          !is_array($bound_field)
          || !self::isPrecheckDialogueSafeField($bound_field)
        ) {
          throw new \InvalidArgumentException(sprintf(
            'Precheck criterion %d requires a classified, dialogue-safe canonical field before approval.',
            $index,
          ));
        }
      }
      if (
        $criterion['reviewStatus'] === 'approved'
        && $field_id === NULL
        && $criterion_privacy_kind !== 'not_personal'
      ) {
        throw new \InvalidArgumentException(sprintf(
          'Precheck criterion %d requires explicit non-personal classification before unbound approval.',
          $index,
        ));
      }

      if (array_key_exists('unit', $criterion)) {
        self::validatePrecheckString($criterion['unit'], 80, $index, 'unit');
      }
      if (array_key_exists('options', $criterion)) {
        if ($criterion['factType'] !== 'enum' || !is_array($criterion['options']) || !array_is_list($criterion['options']) || $criterion['options'] === [] || count($criterion['options']) > 50) {
          throw new \InvalidArgumentException(sprintf('Precheck criterion %d options must be a non-empty array for enum facts.', $index));
        }
        $unique_options = [];
        foreach ($criterion['options'] as $option) {
          self::validatePrecheckString($option, 120, $index, 'option');
          if (isset($unique_options[$option])) {
            throw new \InvalidArgumentException(sprintf('Precheck criterion %d contains a duplicate option.', $index));
          }
          $unique_options[$option] = TRUE;
        }
      }
      elseif ($criterion['factType'] === 'enum') {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d enum facts require options.', $index));
      }

      $evidence = $criterion['evidence'] ?? NULL;
      if (!is_array($evidence) || array_is_list($evidence)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d evidence must be a JSON object.', $index));
      }
      foreach (array_keys($evidence) as $key) {
        if (!is_string($key) || !in_array($key, ['sourceType', 'sourceId', 'sourceTitle', 'quote', 'page'], TRUE)) {
          throw new \InvalidArgumentException(sprintf('Precheck criterion %d evidence contains an unsupported key.', $index));
        }
      }
      if (!in_array($evidence['sourceType'] ?? NULL, ['form_source', 'context_source'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d evidence has an unsupported sourceType.', $index));
      }
      self::validatePrecheckString($evidence['quote'] ?? NULL, 1000, $index, 'evidence quote');
      if (array_key_exists('sourceTitle', $evidence)) {
        self::validatePrecheckString($evidence['sourceTitle'], 240, $index, 'evidence sourceTitle');
      }
      if (array_key_exists('page', $evidence) && (!is_int($evidence['page']) || $evidence['page'] < 1)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d evidence page must be a positive integer.', $index));
      }

      $source_id = $evidence['sourceId'] ?? NULL;
      if ($evidence['sourceType'] === 'context_source') {
        if (!is_string($source_id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $source_id) !== 1) {
          throw new \InvalidArgumentException(sprintf('Precheck criterion %d context evidence must have a valid sourceId.', $index));
        }
      }
      elseif ($source_id !== NULL) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d form-source evidence cannot set sourceId.', $index));
      }

      if ($criterion['reviewStatus'] === 'approved'
        && ($evidence['sourceType'] !== 'context_source'
          || !isset($selected_source_ids[strtolower((string) $source_id)]))) {
        throw new \InvalidArgumentException(sprintf(
          'Precheck criterion %d can only be approved with a selected citizen-assistant context source.',
          $index,
        ));
      }
    }
  }

  /**
   * Validates one localized precheck label or question.
   */
  protected static function validatePrecheckLocalizedText(mixed $value, int $index, string $field): void {
    if (!is_array($value) || array_is_list($value)) {
      throw new \InvalidArgumentException(sprintf('Precheck criterion %d %s must be a JSON object.', $index, $field));
    }
    foreach (array_keys($value) as $key) {
      if (!is_string($key) || !in_array($key, ['de', 'en'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('Precheck criterion %d %s contains an unsupported locale.', $index, $field));
      }
    }
    self::validatePrecheckString($value['de'] ?? NULL, 500, $index, $field . ' de');
    if (array_key_exists('en', $value)) {
      self::validatePrecheckString($value['en'], 500, $index, $field . ' en');
    }
  }

  /**
   * Checks the full criterion semantics for blocked personal-data requests.
   */
  protected static function precheckCriterionRequestsPersonalData(array $criterion): bool {
    foreach (($criterion['question'] ?? []) as $question) {
      if (is_string($question)
        && (
          preg_match(self::PRECHECK_PERSONAL_DATA_QUESTION_PATTERN, $question) === 1
          || preg_match(self::PRECHECK_PERSONAL_DATA_PARAPHRASE_PATTERN, $question) === 1
          || preg_match(self::PRECHECK_INSTRUCTION_INJECTION_PATTERN, $question) === 1
        )) {
        return TRUE;
      }
    }

    $metadata = [
      $criterion['id'] ?? NULL,
      $criterion['factKey'] ?? NULL,
      $criterion['fieldId'] ?? NULL,
      ...array_values(is_array($criterion['label'] ?? NULL) ? $criterion['label'] : []),
      $criterion['unit'] ?? NULL,
      ...array_values(is_array($criterion['options'] ?? NULL) ? $criterion['options'] : []),
    ];
    $metadata_text = implode(' ', array_filter($metadata, 'is_string'));

    return preg_match(self::PRECHECK_PERSONAL_DATA_QUESTION_PATTERN, $metadata_text) === 1
      || preg_match(self::PRECHECK_PERSONAL_DATA_PARAPHRASE_PATTERN, $metadata_text) === 1
      || preg_match(self::PRECHECK_PERSONAL_DATA_METADATA_PATTERN, $metadata_text) === 1
      || preg_match(self::PRECHECK_INSTRUCTION_INJECTION_PATTERN, $metadata_text) === 1;
  }

  /**
   * Validates one bounded, non-empty precheck string.
   */
  protected static function validatePrecheckString(mixed $value, int $max_length, int $index, string $field): void {
    if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max_length) {
      throw new \InvalidArgumentException(sprintf(
        'Precheck criterion %d %s must contain between 1 and %d characters.',
        $index,
        $field,
        $max_length,
      ));
    }
  }

  /**
   * Validates one optional bounded context source string.
   *
   * @param array<string, mixed> $source
   *   Context source metadata.
   * @param string $key
   *   Metadata key.
   * @param int $max_length
   *   Maximum character length.
   * @param int $index
   *   Source list index for validation errors.
   * @param bool $required
   *   Whether the key must exist.
   */
  protected static function validateBoundedContextSourceString(
    array $source,
    string $key,
    int $max_length,
    int $index,
    bool $required = FALSE,
  ): void {
    if (!array_key_exists($key, $source)) {
      if ($required) {
        throw new \InvalidArgumentException(sprintf('Context source %d %s is required.', $index, $key));
      }
      return;
    }
    if (!is_string($source[$key])) {
      throw new \InvalidArgumentException(sprintf('Context source %d %s must be a string.', $index, $key));
    }
    $value = trim($source[$key]);
    if ($value === '' || mb_strlen($value) > $max_length) {
      throw new \InvalidArgumentException(sprintf('Context source %d %s must contain between 1 and %d characters.', $index, $key, $max_length));
    }
  }

  /**
   * Validates optional attachment analysis profile metadata.
   */
  protected static function validateAttachmentAnalysisProfile(mixed $analysis, string $field_id): void {
    if (!is_array($analysis) || array_is_list($analysis)) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" analysis profile must be a JSON object.',
        $field_id,
      ));
    }

    if (!array_key_exists('egressPolicy', $analysis) || !is_string($analysis['egressPolicy']) || !in_array($analysis['egressPolicy'], self::ALLOWED_ATTACHMENT_ANALYSIS_EGRESS_POLICIES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" analysis profile must have a supported egressPolicy.',
        $field_id,
      ));
    }
    if ($analysis['egressPolicy'] === 'no_ai' && array_key_exists('modelClass', $analysis)) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" analysis profile cannot set modelClass when egressPolicy is no_ai.',
        $field_id,
      ));
    }
    if (array_key_exists('modelClass', $analysis) && (!is_string($analysis['modelClass']) || !in_array($analysis['modelClass'], self::ALLOWED_ATTACHMENT_ANALYSIS_MODEL_CLASSES, TRUE))) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" analysis profile modelClass is not supported.',
        $field_id,
      ));
    }
    if (array_key_exists('version', $analysis) && (!is_int($analysis['version']) || $analysis['version'] < 1)) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" analysis profile version must be a positive integer.',
        $field_id,
      ));
    }
    foreach (self::ATTACHMENT_ANALYSIS_TEXT_KEYS as $key) {
      if (array_key_exists($key, $analysis) && (!is_string($analysis[$key]) || mb_strlen($analysis[$key]) > self::MAX_ATTACHMENT_ANALYSIS_TEXT_LENGTH)) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis profile %s must be a string up to %d characters.',
          $field_id,
          $key,
          self::MAX_ATTACHMENT_ANALYSIS_TEXT_LENGTH,
        ));
      }
    }
    if (array_key_exists('redaction', $analysis) && (!is_string($analysis['redaction']) || !in_array($analysis['redaction'], self::ALLOWED_ATTACHMENT_ANALYSIS_REDACTION_MODES, TRUE))) {
      throw new \InvalidArgumentException(sprintf(
        'Field "%s" analysis profile redaction must be none or when_possible.',
        $field_id,
      ));
    }
    if (array_key_exists('resultSchema', $analysis)) {
      if (!is_array($analysis['resultSchema']) || array_is_list($analysis['resultSchema'])) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis profile resultSchema must be a JSON object.',
          $field_id,
        ));
      }
      $encoded = json_encode($analysis['resultSchema']);
      if ($encoded === FALSE || strlen($encoded) > self::MAX_ATTACHMENT_ANALYSIS_RESULT_SCHEMA_LENGTH) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis profile resultSchema is too large.',
          $field_id,
        ));
      }
      self::validatePurposeBoundAnalysisValues($analysis, $field_id);
    }
  }

  /**
   * Validates personal, purpose-bound values in an analysis result schema.
   *
   * A property with "purposeBound": true may hold a personal value read from
   * the attachment (for example the gross pay on a payslip). It is only shown
   * to staff and must never leave for an external model, so the profile has
   * to keep the attachment with the operator or a sovereign processor.
   */
  protected static function validatePurposeBoundAnalysisValues(array $analysis, string $field_id): void {
    foreach (self::attachmentAnalysisResultDefinitions($analysis) as [$key, $definition]) {
      $flag = $definition['purposeBound'] ?? NULL;
      // An explicit false is the same as leaving the flag out.
      if ($flag === NULL || $flag === FALSE) {
        continue;
      }
      if ($flag !== TRUE || ($definition['nonPersonal'] ?? FALSE) === TRUE) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis value "%s" must set purposeBound to true and cannot also be nonPersonal.',
          $field_id,
          $key,
        ));
      }
      if (!in_array($analysis['egressPolicy'], self::PURPOSE_BOUND_ANALYSIS_EGRESS_POLICIES, TRUE)) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis value "%s" is personal and needs egressPolicy sovereign_only or local_only.',
          $field_id,
          $key,
        ));
      }
      if (!in_array($definition['type'] ?? NULL, ['number', 'integer', 'boolean'], TRUE)) {
        throw new \InvalidArgumentException(sprintf(
          'Field "%s" analysis value "%s" must be of type number, integer or boolean.',
          $field_id,
          $key,
        ));
      }
      if (array_key_exists('compareWith', $definition)) {
        if (!is_string($definition['compareWith']) || $definition['compareWith'] === '') {
          throw new \InvalidArgumentException(sprintf(
            'Field "%s" analysis value "%s" compareWith must name a form field.',
            $field_id,
            $key,
          ));
        }
        if (!in_array($definition['type'], ['number', 'integer'], TRUE)) {
          throw new \InvalidArgumentException(sprintf(
            'Field "%s" analysis value "%s" can only compare a number.',
            $field_id,
            $key,
          ));
        }
      }
    }
  }

  /**
   * Lists result schema definitions from both supported formats.
   *
   * The pre-check reads "properties" (a map) and "fields" (a list with key,
   * id or name), so every rule about extracted values has to see both. Every
   * declaration is returned on its own, also duplicates and numeric keys
   * (a JSON object {"0": ...} decodes to a PHP list), so none can hide
   * behind another.
   *
   * @return list<array{0: string, 1: array<string, mixed>}>
   *   Pairs of result key and definition.
   */
  protected static function attachmentAnalysisResultDefinitions(mixed $analysis): array {
    $schema = is_array($analysis) ? ($analysis['resultSchema'] ?? NULL) : NULL;
    if (!is_array($schema)) {
      return [];
    }
    $definitions = [];
    if (is_array($schema['properties'] ?? NULL)) {
      foreach ($schema['properties'] as $key => $definition) {
        if (is_array($definition)) {
          $definitions[] = [(string) $key, $definition];
        }
      }
    }
    if (is_array($schema['fields'] ?? NULL)) {
      foreach ($schema['fields'] as $index => $definition) {
        if (!is_array($definition)) {
          continue;
        }
        $key = $definition['key'] ?? $definition['id'] ?? $definition['name'] ?? NULL;
        $definitions[] = [is_string($key) && $key !== '' ? $key : sprintf('fields[%s]', $index), $definition];
      }
    }
    return $definitions;
  }

  /**
   * Tells whether a sensitive flag explicitly marks a field as not sensitive.
   *
   * Mirrors how attachment access reads the flag (booleans, and strings or
   * integers such as "false", "0" or 0), so both agree on the same schema.
   */
  protected static function isExplicitlyNonSensitive(mixed $value): bool {
    if (is_bool($value)) {
      return $value === FALSE;
    }
    if (is_string($value) || is_int($value)) {
      return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === FALSE;
    }
    return FALSE;
  }

  /**
   * Tells whether an analysis profile declares any purpose-bound value.
   */
  protected static function hasPurposeBoundAnalysisValue(mixed $analysis): bool {
    foreach (self::attachmentAnalysisResultDefinitions($analysis) as [, $definition]) {
      if (($definition['purposeBound'] ?? NULL) === TRUE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Lists the form fields that purpose-bound analysis values compare with.
   *
   * @return list<array{0: string, 1: string}>
   *   Pairs of result schema key and referenced field id.
   */
  protected static function attachmentAnalysisCompareReferences(mixed $analysis): array {
    $references = [];
    foreach (self::attachmentAnalysisResultDefinitions($analysis) as [$key, $definition]) {
      if (($definition['purposeBound'] ?? NULL) === TRUE && is_string($definition['compareWith'] ?? NULL)) {
        $references[] = [$key, $definition['compareWith']];
      }
    }
    return $references;
  }

  /**
   * Validates publish-time authoring rules that protect public schemas.
   *
   * Throws the first blocker of collectPublishBlockers() with the message
   * this gate has always used.
   */
  public static function validatePublishableFormSchemaDefinition(string $schema_value): void {
    $blockers = self::collectPublishBlockers($schema_value);
    if ($blockers !== []) {
      throw new \InvalidArgumentException($blockers[0]['message']);
    }
  }

  /**
   * Collects every publish-time authoring blocker of a schema.
   *
   * Same four conditions and the same order as the publish gate: per field,
   * a high-risk class without the sensitive flag, a high-risk pattern
   * without the sensitive flag, a high-risk pattern without a high-risk
   * class, and personal data without a class (or with not_personal). The two
   * sensitive conditions share one code and are reported once per field.
   * Fields come in schema order. Malformed parts are skipped, like the gate.
   *
   * @return list<array{sectionId: string|null, fieldId: string|null, code: string, message: string}>
   *   Blockers. Codes: sensitive_required, high_risk_kind_required,
   *   privacy_kind_required. The message is the gate's English sentence.
   */
  public static function collectPublishBlockers(string $schema_value): array {
    try {
      $schema = json_decode($schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    if (!is_array($schema) || array_is_list($schema) || !is_array($schema['sections'] ?? NULL)) {
      return [];
    }

    $blockers = [];
    foreach ($schema['sections'] as $section) {
      if (!is_array($section) || !is_array($section['fields'] ?? NULL)) {
        continue;
      }
      $section_id = self::fieldString($section['id'] ?? NULL);
      foreach ($section['fields'] as $field) {
        if (!is_array($field)) {
          continue;
        }
        $field_id = self::fieldString($field['id'] ?? NULL);
        $field_label = self::fieldString($field['label'] ?? NULL) ?? $field_id ?? 'unknown';
        $privacy_kind = self::privacyKind($field);
        $is_high_risk = self::isHighRiskSchemaField($field, $section);
        $is_sensitive = self::isSensitiveFlag($field['sensitive'] ?? NULL);
        $has_high_risk_kind = in_array($privacy_kind, ['special_category', 'criminal_offense'], TRUE);
        $codes = [];
        if (($has_high_risk_kind || $is_high_risk) && !$is_sensitive) {
          $codes['sensitive_required'] = sprintf('Field "%s" must be marked sensitive before publishing.', $field_label);
        }
        if ($is_high_risk && !$has_high_risk_kind) {
          $codes['high_risk_kind_required'] = sprintf('Field "%s" must have a high-risk privacy.kind classification before publishing.', $field_label);
        }
        if (self::isPersonalDataSchemaField($field, $section) && ($privacy_kind === NULL || $privacy_kind === 'not_personal')) {
          $codes['privacy_kind_required'] = sprintf('Field "%s" must have a privacy.kind classification before publishing.', $field_label);
        }
        foreach ($codes as $code => $message) {
          $blockers[] = [
            'sectionId' => $section_id,
            'fieldId' => $field_id,
            'code' => $code,
            'message' => $message,
          ];
        }
      }
    }
    return $blockers;
  }

  /**
   * Checks whether a schema field looks like high-risk Art. 9/10 content.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   * @param array<string, mixed> $section
   *   Parent schema section.
   */
  protected static function isHighRiskSchemaField(array $field, array $section = []): bool {
    return (bool) preg_match(
      '/\b(?:diagnos(?:e|is|en|edaten)?|krank[a-z]*|erkrankung[a-z]*|gesundheit[a-z]*|health[a-z]*|medical[a-z]*|arzt[a-z]*|attest[a-z]*|medikament[a-z]*|pflegegrad[a-z]*|gdb|grad[\s_-]*der[\s_-]*behinderung|schwerbehinderung[a-z]*|schwerbehindert[a-z]*|behinderung[a-z]*|disability[a-z]*|infektion[a-z]*|infektionsschutz[a-z]*|ifsg|impfung[a-z]*|religion[a-z]*|konfession[a-z]*|weltanschauung[a-z]*|ueberzeugung[a-z]*|uberzeugung[a-z]*|gewerkschaft[a-z]*|politisch[a-z]*|ethni(?:sch|c)[a-z]*|herkunft[a-z]*|orientierung[a-z]*|sexual(?:itaet|itat|leben| life| orientation)?[a-z]*|sexuell[a-z]*|biometr(?:ie|isch|ic)[a-z]*|genet(?:ik|isch|ic)[a-z]*|straf[a-z]*|vorstrafe[a-z]*|fuehrungszeugnis[a-z]*|fuhrungszeugnis[a-z]*|polizei[a-z]*)\b/u',
      self::fieldSearchContext($field, $section),
    );
  }

  /**
   * Checks whether a canonical field may drive citizen precheck questions.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   */
  protected static function isPrecheckDialogueSafeField(array $field): bool {
    $type = strtolower(self::fieldString($field['type'] ?? NULL) ?? '');
    $format = strtolower(self::fieldString($field['format'] ?? NULL) ?? '');
    $crm_key = strtolower(self::fieldString($field['crm_key'] ?? NULL) ?? '');

    return self::privacyKind($field) === 'not_personal'
      && !in_array($crm_key, self::PERSONAL_DATA_POLICY_CRM_KEYS, TRUE)
      && !in_array($crm_key, ['company.register_number', 'company.vat_id'], TRUE)
      && in_array($type, self::PRECHECK_DIALOGUE_SAFE_FIELD_TYPES, TRUE)
      && !in_array($format, self::PRECHECK_BLOCKED_FIELD_FORMATS, TRUE)
      && !self::isSensitiveFlag($field['sensitive'] ?? NULL)
      && !self::isHighRiskSchemaField($field);
  }

  /**
   * Recognizes personal data even when a privacy class is missing.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   * @param array<string, mixed> $section
   *   Parent schema section.
   */
  protected static function isPersonalDataSchemaField(array $field, array $section = []): bool {
    $crm_key = self::fieldString($field['crm_key'] ?? NULL);
    if ($crm_key !== NULL && in_array($crm_key, self::PERSONAL_DATA_POLICY_CRM_KEYS, TRUE)) {
      return TRUE;
    }

    $type = self::fieldString($field['type'] ?? NULL);
    if (in_array($type, ['email', 'tel'], TRUE)) {
      return TRUE;
    }

    $format = self::fieldString($field['format'] ?? NULL);
    if (in_array($format, ['postal_code', 'iban', 'bic', 'tax_id'], TRUE)) {
      return TRUE;
    }

    if (self::privacyKind($field) === 'not_personal') {
      return FALSE;
    }

    // A place word on its own names a municipality or a destination: the
    // local jurisdiction is the city that offers the form anyway. A place is
    // personal as part of an address, which the address semantics of the
    // field or the section's address groups mark. Mirrors isPersonalDataField()
    // in the frontend (app/utils/form-schema.ts).
    $semantics = is_array($field['semantics'] ?? NULL) ? $field['semantics'] : [];
    $concept = self::fieldString($semantics['concept'] ?? NULL);
    if ($concept !== NULL && str_starts_with($concept, 'address.')) {
      return TRUE;
    }
    $field_id = self::fieldString($field['id'] ?? NULL);
    if ($field_id !== NULL && in_array($field_id, self::addressGroupFieldIds($section), TRUE)) {
      return TRUE;
    }

    $field_context = self::fieldSearchContext($field);
    $context = self::fieldSearchContext($field, $section);
    if (preg_match('/\b(name|vorname|nachname|full[\s_-]*name|first[\s_-]*name|last[\s_-]*name)\b/u', $field_context)
      && preg_match('/(?:\b(?:hundename|hundenamen|katzenname|katzennamen|tiername|tiernamen|haustiername|haustiernamen|dogname|catname|animalname|petname|objektname|objectname)\b|\b(?:hund|dog|cat|animal|pet|objekt|object)[\s_-]*name\b|\bname[\s_-]*(?:des|der|vom|of[\s_-]*the)?[\s_-]*(?:hund(?:es)?|katze|tiers?|haustiers?|dog|cat|animal|pet|objekt(?:s)?|object)\b)/u', $field_context)) {
      return FALSE;
    }

    return (bool) preg_match(
      '/\b(?:name|vorname|nachname|familienname|surname|forename|full[\s_-]*name|first[\s_-]*name|last[\s_-]*name|given[\s_-]*name|family[\s_-]*name|geburts(?:datum|tag|ort|name)?|maedchenname|madchenname|birth(?:date|day|place|name)?|(?:applicant|claimant|requester|submitter|citizen|antragsteller(?:in)?)[\s_-]*(?:(?:full|first|last|given|family)[\s_-]*)?(?:name|vorname|nachname|familienname|surname|forename)|kontaktperson|ansprechpartner(?:in)?|vertreter(?:in)?|bevollmaechtigt[a-z]*|bevollmachtigt[a-z]*|contact[\s_-]*person|representative|authorized[\s_-]*person|adresse|anschrift|strasse|str|street|hausnummer|wohnort|address|postal[\s_-]*code|postalcode|house[\s_-]*number|plz|postleitzahl|(?:ihr|ihre|your|my|mein|meine)[\s_-]+(?:ort|stadt|city|str)|telefon|telefonnummer|handy|mobil|phone|phone[\s_-]*number|telephone|mobile|tel|e[\s_-]?mail|email|(?:applicant|claimant|requester|submitter|citizen|antragsteller(?:in)?)[\s_-]*(?:e[\s_-]?mail|email)|(?:(?:hunde?|tier|fahrzeug|kfz|grundstueck|grundstuck)[\s_-]*)?halter(?:in)?s?[\s_-]*name|(?:(?:hunde?|tier|fahrzeug|kfz|grundstueck|grundstuck)[\s_-]*)?besitzer(?:in)?s?[\s_-]*name|(?:(?:hunde?|tier|fahrzeug|kfz|grundstueck|grundstuck)[\s_-]*)?(?:eigentuemer|eigentumer)(?:in)?s?[\s_-]*name|(?:(?:[a-z0-9]+[\s_-]+)+|[a-z0-9]+)?owner[\s_-]*name|iban|bic|steuer[\s_-]?id|steueridentifikationsnummer|tax[\s_-]?id|geschlecht|gender|nationalitat|nationalitaet|nationality|staatsangehorigkeit|staatsangehoerigkeit|familienstand|marital[\s_-]*status|ausweis(?:nummer)?|personalausweis|passnummer|passport[\s_-]*number|identity[\s_-]*card|id[\s_-]*number|sozialversicherungsnummer|social[\s_-]*security[\s_-]*number)\b/u',
      $context,
    );
  }

  /**
   * Returns a normalized privacy kind when one is supported.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   */
  protected static function privacyKind(array $field): ?string {
    if (!is_array($field['privacy'] ?? NULL) || array_is_list($field['privacy'])) {
      return NULL;
    }
    return self::normalizePrivacyKindValue($field['privacy']['kind'] ?? NULL);
  }

  /**
   * Normalizes one privacy kind value to the canonical machine name.
   */
  protected static function normalizePrivacyKindValue(mixed $value): ?string {
    $kind = self::fieldString($value);
    if ($kind === NULL) {
      return NULL;
    }
    $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($kind)));
    $normalized = trim((string) $normalized, '_');
    return in_array($normalized, self::ALLOWED_PRIVACY_KINDS, TRUE) ? $normalized : NULL;
  }

  /**
   * Canonicalizes privacy.kind spellings before published schemas are stored.
   */
  public static function normalizeSchemaPrivacyKinds(string $schema_value): string {
    try {
      $schema = json_decode($schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $schema_value;
    }

    if (!is_array($schema)) {
      return $schema_value;
    }

    self::normalizeSchemaPrivacyKindsNode($schema);

    try {
      return json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    catch (\JsonException) {
      return $schema_value;
    }
  }

  /**
   * Recursively canonicalizes privacy.kind values in decoded schema nodes.
   */
  protected static function normalizeSchemaPrivacyKindsNode(mixed &$value): void {
    if (!is_array($value)) {
      return;
    }

    if (!array_is_list($value) && is_array($value['privacy'] ?? NULL) && !array_is_list($value['privacy'])) {
      $kind = self::normalizePrivacyKindValue($value['privacy']['kind'] ?? NULL);
      if ($kind !== NULL) {
        $value['privacy']['kind'] = $kind;
        if (in_array($kind, ['special_category', 'criminal_offense'], TRUE)) {
          $value['sensitive'] = TRUE;
        }
      }
    }

    foreach ($value as &$child) {
      self::normalizeSchemaPrivacyKindsNode($child);
    }
    unset($child);
  }

  /**
   * Builds a normalized string context for schema-field heuristics.
   *
   * @param array<string, mixed> $field
   *   Schema field.
   * @param array<string, mixed> $section
   *   Parent schema section.
   */
  protected static function fieldSearchContext(array $field, array $section = []): string {
    $parts = [];
    foreach (['type', 'format', 'crm_key', 'id', 'label', 'placeholder'] as $key) {
      $value = self::fieldString($field[$key] ?? NULL);
      if ($value !== NULL) {
        $parts[] = $value;
      }
    }
    foreach (self::fieldStringList($field['options'] ?? NULL) as $value) {
      $parts[] = $value;
    }
    foreach (self::fieldI18nStrings($field['i18n'] ?? NULL) as $value) {
      $parts[] = $value;
    }
    foreach (['title', 'description'] as $key) {
      $value = self::fieldString($section[$key] ?? NULL);
      if ($value !== NULL) {
        $parts[] = $value;
      }
    }
    foreach (self::sectionI18nStrings($section['i18n'] ?? NULL) as $value) {
      $parts[] = $value;
    }
    return self::normalizeSearchText(implode(' ', $parts));
  }

  /**
   * Normalizes search text without depending on the intl extension.
   */
  protected static function normalizeSearchText(string $value): string {
    $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value) ?? $value;
    $value = strtr($value, [
      'Ä' => 'A',
      'Ö' => 'O',
      'Ü' => 'U',
      'ä' => 'a',
      'ö' => 'o',
      'ü' => 'u',
      'ß' => 'ss',
    ]);
    $value = preg_replace('/[_-]+/', ' ', $value) ?? $value;
    return strtolower($value);
  }

  /**
   * Returns the field ids the address groups of a section reference.
   *
   * @param array<string, mixed> $section
   *   Schema section.
   *
   * @return string[]
   *   Street, house number, postcode and city field ids.
   */
  protected static function addressGroupFieldIds(array $section): array {
    $ids = [];
    $groups = is_array($section['addressGroups'] ?? NULL) ? $section['addressGroups'] : [];
    foreach ($groups as $group) {
      $fields = is_array($group) && is_array($group['fields'] ?? NULL) ? $group['fields'] : [];
      foreach ($fields as $field_id) {
        if (is_string($field_id) && $field_id !== '') {
          $ids[] = $field_id;
        }
      }
    }
    return $ids;
  }

  /**
   * Extracts a scalar string from a field value.
   */
  protected static function fieldString(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? NULL : $trimmed;
  }

  /**
   * Extracts scalar strings from a list.
   *
   * @return string[]
   *   Non-empty strings.
   */
  protected static function fieldStringList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }

    $strings = [];
    foreach ($value as $item) {
      $string = self::fieldString($item);
      if ($string !== NULL) {
        $strings[] = $string;
      }
    }
    return $strings;
  }

  /**
   * Extracts user-facing localized field text.
   *
   * @return string[]
   *   Non-empty localized labels, placeholders, and option labels.
   */
  protected static function fieldI18nStrings(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }

    $strings = [];
    foreach ($value as $translation) {
      if (!is_array($translation) || array_is_list($translation)) {
        continue;
      }
      foreach (['label', 'placeholder'] as $key) {
        $string = self::fieldString($translation[$key] ?? NULL);
        if ($string !== NULL) {
          $strings[] = $string;
        }
      }
      $options = $translation['options'] ?? NULL;
      if (is_array($options)) {
        foreach (self::fieldStringList($options) as $string) {
          $strings[] = $string;
        }
      }
    }
    return $strings;
  }

  /**
   * Extracts user-facing localized section text.
   *
   * @return string[]
   *   Non-empty localized section titles and descriptions.
   */
  protected static function sectionI18nStrings(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }

    $strings = [];
    foreach ($value as $translation) {
      if (!is_array($translation) || array_is_list($translation)) {
        continue;
      }
      foreach (['title', 'description'] as $key) {
        $string = self::fieldString($translation[$key] ?? NULL);
        if ($string !== NULL) {
          $strings[] = $string;
        }
      }
    }
    return $strings;
  }

  /**
   * Normalizes stored sensitive flag variants from legacy or imported schemas.
   */
  protected static function isSensitiveFlag(mixed $value): bool {
    if ($value === TRUE || $value === 1) {
      return TRUE;
    }
    if (!is_string($value)) {
      return FALSE;
    }
    return in_array(strtolower(trim($value)), ['true', 'yes', 'on', '1'], TRUE);
  }

  /**
   * Validates the schema-level public submission auth policy.
   */
  protected static function validateAuthPolicy(mixed $auth): void {
    if (!is_array($auth) || array_is_list($auth)) {
      throw new \InvalidArgumentException('The field_schema auth value must be a JSON object.');
    }

    if (array_key_exists('mode', $auth)
      && (!is_string($auth['mode']) || !in_array($auth['mode'], self::ALLOWED_AUTH_MODES, TRUE))) {
      throw new \InvalidArgumentException('The field_schema auth.mode value is not supported.');
    }

    if (array_key_exists('providers', $auth)) {
      if (!is_array($auth['providers']) || !array_is_list($auth['providers'])) {
        throw new \InvalidArgumentException('The field_schema auth.providers value must be a JSON array.');
      }
      foreach ($auth['providers'] as $provider) {
        if (!is_string($provider) || !in_array($provider, self::ALLOWED_AUTH_PROVIDERS, TRUE)) {
          throw new \InvalidArgumentException('The field_schema auth.providers value contains an unsupported provider.');
        }
      }
    }

    if (array_key_exists('collect', $auth)) {
      if (!is_array($auth['collect']) || array_is_list($auth['collect'])) {
        throw new \InvalidArgumentException('The field_schema auth.collect value must be a JSON object.');
      }
      $allowed_keys = ['fullName', 'email'];
      foreach ($auth['collect'] as $key => $value) {
        if (!in_array($key, $allowed_keys, TRUE)) {
          throw new \InvalidArgumentException('The field_schema auth.collect value contains an unsupported key.');
        }
        if (!is_bool($value) && (!is_string($value) || !in_array($value, self::ALLOWED_IDENTITY_REQUIREMENTS, TRUE))) {
          throw new \InvalidArgumentException('The field_schema auth.collect value contains an unsupported requirement.');
        }
      }
    }
  }

  /**
   * Validates the schema-level delivery policy.
   */
  protected static function validateDeliveryPolicy(mixed $delivery): void {
    if (!is_array($delivery) || $delivery === [] || array_is_list($delivery)) {
      throw new \InvalidArgumentException('The field_schema delivery value must be a JSON object.');
    }

    foreach ($delivery as $key => $value) {
      if (!in_array($key, self::ALLOWED_DELIVERY_KEYS, TRUE)) {
        throw new \InvalidArgumentException('The field_schema delivery value contains an unsupported key.');
      }
    }

    if (array_key_exists('processingMode', $delivery)
      && (!is_string($delivery['processingMode']) || !in_array($delivery['processingMode'], self::ALLOWED_PROCESSING_MODES, TRUE))) {
      throw new \InvalidArgumentException('The field_schema delivery.processingMode value is not supported.');
    }

    if (array_key_exists('adapter', $delivery)
      && (!is_string($delivery['adapter']) || !in_array($delivery['adapter'], self::ALLOWED_DELIVERY_ADAPTERS, TRUE))) {
      throw new \InvalidArgumentException('The field_schema delivery.adapter value is not supported.');
    }
  }

  /**
   * Requires a separate permission for automatic or external delivery policy.
   */
  protected static function validateDeliveryPolicyPermission(
    string $schema_value,
    ?string $original_schema_value,
  ): void {
    $new_policy = self::deliveryPolicyFromSchemaValue($schema_value);
    $original_policy = self::deliveryPolicyFromSchemaValue($original_schema_value);

    if ($new_policy === $original_policy) {
      return;
    }

    if (!self::deliveryPolicyRequiresPrivilegedPermission($new_policy)
      && !self::deliveryPolicyRequiresPrivilegedPermission($original_policy)) {
      return;
    }

    if (!\Drupal::currentUser()->hasPermission(self::ADMINISTER_DELIVERY_POLICIES_PERMISSION)) {
      throw new \InvalidArgumentException('Changing automatic or external delivery policy requires the administer application delivery policies permission.');
    }
  }

  /**
   * Extracts the delivery policy object from a schema JSON string.
   *
   * @return array<string, mixed>|null
   *   Delivery policy, or NULL when absent or unreadable.
   */
  protected static function deliveryPolicyFromSchemaValue(?string $schema_value): ?array {
    if ($schema_value === NULL || trim($schema_value) === '') {
      return NULL;
    }

    try {
      $schema = json_decode($schema_value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }

    if (!is_array($schema) || array_is_list($schema) || !array_key_exists('delivery', $schema)) {
      return NULL;
    }

    $delivery = $schema['delivery'];
    if (!is_array($delivery) || $delivery === [] || array_is_list($delivery)) {
      return NULL;
    }

    ksort($delivery);
    return $delivery;
  }

  /**
   * Returns TRUE when a policy can force or externally route delivery.
   *
   * @param array<string, mixed>|null $delivery
   *   Delivery policy.
   */
  protected static function deliveryPolicyRequiresPrivilegedPermission(?array $delivery): bool {
    if ($delivery === NULL) {
      return FALSE;
    }

    return ($delivery['processingMode'] ?? NULL) === 'pass_through'
      || in_array($delivery['adapter'] ?? NULL, ['webhook', 'fit_connect'], TRUE);
  }

  /**
   * Validates one machine-name-like schema identifier and returns it.
   */
  protected static function validateMachineId(mixed $value, string $label, ?int $max_length = NULL): string {
    if (!is_string($value)
      || ($max_length !== NULL && strlen($value) > $max_length)
      || preg_match(self::MACHINE_NAME_PATTERN, $value) !== 1) {
      throw new \InvalidArgumentException($max_length === NULL
        ? sprintf('%s must match ^[a-z][a-z0-9_]*$.', $label)
        : sprintf('%s must be at most %d characters and match ^[a-z][a-z0-9_]*$.', $label, $max_length));
    }
    return $value;
  }

  /**
   * Validates a required non-empty string while accepting the literal "0".
   */
  protected static function validateNonEmptyString(mixed $value, string $label): void {
    if (!is_string($value) || trim($value) === '') {
      throw new \InvalidArgumentException(sprintf(
        '%s must be a non-empty string.',
        $label,
      ));
    }
  }

  /**
   * Validates a JSON string list.
   */
  protected static function validateStringList(mixed $value, string $label): void {
    if (!is_array($value) || !array_is_list($value)) {
      throw new \InvalidArgumentException(sprintf(
        '%s must be a JSON array of strings.',
        $label,
      ));
    }
    foreach ($value as $item) {
      if (!is_string($item)) {
        throw new \InvalidArgumentException(sprintf(
          '%s must contain only strings.',
          $label,
        ));
      }
    }
  }

  /**
   * Validates one field validation regex pattern.
   */
  protected static function validateValidationPattern(string $pattern): void {
    $pattern = trim($pattern);
    if ($pattern === '') {
      return;
    }
    if (strlen($pattern) > self::MAX_VALIDATION_PATTERN_LENGTH) {
      throw new \InvalidArgumentException(sprintf(
        'Validation patterns must be at most %d characters long.',
        self::MAX_VALIDATION_PATTERN_LENGTH,
      ));
    }

    $regex = '/' . str_replace('/', '\/', $pattern) . '/u';
    if (@preg_match($regex, '') === FALSE) {
      throw new \InvalidArgumentException('Validation pattern is not a valid regular expression.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);

    if (!$entities) {
      return;
    }

    foreach ($entities as $entity) {
      $workflow_status = $entity instanceof FormSchemaInterface && $entity->hasField('field_workflow_status')
        ? $entity->get('field_workflow_status')->value
        : NULL;
      if (!$entity instanceof FormSchemaInterface
        || $workflow_status !== 'draft'
        || $entity->isPublished()) {
        throw new EntityStorageException('Only draft form schemas can be deleted.');
      }
    }

    $schema_ids = array_values(array_filter(array_map(
      static fn(FormSchemaInterface $entity): int => (int) $entity->id(),
      $entities
    )));
    if (!$schema_ids) {
      return;
    }

    if (\Drupal::entityTypeManager()->hasDefinition('form_schema_group')) {
      $referencing_groups = \Drupal::entityTypeManager()
        ->getStorage('form_schema_group')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_schemas', $schema_ids, 'IN')
        ->range(0, 1)
        ->execute();

      if ($referencing_groups) {
        throw new EntityStorageException('Cannot delete form schemas while form schema groups reference them.');
      }
    }

    if (!\Drupal::entityTypeManager()->hasDefinition('application')) {
      return;
    }

    $referencing_applications = \Drupal::entityTypeManager()
      ->getStorage('application')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_schema', $schema_ids, 'IN')
      ->range(0, 1)
      ->execute();

    if ($referencing_applications) {
      throw new EntityStorageException('Cannot delete form schemas while applications reference them.');
    }
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
  public function getSchema(): ?string {
    return $this->get('field_schema')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSchema(string $schema): static {
    $this->set('field_schema', $schema);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflowDefinition(): ?string {
    if (!$this->hasField('field_workflow_definition') || $this->get('field_workflow_definition')->isEmpty()) {
      return NULL;
    }
    return $this->get('field_workflow_definition')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setWorkflowDefinition(?string $workflow_definition): static {
    $this->set('field_workflow_definition', $workflow_definition);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaStatus(): string {
    return $this->get('field_workflow_status')->value ?? 'draft';
  }

  /**
   * {@inheritdoc}
   */
  public function setSchemaStatus(string $status): static {
    $this->set('field_workflow_status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicationScope(): string {
    return $this->get('field_publication_scope')->value === 'internet' ? 'internet' : 'intranet';
  }

  /**
   * {@inheritdoc}
   */
  public function setPublicationScope(string $scope): static {
    $this->set('field_publication_scope', $scope);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion(): int {
    return (int) $this->get('field_version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceFilename(): ?string {
    return $this->get('field_source_filename')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getReferencePattern(): ?string {
    if (!$this->hasField('field_reference_pattern') || $this->get('field_reference_pattern')->isEmpty()) {
      return NULL;
    }
    $value = trim((string) $this->get('field_reference_pattern')->value);
    return $value !== '' ? $value : NULL;
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
      ->setLabel(t('Title'))
      ->setDescription(t('The form title, e.g. "Meldung zur Hundesteuer".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('A description of the form.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_schema'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Form Schema'))
      ->setDescription(t('JSON schema defining sections, fields, types, and validation rules.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -5,
        'settings' => [
          'rows' => 20,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_workflow_definition'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Workflow definition'))
      ->setDescription(t('JSON object defining this form schema application workflow. Organisation statuses stay the catalogue; this field selects active statuses, default status, transitions, and transition attributes.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -4,
        'settings' => [
          'rows' => 12,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_source_filename'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source Filename'))
      ->setDescription(t('Original filename of the uploaded PDF or image.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_ai_review_pending'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('AI review pending'))
      ->setDescription(t('The form was created by the AI compiler and has never been published.'))
      ->setDefaultValue(FALSE)
      ->setReadOnly(TRUE);

    $fields['field_workflow_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Schema Status'))
      ->setDescription(t('The workflow status of this form schema.'))
      ->setDefaultValue('draft')
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_publication_scope'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Publication scope'))
      ->setDescription(t('Controls whether this form is published for internet or intranet use.'))
      ->setDefaultValue('intranet')
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'internet' => 'Internet',
        'intranet' => 'Intranet',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_reference_pattern'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Reference number pattern'))
      ->setDescription(t('Optional per-form override for the application reference number pattern. When set, this form gets its own independent sequence counter starting at 1. Tokens: {ORG}, {YEAR}, {SEQ}, {SEQ:N}, {FORM_CODE}, {UUID_SHORT}. Leave empty to inherit the organisation pattern.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version'))
      ->setDescription(t('Auto-incremented version number.'))
      ->setDefaultValue(1)
      ->setRevisionable(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created the form schema.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 15,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setDefaultValue(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the form schema was created.'))
      ->setRevisionable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the form schema was last edited.'))
      ->setRevisionable(TRUE);

    return $fields;
  }

}
