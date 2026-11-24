<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Access\ApplicationCommentStaffScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationMessageReadState;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves per-person message counts and read boundaries on a real database.
 */
#[Group('beantragt_application')]
final class ApplicationMessageReadStateKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'language', 'beantragt_schema', 'beantragt_workflow',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_roles_api', 'beantragt_comment', 'beantragt_notification',
  ];

  /**
   * Fixed clock that can advance during a test.
   */
  private int $now = 1000;

  /**
   * Service under test.
   */
  private ApplicationMessageReadState $readState;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('application_comment');
    $this->installSchema('beantragt_application', [ApplicationMessageReadState::TABLE]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $this->container->set('datetime.time', $time);
    $this->container->get('state')->set(ApplicationMessageReadState::BASELINE_STATE, 800);
    $this->readState = new ApplicationMessageReadState(
      $this->container->get('database'), $time, $this->container->get('module_handler'),
      new ApplicationCommentStaffScope($this->container->get('entity_type.manager')),
      $this->container->get('state'),
    );
  }

  /**
   * Only published public applicant messages count, even with an empty body.
   */
  public function testApplicantMessagesAndPerPersonReadState(): void {
    $one = $this->message(42, 'citizen', 'public', 900);
    $two = $this->message(42, 'citizen', 'public', 950, '');
    $this->message(42, 'staff', 'public', 990);
    $this->message(42, 'citizen', 'internal', 991);
    $this->message(42, 'citizen', 'public', 992, 'Unpublished', 0);
    $this->message(43, 'citizen', 'public', 993);
    $application = $this->application(42);
    $alice = $this->account(21);
    $bob = $this->account(22);
    $this->assertSame([$application->uuid() => 2], $this->readState->counts([$application], $alice));
    // Background detail reads do not consume the notification.
    $this->assertSame(['count' => 2, 'previousSeenAt' => NULL, 'seenAt' => NULL, 'newMessageIds' => [$one, $two]], $this->readState->detail($application, $alice, FALSE));
    $this->assertSame(['count' => 2, 'previousSeenAt' => NULL, 'seenAt' => 1000, 'newMessageIds' => [$one, $two]], $this->readState->detail($application, $alice, TRUE));
    $this->assertSame([$application->uuid() => 0], $this->readState->counts([$application], $alice));
    $this->assertSame([$application->uuid() => 2], $this->readState->counts([$application], $bob));
    $this->assertSame(1000, $this->readState->detail($application, $alice, FALSE)['previousSeenAt']);
    $this->readState->detail($application, $bob, TRUE);
    $this->assertSame([$application->uuid() => 0], $this->readState->counts([$application], $bob));
    // A reply arriving in the same second stays new for both people.
    $later = $this->message(42, 'citizen', 'public', 1000);
    $this->assertSame([$application->uuid() => 1], $this->readState->counts([$application], $alice));
    $this->assertSame([$later], $this->readState->detail($application, $alice, TRUE)['newMessageIds']);
    $this->assertSame([$application->uuid() => 1], $this->readState->counts([$application], $bob));
    $this->now = 1001;
    $this->readState->detail($application, $bob, TRUE);
    $this->assertSame([$application->uuid() => 0], $this->readState->counts([$application], $bob));
    // Created uses request start time: a slow save may arrive after opening.
    $delayed = $this->message(42, 'citizen', 'public', 999);
    $this->assertSame([$delayed], $this->readState->detail($application, $bob, FALSE)['newMessageIds']);
  }

  /**
   * Unconfirmed applications and users without comment access get no metadata.
   */
  public function testMessageAccessIsNotWidened(): void {
    $this->message(42, 'citizen', 'public', 900);
    $cases = [
      [$this->application(42, FALSE), $this->account(21)],
      [$this->application(42), $this->account(21, FALSE)],
    ];
    foreach ($cases as [$application, $account]) {
      $this->assertSame([$application->uuid() => 0], $this->readState->counts([$application], $account));
      $this->assertSame(['count' => 0, 'previousSeenAt' => NULL, 'seenAt' => NULL, 'newMessageIds' => []], $this->readState->detail($application, $account, TRUE));
    }
    $this->assertSame(0, (int) $this->container->get('database')->select(ApplicationMessageReadState::TABLE)->countQuery()->execute()->fetchField());
  }

  /**
   * Schema update is safe to repeat and read state is removed with its owner.
   */
  public function testUpdateAndCleanup(): void {
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    $this->container->get('database')->schema()->dropTable(ApplicationMessageReadState::TABLE);
    $this->container->get('state')->delete(ApplicationMessageReadState::BASELINE_STATE);
    beantragt_application_update_10032();
    $this->assertSame(1000, $this->container->get('state')->get(ApplicationMessageReadState::BASELINE_STATE));
    $this->now = 1100;
    beantragt_application_update_10032();
    $this->assertSame(1000, $this->container->get('state')->get(ApplicationMessageReadState::BASELINE_STATE));
    $application = $this->application(42);
    $this->message(42, 'citizen', 'public', 900);
    $this->message(42, 'citizen', 'public', 1050);
    $this->readState->detail($application, $this->account(21), TRUE);
    $this->readState->detail($application, $this->account(22), TRUE);
    $this->readState->deleteFor('uid', 21);
    $this->assertSame(1, $this->readState->detail($application, $this->account(21), FALSE)['count']);
    $this->assertSame(0, $this->readState->detail($application, $this->account(22), FALSE)['count']);
    $this->readState->deleteFor('application_id', 42);
    $this->assertSame(1, $this->readState->detail($application, $this->account(22), FALSE)['count']);
  }

  /**
   * A jurisdiction member may view the case but cannot open its messages.
   */
  public function testJurisdictionOnlyRequiresAdminForMessageMetadata(): void {
    $this->installEntitySchema('workflow_status');
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Message scope']);
    $member = $this->createUser(['view application_comment entities']);
    $admin = $this->createUser(['view application_comment entities']);
    $jurisdiction->addMember($member, ['group_roles' => ['jur-member']]);
    $jurisdiction->addMember($admin, ['group_roles' => ['jur-admin']]);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->with('target_id')->willReturn(TRUE);
    $field->method('__get')->with('target_id')->willReturn((int) $jurisdiction->id());
    $application = $this->application(42);
    $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
    $application->method('get')->with('field_jurisdiction')->willReturn($field);
    $message = $this->message(42, 'citizen', 'public', 900);

    $this->assertSame([$application->uuid() => 0], $this->readState->counts([$application], $member));
    $this->assertSame(['count' => 0, 'previousSeenAt' => NULL, 'seenAt' => NULL, 'newMessageIds' => []], $this->readState->detail($application, $member, TRUE));
    $this->assertSame(0, (int) $this->container->get('database')->select(ApplicationMessageReadState::TABLE)->countQuery()->execute()->fetchField());
    $this->assertSame([$application->uuid() => 1], $this->readState->counts([$application], $admin));
    $this->assertSame([$message], $this->readState->detail($application, $admin, TRUE)['newMessageIds']);
  }

  /**
   * No read position means only messages strictly after rollout are new.
   */
  public function testBaselineExcludesHistoricalMessagesOnFirstRead(): void {
    $this->container->get('state')->set(ApplicationMessageReadState::BASELINE_STATE, 950);
    $this->message(42, 'citizen', 'public', 949);
    $this->message(42, 'citizen', 'public', 950);
    $new = $this->message(42, 'citizen', 'public', 951);
    $application = $this->application(42);
    $account = $this->account(21);
    $this->assertSame([$application->uuid() => 1], $this->readState->counts([$application], $account));
    $this->assertSame([$new], $this->readState->detail($application, $account, TRUE)['newMessageIds']);
    // A later rollout baseline must not replace a person's existing position.
    $this->container->get('state')->set(ApplicationMessageReadState::BASELINE_STATE, 2000);
    $later = $this->message(42, 'citizen', 'public', 1000);
    $this->assertSame([$later], $this->readState->detail($application, $account, FALSE)['newMessageIds']);
  }

  /**
   * Code can run before the schema update without breaking lists or detail.
   */
  public function testMissingReadStateTableReturnsEmptyMetadata(): void {
    $this->container->get('database')->schema()->dropTable(ApplicationMessageReadState::TABLE);
    $this->message(42, 'citizen', 'public', 900);
    $application = $this->application(42);
    $this->assertSame([$application->uuid() => 0], $this->readState->counts([$application], $this->account(21)));
    foreach ([FALSE, TRUE] as $mark_seen) {
      $this->assertSame(['count' => 0, 'previousSeenAt' => NULL, 'seenAt' => NULL, 'newMessageIds' => []], $this->readState->detail($application, $this->account(21), $mark_seen));
    }
    $this->readState->deleteFor('uid', 21);
  }

  /**
   * A partially updated schema repairs its key and cleanup survives a window.
   */
  public function testPartialSchemaUpdateAndReceiptCleanup(): void {
    $db = $this->container->get('database');
    $schema = $db->schema();
    $schema->dropPrimaryKey(ApplicationMessageReadState::TABLE);
    $schema->addPrimaryKey(ApplicationMessageReadState::TABLE, ['uid', 'application_id']);
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    beantragt_application_update_10032();
    foreach (['message', 'documents'] as $kind) {
      $db->insert(ApplicationMessageReadState::TABLE)->fields([
        'uid' => 21,
        'application_id' => 42,
        'kind' => $kind,
        'seen_at' => 900,
      ])->execute();
    }
    $receipts = ApplicationEventReadState::RECEIPTS_TABLE;
    $db->insert($receipts)->fields([
      'uid' => 21,
      'application_id' => 42,
      'kind' => 'message',
      'event_key' => str_repeat('a', 64),
      'token' => str_repeat('b', 64),
    ])->execute();
    $schema->dropTable(ApplicationMessageReadState::TABLE);
    $this->readState->deleteFor('uid', 21);
    $this->assertSame(0, (int) $db->select($receipts)->countQuery()->execute()->fetchField());
  }

  /**
   * Writes a comment row with the same fields used by the public API.
   */
  private function message(int $application_id, string $role, string $visibility, int $created, string $body = 'Reply', int $published = 1): string {
    // Seed the real table directly to isolate read-state behavior from mail
    // and application mutation hooks, which are not part of this feature.
    $uuid = $this->container->get('uuid')->generate();
    $this->container->get('database')->insert('application_comment')->fields([
      'uuid' => $uuid,
      'field_application' => $application_id,
      'field_author_role' => $role,
      'field_visibility' => $visibility,
      'field_body' => $body,
      'created' => $created,
      'status' => $published,
      'uid' => 0,
    ])->execute();
    return $uuid;
  }

  /**
   * Application already authorized by the caller.
   */
  private function application(int $id, bool $confirmed = TRUE): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn($id);
    $application->method('uuid')->willReturn('00000000-0000-4000-8000-' . sprintf('%012d', $id));
    $application->method('isEmailConfirmed')->willReturn($confirmed);
    return $application;
  }

  /**
   * Staff account already authorized for the application's scope.
   */
  private function account(int $uid, bool $can_read = TRUE): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => $can_read && in_array($permission, [
      'view application_comment entities',
      'bypass beantragt group scope',
    ], TRUE));
    return $account;
  }

}
