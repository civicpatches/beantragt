<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\PublicRejectionReason;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\RevisionLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\beantragt_application\Service\ApplicationCorrectionTransferLock;
use Drupal\beantragt_application\Service\ApplicationDataEditValidator;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Handles administrative application data edits.
 */
final class ApplicationDataController extends ControllerBase {

  private const MAX_DATA_BYTES = 1048576;
  private const MAX_DATA_EDIT_REASON_LENGTH = 500;
  private const PROTECTED_DATA_EDIT_DENIAL = 'Missing protected application data permission.';
  private const SUBMISSION_LOCALE_WORKFLOW_ATTRIBUTE = 'submissionLocale';

  /**
   * Locales a submission may carry; each needs a profile consent text.
   */
  public const SUBMISSION_LOCALES = [
    'de' => TRUE,
    'en' => TRUE,
  ];

  /**
   * Validates submitted application data edits.
   */
  private readonly ApplicationDataEditValidator $dataEditValidator;

  /**
   * Error code of an undo whose target the form's workflow no longer has.
   */
  public const ROLLBACK_TARGET_NOT_IN_WORKFLOW_CODE = 'workflow.rollback_target_not_in_workflow';

  /**
   * Reason of the rollback target endpoint for the same case.
   */
  public const ROLLBACK_TARGET_NOT_IN_WORKFLOW_REASON = 'target_not_in_workflow';

  /**
   * Constructs an ApplicationDataController.
   */
  public function __construct(
    AccountInterface $current_user,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $manager,
    private readonly ApplicationCaseScope $applicationCaseScope,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    ?ApplicationDataEditValidator $data_edit_validator = NULL,
    private readonly ?ApplicationDependentMutationLock $applicationMutationLock = NULL,
    private readonly ?ApplicantScopeMutationLock $applicantScopeMutationLock = NULL,
    private readonly ?ProtectedDataGate $protectedDataGate = NULL,
    private readonly ?EffectiveWorkflowResolver $workflowResolver = NULL,
    private readonly ?ApplicationCorrectionTransferLock $correctionTransferLock = NULL,
  ) {
    $this->currentUser = $current_user;
    $this->dataEditValidator = $data_edit_validator ?? new ApplicationDataEditValidator();
  }

