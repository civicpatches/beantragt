<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\beantragt_application\ApplicationAssignmentPolicy;
use Drupal\beantragt_notification\Service\NotificationOutcomeCollector;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership as GroupMembershipEntity;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Handles scoped administrative application assignment.
 */
final class ApplicationAssignmentController extends ControllerBase {

  public function __construct(
    AccountInterface $current_user,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $manager,
    private readonly ApplicationCaseScope $applicationCaseScope,
    private readonly ?EntityFieldManagerInterface $fieldManager = NULL,
    private readonly ?object $orgHierarchyResolver = NULL,
    private readonly ?object $notificationMailer = NULL,
    private readonly ?Connection $database = NULL,
    private readonly ?object $jurisdictionConfigService = NULL,
    private readonly ?NotificationOutcomeCollector $notificationOutcomeCollector = NULL,
  ) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $org_hierarchy_resolver = NULL;
    try {
      $org_hierarchy_resolver = $container->get('beantragt_group.org_hierarchy_resolver');
    }
    catch (ServiceNotFoundException) {
      $org_hierarchy_resolver = NULL;
    }

    $notification_mailer = NULL;
    try {
      $notification_mailer = $container->get('beantragt_notification.mailer');
    }
    catch (ServiceNotFoundException) {
      $notification_mailer = NULL;
    }

    $jurisdiction_config = NULL;
    try {
      $jurisdiction_config = $container->get('beantragt_group.jurisdiction_config');
    }
    catch (ServiceNotFoundException) {
      $jurisdiction_config = NULL;
    }

    $notification_outcome_collector = NULL;
    try {
      $notification_outcome_collector = $container->get('beantragt_notification.sent_mail_collector');
    }
    catch (ServiceNotFoundException) {
      $notification_outcome_collector = NULL;
    }

