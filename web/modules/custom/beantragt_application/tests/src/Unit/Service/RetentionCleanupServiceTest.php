<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\RetentionCleanupService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests retention policy normalization.
 *
 * @group beantragt_application
 */
final class RetentionCleanupServiceTest extends UnitTestCase {

  /**
   * Destructive runs fail closed when another cleanup holds the lock.
   */
  public function testDestructiveRunSkipsWhenLockIsUnavailable(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.retention_cleanup', 3600.0)
      ->willReturn(FALSE);
    $lock->expects($this->never())->method('release');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $service = $this->service(lock: $lock, logger: $logger);
    $report = $service->run(FALSE);

    $this->assertSame([
      [
        'code' => 'retention_cleanup_already_running',
        'message' => 'Retention cleanup is already running.',
      ],
    ], $report['errors']);
  }

  /**
   * Destructive runs release the shared lock after execution.
   */
  public function testDestructiveRunReleasesLock(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(TRUE);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_application.retention_cleanup');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')->willReturn(FALSE);

    $config = $this->config(['data_retention_days' => 0]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('beantragt_application.settings')->willReturn($config);

    $service = $this->service(
      entityTypeManager: $entity_type_manager,
      configFactory: $config_factory,
      lock: $lock,
    );
    $report = $service->run(FALSE);

    $this->assertSame([], $report['errors']);
  }

