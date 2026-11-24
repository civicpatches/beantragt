<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Exception\AttachmentAnalysisMetadataLockedException;
use Drupal\beantragt_application\Exception\AttachmentAnalysisRerunRefusedException;
use Drupal\beantragt_application\Service\AttachmentAnalysisMetadataWriter;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Psr\Log\NullLogger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the per-story attachment analysis metadata writer.
 *
 * @coversDefaultClass \Drupal\beantragt_application\Service\AttachmentAnalysisMetadataWriter
 */
final class AttachmentAnalysisMetadataWriterTest extends UnitTestCase {

  private const ATTACHMENT_UUID = '8cf1ce03-5c64-4098-8b1c-a8322a62e073';
  private const NOW = 1782862200;

  /**
   * Original encryption key.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('k', 32));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * The first story write lifts the v1 start record and keeps its language.
   *
   * @covers ::mergeStory
   */
  public function testFirstStoryWriteLiftsStartRecordAndKeepsLanguage(): void {
    $state = new \ArrayObject([
      'field_analysis_status' => 'not_requested',
      'field_analysis_metadata' => json_encode(['version' => 1, 'status' => 'not_requested', 'language' => 'en']),
    ]);
    $attachment = $this->statefulAttachment($state);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())
      ->method('acquire')
      ->with('beantragt_application.attachment_claim.77', 30.0)
      ->willReturn(TRUE);
    $lock->expects($this->once())->method('release')->with('beantragt_application.attachment_claim.77');