    return new static(
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('beantragt_application.application_case_scope'),
      $container->get('entity_field.manager'),
      $org_hierarchy_resolver,
      $notification_mailer,
      $container->get('database'),
      $jurisdiction_config,
      $notification_outcome_collector,
    );
  }

  /**
   * Checks scoped access for viewing assignment metadata.
   */
  public function viewAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->scopeAccess(
      $application,
      $account,
      'view workflow assignments',
      'User may not view application assignment for this tenant.',
    );
  }

  /**
   * Checks scoped access for changing assignment.
   */
  public function assignAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    if (!$application->isEmailConfirmed()) {
      return AccessResult::forbidden('Application applicant email is not confirmed.')
        ->addCacheableDependency($application);
    }
    return $this->scopeAccess(
      $application,
      $account,
      'assign applications',
      'User may not assign applications for this tenant.',
    );
  }

  /**
   * Checks scoped access for delegating an application to another organisation.
   */
  public function delegateAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    if (!$account->hasPermission('delegate applications')
      && !$account->hasPermission('administer application entities')
      && !$account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::forbidden('Missing delegate applications permission.')
        ->cachePerPermissions()->addCacheableDependency($application);
    }

    return $this->applicationCaseScope
      ->access($application, $account)
      ->andIf($application->access('update', $account, TRUE));
  }

  /**
   * Returns assignable users for the application's tenant scope.
   */
  public function candidates(ApplicationInterface $application): JsonResponse {
    $candidates = $this->candidateMap($application);
    $team_candidates = $this->teamCandidateMap($application);
    $personal_assignment = ApplicationAssignmentPolicy::personalAssignmentEnabled($application);
    $can_assign = $this->currentUser->hasPermission('assign applications')
      && $this->assignAccess($application, $this->currentUser)->isAllowed();
    $can_delegate = $this->currentUser->hasPermission('delegate applications')
      && $this->delegateAccess($application, $this->currentUser)->isAllowed();
    $response = new JsonResponse([
      'current' => $this->currentAssignment($application, $candidates, $team_candidates),
      'can_assign' => $can_assign,
      'can_delegate' => $can_delegate,
      'note_required' => $this->delegationNoteRequired($application),
      'responsibility' => $this->responsibilityProjection($application),
      'personal_assignment_enabled' => $personal_assignment,
      'candidates' => $personal_assignment ? array_values($candidates) : [],
      'teams' => array_values($team_candidates),
      'delegation_targets' => array_values($this->delegationTargetMap($application)),
    ]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Updates assignment target after validating workspace and candidate scope.
   */
  public function assign(ApplicationInterface $application, Request $request): JsonResponse {
    $this->notificationOutcomeCollector?->drain();
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing assignment payload.');
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new BadRequestHttpException('Missing expected changed timestamp.');
    }
    $normalized_expected_changed = $this->normalizeChangedTimestamp($expected_changed);
    if ($normalized_expected_changed < 0) {
      throw new BadRequestHttpException('Invalid expected changed timestamp.');
    }
    if ($normalized_expected_changed !== $application->getChangedTime()) {
      throw new ConflictHttpException('Application changed since it was loaded.');
    }
    $expected_revision_id = $payload['expected_revision_id'] ?? NULL;
    if ($expected_revision_id === NULL || $expected_revision_id === '') {
      throw new HttpException(428, 'Missing expected revision id.');
    }
    if (!is_int($expected_revision_id) && !is_string($expected_revision_id)) {
      throw new BadRequestHttpException('Expected revision id must be an integer.');
    }
    $normalized_expected_revision_id = filter_var($expected_revision_id, FILTER_VALIDATE_INT, [
      'options' => ['min_range' => 1],
    ]);
    if ($normalized_expected_revision_id === FALSE) {
      throw new BadRequestHttpException('Expected revision id must be an integer.');
    }
    if ((int) $normalized_expected_revision_id !== (int) $application->getRevisionId()) {
      throw new ConflictHttpException('Application revision changed since it was loaded.');
    }

    $assignee_uuid = $payload['assignee_uuid'] ?? NULL;
    if ($assignee_uuid !== NULL && !is_string($assignee_uuid)) {
      throw new BadRequestHttpException('Assignee must be a user UUID or null.');
    }
    $assignee_uuid = is_string($assignee_uuid) ? trim($assignee_uuid) : NULL;
    if ($assignee_uuid === '') {
      $assignee_uuid = NULL;
    }
    if ($assignee_uuid !== NULL && !preg_match('/^[0-9a-fA-F-]{36}$/', $assignee_uuid)) {
      throw new BadRequestHttpException('Assignee must be a valid UUID.');
    }

    $team_uuid = $payload['team_uuid'] ?? NULL;
    if ($team_uuid !== NULL && !is_string($team_uuid)) {
      throw new BadRequestHttpException('Team must be a group UUID or null.');
    }
    $team_uuid = is_string($team_uuid) ? trim($team_uuid) : NULL;
    if ($team_uuid === '') {
      $team_uuid = NULL;
    }
    if ($team_uuid !== NULL && !preg_match('/^[0-9a-fA-F-]{36}$/', $team_uuid)) {
      throw new BadRequestHttpException('Team must be a valid UUID.');
    }
    if ($assignee_uuid !== NULL && $team_uuid !== NULL) {
      throw new BadRequestHttpException('Assign to either a user or a team, not both.');
    }

    $assignee = $assignee_uuid === NULL ? NULL : $this->loadUserByUuid($assignee_uuid);
    if ($assignee_uuid !== NULL && !ApplicationAssignmentPolicy::personalAssignmentEnabled($application)) {
      throw new UnprocessableEntityHttpException('Personal assignment is disabled for this tenant.');
    }
    if ($assignee_uuid !== NULL && !$assignee instanceof UserInterface) {
      throw new NotFoundHttpException('Assignee not found.');
    }

    $candidate_map = $this->candidateMap($application);
    $team_candidate_map = $this->teamCandidateMap($application);
    if ($assignee instanceof UserInterface) {
      if (!isset($candidate_map[$assignee->uuid()])) {
        throw new UnprocessableEntityHttpException('Assignee is not eligible for this application scope.');
      }
    }

    $team = $team_uuid === NULL ? NULL : $this->loadOrgGroupByUuid($team_uuid);
    if ($team_uuid !== NULL && !$team instanceof GroupInterface) {
      throw new NotFoundHttpException('Team not found.');
    }
    if ($team instanceof GroupInterface && !isset($team_candidate_map[$team->uuid()])) {
      throw new UnprocessableEntityHttpException('Team is not eligible for this application scope.');
    }

    $note = $payload['note'] ?? '';
    if (!is_string($note)) {
      throw new BadRequestHttpException('Assignment note must be a string.');
    }
    $note = trim(strip_tags($note));
    if (mb_strlen($note) > 2000) {
      throw new UnprocessableEntityHttpException('Assignment note must not exceed 2000 characters.');
    }
    if (!$team instanceof GroupInterface
      && (!$assignee instanceof UserInterface || (int) $assignee->id() === (int) $this->currentUser->id())) {
      $note = '';
    }

    $application->setNewRevision(TRUE);
    $application->setRevisionUserId((int) $this->currentUser->id());
    $application->setRevisionCreationTime($this->time->getRequestTime());
    RevisionLog::setEvent($application, 'assignment_changed');
    if ($application->hasField('field_assigned_to')) {
      $application->set(
        'field_assigned_to',
        $assignee instanceof UserInterface ? ['target_id' => (int) $assignee->id()] : NULL,
      );
    }
    if ($application->hasField('field_assigned_team')) {
      $application->set(
        'field_assigned_team',
        $team instanceof GroupInterface ? ['target_id' => (int) $team->id()] : NULL,
      );
    }

    $violations = $application->validate();
    if ($violations->count() > 0) {
      throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
    }

    $transaction = $note !== '' ? $this->database?->startTransaction() : NULL;
    try {
      // Save the note first; the assignment save triggers its mail hook.
      // A note failure must not send assignment mail.
      if ($note !== '') {
        $this->createAssignmentAuditComment($application, $assignee ?? $team, $note);
      }
      $application->save();
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      // A running transfer or deletion holds the case: nothing was stored.
      throw ApplicationLockedHttpException::fromFailure($exception);
    }
    unset($transaction);

    $response = new JsonResponse([
      'success' => TRUE,
      'revision_id' => (int) $application->getRevisionId(),
      'assignee' => $this->currentAssignment($application, $candidate_map, $team_candidate_map),
      'notifications' => $this->notificationOutcomeCollector?->drain() ?? [],
    ]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Delegates an application to another organisation in the same jurisdiction.
   */
  public function delegate(ApplicationInterface $application, Request $request): JsonResponse {
    $this->notificationOutcomeCollector?->drain();
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Missing delegation payload.');
    }

    $expected_changed = $payload['expected_changed'] ?? NULL;
    if (!is_string($expected_changed) && !is_int($expected_changed)) {
      throw new BadRequestHttpException('Missing expected changed timestamp.');
    }
    $normalized_expected_changed = $this->normalizeChangedTimestamp($expected_changed);
    if ($normalized_expected_changed < 0) {
      throw new BadRequestHttpException('Invalid expected changed timestamp.');
    }
    if ($normalized_expected_changed !== $application->getChangedTime()) {
      throw new ConflictHttpException('Application changed since it was loaded.');
    }
    $expected_revision_id = $payload['expected_revision_id'] ?? NULL;
    if ($expected_revision_id !== NULL && (!is_int($expected_revision_id) && !is_string($expected_revision_id))) {
      throw new BadRequestHttpException('Expected revision id must be an integer.');
    }
    $normalized_expected_revision_id = $expected_revision_id === NULL ? NULL : filter_var($expected_revision_id, FILTER_VALIDATE_INT, [
      'options' => ['min_range' => 1],
    ]);
    if ($expected_revision_id !== NULL && $normalized_expected_revision_id === FALSE) {
      throw new BadRequestHttpException('Expected revision id must be an integer.');
    }
    if ($normalized_expected_revision_id !== NULL && (int) $normalized_expected_revision_id !== (int) $application->getRevisionId()) {
      throw new ConflictHttpException('Application revision changed since it was loaded.');
    }

    $target_org_uuid = $payload['target_org_uuid'] ?? NULL;
    if (!is_string($target_org_uuid)) {
      throw new BadRequestHttpException('Target organisation is required.');
    }
    $target_org_uuid = trim($target_org_uuid);
    if ($target_org_uuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $target_org_uuid)) {
      throw new BadRequestHttpException('Target organisation must be a valid UUID.');
    }

    $note = $payload['note'] ?? NULL;
    if ($note !== NULL && !is_string($note)) {
      throw new BadRequestHttpException('Delegation note must be a string.');
    }
    $note = $this->sanitizeDelegationNote($note ?? '');

    $target_org = $this->loadGroupByUuid($target_org_uuid);
    if (!$target_org instanceof GroupInterface) {
      throw new NotFoundHttpException('Target organisation not found.');
    }
    if ($target_org->bundle() !== 'org') {
      throw new UnprocessableEntityHttpException('Target must be an organisation group.');
    }

    $source_scope = $this->applicationScopeGroup($application);
    if (!$source_scope instanceof GroupInterface) {
      throw new UnprocessableEntityHttpException('Application has no tenant scope.');
    }
    $source_jurisdiction = $this->jurisdictionForScope($source_scope);
    $target_jurisdiction = $this->parentJurisdiction($target_org);
    if (!$source_jurisdiction instanceof GroupInterface || !$target_jurisdiction instanceof GroupInterface) {
      throw new UnprocessableEntityHttpException('Delegation requires source and target jurisdiction scopes.');
    }
    if ($note === '' && $this->delegationNoteRequiredForJurisdiction($source_jurisdiction)) {
      throw new BadRequestHttpException('Delegation note is required.');
    }
    if ((int) $source_jurisdiction->id() !== (int) $target_jurisdiction->id()) {
      throw new UnprocessableEntityHttpException('Target organisation is outside the application jurisdiction.');
    }
    if ($source_scope->bundle() === 'org' && (int) $source_scope->id() === (int) $target_org->id()) {
      throw new UnprocessableEntityHttpException('Target organisation is already responsible for this application.');
    }

    // Fail before mutating: the target organisation must be notifiable, or a
    // delegation would persist while the 500 suggests it did not.
    if (!is_object($this->notificationMailer) || !method_exists($this->notificationMailer, 'sendStaffNotification')) {
      throw new ServiceUnavailableHttpException(NULL, 'The notification mailer service is unavailable.');
    }

    $source_label = $this->groupLabel($source_scope);
    $target_label = $this->groupLabel($target_org);

    $application->setNewRevision(TRUE);
    $application->setRevisionUserId((int) $this->currentUser->id());
    $application->setRevisionCreationTime($this->time->getRequestTime());
    RevisionLog::setEvent($application, 'delegated');
    RevisionLog::setMessage($application, $note);
    $source_status_machine_name = $application->getWorkflowStatus();
    $application->set('field_organisation', ['target_id' => (int) $target_org->id()]);
    $this->carryWorkflowStatusToTarget($application, $source_status_machine_name);
    if ($application->hasField('field_assigned_to')) {
      $application->set('field_assigned_to', NULL);
    }
    if ($application->hasField('field_assigned_team')) {
      $application->set('field_assigned_team', NULL);
    }

    $violations = $application->validate();
    if ($violations->count() > 0) {
      throw new UnprocessableEntityHttpException((string) $violations[0]->getMessage());
    }

    $transaction = $this->database?->startTransaction();
    try {
      $application->save();
      $this->createDelegationAuditComment($application, $source_label, $target_label, $note);
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      // A held case lock refuses the case save or the audit comment alike.
      throw ApplicationLockedHttpException::fromFailure($exception);
    }
    unset($transaction);

    $this->sendDelegationNotification($application, $source_label, $target_label, $note);

    $response = new JsonResponse([
      'success' => TRUE,
      'revision_id' => (int) $application->getRevisionId(),
      'target_org' => [
        'uuid' => (string) $target_org->uuid(),
        'label' => $target_label,
      ],
      'notifications' => $this->notificationOutcomeCollector?->drain() ?? [],
    ]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Checks permission and tenant scope membership.
   */
  private function scopeAccess(ApplicationInterface $application, AccountInterface $account, string $permission, string $forbidden_reason): AccessResultInterface {
    if ($account->hasPermission('administer application entities')
      || $account->hasPermission('bypass beantragt group scope')) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    if (!$account->hasPermission($permission)) {
      return AccessResult::forbidden("Missing $permission permission.")
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($application);
    }

    $group = $this->applicationScopeGroup($application);
    if (!$group instanceof GroupInterface) {
      return AccessResult::forbidden('Application has no tenant scope.')
        ->cachePerPermissions()
        ->cachePerUser()
        ->setCacheMaxAge(0)
        ->addCacheableDependency($application);
    }

    $allowed = $this->accountIsGroupMember($group, $account);
    $jurisdiction = NULL;
    if (!$allowed && $group->bundle() === 'org') {
      $jurisdiction = $this->parentJurisdiction($group);
      $allowed = $jurisdiction instanceof GroupInterface
        && $this->accountHasGroupAdminRole($jurisdiction, $account);
    }

    $result = $allowed ? AccessResult::allowed() : AccessResult::forbidden($forbidden_reason);
    $result->cachePerPermissions()->cachePerUser()
      ->setCacheMaxAge(0)
      ->addCacheableDependency($application)
      ->addCacheableDependency($group)
      ->addCacheTags(ApplicationCaseScope::cacheTags($account));
    if ($jurisdiction instanceof GroupInterface) {
      $result->addCacheableDependency($jurisdiction);
    }
    return $result;
  }

  /**
   * Builds assignable user projections keyed by user UUID.
   *
   * @return array<string, array{type: string, id: string, label: string}>
   *   Candidate projection map.
   */
  private function candidateMap(ApplicationInterface $application): array {
    $group = $this->applicationScopeGroup($application);
    if (!$group instanceof GroupInterface) {
      return [];
    }

    $candidates = $this->candidateMapForGroup($group, FALSE);
    if ($group->bundle() === 'org') {
      $jurisdiction = $this->parentJurisdiction($group);
      if ($jurisdiction instanceof GroupInterface) {
        $candidates += $this->candidateMapForGroup($jurisdiction, TRUE);
      }
    }

    uasort(
      $candidates,
      static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']),
    );
    return $candidates;
  }

  /**
   * Builds assignable user projections for one group.
   *
   * @return array<string, array{type: string, id: string, label: string}>
   *   Candidate projection map.
   */
  private function candidateMapForGroup(GroupInterface $group, bool $admins_only): array {
    $candidates = [];
    foreach (GroupMembershipEntity::loadByGroup($group) as $membership) {
      if ($admins_only && !ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE))) {
        continue;
      }
      $user = $membership->getEntity();
      if (!$user instanceof UserInterface || !$this->userCanReceiveAssignment($user)) {
        continue;
      }
      $uuid = (string) $user->uuid();
      if ($uuid === '') {
        continue;
      }
      $candidates[$uuid] = [
        'type' => 'user',
        'id' => $uuid,
        'label' => $this->userLabel($user),
      ];
    }
    return $candidates;
  }

  /**
   * Builds assignable team projections for the application's tenant scope.
   *
   * @return array<string, array{type: string, id: string, label: string, slug: string|null, parent_org_uuid: string|null}>
   *   Team candidate projection map.
   */
  private function teamCandidateMap(ApplicationInterface $application): array {
    $group = $this->applicationScopeGroup($application);
    if (!$group instanceof GroupInterface) {
      return [];
    }

    $teams = [];
    if ($group->bundle() === 'org') {
      foreach ($this->loadDescendantOrgGroups($group) as $organisation) {
        $this->addTeamCandidate($teams, $organisation);
      }
    }
    elseif ($group->bundle() === 'jur') {
      foreach ($this->loadOrgGroupsForJurisdiction($group) as $organisation) {
        $this->addTeamCandidate($teams, $organisation);
      }
    }

    uasort(
      $teams,
      static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']),
    );
    return $teams;
  }

  /**
   * Adds a team projection to a candidate map.
   *
   * @param array<string, array{type: string, id: string, label: string, slug: string|null, parent_org_uuid: string|null}> $teams
   *   Team candidates keyed by group UUID.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The organisation group to project.
   */
  private function addTeamCandidate(array &$teams, GroupInterface $group): void {
    if ($group->bundle() !== 'org') {
      return;
    }
    $uuid = (string) $group->uuid();
    if ($uuid === '') {
      return;
    }
    $slug = NULL;
    if ($group->hasField('field_slug')) {
      $slug_items = $group->get('field_slug')->getValue();
      $slug_value = trim((string) ($slug_items[0]['value'] ?? ''));
      $slug = $slug_value === '' ? NULL : $slug_value;
    }
    $teams[$uuid] = [
      'type' => 'team',
      'id' => $uuid,
      'label' => $this->groupLabel($group),
      'slug' => $slug,
      'parent_org_uuid' => $this->parentOrgUuid($group),
    ];
  }

  /**
   * Loads an organisation and its descendants for routing candidates.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Organisation groups including the input group.
   */
  private function loadDescendantOrgGroups(GroupInterface $group): array {
    if (!is_object($this->orgHierarchyResolver) || !method_exists($this->orgHierarchyResolver, 'getDescendantIds')) {
      return [$group];
    }

    $group_id = (int) $group->id();
    if ($group_id <= 0) {
      return [];
    }

    $ids = $this->orgHierarchyResolver->getDescendantIds($group_id);
    if (!is_array($ids)) {
      return [$group];
    }
    return $ids === [] ? [$group] : $this->loadOrgGroupsByIds($ids);
  }

  /**
   * Loads organisation groups inside one jurisdiction.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Organisation groups for the jurisdiction.
   */
  private function loadOrgGroupsForJurisdiction(GroupInterface $jurisdiction): array {
    if (!$this->groupFieldExists('field_jurisdiction')) {
      return [];
    }

    $jurisdiction_id = (int) $jurisdiction->id();
    if ($jurisdiction_id <= 0) {
      return [];
    }

    $ids = $this->manager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $jurisdiction_id)
      ->execute();
    return $this->loadOrgGroupsByIds($ids);
  }

  /**
   * Moves the workflow status into the target organisation's catalogue.
   *
   * Every organisation owns its status catalogue, so the source status is
   * invalid after a delegation. The status with the same machine name keeps
   * the progress and the citizen-facing text; without one the target's
   * default status applies. The change is a reset outside the target graph.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException
   *   When the target organisation has no usable status.
   */
  private function carryWorkflowStatusToTarget(ApplicationInterface $application, string $source_machine_name): void {
    $current = $application->get('field_workflow_status')->entity;
    // Without a stored status there is nothing to carry; resolve the
    // workflow owner only then (the resolver reads entities via \Drupal).
    if (!$current instanceof FieldableEntityInterface || !$current->hasField('field_group')) {
      return;
    }
    $target_group_id = function_exists('_beantragt_application_resolve_workflow_group_id')
      ? _beantragt_application_resolve_workflow_group_id($application)
      : NULL;
    if ($target_group_id === NULL || (int) $current->get('field_group')->target_id === $target_group_id) {
      return;
    }

    $status_id = NULL;
    if ($source_machine_name !== '') {
      $ids = $this->manager->getStorage('workflow_status')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_group', $target_group_id)
        ->condition('field_machine_name', $source_machine_name)
        ->range(0, 1)
        ->execute();
      $status_id = $ids === [] ? NULL : (int) reset($ids);
    }
    if ($status_id === NULL && function_exists('_beantragt_application_default_workflow_status_id')) {
      $status_id = _beantragt_application_default_workflow_status_id($application);
    }
    if ($status_id === NULL) {
      throw new UnprocessableEntityHttpException('The target organisation has no workflow status for this application.');
    }
    $application->setWorkflowStatusId($status_id);
    $application->allowWorkflowStatusReset();
  }

  /**
   * Builds delegation target projections for the application's jurisdiction.
   *
   * @return array<string, array{uuid: string, label: string, slug: string|null, parent_org_uuid: string|null}>
   *   Delegation targets keyed by group UUID.
   */
  private function delegationTargetMap(ApplicationInterface $application): array {
    $scope = $this->applicationScopeGroup($application);
    $jurisdiction = $scope instanceof GroupInterface ? $this->jurisdictionForScope($scope) : NULL;
    if (!$jurisdiction instanceof GroupInterface) {
      return [];
    }
    $current_org_id = $scope->bundle() === 'org'
      ? (int) $scope->id()
      : NULL;

    $targets = [];
    foreach ($this->loadOrgGroupsForJurisdiction($jurisdiction) as $organisation) {
      if ($current_org_id !== NULL && (int) $organisation->id() === $current_org_id) {
        continue;
      }
      $uuid = (string) $organisation->uuid();
      if ($uuid === '') {
        continue;
      }
      $slug = NULL;
      if ($organisation->hasField('field_slug')) {
        $slug_items = $organisation->get('field_slug')->getValue();
        $slug_value = trim((string) ($slug_items[0]['value'] ?? ''));
        $slug = $slug_value === '' ? NULL : $slug_value;
      }
      $targets[$uuid] = [
        'uuid' => $uuid,
        'label' => $this->groupLabel($organisation),
        'slug' => $slug,
        'parent_org_uuid' => $this->parentOrgUuid($organisation),
      ];
    }

    uasort(
      $targets,
      static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']),
    );
    return $targets;
  }

  /**
   * Resolves the direct parent org UUID for tree rendering.
   */
  private function parentOrgUuid(GroupInterface $group): ?string {
    if (!is_object($this->orgHierarchyResolver) || !method_exists($this->orgHierarchyResolver, 'getAncestorIds')) {
      return NULL;
    }

    $group_id = (int) $group->id();
    if ($group_id <= 0) {
      return NULL;
    }

    $ancestor_ids = $this->orgHierarchyResolver->getAncestorIds($group_id);
    if (!is_array($ancestor_ids)) {
      return NULL;
    }
    $parent_id = (int) ($ancestor_ids[0] ?? 0);
    if ($parent_id <= 0) {
      return NULL;
    }

    $parent = $this->manager->getStorage('group')->load($parent_id);
    if (!$parent instanceof GroupInterface || $parent->bundle() !== 'org') {
      return NULL;
    }

    $uuid = (string) $parent->uuid();
    return $uuid === '' ? NULL : $uuid;
  }

  /**
   * Loads org groups from entity ids.
   *
   * @param array<int|string> $ids
   *   Group ids returned by entity query.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Loaded org groups.
   */
  private function loadOrgGroupsByIds(array $ids): array {
    if ($ids === []) {
      return [];
    }

    return array_values(array_filter(
      $this->manager->getStorage('group')->loadMultiple($ids),
      static fn($group): bool => $group instanceof GroupInterface && $group->bundle() === 'org',
    ));
  }

  /**
   * Returns TRUE if an org group field exists in the current schema.
   */
  private function groupFieldExists(string $field_name): bool {
    if (!$this->fieldManager instanceof EntityFieldManagerInterface) {
      return TRUE;
    }
    return isset($this->fieldManager->getFieldDefinitions('group', 'org')[$field_name]);
  }

  /**
   * Returns the current assignment projection.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application entity.
   * @param array<string, array{type: string, id: string, label: string}> $candidates
   *   Assignable user candidate projections keyed by user UUID.
   * @param array<string, array{type: string, id: string, label: string}> $team_candidates
   *   Assignable team candidate projections keyed by group UUID.
   *
   * @return array{type: string, id: string, label: string, outOfScope?: bool}|null
   *   Current assignment projection, or NULL when unassigned.
   */
  private function currentAssignment(ApplicationInterface $application, array $candidates, array $team_candidates): ?array {
    if ($application->hasField('field_assigned_to') && !$application->get('field_assigned_to')->isEmpty()) {
      $user = $application->get('field_assigned_to')->entity ?? NULL;
      if (!$user instanceof UserInterface || (string) $user->uuid() === '') {
        return NULL;
      }
      $uuid = (string) $user->uuid();
      if (!isset($candidates[$uuid])) {
        return [
          'type' => 'user',
          'id' => '__out_of_scope__',
          'label' => '',
          'outOfScope' => TRUE,
        ];
      }
      return [
        'type' => 'user',
        'id' => $uuid,
        'label' => $this->userLabel($user),
      ];
    }

    if ($application->hasField('field_assigned_team') && !$application->get('field_assigned_team')->isEmpty()) {
      $team = $application->get('field_assigned_team')->entity ?? NULL;
      if (!$team instanceof GroupInterface || (string) $team->uuid() === '') {
        return NULL;
      }
      $uuid = (string) $team->uuid();
      if (!isset($team_candidates[$uuid])) {
        return [
          'type' => 'team',
          'id' => '__out_of_scope__',
          'label' => '',
          'outOfScope' => TRUE,
        ];
      }
      return [
        'type' => 'team',
        'id' => $uuid,
        'label' => $this->groupLabel($team),
      ];
    }

    return NULL;
  }

  /**
   * Returns the current application responsibility scope projection.
   *
   * @return array{label: string, kind: string}|null
   *   Responsibility projection, or NULL when the application has no scope.
   */
  private function responsibilityProjection(ApplicationInterface $application): ?array {
    $group = $this->applicationScopeGroup($application);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }

    return [
      'label' => $this->groupLabel($group),
      'kind' => $group->bundle() === 'org' ? 'org' : 'jur',
    ];
  }

  /**
   * Returns TRUE when the application's jurisdiction requires a note.
   */
  private function delegationNoteRequired(ApplicationInterface $application): bool {
    $scope = $this->applicationScopeGroup($application);
    $jurisdiction = $scope instanceof GroupInterface ? $this->jurisdictionForScope($scope) : NULL;
    if (!$jurisdiction instanceof GroupInterface) {
      return FALSE;
    }

    return $this->delegationNoteRequiredForJurisdiction($jurisdiction);
  }

  /**
   * Reads the delegation note policy from the jurisdiction config.
   */
  private function delegationNoteRequiredForJurisdiction(GroupInterface $jurisdiction): bool {
    if (!is_object($this->jurisdictionConfigService)
      || !is_callable([$this->jurisdictionConfigService, 'getSection'])) {
      return FALSE;
    }

    try {
      $section = call_user_func([$this->jurisdictionConfigService, 'getSection'], $jurisdiction, 'delegation');
    }
    catch (\Throwable) {
      return FALSE;
    }

    if (!is_array($section)) {
      return FALSE;
    }

    // Read only the one key this gate owns: future additions to the
    // delegation section must not silently disable an active requirement.
    return ($section['noteRequired'] ?? NULL) === TRUE;
  }

  /**
   * Returns TRUE when the user is an active person doing case work.
   *
   * System administration is not case work: uid 1, service accounts and
   * accounts that only hold the global administrator role never appear as
   * assignable persons and cannot be assigned through this endpoint.
   */
  private function userCanReceiveAssignment(UserInterface $user): bool {
    return $user->isActive() && BeantragtRoles::isCaseProcessingAccount($user);
  }

  /**
   * Loads a user entity by UUID.
   */
  private function loadUserByUuid(string $uuid): ?UserInterface {
    $storage = $this->manager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $user = $storage->load(reset($ids));
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Loads a group entity by UUID.
   */
  private function loadGroupByUuid(string $uuid): ?GroupInterface {
    $storage = $this->manager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Loads an org group entity by UUID.
   */
  private function loadOrgGroupByUuid(string $uuid): ?GroupInterface {
    $storage = $this->manager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $uuid)
      ->condition('type', 'org')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Resolves the application organisation or jurisdiction scope group.
   */
  private function applicationScopeGroup(ApplicationInterface $application): ?GroupInterface {
    foreach (['field_organisation', 'field_group', 'field_jurisdiction'] as $field_name) {
      if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
        continue;
      }
      $group = $application->get($field_name)->entity ?? NULL;
      return $group instanceof GroupInterface ? $group : NULL;
    }

    return NULL;
  }

  /**
   * Resolves the jurisdiction for an application scope group.
   */
  private function jurisdictionForScope(GroupInterface $scope): ?GroupInterface {
    if ($scope->bundle() === 'jur') {
      return $scope;
    }
    if ($scope->bundle() === 'org') {
      return $this->parentJurisdiction($scope);
    }

    return NULL;
  }

  /**
   * Returns TRUE if an account has a membership in the group.
   */
  private function accountIsGroupMember(GroupInterface $group, AccountInterface $account): bool {
    return GroupMembershipEntity::loadSingle($group, $account) instanceof GroupMembershipInterface;
  }

  /**
   * Returns TRUE if an account has an admin membership in the group.
   */
  private function accountHasGroupAdminRole(GroupInterface $group, AccountInterface $account): bool {
    $membership = GroupMembershipEntity::loadSingle($group, $account);
    return $membership instanceof GroupMembershipInterface
      && ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Resolves an organisation group's parent jurisdiction.
   */
  private function parentJurisdiction(GroupInterface $group): ?GroupInterface {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $jurisdiction = $group->get('field_jurisdiction')->entity ?? NULL;
    return $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL;
  }

  /**
   * Builds a stable display label for staff selectors.
   */
  private function userLabel(UserInterface $user): string {
    $label = trim($user->getDisplayName());
    if ($label !== '') {
      return $label;
    }
    $mail = trim((string) $user->getEmail());
    return $mail !== '' ? $mail : 'User ' . $user->id();
  }

  /**
   * Builds a stable display label for team selectors.
   */
  private function groupLabel(GroupInterface $group): string {
    $label = trim((string) $group->label());
    return $label !== '' ? $label : 'Team ' . $group->id();
  }

  /**
   * Normalizes string or integer Drupal changed values.
   */
  private function normalizeChangedTimestamp(string|int $value): int {
    if (is_int($value)) {
      return $value;
    }
    if (ctype_digit($value)) {
      return (int) $value;
    }
    $timestamp = strtotime($value);
    return $timestamp === FALSE ? -1 : $timestamp;
  }

  /**
   * Sanitizes the optional internal delegation note.
   */
  private function sanitizeDelegationNote(string $note): string {
    $note = trim(strip_tags($note));
    if (mb_strlen($note) > 2000) {
      throw new UnprocessableEntityHttpException('Delegation note must not exceed 2000 characters.');
    }

    return $note;
  }

  /**
   * Records an optional assignment note without notifying the applicant.
   */
  private function createAssignmentAuditComment(
    ApplicationInterface $application,
    UserInterface|GroupInterface $target,
    string $note,
  ): void {
    $label = $target instanceof UserInterface ? $this->userLabel($target) : $this->groupLabel($target);
    $body = [
      'beantragt_application:assignment',
      'Assignment',
      'Assigned to: ' . preg_replace('/[\r\n]+/', ' ', $label),
      'Target user UUID: ' . ($target instanceof UserInterface ? $target->uuid() : ''),
      sprintf('Assigned by: %s (uid: %s)',
        preg_replace('/[\r\n]+/', ' ', $this->currentUser->getDisplayName()),
        $this->currentUser->id(),
      ),
      'Note: ' . $note,
    ];
    $comment = $this->manager->getStorage('application_comment')->create([
      'field_application' => (int) $application->id(),
      'field_body' => implode("\n", $body),
      'field_author_role' => 'staff',
      'field_visibility' => 'internal',
      'uid' => (int) $this->currentUser->id(),
      'status' => 1,
    ]);
    $comment->save();
  }

  /**
   * Creates an internal staff comment that records a delegation event.
   */
  private function createDelegationAuditComment(
    ApplicationInterface $application,
    string $source_label,
    string $target_label,
    string $note,
  ): void {
    $application_id = (int) $application->id();
    if ($application_id <= 0) {
      throw new \RuntimeException('Cannot create delegation audit comment for an unsaved application.');
    }

    $body = [
      'beantragt_application:delegation',
      'Delegation',
      'Source organisation: ' . $source_label,
      'Target organisation: ' . $target_label,
      sprintf(
        'Delegated by: %s (uid: %s)',
        $this->currentUser->getDisplayName(),
        $this->currentUser->id(),
      ),
    ];
    if ($note !== '') {
      $body[] = 'Note: ' . $note;
    }

    $comment = $this->manager->getStorage('application_comment')->create([
      'field_application' => $application_id,
      'field_body' => implode("\n", $body),
      'field_author_role' => 'staff',
      'field_visibility' => 'internal',
      'uid' => (int) $this->currentUser->id(),
      'status' => 1,
    ]);
    $comment->save();
  }

  /**
   * Sends a delegation alert to the target organisation mailbox.
   */
  private function sendDelegationNotification(
    ApplicationInterface $application,
    string $source_label,
    string $target_label,
    string $note,
  ): void {
    if (!is_object($this->notificationMailer) || !method_exists($this->notificationMailer, 'sendStaffNotification')) {
      throw new \RuntimeException('The notification mailer service is unavailable.');
    }

    $this->notificationMailer->sendStaffNotification($application, 'delegation_alert', [
      '@source_org' => $this->mailToken($source_label),
      '@target_org' => $this->mailToken($target_label),
      '@note' => $this->mailToken($note === '' ? 'Keine interne Notiz.' : 'Interne Notiz im Vorgang vorhanden.'),
    ]);
  }

  /**
   * Normalizes extra mail tokens to safe single-line values.
   */
  private function mailToken(string $value): string {
    $value = str_replace(["\r", "\n", "\0"], ' ', strip_tags($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim(mb_substr($value, 0, 500));
  }

}
