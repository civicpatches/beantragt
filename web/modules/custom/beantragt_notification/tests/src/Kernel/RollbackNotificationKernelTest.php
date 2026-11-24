<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Kernel;

use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_notification\Service\ReminderSchedule;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises rejection and rollback through the staff controller and mail hooks.
 */
#[Group('beantragt_notification')]
final class RollbackNotificationKernelTest extends GroupKernelTestBase {

  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

  /**
   * Encryption configuration to restore after the fixture.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'language',
    'serialization', 'beantragt_roles_api', 'beantragt_compute_api',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_workflow', 'beantragt_notification',
    'beantragt_application_api_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . bin2hex(random_bytes(32)));
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? self::KEY_ENV : self::KEY_ENV . '=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * Only a new rejection can carry an explicitly public explanation.
   */
  public function testRollbackMailsOnlyChangedCitizenLabels(): void {
    $this->installConfig(['user', 'beantragt_group', 'beantragt_notification', 'beantragt_workflow']);
    foreach (['form_schema', 'workflow_status', 'application', 'file', 'application_attachment'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('beantragt_notification', [ReminderSchedule::TABLE]);
    $this->installSchema('file', ['file_usage']);
    $this->config('system.site')->set('mail', 'system@example.test')->save();
    $this->config('beantragt_notification.settings')->set('enabled_templates.rejection', TRUE)->save();
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope', 'administer application entities']));
    $workspace = $this->createGroup(['type' => 'jur', 'label' => 'Test office', 'field_slug' => 'test-office']);
    $manager = $this->entityTypeManager();
    $statuses = $this->container->get('beantragt_workflow_status.status_seeder')->seedForGroup($workspace);
    $received = $statuses['eingegangen'];
    $review = $statuses['in_bearbeitung'];
    $rejected = $statuses['abgelehnt'];
    $received->set('field_allowed_next', [$review->id(), $rejected->id()])->save();
    // A different internal review stage shows the same public label.
    $review->set('field_citizen_label_de', $received->getCitizenLabel('de'));
    $review->set('field_citizen_label_en', $received->getCitizenLabel('en'))->save();

    $form = $manager->getStorage('form_schema')->create([
      'label' => 'Test form',
      'field_schema' => '{"sections":[]}',
      'field_jurisdiction' => $workspace->id(),
      'field_group' => $workspace->id(),
    ]);
    $form->save();
    $storage = $manager->getStorage('application');
    $application = $storage->create([
      'field_schema' => $form->id(),
      'field_reference_number' => 'TEST-182',
      'field_jurisdiction' => $workspace->id(),
      'field_group' => $workspace->id(),
      'field_data' => '{"fixture":"test"}',
      'field_workflow_status' => $received->id(),
      'field_applicant_email' => 'applicant@example.test',
      'field_email_confirmed' => TRUE,
    ]);
    $application->save();
    $id = $application->id();
    $controller = ApplicationDataController::create($this->container);
    $state = $this->container->get('state');
    $state->delete('system.test_mail_collector');
    $private = 'Internal revision explanation';
    $public = 'This is the public reason.';
    $application = $storage->loadUnchanged($id);
    $controller->updateStatus($application, $this->request($application, [
      'target_status_id' => $rejected->id(),
      'note' => $private,
      'public_rejection_reason' => '<b>This is</b> the public reason.',
    ]));
    $mail = $this->takeMail('rejection', $private);
    self::assertStringContainsString($public, $mail['body']);
    $application = $storage->loadUnchanged($id);
    self::assertSame($private, RevisionLog::message((string) $application->getRevisionLogMessage()));

    // Undo the decision, then undo the reset back into the rejected status.
    // Neither action issues an outcome or inherits the earlier explanation.
    foreach ([$received, $rejected] as $target) {
      $controller->rollbackStatus($application, $this->request($application, ['note' => $private]));
      $mail = $this->takeMail('status_change', $private);
      self::assertStringNotContainsString($public, $mail['body']);
      $application = $storage->loadUnchanged($id);
      self::assertSame((int) $target->id(), $application->getWorkflowStatusId());
      self::assertSame($private, RevisionLog::message((string) $application->getRevisionLogMessage()));
    }

    $controller->rollbackStatus($application, $this->request($application, ['note' => $private]));
    $this->takeMail('status_change', $private);
    $application = $storage->loadUnchanged($id);
    $controller->updateStatus($application, $this->request($application, ['target_status_id' => $review->id()]));
    $application = $storage->loadUnchanged($id);
    $controller->rollbackStatus($application, $this->request($application, ['note' => $private]));
    self::assertSame((int) $received->id(), $storage->loadUnchanged($id)->getWorkflowStatusId());
    self::assertSame([], $state->get('system.test_mail_collector', []));
  }

  /**
   * Saved site and tenant templates omit only a reason's unfilled body line.
   */
  public function testOptionalReasonInLocalizedRejectionTemplates(): void {
    $this->installConfig(['user', 'beantragt_group', 'beantragt_notification']);
    $this->installEntitySchema('workflow_status');
    $this->config('system.site')->set('mail', 'system@example.test')->save();
    // Install the German site default and the same English override used by
    // installed-site updates. The kernel keeps its running request language.
    $this->container->get('module_handler')->loadInclude('beantragt_notification', 'install');
    beantragt_notification_install();
    $languages = $this->container->get('language_manager');
    self::assertSame('de', $languages->getDefaultLanguage()->getId());
    $languages->setConfigOverrideLanguage($languages->getDefaultLanguage());
    self::assertStringContainsString('Begründung: @rejection_reason', $this->container->get('config.factory')->get('beantragt_notification.mail')->get('rejection.body'));
    self::assertStringContainsString('Reason: @rejection_reason', $languages->getLanguageConfigOverride('en', 'beantragt_notification.mail')->get('rejection.body'));
    $tenant = $this->createGroup([
      'type' => 'jur',
      'label' => 'Test office',
      'field_slug' => 'test-office',
      'field_jur_config' => json_encode([
        'notification_mail' => [
          'templates' => [
            'rejection' => [
              'translations' => [
                'de' => [
                  'subject' => 'Bescheid @reference_number',
                  'body' => "Guten Tag @full_name,\nIhr Antrag wurde abgelehnt.\n"
                  . "Grund für @form_title (Abs. 2): @rejection_reason\n@rejection_reason\n"
                  . "Frist: 14 Tage. Weitere Auskunft zu @rejection_reason erteilt das Amt.\n"
                  . "@citizen_application_url\n@org_name",
                ],
                'en' => [
                  'subject' => 'Decision @reference_number',
                  'body' => "Hello @full_name,\nYour application was rejected.\n"
                  . "Reason for @form_title (sec. 2): @rejection_reason\n@rejection_reason\n"
                  . "Deadline: 14 days. Contact our office about @rejection_reason at any time.\n"
                  . "@citizen_application_url\n@org_name",
                ],
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
    ]);
    $transport = $this->container->get('plugin.manager.mail');
    $resolver = $this->container->get('beantragt_notification.template_resolver');
    $labels = [
      'de' => ['Begründung:', 'Grund für Test (Abs. 2):', 'Frist: 14 Tage.', 'erteilt das Amt.'],
      'en' => ['Reason:', 'Reason for Test (sec. 2):', 'Deadline: 14 days.', 'at any time.'],
    ];
    foreach ($labels as $language => [$site_label, $tenant_label, $footer, $inline_text]) {
      $tenant_template = $resolver->resolve($tenant, 'rejection', $language);
      foreach ([$site_label => NULL, $tenant_label => $tenant_template] as $label => $template) {
        foreach (['', 'Public explanation'] as $reason) {
          $mail = $transport->mail('beantragt_notification', 'rejection', 'applicant@example.test', $language, [
            'resolvedTemplate' => $template,
            'replacements' => [
              '@rejection_reason' => $reason,
              '@full_name' => 'Test Person',
              '@form_title' => 'Test',
              '@reference_number' => 'TEST-182',
              '@org_name' => 'Test office',
              '@citizen_application_url' => 'https://office.example.test/applications/TEST-182',
            ],
          ]);
          self::assertTrue($mail['result']);
          if ($template !== NULL && $reason === '') {
            self::assertStringNotContainsString("\n\n" . $footer, $mail['params']['_plain_alt']);
          }
          foreach ([$mail['params']['_plain_alt'], $mail['params']['_html_body']] as $body) {
            if ($reason === '') {
              self::assertStringNotContainsString($label, $body);
            }
            else {
              self::assertStringContainsString($label . ' ' . $reason, $body);
            }
            if ($template !== NULL) {
              self::assertStringContainsString($footer, $body);
              self::assertStringContainsString($inline_text, $body);
            }
          }
        }
      }
    }
  }

  /**
   * Builds a request with the staff controller's preconditions.
   */
  private function request(ApplicationInterface $application, array $payload): Request {
    return Request::create('/', 'PATCH', content: json_encode($payload + [
      'expected_status_id' => $application->getWorkflowStatusId(),
      'expected_changed' => $application->getChangedTime(),
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Returns the only mail, checking the rendered output at the test transport.
   */
  private function takeMail(string $key, string $private): array {
    $state = $this->container->get('state');
    $mails = $state->get('system.test_mail_collector', []);
    self::assertCount(1, $mails);
    $mail = $mails[0];
    self::assertSame('applicant@example.test', $mail['to']);
    self::assertSame($key, $mail['key']);
    self::assertStringNotContainsString($private, $mail['subject'] . $mail['body']);
    self::assertStringNotContainsString($private, $mail['params']['_html_body'] ?? '');
    $state->delete('system.test_mail_collector');
    return $mail;
  }

}
