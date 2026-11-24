<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\StaffApplicationApiController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\EntityQuery\ScopedAggregateSelectQuery;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\beantragt_application\Service\ApplicationMessageReadState;
use Drupal\beantragt_application\Service\StaffApplicationStatistics;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the scoped staff counts and report aggregates against real entities.
 */
#[Group('beantragt_application')]
final class StaffApplicationStatisticsKernelTest extends GroupKernelTestBase {

  /**
   * More rows in one form than one JSON:API page holds (50).
   */
  private const LARGE_FORM_ROWS = 55;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_form_schema_group',
    'beantragt_roles_api',
  ];

  /**
   * The service under test.
   */
  private StaffApplicationStatistics $statistics;

  /**
   * Jurisdiction with two organisations.
   */
  private GroupInterface $jurisdiction;

  /**
   * Organisation with the large form.
   */
  private GroupInterface $organisationA;

  /**
   * Organisation with the small form.
   */
  private GroupInterface $organisationB;

  /**
   * Another jurisdiction with one organisation.
   */
  private GroupInterface $foreignJurisdiction;

  /**
   * Status owned by organisation B that marks a row as approved.
   */
  private WorkflowStatusInterface $approvedB;

  /**
   * Open status owned by organisation A.
   */
  private WorkflowStatusInterface $openA;

  /**
   * Large form in organisation A.
   */
  private FormSchemaInterface $formA;

  /**
   * Small form in organisation B.
   */
  private FormSchemaInterface $formB;

  /**
   * Request time used for created/changed and due dates.
   */
  private int $now;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('form_schema_group');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    // The staff routes resolve workspaces by slug, a configurable group field.
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->entityTypeManager());

    $this->setCurrentUser($this->createUser(['bypass beantragt group scope', 'view published form_schema entities']));
    $this->statistics = $this->container->get('beantragt_application.staff_application_statistics');
    $this->now = (int) $this->container->get('datetime.time')->getRequestTime();

    $this->jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Statistik Stadt',
      'field_slug' => 'statistik-stadt',
    ]);
    $this->organisationA = $this->createOrganisation('statistik-amt-a', $this->jurisdiction);
    $this->organisationB = $this->createOrganisation('statistik-amt-b', $this->jurisdiction);
    $this->foreignJurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Andere Stadt',
      'field_slug' => 'andere-stadt',
    ]);
    $foreign_organisation = $this->createOrganisation('anderes-amt', $this->foreignJurisdiction);

    $open_a = $this->createStatus($this->organisationA, 'eingegangen', FALSE, ['received']);
    $closed_a = $this->createStatus($this->organisationA, 'abgeschlossen', TRUE, ['closed']);
    $follow_up_a = $this->createStatus($this->organisationA, 'rueckfrage', FALSE, ['request_info']);
    $open_b = $this->createStatus($this->organisationB, 'eingegangen', FALSE, ['received']);
    $approved_b = $this->createStatus($this->organisationB, 'bewilligt', FALSE, ['approved']);
    $this->approvedB = $approved_b;
    $this->openA = $open_a;
    $open_foreign = $this->createStatus($foreign_organisation, 'eingegangen', FALSE, ['received']);

    $this->formA = $this->createFormSchema('Grosses Formular', $this->organisationA);
    $this->formB = $this->createFormSchema('Kleines Formular', $this->organisationB);
    $form_foreign = $this->createFormSchema('Fremdes Formular', $foreign_organisation);

    $today = $this->berlinDate(0);
    // Organisation A: 55 visible rows, of which 10 closed and 5 waiting for a
    // reply. Due dates on open rows: 2 overdue, 1 today, 3 within 7 days. A
    // closed row with an overdue date must not count as overdue.
    for ($i = 0; $i < self::LARGE_FORM_ROWS; $i++) {
      $status = $open_a;
      if ($i < 10) {
        $status = $closed_a;
      }
      elseif ($i < 15) {
        $status = $follow_up_a;
      }
      $due = match (TRUE) {
        $i === 0 => $this->berlinDate(-3),
        $i === 20, $i === 21 => $this->berlinDate(-1),
        $i === 22 => $today,
        $i >= 23 && $i <= 25 => $this->berlinDate(3),
        default => NULL,
      };
      $this->createApplication($this->formA, $this->organisationA, $status, [
        'field_due_date' => $due,
      ]);
    }
    // Hidden from staff lists: unconfirmed and unpublished rows.
    $this->createApplication($this->formA, $this->organisationA, $open_a, ['field_email_confirmed' => FALSE]);
    $this->createApplication($this->formA, $this->organisationA, $open_a, ['field_email_confirmed' => FALSE]);
    $this->createApplication($this->formA, $this->organisationA, $open_a, ['status' => FALSE]);

    // Organisation B: processing times of 1, 2 and 6 hours.
    foreach ([1, 2, 6] as $index => $hours) {
      $this->createApplication($this->formB, $this->organisationB, $index === 2 ? $approved_b : $open_b, [
        'created' => $this->now - $hours * 3600,
        'changed' => $this->now,
      ]);
    }

    // Another jurisdiction.
    $this->createApplication($form_foreign, $foreign_organisation, $open_foreign);
    $this->createApplication($form_foreign, $foreign_organisation, $open_foreign);

    // Flows share the scope of their forms: one in A, two in B, one abroad.
    $this->createFormGroup('Ablauf Amt A', [$this->formA], $this->organisationA);
    $this->createFormGroup('Ablauf Amt B', [$this->formB], $this->organisationB);
    $this->createFormGroup('Ablauf B zwei', [$this->formB], $this->organisationB);
    $this->createFormGroup('Ablauf fremd', [$form_foreign], $foreign_organisation);
  }

  /**
   * A one-organisation case worker counts all of its rows past 50 per form.
   */
  public function testOrganisationMemberCountsMatchPerRowAccess(): void {
    $viewer = $this->createCaseWorker();
    $this->organisationA->addMember($viewer, ['group_roles' => ['org-member']]);

    $counts = $this->statistics->applicationCounts($viewer, $this->jurisdiction, $this->organisationA);

    $this->assertSame(self::LARGE_FORM_ROWS, $counts['total']);
    $this->assertSame([$this->formA->uuid() => self::LARGE_FORM_ROWS], $counts['applicationsByForm']);
    $this->assertSame($this->staffListCount($viewer, $this->organisationA), $counts['total']);
    $this->assertSame(['Ablauf Amt A'], array_column($counts['formGroups'], 'label'));
    $this->assertSame([self::LARGE_FORM_ROWS], array_values($counts['applicationsByFormGroup']));
  }

  /**
   * A jurisdiction administrator counts every organisation of the workspace.
   */
  public function testJurisdictionAdminCountsMatchPerRowAccess(): void {
    $admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($admin, ['group_roles' => ['jur-admin']]);

    $counts = $this->statistics->applicationCounts($admin, $this->jurisdiction);

    $this->assertSame(self::LARGE_FORM_ROWS + 3, $counts['total']);
    $this->assertSame($this->staffListCount($admin, $this->jurisdiction), $counts['total']);
    $this->assertSame(self::LARGE_FORM_ROWS, $counts['applicationsByForm'][$this->formA->uuid()]);
    $this->assertSame(3, $counts['applicationsByForm'][$this->formB->uuid()]);

    // The jurisdiction workspace holds the flows of its organisations, never
    // the foreign one (same filter as the start page).
    $groups = array_column($counts['formGroups'], 'id', 'label');
    $this->assertSame(['Ablauf Amt A', 'Ablauf Amt B', 'Ablauf B zwei'], array_keys($groups));
    $this->assertSame(self::LARGE_FORM_ROWS, $counts['applicationsByFormGroup'][$groups['Ablauf Amt A']]);
    $this->assertSame(3, $counts['applicationsByFormGroup'][$groups['Ablauf Amt B']]);
    $this->assertSame(1, $counts['formGroupsByForm'][$this->formA->uuid()]);
    $this->assertSame(2, $counts['formGroupsByForm'][$this->formB->uuid()]);
  }

  /**
   * The organisation workspace lists only the flows of that organisation.
   */
  public function testOrganisationWorkspaceFormGroups(): void {
    $viewer = $this->createCaseWorker();
    $this->organisationB->addMember($viewer, ['group_roles' => ['org-member']]);

    $counts = $this->statistics->applicationCounts($viewer, $this->jurisdiction, $this->organisationB);

    $this->assertSame(3, $counts['total']);
    $this->assertSame(['Ablauf Amt B', 'Ablauf B zwei'], array_column($counts['formGroups'], 'label'));
    $this->assertSame([$this->formB->uuid()], $counts['formGroups'][0]['formIds']);
    $this->assertSame([$this->formB->uuid() => 2], $counts['formGroupsByForm']);
  }

  /**
   * The report aggregates status, deadlines, processing time and days.
   */
  public function testReportAggregates(): void {
    $admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($admin, ['group_roles' => ['jur-admin']]);
    // Organisation B names its "eingegangen" status differently. It has fewer
    // applications and sorts first alphabetically, so only the count rule
    // keeps organisation A's label on the merged bucket.
    $statuses = $this->entityTypeManager()->getStorage('workflow_status')->loadByProperties([
      'field_machine_name' => 'eingegangen',
      'field_group' => $this->organisationB->id(),
    ]);
    $open_b = reset($statuses);
    $this->assertInstanceOf(WorkflowStatusInterface::class, $open_b);
    $open_b->set('label', 'Angekommen')->save();

    $report = $this->statistics->report($admin, $this->jurisdiction, NULL);

    $this->assertSame(self::LARGE_FORM_ROWS + 3, $report['totals']['applications']);
    // 10 closed in A plus the approved row in B.
    $this->assertSame(11, $report['totals']['completed']);
    $this->assertSame(self::LARGE_FORM_ROWS + 3 - 11, $report['totals']['open']);
    $this->assertSame(5, $report['totals']['followUps']);
    $this->assertSame(2, $report['deadlines']['overdue']);
    $this->assertSame(1, $report['deadlines']['dueToday']);
    $this->assertSame(4, $report['deadlines']['dueSoon']);
    $this->assertSame($this->berlinDate(0), $report['deadlines']['today']);

    $by_status = array_column($report['byStatus'], 'count', 'key');
    // Status machine names repeat per organisation and merge into one bucket.
    $this->assertSame(self::LARGE_FORM_ROWS - 15 + 2, $by_status['eingegangen']);
    $labels = array_column($report['byStatus'], 'label', 'key');
    $this->assertSame('Eingegangen', $labels['eingegangen']);
    $this->assertSame(10, $by_status['abgeschlossen']);
    $this->assertSame(5, $by_status['rueckfrage']);
    $this->assertSame(1, $by_status['bewilligt']);

    $by_form = array_column($report['byForm'], NULL, 'key');
    $this->assertSame(self::LARGE_FORM_ROWS, $by_form[$this->formA->uuid()]['count']);
    $this->assertSame(5, $by_form[$this->formA->uuid()]['followUpCount']);
    $this->assertSame('Kleines Formular', $by_form[$this->formB->uuid()]['label']);
    $this->assertSame(3.0, $by_form[$this->formB->uuid()]['averageProcessingHours']);

    $this->assertSame(self::LARGE_FORM_ROWS + 3, array_sum(array_column($report['byDay'], 'count')));
  }

  /**
   * Median and form filter run on the same scope.
   */
  public function testReportMedianWithFormFilter(): void {
    $viewer = $this->createCaseWorker();
    $this->organisationB->addMember($viewer, ['group_roles' => ['org-member']]);

    $report = $this->statistics->report($viewer, $this->jurisdiction, $this->organisationB, [
      'formId' => (int) $this->formB->id(),
    ]);

    $this->assertSame(3, $report['totals']['applications']);
    $this->assertSame(3.0, $report['totals']['averageProcessingHours']);
    $this->assertSame(2.0, $report['totals']['medianProcessingHours']);

    $empty = $this->statistics->report($viewer, $this->jurisdiction, $this->organisationB, [
      'from' => $this->now + 3600,
    ]);
    $this->assertSame(0, $empty['totals']['applications']);
    $this->assertNull($empty['totals']['medianProcessingHours']);
  }

  /**
   * Without the unpublished permission the count follows the row access.
   */
  public function testUnpublishedRowsFollowViewPermissions(): void {
    $viewer = $this->createCaseWorker(['view unpublished application entities']);
    $this->organisationA->addMember($viewer, ['group_roles' => ['org-member']]);

    $counts = $this->statistics->applicationCounts($viewer, $this->jurisdiction, $this->organisationA);

    $this->assertSame(self::LARGE_FORM_ROWS + 1, $counts['total']);
    $this->assertSame($this->staffListCount($viewer, $this->organisationA), $counts['total']);
  }

  /**
   * Nested OR groups resolve their columns directly on the base table.
   */
  public function testScopeCompilesWithoutJoins(): void {
    $admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($admin, ['group_roles' => ['jur-admin']]);
    $this->organisationA->addMember($admin, ['group_roles' => ['org-admin']]);

    $query = new ScopedAggregateSelectQuery(
      $this->entityTypeManager()->getDefinition('application'),
      'AND',
      $this->container->get('database'),
      QueryBase::getNamespaces($this->container->get('entity.query.sql')),
    );
    $scope = $this->container->get('beantragt_application.tenant_scoped_entity_query');
    $scope->scopeQuery($query, 'application', $admin);
    $scope->applyWorkspaceScope($query, 'application', $this->jurisdiction);
    $select = $query->compileSelect();
    $select->addExpression($query->sqlColumn('field_schema'), 'form_id');
    $select->addExpression('COUNT(*)', 'application_count');
    $select->groupBy('form_id');
    $select->orderBy('form_id');

    $this->assertSame(['base_table'], array_keys($select->getTables()));
    // Without the view-permission condition of the service the unpublished
    // row of form A is part of the tenant scope.
    $this->assertSame(
      [(string) $this->formA->id() => (string) (self::LARGE_FORM_ROWS + 1), (string) $this->formB->id() => '3'],
      array_map('strval', $select->execute()->fetchAllKeyed()),
    );
  }

  /**
   * The service's compiled scope uses only the base table with every filter.
   */
  public function testServiceScopeCompilesWithoutJoinsWithFilters(): void {
    // Without the unpublished permission the scope carries a status condition.
    $admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($admin, ['group_roles' => ['jur-admin']]);

    $scope = $this->statistics->scopedSelect($admin, $this->jurisdiction, NULL, [
      'formId' => (int) $this->formA->id(),
      'from' => $this->now - 86400,
      'to' => $this->now + 86400,
    ]);
    $select = $scope->newSelect();
    $select->addExpression($scope->column('field_schema'), 'form_id');
    $select->addExpression('COUNT(*)', 'application_count');
    $select->groupBy('form_id');
    $select->orderBy('form_id');

    $sql = (string) $select;
    $this->assertSame(['base_table'], array_keys($select->getTables()));
    $this->assertStringContainsString('"status"', $sql);
    $this->assertSame(
      [(string) $this->formA->id() => (string) self::LARGE_FORM_ROWS],
      array_map('strval', $select->execute()->fetchAllKeyed()),
    );
    // Each aggregate takes its own clone of the compiled scope.
    $this->assertNotSame($scope->newSelect(), $scope->newSelect());
  }

  /**
   * The aggregate query refuses joins and a second compile.
   */
  public function testScopedQueryFailsClosed(): void {
    $relationship = $this->newScopedQuery();
    $relationship->condition('field_schema.entity.label', 'Grosses Formular');
    try {
      $relationship->compileSelect();
      $this->fail('A condition on another table must not compile.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('single-valued columns', $e->getMessage());
    }

    $twice = $this->newScopedQuery();
    $twice->condition('field_email_confirmed', TRUE);
    $twice->compileSelect();
    $this->expectException(\LogicException::class);
    $twice->compileSelect();
  }

  /**
   * Counts and the staff list agree on tenant scope after an office move.
   */
  public function testCountsEqualStaffListAcrossPersonas(): void {
    // Legacy row 1: only field_group (older than the save normalization).
    $legacy_only = $this->createApplication($this->formA, $this->organisationA, $this->openA);
    $this->setScopeColumns($legacy_only, ['field_jurisdiction' => NULL, 'field_organisation' => NULL]);
    // Legacy row 2: field_group and field_jurisdiction, no field_organisation.
    $legacy_jurisdiction = $this->createApplication($this->formA, $this->organisationA, $this->openA);
    $this->setScopeColumns($legacy_jurisdiction, ['field_organisation' => NULL]);

    // An organisation with two applications moves to the other jurisdiction.
    $moved = $this->createOrganisation('umgezogenes-amt', $this->jurisdiction);
    $moved_status = $this->createStatus($moved, 'eingegangen', FALSE, ['received']);
    $moved_form = $this->createFormSchema('Umzugsformular', $moved);
    $this->createApplication($moved_form, $moved, $moved_status);
    $this->createApplication($moved_form, $moved, $moved_status);
    $jur_admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($jur_admin, ['group_roles' => ['jur-admin']]);
    $this->assertSame(2, $this->statistics->applicationCounts($jur_admin, $this->jurisdiction, $moved)['total']);
    $moved->set('field_jurisdiction', ['target_id' => $this->foreignJurisdiction->id()]);
    $moved->save();
    $kept = (int) $this->container->get('database')->select('application', 'a')
      ->condition('field_organisation', $moved->id())
      ->condition('field_jurisdiction', $this->jurisdiction->id())
      ->countQuery()->execute()->fetchField();
    $this->assertSame(2, $kept, 'Moved applications keep the old jurisdiction.');

    $org_member = $this->createCaseWorker();
    $this->organisationA->addMember($org_member, ['group_roles' => ['org-member']]);
    $foreign_admin = $this->createCaseWorker();
    $this->foreignJurisdiction->addMember($foreign_admin, ['group_roles' => ['jur-admin']]);
    $both_admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($both_admin, ['group_roles' => ['jur-admin']]);
    $this->foreignJurisdiction->addMember($both_admin, ['group_roles' => ['jur-admin']]);
    $moved_member = $this->createCaseWorker();
    $moved->addMember($moved_member, ['group_roles' => ['org-member']]);
    $entity_admin = $this->createCaseWorker(['administer application entities']);
    $this->organisationA->addMember($entity_admin, ['group_roles' => ['org-member']]);
    $bypass = $this->createCaseWorker(['bypass beantragt group scope']);
    $owner = $this->createCaseWorker(['view own application entities']);
    $this->organisationA->addMember($owner, ['group_roles' => ['org-member']]);
    $this->createApplication($this->formA, $this->organisationA, $this->openA, [
      'status' => FALSE,
      'uid' => $owner->id(),
    ]);

    $rows = self::LARGE_FORM_ROWS;
    $cases = [
      // Persona, workspace, expected count, expected staff list.
      'org member' => [$org_member, $this->organisationA, $rows + 2, $rows + 2],
      // Only the legacy office row with a recorded jurisdiction is inherited.
      'jurisdiction admin' => [$jur_admin, $this->jurisdiction, $rows + 3 + 1, $rows + 3 + 1],
      // The moved rows keep the old jurisdiction: outside both workspaces.
      'new jurisdiction admin' => [$foreign_admin, $this->foreignJurisdiction, 2, 2],
      'new jurisdiction admin in moved office' => [$foreign_admin, $moved, 0, 0],
      'admin of both jurisdictions in moved office' => [$both_admin, $moved, 0, 0],
      'direct member in moved office' => [$moved_member, $moved, 2, 2],
      // Admin permission: unpublished rows of the scope count and list.
      'entity admin' => [$entity_admin, $this->organisationA, $rows + 2 + 2, $rows + 2 + 2],
      // Scope bypass: the whole jurisdiction workspace, published rows only.
      'bypass' => [$bypass, $this->jurisdiction, $rows + 3 + 2 + 2, $rows + 3 + 2 + 2],
      // Own unpublished row: listed through "view own", not counted.
      'owner' => [$owner, $this->organisationA, $rows + 2, $rows + 3],
    ];
    foreach ($cases as $label => [$account, $workspace, $expected_count, $expected_list]) {
      $organisation = $workspace->bundle() === 'org' ? $workspace : NULL;
      $jurisdiction = $this->jurisdictionOf($workspace);
      $this->assertSame($expected_list, $this->staffListCount($account, $workspace), "$label: staff list");
      $counts = $this->statistics->applicationCounts($account, $jurisdiction, $organisation);
      $this->assertSame($expected_count, $counts['total'], "$label: counts");
      $report = $this->statistics->report($account, $jurisdiction, $organisation);
      $this->assertSame($expected_count, $report['totals']['applications'], "$label: report");
    }
  }

  /**
   * A status the account may not view is reported as unknown, as in the list.
   */
  public function testReportHidesStatusesTheAccountMayNotView(): void {
    // A row of organisation A that points at a status owned by organisation B.
    $this->createApplication($this->formA, $this->organisationA, $this->approvedB);
    $viewer = $this->createCaseWorker();
    $this->organisationA->addMember($viewer, ['group_roles' => ['org-member']]);
    $this->assertFalse($this->approvedB->access('view', $viewer));

    $report = $this->statistics->report($viewer, $this->jurisdiction, $this->organisationA);

    $by_status = array_column($report['byStatus'], NULL, 'key');
    $this->assertArrayNotHasKey('bewilligt', $by_status);
    $this->assertSame(1, $by_status['unknown']['count']);
    $this->assertSame('unknown', $by_status['unknown']['label']);
    $this->assertNull($by_status['unknown']['color']);
    // Without the status flags the row stays open, as the list shows it.
    $this->assertSame(10, $report['totals']['completed']);
  }

  /**
   * The controller reuses the list's workspace checks and returns metadata.
   */
  public function testControllerCountsContract(): void {
    $viewer = $this->createCaseWorker();
    $this->organisationA->addMember($viewer, ['group_roles' => ['org-member']]);
    $this->setCurrentUser($viewer);
    $controller = StaffApplicationApiController::create($this->container);

    $response = $controller->counts(Request::create('/api/v1/staff/applications/counts', 'GET', [
      'jurisdiction' => 'statistik-stadt',
      'organisation' => 'statistik-amt-a',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(self::LARGE_FORM_ROWS, $payload['data']['total']);
    $this->assertSame('staffApplicationCounts', $payload['meta']['resource']);
    $this->assertSame(['jurisdiction' => 'statistik-stadt', 'organisation' => 'statistik-amt-a'], $payload['meta']['scope']);
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $cacheability = $response->getCacheableMetadata();
    $this->assertContains('application_list', $cacheability->getCacheTags());
    $this->assertContains('group_relationship_list:plugin:group_membership:entity:' . $viewer->id(), $cacheability->getCacheTags());
    $this->assertContains('config:group_role_list', $cacheability->getCacheTags());
    $this->assertContains('user', $cacheability->getCacheContexts());
    $this->assertContains('url.query_args:jurisdiction', $cacheability->getCacheContexts());
    $this->assertContains('url.query_args:organisation', $cacheability->getCacheContexts());
    $this->assertNotContains('url.query_args', $cacheability->getCacheContexts());
    $this->assertNotContains('url.query_args:from', $cacheability->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());

    $this->assertIsArray($payload['data']['applicationsByForm']);
    $this->assertNotSame([], $payload['data']['applicationsByForm']);

    // A workspace without applications still sends its maps as JSON objects.
    $empty_organisation = $this->createOrganisation('statistik-amt-leer', $this->jurisdiction);
    $empty_viewer = $this->createCaseWorker();
    $empty_organisation->addMember($empty_viewer, ['group_roles' => ['org-member']]);
    $this->setCurrentUser($empty_viewer);
    $empty = StaffApplicationApiController::create($this->container)->counts(Request::create('/api/v1/staff/applications/counts', 'GET', [
      'jurisdiction' => 'statistik-stadt',
      'organisation' => 'statistik-amt-leer',
    ]));
    $this->assertSame(Response::HTTP_OK, $empty->getStatusCode());
    $empty_content = (string) $empty->getContent();
    $this->assertStringContainsString('"total":0', $empty_content);
    foreach (['applicationsByForm', 'applicationsByFormGroup', 'formGroupsByForm'] as $map) {
      $this->assertStringContainsString('"' . $map . '":{}', $empty_content);
    }
    $this->setCurrentUser($viewer);

    $forbidden = $controller->counts(Request::create('/api/v1/staff/applications/counts', 'GET', [
      'jurisdiction' => 'statistik-stadt',
    ]));
    $this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
    $this->assertNotInstanceOf(CacheableJsonResponse::class, $forbidden);
  }

  /**
   * Filters the scoped list before paging and respects personal message reads.
   */
  public function testUnreadMessageFilter(): void {
    $first = $this->createApplication($this->formA, $this->organisationA, $this->openA, ['created' => $this->now - 1000]);
    $second = $this->createApplication($this->formA, $this->organisationA, $this->openA, ['created' => $this->now - 2000]);
    $hidden = $this->createApplication($this->formA, $this->organisationA, $this->openA, [
      'status' => FALSE,
      'created' => $this->now - 1500,
    ]);
    $other_scope = $this->createApplication($this->formB, $this->organisationB, $this->approvedB);
    $staff_only = $this->createApplication($this->formA, $this->organisationA, $this->openA);
    $this->installMessageReadState();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $file = $this->container->get('entity_type.manager')->getStorage('file')->create([
      'uri' => 'private://fixture.pdf',
      'status' => 1,
    ]);
    $file->save();
    $attachment_reply = $this->seedReply($first, [
      'field_body' => '',
      'field_attachments' => [['target_id' => $file->id()]],
    ]);
    $this->seedReply($first);
    $this->seedReply($second);
    $this->seedReply($second, ['created' => $this->now - 20]);
    $this->seedReply($hidden);
    $this->seedReply($other_scope);
    $this->seedReply($staff_only, ['field_author_role' => 'staff']);
    $this->seedReply($staff_only, ['field_visibility' => 'internal']);
    $this->seedReply($staff_only, ['status' => 0]);
    $this->seedReply($staff_only, ['created' => $this->now + 3600]);

    $alice = $this->createCaseWorker(['view application_comment entities']);
    $colleague = $this->createCaseWorker(['view application_comment entities']);
    $without_messages = $this->createCaseWorker();
    foreach ([$alice, $colleague, $without_messages] as $viewer) {
      $this->organisationA->addMember($viewer, ['group_roles' => ['org-member']]);
    }
    $this->organisationB->addMember($alice, ['group_roles' => ['org-member']]);
    $this->container->get('state')->set(ApplicationMessageReadState::BASELINE_STATE, $this->now - 10);
    $this->container->get('database')->insert(ApplicationMessageReadState::TABLE)->fields([
      'uid' => $alice->id(),
      'application_id' => $second->id(),
      'kind' => 'message',
      'seen_at' => $this->now - 30,
    ])->execute();
    $this->setCurrentUser($alice);
    $controller = StaffApplicationApiController::create($this->container);
    $scope = ['jurisdiction' => 'statistik-stadt', 'organisation' => 'statistik-amt-a'];
    $list_path = '/api/v1/staff/applications';
    $base = $scope + ['sort' => 'created'];
    $filtered = $base + ['unreadMessages' => 'true', 'limit' => '1'];
    $unfiltered = $controller->list(Request::create($list_path, 'GET', $base + ['limit' => '25']));
    $unfiltered_data = json_decode((string) $unfiltered->getContent(), TRUE);
    $this->assertSame(25, $unfiltered_data['meta']['count']);
    $this->assertNotContains($first->uuid(), array_column($unfiltered_data['data'], 'id'));
    $this->assertNotContains($second->uuid(), array_column($unfiltered_data['data'], 'id'));
    $disabled = $controller->list(Request::create($list_path, 'GET', $base + [
      'limit' => '25',
      'unreadMessages' => 'false',
    ]));
    $this->assertSame($unfiltered->getContent(), $disabled->getContent());

    $response = $controller->list(Request::create($list_path, 'GET', $filtered));
    $page = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame([$first->uuid()], array_column($page['data'], 'id'));
    $this->assertSame(1, $page['meta']['count']);
    $this->assertSame(2, $page['meta']['events'][$first->uuid()]['message']);
    $this->assertTrue($page['meta']['page']['hasMore']);
    $this->assertArrayNotHasKey('offset', $page['meta']['page']);
    $this->assertArrayNotHasKey('nextOffset', $page['meta']['page']);
    $cursor = $page['meta']['page']['nextCursor'];
    $response = $controller->list(Request::create($list_path, 'GET', $filtered + ['after' => $cursor]));
    $page = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([$second->uuid()], array_column($page['data'], 'id'));
    $this->assertSame(2, $page['meta']['events'][$second->uuid()]['message']);
    $this->assertSame(['limit' => 1, 'hasMore' => FALSE], $page['meta']['page']);

    $tampered = ($cursor[0] === 'A' ? 'B' : 'A') . substr($cursor, 1);
    $bad_queries = [
      'pagination.offset_with_unread_messages' => $filtered + ['offset' => '1'],
      'pagination.cursor_requires_unread_messages' => $base + ['after' => $cursor],
      'unread_messages.confirmation_conflict' => $filtered + ['confirmation' => 'unconfirmed'],
    ];
    foreach ($bad_queries as $code => $query) {
      $response = $controller->list(Request::create($list_path, 'GET', $query));
      $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
      $this->assertSame($code, json_decode((string) $response->getContent(), TRUE)['error']['code']);
    }
    foreach ([
      $filtered + ['after' => $tampered],
      $filtered + ['after' => [$cursor]],
      array_replace($filtered, ['after' => $cursor, 'organisation' => 'statistik-amt-b']),
      array_replace($filtered, ['after' => $cursor, 'sort' => 'updated']),
      $filtered + ['after' => $cursor, 'form' => $this->formA->uuid()],
    ] as $query) {
      $this->assertInvalidUnreadCursor($controller, $list_path, $query);
    }

    $detail_path = $list_path . '/' . $first->uuid();
    $detail = $controller->detail($first->uuid(), Request::create($detail_path, 'GET', $scope));
    $events = json_decode((string) $detail->getContent(), TRUE)['meta']['events'];
    $this->assertContains($attachment_reply, $events['newMessageIds']);
    $this->assertNull($events['previousSeenAt']['message']);
    $request = Request::create($detail_path . '/events/message/seen?' . http_build_query($scope), 'POST', [], [], [], [],
      json_encode(['tokens' => $events['seenTokens']['message']], JSON_THROW_ON_ERROR));
    $response = $controller->markEventSeen($first->uuid(), 'message', $request);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame($events['newMessageIds'], json_decode((string) $response->getContent(), TRUE)['meta']['events']['newMessageIds']);
    $response = $controller->list(Request::create($list_path, 'GET', $filtered));
    $page = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([$second->uuid()], array_column($page['data'], 'id'));
    $this->assertFalse($page['meta']['page']['hasMore']);
    $this->setCurrentUser($colleague);
    $this->assertInvalidUnreadCursor($controller, $list_path, $filtered + ['after' => $cursor]);
    $response = $controller->list(Request::create($list_path, 'GET', $filtered));
    $this->assertSame(2, json_decode((string) $response->getContent(), TRUE)['meta']['events'][$first->uuid()]['message']);
    $this->setCurrentUser($without_messages);
    $response = $controller->list(Request::create($list_path, 'GET', $filtered));
    $this->assertSame([], json_decode((string) $response->getContent(), TRUE)['data']);
    $forbidden = $controller->list(Request::create($list_path, 'GET', [
      'jurisdiction' => 'statistik-stadt',
      'unreadMessages' => 'true',
    ]));
    $this->assertSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode());
    $invalid = $controller->list(Request::create($list_path, 'GET', $scope + ['unreadMessages' => ['true']]));
    $this->assertSame(Response::HTTP_BAD_REQUEST, $invalid->getStatusCode());
    $this->assertSame('unread_messages.invalid', json_decode((string) $invalid->getContent(), TRUE)['error']['code']);
  }

  /**
   * Reaches all matches and resumes after a scan window with no matches.
   */
  public function testUnreadMessageCursorContinuesPastScanLimit(): void {
    $seed = $this->createApplication($this->formA, $this->organisationA, $this->openA);
    $sparse_form = $this->createFormSchema('Formular mit wenigen sichtbaren Nachrichten', $this->organisationA);
    $sparse_seed = $this->createApplication($sparse_form, $this->organisationA, $this->openA);
    $this->installMessageReadState();
    $this->seedReply($seed);
    $this->seedReply($sparse_seed);
    $dense_candidates = $this->seedUnreadCursorCandidates($seed, 2000, TRUE);
    $sparse_candidates = $this->seedUnreadCursorCandidates($sparse_seed, 2000, FALSE);
    $expected = [...array_reverse($dense_candidates), $seed->uuid()];

    $viewer = $this->createCaseWorker(['view application_comment entities']);
    $this->organisationA->addMember($viewer, ['group_roles' => ['org-member']]);
    $this->setCurrentUser($viewer);
    $controller = StaffApplicationApiController::create($this->container);
    $list_path = '/api/v1/staff/applications';
    $query = [
      'jurisdiction' => 'statistik-stadt',
      'organisation' => 'statistik-amt-a',
      'form' => $this->formA->uuid(),
      'unreadMessages' => 'true',
      'limit' => '500',
    ];

    $rows = [];
    for ($i = 0; $i < 5; $i++) {
      $response = $controller->list(Request::create($list_path, 'GET', $query));
      $page = json_decode((string) $response->getContent(), TRUE);
      $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
      $this->assertSame($i < 4, $page['meta']['page']['hasMore']);
      $this->assertCount($i < 4 ? 500 : 1, $page['data']);
      array_push($rows, ...array_column($page['data'], 'id'));
      $query['after'] = $page['meta']['page']['nextCursor'] ?? NULL;
    }
    $this->assertSame($expected, $rows, 'Equal timestamps are ordered by descending ID across every page.');
    $this->assertNull($query['after']);
    unset($query['after']);

    // All 2,000 unpublished rows are candidates, even though view is denied.
    $hidden_id = array_key_first($sparse_candidates);
    $hidden = $this->entityTypeManager()->getStorage('application')->load($hidden_id);
    $this->assertInstanceOf(ApplicationInterface::class, $hidden);
    $this->assertFalse($hidden->isPublished());
    $this->assertFalse($hidden->access('view', $viewer));
    $query['form'] = $sparse_form->uuid();
    $query['sort'] = 'created';
    $response = $controller->list(Request::create($list_path, 'GET', $query));
    $page = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([], $page['data']);
    $this->assertTrue($page['meta']['page']['hasMore']);
    $this->assertNotEmpty($page['meta']['page']['nextCursor']);

    // A position is usable even after the anchor application is deleted.
    $database = $this->container->get('database');
    foreach (['application', 'application_revision'] as $table) {
      $database->delete($table)->condition('id', $hidden_id)->execute();
    }
    $response = $controller->list(Request::create($list_path, 'GET', $query + ['after' => $page['meta']['page']['nextCursor']]));
    $page = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([$sparse_seed->uuid()], array_column($page['data'], 'id'));
    $this->assertSame(['limit' => 500, 'hasMore' => FALSE], $page['meta']['page']);
  }

  /**
   * Installs the comment source and personal read state for filter fixtures.
   */
  private function installMessageReadState(): void {
    $this->enableModules(['language', 'beantragt_workflow', 'beantragt_notification', 'beantragt_comment']);
    $this->installEntitySchema('application_comment');
    $this->installSchema('beantragt_application', [
      ApplicationMessageReadState::TABLE,
      ApplicationEventReadState::RECEIPTS_TABLE,
    ]);
  }

  /**
   * Asserts a rejected cursor exposes no rows or continuation metadata.
   */
  private function assertInvalidUnreadCursor(StaffApplicationApiController $controller, string $path, array $query): void {
    $response = $controller->list(Request::create($path, 'GET', $query));
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('pagination.cursor_invalid', $body['error']['code']);
    $this->assertArrayNotHasKey('data', $body);
    $this->assertArrayNotHasKey('page', $body['meta']);
  }

  /**
   * Seeds one window of dated applications with a reply on each.
   *
   * Copies real fixture rows in batches to avoid unrelated save hooks.
   */
  private function seedUnreadCursorCandidates(ApplicationInterface $seed, int $count, bool $published): array {
    $database = $this->container->get('database');
    $templates = [];
    foreach (['application', 'application_revision'] as $table) {
      $templates[$table] = $database->select($table, 'a')->fields('a')
        ->condition('id', $seed->id())->execute()->fetchAssoc();
    }
    $reply = $database->select('application_comment', 'c')->fields('c')
      ->condition('field_application', $seed->id())->execute()->fetchAssoc();
    unset($reply['id']);
    $last_id = (int) $database->select('application', 'a')->fields('a', ['id'])
      ->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
    $last_revision_id = (int) $database->select('application_revision', 'a')->fields('a', ['revision_id'])
      ->orderBy('revision_id', 'DESC')->range(0, 1)->execute()->fetchField();
    $uuid_service = $this->container->get('uuid');
    $uuids = [];
    for ($start = 0; $start < $count; $start += 100) {
      $applications = $database->insert('application')->fields(array_keys($templates['application']));
      $revisions = $database->insert('application_revision')->fields(array_keys($templates['application_revision']));
      $replies = $database->insert('application_comment')->fields(array_keys($reply));
      for ($i = $start; $i < min($start + 100, $count); $i++) {
        $id = $last_id + $i + 1;
        $revision_id = $last_revision_id + $i + 1;
        $uuids[$id] = $uuid = $uuid_service->generate();
        $values = ['id' => $id, 'revision_id' => $revision_id, 'uuid' => $uuid, 'status' => (int) $published];
        $revision_values = array_intersect_key($values, $templates['application_revision']);
        $applications->values(array_replace($templates['application'], $values));
        $revisions->values(array_replace($templates['application_revision'], $revision_values));
        $replies->values(array_replace($reply, ['uuid' => $uuid_service->generate(), 'field_application' => $id]));
      }
      $revisions->execute();
      $applications->execute();
      $replies->execute();
    }
    return $uuids;
  }

  /**
   * Adds a reply directly, bypassing mail hooks for the application fixtures.
   */
  private function seedReply(ApplicationInterface $application, array $values = []): string {
    $uuid = $this->container->get('uuid')->generate();
    $attachments = $values['field_attachments'] ?? [];
    unset($values['field_attachments']);
    $id = $this->container->get('database')->insert('application_comment')->fields($values + [
      'uuid' => $uuid,
      'field_application' => $application->id(),
      'field_author_role' => 'citizen',
      'field_visibility' => 'public',
      'field_body' => 'Reply',
      'created' => $this->now - 1,
      'status' => 1,
      'uid' => 0,
    ])->execute();
    if ($attachments !== []) {
      $comment = $this->container->get('entity_type.manager')->getStorage('application_comment')->load($id);
      $comment->set('field_attachments', $attachments)->save();
    }
    return $uuid;
  }

  /**
   * The report route validates its range and keeps the deadline day short.
   */
  public function testControllerReportContract(): void {
    $admin = $this->createCaseWorker();
    $this->jurisdiction->addMember($admin, ['group_roles' => ['jur-admin']]);
    $this->setCurrentUser($admin);
    $controller = StaffApplicationApiController::create($this->container);

    $response = $controller->report(Request::create('/api/v1/staff/applications/report', 'GET', [
      'jurisdiction' => 'statistik-stadt',
      'form' => $this->formB->uuid(),
      'from' => $this->berlinDate(-1),
      'to' => $this->berlinDate(0),
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(3, $payload['data']['totals']['applications']);
    $this->assertSame($this->formB->uuid(), $payload['meta']['filters']['form']);
    $this->assertFalse($payload['meta']['truncated']);
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $this->assertContains('url.query_args:from', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('url.query_args:form', $response->getCacheableMetadata()->getCacheContexts());
    $max_age = $response->getCacheableMetadata()->getCacheMaxAge();
    $this->assertGreaterThan(0, $max_age);
    $this->assertLessThanOrEqual(25 * 3600, $max_age);

    $invalid = $controller->report(Request::create('/api/v1/staff/applications/report', 'GET', [
      'jurisdiction' => 'statistik-stadt',
      'from' => '2026-02-30',
    ]));
    $this->assertSame(Response::HTTP_BAD_REQUEST, $invalid->getStatusCode());
    $this->assertSame('report.range_invalid', json_decode((string) $invalid->getContent(), TRUE)['error']['code']);

    $reversed = $controller->report(Request::create('/api/v1/staff/applications/report', 'GET', [
      'jurisdiction' => 'statistik-stadt',
      'from' => '2026-10-02',
      'to' => '2026-10-01',
    ]));
    $this->assertSame(Response::HTTP_BAD_REQUEST, $reversed->getStatusCode());
  }

  /**
   * Counts what the staff list shows for an account in a workspace.
   *
   * Same steps as StaffApplicationApiController::list() without paging: the
   * core-compiled tenant query (confirmed rows, account scope, workspace
   * scope), then per-row view access through the access handler and hooks.
   */
  private function staffListCount(AccountInterface $account, GroupInterface $workspace): int {
    $scope = $this->container->get('beantragt_application.tenant_scoped_entity_query');
    $query = $scope->getQuery('application', $account);
    $scope->applyWorkspaceGroupScope($query, 'application', $workspace);
    $storage = $this->entityTypeManager()->getStorage('application');
    $storage->resetCache();
    $this->entityTypeManager()->getAccessControlHandler('application')->resetCache();
    $visible = 0;
    foreach ($storage->loadMultiple($query->execute()) as $application) {
      if ($application instanceof ApplicationInterface && $application->access('view', $account)) {
        $visible++;
      }
    }
    return $visible;
  }

  /**
   * Builds an empty scoped aggregate query for the application entity.
   */
  private function newScopedQuery(): ScopedAggregateSelectQuery {
    $query = new ScopedAggregateSelectQuery(
      $this->entityTypeManager()->getDefinition('application'),
      'AND',
      $this->container->get('database'),
      QueryBase::getNamespaces($this->container->get('entity.query.sql')),
    );
    $query->accessCheck(FALSE);
    return $query;
  }

  /**
   * Returns the jurisdiction of a workspace group.
   */
  private function jurisdictionOf(GroupInterface $workspace): GroupInterface {
    if ($workspace->bundle() === 'jur') {
      return $workspace;
    }
    $jurisdiction = $workspace->get('field_jurisdiction')->entity;
    assert($jurisdiction instanceof GroupInterface);
    return $jurisdiction;
  }

  /**
   * Overwrites scope columns in SQL, like rows older than the normalization.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application.
   * @param array<string, int|null> $columns
   *   Column values keyed by field name.
   */
  private function setScopeColumns(ApplicationInterface $application, array $columns): void {
    $database = $this->container->get('database');
    foreach (['application', 'application_revision'] as $table) {
      $database->update($table)
        ->fields($columns)
        ->condition('id', $application->id())
        ->execute();
    }
    $this->entityTypeManager()->getStorage('application')->resetCache([$application->id()]);
  }

  /**
   * Creates a case worker with the published view permission.
   *
   * @param string[] $extraPermissions
   *   Additional permissions.
   */
  private function createCaseWorker(array $extraPermissions = []): UserInterface {
    $role_id = 'case_worker';
    $role = $this->container->get('entity_type.manager')->getStorage('user_role')->load($role_id);
    if ($role === NULL) {
      $this->createRole([
        'view published application entities',
        'view published form_schema_group entities',
      ], $role_id);
    }
    $user = $this->createUser($extraPermissions);
    assert($user instanceof UserInterface);
    $user->addRole($role_id);
    $user->save();
    return $user;
  }

  /**
   * Creates an organisation in a jurisdiction.
   */
  private function createOrganisation(string $slug, GroupInterface $jurisdiction): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => ucfirst($slug),
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
  }

  /**
   * Creates a workflow status for an organisation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   Owning organisation.
   * @param string $machineName
   *   Status machine name.
   * @param bool $terminal
   *   Whether the status is terminal.
   * @param string[] $flags
   *   Semantic flags.
   */
  private function createStatus(GroupInterface $group, string $machineName, bool $terminal, array $flags): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => ucfirst($machineName),
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => $machineName,
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => $machineName === 'eingegangen',
      'field_is_terminal' => $terminal,
      'field_semantic_flags' => array_map(static fn(string $flag): array => ['value' => $flag], $flags),
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Creates a published intranet form in an organisation.
   */
  private function createFormSchema(string $label, GroupInterface $organisation): FormSchemaInterface {
    $jurisdiction_id = $organisation->get('field_jurisdiction')->target_id;
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => $label,
      'field_schema' => json_encode([
        'title' => $label,
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              ['id' => 'message', 'type' => 'text', 'label' => 'Message', 'required' => FALSE],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $jurisdiction_id],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $schema->save();
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Creates a confirmed, published application unless values override it.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $schema
   *   Form.
   * @param \Drupal\group\Entity\GroupInterface $organisation
   *   Organisation scope.
   * @param \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface $status
   *   Workflow status.
   * @param array<string, mixed> $values
   *   Field overrides.
   */
  private function createApplication(
    FormSchemaInterface $schema,
    GroupInterface $organisation,
    WorkflowStatusInterface $status,
    array $values = [],
  ): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create($values + [
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode(['message' => 'Hallo'], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'antrag@example.org',
      'field_email_confirmed' => TRUE,
      'field_jurisdiction' => ['target_id' => $organisation->get('field_jurisdiction')->target_id],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'field_workflow_status' => ['target_id' => $status->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Creates a form group in the scope of its organisation's forms.
   *
   * @param string $label
   *   Label.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface[] $forms
   *   Member forms of the same organisation.
   * @param \Drupal\group\Entity\GroupInterface $organisation
   *   Organisation scope.
   */
  private function createFormGroup(string $label, array $forms, GroupInterface $organisation): void {
    $this->entityTypeManager()->getStorage('form_schema_group')->create([
      'label' => $label,
      'field_schemas' => array_map(static fn(FormSchemaInterface $form): array => ['target_id' => $form->id()], $forms),
      'field_jurisdiction' => ['target_id' => $organisation->get('field_jurisdiction')->target_id],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ])->save();
  }

  /**
   * Returns a Y-m-d date relative to today in the report time zone.
   */
  private function berlinDate(int $days): string {
    return (new \DateTimeImmutable('@' . $this->now))
      ->setTimezone(new \DateTimeZone(StaffApplicationStatistics::REPORT_TIME_ZONE))
      ->modify(sprintf('%+d days', $days))
      ->format('Y-m-d');
  }

}
