<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\beantragt_application\Entity\Application;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\beantragt_application\ApplicationAssignmentPolicy;
use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Controller\StaffApplicationApiController;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_schema\Access\JsonApiReadGateAccessCheck;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests application data protection against real entity storage.
 */
#[Group('beantragt_application')]
final class ApplicationDataProtectionKernelTest extends GroupKernelTestBase {

  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

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
    'beantragt_workflow',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
    'beantragt_application_revision_test',
  ];

  /**
   * Original application data key environment value.
   */
  private string|false $originalApplicationDataKey;

  /**
   * The service under test.
   */
  private ApplicationDataProtectionService $dataProtection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalApplicationDataKey = getenv(self::KEY_ENV);
    putenv(self::KEY_ENV . '=' . str_repeat('k', 32));

    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);

    // Fixture setup writes scoped form schemas outside the behavior under
    // test. Use the same explicit bypass as trusted seed tooling.
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
    $this->dataProtection = $this->container->get('beantragt_application.data_protection');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalApplicationDataKey === FALSE) {
      putenv(self::KEY_ENV);
    }
    else {
      putenv(self::KEY_ENV . '=' . $this->originalApplicationDataKey);
    }

    parent::tearDown();
  }

  /**
   * Presave encrypts sensitive field_data and stores only email lookup hashes.
   */
  public function testSensitiveApplicationFieldDataIsProtectedOnEntitySave(): void {
    $jurisdiction = $this->createJurisdiction('pii-jur', 'PII Jurisdiction');
    $organisation = $this->createOrganisation('pii-org', 'PII Organisation', $jurisdiction);
    $workflow_status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);

    $application = $this->createApplication($schema, $organisation, $jurisdiction, $workflow_status);
    $reloaded = $this->entityTypeManager()
      ->getStorage('application')
      ->load($application->id());
    assert($reloaded instanceof ApplicationInterface);

    $stored_json = $reloaded->getData();
    $stored_data = json_decode($stored_json, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($stored_data);
    $this->assertSame('visible-note', $stored_data['public_note']);
    $this->assertTrue($this->dataProtection->isEncryptedValue($stored_data['diagnosis']));
    $this->assertTrue($this->dataProtection->isEncryptedValue($stored_data['applicant_email']));
    $this->assertStringNotContainsString('IfSG-Meldung', $stored_json);
    $this->assertStringNotContainsString('max@example.test', $stored_json);

    $this->assertTrue($reloaded->get('field_applicant_email')->isEmpty());
    $this->assertTrue($reloaded->get('field_crm_data')->isEmpty());
    $this->assertFalse($reloaded->get('field_applicant_email_hash')->isEmpty());
    $this->assertSame(
      $this->dataProtection->applicantEmailLookupHash('max@example.test'),
      (string) $reloaded->get('field_applicant_email_hash')->value,
    );
    $this->assertMatchesRegularExpression(
      '/^[a-f0-9]{64}$/',
      (string) $reloaded->get('field_applicant_email_hash')->value,
    );
    $this->assertTrue($this->dataProtection->applicationApplicantEmailMatches($reloaded, 'MAX@example.test'));
    $this->assertFalse($this->dataProtection->applicationApplicantEmailMatches($reloaded, 'other@example.test'));
    $this->assertApplicationStorageRowsDoNotContainClearPii((int) $application->id());

    // System follow-up revisions keep the ciphertext byte for byte; the
    // original-submission proof of attachments compares it per revision.
    $storage = $this->entityTypeManager()->getStorage('application');
    $revision_ids = array_keys($storage->getQuery()->accessCheck(FALSE)->allRevisions()
      ->condition('id', $application->id())->execute());
    $this->assertGreaterThan(1, count($revision_ids));
    foreach ($storage->loadMultipleRevisions($revision_ids) as $revision) {
      $this->assertSame($stored_json, $revision->getData());
    }

    $this->assertSame([
      'applicant_email' => 'max@example.test',
      'diagnosis' => 'IfSG-Meldung',
      'public_note' => 'visible-note',
    ], $this->dataProtection->revealDataJson($stored_json));
  }

  /**
   * Recognised financial fields roundtrip and legacy storage is backfilled.
   */
  public function testFinancialDataProtectionAndBackfill(): void {
    $this->installSchema('beantragt_application', ['beantragt_protected_access_log']);
    $jurisdiction = $this->createJurisdiction('financial-jur', 'Musterstadt');
    $organisation = $this->createOrganisation('financial-org', 'Dienststelle A', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $definition = [
      'sections' => [
        [
          'id' => 'main',
          'title' => 'Synthetic fields',
          'fields' => [
            [
              'id' => 'bank_value',
              'type' => 'text',
              'label' => 'Bank value',
              'format' => 'iban',
              'privacy' => ['kind' => 'financial'],
            ],
            [
              'id' => 'id_value',
              'type' => 'text',
              'label' => 'Steuer-ID',
              'privacy' => ['kind' => 'financial'],
              'sensitive' => FALSE,
            ],
          ],
        ],
      ],
    ];
    $schema->set('field_schema', json_encode($definition, JSON_THROW_ON_ERROR))->save();
    $values = ['bank_value' => 'DE00000000000000000000', 'id_value' => '00000000000'];
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status, $values);
    $first_revision = $application->getRevisionId();
    $application->set('field_reference_number', 'SYNTHETIC-REVISED')->save();
    $database = $this->container->get('database');
    $read_rows = static function () use ($database, $application): array {
      $rows = [];
      foreach (['application', 'application_revision'] as $table) {
        $rows[$table] = $database->select($table, 'a')->fields('a', ['field_data'])
          ->condition('id', $application->id())->orderBy('revision_id')->execute()->fetchCol();
      }
      return $rows;
    };
    $revision_count = count($read_rows()['application_revision']);
    $this->assertGreaterThanOrEqual(2, $revision_count);
    $assert_encrypted = function (array $rows) use ($values, $revision_count): void {
      $this->assertCount(1, $rows['application']);
      $this->assertCount($revision_count, $rows['application_revision']);
      foreach (array_merge(...array_values($rows)) as $raw) {
        $document = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
        foreach ($values as $key => $value) {
          $this->assertTrue($this->dataProtection->isEncryptedValue($document[$key]));
          $this->assertStringNotContainsString($value, $raw);
        }
        $this->assertSame($values, $this->dataProtection->revealDataJson($raw));
      }
    };
    $assert_encrypted($read_rows());

    $unresolved = $this->createApplication($schema, $organisation, $jurisdiction, $status, $values);
    $later = $this->createApplication($schema, $organisation, $jurisdiction, $status, $values);
    $count_storage_rows = static function (ApplicationInterface $candidate) use ($database): int {
      return 1 + (int) $database->select('application_revision', 'a')
        ->condition('id', $candidate->id())->countQuery()->execute()->fetchField();
    };
    $submitted_revision = $unresolved->get('field_schema_revision_id')->value;
    $plaintext = json_encode($values, JSON_THROW_ON_ERROR);
    // Simulate historical plaintext without triggering modern save hooks.
    foreach (['application', 'application_revision'] as $table) {
      $database->update($table)->fields(['field_data' => $plaintext])
        ->condition('id', [$application->id(), $unresolved->id(), $later->id()], 'IN')->execute();
      $database->update($table)->fields(['field_schema_revision_id' => 999999])
        ->condition('id', $unresolved->id())->execute();
    }
    $empty = $this->createApplication($schema, $organisation, $jurisdiction, $status, ['bank_value' => '']);
    foreach (['application', 'application_revision'] as $table) {
      $database->update($table)->fields(['field_data' => ' { } '])
        ->condition('id', $empty->id())->execute();
    }
    // Existing submissions must still use their pinned definition.
    $schema->set('field_schema', '{"sections":[]}')->save();
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    $run_update = static function (): array {
      $sandbox = [];
      do {
        $message = beantragt_application_update_10042($sandbox);
      } while ($sandbox['#finished'] < 1);
      return [$sandbox['updated'], $message];
    };
    [$updated, $message] = $run_update();
    $this->assertStringContainsString('Skipped applications with unavailable submitted form definitions (1).', $message);
    $state = $this->container->get('state');
    $this->assertSame([(int) $unresolved->id()], $state->get(ApplicationDataProtectionService::BACKFILL_PENDING_STATE_KEY));
    $this->assertSame(1 + $revision_count + $count_storage_rows($later), $updated);
    $requirements = beantragt_application_requirements('runtime');
    $this->assertSame(REQUIREMENT_WARNING, $requirements['beantragt_application_data_backfill']['severity']);
    $this->assertSame($plaintext, $database->select('application', 'a')->fields('a', ['field_data'])
      ->condition('id', $unresolved->id())->execute()->fetchField());
    $this->assertSame(['updated' => 0, 'pending' => 1], beantragt_application_retry_data_protection());
    foreach (['application', 'application_revision'] as $table) {
      $database->update($table)->fields(['field_schema_revision_id' => $submitted_revision])
        ->condition('id', $unresolved->id())->execute();
    }
    $this->assertSame(['updated' => $count_storage_rows($unresolved), 'pending' => 0], beantragt_application_retry_data_protection());
    $this->assertSame([], beantragt_application_requirements('runtime'));
    foreach (['application', 'application_revision'] as $table) {
      foreach ([$unresolved, $later] as $candidate) {
        $rows = $database->select($table, 'a')->fields('a', ['field_data'])
          ->condition('id', $candidate->id())->execute()->fetchCol();
        foreach ($rows as $row) {
          $this->assertNotSame($plaintext, $row);
          $this->assertSame($values, $this->dataProtection->revealDataJson($row));
        }
      }
    }
    $migrated = $read_rows();
    $assert_encrypted($migrated);
    $this->assertSame(0, $run_update()[0]);
    $this->assertSame($migrated, $read_rows());
    $empty_data = $database->select('application', 'a')->fields('a', ['field_data'])
      ->condition('id', $empty->id())->execute()->fetchField();
    $this->assertSame(' { } ', $empty_data);

    $permissions = ['view published application entities'];
    $reader = $this->createUser([...$permissions, 'view protected application data']);
    $redacted = $this->createUser($permissions);
    $organisation->addMember($reader, ['group_roles' => ['org-admin']]);
    $organisation->addMember($redacted, ['group_roles' => ['org-admin']]);
    $storage = $this->entityTypeManager()->getStorage('application');
    $current = $storage->loadUnchanged($application->id());
    $historic = $storage->loadRevision($first_revision);
    foreach ([$current, $historic] as $stored) {
      $this->assertTrue($stored->get('field_data')->access('view', $reader));
      $this->assertFalse($stored->get('field_data')->access('view', $redacted));
    }
    $this->setCurrentUser($reader);
    $controller = ApplicationDataController::create($this->container);
    $this->assertTrue($controller->protectedDataAccess($current, $reader)->isAllowed());
    $response = $controller->view($current);
    $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($values, $document['data']);
    $this->assertFalse($document['meta']['protected_data_redacted']);
  }

  /**
   * A changed schema version cannot bypass the submitted revision's protection.
   */
  public function testChangedSchemaVersionDoesNotRevealProtectedData(): void {
    $jurisdiction = $this->createJurisdiction('pinned-jur', 'Pinned Jurisdiction');
    $office = $this->createOrganisation('pinned-office', 'Pinned Office', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($office);
    $schema = $this->createSensitiveFormSchema($office, $jurisdiction);
    $application = $this->createApplication($schema, $office, $jurisdiction, $status);
    $pin = (int) $application->get('field_schema_revision_id')->value;
    $this->assertGreaterThan(0, $pin);
    $this->assertArrayHasKey('diagnosis', $this->dataProtection->revealDataJson($application->getData()));

    $schema->setSchema('{"sections":[]}')->save();
    $this->assertNotSame($application->getSchemaVersion(), $schema->getVersion());
    $this->assertFalse($this->dataProtection->schemaHasSensitiveFieldsJson($schema->getSchema()));

    $reader = $this->createUser(['view published application entities', 'edit application data entities']);
    $office->addMember($reader, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($reader);
    $submitted = ApplicationDataController::create($this->container)->view($application);
    $meta = json_decode((string) $submitted->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['meta'];
    $this->assertSame($application->getSchemaVersion(), $meta['schema']['attributes']['field_version']);
    $this->assertStringContainsString('diagnosis', $meta['schema']['attributes']['field_schema']['value']);

    // Simulate a stored version mismatch while keeping the original pin.
    $this->container->get('database')->update('application')
      ->fields(['field_schema_version' => $schema->getVersion()])
      ->condition('id', $application->id())->execute();
    $application = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertSame($pin, (int) $application->get('field_schema_revision_id')->value);
    $this->assertSame($schema->getVersion(), $application->getSchemaVersion());

    $controller = ApplicationDataController::create($this->container);
    $this->assertTrue($controller->protectedDataAccess($application, $reader)->isAllowed());
    $this->assertTrue($controller->access($application, $reader)->isForbidden());
    $response = $controller->view($application);
    $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($document['meta']['protected_data_redacted']);
    $this->assertSame([ApplicationDataProtectionService::REDACTED_MARKER => TRUE], $document['data']['diagnosis']);
  }

  /**
   * A ciphertext envelope still requires a gated and audited reveal.
   */
  public function testEncryptedDataWithoutSensitiveSchemaIsRedactedOrAudited(): void {
    $this->installSchema('beantragt_application', [ProtectedAccessLog::TABLE]);
    $jurisdiction = $this->createJurisdiction('ciphertext-jur', 'Ciphertext Jurisdiction');
    $office = $this->createOrganisation('ciphertext-office', 'Ciphertext Office', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($office);
    $schema = $this->createSensitiveFormSchema($office, $jurisdiction);
    $sensitive_schema = json_decode($schema->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema->setSchema('{"sections":[]}')->save();
    $application = $this->createApplication($schema, $office, $jurisdiction, $status);
    // Model older stored data with a ciphertext envelope outside the schema.
    $data = $this->dataProtection->protectData(
      ['diagnosis' => 'synthetic encrypted value', 'public_note' => 'visible'],
      $sensitive_schema,
    );
    $this->container->get('database')->update('application')
      ->fields(['field_data' => json_encode($data, JSON_THROW_ON_ERROR)])
      ->condition('id', $application->id())->execute();
    $application = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertFalse($this->dataProtection->applicationHasSensitiveFields($application));
    $this->assertTrue($this->dataProtection->applicationRequiresProtectedReveal($application));

    $audit = $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'l')
      ->fields('l', ['channel'])
      ->condition('application_uuid', $application->uuid());
    $staff = $this->createUser(['view published application entities']);
    $office->addMember($staff, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($staff);
    $response = ApplicationDataController::create($this->container)->view($application);
    $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($document['meta']['protected_data_redacted']);
    $this->assertSame([
      'diagnosis' => [ApplicationDataProtectionService::REDACTED_MARKER => TRUE],
      'public_note' => 'visible',
    ], $document['data']);
    $this->assertSame([], $audit->execute()->fetchCol());

    $authorized = $this->createUser(['view published application entities', 'view protected application data']);
    $office->addMember($authorized, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($authorized);
    $response = ApplicationDataController::create($this->container)->view($application);
    $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertFalse($document['meta']['protected_data_redacted']);
    $this->assertSame('synthetic encrypted value', $document['data']['diagnosis']);
    $this->assertSame(['admin_data'], $audit->execute()->fetchCol());
  }

  /**
   * Historical ciphertext is revealed only with permission and case scope.
   */
  public function testEncryptedCorrectionHistoryIsGatedAndAudited(): void {
    $this->installSchema('beantragt_application', [ProtectedAccessLog::TABLE]);
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->entityTypeManager());
    $jurisdiction = $this->createJurisdiction('history-jur', 'History Jurisdiction');
    $office = $this->createOrganisation('history-office', 'History Office', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($office);
    $schema = $this->createSensitiveFormSchema($office, $jurisdiction);
    $sensitive_schema = json_decode($schema->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    $schema->setSchema('{"sections":[]}')->save();
    $application = $this->createApplication($schema, $office, $jurisdiction, $status);
    $before_id = $application->getRevisionId();
    $corrected_data = '{"diagnosis":"corrected answer","public_note":"visible-note"}';
    $application->set('field_data', $corrected_data);
    $application->refreshCrmDataFromSchema($corrected_data, $schema);
    $application->setNewRevision(TRUE);
    RevisionLog::setEvent($application, 'data_edited');
    $application->save();
    $this->assertNotSame($before_id, $application->getRevisionId());

    $old_value = 'historical encrypted answer';
    $data = $this->dataProtection->protectData(['diagnosis' => $old_value, 'public_note' => 'visible-note'], $sensitive_schema);
    $database = $this->container->get('database');
    $database->update('application_revision')
      ->fields(['field_data' => json_encode($data, JSON_THROW_ON_ERROR)])
      ->condition('revision_id', $before_id)->execute();
    $before = $this->entityTypeManager()->getStorage('application')->loadRevision($before_id);
    $this->assertFalse($this->dataProtection->applicationHasSensitiveFields($before));
    $this->assertTrue($this->dataProtection->applicationRequiresProtectedReveal($before));
    $this->assertFalse($this->dataProtection->applicationRequiresProtectedReveal($application));
    $audit = $database->select(ProtectedAccessLog::TABLE, 'l')->fields('l', ['uid', 'channel'])
      ->condition('application_uuid', $application->uuid());

    Role::create(['id' => 'department_admin', 'label' => 'Department admin'])->save();
    $permissions = ['view published application entities', 'view workflow assignments'];
    $path = '/api/internal/staff/applications/' . $application->uuid() . '/events';
    foreach ([[FALSE, TRUE], [TRUE, FALSE], [TRUE, TRUE]] as [$permission, $case_scope]) {
      $staff = $this->createUser($permission ? [...$permissions, 'view protected application data'] : $permissions);
      $staff->addRole('department_admin')->save();
      $office->addMember($staff, ['group_roles' => [$case_scope ? 'org-admin' : 'org-member']]);
      $this->setCurrentUser($staff);
      $allowed = $permission && $case_scope;
      $this->assertSame($allowed, $before->get('field_data')->access('view', $staff));
      $response = StaffApplicationApiController::create($this->container)->events($application->uuid(), Request::create($path, 'GET', [
        'jurisdiction' => 'history-jur',
        'organisation' => 'history-office',
        'includeDataCorrections' => '1',
      ]));
      $this->assertSame(200, $response->getStatusCode());
      $history = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)['meta']['dataCorrections'];
      if ($allowed) {
        $this->assertSame([['field' => 'diagnosis', 'oldValue' => $old_value, 'hadOldValue' => TRUE]], $history[0]['changes']);
        $this->assertSame([$staff->id() => 'admin_data_history'], $audit->execute()->fetchAllKeyed());
      }
      else {
        $this->assertSame([], $history);
        $this->assertSame([], $audit->execute()->fetchAllKeyed());
      }
    }
  }

  /**
   * Cleanup and preview agree on form, office, jurisdiction and global rules.
   */
  public function testRetentionForSiblingOrganisationsAndFallbacks(): void {
    $this->installEntitySchema('application_attachment');
    $this->installSchema('file', ['file_usage']);
    $retention_admin = $this->createUser([
      'bypass beantragt group scope',
      'administer beantragt retention policies',
    ]);
    $this->setCurrentUser($retention_admin);
    $this->config('beantragt_application.settings')->set('data_retention_days', 120)->save();
    $jurisdiction = $this->createJurisdiction('retention-jur', 'Musterstadt');
    $jurisdiction->set('field_jur_config', '{"retention":{"dataRetentionDays":60}}')->save();
    $global = $this->createJurisdiction('retention-fallback', 'Musterhausen');
    $short = $this->createOrganisation('retention-short', 'Dienststelle A', $jurisdiction);
    $long = $this->createOrganisation('retention-long', 'Dienststelle B', $jurisdiction);
    $inherited = $this->createOrganisation('retention-inherit', 'Dienststelle C', $jurisdiction);
    $default = $this->createOrganisation('retention-default', 'Dienststelle D', $global);
    $parent = $this->createOrganisation('retention-parent', 'Dienststelle E', $jurisdiction);
    $child = $this->createOrganisation('retention-child', 'Dienststelle F', $jurisdiction);
    $audit = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context) use (&$audit): void {
      if ($message === 'User @uid changed retention for organisation @id from @old to @new.') {
        $audit[] = [$level, $context['@uid'], $context['@id'], $context['@old'], $context['@new']];
      }
    });
    $this->container->get('logger.factory')->addLogger($logger);
    $short->set('field_retention_days', 10)->save();
    $long->set('field_retention_days', 30)->save();
    $parent->set('field_parent_org', $long->id())->set('field_retention_days', 0)->save();
    $child->set('field_parent_org', $parent->id())->save();
    $short->set('field_retention_days', 11)->save();
    $short->set('field_retention_days', 10)->save();
    $this->setCurrentUser($this->createUser(['administer beantragt groups']));
    try {
      $short->set('field_retention_days', 0)->save();
      $this->fail('An administrator without retention permission changed the policy.');
    }
    catch (EntityStorageException $exception) {
      $this->assertStringContainsString('retention administration permission', $exception->getMessage());
    }
    $short = $this->entityTypeManager()->getStorage('group')->loadUnchanged($short->id());
    $this->setCurrentUser($retention_admin);
    $this->assertSame([
      [RfcLogLevel::NOTICE, $retention_admin->id(), $short->id(), 'inherit', 10],
      [RfcLogLevel::NOTICE, $retention_admin->id(), $long->id(), 'inherit', 30],
      [RfcLogLevel::NOTICE, $retention_admin->id(), $parent->id(), 'inherit', 0],
      [RfcLogLevel::NOTICE, $retention_admin->id(), $short->id(), 10, 11],
      [RfcLogLevel::NOTICE, $retention_admin->id(), $short->id(), 11, 10],
    ], $audit);

    $cases = [
      [$short, $jurisdiction, 20, NULL, 10, 'organisation', TRUE],
      [$long, $jurisdiction, 20, NULL, 30, 'organisation', FALSE],
      [$short, $jurisdiction, 20, 45, 45, 'schema', FALSE],
      [$long, $jurisdiction, 40, 0, 0, 'schema', FALSE],
      [$inherited, $jurisdiction, 70, NULL, 60, 'jurisdiction', TRUE],
      [$inherited, $jurisdiction, 40, NULL, 60, 'jurisdiction', FALSE],
      [$default, $global, 70, NULL, 120, 'global', FALSE],
      [$child, $jurisdiction, 90, NULL, 0, 'organisation', FALSE],
    ];
    $now = $this->container->get('datetime.time')->getRequestTime();
    $service = $this->container->get('beantragt_application.retention_cleanup');
    $storage = $this->entityTypeManager()->getStorage('application');
    $keep = [];
    foreach ($cases as $index => [$org, $jur, $age, $override, $days, $source, $expired]) {
      $status = $this->entityTypeManager()->getStorage('workflow_status')->loadByProperties([
        'field_group' => $org->id(),
        'field_machine_name' => 'eingegangen',
      ]);
      $workflow_status = reset($status) ?: $this->createDefaultWorkflowStatus($org);
      $schema = $this->createSensitiveFormSchema($org, $jur);
      if ($override !== NULL) {
        $definition = json_decode($schema->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
        $definition['retention'] = ['dataRetentionDays' => $override];
        $schema->set('field_schema', json_encode($definition, JSON_THROW_ON_ERROR))->save();
      }
      $application = $this->createApplication($schema, $org, $jur, $workflow_status);
      $application->set('field_email_confirmed', TRUE)
        ->set('created', $now - $age * 86400)
        ->set('field_reference_number', 'RETENTION-' . $index)
        ->save();
      if ($org === $default) {
        // An inconsistent legacy reference must not change this tenant rule.
        $this->container->get('database')->update('application')
          ->fields(['field_organisation' => $short->id()])
          ->condition('id', $application->id())->execute();
        $application = $storage->loadUnchanged($application->id());
      }
      $this->assertSame([
        'days' => $days,
        'source' => $source,
        'deleteAfter' => $days > 0 ? $now + ($days - $age) * 86400 : NULL,
      ], $service->retentionForApplication($application));
      $keep[(int) $application->id()] = !$expired;
    }

    $preview = $service->run(TRUE);
    $this->assertSame(['matched' => 2, 'deleted' => 0], $preview['counts']['applications']);
    $report = $service->run();
    $this->assertSame(['matched' => 2, 'deleted' => 2], $report['counts']['applications']);
    foreach ($keep as $id => $expected) {
      $this->assertSame($expected, $storage->loadUnchanged($id) !== NULL);
    }
  }

  /**
   * A delegation carries the status into the target organisation catalogue.
   *
   * Every organisation owns its statuses; keeping the source status made
   * every delegation across organisations fail validation.
   */
  public function testDelegationCarriesStatusIntoTargetCatalogue(): void {
    $jurisdiction = $this->createJurisdiction('delegation-jur', 'Delegation jurisdiction');
    $source = $this->createOrganisation('delegation-source', 'Source office', $jurisdiction);
    $same_name = $this->createOrganisation('delegation-same', 'Office with the same status', $jurisdiction);
    $default_only = $this->createOrganisation('delegation-default', 'Office with a default only', $jurisdiction);
    $empty = $this->createOrganisation('delegation-empty', 'Office without statuses', $jurisdiction);
    $this->createDefaultWorkflowStatus($source);
    $source_checked = $this->createNamedWorkflowStatus($source, 'zustaendigkeit_geprueft');
    $this->createDefaultWorkflowStatus($same_name);
    $target_checked = $this->createNamedWorkflowStatus($same_name, 'zustaendigkeit_geprueft');
    $target_default = $this->createDefaultWorkflowStatus($default_only);
    // A jurisdiction-wide form, as in the demo: the case's organisation then
    // owns the status catalogue.
    $schema = $this->createSensitiveFormSchema($source, $jurisdiction);
    $schema->set('field_organisation', NULL);
    $schema->set('field_group', ['target_id' => $jurisdiction->id()]);
    $schema->save();

    $controller = (new \ReflectionClass(ApplicationAssignmentController::class))->newInstanceWithoutConstructor();
    $manager = new \ReflectionProperty(ApplicationAssignmentController::class, 'manager');
    $manager->setValue($controller, $this->entityTypeManager());
    $carry = new \ReflectionMethod(ApplicationAssignmentController::class, 'carryWorkflowStatusToTarget');

    $application = $this->createApplication($schema, $source, $jurisdiction, $source_checked);
    $application->set('field_organisation', ['target_id' => $same_name->id()]);
    $carry->invoke($controller, $application, 'zustaendigkeit_geprueft');
    $this->assertSame((int) $target_checked->id(), $application->getWorkflowStatusId());
    $this->assertTrue($application->isWorkflowStatusResetAllowed());
    $this->assertCount(0, $application->validate()->getByField('field_workflow_status'));

    $application = $this->createApplication($schema, $source, $jurisdiction, $source_checked);
    $application->set('field_organisation', ['target_id' => $default_only->id()]);
    $carry->invoke($controller, $application, 'zustaendigkeit_geprueft');
    $this->assertSame((int) $target_default->id(), $application->getWorkflowStatusId());

    $application = $this->createApplication($schema, $source, $jurisdiction, $source_checked);
    $application->set('field_organisation', ['target_id' => $empty->id()]);
    // Without an own catalogue the effective workflow falls back; whatever
    // it picks must validate, otherwise the delegation is refused clearly.
    try {
      $carry->invoke($controller, $application, 'zustaendigkeit_geprueft');
      $this->assertNotSame((int) $source_checked->id(), $application->getWorkflowStatusId());
      $this->assertCount(0, $application->validate()->getByField('field_workflow_status'));
    }
    catch (UnprocessableEntityHttpException $exception) {
      $this->assertStringContainsString('no workflow status', $exception->getMessage());
    }
  }

  /**
   * Creates a non-default status with a given machine name.
   */
  private function createNamedWorkflowStatus(GroupInterface $group, string $machine_name): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Zuständigkeit geklärt',
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => $machine_name,
      'field_weight' => 5,
      'field_is_default' => FALSE,
      'field_is_terminal' => FALSE,
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Creates a jurisdiction group.
   */
  private function createJurisdiction(string $slug, string $label): GroupInterface {
    return $this->createGroup([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
    ]);
  }

  /**
   * Programmatic application edits cannot overwrite an earlier revision.
   */
  public function testApplicationRevisionsCannotBeDisabledByCaller(): void {
    $jurisdiction = $this->createJurisdiction('revision-jur', 'Revision jurisdiction');
    $organisation = $this->createOrganisation('revision-org', 'Revision organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $old_id = (int) $application->getRevisionId();
    $old_reference = $application->get('field_reference_number')->value;
    $actor = $this->container->get('current_user')->id();
    $application->setNewRevision(FALSE);
    $application->set('field_reference_number', 'REVISION-TEST-NEW');
    $application->save();
    $this->assertNotSame($old_id, (int) $application->getRevisionId());
    $this->assertSame((int) $actor, (int) $application->getRevisionUserId());
    $old = $this->entityTypeManager()->getStorage('application')->loadRevision($old_id);
    $this->assertSame($old_reference, $old->get('field_reference_number')->value);
    $this->assertSame('REVISION-TEST-NEW', $application->get('field_reference_number')->value);
    foreach ([1, 2] as $change) {
      $application->setNewRevision(TRUE);
      $application->setRevisionUserId(0);
      $application->setRevisionCreationTime(1700000000 + $change);
      RevisionLog::setMessage($application, 'Application assignment changed by repeated actor.');
      $application->save();
      $this->assertSame(0, (int) $application->getRevisionUserId());
      $this->assertSame(1700000000 + $change, (int) $application->getRevisionCreationTime());
      $this->assertSame('Application assignment changed by repeated actor.', RevisionLog::message((string) $application->getRevisionLogMessage()));
    }

  }

  /**
   * The tenant opt-out blocks direct writes but retains history and teams.
   */
  public function testPersonalAssignmentPolicyAndRevisionPrivacy(): void {
    $jurisdiction = $this->createJurisdiction('assignment-jur', 'Assignment jurisdiction');
    $organisation = $this->createOrganisation('assignment-org', 'Assignment organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $assignee = $this->createUser();
    $application->set('field_assigned_to', $assignee->id())->save();
    $jurisdiction->set('field_jur_config', json_encode(['delegation' => ['personalAssignment' => FALSE]]))->save();
    $storage = $this->entityTypeManager()->getStorage('application');
    $application = $storage->loadUnchanged($application->id());
    $this->assertFalse(ApplicationAssignmentPolicy::personalAssignmentEnabled($application));
    $child = $this->createJurisdiction('assignment-child', 'Child jurisdiction');
    $child->set('field_parent_jurisdiction', $jurisdiction->id());
    $child->set('field_jur_config', json_encode(['delegation' => ['personalAssignment' => TRUE]]))->save();
    $application->set('field_jurisdiction', $child->id());
    $this->assertFalse(ApplicationAssignmentPolicy::personalAssignmentEnabled($application));
    $other = $this->createJurisdiction('assignment-other', 'Other tenant root');
    $application->set('field_jurisdiction', $other->id());
    $this->assertFalse(ApplicationAssignmentPolicy::retainsExistingAssignment($application));
    $application->set('field_jurisdiction', $jurisdiction->id());

    $application->save();
    $this->assertSame((int) $assignee->id(), (int) $application->get('field_assigned_to')->target_id);
    $this->assertFalse($application->get('revision_log_message')->access('view', new AnonymousUserSession()));
    $this->assertFalse($application->get('revision_log_message')->access('edit', $this->container->get('current_user')));
    $this->assertFalse($application->get('revision_user')->access('edit', $this->container->get('current_user')));
    $this->assertFalse($application->get('revision_created')->access('edit', $this->container->get('current_user')));
    $application->set('field_assigned_to', NULL);
    $application->set('field_assigned_team', $organisation->id());
    $application->save();
    $this->assertTrue($application->get('field_assigned_to')->isEmpty());
    $application->set('field_assigned_to', $assignee->id());
    $this->expectException(EntityStorageException::class);
    $application->save();
  }

  /**
   * JSON:API enforces revision-log privacy on current and historic reads.
   */
  public function testJsonApiRevisionLogsRequireStaffAccess(): void {
    $jurisdiction = $this->createJurisdiction('jsonapi-jur', 'JSON API jurisdiction');
    $organisation = $this->createOrganisation('jsonapi-org', 'JSON API organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $revision = $application->getRevisionId();
    $application->set('field_reference_number', 'LATER-JSONAPI-REVISION')->save();
    $this->assertNotSame((int) $revision, (int) $application->getRevisionId());
    $staff = $this->createUser([
      'administer application entities',
      'bypass beantragt group scope',
      'view workflow assignments',
    ]);
    $staff->setPassword('synthetic-jsonapi-password')->save();
    $this->container->get('router.builder')->rebuild();
    $path = '/jsonapi/application/application/' . $application->uuid() . '?resourceVersion=id:' . $revision;
    $headers = ['HTTP_ACCEPT' => 'application/vnd.api+json'];
    $request = Request::create($path, 'GET', server: $headers);
    $anonymous = $this->container->get('http_kernel')->handle($request);
    $this->assertContains($anonymous->getStatusCode(), [401, 403]);
    $this->assertStringNotContainsString('civic.revision.v1', (string) $anonymous->getContent());
    $headers['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($staff->getAccountName() . ':synthetic-jsonapi-password');
    $headers['PHP_AUTH_USER'] = $staff->getAccountName();
    $headers['PHP_AUTH_PW'] = 'synthetic-jsonapi-password';
    $request = Request::create($path, 'GET', server: $headers);
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $data = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertArrayHasKey('revision_log_message', $data['data']['attributes']);
    $this->assertNotNull(RevisionLog::decode($data['data']['attributes']['revision_log_message']));
  }

  /**
   * JSON:API cannot filter or sort on fields whose values it hides.
   *
   * A filter on a hidden field answers per application whether a value
   * matches (meta.omitted carries each UUID), for anonymous requests too.
   * Only platform operators keep these paths; readable fields stay open.
   */
  public function testJsonApiCannotFilterOrSortOnHiddenFields(): void {
    $jurisdiction = $this->createJurisdiction('filter-jur', 'Filter jurisdiction');
    $organisation = $this->createOrganisation('filter-org', 'Filter organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $citizen = $this->createUser(['view own application entities'], 'filter-citizen');
    $application->set('uid', $citizen->id());
    $application->set('field_delivery_record', '[{"event":"route_chosen","at":1762500000,"route":"portal","source":"form_default","offered":[],"reason":"not_configured"}]');
    $application->save();
    $case_worker = $this->createUser([
      'view published application entities',
      'view unpublished application entities',
      'edit any application entities',
      'view workflow assignments',
    ], 'filter-case-worker');
    $admin = $this->createUser(['administer application entities'], 'filter-admin');
    // Staff pass the JSON:API read gate by role; their field lists stay
    // the protection under test until query access exists.
    foreach (['case_worker', 'administrator'] as $role_id) {
      if (!Role::load($role_id)) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }
    $case_worker->addRole('case_worker');
    $admin->addRole('administrator');
    foreach ([$citizen, $case_worker, $admin] as $account) {
      $account->setPassword('synthetic-filter-password')->save();
    }
    $this->container->get('router.builder')->rebuild();

    $condition = static function (string $path, string $operator, ?string $value = NULL): array {
      $condition = ['path' => $path, 'operator' => $operator];
      if ($value !== NULL) {
        $condition['value'] = $value;
      }
      return ['filter' => ['probe' => ['condition' => $condition]]];
    };
    $probes = [
      'field_delivery_record' => $condition('field_delivery_record', 'CONTAINS', 'route_chosen'),
      'field_applicant_email' => $condition('field_applicant_email', 'CONTAINS', '@'),
      'field_applicant_email_hash' => $condition('field_applicant_email_hash', 'IS NOT NULL'),
      'field_data' => $condition('field_data', 'CONTAINS', 'IfSG'),
    ];
    $sorts = [
      'field_delivery_record' => ['sort' => 'field_delivery_record'],
      'field_applicant_email' => ['sort' => '-field_applicant_email'],
    ];
    $cases = [];
    foreach ([$probes, $sorts] as $map) {
      foreach ($map as $field => $query) {
        $cases[] = [$field, $query];
      }
    }
    $get = function (?UserInterface $account, array $query): array {
      $headers = ['HTTP_ACCEPT' => 'application/vnd.api+json'];
      if ($account === NULL) {
        $this->setCurrentUser(new AnonymousUserSession());
      }
      else {
        $headers['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($account->getAccountName() . ':synthetic-filter-password');
        $headers['PHP_AUTH_USER'] = $account->getAccountName();
        $headers['PHP_AUTH_PW'] = 'synthetic-filter-password';
      }
      $request = Request::create('/jsonapi/application/application?' . http_build_query($query), 'GET', server: $headers);
      $response = $this->container->get('http_kernel')->handle($request);
      return [$response->getStatusCode(), (string) $response->getContent()];
    };

    foreach (['anonymous' => NULL, 'citizen owner' => $citizen, 'case worker' => $case_worker] as $label => $account) {
      foreach ($cases as [$field, $query]) {
        [$code, $content] = $get($account, $query);
        if ($account === NULL) {
          // Basic auth turns the denial into its 401 challenge for anonymous.
          $this->assertContains($code, [401, 403], $label . ' ' . $field . ': ' . $content);
        }
        elseif ($account === $citizen) {
          // The read gate refuses before the controller looks at the field.
          $this->assertSame(403, $code, $label . ' ' . $field . ': ' . $content);
          $this->assertStringContainsString(json_encode(JsonApiReadGateAccessCheck::REASON, JSON_THROW_ON_ERROR), $content, $label);
        }
        else {
          $this->assertSame(403, $code, $label . ' ' . $field . ': ' . $content);
          $this->assertStringContainsString('`' . $field . '`', $content, $label);
        }
        $this->assertStringNotContainsString((string) $application->uuid(), $content, $label . ' ' . $field);
        $this->assertStringNotContainsString('omitted', $content, $label . ' ' . $field);
      }
    }

    // Readable fields and the paths a role may read stay open.
    [$code, $content] = $get($case_worker, $condition('field_delivery_route', '=', 'portal'));
    $this->assertSame(200, $code, $content);
    [$code, $content] = $get($case_worker, $condition('field_assigned_to.id', 'IS NULL'));
    $this->assertSame(200, $code, $content);
    // The citizen never reaches the filter check: the JSON:API read gate
    // refuses the list first, so this line proves the gate, not the field.
    [$code, $content] = $get($citizen, $condition('field_assigned_to.id', 'IS NULL'));
    $this->assertSame(403, $code, $content);
    $this->assertStringContainsString(json_encode(JsonApiReadGateAccessCheck::REASON, JSON_THROW_ON_ERROR), $content);
    foreach ($cases as [$field, $query]) {
      [$code, $content] = $get($admin, $query);
      $this->assertSame(200, $code, $field . ': ' . $content);
    }
  }

  /**
   * Clearing raw scope cannot bypass opt-out when group hooks restore it.
   */
  public function testNormalizedScopeCannotBypassPersonalAssignmentOptOut(): void {
    $jurisdiction = $this->createJurisdiction('normalize-jur', 'Normalized jurisdiction');
    $organisation = $this->createOrganisation('normalize-org', 'Normalized organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $jurisdiction->set('field_jur_config', json_encode(['delegation' => ['personalAssignment' => FALSE]]))->save();
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field) {
      $application->set($field, NULL);
    }
    $application->set('field_assigned_to', $this->createUser()->id());
    $this->assertTrue(ApplicationAssignmentPolicy::personalAssignmentEnabled($application));
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('Personal assignment is disabled');
    $application->save();
  }

  /**
   * Field presave sees the revision flag and modern domain hooks reach the log.
   */
  public function testEarlyRevisionAndFinalDomainHookOrdering(): void {
    $jurisdiction = $this->createJurisdiction('lifecycle-jur', 'Lifecycle jurisdiction');
    $organisation = $this->createOrganisation('lifecycle-org', 'Lifecycle organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $storage = $this->entityTypeManager()->getStorage('application');
    $old_id = (int) $application->getRevisionId();
    $this->container->get('state')->set('application_revision_probe.team', (int) $organisation->id());
    $application->set('revision_probe', 'observe field presave');
    $application->setNewRevision(FALSE);
    RevisionLog::setMessage($application, '  Bitte prüfen. Please review.  ');
    RevisionLog::setEvent($application, 'delegated');
    $application->save();
    $this->assertTrue($this->container->get('state')->get('application_revision_probe.new_revision'));
    $this->assertNotSame($old_id, (int) $application->getRevisionId());
    $old = $storage->loadRevision($old_id);
    $this->assertTrue($old->get('field_assigned_team')->isEmpty());
    $data = RevisionLog::decode((string) $application->getRevisionLogMessage());
    $this->assertSame(['delegated'], $data['events']);
    $this->assertSame('  Bitte prüfen. Please review.  ', $data['message']);
    $this->assertContains([
      'field' => 'team',
      'before' => [],
      'after' => [['id' => (string) $organisation->id()]],
    ], $data['changes']);
    $application->setNewRevision(FALSE);
    $application->save();
    $data = RevisionLog::decode((string) $application->getRevisionLogMessage());
    $this->assertSame([], $data['events']);
    $this->assertSame('', $data['message']);
  }

  /**
   * An unresolved legacy root retains only an identical stored assignment.
   */
  public function testOrphanAssignmentExceptionCannotChangeScopeOrPerson(): void {
    $jurisdiction = $this->createJurisdiction('orphan-jur', 'Orphan jurisdiction');
    $organisation = $this->createOrganisation('orphan-org', 'Orphan organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $person = $this->createUser();
    $application->set('field_assigned_to', $person->id())->save();
    // Simulate damaged imported hierarchy without a new application mutation.
    $this->container->get('database')->insert('group__field_parent_jurisdiction')->fields([
      'bundle' => 'jur',
      'deleted' => 0,
      'entity_id' => $jurisdiction->id(),
      'revision_id' => $jurisdiction->getRevisionId(),
      'langcode' => $jurisdiction->language()->getId(),
      'delta' => 0,
      'field_parent_jurisdiction_target_id' => $jurisdiction->id(),
    ])->execute();
    $this->entityTypeManager()->getStorage('group')->resetCache();
    $storage = $this->entityTypeManager()->getStorage('application');
    $application = $storage->loadUnchanged($application->id());
    $this->assertFalse(ApplicationAssignmentPolicy::personalAssignmentEnabled($application));
    $this->assertTrue(ApplicationAssignmentPolicy::retainsExistingAssignment($application));
    $application->set('field_reference_number', 'ORPHAN-EDIT')->save();
    $this->assertSame('ORPHAN-EDIT', $storage->loadUnchanged($application->id())->get('field_reference_number')->value);
    $application->set('field_group', NULL);
    $this->assertFalse(ApplicationAssignmentPolicy::retainsExistingAssignment($application));
    $application->set('field_group', $organisation->id());
    $application->set('field_assigned_to', $this->createUser()->id());
    $this->assertFalse(ApplicationAssignmentPolicy::retainsExistingAssignment($application));
    $this->expectException(EntityStorageException::class);
    $application->save();
  }

  /**
   * Real JSON:API snapshots require both current and historical org scope.
   */
  public function testHistoricSnapshotCannotReviveLostScopeAccess(): void {
    $this->installSchema('file', ['file_usage']);
    $jurisdiction = $this->createJurisdiction('scope-jur', 'Scope jurisdiction');
    $source = $this->createOrganisation('scope-source', 'Source organisation', $jurisdiction);
    $target = $this->createOrganisation('scope-target', 'Target organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($source);
    $schema = $this->createSensitiveFormSchema($source, $jurisdiction);
    $application = $this->createApplication($schema, $source, $jurisdiction, $status);
    $old_id = (int) $application->getRevisionId();
    $application->set('field_organisation', $target->id());
    $application->set('field_group', $target->id());
    $application->save();
    $storage = $this->entityTypeManager()->getStorage('application');
    $old = $storage->loadRevision($old_id);
    $permissions = ['view published application entities', 'view workflow assignments'];
    $source_viewer = $this->createUser($permissions);
    $target_viewer = $this->createUser($permissions);
    $both_viewer = $this->createUser($permissions);
    $source->addMember($source_viewer, ['group_roles' => ['org-member']]);
    $source->addMember($both_viewer, ['group_roles' => ['org-member']]);
    $target->addMember($target_viewer, ['group_roles' => ['org-member']]);
    $target->addMember($both_viewer, ['group_roles' => ['org-member']]);
    $this->assertFalse($old->access('view', $source_viewer));
    $this->assertFalse($old->access('view', $target_viewer));
    $this->assertTrue($old->access('view', $both_viewer));
    $this->container->get('router.builder')->rebuild();
    $cases = [
      [$source_viewer, $old_id, 403],
      [$target_viewer, $old_id, 403],
      [$both_viewer, $old_id, 200],
      [$target_viewer, (int) $application->getRevisionId(), 200],
    ];
    foreach ($cases as [$viewer, $vid, $expected]) {
      $viewer->setPassword('synthetic-scope-password')->save();
      $headers = [
        'HTTP_ACCEPT' => 'application/vnd.api+json',
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($viewer->getAccountName() . ':synthetic-scope-password'),
        'PHP_AUTH_USER' => $viewer->getAccountName(),
        'PHP_AUTH_PW' => 'synthetic-scope-password',
      ];
      $path = '/jsonapi/application/application/' . $application->uuid() . '?resourceVersion=id:' . $vid;
      $response = $this->container->get('http_kernel')->handle(Request::create($path, 'GET', server: $headers));
      $this->assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
      if ($expected === 403) {
        $this->assertStringNotContainsString('civic.revision.v1', (string) $response->getContent());
      }
    }

    // Protected field_data of a past revision follows the same rule: the
    // reveal right of the former scope alone does not open it.
    $reveal_permissions = [...$permissions, 'view protected application data'];
    $former_admin = $this->createUser($reveal_permissions);
    $source->addMember($former_admin, ['group_roles' => ['org-admin']]);
    $target->addMember($former_admin, ['group_roles' => ['org-member']]);
    $both_admin = $this->createUser($reveal_permissions);
    $source->addMember($both_admin, ['group_roles' => ['org-admin']]);
    $target->addMember($both_admin, ['group_roles' => ['org-admin']]);
    if (Role::load('case_worker') === NULL) {
      $this->createRole([], 'case_worker');
    }
    foreach ([$source_viewer, $target_viewer, $both_viewer] as $member) {
      $member->addRole('case_worker')->save();
    }
    $dual_scope_worker = $this->createUser($reveal_permissions);
    $dual_scope_worker->addRole('case_worker')->save();
    $source->addMember($dual_scope_worker, ['group_roles' => ['org-member']]);
    $target->addMember($dual_scope_worker, ['group_roles' => ['org-member']]);
    foreach ([
      [$former_admin, FALSE],
      [$both_admin, TRUE],
      [$both_viewer, FALSE],
      [$dual_scope_worker, TRUE],
    ] as [$viewer, $reveals]) {
      $viewer->setPassword('synthetic-scope-password')->save();
      $headers = [
        'HTTP_ACCEPT' => 'application/vnd.api+json',
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($viewer->getAccountName() . ':synthetic-scope-password'),
        'PHP_AUTH_USER' => $viewer->getAccountName(),
        'PHP_AUTH_PW' => 'synthetic-scope-password',
      ];
      $path = '/jsonapi/application/application/' . $application->uuid() . '?resourceVersion=id:' . $old_id;
      $response = $this->container->get('http_kernel')->handle(Request::create($path, 'GET', server: $headers));
      $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
      $document = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame($reveals, array_key_exists('field_data', $document['data']['attributes']), $viewer->getAccountName());
    }
    foreach ([$source, $target] as $removed) {
      $removed->removeMember($dual_scope_worker);
      $response = $this->container->get('http_kernel')->handle(Request::create($path, 'GET', server: $headers));
      $this->assertSame(403, $response->getStatusCode());
      $removed->addMember($dual_scope_worker, ['group_roles' => ['org-member']]);
    }
  }

  /**
   * Staff notes from the data controller stay the reason of their revision.
   *
   * The status endpoint passes its note through RevisionLog::setMessage();
   * WorkflowManager adds the status event without generated prose, and the
   * final hook keeps the note verbatim, including a deliberate repetition.
   */
  public function testStaffNotesThroughDataControllerStayRevisionMessages(): void {
    $jurisdiction = $this->createJurisdiction('note-jur', 'Note jurisdiction');
    $organisation = $this->createOrganisation('note-org', 'Note organisation', $jurisdiction);
    $received = $this->createDefaultWorkflowStatus($organisation);
    $review = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Fachprüfung',
      'field_group' => ['target_id' => $organisation->id()],
      'field_machine_name' => 'fachpruefung',
      'field_color' => '#16a34a',
      'field_icon' => 'i-lucide-search',
      'field_weight' => 1,
    ]);
    $review->save();
    $received->set('field_allowed_next', [['target_id' => $review->id()]]);
    $received->save();
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $received);
    $storage = $this->entityTypeManager()->getStorage('application');
    $controller = ApplicationDataController::create($this->container);
    $note = 'Unterlagen vollständig, bitte fachlich prüfen.';

    $application = $storage->loadUnchanged($application->id());
    $controller->updateStatus($application, Request::create('/', 'PATCH', content: json_encode([
      'expected_status_id' => (int) $received->id(),
      'expected_changed' => $application->getChangedTime(),
      'target_status_id' => (int) $review->id(),
      'note' => $note,
    ], JSON_THROW_ON_ERROR)));

    $stored = $storage->loadUnchanged($application->id());
    $this->assertSame((int) $review->id(), (int) $stored->get('field_workflow_status')->target_id);
    $log = RevisionLog::decode((string) $stored->getRevisionLogMessage());
    $this->assertIsArray($log);
    $this->assertSame($note, $log['message']);
    $this->assertSame(['status_changed'], $log['events']);
    $this->assertSame('status', $log['changes'][0]['field']);
    $this->assertStringNotContainsString('Status changed', (string) $stored->getRevisionLogMessage());

    // The same note again on a deadline change is a new, explicit reason.
    $status_revision_id = (int) $stored->getRevisionId();
    $controller->updateDeadline($stored, Request::create('/', 'PATCH', content: json_encode([
      'expected_changed' => $stored->getChangedTime(),
      'due_date' => '2026-12-01',
      'note' => $note,
    ], JSON_THROW_ON_ERROR)));
    $deadline = $storage->loadUnchanged($application->id());
    $this->assertGreaterThan($status_revision_id, (int) $deadline->getRevisionId());
    $log = RevisionLog::decode((string) $deadline->getRevisionLogMessage());
    $this->assertSame($note, $log['message']);
    $this->assertSame(['due_date_changed'], $log['events']);

    // A later programmatic save without a reason does not inherit the note.
    $deadline->set('field_reference_number', 'NOTE-TEST-NEXT');
    $deadline->save();
    $this->assertSame('', RevisionLog::message((string) $storage->loadUnchanged($application->id())->getRevisionLogMessage()));

    // The administrative rollback is the reset arm of the status event: it
    // returns to the previous status and keeps the staff note as its reason.
    // The rollback only passes revisions its actor may view.
    $staff = $this->createUser(['bypass beantragt group scope', 'view published application entities']);
    $this->setCurrentUser($staff);
    $controller = ApplicationDataController::create($this->container);
    $current = $storage->loadUnchanged($application->id());
    $before_rollback_revision_id = (int) $current->getRevisionId();
    $rollback_note = 'Fachprüfung irrtümlich gestartet.';
    $controller->rollbackStatus($current, Request::create('/', 'PATCH', content: json_encode([
      'expected_status_id' => (int) $review->id(),
      'expected_changed' => $current->getChangedTime(),
      'note' => $rollback_note,
    ], JSON_THROW_ON_ERROR)));
    $rolled_back = $storage->loadUnchanged($application->id());
    $this->assertGreaterThan($before_rollback_revision_id, (int) $rolled_back->getRevisionId());
    $this->assertSame((int) $received->id(), (int) $rolled_back->get('field_workflow_status')->target_id);
    $log = RevisionLog::decode((string) $rolled_back->getRevisionLogMessage());
    $this->assertIsArray($log);
    $this->assertSame(['status_reset'], $log['events']);
    $this->assertSame($rollback_note, $log['message']);
    $this->assertSame('status', $log['changes'][0]['field']);
    $this->assertSame([['id' => (string) $received->id()]], $log['changes'][0]['after']);
    $this->assertSame((int) $staff->id(), (int) $rolled_back->getRevisionUserId());
  }

  /**
   * A send in flight holds the case lock: a data edit cannot commit under it.
   *
   * A second lock backend stands in for the dispatcher process, which keeps
   * the shared application lock from claim to the end of the HTTP send.
   */
  public function testDataEditIsRefusedWhileAnotherProcessHoldsTheCase(): void {
    $jurisdiction = $this->createJurisdiction('lock-jur', 'Lock jurisdiction');
    $organisation = $this->createOrganisation('lock-org', 'Lock organisation', $jurisdiction);
    $status = $this->createDefaultWorkflowStatus($organisation);
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $status);
    $storage = $this->entityTypeManager()->getStorage('application');
    $application = $storage->loadUnchanged($application->id());
    $before = $application->getData();
    $data = $this->dataProtection->revealDataJson($application->getData());
    $data['public_note'] = 'corrected-note';
    $editor = $this->createUser([
      'edit any application entities',
      'edit application data entities',
      'view protected application data',
    ]);
    $organisation->addMember($editor, ['group_roles' => ['org-admin']]);
    $this->setCurrentUser($editor);

    // Kernel tests register a null lock; this case uses real database locks.
    $database = $this->container->get('database');
    $this->container->set('beantragt_application.dependent_mutation_lock', new ApplicationDependentMutationLock(
      $this->entityTypeManager(), new DatabaseLockBackend($database), $database));
    $dispatcher = new DatabaseLockBackend($database);
    $lock_name = Application::deletionLockName((int) $application->id());
    $this->assertTrue($dispatcher->acquire($lock_name, 300.0));
    try {
      ApplicationDataController::create($this->container)->update($application, Request::create('/', 'PATCH', content: json_encode([
        'data' => $data,
        'reason' => 'Corrected after review',
        'expected_changed' => $application->getChangedTime(),
      ], JSON_THROW_ON_ERROR)));
      $this->fail('A held case lock must refuse the edit.');
    }
    catch (HttpException $exception) {
      $this->assertSame(423, $exception->getStatusCode());
    }
    finally {
      $dispatcher->release($lock_name);
    }
    $this->assertSame($before, $storage->loadUnchanged($application->id())->getData());

    // Once the send has finished, the same edit goes through.
    $response = ApplicationDataController::create($this->container)->update($storage->loadUnchanged($application->id()), Request::create('/', 'PATCH', content: json_encode([
      'data' => $data,
      'reason' => 'Corrected after review',
      'expected_changed' => $application->getChangedTime(),
    ], JSON_THROW_ON_ERROR)));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('corrected-note', $this->dataProtection->revealDataJson($storage->loadUnchanged($application->id())->getData())['public_note']);
  }

  /**
   * An automatic status reason applies to the one save that carried it.
   *
   * The marker drives the event code and the system authorship. A later
   * manual status change saved on the same object must count as manual.
   */
  public function testSystemStatusReasonAppliesToOneSaveOnly(): void {
    $jurisdiction = $this->createJurisdiction('marker-jur', 'Marker jurisdiction');
    $organisation = $this->createOrganisation('marker-org', 'Marker organisation', $jurisdiction);
    $received = $this->createDefaultWorkflowStatus($organisation);
    $review = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Im Fachverfahren',
      'field_group' => ['target_id' => $organisation->id()],
      'field_machine_name' => 'im_fachverfahren',
      'field_color' => '#16a34a',
      'field_icon' => 'i-lucide-send',
      'field_weight' => 1,
      'field_allowed_next' => [['target_id' => $received->id()]],
    ]);
    $review->save();
    $received->set('field_allowed_next', [['target_id' => $review->id()]]);
    $received->save();
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $application = $this->createApplication($schema, $organisation, $jurisdiction, $received);
    $actor = (int) $this->container->get('current_user')->id();
    $this->assertGreaterThan(0, $actor);

    $application->markSystemStatusChange(SemanticStatusChanger::CASE_SYSTEM_ACCEPTED);
    $application->set('field_workflow_status', ['target_id' => $review->id()]);
    $application->save();
    $this->assertNull($application->getSystemStatusChange());
    $log = RevisionLog::decode((string) $application->getRevisionLogMessage());
    $this->assertSame(['status_changed', 'case_system_accepted'], $log['events']);
    $this->assertSame(0, (int) $application->getRevisionUserId());

    $application->set('field_workflow_status', ['target_id' => $received->id()]);
    $application->save();
    $log = RevisionLog::decode((string) $application->getRevisionLogMessage());
    $this->assertSame(['status_changed'], $log['events']);
    $this->assertSame($actor, (int) $application->getRevisionUserId());
  }

  /**
   * A decision notice keeps its cause as an event code in the revision.
   *
   * Releasing a notice in beantragt.ai (docgen) and receiving one from a
   * case system both close the procedure through SemanticStatusChanger. The
   * status revision must carry status_changed plus the decision code in
   * civic.revision.v1, must not be emptied by the inherited-log rule, and
   * names the releasing person or the system as its author.
   */
  public function testDecisionNoticeStatusRevisionCarriesEventCode(): void {
    $jurisdiction = $this->createJurisdiction('decision-jur', 'Decision jurisdiction');
    $organisation = $this->createOrganisation('decision-org', 'Decision organisation', $jurisdiction);
    $received = $this->createDefaultWorkflowStatus($organisation);
    $closed = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Abgeschlossen',
      'field_group' => ['target_id' => $organisation->id()],
      'field_machine_name' => 'abgeschlossen',
      'field_color' => '#64748b',
      'field_icon' => 'i-lucide-archive',
      'field_weight' => 9,
      'field_semantic_flags' => ['closed'],
    ]);
    $closed->save();
    $schema = $this->createSensitiveFormSchema($organisation, $jurisdiction);
    $storage = $this->entityTypeManager()->getStorage('application');
    $changer = $this->container->get('beantragt_workflow.semantic_status_changer');
    $releaser = (int) $this->container->get('current_user')->id();
    $this->assertGreaterThan(0, $releaser);

    foreach ([
      'released by staff (docgen)' => [TRUE, 'decision_released', $releaser],
      'received from the case system' => [FALSE, 'decision_issued', 0],
    ] as $case => [$by_person, $event, $author]) {
      $application = $this->createApplication($schema, $organisation, $jurisdiction, $received);
      // A staff reason on the previous revision must not ride along.
      RevisionLog::setMessage($application, 'Earlier staff reason');
      $application->save();

      $this->assertTrue($changer->decisionIssued($application, $by_person), $case);

      $stored = $storage->loadUnchanged($application->id());
      // The catalogue may hold a seeded decision status that wins over the
      // generic closed status; either way the procedure is closed.
      $target = $stored->get('field_workflow_status')->entity;
      $this->assertInstanceOf(WorkflowStatusInterface::class, $target, $case);
      $this->assertNotSame((int) $received->id(), (int) $target->id(), $case);
      $this->assertNotSame([], array_intersect(['decision_issued', 'closed'], $target->getSemanticFlags()), $case);
      $status_revision = NULL;
      $revision_ids = $storage->getQuery()->accessCheck(FALSE)->allRevisions()
        ->condition('id', $application->id())->execute();
      krsort($revision_ids);
      foreach (array_keys($revision_ids) as $revision_id) {
        $revision = $storage->loadRevision($revision_id);
        $log = RevisionLog::decode((string) $revision->getRevisionLogMessage());
        if (is_array($log) && in_array('status', array_column($log['changes'], 'field'), TRUE)) {
          $status_revision = $revision;
          break;
        }
      }
      $this->assertInstanceOf(ApplicationInterface::class, $status_revision, $case);
      $log = RevisionLog::decode((string) $status_revision->getRevisionLogMessage());
      $this->assertSame(['status_changed', $event], $log['events'], $case);
      $this->assertSame('', $log['message'], $case);
      $this->assertSame([['id' => (string) $target->id()]], $log['changes'][0]['after'], $case);
      $this->assertSame($author, (int) $status_revision->getRevisionUserId(), $case);
    }
  }

  /**
   * Creates an organisation group owned by one jurisdiction.
   */
  private function createOrganisation(string $slug, string $label, GroupInterface $jurisdiction): GroupInterface {
    return $this->createGroup([
      'type' => 'org',
      'label' => $label,
      'field_slug' => $slug,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
  }

  /**
   * Creates the default workflow status required by applications.
   */
  private function createDefaultWorkflowStatus(GroupInterface $group): WorkflowStatusInterface {
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Eingegangen',
      'field_group' => ['target_id' => $group->id()],
      'field_machine_name' => 'eingegangen',
      'field_color' => '#2563eb',
      'field_icon' => 'i-lucide-inbox',
      'field_weight' => 0,
      'field_is_default' => TRUE,
      'field_is_terminal' => FALSE,
      'field_semantic_flags' => [['value' => 'received']],
    ]);
    $status->save();
    assert($status instanceof WorkflowStatusInterface);
    return $status;
  }

  /**
   * Creates a form schema containing sensitive non-file data.
   */
  private function createSensitiveFormSchema(GroupInterface $organisation, GroupInterface $jurisdiction): FormSchemaInterface {
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'PII protection smoke',
      'field_schema' => json_encode([
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              [
                'id' => 'applicant_email',
                'type' => 'email',
                'label' => 'E-Mail',
                'crm_key' => 'person.email',
              ],
              [
                'id' => 'diagnosis',
                'type' => 'textarea',
                'label' => 'Diagnosis',
                'sensitive' => TRUE,
              ],
              [
                'id' => 'public_note',
                'type' => 'text',
                'label' => 'Public note',
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'intranet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $schema->save();
    assert($schema instanceof FormSchemaInterface);
    return $schema;
  }

  /**
   * Creates an application that must be protected by presave hooks.
   */
  private function createApplication(
    FormSchemaInterface $schema,
    GroupInterface $organisation,
    GroupInterface $jurisdiction,
    WorkflowStatusInterface $workflowStatus,
    ?array $data = NULL,
  ): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $schema->id()],
      'field_data' => json_encode($data ?? [
        'applicant_email' => 'max@example.test',
        'diagnosis' => 'IfSG-Meldung',
        'public_note' => 'visible-note',
      ], JSON_THROW_ON_ERROR),
      'field_applicant_email' => 'max@example.test',
      'field_workflow_status' => ['target_id' => $workflowStatus->id()],
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $organisation->id()],
      'field_group' => ['target_id' => $organisation->id()],
      'status' => TRUE,
    ]);
    $application->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Asserts no current or revision storage row keeps clear PII side channels.
   */
  private function assertApplicationStorageRowsDoNotContainClearPii(int $applicationId): void {
    $database = $this->container->get('database');
    foreach (['application', 'application_revision'] as $table) {
      $rows = $database->select($table, 'a')
        ->fields('a', [
          'field_data',
          'field_crm_data',
          'field_applicant_email',
          'field_applicant_email_hash',
        ])
        ->condition('id', $applicationId)
        ->execute()
        ->fetchAllAssoc('field_applicant_email_hash');

      $this->assertNotSame([], $rows);
      foreach ($rows as $row) {
        $serialized = json_encode($row, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('IfSG-Meldung', $serialized);
        $this->assertStringNotContainsString('max@example.test', $serialized);
        $this->assertSame('', (string) $row->field_applicant_email);
        $this->assertSame('', (string) $row->field_crm_data);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row->field_applicant_email_hash);
      }
    }
  }

}
