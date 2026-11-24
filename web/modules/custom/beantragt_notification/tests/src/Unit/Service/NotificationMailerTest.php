<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_notification\Service\StaffMailOutcome;
use Drupal\beantragt_notification\Service\NotificationOutcomeCollector;
use Drupal\beantragt_notification\Service\ResubmissionReminderResolver;
use Drupal\beantragt_notification\Service\NotificationTemplateResolver;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 4) . '/beantragt_notification.module';

/**
 * Tests notification mail token resolution.
 */
#[CoversClass(NotificationMailer::class)]
#[Group('beantragt_notification')]
class NotificationMailerTest extends UnitTestCase {

  /**
   * CRM-tagged person names are used for citizen greetings.
   */
  public function testResolveTokensUsesCrmFullName(): void {
    $mailer = $this->createMailer();

    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Hundesteuer anmelden');

    $schema_field = $this->createMock(FieldItemListInterface::class);
    $schema_field->method('__get')->willReturnCallback(
      static fn (string $name): ?EntityInterface => $name === 'entity' ? $schema : NULL
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getReferenceNumber')->willReturn('DUNKELSTADT-11-2026-00003');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('new');
    $application->method('getCrmData')->willReturn(json_encode([
      'person.full_name' => 'Erika Mustermann',
      'full_name' => 'Legacy Name',
    ]));
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('get')->with('field_schema')->willReturn($schema_field);
    $application->method('hasField')->willReturn(FALSE);

    $tokens = $mailer->resolveTokens($application);

    $this->assertSame('Erika Mustermann', $tokens['@full_name']);
    $this->assertSame('Hundesteuer anmelden', $tokens['@form_title']);
    $this->assertSame('', $tokens['@citizen_application_url']);
    $this->assertSame('', $tokens['@admin_application_url']);
  }

  /**
   * Protected identity linkage is not exposed in notification tokens.
   */
  public function testResolveTokensRedactsFullNameForProtectedIdentityLinkage(): void {
    $data_protection = new ApplicationDataProtectionService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    );
    $mailer = $this->createMailer(NULL, 'https://beantragt.example', $data_protection);

    $schema = $this->createMock(FormSchemaInterface::class);
    $schema->method('label')->willReturn('IfSG Meldung');
    $schema->method('getVersion')->willReturn(1);
    $schema->method('getSchema')->willReturn(json_encode([
      'sections' => [
        [
          'fields' => [
            ['id' => 'full_name', 'label' => 'Name', 'crm_key' => 'person.full_name'],
            ['id' => 'diagnosis', 'label' => 'Diagnose', 'sensitive' => TRUE],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getReferenceNumber')->willReturn('KM-GA-2026-00004');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('new');
    $application->method('getCrmData')->willReturn(json_encode([
      'person.full_name' => 'Erika Mustermann',
    ]));
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_schema'
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      default => $this->fieldList([], TRUE),
    });
    $application->method('getSchemaVersion')->willReturn(1);

    $tokens = $mailer->resolveTokens($application);

    $this->assertSame('', $tokens['@full_name']);
    $this->assertSame('IfSG Meldung', $tokens['@form_title']);
  }

  /**
   * Due dates are exposed as staff-template tokens.
   */
  public function testResolveTokensFormatsDueDate(): void {
    $mailer = $this->createMailer();

    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Fristformular');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getReferenceNumber')->willReturn('MS-2026-00022');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('eingegangen');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, ['field_schema', 'field_due_date'], TRUE)
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      'field_due_date' => $this->fieldList(['value' => '2026-06-23']),
      default => $this->fieldList([], TRUE),
    });

    $tokens = $mailer->resolveTokens($application);

    $this->assertSame('23.06.2026', $tokens['@due_date']);
  }

  /**
   * Deadline helper dates use the local Berlin day.
   */
  public function testBerlinDayKeyUsesLocalDate(): void {
    $timestamp = (new \DateTimeImmutable('2026-06-24 22:30:00 UTC'))->getTimestamp();

    $this->assertSame('2026-06-25', \_beantragt_notification_berlin_day_key($timestamp));
  }

