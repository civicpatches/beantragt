<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Utility\UpdateException;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies explicit, idempotent migration without generating tenant work.
 */
#[Group('beantragt_delivery')]
final class IntegrationUpdateTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'file', 'jsonapi', 'json_form_widget', 'serialization',
    'language', 'beantragt_schema', 'beantragt_workflow_status',
    'beantragt_application', 'beantragt_group', 'beantragt_notification',
    'beantragt_delivery',
  ];

  /**
   * Existing dispatcher config is preserved and no deliveries are created.
   */
  public function testMigrationIsIdempotentAndDoesNotEnqueue(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $database = $this->container->get('database');
    $schemas = beantragt_delivery_schema();
    $database->schema()->createTable('beantragt_delivery_log', $schemas['beantragt_delivery_log']);
    $queue = $this->container->get('queue')->get('beantragt_delivery');
    $queue->createQueue();
    $old = [
      'client_id' => 'synthetic-client',
      'client_secret_env' => 'SYNTHETIC_SECRET_ENV',
      'token_url' => 'https://auth.test.fit-connect.fitko.net/token',
      'submission_api_base' => 'https://api.test.fit-connect.fitko.net',
      'metadata_schema_version' => '2.1.0',
      'destination_id' => '00000000-0000-4000-8000-000000000001',
    ];
    $config = $this->container->get('config.factory')->getEditable('beantragt_delivery.settings');
    $config->set('fit_connect', $old)->save(TRUE);
    Role::create(['id' => 'department_admin', 'label' => 'Synthetic department admin'])->save();
    Role::create(['id' => 'case_worker', 'label' => 'Synthetic case worker'])->save();

    beantragt_delivery_update_10005();
    $first = $this->container->get('config.factory')->getEditable('beantragt_delivery.settings')->getRawData();
    self::assertEquals($old, $first['fit_connect']);
    self::assertSame('synthetic-client', $first['credential_profiles']['default']['client_id']);
    self::assertSame('SYNTHETIC_SECRET_ENV', $first['credential_profiles']['default']['client_secret_env']);
    self::assertArrayNotHasKey('destination_id', $first['credential_profiles']['default']);
    beantragt_delivery_update_10005();
    self::assertSame($first, $this->container->get('config.factory')->getEditable('beantragt_delivery.settings')->getRawData());

    foreach (array_keys(IntegrationRepository::schema()) as $table) {
      self::assertTrue($database->schema()->tableExists($table));
      self::assertSame(0, (int) $database->select($table)->countQuery()->execute()->fetchField());
    }
    self::assertSame(0, (int) $database->select('beantragt_delivery_log')->countQuery()->execute()->fetchField());
    self::assertSame(0, $queue->numberOfItems());
    foreach (['administer integration connections', 'administer integration mappings'] as $permission) {
      self::assertTrue(Role::load('department_admin')->hasPermission($permission));
      self::assertFalse(Role::load('case_worker')->hasPermission($permission));
    }

    $profiles = $first['credential_profiles'];
    $profiles['default']['label'] = 'Operator custom label';
    $config->set('credential_profiles', $profiles)->save(TRUE);
    beantragt_delivery_update_10005();
    self::assertSame($profiles, $this->container->get('config.factory')->getEditable('beantragt_delivery.settings')->get('credential_profiles'));
  }

  /**
   * Upgrades a site that already ran 10005 without widening operator grants.
   */
  public function testFollowupMigrationRepairsLegacyProfilesAndIsIdempotent(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $database = $this->container->get('database');
    // Reproduce the earlier storage shape, before ASCII fields and new indexes.
    foreach (IntegrationRepository::schema() as $table => $definition) {
      if ($table === 'beantragt_integration_scope_lock') {
        continue;
      }
      foreach ($definition['fields'] as &$field) {
        if ($field['type'] === 'varchar_ascii') {
          $field['type'] = 'varchar';
        }
      }
      unset($field);
      if (isset($definition['fields']['last_check_result'])) {
        $definition['fields']['last_check_result']['not null'] = TRUE;
      }
      $definition['indexes'] = ['scope' => ['scope_group_id']];
      $database->schema()->createTable($table, $definition);
    }
    $stored_connection = [
      'uuid' => '00000000-0000-4000-8000-000000000006',
      'scope_group_id' => 77,
      'created' => 1,
      'changed' => 2,
      'uid' => 3,
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
      'label' => 'Synthetic prior connection',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'default',
      'destination_id' => '00000000-0000-4000-8000-000000000096',
      'service_urn' => 'urn:example:synthetic-service',
      'service_name' => 'Synthetic service',
      'reply_process_standard' => NULL,
      'status' => 'draft',
      'last_check_result' => 'null',
    ];
    $database->insert('beantragt_integration_connection')->fields($stored_connection)->execute();
    $schemas = beantragt_delivery_schema();
    $database->schema()->createTable('beantragt_delivery_log', $schemas['beantragt_delivery_log']);
    $queue = $this->container->get('queue')->get('beantragt_delivery');
    $queue->createQueue();
    $this->container->get('keyvalue')->get('system.schema')->set('beantragt_delivery', 10005);
    $legacy = [
      'client_id' => '',
      'client_secret_env' => 'SYNTHETIC_SECRET_ENV',
      'token_url' => '',
      'submission_api_base' => '',
      'metadata_schema_version' => '2.1.0',
    ];
    $profiles = [
      'default' => ['label' => 'Legacy synthetic default'] + $legacy,
      'dedicated' => ['label' => 'Other synthetic identity'] + $legacy,
      'restricted' => ['label' => 'Explicit restriction', 'allowed_scopes' => [77], 'all_scopes' => FALSE] + $legacy,
    ];
    $factory = $this->container->get('config.factory');
    $factory->getEditable('beantragt_delivery.settings')
      ->set('fit_connect', $legacy)
      ->set('credential_profiles', $profiles)
      ->save(TRUE);

    $message = beantragt_delivery_update_10006();
    self::assertStringContainsString('empty client_id', $message);
    self::assertStringContainsString('not granted', $message);
    $first = $factory->getEditable('beantragt_delivery.settings')->getRawData();
    self::assertEquals($legacy, $first['fit_connect']);
    self::assertTrue($first['credential_profiles']['default']['all_scopes']);
    self::assertSame([], $first['credential_profiles']['default']['allowed_scopes']);
    self::assertSame(FitConnectSettings::DEFAULT_TEST_TOKEN_URL, $first['credential_profiles']['default']['token_url']);
    self::assertSame(FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE, $first['credential_profiles']['default']['submission_api_base']);
    self::assertFalse($first['credential_profiles']['dedicated']['all_scopes']);
    self::assertSame([], $first['credential_profiles']['dedicated']['allowed_scopes']);
    self::assertSame('', $first['credential_profiles']['dedicated']['token_url']);
    self::assertEquals($profiles['restricted'], $first['credential_profiles']['restricted']);
    beantragt_delivery_update_10006();
    self::assertSame($first, $factory->getEditable('beantragt_delivery.settings')->getRawData());
    foreach (IntegrationRepository::schema() as $table => $definition) {
      self::assertTrue($database->schema()->tableExists($table));
      foreach ($definition['indexes'] ?? [] as $name => $columns) {
        self::assertTrue($database->schema()->indexExists($table, $name));
      }
      $expected = in_array($table, ['beantragt_integration_connection', 'beantragt_integration_scope_lock'], TRUE) ? 1 : 0;
      self::assertSame($expected, (int) $database->select($table)->countQuery()->execute()->fetchField());
    }
    $after = $database->select('beantragt_integration_connection', 'c')->fields('c')->execute()->fetchAssoc();
    $stored_connection['last_check_result'] = NULL;
    $stored_connection['presentation'] = NULL;
    self::assertEquals($stored_connection, $after);
    self::assertNull($after['last_check_result']);
    self::assertSame(0, (int) $database->select('beantragt_delivery_log')->countQuery()->execute()->fetchField());
    self::assertSame(0, $queue->numberOfItems());
  }

  /**
   * The presentation column arrives once and leaves connections untouched.
   */
  public function testPresentationUpdateAddsColumnOnceAndKeepsConnections(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $database = $this->container->get('database');
    $definition = IntegrationRepository::schema()['beantragt_integration_connection'];
    unset($definition['fields']['presentation']);
    $database->schema()->createTable('beantragt_integration_connection', $definition);
    $row = [
      'uuid' => '00000000-0000-4000-8000-000000000009',
      'scope_group_id' => 77,
      'created' => 1,
      'changed' => 2,
      'uid' => 3,
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
      'label' => 'Existing connection',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'default',
      'destination_id' => '00000000-0000-4000-8000-000000000010',
      'service_urn' => 'urn:de:fim:leika:leistung:99000000000000',
      'service_name' => 'Service',
      'status' => 'active',
    ];
    $database->insert('beantragt_integration_connection')->fields($row)->execute();

    beantragt_delivery_update_10010();
    beantragt_delivery_update_10010();

    self::assertTrue($database->schema()->fieldExists('beantragt_integration_connection', 'presentation'));
    $after = $database->select('beantragt_integration_connection', 'c')->fields('c')->execute()->fetchAssoc();
    self::assertSame('Existing connection', $after['label']);
    self::assertSame('active', $after['status']);
    // No stored presentation means: status names and closing on the notice.
    self::assertNull($after['presentation']);
    self::assertSame('on_response', IntegrationRepository::presentation(NULL)['closure_rule']);
  }

  /**
   * Explicit restrictions on the default survive repeated upgrades unchanged.
   */
  public function testFollowupKeepsScopedDefaultAndGrantsPermissionsOnce(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $factory = $this->container->get('config.factory');
    $profile = [
      'label' => 'Scoped synthetic default',
      'client_id' => 'synthetic-client',
      'client_secret_env' => 'SYNTHETIC_SECRET_ENV',
      'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'metadata_schema_version' => '2.1.0',
      'allowed_scopes' => [77],
      'all_scopes' => FALSE,
    ];
    $factory->getEditable('beantragt_delivery.settings')->set('credential_profiles', ['default' => $profile])->save(TRUE);
    Role::create(['id' => 'department_admin', 'label' => 'Synthetic admin'])->save();
    self::assertStringContainsString('Granted integration permissions', beantragt_delivery_update_10006());
    self::assertStringContainsString('already granted', beantragt_delivery_update_10006());
    self::assertFalse(_beantragt_delivery_grant_integration_permissions());
    self::assertEquals($profile, $factory->getEditable('beantragt_delivery.settings')->get('credential_profiles.default'));
  }

  /**
   * Legacy case variants acquire canonical slots without creating deliveries.
   */
  public function testLegacyAssignmentKeysAreCanonicalized(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $database = $this->container->get('database');
    foreach (IntegrationRepository::schema() as $table => $definition) {
      $database->schema()->createTable($table, $definition);
    }
    $table = 'beantragt_integration_assignment';
    $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $form = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $row = [
      'uuid' => $uuid,
      'scope_group_id' => 1,
      'created' => 1,
      'changed' => 1,
      'uid' => 1,
      'form_schema_uuid' => strtoupper($form),
      'compatible_revisions' => '[1]',
      'service_urn' => 'urn:FAKE:SERVICE',
      'connection_uuid' => $uuid,
      'mapping_uuid' => $uuid,
      'mapping_version' => 1,
      'dispatch_mode' => 'approval',
      'status' => 'active',
      'active_key' => str_repeat('a', 64),
    ];
    $database->insert($table)->fields($row)->execute();
    _beantragt_delivery_normalize_keys_10006();
    $first = $database->select($table, 'i')->fields('i')->execute()->fetchAssoc();
    self::assertSame('urn:fake:service', $first['service_urn']);
    self::assertSame($form, $first['form_schema_uuid']);
    self::assertSame(hash('sha256', '[1,"' . $form . '","urn:fake:service"]'), $first['active_key']);
    _beantragt_delivery_normalize_keys_10006();
    self::assertSame($first, $database->select($table, 'i')->fields('i')->execute()->fetchAssoc());
    $row['uuid'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $row['active_key'] = str_repeat('b', 64);
    $database->insert($table)->fields($row)->execute();
    $this->expectException(UpdateException::class);
    $this->expectExceptionMessage('Conflicting active integration assignments');
    _beantragt_delivery_normalize_keys_10006();
  }

}
