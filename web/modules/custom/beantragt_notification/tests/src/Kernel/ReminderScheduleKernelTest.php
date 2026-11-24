<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_notification\Service\ReminderSchedule;
use Drupal\beantragt_notification\Service\ResubmissionReminderResolver;
use Drupal\beantragt_notification\Service\StaffMailOutcome;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Real indexed scheduling queries with harmless mail and entity doubles.
 */
#[Group('beantragt_notification')]
final class ReminderScheduleKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];
  /**
   * Application doubles keyed by identifier.
   *
   * @var array<int, \Drupal\beantragt_application\Entity\ApplicationInterface>
   */
  private array $applications = [];
  /**
   * Loaded identifier pages.
   *
   * @var array<array<int|string>>
   */
  private array $loaded = [];
  /**
   * Attempted mail deliveries.
   *
   * @var array<array<mixed>>
   */
  private array $sent = [];
  /**
   * Whether the fake transport accepts mail.
   */
  private StaffMailOutcome $mailOutcome = StaffMailOutcome::Sent;

  /**
   * Per-application mail outcomes for mixed tenant queues.
   *
   * @var array<int, \Drupal\beantragt_notification\Service\StaffMailOutcome>
   */
  private array $outcomes = [];

  /**
   * Global reminder template switches.
   *
   * @var array<string, bool>
   */
  private array $enabled = [];
  /**
   * Fixed scheduler clock.
   */
  private int $now = 1800000000;
  /**
   * Mutable workflow policy.
   *
   * @var array<string, int>
   */
  private array $workflow = ['escalation_days' => 7, 'resubmission_days' => 42, 'resubmission_interval_days' => 7];
  /**
   * Actual scheduler under test.
   */
  private ReminderSchedule $schedule;
  /**
   * Captured entity-query conditions.
   *
   * @var array<string, array<mixed>>
   */
  private array $queryConditions = [];
  /**
   * Captured bounded entity-query ranges.
   *
   * @var array<array<int>>
   */
  private array $queryRanges = [];

  /**
   * Injects a concurrent policy edit while a rebuild page is loaded.
   *
   * @var callable|null
   */
  private $onLoad = NULL;

  /**
   * Simulates a concurrent update at lock acquisition.
   *
   * @var callable|null
   */
  private $onAcquire = NULL;

  /**
   * Simulates another writer holding an application lock.
   */
  private bool $lockAvailable = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $loader = require dirname(DRUPAL_ROOT) . '/vendor/autoload.php';
    foreach (['beantragt_notification', 'beantragt_application', 'beantragt_workflow_status', 'beantragt_schema'] as $module) {
      $loader->addPsr4('Drupal\\' . $module . '\\', DRUPAL_ROOT . '/modules/custom/' . $module . '/src');
    }
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_notification/beantragt_notification.module';
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_notification/beantragt_notification.install';
    $this->container->get('database')->schema()->createTable(ReminderSchedule::TABLE, beantragt_notification_schema()[ReminderSchedule::TABLE]);
    $this->container->get('state')->set('beantragt_notification.schedule_rebuild', FALSE);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnCallback(function ($field, $value, $operator = '=') use ($query) {
      $this->queryConditions[$field] = [$value, $operator];
      return $query;
    });
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnCallback(function ($start, $length) use ($query) {
      $this->queryRanges[] = [$start, $length];
      return $query;
    });
    $query->method('execute')->willReturnCallback(function () {
      $after = $this->queryConditions['id'][0] ?? 0;
      $ids = array_filter(array_keys($this->applications), static fn(int $id): bool => $id > $after);
      return array_combine(array_slice($ids, 0, 100), array_slice($ids, 0, 100));
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->willReturnCallback(fn($id) => $this->applications[$id] ?? NULL);
    $storage->method('loadMultiple')->willReturnCallback(function (array $ids): array {
      $this->loaded[] = $ids;
      if ($this->onLoad !== NULL && $ids !== []) {
        ($this->onLoad)();
      }
      return array_intersect_key($this->applications, array_flip($ids));
    });
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $this->container->set('entity_type.manager', $manager);
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(fn(string $key) => $this->enabled[$key] ?? TRUE);
    $workflow = $this->createMock(ImmutableConfig::class);
    $workflow->method('get')->willReturnCallback(fn(string $key) => $this->workflow[$key] ?? NULL);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->willReturnCallback(static fn(string $name) => $name === 'beantragt_workflow.settings' ? $workflow : $settings);
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->method('sendStaffNotificationOutcome')->willReturnCallback(function ($application, string $type): StaffMailOutcome {
      $this->sent[] = [$application->id(), $type];
      return $this->outcomes[(int) $application->id()] ?? $this->mailOutcome;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn() => $this->now);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (string $name): bool {
      if ($this->onAcquire !== NULL && $name !== 'beantragt_notification.reminder_schedule') {
        ($this->onAcquire)();
      }
      return $name === 'beantragt_notification.reminder_schedule' || $this->lockAvailable;
    });
    $this->schedule = new ReminderSchedule($this->container->get('database'), $manager, $this->container->get('state'), $config, new ResubmissionReminderResolver($config, $manager), $mailer, $lock, $time, new NullLogger(), new ApplicationDependentMutationLock($manager, $lock, $this->container->get('database')), $this->container->get('keyvalue'));
  }

  /**
   * No due work means no entity load, even with a 12,000-row backlog.
   */
  public function testLargeIdleBacklogUsesOnlyDueIndex(): void {
    $db = $this->container->get('database');
    for ($start = 1; $start <= 12000; $start += 200) {
      $insert = $db->insert(ReminderSchedule::TABLE)->fields([
        'application_id', 'status_id', 'status_entered', 'escalation_due', 'reminder_due',
      ]);
      for ($id = $start; $id < $start + 200; $id++) {
        $insert->values([$id, 1, $this->now, $this->now + 86400, $this->now + 86400]);
      }
      $insert->execute();
    }
    $this->schedule->run();
    self::assertSame([[], []], $this->loaded);
    self::assertSame([], $this->sent);
  }

  /**
   * A due escalation is recorded only after successful mail acceptance.
   */
  public function testDueEscalationRetriesAndIsNotRepeated(): void {
    $app = $this->application(1, $this->now - 10 * 86400);
    $this->applications[1] = $app;
    $this->schedule->sync($app);
    $this->mailOutcome = StaffMailOutcome::TransportError;
    $this->schedule->run();
    self::assertCount(1, $this->sent);
    self::assertNull($this->container->get('keyvalue')->get('state')->get('beantragt_notification.escalation_sent.1'));
    $this->schedule->run();
    self::assertCount(1, $this->sent);
    $this->now += 301;
    $this->mailOutcome = StaffMailOutcome::Sent;
    $this->schedule->run();
    $this->schedule->run();
    self::assertCount(2, $this->sent);
  }

  /**
   * Sending a later reminder never re-enables a permanently skipped escalation.
   */
  #[DataProvider('permanentSkipOutcomes')]
  public function testReminderPreservesSkippedEscalation(StaffMailOutcome $outcome): void {
    $app = $this->application(1, $this->now - 10 * 86400);
    $this->applications[1] = $app;
    $this->schedule->sync($app);
    $this->mailOutcome = $outcome;
    $this->schedule->run();
    self::assertSame([[1, 'escalation_alert']], $this->sent);
    self::assertNull($this->row(1)['escalation_due']);

    $this->now += 33 * 86400;
    $this->mailOutcome = StaffMailOutcome::Sent;
    $this->schedule->run();
    self::assertSame([[1, 'escalation_alert'], [1, 'resubmission_reminder']], $this->sent);
    self::assertNull($this->row(1)['escalation_due']);
    $this->schedule->run();
    self::assertCount(2, $this->sent);
    self::assertNull($this->row(1)['escalation_due']);
  }

  /**
   * Permanent skips which must survive another mail kind's delivery recheck.
   */
  public static function permanentSkipOutcomes(): array {
    return [
      'disabled' => [StaffMailOutcome::Disabled],
      'no recipient' => [StaffMailOutcome::NoRecipient],
    ];
  }

  /**
   * Edits inside one status do not postpone its escalation clock.
   */
  public function testStatusEntryAndIdleClocksStaySeparate(): void {
    $old = $this->now - 50 * 86400;
    $this->schedule->sync($this->application(1, $old));
    $app = $this->application(1, $this->now - 2 * 86400);
    $this->applications[1] = $app;
    $this->schedule->sync($app);
    $row = $this->row(1);
    self::assertSame($old, (int) $row['status_entered']);
    self::assertSame($old + 7 * 86400, (int) $row['escalation_due']);
    self::assertSame($this->now + 40 * 86400, (int) $row['reminder_due']);
  }

  /**
   * Due pages are capped and drain without starving later IDs.
   */
  public function testDueWorkIsBounded(): void {
    for ($id = 1; $id <= 205; $id++) {
      $this->applications[$id] = $this->application($id, $this->now - 10 * 86400);
      $this->schedule->sync($this->applications[$id]);
    }
    foreach ([100, 200, 205, 205] as $count) {
      $this->schedule->run();
      self::assertCount($count, $this->sent);
    }
  }

  /**
   * Repeat reminders honor interval, cap and deletion cleanup.
   */
  public function testResubmissionPolicyAndCleanup(): void {
    $this->workflow['escalation_days'] = 0;
    $this->workflow['resubmission_max_reminders'] = 2;
    $app = $this->application(1, $this->now - 50 * 86400);
    $this->applications[1] = $app;
    $this->schedule->sync($app);
    $this->schedule->run();
    $this->schedule->run();
    self::assertCount(1, $this->sent);
    $this->now += 7 * 86400;
    $this->schedule->run();
    self::assertCount(2, $this->sent);
    self::assertNull($this->row(1)['reminder_due']);
    $this->schedule->delete(1);
    self::assertFalse($this->row(1));
    self::assertNull($this->container->get('keyvalue')->get('state')->get('beantragt_notification.resubmission.1'));
  }

  /**
   * Policy rebuild uses bounded keyset pages and eventually reaches every case.
   */
  public function testPolicyRebuildIsBounded(): void {
    for ($id = 1; $id <= 205; $id++) {
      $this->applications[$id] = $this->application($id, $this->now);
    }
    $this->schedule->invalidate();
    $this->schedule->run();
    self::assertSame(100, (int) $this->container->get('database')->select(ReminderSchedule::TABLE)->countQuery()->execute()->fetchField());
    $this->schedule->run();
    self::assertSame(200, (int) $this->container->get('database')->select(ReminderSchedule::TABLE)->countQuery()->execute()->fetchField());
    $this->schedule->run();
    self::assertSame(205, (int) $this->container->get('database')->select(ReminderSchedule::TABLE)->countQuery()->execute()->fetchField());
    self::assertTrue($this->container->get('state')->get('beantragt_notification.schedule_cursor')['done']);
    self::assertSame([[0, 100], [0, 100], [0, 100]], $this->queryRanges);
    self::assertSame([], $this->sent);
    $this->workflow['escalation_days'] = 3;
    $this->schedule->invalidate();
    $this->schedule->run();
    self::assertSame($this->now + 3 * 86400, (int) $this->row(1)['escalation_due']);
  }

  /**
   * A policy edit during backfill cannot be lost by the stale page writer.
   */
  public function testConcurrentPolicyInvalidationWaitsForCurrentPass(): void {
    for ($id = 1; $id <= 205; $id++) {
      $this->applications[$id] = $this->application($id, $this->now);
    }
    $this->schedule->invalidate();
    $state = $this->container->get('state');
    $first = $state->get('beantragt_notification.schedule_rebuild');
    $this->onLoad = function (): void {
      $this->schedule->invalidate();
    };
    foreach ([100, 200, 205] as $cursor) {
      $this->schedule->run();
      self::assertSame($cursor, $state->get('beantragt_notification.schedule_cursor')['cursor']);
      self::assertSame($first, $state->get('beantragt_notification.schedule_cursor')['generation']);
    }
    self::assertIsArray($this->row(205));
    self::assertNotSame($first, $state->get('beantragt_notification.schedule_rebuild'));
    $this->onLoad = NULL;
    $this->schedule->run();
    self::assertSame(100, $state->get('beantragt_notification.schedule_cursor')['cursor']);
    self::assertSame($state->get('beantragt_notification.schedule_rebuild'), $state->get('beantragt_notification.schedule_cursor')['generation']);
    self::assertSame([TRUE, NULL], $this->queryConditions['field_email_confirmed']);
    self::assertSame([FALSE, NULL], $this->queryConditions['field_workflow_status.entity.field_is_terminal']);
  }

  /**
   * A queued stale entity cannot resurrect a concurrently completed case.
   */
  public function testTerminalUpdateBeforeLockPreventsStaleMail(): void {
    $this->applications[1] = $this->application(1, $this->now - 10 * 86400);
    $this->schedule->sync($this->applications[1]);
    $this->onAcquire = function (): void {
      $this->applications[1] = $this->application(1, $this->now, TRUE);
      $this->schedule->sync($this->applications[1]);
      $this->onAcquire = NULL;
    };
    $this->schedule->run();
    self::assertSame([], $this->sent);
    self::assertFalse($this->row(1));
  }

  /**
   * A stale backfill snapshot cannot overwrite a concurrent terminal update.
   */
  public function testBackfillReloadsBeforeWritingSchedule(): void {
    $this->applications[1] = $this->application(1, $this->now - 10 * 86400);
    $this->schedule->invalidate();
    $this->onAcquire = function (): void {
      $this->applications[1] = $this->application(1, $this->now, TRUE);
      $this->onAcquire = NULL;
    };
    $this->schedule->run();
    self::assertSame([], $this->sent);
    self::assertFalse($this->row(1));
  }

  /**
   * Contention retries the same backfill page without aborting other cron work.
   */
  public function testBackfillContentionDoesNotAbortCron(): void {
    $this->applications[1] = $this->application(1, $this->now);
    $this->schedule->invalidate();
    $this->lockAvailable = FALSE;
    $this->schedule->run();
    self::assertNull($this->container->get('state')->get('beantragt_notification.schedule_cursor'));
    self::assertFalse($this->row(1));
    $this->lockAvailable = TRUE;
    $this->schedule->run();
    self::assertIsArray($this->row(1));
    self::assertTrue($this->container->get('state')->get('beantragt_notification.schedule_cursor')['done']);
  }

  /**
   * Permanent skips leave the due queue, allowing newer work through next run.
   */
  public function testPermanentSkipsDoNotStarveNewDueWork(): void {
    for ($id = 1; $id <= 101; $id++) {
      $this->applications[$id] = $this->application($id, $this->now - 10 * 86400);
      $this->outcomes[$id] = $id % 2 === 0 ? StaffMailOutcome::Disabled : StaffMailOutcome::NoRecipient;
      $this->schedule->sync($this->applications[$id]);
    }
    $this->schedule->run();
    self::assertCount(100, $this->sent);
    self::assertNull($this->row(1)['escalation_due']);
    $this->applications[102] = $this->application(102, $this->now - 8 * 86400);
    $this->schedule->sync($this->applications[102]);
    $this->schedule->run();
    self::assertCount(102, $this->sent);
    self::assertSame([102, 'escalation_alert'], $this->sent[101]);
    $this->schedule->run();
    self::assertCount(102, $this->sent);
    // An explicit application change allows a repaired recipient to be tried.
    $this->outcomes[1] = StaffMailOutcome::Sent;
    $this->schedule->sync($this->applications[1]);
    $this->schedule->run();
    self::assertCount(103, $this->sent);
  }

  /**
   * Transport errors back off exponentially, retaining their delay on recheck.
   */
  public function testTransportBackoffGrowsAndPreservesOtherReminderSkip(): void {
    $this->applications[1] = $this->application(1, $this->now - 50 * 86400);
    $this->schedule->sync($this->applications[1]);
    $this->mailOutcome = StaffMailOutcome::TransportError;
    $this->schedule->run();
    self::assertSame($this->now + 300, (int) $this->row(1)['escalation_due']);
    self::assertSame($this->now + 300, (int) $this->row(1)['reminder_due']);
    $this->now += 300;
    $this->schedule->run();
    self::assertSame($this->now + 600, (int) $this->row(1)['escalation_due']);
    self::assertSame($this->now + 600, (int) $this->row(1)['reminder_due']);
    // An unrelated application save or policy backfill must retain backoff.
    $this->schedule->sync($this->applications[1]);
    $this->schedule->invalidate();
    $this->schedule->run();
    self::assertCount(4, $this->sent);
    self::assertSame($this->now + 600, (int) $this->row(1)['escalation_due']);
    self::assertSame($this->now + 600, (int) $this->row(1)['reminder_due']);
    $this->now += 600;
    $this->schedule->run();
    self::assertSame($this->now + 1200, (int) $this->row(1)['escalation_due']);
    self::assertCount(6, $this->sent);
  }

  /**
   * Legacy markers remain effective without entering the global State cache.
   */
  public function testLegacyMarkersBypassStateCacheAndTerminalCleanup(): void {
    $markers = $this->container->get('keyvalue')->get('state');
    $old = $this->now - 50 * 86400;
    $markers->set('beantragt_notification.escalation_sent.1', $old);
    $markers->set('beantragt_notification.resubmission.1', [
      'count' => 2,
      'last' => $this->now,
    ]);
    $this->workflow['resubmission_max_reminders'] = 2;
    $this->applications[1] = $this->application(1, $old);
    $this->schedule->invalidate();
    $this->schedule->run();
    self::assertSame([], $this->sent);
    self::assertNull($this->row(1)['escalation_due']);
    self::assertNull($this->row(1)['reminder_due']);
    self::assertSame('1:' . $old, $markers->get('beantragt_notification.resubmission.1')['condition']);
    $state = $this->container->get('state');
    $reflection = new \ReflectionProperty($state, 'storage');
    self::assertArrayNotHasKey('beantragt_notification.escalation_sent.1', $reflection->getValue($state));
    self::assertArrayNotHasKey('beantragt_notification.resubmission.1', $reflection->getValue($state));
    $this->schedule->sync($this->application(1, $this->now, TRUE));
    self::assertFalse($this->row(1));
    self::assertNull($markers->get('beantragt_notification.escalation_sent.1'));
    self::assertNull($markers->get('beantragt_notification.resubmission.1'));
  }

  /**
   * The cache-only update preserves legacy delivery state and resets its blob.
   */
  public function testCacheUpdatePreservesLegacyMarkers(): void {
    $state = $this->container->get('state');
    $state->set('beantragt_notification.escalation_sent.1', 123);
    $state->set('beantragt_notification.resubmission.1', ['count' => 2, 'last' => 456]);
    beantragt_notification_update_10020();
    $markers = $this->container->get('keyvalue')->get('state');
    self::assertSame(123, $markers->get('beantragt_notification.escalation_sent.1'));
    self::assertSame(['count' => 2, 'last' => 456], $markers->get('beantragt_notification.resubmission.1'));
    $reflection = new \ReflectionProperty($state, 'storage');
    self::assertSame([], $reflection->getValue($state));
  }

  /**
   * A new idle condition resets a capped stream without bypassing its cadence.
   */
  public function testNewConditionResetsReminderCap(): void {
    $this->workflow['escalation_days'] = 0;
    $this->workflow['resubmission_max_reminders'] = 1;
    $this->applications[1] = $this->application(1, $this->now - 60 * 86400);
    $this->schedule->sync($this->applications[1]);
    $this->schedule->run();
    self::assertCount(1, $this->sent);
    self::assertNull($this->row(1)['reminder_due']);
    $this->applications[1] = $this->application(1, $this->now);
    $this->schedule->sync($this->applications[1]);
    $this->schedule->run();
    self::assertCount(1, $this->sent);
    $this->now += 42 * 86400;
    $this->schedule->run();
    self::assertCount(2, $this->sent);
    self::assertNull($this->row(1)['reminder_due']);
  }

  /**
   * Disabled global templates never consume a due batch or send mail.
   */
  public function testDisabledTemplatesDoNotSend(): void {
    $this->enabled = ['enabled_templates.escalation_alert' => FALSE, 'enabled_templates.resubmission_reminder' => FALSE];
    $this->applications[1] = $this->application(1, $this->now - 50 * 86400);
    $this->schedule->sync($this->applications[1]);
    $this->schedule->run();
    self::assertNull($this->row(1)['escalation_due']);
    self::assertNull($this->row(1)['reminder_due']);
    self::assertSame([], $this->sent);
  }

  /**
   * Reads one persisted due-date row.
   */
  private function row(int $id): array|false {
    return $this->container->get('database')->select(ReminderSchedule::TABLE, 's')->fields('s')->condition('application_id', $id)->execute()->fetchAssoc();
  }

  /**
   * Builds an application in an open status at a fixed modification time.
   */
  private function application(int $id, int $changed, bool $terminal = FALSE): ApplicationInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('isTerminal')->willReturn($terminal);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('getValue')->willReturn([['target_id' => 1]]);
    $field->method('__get')->willReturnCallback(static fn(string $key) => $key === 'entity' ? $status : ($key === 'target_id' ? 1 : NULL));
    $app = $this->createMock(ApplicationInterface::class);
    $app->method('id')->willReturn($id);
    $app->method('uuid')->willReturn('application-' . $id);
    $app->method('isEmailConfirmed')->willReturn(TRUE);
    $app->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_workflow_status');
    $app->method('get')->willReturn($field);
    $app->method('getChangedTime')->willReturn($changed);
    $app->method('getCreatedTime')->willReturn($changed);
    return $app;
  }

}
