<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers ingestion, replay, security scanning and explicit citizen release.
 */
#[Group('beantragt_delivery')]
final class ExternalResponseServiceKernelTest extends KernelTestBase {

  /**
   * Modules enabled for this test.
   *
   * @var string[]
   */
  protected static $modules = ['system'];

  /**
   * Repository under test.
   */
  private ExternalResponseRepository $repository;

  /**
   * Application mock that owns the external responses.
   */
  private ApplicationInterface $application;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $database = $this->container->get('database');
    foreach (ExternalResponseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->repository = new ExternalResponseRepository($database);
    $this->application = $this->createMock(ApplicationInterface::class);
    $this->application->method('id')->willReturn(1);
    $this->application->method('uuid')->willReturn('application-one');
    $this->application->method('getOwnerId')->willReturn(7);
  }

  /**
   * Tests that replays keep one response and release notifies once.
   *
   * A communication on route portal (a reply without outcome would be a
   * decision and go by post without a mail).
   */
  public function testPendingReplayReleaseAndOneNotification(): void {
    $service = $this->service(TRUE, TRUE, 1);
    $response = $service->ingest($this->application, 'fit_connect', 'reply-one', 'case-one', 'Bescheid', [$this->document()], isDecision: FALSE);
    self::assertSame('pending', $response['status']);
    self::assertSame(1000, $response['receivedAt']);
    self::assertNull($response['releasedAt']);
    self::assertSame('FIT-Connect', $response['source']);
    self::assertSame('pending', $response['notificationState']);
    self::assertSame([], $service->listForApplication($this->application, FALSE));
    self::assertCount(1, $service->listForApplication($this->application, TRUE));
    $duplicate = $service->ingest($this->application, 'fit_connect', 'reply-one', 'case-one', 'Bescheid', [$this->document()], TRUE, isDecision: FALSE);
    self::assertSame($response['id'], $duplicate['id']);
    self::assertSame('pending', $duplicate['status']);
    $released = $service->release($this->application, $response['id']);
    self::assertSame('released', $released['status']);
    self::assertSame(1000, $released['releasedAt']);
    self::assertSame('sent', $released['notificationState']);
    self::assertCount(1, $service->listForApplication($this->application, FALSE));
    $service->release($this->application, $response['id']);
    self::assertSame('sent', $this->repository->find(['uuid' => $response['id']])['notification_state']);
    $service->cleanupForApplication($this->application);
    self::assertSame([], $this->repository->list(1, TRUE));
  }

  /**
   * Bulk release and pending recovery never invoke citizen mail transport.
   */
  public function testBulkReleaseAndRecoveryNeverNotifyCitizens(): void {
    $origin = $this->createMock(FieldItemListInterface::class);
    $origin->method('__get')->with('value')->willReturn('bulk');
    $this->application->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_origin');
    $this->application->method('get')->with('field_origin')->willReturn($origin);
    $service = $this->service(TRUE, TRUE, 0);
    $response = $service->ingest($this->application, 'docgen', 'bulk-letter', '', 'Nachforderung', [$this->document()], TRUE);
    self::assertSame('not_applicable', $response['notificationState']);
    self::assertSame('not_applicable', $this->repository->find(['uuid' => $response['id']])['notification_state']);
    $this->container->get('database')->update('beantragt_external_response')->fields(['notification_state' => 'pending'])->execute();
    self::assertSame(1, $service->recoverPendingNotifications());
    self::assertSame([], $this->repository->pendingNotifications());
    self::assertSame('not_applicable', $service->release($this->application, $response['id'])['notificationState']);
  }

