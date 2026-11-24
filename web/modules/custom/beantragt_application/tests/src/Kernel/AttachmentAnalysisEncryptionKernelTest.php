<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\Core\TypedData\TypedDataInternalPropertiesHelper;
use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\AttachmentAnalysisMetadataWriter;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\Core\Session\AnonymousUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies analysis encryption against real storage, callbacks and audit rows.
 */
#[Group('beantragt_application')]
final class AttachmentAnalysisEncryptionKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'beantragt_schema', 'beantragt_workflow',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * Original environment.
   */
  private array $originalEnv = [];

  /**
   * Sensitive file field schema.
   */
  private FormSchemaInterface $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    foreach ([
      'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' => str_repeat('k', 32),
      'NUXT_DRUPAL_SERVICE_USER' => 'nuxt_service',
      'NUXT_DRUPAL_SERVICE_PASSWORD' => 'test-secret',
    ] as $key => $value) {
      $this->originalEnv[$key] = getenv($key);
      putenv($key . '=' . $value);
    }
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    foreach (['file', 'form_schema', 'workflow_status', 'application', 'application_attachment'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_application', [ProtectedAccessLog::TABLE]);
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Test jurisdiction']);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Test office',
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $this->form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Sensitive file',
      'field_jurisdiction' => $jurisdiction->id(),
      'field_organisation' => $organisation->id(),
      'field_group' => $organisation->id(),
      'field_schema' => json_encode([
        'sections' => [[
          'id' => 'main',
          'title' => 'Main',
          'fields' => [['id' => 'evidence', 'type' => 'file', 'label' => 'Evidence', 'sensitive' => TRUE]],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
    ]);
    $this->form->save();
    $this->container->get('database')->insert('application')->fields([
      'id' => 9001,
      'revision_id' => 9001,
      'uuid' => '11a87815-1122-4411-8123-990011223344',
      'uid' => 0,
      'status' => 1,
      'field_schema' => $this->form->id(),
      'field_jurisdiction' => $jurisdiction->id(),
    ])->execute();
    $this->container->get('database')->insert('application_revision')->fields([
      'id' => 9001,
      'revision_id' => 9001,
      'uid' => 0,
      'status' => 1,
    ])->execute();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->originalEnv as $key => $value) {
      putenv($value === FALSE ? $key : $key . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Callback versions.
   */
  public static function versions(): array {
    return ['legacy' => [FALSE], 'staff story' => [TRUE]];
  }

  /**
   * Both callback paths encrypt, and only authorized staff can reveal.
   */
  #[DataProvider('versions')]
  public function testCallbackStorageAndAuditedStaffList(bool $v2): void {
    $attachment = $this->attachment($v2);
    $controller = ApplicationAttachmentApiController::create($this->container);
    $response = $controller->updateAttachmentAnalysis($attachment->uuid(), $this->callbackRequest($v2));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $raw = $this->raw($attachment);
    $this->assertStringNotContainsString('2340.5', $raw);
    $this->assertStringContainsString(ApplicationDataProtectionService::ENCRYPTED_MARKER, $raw);
    $this->assertStringNotContainsString('2340.5', (string) $response->getContent());
    $this->assertStringNotContainsString(ApplicationDataProtectionService::ENCRYPTED_MARKER, (string) $response->getContent());

    $application = $this->scopedApplication();

    $this->setCurrentUser($this->createUser([]));
    $denied = $controller->listApplicationAttachments($application);
    $this->assertSame([], json_decode((string) $denied->getContent(), TRUE)['data']);
    $this->assertSame(0, $this->auditCount());

    $this->setCurrentUser($this->createUser(['view protected application data']));
    $allowed = $controller->listApplicationAttachments($application);
    $metadata = json_decode((string) $allowed->getContent(), TRUE)['data'][0]['analysisMetadata'];
    $result = $v2 ? $metadata['stories']['staff']['result'] : $metadata['result'];
    $this->assertSame(2340.5, $result['extracted'][0]['value']);
    $this->assertSame(1, $this->auditCount());
    $this->assertSame('attachment_analysis', $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'l')->fields('l', ['channel'])->execute()->fetchField());
    $this->assertSame($raw, $this->raw($attachment));
  }

  /**
   * Internal metadata cannot be serialized or written through generic APIs.
   */
  public function testAnalysisFieldIsInternalWithoutStorageSchemaChange(): void {
    $definition = $this->container->get('entity_field.manager')
      ->getBaseFieldDefinitions('application_attachment')['field_analysis_metadata'];
    $this->assertTrue($definition->isInternal());
    $original = clone $definition;
    $original->setInternal(FALSE);
    $storage = $this->entityTypeManager()->getStorage('application_attachment');
    $this->assertFalse($storage->requiresFieldStorageSchemaChanges($definition, $original));
    $attachment = $this->attachment(FALSE);
    $properties = TypedDataInternalPropertiesHelper::getNonInternalProperties($attachment->getTypedData());
    $this->assertArrayNotHasKey('field_analysis_metadata', $properties);
    foreach ([$attachment, $storage->create([])] as $entity) {
      // JSON:API POST and PATCH both check field-level edit access.
      $this->assertTrue($entity->get('field_analysis_metadata')->access('edit', NULL, TRUE)->isForbidden());
    }
  }

  /**
   * One failed record does not hide other stories or other attachments.
   */
  #[DataProvider('versions')]
  public function testStaffListIsolatesTamperedEnvelopes(bool $v2): void {
    $bad = $this->attachment($v2);
    $good = $this->attachment($v2);
    $protection = $this->container->get('beantragt_application.data_protection');
    $good_result = $protection->protectAnalysisResult($this->extractedResult());
    $bad_result = $good_result;
    $bad_result['extracted'][0]['value']['ciphertext'] = base64_encode('tampered');
    foreach ([[$bad, $bad_result], [$good, $good_result]] as [$attachment, $result]) {
      $record = ['status' => 'complete', 'result' => $result];
      $metadata = $v2 ? [
        'version' => 2,
        'stories' => [
          'staff' => $record,
          'citizen' => ['status' => 'complete', 'result' => $good_result],
        ],
      ] : ['version' => 1] + $record;
      $attachment->set('field_analysis_metadata', json_encode($metadata, JSON_THROW_ON_ERROR))->save();
    }
    $before = $this->raw($bad);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      'Attachment analysis values unavailable for @attachment.',
      ['@attachment' => $bad->uuid()],
    );
    $this->container->set('logger.channel.beantragt_application', $logger);
    $this->setCurrentUser($this->createUser(['view protected application data']));
    $controller = ApplicationAttachmentApiController::create($this->container);
    $response = $controller->listApplicationAttachments($this->scopedApplication());
    $this->assertSame(200, $response->getStatusCode());
    $items = array_column(json_decode((string) $response->getContent(), TRUE)['data'], NULL, 'id');
    $bad_metadata = $items[$bad->uuid()]['analysisMetadata'];
    $good_metadata = $items[$good->uuid()]['analysisMetadata'];
    $bad_record = $v2 ? $bad_metadata['stories']['staff'] : $bad_metadata;
    $good_record = $v2 ? $good_metadata['stories']['staff'] : $good_metadata;
    $this->assertTrue($bad_record['extractedUnavailable']);
    $this->assertArrayNotHasKey('extracted', $bad_record['result'] ?? []);
    $this->assertSame(2340.5, $good_record['result']['extracted'][0]['value']);
    $this->assertArrayNotHasKey('extractedUnavailable', $good_record);
    if ($v2) {
      $this->assertSame(2340.5, $bad_metadata['stories']['citizen']['result']['extracted'][0]['value']);
    }
    $this->assertSame(1, $this->auditCount());
    $this->assertSame($before, $this->raw($bad));
  }

  /**
   * Citizen reveals are token-bound, story A only and never change ciphertext.
   */
  #[DataProvider('versions')]
  public function testCitizenPollExceptionPreservesEncryptedStorage(bool $v2): void {
    $schema = json_decode($this->form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    $properties = [];
    foreach (['legible', 'complete', 'wrong_value', 'null_value', 'staff_only'] as $key) {
      $properties[$key] = ['type' => 'boolean', 'title' => $key, 'nonPersonal' => TRUE];
    }
    $schema['sections'][0]['fields'][0]['analysis'] = [
      'enabled' => TRUE,
      'citizenPrompt' => 'Check legibility.',
      'staffPrompt' => 'Read the document.',
      'egressPolicy' => 'sovereign_only',
      'modelClass' => 'openai_compatible',
      'resultSchema' => ['type' => 'object', 'properties' => $properties],
    ];
    $this->form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR))->save();
    $attachment = $this->attachment($v2);
    $result = [
      'outcome' => 'pass',
      'extracted' => [
        ['key' => 'legible', 'value' => TRUE],
        ['key' => 'complete', 'value' => FALSE],
        ['key' => 'wrong_value', 'value' => 2340.5],
        ['key' => 'null_value', 'value' => NULL],
        ['key' => 'undeclared', 'value' => TRUE],
      ],
    ];
    $protection = $this->container->get('beantragt_application.data_protection');
    if ($v2) {
      $this->writer()->mergeStory($attachment, 'citizen', ['status' => 'complete', 'result' => $result]);
      $this->writer()->mergeStory($attachment, 'staff', [
        'status' => 'complete',
        'result' => [
          'extracted' => [
          ['key' => 'legible', 'value' => FALSE],
          ['key' => 'staff_only', 'value' => TRUE],
          ],
        ],
      ]);
      $this->entityTypeManager()->getStorage('application_attachment')->resetCache([$attachment->id()]);
      $attachment = $this->entityTypeManager()->getStorage('application_attachment')->load($attachment->id());
    }
    else {
      $attachment->set('field_analysis_metadata', json_encode([
        'version' => 1,
        'status' => 'complete',
        'result' => $protection->protectAnalysisResult($result),
      ], JSON_THROW_ON_ERROR));
    }
    $token = str_repeat('c', 48);
    $attachment->set('field_application', NULL);
    $attachment->set('field_upload_status', 'uploaded');
    $attachment->set('field_claim_token_hash', hash('sha256', $token));
    $attachment->set('field_claim_expires', $this->container->get('datetime.time')->getRequestTime() + 3600);
    $attachment->save();
    $raw = $this->raw($attachment);
    $metadata = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    $records = $v2 ? $metadata['stories'] : [$metadata];
    foreach ($records as $record) {
      foreach ($record['result']['extracted'] as $item) {
        $this->assertTrue($protection->isEncryptedValue($item['value']));
      }
    }
    $this->assertStringNotContainsString('2340.5', $raw);
    $this->setCurrentUser(new AnonymousUserSession());
    $controller = ApplicationAttachmentApiController::create($this->container);
    $request = static fn (string $claim_token): Request => Request::create('/citizen-analysis', 'POST', [], [], [], [
      'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret'),
    ], json_encode(['claimToken' => $claim_token], JSON_THROW_ON_ERROR));

    // An invalid token must be rejected before attempting any decryption.
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    $invalid = $controller->citizenAttachmentAnalysis($attachment->uuid(), $request(str_repeat('d', 48)));
    $this->assertSame(404, $invalid->getStatusCode());
    $this->assertArrayNotHasKey('data', json_decode((string) $invalid->getContent(), TRUE));
    $this->assertStringNotContainsString('legible', (string) $invalid->getContent());
    $this->assertStringNotContainsString(ApplicationDataProtectionService::ENCRYPTED_MARKER, (string) $invalid->getContent());
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('k', 32));
    // Legacy v1 has the same narrow criteria projection as story A.
    $response = $controller->citizenAttachmentAnalysis($attachment->uuid(), $request($token));
    $this->assertSame(200, $response->getStatusCode());
    $body = (string) $response->getContent();
    $expected = [
      'outcome' => 'pass',
      'extracted' => [
        ['key' => 'legible', 'type' => 'boolean', 'value' => TRUE],
        ['key' => 'complete', 'type' => 'boolean', 'value' => FALSE],
      ],
    ];
    $this->assertSame($expected, json_decode($body, TRUE)['data']['result']);
    foreach ([
      'wrong_value', 'null_value', 'undeclared', 'staff_only', '2340.5',
      ApplicationDataProtectionService::ENCRYPTED_MARKER,
    ] as $hidden) {
      $this->assertStringNotContainsString($hidden, $body);
    }
    $this->assertSame(0, $this->auditCount());
    $this->assertSame($raw, $this->raw($attachment));

    // A damaged allowed criterion hides this record's values, not its status.
    if ($v2) {
      $metadata['stories']['citizen']['result']['extracted'][0]['value']['ciphertext'] = base64_encode('tampered');
    }
    else {
      $metadata['result']['extracted'][0]['value']['ciphertext'] = base64_encode('tampered');
    }
    $damaged = json_encode($metadata, JSON_THROW_ON_ERROR);
    $this->container->get('database')->update('application_attachment')
      ->fields(['field_analysis_metadata' => $damaged])->condition('id', $attachment->id())->execute();
    $this->entityTypeManager()->getStorage('application_attachment')->resetCache([$attachment->id()]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      'Attachment analysis values unavailable for @attachment.',
      ['@attachment' => $attachment->uuid()],
    );
    $this->container->set('logger.channel.beantragt_application', $logger);
    $controller = ApplicationAttachmentApiController::create($this->container);
    $response = $controller->citizenAttachmentAnalysis($attachment->uuid(), $request($token));
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode((string) $response->getContent(), TRUE)['data'];
    $this->assertTrue($data['extractedUnavailable']);
    $this->assertSame('complete', $data['status']);
    $this->assertSame(['outcome' => 'pass'], $data['result']);
    $this->assertSame(0, $this->auditCount());
    $this->assertSame($damaged, $this->raw($attachment));
  }

  /**
   * A missing key cannot overwrite a pending attachment with plaintext.
   */
  #[DataProvider('versions')]
  public function testMissingKeyLeavesCallbackStorageUnchanged(bool $v2): void {
    $attachment = $this->attachment($v2);
    $before = $this->raw($attachment);
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    $controller = ApplicationAttachmentApiController::create($this->container);
    try {
      $response = $controller->updateAttachmentAnalysis($attachment->uuid(), $this->callbackRequest($v2));
      $this->assertSame(500, $response->getStatusCode());
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('ENCRYPTION_KEY', $exception->getMessage());
    }
    $this->assertSame($before, $this->raw($attachment));
  }

  /**
   * The writer itself throws before saving when encryption is unavailable.
   */
  public function testMissingKeyWriterThrowsWithoutSaving(): void {
    $attachment = $this->attachment(TRUE);
    $before = $this->raw($attachment);
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    try {
      $this->writer()->mergeStory($attachment, 'staff', ['status' => 'complete', 'result' => $this->extractedResult()]);
      $this->fail('Expected a fail-closed encryption error.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('ENCRYPTION_KEY', $exception->getMessage());
    }
    $this->assertSame($before, $this->raw($attachment));
  }

  /**
   * Rerunning one story encrypts retained legacy plaintext in the other.
   */
  public function testRerunProtectsRetainedStory(): void {
    $attachment = $this->attachment(TRUE);
    $metadata = [
      'version' => 2,
      'stories' => [
        'staff' => ['status' => 'complete', 'result' => $this->extractedResult()],
        'citizen' => ['status' => 'complete'],
      ],
    ];
    $attachment->set('field_analysis_metadata', json_encode($metadata))->save();
    $this->writer()->startRerun($attachment, 'citizen', FALSE, 60);
    $this->assertStringNotContainsString('2340.5', $this->raw($attachment));
    $this->assertStringContainsString(ApplicationDataProtectionService::ENCRYPTED_MARKER, $this->raw($attachment));
  }

  /**
   * Non-staff reruns also restore the prior result when enqueueing fails.
   */
  #[DataProvider('versions')]
  public function testRerunReservationRollsBack(bool $v2): void {
    $attachment = $this->attachment($v2);
    $metadata = $v2
      ? ['version' => 2, 'status' => 'complete', 'stories' => ['citizen' => ['status' => 'complete']]]
      : ['version' => 1, 'status' => 'complete'];
    $attachment->set('field_analysis_metadata', json_encode($metadata, JSON_THROW_ON_ERROR));
    $attachment->set('field_analysis_status', 'complete')->save();
    $before = $this->raw($attachment);
    $failure = new \RuntimeException('Synthetic enqueue failure.');
    try {
      $this->writer()->startRerun($attachment, $v2 ? 'citizen' : 'legacy', FALSE, 60,
        function (ApplicationAttachmentInterface $fresh, string $run_id) use ($failure): void {
          $this->assertStringContainsString($run_id, $this->raw($fresh));
          throw $failure;
        },
      );
      $this->fail('Expected the enqueue failure.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame($failure, $exception);
    }
    $this->assertSame($before, $this->raw($attachment));
  }

  /**
   * Migration batches, protects both shapes and is byte-for-byte idempotent.
   */
  public function testUpdateBatchesAndIsIdempotent(): void {
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    $attachment = $this->attachment(FALSE);
    $database = $this->container->get('database');
    $legacy = json_encode(['version' => 1, 'result' => $this->extractedResult()]);
    $stories = json_encode(['version' => 2, 'stories' => ['staff' => ['result' => $this->extractedResult()]]]);
    $database->update('application_attachment')->fields(['field_analysis_metadata' => $legacy])->condition('id', $attachment->id())->execute();
    for ($index = 0; $index < 100; $index++) {
      $database->insert('application_attachment')->fields([
        'uuid' => sprintf('00000000-0000-4000-8000-%012d', $index),
        'uid' => 0,
        'field_analysis_metadata' => $stories,
      ])->execute();
    }
    $sandbox = [];
    beantragt_application_update_10028($sandbox);
    $this->assertSame(100, $sandbox['processed']);
    $this->assertLessThan(1, $sandbox['#finished']);
    beantragt_application_update_10028($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(101, $sandbox['updated']);
    $before = $database->select('application_attachment', 'a')->fields('a', ['id', 'field_analysis_metadata'])->execute()->fetchAllKeyed();
    foreach ($before as $raw) {
      $this->assertStringNotContainsString('2340.5', $raw);
      $this->assertStringContainsString(ApplicationDataProtectionService::ENCRYPTED_MARKER, $raw);
    }
    $sandbox = [];
    do {
      beantragt_application_update_10028($sandbox);
    } while ($sandbox['#finished'] < 1);
    $this->assertSame(0, $sandbox['updated']);
    $after = $database->select('application_attachment', 'a')
      ->fields('a', ['id', 'field_analysis_metadata'])->execute()->fetchAllKeyed();
    $this->assertSame($before, $after);
  }

  /**
   * Envelope expansion cannot create a record the normal writer rejects.
   */
  public function testUpdateRejectsOversizedRecordWithoutChangingIt(): void {
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    $attachment = $this->attachment(FALSE);
    $metadata = [
      'version' => 1,
      'result' => $this->extractedResult(),
      'context' => str_repeat('x', 65320),
    ];
    $raw = json_encode($metadata, JSON_THROW_ON_ERROR);
    $this->assertLessThan(65536, strlen($raw));
    $this->container->get('database')->update('application_attachment')
      ->fields(['field_analysis_metadata' => $raw])->condition('id', $attachment->id())->execute();
    $sandbox = [];
    try {
      beantragt_application_update_10028($sandbox);
      $this->fail('Expected the metadata size guard.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('65536', $exception->getMessage());
      $this->assertStringContainsString((string) $attachment->id(), $exception->getMessage());
    }
    $this->assertSame($raw, $this->raw($attachment));
  }

  /**
   * Invalid stored JSON identifies the attachment without exposing its data.
   */
  public function testUpdateErrorsIdentifyAttachment(): void {
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    $attachment = $this->attachment(FALSE);
    foreach ([
      '{broken', '[]', 'null', '"private-fixture"',
      '{"result":{"extracted":[{"key":"overflow","value":1e999}]}}',
    ] as $raw) {
      $this->container->get('database')->update('application_attachment')
        ->fields(['field_analysis_metadata' => $raw])->condition('id', $attachment->id())->execute();
      $sandbox = [];
      try {
        beantragt_application_update_10028($sandbox);
        $this->fail('Expected invalid metadata to stop the update.');
      }
      catch (\RuntimeException $exception) {
        $this->assertStringContainsString('attachment ' . $attachment->id(), $exception->getMessage());
        $this->assertStringNotContainsString($raw, $exception->getMessage());
      }
      $this->assertSame($raw, $this->raw($attachment));
      $this->assertSame(0, $sandbox['last_id']);
    }
  }

  /**
   * Missing migration key fails before changing a row or its progress cursor.
   */
  public function testUpdateRequiresKey(): void {
    $this->container->get('module_handler')->loadInclude('beantragt_application', 'install');
    $attachment = $this->attachment(FALSE);
    $before = $this->raw($attachment);
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    $sandbox = [];
    try {
      beantragt_application_update_10028($sandbox);
      $this->fail('Expected a missing-key error.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('ENCRYPTION_KEY', $exception->getMessage());
    }
    $this->assertSame([], $sandbox);
    $this->assertSame($before, $this->raw($attachment));
  }

  /**
   * Grants only application scope; field permissions remain real.
   */
  private function scopedApplication(): ApplicationInterface {
    $schema_field = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => $this->form->id(),
    ])->get('field_schema');
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(9001);
    $application->method('uuid')->willReturn('11a87815-1122-4411-8123-990011223344');
    $application->method('getReferenceNumber')->willReturn('TEST-00001');
    $application->method('access')->willReturn(TRUE);
    $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_schema');
    $application->method('get')->with('field_schema')->willReturn($schema_field);

    return $application;
  }

  /**
   * Builds a pending attachment backed by real SQL and file entities.
   */
  private function attachment(bool $v2): ApplicationAttachmentInterface {
    $file = $this->entityTypeManager()->getStorage('file')->create([
      'filename' => 'test.pdf',
      'uri' => 'public://test.pdf',
      'filemime' => 'application/pdf',
      'status' => 1,
    ]);
    $file->save();
    $record = ['status' => 'pending', 'analysisRunId' => str_repeat('a', 32)];
    $metadata = $v2 ? ['version' => 2, 'stories' => ['staff' => $record]] : ['version' => 1] + $record;
    $attachment = $this->entityTypeManager()->getStorage('application_attachment')->create([
      'field_file' => $file->id(),
      'field_schema' => $this->form->id(),
      'field_application' => 9001,
      'field_field_id' => 'evidence',
      'field_analysis_status' => 'pending',
      'field_scan_status' => 'clean',
      'field_analysis_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
    ]);
    $attachment->save();
    return $attachment;
  }

  /**
   * A trusted callback with a purpose-bound financial value.
   */
  private function callbackRequest(bool $v2): Request {
    return Request::create('/analysis', 'POST', [], [], [], [
      'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret'),
    ], json_encode([
      'status' => 'complete',
      'story' => $v2 ? 'staff' : 'legacy',
      'formId' => $this->form->uuid(),
      'fieldId' => 'evidence',
      'analysisRunId' => str_repeat('a', 32),
      'metadata' => ['version' => 1, 'result' => $this->extractedResult()],
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Raw column access deliberately bypasses entity/API projections.
   */
  private function raw(ApplicationAttachmentInterface $attachment): string {
    return (string) $this->container->get('database')->select('application_attachment', 'a')
      ->fields('a', ['field_analysis_metadata'])->condition('id', $attachment->id())->execute()->fetchField();
  }

  /**
   * The canonical extracted value fixture.
   */
  private function extractedResult(): array {
    return ['extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2340.5]]];
  }

  /**
   * Counts real protected-access rows.
   */
  private function auditCount(): int {
    return (int) $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'l')->countQuery()->execute()->fetchField();
  }

  /**
   * Gets the container-wired metadata writer.
   */
  private function writer(): AttachmentAnalysisMetadataWriter {
    return $this->container->get('beantragt_application.attachment_analysis_metadata_writer');
  }

}
