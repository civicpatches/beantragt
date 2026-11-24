<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Kernel;

use Drupal\beantragt_notification\Service\ReminderSchedule;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Actual entity hooks and revisions maintain due dates across the lifecycle.
 */
#[Group('beantragt_notification')]
final class ReminderLifecycleKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'language',
    'serialization', 'beantragt_roles_api', 'beantragt_compute_api',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_workflow', 'beantragt_notification',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_notification', 'beantragt_workflow']);
    foreach (['file', 'form_schema', 'workflow_status', 'application', 'application_attachment'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('beantragt_notification', [ReminderSchedule::TABLE]);
    $this->installSchema('file', ['file_usage']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('mail', 'system@fixture.example')->save();
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
  }

  /**
   * Insert, ordinary edit, status change and delete run the real hooks.
   */
  public function testRealApplicationLifecycleMaintainsSchedule(): void {
    $manager = $this->container->get('entity_type.manager');
    $statuses = $manager->getStorage('workflow_status');
    $first = $statuses->create(['name' => 'First', 'field_is_terminal' => FALSE, 'field_escalation_days' => 7]);
    $first->save();
    $second = $statuses->create(['name' => 'Second', 'field_is_terminal' => FALSE, 'field_escalation_days' => 2]);
    $second->save();
    $form = $manager->getStorage('form_schema')->create([
      'label' => 'Schedule test',
      'field_schema' => '{"title":"Schedule test","sections":[]}',
    ]);
    $form->save();
    $app = $manager->getStorage('application')->create([
      'label' => 'Schedule case',
      'field_reference_number' => 'TEST-127',
      'field_schema' => $form->id(),
      'field_data' => '{"note":"synthetic"}',
      'field_crm_data' => '{}',
      'field_workflow_status' => $first->id(),
      'field_email_confirmed' => FALSE,
    ]);
    $app->save();
    self::assertFalse($this->row((int) $app->id()));
    $app->set('field_email_confirmed', TRUE);
    $app->save();
    $row = $this->row((int) $app->id());
    self::assertIsArray($row);
    self::assertSame((int) $first->id(), (int) $row['status_id']);
    $entered = (int) $row['status_entered'];
    $app->set('label', 'Edited');
    $app->save();
    self::assertSame($entered, (int) $this->row((int) $app->id())['status_entered']);
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturn($entered + 60);
    $this->container->set('datetime.time', $clock);
    $app->set('field_workflow_status', $second->id());
    $app->allowWorkflowStatusReset();
    $app->setNewRevision(TRUE);
    $app->setRevisionCreationTime($entered + 60);
    $app->save();
    $row = $this->row((int) $app->id());
    self::assertSame((int) $second->id(), (int) $row['status_id']);
    self::assertSame((int) $app->getRevisionCreationTime(), (int) $row['status_entered']);
    self::assertSame((int) $row['status_entered'] + 2 * 86400, (int) $row['escalation_due']);
    $state = $this->container->get('state');
    $before = $state->get('beantragt_notification.schedule_rebuild');
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Schedule jurisdiction',
      'field_slug' => 'schedule-jur',
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Schedule office',
      'field_slug' => 'schedule-office',
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $jurisdiction_b = $this->createGroup([
      'type' => 'jur',
      'label' => 'Second jurisdiction',
      'field_slug' => 'schedule-jur-b',
    ]);
    $organisation->set('field_jurisdiction', $jurisdiction_b->id());
    $organisation->save();
    self::assertNotSame($before, $state->get('beantragt_notification.schedule_rebuild'));
    $id = (int) $app->id();
    $app->delete();
    self::assertFalse($this->row($id));
  }

  /**
   * Unrelated schema edits and JSON formatting do not restart policy refreshes.
   */
  public function testOnlyNormalizedReminderPoliciesInvalidate(): void {
    $form = $this->container->get('entity_type.manager')->getStorage('form_schema')->create([
      'label' => 'Policy fixture',
      'field_schema' => '{"title":"Before","resubmission":{"reminderDays":42,"reminderScope":"org"}}',
    ]);
    $form->save();
    $state = $this->container->get('state');
    $state->set('beantragt_notification.schedule_rebuild', 'unchanged');
    $form->set('field_schema', '{"resubmission":{"reminderScope":"org","reminderDays":"42"},"title":"After"}');
    $form->save();
    self::assertSame('unchanged', $state->get('beantragt_notification.schedule_rebuild'));
    $form->set('field_schema', '{"resubmission":{"reminderDays":7}}');
    $form->save();
    self::assertNotSame('unchanged', $state->get('beantragt_notification.schedule_rebuild'));
  }

  /**
   * Real entities and mailer accept each escalation once with a fake transport.
   */
  public function testRealMailerCronAndConfirmedNonterminalBackfill(): void {
    $now = 1800000000;
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturnCallback(static function () use (&$now): int {
      return $now;
    });
    $this->container->set('datetime.time', $clock);
    $sent = [];
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(static function ($module, $key, $to) use (&$sent): array {
      $sent[] = [$key, $to];
      return ['result' => TRUE];
    });
    $this->container->set('beantragt_notification.mailer', new NotificationMailer(
      $mail,
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.channel.beantragt_notification'),
      $this->container->get('language_manager'),
      $this->container->get('beantragt_application.data_protection'),
      $this->container->get('beantragt_notification.template_resolver'),
    ));
    $this->container->set('beantragt_notification.reminder_schedule', new ReminderSchedule(
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('state'),
      $this->container->get('config.factory'),
      $this->container->get('beantragt_notification.resubmission_resolver'),
      $this->container->get('beantragt_notification.mailer'),
      $this->container->get('lock'),
      $clock,
      $this->container->get('logger.channel.beantragt_notification'),
      $this->container->get('beantragt_application.dependent_mutation_lock'),
      $this->container->get('keyvalue'),
    ));
    $manager = $this->container->get('entity_type.manager');
    $status = $manager->getStorage('workflow_status')->create([
      'name' => 'Open',
      'field_is_terminal' => FALSE,
      'field_escalation_days' => 7,
    ]);
    $status->save();
    $terminal = $manager->getStorage('workflow_status')->create(['name' => 'Closed', 'field_is_terminal' => TRUE]);
    $terminal->save();
    $jur = $this->createGroup([
      'type' => 'jur',
      'label' => 'Cron fixture',
      'field_slug' => 'cron-fixture',
      'field_org_email' => 'staff@cron-fixture.example',
    ]);
    $form = $manager->getStorage('form_schema')->create(['label' => 'Cron fixture', 'field_schema' => '{"sections":[]}']);
    $form->save();
    $apps = [];
    foreach ([[TRUE, $status], [FALSE, $status], [TRUE, $terminal]] as [$confirmed, $workflow]) {
      $app = $manager->getStorage('application')->create([
        'label' => 'Cron fixture',
        'field_schema' => $form->id(),
        'field_reference_number' => 'CRON-' . count($apps),
        'field_data' => '{"note":"synthetic"}',
        'field_crm_data' => '{}',
        'field_workflow_status' => $workflow->id(),
        'field_email_confirmed' => $confirmed,
        'field_jurisdiction' => $jur->id(),
      ]);
      $app->save();
      $apps[] = $app;
    }
    $sent = [];
    $now += 10 * 86400;
    $schedule = $this->container->get('beantragt_notification.reminder_schedule');
    $schedule->invalidate();
    $schedule->run();
    $schedule->run();
    self::assertSame([['escalation_alert', 'staff@cron-fixture.example']], $sent);
    self::assertIsArray($this->row((int) $apps[0]->id()));
    self::assertFalse($this->row((int) $apps[1]->id()));
    self::assertFalse($this->row((int) $apps[2]->id()));
  }

  /**
   * Updating a staff email re-enables user-scoped reminders after a skip.
   */
  public function testStaffMailRepairReactivatesSkippedReminder(): void {
    $manager = $this->container->get('entity_type.manager');
    $recipients = [];
    $transport = $this->createMock(MailManagerInterface::class);
    $transport->method('mail')->willReturnCallback(static function ($module, $key, $to) use (&$recipients): array {
      $recipients[] = [$key, $to];
      return ['result' => TRUE];
    });
    $mailer = new NotificationMailer(
      $transport,
      $this->container->get('config.factory'),
      $manager,
      $this->container->get('logger.channel.beantragt_notification'),
      $this->container->get('language_manager'),
      $this->container->get('beantragt_application.data_protection'),
      $this->container->get('beantragt_notification.template_resolver'),
    );
    $this->container->set('plugin.manager.mail', $transport);
    $this->container->set('beantragt_notification.mailer', $mailer);
    $status = $manager->getStorage('workflow_status')->create([
      'name' => 'Waiting',
      'field_is_terminal' => FALSE,
      'field_escalation_days' => 0,
    ]);
    $status->save();
    $form = $manager->getStorage('form_schema')->create([
      'label' => 'User reminder fixture',
      'field_schema' => '{"sections":[],"resubmission":{"reminderDays":1,"reminderScope":"user"}}',
    ]);
    $form->save();
    $account = $manager->getStorage('user')->create(['name' => 'reminder-fixture', 'mail' => '', 'status' => 1]);
    $account->save();
    $app = $manager->getStorage('application')->create([
      'label' => 'User reminder fixture',
      'field_reference_number' => 'USER-REMINDER',
      'field_schema' => $form->id(),
      'field_data' => '{"note":"synthetic"}',
      'field_crm_data' => '{}',
      'field_workflow_status' => $status->id(),
      'field_email_confirmed' => TRUE,
      'field_assigned_to' => $account->id(),
    ]);
    $app->save();
    $now = $app->getChangedTime() + 2 * 86400;
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturn($now);
    $mailer = $this->container->get('beantragt_notification.mailer');
    self::assertNull($mailer->resolveStaffRecipient($app, 'user'));
    $schedule = new ReminderSchedule(
      $this->container->get('database'),
      $manager,
      $this->container->get('state'),
      $this->container->get('config.factory'),
      $this->container->get('beantragt_notification.resubmission_resolver'),
      $mailer,
      $this->container->get('lock'),
      $clock,
      $this->container->get('logger.channel.beantragt_notification'),
      $this->container->get('beantragt_application.dependent_mutation_lock'),
      $this->container->get('keyvalue'),
    );
    $this->container->set('beantragt_notification.reminder_schedule', $schedule);
    $schedule->run();
    self::assertNull($this->row((int) $app->id())['reminder_due']);
    $before = $this->container->get('state')->get('beantragt_notification.schedule_rebuild');
    $account->setEmail('staff@fixture.example');
    $account->save();
    self::assertNotSame($before, $this->container->get('state')->get('beantragt_notification.schedule_rebuild'));
    $schedule->run();
    self::assertGreaterThan($now, (int) $this->row((int) $app->id())['reminder_due']);
    $marker = $this->container->get('keyvalue')->get('state')->get('beantragt_notification.resubmission.' . $app->id());
    self::assertSame(1, $marker['count']);
    self::assertSame([['resubmission_reminder', 'staff@fixture.example']], $recipients);
  }

  /**
   * Reads the persisted schedule after real entity hooks ran.
   */
  private function row(int $id): array|false {
    return $this->container->get('database')->select(ReminderSchedule::TABLE, 's')->fields('s')->condition('application_id', $id)->execute()->fetchAssoc();
  }

}