    $written = $this->writer($attachment, $lock)->mergeStory($attachment, 'staff', [
      'status' => 'pending',
      'code' => 'analysis_queued',
      'version' => 1,
      'worker' => ['attempt' => 1, 'maxAttempts' => 3],
    ], TRUE);

    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(2, $stored['version']);
    $this->assertSame('en', $stored['language']);
    $this->assertSame('pending', $stored['status']);
    $this->assertSame('pending', $state['field_analysis_status']);
    $this->assertSame(['staff'], array_keys($stored['stories']));
    $this->assertArrayNotHasKey('version', $stored['stories']['staff']);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $stored['stories']['staff']['analysisRunId']);
    $this->assertSame(self::NOW, $stored['stories']['staff']['analysisStartedAt']);
    $this->assertSame($stored['stories']['staff']['analysisRunId'], $written['story']['analysisRunId']);
  }

  /**
   * Story A and story B written from stale handles both survive.
   *
   * Every writer passes the entity it loaded before the other story wrote,
   * as two processes do. Only the reload under the lock sees the newer
   * record; a stale handle is never saved.
   *
   * @covers ::mergeStory
   * @covers ::overallStatus
   */
  public function testCitizenAndStaffWrittenInParallelBothSurvive(): void {
    $state = new \ArrayObject([
      'field_analysis_status' => 'not_requested',
      'field_analysis_metadata' => json_encode(['version' => 1, 'status' => 'not_requested', 'language' => 'de']),
    ]);
    $live = $this->statefulAttachment($state);
    $writer = $this->writer($live);
    // Both handles are loaded before either story writes.
    $citizen_handle = $this->staleAttachment($state);
    $staff_handle = $this->staleAttachment($state);

    $citizen = $writer->mergeStory($citizen_handle, 'citizen', ['status' => 'pending'], TRUE);
    $staff = $writer->mergeStory($staff_handle, 'staff', ['status' => 'pending'], TRUE);
    $this->assertNotSame($citizen['story']['analysisRunId'], $staff['story']['analysisRunId']);
    $this->assertSame($live, $staff['attachment']);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['citizen', 'staff'], array_keys($stored['stories']));

    $writer->mergeStory($staff_handle, 'staff', [
      'status' => 'complete',
      'code' => 'analysis_complete',
      'result' => [
        'outcome' => 'unknown',
        'extracted' => [['key' => 'gross_pay_month', 'type' => 'number', 'value' => 2480]],
      ],
    ]);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('pending', $stored['stories']['citizen']['status']);
    $this->assertSame('complete', $stored['stories']['staff']['status']);
    $this->assertSame($staff['story']['analysisRunId'], $stored['stories']['staff']['analysisRunId']);
    $this->assertSame('pending', $state['field_analysis_status']);

    $writer->mergeStory($citizen_handle, 'citizen', [
      'status' => 'failed',
      'code' => 'analysis_failed',
      'language' => 'en',
    ]);
    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['citizen', 'staff'], array_keys($stored['stories']));
    $this->assertTrue($stored['stories']['staff']['result']['extracted'][0]['value'][ApplicationDataProtectionService::ENCRYPTED_MARKER]);
    $this->assertSame('failed', $stored['status']);
    $this->assertSame('en', $stored['language']);
    $this->assertSame('failed', $state['field_analysis_status']);
  }

  /**
   * A held claim lock writes nothing.
   *
   * @covers ::mergeStory
   */
  public function testLockedClaimWritesNothing(): void {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(77);
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $lock->expects($this->never())->method('release');

    $this->expectException(AttachmentAnalysisMetadataLockedException::class);
    $this->writer($attachment, $lock)->mergeStory($attachment, 'citizen', ['status' => 'pending'], TRUE);
  }

  /**
   * Overall status orders pending before failed before complete.
   *
   * @covers ::overallStatus
   */
  public function testOverallStatusOrder(): void {
    $this->assertSame('not_requested', AttachmentAnalysisMetadataWriter::overallStatus([]));
    $this->assertSame('complete', AttachmentAnalysisMetadataWriter::overallStatus(['citizen' => ['status' => 'complete']]));
    $this->assertSame('failed', AttachmentAnalysisMetadataWriter::overallStatus([
      'citizen' => ['status' => 'complete'],
      'staff' => ['status' => 'failed'],
    ]));
    $this->assertSame('pending', AttachmentAnalysisMetadataWriter::overallStatus([
      'citizen' => ['status' => 'failed'],
      'staff' => ['status' => 'pending'],
    ]));
  }

  /**
   * The lift of a start record keeps foreign top-level keys.
   *
   * @covers ::liftToV2
   * @covers ::holdsLegacyRun
   */
  public function testLiftKeepsContextOfStartRecord(): void {
    $start = [
      'version' => 1,
      'status' => 'not_requested',
      'language' => 'de',
      'context' => 'application_comment',
    ];
    $this->assertFalse(AttachmentAnalysisMetadataWriter::holdsLegacyRun($start));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::holdsLegacyRun([]));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::holdsLegacyRun([
      'version' => 2,
      'status' => 'complete',
      'stories' => [],
    ]));
    foreach (['pending', 'complete', 'failed'] as $status) {
      $this->assertTrue(AttachmentAnalysisMetadataWriter::holdsLegacyRun(['version' => 1, 'status' => $status]));
    }
    $lifted = AttachmentAnalysisMetadataWriter::liftToV2($start);
    $this->assertSame(['version' => 2, 'language' => 'de', 'context' => 'application_comment', 'stories' => []], $lifted);
  }

  /**
   * A story write never wipes a legacy run kept from before a profile switch.
   *
   * @covers ::mergeStory
   */
  public function testStoryWriteRefusesLegacyRun(): void {
    $legacy = json_encode([
      'version' => 1,
      'status' => 'complete',
      'code' => 'analysis_complete',
      'language' => 'de',
      'result' => ['outcome' => 'pass', 'extracted' => [['key' => 'gross_pay_month', 'value' => 2480]]],
    ]);
    $state = new \ArrayObject([
      'field_analysis_status' => 'complete',
      'field_analysis_metadata' => $legacy,
    ]);
    $attachment = $this->statefulAttachment($state);

    try {
      $this->writer($attachment)->mergeStory($attachment, 'staff', ['status' => 'pending'], TRUE);
      $this->fail('A story write over a legacy run must be refused.');
    }
    catch (\LogicException) {
    }
    $this->assertSame($legacy, $state['field_analysis_metadata']);
    $this->assertSame('complete', $state['field_analysis_status']);
  }

  /**
   * A terminal write waits out a held lock for up to the given rounds.
   *
   * @covers ::mergeStory
   */
  public function testTerminalWriteWaitsOutHeldLock(): void {
    $state = new \ArrayObject([
      'field_analysis_status' => 'pending',
      'field_analysis_metadata' => json_encode([
        'version' => 2,
        'status' => 'pending',
        'stories' => [
          'staff' => ['status' => 'pending', 'analysisRunId' => str_repeat('a', 32)],
        ],
      ]),
    ]);
    $attachment = $this->statefulAttachment($state);
    $acquisitions = 0;
    $lock = $this->createMock(LockBackendInterface::class);
    // Free only on the fifth try: four waits, more than one start write gets.
    $lock->method('acquire')->willReturnCallback(static function () use (&$acquisitions): bool {
      return ++$acquisitions >= 5;
    });
    $lock->expects($this->exactly(4))->method('wait');
    $lock->expects($this->once())->method('release');

    $this->writer($attachment, $lock)->mergeStory(
      $attachment,
      'staff',
      ['status' => 'failed', 'code' => 'worker_dispatch_failed'],
      FALSE,
      [],
      AttachmentAnalysisMetadataWriter::TERMINAL_WRITE_WAIT_ROUNDS,
    );

    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('failed', $stored['stories']['staff']['status']);
    $this->assertSame(str_repeat('a', 32), $stored['stories']['staff']['analysisRunId']);
  }

  /**
   * Story mode, story A and story B are derived from the profile.
   *
   * @covers ::isStoryProfile
   * @covers ::profileHasCitizenStory
   * @covers ::profileHasStaffStory
   * @covers ::uploadRunsCitizenStory
   */
  public function testProfileStoryDerivation(): void {
    $schema = [
      'properties' => [
        'legible' => ['type' => 'boolean', 'nonPersonal' => TRUE],
        'gross_pay_month' => ['type' => 'number', 'purposeBound' => TRUE],
      ],
    ];
    $story = [
      'enabled' => TRUE,
      'citizenPrompt' => 'Lesbar?',
      'staffPrompt' => ' ',
      'egressPolicy' => 'sovereign_only',
      'modelClass' => 'openai_compatible',
      'resultSchema' => $schema,
    ];
    $this->assertTrue(AttachmentAnalysisMetadataWriter::isStoryProfile($story));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::isStoryProfile([
      'promptSuggestion' => 'x',
      'staffPrompt' => '  ',
    ]));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::profileHasCitizenStory($story));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::profileHasStaffStory($story));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::profileHasStaffStory(['resultSchema' => ['properties' => ['legible' => ['nonPersonal' => TRUE]]]]));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::uploadRunsCitizenStory($story, FALSE));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::uploadRunsCitizenStory([
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ] + $story, FALSE));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::uploadRunsCitizenStory([
      'egressPolicy' => 'external_allowed',
      'modelClass' => 'claude_sonnet',
    ] + $story, TRUE));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::uploadRunsCitizenStory([
      'egressPolicy' => 'external_redacted',
      'modelClass' => 'claude_sonnet',
    ] + $story, TRUE));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::uploadRunsCitizenStory([
      'citizenPrompt' => '',
      'staffPrompt' => '',
    ] + $story, FALSE));
  }

  /**
   * A rerun of a complete story starts a new run; the other story stays.
   *
   * @covers ::startRerun
   */
  public function testRerunOnCompleteStoryCreatesNewRunAndLeavesOtherStoryUntouched(): void {
    $citizen = [
      'status' => 'complete',
      'code' => 'analysis_complete',
      'analysisRunId' => str_repeat('a', 32),
      'analysisStartedAt' => self::NOW - 7200,
      'result' => ['outcome' => 'pass', 'missingCount' => 0],
    ];
    $staff = [
      'status' => 'complete',
      'code' => 'analysis_complete',
      'analysisRunId' => str_repeat('b', 32),
      'analysisStartedAt' => self::NOW - 7000,
      'worker' => ['attempt' => 2, 'maxAttempts' => 3],
      'result' => ['outcome' => 'unknown', 'extracted' => [['key' => 'gross_pay_month', 'value' => 2480]]],
    ];
    $state = new \ArrayObject([
      'field_analysis_status' => 'complete',
      'field_analysis_metadata' => json_encode([
        'version' => 2,
        'status' => 'complete',
        'language' => 'de',
        'stories' => ['citizen' => $citizen, 'staff' => $staff],
      ]),
    ]);
    $attachment = $this->statefulAttachment($state);

    $started = $this->writer($attachment)->startRerun($attachment, 'staff', FALSE, 3600);

    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($citizen, $stored['stories']['citizen']);
    $this->assertSame('pending', $stored['stories']['staff']['status']);
    $this->assertSame('analysis_queued', $stored['stories']['staff']['code']);
    $this->assertSame(self::NOW, $stored['stories']['staff']['analysisStartedAt']);
    $this->assertSame($started['runId'], $stored['stories']['staff']['analysisRunId']);
    $this->assertNotSame($staff['analysisRunId'], $started['runId']);
    // The attempt count of the rerun starts again, the old result is gone.
    $this->assertArrayNotHasKey('worker', $stored['stories']['staff']);
    $this->assertArrayNotHasKey('result', $stored['stories']['staff']);
    $this->assertSame('pending', $state['field_analysis_status']);
  }

  /**
   * A guard block stays final, a failed story reruns, a running one waits.
   *
   * @covers ::startRerun
   */
  public function testRerunRefusesGuardBlockedAndRunningButRerunsFailed(): void {
    $records = [
      'guard_blocked' => [
        ['status' => 'failed', 'code' => 'guard_blocked', 'analysisRunId' => str_repeat('c', 32)],
        'attachment.guard_blocked',
      ],
      'running' => [
        [
          'status' => 'pending',
          'code' => 'analysis_queued',
          'analysisRunId' => str_repeat('d', 32),
          'analysisStartedAt' => self::NOW - 60,
        ],
        'attachment.analysis_running',
      ],
      'failed' => [
        ['status' => 'failed', 'code' => 'analysis_failed', 'analysisRunId' => str_repeat('e', 32)],
        NULL,
      ],
      'stale pending' => [
        [
          'status' => 'pending',
          'code' => 'analysis_queued',
          'analysisRunId' => str_repeat('f', 32),
          'analysisStartedAt' => self::NOW - 4000,
        ],
        NULL,
      ],
    ];
    foreach ($records as $label => [$record, $refusal]) {
      $state = new \ArrayObject([
        'field_analysis_status' => $record['status'],
        'field_analysis_metadata' => json_encode([
          'version' => 2,
          'status' => $record['status'],
          'stories' => ['citizen' => $record],
        ]),
      ]);
      $attachment = $this->statefulAttachment($state);
      try {
        $started = $this->writer($attachment)->startRerun($attachment, 'citizen', FALSE, 3600);
        $this->assertNull($refusal, $label);
        $this->assertNotSame($record['analysisRunId'], $started['runId'], $label);
      }
      catch (AttachmentAnalysisRerunRefusedException $e) {
        $this->assertSame($refusal, $e->getMessage(), $label);
        $this->assertSame($record, json_decode((string) $state['field_analysis_metadata'], TRUE)['stories']['citizen'], $label);
      }
    }

    // external_redacted reads the guard derivative: a guard needs_review
    // blocks the rerun before anything is written.
    $state = new \ArrayObject([
      'field_analysis_status' => 'not_requested',
      'field_analysis_metadata' => json_encode(['version' => 1, 'status' => 'not_requested', 'language' => 'de']),
      'field_guard_status' => 'needs_review',
    ]);
    $attachment = $this->statefulAttachment($state);
    try {
      $this->writer($attachment)->startRerun($attachment, 'citizen', TRUE, 3600);
      $this->fail('A guard block must refuse the rerun.');
    }
    catch (AttachmentAnalysisRerunRefusedException $e) {
      $this->assertSame('attachment.guard_blocked', $e->getMessage());
      $this->assertSame('not_requested', $state['field_analysis_status']);
    }
  }

  /**
   * A legacy rerun writes a version 1 run record with its own run id.
   *
   * @covers ::startRerun
   */
  public function testLegacyRerunWritesVersionOneRunRecord(): void {
    $state = new \ArrayObject([
      'field_analysis_status' => 'complete',
      'field_analysis_metadata' => json_encode([
        'version' => 1,
        'status' => 'complete',
        'code' => 'analysis_complete',
        'language' => 'en',
        'commentId' => 'c-1',
        'result' => ['outcome' => 'pass'],
      ]),
    ]);
    $attachment = $this->statefulAttachment($state);

    $started = $this->writer($attachment)->startRerun($attachment, 'legacy', FALSE, 3600);

    $stored = json_decode((string) $state['field_analysis_metadata'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(1, $stored['version']);
    $this->assertSame('pending', $stored['status']);
    $this->assertSame('en', $stored['language']);
    $this->assertSame('c-1', $stored['commentId']);
    $this->assertSame($started['runId'], $stored['analysisRunId']);
    $this->assertSame(self::NOW, $stored['analysisStartedAt']);
    $this->assertArrayNotHasKey('result', $stored);
    $this->assertSame('pending', $state['field_analysis_status']);

    // A version 2 record has no legacy run to repeat.
    $state['field_analysis_metadata'] = json_encode(['version' => 2, 'status' => 'complete', 'stories' => []]);
    $this->expectException(AttachmentAnalysisRerunRefusedException::class);
    $this->expectExceptionMessage('attachment.analysis_story_required');
    $this->writer($attachment)->startRerun($attachment, 'legacy', FALSE, 3600);
  }

  /**
   * Which story a case worker may rerun on which profile.
   *
   * @covers ::rerunStoryAllowed
   */
  public function testRerunStoryAllowed(): void {
    $schema = [
      'properties' => [
        'legible' => ['type' => 'boolean', 'nonPersonal' => TRUE],
        'gross_pay_month' => ['type' => 'number', 'purposeBound' => TRUE],
      ],
    ];
    $story = [
      'enabled' => TRUE,
      'citizenPrompt' => 'Lesbar?',
      'staffPrompt' => 'Brutto lesen',
      'egressPolicy' => 'sovereign_only',
      'modelClass' => 'openai_compatible',
      'resultSchema' => $schema,
    ];
    $legacy = ['enabled' => TRUE, 'egressPolicy' => 'sovereign_only', 'modelClass' => 'openai_compatible'];
    $external = ['egressPolicy' => 'external_redacted', 'modelClass' => 'claude_sonnet'] + $story;

    $this->assertTrue(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($legacy, 'legacy'));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($legacy, 'citizen'));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($story, 'citizen'));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($story, 'staff'));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($story, 'legacy'));
    $this->assertTrue(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($external, 'citizen'));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::rerunStoryAllowed($external, 'staff'));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::rerunStoryAllowed(['egressPolicy' => 'local_only'] + $story, 'staff'));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::rerunStoryAllowed(['enabled' => FALSE] + $story, 'citizen'));
    $this->assertFalse(AttachmentAnalysisMetadataWriter::rerunStoryAllowed(NULL, 'legacy'));
  }

  /**
   * Both stories at the 80-entry limit fit with 100-character field keys.
   */
  public function testMaximumExtractedEntriesFitEncryptedMetadata(): void {
    $state = new \ArrayObject(['field_analysis_metadata' => '{}']);
    $attachment = $this->statefulAttachment($state);
    $writer = $this->writer($attachment);
    $entries = [];
    for ($index = 0; $index < 80; $index++) {
      $entries[] = ['key' => str_pad((string) $index, 100, 'x'), 'type' => 'number', 'value' => -PHP_FLOAT_MAX];
    }
    foreach (AttachmentAnalysisMetadataWriter::STORIES as $story) {
      $writer->mergeStory($attachment, $story, ['status' => 'complete', 'result' => ['extracted' => $entries]]);
    }
    $raw = (string) $state['field_analysis_metadata'];
    $this->assertLessThanOrEqual(65536, strlen($raw));
    $this->assertSame(160, substr_count($raw, ApplicationDataProtectionService::ENCRYPTED_MARKER));
  }

  /**
   * Padding plus extreme key lengths must not bypass the overall byte cap.
   */
  public function testPaddedExtremeMetadataIsRefusedWithoutSaving(): void {
    $state = new \ArrayObject(['field_analysis_metadata' => '{}']);
    $attachment = $this->statefulAttachment($state);
    $writer = $this->writer($attachment);
    $entries = [];
    for ($index = 0; $index < 80; $index++) {
      $entries[] = ['key' => str_pad((string) $index, 200, 'x'), 'type' => 'number', 'value' => -PHP_FLOAT_MAX];
    }
    $writer->mergeStory($attachment, 'citizen', ['status' => 'complete', 'result' => ['extracted' => $entries]]);
    $before = $state['field_analysis_metadata'];
    try {
      $writer->mergeStory($attachment, 'staff', ['status' => 'complete', 'result' => ['extracted' => $entries]]);
      $this->fail('Expected a metadata size exception.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Attachment analysis metadata is too large.', $exception->getMessage());
    }
    $this->assertSame($before, $state['field_analysis_metadata']);
  }

  /**
   * Builds a writer around one attachment double.
   */
  private function writer(ApplicationAttachmentInterface $attachment, ?LockBackendInterface $lock = NULL): AttachmentAnalysisMetadataWriter {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->with(['uuid' => self::ATTACHMENT_UUID])->willReturn([$attachment]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('application_attachment')->willReturn($storage);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    return new AttachmentAnalysisMetadataWriter($manager, $lock, $time, new ApplicationDataProtectionService($manager, new NullLogger()));
  }

  /**
   * Builds an attachment whose analysis fields persist across set() calls.
   *
   * @param \ArrayObject<string, mixed> $state
   *   Field state.
   */
  private function statefulAttachment(\ArrayObject $state): ApplicationAttachmentInterface {
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(77);
    $attachment->method('uuid')->willReturn(self::ATTACHMENT_UUID);
    $attachment->method('hasField')->willReturnCallback(static fn (string $field): bool => $state->offsetExists($field));
    $attachment->method('get')->willReturnCallback(function (string $field) use ($state): FieldItemListInterface {
      $value = $state->offsetExists($field) ? $state[$field] : NULL;
      $item = $this->createMock(FieldItemListInterface::class);
      $item->method('isEmpty')->willReturn($value === NULL);
      $item->method('__isset')->willReturnCallback(static fn (string $property): bool => $property === 'value' && $value !== NULL);
      $item->method('__get')->willReturnCallback(static fn (string $property): mixed => $property === 'value' ? $value : NULL);
      return $item;
    });
    $attachment->method('set')->willReturnCallback(
      static function (string $field, mixed $value) use ($attachment, $state): ApplicationAttachmentInterface {
        $state[$field] = $value;
        return $attachment;
      },
    );
    return $attachment;
  }

  /**
   * Builds a handle that keeps the field values it was loaded with.
   *
   * It stands for an entity another process loaded earlier: it never sees
   * later writes and must never be saved.
   *
   * @param \ArrayObject<string, mixed> $state
   *   Field state at load time.
   */
  private function staleAttachment(\ArrayObject $state): ApplicationAttachmentInterface {
    $snapshot = $state->getArrayCopy();
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('id')->willReturn(77);
    $attachment->method('uuid')->willReturn(self::ATTACHMENT_UUID);
    $attachment->method('get')->willReturnCallback(function (string $field) use ($snapshot): FieldItemListInterface {
      $value = $snapshot[$field] ?? NULL;
      $item = $this->createMock(FieldItemListInterface::class);
      $item->method('isEmpty')->willReturn($value === NULL);
      $item->method('__isset')->willReturnCallback(static fn (string $property): bool => $property === 'value' && $value !== NULL);
      $item->method('__get')->willReturnCallback(static fn (string $property): mixed => $property === 'value' ? $value : NULL);
      return $item;
    });
    $attachment->expects($this->never())->method('set');
    $attachment->expects($this->never())->method('save');
    return $attachment;
  }

}
