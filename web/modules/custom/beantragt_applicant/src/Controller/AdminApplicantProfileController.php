<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scoped admin API for editable applicant profile basics.
 */
final class AdminApplicantProfileController extends ControllerBase {

  private const SENSITIVE_REVEAL_FLOOD_EVENT = 'beantragt_applicant.sensitive_reveal';
  private const SENSITIVE_REVEAL_FLOOD_LIMIT = 20;
  private const SENSITIVE_REVEAL_FLOOD_WINDOW = 60;

  private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';
  private const FIELD_MAP = [
    'fullName' => ['field' => 'field_full_name', 'max' => 255],
    'phone' => ['field' => 'field_phone', 'max' => 50],
    'address' => ['field' => 'field_address', 'max' => 500],
    'street' => ['field' => 'field_street', 'max' => 255],
    'postalCode' => ['field' => 'field_postal_code', 'max' => 20],
    'city' => ['field' => 'field_city', 'max' => 255],
  ];
  /**
   * Encrypted profile fields staff may reveal through the audited path.
   *
   * The tax ID is not among them: it never reaches a profile (section 139b
   * AO), and beantragt_applicant_update_10006 cleared older values.
   */
  private const SENSITIVE_FIELD_MAP = [
    'iban' => 'field_iban',
    'accountHolder' => 'field_account_holder',
  ];

