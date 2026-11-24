<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_nachreichung\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\DocgenAppendix;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_docgen\Service\LetterheadService;
use Drupal\beantragt_docgen\Service\RenderDataBuilder;
use Drupal\beantragt_docgen\Service\RequestLetterValues;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_nachreichung\Code\CoverCodeHasher;
use Drupal\beantragt_nachreichung\Repository\CoverCodeRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\beantragt_nachreichung\Repository\NachreichungEventLog;
use Drupal\beantragt_nachreichung\RequestedProofsProviderInterface;
use Drupal\beantragt_nachreichung\Service\CoverPrintService;
use Drupal\beantragt_nachreichung\Service\CoverSheetAppendix;
use Drupal\beantragt_nachreichung\Service\CoverSheetBuilder;
use Drupal\beantragt_nachreichung\Service\NachreichungActivation;
use Drupal\beantragt_nachreichung\Service\NachreichungCard;
use Drupal\beantragt_nachreichung\Service\NachreichungCleanup;
use Drupal\beantragt_nachreichung\Service\NachreichungSectionValidator;
use Drupal\beantragt_nachreichung\Service\RequestedProofs;
use Drupal\beantragt_nachreichung\Exception\NachreichungException;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\beantragt_docgen\Traits\DocgenReleaseTestTrait;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Request letters with their cover sheet as a companion PDF.
 *
 * Decision of 09.11.2026: the cover sheet is not a section of the letter
 * (LibreOffice damages the tagged PDF/A for the blank page after a one-page
 * letter) but a PDF/A of its own on the letter's docgen row, rendered by the
 * same renderer as the staff reprint, released only with the letter as the
 * second document of the same response. Its identifier is inactive until
 * the release books the letter and becomes active in that transaction.
 */
#[Group('beantragt_nachreichung')]
final class CoverSheetLetterKernelTest extends GroupKernelTestBase {

  use DocgenReleaseTestTrait;

  private const ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

  /**
   * Thursday 11.03.2027 10:00 Europe/Berlin: 15 days end on Good Friday.
   */
  private const REQUEST_TIME = 1804755600;

  /**
   * What the bulk provider double reports as requested.
   */
  private const REQUESTED = [
    'key' => 'miete#',
    'fieldId' => 'miete',
    'personBlock' => NULL,
    'label' => 'Mietbescheinigung',
    'reason' => 'missing',
    'criteria' => [],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'beantragt_docgen'];

  /**
   * Document repository on the test database.
   */
  private DocumentRepository $repository;

  /**
   * External response repository on the test database.
   */
  private ExternalResponseRepository $responses;

  /**
   * Request-for-information template.
   */
  private DocgenTemplateInterface $requestInfo;

  /**
   * The jurisdiction of the application.
   */
  private GroupInterface $jurisdiction;

  /**
   * Application double.
   */
  private ApplicationInterface $application;

  /**
   * Application returned by storage (NULL when deleted).
   */
  private ?ApplicationInterface $current = NULL;

  /**
   * Jurisdiction config sections by name.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $sections = [];

  /**
   * Status changer double.
   */
  private SemanticStatusChanger $statusChanger;

  /**
   * Whether the next cover sheet rendering is not compliant.
   */
  private bool $coverNotCompliant = FALSE;

  /**
   * Runs while docgen finalizes a cover sheet (simulates a second clerk).
   */
  private ?\Closure $duringCoverRender = NULL;

  /**
   * Key material before the test.
   */
  private string|false $previousKey;

  /**
   * Timezone before the test.
   */
  private string $previousTimezone = 'UTC';

  /**
   * The appendix under test.
   */
  private CoverSheetAppendix $appendix;

  /**
   * Identifier rows.
   */
  private CoverCodeRepository $codes;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->previousKey = getenv(self::ENV);
    $this->previousTimezone = date_default_timezone_get();
    putenv(self::ENV . '=' . str_repeat('0123456789abcdef', 4));
    date_default_timezone_set('Europe/Berlin');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('docgen_template');
    $this->installSchema('beantragt_docgen', array_keys(DocumentRepository::schema() + DocgenSettingsRepository::schema()));
    $database = $this->container->get('database');
    foreach (ExternalResponseRepository::schema() + [
      ProtectedAccessLog::TABLE => ProtectedAccessLog::schema(),
      CoverCodeRepository::TABLE => CoverCodeRepository::schema(),
      NachforderungRepository::TABLE => NachforderungRepository::schema(),
      NachreichungEventLog::TABLE => NachreichungEventLog::schema(),
    ] as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $this->repository = new DocumentRepository($database);
    $this->responses = new ExternalResponseRepository($database);
    $this->createGroupType(['id' => 'jur']);
    $this->jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Stadt Musterstadt']);
    $this->requestInfo = $this->template();
    $this->application = $this->applicationDouble();
    $this->current = $this->application;
    $this->statusChanger = $this->createMock(SemanticStatusChanger::class);
    $this->sections = [
      'docgen' => ['requestInfoDeadlineDays' => 15, 'holidayRegion' => 'DE-NW'],
      'nachreichung' => ['coverSheet' => TRUE],
    ];
    $this->appendix = $this->appendix();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    date_default_timezone_set($this->previousTimezone);
    putenv($this->previousKey === FALSE ? self::ENV : self::ENV . '=' . $this->previousKey);
    parent::tearDown();
  }

  /**
   * A draft stores the companion PDF, a draft identifier and a draft record.
   */
  public function testDraftCarriesCompanionCoverSheet(): void {
    $service = $this->documentService();
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser());

