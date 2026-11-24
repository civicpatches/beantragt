<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Access\ApplicationMembershipBatch;
use Drupal\beantragt_application\ApplicationConsentProjection;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\EntityQuery\ScopedAggregateSelectQuery;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_application\Service\StaffApplicationStatistics;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_application\Service\UnreadMessageCursor;
use Drupal\beantragt_application\WorkflowStatusResolver;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryFactoryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff API for scoped application access and per-person message read state.
 */
final class StaffApplicationApiController extends ControllerBase {

  private const API_VERSION = '1.0';
  private const DEFAULT_LIMIT = 20;
  private const MAX_LIMIT = 500;
  private const MAX_SUBMITTED_DATA_LIMIT = 100;
  private const MAX_OFFSET = 10000;
  private const MESSAGE_CANDIDATE_BATCH_SIZE = 100;

  /**
   * Upper bound of candidates one unread-filter request may scan.
   *
   * Bounds entity loading even when few candidates are readable matches.
   */
  private const MESSAGE_CANDIDATE_SCAN_LIMIT = 2000;
  private const EVENT_SEEN_LIMIT = 120;
  private const EVENT_SEEN_WINDOW = 60;
  private const DATA_CORRECTIONS_PAGE_SIZE = 10;
  private const CRM_PROJECTION_KEYS = [
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
  private const WORKFLOW_ATTRIBUTE_PROJECTION_KEYS = [
    'submissionLocale',
  ];
  private const WORKSPACE_QUERY_ARGS = ['jurisdiction', 'organisation', 'organization', 'org'];
  private const REPORT_QUERY_ARGS = ['form', 'schema', 'formSchema', 'from', 'to'];

  public function __construct(
    private readonly EntityTypeManagerInterface $manager,
    private readonly AccountInterface $account,
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly TenantScopedEntityQuery $tenantScopedEntityQuery,
    private readonly EffectiveWorkflowResolver $effectiveWorkflowResolver,
    private readonly StaffApplicationStatistics $statistics,
    private readonly ApplicationEventReadState $eventReadState,
    private readonly FloodInterface $flood,
    private readonly Connection $database,
    private readonly QueryFactoryInterface $entityQueryFactory,
    private readonly ApplicationCaseScope $applicationCaseScope,
    private readonly ProtectedDataGate $protectedDataGate,
  ) {
    $this->entityTypeManager = $manager;
    $this->currentUser = $account;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_application.data_protection'),
      $container->get('beantragt_application.tenant_scoped_entity_query'),
      $container->get('beantragt_application.effective_workflow_resolver'),
      $container->get('beantragt_application.staff_application_statistics'),
      $container->get('beantragt_application.event_read_state'),
      $container->get('flood'),
      $container->get('database'),
      $container->get('entity.query.sql'),
      $container->get('beantragt_application.application_case_scope'),
      $container->get('beantragt_application.protected_data_gate'),
    );
  }

