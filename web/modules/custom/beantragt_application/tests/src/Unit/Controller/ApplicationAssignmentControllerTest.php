<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\beantragt_comment\Entity\ApplicationCommentInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\OrgHierarchyResolverInterface;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_notification\Service\NotificationOutcomeCollector;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Tests scoped administrative application assignment.
 */
#[CoversClass(ApplicationAssignmentController::class)]
#[Group('beantragt_application')]
final class ApplicationAssignmentControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    // GroupMembershipTrait::loadByUser() reaches this static cache when the
    // candidates() path resolves memberships; a miss-only backend suffices.
    $membership_cache = $this->createMock(CacheBackendInterface::class);
    $membership_cache->method('get')->willReturn(FALSE);
    $container->set('cache.group_memberships_chained', $membership_cache);
    \Drupal::setContainer($container);
  }

  /**
   * Assignment notes are bounded before any application mutation.
   */
  public function testAssignmentRejectsOverlongNote(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(51);
    $application->expects($this->never())->method('save');
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );
    $this->expectException(UnprocessableEntityHttpException::class);
    $this->expectExceptionMessage('Assignment note must not exceed 2000 characters.');
    $controller->assign($application, new Request([], [], [], [], [], [], json_encode([
      'expected_changed' => 1779780300,
      'expected_revision_id' => 51,
      'note' => str_repeat('ä', 2001),
    ], JSON_THROW_ON_ERROR)));
  }

  /**
   * The assignment marker is an internal staff note with the selected target.
   */
  public function testAssignmentAuditNoteKeepsTargetAndVisibility(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(99);
    $target = $this->createMock(UserInterface::class);
    $target->method('getDisplayName')->willReturn("Bob\nExample");
    $target->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->expects($this->once())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('create')->with($this->callback(
      static fn(array $values): bool => $values['field_visibility'] === 'internal'
        && $values['field_author_role'] === 'staff'
        && $values['field_application'] === 99
        && str_starts_with($values['field_body'], "beantragt_application:assignment\n")
        && str_contains($values['field_body'], 'Assigned to: Bob Example')
        && str_contains($values['field_body'], 'Assigned by: Vera Verwaltung (uid: 23)')
        && str_ends_with($values['field_body'], 'Note: Please review.'),
    ))->willReturn($comment);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application_comment')->willReturn($storage);
    $controller = new ApplicationAssignmentController($this->account(), $this->createMock(TimeInterface::class), $manager, new ApplicationCaseScope($manager));
    $this->invokePrivate($controller, 'createAssignmentAuditComment', [$application, $target, 'Please review.']);
  }

  /**
   * An empty target label still produces a readable assignment marker.
   */
  public function testAssignmentAuditNoteFallsBackToUserId(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(99);
    $target = $this->createMock(UserInterface::class);
    $target->method('getDisplayName')->willReturn('');
    $target->method('getEmail')->willReturn('');
    $target->method('id')->willReturn(8);
    $target->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->expects($this->once())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('create')->with($this->callback(
      static fn(array $values): bool => $values['field_visibility'] === 'internal'
        && $values['field_author_role'] === 'staff'
        && $values['field_application'] === 99
        && str_starts_with($values['field_body'], "beantragt_application:assignment\n")
        && str_contains($values['field_body'], 'Assigned to: User 8')
        && str_contains($values['field_body'], 'Assigned by: Vera Verwaltung (uid: 23)')
        && str_ends_with($values['field_body'], 'Note: Please review.'),
    ))->willReturn($comment);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application_comment')->willReturn($storage);
    $controller = new ApplicationAssignmentController($this->account(), $this->createMock(TimeInterface::class), $manager, new ApplicationCaseScope($manager));
    $this->invokePrivate($controller, 'createAssignmentAuditComment', [$application, $target, 'Please review.']);
  }

  /**
   * A note failure prevents the assignment save and its notification hook.
   */
  public function testAssignmentNoteFailureDoesNotSaveAssignment(): void {
    $this->stubEmptyGroupMemberships();
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Test city');
    $team = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Target team', $jurisdiction);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([8]);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('getQuery')->willReturn($query);
    $groups->method('load')->willReturn($team);
    $groups->method('loadMultiple')->willReturn([8 => $team]);
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->method('save')->willThrowException(new EntityStorageException('Note storage unavailable'));
    $comments = $this->createMock(EntityStorageInterface::class);
    $comments->expects($this->once())->method('create')->willReturn($comment);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(static fn(string $type) => $type === 'group' ? $groups : $comments);
    $application = $this->applicationForDelegationValidation($jurisdiction);
    $application->method('id')->willReturn(99);
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->never())->method('save');
    $controller = new ApplicationAssignmentController($this->account(), $this->createMock(TimeInterface::class), $manager, new ApplicationCaseScope($manager));
    $this->expectExceptionMessage('Note storage unavailable');
    $controller->assign($application, new Request([], [], [], [], [], [], json_encode([
      'expected_changed' => 1779780300,
      'expected_revision_id' => 51,
      'team_uuid' => $team->uuid(),
      'note' => 'Please review.',
    ], JSON_THROW_ON_ERROR)));
  }

  /**
   * Stale assignment writes are rejected before mutating the application.
   */
  public function testAssignRejectsStaleChangedTimestamp(): void {
    $account = $this->account();
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $account,
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->expectException(ConflictHttpException::class);
    $controller->assign(
      $application,
      new Request([], [], [], [], [], [], '{"assignee_uuid":null,"expected_changed":1779780200}'),
    );
  }

  /**
   * Malformed changed timestamps are rejected as bad requests.
   */
  public function testAssignRejectsInvalidChangedTimestamp(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->expectException(BadRequestHttpException::class);
    $controller->assign(
      $application,
      new Request([], [], [], [], [], [], '{"assignee_uuid":null,"expected_changed":"not-a-date"}'),
    );
  }

  /**
   * Stale assignment revision IDs are rejected before mutating the application.
   */
  public function testAssignRejectsStaleRevisionId(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(52);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->expectException(ConflictHttpException::class);
    $controller->assign(
      $application,
      new Request([], [], [], [], [], [], '{"assignee_uuid":null,"expected_changed":1779780300,"expected_revision_id":51}'),
    );
  }

  /**
   * Missing assignment revision IDs are rejected before application mutation.
   */
  public function testAssignRejectsMissingRevisionId(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    try {
      $controller->assign(
        $application,
        new Request([], [], [], [], [], [], '{"assignee_uuid":null,"expected_changed":1779780300}'),
      );
    }
    catch (HttpException $exception) {
      $this->assertSame(428, $exception->getStatusCode());
      return;
    }

    $this->fail('Missing revision id should have thrown.');
  }

  /**
   * Unassigning an application creates an auditable revision.
   */
  public function testAssignClearsAssigneeWithRevisionMetadata(): void {
    $account = $this->account();
    $time = $this->createMock(TimeInterface::class);
    $time->expects($this->once())
      ->method('getRequestTime')
      ->willReturn(1779780400);

    $assignment_field = $this->createMock(FieldItemListInterface::class);
    $assignment_field->method('isEmpty')->willReturn(TRUE);

    $collector = new NotificationOutcomeCollector();
    $collector->record([
      'key' => 'delegation_alert',
      'recipient_kind' => 'org_mailbox',
      'status' => 'failed',
    ]);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->once())->method('setNewRevision')->with(TRUE)->willReturnSelf();
    $application->expects($this->once())->method('setRevisionUserId')->with(23)->willReturnSelf();
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1779780400)->willReturnSelf();
    $application->expects($this->never())->method('setRevisionLogMessage');
    $application->expects($this->once())
      ->method('set')
      ->with('field_assigned_to', NULL)
      ->willReturnSelf();
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())
      ->method('save')
      ->willReturnCallback(function () use ($collector): void {
        $collector->record([
          'key' => 'assignment_alert',
          'recipient_kind' => NULL,
          'status' => 'skipped_no_recipient',
        ]);
      });
    $application->method('getRevisionId')->willReturn(51);
    $application->method('hasField')
      ->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_assigned_to');
    $application->method('get')
      ->with('field_assigned_to')
      ->willReturn($assignment_field);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $account,
      $time,
      $manager,
      new ApplicationCaseScope($manager),
      notificationOutcomeCollector: $collector,
    );

    $response = $controller->assign(
      $application,
      new Request([], [], [], [], [], [], '{"assignee_uuid":null,"expected_changed":1779780300,"expected_revision_id":51}'),
    );

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      [
        'success' => TRUE,
        'revision_id' => 51,
        'assignee' => NULL,
        'notifications' => [
          [
            'key' => 'assignment_alert',
            'recipient_kind' => NULL,
            'status' => 'skipped_no_recipient',
          ],
        ],
      ],
      json_decode((string) $response->getContent(), TRUE),
    );
    $this->assertStringNotContainsString('@', (string) $response->getContent());
  }

  /**
   * A held case lock refuses assignment and delegation with 423, not a 500.
   */
  public function testAssignAndDelegateAnswerLockedWhileTheCaseIsHeld(): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);
    $assignment_field = $this->createMock(FieldItemListInterface::class);
    $assignment_field->method('isEmpty')->willReturn(TRUE);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(51);
    $application->method('hasField')->willReturnCallback(static fn (string $field_name): bool => $field_name === 'field_assigned_to');
    $application->method('get')->with('field_assigned_to')->willReturn($assignment_field);
    $application->method('validate')->willReturn(new ConstraintViolationList());
    // Entity storage wraps the presave lock failure.
    $application->expects($this->once())->method('save')->willThrowException(new EntityStorageException('Locked.', 0,
      new ApplicationDeletionLockedException('The application is currently being deleted or delivered.')));
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController($this->account(), $time, $manager, new ApplicationCaseScope($manager));
    try {
      $controller->assign($application, new Request([], [], [], [], [], [], '{"assignee_uuid":null,"expected_changed":1779780300,"expected_revision_id":51}'));
      $this->fail('A held case lock must refuse the assignment.');
    }
    catch (ApplicationLockedHttpException $exception) {
      $this->assertSame(423, $exception->getStatusCode());
    }

    // The delegation audit comment takes the same lock; entity storage wraps
    // its presave failure as well.
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(99);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(51);
    $application->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'field_organisation');
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => $field === 'field_organisation'
      ? $this->fieldList(['entity' => $source_org, 'target_id' => 5])
      : $this->fieldList([], TRUE));
    $application->method('set')->willReturnSelf();
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->expects($this->once())->method('save')->willThrowException(new EntityStorageException('Locked.', 0,
      new ApplicationDeletionLockedException('The related application is currently being deleted or delivered.')));
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->never())->method('sendStaffNotification');
    $manager = $this->entityTypeManagerForDelegation($target_org, $comment);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $time,
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $mailer,
      NULL,
      $this->configServiceWithDelegationSection([]),
    );
    $this->expectException(ApplicationLockedHttpException::class);
    $controller->delegate($application, new Request([], [], [], [], [], [], json_encode([
      'target_org_uuid' => '33333333-3333-4333-8333-333333333333',
      'note' => 'Bitte uebernehmen.',
      'expected_changed' => 1779780300,
      'expected_revision_id' => 51,
    ], JSON_THROW_ON_ERROR)));
  }

  /**
   * Delegating an application moves the org scope and records the change.
   */
  public function testDelegateMovesOrganisationAndCreatesAuditTrail(): void {
    $account = $this->account();
    $time = $this->createMock(TimeInterface::class);
    $time->expects($this->once())
      ->method('getRequestTime')
      ->willReturn(1779780400);

    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(99);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(51);
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, [
        'field_organisation',
        'field_assigned_to',
        'field_assigned_team',
      ], TRUE),
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_organisation' => $this->fieldList(['entity' => $source_org, 'target_id' => 5]),
      default => $this->fieldList([], TRUE),
    });
    $application->expects($this->once())->method('setNewRevision')->with(TRUE)->willReturnSelf();
    $application->expects($this->once())->method('setRevisionUserId')->with(23)->willReturnSelf();
    $application->expects($this->once())->method('setRevisionCreationTime')->with(1779780400)->willReturnSelf();
    $application->expects($this->once())
      ->method('setRevisionLogMessage')
      ->with('Bitte uebernehmen.')
      ->willReturnSelf();
    $application->expects($this->exactly(3))
      ->method('set')
      ->willReturnCallback(function (string $field, mixed $value) use ($application): ApplicationInterface {
        match ($field) {
          'field_organisation' => $this->assertSame(['target_id' => 8], $value),
          'field_assigned_to', 'field_assigned_team' => $this->assertNull($value),
          default => $this->fail('Unexpected field mutation: ' . $field),
        };
        return $application;
      });
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->expects($this->once())->method('save');
    $manager = $this->entityTypeManagerForDelegation($target_org, $comment);

    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendStaffNotification')
      ->with(
        $this->identicalTo($application),
        'delegation_alert',
        [
          '@source_org' => 'Personalamt',
          '@target_org' => 'Bauamt',
          '@note' => 'Interne Notiz im Vorgang vorhanden.',
        ],
      )
      ->willReturnCallback(function () use ($collector): bool {
        $collector->record([
          'key' => 'delegation_alert',
          'recipient_kind' => 'org_mailbox',
          'status' => 'sent',
        ]);
        return TRUE;
      });

    $controller = new ApplicationAssignmentController(
      $account,
      $time,
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $mailer,
      NULL,
      $this->configServiceWithDelegationSection(['noteRequired' => TRUE]),
      $collector,
    );

    $response = $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], json_encode([
        'target_org_uuid' => '33333333-3333-4333-8333-333333333333',
        'note' => 'Bitte uebernehmen.',
        'expected_changed' => 1779780300,
        'expected_revision_id' => 51,
      ], JSON_THROW_ON_ERROR)),
    );

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      [
        'success' => TRUE,
        'revision_id' => 51,
        'target_org' => [
          'uuid' => '33333333-3333-4333-8333-333333333333',
          'label' => 'Bauamt',
        ],
        'notifications' => [
          [
            'key' => 'delegation_alert',
            'recipient_kind' => 'org_mailbox',
            'status' => 'sent',
          ],
        ],
      ],
      json_decode((string) $response->getContent(), TRUE),
    );
    $this->assertStringNotContainsString('@', (string) $response->getContent());
  }

  /**
   * Delegation rejects an empty note when the jurisdiction requires one.
   */
  public function testDelegateRejectsEmptyRequiredNote(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction);

    $application = $this->applicationForDelegationValidation($source_org);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->entityTypeManagerForDelegation($target_org);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $this->createMock(NotificationMailer::class),
      NULL,
      $this->configServiceWithDelegationSection(['noteRequired' => TRUE]),
    );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Delegation note is required.');
    $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], json_encode([
        'target_org_uuid' => '33333333-3333-4333-8333-333333333333',
        'note' => '<strong> </strong>',
        'expected_changed' => 1779780300,
        'expected_revision_id' => 51,
      ], JSON_THROW_ON_ERROR)),
    );
  }

  /**
   * Extra keys in the delegation section never disable an active requirement.
   */
  public function testDelegateStillRequiresNoteWhenSectionHasExtraKeys(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction);

    $application = $this->applicationForDelegationValidation($source_org);
    $application->expects($this->never())->method('save');

    $manager = $this->entityTypeManagerForDelegation($target_org);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $this->createMock(NotificationMailer::class),
      NULL,
      $this->configServiceWithDelegationSection(['noteRequired' => TRUE, 'futureKey' => 'x']),
    );

    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('Delegation note is required.');
    $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], json_encode([
        'target_org_uuid' => '33333333-3333-4333-8333-333333333333',
        'note' => '',
        'expected_changed' => 1779780300,
        'expected_revision_id' => 51,
      ], JSON_THROW_ON_ERROR)),
    );
  }

  /**
   * Delegation accepts an empty note while the jurisdiction flag is optional.
   */
  #[DataProvider('optionalDelegationNoteConfigProvider')]
  public function testDelegateAllowsEmptyNoteWhenRequirementMissingOrFalse(array $delegation_config): void {
    $response = $this->delegateWithEmptyNote(
      $this->configServiceWithDelegationSection($delegation_config),
    );

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      [
        'success' => TRUE,
        'revision_id' => 51,
        'target_org' => [
          'uuid' => '33333333-3333-4333-8333-333333333333',
          'label' => 'Bauamt',
        ],
        'notifications' => [],
      ],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Invalid jurisdiction config falls back to optional notes.
   */
  public function testDelegateAllowsEmptyNoteWhenJurisdictionConfigIsInvalid(): void {
    $response = $this->delegateWithEmptyNote(
      $this->configServiceWithDelegationSection(['noteRequired' => 'true']),
    );

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Provides optional delegation note config sections.
   *
   * @return array<string, array{array}>
   *   Optional delegation config sections.
   */
  public static function optionalDelegationNoteConfigProvider(): array {
    return [
      'missing flag' => [[]],
      'false flag' => [['noteRequired' => FALSE]],
    ];
  }

  /**
   * Broken jurisdiction config falls back to optional notes.
   */
  public function testDelegateAllowsEmptyNoteWhenJurisdictionConfigIsBroken(): void {
    $config = $this->createMock(JurisdictionConfigServiceInterface::class);
    $config->method('getSection')->willThrowException(new \RuntimeException('broken config'));

    $response = $this->delegateWithEmptyNote($config);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Delegation rejects a target org from another jurisdiction.
   */
  public function testDelegateRejectsForeignOrganisation(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $foreign_jurisdiction = $this->group(9, '99999999-9999-4999-8999-999999999999', 'jur', 'Stadt Anderswo');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $foreign_jurisdiction);

    $application = $this->applicationForDelegationValidation($source_org);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->entityTypeManagerForDelegation($target_org);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $this->createMock(NotificationMailer::class),
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], '{"target_org_uuid":"33333333-3333-4333-8333-333333333333","expected_changed":1779780300,"expected_revision_id":51}'),
    );
  }

  /**
   * Delegation rejects the current organisation as target.
   */
  public function testDelegateRejectsCurrentOrganisationTarget(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);

    $application = $this->applicationForDelegationValidation($source_org);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->entityTypeManagerForDelegation($source_org);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $this->createMock(NotificationMailer::class),
    );

    $this->expectException(UnprocessableEntityHttpException::class);
    $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], '{"target_org_uuid":"22222222-2222-4222-8222-222222222222","expected_changed":1779780300,"expected_revision_id":51}'),
    );
  }

  /**
   * Stale delegation writes are rejected before mutating the application.
   */
  public function testDelegateRejectsStaleChangedTimestamp(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->expects($this->never())->method('setNewRevision');
    $application->expects($this->never())->method('set');
    $application->expects($this->never())->method('save');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->expectException(ConflictHttpException::class);
    $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], '{"target_org_uuid":"33333333-3333-4333-8333-333333333333","expected_changed":1779780200}'),
    );
  }

  /**
   * Delegation target projections include the optional org slug.
   */
  public function testDelegationTargetsIncludeOrgSlugWhenAvailable(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_with_slug = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction, ' bauamt ');
    $target_empty_slug = $this->group(9, '44444444-4444-4444-8444-444444444444', 'org', 'Ordnungsamt', $jurisdiction, NULL, TRUE);
    $target_missing_slug = $this->group(10, '55555555-5555-4555-8555-555555555555', 'org', 'Zulassungsstelle', $jurisdiction);

    $application = $this->applicationForDelegationValidation($source_org);
    $manager = $this->entityTypeManagerForDelegationTargets([
      $source_org,
      $target_with_slug,
      $target_empty_slug,
      $target_missing_slug,
    ]);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $targets = $this->invokePrivate($controller, 'delegationTargetMap', [$application]);

    $this->assertSame(
      [
        '33333333-3333-4333-8333-333333333333' => [
          'uuid' => '33333333-3333-4333-8333-333333333333',
          'label' => 'Bauamt',
          'slug' => 'bauamt',
          'parent_org_uuid' => NULL,
        ],
        '44444444-4444-4444-8444-444444444444' => [
          'uuid' => '44444444-4444-4444-8444-444444444444',
          'label' => 'Ordnungsamt',
          'slug' => NULL,
          'parent_org_uuid' => NULL,
        ],
        '55555555-5555-4555-8555-555555555555' => [
          'uuid' => '55555555-5555-4555-8555-555555555555',
          'label' => 'Zulassungsstelle',
          'slug' => NULL,
          'parent_org_uuid' => NULL,
        ],
      ],
      $targets,
    );
  }

  /**
   * Team candidate projections include org catalog join fields.
   */
  public function testTeamCandidatesIncludeOrgSlugAndParentUuid(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction, ' personalamt ');
    $child_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction, 'bauamt');

    $resolver = $this->createMock(OrgHierarchyResolverInterface::class);
    $resolver->method('getDescendantIds')
      ->with(5)
      ->willReturn([5, 8]);
    $resolver->method('getAncestorIds')
      ->willReturnCallback(static fn (int $group_id): array => $group_id === 8 ? [5] : []);

    $manager = $this->entityTypeManagerForDelegationTargets([$source_org, $child_org]);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $resolver,
    );

    $teams = $this->invokePrivate($controller, 'teamCandidateMap', [$this->applicationForDelegationValidation($source_org)]);

    $this->assertSame(
      [
        '33333333-3333-4333-8333-333333333333' => [
          'type' => 'team',
          'id' => '33333333-3333-4333-8333-333333333333',
          'label' => 'Bauamt',
          'slug' => 'bauamt',
          'parent_org_uuid' => '22222222-2222-4222-8222-222222222222',
        ],
        '22222222-2222-4222-8222-222222222222' => [
          'type' => 'team',
          'id' => '22222222-2222-4222-8222-222222222222',
          'label' => 'Personalamt',
          'slug' => 'personalamt',
          'parent_org_uuid' => NULL,
        ],
      ],
      $teams,
    );
  }

  /**
   * Responsibility projections expose the current organisation scope.
   */
  public function testResponsibilityProjectionUsesCurrentOrgScope(): void {
    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $application = $this->applicationForDelegationValidation($source_org);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->assertSame(
      [
        'label' => 'Personalamt',
        'kind' => 'org',
      ],
      $this->invokePrivate($controller, 'responsibilityProjection', [$application]),
    );
  }

  /**
   * Only active case-working persons can receive a personal assignment.
   */
  #[DataProvider('assignableUserProvider')]
  public function testUserCanReceiveAssignmentExcludesSystemAccounts(int $uid, array $roles, bool $active, bool $expected): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn($uid);
    $user->method('getRoles')->willReturn(array_merge(['authenticated'], $roles));
    $user->method('isActive')->willReturn($active);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->assertSame($expected, $this->invokePrivate($controller, 'userCanReceiveAssignment', [$user]));
  }

  /**
   * Accounts and whether they are assignable persons.
   *
   * @return array<string, array{int, string[], bool, bool}>
   *   Uid, roles, active flag, expected result.
   */
  public static function assignableUserProvider(): array {
    return [
      'case worker' => [8, ['case_worker'], TRUE, TRUE],
      'department admin' => [3, ['department_admin', 'workspace_inviter'], TRUE, TRUE],
      'blocked case worker' => [8, ['case_worker'], FALSE, FALSE],
      'uid 1' => [1, ['administrator', 'workspace_inviter'], TRUE, FALSE],
      'administrator only' => [16, ['administrator'], TRUE, FALSE],
      'service account' => [2, ['service_account'], TRUE, FALSE],
      'citizen' => [4, [], TRUE, FALSE],
    ];
  }

  /**
   * Responsibility projections are NULL when the application has no scope.
   */
  public function testResponsibilityProjectionReturnsNullWithoutScope(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->willReturn(FALSE);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $this->assertNull($this->invokePrivate($controller, 'responsibilityProjection', [$application]));
  }

  /**
   * Candidate responses expose delegation access for the current user.
   */
  public function testCandidatesExposeCanDelegateWhenCurrentUserCanDelegate(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(['administer application entities', 'assign applications', 'delegate applications']),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $application = $this->applicationWithoutScope();
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('access')->with('update', $this->anything(), TRUE)->willReturn(AccessResult::allowed());
    $response = $controller->candidates($application);

    $this->assertSame(
      [
        'current' => NULL,
        'can_assign' => TRUE,
        'can_delegate' => TRUE,
        'note_required' => FALSE,
        'responsibility' => NULL,
        'personal_assignment_enabled' => TRUE,
        'candidates' => [],
        'teams' => [],
        'delegation_targets' => [],
      ],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Candidate responses deny delegation controls without delegate access.
   */
  public function testCandidatesExposeCanDelegateFalseWithoutDelegatePermission(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(['view workflow assignments']),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $response = $controller->candidates($this->applicationWithoutScope());

    $this->assertSame(
      [
        'current' => NULL,
        'can_assign' => FALSE,
        'can_delegate' => FALSE,
        'note_required' => FALSE,
        'responsibility' => NULL,
        'personal_assignment_enabled' => TRUE,
        'candidates' => [],
        'teams' => [],
        'delegation_targets' => [],
      ],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Candidate responses expose the jurisdiction delegation-note policy.
   */
  #[DataProvider('candidateDelegationNoteConfigProvider')]
  public function testCandidatesExposeDelegationNoteRequiredFromJurisdiction(array $delegation_config, bool $expected): void {
    $this->stubEmptyGroupMemberships();

    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction);

    $manager = $this->entityTypeManagerForDelegationTargets([$source_org, $target_org]);
    $controller = new ApplicationAssignmentController(
      $this->account(['administer application entities']),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      NULL,
      NULL,
      NULL,
      $this->configServiceWithDelegationSection($delegation_config),
    );

    $response = $controller->candidates($this->applicationForDelegationValidation($source_org));
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame($expected, $payload['note_required']);
    $this->assertSame(
      [
        'label' => 'Personalamt',
        'kind' => 'org',
      ],
      $payload['responsibility'],
    );
  }

  /**
   * Provides delegation note config sections for candidates responses.
   *
   * @return array<string, array{array, bool}>
   *   Delegation config sections and expected candidates flag.
   */
  public static function candidateDelegationNoteConfigProvider(): array {
    return [
      'required' => [['noteRequired' => TRUE], TRUE],
      'optional' => [['noteRequired' => FALSE], FALSE],
      'invalid' => [['noteRequired' => 'true'], FALSE],
    ];
  }

  /**
   * Delegation access requires the dedicated permission.
   */
  public function testDelegateAccessDeniesMissingPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $controller = new ApplicationAssignmentController(
      $this->account(),
      $this->createMock(TimeInterface::class),
      $manager,
      new ApplicationCaseScope($manager),
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(-1);

    $result = $controller->delegateAccess($application, $account);

    $this->assertFalse($result->isAllowed());
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Creates a staff account mock.
   *
   * @param string[] $permissions
   *   Permissions granted to the mock account.
   */
  private function account(array $permissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(23);
    $account->method('getDisplayName')->willReturn('Vera Verwaltung');
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

  /**
   * Creates an application mock without jurisdiction or organisation scope.
   */
  private function applicationWithoutScope(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->willReturn(FALSE);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(-1);

    return $application;
  }

  /**
   * Creates an application mock for validation-only delegation tests.
   */
  private function applicationForDelegationValidation(GroupInterface $sourceOrg): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getChangedTime')->willReturn(1779780300);
    $application->method('getRevisionId')->willReturn(51);
    // Unconfigured mocks return NULL; addCacheableDependency() needs arrays.
    $application->method('getCacheContexts')->willReturn([]);
    $application->method('getCacheTags')->willReturn([]);
    $application->method('getCacheMaxAge')->willReturn(-1);
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_organisation',
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_organisation' => $this->fieldList(['entity' => $sourceOrg, 'target_id' => (int) $sourceOrg->id()]),
      default => $this->fieldList([], TRUE),
    });

    return $application;
  }

  /**
   * Performs a successful delegation request with an empty note.
   */
  private function delegateWithEmptyNote(object $jurisdiction_config_service): JsonResponse {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1779780400);

    $jurisdiction = $this->group(7, '11111111-1111-4111-8111-111111111111', 'jur', 'Stadt Musterstadt');
    $source_org = $this->group(5, '22222222-2222-4222-8222-222222222222', 'org', 'Personalamt', $jurisdiction);
    $target_org = $this->group(8, '33333333-3333-4333-8333-333333333333', 'org', 'Bauamt', $jurisdiction);

    $application = $this->applicationForDelegationValidation($source_org);
    $application->method('id')->willReturn(99);
    $application->expects($this->once())->method('setNewRevision')->with(TRUE)->willReturnSelf();
    $application->method('setRevisionUserId')->willReturnSelf();
    $application->method('setRevisionCreationTime')->willReturnSelf();
    $application->method('setRevisionLogMessage')->willReturnSelf();
    $application->expects($this->once())
      ->method('set')
      ->with('field_organisation', ['target_id' => 8])
      ->willReturnSelf();
    $application->method('validate')->willReturn(new ConstraintViolationList());
    $application->expects($this->once())->method('save');

    $comment = $this->createMock(ApplicationCommentInterface::class);
    $comment->expects($this->once())->method('save');
    $manager = $this->entityTypeManagerForDelegation($target_org, $comment, NULL);

    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendStaffNotification')
      ->with(
        $this->identicalTo($application),
        'delegation_alert',
        [
          '@source_org' => 'Personalamt',
          '@target_org' => 'Bauamt',
          '@note' => 'Keine interne Notiz.',
        ],
      )
      ->willReturn(TRUE);

    $controller = new ApplicationAssignmentController(
      $this->account(),
      $time,
      $manager,
      new ApplicationCaseScope($manager),
      NULL,
      $this->createMock(OrgHierarchyResolverInterface::class),
      $mailer,
      NULL,
      $jurisdiction_config_service,
    );

    return $controller->delegate(
      $application,
      new Request([], [], [], [], [], [], json_encode([
        'target_org_uuid' => '33333333-3333-4333-8333-333333333333',
        'note' => '',
        'expected_changed' => 1779780300,
        'expected_revision_id' => 51,
      ], JSON_THROW_ON_ERROR)),
    );
  }

  /**
   * Creates a jurisdiction config service mock for delegation settings.
   */
  private function configServiceWithDelegationSection(array $section): JurisdictionConfigServiceInterface {
    $service = $this->createMock(JurisdictionConfigServiceInterface::class);
    $service->method('getSection')
      ->with($this->isInstanceOf(GroupInterface::class), 'delegation')
      ->willReturn($section);

    return $service;
  }

  /**
   * Stubs static group membership lookups with no eligible members.
   */
  private function stubEmptyGroupMemberships(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([]);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([]);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);

    \Drupal::getContainer()->set('entity_type.manager', $manager);
    \Drupal::getContainer()->set('group.membership_loader', new class {

      /**
       * Returns no memberships for candidate projections.
       */
      public function loadByGroup(mixed ...$args): array {
        return [];
      }

      /**
       * Returns no single membership for access projections.
       */
      public function loadSingle(mixed ...$args): mixed {
        return NULL;
      }

    });
  }

  /**
   * Creates an entity type manager for delegation target list storage.
   *
   * @param \Drupal\group\Entity\GroupInterface[] $groups
   *   Groups returned by the jurisdiction target query.
   */
  private function entityTypeManagerForDelegationTargets(array $groups): EntityTypeManagerInterface {
    $groups_by_id = [];
    foreach ($groups as $group) {
      $groups_by_id[(int) $group->id()] = $group;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($groups_by_id));

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('getQuery')->willReturn($query);
    $group_storage->method('loadMultiple')
      ->willReturnCallback(static function (array $ids) use ($groups_by_id): array {
        $loaded = [];
        foreach ($ids as $id) {
          $id = (int) $id;
          if (isset($groups_by_id[$id])) {
            $loaded[$id] = $groups_by_id[$id];
          }
        }
        return $loaded;
      });
    $group_storage->method('load')
      ->willReturnCallback(static fn (int|string $id): ?GroupInterface => $groups_by_id[(int) $id] ?? NULL);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('group')->willReturn($group_storage);

    return $manager;
  }

  /**
   * Creates an entity type manager for delegation target and comment storage.
   */
  private function entityTypeManagerForDelegation(
    ?GroupInterface $targetOrg,
    ?ApplicationCommentInterface $comment = NULL,
    ?string $expected_note = 'Bitte uebernehmen.',
  ): EntityTypeManagerInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($targetOrg instanceof GroupInterface ? [(int) $targetOrg->id() => (int) $targetOrg->id()] : []);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('getQuery')->willReturn($query);
    $group_storage->method('load')
      ->willReturnCallback(static fn (int|string $id): ?GroupInterface => $targetOrg instanceof GroupInterface && (int) $id === (int) $targetOrg->id()
        ? $targetOrg
        : NULL);

    $comment_storage = $this->createMock(EntityStorageInterface::class);
    if ($comment instanceof ApplicationCommentInterface) {
      $comment_storage->expects($this->once())
        ->method('create')
        ->with($this->callback(static function (array $values) use ($expected_note): bool {
          $body = (string) ($values['field_body'] ?? '');
          $matches = ($values['field_application'] ?? NULL) === 99
            && ($values['field_author_role'] ?? NULL) === 'staff'
            && ($values['field_visibility'] ?? NULL) === 'internal'
            && ($values['uid'] ?? NULL) === 23
            && str_contains($body, 'beantragt_application:delegation')
            && str_contains($body, 'Source organisation: Personalamt')
            && str_contains($body, 'Target organisation: Bauamt');
          if (!$matches) {
            return FALSE;
          }
          if ($expected_note === NULL) {
            return !str_contains($body, 'Note:');
          }
          return str_contains($body, 'Note: ' . $expected_note);
        }))
        ->willReturn($comment);
    }
    else {
      $comment_storage->expects($this->never())->method('create');
    }

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(
      static fn (string $entity_type): EntityStorageInterface => match ($entity_type) {
        'group' => $group_storage,
        'application_comment' => $comment_storage,
        default => throw new \RuntimeException('Unexpected storage: ' . $entity_type),
      },
    );

    return $manager;
  }

  /**
   * Creates a group mock with optional jurisdiction reference.
   */
  private function group(
    int $id,
    string $uuid,
    string $bundle,
    string $label,
    ?GroupInterface $jurisdiction = NULL,
    ?string $slug = NULL,
    bool $hasSlugField = FALSE,
  ): GroupInterface {
    $has_slug_field = $hasSlugField || $slug !== NULL;
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('uuid')->willReturn($uuid);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);
    $group->method('hasField')->willReturnCallback(
      static fn (string $field): bool => ($field === 'field_jurisdiction' && $jurisdiction instanceof GroupInterface)
        || ($field === 'field_slug' && $has_slug_field),
    );
    $group->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_jurisdiction' => $jurisdiction instanceof GroupInterface
        ? $this->fieldList(['entity' => $jurisdiction, 'target_id' => (int) $jurisdiction->id()])
        : $this->fieldList([], TRUE),
      'field_slug' => $has_slug_field && $slug !== NULL && trim($slug) !== ''
        ? $this->fieldList(['value' => $slug])
        : $this->fieldList([], TRUE),
      default => $this->fieldList([], TRUE),
    });

    return $group;
  }

  /**
   * Creates a field item list mock exposing magic field properties.
   */
  private function fieldList(array $properties, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('getValue')->willReturn($empty ? [] : [$properties]);
    $field->method('__isset')->willReturnCallback(
      static fn (string $name): bool => isset($properties[$name]),
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $name): mixed => $properties[$name] ?? NULL,
    );
    return $field;
  }

  /**
   * Invokes a private controller helper.
   *
   * @param \Drupal\beantragt_application\Controller\ApplicationAssignmentController $controller
   *   Controller under test.
   * @param string $method
   *   Private method name.
   * @param mixed[] $args
   *   Arguments passed to the private method.
   */
  private function invokePrivate(ApplicationAssignmentController $controller, string $method, array $args): mixed {
    $reflection = new \ReflectionMethod($controller, $method);
    $reflection->setAccessible(TRUE);

    return $reflection->invokeArgs($controller, $args);
  }

}
