<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\beantragt_application\Controller\StaffApplicationApiController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\beantragt_application\Service\EffectiveWorkflowResolver;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_application\Service\StaffApplicationStatistics;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Query\QueryFactoryInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the staff-facing application API controller.
 */
#[CoversClass(StaffApplicationApiController::class)]
#[Group('beantragt_application')]
final class StaffApplicationApiControllerTest extends UnitTestCase {

  /**
   * Anonymous staff v1 reads return a JSON auth error.
   */
  public function testListRejectsAnonymous(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $account = $this->account(0, ['anonymous']);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request());
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    $this->assertSame('auth.required', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Citizen sessions cannot use the staff API profile.
   */
  public function testListRejectsCitizenProfile(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $account = $this->account(7, ['authenticated'], ['view own application entities']);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request(['jurisdiction' => 'musterstadt']));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Service accounts must use a future partner API profile, not staff reads.
   */
  public function testListRejectsServiceAccountProfile(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $account = $this->account(17, ['authenticated', 'service_account'], ['view published application entities']);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request(['jurisdiction' => 'musterstadt']));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);
  }

  /**
   * Staff reads require an explicit jurisdiction scope.
   */
  public function testListRejectsMissingJurisdictionScope(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request());
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('workspace.required', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * The aggregate endpoints run the list's profile and workspace checks.
   */
  public function testAggregatesRejectAnonymousCitizenAndMissingScope(): void {
    $statistics = $this->createMock(StaffApplicationStatistics::class);
    $statistics->expects($this->never())->method('applicationCounts');
    $statistics->expects($this->never())->method('report');
    $manager = $this->createMock(EntityTypeManagerInterface::class);

    $anonymous = $this->account(0, ['anonymous']);
    $this->installContainer($anonymous, $manager);
    $response = $this->controller($manager, $anonymous, NULL, $statistics)->counts(new Request());
    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

    $citizen = $this->account(30, ['authenticated'], ['view own application entities']);
    $this->installContainer($citizen, $manager);
    $response = $this->controller($manager, $citizen, NULL, $statistics)->report(new Request(['jurisdiction' => 'musterstadt']));
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('auth.unsupported_profile', $payload['error']['code']);

    $case_worker = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $this->installContainer($case_worker, $manager);
    foreach (['counts', 'report'] as $action) {
      $response = $this->controller($manager, $case_worker, NULL, $statistics)->{$action}(new Request());
      $payload = json_decode((string) $response->getContent(), TRUE);
      $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $action);
      $this->assertSame('workspace.required', $payload['error']['code'], $action);
    }
  }

  /**
   * Staff list pagination rejects values outside the v1 contract.
   */
  public function testListRejectsInvalidPagination(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'limit' => '501',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('pagination.invalid', $payload['error']['code']);
  }

  /**
   * Staff list rejects unsupported sort contracts.
   */
  public function testListRejectsInvalidSort(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'sort' => 'reference',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('sort.invalid', $payload['error']['code']);
  }

  /**
   * Organisation staff may list applications only for their organisation scope.
   */
  public function testListReturnsScopedStaffProjection(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'view published application entities',
      'view workflow assignments',
    ]);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 3], NULL, [
      ['changed', 'DESC'],
      ['created', 'DESC'],
    ]));
    $application_storage->method('loadMultiple')->with([44 => 44])->willReturn([44 => $application]);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
      NULL,
      NULL,
      $this->userStorage(),
    );
    $this->installContainer($account, $manager);

    $messages = $this->createMock(ApplicationEventReadState::class);
    $messages->expects($this->once())->method('counts')->with([$application], $account)
      ->willReturn([$application->uuid() => (object) ['message' => 3]]);
    $response = $this->controller($manager, $account, NULL, NULL, $messages)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('staffApplications', $payload['meta']['resource']);
    $this->assertSame([$application->uuid() => ['message' => 3]], $payload['meta']['events']);
    $this->assertSame('staff_application', $payload['data'][0]['type']);
    $this->assertSame('9a67f32b-93cc-48c5-8899-9d5470f57f44', $payload['data'][0]['id']);
    $this->assertSame('musterstadt', $payload['data'][0]['workspace']['jurisdiction']['slug']);
    $this->assertSame('building-department', $payload['data'][0]['workspace']['organisation']['slug']);
    $this->assertSame('ada@example.org', $payload['data'][0]['applicant']['email']);
    $this->assertSame('Ada Lovelace', $payload['data'][0]['crmData']['person.full_name']);
    $this->assertSame('10000000-0000-4000-8000-000000000100', $payload['data'][0]['status']['id']);
    $this->assertSame('in_review', $payload['data'][0]['status']['code']);
    $this->assertSame([], $payload['data'][0]['status']['allowedNextIds']);
    $this->assertTrue($payload['data'][0]['assignmentVisible']);
    $this->assertSame('20000000-0000-4000-8000-000000000021', $payload['data'][0]['assignment']['assigneeId']);
    $this->assertSame($organisation->uuid(), $payload['data'][0]['assignment']['teamId']);
    $this->assertSame(
      ['10000000-0000-4000-8000-000000000100'],
      array_column($payload['meta']['workflowStatuses'], 'id'),
    );
    $this->assertArrayNotHasKey('person.iban', $payload['data'][0]['crmData']);
    $this->assertArrayNotHasKey('entityId', $payload['data'][0]);
    $this->assertArrayNotHasKey('submittedData', $payload['data'][0]);
  }

  /**
   * Assignment UUIDs stay hidden without the workflow assignment permission.
   */
  public function testListHidesAssignmentsWithoutPermission(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 3]));
    $application_storage->method('loadMultiple')->with([44 => 44])->willReturn([44 => $application]);
    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertFalse($payload['data'][0]['assignmentVisible']);
    $this->assertArrayNotHasKey('assignment', $payload['data'][0]);
  }

  /**
   * Applications without a workflow status do not emit an invalid UUID.
   */
  public function testListOmitsMissingWorkflowStatusUuid(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation, has_workflow_status: FALSE);
    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 3]));
    $application_storage->method('loadMultiple')->willReturn([44 => $application]);
    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('unknown', $payload['data'][0]['status']['code']);
    $this->assertArrayNotHasKey('id', $payload['data'][0]['status']);
    $this->assertSame([], $payload['meta']['workflowStatuses']);
  }

  /**
   * Optional form filters are resolved only inside the requested workspace.
   */
  public function testListScopesOptionalFormFilterToWorkspace(): void {
    $form_uuid = 'a25564c2-b6a4-4c7b-83b3-8e6e31fb3602';
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);

    $form_schema_storage = $this->createMock(EntityStorageInterface::class);
    $form_schema_storage->method('getQuery')->willReturn($this->query([88 => 88], [0, 1], [
      ['uuid', $form_uuid],
      ['field_organisation', 4],
    ]));

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 3], [
      ['field_organisation', 4],
      ['field_schema', 88],
    ]));
    $application_storage->method('loadMultiple')->with([44 => 44])->willReturn([44 => $application]);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
      $form_schema_storage,
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'form' => $form_uuid,
      'include' => 'submittedData',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame($form_uuid, $payload['data'][0]['form']['id']);
    $this->assertSame('Ada Lovelace', $payload['data'][0]['submittedData']['full_name']);
    $this->assertSame(
      [ApplicationDataProtectionService::REDACTED_MARKER => TRUE],
      $payload['data'][0]['submittedData']['evidence'],
    );
    $this->assertArrayNotHasKey('timeline', $payload['data'][0]);
  }

  /**
   * Bulk submitted-data expansion requires an explicit form scope.
   */
  public function testListRejectsSubmittedDataWithoutFormScope(): void {
    $account = $this->account(31, ['authenticated', 'department_admin'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $manager = $this->entityTypeManager(
      $this->createMock(EntityStorageInterface::class),
      $this->groupStorage($jurisdiction),
      $this->relationshipStorage([10 => ['jur-admin']]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'include' => 'submittedData',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('include.form_required', $payload['error']['code']);
  }

  /**
   * Bulk submitted data has a smaller availability budget than summaries.
   */
  public function testListRejectsLargeSubmittedDataPage(): void {
    $form_uuid = 'a25564c2-b6a4-4c7b-83b3-8e6e31fb3602';
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $form_schema_storage = $this->createMock(EntityStorageInterface::class);
    $form_schema_storage->method('getQuery')->willReturn($this->query([88 => 88], [0, 1], [
      ['uuid', $form_uuid],
      ['field_organisation', 4],
    ]));
    $manager = $this->entityTypeManager(
      $this->createMock(EntityStorageInterface::class),
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
      $form_schema_storage,
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'form' => $form_uuid,
      'include' => 'submittedData',
      'limit' => '101',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('pagination.include_limit', $payload['error']['code']);
  }

  /**
   * Non-scalar include values return a controlled v1 error.
   */
  public function testListRejectsArrayInclude(): void {
    $account = $this->account(31, ['authenticated', 'department_admin'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $manager = $this->entityTypeManager(
      $this->createMock(EntityStorageInterface::class),
      $this->groupStorage($jurisdiction),
      $this->relationshipStorage([10 => ['jur-admin']]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'include' => ['submittedData'],
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('include.invalid', $payload['error']['code']);
  }

  /**
   * Staff list can align projections with created-sorted JSON:API lists.
   */
  public function testListSupportsCreatedSortForProjectionAlignment(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 3], NULL, [
      ['created', 'DESC'],
      ['changed', 'DESC'],
    ]));
    $application_storage->method('loadMultiple')->with([44 => 44])->willReturn([44 => $application]);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'sort' => 'created',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('staffApplications', $payload['meta']['resource']);
    $this->assertSame('9a67f32b-93cc-48c5-8899-9d5470f57f44', $payload['data'][0]['id']);
  }

  /**
   * Staff list exposes schema-effective quick transitions as public UUIDs.
   */
  public function testListReturnsEffectiveQuickTransitions(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'view published application entities',
      'manage workflow transitions',
      'bypass beantragt group scope',
    ]);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $current_status = $this->workflowStatus(100, 'in_review', [200, 201]);
    $target_status = $this->workflowStatus(200, 'approved');
    $guarded_target_status = $this->workflowStatus(201, 'returned');
    $foreign_target_status = $this->workflowStatus(202, 'rejected', [], 999);
    $application = $this->application($jurisdiction, $organisation, FALSE, $current_status);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 3]));
    $application_storage->method('loadMultiple')->with([44 => 44])->willReturn([44 => $application]);
    $workflow_status_storage = $this->createMock(EntityStorageInterface::class);
    $workflow_status_storage->expects($this->once())
      ->method('loadMultiple')
      ->with([200 => 200, 201 => 201, 202 => 202])
      ->willReturn([
        200 => $target_status,
        201 => $guarded_target_status,
        202 => $foreign_target_status,
      ]);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
      NULL,
      $workflow_status_storage,
      $this->userStorage(),
    );
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->expects($this->once())
      ->method('allowedTargetIds')
      ->with($application, $current_status)
      ->willReturn([200, 201, 202]);
    $resolver->expects($this->exactly(2))
      ->method('transitionAttributes')
      ->willReturnCallback(static fn(
        ApplicationInterface $_application,
        WorkflowStatusInterface $_current,
        WorkflowStatusInterface $target,
      ): array => $target === $guarded_target_status ? [['code' => 'reason']] : []);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account, $resolver)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(
      ['10000000-0000-4000-8000-000000000200'],
      $payload['data'][0]['status']['allowedNextIds'],
    );
    $this->assertSame(
      [
        '10000000-0000-4000-8000-000000000100',
        '10000000-0000-4000-8000-000000000200',
        '10000000-0000-4000-8000-000000000201',
      ],
      array_column($payload['meta']['workflowStatuses'], 'id'),
    );
  }

  /**
   * Workflow cache entries are separated by the governing form revision.
   *
   * Two applications of one form and status share the entry when the same
   * published revision governs them, and keep separate entries
   * when their own intake revisions govern (a never-published form).
   */
  public function testListSeparatesWorkflowCacheByWorkflowRevision(): void {
    foreach ([[412, 412, 1], [412, 413, 2]] as [$first_revision, $second_revision, $expected_calls]) {
      $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
      $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
      $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
      $status = $this->workflowStatus(100, 'in_review');
      $first = $this->application(
        $jurisdiction,
        $organisation,
        FALSE,
        $status,
        '9a67f32b-93cc-48c5-8899-9d5470f57f41',
      );
      $second = $this->application(
        $jurisdiction,
        $organisation,
        FALSE,
        $status,
        '9a67f32b-93cc-48c5-8899-9d5470f57f42',
      );
      $application_storage = $this->createMock(EntityStorageInterface::class);
      $application_storage->method('getQuery')->willReturn($this->query([41 => 41, 42 => 42], [0, 3]));
      $application_storage->method('loadMultiple')->willReturn([41 => $first, 42 => $second]);
      $manager = $this->entityTypeManager(
        $application_storage,
        $this->groupStorage($jurisdiction, $organisation),
        $this->relationshipStorage([10 => []]),
      );
      $resolver = $this->createMock(EffectiveWorkflowResolver::class);
      $resolver->method('workflowRevisionId')
        ->willReturnCallback(static fn(ApplicationInterface $application): int => $application === $first ? $first_revision : $second_revision);
      $resolver->expects($this->exactly($expected_calls))
        ->method('allowedTargetIds')
        ->willReturn(NULL);
      $resolver->expects($this->exactly($expected_calls))
        ->method('reviewCode')
        ->willReturn(NULL);
      $this->installContainer($account, $manager);

      $response = $this->controller($manager, $account, $resolver)->list(new Request([
        'jurisdiction' => 'musterstadt',
        'organisation' => 'building-department',
        'limit' => '2',
      ]));

      $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }
  }

  /**
   * The review code reaches a list row only when it holds.
   */
  public function testListCarriesReviewCodeOnlyWhenItHolds(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $status = $this->workflowStatus(100, 'in_review', [200]);
    $marked = $this->application($jurisdiction, $organisation, FALSE, $status, '9a67f32b-93cc-48c5-8899-9d5470f57f41');
    $ordinary = $this->application($jurisdiction, $organisation, FALSE, $status, '9a67f32b-93cc-48c5-8899-9d5470f57f42');
    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([41 => 41, 42 => 42], [0, 3]));
    $application_storage->method('loadMultiple')->willReturn([41 => $marked, 42 => $ordinary]);
    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $resolver = $this->createMock(EffectiveWorkflowResolver::class);
    $resolver->method('workflowRevisionId')
      ->willReturnCallback(static fn(ApplicationInterface $application): int => $application === $marked ? 7 : 8);
    // The marked status is not in the form's list, so it has no targets.
    $resolver->method('allowedTargetIds')->willReturn([]);
    $resolver->method('reviewCode')
      ->willReturnCallback(static fn(ApplicationInterface $application): ?string => $application === $marked
        ? EffectiveWorkflowResolver::REVIEW_STATUS_NOT_IN_WORKFLOW
        : NULL);
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account, $resolver)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'organisation' => 'building-department',
      'limit' => '2',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $rows = array_column($payload['data'], 'status', 'id');
    $this->assertSame('status_not_in_workflow', $rows['9a67f32b-93cc-48c5-8899-9d5470f57f41']['reviewCode'] ?? NULL);
    $this->assertSame([], $rows['9a67f32b-93cc-48c5-8899-9d5470f57f41']['allowedNextIds']);
    $this->assertArrayNotHasKey('reviewCode', $rows['9a67f32b-93cc-48c5-8899-9d5470f57f42']);
  }

  /**
   * Jurisdiction admins may use the broad jurisdiction scope.
   */
  public function testListAllowsJurisdictionScopeForJurisdictionAdmin(): void {
    $account = $this->account(31, ['authenticated', 'department_admin'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44], [0, 2]));
    $application_storage->method('loadMultiple')->with([44 => 44])->willReturn([44 => $application]);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction),
      $this->relationshipStorage([10 => ['jur-admin']]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
      'limit' => '1',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('staffApplications', $payload['meta']['resource']);
    $this->assertSame('musterstadt', $payload['data'][0]['workspace']['jurisdiction']['slug']);
    $this->assertSame('citizen-services', $payload['data'][0]['workspace']['organisation']['slug']);
  }

  /**
   * Organisation staff cannot broaden reads to a whole jurisdiction.
   */
  public function testListRejectsJurisdictionScopeForOrgMember(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');

    $manager = $this->entityTypeManager(
      $this->createMock(EntityStorageInterface::class),
      $this->groupStorage($jurisdiction),
      $this->relationshipStorage([10 => ['org-member']]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->list(new Request([
      'jurisdiction' => 'musterstadt',
    ]));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertSame('workspace.forbidden', $payload['error']['code']);
  }

  /**
   * Staff detail redacts protected submitted data without the reveal role.
   */
  public function testDetailRedactsProtectedSubmittedData(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $application_storage->method('load')->with(44)->willReturn($application);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->detail(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request([
        'jurisdiction' => 'musterstadt',
        'organisation' => 'building-department',
      ]),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertTrue($payload['meta']['protectedDataRedacted']);
    $this->assertSame('ada@example.org', $payload['data']['applicant']['email']);
    $this->assertSame('de', $payload['data']['workflowAttributes']['submissionLocale']);
    $this->assertArrayNotHasKey('editorDraft', $payload['data']['workflowAttributes']);
    $this->assertSame('Ada Lovelace', $payload['data']['submittedData']['full_name']);
    $this->assertSame(
      [ApplicationDataProtectionService::REDACTED_MARKER => TRUE],
      $payload['data']['submittedData']['evidence'],
    );
  }

  /**
   * Staff detail hides applicant linkage for sensitive non-file facts.
   */
  public function testDetailHidesProtectedIdentityLinkage(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation, TRUE);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $application_storage->method('load')->with(44)->willReturn($application);

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->detail(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request([
        'jurisdiction' => 'musterstadt',
        'organisation' => 'building-department',
      ]),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $this->assertTrue($payload['meta']['protectedDataRedacted']);
    $this->assertArrayNotHasKey('applicant', $payload['data']);
    $this->assertArrayNotHasKey('crmData', $payload['data']);
    $this->assertSame(
      [ApplicationDataProtectionService::REDACTED_MARKER => TRUE],
      $payload['data']['submittedData']['full_name'],
    );
    $this->assertSame(
      [ApplicationDataProtectionService::REDACTED_MARKER => TRUE],
      $payload['data']['submittedData']['diagnosis'],
    );
  }

  /**
   * Staff detail hides applications that are outside the requested scope.
   */
  public function testDetailReturnsNotFoundForHiddenApplication(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($this->query([]));
    $application_storage->expects($this->never())->method('load');

    $manager = $this->entityTypeManager(
      $application_storage,
      $this->groupStorage($jurisdiction, $organisation),
      $this->relationshipStorage([10 => []]),
    );
    $this->installContainer($account, $manager);

    $response = $this->controller($manager, $account)->detail(
      '9a67f32b-93cc-48c5-8899-9d5470f57f44',
      new Request([
        'jurisdiction' => 'musterstadt',
        'organisation' => 'building-department',
      ]),
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('application.not_found', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Installs the Drupal service container required by ControllerBase.
   */
  private function installContainer(AccountInterface $account, EntityTypeManagerInterface $manager): void {
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    $container->set('entity_type.manager', $manager);
    \Drupal::setContainer($container);
  }

  /**
   * Detail is read-only and rejects the removed GET acknowledgement.
   */
  public function testDetailNeverMarksEventsSeen(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $storage->method('load')->with(44)->willReturn($application);
    foreach ([NULL, '0', '1', ['1'], 'true'] as $mark_seen) {
      $manager = $this->entityTypeManager($storage, $this->groupStorage($jurisdiction, $organisation), $this->relationshipStorage([10 => []]));
      $this->installContainer($account, $manager);
      $messages = $this->createMock(ApplicationEventReadState::class);
      $snapshot = [
        'counts' => ['message' => 1],
        'previousSeenAt' => ['message' => 900],
        'seenAt' => ['message' => 900],
        'newEventIds' => ['message' => ['message-uuid']],
        'newMessageIds' => ['message-uuid'],
      ];
      $valid = $mark_seen === NULL;
      if ($valid) {
        $messages->expects($this->once())->method('detail')->with($application, $account)->willReturn($snapshot);
      }
      else {
        $messages->expects($this->never())->method('detail');
      }
      $query = ['jurisdiction' => 'musterstadt', 'organisation' => 'building-department'];
      if ($mark_seen !== NULL) {
        $query['markMessagesSeen'] = $mark_seen;
      }
      $response = $this->controller($manager, $account, NULL, NULL, $messages)->detail($application->uuid(), new Request($query));
      $payload = json_decode((string) $response->getContent(), TRUE);
      $this->assertSame($valid ? 200 : 400, $response->getStatusCode());
      if ($valid) {
        $this->assertSame($snapshot, $payload['meta']['events']);
      }
    }
  }

  /**
   * Removed GET acknowledgements never mutate; POST bounds event token input.
   */
  public function testEventSeenRejectsStateKindsAndMalformedTokenBodies(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = $this->controller($manager, $this->account(21, ['authenticated', 'case_worker']));
    foreach (['transfer', 'release', 'unknown'] as $kind) {
      $this->assertSame(400, $controller->markEventSeen('unused', $kind, new Request())->getStatusCode());
    }
    foreach (['', '{}', '{"tokens":["bad"]}', '{"tokens":{},"extra":true}'] as $body) {
      $request = new Request([], [], [], [], [], [], $body);
      $this->assertSame(400, $controller->markEventSeen('unused', 'message', $request)->getStatusCode());
    }
  }

  /**
   * POST passes only the selected section tokens after authorized scope lookup.
   */
  public function testEventSeenPostUsesAuthorizedWorkspaceAndRenderedTokens(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $storage->method('load')->with(44)->willReturn($application);
    $manager = $this->entityTypeManager($storage, $this->groupStorage($jurisdiction, $organisation), $this->relationshipStorage([10 => []]));
    $this->installContainer($account, $manager);
    $events = $this->createMock(ApplicationEventReadState::class);
    $events->expects($this->never())->method('detail');
    $tokens = [str_repeat('a', 64)];
    $events->expects($this->once())->method('markSeen')->with($application, $account, 'message', $tokens)->willReturn(['counts' => ['message' => 1]]);
    $request = new Request(['jurisdiction' => 'musterstadt', 'organisation' => 'building-department'], [], [], [], [], [], json_encode(['tokens' => $tokens]));
    $response = $this->controller($manager, $account, NULL, NULL, $events)->markEventSeen($application->uuid(), 'message', $request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['id' => $application->uuid(), 'kind' => 'message'], json_decode($response->getContent(), TRUE)['data']);
  }

  /**
   * Counter GET and seen POST never decrypt or write protected-access rows.
   */
  public function testEventRequestsWriteNoProtectedAccessLog(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], [
      'view published application entities',
      'view protected application data',
    ]);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $application = $this->application($jurisdiction, $organisation);
    $application->expects($this->never())->method('getData');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([44 => 44]));
    $storage->method('load')->with(44)->willReturn($application);
    $manager = $this->entityTypeManager($storage, $this->groupStorage($jurisdiction, $organisation), $this->relationshipStorage([10 => []]));
    $this->installContainer($account, $manager);
    $access_log = $this->createMock(ProtectedAccessLog::class);
    $access_log->expects($this->never())->method('record');
    $protection = new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class), $access_log);
    $events = $this->createMock(ApplicationEventReadState::class);
    $events->expects($this->once())->method('detail')->with($application, $account)->willReturn(['counts' => []]);
    $events->expects($this->once())->method('markSeen')->willReturn(['counts' => []]);
    $controller = $this->controller($manager, $account, NULL, NULL, $events, NULL, $protection);
    $query = ['jurisdiction' => 'musterstadt', 'organisation' => 'building-department'];
    $response = $controller->events($application->uuid(), new Request($query));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['id' => $application->uuid()], json_decode($response->getContent(), TRUE)['data']);
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame(200, $controller->markEventSeen($application->uuid(), 'message', new Request($query, [], [], [], [], [], '{"tokens":[]}'))->getStatusCode());
  }

  /**
   * A staff UID is rate limited before any scope lookup or source scan.
   */
  public function testEventSeenRateLimit(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->expects($this->never())->method('getStorage');
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())->method('isAllowed')->with('beantragt_application.event_seen', 120, 60, '21')->willReturn(FALSE);
    $flood->expects($this->never())->method('register');
    $controller = $this->controller($manager, $account, NULL, NULL, NULL, $flood);
    $response = $controller->markEventSeen('unused', 'message', new Request([], [], [], [], [], [], '{"tokens":[]}'));
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('60', $response->headers->get('Retry-After'));
  }

  /**
   * A UUID outside the authorized page cannot receive an acknowledgement.
   */
  public function testEventSeenPostRejectsForeignWorkspaceApplication(): void {
    $account = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->group(4, 'org', 'building-department', 'Bauamt', $jurisdiction);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query([]));
    $storage->expects($this->never())->method('load');
    $manager = $this->entityTypeManager($storage, $this->groupStorage($jurisdiction, $organisation), $this->relationshipStorage([10 => []]));
    $this->installContainer($account, $manager);
    $events = $this->createMock(ApplicationEventReadState::class);
    $events->expects($this->never())->method('markSeen');
    $request = new Request(['jurisdiction' => 'musterstadt', 'organisation' => 'building-department'], [], [], [], [], [], '{"tokens":[]}');
    $response = $this->controller($manager, $account, NULL, NULL, $events)->markEventSeen('foreign', 'message', $request);
    $this->assertSame(404, $response->getStatusCode());
    $response = $this->controller($manager, $account, NULL, NULL, $events)->events('foreign', $request);
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Internal counters retain authentication, staff profile and scope checks.
   */
  public function testEventCountersRequireStaffAndExplicitScope(): void {
    foreach ([
      [$this->account(0, ['anonymous']), 401],
      [$this->account(7, ['authenticated'], ['view own application entities']), 403],
      [$this->account(21, ['authenticated', 'case_worker'], ['view published application entities']), 400],
    ] as [$account, $status]) {
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $events = $this->createMock(ApplicationEventReadState::class);
      $events->expects($this->never())->method('detail');
      $this->installContainer($account, $manager);
      $response = $this->controller($manager, $account, NULL, NULL, $events)->events('unused', new Request());
      $this->assertSame($status, $response->getStatusCode());
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
  }

  /**
   * Creates the controller under test.
   */
  private function controller(
    EntityTypeManagerInterface $manager,
    AccountInterface $account,
    ?EffectiveWorkflowResolver $effective_workflow_resolver = NULL,
    ?StaffApplicationStatistics $statistics = NULL,
    ?ApplicationEventReadState $message_read_state = NULL,
    ?FloodInterface $flood = NULL,
    ?ApplicationDataProtectionService $protection = NULL,
  ): StaffApplicationApiController {
    if ($effective_workflow_resolver === NULL) {
      $effective_workflow_resolver = $this->createMock(EffectiveWorkflowResolver::class);
      $effective_workflow_resolver->method('allowedTargetIds')->willReturn(NULL);
      $effective_workflow_resolver->method('transitionAttributes')->willReturn([]);
    }
    if ($flood === NULL) {
      $flood = $this->createMock(FloodInterface::class);
      $flood->method('isAllowed')->willReturn(TRUE);
    }
    return new StaffApplicationApiController(
      $manager,
      $account,
      $protection ?? new ApplicationDataProtectionService($manager, $this->createMock(LoggerInterface::class)),
      $this->tenantScopedEntityQuery($manager),
      $effective_workflow_resolver,
      $statistics ?? $this->createMock(StaffApplicationStatistics::class),
      $message_read_state ?? $this->createMock(ApplicationEventReadState::class),
      $flood,
      $this->createMock(Connection::class),
      $this->createMock(QueryFactoryInterface::class),
      new ApplicationCaseScope($manager),
      new ProtectedDataGate(new ApplicationCaseScope($manager)),
    );
  }

  /**
   * Creates a tenant-scoped query service test double.
   */
  private function tenantScopedEntityQuery(EntityTypeManagerInterface $manager): TenantScopedEntityQuery {
    $service = $this->createMock(TenantScopedEntityQuery::class);
    $service->method('getQuery')
      ->willReturnCallback(
        static fn(string $entity_type_id): QueryInterface => $manager
          ->getStorage($entity_type_id)
          ->getQuery()
          ->accessCheck(FALSE),
      );
    $service->method('applyWorkspaceScope')
      ->willReturnCallback(
        static function (
          QueryInterface $query,
          string $_entity_type_id,
          GroupInterface $jurisdiction,
          ?GroupInterface $organisation = NULL,
        ): void {
          if ($organisation instanceof GroupInterface) {
            $query->condition('field_organisation', (int) $organisation->id());
            return;
          }
          $query->condition('field_jurisdiction', (int) $jurisdiction->id());
        },
      );
    return $service;
  }

  /**
   * Creates an account mock.
   *
   * @param int $uid
   *   User ID.
   * @param string[] $roles
   *   Role IDs.
   * @param string[] $permissions
   *   Permission strings.
   */
  private function account(int $uid, array $roles, array $permissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);
    $account->method('hasPermission')
      ->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $account;
  }

  /**
   * Creates an entity type manager for staff application tests.
   */
  private function entityTypeManager(
    EntityStorageInterface $application_storage,
    EntityStorageInterface $group_storage,
    EntityStorageInterface $relationship_storage,
    ?EntityStorageInterface $form_schema_storage = NULL,
    ?EntityStorageInterface $workflow_status_storage = NULL,
    ?EntityStorageInterface $user_storage = NULL,
  ): EntityTypeManagerInterface {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $entity_type_id): bool => $entity_type_id === 'group_relationship');
    $manager->method('getStorage')
      ->willReturnCallback(
        static fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
          'application' => $application_storage,
          'form_schema' => $form_schema_storage ?? throw new \InvalidArgumentException($entity_type_id),
          'workflow_status' => $workflow_status_storage ?? throw new \InvalidArgumentException($entity_type_id),
          'user' => $user_storage ?? throw new \InvalidArgumentException($entity_type_id),
          'group' => $group_storage,
          'group_relationship' => $relationship_storage,
          default => throw new \InvalidArgumentException($entity_type_id),
        },
      );
    return $manager;
  }

  /**
   * Creates group storage for jurisdiction and optional organisation lookups.
   */
  private function groupStorage(GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $queries = [$this->query([3 => 3])];
    if ($organisation instanceof GroupInterface) {
      $queries[] = $this->query([4 => 4]);
    }
    $storage->method('getQuery')->willReturnCallback(static function () use ($queries): QueryInterface {
      static $index = 0;
      return $queries[$index++ % count($queries)];
    });
    $storage->method('load')
      ->willReturnCallback(static fn(int|string $id): ?GroupInterface => match ((int) $id) {
        3 => $jurisdiction,
        4 => $organisation,
        default => NULL,
      });
    $storage->method('loadMultiple')
      ->willReturnCallback(static function (array $ids) use ($jurisdiction, $organisation): array {
        $groups = array_filter([
          3 => $jurisdiction,
          4 => $organisation,
        ]);
        return array_intersect_key($groups, array_flip(array_map('intval', $ids)));
      });
    return $storage;
  }

  /**
   * Creates user storage for assignment batch loading.
   */
  private function userStorage(): EntityStorageInterface {
    $assignee = $this->createMock(EntityInterface::class);
    $assignee->method('uuid')->willReturn('20000000-0000-4000-8000-000000000021');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')
      ->willReturnCallback(static fn(array $ids): array => in_array(21, array_map('intval', $ids), TRUE)
        ? [21 => $assignee]
        : []);
    return $storage;
  }

  /**
   * Creates group relationship storage for current-user membership lookups.
   *
   * @param array<int, string[]> $memberships
   *   Relationship IDs mapped to group role IDs.
   */
  private function relationshipStorage(array $memberships): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($this->query(
      array_combine(array_keys($memberships), array_keys($memberships)) ?: [],
    ));
    $storage->method('load')
      ->willReturnCallback(fn(int|string $id): ?GroupMembershipInterface => isset($memberships[(int) $id])
        ? $this->membership($memberships[(int) $id])
        : NULL);
    return $storage;
  }

  /**
   * Creates a group membership relationship mock.
   *
   * @param string[] $role_ids
   *   Group role IDs.
   */
  private function membership(array $role_ids): GroupMembershipInterface {
    $relationship = $this->createMock(GroupMembershipInterface::class);
    $roles = [];
    foreach ($role_ids as $role_id) {
      $role = $this->createMock(GroupRoleInterface::class);
      $role->method('isAdmin')->willReturn(in_array($role_id, ['jur-admin', 'org-admin'], TRUE));
      $roles[$role_id] = $role;
    }
    $relationship->method('getRoles')->with(FALSE)->willReturn($roles);
    return $relationship;
  }

  /**
   * Creates an entity query mock.
   *
   * @param array<int, int> $ids
   *   Query result IDs.
   * @param array{0:int,1:int}|null $expected_range
   *   Optional expected range.
   * @param array<int, array{0:string,1:mixed}>|null $expected_conditions
   *   Optional expected query conditions in call order.
   * @param array<int, array{0:string,1:string}>|null $expected_sorts
   *   Optional expected query sorts in call order.
   */
  private function query(
    array $ids,
    ?array $expected_range = NULL,
    ?array $expected_conditions = NULL,
    ?array $expected_sorts = NULL,
  ): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    if ($expected_sorts === NULL) {
      $query->method('sort')->willReturnSelf();
    }
    else {
      $sort_index = 0;
      $query->expects($this->exactly(count($expected_sorts)))
        ->method('sort')
        ->willReturnCallback(function (
          string $field,
          string $direction = 'ASC',
        ) use (&$sort_index, $expected_sorts, $query): QueryInterface {
          [$expected_field, $expected_direction] = $expected_sorts[$sort_index];
          $sort_index++;
          $this->assertSame($expected_field, $field);
          $this->assertSame($expected_direction, $direction);
          return $query;
        });
    }
    if ($expected_conditions === NULL) {
      $query->method('condition')->willReturnSelf();
    }
    else {
      $condition_index = 0;
      $query->expects($this->exactly(count($expected_conditions)))
        ->method('condition')
        ->willReturnCallback(function (
          string $field,
          mixed $value = NULL,
        ) use (&$condition_index, $expected_conditions, $query): QueryInterface {
          [$expected_field, $expected_value] = $expected_conditions[$condition_index];
          $condition_index++;
          $this->assertSame($expected_field, $field);
          $this->assertSame($expected_value, $value);
          return $query;
        });
    }
    if ($expected_range === NULL) {
      $query->method('range')->willReturnSelf();
    }
    else {
      $query->expects($this->once())
        ->method('range')
        ->with($expected_range[0], $expected_range[1])
        ->willReturnSelf();
    }
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Creates an application mock with schema, status and workspace fields.
   */
  private function application(
    GroupInterface $jurisdiction,
    GroupInterface $organisation,
    bool $protected_identity_linkage = FALSE,
    ?WorkflowStatusInterface $workflow_status = NULL,
    string $application_uuid = '9a67f32b-93cc-48c5-8899-9d5470f57f44',
    int $schema_version = 0,
    bool $has_workflow_status = TRUE,
  ): ApplicationInterface {
    $schema = $this->schema($protected_identity_linkage);
    $status = $workflow_status ?? $this->workflowStatus();
    $assignee = $this->createMock(EntityInterface::class);
    $assignee->method('id')->willReturn(21);
    $assignee->method('uuid')->willReturn('20000000-0000-4000-8000-000000000021');
    $fields = [
      'field_schema' => $this->entityReference($schema),
      'field_workflow_status' => $has_workflow_status ? $this->entityReference($status) : $this->emptyField(),
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      'field_organisation' => $this->entityReference($organisation),
      'field_assigned_to' => $this->entityReference($assignee),
      'field_assigned_team' => $this->entityReference($organisation),
      'field_applicant_email' => $this->fieldValue('ADA@example.org'),
      'field_crm_data' => $this->fieldValue(json_encode([
        'person.full_name' => 'Ada Lovelace',
        'person.email' => 'crm-ada@example.org',
        'person.phone' => '+49 30 23125 123',
        'person.iban' => 'DE02120300000000202051',
      ], JSON_THROW_ON_ERROR)),
      'field_workflow_attributes' => $this->fieldValue(json_encode([
        'submissionLocale' => 'de',
        'editorDraft' => TRUE,
      ], JSON_THROW_ON_ERROR)),
    ];

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(-1);
    $application->method('id')->willReturn(44);
    $application->method('uuid')->willReturn($application_uuid);
    $application->method('getReferenceNumber')->willReturn('MS-BAU-2026-00005');
    $application->method('getCreatedTime')->willReturn(1781431200);
    $application->method('getChangedTime')->willReturn(1781431300);
    $application->method('getData')->willReturn($protected_identity_linkage
      ? '{"full_name":"Ada Lovelace","email":"ada@example.org","diagnosis":"IfSG-Meldung"}'
      : '{"full_name":"Ada Lovelace","evidence":"scan.pdf"}');
    $application->method('getSchemaVersion')->willReturn($schema_version);
    $application->method('access')->willReturnCallback(static fn (string $operation): bool => $operation === 'view' || $operation === 'update');
    $application->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => isset($fields[$field_name]));
    $application->method('get')
      ->willReturnCallback(fn(string $field_name): FieldItemListInterface => $fields[$field_name] ?? $this->emptyField());
    return $application;
  }

  /**
   * Creates a schema entity mock.
   */
  private function schema(bool $protected_identity_linkage = FALSE): FormSchemaInterface {
    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('uuid')->willReturn('a25564c2-b6a4-4c7b-83b3-8e6e31fb3602');
    $schema->method('label')->willReturn('Bauantrag');
    $schema->method('getVersion')->willReturn(1);
    $fields = $protected_identity_linkage
      ? [
        ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'crm_key' => 'person.full_name'],
        ['id' => 'email', 'type' => 'email', 'label' => 'E-Mail', 'crm_key' => 'person.email'],
        ['id' => 'diagnosis', 'type' => 'text', 'label' => 'Diagnose', 'sensitive' => TRUE],
      ]
      : [
        ['id' => 'full_name', 'type' => 'text', 'label' => 'Name'],
        ['id' => 'evidence', 'type' => 'file', 'label' => 'Nachweis', 'sensitive' => TRUE],
      ];
    $schema->method('getSchema')->willReturn(json_encode([
      'sections' => [
        [
          'fields' => $fields,
        ],
      ],
    ], JSON_THROW_ON_ERROR));
    return $schema;
  }

  /**
   * A visible application cannot reveal a foreign status through its timeline.
   */
  public function testTimelineDoesNotRevealAnInaccessibleReferencedStatus(): void {
    $viewer = $this->account(21, ['authenticated', 'case_worker'], ['view published application entities']);
    $jurisdiction = $this->group(3, 'jur', 'own-jur', 'Own jurisdiction');
    $organisation = $this->group(4, 'org', 'own-org', 'Own organisation', $jurisdiction);
    $status = $this->workflowStatus(200, 'foreign_secret', group_id: 999, can_view: FALSE);
    $status->expects($this->never())->method('getMachineName');
    $application = $this->application($jurisdiction, $organisation, FALSE, $status);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([1 => 44]);
    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')->with(1)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $controller = $this->controller($manager, $viewer);
    // The timeline offers an alter hook to other modules.
    $container = new ContainerBuilder();
    $container->set('module_handler', $this->createMock(ModuleHandlerInterface::class));
    \Drupal::setContainer($container);
    $timeline = (new \ReflectionMethod($controller, 'buildTimeline'))->invoke($controller, $application);
    $this->assertSame('unknown', $timeline[0]['status']);
    $json = json_encode($timeline, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('foreign_secret', $json);
    $this->assertStringNotContainsString('In Pruefung', $json);
    $this->assertStringNotContainsString('10000000-', $json);
  }

  /**
   * Creates a workflow status entity mock.
   */
  private function workflowStatus(
    int $id = 100,
    string $machine_name = 'in_review',
    array $allowed_next_ids = [],
    int $group_id = 4,
    bool $can_view = TRUE,
  ): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('id')->willReturn($id);
    $status->method('access')->with('view', $this->anything())->willReturn($can_view);
    $status->method('uuid')->willReturn(sprintf('10000000-0000-4000-8000-%012d', $id));
    $status->method('label')->willReturn('In Pruefung');
    $status->method('getMachineName')->willReturn($machine_name);
    $status->method('getColor')->willReturn('blue');
    $status->method('getIcon')->willReturn('hourglass');
    $status->method('getWeight')->willReturn(10);
    $status->method('getSemanticFlags')->willReturn([]);
    $status->method('getGroupId')->willReturn($group_id);
    $status->method('getAllowedNextIds')->willReturn($allowed_next_ids);
    return $status;
  }

  /**
   * Creates a group entity mock.
   */
  private function group(
    int $id,
    string $bundle,
    string $slug,
    string $label,
    ?GroupInterface $jurisdiction = NULL,
  ): GroupInterface {
    $fields = [
      'field_slug' => $this->fieldValue($slug),
    ];
    if ($jurisdiction instanceof GroupInterface) {
      $fields['field_jurisdiction'] = $this->entityReference($jurisdiction);
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('uuid')->willReturn(sprintf('00000000-0000-0000-0000-00000000000%d', $id));
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => isset($fields[$field_name]));
    $group->method('get')
      ->willReturnCallback(fn(string $field_name): FieldItemListInterface => $fields[$field_name] ?? $this->emptyField());
    return $group;
  }

  /**
   * Builds an entity reference field list.
   */
  private function entityReference(object $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn(string $property): bool => in_array($property, ['entity', 'target_id'], TRUE),
    );
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => method_exists($entity, 'id') ? $entity->id() : NULL,
        default => NULL,
      },
    );
    return $field;
  }

  /**
   * Builds a scalar field list.
   */
  private function fieldValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === NULL || $value === '');
    $field->method('getValue')->willReturn([['value' => $value]]);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createStub(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    $field->method('getValue')->willReturn([]);
    $field->method('__get')->willReturn(NULL);
    return $field;
  }

}
