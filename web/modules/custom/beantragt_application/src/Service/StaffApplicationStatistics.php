<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\EntityQuery\ScopedAggregateSelectQuery;
use Drupal\beantragt_application\EntityQuery\ScopedApplicationSelect;
use Drupal\beantragt_form_schema_group\Entity\FormSchemaGroupInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryFactoryInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Scoped aggregate counts and report figures for the staff v1 API.
 *
 * Every figure comes from GROUP BY queries over the applications the account
 * may see in one workspace. The scope is the one of the staff list: the
 * TenantScopedEntityQuery account scope (confirmed applications only), the
 * workspace scope and the published/unpublished view permissions that the
 * list enforces per row through ApplicationAccessControlHandler.
 *
 * The list drops rows after its query through per-row entity access; this
 * service cannot. A view rule added to ApplicationAccessControlHandler or to a
 * hook_application_access() implementation must therefore be mirrored here,
 * or the counts and the list drift apart. An unpublished application a staff
 * member owns is listed through "view own application entities"; it is counted
 * only when the account can view unpublished applications in the workspace.
 */
class StaffApplicationStatistics {

  /**
   * Time zone for date-only range boundaries and the deadline "today".
   */
  public const REPORT_TIME_ZONE = 'Europe/Berlin';

  /**
   * Days after today that still count as "due soon".
   */
  public const DEADLINE_SOON_DAYS = 7;

  /**
   * Semantic status flags that mark an application as completed.
   */
  private const COMPLETED_FLAGS = ['approved', 'rejected', 'closed', 'withdrawn'];

  /**
   * Semantic status flag that marks an application as waiting for a reply.
   */
  private const FOLLOW_UP_FLAG = 'request_info';

  /**
   * Bucket key for applications without a status or form.
   */
  private const UNKNOWN_KEY = 'unknown';

