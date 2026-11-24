<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends notification emails for applications.
 *
 * Resolves token replacements from application entities, loads related
 * schema and group information, and dispatches emails via Drupal's
 * mail system using resolved templates.
 */
class NotificationMailer {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a NotificationMailer.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\beantragt_application\Service\ApplicationDataProtectionService|null $dataProtection
   *   Optional applicant identity protection service.
   * @param \Drupal\beantragt_notification\Service\NotificationTemplateResolver|null $templateResolver
   *   Optional jurisdiction-aware template resolver.
   * @param \Drupal\Core\Session\AccountInterface|null $currentUser
   *   The acting account, when assignment self-notifications can be checked.
   * @param \Drupal\beantragt_notification\Service\NotificationOutcomeCollector|null $outcomeCollector
   *   Optional request-scoped staff notification outcome collector.
   */
  public function __construct(
    protected readonly MailManagerInterface $mailManager,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    protected readonly ?NotificationTemplateResolver $templateResolver = NULL,
    protected readonly ?AccountInterface $currentUser = NULL,
    protected readonly ?NotificationOutcomeCollector $outcomeCollector = NULL,
  ) {
    $this->logger = $logger;
  }

  /**
   * Sends a notification email to the citizen who submitted the form.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param string $templateKey
   *   The mail template key (e.g., 'application_confirmation', 'approval').
   * @param array $extraTokens
   *   Additional token replacements to merge with the resolved tokens.
   */
  public function sendCitizenNotification(
    ApplicationInterface $application,
    string $templateKey,
    array $extraTokens = [],
  ): void {
    $this->sendCitizenNotificationWithResult($application, $templateKey, $extraTokens);
  }

  /**
   * Reports whether the mail transport accepted the citizen notification.
   */
  public function sendCitizenNotificationWithResult(
    ApplicationInterface $application,
    string $templateKey,
    array $extraTokens = [],
  ): bool {
    return $this->deliverCitizenNotification($application, $templateKey, $extraTokens);
  }

  /**
   * Sends confirmation mail with a fallback address bound to the submission.
   */
  public function sendApplicationConfirmationRequest(
    ApplicationInterface $application,
    string $confirmationUrl,
    ?string $fallbackRecipient,
  ): void {
    $this->deliverCitizenNotification(
      $application,
      'application_confirmation_request',
      ['@confirmation_url' => $confirmationUrl],
      $fallbackRecipient,
    );
  }

