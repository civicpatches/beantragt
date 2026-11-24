<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Service\TransferPayloadRepository;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the explicit one-time legacy boundary and idempotent transfer upgrade.
 */
#[Group('beantragt_delivery')]
final class TransferUpdateTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization',
    'language', 'beantragt_schema', 'beantragt_workflow_status',
    'beantragt_application', 'beantragt_group', 'beantragt_notification',
    'beantragt_delivery',
  ];

  /**
   * Existing pending legacy rows survive without creating assignments or work.
   */
  public function testMigrationIsIdempotentAndNeverEnqueues(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_application/beantragt_application.install';
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('application');
    $updates = $this->container->get('entity.definition_update_manager');
    $updates->uninstallFieldStorageDefinition($updates->getFieldStorageDefinition('field_schema_revision_id', 'application'));
    $this->assertNull($updates->getFieldStorageDefinition('field_schema_revision_id', 'application'));
    beantragt_application_update_10020();
    beantragt_application_update_10020();
    $this->assertNotNull($updates->getFieldStorageDefinition('field_schema_revision_id', 'application'));
    $this->assertSame(10020, beantragt_delivery_update_dependencies()['beantragt_delivery'][10007]['beantragt_application']);
    $database = $this->container->get('database');
    foreach (IntegrationRepository::schema() as $table => $definition) {
      $database->schema()->createTable($table, $definition);
    }
    $definition = beantragt_delivery_schema()['beantragt_delivery_log'];
    foreach (array_keys(DeliveryLogRepository::bindingFields()) as $name) {
      unset($definition['fields'][$name]);
    }
    unset($definition['indexes']['payload_reference'], $definition['indexes']['scope_connection']);
    $database->schema()->createTable('beantragt_delivery_log', $definition);
    $queue = $this->container->get('queue')->get('beantragt_delivery');
    $queue->createQueue();
    foreach (['case_worker', 'department_admin', 'synthetic_custom_role'] as $id) {
      Role::create(['id' => $id, 'label' => 'Synthetic role'])->save();
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('form_schema');
    $this->setCurrentUser($this->container->get('entity_type.manager')->getStorage('user')->load(1));
    $legacy = $storage->create([
      'label' => 'Synthetic legacy FIT policy',
      'field_schema' => json_encode([
        'title' => 'Synthetic',
        'sections' => [],
        'delivery' => ['adapter' => 'fit_connect'],
      ], JSON_THROW_ON_ERROR),
    ]);
    $legacy->save();
    $local = $storage->create([
      'label' => 'Synthetic internal form',
      'field_schema' => json_encode(['title' => 'Synthetic', 'sections' => []], JSON_THROW_ON_ERROR),
    ]);
    $local->save();
    $rows = [];
    foreach (['queued', 'failed', 'processing', 'delivered', 'awaiting_approval'] as $status) {
      $rows[$status] = $this->insertRow('fit_connect', $status);
    }
    $mock = $this->insertRow('mock', 'queued');
    $config = $this->container->get('config.factory')->getEditable('beantragt_delivery.settings');
    $config->set('default_adapter', 'fit_connect')->save(TRUE);
    $before_config = $config->getRawData();

    beantragt_delivery_update_10007();
    $this->assertNotNull($updates->getFieldStorageDefinition('field_schema_revision_id', 'application'));
    foreach (array_keys(DeliveryLogRepository::bindingFields()) as $name) {
      $this->assertTrue($database->schema()->fieldExists('beantragt_delivery_log', $name));
    }
    foreach (array_keys(TransferPayloadRepository::schema()) as $name) {
      $this->assertTrue($database->schema()->tableExists($name));
    }
    foreach ($rows as $status => $id) {
      $eligible = $database->select('beantragt_delivery_log', 'd')->fields('d', ['legacy_eligible'])->condition('id', $id)->execute()->fetchField();
      $this->assertSame(in_array($status, ['queued', 'failed', 'processing'], TRUE) ? 1 : NULL, $eligible === NULL ? NULL : (int) $eligible);
    }
    $this->assertNull($database->select('beantragt_delivery_log', 'd')->fields('d', ['legacy_eligible'])->condition('id', $mock)->execute()->fetchField());
    $report = $this->container->get('state')->get('beantragt_delivery.legacy_policies');
    $this->assertSame([[
      'form_schema_uuid' => $legacy->uuid(),
      'revision_id' => (int) $legacy->getRevisionId(),
      'code' => 'integration.legacy_policy_without_assignment',
    ],
    ], $report);
    $late = $this->insertRow('fit_connect', 'queued');
    beantragt_delivery_update_10007();
    $this->assertNull($database->select('beantragt_delivery_log', 'd')->fields('d', ['legacy_eligible'])->condition('id', $late)->execute()->fetchField());
    $this->assertSame($report, $this->container->get('state')->get('beantragt_delivery.legacy_policies'));
    $this->assertSame($before_config, $this->container->get('config.factory')->getEditable('beantragt_delivery.settings')->getRawData());
    $this->assertTrue(Role::load('case_worker')->hasPermission('approve application dispatch'));
    $this->assertTrue(Role::load('department_admin')->hasPermission('approve application dispatch'));
    $this->assertFalse(Role::load('synthetic_custom_role')->hasPermission('approve application dispatch'));
    $this->assertFalse(Role::load('case_worker')->hasPermission('administer integration mappings'));
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertSame(7, (int) $database->select('beantragt_delivery_log')->countQuery()->execute()->fetchField());
    foreach (array_keys(IntegrationRepository::schema()) as $name) {
      $this->assertSame(0, (int) $database->select($name)->countQuery()->execute()->fetchField());
    }
    $application_storage = $this->container->get('entity_type.manager')->getStorage('application');
    $application = $application_storage->create(['field_schema' => $local->id(), 'field_schema_revision_id' => 999999]);
    $application->preSave($application_storage);
    $this->assertSame((int) $local->getRevisionId(), (int) $application->get('field_schema_revision_id')->value);
    $application->set('id', 22);
    $application->enforceIsNew(FALSE);
    $application->setOriginal(clone $application);
    $application->set('field_schema_revision_id', 999999);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The submitted schema revision is immutable.');
    $application->preSave($application_storage);
  }

  /**
   * Partial DDL resumes only saved candidates, never newly inserted rows.
   */
  public function testInterruptedMigrationUsesItsFrozenCandidateSet(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('application');
    $database = $this->container->get('database');
    $definition = beantragt_delivery_schema()['beantragt_delivery_log'];
    foreach (array_keys(DeliveryLogRepository::bindingFields()) as $name) {
      if ($name !== 'legacy_eligible') {
        unset($definition['fields'][$name]);
      }
    }
    unset($definition['indexes']['payload_reference'], $definition['indexes']['scope_connection']);
    $database->schema()->createTable('beantragt_delivery_log', $definition);
    $candidate = $this->insertRow('fit_connect', 'queued');
    $late = $this->insertRow('fit_connect', 'failed');
    $state = $this->container->get('state');
    $state->set('beantragt_delivery.legacy_candidate_ids', [$candidate]);
    $state->set('beantragt_delivery.legacy_migration_complete', FALSE);
    beantragt_delivery_update_10007();
    $rows = $database->select('beantragt_delivery_log', 'd')->fields('d', ['id', 'legacy_eligible'])->execute()->fetchAllKeyed();
    $this->assertSame(1, (int) $rows[$candidate]);
    $this->assertNull($rows[$late]);
    $this->assertTrue($state->get('beantragt_delivery.legacy_migration_complete'));
    $this->assertNull($state->get('beantragt_delivery.legacy_candidate_ids'));
    beantragt_delivery_update_10007();
    $this->assertSame($rows, $database->select('beantragt_delivery_log', 'd')->fields('d', ['id', 'legacy_eligible'])->execute()->fetchAllKeyed());
  }

  /**
   * Recovery upgrade adds indexes/audit and authenticates old payload context.
   */
  public function testRecoveryUpdateIsIdempotentAndDoesNotEnqueue(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $this->installEntitySchema('application');
    $database = $this->container->get('database');
    foreach (IntegrationRepository::schema() as $table => $definition) {
      if ($table === 'beantragt_integration_assignment') {
        unset($definition['fields']['send_attachments']);
      }
      $database->schema()->createTable($table, $definition);
    }
    $definition = beantragt_delivery_schema()['beantragt_delivery_log'];
    foreach (array_keys(DeliveryLogRepository::operationalFields()) as $name) {
      unset($definition['fields'][$name]);
    }
    foreach (['payload_reference', 'scope_connection', 'replaces_uuid'] as $name) {
      unset($definition['indexes'][$name]);
    }
    $database->schema()->createTable('beantragt_delivery_log', $definition);
    $database->schema()->createTable('beantragt_transfer_payload', TransferPayloadRepository::schema()['beantragt_transfer_payload']);
    $id = $this->insertRow('fit_connect', 'awaiting_approval');
    $json = '{"synthetic":"private payload"}';
    $reference = $this->container->get('uuid')->generate();
    $original_key = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('u', 32));
    try {
      /** @var \Drupal\beantragt_application\Service\ApplicationDataProtectionService $protection */
      $protection = $this->container->get('beantragt_application.data_protection');
      $this->assertInstanceOf(ApplicationDataProtectionService::class, $protection);
      $protected = $protection->protectData(['payload' => $json], [
        'sections' => [['fields' => [['id' => 'payload', 'type' => 'text', 'sensitive' => TRUE]]]],
      ]);
      $database->insert('beantragt_transfer_payload')->fields([
        'uuid' => $reference,
        'application_id' => 1,
        'ciphertext' => json_encode($protected, JSON_THROW_ON_ERROR),
        'created' => 1,
      ])->execute();
      $database->update('beantragt_delivery_log')->fields([
        'payload_reference' => $reference,
        'payload_sha256' => hash('sha256', $json),
        'attempts' => 3,
        'scope_group_id' => 1,
        'connection_snapshot' => '{"destination_id":"synthetic-fixed-destination","profile":"synthetic-profile"}',
      ])->condition('id', $id)->execute();
      $queue = $this->container->get('queue')->get('beantragt_delivery');
      $queue->createQueue();
      beantragt_delivery_update_10008();
      beantragt_delivery_update_10008();
      foreach (array_keys(DeliveryLogRepository::operationalFields()) as $name) {
        $this->assertTrue($database->schema()->fieldExists('beantragt_delivery_log', $name));
      }
      foreach (['payload_reference', 'scope_connection', 'replaces_uuid'] as $name) {
        $this->assertTrue($database->schema()->indexExists('beantragt_delivery_log', $name));
      }
      $this->assertTrue($database->schema()->tableExists('beantragt_transfer_audit'));
      $this->assertTrue($database->schema()->fieldExists('beantragt_integration_assignment', 'send_attachments'));
      $this->assertSame(3, (int) $database->select('beantragt_delivery_log', 'd')->fields('d', ['automatic_attempts'])->condition('id', $id)->execute()->fetchField());
      $snapshot = json_decode($database->select('beantragt_delivery_log', 'd')->fields('d', ['connection_snapshot'])->condition('id', $id)->execute()->fetchField(), TRUE, flags: JSON_THROW_ON_ERROR);
      $this->assertSame('synthetic-fixed-destination', $snapshot['destination_id']);
      $this->assertSame('synthetic-profile', $snapshot['profile']);
      $this->assertTrue($snapshot['send_attachments']);
      $this->assertSame($json, $this->container->get('beantragt_delivery.transfer_payload_repository')->verified([
        'applicationId' => 1,
        'payload_reference' => $reference,
        'payload_sha256' => hash('sha256', $json),
      ]));
      $this->assertSame(0, $queue->numberOfItems());
      $this->assertSame(1, (int) $database->select('beantragt_delivery_log')->countQuery()->execute()->fetchField());
    }
    finally {
      putenv($original_key === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $original_key);
    }
  }

  /**
   * Inserts a synthetic pre-upgrade row with no transport or network activity.
   */
  private function insertRow(string $adapter, string $status): int {
    return (int) $this->container->get('database')->insert('beantragt_delivery_log')->fields([
      'uuid' => $this->container->get('uuid')->generate(),
      'application_id' => 1,
      'application_uuid' => '00000000-0000-4000-8000-000000000001',
      'adapter' => $adapter,
      'status' => $status,
      'data_hash' => hash('sha256', '{}'),
      'created' => 1,
      'changed' => 1,
    ])->execute();
  }

}