  /**
   * Application dependent cleanup deletes attachments, comments and files.
   */
  public function testDeleteApplicationDependentsDeletesAttachmentsCommentsAndFiles(): void {
    $attachment = $this->fieldableEntity('field_file', [301, 302], [
      'field_guard_derivative' => [303],
    ]);
    $comment_a = $this->fieldableEntity('field_attachments', [401]);
    $comment_b = $this->fieldableEntity('field_attachments', [401, 402]);
    $file_301 = $this->createMock(EntityInterface::class);
    $file_302 = $this->createMock(EntityInterface::class);
    $file_401 = $this->createMock(EntityInterface::class);
    $file_402 = $this->createMock(EntityInterface::class);

    $attachment_storage = $this->storage([10], [10 => $attachment]);
    $attachment_storage->expects($this->once())
      ->method('delete')
      ->with([10 => $attachment]);

    $comment_storage = $this->storage([20, 21], [
      20 => $comment_a,
      21 => $comment_b,
    ]);
    $comment_storage->expects($this->once())
      ->method('delete')
      ->with([
        20 => $comment_a,
        21 => $comment_b,
      ]);

    $deleted_file_batches = [];
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->expects($this->exactly(2))
      ->method('loadMultiple')
      ->willReturnMap([
        [[301, 302], [301 => $file_301, 302 => $file_302]],
        [[401, 402], [401 => $file_401, 402 => $file_402]],
      ]);
    $file_storage->expects($this->exactly(2))
      ->method('delete')
      ->willReturnCallback(static function (array $files) use (&$deleted_file_batches): void {
        $deleted_file_batches[] = array_keys($files);
      });

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $entity_type): bool => in_array($entity_type, [
        'application_attachment',
        'application_comment',
      ], TRUE));
    $entity_type_manager->method('getStorage')
      ->willReturnMap([
        ['application_attachment', $attachment_storage],
        ['application_comment', $comment_storage],
        ['file', $file_storage],
      ]);

    $service = $this->service(entityTypeManager: $entity_type_manager);
    $report = $service->deleteApplicationDependents([77], FALSE);

    $this->assertSame(['matched' => 1, 'deleted' => 1], $report['applicationAttachments']);
    $this->assertSame(['matched' => 3, 'deleted' => 3], $report['applicationAttachmentFiles']);
    $this->assertSame(['matched' => 2, 'deleted' => 2], $report['comments']);
    $this->assertSame(['matched' => 2, 'deleted' => 2], $report['commentAttachmentFiles']);
    $this->assertSame([[301, 302], [401, 402]], $deleted_file_batches);
  }

  /**
   * Expired upload cleanup includes application-bound comment reservations.
   */
  public function testExpiredAttachmentCleanupSelectsCleanUploadedReservations(): void {
    $conditions = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (string $field, mixed $value = NULL, ?string $operator = NULL) use (&$conditions, $query): QueryInterface {
        $conditions[] = [$field, $value, $operator];
        return $query;
      },
    );
    $query->expects($this->never())->method('notExists');
    $query->method('range')->with(0, 100)->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('getQuery')->willReturn($query);
    $attachment_storage->expects($this->never())->method('delete');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $entity_type): bool => $entity_type === 'application_attachment');
    $entity_type_manager->method('getStorage')
      ->with('application_attachment')
      ->willReturn($attachment_storage);

    $config = $this->config(['data_retention_days' => 0]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('beantragt_application.settings')->willReturn($config);

    $service = $this->service(
      entityTypeManager: $entity_type_manager,
      configFactory: $config_factory,
    );
    $service->run(TRUE);

    $this->assertContains(['field_upload_status', 'uploaded', NULL], $conditions);
    $this->assertContains(['field_claim_expires', 1781678735, '<'], $conditions);
    $this->assertContains(['field_scan_status', 'clean', NULL], $conditions);
  }

  /**
   * Bound comment reservations are revalidated under claim and app locks.
   */
  public function testExpiredBoundCommentReservationIsDeletedUnderLocks(): void {
    $fields = [
      'field_upload_status' => $this->fieldList(['value' => 'uploaded']),
      'field_claim_expires' => $this->fieldList(['value' => 1781678734]),
      'field_scan_status' => $this->fieldList(['value' => 'clean']),
      'field_application' => $this->fieldList(['target_id' => 44]),
      'field_field_id' => $this->fieldList(['value' => 'comment_attachment']),
      'field_file' => $this->fieldList(['target_id' => 301]),
    ];
    $attachment = $this->createMock(FieldableEntityInterface::class);
    $attachment->method('id')->willReturn('77');
    $attachment->method('hasField')
      ->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $attachment->method('get')
      ->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);

    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('getQuery')->willReturn($this->queryResult([77]));
    $attachment_storage->method('loadMultiple')->with([77])->willReturn([77 => $attachment]);
    $attachment_storage->expects($this->exactly(2))->method('resetCache')->with([77]);
    $attachment_storage->method('load')->with(77)->willReturn($attachment);
    $attachment_storage->expects($this->once())->method('delete')->with([$attachment]);

    $file = $this->createMock(EntityInterface::class);
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('loadMultiple')->with([301])->willReturn([301 => $file]);
    $file_storage->expects($this->once())->method('delete')->with([301 => $file]);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $type): bool => in_array($type, ['application_attachment', 'file'], TRUE));
    $manager->method('getStorage')
      ->willReturnCallback(static fn(string $type): EntityStorageInterface => match ($type) {
        'application_attachment' => $attachment_storage,
        'file' => $file_storage,
        default => throw new \InvalidArgumentException($type),
      });

    $acquired = [];
    $released = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')
      ->willReturnCallback(static function (string $name) use (&$acquired): bool {
        $acquired[] = $name;
        return TRUE;
      });
    $lock->method('release')
      ->willReturnCallback(static function (string $name) use (&$released): void {
        $released[] = $name;
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('beantragt_application.settings')
      ->willReturn($this->config(['data_retention_days' => 0]));

    $service = $this->service(
      entityTypeManager: $manager,
      configFactory: $config_factory,
      lock: $lock,
    );
    $report = $service->run(FALSE);

    $this->assertSame([
      'beantragt_application.retention_cleanup',
      'beantragt_application.attachment_claim.77',
      'beantragt_comment.attachment_claim.77',
      'beantragt_application.delete.44',
    ], $acquired);
    $this->assertSame([
      'beantragt_application.delete.44',
      'beantragt_comment.attachment_claim.77',
      'beantragt_application.attachment_claim.77',
      'beantragt_application.retention_cleanup',
    ], $released);
    $this->assertSame(['matched' => 1, 'deleted' => 1], $report['unclaimedAttachmentUploads']['unclaimedAttachmentUploads']);
    $this->assertSame(['matched' => 1, 'deleted' => 1], $report['unclaimedAttachmentUploads']['unclaimedAttachmentFiles']);
  }

  /**
   * Unconfirmed applications get a dedicated dry-run row with attachments.
   */
  public function testUnconfirmedApplicationsPurgeHasDedicatedReportRow(): void {
    $upload_query = $this->queryResult([]);
    $unconfirmed_conditions = [];
    $unconfirmed_query = $this->queryResult([101], $unconfirmed_conditions);
    $attachment_query = $this->queryResult([10]);

    $application = $this->createMock(EntityInterface::class);
    $attachment = $this->fieldableEntity('field_file', [301, 302]);

    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')->willReturn($unconfirmed_query);
    $application_storage->method('loadMultiple')->with([101])->willReturn([101 => $application]);
    $application_storage->expects($this->never())->method('delete');

    $attachment_storage = $this->createMock(EntityStorageInterface::class);
    $attachment_storage->method('getQuery')
      ->willReturnOnConsecutiveCalls($upload_query, $attachment_query);
    $attachment_storage->method('loadMultiple')->with([10])->willReturn([10 => $attachment]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $entity_type): bool => in_array($entity_type, [
        'application',
        'application_attachment',
      ], TRUE));
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(static fn(string $entity_type): EntityStorageInterface => match ($entity_type) {
        'application' => $application_storage,
        'application_attachment' => $attachment_storage,
        default => throw new \InvalidArgumentException($entity_type),
      });

    $config = $this->config([
      'data_retention_days' => 0,
      'confirmation_purge_hours' => 168,
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('beantragt_application.settings')->willReturn($config);

    $service = $this->service(
      entityTypeManager: $entity_type_manager,
      configFactory: $config_factory,
    );
    $report = $service->run(TRUE, 10);

    $this->assertSame(['matched' => 1, 'deleted' => 0], $report['counts']['unconfirmedApplications']);
    $this->assertSame(['matched' => 1, 'deleted' => 0], $report['unconfirmedApplications']['unconfirmedApplications']);
    $this->assertSame(['matched' => 1, 'deleted' => 0], $report['unconfirmedApplications']['applicationAttachments']);
    $this->assertSame(['matched' => 2, 'deleted' => 0], $report['unconfirmedApplications']['applicationAttachmentFiles']);
    $this->assertContains(['field_email_confirmed', FALSE, NULL], $unconfirmed_conditions);
    $this->assertContains(['created', 1781678735 - (168 * 3600), '<'], $unconfirmed_conditions);
  }

  /**
   * Jurisdiction retention config overrides the global default.
   */
  public function testJurisdictionRetentionPolicyOverridesGlobalDefault(): void {
    $policy = RetentionCleanupService::retentionDaysFromConfig([
      'retention' => ['dataRetentionDays' => '365'],
    ], 730);

    $this->assertSame(['days' => 365, 'source' => 'jurisdiction'], $policy);
  }

  /**
   * Missing retention config uses the global default.
   */
  public function testMissingJurisdictionRetentionPolicyUsesGlobalDefault(): void {
    $policy = RetentionCleanupService::retentionDaysFromConfig([
      'general' => ['platformName' => 'Musterstadt'],
    ], 730);

    $this->assertSame(['days' => 730, 'source' => 'global'], $policy);
  }

  /**
   * Invalid tenant config fails closed to the global default.
   */
  public function testInvalidJurisdictionRetentionPolicyUsesGlobalDefault(): void {
    $policy = RetentionCleanupService::retentionDaysFromConfig([
      'retention' => ['dataRetentionDays' => '-7'],
    ], 730);

    $this->assertSame(['days' => 730, 'source' => 'global_invalid'], $policy);
  }

  /**
   * Zero disables automatic application retention for a jurisdiction.
   */
  public function testZeroRetentionDaysIsAcceptedAsDisabledPolicy(): void {
    $policy = RetentionCleanupService::retentionDaysFromConfig([
      'retention' => ['dataRetentionDays' => 0],
    ], 730);

    $this->assertSame(['days' => 0, 'source' => 'jurisdiction'], $policy);
  }

  /**
   * Schema retention config overrides the inherited policy.
   */
  public function testSchemaRetentionPolicyOverridesInheritedPolicy(): void {
    $policy = RetentionCleanupService::retentionDaysFromSchema([
      'retention' => ['dataRetentionDays' => '30'],
    ], 365, 'jurisdiction');

    $this->assertSame(['days' => 30, 'source' => 'schema'], $policy);
  }

  /**
   * Missing schema retention config keeps the inherited policy.
   */
  public function testMissingSchemaRetentionPolicyUsesInheritedPolicy(): void {
    $policy = RetentionCleanupService::retentionDaysFromSchema([
      'title' => 'Meldebescheinigung',
      'sections' => [],
    ], 365, 'jurisdiction');

    $this->assertSame(['days' => 365, 'source' => 'jurisdiction'], $policy);
  }

  /**
   * Zero disables automatic application retention for a schema.
   */
  public function testZeroSchemaRetentionDaysIsAcceptedAsDisabledPolicy(): void {
    $policy = RetentionCleanupService::retentionDaysFromSchema([
      'retention' => ['dataRetentionDays' => 0],
    ], 365, 'jurisdiction');

    $this->assertSame(['days' => 0, 'source' => 'schema'], $policy);
  }

  /**
   * Schema overrides use their own cutoff and stay out of inherited buckets.
   */
  public function testSchemaOverridePolicyUsesSchemaCutoffAndExcludesSiblingFromInheritedBucket(): void {
    $schema_policy_conditions = [];
    $base_policy_conditions = [];
    $unscoped_schema_conditions = [];
    $unscoped_base_conditions = [];
    $unscoped_schema_not_exists = [];
    $unscoped_base_not_exists = [];

    $jurisdiction = $this->jurisdiction(3, 'Musterstadt', 'musterstadt', [
      'retention' => ['dataRetentionDays' => 365],
    ]);
    $schema_short = $this->schemaEntity(10, '11111111-1111-4111-8111-111111111111', 'Meldebescheinigung', [
      'title' => 'Meldebescheinigung',
      'retention' => ['dataRetentionDays' => 30],
      'sections' => [],
    ]);
    $schema_inherited = $this->schemaEntity(11, '22222222-2222-4222-8222-222222222222', 'Gewerbeanmeldung', [
      'title' => 'Gewerbeanmeldung',
      'sections' => [],
    ]);
    $schema_disabled = $this->schemaEntity(12, '33333333-3333-4333-8333-333333333333', 'Archivierter Antrag', [
      'title' => 'Archivierter Antrag',
      'retention' => ['dataRetentionDays' => 0],
      'sections' => [],
    ]);

    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('getQuery')
      ->willReturn($this->queryResult([3]));
    $group_storage->method('loadMultiple')
      ->with([3])
      ->willReturn([3 => $jurisdiction]);

    $schema_storage = $this->createMock(EntityStorageInterface::class);
    $schema_storage->method('getQuery')
      ->willReturn($this->queryResult([10, 11, 12]));
    $schema_storage->method('loadMultiple')
      ->with([10, 11, 12])
      ->willReturn([
        10 => $schema_short,
        11 => $schema_inherited,
        12 => $schema_disabled,
      ]);

    $applications = [
      101 => $this->createMock(EntityInterface::class),
      102 => $this->createMock(EntityInterface::class),
    ];
    $application_queries = [
      $this->queryResult([]),
      $this->queryResult([101], $schema_policy_conditions),
      $this->queryResult([102], $base_policy_conditions),
      $this->queryResult([], $unscoped_schema_conditions, $unscoped_schema_not_exists),
      $this->queryResult([], $unscoped_base_conditions, $unscoped_base_not_exists),
    ];
    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('getQuery')
      ->willReturnCallback(static function () use (&$application_queries): QueryInterface {
        return array_shift($application_queries);
      });
    $application_storage->method('loadMultiple')
      ->willReturnCallback(static function (array $ids) use ($applications): array {
        return array_intersect_key($applications, array_flip($ids));
      });
    $application_storage->expects($this->never())->method('delete');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->willReturnCallback(static fn(string $entity_type): bool => in_array($entity_type, [
        'application',
        'form_schema',
        'group',
      ], TRUE));
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(static fn(string $entity_type): EntityStorageInterface => match ($entity_type) {
        'application' => $application_storage,
        'form_schema' => $schema_storage,
        'group' => $group_storage,
        default => throw new \InvalidArgumentException($entity_type),
      });

    $application_config = $this->config(['data_retention_days' => 730]);
    $group_config = $this->config(['jurisdiction_group_type' => 'jur']);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(static fn(string $name): ImmutableConfig => match ($name) {
        'beantragt_application.settings' => $application_config,
        'beantragt_group.settings' => $group_config,
        default => throw new \InvalidArgumentException($name),
      });

    $service = $this->service(
      entityTypeManager: $entity_type_manager,
      configFactory: $config_factory,
    );
    $report = $service->run(TRUE, 10);

    $this->assertSame(2, $report['counts']['applications']['matched']);
    $this->assertSame('schema', $report['policies'][0]['source']);
    $this->assertSame(10, $report['policies'][0]['schemaId']);
    $this->assertSame(30, $report['policies'][0]['retentionDays']);
    $this->assertSame(1781678735 - (30 * 86400), $report['policies'][0]['cutoff']);
    $this->assertSame(['matched' => 1, 'deleted' => 0], $report['policies'][0]['applications']);

    $this->assertSame('schema', $report['policies'][1]['source']);
    $this->assertSame(12, $report['policies'][1]['schemaId']);
    $this->assertTrue($report['policies'][1]['disabled']);
    $this->assertNull($report['policies'][1]['cutoff']);

    $this->assertSame('jurisdiction', $report['policies'][2]['source']);
    $this->assertNull($report['policies'][2]['schemaId']);
    $this->assertSame(365, $report['policies'][2]['retentionDays']);
    $this->assertSame(['matched' => 1, 'deleted' => 0], $report['policies'][2]['applications']);

    $this->assertContains(['field_jurisdiction', 3, NULL], $schema_policy_conditions);
    $this->assertContains(['field_schema', 10, NULL], $schema_policy_conditions);
    $this->assertContains(['field_jurisdiction', 3, NULL], $base_policy_conditions);
    $this->assertContains(['field_schema', [10, 12], 'NOT IN'], $base_policy_conditions);
    $this->assertSame(['field_jurisdiction'], $unscoped_schema_not_exists);
    $this->assertContains(['field_schema', 10, NULL], $unscoped_schema_conditions);
    $this->assertSame(['field_jurisdiction'], $unscoped_base_not_exists);
    $this->assertContains(['field_schema', [10, 12], 'NOT IN'], $unscoped_base_conditions);
  }

  /**
   * Retention day values are bounded and integer-only.
   *
   * @dataProvider invalidRetentionDaysProvider
   */
  public function testInvalidRetentionDaysAreRejected(mixed $value): void {
    $this->assertNull(RetentionCleanupService::normalizeRetentionDays($value));
  }

  /**
   * Provides invalid retention day values.
   *
   * @return array<string, array{mixed}>
   *   Invalid value examples.
   */
  public static function invalidRetentionDaysProvider(): array {
    return [
      'negative' => [-1],
      'fraction' => [12.5],
      'string fraction' => ['12.5'],
      'too large' => [36501],
      'empty string' => [''],
      'text' => ['soon'],
      'bool true' => [TRUE],
      'bool false' => [FALSE],
      'null' => [NULL],
      'float' => [12.0],
      'array' => [['days' => 30]],
      'object' => [new \stdClass()],
    ];
  }

  /**
   * Builds a retention service with overridable dependencies.
   */
  private function service(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?TimeInterface $time = NULL,
    ?LoggerInterface $logger = NULL,
    ?LockBackendInterface $lock = NULL,
  ): RetentionCleanupService {
    $time ??= $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1781678735);

    $configFactory ??= $this->createMock(ConfigFactoryInterface::class);
    $entityTypeManager ??= $this->createMock(EntityTypeManagerInterface::class);

    return new RetentionCleanupService(
      $entityTypeManager,
      $configFactory,
      $time,
      $logger ?? $this->createMock(LoggerInterface::class),
      $lock ?? $this->createMock(LockBackendInterface::class),
    );
  }

  /**
   * Builds a config mock with simple scalar values.
   *
   * @param array<string, mixed> $values
   *   Config values keyed by config key.
   */
  private function config(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $values[$key] ?? NULL);
    return $config;
  }

  /**
   * Builds a storage mock with one entity query result.
   *
   * @param int[] $ids
   *   Entity IDs returned by the query.
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $entities
   *   Entities returned by loadMultiple().
   */
  private function storage(array $ids, array $entities): EntityStorageInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('notExists')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->with($ids)->willReturn($entities);
    return $storage;
  }

  /**
   * Builds an entity query mock with an execute result.
   *
   * @param int[] $ids
   *   Entity IDs returned by the query.
   * @param array<int, array{string, mixed, string|null}>|null $conditions
   *   Captures condition calls by reference.
   * @param string[]|null $notExists
   *   Captures notExists calls by reference.
   */
  private function queryResult(array $ids, ?array &$conditions = NULL, ?array &$notExists = NULL): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')
      ->willReturnCallback(static function (string $field, mixed $value = NULL, ?string $operator = NULL) use (&$conditions, $query): QueryInterface {
        if ($conditions !== NULL) {
          $conditions[] = [$field, $value, $operator];
        }
        return $query;
      });
    $query->method('notExists')
      ->willReturnCallback(static function (string $field) use (&$notExists, $query): QueryInterface {
        if ($notExists !== NULL) {
          $notExists[] = $field;
        }
        return $query;
      });
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

  /**
   * Builds a jurisdiction group exposing retention config and slug fields.
   *
   * @param int $id
   *   Group entity ID.
   * @param string $label
   *   Group label.
   * @param string $slug
   *   Public group slug.
   * @param array<string, mixed> $config
   *   Decoded field_jur_config payload.
   */
  private function jurisdiction(int $id, string $label, string $slug, array $config): GroupInterface {
    $fields = [
      'field_jur_config' => $this->fieldList([
        'value' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      ]),
      'field_slug' => $this->fieldList(['value' => $slug]),
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('label')->willReturn($label);
    $group->method('getUntranslated')->willReturn($group);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => isset($fields[$field_name]));
    $group->method('get')
      ->willReturnCallback(static fn(string $field_name): FieldItemListInterface => $fields[$field_name]);
    return $group;
  }

  /**
   * Builds a form schema entity exposing field_schema JSON.
   *
   * @param int $id
   *   Form schema entity ID.
   * @param string $uuid
   *   Form schema UUID.
   * @param string $label
   *   Form schema label.
   * @param array<string, mixed> $schema
   *   Decoded field_schema payload.
   */
  private function schemaEntity(int $id, string $uuid, string $label, array $schema): FieldableEntityInterface {
    $field = $this->fieldList([
      'value' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('id')->willReturn((string) $id);
    $entity->method('uuid')->willReturn($uuid);
    $entity->method('label')->willReturn($label);
    $entity->method('hasField')
      ->willReturnCallback(static fn(string $field_name): bool => $field_name === 'field_schema');
    $entity->method('get')
      ->with('field_schema')
      ->willReturn($field);
    return $entity;
  }

  /**
   * Creates a field item list mock exposing magic field properties.
   *
   * @param array<string, mixed> $properties
   *   Magic field properties.
   * @param bool $empty
   *   Whether the field list should report empty.
   */
  private function fieldList(array $properties, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('getValue')->willReturn($empty ? [] : [$properties]);
    $field->method('__isset')->willReturnCallback(
      static fn(string $name): bool => array_key_exists($name, $properties),
    );
    $field->method('__get')->willReturnCallback(
      static fn(string $name): mixed => $properties[$name] ?? NULL,
    );
    return $field;
  }

  /**
   * Builds a fieldable entity with a file-reference list.
   *
   * @param string $fieldName
   *   Field name containing file references.
   * @param int[] $target_ids
   *   Referenced file entity IDs.
   * @param array<string, int[]> $additional_fields
   *   Additional file-reference field targets.
   */
  private function fieldableEntity(string $fieldName, array $target_ids, array $additional_fields = []): FieldableEntityInterface {
    $field_targets = [$fieldName => $target_ids, ...$additional_fields];
    $lists = [];
    foreach ($field_targets as $name => $ids) {
      $values = array_map(static fn(int $target_id): array => ['target_id' => $target_id], $ids);
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturn($values === []);
      $list->method('getValue')->willReturn($values);
      $lists[$name] = $list;
    }

    $entity = $this->createMock(FieldableEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn(
      $fieldName === 'field_file' ? 'application_attachment' : 'application_comment',
    );
    $entity->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => isset($lists[$name]));
    $entity->method('get')
      ->willReturnCallback(static fn(string $name): FieldItemListInterface => $lists[$name]);
    return $entity;
  }

}