  /**
   * A filing receipt is stored for staff only and never announced.
   */
  public function testFilingReceiptStaysWithStaff(): void {
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::once())->method('caseSystemResponded')
      ->with($this->application, 'filed', FALSE, ['filed' => 'closed'], 'fit_connect')->willReturn(TRUE);
    $service = $this->service(TRUE, TRUE, 0, $changer);
    // Even a released flag from the sender cannot release a receipt.
    $response = $service->ingest($this->application, 'fit_connect', 'reply-filed', 'HSt-2026-10001', 'Ablagequittung', [$this->document()], TRUE, outcome: 'filed', followUp: ['filed' => 'closed']);
    self::assertSame('filing_receipt', $response['kind']);
    self::assertSame('filed', $response['outcome']);
    self::assertSame('pending', $response['status']);
    self::assertSame([], $service->listForApplication($this->application, FALSE));
    // A replay stores nothing and changes no status.
    $service->ingest($this->application, 'fit_connect', 'reply-filed', 'HSt-2026-10001', 'Ablagequittung', [$this->document()], outcome: 'filed');
    self::assertCount(1, $service->listForApplication($this->application, TRUE));
    try {
      $service->release($this->application, $response['id']);
      self::fail('A filing receipt was released.');
    }
    catch (\DomainException) {
      self::assertNull($this->repository->find(['uuid' => $response['id']])['released']);
    }
  }

  /**
   * Old responses and old callers keep working; unknown outcomes are refused.
   */
  public function testResponsesWithoutOutcomeAndUnknownOutcomes(): void {
    $changer = $this->createMock(SemanticStatusChanger::class);
    $changer->expects(self::once())->method('caseSystemResponded')
      ->with($this->application, NULL, FALSE, [], 'fit_connect')->willReturn(TRUE);
    $service = $this->service(TRUE, TRUE, 0, $changer);
    $response = $service->ingest($this->application, 'fit_connect', 'reply-legacy', 'case-one', 'Bescheid', [$this->document()]);
    self::assertNull($response['outcome']);
    self::assertSame('decision', $response['kind']);
    try {
      $service->ingest($this->application, 'fit_connect', 'reply-unknown', 'case-one', 'Bescheid', [$this->document()], outcome: 'granted');
      self::fail('An unknown outcome was accepted.');
    }
    catch (\InvalidArgumentException) {
      self::assertNull($this->repository->findExternal('fit_connect', 'reply-unknown'));
    }
    self::assertNull($response['outcomeLabel']);
  }

  /**
   * The case system's word for the outcome is kept, only next to an outcome.
   */
  public function testOutcomeLabel(): void {
    $service = $this->service(TRUE, TRUE, 0);
    $response = $service->ingest($this->application, 'fit_connect', 'reply-labelled', 'HSt-2026-10002', 'Festsetzungsbescheid', [$this->document()], outcome: 'approved', outcomeLabel: ' Festsetzung ');
    self::assertSame('approved', $response['outcome']);
    self::assertSame('Festsetzung', $response['outcomeLabel']);
    // Staff see the case system's word; the applicant's list carries none.
    self::assertSame('Festsetzung', $service->listForApplication($this->application, TRUE)[0]['outcomeLabel']);
    self::assertNull($service->listForApplication($this->application, FALSE)[0]['outcomeLabel'] ?? NULL);
    try {
      $service->ingest($this->application, 'fit_connect', 'reply-label-only', 'HSt-2026-10003', 'Bescheid', [$this->document()], outcomeLabel: 'Festsetzung');
      self::fail('A label without an outcome was accepted.');
    }
    catch (\InvalidArgumentException) {
      self::assertNull($this->repository->findExternal('fit_connect', 'reply-label-only'));
    }
  }

  /**
   * Tests that a failed notification is neither reported sent nor repeated.
   */
  public function testFailedNotificationDoesNotPretendSentOrRepeat(): void {
    $service = $this->service(TRUE, FALSE, 1);
    $response = $service->ingest($this->application, 'fit_connect', 'reply-one', 'case-one', 'Bescheid', [$this->document()], TRUE, isDecision: FALSE);
    self::assertSame('failed', $this->repository->find(['uuid' => $response['id']])['notification_state']);
    $service->release($this->application, $response['id']);
    self::assertSame('released', $response['status']);
  }

  /**
   * Tests that a quarantined PDF is neither persisted nor notified.
   */
  public function testQuarantinedPdfNeverPersistsOrNotifies(): void {
    $service = $this->service(FALSE, FALSE, 0);
    try {
      $service->ingest($this->application, 'fit_connect', 'reply-one', 'case-one', 'Bescheid', [$this->document()], TRUE);
      self::fail('Quarantined response was accepted.');
    }
    catch (\UnexpectedValueException) {
      self::assertSame([], $this->repository->list(1, TRUE));
    }
  }

  /**
   * Tests that a known external reply cannot move to another application.
   */
  public function testDuplicateCannotMoveToAnotherApplication(): void {
    $this->repository->insert([
      'uuid' => 'prior',
      'application_id' => 2,
      'source' => 'fit_connect',
      'external_id_hash' => hash('sha256', 'reply-one'),
      'external_reference' => 'case-other',
      'title' => 'Other',
    ], []);
    $service = $this->service(FALSE, FALSE, 0);
    $this->expectException(\UnexpectedValueException::class);
    $service->ingest($this->application, 'fit_connect', 'reply-one', 'case-one', 'Bescheid', [$this->document()]);
  }

  /**
   * Tests that downloads reject pending and foreign-application documents.
   */
  public function testDownloadsRejectPendingApplicantAndForeignApplication(): void {
    $service = $this->service(TRUE, FALSE, 0);
    $response = $service->ingest($this->application, 'fit_connect', 'reply-one', 'case-one', 'Bescheid', [$this->document()]);
    $other = $this->createMock(ApplicationInterface::class);
    $other->method('id')->willReturn(2);
    foreach ([[$this->application, FALSE], [$other, FALSE], [$other, TRUE]] as [$application, $staff]) {
      try {
        $service->download($application, $response['id'], $response['documents'][0]['id'], $staff);
        self::fail('Pending or foreign document was accessible.');
      }
      catch (\OutOfBoundsException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Builds the service under test with scanner, file, mailer and lock doubles.
   */
  private function service(bool $clean, bool $sent, int $notifications, ?SemanticStatusChanger $changer = NULL): ExternalResponseService {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(12);
    $file->method('getFilename')->willReturn('bescheid.pdf');
    $file->method('getSize')->willReturn(50);
    $file->method('setPermanent')->willReturnCallback(function () use ($file): FileInterface {
      self::assertNotEmpty($this->repository->documents('uuid-1'));
      self::assertTrue($this->container->get('database')->inTransaction());
      return $file;
    });
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $file_storage->method('getQuery')->willReturn($query);
    $file_storage->method('load')->with(12)->willReturn($file);
    $app_storage = $this->createMock(EntityStorageInterface::class);
    $app_storage->method('load')->with(1)->willReturn($this->application);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['application', $app_storage], ['file', $file_storage]]);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $file_repository->method('writeData')->willReturn($file);
    if (!$clean) {
      $file_repository->expects(self::never())->method('writeData');
    }
    $fs = $this->createMock(FileSystemInterface::class);
    $fs->method('prepareDirectory')->willReturn(TRUE);
    $scanner = $this->createMock(AttachmentScanServiceInterface::class);
    $scanner->method('scan')->willReturn(new AttachmentScanResult($clean ? 'clean' : 'quarantined'));
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $uuid = $this->createMock(UuidInterface::class);
    $i = 0;
    $uuid->method('generate')->willReturnCallback(static function () use (&$i): string {
      return 'uuid-' . ++$i;
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1000);
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->expects(self::exactly($notifications))->method('sendCitizenNotificationWithResult')->with($this->application, 'external_response_available')->willReturn($sent);
    return new ExternalResponseService($this->repository, $manager, $fs, $file_repository, $scanner, new ApplicationDependentMutationLock($manager, $lock), $uuid, $time, $mailer, $changer);
  }

  /**
   * Returns a valid PDF document payload for ingestion.
   */
  private function document(): array {
    $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    return [
      'bytes' => $bytes,
      'filename' => 'bescheid.pdf',
      'mimeType' => 'application/pdf',
      'sha512' => hash('sha512', $bytes),
    ];
  }

}
