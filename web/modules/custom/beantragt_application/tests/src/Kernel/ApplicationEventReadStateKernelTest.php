<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Service\ApplicationProofAssignments;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\beantragt_application\Access\ApplicationCommentStaffScope;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationEventReadState;
use Drupal\beantragt_application\Service\ApplicationMessageReadState;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_application\Service\ScanEventIntakeSourceInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\RenderDataBuilder;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\beantragt_nachreichung\Repository\EingangRepository;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\file\FileRepositoryInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real SQL proof of all event kinds, exact acknowledgements and batching.
 */
#[Group('beantragt_application')]
final class ApplicationEventReadStateKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization',
    'language', 'beantragt_schema', 'beantragt_workflow', 'beantragt_workflow_status',
    'beantragt_application', 'beantragt_group', 'beantragt_roles_api',
    'beantragt_comment', 'beantragt_notification',
  ];

  /**
   * Event service under test.
   */
  private ApplicationEventReadState $events;

  /**
   * Real release rules, without rendering or provider calls.
   */
  private DocumentService $documents;

  /**
   * Optional extension blockers used by the real document service.
   *
   * @var string[]
   */
  private array $extensionBlockers = [];
  /**
   * Current staff reader.
   */
  private AccountInterface $reader;
  /**
   * Stable test clock.
   */
  private int $now = 1000;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('application_comment');
    $this->installEntitySchema('application_attachment');
    $this->installSchema('beantragt_application', [
      ApplicationMessageReadState::TABLE, ApplicationEventReadState::RECEIPTS_TABLE,
    ]);
    $db = $this->container->get('database');
    $schema = $db->schema();
    $schema->createTable(EingangRepository::TABLE, EingangRepository::schema());
    foreach (ExternalResponseRepository::schema() + DocumentRepository::schema() as $table => $definition) {
      $schema->createTable($table, $definition);
    }
    $schema->createTable('beantragt_delivery_log', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'uuid' => ['type' => 'varchar', 'length' => 36],
        'application_id' => ['type' => 'int'],
        'status' => ['type' => 'varchar', 'length' => 30],
        'replaces_uuid' => ['type' => 'varchar', 'length' => 36],
      ],
      'primary key' => ['id'],
    ]);
    $schema->createTable('application_revision', [
      'fields' => [
        'revision_id' => ['type' => 'serial', 'not null' => TRUE],
        'id' => ['type' => 'int'],
        'revision_user' => ['type' => 'int'],
        'revision_created' => ['type' => 'int'],
        'revision_log_message' => ['type' => 'text'],
      ],
      'primary key' => ['revision_id'],
    ]);
    $this->container->get('state')->set(ApplicationMessageReadState::BASELINE_STATE, 800);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $time->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $messages = new class($db, $time, $this->container->get('module_handler'),
      new ApplicationCommentStaffScope($this->container->get('entity_type.manager')), $this->container->get('state')) extends ApplicationMessageReadState {

      /**
       * Counting fixtures isolate source SQL; owner batching has its own test.
       */
      public function withAccessBatch(array $applications, AccountInterface $account, callable $read): mixed {
        return $read();
      }

    };
    $extension = $this->createMock(DocgenCaseExtensionInterface::class);
    $extension->method('releaseBlockers')->willReturnCallback(fn(array $row): array =>
      (int) ($row['created_by'] ?? 0) === 22 ? $this->extensionBlockers : []);
    $this->documents = new DocumentService(
      new DocumentRepository($db), $this->createMock(TemplateCatalog::class),
      $this->createMock(DocgenSettingsRepository::class), $this->createMock(RenderDataBuilder::class),
      $this->createMock(DocgenClient::class), $this->createMock(DocgenScopeAccess::class),
      $this->container->get('entity_type.manager'), $this->createMock(FileSystemInterface::class),
      $this->createMock(FileRepositoryInterface::class), $this->createMock(UuidInterface::class),
      $time, $this->createMock(LockBackendInterface::class), new NullLogger(), NULL, NULL, $extension,
    );
    $this->events = new class($db, $time, $messages, $this->container->get('state'), $this->createMock(ClassResolverInterface::class),
      $this->createMock(ProtectedDataGate::class), NULL, NULL, $this->documents, $this->batchIntakeSource()) extends ApplicationEventReadState {

      /**
       * Whether the fixture reader has delivery management scope.
       */
      public bool $pendingReplies = TRUE;

      /**
       * Field access checks performed in this snapshot.
       *
       * @var array<string, int>
       */
      public array $fieldChecks = [];

      /**
       * {@inheritdoc}
       */
      protected function allowed(string $kind, ApplicationInterface $application, AccountInterface $account): bool {
        return $account->hasPermission('event:' . $kind);
      }

      /**
       * {@inheritdoc}
       */
      protected function canReadPendingReply(ApplicationInterface $application, AccountInterface $account): bool {
        return $this->pendingReplies;
      }

      /**
       * {@inheritdoc}
       */
      protected function scanFieldVisible(ApplicationInterface $application, string $field_id, AccountInterface $account): bool {
        $key = $application->id() . ':' . $field_id;
        $this->fieldChecks[$key] = ($this->fieldChecks[$key] ?? 0) + 1;
        return $field_id !== 'protected' || $account->hasPermission('view protected application data');
      }

    };
    $this->reader = $this->account(21, self::KINDS);
  }

  private const KINDS = ['message', 'documents', 'reply', 'transfer', 'assigned', 'release', 'scan'];

  /**
   * Enumerates each event source.
   */
  public static function kinds(): array {
    return array_combine(self::KINDS, array_map(static fn(string $kind): array => [$kind], self::KINDS));
  }

  /**
   * Every event source excludes readers who cannot open its section.
   */
  #[DataProvider('kinds')]
  public function testEachKindCountsAndCannotBeReadWithoutItsPermission(string $kind): void {
    $this->seed($kind, 42);
    $application = $this->application(42);
    $this->assertSame(1, $this->counts($application)[$kind] ?? 0);
    $snapshot = $this->events->detail($application, $this->reader);
    $this->assertCount(1, (array) ($snapshot['newEventIds']->$kind ?? []));
    $denied = $this->account(22, array_values(array_diff(self::KINDS, [$kind])));
    $this->assertArrayNotHasKey($kind, (array) $this->events->counts([$application], $denied)[$application->uuid()]);
    $this->assertArrayNotHasKey($kind, (array) $this->events->detail($application, $denied)['newEventIds']);
    if (in_array($kind, ApplicationEventReadState::SINCE_SEEN, TRUE)) {
      $this->assertNull($this->events->markSeen($application, $denied, $kind, []));
    }
  }

  /**
   * Acknowledgement is exact and per section, person and source version.
   */
  public function testOpeningDoesNotClearAndOnlyRenderedSectionVersionsClear(): void {
    $application = $this->application(42);
    foreach (self::KINDS as $kind) {
      $this->seed($kind, 42);
    }
    $snapshot = $this->events->detail($application, $this->reader);
    $this->assertCount(7, (array) $snapshot['counts']);
    $this->assertSame(1, $this->counts($application)['message']);
    // A new message after GET is not consumed by the old rendered snapshot.
    $this->seed('message', 42);
    $this->events->markSeen($application, $this->reader, 'message', $snapshot['seenTokens']->message);
    $this->assertSame(1, $this->counts($application)['message']);
    $this->assertSame(1, $this->counts($application)['documents']);
    $this->assertSame(1, $this->counts($application)['transfer']);
    $this->assertSame(1, $this->counts($application)['release']);
    $colleague = $this->account(22, self::KINDS);
    $this->assertSame(2, $this->events->counts([$application], $colleague)[$application->uuid()]->message);
    foreach (ApplicationEventReadState::SINCE_SEEN as $kind) {
      $next = $this->events->detail($application, $this->reader);
      $this->events->markSeen($application, $this->reader, $kind, $next['seenTokens']->$kind ?? []);
    }
    $this->assertSame(['transfer' => 1, 'release' => 1], $this->counts($application));
  }

  /**
   * Resolved replacement transfers remove the superseded failure marker.
   */
  public function testAcceptedReplacementResolvesOldTransferFailure(): void {
    $this->seed('transfer', 42);
    $db = $this->container->get('database');
    $old = $db->select('beantragt_delivery_log', 'l')->fields('l', ['uuid'])->execute()->fetchField();
    $db->insert('beantragt_delivery_log')->fields([
      'uuid' => $this->container->get('uuid')->generate(),
      'application_id' => 42,
      'status' => 'accepted',
      'replaces_uuid' => $old,
    ])->execute();
    $this->assertArrayNotHasKey('transfer', $this->counts($this->application(42)));
  }

  /**
   * Every time-based source respects rollout and missing-schema windows.
   */
  public function testBaselineEverySinceSeenKindAndMissingReadTable(): void {
    $application = $this->application(42);
    foreach (ApplicationEventReadState::SINCE_SEEN as $kind) {
      $this->seed($kind, 42, 799);
      $this->seed($kind, 42, 800);
      $this->seed($kind, 42, 801);
      $this->assertSame(1, $this->counts($application)[$kind]);
    }
    $this->seed('transfer', 42);
    $this->container->get('database')->schema()->dropTable(ApplicationMessageReadState::TABLE);
    $this->assertSame(['transfer' => 1], $this->counts($application));
  }

  /**
   * Protected replies, authors and changed scans retain correct visibility.
   */
  public function testReplyProtectionReleaseFourEyesAndScanFindingVersions(): void {
    $application = $this->application(42);
    $this->seed('reply', 42);
    $db = $this->container->get('database');
    $db->update('beantragt_external_response')->fields(['contains_protected' => 1])->execute();
    $this->assertArrayNotHasKey('reply', $this->counts($application));
    $protected_reader = $this->account(21, self::KINDS, TRUE);
    $this->assertSame(1, $this->events->counts([$application], $protected_reader)[$application->uuid()]->reply);
    $this->seed('release', 42);
    $db->update('beantragt_docgen_document')->fields(['created_by' => 21])->execute();
    $this->assertArrayNotHasKey('release', $this->counts($application));
    $this->seed('scan', 42);
    $before = $this->events->detail($application, $this->reader);
    $this->events->markSeen($application, $this->reader, 'scan', $before['seenTokens']->scan);
    $this->assertArrayNotHasKey('scan', $this->counts($application));
    // Same attachment, same second, different finished finding stays new.
    $db->update('application_attachment')->fields(['field_scan_status' => 'failed'])->execute();
    $this->assertSame(1, $this->counts($application)['scan']);
    $db->update('application_attachment')->fields(['field_field_id' => 'protected'])->execute();
    $this->assertArrayNotHasKey('scan', $this->counts($application));
  }

  /**
   * A staff result cannot reopen a citizen finding for a restricted reader.
   */
  public function testHiddenStaffAnalysisDoesNotChangeScanReceipt(): void {
    $application = $this->application(42);
    $this->seed('scan', 42);
    $database = $this->container->get('database');
    $metadata = [
      'version' => 2,
      'status' => 'complete',
      'stories' => ['citizen' => ['status' => 'complete', 'result' => ['outcome' => 'needs_review']]],
    ];
    $database->update('application_attachment')->fields([
      'field_scan_status' => 'clean',
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ])->execute();
    $detail = $this->events->detail($application, $this->reader);
    $this->events->markSeen($application, $this->reader, 'scan', $detail['seenTokens']->scan);
    $this->assertArrayNotHasKey('scan', $this->counts($application));

    $metadata['status'] = 'failed';
    $metadata['profile'] = ['modelClass' => 'openai_compatible'];
    $metadata['stories']['staff'] = ['status' => 'failed'];
    $database->update('application_attachment')->fields([
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ])->execute();
    $this->assertArrayNotHasKey('scan', $this->counts($application));
    $this->assertArrayNotHasKey('scan', (array) $this->events->detail($application, $this->reader)['seenTokens']);

    $metadata['stories']['citizen']['result']['outcome'] = 'pass';
    $database->update('application_attachment')->fields([
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ])->execute();
    $this->assertArrayNotHasKey('scan', $this->counts($application));
    $protected_reader = $this->account(21, self::KINDS, TRUE);
    $this->assertSame(1, $this->events->counts([$application], $protected_reader)[$application->uuid()]->scan);
  }

  /**
   * Staff writes cannot move visible findings past a time-based read cutoff.
   */
  public function testHiddenStaffAnalysisDoesNotAdvanceScanCutoff(): void {
    $this->now = $this->container->get('datetime.time')->getRequestTime();
    $baseline = $this->now - 100;
    $this->container->get('state')->set(ApplicationMessageReadState::BASELINE_STATE, $baseline);
    $this->seed('scan', 42, $baseline - 100);
    $application = $this->application(42);
    $database = $this->container->get('database');
    $database->update('application_attachment')->fields([
      'field_application' => NULL,
      'field_scan_status' => 'clean',
      'field_analysis_metadata' => json_encode([
        'version' => 2,
        'status' => 'complete',
        'stories' => ['citizen' => ['status' => 'complete', 'result' => ['outcome' => 'needs_review']]],
      ], JSON_THROW_ON_ERROR),
    ])->execute();
    $attachment_id = $database->select('application_attachment', 'a')->fields('a', ['id'])->execute()->fetchField();
    $database->insert(ApplicationMessageReadState::TABLE)->fields([
      'uid' => 22,
      'application_id' => 42,
      'kind' => 'scan',
      'seen_at' => $baseline - 50,
      'last_comment_id' => $attachment_id,
    ])->execute();
    $legacy_reader = $this->account(22, self::KINDS);
    $protected_reader = $this->account(23, self::KINDS, TRUE);
    $storage = $this->entityTypeManager()->getStorage('application_attachment');
    $attachment = $storage->load($attachment_id);
    $writer = $this->container->get('beantragt_application.attachment_analysis_metadata_writer');
    $writer->mergeStory($attachment, 'staff', ['status' => 'failed']);
    $writer->mergeStory($attachment, 'staff', ['status' => 'complete', 'result' => ['outcome' => 'unknown']]);
    $this->assertSame($this->now, $storage->loadUnchanged($attachment_id)->getChangedTime());
    // The read fixture uses a mock application without an entity row.
    $database->update('application_attachment')->fields(['field_application' => 42])->execute();
    $this->assertArrayNotHasKey('scan', $this->counts($application));
    $this->assertArrayNotHasKey('scan', (array) $this->events->detail($application, $legacy_reader)['counts']);
    $this->assertSame(1, $this->events->detail($application, $protected_reader)['counts']->scan);

    // An independent citizen result is still new in the same second.
    $database->update('application_attachment')->fields(['field_application' => NULL])->execute();
    $writer->mergeStory($attachment, 'citizen', ['status' => 'failed']);
    $database->update('application_attachment')->fields(['field_application' => 42])->execute();
    $this->assertSame(1, $this->counts($application)['scan']);
    $this->assertSame(1, $this->events->detail($application, $legacy_reader)['counts']->scan);
  }

  /**
   * The number of source queries is independent of the page row count.
   */
  public function testOneSourceQueryPerKindAcrossPage(): void {
    $applications = [$this->application(42), $this->application(43), $this->application(44)];
    foreach ($applications as $application) {
      foreach (self::KINDS as $kind) {
        $this->seed($kind, (int) $application->id());
      }
    }
    Database::startLog('events');
    $counts = $this->events->counts($applications, $this->reader);
    $log = Database::getLog('events');
    Database::getConnection()->getLogger()->end('events');
    $this->assertCount(3, $counts);
    foreach ([
      'application_comment', 'beantragt_nachreichung_eingang', 'beantragt_external_response',
      'beantragt_delivery_log', 'beantragt_docgen_document', 'application_revision', 'application_attachment',
    ] as $table) {
      $queries = array_filter($log, static fn(array $entry): bool =>
        preg_match('/FROM\\s+[\"{]?[^\"}\\s]*' . preg_quote($table, '/') . '[\"}\\s]/', $entry['query']) === 1);
      $this->assertCount(1, $queries, $table);
    }
  }

  /**
   * Release events announce unpreviewed work; actual release stays blocked.
   */
  public function testReleasePillIgnoresOnlyPreviewNotViewedWithRealDocumentService(): void {
    $this->seed('release', 42);
    $db = $this->container->get('database');
    $db->delete('beantragt_docgen_preview')->execute();
    $application = $this->application(42);
    $this->assertSame(1, $this->counts($application)['release']);
    $document = $db->select('beantragt_docgen_document', 'd')->fields('d')->execute()->fetchAssoc();
    $this->assertSame(['docgen.preview_not_viewed'], $this->documents->releaseBlockers($document, $this->reader));
    $this->extensionBlockers = ['docgen.agents_stopped'];
    $this->assertArrayNotHasKey('release', $this->counts($application));
    $this->extensionBlockers = [];
    $db->update('beantragt_docgen_document')->fields(['pdf_docx_sha256' => 'stale'])->execute();
    $this->assertArrayNotHasKey('release', $this->counts($application));
    $db->update('beantragt_docgen_document')->fields([
      'pdf_docx_sha256' => 'docx',
      'validation' => '{"compliant":false}',
    ])->execute();
    $this->assertArrayNotHasKey('release', $this->counts($application));
    $db->update('beantragt_docgen_document')->fields(['validation' => '{"compliant":true}', 'pdf_file_id' => NULL])->execute();
    $this->assertArrayNotHasKey('release', $this->counts($application));
  }

  /**
   * The counter projection keeps metadata consumed by release extensions.
   */
  public function testReleaseProjectionKeepsExtensionMetadataWithoutDocumentPayload(): void {
    $this->seed('release', 42);
    $rows = new \ReflectionMethod(ApplicationEventReadState::class, 'rows');
    $row = $rows->invoke($this->events, 'release', [42 => $this->application(42)], $this->reader, 800)[0];
    $this->assertSame(22, (int) $row['created_by']);
    $this->assertSame(42, (int) $row['application_id']);
    $this->assertArrayNotHasKey('values', $row);
    $this->assertArrayNotHasKey('docx_file_id', $row);
    $this->assertArrayNotHasKey('preview_viewed', $row);
  }

  /**
   * Own incoming decisions do not announce work authored by the reader.
   */
  public function testDocumentsExcludeOwnDecisions(): void {
    $this->seed('documents', 42);
    $this->container->get('database')->update(EingangRepository::TABLE)->fields(['decided_by' => 21])->execute();
    $application = $this->application(42);
    $this->assertArrayNotHasKey('documents', $this->counts($application));
    $this->container->get('database')->update(EingangRepository::TABLE)->fields(['decided_by' => 22])->execute();
    $this->assertSame(1, $this->counts($application)['documents']);
  }

  /**
   * SQL drops historical rows before their JSON is decoded or inspected.
   */
  public function testSourceQueriesAreBoundedAndAssignmentRequiresPersonChange(): void {
    $applications = [42 => $this->application(42)];
    $rows = new \ReflectionMethod(ApplicationEventReadState::class, 'rows');
    foreach (ApplicationEventReadState::SINCE_SEEN as $kind) {
      $this->seed($kind, 42, 799);
      $this->seed($kind, 42, 801);
      $this->assertCount(1, $rows->invoke($this->events, $kind, $applications, $this->reader, 800), $kind);
    }
    $db = $this->container->get('database');
    $db->update('application_revision')->fields([
      'revision_log_message' => json_encode([
        'schema' => 'civic.revision.v1',
        'action' => 'updated',
        'changes' => [],
        'message' => 'person assignment only mentioned in a note',
      ]),
    ])->execute();
    $this->assertSame([], $rows->invoke($this->events, 'assigned', $applications, $this->reader, 800));
    $db->update('application_revision')->fields(['revision_log_message' => 'legacy plain text'])->execute();
    $this->assertSame([], $rows->invoke($this->events, 'assigned', $applications, $this->reader, 800));
  }

  /**
   * A legacy cursor below the rollout baseline is part of the SQL bound.
   */
  public function testLegacyCutoffBelowBaselineAndFieldMemoization(): void {
    $this->seed('message', 42, 750);
    $this->seed('message', 42, 700);
    $db = $this->container->get('database');
    $db->insert(ApplicationMessageReadState::TABLE)->fields([
      'uid' => 21,
      'application_id' => 42,
      'kind' => 'message',
      'seen_at' => 900,
      'legacy_seen_at' => 700,
      'last_comment_id' => 0,
    ])->execute();
    $this->seed('scan', 42);
    $this->seed('scan', 42);
    $application = $this->application(42);
    $counts = $this->counts($application);
    $this->assertSame(2, $counts['message']);
    $this->assertSame(2, $counts['scan']);
    $this->assertSame(1, $this->events->fieldChecks['42:file']);
    $this->counts($application);
    $this->assertSame(2, $this->events->fieldChecks['42:file'], 'Field decisions are reset between snapshots.');
  }

  /**
   * The batch intake source, where its module is part of the build.
   *
   * Without it the read state keeps only events changed after the cutoff,
   * as in the open backend.
   */
  private function batchIntakeSource(): ?ScanEventIntakeSourceInterface {
    $source = 'Drupal\\beantragt_bulk\\Service\\BulkScanEventIntakeSource';
    $projection = 'Drupal\\beantragt_bulk\\Service\\BulkFormProjection';
    $conditions = 'Drupal\\beantragt_bulk\\Service\\BulkConditionEvaluator';
    if (!class_exists($source)) {
      return NULL;
    }
    return new $source($this->container->get('database'), new $projection(new $conditions()));
  }

  /**
   * Actual entity SQL columns and binding checks project a bulk finding.
   */
  public function testBulkFindingUsesBoundRevisionAndAttachment(): void {
    if ($this->batchIntakeSource() === NULL) {
      $this->markTestSkipped('Batch intake is not part of this build.');
    }
    $this->installEntitySchema('workflow_status');
    $db = $this->container->get('database');
    $db->schema()->dropTable('application_revision');
    $this->container->get('module_handler')->addModule('beantragt_bulk', 'web/modules/custom/beantragt_bulk');
    $this->container->get('container.namespaces')['Drupal\\beantragt_bulk'] = DRUPAL_ROOT . '/modules/custom/beantragt_bulk/src';
    $this->container->get('module_handler')->load('beantragt_bulk');
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->installEntitySchema('application');
    if (!$db->schema()->fieldExists('application', 'field_bulk_case')) {
      $field = BaseFieldDefinition::create('entity_reference')->setSetting('target_type', 'bulk_case')->setRevisionable(TRUE);
      $this->container->get('entity.definition_update_manager')->installFieldStorageDefinition('field_bulk_case', 'application', 'beantragt_bulk', $field);
    }
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('bulk_case');
    $this->installEntitySchema('bulk_item');
    $this->installEntitySchema('bulk_batch');
    $db = $this->container->get('database');
    $this->seed('scan', 42);
    $attachment = $db->select('application_attachment', 'a')->fields('a', ['id'])->execute()->fetchField();
    $db->update('application_attachment')->fields(['field_scan_status' => 'clean', 'field_file__target_id' => 9])->execute();
    $schema = ['sections' => [['id' => 'proofs', 'fields' => [['id' => 'file', 'type' => 'file', 'label' => 'Proof']]]]];
    $db->insert('form_schema_revision')->fields([
      'id' => 1,
      'revision_id' => 1,
      'uid' => 0,
      'status' => 1,
      'field_schema' => json_encode($schema),
    ])->execute();
    $db->insert('application')->fields([
      'id' => 42,
      'uuid' => $this->application(42)->uuid(),
      'uid' => 0,
      'status' => 1,
      'field_origin' => 'bulk',
      'field_bulk_case' => 2,
      'field_jurisdiction' => 3,
    ])->execute();
    $db->insert('bulk_batch')->fields([
      'id' => 1,
      'uuid' => $this->container->get('uuid')->generate(),
      'field_form_revision' => 1,
      'field_jurisdiction' => 3,
      'field_organisation' => 4,
      'kind' => 'antraege',
      'state' => 'ready',
    ])->execute();
    $item_uuid = $this->container->get('uuid')->generate();
    $db->insert('bulk_case')->fields([
      'id' => 2,
      'uuid' => $this->container->get('uuid')->generate(),
      'field_batch' => 1,
      'field_application' => 42,
      'field_jurisdiction' => 3,
      'field_organisation' => 4,
      'kind' => 'antraege',
      'state' => 'accepted',
      'changed' => 900,
      'field_result' => json_encode(['proofs' => [], 'notices' => []]),
    ])->execute();
    $db->insert('bulk_item')->fields([
      'uuid' => $item_uuid,
      'field_case' => 2,
      'field_batch' => 1,
      'field_file' => 9,
      'field_attachment' => $attachment,
      'field_jurisdiction' => 3,
      'field_organisation' => 4,
      'state' => 'attached',
      'field_classification' => json_encode(['fieldId' => 'file', 'confidence' => 'low', 'facts' => [], 'codes' => []]),
    ])->execute();
    $this->assertSame(1, $this->counts($this->application(42))['scan']);
    $db->update('bulk_item')->fields(['field_jurisdiction' => 99])->execute();
    $this->assertArrayNotHasKey('scan', $this->counts($this->application(42)));
  }

  /**
   * A visible scan becomes protected at its confirmed target.
   */
  public function testScanEventRespectsConfirmedProofField(): void {
    $uuid = $this->seed('scan', 42);
    $this->assertSame(1, $this->counts($this->application(42))['scan']);
    $application = $this->application(42);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('getString')->willReturn(json_encode([$uuid => ['fieldId' => 'protected', 'personBlock' => NULL]]));
    $application->method('hasField')->with(ApplicationProofAssignments::FIELD)->willReturn(TRUE);
    $application->method('get')->with(ApplicationProofAssignments::FIELD)->willReturn($field);

    $this->assertArrayNotHasKey('scan', $this->counts($application));
    $counts = $this->events->counts([$application], $this->account(22, ['scan'], TRUE));
    $this->assertSame(1, $counts[$application->uuid()]->scan);
  }

  /**
   * Non-delivery readers receive only released decision replies they can open.
   */
  public function testReleasedReplyAudienceAndProtectedDocuments(): void {
    $this->seed('reply', 42);
    $this->events->pendingReplies = FALSE;
    $application = $this->application(42);
    $this->assertArrayNotHasKey('reply', $this->counts($application));
    $db = $this->container->get('database');
    $db->update('beantragt_external_response')->fields(['released' => 950])->execute();
    $this->assertSame(1, $this->counts($application)['reply']);
    $db->update('beantragt_external_response')->fields(['kind' => 'filing_receipt'])->execute();
    $this->assertArrayNotHasKey('reply', $this->counts($application));
    $db->update('beantragt_external_response')->fields(['kind' => 'decision', 'contains_protected' => 1])->execute();
    $this->assertArrayNotHasKey('reply', $this->counts($application));
    $reader = $this->account(21, self::KINDS, TRUE);
    $this->assertSame(1, $this->events->counts([$application], $reader)[$application->uuid()]->reply);
  }

  /**
   * Migrated message cutoffs survive section visits and later arrivals.
   */
  public function testLegacyMessageCursorKeepsCutoff(): void {
    $this->seed('message', 42, 850);
    $this->seed('message', 42, 950);
    $db = $this->container->get('database');
    $db->insert(ApplicationMessageReadState::TABLE)->fields([
      'uid' => 21,
      'application_id' => 42,
      'kind' => 'message',
      'seen_at' => 900,
      'last_comment_id' => 1,
    ])->execute();
    $application = $this->application(42);
    $snapshot = $this->events->detail($application, $this->reader);
    $this->assertSame(1, $snapshot['counts']->message);
    $this->seed('message', 42, 960);
    $this->events->markSeen($application, $this->reader, 'message', $snapshot['seenTokens']->message);
    $this->assertSame(1, $this->counts($application)['message']);
    $next = $this->events->detail($application, $this->reader);
    $this->events->markSeen($application, $this->reader, 'message', $next['seenTokens']->message);
    $this->assertArrayNotHasKey('message', $this->counts($application));
  }

  /**
   * Seeds real source rows without invoking providers or external services.
   */
  private function seed(string $kind, int $application, int $at = 900): string {
    $uuid = $this->container->get('uuid')->generate();
    $db = $this->container->get('database');
    $tables = [
      'message' => 'application_comment',
      'documents' => EingangRepository::TABLE,
      'reply' => 'beantragt_external_response',
      'transfer' => 'beantragt_delivery_log',
      'release' => 'beantragt_docgen_document',
      'assigned' => 'application_revision',
      'scan' => 'application_attachment',
    ];
    $table = $tables[$kind];
    $row = ['uuid' => $uuid, 'application_id' => $application];
    switch ($kind) {
      case 'message':
        $row = [
          'uuid' => $uuid,
          'field_application' => $application,
          'created' => $at,
          'status' => 1,
          'field_author_role' => 'citizen',
          'field_visibility' => 'public',
          'uid' => 0,
        ];
        break;

      case 'documents':
        $row += ['nachforderung_id' => 1, 'decided_at' => $at];
        break;

      case 'reply':
        $row += [
          'source' => 'fit_connect',
          'external_id_hash' => hash('sha256', $uuid),
          'external_reference' => '',
          'title' => '',
          'received' => $at,
        ];
        break;

      case 'transfer':
        $row += ['status' => 'blocked'];
        break;

      case 'release':
        $row += [
          'created_by' => 22,
          'state' => 'draft',
          'pdf_sha256' => 'hash',
          'pdf_file_id' => 1,
          'docx_sha256' => 'docx',
          'pdf_docx_sha256' => 'docx',
          'validation' => json_encode(['compliant' => TRUE]),
        ];
        break;

      case 'assigned':
        $row = [
          'id' => $application,
          'revision_created' => $at,
          'revision_user' => 22,
          'revision_log_message' => json_encode([
            'schema' => 'civic.revision.v1',
            'action' => 'updated',
            'message' => '',
            'changes' => [['field' => 'person', 'before' => [], 'after' => [['id' => '21']]]],
          ]),
        ];
        break;

      case 'scan':
        $row = [
          'uid' => 0,
          'uuid' => $uuid,
          'field_application' => $application,
          'created' => $at,
          'changed' => $at,
          'field_scan_status' => 'quarantined',
          'field_field_id' => 'file',
        ];
        break;
    }
    $db->insert($table)->fields($row)->execute();
    if ($kind === 'release') {
      $db->insert('beantragt_docgen_preview')->fields([
        'document_uuid' => $uuid,
        'pdf_sha256' => 'hash',
        'uid' => 21,
        'viewed' => 900,
      ])->execute();
    }
    return $uuid;
  }

  /**
   * Reads the sparse count map for the test reader.
   */
  private function counts(ApplicationInterface $application): array {
    return (array) $this->events->counts([$application], $this->reader)[$application->uuid()];
  }

  /**
   * Builds an application already authorized by the list controller.
   */
  private function application(int $id): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn($id);
    $application->method('uuid')->willReturn('00000000-0000-4000-8000-' . sprintf('%012d', $id));
    $application->method('access')->willReturn(TRUE);
    return $application;
  }

  /**
   * Builds a staff reader with explicit event-surface permissions.
   */
  private function account(int $id, array $kinds, bool $protected = FALSE): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($id);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['authenticated', 'case_worker']);
    $permissions = array_map(static fn(string $kind): string => 'event:' . $kind, $kinds);
    $account->method('hasPermission')->willReturnCallback(static fn(string $permission): bool =>
      in_array($permission, $permissions, TRUE) || ($protected && $permission === 'view protected application data'));
    return $account;
  }

}