  /**
   * Checks scoped access for administrative application data edits.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    if ($account->hasPermission('administer application entities')
          || $account->hasPermission('bypass beantragt group scope')
      ) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    if (!$account->hasPermission('edit application data entities')) {
      return AccessResult::forbidden('Missing application data edit permission.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    if ($this->applicationRequiresProtectedReveal($application)
          && !$account->hasPermission('view protected application data')
      ) {
      return AccessResult::forbidden(self::PROTECTED_DATA_EDIT_DENIAL)
        ->cachePerPermissions()
        ->cachePerUser()
        ->setCacheMaxAge(0)
        ->addCacheableDependency($application);
    }

    return $this->applicationCaseScope->access($application, $account)->setCacheMaxAge(0);
  }

  /**
   * Checks tenant-scoped access for permanently deleting an application.
   */
  public function deleteAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    // The route also requires application.delete.
    if (!$account->hasPermission('delete any application entities')
      && !$account->hasPermission('administer application entities')
      && !$account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::forbidden('Missing application delete permission.')
        ->cachePerPermissions()->addCacheableDependency($application);
    }
    return $this->applicationCaseScope->access($application, $account)->setCacheMaxAge(0);
  }

  /**
   * Checks scoped access for administrative application status changes.
   */
  public function statusAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    // The route also requires application.update.
    if (!$account->hasPermission('manage workflow transitions')
      && !$account->hasPermission('administer application entities')
      && !$account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::forbidden('Missing workflow transition permission.')
        ->cachePerPermissions()->addCacheableDependency($application);
    }
    return $this->applicationCaseScope->access($application, $account);
  }

  /**
   * Checks scoped access for revealing protected application data values.
   */
  public function protectedDataAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    // The gate lives in a service so the Nachreichung case view asks the
    // same question (D15); the route behaves as before.
    return ($this->protectedDataGate ?? new ProtectedDataGate($this->applicationCaseScope))->access($application, $account);
  }

  /**
   * Checks staff access to the application's workflow.
   *
   * Entity view alone also admits the citizen who owns the application.
   */
  public function workflowAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->protectedDataAccess($application, $account);
  }

  /**
   * Returns the governing workflow of one application for the staff UI.
   *
   * Internal route for the Nuxt proxy only, not part of /api/v1. The list of
   * allowed targets is computed here, the same way WorkflowManager enforces
   * it; Nuxt only adds the attribute definitions from the definition text.
   */
  public function workflow(ApplicationInterface $application): JsonResponse {
    $resolver = $this->workflowResolver();
    $source = $resolver->workflowSource($application);
    $override = $resolver->hasOverride($application);
    $review_code = $resolver->reviewCode($application);

    $can_change_status = $this->applicationCaseScope->canChangeStatus($application, $this->currentUser);
    $allowed_next = [];
    if ($can_change_status && $review_code === NULL) {
      $allowed_next = $this->statusReferences($this->allowedNextStatusIds($application, $resolver));
    }

    $review = NULL;
    if ($review_code !== NULL) {
      $review = [
        'code' => $review_code,
        'can_correct' => $can_change_status,
        'targets' => $can_change_status ? $this->statusReferences($resolver->correctionTargetIds($application)) : [],
      ];
    }

    $response = new JsonResponse([
      'form' => $source,
      'override' => $override,
      'definition' => $override ? $resolver->workflowDefinition($application) : NULL,
      'can_change_status' => $can_change_status,
      'allowed_next' => $allowed_next,
      'review' => $review,
    ]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Sets a valid status for an application whose status left its workflow.
   *
   * A repair, not a decision: allowed only while the review
   * state holds, the target is a status of the current workflow that is no
   * decision status, and the change is saved as an administrative reset. It
   * therefore skips transition validation, never starts a transfer and the
   * citizen gets at most the generic status mail; the note stays internal.
   */
  public function correctStatus(ApplicationInterface $application, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing status correction payload.');
    }

    $lock_owner = $application;
    $application_lock_acquired = FALSE;
    try {
      if ($this->applicationMutationLock instanceof ApplicationDependentMutationLock) {
        if (!$this->applicationMutationLock->acquireApplication($lock_owner)) {
          throw new ApplicationLockedHttpException();
        }
        $application_lock_acquired = TRUE;
      }

      $application_id = (int) $application->id();
      $storage = $this->manager->getStorage('application');
      $storage->resetCache([$application_id]);
      $reloaded = $storage->load($application_id);
      if (!$reloaded instanceof ApplicationInterface
        || $reloaded->uuid() !== $application->uuid()) {
        throw new ConflictHttpException('Application no longer exists.');
      }
      $application = $reloaded;

      $expected_status_id = $payload['expected_status_id'] ?? NULL;
      if (!is_string($expected_status_id) && !is_int($expected_status_id)) {
        throw new HttpException(428, 'Missing expected workflow status.');
      }
      $expected_status_id = (int) $expected_status_id;
      $current_status_id = (int) ($application->get('field_workflow_status')->target_id ?? 0);
      if ($expected_status_id <= 0 || $expected_status_id !== $current_status_id) {
        throw new ConflictHttpException('Application workflow status changed since it was loaded.');
      }

      $expected_changed = $payload['expected_changed'] ?? NULL;
      if (!is_string($expected_changed) && !is_int($expected_changed)) {
        throw new HttpException(428, 'Missing expected changed timestamp.');
      }
      if ($this->normalizeChangedTimestamp($expected_changed) !== $application->getChangedTime()) {
        throw new ConflictHttpException('Application changed since it was loaded.');
      }

      $resolver = $this->workflowResolver();
      $resolver->refresh($application);
      // A publish between this check and save can remove the chosen status.
      // The application is then marked for review again, the designed recovery.
      if ($resolver->reviewCode($application) === NULL) {
        throw new ConflictHttpException('Application status is part of the current workflow.');
      }

      $target_status_id = $payload['target_status_id'] ?? NULL;
      if (!is_string($target_status_id) && !is_int($target_status_id)) {
        throw new BadRequestHttpException('Missing target workflow status.');
      }
      $target_status_id = (int) $target_status_id;
      if ($target_status_id <= 0
        || $target_status_id === $current_status_id
        || !in_array($target_status_id, $resolver->correctionTargetIds($application), TRUE)) {
        throw new UnprocessableEntityHttpException('Target workflow status cannot be set by a correction.');
      }

      $note = is_string($payload['note'] ?? NULL) ? trim($payload['note']) : '';
      // Count characters like the dialog: a byte cut can split a multi-byte
      // character and break the JSON revision log on save.
      if (mb_strlen($note) > 2000) {
        $note = mb_substr($note, 0, 2000);
      }

      $application->allowWorkflowStatusReset();
      $application->setNewRevision(TRUE);
      $application->setRevisionUserId((int) $this->currentUser->id());
      $application->setRevisionCreationTime($this->time->getRequestTime());
      RevisionLog::setMessage($application, $note);
      $application->set('field_workflow_status', ['target_id' => $target_status_id]);
      // Same as a transition without attributes: only the submission locale
      // survives.
      $application->set('field_workflow_attributes', $this->encodeWorkflowAttributes(
        $this->withPreservedSubmissionLocale($application, []),
      ));
      $this->applyStatusSlaDueDate($application, $target_status_id);

      $violations = $application->validate();
      if ($violations->count() > 0) {
        throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
      }

      $this->saveUnlessLocked($application);

      return new JsonResponse(
            [
              'success' => TRUE,
              'revision_id' => (int) $application->getRevisionId(),
              'target_status' => $this->statusSummaryFromApplication($application),
            ]
        );
    }
    finally {
      if ($application_lock_acquired
        && $this->applicationMutationLock instanceof ApplicationDependentMutationLock) {
        $this->applicationMutationLock->release($lock_owner);
      }
    }
  }

  /**
   * Returns the targets that enforcement accepts for this application.
   *
   * Same result as WorkflowManager::isApplicationTransitionAllowed() for
   * every status of the group.
   *
   * The form's transitions when its governing workflow overrides the graph,
   * else the organisation edges of the current status; nothing from a
   * terminal status. Statuses of another group are never targets.
   *
   * @return int[]
   *   Status ids.
   */
  private function allowedNextStatusIds(ApplicationInterface $application, EffectiveWorkflowResolver $resolver): array {
    $current = $application->get('field_workflow_status')->entity ?? NULL;
    if (!$current instanceof WorkflowStatusInterface || $current->isTerminal()) {
      return [];
    }

    $ids = $resolver->allowedTargetIds($application, $current) ?? $current->getAllowedNextIds();
    $current_id = (int) $current->id();
    $result = [];
    foreach ($this->manager->getStorage('workflow_status')->loadMultiple(array_map('intval', $ids)) as $status) {
      if ($status instanceof WorkflowStatusInterface
        && (int) $status->id() !== $current_id
        && $status->getGroupId() === $current->getGroupId()) {
        $result[] = (int) $status->id();
      }
    }
    return $result;
  }

  /**
   * Builds id/UUID pairs for status ids; labels come from the catalogue.
   *
   * @param int[] $status_ids
   *   Status ids.
   *
   * @return array<int, array{id: int, uuid: string}>
   *   References in the order given.
   */
  private function statusReferences(array $status_ids): array {
    if ($status_ids === []) {
      return [];
    }
    $statuses = $this->manager->getStorage('workflow_status')->loadMultiple($status_ids);
    $references = [];
    foreach ($status_ids as $status_id) {
      $status = $statuses[$status_id] ?? NULL;
      $uuid = $status instanceof WorkflowStatusInterface ? $status->uuid() : NULL;
      if (is_string($uuid) && $uuid !== '') {
        $references[] = ['id' => (int) $status_id, 'uuid' => $uuid];
      }
    }
    return $references;
  }

  /**
   * Returns the effective workflow resolver.
   */
  private function workflowResolver(): EffectiveWorkflowResolver {
    if (!$this->workflowResolver instanceof EffectiveWorkflowResolver) {
      throw new \LogicException('The effective workflow resolver is not available.');
    }
    return $this->workflowResolver;
  }

  /**
   * Updates submitted application data and writes a new revision.
   */
  public function update(ApplicationInterface $application, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !array_key_exists('data', $payload)) {
      throw new BadRequestHttpException('Missing application data.');
    }

    $data = $payload['data'];
    if (!is_array($data) || array_is_list($data)) {
      throw new BadRequestHttpException('Application data must be an object.');
    }

    $reason = $payload['reason'] ?? NULL;
    $reason = is_string($reason) ? trim(preg_replace('/[\p{C}\s]+/u', ' ', strip_tags($reason)) ?? '') : '';
    if ($reason === '') {
      return $this->dataEditError('application.data_edit_reason_required', 'A reason is required to correct application data.', 400);
    }
    if (mb_strlen($reason) > self::MAX_DATA_EDIT_REASON_LENGTH) {
      return $this->dataEditError('application.data_edit_reason_too_long', 'The correction reason must be at most 500 characters.', 400);
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new HttpException(428, 'Missing expected changed timestamp.');
    }

    $expected_changed = $this->normalizeChangedTimestamp($expected_changed);
    if ($expected_changed !== $application->getChangedTime()) {
      throw new ConflictHttpException('Application changed since it was loaded.');
    }

    if ($this->applicationMutationLock === NULL || !$this->applicationMutationLock->acquireApplication($application)) {
      throw new ApplicationLockedHttpException();
    }
    try {
      $storage = $this->manager->getStorage('application');
      $current = $storage->loadUnchanged($application->id());
      if (!$current instanceof ApplicationInterface || $current->uuid() !== $application->uuid()) {
        throw new ConflictHttpException('Application no longer exists.');
      }
      $this->manager->getAccessControlHandler('application')->resetCache();
      $blocked_reason = $this->dataEditAccessBlockedReason($current, $this->currentUser);
      if ($blocked_reason === 'transfer') {
        return $this->dataEditError('application.data_edit_transfer_locked', 'Application data cannot be corrected after a transfer has been planned.', 409);
      }
      if ($blocked_reason !== NULL) {
        throw new AccessDeniedHttpException();
      }
      if ($expected_changed !== $current->getChangedTime() || $current->getRevisionId() !== $application->getRevisionId()) {
        throw new ConflictHttpException('Application changed since it was loaded.');
      }
      return $this->saveDataCorrection($current, $data, $reason);
    }
    finally {
      $this->applicationMutationLock->release($application);
    }
  }

  /**
   * Validates and stores a correction with its reason in the revision log.
   */
  private function saveDataCorrection(ApplicationInterface $application, array $data, string $reason): JsonResponse {
    $this->dataEditValidator->assertSafeDataShape($data);
    $schema_entity = $this->validateApplicationData($application, $data);

    try {
      $data_json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
    catch (\JsonException $exception) {
      throw new BadRequestHttpException('Application data is not valid JSON.', $exception);
    }

    if (strlen($data_json) > self::MAX_DATA_BYTES) {
      throw new BadRequestHttpException('Application data is too large.');
    }

    $application->setNewRevision(TRUE);
    $application->setRevisionUserId((int) $this->currentUser->id());
    $application->setRevisionCreationTime($this->time->getRequestTime());
    RevisionLog::setEvent($application, 'data_edited');
    RevisionLog::setMessage($application, $reason);
    $application->set('field_data', $data_json);
    $application->refreshCrmDataFromSchema($data_json, $schema_entity);
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      $this->dataProtection->protectApplicationIdentityLinkage($application);
    }

    $violations = $application->validate();
    if ($violations->count() > 0) {
      throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
    }

    $this->saveUnlessLocked($application);

    return new JsonResponse(
          [
            'success' => TRUE,
            'revision_id' => (int) $application->getRevisionId(),
          ]
      );
  }

  /**
   * Returns a stable refusal code for the staff data correction proxy.
   */
  private function dataEditError(string $code, string $message, int $status): JsonResponse {
    return new JsonResponse([
      'message' => $message,
      'error' => ['code' => $code, 'message' => $message],
    ], $status, ['Cache-Control' => 'private, no-store, max-age=0']);
  }

  /**
   * Returns the first permission or transfer barrier to correcting data.
   */
  private function dataEditAccessBlockedReason(ApplicationInterface $application, AccountInterface $account): ?string {
    if (!$account->hasPermission('edit application data entities') || !$application->access('update', $account)) {
      return 'permission';
    }
    $access = $this->access($application, $account);
    if (!$access->isAllowed()) {
      return $access instanceof AccessResultReasonInterface && $access->getReason() === self::PROTECTED_DATA_EDIT_DENIAL
        ? 'protected'
        : 'permission';
    }
    // The transfer check also depends on the availability of the case lock.
    if ($this->applicationMutationLock === NULL || $this->correctionTransferLock?->blocksCorrection((int) $application->id()) !== FALSE) {
      return 'transfer';
    }
    return NULL;
  }

  /**
   * Permanently deletes an application and its locally owned dependents.
   */
  public function delete(ApplicationInterface $application, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing application deletion payload.');
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new HttpException(428, 'Missing expected changed timestamp.');
    }
    $expected_changed = $this->normalizeChangedTimestamp($expected_changed);
    $loaded_revision_id = (int) $application->getRevisionId();
    $scope_lock_acquired = FALSE;
    $application_lock_acquired = FALSE;

    try {
      if ($this->applicantScopeMutationLock instanceof ApplicantScopeMutationLock) {
        if (!$this->applicantScopeMutationLock->acquireForApplication($application)) {
          throw new ConflictHttpException('The applicant scope is currently being changed or deleted.');
        }
        $scope_lock_acquired = TRUE;
      }
      if ($this->applicationMutationLock instanceof ApplicationDependentMutationLock) {
        if (!$this->applicationMutationLock->acquireApplication($application)) {
          throw new ConflictHttpException('Application is currently being changed, deleted, or delivered.');
        }
        $application_lock_acquired = TRUE;
      }

      $current = $application;
      if ($this->applicationMutationLock instanceof ApplicationDependentMutationLock) {
        $application_id = (int) $application->id();
        $storage = $this->manager->getStorage('application');
        $storage->resetCache([$application_id]);
        $reloaded = $storage->load($application_id);
        if (!$reloaded instanceof ApplicationInterface
          || $reloaded->uuid() !== $application->uuid()) {
          throw new ConflictHttpException('Application no longer exists.');
        }
        $current = $reloaded;
      }
      if ($expected_changed !== $current->getChangedTime()) {
        throw new ConflictHttpException('Application changed since it was loaded.');
      }
      if ($loaded_revision_id !== (int) $current->getRevisionId()) {
        throw new ConflictHttpException('Application revision changed since it was loaded.');
      }
      if (!$this->deleteAccess($current, $this->currentUser)->isAllowed()) {
        throw new AccessDeniedHttpException('Application is no longer deletable in the active tenant scope.');
      }

      $uuid = (string) $current->uuid();
      $current->delete();
    }
    catch (EntityStorageException $exception) {
      $cause = $exception;
      while ($cause instanceof \Throwable) {
        if ($cause instanceof ApplicationDeletionLockedException) {
          throw new ConflictHttpException('Application delivery is currently in progress. Retry deletion after delivery has finished.', $exception);
        }
        $cause = $cause->getPrevious();
      }
      throw $exception;
    }
    finally {
      if ($application_lock_acquired
        && $this->applicationMutationLock instanceof ApplicationDependentMutationLock) {
        $this->applicationMutationLock->release($application);
      }
      if ($scope_lock_acquired
        && $this->applicantScopeMutationLock instanceof ApplicantScopeMutationLock) {
        $this->applicantScopeMutationLock->release($application);
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'id' => $uuid,
    ]);
  }

  /**
   * Returns decrypted application data for administrative detail/edit views.
   */
  public function view(ApplicationInterface $application): JsonResponse {
    $sensitive = $this->applicationRequiresProtectedReveal($application);
    $can_reveal = !$sensitive || $this->accountCanRevealProtectedData($application, $this->currentUser);
    $data = $can_reveal
      ? $this->revealDataJson($application->getData())
      : $this->redactApplicationData($application);
    // Logged only after a successful decrypt, so failures leave no entry.
    if ($sensitive && $can_reveal && $this->dataProtection instanceof ApplicationDataProtectionService) {
      $this->dataProtection->logProtectedReveal($application, $this->currentUser, 'admin_data');
    }
    $blocked_reason = $this->dataEditAccessBlockedReason($application, $this->currentUser);
    $schema = $this->applicationSchemaMeta($application);
    $blocked_reason ??= $schema === NULL ? 'schema' : NULL;
    $response = new JsonResponse(
          [
            'data' => $data,
            'changed' => (string) $application->getChangedTime(),
            'meta' => [
              'protected_data_redacted' => !$can_reveal,
              'schema' => $schema,
              'can_edit_data' => $blocked_reason === NULL,
              'can_delete' => $this->applicationCaseScope->canDelete($application, $this->currentUser),
              'edit_data_blocked_reason' => $blocked_reason,
            ],
          ]
      );
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Builds a revision-aware form schema projection for administrative clients.
   *
   * @return array<string, mixed>|null
   *   A JSON:API-like schema shape, or NULL when the application has no schema.
   */
  private function applicationSchemaMeta(ApplicationInterface $application): ?array {
    $schema = $this->applicationSchema($application);
    if (!$schema instanceof FormSchemaInterface) {
      return NULL;
    }

    return [
      'id' => $schema->uuid(),
      'type' => 'form_schema--form_schema',
      'attributes' => [
        'label' => (string) $schema->label(),
        'field_schema' => ['value' => $schema->getSchema()],
        'field_workflow_definition' => $schema->getWorkflowDefinition() !== NULL
          ? ['value' => $schema->getWorkflowDefinition()]
          : NULL,
        'field_version' => $schema->getVersion(),
        'changed' => gmdate(DATE_ATOM, $schema->getChangedTime()),
      ],
    ];
  }

  /**
   * Updates the workflow status and writes a guarded new revision.
   */
  public function updateStatus(ApplicationInterface $application, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing status update payload.');
    }

    $expected_status_id = $payload['expected_status_id'] ?? NULL;
    if (!is_string($expected_status_id) && !is_int($expected_status_id)) {
      throw new HttpException(428, 'Missing expected workflow status.');
    }
    $expected_status_id = (int) $expected_status_id;
    $current_status_id = (int) ($application->get('field_workflow_status')->target_id ?? 0);
    if ($expected_status_id <= 0 || $expected_status_id !== $current_status_id) {
      throw new ConflictHttpException('Application workflow status changed since it was loaded.');
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new HttpException(428, 'Missing expected changed timestamp.');
    }
    if ($this->normalizeChangedTimestamp($expected_changed) !== $application->getChangedTime()) {
      throw new ConflictHttpException('Application changed since it was loaded.');
    }

    $target_status_id = $payload['target_status_id'] ?? NULL;
    if (!is_string($target_status_id) && !is_int($target_status_id)) {
      throw new BadRequestHttpException('Missing target workflow status.');
    }
    $target_status_id = (int) $target_status_id;
    if ($target_status_id <= 0 || $target_status_id === $current_status_id) {
      throw new BadRequestHttpException('Target workflow status is invalid.');
    }

    $attributes = $payload['attributes'] ?? [];
    // An empty JSON object decodes to [] in PHP and is a valid "no
    // attributes" payload for transitions without attribute definitions.
    if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
      throw new BadRequestHttpException('Workflow attributes must be an object.');
    }
    if (array_key_exists(self::SUBMISSION_LOCALE_WORKFLOW_ATTRIBUTE, $attributes)) {
      throw new BadRequestHttpException('Workflow attributes contain reserved submission metadata.');
    }
    $attributes = $this->withPreservedSubmissionLocale($application, $attributes);

    try {
      $attributes_json = $this->encodeWorkflowAttributes($attributes);
    }
    catch (\JsonException $exception) {
      throw new BadRequestHttpException('Workflow attributes are not valid JSON.', $exception);
    }
    if (strlen($attributes_json) > 16384) {
      throw new BadRequestHttpException('Workflow attributes are too large.');
    }

    // The internal staff endpoint accepts a separate citizen-facing reason.
    // A revision note or workflow attribute has no such audience.
    $reason = $payload['public_rejection_reason'] ?? '';
    if (!is_string($reason) || mb_strlen($reason) > 1000) {
      throw new BadRequestHttpException('The public rejection reason must be text of at most 1000 characters.');
    }
    $reason = trim(preg_replace('/[\p{C}\s]+/u', ' ', strip_tags($reason)) ?? '');

    $note = is_string($payload['note'] ?? NULL) ? trim($payload['note']) : '';
    if (strlen($note) > 2000) {
      $note = substr($note, 0, 2000);
    }

    $application->setNewRevision(TRUE);
    $application->setRevisionUserId((int) $this->currentUser->id());
    $application->setRevisionCreationTime($this->time->getRequestTime());
    RevisionLog::setMessage($application, $note);
    $application->set('field_workflow_status', ['target_id' => $target_status_id]);
    $application->set('field_workflow_attributes', $attributes_json);
    $this->applyStatusSlaDueDate($application, $target_status_id);

    $violations = $application->validate();
    if ($violations->count() > 0) {
      throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
    }

    PublicRejectionReason::duringSave($application, $reason, $this->saveUnlessLocked(...));

    return new JsonResponse(
          [
            'success' => TRUE,
            'revision_id' => (int) $application->getRevisionId(),
            'target_status' => $this->statusSummaryFromApplication($application),
          ]
      );
  }

  /**
   * Updates the internal due date and writes a guarded new revision.
   */
  public function updateDeadline(ApplicationInterface $application, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing deadline update payload.');
    }

    if (!$application->hasField('field_due_date')) {
      throw new UnprocessableEntityHttpException('Application due date field is not installed.');
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new HttpException(428, 'Missing expected changed timestamp.');
    }
    if ($this->normalizeChangedTimestamp($expected_changed) !== $application->getChangedTime()) {
      throw new ConflictHttpException('Application changed since it was loaded.');
    }

    if (!array_key_exists('due_date', $payload)) {
      throw new BadRequestHttpException('Missing due date.');
    }
    $due_date = $payload['due_date'];
    if ($due_date !== NULL && !is_string($due_date)) {
      throw new BadRequestHttpException('Due date must be a date string or null.');
    }
    if (is_string($due_date)) {
      $due_date = trim($due_date);
      if ($due_date === '') {
        $due_date = NULL;
      }
      elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        throw new BadRequestHttpException('Due date must use YYYY-MM-DD.');
      }
      else {
        [$year, $month, $day] = array_map('intval', explode('-', $due_date));
        if (!checkdate($month, $day, $year)) {
          throw new BadRequestHttpException('Due date is invalid.');
        }
      }
    }

    $note = is_string($payload['note'] ?? NULL) ? trim($payload['note']) : '';
    if (strlen($note) > 2000) {
      $note = substr($note, 0, 2000);
    }
    RevisionLog::setEvent($application, 'due_date_changed');

    $application->setNewRevision(TRUE);
    $application->setRevisionUserId((int) $this->currentUser->id());
    $application->setRevisionCreationTime($this->time->getRequestTime());
    RevisionLog::setMessage($application, $note);
    $application->set('field_due_date', $due_date);

    $violations = $application->validate();
    if ($violations->count() > 0) {
      throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
    }

    $this->saveUnlessLocked($application);

    return new JsonResponse(
          [
            'success' => TRUE,
            'revision_id' => (int) $application->getRevisionId(),
            'due_date' => $due_date,
          ]
      );
  }

  /**
   * Saves a staff change, or answers 423 while another process holds the case.
   *
   * A running transfer, deletion or comment holds the shared case lock that the
   * application presave hook takes without waiting. Nothing was stored then,
   * and the editor may send the same change again shortly.
   */
  private function saveUnlessLocked(ApplicationInterface $application): void {
    try {
      $application->save();
    }
    catch (EntityStorageException $exception) {
      throw ApplicationLockedHttpException::fromFailure($exception);
    }
  }

  /**
   * Applies the target status SLA as a due date when configured.
   */
  private function applyStatusSlaDueDate(ApplicationInterface $application, int $target_status_id): void {
    if ($target_status_id <= 0 || !$application->hasField('field_due_date')) {
      return;
    }

    $status = $this->manager
      ->getStorage('workflow_status')
      ->load($target_status_id);
    if (!$status instanceof WorkflowStatusInterface || $status->isTerminal()) {
      return;
    }

    $sla_days = $status->getSlaDays();
    if ($sla_days <= 0) {
      return;
    }

    $due_date = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone('Europe/Berlin'))
      ->modify('+' . $sla_days . ' days')
      ->format('Y-m-d');
    $application->set('field_due_date', $due_date);
  }

  /**
   * Returns the previous distinct workflow status from revision history.
   */
  public function statusRollbackTarget(ApplicationInterface $application): JsonResponse {
    $rollback_context = $this->workflowStatusRollbackContext($application);
    if ($rollback_context === NULL) {
      return new JsonResponse(
            [
              'available' => FALSE,
            ]
        );
    }

    $previous_revision = $rollback_context['targetRevision'];
    $target_status_id = (int) ($previous_revision->get('field_workflow_status')->target_id ?? 0);
    if (!$this->rollbackTargetInWorkflow($application, $target_status_id)) {
      // Said before the click: the page offers the status correction.
      return new JsonResponse([
        'available' => FALSE,
        'reason' => self::ROLLBACK_TARGET_NOT_IN_WORKFLOW_REASON,
        'currentStatus' => $this->statusSummaryFromApplication($application),
        'targetStatus' => $this->statusSummaryFromApplication($previous_revision),
      ]);
    }

    return new JsonResponse(
          [
            'available' => TRUE,
            'currentStatus' => $this->statusSummaryFromApplication($application),
            'targetStatus' => $this->statusSummaryFromApplication($previous_revision),
            'currentStatusRevision' => $this->revisionSummary($rollback_context['currentStatusRevision']),
          ]
      );
  }

  /**
   * Resets the application to the previous distinct workflow status.
   */
  public function rollbackStatus(ApplicationInterface $application, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing status rollback payload.');
    }

    $expected_status_id = $payload['expected_status_id'] ?? NULL;
    if (!is_string($expected_status_id) && !is_int($expected_status_id)) {
      throw new HttpException(428, 'Missing expected workflow status.');
    }
    $expected_status_id = (int) $expected_status_id;
    if ($expected_status_id <= 0 || $expected_status_id !== (int) ($application->get('field_workflow_status')->target_id ?? 0)) {
      throw new ConflictHttpException('Application workflow status changed since it was loaded.');
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new HttpException(428, 'Missing expected changed timestamp.');
    }
    if ($this->normalizeChangedTimestamp($expected_changed) !== $application->getChangedTime()) {
      throw new ConflictHttpException('Application changed since it was loaded.');
    }

    $rollback_context = $this->workflowStatusRollbackContext($application);
    if ($rollback_context === NULL) {
      throw new ConflictHttpException('No previous workflow status revision exists.');
    }

    $previous_revision = $rollback_context['targetRevision'];
    $target_status_id = (int) ($previous_revision->get('field_workflow_status')->target_id ?? 0);
    if ($target_status_id <= 0) {
      throw new UnprocessableEntityHttpException('Previous workflow status is missing.');
    }
    if (!$this->rollbackTargetInWorkflow($application, $target_status_id)) {
      // Refused before anything changes: no save, so no status mail.
      $message = 'The previous status is not part of the workflow that governs this form.';
      $response = new JsonResponse([
        'message' => $message,
        'error' => [
          'code' => self::ROLLBACK_TARGET_NOT_IN_WORKFLOW_CODE,
          'message' => $message,
        ],
      ], 422);
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      return $response;
    }

    $note = is_string($payload['note'] ?? NULL) ? trim($payload['note']) : '';
    if (strlen($note) > 2000) {
      $note = substr($note, 0, 2000);
    }
    $previous_attributes = $this->withPreservedSubmissionLocale(
      $application,
      $this->decodeWorkflowAttributes($previous_revision->get('field_workflow_attributes')->value ?? NULL),
    );
    $previous_attributes_json = $previous_attributes === []
      ? NULL
      : $this->encodeWorkflowAttributes($previous_attributes);

    $application->allowWorkflowStatusReset();
    $application->setNewRevision(TRUE);
    $application->setRevisionUserId((int) $this->currentUser->id());
    $application->setRevisionCreationTime($this->time->getRequestTime());
    RevisionLog::setMessage($application, $note);
    $application->set('field_workflow_status', ['target_id' => $target_status_id]);
    $application->set('field_workflow_attributes', $previous_attributes_json);

    $violations = $application->validate();
    if ($violations->count() > 0) {
      throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
    }

    $this->saveUnlessLocked($application);

    return new JsonResponse(
          [
            'success' => TRUE,
            'revision_id' => (int) $application->getRevisionId(),
            'target_status' => $this->statusSummaryFromApplication($application),
          ]
      );
  }

  /**
   * Validates submitted data against the application's form schema.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application being edited.
   * @param array<string, mixed> $data
   *   Submitted application data.
   *
   * @return \Drupal\beantragt_schema\Entity\FormSchemaInterface
   *   The schema revision used to validate this edit.
   */
  private function validateApplicationData(ApplicationInterface $application, array &$data): FormSchemaInterface {
    $schema_entity = $this->applicationSchema($application);
    if (!$schema_entity instanceof FormSchemaInterface) {
      throw new UnprocessableEntityHttpException('Application schema is missing.');
    }

    $original_data = $this->decodeStoredData($application->getData());

    $data = $this->dataEditValidator->normalizeData($schema_entity, $data);
    // The origin decides whether a repeatable section holds entry columns;
    // it is read-only and set at intake, the edit cannot change it.
    $this->dataEditValidator->validate($schema_entity, $data, $original_data, $application->getOrigin());

    return $schema_entity;
  }

  /**
   * Resolves the submitted form schema without falling back past a pin.
   */
  private function applicationSchema(ApplicationInterface $application): ?FormSchemaInterface {
    return PublicSubmission::protectedSchema($application, $this->manager);
  }

  /**
   * Determines whether viewing the data requires the protected reveal gate.
   */
  private function applicationRequiresProtectedReveal(ApplicationInterface $application): bool {
    try {
      return $this->dataProtection?->applicationRequiresProtectedReveal($application) ?? TRUE;
    }
    catch (\Throwable) {
      return TRUE;
    }
  }

  /**
   * Decodes stored application data.
   *
   * @return array<string, mixed>
   *   Decoded stored data.
   */
  private function decodeStoredData(?string $data_json): array {
    return $this->revealDataJson($data_json);
  }

  /**
   * Decodes stored application data, decrypting protected values when possible.
   */
  private function revealDataJson(?string $data_json): array {
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      return $this->dataProtection->revealDataJson($data_json);
    }

    if ($data_json === NULL || trim($data_json) === '') {
      return [];
    }

    try {
      $data = json_decode($data_json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    return is_array($data) && !array_is_list($data) ? $data : [];
  }

  /**
   * Returns redacted application data for scoped users without reveal access.
   */
  private function redactApplicationData(ApplicationInterface $application): array {
    if ($this->dataProtection instanceof ApplicationDataProtectionService) {
      return $this->dataProtection->redactApplicationData($application);
    }

    $data = $this->revealDataJson($application->getData());
    return array_fill_keys(
          array_keys($data), [
            ApplicationDataProtectionService::REDACTED_MARKER => TRUE,
          ]
      );
  }

  /**
   * Returns TRUE when an account may reveal sensitive application data values.
   */
  private function accountCanRevealProtectedData(ApplicationInterface $application, AccountInterface $account): bool {
    if ($account->hasPermission('administer application entities')
          || $account->hasPermission('bypass beantragt group scope')
      ) {
      return TRUE;
    }
    if (!$account->hasPermission('view protected application data')) {
      return FALSE;
    }
    return $this->protectedDataAccess($application, $account)->isAllowed();
  }

  /**
   * Whether "Rückgängig" may lead into a status.
   *
   * Only into a status of the workflow that governs the application's form
   * (EffectiveWorkflowResolver::governingStatusIds(): the published
   * workflow, or for a form without own workflow the statuses its standard
   * reaches), terminal statuses included. An undo is a person's change, and
   * a status the form switched off would bring back its outcome and mail.
   * A status without group restricts nothing, as everywhere in the resolver.
   */
  private function rollbackTargetInWorkflow(ApplicationInterface $application, int $target_status_id): bool {
    $target = $this->manager->getStorage('workflow_status')->load($target_status_id);
    if (!$target instanceof WorkflowStatusInterface) {
      return FALSE;
    }
    $group_id = $target->getGroupId();
    if ($group_id === NULL || $group_id <= 0) {
      return TRUE;
    }
    return in_array($target_status_id, $this->workflowResolver()->governingStatusIds($application, $group_id), TRUE);
  }

  /**
   * Builds context for undoing the latest distinct workflow status run.
   *
   * @return array{targetRevision: \Drupal\beantragt_application\Entity\ApplicationInterface, currentStatusRevision: \Drupal\beantragt_application\Entity\ApplicationInterface}|null
   *   The earlier target revision and the revision that introduced the current
   *   status run, or NULL when no earlier distinct status exists.
   */
  private function workflowStatusRollbackContext(ApplicationInterface $application): ?array {
    $application_id = $application->id();
    if ($application_id === NULL) {
      return NULL;
    }

    $storage = $this->manager->getStorage('application');
    if (!$storage instanceof RevisionableStorageInterface) {
      return NULL;
    }

    $current_revision_id = (int) $application->getRevisionId();
    $current_status_id = (int) ($application->get('field_workflow_status')->target_id ?? 0);
    if ($current_status_id <= 0) {
      return NULL;
    }

    $current_status_revision = $application;
    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $application_id)
      ->sort('revision_id', 'DESC')
      ->execute();

    foreach (array_keys($revision_ids) as $revision_id) {
      $revision_id = (int) $revision_id;
      if ($revision_id === $current_revision_id) {
        continue;
      }
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof ApplicationInterface) {
        continue;
      }
      if (!$revision->access('view', $this->currentUser)) {
        // A rollback cannot skip an invisible intervening scope transition.
        return NULL;
      }
      $revision_status_id = (int) ($revision->get('field_workflow_status')->target_id ?? 0);
      if ($revision_status_id === $current_status_id) {
        $current_status_revision = $revision;
        continue;
      }
      if ($revision_status_id > 0) {
        return [
          'targetRevision' => $revision,
          'currentStatusRevision' => $current_status_revision,
        ];
      }
    }

    return NULL;
  }

  /**
   * Builds revision metadata for the admin status history UI.
   *
   * @return array<string, mixed>
   *   Revision id, timestamp and author (or authorSystem for automatic
   *   changes) for the status history UI.
   */
  private function revisionSummary(ApplicationInterface $application): array {
    $summary = [
      'id' => (int) $application->getRevisionId(),
    ];

    $created = (int) $application->getRevisionCreationTime();
    if ($created > 0) {
      $summary['created'] = $created;
    }

    $revision_user = $application->getRevisionUser();
    if ($revision_user instanceof AccountInterface && $revision_user->isAnonymous()) {
      // Automatic changes (uid 0) belong to the system, not to "Anonymous".
      $summary['authorSystem'] = TRUE;
    }
    elseif ($revision_user instanceof UserInterface && $revision_user->access('view label', $this->currentUser)) {
      // Core grants "view label" on every user; the check only honours a
      // module that forbids it via hook_user_access(). The real boundary is
      // the staff-only route, the same as for the applicant contact data.
      $summary['authorName'] = $revision_user->getDisplayName();
    }

    return $summary;
  }

  /**
   * Builds a small status summary from an application's workflow status.
   *
   * @return array<string, mixed>
   *   Status target information for the admin UI.
   */
  private function statusSummaryFromApplication(ApplicationInterface $application): array {
    $status_id = (int) ($application->get('field_workflow_status')->target_id ?? 0);
    $status = $application->get('field_workflow_status')->entity ?? NULL;
    if (!$status instanceof FieldableEntityInterface || !$status->access('view', $this->currentUser)) {
      return [
        'internalId' => $status_id,
      ];
    }

    return [
      'id' => $status->uuid(),
      'internalId' => $status_id,
      'label' => $status->label(),
      'machineName' => $status->hasField('field_machine_name') ? (string) ($status->get('field_machine_name')->value ?? '') : '',
      'color' => $status->hasField('field_color') ? (string) ($status->get('field_color')->value ?? '') : '',
      'icon' => $status->hasField('field_icon') ? (string) ($status->get('field_icon')->value ?? '') : '',
    ];
  }

  /**
   * Normalizes a JSON:API changed value or unix timestamp for comparison.
   */
  private function normalizeChangedTimestamp(string|int $value): int {
    if (is_int($value)) {
      return $value;
    }

    if (ctype_digit($value)) {
      return (int) $value;
    }

    $timestamp = strtotime($value);
    if ($timestamp === FALSE) {
      throw new BadRequestHttpException('Expected changed timestamp is invalid.');
    }
    return $timestamp;
  }

  /**
   * Encodes workflow attributes as a JSON object string.
   *
   * @param array<string, mixed> $attributes
   *   Workflow attributes.
   *
   * @throws \JsonException
   *   Thrown when the attributes cannot be encoded.
   */
  private function encodeWorkflowAttributes(array $attributes): string {
    return $attributes === []
      ? '{}'
      : json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

  /**
   * Decodes stored workflow attributes.
   *
   * @return array<string, mixed>
   *   Stored object attributes, or an empty object when absent or invalid.
   */
  private function decodeWorkflowAttributes(?string $attributes_json): array {
    if ($attributes_json === NULL || trim($attributes_json) === '') {
      return [];
    }

    try {
      $attributes = json_decode($attributes_json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
      return [];
    }

    return $attributes;
  }

  /**
   * Keeps submit-time metadata across workflow status attribute writes.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application with stored workflow attributes.
   * @param array<string, mixed> $attributes
   *   Workflow transition attributes.
   *
   * @return array<string, mixed>
   *   Attributes with durable submission metadata restored.
   */
  private function withPreservedSubmissionLocale(ApplicationInterface $application, array $attributes): array {
    $submission_locale = $this->submissionLocaleFromApplication($application);
    if ($submission_locale !== NULL) {
      $attributes[self::SUBMISSION_LOCALE_WORKFLOW_ATTRIBUTE] = $submission_locale;
    }

    return $attributes;
  }

  /**
   * Returns the stored submit-time locale for citizen notifications.
   */
  private function submissionLocaleFromApplication(ApplicationInterface $application): ?string {
    $attributes = $this->decodeWorkflowAttributes($application->get('field_workflow_attributes')->value ?? NULL);
    $submission_locale = $attributes[self::SUBMISSION_LOCALE_WORKFLOW_ATTRIBUTE] ?? NULL;

    return is_string($submission_locale) && isset(self::SUBMISSION_LOCALES[$submission_locale])
      ? $submission_locale
      : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
          $container->get('current_user'),
          $container->get('datetime.time'),
          $container->get('entity_type.manager'),
          $container->get('beantragt_application.application_case_scope'),
          $container->get('beantragt_application.data_protection'),
          $container->get('beantragt_application.data_edit_validator'),
          $container->get('beantragt_application.dependent_mutation_lock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
          $container->get('beantragt_application.applicant_scope_mutation_lock'),
          $container->get('beantragt_application.protected_data_gate'),
          $container->get('beantragt_application.effective_workflow_resolver'),
          $container->get('beantragt_application.correction_transfer_lock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
      );
  }

}