    $row = $this->repository->find(1, $document['id']);
    $this->assertNotEmpty($row['cover_pdf_file_id']);
    $this->assertNotEmpty($row['rendered_at']);
    $this->assertTrue(json_decode((string) $row['cover_validation'], TRUE)['compliant']);
    $this->assertSame($row['cover_pdf_sha256'], $document['cover']['sha256']);
    $this->assertStringEndsWith('/docgen/documents/' . $document['id'] . '/cover-pdf', $document['cover']['downloadUrl']);
    // The letter itself is unchanged by the sheet: no section, no image.
    $this->assertStringStartsWith('LH:template', (string) file_get_contents($this->fileUri((int) $row['docx_file_id'])));
    $cover = $service->coverPdfFile($this->application, $document['id'], $this->getCurrentUser());
    $this->assertSame($row['cover_pdf_sha256'], hash('sha256', (string) file_get_contents($cover->getFileUri())));
    $this->assertContains('cover_rendered', array_column($this->repository->events($document['id']), 'event'));

    $codes = $this->rows(CoverCodeRepository::TABLE);
    $this->assertCount(1, $codes);
    $this->assertSame('draft', $codes[0]['state']);
    $this->assertSame('letter', $codes[0]['origin']);
    $this->assertSame($document['id'], $codes[0]['docgen_document_uuid']);
    $this->assertNull($codes[0]['activated_at']);
    $requests = $this->rows(NachforderungRepository::TABLE);
    $this->assertCount(1, $requests);
    $this->assertSame('draft', $requests[0]['state']);
    // Karfreitag case: 15 days from Thursday 11.03.2027 end on 26.03.,
    // Good Friday, so the printed deadline is Tuesday 30.03.2027; the
    // letter's value is the same day.
    $this->assertSame(['2027-03-30', '2027-03-30'], [$requests[0]['deadline_printed'], $requests[0]['deadline']]);
    $this->assertSame('30.03.2027', $this->values()->values($this->application)['nachforderung.frist']);
    $this->assertSame([self::REQUESTED], json_decode((string) $requests[0]['requested'], TRUE));
    $this->assertSame($requests[0]['id'], $codes[0]['nachforderung_id']);
    $this->assertSame(['cover_issued'], array_column($this->rows(NachreichungEventLog::TABLE), 'code'));
    $this->assertNoIdentifierStored();
    $this->assertContains('docgen.preview_missing', $service->releaseBlockers($row, $this->getCurrentUser()));
    $this->assertNotContains('docgen.cover_sheet_missing', $service->releaseBlockers($row, $this->getCurrentUser()));
  }

  /**
   * A cover sheet that is not PDF/A compliant fails the draft; nothing stays.
   */
  public function testFailedCoverSheetStoresNothing(): void {
    $this->coverNotCompliant = TRUE;
    $this->assertCode('docgen.cover_sheet_unavailable', fn () => $this->documentService()->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser()));
    $this->assertSame([], $this->repository->list(1));
    $this->assertSame([], $this->rows(CoverCodeRepository::TABLE));
    $this->assertSame([], $this->rows(NachforderungRepository::TABLE));
    $this->assertSame([], $this->files());
  }

  /**
   * A failure while storing rolls back docgen row, record and identifier.
   */
  public function testStoringFailureRollsBackEverything(): void {
    $failing = new class(...$this->appendixArguments()) extends CoverSheetAppendix {

      /**
       * {@inheritdoc}
       */
      public function commit(ApplicationInterface $application, string $document_uuid, DocgenAppendix $appendix, int $now): void {
        parent::commit($application, $document_uuid, $appendix, $now);
        throw new \RuntimeException('Simulated failure after the appendix rows.');
      }

    };
    $service = $this->documentService($failing);
    try {
      $service->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser());
      $this->fail('The draft was stored.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Simulated failure after the appendix rows.', $exception->getMessage());
    }
    $this->assertSame([], $this->repository->list(1));
    $this->assertSame([], $this->rows(CoverCodeRepository::TABLE));
    $this->assertSame([], $this->rows(NachforderungRepository::TABLE));
    $this->assertSame([], $this->rows(NachreichungEventLog::TABLE));
    $this->assertSame([], $this->files());
  }

  /**
   * The release sends letter, then cover sheet, and activates in one go.
   */
  public function testReleaseCarriesTheCoverSheetSecondAndActivates(): void {
    $account = $this->getCurrentUser();
    $older = $this->olderOpenRequest();
    $service = $this->documentService();
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    $service->preview($this->application, $document['id'], $account);
    $this->view($service, $document['id'], $account);

    $released = $service->release($this->application, [$document['id']], $account);

    $files = $released['response']['documents'];
    $this->assertCount(2, $files);
    $this->assertSame(['Nachforderung.pdf', 'Deckblatt Nachforderung.pdf'], array_column($files, 'filename'));
    $row = $this->repository->find(1, $document['id']);
    $stored = $this->responses->find(['uuid' => $released['response']['id']]);
    $this->assertNotNull($stored);
    $codes = $this->rows(CoverCodeRepository::TABLE);
    $this->assertSame('active', $codes[0]['state']);
    $this->assertSame($row['released_at'], $codes[0]['activated_at']);
    // Only the keyed hash: no unkeyed fingerprint of the QR (variant A).
    $this->assertArrayNotHasKey('qr_sha256', $codes[0]);
    $requests = array_column($this->rows(NachforderungRepository::TABLE), NULL, 'uuid');
    $this->assertSame('superseded', $requests[$older]['state']);
    $current = array_values(array_filter($requests, static fn (array $request): bool => $request['uuid'] !== $older))[0];
    $this->assertSame('open', $current['state']);
    $this->assertSame($released['response']['id'], $current['response_uuid']);
    $this->assertSame((string) $account->id(), (string) $current['released_by']);
    $this->assertSame(['cover_issued', 'request_opened', 'request_superseded', 'cover_activated'], array_column($this->rows(NachreichungEventLog::TABLE), 'code'));
    $this->assertNoIdentifierStored();
  }

  /**
   * Two request letters of one release both stay open.
   *
   * They went out in one response with one deadline; only records of
   * earlier releases are superseded.
   */
  public function testLettersOfOneReleaseDoNotSupersedeEachOther(): void {
    $account = $this->getCurrentUser();
    $older = $this->olderOpenRequest();
    $service = $this->documentService();
    $ids = [];
    foreach ([1, 2] as $index) {
      $document = $service->create($this->application, $this->requestInfo->uuid(), 'Nachforderung ' . $index, $account);
      $service->preview($this->application, $document['id'], $account);
      $this->view($service, $document['id'], $account);
      $ids[] = $document['id'];
    }

    $released = $service->release($this->application, $ids, $account);

    $this->assertCount(4, $released['response']['documents']);
    $requests = array_column($this->rows(NachforderungRepository::TABLE), NULL, 'docgen_document_uuid');
    $this->assertSame('superseded', array_column($requests, 'state', 'uuid')[$older]);
    foreach ($ids as $id) {
      $this->assertSame('open', $requests[$id]['state'], $id);
      $this->assertNull($requests[$id]['closed_at'], $id);
      $this->assertSame($released['response']['id'], $requests[$id]['response_uuid']);
    }
    $this->assertSame(['active', 'active'], array_column($this->rows(CoverCodeRepository::TABLE), 'state'));
    $events = array_count_values(array_column($this->rows(NachreichungEventLog::TABLE), 'code'));
    $this->assertSame(['cover_issued' => 2, 'request_opened' => 2, 'request_superseded' => 1, 'cover_activated' => 2], $events);
  }

  /**
   * Reconcile repairs two letters of one release without superseding them.
   */
  public function testReconcileKeepsLettersOfOneReleaseOpen(): void {
    $account = $this->getCurrentUser();
    $older = $this->olderOpenRequest();
    $ids = [];
    foreach ([1, 2] as $index) {
      $ids[] = $this->documentService()->create($this->application, $this->requestInfo->uuid(), 'Nachforderung ' . $index, $account)['id'];
    }
    $service = $this->documentService(FALSE);
    foreach ($ids as $id) {
      $service->preview($this->application, $id, $account);
      $this->view($service, $id, $account);
    }
    $service->release($this->application, $ids, $account);

    $database = $this->container->get('database');
    $requests = new NachforderungRepository($database);
    $events = new NachreichungEventLog($database, $this->container->get('datetime.time'));
    (new NachreichungActivation($database, $this->codes(), $requests, $events, $this->repository, $this->container->get('datetime.time')))->reconcile(1);

    $states = array_column($this->rows(NachforderungRepository::TABLE), 'state', 'docgen_document_uuid');
    $this->assertSame(['open', 'open'], [$states[$ids[0]], $states[$ids[1]]]);
    $this->assertSame('superseded', array_column($this->rows(NachforderungRepository::TABLE), 'state', 'uuid')[$older]);
  }

  /**
   * A late repair of an older release never closes a newer request.
   */
  public function testReconcileOfAnOlderReleaseKeepsTheNewerOpen(): void {
    $account = $this->getCurrentUser();
    $early = $this->documentService()->create($this->application, $this->requestInfo->uuid(), 'Nachforderung alt', $account)['id'];
    $without = $this->documentService(FALSE);
    $without->preview($this->application, $early, $account);
    $this->view($without, $early, $account);
    $without->release($this->application, [$early], $account);
    $this->repository->update($early, ['released_at' => $this->container->get('datetime.time')->getRequestTime() - 3600]);

    $service = $this->documentService();
    $late = $service->create($this->application, $this->requestInfo->uuid(), 'Nachforderung neu', $account)['id'];
    $service->preview($this->application, $late, $account);
    $this->view($service, $late, $account);
    $service->release($this->application, [$late], $account);

    $database = $this->container->get('database');
    $requests = new NachforderungRepository($database);
    $events = new NachreichungEventLog($database, $this->container->get('datetime.time'));
    (new NachreichungActivation($database, $this->codes(), $requests, $events, $this->repository, $this->container->get('datetime.time')))->reconcile(1);

    $states = array_column($this->rows(NachforderungRepository::TABLE), 'state', 'docgen_document_uuid');
    $this->assertSame('open', $states[$late]);
    $this->assertSame((string) $late, (string) $requests->newestWaiting(1)['docgen_document_uuid']);
  }

  /**
   * Activation runs inside the booking: a failure keeps the release resumable.
   */
  public function testActivationFailureRollsBackTheBooking(): void {
    $account = $this->getCurrentUser();
    $failing = new class(...$this->appendixArguments()) extends CoverSheetAppendix {

      /**
       * Whether the next booking fails.
       */
      public static bool $armed = TRUE;

      /**
       * {@inheritdoc}
       */
      public function released(ApplicationInterface $application, array $rows, string $response_uuid, int $now): void {
        parent::released($application, $rows, $response_uuid, $now);
        if (self::$armed) {
          self::$armed = FALSE;
          throw new \RuntimeException('Simulated failure in the booking.');
        }
      }

    };
    $service = $this->documentService($failing);
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    $service->preview($this->application, $document['id'], $account);
    $this->view($service, $document['id'], $account);
    try {
      $service->release($this->application, [$document['id']], $account);
      $this->fail('The booking did not fail.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('Simulated failure in the booking.', $exception->getMessage());
    }
    $this->assertSame('releasing', $this->repository->find(1, $document['id'])['state']);
    $this->assertSame('draft', $this->rows(CoverCodeRepository::TABLE)[0]['state']);
    $this->assertSame('draft', $this->rows(NachforderungRepository::TABLE)[0]['state']);

    // Interrupted releases use the explicit, authority-checked resume path.
    $resumed = $service->resumeRelease($this->application, [$document['id']], $account);
    $this->assertSame('released', $resumed['documents'][0]['state']);
    $this->assertSame('active', $this->rows(CoverCodeRepository::TABLE)[0]['state']);
    $this->assertSame('open', $this->rows(NachforderungRepository::TABLE)[0]['state']);
    $this->assertCount(1, $this->responses->list(1, TRUE));
  }

  /**
   * A letter released without the appendix is repaired by reconcile().
   */
  public function testReconcileActivatesReleasedLetter(): void {
    $account = $this->getCurrentUser();
    $document = $this->documentService()->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    // Released by a service that does not know the appendix (module
    // installed later, or a release booked before the update).
    $service = $this->documentService(FALSE);
    $service->preview($this->application, $document['id'], $account);
    $this->view($service, $document['id'], $account);
    $service->release($this->application, [$document['id']], $account);
    $this->assertSame('draft', $this->rows(CoverCodeRepository::TABLE)[0]['state']);

    $database = $this->container->get('database');
    $requests = new NachforderungRepository($database);
    $events = new NachreichungEventLog($database, $this->container->get('datetime.time'));
    $activation = new NachreichungActivation($database, $this->codes(), $requests, $events, $this->repository, $this->container->get('datetime.time'));
    $activation->reconcile(1);
    $activation->reconcile(1);
    $this->assertSame('active', $this->rows(CoverCodeRepository::TABLE)[0]['state']);
    $this->assertSame('open', $this->rows(NachforderungRepository::TABLE)[0]['state']);
    $this->assertSame(['cover_issued', 'request_opened', 'cover_activated'], array_column($this->rows(NachreichungEventLog::TABLE), 'code'));
  }

  /**
   * Without the switch: a record, no identifier, no sheet, no blocker.
   */
  public function testSwitchOffKeepsTheRecordOnly(): void {
    $this->sections['nachreichung'] = [];
    $service = $this->documentService();
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser());
    $this->assertNull($document['cover']);
    $this->assertNotContains('docgen.cover_sheet_missing', $document['releaseBlockers']);
    $this->assertSame([], $this->rows(CoverCodeRepository::TABLE));
    $this->assertCount(1, $this->rows(NachforderungRepository::TABLE));

    // Switched on later: the draft needs a sheet, a re-render brings it.
    $this->sections['nachreichung'] = ['coverSheet' => TRUE];
    $this->assertContains('docgen.cover_sheet_missing', $service->get($this->application, $document['id'], $this->getCurrentUser())['releaseBlockers']);
    $again = $service->rerender($this->application, $document['id'], $this->getCurrentUser());
    $this->assertNotNull($again['cover']);
    $this->assertNotContains('docgen.cover_sheet_missing', $again['releaseBlockers']);
    $this->assertCount(1, $this->rows(NachforderungRepository::TABLE));
  }

  /**
   * Switch on without a holiday region: refused before anything is stored.
   */
  public function testMissingHolidayRegionRefusesTheDraft(): void {
    unset($this->sections['docgen']['holidayRegion']);
    $this->assertCode('docgen.holiday_region_required', fn () => $this->documentService()->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser()));
    $this->assertSame([], $this->repository->list(1));
    $this->assertSame([], $this->rows(NachforderungRepository::TABLE));
  }

  /**
   * A re-render voids the draft identifier; a discard removes the drafts.
   */
  public function testRerenderVoidsAndDiscardRemoves(): void {
    $service = $this->documentService();
    $account = $this->getCurrentUser();
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    $first = $this->rows(CoverCodeRepository::TABLE)[0];
    $old_cover = (int) $this->repository->find(1, $document['id'])['cover_pdf_file_id'];
    $record = $this->rows(NachforderungRepository::TABLE)[0]['id'];

    $again = $service->rerender($this->application, $document['id'], $account);
    $this->assertSame(2, $again['docx']['revision']);
    $codes = $this->rows(CoverCodeRepository::TABLE);
    $this->assertCount(1, $codes);
    $this->assertNotSame($first['uuid'], $codes[0]['uuid']);
    $this->assertNotSame($first['code_hash'], $codes[0]['code_hash']);
    $this->assertSame([$record], array_column($this->rows(NachforderungRepository::TABLE), 'id'));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('file')->load($old_cover));
    $this->assertContains('rerendered', array_column($this->repository->events($document['id']), 'event'));

    $service->discard($this->application, $document['id']);
    $this->assertSame([], $this->rows(CoverCodeRepository::TABLE));
    $this->assertSame([], $this->rows(NachforderungRepository::TABLE));
    $this->assertSame([], $this->files());
  }

  /**
   * The deadline counts from the release day (question 1).
   */
  public function testOutdatedDraftIsRenderedAgain(): void {
    $service = $this->documentService();
    $account = $this->getCurrentUser();
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    $first = $this->rows(CoverCodeRepository::TABLE)[0]['uuid'];
    $yesterday = $this->container->get('datetime.time')->getRequestTime() - 86400;
    $this->repository->update($document['id'], ['rendered_at' => $yesterday]);
    $this->assertContains('docgen.deadline_outdated', $service->get($this->application, $document['id'], $account)['releaseBlockers']);

    // Rendered source: the preview renders again first.
    $previewed = $service->preview($this->application, $document['id'], $account);
    $this->assertSame([2, 'rendered'], [$previewed['docx']['revision'], $previewed['docx']['source']]);
    $this->assertTrue($previewed['preview']['current']);
    $this->assertNotContains('docgen.deadline_outdated', $previewed['releaseBlockers']);
    $this->assertNotSame($first, $this->rows(CoverCodeRepository::TABLE)[0]['uuid']);

    // Uploaded source: the preview cannot render Word edits again.
    $service->uploadRevision($this->application, $document['id'], 'bearbeitet.docx', DocxWriter::build(['Bearbeitet']), $account);
    $this->repository->update($document['id'], ['rendered_at' => $yesterday]);
    $kept = $this->rows(CoverCodeRepository::TABLE)[0]['uuid'];
    $previewed = $service->preview($this->application, $document['id'], $account);
    $this->assertSame('uploaded', $previewed['docx']['source']);
    $this->assertContains('docgen.deadline_outdated', $previewed['releaseBlockers']);
    // The upload kept the cover sheet: it is not part of the Word file.
    $this->assertNotNull($previewed['cover']);
    $this->assertSame($kept, $this->rows(CoverCodeRepository::TABLE)[0]['uuid']);

    $again = $service->rerender($this->application, $document['id'], $account);
    $this->assertSame('rendered', $again['docx']['source']);
    $this->assertNotContains('docgen.deadline_outdated', $again['releaseBlockers']);
  }

  /**
   * Staff reprint: same renderer, new valid identifier, audited, limited.
   */
  public function testStaffReprintAndRevoke(): void {
    $account = $this->getCurrentUser();
    $service = $this->documentService();
    $document = $service->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    $service->preview($this->application, $document['id'], $account);
    $this->view($service, $document['id'], $account);
    $service->release($this->application, [$document['id']], $account);
    $letter = $this->rows(CoverCodeRepository::TABLE)[0];

    $print = $this->printService($service);
    $printed = $print->print($this->application, $account);
    $this->assertStringStartsWith('%PDF-', $printed['pdf']);
    $this->assertSame('deckblatt-MS-WG-2026-00042.pdf', $printed['filename']);
    $codes = array_column($this->rows(CoverCodeRepository::TABLE), NULL, 'origin');
    $this->assertSame('active', $codes['staff']['state']);
    $this->assertArrayNotHasKey('qr_sha256', $codes['staff']);
    $this->assertSame($this->rows(NachforderungRepository::TABLE)[0]['id'], $codes['staff']['nachforderung_id']);
    // The released letter's identifier is untouched.
    $this->assertSame($letter, $codes['letter']);
    $audit = $this->rows(ProtectedAccessLog::TABLE);
    $this->assertSame([
      'application_uuid' => 'application-one',
      'subject_type' => 'cover_code',
      'subject_uuid' => $codes['staff']['uuid'],
      'reference' => 'MS-WG-2026-00042',
      'channel' => 'cover_printed',
    ], array_intersect_key($audit[0], array_flip([
      'subject_type',
      'subject_uuid',
      'application_uuid',
      'reference',
      'channel',
    ])));

    $card = $this->card($print)->build($this->application, $account);
    $this->assertSame(2, $card['activeCount']);
    $this->assertTrue($card['canPrint']);
    $json = json_encode($card, JSON_THROW_ON_ERROR);
    foreach (['code', 'codeHash', 'qrSha256', 'keyVersion', 'code_hash', 'qr_sha256'] as $key) {
      $this->assertStringNotContainsString('"' . $key . '"', $json);
    }
    $this->assertDoesNotMatchRegularExpression('/[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]\b/', $json);

    $print->revoke($this->application, $codes['staff']['uuid'], 'lost', $account);
    $this->assertSame('revoked', $this->rows(CoverCodeRepository::TABLE)[1]['state']);
    $this->assertCodeException('nachreichung.cover_already_revoked', fn () => $print->revoke($this->application, $codes['staff']['uuid'], 'lost', $account));
    $this->assertCodeException('nachreichung.input_invalid', fn () => $print->revoke($this->application, $codes['letter']['uuid'], 'stolen', $account));
    $this->assertCodeException('nachreichung.cover_not_found', fn () => $print->revoke($this->application, '5b1c6a3e-3f0e-4f43-9a0b-2d8f2f6c9a11', 'lost', $account));

    // Ten valid sheets are the limit.
    for ($index = 0; $index < 9; $index++) {
      $print->print($this->application, $account);
    }
    $this->assertSame(10, $this->codes->activeCount(1));
    try {
      $print->print($this->application, $account);
      $this->fail('An eleventh valid sheet was printed.');
    }
    catch (NachreichungException $exception) {
      $this->assertSame('nachreichung.cover_limit_reached', $exception->errorCode);
      $this->assertSame([409, ['limit' => 10]], [$exception->status, $exception->details]);
    }
    $this->assertSame('nachreichung.cover_limit_reached', $this->card($print)->build($this->application, $account)['printBlocker']);
    $this->assertNoIdentifierStored();
  }

  /**
   * The limit holds when another sheet becomes valid during the render.
   *
   * The lock spans the whole docgen chain and the count is checked again in
   * the storing transaction.
   */
  public function testPrintRechecksTheLimitAfterTheRender(): void {
    $account = $this->getCurrentUser();
    $print = $this->printService($this->documentService());
    for ($index = 0; $index < 9; $index++) {
      $print->print($this->application, $account);
    }
    $audits = count($this->rows(ProtectedAccessLog::TABLE));
    $database = $this->container->get('database');
    $name = 'beantragt_nachreichung:cover:1';
    $this->duringCoverRender = function () use ($database, $name): void {
      // The lock lasts as long as three docgen calls at the client timeout.
      $expire = (float) $database->select('semaphore', 's')->fields('s', ['expire'])->condition('name', $name)->execute()->fetchField();
      $this->assertGreaterThanOrEqual(3 * DocgenClient::DEFAULT_TIMEOUT_SECONDS, $expire - microtime(TRUE));
      // A second clerk's sheet (or a released letter) became valid meanwhile.
      $this->codes()->insert([
        'uuid' => $this->container->get('uuid')->generate(),
        'application_id' => 1,
        'origin' => 'staff',
        'code_hash' => str_repeat('e', 64),
        'key_version' => 1,
        'state' => 'active',
        'created' => 1,
        'activated_at' => 1,
      ]);
    };

    try {
      $print->print($this->application, $account);
      $this->fail('An eleventh valid sheet was printed.');
    }
    catch (NachreichungException $exception) {
      $this->assertSame('nachreichung.cover_limit_reached', $exception->errorCode);
      $this->assertSame([409, ['limit' => 10]], [$exception->status, $exception->details]);
    }
    $this->assertSame(10, $this->codes->activeCount(1));
    $this->assertCount($audits, $this->rows(ProtectedAccessLog::TABLE));
  }

  /**
   * A print whose lock another request took over stores nothing.
   */
  public function testPrintWithLostLockStoresNothing(): void {
    $account = $this->getCurrentUser();
    $print = $this->printService($this->documentService());
    $database = $this->container->get('database');
    $name = 'beantragt_nachreichung:cover:1';
    $other = new DatabaseLockBackend($database);
    $this->duringCoverRender = function () use ($database, $name, $other): void {
      // The lock ran out during the render and a second clerk took it.
      $database->update('semaphore')->fields(['expire' => microtime(TRUE) - 1])->condition('name', $name)->execute();
      $this->assertTrue($other->acquire($name));
    };

    $this->assertCodeException('nachreichung.locked', fn () => $print->print($this->application, $account));
    $this->assertSame([], $this->rows(CoverCodeRepository::TABLE));
    $this->assertSame([], $this->rows(ProtectedAccessLog::TABLE));
    // The second clerk keeps the lock.
    $this->assertFalse($this->container->get('lock')->lockMayBeAvailable($name));
    $other->release($name);
  }

  /**
   * A draft identifier cannot be revoked; an audit failure prints nothing.
   */
  public function testDraftIdentifierAndAuditFailure(): void {
    $account = $this->getCurrentUser();
    $service = $this->documentService();
    $service->create($this->application, $this->requestInfo->uuid(), NULL, $account);
    $draft = $this->rows(CoverCodeRepository::TABLE)[0]['uuid'];
    $print = $this->printService($service);
    $this->assertCodeException('nachreichung.cover_not_active', fn () => $print->revoke($this->application, $draft, 'lost', $account));

    $this->container->get('database')->schema()->dropTable(ProtectedAccessLog::TABLE);
    $this->assertCodeException('nachreichung.audit_unavailable', fn () => $print->print($this->application, $account));
    $this->assertSame(['letter'], array_column($this->rows(CoverCodeRepository::TABLE), 'origin'));

    $this->sections['nachreichung'] = [];
    $this->assertCodeException('nachreichung.cover_sheet_disabled', fn () => $print->print($this->application, $account));
  }

  /**
   * Deletion and anonymization remove every row of the application.
   */
  public function testCleanupRemovesEverything(): void {
    $service = $this->documentService();
    $service->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser());
    (new NachreichungCleanup($this->container->get('database')))->forApplication(1);
    foreach ([CoverCodeRepository::TABLE, NachforderungRepository::TABLE, NachreichungEventLog::TABLE] as $table) {
      $this->assertSame([], $this->rows($table), $table);
    }
  }

  /**
   * No identifier pattern in any stored row, event or log-like column.
   */
  private function assertNoIdentifierStored(): void {
    $tables = [
      CoverCodeRepository::TABLE,
      NachforderungRepository::TABLE,
      NachreichungEventLog::TABLE,
      ProtectedAccessLog::TABLE,
      'beantragt_docgen_event',
    ];
    foreach ($tables as $table) {
      if (!$this->container->get('database')->schema()->tableExists($table)) {
        continue;
      }
      $json = json_encode($this->rows($table), JSON_THROW_ON_ERROR);
      $this->assertDoesNotMatchRegularExpression('/BA1-|[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]\b/', $json, $table);
    }
  }

  /**
   * Older waiting request record of the application.
   *
   * @return string
   *   Its UUID.
   */
  private function olderOpenRequest(): string {
    $uuid = $this->container->get('uuid')->generate();
    (new NachforderungRepository($this->container->get('database')))->insert([
      'uuid' => $uuid,
      'application_id' => 1,
      'channel' => 'letter',
      'created' => 900,
      'released_at' => 900,
      'deadline_printed' => '2027-01-04',
      'deadline' => '2027-01-04',
      'requested' => '[]',
      'state' => 'open',
    ]);
    return $uuid;
  }

  /**
   * The document service with the appendix and docgen doubles.
   *
   * @param \Drupal\beantragt_nachreichung\Service\CoverSheetAppendix|false|null $appendix
   *   Another appendix, FALSE for none, NULL for the one under test.
   */
  private function documentService(CoverSheetAppendix|false|null $appendix = NULL): DocumentService {
    $catalog = $this->createMock(TemplateCatalog::class);
    $catalog->method('bytes')->willReturn('template');
    $letterhead = $this->createMock(LetterheadService::class);
    $letterhead->method('apply')->willReturnCallback(static fn (ApplicationInterface $application, string $bytes): string => 'LH:' . $bytes);
    $builder = $this->createMock(RenderDataBuilder::class);
    $builder->method('build')->willReturn([
      'data' => [],
      'protectedOmitted' => [],
      'protectedValues' => FALSE,
      'dataSha256' => str_repeat('d', 64),
      'schema' => [],
    ]);
    $client = $this->createMock(DocgenClient::class);
    $client->method('inspect')->willReturn(['placeholders' => [], 'loops' => [], 'errors' => []]);
    $client->method('render')->willReturnCallback(static fn (string $template): array => [
      'docx' => $template,
      'sha256' => hash('sha256', $template),
      'missing' => [],
    ]);
    $client->method('finalize')->willReturnCallback(function (string $docx): array {
      $cover = str_starts_with($docx, 'LH:PK');
      if ($cover && $this->duringCoverRender !== NULL) {
        ($this->duringCoverRender)();
      }
      $pdf = "%PDF-1.7\n% " . ($cover ? 'cover ' : 'letter ') . hash('sha256', $docx) . "\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
      return [
        'pdf' => $pdf,
        'sha256' => hash('sha256', $pdf),
        'docxSha256' => hash('sha256', $docx),
        'validation' => [
          'profile' => '2b',
          'compliant' => !($cover && $this->coverNotCompliant),
          'failedRules' => 0,
          'failedChecks' => 0,
          'summary' => '',
        ],
      ];
    });
    $catalog->method('assertClean');
    return $this->service([
      'catalog' => $catalog,
      'letterhead' => $letterhead,
      'dataBuilder' => $builder,
      'client' => $client,
      'templates' => [$this->requestInfo],
      'appendix' => $appendix === FALSE ? NULL : ($appendix ?? $this->appendix),
    ]);
  }

  /**
   * Constructor arguments of the appendix.
   *
   * @return array<int, mixed>
   *   Arguments in order.
   */
  private function appendixArguments(): array {
    $database = $this->container->get('database');
    $events = new NachreichungEventLog($database, $this->container->get('datetime.time'));
    $requests = new NachforderungRepository($database);
    $proofs = new RequestedProofs();
    $provider = $this->createMock(RequestedProofsProviderInterface::class);
    $provider->method('requested')->willReturn([self::REQUESTED]);
    $proofs->addProvider($provider);
    return [
      $this->section(),
      $this->values(),
      $proofs,
      new CoverSheetBuilder($this->hasher()),
      $this->codes(),
      $requests,
      $events,
      new NachreichungActivation($database, $this->codes(), $requests, $events, $this->repository, $this->container->get('datetime.time')),
      $this->manager(),
      $this->container->get('uuid'),
      new NullLogger(),
    ];
  }

  /**
   * The appendix under test.
   */
  private function appendix(): CoverSheetAppendix {
    return new CoverSheetAppendix(...$this->appendixArguments());
  }

  /**
   * Event counts load issued cover codes once for every draft on the page.
   */
  public function testEventCoverRequirementsAreBatchedAndReset(): void {
    $rows = [
      ['uuid' => 'first', 'application_id' => 42, 'purpose' => 'request_info'],
      ['uuid' => 'second', 'application_id' => 42, 'purpose' => 'request_info'],
    ];
    $this->codes()->insert(['uuid' => 'code', 'code_hash' => 'unique', 'docgen_document_uuid' => 'first']);
    $application = $this->createMock(ApplicationInterface::class);
    $section = $this->createMock(NachreichungSectionValidator::class);
    $checks = 0;
    $section->expects($this->exactly(2))->method('coverSheet')->willReturnCallback(static function () use (&$checks): bool {
      $checks++;
      return FALSE;
    });
    $arguments = $this->appendixArguments();
    $arguments[0] = $section;
    $appendix = new CoverSheetAppendix(...$arguments);
    Database::startLog('cover-batch');
    $appendix->withCoverRequirements([42 => $application], $rows, function () use ($appendix, $rows): void {
      $this->assertTrue($appendix->coverRequired($rows[0]));
      $this->assertFalse($appendix->coverRequired($rows[1]));
      $this->assertTrue($appendix->coverRequired($rows[0]));
    });
    $queries = Database::getLog('cover-batch');
    Database::getConnection()->getLogger()->end('cover-batch');
    $this->assertCount(1, $queries);
    $this->assertSame(1, $checks);
    $this->codes()->deleteDrafts('first');
    // Outside the callback the cache is gone and the normal predicate runs.
    $this->assertFalse($appendix->coverRequired($rows[0]));
  }

  /**
   * Identifier repository.
   */
  private function codes(): CoverCodeRepository {
    return $this->codes ??= new CoverCodeRepository($this->container->get('database'), $this->hasher());
  }

  /**
   * Hasher on the test key.
   */
  private function hasher(): CoverCodeHasher {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(1);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return new CoverCodeHasher(new ApplicationDataProtectionService($this->manager(), new NullLogger()), $factory, $this->container->get('state'), new NullLogger());
  }

  /**
   * Section validator reading $this->sections.
   */
  private function section(): NachreichungSectionValidator {
    return new NachreichungSectionValidator($this->jurisdictionConfig());
  }

  /**
   * Request letter values at the fixed request time.
   */
  private function values(): RequestLetterValues {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::REQUEST_TIME);
    return new RequestLetterValues($this->jurisdictionConfig(), $this->container->get('entity_type.manager'), $this->container->get('date.formatter'), $time);
  }

  /**
   * Jurisdiction config double over $this->sections.
   */
  private function jurisdictionConfig(): JurisdictionConfigServiceInterface {
    $config = $this->createMock(JurisdictionConfigServiceInterface::class);
    $config->method('getSection')->willReturnCallback(fn (GroupInterface $group, string $section): array => $this->sections[$section] ?? []);
    return $config;
  }

  /**
   * The staff print service on the document service.
   */
  private function printService(DocumentService $documents): CoverPrintService {
    $database = $this->container->get('database');
    return new CoverPrintService(
      $this->section(),
      $this->values(),
      new CoverSheetBuilder($this->hasher()),
      $this->codes(),
      new NachforderungRepository($database),
      new NachreichungEventLog($database, $this->container->get('datetime.time')),
      $documents,
      new DocgenClient($this->container->get('http_client'), new NullLogger(), ['timeout' => (float) DocgenClient::DEFAULT_TIMEOUT_SECONDS]),
      new ProtectedAccessLog($database, $this->container->get('datetime.time')),
      $database,
      $this->container->get('lock'),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
      new NullLogger(),
    );
  }

  /**
   * The card builder.
   */
  private function card(CoverPrintService $print): NachreichungCard {
    return new NachreichungCard($this->section(), $print, $this->codes(), new NachforderungRepository($this->container->get('database')));
  }

  /**
   * Opens and confirms the preview.
   */
  private function view(DocumentService $service, string $uuid, AccountInterface $account): void {
    $file = $service->pdfFile($this->application, $uuid, $account);
    $service->confirmViewed($this->application, $uuid, hash('sha256', (string) file_get_contents($file->getFileUri())), $account);
  }

  /**
   * Asserts a docgen code.
   */
  private function assertCode(string $code, callable $operation): void {
    try {
      $operation();
      $this->fail('Expected ' . $code);
    }
    catch (DocgenApiException $exception) {
      $this->assertSame($code, $exception->errorCode);
    }
  }

  /**
   * Asserts a nachreichung code.
   */
  private function assertCodeException(string $code, callable $operation): void {
    try {
      $operation();
      $this->fail('Expected ' . $code);
    }
    catch (NachreichungException $exception) {
      $this->assertSame($code, $exception->errorCode);
    }
  }

  /**
   * Rows of a table in insertion order.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  private function rows(string $table): array {
    return $this->container->get('database')->select($table, 't')->fields('t')->orderBy('id')->execute()->fetchAll(FetchAs::Associative);
  }

  /**
   * Document files of the application.
   *
   * @return string[]
   *   URIs.
   */
  private function files(): array {
    $uris = array_map(static fn ($file): string => $file->getFileUri(), $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
    return array_values(array_filter($uris, static fn (string $uri): bool => str_starts_with($uri, DocumentService::DIRECTORY . '/application-one/')));
  }

  /**
   * URI of a file entity.
   */
  private function fileUri(int $id): string {
    return $this->container->get('entity_type.manager')->getStorage('file')->load($id)->getFileUri();
  }

  /**
   * Released request-for-information template.
   */
  private function template(): DocgenTemplateInterface {
    $directory = TemplateCatalog::DIRECTORY . '/' . $this->jurisdiction->id();
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file = $this->container->get('file.repository')->writeData('template', $directory . '/request.docx', FileExists::Error);
    $template = $this->container->get('entity_type.manager')->getStorage('docgen_template')->create([
      'family' => $this->container->get('uuid')->generate(),
      'version' => 1,
      'label' => 'Nachforderung',
      'purpose' => 'request_info',
      'status' => DocgenTemplateInterface::STATUS_RELEASED,
      'field_jurisdiction' => (int) $this->jurisdiction->id(),
      'file' => ['target_id' => $file->id()],
      'sha256' => hash('sha256', 'template'),
      'inspection' => '{"placeholders":[],"loops":[],"errors":[]}',
    ]);
    $template->save();
    assert($template instanceof DocgenTemplateInterface);
    return $template;
  }

  /**
   * Application double with jurisdiction, reference number and email.
   */
  private function applicationDouble(): ApplicationInterface {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('uuid')->willReturn('application-one');
    $application->method('getOwnerId')->willReturn((int) $this->createUser()->id());
    $application->method('getData')->willReturn('{"name":"Erika"}');
    $application->method('getReferenceNumber')->willReturn('MS-WG-2026-00042');
    $application->method('access')->willReturn(TRUE);
    $email = $this->createMock(FieldItemListInterface::class);
    $email->method('isEmpty')->willReturn(FALSE);
    $email->method('__isset')->willReturn(TRUE);
    $email->method('__get')->willReturnCallback(static fn (string $name) => $name === 'value' ? 'erika@example.org' : NULL);
    $jurisdiction = $this->createMock(FieldItemListInterface::class);
    $jurisdiction->method('__get')->willReturnCallback(fn (string $name) => $name === 'entity' ? $this->jurisdiction : NULL);
    $application->method('hasField')->willReturnCallback(static fn (string $name): bool => $name === 'field_applicant_email');
    $application->method('get')->willReturnCallback(static fn (string $name) => $name === 'field_jurisdiction' ? $jurisdiction : $email);
    return $application;
  }

  /**
   * Entity type manager whose application storage returns $this->current.
   */
  protected function manager(): EntityTypeManagerInterface {
    $real = $this->container->get('entity_type.manager');
    $application_storage = $this->createMock(EntityStorageInterface::class);
    $application_storage->method('load')->willReturnCallback(fn () => $this->current);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnCallback(static fn (string $type) => $type === 'application' ? $application_storage : $real->getStorage($type));
    return $manager;
  }

  /**
   * The real response path with test doubles for scan, mail and status.
   */
  protected function responseService(string $scan = AttachmentScanResult::STATUS_CLEAN): ExternalResponseService {
    $manager = $this->manager();
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $scanner = $this->createMock(AttachmentScanServiceInterface::class);
    $scanner->method('scan')->willReturn(new AttachmentScanResult($scan));
    $mailer = $this->createMock(NotificationMailer::class);
    $mailer->method('sendCitizenNotificationWithResult')->willReturn(TRUE);
    return new ExternalResponseService(
      $this->responses,
      $manager,
      $this->container->get('file_system'),
      $this->container->get('file.repository'),
      $scanner,
      new ApplicationDependentMutationLock($manager, $lock),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
      $mailer,
      $this->statusChanger,
      new ApplicationDataProtectionService($manager, new NullLogger(), new ProtectedAccessLog($this->container->get('database'), $this->container->get('datetime.time'))),
    );
  }

  /**
   * Required by the trait: anonymization during a release.
   */
  protected function anonymize(): void {
    $this->current = NULL;
  }

}
