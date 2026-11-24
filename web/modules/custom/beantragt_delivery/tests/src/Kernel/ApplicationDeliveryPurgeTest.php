<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_applicant\Controller\AdminApplicantProfileController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\ApplicationDeliveryPurger;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_passwordless\Controller\GdprController;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Enforces complete delivery erasure through real application lifecycle exits.
 */
#[Group('beantragt_delivery')]
final class ApplicationDeliveryPurgeTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization', 'language',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_roles_api', 'beantragt_applicant',
    'beantragt_passwordless', 'beantragt_comment', 'beantragt_notification',
    'beantragt_delivery',
  ];

  /**
   * Original application key restored after every independent scenario.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('k', 32));
    parent::setUp();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    $this->installConfig(['user', 'system', 'file', 'beantragt_group', 'beantragt_application', 'beantragt_delivery']);
    FieldStorageConfig::create(['entity_type' => 'group', 'field_name' => 'field_slug', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'group', 'bundle' => 'jur', 'field_name' => 'field_slug', 'label' => 'Slug'])->save();
    $types = ['form_schema', 'workflow_status', 'application',
      'application_attachment', 'application_comment', 'applicant', 'file',
    ];
    foreach ($types as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('beantragt_passwordless', ['beantragt_passwordless_codes', 'beantragt_verified_account_mail']);
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $this->installSchema('beantragt_delivery', array_keys(beantragt_delivery_schema()));
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $http->expects($this->never())->method('send');
    $this->container->set('http_client', $http);
    $this->container->set('beantragt_notification.mailer', $this->createMock(NotificationMailer::class));
    $this->container->get('config.factory')->getEditable('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->container->get('config.factory')->getEditable('system.site')->set('mail', 'site@example.test')->save();
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope', 'delete any application entities']));
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * Future application-linked stores cannot silently escape the purge policy.
   */
  public function testEveryApplicationStoreAndDocumentDependencyIsRegistered(): void {
    $found = [];
    foreach (beantragt_delivery_schema() as $table => $schema) {
      foreach (['application_id', 'application_uuid'] as $column) {
        if (isset($schema['fields'][$column])) {
          $found[$table][$column] = TRUE;
          $this->assertArrayHasKey($table, ApplicationDeliveryPurger::STORES, 'Register purge policy for ' . $table);
          $this->assertArrayHasKey($column, ApplicationDeliveryPurger::STORES[$table]);
        }
      }
      if (isset($schema['fields']['response_uuid'])) {
        $this->assertSame('response_dependency', ApplicationDeliveryPurger::STORES[$table]['response_uuid'] ?? NULL);
      }
    }
    $this->assertNotEmpty($found);
    foreach (ApplicationDeliveryPurger::STORES as $table => $columns) {
      $this->assertArrayHasKey($table, beantragt_delivery_schema());
    }
    $this->assertSame(['beantragt_delivery'], ApplicationDeliveryPurger::FILE_USAGE_MODULES);
  }

  /**
   * All real exits traverse the same complete erasure service.
   */
  #[DataProvider('exits')]
  public function testEveryRealLifecycleExitPurgesAllStores(string $exit): void {
    $unconfirmed = in_array($exit, ['unconfirmed', 'gdpr_unconfirmed'], TRUE);
    $application = $this->application(FALSE, $unconfirmed, in_array($exit, ['retention', 'unconfirmed'], TRUE));
    $evidence = $this->seedAllStores($application);
    if ($exit === 'delete') {
      $application->delete();
    }
    elseif ($exit === 'anonymize') {
      $application->set('field_data', '{"_anonymized":true}');
      $application->set('field_crm_data', NULL);
      $application->save();
    }
    elseif ($exit === 'gdpr_unconfirmed') {
      // Citizen erasure deletes only submissions whose double opt-in was
      // never confirmed; confirmed ones stay (see the test below).
      $response = $this->citizenErasure();
      $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }
    elseif (in_array($exit, ['retention', 'unconfirmed'], TRUE)) {
      $this->container->get('beantragt_application.retention_cleanup')->run(FALSE, 100);
      $this->assertNull($this->entityTypeManager()->getStorage('application')->load($application->id()));
    }
    elseif ($exit === 'admin') {
      $controller = AdminApplicantProfileController::create($this->container);
      $request = Request::create('/api/admin/applicants/delete-preview?jurisdiction=purge-jur', 'POST', content: json_encode(['email' => 'synthetic@example.test'], JSON_THROW_ON_ERROR));
      $preview = $controller->deletionPreview($request);
      $this->assertSame(200, $preview->getStatusCode(), $preview->getContent());
      $token = json_decode($preview->getContent(), TRUE, flags: JSON_THROW_ON_ERROR)['targetToken'];
      $request = Request::create('/api/admin/applicants?jurisdiction=purge-jur', 'DELETE', content: json_encode([
        'email' => 'synthetic@example.test',
        'confirmation_email' => 'synthetic@example.test',
        'target_token' => $token,
      ], JSON_THROW_ON_ERROR));
      $response = $controller->delete($request);
      $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }
    else {
      // Simulates an interrupted/older anonymiser that missed entity hooks.
      $this->container->get('database')->update('application')->fields(['field_data' => '{"_anonymized":true}'])->condition('id', $application->id())->execute();
      $this->container->get('database')->update('application_revision')->fields(['field_data' => '{"_anonymized":true}'])->condition('id', $application->id())->execute();
      $this->container->get('beantragt_delivery.application_purger')->cleanup();
    }
    $this->assertPurged($application, $evidence);
  }

  /**
   * Every known application exit, including the admin applicant route.
   */
  public static function exits(): array {
    $exits = ['delete', 'anonymize', 'gdpr_unconfirmed', 'retention',
      'unconfirmed', 'admin', 'cron',
    ];
    return array_combine($exits, array_map(static fn(string $exit): array => [$exit], $exits));
  }

  /**
   * Citizen erasure keeps every store of a confirmed application.
   *
   * The submitted application, its transfer records and the received decision
   * document belong to the record of the authority.
   */
  public function testCitizenErasureKeepsConfirmedApplicationStores(): void {
    $application = $this->application(TRUE, FALSE, FALSE);
    $evidence = $this->seedAllStores($application);
    $before = $this->storeRows($application, $evidence);
    $response = $this->citizenErasure();
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());

    $storage = $this->entityTypeManager()->getStorage('application');
    $storage->resetCache();
    $kept = $storage->load($application->id());
    $this->assertInstanceOf(ApplicationInterface::class, $kept);
    $this->assertSame($application->getData(), $kept->getData());
    $this->assertSame(0, (int) $kept->getOwnerId());
    $after = $this->storeRows($application, $evidence);
    foreach ($before as $table => $rows) {
      $this->assertNotEmpty($rows, $table);
      $this->assertEquals($rows, $after[$table], $table);
    }
    $this->entityTypeManager()->getStorage('file')->resetCache();
    $this->assertNotNull($this->entityTypeManager()->getStorage('file')->load($evidence['file_id']));
    $this->assertFileExists($evidence['uri']);
  }

  /**
   * Reads every registered store row of one application.
   *
   * @return array<string, array<int, object>>
   *   Rows keyed by table.
   */
  private function storeRows(ApplicationInterface $application, array $evidence): array {
    $database = $this->container->get('database');
    $rows = [];
    foreach (ApplicationDeliveryPurger::STORES as $table => $columns) {
      $column = isset($columns['application_id']) ? 'application_id' : 'response_uuid';
      $rows[$table] = $database->select($table, 's')->fields('s')->condition($column, $column === 'application_id' ? $application->id() : $evidence['response_uuid'])->execute()->fetchAll();
    }
    return $rows;
  }

  /**
   * Runs the citizen erasure endpoint as the current user.
   */
  private function citizenErasure(): Response {
    $request = Request::create('/auth/gdpr/all', 'DELETE');
    $request->setSession($this->container->get('request_stack')->getCurrentRequest()->getSession());
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    return GdprController::create($this->container)->deleteAll($request);
  }

  /**
   * Legacy attachments gain nullable proof fields without invented scan facts.
   */
  public function testAttachmentProofUpdateIsIdempotent(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_application/beantragt_application.install';
    $updates = $this->container->get('entity.definition_update_manager');
    foreach (['field_scan_sha256', 'field_submission_revision_id'] as $name) {
      $updates->uninstallFieldStorageDefinition($updates->getFieldStorageDefinition($name, 'application_attachment'));
      $this->assertNull($updates->getFieldStorageDefinition($name, 'application_attachment'));
    }
    beantragt_application_update_10021();
    beantragt_application_update_10021();
    foreach (['field_scan_sha256', 'field_submission_revision_id'] as $name) {
      $definition = $updates->getFieldStorageDefinition($name, 'application_attachment');
      $this->assertNotNull($definition);
      $this->assertTrue($definition->isReadOnly());
      $this->assertFalse($definition->isRequired());
    }
  }

  /**
   * An erasure marker cannot bypass source/CRM consistency for retained data.
   */
  #[DataProvider('invalidErasureMarkers')]
  public function testErasureExceptionRequiresEmptyPersonalData(string $data, ?string $crm): void {
    $application = $this->application(FALSE, TRUE, FALSE);
    $application->set('field_data', $data);
    $application->set('field_crm_data', $crm);
    $this->expectExceptionMessage('Application field_data changes must refresh CRM data');
    $application->save();
  }

  /**
   * Private source or CRM values must not survive an erasure-marker shortcut.
   */
  public static function invalidErasureMarkers(): array {
    return [
      ['{"_anonymized":true,"name":"PRIVATE-MARKER"}', NULL],
      ['{"_anonymized":true}', '{"name":"PRIVATE-MARKER"}'],
    ];
  }

  /**
   * Terminal payloads disappear, recoverable ones survive and audit expires.
   */
  public function testTerminalPayloadAndSkeletonRetention(): void {
    $application = $this->application(FALSE, TRUE, FALSE);
    $evidence = $this->seedAllStores($application);
    $database = $this->container->get('database');
    $payloads = $this->container->get('beantragt_delivery.transfer_payload_repository');
    $database->update('beantragt_delivery_log')->fields([
      'status' => 'failed',
      'last_error' => 'integration.connection_disabled',
    ])->condition('id', $evidence['log_id'])->execute();
    $payloads->purgeTerminalPayload($evidence['log_id']);
    $this->assertSame(1, (int) $database->select('beantragt_transfer_payload')->countQuery()->execute()->fetchField());
    $database->update('beantragt_delivery_log')->fields([
      'last_error' => 'integration.payload_invalid',
    ])->condition('id', $evidence['log_id'])->execute();
    $payloads->purgeTerminalPayload($evidence['log_id']);
    $this->assertSame(0, (int) $database->select('beantragt_transfer_payload')->countQuery()->execute()->fetchField());
    $application->delete();
    $database->update('beantragt_delivery_log')->fields(['changed' => 1])->condition('id', $evidence['log_id'])->execute();
    $this->container->get('beantragt_delivery.application_purger')->cleanup();
    $this->assertSame(0, (int) $database->select('beantragt_delivery_log')->countQuery()->execute()->fetchField());
  }

  /**
   * Unreferenced staging payloads have a grace period and bounded cleanup.
   */
  public function testOrphanPayloadGracePeriodAndBoundedBatch(): void {
    $application = $this->application(FALSE, TRUE, FALSE);
    $this->seedAllStores($application);
    $database = $this->container->get('database');
    $payloads = $this->container->get('beantragt_delivery.transfer_payload_repository');
    for ($index = 0; $index < 101; $index++) {
      $payloads->store((int) $application->id(), '{}');
    }
    $database->update('beantragt_transfer_payload')->fields(['created' => 1])->execute();
    $payloads->store((int) $application->id(), '{}');
    $this->assertSame(103, (int) $database->select('beantragt_transfer_payload')->countQuery()->execute()->fetchField());
    $payloads->cleanupOrphans();
    $this->assertSame(3, (int) $database->select('beantragt_transfer_payload')->countQuery()->execute()->fetchField());
    $payloads->cleanupOrphans();
    $this->assertSame(2, (int) $database->select('beantragt_transfer_payload')->countQuery()->execute()->fetchField());
    $payloads->cleanupOrphans();
    $this->assertSame(2, (int) $database->select('beantragt_transfer_payload')->countQuery()->execute()->fetchField());
  }

  /**
   * Saves an actual application with real form, scope and workflow entities.
   */
  private function application(bool $terminal, bool $unconfirmed, bool $expired): ApplicationInterface {
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Synthetic jurisdiction',
      'field_slug' => 'purge-jur',
    ]);
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Synthetic state',
      'field_group' => $jurisdiction->id(),
      'field_machine_name' => 'synthetic',
      'field_is_default' => TRUE,
      'field_is_terminal' => $terminal,
    ]);
    $status->save();
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Synthetic form',
      'field_schema' => '{"sections":[]}',
      'field_jurisdiction' => $jurisdiction->id(),
      'field_group' => $jurisdiction->id(),
    ]);
    $form->save();
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'uid' => $this->container->get('current_user')->id(),
      'field_schema' => $form->id(),
      'field_workflow_status' => $status->id(),
      'field_jurisdiction' => $jurisdiction->id(),
      'field_group' => $jurisdiction->id(),
      'field_data' => '{"synthetic":"PRIVATE-MARKER"}',
      'field_applicant_email' => 'synthetic@example.test',
      'field_email_confirmed' => !$unconfirmed,
      'created' => $expired ? time() - 1000 * 86400 : time(),
    ]);
    $application->save();
    return $application;
  }

  /**
   * Seeds every registered linked store, document, managed file and queue item.
   */
  private function seedAllStores(ApplicationInterface $application): array {
    $database = $this->container->get('database');
    $uuid = $this->container->get('uuid');
    $logs = $this->container->get('beantragt_delivery.log_repository');
    $reference = $this->container->get('beantragt_delivery.transfer_payload_repository')->store((int) $application->id(), '{"synthetic":"PRIVATE-MARKER"}');
    $envelope = new DeliveryEnvelope((int) $application->id(), $application->uuid(), 'PRIVATE-MARKER', 'schema', 'PRIVATE-MARKER', 1, hash('sha256', $application->getData()), 1, 0);
    $log_id = $logs->createQueued($application, $envelope, 'fit_connect', 'PRIVATE-MARKER', [
      'scope_group_id' => (int) $application->get('field_jurisdiction')->target_id,
      'payload_reference' => $reference,
      'payload_sha256' => str_repeat('a', 64),
      'connection_snapshot' => '{"label":"PRIVATE-MARKER"}',
      'validation_errors' => '[{"source":"PRIVATE-MARKER"}]',
    ], DeliveryLogRepository::STATUS_AWAITING_APPROVAL);
    $response_uuid = $uuid->generate();
    $uri = 'private://external_responses/' . $application->uuid() . '/' . $response_uuid . '/decision.pdf';
    $directory = dirname($uri);
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($uri, '%PDF-1.7 PRIVATE-MARKER');
    $file = $this->entityTypeManager()->getStorage('file')->create([
      'uri' => $uri,
      'filename' => 'PRIVATE-MARKER.pdf',
      'status' => 1,
      'uid' => 0,
    ]);
    $file->save();
    $this->container->get('file.usage')->add($file, 'beantragt_delivery', 'application', $application->id());
    $this->container->get('beantragt_delivery.external_response_repository')->insert([
      'uuid' => $response_uuid,
      'application_id' => $application->id(),
      'source' => 'fit_connect',
      'external_id_hash' => hash('sha256', $response_uuid),
      'external_reference' => 'PRIVATE-MARKER',
      'title' => 'PRIVATE-MARKER',
    ], [[
      'uuid' => $uuid->generate(),
      'response_uuid' => $response_uuid,
      'file_id' => $file->id(),
      'sha512' => str_repeat('a', 128),
    ],
    ]);
    $seeded = ['beantragt_delivery_log', 'beantragt_transfer_payload',
      'beantragt_external_response', 'beantragt_external_response_document',
    ];
    foreach (ApplicationDeliveryPurger::STORES as $table => $columns) {
      if (in_array($table, $seeded, TRUE)) {
        continue;
      }
      $values = [];
      if ($table === 'beantragt_transfer_plan') {
        $database->delete($table)->condition('application_id', $application->id())->execute();
      }
      foreach (beantragt_delivery_schema()[$table]['fields'] as $name => $field) {
        if ($field['type'] === 'serial') {
          continue;
        }
        $values[$name] = match ($name) {
          'application_id' => (int) $application->id(),
          'application_uuid' => $application->uuid(),
          'log_id' => $log_id,
          'created', 'changed' => time(),
          'uid' => 1,
          'action' => 'approve_dispatch',
          'outcome' => 'success',
          'code' => 'integration.synthetic',
          default => $field['type'] === 'int' ? 1 : (str_contains($name, 'uuid') || str_ends_with($name, '_id') ? $uuid->generate() : substr('PRIVATE-MARKER', 0, $field['length'] ?? 100)),
        };
      }
      $database->insert($table)->fields($values)->execute();
      $seeded[] = $table;
    }
    $this->assertEqualsCanonicalizing(array_keys(ApplicationDeliveryPurger::STORES), $seeded);
    $queue = $this->container->get('queue')->get(DeliveryDispatcher::QUEUE_NAME);
    $item = $queue->createItem([
      'logId' => $log_id,
      'applicationId' => (int) $application->id(),
      'adapter' => 'fit_connect',
    ]);
    return [
      'log_id' => $log_id,
      'file_id' => $file->id(),
      'uri' => $uri,
      'queue_id' => $item,
      'response_uuid' => $response_uuid,
    ];
  }

  /**
   * Asserts every store and file usage entry loses all private material.
   */
  private function assertPurged(ApplicationInterface $application, array $evidence): void {
    $database = $this->container->get('database');
    foreach (ApplicationDeliveryPurger::STORES as $table => $columns) {
      $column = isset($columns['application_id']) ? 'application_id' : 'response_uuid';
      $rows = $database->select($table, 's')->fields('s')->condition($column, $column === 'application_id' ? $application->id() : $evidence['response_uuid'])->execute()->fetchAll();
      if (in_array($table, ['beantragt_delivery_log', 'beantragt_transfer_audit'], TRUE)) {
        $this->assertNotEmpty($rows, $table);
        $this->assertStringNotContainsString('PRIVATE-MARKER', json_encode($rows, JSON_THROW_ON_ERROR), $table);
        foreach ($rows as $row) {
          if ($table === 'beantragt_transfer_audit') {
            $this->assertNull($row->uid);
          }
          else {
            $this->assertSame('failed', $row->status);
            $this->assertSame('integration.application_anonymised', $row->last_error);
          }
        }
      }
      else {
        $this->assertSame([], $rows, $table);
      }
    }
    $this->entityTypeManager()->getStorage('file')->resetCache();
    $this->assertNull($this->entityTypeManager()->getStorage('file')->load($evidence['file_id']));
    $this->assertFalse(file_exists($evidence['uri']));
    $this->assertSame(0, (int) $database->select('file_usage')->condition('fid', $evidence['file_id'])->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $database->select('queue')->condition('item_id', $evidence['queue_id'])->countQuery()->execute()->fetchField());
  }

}