  /**
   * Application fields the aggregates read from the scoped select.
   */
  private const AGGREGATE_FIELDS = [
    'field_schema',
    'field_workflow_status',
    'field_due_date',
    'created',
    'changed',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TenantScopedEntityQuery $tenantScopedEntityQuery,
    private readonly QueryFactoryInterface $entityQueryFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Counts applications per form and per form group in one aggregate query.
   *
   * @return array{total: int, applicationsByForm: array<string, int>, applicationsByFormGroup: array<string, int>, formGroupsByForm: array<string, int>, formGroups: list<array{id: string, label: string, formIds: list<string>}>}
   *   Counts keyed by form and form group UUID.
   */
  public function applicationCounts(AccountInterface $account, GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): array {
    $scope = $this->scopedSelect($account, $jurisdiction, $organisation);
    $select = $scope->newSelect();
    $select->addExpression($scope->column('field_schema'), 'form_id');
    $select->addExpression('COUNT(*)', 'application_count');
    $select->groupBy('form_id');

    $total = 0;
    $counts_by_form_id = [];
    foreach ($select->execute()->fetchAll() as $row) {
      $count = (int) $row->application_count;
      $total += $count;
      $form_id = (int) $row->form_id;
      if ($form_id > 0) {
        $counts_by_form_id[$form_id] = $count;
      }
    }

    $form_groups = $this->visibleFormGroups($account, $jurisdiction, $organisation);
    $form_ids = array_keys($counts_by_form_id);
    foreach ($form_groups as $form_group) {
      $form_ids = array_merge($form_ids, $form_group['formIds']);
    }
    $forms = $this->formProjection($form_ids);

    $applications_by_form = [];
    foreach ($counts_by_form_id as $form_id => $count) {
      if (isset($forms[$form_id])) {
        $applications_by_form[$forms[$form_id]['uuid']] = $count;
      }
    }

    $applications_by_form_group = [];
    $form_groups_by_form = [];
    $form_group_projection = [];
    foreach ($form_groups as $form_group) {
      $form_uuids = [];
      $group_count = 0;
      foreach (array_unique($form_group['formIds']) as $form_id) {
        if (!isset($forms[$form_id])) {
          continue;
        }
        $form_uuid = $forms[$form_id]['uuid'];
        $form_uuids[] = $form_uuid;
        $group_count += $counts_by_form_id[$form_id] ?? 0;
        $form_groups_by_form[$form_uuid] = ($form_groups_by_form[$form_uuid] ?? 0) + 1;
      }
      $applications_by_form_group[$form_group['uuid']] = $group_count;
      $form_group_projection[] = [
        'id' => $form_group['uuid'],
        'label' => $form_group['label'],
        'formIds' => $form_uuids,
      ];
    }

    return [
      'total' => $total,
      'applicationsByForm' => $applications_by_form,
      'applicationsByFormGroup' => $applications_by_form_group,
      'formGroupsByForm' => $form_groups_by_form,
      'formGroups' => $form_group_projection,
    ];
  }

  /**
   * Builds the report figures with three aggregate queries on one scope.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Staff account.
   * @param \Drupal\group\Entity\GroupInterface $jurisdiction
   *   Jurisdiction workspace.
   * @param \Drupal\group\Entity\GroupInterface|null $organisation
   *   Optional organisation workspace.
   * @param array{formId?: int|null, from?: int|null, to?: int|null} $filters
   *   Optional form schema ID and inclusive creation time range (Unix time).
   *
   * @return array{totals: array<string, int|float|null>, deadlines: array<string, int|string>, byStatus: list<array<string, mixed>>, byForm: list<array<string, mixed>>, byDay: list<array{date: string, count: int}>}
   *   Report figures.
   */
  public function report(AccountInterface $account, GroupInterface $jurisdiction, ?GroupInterface $organisation, array $filters = []): array {
    $today = $this->todayInReportTimeZone();
    $soon = $today->modify('+' . self::DEADLINE_SOON_DAYS . ' days');
    $today_key = $today->format('Y-m-d');
    $soon_key = $soon->format('Y-m-d');

    // The scope is compiled once; each aggregate runs on its own clone.
    $scope = $this->scopedSelect($account, $jurisdiction, $organisation, $filters);

    // Query 1: status x form with sums for processing time and deadlines.
    $select = $scope->newSelect();
    $status = $scope->column('field_workflow_status');
    $form = $scope->column('field_schema');
    $created = $scope->column('created');
    $changed = $scope->column('changed');
    $due = $scope->column('field_due_date');
    $select->addExpression($status, 'status_id');
    $select->addExpression($form, 'form_id');
    $select->addExpression('COUNT(*)', 'application_count');
    $select->addExpression("SUM(CASE WHEN $changed >= $created THEN $changed - $created ELSE 0 END)", 'processing_seconds');
    $select->addExpression("SUM(CASE WHEN $changed >= $created THEN 1 ELSE 0 END)", 'processing_count');
    $select->addExpression("SUM(CASE WHEN $due < :overdue_before THEN 1 ELSE 0 END)", 'overdue', [':overdue_before' => $today_key]);
    $select->addExpression("SUM(CASE WHEN $due = :due_today THEN 1 ELSE 0 END)", 'due_today', [':due_today' => $today_key]);
    $select->addExpression(
      "SUM(CASE WHEN $due >= :soon_from AND $due <= :soon_to THEN 1 ELSE 0 END)",
      'due_soon',
      [':soon_from' => $today_key, ':soon_to' => $soon_key],
    );
    $select->groupBy('status_id');
    $select->groupBy('form_id');
    $rows = $select->execute()->fetchAll();

    $status_ids = [];
    $form_ids = [];
    foreach ($rows as $row) {
      if ((int) $row->status_id > 0) {
        $status_ids[] = (int) $row->status_id;
      }
      if ((int) $row->form_id > 0) {
        $form_ids[] = (int) $row->form_id;
      }
    }
    $statuses = $this->statusProjection($status_ids, $account);
    $forms = $this->formProjection($form_ids);

    $totals = [
      'applications' => 0,
      'completed' => 0,
      'followUps' => 0,
      'processingSeconds' => 0,
      'processingCount' => 0,
    ];
    $deadlines = ['overdue' => 0, 'dueToday' => 0, 'dueSoon' => 0];
    $by_status = [];
    $by_form = [];
    foreach ($rows as $row) {
      $count = (int) $row->application_count;
      $processing_seconds = (int) $row->processing_seconds;
      $processing_count = (int) $row->processing_count;
      $status_info = $statuses[(int) $row->status_id] ?? $this->unknownStatus();
      $form_info = $forms[(int) $row->form_id] ?? ['uuid' => self::UNKNOWN_KEY, 'label' => self::UNKNOWN_KEY];

      $totals['applications'] += $count;
      $totals['processingSeconds'] += $processing_seconds;
      $totals['processingCount'] += $processing_count;
      if ($status_info['completed']) {
        $totals['completed'] += $count;
      }
      else {
        $deadlines['overdue'] += (int) $row->overdue;
        $deadlines['dueToday'] += (int) $row->due_today;
        $deadlines['dueSoon'] += (int) $row->due_soon;
      }
      $follow_ups = $status_info['followUp'] ? $count : 0;
      $totals['followUps'] += $follow_ups;

      $status_key = $status_info['key'];
      $status_label = $status_info['label'];
      $by_status[$status_key] ??= ['key' => $status_key, 'count' => 0, 'variants' => []];
      $by_status[$status_key]['count'] += $count;
      $by_status[$status_key]['variants'][$status_label] ??= [
        'label' => $status_label,
        'color' => $status_info['color'],
        'completed' => $status_info['completed'],
        'count' => 0,
      ];
      $by_status[$status_key]['variants'][$status_label]['count'] += $count;

      $form_key = $form_info['uuid'];
      $by_form[$form_key] ??= [
        'key' => $form_key,
        'label' => $form_info['label'],
        'count' => 0,
        'followUpCount' => 0,
        'processingSeconds' => 0,
        'processingCount' => 0,
      ];
      $by_form[$form_key]['count'] += $count;
      $by_form[$form_key]['followUpCount'] += $follow_ups;
      $by_form[$form_key]['processingSeconds'] += $processing_seconds;
      $by_form[$form_key]['processingCount'] += $processing_count;
    }

    $by_status_list = [];
    foreach ($by_status as $bucket) {
      // Organisations may label one machine name differently. The label with
      // the most applications names the merged bucket, ties go alphabetical,
      // so the name does not depend on row order.
      $variants = array_values($bucket['variants']);
      usort($variants, static fn(array $a, array $b): int => ($b['count'] <=> $a['count'])
        ?: strcmp((string) $a['label'], (string) $b['label']));
      $by_status_list[] = [
        'key' => $bucket['key'],
        'label' => $variants[0]['label'],
        'color' => $variants[0]['color'],
        'completed' => $variants[0]['completed'],
        'count' => $bucket['count'],
      ];
    }

    $by_form_list = [];
    foreach ($by_form as $bucket) {
      $by_form_list[] = [
        'key' => $bucket['key'],
        'label' => $bucket['label'],
        'count' => $bucket['count'],
        'averageProcessingHours' => $this->averageHours($bucket['processingSeconds'], $bucket['processingCount']),
        'followUpCount' => $bucket['followUpCount'],
      ];
    }

    return [
      'totals' => [
        'applications' => $totals['applications'],
        'completed' => $totals['completed'],
        'open' => $totals['applications'] - $totals['completed'],
        'followUps' => $totals['followUps'],
        'averageProcessingHours' => $this->averageHours($totals['processingSeconds'], $totals['processingCount']),
        'medianProcessingHours' => $this->medianProcessingHours($scope, $totals['processingCount']),
      ],
      'deadlines' => $deadlines + [
        'today' => $today_key,
        'horizonDays' => self::DEADLINE_SOON_DAYS,
      ],
      'byStatus' => $this->sortBuckets($by_status_list),
      'byForm' => $this->sortBuckets($by_form_list),
      'byDay' => $this->applicationsByDay($scope),
    ];
  }

  /**
   * Returns seconds until the next midnight in the report time zone.
   *
   * The deadline figures change at midnight without any entity change.
   */
  public function secondsUntilReportDayEnds(): int {
    $tomorrow = $this->todayInReportTimeZone()->modify('+1 day');
    return max(1, $tomorrow->getTimestamp() - $this->time->getRequestTime());
  }

  /**
   * Compiles the scope of the applications the account may see, once.
   *
   * The select reads the application base table. Callers clone it and add
   * the fields they need for each aggregate. Compilation fails
   * closed (\LogicException) when a condition needs another table.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Staff account.
   * @param \Drupal\group\Entity\GroupInterface $jurisdiction
   *   Jurisdiction workspace.
   * @param \Drupal\group\Entity\GroupInterface|null $organisation
   *   Optional organisation workspace.
   * @param array{formId?: int|null, from?: int|null, to?: int|null} $filters
   *   Optional form schema ID and inclusive creation time range (Unix time).
   */
  public function scopedSelect(
    AccountInterface $account,
    GroupInterface $jurisdiction,
    ?GroupInterface $organisation = NULL,
    array $filters = [],
  ): ScopedApplicationSelect {
    $query = new ScopedAggregateSelectQuery(
      $this->entityTypeManager->getDefinition('application'),
      'AND',
      $this->database,
      QueryBase::getNamespaces($this->entityQueryFactory),
    );
    // Same sequence as the staff list: getQuery() scope, then workspace scope.
    $this->tenantScopedEntityQuery->scopeQuery($query, 'application', $account);
    $this->tenantScopedEntityQuery->applyWorkspaceScope($query, 'application', $jurisdiction, $organisation);
    $this->applyViewPermissionScope($query, $account);

    if (!empty($filters['formId'])) {
      $query->condition('field_schema', (int) $filters['formId']);
    }
    if (isset($filters['from'])) {
      $query->condition('created', (int) $filters['from'], '>=');
    }
    if (isset($filters['to'])) {
      $query->condition('created', (int) $filters['to'], '<=');
    }

    $select = $query->compileSelect();
    $columns = [];
    foreach (self::AGGREGATE_FIELDS as $field_name) {
      $columns[$field_name] = $query->sqlColumn($field_name);
    }
    return new ScopedApplicationSelect($select, $columns);
  }

  /**
   * Applies the published/unpublished view permissions as SQL conditions.
   *
   * The staff list drops rows per entity through
   * ApplicationAccessControlHandler::checkAccess('view'): the admin permission
   * sees every row of the scope, otherwise a published row needs "view
   * published application entities" and an unpublished row "view unpublished
   * application entities". The tenant scope itself is already in the query.
   */
  private function applyViewPermissionScope(QueryInterface $query, AccountInterface $account): void {
    if ($account->hasPermission('administer application entities')) {
      return;
    }
    $published = $account->hasPermission('view published application entities');
    $unpublished = $account->hasPermission('view unpublished application entities');
    if ($published && $unpublished) {
      return;
    }
    if ($published) {
      $query->condition('status', 1);
      return;
    }
    if ($unpublished) {
      $query->condition('status', 0);
      return;
    }
    $query->condition('id', 0);
  }

  /**
   * Counts applications per UTC day of creation.
   *
   * @return list<array{date: string, count: int}>
   *   Days in ascending order.
   */
  private function applicationsByDay(ScopedApplicationSelect $scope): array {
    $select = $scope->newSelect();
    $created = $scope->column('created');
    $select->addExpression("$created - ($created % 86400)", 'day_start');
    $select->addExpression('COUNT(*)', 'application_count');
    $select->groupBy('day_start');
    $select->orderBy('day_start');

    $days = [];
    foreach ($select->execute()->fetchAll() as $row) {
      $days[] = [
        'date' => gmdate('Y-m-d', (int) $row->day_start),
        'count' => (int) $row->application_count,
      ];
    }
    return $days;
  }

  /**
   * Returns the median processing time in hours.
   *
   * Scaling limit: the database sorts every scoped row by processing time to
   * reach the middle offset (about 0.2 s for 150,000 rows). It is the most
   * expensive of the report queries and grows with n log n.
   *
   * @param \Drupal\beantragt_application\EntityQuery\ScopedApplicationSelect $scope
   *   Compiled scope.
   * @param int $count
   *   Number of rows with a processing time, from the first query.
   */
  private function medianProcessingHours(ScopedApplicationSelect $scope, int $count): ?float {
    if ($count <= 0) {
      return NULL;
    }
    $select = $scope->newSelect();
    $created = $scope->column('created');
    $changed = $scope->column('changed');
    $select->addExpression("$changed - $created", 'processing_seconds');
    $select->where("$changed >= $created");
    $select->orderBy('processing_seconds');
    $select->range(intdiv($count - 1, 2), $count % 2 === 1 ? 1 : 2);
    $values = array_map('intval', $select->execute()->fetchCol());
    if ($values === []) {
      return NULL;
    }
    return round(array_sum($values) / count($values) / 3600, 1);
  }

  /**
   * Loads the form groups visible in the workspace.
   *
   * Form groups are few per workspace, so they are loaded and checked like
   * the JSON:API collection the start page used before.
   *
   * @return list<array{uuid: string, label: string, formIds: int[]}>
   *   Visible form groups.
   */
  private function visibleFormGroups(AccountInterface $account, GroupInterface $jurisdiction, ?GroupInterface $organisation): array {
    if (!$this->entityTypeManager->hasDefinition('form_schema_group')) {
      return [];
    }
    $query = $this->tenantScopedEntityQuery->getQuery('form_schema_group', $account);
    $this->tenantScopedEntityQuery->applyWorkspaceScope($query, 'form_schema_group', $jurisdiction, $organisation);
    $ids = $query->sort('id')->execute();
    if ($ids === []) {
      return [];
    }

    $groups = [];
    foreach ($this->entityTypeManager->getStorage('form_schema_group')->loadMultiple($ids) as $group) {
      if (!$group instanceof FormSchemaGroupInterface || !$group->access('view', $account)) {
        continue;
      }
      $groups[] = [
        'uuid' => (string) $group->uuid(),
        'label' => (string) $group->label(),
        'formIds' => $group->getFormSchemaIds(),
      ];
    }
    return $groups;
  }

  /**
   * Maps form schema IDs to UUID and label without loading the schemas.
   *
   * @param int[] $formIds
   *   Form schema IDs.
   *
   * @return array<int, array{uuid: string, label: string}>
   *   Projection keyed by form schema ID.
   */
  private function formProjection(array $formIds): array {
    $formIds = array_values(array_unique(array_filter(array_map('intval', $formIds), static fn(int $id): bool => $id > 0)));
    if ($formIds === []) {
      return [];
    }
    /** @var list<array{id: int|string, uuid: string, label: string|null}> $rows */
    $rows = $this->entityTypeManager->getStorage('form_schema')
      ->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('id', $formIds, 'IN')
      ->groupBy('id')
      ->groupBy('uuid')
      ->groupBy('label')
      ->execute();

    $forms = [];
    foreach ($rows as $row) {
      $label = trim((string) ($row['label'] ?? ''));
      $forms[(int) $row['id']] = [
        'uuid' => (string) $row['uuid'],
        'label' => $label !== '' ? $label : (string) $row['uuid'],
      ];
    }
    return $forms;
  }

  /**
   * Projects the workflow statuses that appear in the result.
   *
   * Like the staff list (workflowStatusInfo()), a status the account may not
   * view is left out, so its rows fall back to the "unknown" projection.
   *
   * @param int[] $statusIds
   *   Workflow status IDs.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Staff account.
   *
   * @return array<int, array{key: string, label: string, color: string|null, completed: bool, followUp: bool}>
   *   Projection keyed by workflow status ID.
   */
  private function statusProjection(array $statusIds, AccountInterface $account): array {
    $statusIds = array_values(array_unique($statusIds));
    if ($statusIds === []) {
      return [];
    }
    $statuses = [];
    foreach ($this->entityTypeManager->getStorage('workflow_status')->loadMultiple($statusIds) as $status) {
      if (!$status instanceof WorkflowStatusInterface || !$status->access('view', $account)) {
        continue;
      }
      $flags = $status->getSemanticFlags();
      $label = trim((string) $status->label());
      $machine_name = trim($status->getMachineName());
      $statuses[(int) $status->id()] = [
        'key' => $machine_name !== '' ? $machine_name : ($label !== '' ? $label : (string) $status->uuid()),
        'label' => $label !== '' ? $label : ($machine_name !== '' ? $machine_name : (string) $status->uuid()),
        'color' => $status->getColor(),
        'completed' => $status->isTerminal() || array_intersect(self::COMPLETED_FLAGS, $flags) !== [],
        'followUp' => in_array(self::FOLLOW_UP_FLAG, $flags, TRUE),
      ];
    }
    return $statuses;
  }

  /**
   * Returns the projection for applications without a known status.
   *
   * @return array{key: string, label: string, color: null, completed: false, followUp: false}
   *   Unknown status projection.
   */
  private function unknownStatus(): array {
    return [
      'key' => self::UNKNOWN_KEY,
      'label' => self::UNKNOWN_KEY,
      'color' => NULL,
      'completed' => FALSE,
      'followUp' => FALSE,
    ];
  }

  /**
   * Sorts buckets by count descending, then label.
   *
   * @param list<array<string, mixed>> $buckets
   *   Buckets with count and label.
   *
   * @return list<array<string, mixed>>
   *   Sorted buckets.
   */
  private function sortBuckets(array $buckets): array {
    usort($buckets, static fn(array $a, array $b): int => ((int) $b['count'] <=> (int) $a['count'])
      ?: strcmp((string) $a['label'], (string) $b['label']));
    return $buckets;
  }

  /**
   * Converts summed seconds to average hours with one decimal.
   */
  private function averageHours(int $seconds, int $count): ?float {
    return $count > 0 ? round($seconds / $count / 3600, 1) : NULL;
  }

  /**
   * Returns today's midnight in the report time zone.
   */
  private function todayInReportTimeZone(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(self::REPORT_TIME_ZONE))
      ->setTime(0, 0);
  }

}