  /**
   * Escalation timing uses the revision that introduced the current status.
   */
  public function testApplicationStatusEnteredTimeUsesCurrentStatusRevisionRun(): void {
    $current = $this->applicationRevision(17, 30, 2, 1_799_433_900, 1_799_434_500);
    $same_status = $this->applicationRevision(17, 20, 2, 1_799_433_000, 1_799_433_100);
    $previous_status = $this->applicationRevision(17, 10, 1, 1_799_432_000, 1_799_432_100);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('allRevisions')->willReturnSelf();
    $query->method('condition')->with('id', 17)->willReturnSelf();
    $query->method('sort')->with('revision_id', 'DESC')->willReturnSelf();
    $query->method('execute')->willReturn([
      30 => 30,
      20 => 20,
      10 => 10,
    ]);

    $storage = $this->createMock(RevisionableStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadRevision')->willReturnMap([
      [20, $same_status],
      [10, $previous_status],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('application')
      ->willReturn($storage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);

    $this->assertSame(1_799_433_000, \_beantragt_notification_application_status_entered_time($current));
  }

  /**
   * Escalation day resolution honors status rules and terminal status.
   */
  public function testApplicationEscalationDaysUseStatusRuleFallbackAndTerminalSkip(): void {
    $custom_status = $this->workflowStatus(4, FALSE);
    $default_status = $this->workflowStatus(0, FALSE);
    $terminal_status = $this->workflowStatus(12, TRUE);

    $this->assertSame(4, \_beantragt_notification_application_escalation_days($this->applicationWithWorkflowStatus($custom_status), 9));
    $this->assertSame(9, \_beantragt_notification_application_escalation_days($this->applicationWithWorkflowStatus($default_status), 9));
    $this->assertSame(0, \_beantragt_notification_application_escalation_days($this->applicationWithWorkflowStatus($terminal_status), 9));
  }

  /**
   * Application links point to the owning public/admin workspace route.
   */
  public function testResolveTokensBuildsWorkspaceApplicationUrls(): void {
    $jurisdiction = $this->groupEntity('jur', 'Musterstadt', 'musterstadt');
    $organisation = $this->groupEntity('org', 'Personalamt', 'personnel-office', 7);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnMap([
      [5, $organisation],
      [7, $jurisdiction],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($group_storage);

    $mailer = $this->createMailer($entity_type_manager, 'https://beantragt.example');

    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Dienstreiseantrag');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('MS-PA-2026-99005');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('vorgesetztenfreigabe_offen');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('julia.schneider@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_organisation'
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      'field_organisation' => $this->fieldList(['target_id' => 5]),
      default => $this->fieldList([], TRUE),
    });

    $tokens = $mailer->resolveTokens($application);

    $this->assertSame('Personalamt', $tokens['@org_name']);
    $this->assertSame(
      'https://beantragt.example/musterstadt/personnel-office/meine-antraege/e4f5ecb8-a351-4117-8034-1405feda1624',
      $tokens['@citizen_application_url']
    );
    $this->assertSame(
      'https://beantragt.example/musterstadt/personnel-office/admin/applications/e4f5ecb8-a351-4117-8034-1405feda1624',
      $tokens['@admin_application_url']
    );
  }

  /**
   * URL tokens stay empty without a trusted configured frontend base URL.
   */
  public function testResolveTokensRequiresConfiguredFrontendBaseUrl(): void {
    $jurisdiction = $this->groupEntity('jur', 'Musterstadt', 'musterstadt');
    $organisation = $this->groupEntity('org', 'Personalamt', 'personnel-office', 7);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnMap([
      [5, $organisation],
      [7, $jurisdiction],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($group_storage);

    $application = $this->applicationWithOrganisationScope();

    foreach (['', 'javascript:alert(1)'] as $frontend_base_url) {
      $mailer = $this->createMailer($entity_type_manager, $frontend_base_url);
      $tokens = $mailer->resolveTokens($application);

      $this->assertSame('', $tokens['@citizen_application_url']);
      $this->assertSame('', $tokens['@admin_application_url']);
    }
  }

  /**
   * Empty application-link tokens remove the containing body line.
   */
  public function testEmptyApplicationLinkTokenLineIsRemoved(): void {
    $body = "Hallo\nAntrag ansehen: @citizen_application_url\nViele Gruesse";

    $stripped = \_beantragt_notification_strip_empty_link_token_lines($body, [
      '@citizen_application_url' => '',
    ]);

    $this->assertSame("Hallo\nViele Gruesse", $stripped);
  }

  /**
   * Filled application-link tokens keep the containing body line.
   */
  public function testFilledApplicationLinkTokenLineIsKept(): void {
    $body = "Hallo\nAntrag ansehen: @citizen_application_url\nViele Gruesse";

    $stripped = \_beantragt_notification_strip_empty_link_token_lines($body, [
      '@citizen_application_url' => 'https://beantragt.example/musterstadt/meine-antraege/1',
    ]);

    $this->assertSame($body, $stripped);
  }

  /**
   * Citizen mails use the stored public submission locale.
   */
  public function testSendCitizenNotificationUsesSubmissionLocale(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key): mixed => $key === 'enabled_templates.application_confirmation' ? TRUE : NULL
    );
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? '' : NULL
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      static fn (string $name): ImmutableConfig => $name === 'beantragt_notification.settings' ? $settings : $group_settings
    );

    $default_language = $this->createMock(LanguageInterface::class);
    $default_language->method('getId')->willReturn('de');
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($default_language);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'application_confirmation',
        'erika@example.test',
        'en',
        $this->callback(static fn (array $params): bool => isset($params['replacements']['@reference_number'])),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $config_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $language_manager,
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getReferenceNumber')->willReturn('MS-2026-00001');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('new');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_workflow_attributes'
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => NULL], TRUE),
      'field_workflow_attributes' => $this->fieldList(['value' => '{"submissionLocale":"en"}']),
      default => $this->fieldList([], TRUE),
    });

    $mailer->sendCitizenNotification($application, 'application_confirmation');
  }

  /**
   * Extra citizen mail tokens override default account-only application links.
   */
  public function testSendCitizenNotificationAllowsSubmitStatusUrlOverride(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key): mixed => $key === 'enabled_templates.application_confirmation' ? TRUE : NULL
    );
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? 'https://beantragt.example' : NULL
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      static fn (string $name): ImmutableConfig => $name === 'beantragt_notification.settings' ? $settings : $group_settings
    );

    $default_language = $this->createMock(LanguageInterface::class);
    $default_language->method('getId')->willReturn('de');
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($default_language);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'application_confirmation',
        'erika@example.test',
        'de',
        $this->callback(static function (array $params): bool {
          return ($params['replacements']['@citizen_application_url'] ?? NULL)
            === 'https://beantragt.example/musterstadt/form/fbc3/status/public-token';
        }),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $config_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $language_manager,
    );

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('MS-2026-00001');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('new');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturn(FALSE);
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => NULL], TRUE),
      default => $this->fieldList([], TRUE),
    });

    $mailer->sendCitizenNotification(
      $application,
      'application_confirmation',
      ['@citizen_application_url' => 'https://beantragt.example/musterstadt/form/fbc3/status/public-token'],
    );
  }

  /**
   * Insert hook passes the request-local status URL to confirmation mail.
   */
  public function testApplicationInsertUsesSubmitStatusUrlForCitizenConfirmation(): void {
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->once())
      ->method('sendCitizenNotification')
      ->with(
        $this->isInstanceOf(ApplicationInterface::class),
        'application_confirmation',
        ['@citizen_application_url' => 'https://beantragt.example/musterstadt/form/fbc3/status/public-token'],
      );
    $mailer->expects($this->once())
      ->method('sendStaffNotification')
      ->with(
        $this->isInstanceOf(ApplicationInterface::class),
        'new_application_alert',
      );

    $container = new ContainerBuilder();
    $container->set('beantragt_notification.mailer', $mailer);
    \Drupal::setContainer($container);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('getSubmitStatusUrl')
      ->willReturn('https://beantragt.example/musterstadt/form/fbc3/status/public-token');

    \beantragt_notification_application_insert($application);
  }

  /**
   * Bulk notifications wait for commit and do nothing after rollback.
   */
  public function testBulkInsertDefersNotificationsUntilCommit(): void {
    $callback = NULL;
    $transactions = $this->createMock(TransactionManagerInterface::class);
    $transactions->method('inTransaction')->willReturn(TRUE);
    $transactions->expects($this->once())->method('addPostTransactionCallback')
      ->willReturnCallback(static function (callable $registered) use (&$callback): void {
        $callback = $registered;
      });
    $database = $this->createMock(Connection::class);
    $database->method('transactionManager')->willReturn($transactions);
    $origin = $this->createMock(FieldItemListInterface::class);
    $origin->method('__get')->with('value')->willReturn('bulk');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('hasField')->with('field_origin')->willReturn(TRUE);
    $application->method('get')->with('field_origin')->willReturn($origin);
    $application->method('id')->willReturn(7);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('load')->with(7)->willReturn($application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application')->willReturn($storage);
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->never())->method('sendCitizenNotification');
    $mailer->expects($this->once())->method('sendStaffNotification');
    $container = new ContainerBuilder();
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container->set('logger.factory', $loggerFactory);
    $container->set('database', $database);
    $container->set('entity_type.manager', $manager);
    $container->set('beantragt_notification.mailer', $mailer);
    \Drupal::setContainer($container);
    \beantragt_notification_application_insert($application);
    $this->assertIsCallable($callback);
    $callback(FALSE);
    $callback(TRUE);
  }

  /**
   * A manifest email does not opt staff intake into any citizen mail channel.
   */
  public function testBulkManifestEmailNeverReceivesCitizenMail(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->willReturn(TRUE);
    $application->method('get')->with('field_origin')->willReturn($this->fieldList(['value' => 'bulk']));
    $application->method('getData')->willReturn(json_encode(['email' => 'fictional@example.test']));
    $application->method('getApplicantEmail')->willReturn('fictional@example.test');
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');
    $mailer = new NotificationMailer($mail, $this->mailerConfigFactory(siteTemplateEnabled: TRUE), $this->createMock(EntityTypeManagerInterface::class), $this->createMock(LoggerInterface::class), $this->languageManager('de'));
    foreach (['application_confirmation', 'status_change'] as $template) {
      $this->assertFalse($mailer->sendCitizenNotificationWithResult($application, $template));
    }
  }

  /**
   * Insert hook suppresses receipt and staff mail before double opt-in.
   */
  public function testApplicationInsertSkipsUnconfirmedApplications(): void {
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects($this->never())->method('sendCitizenNotification');
    $mailer->expects($this->never())->method('sendStaffNotification');

    $container = new ContainerBuilder();
    $container->set('beantragt_notification.mailer', $mailer);
    \Drupal::setContainer($container);

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(FALSE);

    \beantragt_notification_application_insert($application);
  }

  /**
   * Staff notifications fall back to the jurisdiction mailbox without org.
   */
  public function testSendStaffNotificationUsesJurisdictionMailboxFallback(): void {
    $jurisdiction = $this->groupEntity('jur', 'Stadt Musterstadt', 'musterstadt', NULL, 'service@musterstadt.example.org');

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnMap([
      [7, $jurisdiction],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($group_storage);

    $mailer = $this->createStaffMailer($entity_type_manager, 'service@musterstadt.example.org', 'de');

    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Hund abmelden');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(18);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('MS-2026-00018');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('eingegangen');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, ['field_schema', 'field_jurisdiction'], TRUE)
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      'field_jurisdiction' => $this->fieldList(['target_id' => 7]),
      default => $this->fieldList([], TRUE),
    });

    $mailer->sendStaffNotification($application, 'new_application_alert');
  }

  /**
   * Staff notifications do not fall back when an org lacks a mailbox.
   */
  public function testSendStaffNotificationDoesNotFallbackForMailboxlessOrg(): void {
    $jurisdiction = $this->groupEntity('jur', 'Stadt Musterstadt', 'musterstadt', NULL, 'service@musterstadt.example.org');
    $organisation = $this->groupEntity('org', 'Buergerbuero', 'citizen-services', 7);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturnMap([
      [5, $organisation],
      [7, $jurisdiction],
    ]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($group_storage);

    $mailer = $this->createStaffMailerWithoutSend($entity_type_manager);

    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Hund abmelden');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(19);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('MS-2026-00019');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('eingegangen');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array($field, ['field_schema', 'field_organisation'], TRUE)
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      'field_organisation' => $this->fieldList(['target_id' => 5]),
      default => $this->fieldList([], TRUE),
    });

    $mailer->sendStaffNotification($application, 'new_application_alert');
  }

  /**
   * Assignment mails are sent to the assigned user's email address.
   */
  public function testSendAssignmentNotificationUsesAssignedUserEmail(): void {
    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createAssignmentMailer('person@example.test', 'de', outcomeCollector: $collector);

    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('person@example.test');
    $user->method('getDisplayName')->willReturn('Vera Verwaltung');

    $application = $this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
    ]);

    $mailer->sendAssignmentNotification($application);

    $this->assertSame([
      [
        'key' => 'assignment_alert',
        'recipient_kind' => 'person',
        'status' => 'sent',
      ],
    ], $collector->drain());
  }

  /**
   * Assignment mails are suppressed when the acting user is the assignee.
   */
  public function testSendAssignmentNotificationSkipsSelfAssignmentPerson(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(42);
    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createAssignmentMailer(
      'person@example.test',
      'de',
      $current_user,
      expectSend: FALSE,
      outcomeCollector: $collector,
    );

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);
    $user->method('getEmail')->willReturn('person@example.test');

    $mailer->sendAssignmentNotification($this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
    ]));

    $this->assertSame([
      [
        'key' => 'assignment_alert',
        'recipient_kind' => 'person',
        'status' => 'skipped_self_assignment',
      ],
    ], $collector->drain());
  }

  /**
   * Assignment mail delivery failures are recorded without recipient PII.
   */
  public function testSendAssignmentNotificationRecordsFailedDelivery(): void {
    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createAssignmentMailer(
      'person@example.test',
      'de',
      mailResult: FALSE,
      outcomeCollector: $collector,
    );

    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('person@example.test');
    $user->method('getDisplayName')->willReturn('Vera Verwaltung');
    $mailer->sendAssignmentNotification($this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
    ]));

    $this->assertSame([
      [
        'key' => 'assignment_alert',
        'recipient_kind' => 'person',
        'status' => 'failed',
      ],
    ], $collector->drain());
  }

  /**
   * Disabled assignment templates are recorded with the resolved kind.
   */
  public function testSendAssignmentNotificationRecordsDisabledTemplate(): void {
    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createAssignmentMailer(
      'team@example.test',
      'de',
      expectSend: FALSE,
      templateEnabled: FALSE,
      outcomeCollector: $collector,
    );
    $team = $this->createMock(FieldableEntityInterface::class);
    $team->method('hasField')->with('field_org_email')->willReturn(TRUE);
    $team->method('get')->with('field_org_email')->willReturn(
      $this->fieldList(['value' => 'team@example.test']),
    );

    $mailer->sendAssignmentNotification($this->baseAssignmentApplication([
      'field_assigned_team' => $this->fieldList(['entity' => $team]),
    ]));

    $this->assertSame([
      [
        'key' => 'assignment_alert',
        'recipient_kind' => 'org_mailbox',
        'status' => 'skipped_disabled',
      ],
    ], $collector->drain());
  }

  /**
   * Missing assignment recipients are recorded without an inferred kind.
   */
  public function testSendAssignmentNotificationRecordsMissingRecipient(): void {
    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createAssignmentMailer(
      'unused@example.test',
      'de',
      expectSend: FALSE,
      outcomeCollector: $collector,
    );

    $mailer->sendAssignmentNotification($this->baseAssignmentApplication([]));

    $this->assertSame([
      [
        'key' => 'assignment_alert',
        'recipient_kind' => NULL,
        'status' => 'skipped_no_recipient',
      ],
    ], $collector->drain());
  }

  /**
   * Assignment mails are sent to the assigned team's org mailbox.
   */
  public function testSendAssignmentNotificationKeepsTeamMailboxForActingUser(): void {
    $current_user = $this->createMock(AccountInterface::class);
    $current_user->method('id')->willReturn(42);
    $collector = new NotificationOutcomeCollector();
    $mailer = $this->createAssignmentMailer(
      'team@example.test',
      'de',
      $current_user,
      outcomeCollector: $collector,
    );

    $team = $this->createMock(FieldableEntityInterface::class);
    $team->method('label')->willReturn('Team Bauamt');
    $team->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_org_email'
    );
    $team->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_org_email' => $this->fieldList(['value' => 'team@example.test']),
      default => $this->fieldList([], TRUE),
    });

    $application = $this->baseAssignmentApplication([
      'field_assigned_team' => $this->fieldList(['entity' => $team]),
    ]);

    $mailer->sendAssignmentNotification($application);

    $this->assertSame([
      [
        'key' => 'assignment_alert',
        'recipient_kind' => 'org_mailbox',
        'status' => 'sent',
      ],
    ], $collector->drain());
  }

  /**
   * Citizen notifications pass resolver-provided raw template text.
   */
  public function testSendCitizenNotificationPassesResolvedTemplate(): void {
    $jurisdiction = $this->groupInterfaceEntity(
      'jur',
      'Stadt Dunkelstadt',
      'dunkelstadt',
      NULL,
      'service@dunkelstadt.example',
      "Stadt Dunkelstadt\nDer Oberbuergermeister",
    );
    $entity_type_manager = $this->entityTypeManagerForGroups([7 => $jurisdiction]);
    $resolver = $this->resolverForTemplate(
      'application_confirmation',
      $jurisdiction,
      [
        'application_confirmation' => [
          'enabled' => TRUE,
          'translations' => [
            'en' => [
              'subject' => 'DUNKELSTADT EN raw @reference_number',
              'body' => 'DUNKELSTADT EN body @citizen_application_url',
            ],
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'application_confirmation',
        'erika@example.test',
        'en',
        $this->callback(static function (array $params): bool {
          return ($params['resolvedTemplate']['subject'] ?? NULL) === 'DUNKELSTADT EN raw @reference_number'
            && ($params['resolvedTemplate']['body'] ?? NULL) === 'DUNKELSTADT EN body @citizen_application_url'
            && ($params['emailFooter'] ?? NULL) === "Stadt Dunkelstadt\nDer Oberbuergermeister"
            && isset($params['replacements']['@reference_number']);
        }),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $entity_type_manager,
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $mailer->sendCitizenNotification(
      $this->notificationApplicationWithJurisdiction(['submissionLocale' => 'en']),
      'application_confirmation',
    );
  }

  /**
   * A citizen template with @assigned_to never names the staff member.
   *
   * Workspace admins may add the token to a citizen template; the mail must
   * still render without the assignee's name. The team stays, it is an
   * office and not a person.
   */
  public function testCitizenMailBlanksAssigneeName(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');
    $resolver = $this->resolverForTemplate(
      'status_change',
      $jurisdiction,
      [
        'status_change' => [
          'enabled' => TRUE,
          'translations' => [
            'en' => [
              'subject' => 'Status @reference_number',
              'body' => 'Handled by @assigned_to (@assigned_team)',
            ],
          ],
        ],
      ],
    );

    $captured = NULL;
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willReturnCallback(static function (string $module, string $key, string $to, string $langcode, array $params) use (&$captured): array {
        $captured = $params;
        return ['result' => TRUE];
      });

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('getDisplayName')->willReturn('Vera Verwaltung');
    $team = $this->createMock(EntityInterface::class);
    $team->method('label')->willReturn('Team Hundesteuer');
    $application = $this->notificationApplicationWithJurisdiction(['submissionLocale' => 'en'], [
      'field_assigned_to' => $this->fieldList(['entity' => $assignee]),
      'field_assigned_team' => $this->fieldList(['entity' => $team]),
    ]);

    // The shared token set still carries the name for staff mails.
    $this->assertSame('Vera Verwaltung', $mailer->resolveTokens($application)['@assigned_to']);

    $this->assertTrue($mailer->sendCitizenNotificationWithResult($application, 'status_change', ['@assigned_to' => 'Vera Verwaltung']));
    $this->assertIsArray($captured);
    $this->assertSame('', $captured['replacements']['@assigned_to']);

    $message = ['langcode' => 'en', 'body' => []];
    \beantragt_notification_mail('status_change', $message, $captured);
    $this->assertStringNotContainsString('Vera', $message['body'][0]);
    $this->assertStringContainsString('Team Hundesteuer', $message['body'][0]);
  }

  /**
   * Citizen notifications use the resolver enabled flag as the send gate.
   */
  public function testSendCitizenNotificationSkipsWhenResolverDisablesTemplate(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');
    $resolver = $this->resolverForTemplate(
      'application_confirmation',
      $jurisdiction,
      [
        'application_confirmation' => [
          'enabled' => FALSE,
          'default' => [
            'subject' => 'Disabled subject',
            'body' => 'Disabled body',
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $mailer->sendCitizenNotification(
      $this->notificationApplicationWithJurisdiction(),
      'application_confirmation',
    );
  }

  /**
   * Staff notifications pass resolver-provided raw template text.
   */
  public function testSendStaffNotificationPassesResolvedTemplate(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');
    $resolver = $this->resolverForTemplate(
      'new_application_alert',
      $jurisdiction,
      [
        'new_application_alert' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'DUNKELSTADT staff raw @reference_number',
            'body' => 'DUNKELSTADT staff body @admin_application_url',
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'new_application_alert',
        'service@dunkelstadt.example',
        'de',
        $this->callback(static function (array $params): bool {
          return ($params['resolvedTemplate']['subject'] ?? NULL) === 'DUNKELSTADT staff raw @reference_number'
            && ($params['resolvedTemplate']['body'] ?? NULL) === 'DUNKELSTADT staff body @admin_application_url'
            && isset($params['replacements']['@reference_number']);
        }),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $this->assertTrue($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'new_application_alert',
    ));
  }

  /**
   * A reserved demo mailbox skipped by the mail fence counts as handled.
   *
   * Reminder loops only advance their state on TRUE, so treating the fence's
   * result NULL as a failure would retry and log an error on every cron run.
   */
  public function testSendStaffNotificationTreatsReservedRecipientSkipAsHandled(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Sampletown', 'sampletown', NULL, 'citizen-services@sampletown.example.org');
    $resolver = $this->resolverForTemplate(
      'resubmission_reminder',
      $jurisdiction,
      [
        'resubmission_reminder' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'Wiedervorlage @reference_number',
            'body' => 'Bitte pruefen.',
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willReturn([
        'send' => FALSE,
        'result' => NULL,
        'beantragt_reserved_recipient_suppressed' => TRUE,
      ]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $logger,
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $this->assertTrue($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'resubmission_reminder',
    ));
  }

  /**
   * Staff notifications use the resolver enabled flag as the send gate.
   */
  public function testSendStaffNotificationSkipsWhenResolverDisablesTemplate(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');
    $resolver = $this->resolverForTemplate(
      'new_application_alert',
      $jurisdiction,
      [
        'new_application_alert' => [
          'enabled' => FALSE,
          'default' => [
            'subject' => 'Disabled subject',
            'body' => 'Disabled body',
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $this->assertFalse($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'new_application_alert',
    ));
  }

  /**
   * The scheduler distinguishes skipped recipients from transport errors.
   */
  public function testStaffMailOutcomeClassifiesPermanentAndTransientFailures(): void {
    $application = $this->notificationApplicationWithJurisdiction();
    $manager = $this->createMock(MailManagerInterface::class);
    $manager->expects($this->once())->method('mail')->willReturn(['result' => FALSE]);
    $mailer = $this->getMockBuilder(NotificationMailer::class)
      ->setConstructorArgs([
        $manager,
        $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
        $this->entityTypeManagerForGroups([7 => $this->groupInterfaceEntity('jur', 'Fixture', 'fixture', NULL, 'staff@fixture.example')]),
        $this->createMock(LoggerInterface::class),
        $this->languageManager('de'),
      ])
      ->onlyMethods(['resolveStaffRecipient', 'resolveTokens'])
      ->getMock();
    $mailer->method('resolveTokens')->willReturn([]);
    $mailer->method('resolveStaffRecipient')->willReturnOnConsecutiveCalls(NULL, 'staff@fixture.example');
    $this->assertSame(StaffMailOutcome::NoRecipient, $mailer->sendStaffNotificationOutcome($application, 'escalation_alert'));
    $this->assertSame(StaffMailOutcome::TransportError, $mailer->sendStaffNotificationOutcome($application, 'escalation_alert'));
    $disabled = new NotificationMailer(
      $manager,
      $this->mailerConfigFactory(siteTemplateEnabled: FALSE),
      $this->entityTypeManagerForGroups([7 => $this->groupInterfaceEntity('jur', 'Fixture', 'fixture', NULL, 'staff@fixture.example')]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
    );
    $this->assertSame(StaffMailOutcome::Disabled, $disabled->sendStaffNotificationOutcome($application, 'escalation_alert'));
  }

  /**
   * Delegation alerts respect the site-level enabled template gate.
   */
  public function testDelegationAlertSkipsWhenSiteTemplateDisabled(): void {
    $collector = new NotificationOutcomeCollector();
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: FALSE),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      NULL,
      NULL,
      $collector,
    );

    $this->assertFalse($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'delegation_alert',
      [
        '@source_org' => 'Personalamt',
        '@target_org' => 'Bauamt',
        '@note' => '',
      ],
    ));
    $this->assertSame([
      [
        'key' => 'delegation_alert',
        'recipient_kind' => NULL,
        'status' => 'skipped_disabled',
      ],
    ], $collector->drain());
  }

  /**
   * Delegation delivery outcomes identify an organisation mailbox without PII.
   */
  public function testDelegationAlertRecordsSentOutcome(): void {
    $jurisdiction = $this->groupInterfaceEntity(
      'jur',
      'Stadt Dunkelstadt',
      'dunkelstadt',
      NULL,
      'service@dunkelstadt.example',
    );
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);
    $collector = new NotificationOutcomeCollector();
    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      NULL,
      NULL,
      $collector,
    );

    $this->assertTrue($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'delegation_alert',
    ));
    $this->assertSame([
      [
        'key' => 'delegation_alert',
        'recipient_kind' => 'org_mailbox',
        'status' => 'sent',
      ],
    ], $collector->drain());
  }

  /**
   * Resubmission reminders respect the site-level enabled template gate.
   */
  public function testResubmissionReminderSkipsWhenSiteTemplateDisabled(): void {
    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: FALSE),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
    );

    $this->assertFalse($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'resubmission_reminder',
      [
        '@days_idle' => '42',
        '@reminder_count' => '1',
      ],
    ));
  }

  /**
   * Resubmission reminders redact URL and applicant-name tokens.
   */
  public function testResubmissionReminderRedactsLinkAndApplicantTokens(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'resubmission_reminder',
        'service@dunkelstadt.example',
        'de',
        $this->callback(static fn (array $params): bool =>
          ($params['replacements']['@full_name'] ?? NULL) === ''
          && ($params['replacements']['@citizen_application_url'] ?? NULL) === ''
          && ($params['replacements']['@admin_application_url'] ?? NULL) === ''
          && ($params['replacements']['@days_idle'] ?? NULL) === '42'
          && ($params['replacements']['@reminder_count'] ?? NULL) === '1'
        ),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
    );

    $this->assertTrue($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'resubmission_reminder',
      [
        '@days_idle' => '42',
        '@reminder_count' => '1',
      ],
    ));
  }

  /**
   * Org-scoped staff recipients use the workspace mailbox.
   */
  public function testResolveStaffRecipientUsesOrgScopeMailbox(): void {
    $organisation = $this->groupInterfaceEntity('org', 'Buergerbuero', 'citizen-services', 7, 'org@example.test');
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Musterstadt', 'musterstadt', NULL, 'jur@example.test');

    $mailer = $this->createMailer($this->entityTypeManagerForGroups([
      5 => $organisation,
      7 => $jurisdiction,
    ]));

    $application = $this->baseAssignmentApplication([
      'field_organisation' => $this->fieldList(['target_id' => 5]),
    ]);

    $this->assertSame(
      'org@example.test',
      $mailer->resolveStaffRecipient($application, ResubmissionReminderResolver::SCOPE_ORG),
    );
  }

  /**
   * User-scoped staff recipients prefer the assigned user email.
   */
  public function testResolveStaffRecipientUsesAssignedUserForUserScope(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('person@example.test');
    $user->method('getDisplayName')->willReturn('Vera Verwaltung');

    $mailer = $this->createMailer();
    $application = $this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
    ]);

    $this->assertSame(
      'person@example.test',
      $mailer->resolveStaffRecipient($application, ResubmissionReminderResolver::SCOPE_USER),
    );
  }

  /**
   * User-scoped staff recipients fall back to the org mailbox when unassigned.
   */
  public function testResolveStaffRecipientFallsBackToOrgWhenAssigneeEmpty(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('');
    $user->method('getDisplayName')->willReturn('Vera Verwaltung');
    $organisation = $this->groupInterfaceEntity('org', 'Buergerbuero', 'citizen-services', 7, 'org@example.test');

    $mailer = $this->createMailer($this->entityTypeManagerForGroups([
      5 => $organisation,
    ]));
    $application = $this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
      'field_organisation' => $this->fieldList(['target_id' => 5]),
    ]);

    $this->assertSame(
      'org@example.test',
      $mailer->resolveStaffRecipient($application, ResubmissionReminderResolver::SCOPE_USER),
    );
  }

  /**
   * User-scoped staff recipients continue to team when user email is invalid.
   */
  public function testResolveStaffRecipientUsesTeamWhenAssigneeEmailInvalid(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('not-an-email');
    $user->method('getDisplayName')->willReturn('Vera Verwaltung');
    $team = $this->groupInterfaceEntity('org', 'Team Bauamt', 'team-bauamt', 7, 'team@example.test');

    $mailer = $this->createMailer();
    $application = $this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
      'field_assigned_team' => $this->fieldList(['entity' => $team]),
    ]);

    $this->assertSame(
      'team@example.test',
      $mailer->resolveStaffRecipient($application, ResubmissionReminderResolver::SCOPE_USER),
    );
  }

  /**
   * Scheduled staff alerts keep the legacy site-config fallback path.
   */
  public function testSendStaffNotificationKeepsScheduledAlertsOnSiteConfigPath(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'deadline_alert',
        'service@dunkelstadt.example',
        'de',
        $this->callback(static function (array $params): bool {
          return isset($params['replacements']['@reference_number'])
            && !isset($params['resolvedTemplate']);
        }),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $this->resolverThatMustNotBeCalled(),
    );

    $this->assertTrue($mailer->sendStaffNotification(
      $this->notificationApplicationWithJurisdiction(),
      'deadline_alert',
    ));
  }

  /**
   * Assignment notifications pass resolver-provided raw template text.
   */
  public function testSendAssignmentNotificationUsesAssigneeLangcodeForResolvedTemplate(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');
    $resolver = $this->resolverForTemplate(
      'assignment_alert',
      $jurisdiction,
      [
        'assignment_alert' => [
          'enabled' => TRUE,
          'translations' => [
            'en' => [
              'subject' => 'DUNKELSTADT assignment raw @reference_number',
              'body' => 'DUNKELSTADT assignment body @assigned_to',
            ],
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'assignment_alert',
        'person@example.test',
        'en',
        $this->callback(static function (array $params): bool {
          return ($params['resolvedTemplate']['subject'] ?? NULL) === 'DUNKELSTADT assignment raw @reference_number'
            && ($params['resolvedTemplate']['body'] ?? NULL) === 'DUNKELSTADT assignment body @assigned_to'
            && isset($params['replacements']['@reference_number']);
        }),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);
    $user->method('getEmail')->willReturn('person@example.test');
    $user->method('getDisplayName')->willReturn('Vera Verwaltung');
    $user->method('getPreferredLangcode')->with(FALSE)->willReturn('en');

    $mailer->sendAssignmentNotification($this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
      'field_jurisdiction' => $this->fieldList(['target_id' => 7]),
    ]));
  }

  /**
   * Assignment notifications use the resolver enabled flag as the send gate.
   */
  public function testSendAssignmentNotificationSkipsWhenResolverDisablesTemplate(): void {
    $jurisdiction = $this->groupInterfaceEntity('jur', 'Stadt Dunkelstadt', 'dunkelstadt', NULL, 'service@dunkelstadt.example');
    $resolver = $this->resolverForTemplate(
      'assignment_alert',
      $jurisdiction,
      [
        'assignment_alert' => [
          'enabled' => FALSE,
          'default' => [
            'subject' => 'Disabled subject',
            'body' => 'Disabled body',
          ],
        ],
      ],
    );

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    $mailer = new NotificationMailer(
      $mail_manager,
      $this->mailerConfigFactory(siteTemplateEnabled: TRUE),
      $this->entityTypeManagerForGroups([7 => $jurisdiction]),
      $this->createMock(LoggerInterface::class),
      $this->languageManager('de'),
      NULL,
      $resolver,
    );

    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('person@example.test');

    $mailer->sendAssignmentNotification($this->baseAssignmentApplication([
      'field_assigned_to' => $this->fieldList(['entity' => $user]),
      'field_jurisdiction' => $this->fieldList(['target_id' => 7]),
    ]));
  }

  /**
   * The mail hook prefers resolver-provided template text over site config.
   */
  public function testHookMailUsesResolvedTemplateBeforeSiteConfig(): void {
    $message = ['langcode' => 'de', 'body' => []];

    \beantragt_notification_mail('application_confirmation', $message, [
      'resolvedTemplate' => [
        'subject' => 'Resolved subject @reference_number',
        'body' => "Resolved body @reference_number\nLink: @citizen_application_url",
      ],
      'replacements' => [
        '@reference_number' => 'DUNKELSTADT-2026-00001',
        '@citizen_application_url' => '',
      ],
    ]);

    $this->assertSame('Resolved subject DUNKELSTADT-2026-00001', $message['subject']);
    $this->assertSame('Resolved body DUNKELSTADT-2026-00001', $message['body'][0]);
  }

  /**
   * The mail hook appends a configured jurisdiction footer as plain text.
   */
  public function testHookMailAppendsJurisdictionEmailFooter(): void {
    $message = ['langcode' => 'de', 'body' => []];

    \beantragt_notification_mail('application_confirmation', $message, [
      'resolvedTemplate' => [
        'subject' => 'Resolved subject',
        'body' => 'Resolved body',
      ],
      'replacements' => [],
      'emailFooter' => "Stadt Dunkelstadt\nDer Oberbuergermeister",
    ]);

    $this->assertSame(
      "Resolved body\n\n-- \nStadt Dunkelstadt\nDer Oberbuergermeister",
      $message['body'][0],
    );
  }

  /**
   * The mail hook keeps the existing fallback without resolved text.
   */
  public function testHookMailFallsBackToSiteConfigWithoutResolvedTemplate(): void {
    $mail_config = $this->createMock(ImmutableConfig::class);
    $mail_config->method('get')
      ->willReturnCallback(static fn (string $key): ?array => $key === 'application_confirmation' ? [
        'subject' => 'Site subject @reference_number',
        'body' => 'Site body @reference_number',
      ] : NULL);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('beantragt_notification.mail')
      ->willReturn($mail_config);

    $container = new ContainerBuilder();
    $container->set('language_manager', $this->createMock(LanguageManagerInterface::class));
    $container->set('config.factory', $config_factory);
    \Drupal::setContainer($container);

    $message = ['langcode' => 'de', 'body' => []];

    \beantragt_notification_mail('application_confirmation', $message, [
      'replacements' => [
        '@reference_number' => 'SITE-2026-00002',
      ],
    ]);

    $this->assertSame('Site subject SITE-2026-00002', $message['subject']);
    $this->assertSame('Site body SITE-2026-00002', $message['body'][0]);
  }

  /**
   * Creates a resolver backed by one expected jurisdiction config lookup.
   *
   * @param string $templateKey
   *   Template id being resolved.
   * @param \Drupal\group\Entity\GroupInterface $expectedJurisdiction
   *   Jurisdiction expected by the resolver.
   * @param array<string, mixed> $jurisdictionTemplates
   *   Stored jurisdiction templates keyed by template id.
   */
  private function resolverForTemplate(
    string $templateKey,
    GroupInterface $expectedJurisdiction,
    array $jurisdictionTemplates,
  ): NotificationTemplateResolver {
    $catalog = $this->createMock(NotificationTemplateCatalogInterface::class);
    $catalog->method('get')
      ->willReturnCallback(static fn (string $key): ?array => $key === $templateKey ? [
        'subject' => 'Catalog subject @reference_number',
        'body' => 'Catalog body @reference_number',
        'enabled' => TRUE,
        'requiredTokens' => ['@reference_number'],
        'lockedEnabled' => FALSE,
      ] : NULL);
    $catalog->method('isLockedEnabled')->willReturn(FALSE);

    $jurisdiction_config = $this->createMock(JurisdictionConfigServiceInterface::class);
    $jurisdiction_config->expects($this->once())
      ->method('getSection')
      ->with($this->identicalTo($expectedJurisdiction), 'notification_mail')
      ->willReturn(['templates' => $jurisdictionTemplates]);

    $site_mail_config = $this->createMock(Config::class);
    $site_mail_config->method('get')
      ->willReturnCallback(static fn (string $key): ?array => $key === $templateKey ? [
        'subject' => 'Site subject @reference_number',
        'body' => 'Site body @reference_number',
      ] : NULL);

    $settings_config = $this->createMock(ImmutableConfig::class);
    $settings_config->method('get')
      ->willReturnCallback(static fn (string $key): mixed => $key === 'enabled_templates.' . $templateKey ? FALSE : NULL);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')
      ->with('beantragt_notification.mail')
      ->willReturn($site_mail_config);
    $config_factory->method('get')
      ->with('beantragt_notification.settings')
      ->willReturn($settings_config);

    $empty_override = $this->createMock(Config::class);
    $empty_override->method('get')->willReturn(NULL);
    $language_override = $this->createMock(LanguageConfigFactoryOverrideInterface::class);
    $language_override->method('getOverride')->willReturn($empty_override);

    return new NotificationTemplateResolver(
      $catalog,
      $jurisdiction_config,
      $config_factory,
      $this->languageManager('de'),
      $language_override,
    );
  }

  /**
   * Creates a resolver whose use would fail the test.
   */
  private function resolverThatMustNotBeCalled(): NotificationTemplateResolver {
    $catalog = $this->createMock(NotificationTemplateCatalogInterface::class);
    $catalog->expects($this->never())->method('get');
    $catalog->expects($this->never())->method('isLockedEnabled');

    $jurisdiction_config = $this->createMock(JurisdictionConfigServiceInterface::class);
    $jurisdiction_config->expects($this->never())->method('getSection');

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->expects($this->never())->method('getEditable');

    $language_override = $this->createMock(LanguageConfigFactoryOverrideInterface::class);
    $language_override->expects($this->never())->method('getOverride');

    return new NotificationTemplateResolver(
      $catalog,
      $jurisdiction_config,
      $config_factory,
      $this->languageManager('de'),
      $language_override,
    );
  }

  /**
   * Creates a mailer config factory with group settings and legacy settings.
   */
  private function mailerConfigFactory(
    string $frontendBaseUrl = 'https://beantragt.example',
    bool $siteTemplateEnabled = FALSE,
  ): ConfigFactoryInterface {
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? $frontendBaseUrl : NULL
    );

    $notification_settings = $this->createMock(ImmutableConfig::class);
    $notification_settings->method('get')->willReturnCallback(
      static fn (string $key): mixed => str_starts_with($key, 'enabled_templates.') ? $siteTemplateEnabled : NULL
    );

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      static fn (string $name): ImmutableConfig => $name === 'beantragt_notification.settings'
        ? $notification_settings
        : $group_settings
    );

    return $config_factory;
  }

  /**
   * Creates a language manager returning the requested default language.
   */
  private function languageManager(string $langcode): LanguageManagerInterface {
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn(new Language(['id' => $langcode]));
    return $language_manager;
  }

  /**
   * Creates an entity type manager that loads the provided groups by id.
   *
   * @param array<int, \Drupal\group\Entity\GroupInterface> $groups
   *   Group mocks keyed by entity id.
   */
  private function entityTypeManagerForGroups(array $groups): EntityTypeManagerInterface {
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')
      ->willReturnCallback(static fn (int $id): ?GroupInterface => $groups[$id] ?? NULL);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->with('group')
      ->willReturn($group_storage);

    return $entity_type_manager;
  }

  /**
   * Creates an application mock scoped directly to a jurisdiction.
   *
   * @param array<string, mixed>|null $workflowAttributes
   *   Optional workflow attributes JSON payload.
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $fields
   *   Additional non-empty fields keyed by field name.
   */
  private function notificationApplicationWithJurisdiction(?array $workflowAttributes = NULL, array $fields = []): ApplicationInterface {
    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Hundesteuer anmelden');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(31);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('DUNKELSTADT-2026-00031');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('new');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('erika@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => isset($fields[$field]) || in_array(
        $field,
        array_filter([
          'field_schema',
          'field_jurisdiction',
          $workflowAttributes === NULL ? NULL : 'field_workflow_attributes',
        ]),
        TRUE
      )
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      'field_jurisdiction' => $this->fieldList(['target_id' => 7]),
      'field_workflow_attributes' => $this->fieldList(['value' => json_encode($workflowAttributes)]),
      default => $fields[$field] ?? $this->fieldList([], TRUE),
    });

    return $application;
  }

  /**
   * Creates a group mock that also satisfies the GroupInterface resolver type.
   */
  private function groupInterfaceEntity(
    string $bundle,
    string $label,
    string $slug,
    ?int $jurisdictionTargetId = NULL,
    ?string $email = NULL,
    ?string $emailFooter = NULL,
  ): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);
    $group->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_slug'
        || ($field === 'field_jurisdiction' && $jurisdictionTargetId !== NULL)
        || ($field === 'field_org_email' && $email !== NULL)
        || ($field === 'field_email_footer' && $emailFooter !== NULL)
    );
    $group->method('get')->willReturnCallback(function (string $field) use ($slug, $jurisdictionTargetId, $email, $emailFooter): FieldItemListInterface {
      if ($field === 'field_slug') {
        return $this->fieldList(['value' => $slug]);
      }
      if ($field === 'field_jurisdiction' && $jurisdictionTargetId !== NULL) {
        return $this->fieldList(['target_id' => $jurisdictionTargetId]);
      }
      if ($field === 'field_org_email' && $email !== NULL) {
        return $this->fieldList(['value' => $email]);
      }
      if ($field === 'field_email_footer' && $emailFooter !== NULL) {
        return $this->fieldList(['value' => $emailFooter]);
      }
      return $this->fieldList([], TRUE);
    });

    return $group;
  }

  /**
   * Creates a notification mailer with a configured frontend base URL.
   */
  private function createMailer(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    string $frontendBaseUrl = 'https://beantragt.example',
    ?ApplicationDataProtectionService $dataProtection = NULL,
  ): NotificationMailer {
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? $frontendBaseUrl : NULL
    );

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($group_settings);

    return new NotificationMailer(
      $this->createMock(MailManagerInterface::class),
      $config_factory,
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $dataProtection,
    );
  }

  /**
   * Creates a mailer that expects one assignment mail to a recipient.
   *
   * @param string $recipient
   *   Expected recipient address.
   * @param string $langcode
   *   Expected mail language.
   * @param \Drupal\Core\Session\AccountInterface|null $currentUser
   *   Acting account for self-assignment checks.
   * @param string $defaultLangcode
   *   Site default language.
   * @param bool $expectSend
   *   Whether one assignment mail is expected.
   * @param bool $mailResult
   *   Whether Drupal accepts the assignment mail for delivery.
   * @param bool $templateEnabled
   *   Whether the assignment template is enabled.
   * @param \Drupal\beantragt_notification\Service\NotificationOutcomeCollector|null $outcomeCollector
   *   Optional notification outcome collector.
   */
  private function createAssignmentMailer(
    string $recipient,
    string $langcode,
    ?AccountInterface $currentUser = NULL,
    string $defaultLangcode = 'de',
    bool $expectSend = TRUE,
    bool $mailResult = TRUE,
    bool $templateEnabled = TRUE,
    ?NotificationOutcomeCollector $outcomeCollector = NULL,
  ): NotificationMailer {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key): mixed => $key === 'enabled_templates.assignment_alert' ? $templateEnabled : NULL
    );
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? '' : NULL
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      static fn (string $name): ImmutableConfig => $name === 'beantragt_notification.settings' ? $settings : $group_settings
    );

    $default_language = $this->createMock(LanguageInterface::class);
    $default_language->method('getId')->willReturn($defaultLangcode);
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($default_language);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_expectation = $mail_manager->expects($expectSend ? $this->once() : $this->never())
      ->method('mail');
    if ($expectSend) {
      $mail_expectation->with(
        'beantragt_notification',
        'assignment_alert',
        $recipient,
        $langcode,
        $this->callback(static fn (array $params): bool => isset($params['replacements']['@reference_number'])),
        NULL,
        TRUE
      )->willReturn(['result' => $mailResult]);
    }

    return new NotificationMailer(
      $mail_manager,
      $config_factory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      $language_manager,
      NULL,
      NULL,
      $currentUser,
      $outcomeCollector,
    );
  }

  /**
   * Creates a mailer that expects one staff notification to a recipient.
   */
  private function createStaffMailer(
    EntityTypeManagerInterface $entityTypeManager,
    string $recipient,
    string $langcode,
  ): NotificationMailer {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key): mixed => $key === 'enabled_templates.new_application_alert' ? TRUE : NULL
    );
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? '' : NULL
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      static fn (string $name): ImmutableConfig => $name === 'beantragt_notification.settings' ? $settings : $group_settings
    );

    $default_language = $this->createMock(LanguageInterface::class);
    $default_language->method('getId')->willReturn($langcode);
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($default_language);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_notification',
        'new_application_alert',
        $recipient,
        $langcode,
        $this->callback(static fn (array $params): bool => isset($params['replacements']['@reference_number'])),
        NULL,
        TRUE
      )
      ->willReturn(['result' => TRUE]);

    return new NotificationMailer(
      $mail_manager,
      $config_factory,
      $entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $language_manager,
    );
  }

  /**
   * Creates a staff mailer that must not dispatch an email.
   */
  private function createStaffMailerWithoutSend(EntityTypeManagerInterface $entityTypeManager): NotificationMailer {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key): mixed => $key === 'enabled_templates.new_application_alert' ? TRUE : NULL
    );
    $group_settings = $this->createMock(ImmutableConfig::class);
    $group_settings->method('get')->willReturnCallback(
      static fn (string $key): ?string => $key === 'frontend_base_url' ? '' : NULL
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnCallback(
      static fn (string $name): ImmutableConfig => $name === 'beantragt_notification.settings' ? $settings : $group_settings
    );

    $default_language = $this->createMock(LanguageInterface::class);
    $default_language->method('getId')->willReturn('de');
    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn($default_language);

    $mail_manager = $this->createMock(MailManagerInterface::class);
    $mail_manager->expects($this->never())->method('mail');

    return new NotificationMailer(
      $mail_manager,
      $config_factory,
      $entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $language_manager,
    );
  }

  /**
   * Creates a minimal application mock for assignment-mail tests.
   *
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $fields
   *   Non-empty fields keyed by field name.
   */
  private function baseAssignmentApplication(array $fields): ApplicationInterface {
    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Gewerbeanmeldung');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(17);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-0017');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('new');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('applicant@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_schema' || isset($fields[$field])
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      default => $fields[$field] ?? $this->fieldList([], TRUE),
    });

    return $application;
  }

  /**
   * Creates an application mock scoped to the Personalamt organisation.
   */
  private function applicationWithOrganisationScope(): ApplicationInterface {
    $schema = $this->createMock(EntityInterface::class);
    $schema->method('label')->willReturn('Dienstreiseantrag');

    $application = $this->createMock(ApplicationInterface::class);
    $application->method('uuid')->willReturn('e4f5ecb8-a351-4117-8034-1405feda1624');
    $application->method('getReferenceNumber')->willReturn('MS-PA-2026-99005');
    $application->method('getCreatedTime')->willReturn(1_799_433_600);
    $application->method('getWorkflowStatus')->willReturn('vorgesetztenfreigabe_offen');
    $application->method('getCrmData')->willReturn(NULL);
    $application->method('getApplicantEmail')->willReturn('julia.schneider@example.test');
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_organisation'
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_schema' => $this->fieldList(['entity' => $schema]),
      'field_organisation' => $this->fieldList(['target_id' => 5]),
      default => $this->fieldList([], TRUE),
    });

    return $application;
  }

  /**
   * Creates a fieldable group mock with slug and optional jurisdiction ref.
   */
  private function groupEntity(
    string $bundle,
    string $label,
    string $slug,
    ?int $jurisdictionTargetId = NULL,
    ?string $email = NULL,
  ): FieldableEntityInterface {
    $group = $this->createMock(FieldableEntityInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('label')->willReturn($label);
    $group->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_slug'
        || ($field === 'field_jurisdiction' && $jurisdictionTargetId !== NULL)
        || ($field === 'field_org_email' && $email !== NULL)
    );
    $group->method('get')->willReturnCallback(function (string $field) use ($slug, $jurisdictionTargetId, $email): FieldItemListInterface {
      if ($field === 'field_slug') {
        return $this->fieldList(['value' => $slug]);
      }
      if ($field === 'field_jurisdiction' && $jurisdictionTargetId !== NULL) {
        return $this->fieldList(['target_id' => $jurisdictionTargetId]);
      }
      if ($field === 'field_org_email' && $email !== NULL) {
        return $this->fieldList(['value' => $email]);
      }
      return $this->fieldList([], TRUE);
    });

    return $group;
  }

  /**
   * Creates an application revision mock with workflow status metadata.
   */
  private function applicationRevision(
    int $id,
    int $revisionId,
    int $statusId,
    int $revisionCreated,
    int $changed,
  ): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn($id);
    $application->method('getRevisionId')->willReturn($revisionId);
    $application->method('getRevisionCreationTime')->willReturn($revisionCreated);
    $application->method('getCreatedTime')->willReturn($revisionCreated);
    $application->method('getChangedTime')->willReturn($changed);
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_workflow_status'
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_workflow_status' => $this->fieldList(['target_id' => $statusId]),
      default => $this->fieldList([], TRUE),
    });

    return $application;
  }

  /**
   * Creates a workflow status mock for escalation-rule tests.
   */
  private function workflowStatus(int $escalationDays, bool $terminal): WorkflowStatusInterface {
    $status = $this->createMock(WorkflowStatusInterface::class);
    $status->method('getEscalationDays')->willReturn($escalationDays);
    $status->method('isTerminal')->willReturn($terminal);
    return $status;
  }

  /**
   * Creates an application mock with a referenced workflow status.
   */
  private function applicationWithWorkflowStatus(WorkflowStatusInterface $status): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->willReturnCallback(
      static fn (string $field): bool => $field === 'field_workflow_status'
    );
    $application->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_workflow_status' => $this->fieldList(['entity' => $status]),
      default => $this->fieldList([], TRUE),
    });
    return $application;
  }

  /**
   * Creates a field item list mock exposing magic field properties.
   */
  private function fieldList(array $properties, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('getValue')->willReturn($empty ? [] : [$properties]);
    $field->method('__get')->willReturnCallback(
      static fn (string $name): mixed => $properties[$name] ?? NULL
    );
    return $field;
  }

  /**
   * The confirmation request names the consents the link makes effective.
   *
   * Mutation probe: dropping the bank part or the Stelle fails this test;
   * an application without consent gets no notice at all.
   */
  public function testConfirmationNoticeNamesConsentsAndStelle(): void {
    $with_bank = $this->createMock(ApplicationInterface::class);
    $with_bank->method('hasProfileConsent')->willReturn(TRUE);
    $with_bank->method('hasBankConsent')->willReturn(TRUE);
    $with_bank->method('getProfileConsentRecord')->willReturn(['scope' => ['label' => 'Bürgerbüro']]);
    $de = NotificationMailer::consentConfirmationNotice($with_bank, 'de');
    $this->assertStringContainsString('Ihre Kontaktdaten und Ihre Bankverbindung', $de);
    $this->assertStringContainsString('bei Bürgerbüro', $de);
    $this->assertStringContainsString('bestätigen Sie bitte nicht', $de);
    $en = NotificationMailer::consentConfirmationNotice($with_bank, 'en');
    $this->assertStringContainsString('your contact details and your bank details', $en);
    $this->assertStringContainsString('to Bürgerbüro', $en);

    $without = $this->createMock(ApplicationInterface::class);
    $without->method('hasProfileConsent')->willReturn(FALSE);
    $this->assertSame('', NotificationMailer::consentConfirmationNotice($without, 'de'));
  }

}