  /**
   * Lists applications visible to the current staff account.
   */
  public function list(Request $request): JsonResponse {
    $auth_error = $this->authorizeStaff();
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    $query_args = $request->query->all();
    $unread_filter = $query_args['unreadMessages'] ?? 'false';
    if (!in_array($unread_filter, ['true', 'false'], TRUE)) {
      return $this->apiError(
        'unread_messages.invalid',
        'Query parameter unreadMessages must be either true or false.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    $unread_messages = $unread_filter === 'true';
    $pagination = $this->parsePagination($request, $unread_messages);
    if ($pagination instanceof JsonResponse) {
      return $pagination;
    }
    $sort = $this->parseListSort($request);
    if ($sort instanceof JsonResponse) {
      return $sort;
    }
    $after = $query_args['after'] ?? NULL;
    if ($after !== NULL && !$unread_messages) {
      return $this->apiError(
        'pagination.cursor_requires_unread_messages',
        'Query parameter after requires unreadMessages=true.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $scope = $this->resolveRequiredWorkspaceScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }
    if (!$this->accountCanReadScope($scope['jurisdiction'], $scope['organisation'] ?? NULL)) {
      return $this->apiError(
        'workspace.forbidden',
        'The authenticated staff account cannot read this workspace scope.',
        Response::HTTP_FORBIDDEN,
      );
    }

    $confirmation = $request->query->all()['confirmation'] ?? NULL;
    if ($confirmation !== NULL && $confirmation !== 'unconfirmed') {
      return $this->apiError(
        'confirmation.invalid',
        'Query parameter confirmation must be unconfirmed when provided.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    $unconfirmed = $confirmation === 'unconfirmed';
    if ($unconfirmed && $unread_messages) {
      return $this->apiError(
        'unread_messages.confirmation_conflict',
        'unreadMessages is unavailable when confirmation is unconfirmed.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $form_filter_id = $this->resolveOptionalFormFilter($request, $scope);
    if ($form_filter_id instanceof JsonResponse) {
      return $form_filter_id;
    }
    $include_submitted_data = $this->includeSubmittedData($request);
    if ($include_submitted_data instanceof JsonResponse) {
      return $include_submitted_data;
    }
    if ($unconfirmed && $include_submitted_data) {
      return $this->apiError(
        'include.confirmation_conflict',
        'submittedData is unavailable when confirmation is unconfirmed.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    if ($include_submitted_data && $form_filter_id === NULL) {
      return $this->apiError(
        'include.form_required',
        'submittedData may only be included when a form filter is present.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    if ($include_submitted_data && $pagination['limit'] > self::MAX_SUBMITTED_DATA_LIMIT) {
      return $this->apiError(
        'pagination.include_limit',
        'Query parameter limit may not exceed 100 when submittedData is included.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $storage = $this->manager->getStorage('application');
    $unread_query = $unread_messages ? new ScopedAggregateSelectQuery(
      $this->manager->getDefinition('application'),
      'AND',
      $this->database,
      QueryBase::getNamespaces($this->entityQueryFactory),
    ) : NULL;
    $query = $unread_query !== NULL
      ? $this->tenantScopedEntityQuery->scopeQuery($unread_query, 'application', $this->account)
      : $this->tenantScopedEntityQuery->getQuery('application', $this->account, emailConfirmed: !$unconfirmed);
    [$primary, $secondary] = $sort === 'created' ? ['created', 'changed'] : ['changed', 'created'];
    $this->tenantScopedEntityQuery->applyWorkspaceScope(
      $query,
      'application',
      $scope['jurisdiction'],
      $scope['organisation'] ?? NULL,
      includeUnconfirmedApplications: $unconfirmed,
    );
    if ($form_filter_id !== NULL) {
      $query->condition('field_schema', $form_filter_id);
    }
    if ($unconfirmed && !$this->account->hasPermission('administer application entities')) {
      $can_view_published = $this->account->hasPermission('view published application entities');
      $can_view_unpublished = $this->account->hasPermission('view unpublished application entities');
      if (!$can_view_published && !$can_view_unpublished) {
        return $this->listResponse([], $this->paginationMeta($pagination['limit'], $pagination['offset'], FALSE), [], []);
      }
      if ($can_view_published !== $can_view_unpublished) {
        $query->condition('status', $can_view_published);
      }
    }
    $message_counts = [];
    if ($unread_query !== NULL) {
      $cursor_context = json_encode([
        (int) $this->account->id(),
        (int) $scope['jurisdiction']->id(),
        isset($scope['organisation']) ? (int) $scope['organisation']->id() : NULL,
        $form_filter_id,
        $sort,
      ], JSON_THROW_ON_ERROR);
      $position = $after === NULL ? NULL : UnreadMessageCursor::decode($after, $cursor_context);
      if ($after !== NULL && $position === NULL) {
        return $this->apiError(
          'pagination.cursor_invalid',
          'Query parameter after must be a valid unread message cursor for this account and query.',
          Response::HTTP_BAD_REQUEST,
        );
      }
      [$visible_applications, $message_counts, $next_position] = $this->unreadMessagePage(
        $unread_query, $storage, $primary, $secondary, $position, $pagination['limit'],
      );
      $page = ['limit' => $pagination['limit'], 'hasMore' => $next_position !== NULL];
      if ($next_position !== NULL) {
        $page['nextCursor'] = UnreadMessageCursor::encode($next_position, $cursor_context);
      }
    }
    else {
      $ids = $query->sort($primary, 'DESC')->sort($secondary, 'DESC')
        ->range($pagination['offset'], $pagination['limit'] + 1)->execute();
      $loaded_applications = $storage->loadMultiple($ids);
      if ($unconfirmed) {
        $applications = [];
        foreach ($loaded_applications as $application) {
          if ($application instanceof ApplicationInterface) {
            $workspace = $this->unconfirmedApplicationScope($application, $scope);
            if ($workspace !== NULL) {
              $applications[] = $this->normalizeUnconfirmedApplication($application, $workspace);
            }
          }
        }
        $has_more = count($applications) > $pagination['limit'];
        $page = $this->paginationMeta($pagination['limit'], $pagination['offset'], $has_more);
        return $this->listResponse(array_slice($applications, 0, $pagination['limit']), $page, [], []);
      }

      $visible_applications = [];
      foreach ($loaded_applications as $application) {
        if ($application instanceof ApplicationInterface
          && $application->access('view', $this->account)) {
          $visible_applications[] = $application;
        }
      }
      $page = $this->paginationMeta($pagination['limit'], $pagination['offset'], count($visible_applications) > $pagination['limit']);
      $visible_applications = array_slice($visible_applications, 0, $pagination['limit']);
    }
    $event_kinds = $unread_messages
      ? array_diff(ApplicationEventReadState::KINDS, ['message']) : ApplicationEventReadState::KINDS;
    $events = $this->eventReadState->counts($visible_applications, $this->account, $event_kinds);
    foreach ($events as $uuid => $counts) {
      if (isset($message_counts[$uuid])) {
        $counts->message = $message_counts[$uuid];
      }
    }
    $workflow_index = $this->workflowIndex($visible_applications);
    $assignment_index = $this->assignmentIndex($visible_applications);
    $applications = array_map(
      fn(ApplicationInterface $application): array => $this->normalizeApplication(
        $application,
        $scope,
        $include_submitted_data,
        FALSE,
        $workflow_index['allowedNextByApplication'][$application->uuid()] ?? [],
        $assignment_index[$application->uuid()] ?? [],
        $workflow_index['reviewCodeByApplication'][$application->uuid()] ?? NULL,
      ) + ['emailConfirmed' => TRUE],
      $visible_applications,
    );

    return $this->listResponse(
      $applications,
      $page,
      array_values($workflow_index['statuses']),
      $events,
    );
  }

  /**
   * Builds the uncached staff list envelope.
   *
   * @param array<int, array<string, mixed>> $applications
   *   Projected application rows.
   * @param array<string, int|bool|string> $page
   *   Offset or cursor pagination metadata.
   * @param array<int, array<string, mixed>> $statuses
   *   Workflow statuses for confirmed rows.
   * @param array<string, object> $events
   *   Event counts for confirmed rows by application UUID.
   */
  private function listResponse(array $applications, array $page, array $statuses, array $events): JsonResponse {
    return $this->noStoreResponse([
      'data' => $applications,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'staffApplications',
        'count' => count($applications),
        'page' => $page,
        'workflowStatuses' => $statuses,
        'events' => (object) $events,
      ],
    ]);
  }

  /**
   * Scans a bounded number of candidates for readable unread messages.
   *
   * @param \Drupal\beantragt_application\EntityQuery\ScopedAggregateSelectQuery $query
   *   Query constrained to the account, workspace and optional form.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   Application storage.
   * @param string $primary
   *   The primary descending sort field.
   * @param string $secondary
   *   The secondary descending sort field.
   * @param array{int, int, int}|null $position
   *   Exclusive scan position in the chosen sort order.
   * @param int $limit
   *   Maximum number of matches to return.
   *
   * @return array{list<\Drupal\beantragt_application\Entity\ApplicationInterface>, array<string, int>, array{int, int, int}|null}
   *   Matches, their message counts, and the position for continuation.
   */
  private function unreadMessagePage(ScopedAggregateSelectQuery $query, EntityStorageInterface $storage, string $primary, string $secondary, ?array $position, int $limit): array {
    $candidates = $this->eventReadState->messageCandidateQuery($this->account);
    if ($candidates === NULL) {
      return [[], [], NULL];
    }
    $query->addTag('beantragt_application_message_candidates')
      ->addMetaData('beantragt_application_message_candidates', $candidates);
    $select = $query->compileSelect();
    // The cursor is derived from the same query as the IDs. An update or
    // deletion between selection and entity loading cannot move its boundary.
    // Legacy NULL timestamps sort below every value of the timestamp columns.
    $primary_sql = 'COALESCE(' . $query->sqlColumn($primary) . ', -2147483649)';
    $secondary_sql = 'COALESCE(' . $query->sqlColumn($secondary) . ', -2147483649)';
    $id_sql = $query->sqlColumn('id');
    $select->addField('base_table', 'id');
    $select->addExpression($primary_sql, 'cursor_primary');
    $select->addExpression($secondary_sql, 'cursor_secondary');
    $select->orderBy('cursor_primary', 'DESC')
      ->orderBy('cursor_secondary', 'DESC')
      ->orderBy($id_sql, 'DESC');

    $matches = [];
    $message_counts = [];
    $scanned = 0;
    while ($scanned < self::MESSAGE_CANDIDATE_SCAN_LIMIT) {
      $batch_size = min(self::MESSAGE_CANDIDATE_BATCH_SIZE, self::MESSAGE_CANDIDATE_SCAN_LIMIT - $scanned);
      $rows = $this->unreadMessageScanQuery($select, $primary_sql, $secondary_sql, $id_sql, $position, $batch_size)
        ->execute()->fetchAllAssoc('id', FetchAs::Associative);
      if ($rows === []) {
        return [$matches, $message_counts, NULL];
      }
      $ids = array_keys($rows);
      $batch = array_filter($storage->loadMultiple($ids),
        static fn(EntityInterface $application): bool => $application instanceof ApplicationInterface);
      // The snapshot applies application view and section access and compares
      // exact receipts and legacy positions before a row can fill the page.
      $events = $this->eventReadState->counts($batch, $this->account, ['message']);
      $remaining = count($ids);
      foreach ($rows as $id => $row) {
        $remaining--;
        $position = [(int) $row['cursor_primary'], (int) $row['cursor_secondary'], (int) $id];
        $application = $batch[$id] ?? NULL;
        if ($application === NULL) {
          continue;
        }
        $uuid = $application->uuid();
        if (!$application->isEmailConfirmed() || !isset($events[$uuid]->message)) {
          continue;
        }
        $matches[] = $application;
        $message_counts[$uuid] = $events[$uuid]->message;
        if (count($matches) === $limit) {
          break;
        }
      }
      // Release each loaded batch, including the batch that fills the page.
      $storage->resetCache($ids);
      $scanned += count($ids);
      if ($remaining > 0) {
        return [$matches, $message_counts, $position];
      }
      if (count($ids) < $batch_size) {
        return [$matches, $message_counts, NULL];
      }
      if (count($matches) === $limit) {
        break;
      }
    }
    // Look for one more candidate without loading it or spending scan budget.
    $has_more = $this->unreadMessageScanQuery($select, $primary_sql, $secondary_sql, $id_sql, $position, 1)
      ->execute()->fetchField() !== FALSE;
    return [$matches, $message_counts, $has_more ? $position : NULL];
  }

  /**
   * Selects the next candidates after a descending (time, time, ID) tuple.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $select
   *   Scoped unread candidate query.
   * @param string $primary
   *   SQL expression for the primary descending sort key.
   * @param string $secondary
   *   SQL expression for the secondary descending sort key.
   * @param string $id_column
   *   SQL column for the descending ID tie-breaker.
   * @param array{int, int, int}|null $position
   *   Exclusive scan position, or NULL for the beginning.
   * @param int $size
   *   Maximum number of candidate IDs.
   */
  private function unreadMessageScanQuery(SelectInterface $select, string $primary, string $secondary, string $id_column, ?array $position, int $size): SelectInterface {
    $select = clone $select;
    if ($position !== NULL) {
      [$first, $second, $id] = $position;
      $select->where(
        "$primary < :cursor_primary_before
          OR ($primary = :cursor_primary_equal AND (
            $secondary < :cursor_secondary_before
            OR ($secondary = :cursor_secondary_equal AND $id_column < :cursor_id)
          ))",
        [
          ':cursor_primary_before' => $first,
          ':cursor_primary_equal' => $first,
          ':cursor_secondary_before' => $second,
          ':cursor_secondary_equal' => $second,
          ':cursor_id' => $id,
        ],
      );
    }
    return $select->range(0, $size);
  }

  /**
   * Returns one application visible to the current staff account.
   */
  public function detail(string $application_uuid, Request $request): JsonResponse {
    $auth_error = $this->authorizeStaff();
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    $scope = $this->resolveRequiredWorkspaceScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }
    if (!$this->accountCanReadScope($scope['jurisdiction'], $scope['organisation'] ?? NULL)) {
      return $this->apiError(
        'workspace.forbidden',
        'The authenticated staff account cannot read this workspace scope.',
        Response::HTTP_FORBIDDEN,
      );
    }

    $storage = $this->manager->getStorage('application');
    $query = $this->tenantScopedEntityQuery
      ->getQuery('application', $this->account)
      ->condition('uuid', $application_uuid)
      ->range(0, 1);
    $this->tenantScopedEntityQuery->applyWorkspaceScope(
      $query,
      'application',
      $scope['jurisdiction'],
      $scope['organisation'] ?? NULL,
    );
    $ids = $query->execute();
    if ($ids === []) {
      return $this->applicationNotFound();
    }

    $application = $storage->load(reset($ids));
    if (!$application instanceof ApplicationInterface
      || !$application->access('view', $this->account)) {
      return $this->applicationNotFound();
    }

    if ($request->query->has('markMessagesSeen') || $request->query->has('markEventsSeen')) {
      return $this->apiError('events.read_only', 'Use the section seen POST to acknowledge an event kind.', Response::HTTP_BAD_REQUEST);
    }

    $redacted = $this->submittedDataIsRedacted($application);
    $assignment_index = $this->assignmentIndex([$application]);
    return $this->noStoreResponse([
      'data' => $this->normalizeApplication(
        $application,
        $scope,
        TRUE,
        TRUE,
        [],
        $assignment_index[$application->uuid()] ?? [],
        $this->effectiveWorkflowResolver->reviewCode($application),
      ),
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'staffApplication',
        'protectedDataRedacted' => $redacted,
        'events' => $this->eventReadState->detail($application, $this->account),
      ],
    ]);
  }

  /**
   * Internal event counters and, on request, the scoped correction history.
   */
  public function events(string $application_uuid, Request $request): JsonResponse {
    $auth_error = $this->authorizeStaff();
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }
    $scope = $this->resolveRequiredWorkspaceScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }
    if (!$this->accountCanReadScope($scope['jurisdiction'], $scope['organisation'] ?? NULL)) {
      return $this->apiError('workspace.forbidden', 'The authenticated staff account cannot read this workspace scope.', Response::HTTP_FORBIDDEN);
    }
    $query = $this->tenantScopedEntityQuery->getQuery('application', $this->account)
      ->condition('uuid', $application_uuid)->range(0, 1);
    $this->tenantScopedEntityQuery->applyWorkspaceScope($query, 'application', $scope['jurisdiction'], $scope['organisation'] ?? NULL);
    $ids = $query->execute();
    $application = $ids === [] ? NULL : $this->manager->getStorage('application')->load(reset($ids));
    if (!$application instanceof ApplicationInterface || !$application->access('view', $this->account)) {
      return $this->applicationNotFound();
    }
    $meta = [
      'apiVersion' => self::API_VERSION,
      'resource' => 'staffApplicationEvents',
      'events' => $this->eventReadState->detail($application, $this->account),
    ];
    if ($request->query->get('includeDataCorrections') === '1') {
      $before = $request->query->all()['dataCorrectionsBefore'] ?? NULL;
      if ($before !== NULL && (!is_string($before) || !ctype_digit($before) || (int) $before <= 0)) {
        return $this->apiError('application.data_edit_history_cursor_invalid', 'Invalid correction history cursor.', Response::HTTP_BAD_REQUEST);
      }
      [$meta['dataCorrections'], $meta['dataCorrectionsNextBefore']] = $this->dataCorrections($application, $before === NULL ? NULL : (int) $before);
    }
    return $this->noStoreResponse(['data' => ['id' => $application_uuid], 'meta' => $meta]);
  }

  /**
   * Projects corrections from the saved revision and its immediate predecessor.
   *
   * @return array{0: array, 1: int|null}
   *   A page of corrections and the exclusive revision cursor, if any.
   */
  private function dataCorrections(ApplicationInterface $application, ?int $before_revision_id): array {
    $storage = $this->manager->getStorage('application');
    if (!$storage instanceof RevisionableStorageInterface
      || !$application->access('view all revisions', $this->account)
      || !$application->get('field_data')->access('view', $this->account)
      || (json_decode($application->getData() ?? '', TRUE)['_anonymized'] ?? FALSE) === TRUE) {
      return [[], NULL];
    }
    // Select only likely correction revisions before loading submitted data.
    // The revision log decoder below verifies the actual event.
    $query = $this->database->select('application_revision', 'r')->fields('r', ['revision_id'])
      ->condition('r.id', $application->id())
      ->condition('r.revision_id', (int) $application->getRevisionId(), '<=')
      ->condition('r.revision_log_message', '%' . $this->database->escapeLike('"data_edited"') . '%', 'LIKE')
      ->orderBy('r.revision_id', 'DESC')->range(0, self::DATA_CORRECTIONS_PAGE_SIZE + 1);
    $query->addExpression('(SELECT MAX(p.revision_id) FROM {application_revision} p WHERE p.id = r.id AND p.revision_id < r.revision_id)', 'previous_revision_id');
    if ($before_revision_id !== NULL) {
      $query->condition('r.revision_id', $before_revision_id, '<');
    }
    $rows = $query->execute()->fetchAll();
    $next_before = count($rows) > self::DATA_CORRECTIONS_PAGE_SIZE ? (int) $rows[self::DATA_CORRECTIONS_PAGE_SIZE - 1]->revision_id : NULL;
    $corrections = [];
    $revealed_protected_data = FALSE;
    foreach (array_slice($rows, 0, self::DATA_CORRECTIONS_PAGE_SIZE) as $row) {
      $revision = $storage->loadRevision($row->revision_id);
      if (!$revision instanceof ApplicationInterface) {
        continue;
      }
      $log = RevisionLog::decode((string) $revision->getRevisionLogMessage());
      if (!in_array('data_edited', $log['events'] ?? [], TRUE)) {
        continue;
      }
      // A moved case can contain revisions created before the move.
      $before = $row->previous_revision_id === NULL ? NULL : $storage->loadRevision($row->previous_revision_id);
      if (!$before instanceof ApplicationInterface
        || !$revision->access('view all revisions', $this->account)
        || !$before->access('view all revisions', $this->account)
        || !$revision->get('field_data')->access('view', $this->account)
        || !$before->get('field_data')->access('view', $this->account)) {
        continue;
      }
      $requires_reveal = $this->dataProtection->applicationRequiresProtectedReveal($before)
        || $this->dataProtection->applicationRequiresProtectedReveal($revision);
      if ($requires_reveal && (!$this->canRevealProtectedData($application)
        || !$this->canRevealProtectedData($before)
        || !$this->canRevealProtectedData($revision))) {
        continue;
      }
      $old_data = $this->dataProtection->revealDataJson($before->getData());
      $new_data = $this->dataProtection->revealDataJson($revision->getData());
      $changes = [];
      foreach (array_keys($old_data + $new_data) as $field) {
        $before_present = array_key_exists($field, $old_data);
        $after_present = array_key_exists($field, $new_data);
        $old_value = $old_data[$field] ?? NULL;
        if ($before_present !== $after_present || $old_value !== ($new_data[$field] ?? NULL)) {
          $changes[] = ['field' => (string) $field, 'oldValue' => $old_value, 'hadOldValue' => $before_present];
        }
      }
      $author = $revision->getRevisionUser();
      $author_details = $author?->access('view label', $this->account)
        ? ['id' => (int) $revision->getRevisionUserId(), 'label' => $author->getDisplayName()]
        : ['label' => NULL];
      $corrections[] = [
        'kind' => 'data_edited',
        'revisionId' => (int) $row->revision_id,
        'timestamp' => $this->revisionTimestamp($revision),
        'author' => $author_details,
        'reason' => ($log['message'] ?? '') !== '' ? $log['message'] : NULL,
        'changes' => $changes,
      ];
      $revealed_protected_data = $revealed_protected_data || $requires_reveal;
    }
    if ($revealed_protected_data) {
      $this->dataProtection->logProtectedReveal($application, $this->account, 'admin_data_history');
    }
    return [$corrections, $next_before];
  }

  /**
   * Acknowledges one reached section, after the same scoped detail lookup.
   */
  public function markEventSeen(string $application_uuid, string $kind, Request $request): JsonResponse {
    if (!in_array($kind, ApplicationEventReadState::SINCE_SEEN, TRUE)) {
      return $this->apiError('events.invalid_kind', 'Only since-seen event kinds can be acknowledged.', Response::HTTP_BAD_REQUEST);
    }
    if (strlen((string) $request->getContent()) > 700000) {
      return $this->apiError('events.invalid_body', 'Event token body is too large.', Response::HTTP_BAD_REQUEST);
    }
    $body = json_decode((string) $request->getContent(), TRUE);
    $tokens = is_array($body) ? ($body['tokens'] ?? NULL) : NULL;
    if (!is_array($body) || array_keys($body) !== ['tokens'] || !is_array($tokens)
      || !array_is_list($tokens) || count($tokens) > 10000
      || array_filter($tokens, static fn(mixed $token): bool => !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token))) {
      return $this->apiError('events.invalid_body', 'Expected at most 10000 event tokens.', Response::HTTP_BAD_REQUEST);
    }
    // Authorize without normalizing, decrypting or auditing case contents.
    $auth_error = $this->authorizeStaff();
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }
    $identifier = (string) $this->account->id();
    if (!$this->flood->isAllowed('beantragt_application.event_seen', self::EVENT_SEEN_LIMIT, self::EVENT_SEEN_WINDOW, $identifier)) {
      $response = $this->apiError('events.rate_limited', 'Too many section acknowledgements.', Response::HTTP_TOO_MANY_REQUESTS);
      $response->headers->set('Retry-After', (string) self::EVENT_SEEN_WINDOW);
      return $response;
    }
    $this->flood->register('beantragt_application.event_seen', self::EVENT_SEEN_WINDOW, $identifier);
    $scope = $this->resolveRequiredWorkspaceScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }
    if (!$this->accountCanReadScope($scope['jurisdiction'], $scope['organisation'] ?? NULL)) {
      return $this->apiError('workspace.forbidden', 'The authenticated staff account cannot read this workspace scope.', Response::HTTP_FORBIDDEN);
    }
    $query = $this->tenantScopedEntityQuery->getQuery('application', $this->account)
      ->condition('uuid', $application_uuid)->range(0, 1);
    $this->tenantScopedEntityQuery->applyWorkspaceScope($query, 'application', $scope['jurisdiction'], $scope['organisation'] ?? NULL);
    $ids = $query->execute();
    $application = $ids === [] ? NULL : $this->manager->getStorage('application')->load(reset($ids));
    if (!$application instanceof ApplicationInterface || !$application->access('view', $this->account)) {
      return $this->applicationNotFound();
    }
    $events = $this->eventReadState->markSeen($application, $this->account, $kind, $tokens);
    if ($events === NULL) {
      return $this->apiError('events.forbidden', 'The event section is not accessible.', Response::HTTP_FORBIDDEN);
    }
    return $this->noStoreResponse([
      'data' => ['id' => $application_uuid, 'kind' => $kind],
      'meta' => ['apiVersion' => self::API_VERSION, 'resource' => 'staffApplicationEvents', 'events' => $events],
    ]);
  }

  /**
   * Counts the applications visible in the workspace per form and form group.
   */
  public function counts(Request $request): JsonResponse {
    $scope = $this->authorizeAggregateScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }

    $counts = $this->statistics->applicationCounts(
      $this->account,
      $scope['jurisdiction'],
      $scope['organisation'] ?? NULL,
    );
    // Maps keyed by UUID stay JSON objects; PHP encodes an empty array as [].
    foreach (['applicationsByForm', 'applicationsByFormGroup', 'formGroupsByForm'] as $map) {
      $counts[$map] = (object) $counts[$map];
    }

    $cacheability = $this->aggregateCacheability($scope, self::WORKSPACE_QUERY_ARGS)
      ->addCacheTags(['form_schema_list', 'form_schema_group_list']);
    return $this->cacheableResponse([
      'data' => $counts,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'staffApplicationCounts',
        'scope' => $this->scopeMeta($scope),
      ],
    ], $cacheability);
  }

  /**
   * Returns report figures for the applications visible in the workspace.
   */
  public function report(Request $request): JsonResponse {
    $scope = $this->authorizeAggregateScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }

    $form_filter_id = $this->resolveOptionalFormFilter($request, $scope);
    if ($form_filter_id instanceof JsonResponse) {
      return $form_filter_id;
    }
    $range = $this->parseReportRange($request);
    if ($range instanceof JsonResponse) {
      return $range;
    }

    $report = $this->statistics->report(
      $this->account,
      $scope['jurisdiction'],
      $scope['organisation'] ?? NULL,
      [
        'formId' => $form_filter_id,
        'from' => $range['from'],
        'to' => $range['to'],
      ],
    );

    $cacheability = $this->aggregateCacheability($scope, [...self::WORKSPACE_QUERY_ARGS, ...self::REPORT_QUERY_ARGS])
      ->addCacheTags(['form_schema_list', 'workflow_status_list'])
      // The deadline figures depend on the date, not on visibility.
      ->setCacheMaxAge($this->statistics->secondsUntilReportDayEnds());
    return $this->cacheableResponse([
      'data' => $report,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'staffApplicationReport',
        'generatedAt' => $this->formatUtcTimestamp((int) $request->server->get('REQUEST_TIME', time())),
        'scope' => $this->scopeMeta($scope),
        'filters' => [
          'form' => $form_filter_id !== NULL ? $this->normalizeUuid($this->formFilterValue($request)) : NULL,
          'from' => $range['from'] !== NULL ? $this->formatUtcTimestamp($range['from']) : NULL,
          'to' => $range['to'] !== NULL ? $this->formatUtcTimestamp($range['to']) : NULL,
        ],
        'timeZone' => StaffApplicationStatistics::REPORT_TIME_ZONE,
        'dayTimeZone' => 'UTC',
        'truncated' => FALSE,
      ],
    ], $cacheability);
  }