  /**
   * Sends a citizen notification through the configured mail transport.
   */
  private function deliverCitizenNotification(
    ApplicationInterface $application,
    string $templateKey,
    array $extraTokens,
    ?string $fallbackRecipient = NULL,
  ): bool {
    if ($application->hasField('field_origin') && $application->get('field_origin')->value === 'bulk') {
      return FALSE;
    }
    $langcode = $this->citizenNotificationLangcode($application);
    $resolvedTemplate = $this->resolveMailTemplate($application, $templateKey, $langcode);
    if ($resolvedTemplate !== NULL && !$resolvedTemplate['enabled']) {
      return FALSE;
    }
    if ($resolvedTemplate === NULL && !$this->isTemplateEnabled($templateKey)) {
      return FALSE;
    }

    $email = $this->resolveApplicantEmail($application);
    if (empty($email) && $fallbackRecipient !== NULL) {
      $email = $this->normalizeEmail($fallbackRecipient);
    }
    if (empty($email)) {
      $this->logger->notice(
        'Cannot send @template for application @id: no applicant email.',
        ['@template' => $templateKey, '@id' => $application->id() ?? 'new']
      );
      return FALSE;
    }

    $replacements = array_merge($this->resolveTokens($application), $extraTokens);
    // Applicants never see the name of the person handling their case, even
    // when a workspace adds @assigned_to to a citizen template. The team
    // (@assigned_team) is an office, not a person, and stays.
    $replacements['@assigned_to'] = '';
    $params = $this->mailParams($application, $replacements, $resolvedTemplate, $langcode);
    if ($templateKey === 'application_confirmation_request') {
      $params['consentNotice'] = self::consentConfirmationNotice($application, $langcode);
    }

    $result = $this->mailManager->mail(
      'beantragt_notification',
      $templateKey,
      $email,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    // A reserved demo domain is never deliverable, so the suppressed mail is
    // handled, not failed. The staff path counts it the same way.
    if ($this->wasReservedRecipientSuppressed($result)) {
      return TRUE;
    }

    if ($result['result'] !== TRUE) {
      $this->logger->error(
        'Failed to send @template email to citizen (*@domain) for application @id.',
        [
          '@template' => $templateKey,
          '@domain' => substr(strrchr($email, '@') ?: '@unknown', 1),
          '@id' => $application->id() ?? 'new',
        ]
      );
    }
    else {
      $this->logger->info(
        'Sent @template email to citizen (*@domain) for application @id.',
        [
          '@template' => $templateKey,
          '@domain' => substr(strstr($email, '@') ?: '@unknown', 1),
          '@id' => $application->id() ?? 'new',
        ]
      );
    }
    return ($result['result'] ?? FALSE) === TRUE;
  }

  /**
   * Sends a notification email to the responsible staff (Sachbearbeiter).
   *
   * Resolves the organisation mailbox first and falls back to the jurisdiction
   * mailbox. If no workspace mailbox is found, the notification is skipped
   * with a log entry.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param string $templateKey
   *   The mail template key, for example 'new_application_alert'.
   * @param array $extraTokens
   *   Additional token replacements to merge with the resolved tokens.
   * @param string $recipientScope
   *   Recipient scope used for staff recipient resolution.
   *
   * @return bool
   *   TRUE when Drupal accepted the mail for delivery.
   */
  public function sendStaffNotification(
    ApplicationInterface $application,
    string $templateKey,
    array $extraTokens = [],
    string $recipientScope = ResubmissionReminderResolver::SCOPE_ORG,
  ): bool {
    return $this->sendStaffNotificationOutcome($application, $templateKey, $extraTokens, $recipientScope) === StaffMailOutcome::Sent;
  }

  /**
   * Distinguishes permanent skips from retryable staff mail transport failures.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param string $templateKey
   *   The template key.
   * @param array<string, string> $extraTokens
   *   Additional token replacements.
   * @param string $recipientScope
   *   The recipient resolution scope.
   */
  public function sendStaffNotificationOutcome(
    ApplicationInterface $application,
    string $templateKey,
    array $extraTokens = [],
    string $recipientScope = ResubmissionReminderResolver::SCOPE_ORG,
  ): StaffMailOutcome {
    $collect_outcome = $templateKey === 'delegation_alert';
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $resolvedTemplate = $this->resolveMailTemplate($application, $templateKey, $langcode);
    if ($resolvedTemplate !== NULL && !$resolvedTemplate['enabled']) {
      if ($collect_outcome) {
        $this->recordOutcome($templateKey, NULL, 'skipped_disabled');
      }
      return StaffMailOutcome::Disabled;
    }
    if ($resolvedTemplate === NULL && !$this->isTemplateEnabled($templateKey)) {
      if ($collect_outcome) {
        $this->recordOutcome($templateKey, NULL, 'skipped_disabled');
      }
      return StaffMailOutcome::Disabled;
    }

    $recipient = $this->resolveStaffRecipient($application, $recipientScope);
    if (empty($recipient)) {
      $this->logger->notice(
        'Cannot send @template for application @id: no staff recipient found for scope @scope.',
        [
          '@template' => $templateKey,
          '@id' => $application->id() ?? 'new',
          '@scope' => $recipientScope,
        ]
      );
      if ($collect_outcome) {
        $this->recordOutcome($templateKey, NULL, 'skipped_no_recipient');
      }
      return StaffMailOutcome::NoRecipient;
    }

    $replacements = array_merge($this->staffStatusTokens($application, $this->resolveTokens($application)), $extraTokens);
    if ($templateKey === 'resubmission_reminder') {
      $replacements['@full_name'] = '';
      $replacements['@citizen_application_url'] = '';
      $replacements['@admin_application_url'] = '';
    }

    $result = $this->mailManager->mail(
      'beantragt_notification',
      $templateKey,
      $recipient,
      $langcode,
      $this->mailParams($application, $replacements, $resolvedTemplate, $langcode),
      NULL,
      TRUE
    );

    if ($this->wasReservedRecipientSuppressed($result)) {
      if ($collect_outcome) {
        $this->recordOutcome($templateKey, 'org_mailbox', 'skipped_reserved_recipient');
      }
      return StaffMailOutcome::Sent;
    }

    if ($result['result'] !== TRUE) {
      $this->logger->error(
        'Failed to send @template email to staff (*@domain) for application @id.',
        [
          '@template' => $templateKey,
          '@domain' => _beantragt_notification_mail_recipient_domain($recipient),
          '@id' => $application->id() ?? 'new',
        ]
      );
      if ($collect_outcome) {
        $this->recordOutcome($templateKey, 'org_mailbox', 'failed');
      }
      return StaffMailOutcome::TransportError;
    }

    $this->logger->info(
      'Sent @template email to staff (*@domain) for application @id.',
      [
        '@template' => $templateKey,
        '@email' => $recipient,
        '@id' => $application->id() ?? 'new',
      ]
    );
    if ($collect_outcome) {
      $this->recordOutcome($templateKey, 'org_mailbox', 'sent');
    }
    return StaffMailOutcome::Sent;
  }

  /**
   * Sends a notification email to the newly assigned person or team mailbox.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   */
  public function sendAssignmentNotification(ApplicationInterface $application): void {
    $templateKey = 'assignment_alert';
    $recipient = $this->resolveAssignmentRecipientContext($application);
    if ($recipient === NULL) {
      $this->logger->notice(
        'Cannot send assignment notification for application @id: no assignment recipient found.',
        ['@id' => $application->id() ?? 'new']
      );
      $this->recordOutcome($templateKey, NULL, 'skipped_no_recipient');
      return;
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $assignee = $recipient['assignee'];
    if ($assignee instanceof UserInterface) {
      if ($this->currentUser instanceof AccountInterface
        && (int) $this->currentUser->id() > 0
        && (int) $this->currentUser->id() === (int) $assignee->id()) {
        $this->recordOutcome($templateKey, 'person', 'skipped_self_assignment');
        return;
      }

      $preferred_langcode = (string) $assignee->getPreferredLangcode(FALSE);
      if ($preferred_langcode !== '') {
        $langcode = $preferred_langcode;
      }
    }

    $resolvedTemplate = $this->resolveMailTemplate($application, $templateKey, $langcode);
    if ($resolvedTemplate !== NULL && !$resolvedTemplate['enabled']) {
      $this->recordOutcome($templateKey, $recipient['recipient_kind'], 'skipped_disabled');
      return;
    }
    if ($resolvedTemplate === NULL && !$this->isTemplateEnabled($templateKey)) {
      $this->recordOutcome($templateKey, $recipient['recipient_kind'], 'skipped_disabled');
      return;
    }

    $result = $this->mailManager->mail(
      'beantragt_notification',
      $templateKey,
      $recipient['email'],
      $langcode,
      $this->mailParams($application, $this->staffStatusTokens($application, $this->resolveTokens($application)), $resolvedTemplate, $langcode),
      NULL,
      TRUE
    );

    if ($this->wasReservedRecipientSuppressed($result)) {
      $this->recordOutcome($templateKey, $recipient['recipient_kind'], 'skipped_reserved_recipient');
      return;
    }

    if ($result['result'] !== TRUE) {
      $this->logger->error(
        'Failed to send assignment notification for application @id.',
        ['@id' => $application->id() ?? 'new']
      );
      $this->recordOutcome($templateKey, $recipient['recipient_kind'], 'failed');
    }
    else {
      $this->logger->info(
        'Sent assignment notification for application @id.',
        ['@id' => $application->id() ?? 'new']
      );
      $this->recordOutcome($templateKey, $recipient['recipient_kind'], 'sent');
    }
  }

  /**
   * Returns TRUE when the mail alter fence skipped a reserved demo recipient.
   *
   * Core reports an altered-away message as result NULL, which would otherwise
   * look like a transport failure to the callers below.
   *
   * @param array<string, mixed> $result
   *   The message array returned by the mail manager.
   */
  private function wasReservedRecipientSuppressed(array $result): bool {
    return !empty($result['beantragt_reserved_recipient_suppressed']);
  }

  /**
   * Records a PII-free staff notification outcome when collection is enabled.
   */
  private function recordOutcome(string $key, ?string $recipient_kind, string $status): void {
    $this->outcomeCollector?->record([
      'key' => $key,
      'recipient_kind' => $recipient_kind,
      'status' => $status,
    ]);
  }

  /**
   * Resolves all token replacements from an application.
   *
   * Reads fields from the application entity, its related schema, and
   * the org group to build a complete set of token replacements.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   *
   * @return array
   *   Associative array of token replacements keyed by token name.
   */
  public function resolveTokens(ApplicationInterface $application): array {
    $tokens = [
      '@reference_number' => $application->getReferenceNumber() ?? '',
      '@application_date' => date('d.m.Y', $application->getCreatedTime()),
      '@new_status' => $application->getWorkflowStatus(),
      '@old_status' => '',
      '@status_internal' => $application->getWorkflowStatus(),
      '@rejection_reason' => '',
      '@assigned_to' => '',
      '@assigned_team' => '',
      '@full_name' => '',
      '@form_title' => '',
      '@org_name' => '',
      '@escalation_days' => '',
      '@due_date' => '',
      '@citizen_application_url' => '',
      '@admin_application_url' => '',
      '@confirmation_url' => '',
    ];

    // Full name from CRM data or applicant email as fallback.
    $identity_linkage_protected = $this->identityLinkageIsProtected($application);
    $crmData = $identity_linkage_protected ? NULL : $application->getCrmData();
    if ($crmData) {
      $crm = json_decode($crmData, TRUE);
      if (is_array($crm)) {
        $tokens['@full_name'] = $this->sanitizeToken($crm['person.full_name'] ?? $crm['full_name'] ?? $crm['name'] ?? '');
      }
    }
    if (!$identity_linkage_protected && empty($tokens['@full_name'])) {
      $tokens['@full_name'] = $application->getApplicantEmail() ?? '';
    }

    // Schema / form title.
    $schema = $application->get('field_schema')->entity;
    if ($schema) {
      $tokens['@form_title'] = $this->sanitizeToken($schema->label() ?? '');
    }

    // Assigned user display name.
    if ($application->hasField('field_assigned_to') && !$application->get('field_assigned_to')->isEmpty()) {
      $assignee = $application->get('field_assigned_to')->entity;
      if ($assignee) {
        $tokens['@assigned_to'] = $this->sanitizeToken($assignee->getDisplayName());
      }
    }

    if ($application->hasField('field_assigned_team') && !$application->get('field_assigned_team')->isEmpty()) {
      $team = $application->get('field_assigned_team')->entity;
      if ($team instanceof EntityInterface) {
        $tokens['@assigned_team'] = $this->sanitizeToken($team->label() ?? '');
      }
    }

    $tokens['@due_date'] = $this->formatDueDateToken($application);

    // Org name from group.
    $orgGroup = $this->loadOrgGroup($application);
    if ($orgGroup) {
      $tokens['@org_name'] = $this->sanitizeToken($orgGroup->label() ?? '');
    }

    $tokens = array_merge($tokens, $this->resolveApplicationUrlTokens($application, $orgGroup));

    // Old status from original entity (available during presave/event).
    if (isset($application->original) && $application->original instanceof ApplicationInterface) {
      $tokens['@old_status'] = $application->original->getWorkflowStatus();
    }

    // Citizen mails name statuses with the text applicants see, in their
    // language; staff mails get the internal label (see staffStatusTokens()).
    $current = $this->statusEntity($application);
    if ($current instanceof WorkflowStatusInterface) {
      $tokens['@status_internal'] = $this->sanitizeToken((string) ($current->label() ?? ''));
      $tokens['@new_status'] = $this->sanitizeToken($current->getCitizenLabel($this->citizenNotificationLangcode($application)));
    }
    $original = isset($application->original) && $application->original instanceof ApplicationInterface ? $this->statusEntity($application->original) : NULL;
    if ($original instanceof WorkflowStatusInterface) {
      $tokens['@old_status'] = $this->sanitizeToken($original->getCitizenLabel($this->citizenNotificationLangcode($application)));
    }

    return $tokens;
  }

  /**
   * Status tokens for staff mails: internal labels, never citizen texts.
   *
   * Staff templates name the current status with @new_status (and newer
   * ones with @status_internal); both carry the internal label there.
   *
   * @return array<string, string>
   *   Token replacements.
   */
  private function staffStatusTokens(ApplicationInterface $application, array $tokens): array {
    $tokens['@new_status'] = $tokens['@status_internal'] ?? $application->getWorkflowStatus();
    $original = isset($application->original) && $application->original instanceof ApplicationInterface ? $this->statusEntity($application->original) : NULL;
    if ($original instanceof WorkflowStatusInterface) {
      $tokens['@old_status'] = $this->sanitizeToken((string) ($original->label() ?? ''));
    }
    return $tokens;
  }

  /**
   * Loads the workflow status an application references.
   */
  private function statusEntity(ApplicationInterface $application): ?WorkflowStatusInterface {
    if (!$application->hasField('field_workflow_status') || $application->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }
    $status = $application->get('field_workflow_status')->entity;
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Builds application detail links for citizen and staff email templates.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param \Drupal\Core\Entity\EntityInterface|null $orgGroup
   *   The resolved organisation group, if available.
   *
   * @return array<string, string>
   *   URL tokens keyed by template token name.
   */
  private function resolveApplicationUrlTokens(ApplicationInterface $application, ?EntityInterface $orgGroup): array {
    $tokens = [
      '@citizen_application_url' => '',
      '@admin_application_url' => '',
    ];

    $application_uuid = $application->uuid();
    if (!is_string($application_uuid) || $application_uuid === '') {
      return $tokens;
    }

    $workspace_path = $this->resolveWorkspacePath($application, $orgGroup);
    if ($workspace_path === NULL) {
      return $tokens;
    }

    $encoded_uuid = rawurlencode($application_uuid);
    $tokens['@citizen_application_url'] = $this->buildFrontendUrl($workspace_path . '/meine-antraege/' . $encoded_uuid);
    $tokens['@admin_application_url'] = $this->buildFrontendUrl($workspace_path . '/admin/applications/' . $encoded_uuid);

    return $tokens;
  }

  /**
   * Resolves the public workspace path for an application.
   */
  private function resolveWorkspacePath(ApplicationInterface $application, ?EntityInterface $orgGroup): ?string {
    $jurisdiction = $this->loadJurisdictionGroup($application, $orgGroup);
    $jurisdiction_slug = $this->entitySlug($jurisdiction);
    if ($jurisdiction_slug === NULL) {
      return NULL;
    }

    $organisation_slug = $this->entitySlug($orgGroup);
    $path = '/' . rawurlencode($jurisdiction_slug);
    if ($organisation_slug !== NULL) {
      $path .= '/' . rawurlencode($organisation_slug);
    }

    return $path;
  }

  /**
   * Loads the jurisdiction group referenced by the application or org.
   */
  private function loadJurisdictionGroup(ApplicationInterface $application, ?EntityInterface $orgGroup): ?EntityInterface {
    $groupId = $this->fieldTargetId($application, 'field_jurisdiction');
    if ($groupId !== NULL) {
      return $this->loadGroupById($groupId, 'jur');
    }

    $jurisdiction = $this->referencedEntity($orgGroup, 'field_jurisdiction');
    if ($jurisdiction) {
      return $jurisdiction;
    }

    $groupId = $this->fieldTargetId($orgGroup, 'field_jurisdiction');
    if ($groupId !== NULL) {
      return $this->loadGroupById($groupId, 'jur');
    }

    $groupId = $this->fieldTargetId($application, 'field_group');
    if ($groupId !== NULL) {
      return $this->loadGroupById($groupId, 'jur');
    }

    return NULL;
  }

  /**
   * Returns a normalized group slug, or NULL if none is available.
   */
  private function entitySlug(?EntityInterface $entity): ?string {
    if (!$entity instanceof FieldableEntityInterface
      || !$entity->hasField('field_slug')
      || $entity->get('field_slug')->isEmpty()) {
      return NULL;
    }

    $slug = strtolower(trim((string) $entity->get('field_slug')->value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Reads an entity reference target id from a fieldable entity.
   */
  private function fieldTargetId(?EntityInterface $entity, string $fieldName): ?int {
    if (!$entity instanceof FieldableEntityInterface
      || !$entity->hasField($fieldName)
      || $entity->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $target_id = (int) $entity->get($fieldName)->target_id;
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * Reads an already loaded entity reference target from a fieldable entity.
   */
  private function referencedEntity(?EntityInterface $entity, string $fieldName): ?EntityInterface {
    if (!$entity instanceof FieldableEntityInterface
      || !$entity->hasField($fieldName)
      || $entity->get($fieldName)->isEmpty()) {
      return NULL;
    }

    $referenced = $entity->get($fieldName)->entity;
    return $referenced instanceof EntityInterface ? $referenced : NULL;
  }

  /**
   * Loads a group by internal id and optional bundle.
   */
  private function loadGroupById(int $groupId, ?string $bundle = NULL): ?EntityInterface {
    if ($groupId <= 0) {
      return NULL;
    }

    try {
      $group = $this->entityTypeManager->getStorage('group')->load($groupId);
      if (!$group instanceof EntityInterface) {
        return NULL;
      }
      if ($bundle !== NULL && $group->bundle() !== $bundle) {
        return NULL;
      }
      return $group;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to load group @id for notification link: @message',
        [
          '@id' => $groupId,
          '@message' => $e->getMessage(),
        ]
      );
      return NULL;
    }
  }

  /**
   * Prefixes a frontend route path with the configured frontend base URL.
   */
  private function buildFrontendUrl(string $path): string {
    $base_url = $this->frontendBaseUrl();
    return $base_url !== '' ? $base_url . $path : '';
  }

  /**
   * Resolves the configured frontend base URL.
   */
  private function frontendBaseUrl(): string {
    $settings = $this->configFactory->get('beantragt_group.settings');
    return $this->normalizeFrontendBaseUrl((string) ($settings->get('frontend_base_url') ?? ''));
  }

  /**
   * Allows only absolute HTTP(S) frontend base URLs.
   */
  private function normalizeFrontendBaseUrl(string $baseUrl): string {
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') {
      return '';
    }

    $parts = parse_url($baseUrl);
    if (!is_array($parts)
      || empty($parts['scheme'])
      || empty($parts['host'])
      || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], TRUE)) {
      return '';
    }

    return rtrim($baseUrl, '/');
  }

  /**
   * Sanitizes a user-supplied value for safe inclusion in email templates.
   *
   * Strips HTML tags and limits length to prevent injection and abuse.
   *
   * @param string $value
   *   The raw value.
   * @param int $maxLength
   *   Maximum allowed length.
   *
   * @return string
   *   The sanitized value.
   */
  protected function sanitizeToken(string $value, int $maxLength = 500): string {
    $clean = strip_tags($value);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim(mb_substr($clean, 0, $maxLength));
  }

  /**
   * Formats the application due date token for staff templates.
   */
  private function formatDueDateToken(ApplicationInterface $application): string {
    if (!$application->hasField('field_due_date') || $application->get('field_due_date')->isEmpty()) {
      return '';
    }

    $value = trim((string) $application->get('field_due_date')->value);
    if ($value === '') {
      return '';
    }

    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
    $errors = \DateTimeImmutable::getLastErrors();
    if ($date === FALSE || (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
      return '';
    }

    return $date->format('d.m.Y');
  }

  /**
   * Checks whether a template is enabled in configuration.
   *
   * @param string $templateKey
   *   The template key to check.
   *
   * @return bool
   *   TRUE if the template is enabled.
   */
  protected function isTemplateEnabled(string $templateKey): bool {
    $settings = $this->configFactory->get('beantragt_notification.settings');
    return !empty($settings->get('enabled_templates.' . $templateKey));
  }

  /**
   * Resolves jurisdiction-aware raw template text for a mail send.
   *
   * @return array{subject: string, body: string, enabled: bool}|null
   *   The resolved template, or NULL when the optional resolver is absent.
   */
  private function resolveMailTemplate(ApplicationInterface $application, string $templateKey, string $langcode): ?array {
    if (!$this->usesResolvedTemplate($templateKey) || !$this->templateResolver instanceof NotificationTemplateResolver) {
      return NULL;
    }

    return $this->templateResolver->resolve(
      $this->resolveJurisdictionGroup($application),
      $templateKey,
      $langcode,
    );
  }

  /**
   * Returns TRUE when the send path should use jurisdiction template resolving.
   */
  private function usesResolvedTemplate(string $templateKey): bool {
    return !in_array($templateKey, ['escalation_alert', 'deadline_alert'], TRUE);
  }

  /**
   * Builds Drupal mail params with optional resolver-provided template text.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The jurisdiction-scoped application being mailed about.
   * @param array<string, string> $replacements
   *   Token replacements keyed by Drupal mail token.
   * @param array{subject: string, body: string, enabled: bool}|null $resolvedTemplate
   *   Resolved raw template text and enabled state.
   * @param string $langcode
   *   Message language used for translated jurisdiction fields.
   *
   * @return array{replacements: array<string, string>, emailFooter: string, jurisdiction_id: int|string|null, resolvedTemplate?: array{subject: string, body: string}}
   *   Mail params for hook_mail().
   */
  private function mailParams(
    ApplicationInterface $application,
    array $replacements,
    ?array $resolvedTemplate,
    string $langcode,
  ): array {
    $jurisdiction = $this->resolveJurisdictionGroup($application);
    $params = [
      'replacements' => $replacements,
      'emailFooter' => $this->resolveJurisdictionEmailFooter($application, $langcode),
      'jurisdiction_id' => $jurisdiction?->id(),
    ];
    if ($resolvedTemplate !== NULL) {
      $params['resolvedTemplate'] = [
        'subject' => $resolvedTemplate['subject'],
        'body' => $resolvedTemplate['body'],
      ];
    }
    return $params;
  }

  /**
   * Says in the confirmation request which consents the link makes effective.
   *
   * An anonymous application syncs the applicant profile only after the
   * confirmation link, so the click turns the consents given with the
   * application into effective ones. The person who proves the mailbox must
   * learn that, because the mail otherwise only speaks of processing the
   * submission. Appended by code, not by a template token, so customised
   * and stored templates carry it as well. Empty without consent.
   */
  public static function consentConfirmationNotice(ApplicationInterface $application, string $langcode): string {
    if (!$application->hasProfileConsent()) {
      return '';
    }
    $record = $application->getProfileConsentRecord();
    $stelle = is_array($record['scope'] ?? NULL) && is_string($record['scope']['label'] ?? NULL)
      ? trim($record['scope']['label'])
      : '';
    $bank = $application->hasBankConsent();
    if (str_starts_with(strtolower($langcode), 'en')) {
      $what = $bank ? 'your contact details and your bank details' : 'your contact details';
      $where = $stelle !== '' ? ' to ' . $stelle : '';
      return 'With this application you consented to storing ' . $what . ' in your profile for future applications' . $where . '. '
        . 'Confirming your email address makes this consent effective. '
        . 'If you did not submit this application or give this consent yourself, please do not confirm. '
        . 'You can withdraw the consent at any time under "My Data".';
    }
    $what = $bank ? 'Ihre Kontaktdaten und Ihre Bankverbindung' : 'Ihre Kontaktdaten';
    $where = $stelle !== '' ? ' bei ' . $stelle : '';
    return 'Mit diesem Antrag haben Sie eingewilligt, dass ' . $what . ' für künftige Anträge' . $where . ' in Ihrem Profil gespeichert werden. '
      . 'Mit der Bestätigung Ihrer E-Mail-Adresse wird diese Einwilligung wirksam. '
      . 'Wenn Sie diesen Antrag nicht gestellt oder diese Einwilligung nicht selbst gegeben haben, bestätigen Sie bitte nicht. '
      . 'Sie können die Einwilligung jederzeit unter „Meine Daten“ widerrufen.';
  }

  /**
   * Resolves the plain-text footer from the template jurisdiction.
   */
  private function resolveJurisdictionEmailFooter(ApplicationInterface $application, string $langcode): string {
    $jurisdiction = $this->resolveJurisdictionGroup($application);
    if ($jurisdiction instanceof GroupInterface && $jurisdiction->hasTranslation($langcode)) {
      $jurisdiction = $jurisdiction->getTranslation($langcode);
    }
    if (!$jurisdiction instanceof FieldableEntityInterface
      || !$jurisdiction->hasField('field_email_footer')
      || $jurisdiction->get('field_email_footer')->isEmpty()) {
      return '';
    }

    return trim(strip_tags((string) $jurisdiction->get('field_email_footer')->value));
  }

  /**
   * Resolves the jurisdiction group for jurisdiction-aware mail templates.
   */
  private function resolveJurisdictionGroup(ApplicationInterface $application): ?GroupInterface {
    $orgGroup = $this->loadOrgGroup($application);
    $jurisdiction = $this->loadJurisdictionGroup($application, $orgGroup);
    return $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL;
  }

  /**
   * Resolves the citizen-facing notification language.
   */
  public function citizenNotificationLangcode(ApplicationInterface $application): string {
    if ($application->hasField('field_workflow_attributes') && !$application->get('field_workflow_attributes')->isEmpty()) {
      $attributes = json_decode((string) $application->get('field_workflow_attributes')->value, TRUE);
      if (
        is_array($attributes)
        && isset($attributes['submissionLocale'])
        && in_array($attributes['submissionLocale'], ['de', 'en'], TRUE)
      ) {
        return $attributes['submissionLocale'];
      }
    }

    return $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * Resolves the staff notification mailbox from the application scope.
   *
   * Loads the org group referenced by field_organisation, falling back to the
   * legacy field_group value, and returns its field_org_email value. Only
   * applications without an organisation fall back to the jurisdiction group's
   * field_org_email value.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   *
   * @return string|null
   *   The org email, or NULL if not resolvable.
   */
  protected function resolveWorkspaceEmail(ApplicationInterface $application): ?string {
    $orgGroup = $this->loadOrgGroup($application);
    if ($orgGroup) {
      return $this->mailboxFromGroup($orgGroup);
    }

    return $this->mailboxFromGroup($this->loadJurisdictionGroup($application, NULL));
  }

  /**
   * Resolves the staff recipient for an org-scoped or user-scoped send.
   */
  public function resolveStaffRecipient(ApplicationInterface $application, string $recipientScope): ?string {
    if ($recipientScope === ResubmissionReminderResolver::SCOPE_USER) {
      return $this->resolveAssignmentRecipient($application)
        ?? $this->resolveWorkspaceEmail($application);
    }

    return $this->resolveWorkspaceEmail($application);
  }

  /**
   * Resolves the applicant email from legacy clear storage or protected data.
   */
  private function resolveApplicantEmail(ApplicationInterface $application): ?string {
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      return $this->dataProtection->protectedApplicantEmail($application);
    }

    return $application->getApplicantEmail();
  }

  /**
   * Reads a normalized mailbox from a group entity.
   */
  private function mailboxFromGroup(?EntityInterface $group): ?string {
    if ($group instanceof FieldableEntityInterface
      && $group->hasField('field_org_email')
      && !$group->get('field_org_email')->isEmpty()) {
      return $this->normalizeEmail((string) $group->get('field_org_email')->value);
    }

    return NULL;
  }

  /**
   * Resolves the recipient for an assignment notification.
   */
  private function resolveAssignmentRecipient(ApplicationInterface $application): ?string {
    return $this->resolveAssignmentRecipientContext($application)['email'] ?? NULL;
  }

  /**
   * Resolves an assignment recipient and its optional person account.
   *
   * @return array{email: string, assignee: \Drupal\user\UserInterface|null, recipient_kind: string}|null
   *   Recipient context, or NULL when no valid recipient exists.
   */
  private function resolveAssignmentRecipientContext(ApplicationInterface $application): ?array {
    if ($application->hasField('field_assigned_to') && !$application->get('field_assigned_to')->isEmpty()) {
      $assignee = $application->get('field_assigned_to')->entity;
      if ($assignee instanceof UserInterface) {
        $email = $this->normalizeEmail((string) $assignee->getEmail());
        if ($email !== NULL) {
          return [
            'email' => $email,
            'assignee' => $assignee,
            'recipient_kind' => 'person',
          ];
        }
      }
    }

    if ($application->hasField('field_assigned_team') && !$application->get('field_assigned_team')->isEmpty()) {
      $team = $application->get('field_assigned_team')->entity;
      if ($team instanceof FieldableEntityInterface
        && $team->hasField('field_org_email')
        && !$team->get('field_org_email')->isEmpty()) {
        $email = $this->normalizeEmail((string) $team->get('field_org_email')->value);
        return $email === NULL ? NULL : [
          'email' => $email,
          'assignee' => NULL,
          'recipient_kind' => 'org_mailbox',
        ];
      }
    }

    return NULL;
  }

  /**
   * Returns a syntactically valid email address, or NULL.
   */
  private function normalizeEmail(string $email): ?string {
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL;
  }

  /**
   * Loads the org group entity from an application.
   *
   * The application references an org group via field_organisation. Existing
   * records may still only carry field_group.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The org group entity, or NULL if not found.
   */
  private function loadOrgGroup(ApplicationInterface $application): ?EntityInterface {
    $groupId = NULL;
    if ($application->hasField('field_organisation') && !$application->get('field_organisation')->isEmpty()) {
      $groupId = (int) $application->get('field_organisation')->target_id;
    }
    elseif ($application->hasField('field_group') && !$application->get('field_group')->isEmpty()) {
      $groupId = (int) $application->get('field_group')->target_id;
    }

    if ($groupId === NULL) {
      return NULL;
    }

    if ($groupId <= 0) {
      return NULL;
    }

    try {
      $group = $this->entityTypeManager->getStorage('group')->load($groupId);
      if ($group && $group->bundle() === 'org') {
        return $group;
      }
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to load org group @id for application @sid: @message',
        [
          '@id' => $groupId,
          '@sid' => $application->id() ?? 'new',
          '@message' => $e->getMessage(),
        ]
      );
      return NULL;
    }
  }

  /**
   * Returns TRUE when applicant identity linkage is protected for the schema.
   */
  private function identityLinkageIsProtected(ApplicationInterface $application): bool {
    if (!$this->dataProtection instanceof ApplicationDataProtectionService) {
      return FALSE;
    }

    try {
      return $this->dataProtection->applicationHasProtectedIdentityLinkage($application);
    }
    catch (\Throwable) {
      return TRUE;
    }
  }

}