  /**
   * Current user's group memberships keyed by group ID.
   *
   * @var array<int, string[]>|null
   */
  private ?array $membershipsByGroupId = NULL;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    private readonly TenantScopedEntityQuery $tenantScopedEntityQuery,
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly ApplicantScopeMutationLock $applicantScopeMutationLock,
    private readonly Connection $database,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_application.tenant_scoped_entity_query'),
      $container->get('beantragt_application.data_protection'),
      $container->get('beantragt_application.applicant_scope_mutation_lock'),
      $container->get('database'),
      $container->get('flood'),
      $container->get('logger.channel.beantragt_applicant'),
    );
  }

  /**
   * Lists persisted basic applicant profile overlays for one workspace.
   */
  public function index(Request $request): JsonResponse {
    if (!$this->isProfileAdmin()) {
      return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
    }

    $workspace = $this->resolveWorkspace($request);
    if (!$workspace instanceof GroupInterface || !$this->canManageWorkspace($workspace)) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }

    $storage = $this->entityTypeManager->getStorage('applicant');
    $query = $this->tenantScopedEntityQuery
      ->getQuery('applicant', $this->currentUser)
      ->sort('changed', 'DESC')
      ->range(0, 1000);
    $this->tenantScopedEntityQuery->applyWorkspaceGroupScope($query, 'applicant', $workspace);

    $profiles = [];
    foreach ($storage->loadMultiple($query->execute()) as $applicant) {
      if (!$applicant instanceof ApplicantInterface) {
        continue;
      }
      $email = $this->normalizeEmail((string) ($applicant->get('field_email')->value ?? ''));
      if ($email === NULL) {
        continue;
      }
      $profiles[$email] = $this->serializeApplicant($applicant);
    }

    return $this->privateJson([
      'data' => array_values($profiles),
      'meta' => [
        'count' => count($profiles),
        'truncated' => count($profiles) >= 1000,
      ],
    ]);
  }

  /**
   * Updates editable basic CRM fields for one applicant profile.
   */
  public function patch(string $email, Request $request): JsonResponse {
    if (!$this->isProfileAdmin()) {
      return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
    }

    $email = $this->normalizeEmail($email);
    if ($email === NULL) {
      return $this->error('errors.admin.applicantsSaveFailed', Response::HTTP_BAD_REQUEST);
    }

    $workspace = $this->resolveWorkspace($request);
    if (!$workspace instanceof GroupInterface) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }
    if (!$this->canManageWorkspace($workspace)) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data']) || $payload['data'] === []) {
      return $this->error('errors.admin.applicantsSaveFailed', Response::HTTP_BAD_REQUEST);
    }

    $updates = $this->normalizeUpdates($payload['data']);
    if ($updates instanceof JsonResponse) {
      return $updates;
    }

    $applicant = $this->loadApplicant($email, $workspace);
    if ($applicant instanceof JsonResponse) {
      return $applicant;
    }
    if (!$applicant instanceof ApplicantInterface) {
      if (!$this->workspaceHasApplicationForEmail($email, $workspace)) {
        return $this->error('errors.admin.applicantsNotFound', Response::HTTP_NOT_FOUND);
      }
      $applicant = $this->createScopedApplicant($email, $workspace);
    }

    foreach ($updates as $field_name => $value) {
      $applicant->set($field_name, $value);
    }

    try {
      $applicant->save();
    }
    catch (\Throwable) {
      return $this->error('errors.admin.applicantsSaveFailed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return $this->privateJson(['data' => $this->serializeApplicant($applicant)]);
  }

  /**
   * Reveals encrypted applicant values for an authorized scoped administrator.
   */
  public function revealSensitive(Request $request): JsonResponse {
    if (!$this->canRevealSensitiveApplicantData()) {
      return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
    }

    $workspace = $this->resolveWorkspace($request);
    if (!$workspace instanceof GroupInterface || !$this->canManageWorkspace($workspace)) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }

    $flood_response = $this->sensitiveRevealFloodResponse((int) $workspace->id());
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $payload = json_decode($request->getContent(), TRUE);
    $email = is_array($payload) && isset($payload['email']) && is_string($payload['email'])
      ? $this->normalizeEmail($payload['email'])
      : NULL;
    $requested_field = is_array($payload) && isset($payload['field']) && is_string($payload['field'])
      ? trim($payload['field'])
      : '';
    if ($email === NULL || !isset(self::SENSITIVE_FIELD_MAP[$requested_field])) {
      return $this->error('errors.admin.applicantsSensitiveRevealFailed', Response::HTTP_BAD_REQUEST);
    }

    $applicant = $this->loadApplicant($email, $workspace);
    if ($applicant instanceof JsonResponse) {
      return $applicant;
    }
    if (!$applicant instanceof ApplicantInterface) {
      return $this->error('errors.admin.applicantsNotFound', Response::HTTP_NOT_FOUND);
    }

    $value = $this->readSensitiveValue($applicant, $requested_field);

    $this->logger->notice('Revealed applicant profile field @field for profile @profile in workspace @workspace to uid @uid.', [
      '@field' => $requested_field,
      '@profile' => (int) $applicant->id(),
      '@workspace' => (int) $workspace->id(),
      '@uid' => (int) $this->currentUser->id(),
    ]);

    return $this->privateJson([
      'data' => [
        'field' => $requested_field,
        'value' => $value,
      ],
      'meta' => ['revealed' => TRUE],
    ]);
  }

  /**
   * Permanently deletes one scoped applicant profile and its applications.
   */
  public function delete(Request $request): JsonResponse {
    if (!$this->canPermanentlyDeleteApplicantData()) {
      return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
    }

    $workspace = $this->resolveWorkspace($request);
    if (!$workspace instanceof GroupInterface || !$this->canManageWorkspace($workspace)) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }

    $payload = json_decode($request->getContent(), TRUE);
    $email = is_array($payload) && isset($payload['email']) && is_string($payload['email'])
      ? $this->normalizeEmail($payload['email'])
      : NULL;
    $confirmation_email = is_array($payload) && isset($payload['confirmation_email']) && is_string($payload['confirmation_email'])
      ? $this->normalizeEmail($payload['confirmation_email'])
      : NULL;
    $target_token = is_array($payload) ? $payload['target_token'] ?? NULL : NULL;
    if ($email === NULL
      || $confirmation_email === NULL
      || !hash_equals($email, $confirmation_email)
      || !is_string($target_token)
      || !preg_match('/^[a-f0-9]{64}$/', $target_token)) {
      return $this->error('errors.admin.applicantsDeleteConfirmationInvalid', Response::HTTP_BAD_REQUEST);
    }

    $jurisdiction = $workspace->bundle() === 'jur'
      ? $workspace
      : $this->parentJurisdiction($workspace);
    if (!$jurisdiction instanceof GroupInterface) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }

    $transaction = $this->database->startTransaction('admin_applicant_delete');
    if (!$this->applicantScopeMutationLock->acquireForEmailWorkspace(
      $request,
      $email,
      (int) $jurisdiction->id(),
      $workspace->bundle() === 'org' ? (int) $workspace->id() : 0,
    )) {
      return $this->error('errors.admin.applicantsDeleteConflict', Response::HTTP_CONFLICT);
    }
    $this->applicantScopeMutationLock->release($request);

    $applications = $this->loadApplicationsForDeletion($email, $workspace);
    $applicant = $this->loadApplicant($email, $workspace);
    if ($applicant instanceof JsonResponse) {
      return $applicant;
    }
    if ($applications === [] && !($applicant instanceof ApplicantInterface)) {
      return $this->error('errors.admin.applicantsNotFound', Response::HTTP_NOT_FOUND);
    }
    $current_target_token = $this->deletionTargetToken($applications, $applicant);
    if (!hash_equals($current_target_token, $target_token)) {
      return $this->error('errors.admin.applicantsDeleteConflict', Response::HTTP_CONFLICT);
    }

    foreach ($applications as $application) {
      if (!$application->access('delete', $this->currentUser)) {
        return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
      }
    }
    try {
      if ($applicant instanceof ApplicantInterface) {
        $this->entityTypeManager->getStorage('applicant')->delete([$applicant]);
      }
      if ($applications !== []) {
        $this->entityTypeManager->getStorage('application')->delete($applications);
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      if ($this->causedByDeletionLock($exception)) {
        return $this->error('errors.admin.applicantsDeleteConflict', Response::HTTP_CONFLICT);
      }
      $this->logger->error('Administrative applicant deletion failed in workspace @workspace: @message', [
        '@workspace' => (int) $workspace->id(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->error('errors.admin.applicantsDeleteFailed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('Administrative applicant deletion removed @count applications and profile @profile in workspace @workspace by uid @uid.', [
      '@count' => count($applications),
      '@profile' => $applicant instanceof ApplicantInterface ? 'yes' : 'no',
      '@workspace' => (int) $workspace->id(),
      '@uid' => (int) $this->currentUser->id(),
    ]);

    return $this->privateJson([
      'success' => TRUE,
      'applications' => ['deleted' => count($applications)],
      'profile' => ['deleted' => $applicant instanceof ApplicantInterface],
    ]);
  }

  /**
   * Returns the exact scoped deletion target before confirmation.
   */
  public function deletionPreview(Request $request): JsonResponse {
    if (!$this->canPermanentlyDeleteApplicantData()) {
      return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
    }

    $payload = json_decode($request->getContent(), TRUE);
    $email = is_array($payload) && isset($payload['email']) && is_string($payload['email'])
      ? $this->normalizeEmail($payload['email'])
      : NULL;
    if ($email === NULL) {
      return $this->error(
        'errors.admin.applicantsDeleteConfirmationInvalid',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $workspace = $this->resolveWorkspace($request);
    if (!$workspace instanceof GroupInterface || !$this->canManageWorkspace($workspace)) {
      return $this->error('workspace.access_denied', Response::HTTP_FORBIDDEN);
    }

    $applications = $this->loadApplicationsForDeletion($email, $workspace);
    $applicant = $this->loadApplicant($email, $workspace);
    if ($applicant instanceof JsonResponse) {
      return $applicant;
    }
    if ($applications === [] && !($applicant instanceof ApplicantInterface)) {
      return $this->error('errors.admin.applicantsNotFound', Response::HTTP_NOT_FOUND);
    }
    foreach ($applications as $application) {
      if (!$application->access('delete', $this->currentUser)) {
        return $this->error('errors.auth.accessDenied', Response::HTTP_FORBIDDEN);
      }
    }

    return $this->privateJson([
      'applications' => ['count' => count($applications)],
      'profile' => ['exists' => $applicant instanceof ApplicantInterface],
      'targetToken' => $this->deletionTargetToken($applications, $applicant),
    ]);
  }

  /**
   * Returns TRUE when the current account may update basic profile data.
   */
  private function isProfileAdmin(): bool {
    if ($this->currentUser->hasPermission('bypass beantragt group scope')
      || $this->currentUser->hasPermission('administer applicant entities')) {
      return TRUE;
    }

    return BeantragtRoles::isManagementAccount($this->currentUser);
  }

  /**
   * Returns TRUE when the current account may permanently delete both types.
   */
  private function canPermanentlyDeleteApplicantData(): bool {
    if (!$this->isProfileAdmin()) {
      return FALSE;
    }
    if ($this->currentUser->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    return $this->currentUser->hasPermission('delete any application entities')
      && $this->currentUser->hasPermission('administer scoped applicant cleanup');
  }

  /**
   * Returns TRUE when the current account may reveal applicant secrets.
   */
  private function canRevealSensitiveApplicantData(): bool {
    if ($this->currentUser->hasPermission('bypass beantragt group scope')
      || $this->currentUser->hasPermission('administer applicant entities')) {
      return TRUE;
    }

    return $this->isProfileAdmin()
      && $this->currentUser->hasPermission('view protected application data');
  }

  /**
   * Resolves the requested workspace from query slugs.
   */
  private function resolveWorkspace(Request $request): ?GroupInterface {
    $jurisdiction_slug = $this->normalizeSlug($request->query->get('jurisdiction'));
    $organisation_slug = $this->normalizeSlug($request->query->get('organisation') ?? $request->query->get('organization'));
    if ($jurisdiction_slug === NULL) {
      return NULL;
    }

    $jurisdiction = $this->loadGroupBySlug('jur', $jurisdiction_slug);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    if ($organisation_slug === NULL) {
      return $jurisdiction;
    }

    $organisation = $this->loadGroupBySlug('org', $organisation_slug);
    if (!$organisation instanceof GroupInterface
      || !$organisation->hasField('field_jurisdiction')
      || $organisation->get('field_jurisdiction')->isEmpty()
      || (int) $organisation->get('field_jurisdiction')->target_id !== (int) $jurisdiction->id()) {
      return NULL;
    }

    return $organisation;
  }

  /**
   * Loads one group by type and slug.
   */
  private function loadGroupBySlug(string $bundle, string $slug): ?GroupInterface {
    $storage = $this->entityTypeManager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_slug', $slug)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();
    $group = $ids ? $storage->load(reset($ids)) : NULL;
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Returns TRUE when current account administers this workspace.
   */
  private function canManageWorkspace(GroupInterface $workspace): bool {
    if ($this->currentUser->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    if ($this->isGroupAdmin($workspace)) {
      return TRUE;
    }
    if ($workspace->bundle() === 'org') {
      $jurisdiction = $this->parentJurisdiction($workspace);
      return $jurisdiction instanceof GroupInterface && $this->isGroupAdmin($jurisdiction);
    }
    return FALSE;
  }

  /**
   * Loads the parent jurisdiction for an organisation group.
   */
  private function parentJurisdiction(GroupInterface $organisation): ?GroupInterface {
    if (!$organisation->hasField('field_jurisdiction') || $organisation->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $jurisdiction = $organisation->get('field_jurisdiction')->entity;
    return $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL;
  }

  /**
   * Returns TRUE when current account has an admin membership in this group.
   */
  private function isGroupAdmin(GroupInterface $group): bool {
    $roles = [];
    foreach ($this->currentUserMemberships()[(int) $group->id()] ?? [] as $role_id) {
      $role = $this->entityTypeManager->getStorage('group_role')->load($role_id);
      if ($role instanceof GroupRoleInterface) {
        $roles[] = $role;
      }
    }
    return ApplicationCaseScope::hasAdminGroupRole($roles);
  }

  /**
   * Loads current user memberships from group_relationship storage.
   *
   * @return array<int, string[]>
   *   Group role IDs keyed by group ID.
   */
  private function currentUserMemberships(): array {
    if ($this->membershipsByGroupId !== NULL) {
      return $this->membershipsByGroupId;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      $this->membershipsByGroupId = [];
      return $this->membershipsByGroupId;
    }

    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_id', $uid)
      ->condition('plugin_id', 'group_membership')
      ->execute();

    $memberships = [];
    foreach ($storage->loadMultiple($ids) as $relationship) {
      if (!$relationship instanceof GroupRelationshipInterface) {
        continue;
      }
      if (!$relationship->hasField('gid') || $relationship->get('gid')->isEmpty()) {
        continue;
      }
      $group_id = (int) ($relationship->get('gid')->__get('target_id') ?? 0);
      if ($group_id <= 0) {
        continue;
      }

      $roles = [];
      if ($relationship->hasField('group_roles')) {
        foreach ($relationship->get('group_roles')->getValue() as $item) {
          $role_id = (string) ($item['target_id'] ?? '');
          if ($role_id !== '') {
            $roles[] = $role_id;
          }
        }
      }
      $memberships[$group_id] = $roles;
    }

    $this->membershipsByGroupId = $memberships;
    return $this->membershipsByGroupId;
  }

  /**
   * Loads a scoped applicant by e-mail.
   */
  private function loadApplicant(string $email, GroupInterface $workspace): ApplicantInterface|JsonResponse|null {
    $storage = $this->entityTypeManager->getStorage('applicant');
    $query = $this->tenantScopedEntityQuery
      ->getQuery('applicant', $this->currentUser)
      ->condition('field_email', $email);
    $this->tenantScopedEntityQuery->applyWorkspaceGroupScope($query, 'applicant', $workspace);
    $ids = $query->range(0, 2)->execute();
    if (count($ids) > 1) {
      return $this->error('errors.admin.applicantsAmbiguous', Response::HTTP_CONFLICT);
    }
    $applicant = $ids ? $storage->load(reset($ids)) : NULL;
    return $applicant instanceof ApplicantInterface ? $applicant : NULL;
  }

  /**
   * Returns TRUE if the workspace already contains an application for e-mail.
   */
  private function workspaceHasApplicationForEmail(string $email, GroupInterface $workspace): bool {
    $query = $this->tenantScopedEntityQuery
      ->getQuery('application', $this->currentUser)
      ->condition('field_applicant_email', $email);
    $this->tenantScopedEntityQuery->applyWorkspaceGroupScope($query, 'application', $workspace);
    $ids = $query->range(0, 1)->execute();
    return $ids !== [];
  }

  /**
   * Loads all deletable applications for one e-mail inside one workspace.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationInterface[]
   *   Applications keyed by entity ID.
   */
  private function loadApplicationsForDeletion(
    string $email,
    GroupInterface $workspace,
  ): array {
    $storage = $this->entityTypeManager->getStorage('application');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $this->tenantScopedEntityQuery->applyAccountScope($query, 'application', $this->currentUser);
    $this->tenantScopedEntityQuery->applyWorkspaceGroupScope(
      $query,
      'application',
      $workspace,
      TRUE,
    );

    $email_condition = $query->orConditionGroup()
      ->condition('field_applicant_email', $email);
    $email_hash = $this->dataProtection->applicantEmailLookupHash($email);
    if ($email_hash !== NULL) {
      $email_condition->condition('field_applicant_email_hash', $email_hash);
    }
    $ids = $query->condition($email_condition)->execute();

    $applications = [];
    foreach ($storage->loadMultiple($ids) as $application) {
      if ($application instanceof ApplicationInterface
        && $this->dataProtection->applicationApplicantEmailMatches($application, $email)) {
        $applications[(int) $application->id()] = $application;
      }
    }
    return $applications;
  }

  /**
   * Returns TRUE when a nested deletion exception represents an active lock.
   */
  private function causedByDeletionLock(\Throwable $exception): bool {
    $cause = $exception;
    while ($cause instanceof \Throwable) {
      if ($cause instanceof ApplicationDeletionLockedException) {
        return TRUE;
      }
      $cause = $cause->getPrevious();
    }
    return FALSE;
  }

  /**
   * Builds an opaque token for the exact current deletion target.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   Applications keyed by entity ID.
   * @param \Drupal\beantragt_applicant\Entity\ApplicantInterface|null $applicant
   *   Optional persisted applicant profile.
   */
  private function deletionTargetToken(
    array $applications,
    ?ApplicantInterface $applicant,
  ): string {
    $application_revisions = [];
    foreach ($applications as $application) {
      $application_revisions[(int) $application->id()] = [
        (int) $application->getRevisionId(),
        $application->getChangedTime(),
      ];
    }
    ksort($application_revisions, SORT_NUMERIC);

    return hash('sha256', json_encode([
      'applications' => $application_revisions,
      'profile' => $applicant instanceof ApplicantInterface
        ? [(int) $applicant->id(), $applicant->getChangedTime()]
        : NULL,
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Creates a scoped applicant shell for existing workspace application data.
   */
  private function createScopedApplicant(string $email, GroupInterface $workspace): ApplicantInterface {
    $values = [
      'status' => TRUE,
      'uid' => 0,
      'field_email' => $email,
      'field_jurisdiction' => ['target_id' => $workspace->bundle() === 'jur' ? $workspace->id() : $this->parentJurisdiction($workspace)?->id()],
      'field_group' => ['target_id' => $workspace->id()],
    ];
    if ($workspace->bundle() === 'org') {
      $values['field_organisation'] = ['target_id' => $workspace->id()];
    }
    $applicant = $this->entityTypeManager->getStorage('applicant')->create($values);
    if (!$applicant instanceof ApplicantInterface) {
      throw new \UnexpectedValueException('Applicant storage created an invalid entity.');
    }
    return $applicant;
  }

  /**
   * Normalizes and validates requested field updates.
   *
   * @param array<string, mixed> $data
   *   Request data.
   *
   * @return array<string, string>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Field updates keyed by entity field name, or an error response.
   */
  private function normalizeUpdates(array $data): array|JsonResponse {
    $updates = [];
    foreach ($data as $key => $value) {
      if (!isset(self::FIELD_MAP[$key])) {
        return $this->error('errors.admin.applicantsSaveFailed', Response::HTTP_BAD_REQUEST);
      }
      if (!is_string($value) && $value !== NULL) {
        return $this->error('errors.admin.applicantsSaveFailed', Response::HTTP_BAD_REQUEST);
      }
      $normalized = trim((string) ($value ?? ''));
      $definition = self::FIELD_MAP[$key];
      if (mb_strlen($normalized) > $definition['max']) {
        return $this->error('errors.admin.applicantsSaveFailed', Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      $updates[$definition['field']] = $normalized;
    }
    return $updates;
  }

  /**
   * Reads one protected value decrypted transparently by Field Encrypt.
   */
  private function readSensitiveValue(ApplicantInterface $applicant, string $key): ?string {
    $field_name = self::SENSITIVE_FIELD_MAP[$key];
    $value = $applicant->hasField($field_name)
      ? $applicant->get($field_name)->value
      : NULL;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Limits direct and proxied reveal attempts at the authoritative boundary.
   */
  private function sensitiveRevealFloodResponse(int $workspace_id): ?JsonResponse {
    $identifier = sprintf('%d:%d', (int) $this->currentUser->id(), $workspace_id);
    if (!$this->flood->isAllowed(
      self::SENSITIVE_REVEAL_FLOOD_EVENT,
      self::SENSITIVE_REVEAL_FLOOD_LIMIT,
      self::SENSITIVE_REVEAL_FLOOD_WINDOW,
      $identifier,
    )) {
      return $this->error('errors.rateLimit.tooManyRequests', Response::HTTP_TOO_MANY_REQUESTS);
    }

    $this->flood->register(
      self::SENSITIVE_REVEAL_FLOOD_EVENT,
      self::SENSITIVE_REVEAL_FLOOD_WINDOW,
      $identifier,
    );
    return NULL;
  }

  /**
   * Returns protected fields available to the current authorized account.
   *
   * @return string[]
   *   Protected API field names containing a stored value.
   */
  private function availableSensitiveFields(ApplicantInterface $applicant): array {
    if (!$this->canRevealSensitiveApplicantData()) {
      return [];
    }

    return array_values(array_filter(
      array_keys(self::SENSITIVE_FIELD_MAP),
      fn(string $key): bool => $this->readSensitiveValue($applicant, $key) !== NULL,
    ));
  }

  /**
   * Serializes editable applicant fields for the admin UI.
   */
  private function serializeApplicant(ApplicantInterface $applicant): array {
    return [
      'id' => $applicant->uuid(),
      'email' => (string) ($applicant->get('field_email')->value ?? ''),
      'fullName' => (string) ($applicant->get('field_full_name')->value ?? ''),
      'phone' => (string) ($applicant->get('field_phone')->value ?? ''),
      'address' => (string) ($applicant->get('field_address')->value ?? ''),
      'street' => (string) ($applicant->get('field_street')->value ?? ''),
      'postalCode' => (string) ($applicant->get('field_postal_code')->value ?? ''),
      'city' => (string) ($applicant->get('field_city')->value ?? ''),
      'protectedFields' => $this->availableSensitiveFields($applicant),
    ];
  }

  /**
   * Normalizes an e-mail route parameter.
   */
  private function normalizeEmail(string $email): ?string {
    $normalized = strtolower(trim($email));
    return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : NULL;
  }

  /**
   * Normalizes a workspace slug.
   */
  private function normalizeSlug(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $slug = strtolower(trim($value));
    return preg_match(self::SLUG_PATTERN, $slug) ? $slug : NULL;
  }

  /**
   * Creates a JSON error response.
   */
  private function error(string $code, int $status): JsonResponse {
    return $this->privateJson([
      'error' => [
        'code' => $code,
        'message' => $code,
      ],
    ], $status);
  }

  /**
   * Creates a private JSON response for applicant profile data.
   *
   * @param array<string, mixed> $data
   *   Response payload.
   * @param int $status
   *   HTTP response status code.
   */
  private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