  /**
   * Runs the staff list's profile and workspace checks for an aggregate.
   *
   * @return array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Resolved and readable workspace scope, or an error response.
   */
  private function authorizeAggregateScope(Request $request): array|JsonResponse {
    $auth_error = $this->authorizeStaff();
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }
    $scope = $this->resolveRequiredWorkspaceScope($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }
    if (!$this->accountCanReadScope($scope['jurisdiction'], $scope['organisation'] ?? NULL)) {
      return $this->apiError(
        'workspace.forbidden',
        'The authenticated staff account cannot read this workspace scope.',
        Response::HTTP_FORBIDDEN,
      );
    }
    return $scope;
  }

  /**
   * Builds the cacheability shared by the aggregate endpoints.
   *
   * Visibility depends on the account, its permissions, its memberships, the
   * groups and the applications. All of them invalidate by tag; nothing here
   * expires by time.
   *
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $scope
   *   Resolved workspace scope.
   * @param string[] $queryArgs
   *   Query parameters the endpoint reads.
   */
  private function aggregateCacheability(array $scope, array $queryArgs): CacheableMetadata {
    $contexts = ['user', 'user.permissions'];
    foreach ($queryArgs as $query_arg) {
      $contexts[] = 'url.query_args:' . $query_arg;
    }
    $cacheability = (new CacheableMetadata())
      ->setCacheContexts($contexts)
      ->setCacheTags([
        'application_list',
        'group_list',
        ...ApplicationCaseScope::cacheTags($this->account),
      ])
      ->addCacheableDependency($scope['jurisdiction']);
    if (isset($scope['organisation'])) {
      $cacheability->addCacheableDependency($scope['organisation']);
    }
    return $cacheability;
  }

  /**
   * Projects the resolved workspace scope into response metadata.
   *
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $scope
   *   Resolved workspace scope.
   *
   * @return array{jurisdiction: string|null, organisation: string|null}
   *   Workspace slugs.
   */
  private function scopeMeta(array $scope): array {
    return [
      'jurisdiction' => $this->groupSlug($scope['jurisdiction']),
      'organisation' => isset($scope['organisation']) ? $this->groupSlug($scope['organisation']) : NULL,
    ];
  }

  /**
   * Returns the raw form filter value under any of its accepted names.
   */
  private function formFilterValue(Request $request): mixed {
    return $request->query->get('form')
      ?? $request->query->get('schema')
      ?? $request->query->get('formSchema');
  }

  /**
   * Parses the optional report creation range.
   *
   * A date (YYYY-MM-DD) is a whole day in the report time zone; an ISO 8601
   * date-time with offset is taken as given. Both bounds are inclusive.
   *
   * @return array{from: int|null, to: int|null}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Unix timestamps, or an error response.
   */
  private function parseReportRange(Request $request): array|JsonResponse {
    $range = ['from' => NULL, 'to' => NULL];
    foreach (['from' => FALSE, 'to' => TRUE] as $name => $end_of_day) {
      $raw = $request->query->get($name);
      if ($raw === NULL || $raw === '') {
        continue;
      }
      $timestamp = $this->parseReportBoundary((string) $raw, $end_of_day);
      if ($timestamp === NULL) {
        return $this->apiError(
          'report.range_invalid',
          sprintf('Query parameter %s must be a date (YYYY-MM-DD) or an ISO 8601 date-time with offset.', $name),
          Response::HTTP_BAD_REQUEST,
        );
      }
      $range[$name] = $timestamp;
    }
    if ($range['from'] !== NULL && $range['to'] !== NULL && $range['from'] > $range['to']) {
      return $this->apiError(
        'report.range_invalid',
        'Query parameter from must not be after to.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    return $range;
  }

  /**
   * Parses one report range boundary into a Unix timestamp.
   */
  private function parseReportBoundary(string $raw, bool $endOfDay): ?int {
    $raw = trim($raw);
    $time_zone = new \DateTimeZone(StaffApplicationStatistics::REPORT_TIME_ZONE);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $time_zone);
      if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $raw) {
        return NULL;
      }
      return $endOfDay
        ? $date->modify('+1 day')->getTimestamp() - 1
        : $date->getTimestamp();
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $raw) !== 1) {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($raw))->getTimestamp();
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Formats a Unix timestamp as an ISO-8601 UTC string.
   */
  private function formatUtcTimestamp(int $timestamp): string {
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
  }

  /**
   * Creates a private response that the dynamic page cache may keep.
   *
   * @param array<string, mixed> $data
   *   Response payload.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Cacheability of the payload.
   */
  private function cacheableResponse(array $data, CacheableMetadata $cacheability): CacheableJsonResponse {
    $response = new CacheableJsonResponse($data);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Authorizes the staff API read profile.
   */
  private function authorizeStaff(): ?JsonResponse {
    if ((int) $this->account->id() <= 0) {
      return $this->apiError('auth.required', 'Authentication required.', Response::HTTP_UNAUTHORIZED);
    }
    if ($this->isServiceAccount($this->account) || !$this->isStaffAccount($this->account)) {
      return $this->apiError(
        'auth.unsupported_profile',
        'This endpoint requires a staff account.',
        Response::HTTP_FORBIDDEN,
      );
    }
    if (!$this->accountCanViewApplications($this->account)) {
      return $this->apiError(
        'application.forbidden',
        'The authenticated staff account is not allowed to read applications.',
        Response::HTTP_FORBIDDEN,
      );
    }
    return NULL;
  }

  /**
   * Resolves the required staff workspace scope.
   *
   * @return array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Resolved workspace scope, or an error response.
   */
  private function resolveRequiredWorkspaceScope(Request $request): array|JsonResponse {
    if (!$request->query->has('jurisdiction')) {
      return $this->apiError(
        'workspace.required',
        'Query parameter jurisdiction is required.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $jurisdiction_slug = $this->normalizeSlug($request->query->get('jurisdiction'));
    if ($jurisdiction_slug === NULL) {
      return $this->workspaceNotFound();
    }

    $jurisdiction = $this->loadGroupBySlug('jur', $jurisdiction_slug);
    if (!$jurisdiction instanceof GroupInterface) {
      return $this->workspaceNotFound();
    }

    $scope = ['jurisdiction' => $jurisdiction];
    $organisation_slug = $this->normalizeSlug(
      $request->query->get('organisation')
      ?? $request->query->get('organization')
      ?? $request->query->get('org')
    );
    if ($request->query->has('organisation') || $request->query->has('organization') || $request->query->has('org')) {
      if ($organisation_slug === NULL) {
        return $this->workspaceNotFound();
      }
      $organisation = $this->loadOrganisationBySlug($organisation_slug, (int) $jurisdiction->id());
      if (!$organisation instanceof GroupInterface) {
        return $this->workspaceNotFound();
      }
      $scope['organisation'] = $organisation;
    }

    return $scope;
  }

  /**
   * Returns TRUE when the account can read the requested workspace scope.
   */
  private function accountCanReadScope(GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): bool {
    if ($this->account->hasPermission('administer application entities')
      || $this->account->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }

    if ($organisation instanceof GroupInterface) {
      return $this->accountIsGroupMember($organisation)
        || $this->accountHasGroupAdminRole($jurisdiction);
    }

    return $this->accountHasGroupAdminRole($jurisdiction);
  }

  /**
   * Returns TRUE when the account has any membership in a group.
   */
  private function accountIsGroupMember(GroupInterface $group): bool {
    return $this->membership($group) !== NULL;
  }

  /**
   * Returns TRUE when the account has an explicit Group admin role.
   */
  private function accountHasGroupAdminRole(GroupInterface $group): bool {
    return ApplicationCaseScope::hasAdminGroupRole($this->membership($group)?->getRoles(FALSE) ?? []);
  }

  /**
   * Loads the current user's membership of one workspace.
   */
  private function membership(GroupInterface $group): ?GroupMembershipInterface {
    return ApplicationMembershipBatch::loadMembership($this->manager, (int) $group->id(), $this->account);
  }

  /**
   * Checks the row's staff access for the unconfirmed list projection.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application returned by the account- and workspace-scoped query.
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $scope
   *   Authorized workspace scope.
   *
   * @return array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface}|null
   *   Effective workspace for the row, or NULL when access is denied.
   */
  private function unconfirmedApplicationScope(ApplicationInterface $application, array $scope): ?array {
    $permission = $application->isPublished()
      ? 'view published application entities'
      : 'view unpublished application entities';
    if ($application->isEmailConfirmed()
      || (!$this->account->hasPermission($permission) && !$this->account->hasPermission('administer application entities'))) {
      return NULL;
    }

    $group = ApplicationCaseScope::effectiveGroup($application, $this->manager);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }
    $jurisdiction = $scope['jurisdiction'];
    $jurisdiction_id = (int) $jurisdiction->id();
    if ($group->bundle() === 'jur') {
      return !isset($scope['organisation']) && (int) $group->id() === $jurisdiction_id ? $scope : NULL;
    }

    if ((isset($scope['organisation']) && (int) $scope['organisation']->id() !== (int) $group->id())
      || (int) $group->get('field_jurisdiction')->target_id !== $jurisdiction_id
      || !$this->accountCanReadScope($jurisdiction, $group)) {
      return NULL;
    }

    if (!$this->accountIsGroupMember($group)
      && !ApplicationCaseScope::matchesRecordedJurisdiction($application, $jurisdiction_id)) {
      return NULL;
    }

    return ['jurisdiction' => $jurisdiction, 'organisation' => $group];
  }

  /**
   * Projects the fields needed for staff to identify an unconfirmed request.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Unconfirmed application accessible through the list filter.
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $workspace
   *   Effective workspace validated for this application.
   *
   * @return array<string, mixed>
   *   Unconfirmed application projection.
   */
  private function normalizeUnconfirmedApplication(ApplicationInterface $application, array $workspace): array {
    $identity_protected = $this->dataProtection->applicationHasProtectedIdentityLinkage($application);
    $crm_data = $identity_protected ? [] : $this->crmDataProjection($application);
    $name = $crm_data['person.full_name'] ?? trim(($crm_data['person.first_name'] ?? '') . ' ' . ($crm_data['person.last_name'] ?? ''));
    // The notification recipient can also be encrypted in the answers.
    // This projection only reads the dedicated cleartext recipient field.
    $email = $identity_protected ? NULL : $application->getApplicantEmail();

    return [
      'id' => $application->uuid(),
      'referenceNumber' => $this->referenceNumber($application),
      'form' => $this->formProjection($application),
      'workspace' => array_map($this->groupProjection(...), $workspace),
      'submittedAt' => $this->formatTimestamp($application->getCreatedTime()),
      'applicantName' => $name !== '' ? $name : NULL,
      'confirmationEmail' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL,
      'emailConfirmed' => FALSE,
    ];
  }

  /**
   * Normalizes an application into the external staff v1 shape.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application entity.
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $scope
   *   Resolved workspace scope.
   * @param bool $include_data
   *   Whether to include submitted data.
   * @param bool $include_detail
   *   Whether to include detail-only timeline and workflow metadata.
   * @param string[] $allowed_next_ids
   *   Effective quick-transition target UUIDs for this application.
   * @param array{assigneeId?: string, teamId?: string} $assignment
   *   Permission-checked assignment UUIDs for this application.
   * @param string|null $review_code
   *   Review code of the current status, NULL when none applies.
   *
   * @return array<string, mixed>
   *   External application projection.
   */
  private function normalizeApplication(
    ApplicationInterface $application,
    array $scope,
    bool $include_data = FALSE,
    bool $include_detail = FALSE,
    array $allowed_next_ids = [],
    array $assignment = [],
    ?string $review_code = NULL,
  ): array {
    $can_change_status = $this->applicationCaseScope->canChangeStatus($application, $this->account);
    $status_info = $this->workflowStatusInfo($application, $can_change_status ? $allowed_next_ids : []);
    $status = [
      'code' => $status_info['status'],
      'label' => $status_info['label'],
      'color' => $status_info['color'],
      'icon' => $status_info['icon'],
      'weight' => $status_info['weight'],
      'semanticFlags' => $status_info['semanticFlags'],
      'allowedNextIds' => $status_info['allowedNextIds'],
    ];
    if ($status_info['id'] !== '') {
      $status['id'] = $status_info['id'];
      // Only for a status the account may see; the status has no outgoing
      // transition then, so allowedNextIds is empty.
      if ($review_code !== NULL) {
        $status['reviewCode'] = $review_code;
      }
    }
    $identity_linkage_redacted = $this->identityLinkageIsRedacted($application);
    $crm_data = $identity_linkage_redacted ? [] : $this->crmDataProjection($application);
    $applicant = $identity_linkage_redacted ? [] : $this->applicantProjection($application, $crm_data);

    $data = [
      'type' => 'staff_application',
      'id' => $application->uuid(),
      'referenceNumber' => $this->referenceNumber($application),
      'form' => $this->formProjection($application),
      'workspace' => $this->workspaceProjection($application, $scope),
      'status' => $status,
      'assignmentVisible' => $this->accountCanViewAssignments(),
      'canChangeStatus' => $can_change_status,
      'canDelete' => $this->applicationCaseScope->canDelete($application, $this->account),
      'submittedAt' => $this->formatTimestamp($application->getCreatedTime()),
      'updatedAt' => $this->formatTimestamp($application->getChangedTime()),
    ];
    if ($assignment !== []) {
      $data['assignment'] = $assignment;
    }
    $due_date = $this->fieldValue($application, 'field_due_date');
    if ($due_date !== '') {
      $data['dueDate'] = $due_date;
    }
    if ($applicant !== []) {
      $data['applicant'] = $applicant;
    }
    if ($crm_data !== []) {
      $data['crmData'] = $crm_data;
    }

    if ($include_data) {
      $requires_reveal = $this->dataProtection->applicationRequiresProtectedReveal($application);
      if ($requires_reveal && !$this->canRevealProtectedData($application)) {
        $data['submittedData'] = $this->dataProtection->redactApplicationData($application);
      }
      else {
        $data['submittedData'] = $this->dataProtection->revealDataJson($application->getData());
        if ($requires_reveal) {
          $this->dataProtection->logProtectedReveal($application, $this->account, 'staff_api');
        }
      }
    }
    if ($include_detail) {
      $data += ApplicationConsentProjection::project($application);
      $data['timeline'] = $this->buildTimeline($application);
      $workflow_attributes = $this->workflowAttributesProjection($application);
      if ($workflow_attributes !== []) {
        $data['workflowAttributes'] = $workflow_attributes;
      }
    }

    return $data;
  }

  /**
   * Returns the external reference number for a staff application.
   */
  private function referenceNumber(ApplicationInterface $application): string {
    return $application->getReferenceNumber() ?: strtoupper(substr($application->uuid(), 0, 8));
  }

  /**
   * Projects the form's external reference for either staff list projection.
   *
   * @return array{title: string, id?: string}
   *   Form label and, when available, UUID.
   */
  private function formProjection(ApplicationInterface $application): array {
    $schema = $application->get('field_schema')->entity ?? NULL;
    $form = [
      'title' => $schema instanceof EntityInterface ? (string) ($schema->label() ?? 'Antrag') : 'Antrag',
    ];
    if ($schema instanceof EntityInterface && is_string($schema->uuid()) && $schema->uuid() !== '') {
      $form['id'] = $schema->uuid();
    }
    return $form;
  }

  /**
   * Projects the applicant contact channel exposed through staff scope.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application entity.
   * @param array<string, string> $crm_data
   *   Already-projected CRM data.
   *
   * @return array<string, string>
   *   Applicant projection.
   */
  private function applicantProjection(ApplicationInterface $application, array $crm_data): array {
    $email = strtolower($this->fieldValue($application, 'field_applicant_email'));
    if ($email === '') {
      $email = strtolower($crm_data['person.email'] ?? '');
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL)
      ? ['email' => $email]
      : [];
  }

  /**
   * Projects allowlisted application CRM values for scoped staff views.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application entity.
   *
   * @return array<string, string>
   *   CRM data projection.
   */
  private function crmDataProjection(ApplicationInterface $application): array {
    $raw = $this->jsonObjectField($application, 'field_crm_data');
    $projection = [];
    foreach (self::CRM_PROJECTION_KEYS as $key) {
      $value = $raw[$key] ?? NULL;
      if (is_scalar($value)) {
        $normalized = trim((string) $value);
        if ($normalized !== '') {
          $projection[$key] = $normalized;
        }
      }
    }
    return $projection;
  }

  /**
   * Reads a JSON object from a string_long field.
   *
   * @return array<string, mixed>
   *   Decoded object, or an empty array for invalid/missing values.
   */
  private function jsonObjectField(ApplicationInterface $application, string $field_name): array {
    if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
      return [];
    }

    $decoded = json_decode((string) $application->get($field_name)->value, TRUE);
    return is_array($decoded) && array_is_list($decoded) === FALSE ? $decoded : [];
  }

  /**
   * Projects workflow metadata that is safe for normal scoped staff views.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application entity.
   *
   * @return array<string, string>
   *   Workflow attribute projection.
   */
  private function workflowAttributesProjection(ApplicationInterface $application): array {
    $raw = $this->jsonObjectField($application, 'field_workflow_attributes');
    $projection = [];
    foreach (self::WORKFLOW_ATTRIBUTE_PROJECTION_KEYS as $key) {
      $value = $raw[$key] ?? NULL;
      if (is_scalar($value)) {
        $normalized = trim((string) $value);
        if ($normalized !== '') {
          $projection[$key] = $normalized;
        }
      }
    }
    return $projection;
  }

  /**
   * Returns whether submitted data must be redacted for this staff account.
   */
  private function submittedDataIsRedacted(ApplicationInterface $application): bool {
    return $this->dataProtection->applicationRequiresProtectedReveal($application)
      && !$this->canRevealProtectedData($application);
  }

  /**
   * Checks the staff reveal permission and the effective case scope.
   */
  private function canRevealProtectedData(ApplicationInterface $application): bool {
    return $this->account->hasPermission('view protected application data')
      && $this->protectedDataGate->allows($application, $this->account);
  }

  /**
   * Returns whether applicant identity linkage must be hidden from staff.
   */
  private function identityLinkageIsRedacted(ApplicationInterface $application): bool {
    return $this->dataProtection->applicationHasProtectedIdentityLinkage($application)
      && !$this->canRevealProtectedData($application);
  }

  /**
   * Builds a workspace projection without numeric group IDs.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application entity.
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $scope
   *   Resolved workspace scope.
   *
   * @return array<string, mixed>
   *   Workspace data.
   */
  private function workspaceProjection(ApplicationInterface $application, array $scope): array {
    $jurisdiction = $this->referencedGroup($application, 'field_jurisdiction') ?? $scope['jurisdiction'];
    $organisation = $this->referencedGroup($application, 'field_organisation') ?? ($scope['organisation'] ?? NULL);

    return array_filter([
      'jurisdiction' => $this->groupProjection($jurisdiction),
      'organisation' => $organisation instanceof GroupInterface ? $this->groupProjection($organisation) : NULL,
    ], static fn(mixed $value): bool => $value !== NULL);
  }

  /**
   * Projects a group as an external workspace reference.
   */
  private function groupProjection(GroupInterface $group): array {
    return array_filter([
      'id' => $group->uuid(),
      'slug' => $this->groupSlug($group),
      'label' => (string) ($group->label() ?? ''),
    ], static fn(mixed $value): bool => $value !== NULL && $value !== '');
  }

  /**
   * Builds effective quick transitions and the render-ready status catalogue.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   Visible applications from the current page.
   *
   * @return array{allowedNextByApplication: array<string, string[]>, reviewCodeByApplication: array<string, string|null>, statuses: array<string, array<string, mixed>>}
   *   Effective target UUIDs, review codes and statuses keyed by UUID.
   */
  private function workflowIndex(array $applications): array {
    $current_by_application = [];
    $target_ids_by_application = [];
    $statuses_by_internal_id = [];
    $allowed_target_cache = [];
    $review_code_cache = [];
    $review_code_by_application = [];
    $target_ids = [];
    $catalog_status_ids = [];

    foreach ($applications as $application) {
      $current = $this->workflowStatusEntity($application);
      if (!$current instanceof WorkflowStatusInterface) {
        continue;
      }
      $current_id = (int) $current->id();
      if ($current_id <= 0) {
        continue;
      }
      $application_uuid = $application->uuid();
      $current_by_application[$application_uuid] = $current;
      $statuses_by_internal_id[$current_id] = $current;
      $catalog_status_ids[$current_id] = TRUE;

      $cache_key = $this->workflowCacheKey($application, $current);
      if (!array_key_exists($cache_key, $allowed_target_cache)) {
        $effective = $this->effectiveWorkflowResolver->allowedTargetIds($application, $current);
        $allowed_target_cache[$cache_key] = $effective ?? $current->getAllowedNextIds();
        $review_code_cache[$cache_key] = $this->effectiveWorkflowResolver->reviewCode($application);
      }
      $review_code_by_application[$application_uuid] = $review_code_cache[$cache_key];
      $application_target_ids = array_values(array_unique(array_filter(
        array_map('intval', $allowed_target_cache[$cache_key]),
        static fn(int $id): bool => $id > 0,
      )));
      $target_ids_by_application[$application_uuid] = $application_target_ids;
      foreach ($application_target_ids as $target_id) {
        $target_ids[$target_id] = $target_id;
      }
    }

    $missing_target_ids = array_diff_key($target_ids, $statuses_by_internal_id);
    if ($missing_target_ids !== []) {
      foreach ($this->manager->getStorage('workflow_status')->loadMultiple($missing_target_ids) as $status) {
        if ($status instanceof WorkflowStatusInterface && (int) $status->id() > 0) {
          $statuses_by_internal_id[(int) $status->id()] = $status;
        }
      }
    }

    $allowed_next_by_application = [];
    $transition_attribute_cache = [];
    foreach ($applications as $application) {
      $application_uuid = $application->uuid();
      $current = $current_by_application[$application_uuid] ?? NULL;
      if (!$current instanceof WorkflowStatusInterface) {
        $allowed_next_by_application[$application_uuid] = [];
        continue;
      }

      $allowed_uuids = [];
      foreach ($target_ids_by_application[$application_uuid] ?? [] as $target_id) {
        $target = $statuses_by_internal_id[$target_id] ?? NULL;
        if (!$target instanceof WorkflowStatusInterface
          || $target->getGroupId() !== $current->getGroupId()) {
          continue;
        }
        $catalog_status_ids[$target_id] = TRUE;
        $transition_key = $this->workflowCacheKey($application, $current) . ':' . $target_id;
        if (!array_key_exists($transition_key, $transition_attribute_cache)) {
          $transition_attribute_cache[$transition_key] = $this->effectiveWorkflowResolver
            ->transitionAttributes($application, $current, $target) !== [];
        }
        if ($transition_attribute_cache[$transition_key]) {
          continue;
        }
        $target_uuid = $target->uuid();
        if (is_string($target_uuid) && $target_uuid !== '') {
          $allowed_uuids[$target_uuid] = $target_uuid;
        }
      }
      $allowed_next_by_application[$application_uuid] = array_values($allowed_uuids);
    }

    $statuses = [];
    foreach ($statuses_by_internal_id as $status_id => $status) {
      if (!isset($catalog_status_ids[$status_id])) {
        continue;
      }
      $projection = $this->workflowStatusProjection($status);
      if ($projection !== NULL) {
        $statuses[$projection['id']] = $projection;
      }
    }
    uasort($statuses, static fn(array $a, array $b): int =>
      ($a['weight'] <=> $b['weight']) ?: strcasecmp($a['label'], $b['label'])
    );

    return [
      'allowedNextByApplication' => $allowed_next_by_application,
      'reviewCodeByApplication' => $review_code_by_application,
      'statuses' => $statuses,
    ];
  }

  /**
   * Returns a stable per-workflow-revision/status cache key for one request.
   *
   * The governing form revision replaces the intake version: all
   * applications of a published form share its flow, while applications of
   * a never-published form keep the flow of their own intake revision.
   */
  private function workflowCacheKey(ApplicationInterface $application, WorkflowStatusInterface $status): string {
    $schema = $application->get('field_schema')->entity ?? NULL;
    $schema_key = $schema instanceof EntityInterface && is_string($schema->uuid())
      ? $schema->uuid()
      : 'none';
    return implode(':', [
      $schema_key,
      (string) $this->effectiveWorkflowResolver->workflowRevisionId($application),
      (string) $status->id(),
    ]);
  }

  /**
   * Projects one status without Drupal-internal numeric IDs.
   *
   * @return array<string, mixed>|null
   *   V1 status projection, or NULL for an invalid status.
   */
  private function workflowStatusProjection(WorkflowStatusInterface $status): ?array {
    $uuid = $status->uuid();
    if (!is_string($uuid) || $uuid === '') {
      return NULL;
    }
    return [
      'id' => $uuid,
      'machineName' => $status->getMachineName(),
      'label' => (string) ($status->label() ?? ''),
      'color' => $status->getColor() ?? '',
      'icon' => $status->getIcon() ?? '',
      'weight' => $status->getWeight(),
      'semanticFlags' => $status->getSemanticFlags(),
    ];
  }

  /**
   * Resolves the current typed workflow status entity.
   */
  private function workflowStatusEntity(EntityInterface $application): ?WorkflowStatusInterface {
    if (!$application instanceof FieldableEntityInterface
      || !$application->hasField('field_workflow_status')
      || $application->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }
    $status = $application->get('field_workflow_status')->entity ?? NULL;
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Builds permission-checked assignment UUIDs without per-row entity loads.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   Visible applications from the current page.
   *
   * @return array<string, array{assigneeId?: string, teamId?: string}>
   *   Assignment projection keyed by application UUID.
   */
  private function assignmentIndex(array $applications): array {
    if (!$this->accountCanViewAssignments()) {
      return [];
    }

    $references = [];
    $target_ids = ['user' => [], 'group' => []];
    $fields = [
      'field_assigned_to' => ['entityType' => 'user', 'key' => 'assigneeId'],
      'field_assigned_team' => ['entityType' => 'group', 'key' => 'teamId'],
    ];
    foreach ($applications as $application) {
      foreach ($fields as $field_name => $field_info) {
        $target_id = $this->referencedEntityId($application, $field_name);
        if ($target_id === NULL) {
          continue;
        }
        $entity_type = $field_info['entityType'];
        $references[$application->uuid()][$field_info['key']] = [$entity_type, $target_id];
        $target_ids[$entity_type][$target_id] = $target_id;
      }
    }

    $uuids = ['user' => [], 'group' => []];
    foreach ($target_ids as $entity_type => $ids) {
      if ($ids === []) {
        continue;
      }
      foreach ($this->manager->getStorage($entity_type)->loadMultiple($ids) as $id => $entity) {
        $uuid = $entity->uuid();
        if (is_string($uuid) && $uuid !== '') {
          $uuids[$entity_type][(int) $id] = $uuid;
        }
      }
    }

    $assignments = [];
    foreach ($references as $application_uuid => $application_references) {
      foreach ($application_references as $key => [$entity_type, $target_id]) {
        $uuid = $uuids[$entity_type][$target_id] ?? NULL;
        if ($uuid !== NULL) {
          $assignments[$application_uuid][$key] = $uuid;
        }
      }
    }
    return $assignments;
  }

  /**
   * Returns TRUE when the account may view workflow assignments.
   */
  private function accountCanViewAssignments(): bool {
    return $this->account->hasPermission('administer application entities')
      || $this->account->hasPermission('bypass beantragt group scope')
      || $this->account->hasPermission('view workflow assignments');
  }

  /**
   * Builds display metadata for the current workflow status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $application
   *   Application entity or revision.
   * @param string[] $allowed_next_ids
   *   Effective quick-transition target UUIDs.
   *
   * @return array{status: string, id: string, label: string, color: string, icon: string, weight: int, semanticFlags: string[], allowedNextIds: string[]}
   *   Render-ready workflow status data.
   */
  private function workflowStatusInfo(EntityInterface $application, array $allowed_next_ids = []): array {
    $status_entity = $this->workflowStatusEntity($application);
    $info = [
      'status' => 'unknown',
      'id' => '',
      'label' => '',
      'color' => '',
      'icon' => '',
      'weight' => 0,
      'semanticFlags' => [],
      'allowedNextIds' => $allowed_next_ids,
    ];

    if ($status_entity instanceof WorkflowStatusInterface && !$status_entity->access('view', $this->currentUser())) {
      $info['allowedNextIds'] = [];
      return $info;
    }
    $status = $status_entity instanceof WorkflowStatusInterface
      ? $status_entity->getMachineName()
      : WorkflowStatusResolver::machineName($application, 'unknown');
    $info['status'] = $status;

    if (!$status_entity instanceof WorkflowStatusInterface) {
      return $info;
    }

    $status_uuid = $status_entity->uuid();
    $info['id'] = is_string($status_uuid) ? $status_uuid : '';
    $info['label'] = (string) ($status_entity->label() ?? '');
    $info['color'] = $status_entity->getColor() ?? '';
    $info['icon'] = $status_entity->getIcon() ?? '';
    $info['weight'] = $status_entity->getWeight();
    $info['semanticFlags'] = $status_entity->getSemanticFlags();
    return $info;
  }

  /**
   * Builds a non-PII workflow timeline from application revisions.
   *
   * @return array<int, array<string, string>>
   *   Timeline entries.
   */
  private function buildTimeline(ApplicationInterface $application): array {
    $storage = $this->manager->getStorage('application');
    if (!$storage instanceof RevisionableStorageInterface) {
      return [];
    }

    $revision_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('id', $application->id())
      ->sort('revision_id', 'ASC')
      ->execute();

    $timeline = [];
    $previous_status = NULL;
    foreach (array_keys($revision_ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof EntityInterface || !$revision->access('view', $this->currentUser())) {
        continue;
      }
      $status_info = $this->workflowStatusInfo($revision);
      $status = $status_info['status'];
      if ($previous_status !== NULL && $status === $previous_status) {
        continue;
      }
      $entry = [
        'status' => $status,
        'timestamp' => $this->revisionTimestamp($revision),
        'label' => $status_info['label'],
        'color' => $status_info['color'],
        'icon' => $status_info['icon'],
      ];
      $timeline[] = array_filter($entry, static fn(string $value): bool => $value !== '');
      $previous_status = $status;
    }

    $langcode = NULL;
    $this->moduleHandler()->alter('beantragt_application_timeline', $timeline, $application, $langcode);
    return $timeline;
  }

  /**
   * Reads a scalar field value from a fieldable entity.
   */
  private function fieldValue(FieldableEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }
    return trim((string) $entity->get($field_name)->value);
  }

  /**
   * Formats a revision timestamp.
   */
  private function revisionTimestamp(EntityInterface $revision): string {
    if ($revision instanceof FieldableEntityInterface
      && $revision->hasField('revision_created')
      && !$revision->get('revision_created')->isEmpty()) {
      return $this->formatTimestamp((int) $revision->get('revision_created')->value);
    }
    return '';
  }

  /**
   * Resolves an entity reference to a group.
   */
  private function referencedGroup(ApplicationInterface $application, string $field_name): ?GroupInterface {
    if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
      return NULL;
    }
    $group = $application->get($field_name)->entity ?? NULL;
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Reads an internal entity-reference target for request-local batch loading.
   */
  private function referencedEntityId(ApplicationInterface $application, string $field_name): ?int {
    if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
      return NULL;
    }
    $target_id = $application->get($field_name)->target_id ?? NULL;
    return is_numeric($target_id) && (int) $target_id > 0 ? (int) $target_id : NULL;
  }

  /**
   * Loads a jurisdiction or organisation group by public slug.
   */
  private function loadGroupBySlug(string $bundle, string $slug): ?GroupInterface {
    $storage = $this->manager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_slug', $slug)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Loads an organisation group by public slug and jurisdiction.
   */
  private function loadOrganisationBySlug(string $slug, int $jurisdiction_id): ?GroupInterface {
    $storage = $this->manager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_slug', $slug)
      ->condition('field_jurisdiction', $jurisdiction_id)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Resolves an optional form schema UUID list filter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Incoming API request.
   * @param array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface} $scope
   *   Resolved workspace scope.
   *
   * @return int|\Symfony\Component\HttpFoundation\JsonResponse|null
   *   Form schema entity ID, an error response, or NULL when no filter is set.
   */
  private function resolveOptionalFormFilter(Request $request, array $scope): int|JsonResponse|null {
    $raw = $request->query->get('form')
      ?? $request->query->get('schema')
      ?? $request->query->get('formSchema');
    if ($raw === NULL || $raw === '') {
      return NULL;
    }

    $uuid = $this->normalizeUuid($raw);
    if ($uuid === NULL) {
      return $this->apiError(
        'form.invalid',
        'Query parameter form must be a UUID.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $query = $this->tenantScopedEntityQuery
      ->getQuery('form_schema', $this->account)
      ->condition('uuid', $uuid)
      ->range(0, 1);
    $this->tenantScopedEntityQuery->applyWorkspaceScope(
      $query,
      'form_schema',
      $scope['jurisdiction'],
      $scope['organisation'] ?? NULL,
    );
    $ids = $query->execute();
    if ($ids === []) {
      return $this->apiError(
        'form.not_found',
        'Form schema not found.',
        Response::HTTP_NOT_FOUND,
      );
    }

    return (int) reset($ids);
  }

  /**
   * Normalizes a public workspace slug.
   */
  private function normalizeSlug(mixed $value): ?string {
    if (is_array($value)) {
      $value = reset($value);
    }
    if (!is_string($value)) {
      return NULL;
    }
    $slug = strtolower(trim($value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Normalizes a UUID query parameter.
   */
  private function normalizeUuid(mixed $value): ?string {
    if (is_array($value)) {
      $value = reset($value);
    }
    if (!is_string($value)) {
      return NULL;
    }
    $uuid = strtolower(trim($value));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1
      ? $uuid
      : NULL;
  }

  /**
   * Reads a group's public slug.
   */
  private function groupSlug(GroupInterface $group): ?string {
    if (!$group->hasField('field_slug') || $group->get('field_slug')->isEmpty()) {
      return NULL;
    }
    $slug = strtolower(trim((string) $group->get('field_slug')->value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Returns TRUE when an account is a staff profile.
   */
  private function isStaffAccount(AccountInterface $account): bool {
    return (int) $account->id() === 1 || BeantragtRoles::isStaffAccount($account);
  }

  /**
   * Returns TRUE when an account is a backend service account.
   */
  private function isServiceAccount(AccountInterface $account): bool {
    return BeantragtRoles::isServiceAccount($account);
  }

  /**
   * Returns TRUE when an account has application view permissions.
   */
  private function accountCanViewApplications(AccountInterface $account): bool {
    return $account->hasPermission('administer application entities')
      || $account->hasPermission('bypass beantragt group scope')
      || $account->hasPermission('view published application entities')
      || $account->hasPermission('view unpublished application entities');
  }

  /**
   * Parses the optional list expansion contract.
   *
   * @return bool|\Symfony\Component\HttpFoundation\JsonResponse
   *   TRUE when submittedData was requested, FALSE when no expansion was
   *   requested, or an error response for unsupported values.
   */
  private function includeSubmittedData(Request $request): bool|JsonResponse {
    $raw = $request->query->all()['include'] ?? NULL;
    if ($raw === NULL || $raw === '') {
      return FALSE;
    }
    if (!is_string($raw)) {
      return $this->apiError(
        'include.invalid',
        'Only submittedData may be included.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    $includes = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    if ($includes !== ['submittedData']) {
      return $this->apiError(
        'include.invalid',
        'Only submittedData may be included.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    return TRUE;
  }

  /**
   * Parses the v1 list pagination query.
   *
   * @return array{limit: int, offset: int}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Parsed pagination values, or an error response.
   */
  private function parsePagination(Request $request, bool $unread_messages): array|JsonResponse {
    $limit = $this->parseIntegerQuery($request, 'limit', self::DEFAULT_LIMIT);
    $offset = $this->parseIntegerQuery($request, 'offset', 0);
    if ($limit === NULL || $limit < 1 || $limit > self::MAX_LIMIT) {
      return $this->apiError(
        'pagination.invalid',
        'Query parameter limit must be an integer between 1 and 500.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    if ($unread_messages && $offset !== NULL && $offset > 0) {
      return $this->apiError(
        'pagination.offset_with_unread_messages',
        'Query parameter offset must be omitted or zero when unreadMessages=true. Use after to continue.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    if ($offset === NULL || $offset < 0 || $offset > self::MAX_OFFSET) {
      return $this->apiError(
        'pagination.invalid',
        'Query parameter offset must be an integer between 0 and 10000.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    return [
      'limit' => $limit,
      'offset' => $offset,
    ];
  }

  /**
   * Parses the v1 list sort query.
   */
  private function parseListSort(Request $request): string|JsonResponse {
    $raw = $request->query->get('sort');
    if ($raw === NULL || $raw === '') {
      return 'updated';
    }
    $sort = strtolower(trim((string) $raw));
    if (in_array($sort, ['updated', 'changed'], TRUE)) {
      return 'updated';
    }
    if ($sort === 'created') {
      return 'created';
    }
    return $this->apiError(
      'sort.invalid',
      'Query parameter sort must be either updated or created.',
      Response::HTTP_BAD_REQUEST,
    );
  }

  /**
   * Parses an integer query parameter.
   */
  private function parseIntegerQuery(Request $request, string $name, int $default): ?int {
    $raw = $request->query->get($name);
    if ($raw === NULL || $raw === '') {
      return $default;
    }
    $value = filter_var((string) $raw, FILTER_VALIDATE_INT);
    return $value === FALSE ? NULL : (int) $value;
  }

  /**
   * Builds the v1 pagination metadata.
   *
   * @return array{limit: int, offset: int, hasMore: bool, nextOffset?: int}
   *   Pagination metadata.
   */
  private function paginationMeta(int $limit, int $offset, bool $has_more): array {
    $page = [
      'limit' => $limit,
      'offset' => $offset,
      'hasMore' => $has_more,
    ];
    if ($has_more) {
      $page['nextOffset'] = $offset + $limit;
    }
    return $page;
  }

  /**
   * Formats a Drupal timestamp as an ISO-8601 string.
   */
  private function formatTimestamp(int $timestamp): string {
    return $timestamp > 0 ? date(\DateTimeInterface::ATOM, $timestamp) : '';
  }

  /**
   * Creates a private, non-cacheable JSON response.
   */
  private function noStoreResponse(array $data): JsonResponse {
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Creates a private v1 error envelope.
   */
  private function apiError(string $code, string $message, int $status): JsonResponse {
    return $this->noStoreResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
      ],
    ])->setStatusCode($status);
  }

  /**
   * Creates the v1 workspace-not-found response.
   */
  private function workspaceNotFound(): JsonResponse {
    return $this->apiError(
      'workspace.not_found',
      'Workspace scope not found.',
      Response::HTTP_NOT_FOUND,
    );
  }

  /**
   * Creates the v1 not-found response for hidden or missing applications.
   */
  private function applicationNotFound(): JsonResponse {
    return $this->apiError(
      'application.not_found',
      'Application not found.',
      Response::HTTP_NOT_FOUND,
    );
  }

}
