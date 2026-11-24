<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Indexed due dates keep routine reminder cron work independent of backlog.
 */
final class ReminderSchedule {

  public const TABLE = 'beantragt_reminder_schedule';
  public const BATCH_SIZE = 100;
  private const REBUILD = 'beantragt_notification.schedule_rebuild';
  private const CURSOR = 'beantragt_notification.schedule_cursor';
  private const RETRY_SECONDS = 300;
  private const MAX_RETRY_SECONDS = 86400;

  /**
   * Direct storage avoids adding per-case keys to Drupal's global State cache.
   */
  private readonly KeyValueStoreInterface $markers;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $manager,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $config,
    private readonly ResubmissionReminderResolver $resolver,
    private readonly NotificationMailer $mailer,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ApplicationDependentMutationLock $mutationLock,
    KeyValueFactoryInterface $keyValue,
  ) {
    $this->markers = $keyValue->get('state');
  }

  /**
   * Re-evaluate existing applications gradually after a policy change.
   */
  public function invalidate(): void {
    $this->state->set(self::REBUILD, bin2hex(random_bytes(8)));
  }

  /**
   * Delete scheduling and legacy send state with its application.
   */
  public function delete(int $id): void {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return;
    }
    $this->database->delete(self::TABLE)->condition('application_id', $id)->execute();
    $this->markers->delete('beantragt_notification.escalation_sent.' . $id);
    $this->markers->delete(_beantragt_notification_resubmission_state_key($id));
    $this->markers->delete($this->retryKey($id));
  }

  /**
   * Refresh dates on a real application mutation, preserving status entry.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The current application.
   * @param bool $refresh
   *   TRUE for application/policy changes; FALSE for a delivery recheck so a
   *   pending retry or permanent skip cannot be erased by another mail kind.
   */
  public function sync(ApplicationInterface $application, bool $refresh = TRUE): void {
    $id = (int) $application->id();
    if (!$this->database->schema()->tableExists(self::TABLE) || $id <= 0) {
      return;
    }
    $status = $application->hasField('field_workflow_status') ? $application->get('field_workflow_status')->entity : NULL;
    if (!$application->isEmailConfirmed() || !$status instanceof WorkflowStatusInterface || $status->isTerminal()) {
      $this->delete($id);
      return;
    }
    $row = $this->row($id);
    $status_id = _beantragt_notification_application_workflow_status_id($application);
    $entered = $row && (int) $row['status_id'] === $status_id
      ? (int) $row['status_entered']
      : _beantragt_notification_application_status_entered_time($application);
    $changed = (int) ($application->getChangedTime() ?: $application->getCreatedTime());
    $settings = $this->config->get('beantragt_notification.settings');
    $days = _beantragt_notification_application_escalation_days($application, (int) $this->config->get('beantragt_workflow.settings')->get('escalation_days'));
    $escalation_due = $settings->get('enabled_templates.escalation_alert') && $days > 0
      && (int) $this->markers->get('beantragt_notification.escalation_sent.' . $id, 0) !== $entered
      ? $entered + $days * 86400 : NULL;
    $key = _beantragt_notification_resubmission_state_key($id);
    $record = _beantragt_notification_resubmission_state_record($this->markers->get($key));
    $condition = _beantragt_notification_resubmission_condition_marker($application);
    if ($record['condition'] !== '' && $record['condition'] !== $condition) {
      $this->markers->delete($key);
    }
    $legacy_record = $record['condition'] === '' && $record['count'] > 0;
    $record = _beantragt_notification_resubmission_state_for_condition($record, $condition);
    if ($legacy_record) {
      $this->markers->set($key, $record);
    }
    $policy = $this->resolver->resolvePolicy($application);
    $max = $this->resolver->maxReminders();
    $reminder_due = $settings->get('enabled_templates.resubmission_reminder') && ($max === 0 || $record['count'] < $max)
      ? max($changed + $policy['days'] * 86400, $record['last'] > 0 ? $record['last'] + $this->resolver->intervalDays() * 86400 : 0) : NULL;
    $dates = ['escalation_due' => $escalation_due, 'reminder_due' => $reminder_due];
    $retry = $this->markers->get($this->retryKey($id), []);
    foreach ($dates as $column => $due) {
      $identity = $column === 'escalation_due' ? (string) $entered : $condition;
      if (isset($retry[$column]) && ($retry[$column]['identity'] ?? NULL) !== $identity) {
        unset($retry[$column]);
      }
      if ($refresh && !empty($retry[$column]['blocked'])) {
        unset($retry[$column]);
      }
      if (!empty($retry[$column]['blocked'])) {
        $dates[$column] = NULL;
      }
      elseif ($due !== NULL && isset($retry[$column]['due'])) {
        $dates[$column] = max($due, (int) $retry[$column]['due']);
      }
    }
    if ($retry === []) {
      $this->markers->delete($this->retryKey($id));
    }
    else {
      $this->markers->set($this->retryKey($id), $retry);
    }
    $this->database->merge(self::TABLE)->keys(['application_id' => $id])->fields([
      'status_id' => $status_id,
      'status_entered' => $entered,
      'escalation_due' => $dates['escalation_due'],
      'reminder_due' => $dates['reminder_due'],
    ])->execute();
  }

  /**
   * One bounded rebuild page plus one bounded page of each due reminder kind.
   */
  public function run(): void {
    if (!$this->database->schema()->tableExists(self::TABLE) || !$this->lock->acquire('beantragt_notification.reminder_schedule', 3600)) {
      return;
    }
    try {
      $this->rebuildPage();
      foreach (['escalation_due' => 'escalation_alert', 'reminder_due' => 'resubmission_reminder'] as $column => $template) {
        if (!$this->config->get('beantragt_notification.settings')->get('enabled_templates.' . $template)) {
          continue;
        }
        $ids = $this->database->select(self::TABLE, 's')->fields('s', ['application_id'])
          ->condition($column, $this->time->getRequestTime(), '<=')
          ->orderBy($column)->orderBy('application_id')->range(0, self::BATCH_SIZE)->execute()->fetchCol();
        $storage = $this->manager->getStorage('application');
        $applications = $storage->loadMultiple($ids);
        foreach ($applications as $application) {
          if (!$application instanceof ApplicationInterface) {
            continue;
          }
          try {
            $this->deliverCurrent($application, $column);
          }
          catch (\Throwable $error) {
            $this->postpone((int) $application->id(), $column);
            $this->logger->error('Reminder scheduling failed for application @id (@type).', [
              '@id' => $application->id(),
              '@type' => get_debug_type($error),
            ]);
          }
        }
        foreach (array_diff($ids, array_keys($applications)) as $missing) {
          $this->delete((int) $missing);
        }
      }
    }
    finally {
      $this->lock->release('beantragt_notification.reminder_schedule');
    }
  }

  /**
   * Backfill and policy updates use a bounded application ID cursor.
   */
  private function rebuildPage(): void {
    $generation = $this->state->get(self::REBUILD, TRUE);
    if (!$generation) {
      return;
    }
    $progress = $this->state->get(self::CURSOR, []);
    if (!is_array($progress) || !isset($progress['generation']) || (!empty($progress['done']) && $progress['generation'] !== $generation)) {
      $progress = ['generation' => $generation, 'cursor' => 0, 'done' => FALSE];
    }
    if (!empty($progress['done'])) {
      return;
    }
    $storage = $this->manager->getStorage('application');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('id', $progress['cursor'], '>')
      ->condition('field_email_confirmed', TRUE)
      ->condition('field_workflow_status.entity.field_is_terminal', FALSE)
      ->sort('id')->range(0, self::BATCH_SIZE)->execute();
    foreach ($storage->loadMultiple($ids) as $application) {
      if ($application instanceof ApplicationInterface) {
        if (!$this->syncCurrent($application)) {
          return;
        }
      }
    }
    // Finish the active pass before adopting a pending generation, so repeated
    // policy edits cannot starve high IDs. Never overwrite the requested value.
    $this->state->set(self::CURSOR, [
      'generation' => $progress['generation'],
      'cursor' => $ids ? max($ids) : $progress['cursor'],
      'done' => count($ids) < self::BATCH_SIZE,
    ]);
  }

  /**
   * Backfill also reloads under the same lock as application writes.
   */
  private function syncCurrent(ApplicationInterface $application): bool {
    if (!$this->mutationLock->acquireApplication($application)) {
      // Retry this page later without aborting unrelated cron work.
      return FALSE;
    }
    try {
      $storage = $this->manager->getStorage('application');
      $storage->resetCache([$application->id()]);
      $current = $storage->load($application->id());
      if ($current instanceof ApplicationInterface) {
        $this->sync($current);
      }
      else {
        $this->delete((int) $application->id());
      }
    }
    finally {
      $this->mutationLock->release($application);
    }
    return TRUE;
  }

  /**
   * Reload under the existing application write/delete lock.
   */
  private function deliverCurrent(ApplicationInterface $application, string $column): void {
    if (!$this->mutationLock->acquireApplication($application)) {
      return;
    }
    try {
      $storage = $this->manager->getStorage('application');
      $storage->resetCache([$application->id()]);
      $current = $storage->load($application->id());
      if ($current instanceof ApplicationInterface) {
        $this->deliver($current, $column);
      }
      else {
        $this->delete((int) $application->id());
      }
    }
    finally {
      $this->mutationLock->release($application);
    }
  }

  /**
   * Recheck the current application and policy before sending a due reminder.
   */
  private function deliver(ApplicationInterface $application, string $column): void {
    $id = (int) $application->id();
    $this->sync($application, FALSE);
    $row = $this->row($id);
    if (!$row || $row[$column] === NULL || (int) $row[$column] > $this->time->getRequestTime()) {
      return;
    }
    if ($column === 'escalation_due') {
      $days = _beantragt_notification_application_escalation_days($application, (int) $this->config->get('beantragt_workflow.settings')->get('escalation_days'));
      $outcome = $this->mailer->sendStaffNotificationOutcome($application, 'escalation_alert', ['@escalation_days' => (string) $days]);
      if ($outcome === StaffMailOutcome::Sent) {
        $this->markers->set('beantragt_notification.escalation_sent.' . $id, (int) $row['status_entered']);
      }
    }
    else {
      $key = _beantragt_notification_resubmission_state_key($id);
      $record = _beantragt_notification_resubmission_state_for_condition(
        _beantragt_notification_resubmission_state_record($this->markers->get($key)),
        _beantragt_notification_resubmission_condition_marker($application),
      );
      $policy = $this->resolver->resolvePolicy($application);
      $outcome = $this->mailer->sendStaffNotificationOutcome($application, 'resubmission_reminder', [
        '@days_idle' => (string) _beantragt_notification_application_idle_days($application, $this->time->getRequestTime()),
        '@reminder_count' => (string) ($record['count'] + 1),
      ], $policy['scope']);
      if ($outcome === StaffMailOutcome::Sent) {
        $this->markers->set($key, [
          'count' => $record['count'] + 1,
          'last' => $this->time->getRequestTime(),
          'condition' => $record['condition'],
        ]);
      }
    }
    if ($outcome === StaffMailOutcome::Sent) {
      $retry = $this->markers->get($this->retryKey($id), []);
      unset($retry[$column]);
      $this->markers->set($this->retryKey($id), $retry);
      $this->sync($application, FALSE);
    }
    elseif ($outcome === StaffMailOutcome::TransportError) {
      $this->postpone($id, $column);
    }
    else {
      // Policy/recipient edits and application mutations re-enable delivery.
      $retry = $this->markers->get($this->retryKey($id), []);
      $retry[$column] = ['blocked' => TRUE, 'identity' => $this->deliveryIdentity($id, $column)];
      $this->markers->set($this->retryKey($id), $retry);
      $this->database->update(self::TABLE)->fields([$column => NULL])->condition('application_id', $id)->execute();
    }
  }

  /**
   * Retry failed delivery on a later cron without falsely recording success.
   */
  private function postpone(int $id, string $column): void {
    $retry = $this->markers->get($this->retryKey($id), []);
    $attempts = min(10, (int) ($retry[$column]['attempts'] ?? 0) + 1);
    $due = $this->time->getRequestTime() + min(self::MAX_RETRY_SECONDS, self::RETRY_SECONDS * (2 ** ($attempts - 1)));
    $retry[$column] = ['attempts' => $attempts, 'due' => $due, 'identity' => $this->deliveryIdentity($id, $column)];
    $this->markers->set($this->retryKey($id), $retry);
    $this->database->update(self::TABLE)->fields([$column => $due])->condition('application_id', $id)->execute();
  }

  /**
   * Scopes transport retries to a status entry or resubmission condition.
   */
  private function deliveryIdentity(int $id, string $column): string {
    if ($column === 'escalation_due') {
      return (string) ($this->row($id)['status_entered'] ?? 0);
    }
    $application = $this->manager->getStorage('application')->load($id);
    return $application instanceof ApplicationInterface
      ? _beantragt_notification_resubmission_condition_marker($application) : '';
  }

  /**
   * Builds the uncached per-case delivery retry key.
   */
  private function retryKey(int $id): string {
    return 'beantragt_notification.schedule_retry.' . $id;
  }

  /**
   * Reads one schedule row.
   */
  private function row(int $id): array|false {
    return $this->database->select(self::TABLE, 's')->fields('s')->condition('application_id', $id)->execute()->fetchAssoc();
  }

}
