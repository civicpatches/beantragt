<?php

declare(strict_types=1);

namespace Drupal\beantragt_status\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\CitizenStatusProjection;
use Drupal\beantragt_application\WorkflowStatusResolver;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds public status responses for application status links.
 *
 * Loads the application, resolves related entity labels (schema, group),
 * and builds a timeline from entity revisions. No PII is included in
 * the output.
 */
class StatusBuilder {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a StatusBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The status link expiry configuration.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The clock used to enforce expiry.
   * @param \Drupal\beantragt_application\Service\ApplicationDataProtectionService|null $dataProtection
   *   Optional applicant identity protection service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $moduleHandler
   *   Lets other modules adjust the public timeline.
   * @param \Drupal\beantragt_application\Service\CitizenStatusProjection|null $statusProjection
   *   Projects citizen texts; without it the internal labels are shown.
   * @param \Drupal\Core\Language\LanguageManagerInterface|null $languageManager
   *   Resolves the reader's language for citizen texts.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    protected readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    protected readonly ?ModuleHandlerInterface $moduleHandler = NULL,
    protected readonly ?CitizenStatusProjection $statusProjection = NULL,
    protected readonly ?LanguageManagerInterface $languageManager = NULL,
  ) {
    $this->logger = $loggerFactory->get('beantragt_status');
  }

  /**
   * Builds the status response array for a reference number.
   *
   * @param string $reference_number
   *   The application reference number.
   *
   * @return array|null
   *   The status data array, or NULL if the application was not found.
   */
  public function build(string $reference_number): ?array {
    $application = $this->loadApplicationByReference($reference_number);
    if ($application === NULL) {
      return NULL;
    }

    return $this->buildFromApplication($application);
  }

  /**
   * Builds the status response array for a public status token.
   *
   * @param string $public_status_token
   *   The plaintext public status token from the citizen status link.
   * @param string $schema_uuid
   *   The public route schema UUID the token must belong to.
   * @param string|null $jurisdiction_slug
   *   Optional public jurisdiction route slug.
   * @param string|null $organisation_slug
   *   Optional public organisation route slug.
   *
   * @return array|null
   *   The status data array, or NULL if the application was not found.
   */
  public function buildByPublicStatusToken(
    string $public_status_token,
    string $schema_uuid,
    ?string $jurisdiction_slug = NULL,
    ?string $organisation_slug = NULL,
  ): ?array {
    $application = $this->loadApplicationByPublicStatusTokenHash(hash('sha256', trim($public_status_token)));
    if ($application === NULL || !$this->applicationMatchesPublicRoute($application, $schema_uuid, $jurisdiction_slug, $organisation_slug)) {
      return NULL;
    }

    return $this->buildFromApplication($application);
  }

  /**
   * Builds status data after reference-number and email ownership proof.
   *
   * @param string $reference_number
   *   The application reference number.
   * @param string $email
   *   The applicant email address used for the submission.
   *
   * @return array|null
   *   The status data array, or NULL if the proof does not match.
   */
  public function buildByReferenceAndApplicantEmail(string $reference_number, string $email): ?array {
    $application = $this->loadApplicationByReference($reference_number);
    if ($application === NULL || !$this->applicantEmailMatches($application, $email)) {
      return NULL;
    }

    return $this->buildFromApplication($application);
  }

  /**
   * Checks whether a reference number belongs to the submitted email address.
   */
  public function referenceMatchesApplicantEmail(string $reference_number, string $email): bool {
    $application = $this->loadApplicationByReference($reference_number);
    // A staff bulk case carries an unverified manifest email: never send a
    // public status code to it, answer like an unknown reference.
    if ($application !== NULL && $application->hasField('field_origin')
      && $application->get('field_origin')->value === 'bulk') {
      return FALSE;
    }
    if ($application === NULL || !$this->applicantEmailMatches($application, $email)) {
      return FALSE;
    }

    return $this->isPublicSchema($this->submittedSchema($application));
  }

  /**
   * Returns the tenant id for a confirmed application reference.
   */
  public function jurisdictionIdForReference(string $reference_number): ?int {
    $application = $this->loadApplicationByReference($reference_number);
    if (!$application instanceof ContentEntityInterface
      || !$application->hasField('field_jurisdiction')
      || $application->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $id = (int) ($application->get('field_jurisdiction')->target_id ?? 0);
    return $id > 0 ? $id : NULL;
  }

  /**
   * Builds the public status response from an application entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $application
   *   The application entity.
   *
   * @return array|null
   *   The status data array, or NULL if the linked form is not public.
   */
  protected function buildFromApplication(ContentEntityInterface $application): ?array {
    // Resolve form title from the schema revision the citizen submitted.
    $schema_entity = $this->submittedSchema($application);
    if (!$this->isPublicSchema($schema_entity)) {
      return NULL;
    }
    $form_title = $schema_entity->label();

    // Resolve org name from explicit organisation scope, with legacy fallback.
    $org_name = NULL;
    if ($application->hasField('field_organisation') && !$application->get('field_organisation')->isEmpty()) {
      $group = $application->get('field_organisation')->entity;
      if ($group && $group->bundle() === 'org') {
        $org_name = $group->label();
      }
    }
    elseif ($application->hasField('field_group') && !$application->get('field_group')->isEmpty()) {
      $group = $application->get('field_group')->entity;
      if ($group && $group->bundle() === 'org') {
        $org_name = $group->label();
      }
    }

    $timeline = $this->buildTimeline($application);

    $status_info = $this->citizenStatusInfo($application);

    return [
      'reference_number' => $application->get('field_reference_number')->value,
      'status' => $status_info['status'],
      'status_label' => $status_info['label'],
      'status_color' => $status_info['color'],
      'status_icon' => $status_info['icon'],
      'form_title' => $form_title,
      'org_name' => $org_name,
      'submitted_at' => $this->formatTimestamp((int) $application->get('created')->value),
      'updated_at' => $this->formatTimestamp((int) $application->get('changed')->value),
      'timeline' => $timeline,
    ];
  }

  /**
   * Loads an application entity by its public status token hash.
   *
   * @param string $token_hash
   *   The SHA-256 hash of the public status token.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The application entity, or NULL if not found.
   */
  protected function loadApplicationByPublicStatusTokenHash(string $token_hash): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_public_status_token_hash', $token_hash)
      ->condition('field_email_confirmed', TRUE)
      ->condition('status', 1)
      ->range(0, 1);
    $ttl_days = (int) ($this->configFactory->get('beantragt_status.settings')->get('public_status_link_ttl_days') ?? 180);
    if ($ttl_days !== 0) {
      // Created is the submission timestamp, also exposed as submitted_at.
      $query->condition('created', $this->time->getCurrentTime() - max(0, $ttl_days) * 86400, '>');
    }
    $ids = $query->execute();

    if (empty($ids)) {
      return NULL;
    }

    $application = $storage->load(reset($ids));
    return $application instanceof ContentEntityInterface ? $application : NULL;
  }

  /**
   * Checks whether the application belongs to the public route context.
   */
  protected function applicationMatchesPublicRoute(
    ContentEntityInterface $application,
    string $schema_uuid,
    ?string $jurisdiction_slug,
    ?string $organisation_slug,
  ): bool {
    $schema = $application->get('field_schema')->entity;
    if (!$schema instanceof ContentEntityInterface || $schema->uuid() !== $schema_uuid) {
      return FALSE;
    }

    if ($jurisdiction_slug !== NULL && !$this->entitySlugMatches($application, 'field_jurisdiction', $jurisdiction_slug)) {
      return FALSE;
    }

    if ($organisation_slug !== NULL && !$this->entitySlugMatches($application, 'field_organisation', $organisation_slug)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks a referenced entity slug field.
   */
  protected function entitySlugMatches(ContentEntityInterface $entity, string $field_name, string $slug): bool {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return FALSE;
    }

    $referenced = $entity->get($field_name)->entity;
    if (!$referenced instanceof ContentEntityInterface || !$referenced->hasField('field_slug')) {
      return FALSE;
    }

    return (string) $referenced->get('field_slug')->value === $slug;
  }

  /**
   * Checks whether the stored applicant email matches a user-supplied email.
   */
  protected function applicantEmailMatches(ContentEntityInterface $application, string $email): bool {
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      return $this->dataProtection->applicationApplicantEmailMatches($application, $email);
    }

    if ($application->hasField('field_applicant_email') && !$application->get('field_applicant_email')->isEmpty()) {
      $stored_email = (string) $application->get('field_applicant_email')->value;
      return strtolower(trim($stored_email)) === strtolower(trim($email));
    }

    return FALSE;
  }

  /**
   * Loads an application entity by its reference number.
   *
   * @param string $reference_number
   *   The reference number to search for.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The application entity, or NULL if not found.
   */
  protected function loadApplicationByReference(string $reference_number): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_reference_number', $this->normalizeReferenceNumber($reference_number))
      ->condition('field_email_confirmed', TRUE)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $application = $storage->load(reset($ids));
    return $application instanceof ContentEntityInterface ? $application : NULL;
  }

  /**
   * Normalizes a user-supplied reference number for exact entity queries.
   */
  protected function normalizeReferenceNumber(string $reference_number): string {
    return strtoupper(trim($reference_number));
  }

  /**
   * Checks whether a schema may expose public status data.
   *
   * @param mixed $schema_entity
   *   The referenced schema entity.
   *
   * @return bool
   *   TRUE when the schema is explicitly scoped to the internet.
   */
  protected function isPublicSchema(mixed $schema_entity): bool {
    return $schema_entity instanceof ContentEntityInterface
      && PublicSubmission::isPublicInternetSchema($schema_entity);
  }

  /**
   * Returns the schema revision the application was submitted against.
   */
  protected function submittedSchema(ContentEntityInterface $application): ?ContentEntityInterface {
    $schema = PublicSubmission::submittedSchema($application, $this->entityTypeManager);
    return $schema instanceof ContentEntityInterface ? $schema : NULL;
  }

  /**
   * Builds a timeline array from entity revisions.
   *
   * Each revision where the workflow status changed creates a timeline
   * entry. The first entry always represents the initial application.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $application
   *   The application entity.
   *
   * @return array
   *   Array of timeline entries, each with status, timestamp, and
   *   optionally an actor description (role only, no personal data).
   */
  protected function buildTimeline(ContentEntityInterface $application): array {
    if ($this->statusProjection !== NULL && $application instanceof ApplicationInterface) {
      // Citizen texts, connection texts, internal steps collapsed.
      return $this->statusProjection->timeline($application, $this->langcode());
    }
    $storage = $this->entityTypeManager->getStorage('application');

    // Load all revision IDs, oldest first.
    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $application->id())
      ->sort('revision_id', 'ASC')
      ->execute();

    if (empty($revision_ids)) {
      return [];
    }

    $timeline = [];
    $previous_status = NULL;

    foreach (array_keys($revision_ids) as $revision_id) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $revision */
      $revision = $storage->loadRevision($revision_id);
      if ($revision === NULL) {
        continue;
      }

      $status_info = $this->workflowStatusInfo($revision);
      $status = $status_info['status'];
      $revision_created = (int) $revision->get('revision_created')->value;

      // Include the first revision (initial application) and any revision
      // where the workflow status changed.
      if ($previous_status === NULL || $status !== $previous_status) {
        $entry = [
          'status' => $status,
          'label' => $status_info['label'],
          'color' => $status_info['color'],
          'icon' => $status_info['icon'],
          'timestamp' => $this->formatTimestamp($revision_created),
        ];

        // For the first revision, no actor. For subsequent revisions,
        // indicate that a case worker made the change (no PII).
        if ($previous_status !== NULL) {
          $entry['actor'] = $this->resolveActorLabel($revision);
        }

        $timeline[] = array_filter($entry, static fn($value): bool => $value !== '');
        $previous_status = $status;
      }
    }

    if ($application instanceof ApplicationInterface) {
      // The anonymous lookup carries no UI locale; the current language
      // decides, like the German-only status catalogue labels next to it.
      $langcode = NULL;
      $this->moduleHandler?->alter('beantragt_application_timeline', $timeline, $application, $langcode);
    }
    return $timeline;
  }

  /**
   * Resolves the current status as applicants see it.
   *
   * @return array{status: string, label: string, color: string, icon: string}
   *   Public workflow status metadata with the citizen text as label.
   */
  protected function citizenStatusInfo(ContentEntityInterface $application): array {
    $info = $this->workflowStatusInfo($application);
    $status = $application->hasField('field_workflow_status') && !$application->get('field_workflow_status')->isEmpty()
      ? $application->get('field_workflow_status')->entity
      : NULL;
    if ($this->statusProjection === NULL || !$application instanceof ApplicationInterface || !$status instanceof WorkflowStatusInterface) {
      return $info;
    }
    $entry = $this->statusProjection->entry($application, $status, $this->langcode());
    $info['label'] = $entry['label'];
    return $info;
  }

  /**
   * The language of the anonymous lookup: the current language.
   */
  protected function langcode(): string {
    $langcode = $this->languageManager?->getCurrentLanguage()->getId() ?? 'de';
    return in_array($langcode, WorkflowStatusInterface::CITIZEN_LANGCODES, TRUE) ? $langcode : 'de';
  }

  /**
   * Resolves safe public workflow-status metadata.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $application
   *   The application entity or revision.
   *
   * @return array{status: string, label: string, color: string, icon: string}
   *   Public workflow status metadata.
   */
  protected function workflowStatusInfo(ContentEntityInterface $application): array {
    $info = [
      'status' => WorkflowStatusResolver::machineName($application),
      'label' => '',
      'color' => '',
      'icon' => '',
    ];

    if (!$application->hasField('field_workflow_status') || $application->get('field_workflow_status')->isEmpty()) {
      return $info;
    }

    $status_entity = $application->get('field_workflow_status')->entity;
    if (!$status_entity instanceof FieldableEntityInterface) {
      return $info;
    }

    $label = trim((string) ($status_entity->label() ?? ''));
    $info['label'] = $label;
    $info['color'] = $this->fieldValue($status_entity, 'field_color');
    $info['icon'] = $this->fieldValue($status_entity, 'field_icon');

    return $info;
  }

  /**
   * Reads a scalar field value from a fieldable entity.
   */
  private function fieldValue(FieldableEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    return trim((string) ($entity->get($field_name)->value ?? ''));
  }

  /**
   * Resolves a safe actor label from a revision.
   *
   * Returns a role-based description instead of personal data.
   * Anonymous/system actions get no actor. Staff actions get a generic
   * role label.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $revision
   *   The entity revision.
   *
   * @return string|null
   *   A role-based actor label, or NULL for system/anonymous actions.
   */
  protected function resolveActorLabel(ContentEntityInterface $revision): ?string {
    $revision_user_id = (int) $revision->get('revision_user')->target_id;

    // Anonymous or system user.
    if ($revision_user_id <= 0) {
      return NULL;
    }

    // User ID 1 is always staff.
    if ($revision_user_id === 1) {
      return 'staff';
    }

    // Load the user to check roles. Return generic 'staff' label to avoid
    // disclosing internal role hierarchy (GDPR Art. 5(1)(a)).
    $user = $this->entityTypeManager->getStorage('user')->load($revision_user_id);
    if ($user === NULL) {
      return NULL;
    }

    if (BeantragtRoles::isStaffAccount($user)) {
      return 'staff';
    }

    // Authenticated user without a staff role (citizen).
    return NULL;
  }

  /**
   * Formats a UNIX timestamp as an ISO 8601 string.
   *
   * @param int $timestamp
   *   The UNIX timestamp.
   *
   * @return string
   *   The formatted date string.
   */
  protected function formatTimestamp(int $timestamp): string {
    $date = DrupalDateTime::createFromTimestamp($timestamp);
    return $date->format('c');
  }

}
