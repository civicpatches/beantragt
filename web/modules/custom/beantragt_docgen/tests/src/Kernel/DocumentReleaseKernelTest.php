<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Session\AccountProxy;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\beantragt_docgen\Controller\DocgenCaseController;
use Psr\Log\NullLogger;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\ProtectedResponseAccessException;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_docgen\Service\RenderDataBuilder;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Two decision documents on one application: one closes, one does not.
 *
 * Covers the release rule (preview of exactly the current DOCX, confirmed
 * as received by the releasing person), separate and joint provision through
 * ExternalResponseService, caseSystemResponded() only for a closing purpose,
 * the hash chain and the immutability of released templates. It also covers
 * the durable "releasing" state and its resume, drafts never persisted for a
 * deleted or anonymized application, the template family lock and the
 * protected-data flag of the provided copy. A resume
 * repeats the closing status change, an anonymization during the release
 * provides nothing, a confirmation counts only for a served PDF, and the
 * applicant is identified positively.
 */
#[Group('beantragt_docgen')]
final class DocumentReleaseKernelTest extends GroupKernelTestBase {

  use \Drupal\Tests\beantragt_docgen\Traits\DocgenReleaseTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file', 'beantragt_docgen'];

  /**
   * Application double that owns the documents.
   */
  private ApplicationInterface&MockObject $application;

  /**
   * Status changer spy.
   */
  private SemanticStatusChanger&MockObject $statusChanger;

  /**
   * Document repository on the test database.
   */
  private DocumentRepository $repository;

  /**
   * External response repository on the test database.
   */
  private ExternalResponseRepository $responses;

  /**
   * Released approval template.
   */
  private DocgenTemplateInterface $approval;

  /**
   * Released request-for-information template.
   */
  private DocgenTemplateInterface $requestInfo;

  /**
   * Application returned by storage (NULL when deleted).
   */
  private ?ApplicationInterface $current = NULL;

  /**
   * The applicant (owner of the application).
   */
  private AccountInterface $applicant;

  /**
   * Applicant email stored on the application double.
   */
  private const APPLICANT_EMAIL = 'erika@example.org';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // KernelTestBase uses a lock that always succeeds; the lock tests need
    // a real one, so a second backend instance can hold a lock.
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
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('docgen_template');
    $this->installSchema('beantragt_docgen', array_keys(DocumentRepository::schema() + DocgenSettingsRepository::schema()));
    $database = $this->container->get('database');
    foreach (ExternalResponseRepository::schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $database->schema()->createTable(ProtectedAccessLog::TABLE, ProtectedAccessLog::schema());
    $this->repository = new DocumentRepository($database);
    $this->responses = new ExternalResponseRepository($database);

    $this->createGroupType(['id' => 'jur']);
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Stadt Musterstadt']);
    $this->approval = $this->template((int) $jurisdiction->id(), 'Bewilligung', 'approval');
    $this->requestInfo = $this->template((int) $jurisdiction->id(), 'Nachforderung', 'request_info');

    $this->applicant = $this->createUser();
    $this->application = $this->applicationDouble('{"name":"Erika"}');
    $this->current = $this->application;
    $this->statusChanger = $this->createMock(SemanticStatusChanger::class);
  }

  /**
   * Separate releases: the request for information never closes.
   */
  public function testTwoDocumentsOnlyTheClosingOneIssuesTheDecision(): void {
    $service = $this->service();
    $account = $this->getCurrentUser();
    $request_info = $this->document($this->requestInfo);
    $approval = $this->document($this->approval);

    // Not opened yet: the release rule blocks.
    try {
      $service->release($this->application, [$request_info], $account);
      self::fail('A preview nobody opened was released.');
    }
    catch (DocgenApiException $exception) {
      self::assertSame('docgen.preview_not_viewed', $exception->errorCode);
    }

    $this->statusChanger->expects(self::once())->method('caseSystemResponded')->with($this->application, 'approved', TRUE, [], 'docgen');

    $this->view($service, $request_info, $account);
    $first = $service->release($this->application, [$request_info], $account);
    self::assertSame('released', $first['documents'][0]['state']);
    self::assertFalse($first['documents'][0]['closesProcedure']);
    // A request for information is a communication: portal, with email.
    self::assertFalse($first['response']['isDecision']);
    self::assertSame('portal', $first['response']['deliveryRoute']);
    $row = $this->responses->find(['uuid' => $first['response']['id']]);
    $state = [(string) $row['is_decision'], $row['delivery_route'], $row['route_reason'], $row['notification_state']];
    self::assertSame(['0', 'portal', NULL, 'sent'], $state);
    self::assertNotNull($row['notification_sent_at']);
    self::assertNull($row['postal_due_at']);
    self::assertCount(1, $first['response']['documents']);
    self::assertSame('released', $first['response']['status']);
    // The approval draft stays open next to the released request.
    self::assertSame('draft', $service->get($this->application, $approval, $account)['state']);

    $this->view($service, $approval, $account);
    $second = $service->release($this->application, [$approval], $account);
    self::assertTrue($second['documents'][0]['closesProcedure']);
    self::assertTrue($second['response']['isDecision']);
    self::assertSame('postal', $second['response']['deliveryRoute']);
    // The decision goes by post: due for dispatch, no email announced.
    $row = $this->responses->find(['uuid' => $second['response']['id']]);
    $state = [(string) $row['is_decision'], $row['delivery_route'], $row['route_reason'], $row['notification_state']];
    self::assertSame(['1', 'postal', 'decision_postal_only', 'postal'], $state);
    self::assertNull($row['notification_sent_at']);
    self::assertNotNull($row['postal_due_at']);
    self::assertNotSame($first['response']['id'], $second['response']['id']);
    self::assertCount(2, $this->responses->list(1, FALSE));
    self::assertTrue($second['documents'][0]['historyIntact']);
    self::assertSame(['created', 'viewed', 'release_started', 'released'], array_column($this->repository->events($approval), 'event'));

    try {
      $service->release($this->application, [$approval], $account);
      self::fail('A released document was released twice.');
    }
    catch (DocgenApiException $exception) {
      self::assertSame('docgen.document_released', $exception->errorCode);
    }
  }

  /**
   * Joint release: one provision with two PDFs, the decision issued once.
   */
  public function testJointReleaseIsOneProvisionAndClosesOnce(): void {
    $service = $this->service();
    $account = $this->getCurrentUser();
    $documents = [$this->document($this->requestInfo), $this->document($this->approval)];
    foreach ($documents as $uuid) {
      $this->view($service, $uuid, $account);
    }
    $this->statusChanger->expects(self::once())->method('caseSystemResponded')->with($this->application, 'approved', TRUE, [], 'docgen');
    $result = $service->release($this->application, $documents, $account);
    self::assertCount(2, $result['documents']);
    self::assertCount(2, $result['response']['documents']);
    self::assertCount(1, $this->responses->list(1, TRUE));
    self::assertSame($result['documents'][0]['releaseId'], $result['documents'][1]['releaseId']);
    // One approval in the bundle makes the provision a decision (OR over the
    // bundle): by post, no email.
    $row = $this->responses->list(1, TRUE)[0];
    self::assertSame(1, (int) $row['is_decision']);
    self::assertSame('postal', $row['delivery_route']);
    self::assertSame('decision_postal_only', $row['route_reason']);
    self::assertSame('postal', $row['notification_state']);
    self::assertNull($row['notification_sent_at']);
    self::assertNotNull($row['postal_due_at']);
    self::assertTrue($result['response']['isDecision']);
    self::assertSame('postal', $result['response']['deliveryRoute']);
  }

  /**
   * The purpose gives the outcome; approval and rejection never mix.
   */
  public function testPurposeDecidesTheOutcomeAndMixedReleasesAreRefused(): void {
    $service = $this->service();
    $account = $this->getCurrentUser();
    $rejection_template = $this->template((int) $this->approval->get('field_jurisdiction')->target_id, 'Ablehnung', 'rejection');
    $approval = $this->document($this->approval);
    $rejection = $this->document($rejection_template);
    foreach ([$approval, $rejection] as $uuid) {
      $this->view($service, $uuid, $account);
    }
    $outcomes = [];
    $this->statusChanger->expects(self::once())->method('caseSystemResponded')
      ->willReturnCallback(static function (ApplicationInterface $application, ?string $outcome) use (&$outcomes): bool {
        $outcomes[] = $outcome;
        return TRUE;
      });
    // Mixed: refused before anything changes.
    $mixed = [$approval, $rejection];
    $this->assertCode('docgen.release_outcome_conflict', fn () => $service->release($this->application, $mixed, $account));
    foreach ([$approval, $rejection] as $uuid) {
      $row = $this->repository->find(1, $uuid);
      self::assertSame(DocumentRepository::STATE_DRAFT, $row['state']);
      self::assertNull($row['release_uuid']);
    }
    self::assertSame([], $this->responses->list(1, TRUE));
    // Alone, the rejection closes with the outcome "rejected".
    $result = $service->release($this->application, [$rejection], $account);
    self::assertSame(['rejected'], $outcomes);
    self::assertSame('rejected', $result['response']['outcome']);
    self::assertSame('decision', $result['response']['kind']);
  }

  /**
   * A preview of an older DOCX can never be released.
   */
  public function testStalePreviewAndTamperedHistoryAreDetected(): void {
    $service = $this->service();
    $account = $this->getCurrentUser();
    $uuid = $this->document($this->approval);
    $this->view($service, $uuid, $account);
    $this->repository->update($uuid, ['docx_sha256' => str_repeat('a', 64)]);
    $this->statusChanger->expects(self::never())->method('caseSystemResponded');
    try {
      $service->release($this->application, [$uuid], $account);
      self::fail('A stale preview was released.');
    }
    catch (DocgenApiException $exception) {
      self::assertSame('docgen.preview_stale', $exception->errorCode);
    }
    self::assertTrue($this->repository->verifyChain($uuid));
    $this->container->get('database')->update('beantragt_docgen_event')
      ->fields(['uid' => 99])->condition('document_uuid', $uuid)->condition('event', 'created')->execute();
    self::assertFalse($this->repository->verifyChain($uuid));
    self::assertSame([], $this->responses->list(1, TRUE));
  }

  /**
   * Openings of a preview keep the event chain linear.
   */
  public function testPreviewViewsKeepTheChainLinear(): void {
    $service = $this->service();
    $uuid = $this->document($this->approval);
    $first = $this->getCurrentUser();
    $this->view($service, $uuid, $first);
    $this->view($service, $uuid, $first);
    $second = $this->createUser();
    $this->view($service, $uuid, $second);
    $events = $this->repository->events($uuid);
    self::assertSame(['created', 'viewed', 'viewed'], array_column($events, 'event'));
    self::assertSame([(int) $first->id(), (int) $second->id()], array_map('intval', array_column(array_slice($events, 1), 'uid')));
    self::assertTrue($this->repository->verifyChain($uuid));

    // A second event that continues the same link is refused by the
    // database instead of forking the chain.
    $last = end($events);
    try {
      $this->container->get('database')->insert('beantragt_docgen_event')->fields([
        'document_uuid' => $uuid,
        'application_id' => 1,
        'event' => 'viewed',
        'uid' => 99,
        'created' => 1000,
        'previous_hash' => $events[1]['chain_hash'],
        'chain_hash' => str_repeat('f', 64),
      ])->execute();
      self::fail('A forked chain link was stored.');
    }
    catch (IntegrityConstraintViolationException) {
      // Expected: UNIQUE(document_uuid, previous_hash).
    }
    self::assertSame($last['chain_hash'], $this->repository->events($uuid)[2]['chain_hash']);
    self::assertTrue($this->repository->verifyChain($uuid));
  }

  /**
   * A deleted scope takes its unused templates along.
   */
  public function testPurgeScopeRemovesUnusedReleasedTemplates(): void {
    $this->document($this->approval);
    $request_file = $this->requestInfo->getFile();
    self::assertNotNull($request_file);
    $catalog = $this->container->get('beantragt_docgen.template_catalog');
    // Organisation scope with no templates: nothing of the jurisdiction goes.
    self::assertSame([], $catalog->purgeScope((int) $this->approval->get('field_jurisdiction')->target_id, 'org'));
    self::assertNotNull($this->container->get('entity_type.manager')->getStorage('docgen_template')->loadUnchanged($this->requestInfo->id()));
    $kept = $catalog->purgeScope((int) $this->approval->get('field_jurisdiction')->target_id, 'jur');
    self::assertSame([(int) $this->approval->id()], $kept);
    $storage = $this->container->get('entity_type.manager')->getStorage('docgen_template');
    self::assertNull($storage->loadUnchanged($this->requestInfo->id()));
    self::assertNull($this->container->get('entity_type.manager')->getStorage('file')->loadUnchanged($request_file->id()));
    self::assertNotNull($storage->loadUnchanged($this->approval->id()));
    // Outside a purge, released versions still cannot be deleted.
    self::assertFalse(TemplateCatalog::isPurging((int) $this->approval->id()));
  }

  /**
   * Released template versions are immutable and cannot be deleted.
   */
  public function testReleasedTemplateIsImmutable(): void {
    $this->approval->set('label', 'Changed');
    try {
      $this->approval->save();
      self::fail('A released template changed.');
    }
    catch (EntityStorageException $exception) {
      self::assertInstanceOf(\LogicException::class, $exception->getPrevious());
    }
    $reloaded = $this->container->get('entity_type.manager')->getStorage('docgen_template')->loadUnchanged($this->approval->id());
    self::assertSame('Bewilligung', $reloaded->label());
    try {
      $reloaded->delete();
      self::fail('A released template was deleted.');
    }
    catch (EntityStorageException $exception) {
      self::assertInstanceOf(\LogicException::class, $exception->getPrevious());
    }
    self::assertNotNull($this->container->get('entity_type.manager')->getStorage('docgen_template')->loadUnchanged($this->approval->id()));
  }

  /**
   * Serving the PDF records nothing; only the confirmed hash counts.
   */
  public function testOnlyConfirmedDownloadCountsAsViewed(): void {
    $service = $this->service();
    $account = $this->getCurrentUser();
    $uuid = $this->document($this->approval);
    $service->pdfFile($this->application, $uuid, $account);
    self::assertFalse($service->get($this->application, $uuid, $account)['preview']['viewedByYou']);
    self::assertSame(['created'], array_column($this->repository->events($uuid), 'event'));
    $this->assertCode('docgen.preview_stale', fn () => $service->confirmViewed($this->application, $uuid, str_repeat('0', 64), $account));
    $this->assertCode('docgen.input_invalid', fn () => $service->confirmViewed($this->application, $uuid, 'not-a-hash', $account));
    // The correct hash (shown in the projection) proves nothing on its own:
    // the PDF must have been served to this very person.
    $colleague = $this->createUser();
    $hash = (string) $this->repository->find(1, $uuid)['pdf_sha256'];
    $this->assertCode('docgen.preview_not_viewed', fn () => $service->confirmViewed($this->application, $uuid, $hash, $colleague));
    self::assertFalse($service->get($this->application, $uuid, $colleague)['preview']['viewedByYou']);
    $document = $this->view($service, $uuid, $account);
    self::assertTrue($document['preview']['viewedByYou']);
    self::assertTrue($document['canRelease']);
  }

  /**
   * Protected render fingerprints stay hidden from readers without permission.
   */
  public function testProtectedDataFingerprintIsHidden(): void {
    $service = $this->service();
    $uuid = $this->document($this->approval, TRUE);
    $this->repository->update($uuid, [
      'data_sha256' => str_repeat('a', 64),
      'cover_pdf_file_id' => 1,
      'cover_pdf_sha256' => str_repeat('b', 64),
    ]);
    $row = $this->repository->find(1, $uuid);
    $projections = [
      $service->get($this->application, $uuid, $this->account(77, FALSE)),
      $service->listDocuments($this->application, $this->account(77, FALSE))[0],
    ];
    foreach ($projections as $hidden) {
      self::assertNull($hidden['dataSha256']);
      self::assertNull($hidden['docx']['sha256']);
      self::assertNull($hidden['preview']['sha256']);
      self::assertNull($hidden['preview']['docxSha256']);
      self::assertNull($hidden['cover']['sha256']);
    }
    $visible = $service->get($this->application, $uuid, $this->account(78, TRUE));
    self::assertSame($row['data_sha256'], $visible['dataSha256']);
    self::assertSame($row['docx_sha256'], $visible['docx']['sha256']);
    self::assertSame($row['pdf_sha256'], $visible['preview']['sha256']);
    self::assertSame($row['pdf_docx_sha256'], $visible['preview']['docxSha256']);
    self::assertSame($row['cover_pdf_sha256'], $visible['cover']['sha256']);
    $plain = $this->document($this->requestInfo);
    self::assertNotNull($service->get($this->application, $plain, $this->account(77, FALSE))['docx']['sha256']);
  }

  /**
   * Both new releases and explicit resumes recheck current release authority.
   */
  public function testReleaseAndResumeRecheckAuthority(): void {
    $allowed = TRUE;
    $scope = $this->createMock(DocgenScopeAccess::class);
    $scope->method('canWorkOnCase')->willReturnCallback(static function (ApplicationInterface $application, AccountInterface $account, string $permission, bool $write) use (&$allowed): bool {
      self::assertSame(DocgenScopeAccess::PERMISSION_RELEASE, $permission);
      self::assertTrue($write);
      return $allowed;
    });
    $service = $this->service(['scope' => $scope, 'failOnReleased' => TRUE]);
    $account = $this->getCurrentUser();
    $uuid = $this->document($this->approval);
    $this->view($service, $uuid, $account);
    $allowed = FALSE;
    $this->assertCode('docgen.access_denied', fn () => $service->release($this->application, [$uuid], $account));
    self::assertSame('draft', $this->repository->find(1, $uuid)['state']);
    self::assertSame([], $this->responses->list(1, TRUE));
    $allowed = TRUE;
    try {
      $service->release($this->application, [$uuid], $account);
      self::fail('Expected simulated bookkeeping failure.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('Simulated crash after the provision.', $exception->getMessage());
    }
    $this->assertCode('docgen.document_releasing', fn () => $service->release($this->application, [$uuid], $account));
    $allowed = FALSE;
    $this->assertCode('docgen.access_denied', fn () => $service->resumeRelease($this->application, [$uuid], $account));
    self::assertSame('releasing', $this->repository->find(1, $uuid)['state']);
    $allowed = TRUE;
    self::assertSame('released', $service->resumeRelease($this->application, [$uuid], $account)['documents'][0]['state']);
    self::assertCount(1, $this->responses->list(1, TRUE));
  }

  /**
   * A crash after the provision leaves "releasing"; a resume ends it.
   */
  public function testInterruptedReleaseStaysReleasingAndResumesOnce(): void {
    $service = $this->service(['failOnReleased' => TRUE]);
    $releaser = $this->getCurrentUser();
    $approval = $this->document($this->approval);
    $other = $this->document($this->requestInfo);
    $this->view($service, $approval, $releaser);
    // First by the releasing person; the resume repeats it (idempotent in
    // the real changer) as a system change, since a colleague resumes.
    $calls = [];
    $this->statusChanger->expects(self::exactly(2))->method('caseSystemResponded')
      ->willReturnCallback(static function (ApplicationInterface $application, ?string $outcome, bool $by_person, array $follow_up, string $source) use (&$calls): bool {
        $calls[] = $by_person;
        self::assertSame('approved', $outcome);
        self::assertSame('docgen', $source);
        return TRUE;
      });

    try {
      $service->release($this->application, [$approval], $releaser);
      self::fail('The simulated crash between provision and bookkeeping did not happen.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('Simulated crash after the provision.', $exception->getMessage());
    }
    // Provided once, booked as releasing: no longer a draft.
    self::assertCount(1, $this->responses->list(1, TRUE));
    $row = $this->repository->find(1, $approval);
    self::assertSame(DocumentRepository::STATE_RELEASING, $row['state']);
    self::assertSame((int) $releaser->id(), (int) $row['released_by']);
    $projection = $service->get($this->application, $approval, $releaser);
    self::assertSame('releasing', $projection['state']);
    self::assertTrue($projection['canRelease']);

    // Neither editable nor discardable, nor mixable with a draft.
    $this->assertCode('docgen.document_releasing', fn () => $service->discard($this->application, $approval));
    $this->assertCode('docgen.document_releasing', fn () => $service->uploadRevision($this->application, $approval, 'neu.docx', DocxWriter::build(['x']), $releaser));
    $this->assertCode('docgen.document_releasing', fn () => $service->preview($this->application, $approval, $releaser));
    $this->assertCode('docgen.document_releasing', fn () => $service->release($this->application, [$approval, $other], $releaser));

    // Another person with the release permission finishes it, without a
    // view of their own: the decision stays with the first person.
    $colleague = $this->createUser();
    $result = $service->resumeRelease($this->application, [$approval], $colleague);
    self::assertSame('released', $result['documents'][0]['state']);
    self::assertSame($releaser->getDisplayName(), $result['documents'][0]['releasedBy']);
    self::assertCount(1, $this->responses->list(1, TRUE), 'The resume provided a second time.');
    self::assertSame($result['response']['id'], $result['documents'][0]['responseId']);
    self::assertSame(['created', 'viewed', 'release_started', 'release_resumed', 'released'], array_column($this->repository->events($approval), 'event'));
    self::assertTrue($this->repository->verifyChain($approval));
    self::assertSame([TRUE, FALSE], $calls);
    $this->assertCode('docgen.document_released', fn () => $service->release($this->application, [$approval], $releaser));
  }

  /**
   * The HTTP handler resumes a provisioned release without a second response.
   */
  public function testHttpReleaseResumesProvisionedDocument(): void {
    $service = $this->service(['failOnReleased' => TRUE]);
    $uuid = $this->document($this->approval);
    $this->view($service, $uuid, $this->getCurrentUser());
    try {
      $service->release($this->application, [$uuid], $this->getCurrentUser());
      self::fail('Expected crash after provision.');
    }
    catch (\RuntimeException $error) {
      self::assertSame('Simulated crash after the provision.', $error->getMessage());
    }
    self::assertSame('releasing', $this->repository->find(1, $uuid)['state']);
    self::assertCount(1, $this->responses->list(1, TRUE));
    $scope = $this->createMock(DocgenScopeAccess::class);
    $scope->method('canWorkOnCase')->willReturn(TRUE);
    $account = new AccountProxy(new EventDispatcher());
    $account->setAccount($this->getCurrentUser());
    $csrf = $this->createMock(CsrfTokenGenerator::class);
    $csrf->method('validate')->willReturn(TRUE);
    $controller = new DocgenCaseController(
      $scope, $account, $csrf, new NullLogger(), $service,
      $this->createMock(FloodInterface::class),
    );
    $request = Request::create(
      '/api/admin/applications/1/docgen/release', 'POST', [], [], [],
      ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'synthetic'],
      json_encode(['data' => ['documents' => [$uuid, strtoupper($uuid), $uuid]]], JSON_THROW_ON_ERROR),
    );
    $response = $controller->release($request, $this->application);
    self::assertSame(200, $response->getStatusCode(), $response->getContent());
    self::assertSame('released', json_decode($response->getContent(), TRUE)['data']['documents'][0]['state']);
    self::assertCount(1, $this->responses->list(1, TRUE));
    self::assertSame('released', $this->repository->find(1, $uuid)['state']);
  }

  /**
   * Stored, but the status change was lost: the resume closes.
   */
  public function testResumeRepeatsTheLostStatusChange(): void {
    $service = $this->service();
    $releaser = $this->getCurrentUser();
    $approval = $this->document($this->approval);
    $this->view($service, $approval, $releaser);
    $calls = 0;
    $this->statusChanger->expects(self::exactly(2))->method('caseSystemResponded')
      ->with($this->application, 'approved', TRUE, [], 'docgen')
      ->willReturnCallback(static function () use (&$calls): bool {
        if (++$calls === 1) {
          throw new \RuntimeException('Simulated crash while closing the procedure.');
        }
        return TRUE;
      });

    $this->assertCode('docgen.delivery_failed', fn () => $service->release($this->application, [$approval], $releaser));
    // Provided once, the procedure still open: the document stays releasing.
    self::assertCount(1, $this->responses->list(1, TRUE));
    self::assertSame(DocumentRepository::STATE_RELEASING, $this->repository->find(1, $approval)['state']);

    // The resume by the same person finds the stored response and closes.
    $result = $service->resumeRelease($this->application, [$approval], $releaser);
    self::assertSame('released', $result['documents'][0]['state']);
    self::assertCount(1, $this->responses->list(1, TRUE));
    self::assertSame(2, $calls);
  }

  /**
   * Anonymized while releasing: no copy, no mail, back to draft.
   */
  public function testAnonymizationDuringReleaseProvidesNothing(): void {
    $service = $this->service(['anonymizeOnReleasing' => TRUE]);
    $account = $this->getCurrentUser();
    $uuid = $this->document($this->approval);
    $this->view($service, $uuid, $account);
    $this->statusChanger->expects(self::never())->method('caseSystemResponded');

    $this->assertCode('docgen.application_anonymized', fn () => $service->release($this->application, [$uuid], $account));
    self::assertSame([], $this->responses->list(1, TRUE));
    self::assertDirectoryDoesNotExist('private://external_responses/application-one');
    $row = $this->repository->find(1, $uuid);
    self::assertSame(DocumentRepository::STATE_DRAFT, $row['state']);
    self::assertSame(['created', 'viewed', 'release_started', 'release_aborted'], array_column($this->repository->events($uuid), 'event'));
  }

  /**
   * A provision that stored nothing returns the documents to draft.
   */
  public function testFailedProvisionReturnsToDraft(): void {
    $service = $this->service(['scan' => AttachmentScanResult::STATUS_QUARANTINED]);
    $account = $this->getCurrentUser();
    $uuid = $this->document($this->approval);
    $this->view($service, $uuid, $account);
    $this->statusChanger->expects(self::never())->method('caseSystemResponded');
    $this->assertCode('docgen.scan_failed', fn () => $service->release($this->application, [$uuid], $account));
    $row = $this->repository->find(1, $uuid);
    self::assertSame(DocumentRepository::STATE_DRAFT, $row['state']);
    self::assertNull($row['release_uuid']);
    self::assertSame([], $this->responses->list(1, TRUE));
    self::assertSame(['created', 'viewed', 'release_started', 'release_aborted'], array_column($this->repository->events($uuid), 'event'));
    // The view still counts: the person can release once the cause is gone.
    self::assertNotContains('docgen.preview_not_viewed', $service->get($this->application, $uuid, $account)['releaseBlockers']);
  }

  /**
   * No draft content for a deleted, anonymized or locked application.
   */
  public function testDraftsAreStoredOnlyUnderTheApplicationLock(): void {
    $account = $this->getCurrentUser();
    $anonymized = $this->applicationDouble('{"_anonymized":true}');
    $uuid = $this->document($this->approval);
    $before = $this->repository->find(1, $uuid);
    $files = $this->files();

    // Preview: the application is deleted while docgen converts.
    $client = $this->createMock(DocgenClient::class);
    $client->method('finalize')->willReturnCallback(function () use ($before): array {
      $this->current = NULL;
      return [
        'pdf' => "%PDF-1.7\n% new\n%%EOF",
        'sha256' => hash('sha256', "%PDF-1.7\n% new\n%%EOF"),
        'docxSha256' => $before['docx_sha256'],
        'validation' => [
          'profile' => '2b',
          'compliant' => TRUE,
          'failedRules' => 0,
          'failedChecks' => 0,
          'summary' => '',
        ],
      ];
    });
    $service = $this->service(['client' => $client]);
    $this->assertCode('docgen.application_not_found', fn () => $service->preview($this->application, $uuid, $account));

    // Upload: the retention run anonymizes it during the malware scan.
    $this->current = $this->application;
    $catalog = $this->createMock(TemplateCatalog::class);
    $catalog->method('assertClean')->willReturnCallback(function () use ($anonymized): void {
      $this->current = $anonymized;
    });
    $service = $this->service(['catalog' => $catalog]);
    $this->assertCode('docgen.application_anonymized', fn () => $service->uploadRevision($this->application, $uuid, 'neu.docx', DocxWriter::build(['x']), $account));

    // Create: anonymized while docgen renders.
    $this->current = $this->application;
    $render = $this->createMock(DocgenClient::class);
    $render->method('render')->willReturnCallback(function () use ($anonymized): array {
      $this->current = $anonymized;
      return ['docx' => 'rendered docx', 'sha256' => hash('sha256', 'rendered docx'), 'missing' => []];
    });
    $builder = $this->createMock(RenderDataBuilder::class);
    $builder->method('build')->willReturn([
      'data' => [],
      'protectedOmitted' => [],
      'protectedValues' => FALSE,
      'dataSha256' => str_repeat('d', 64),
      'schema' => [],
    ]);
    $service = $this->service(['client' => $render, 'dataBuilder' => $builder, 'templates' => [$this->approval]]);
    $this->assertCode('docgen.application_anonymized', fn () => $service->create($this->application, $this->approval->uuid(), NULL, $account));

    // Another process holds the application lock (deletion, save, delivery).
    $this->current = $this->application;
    $render = $this->createMock(DocgenClient::class);
    $render->method('render')->willReturn([
      'docx' => 'rendered docx',
      'sha256' => hash('sha256', 'rendered docx'),
      'missing' => [],
    ]);
    $other = new DatabaseLockBackend($this->container->get('database'));
    self::assertTrue($other->acquire('beantragt_application.delete.1', 60.0));
    $service = $this->service(['client' => $render, 'dataBuilder' => $builder, 'templates' => [$this->approval]]);
    try {
      $this->assertCode('docgen.locked', fn () => $service->create($this->application, $this->approval->uuid(), NULL, $account));
    }
    finally {
      $other->release('beantragt_application.delete.1');
    }

    // Nothing was written: the one draft and its files are unchanged.
    self::assertCount(1, $this->repository->list(1));
    self::assertSame($before, $this->repository->find(1, $uuid));
    self::assertSame(['created'], array_column($this->repository->events($uuid), 'event'));
    self::assertSame($files, $this->files());

    // With the application in place the same draft is stored.
    $created = $this->service(['client' => $render, 'dataBuilder' => $builder, 'templates' => [$this->approval]])->create($this->application, $this->approval->uuid(), NULL, $account);
    self::assertSame('draft', $created['state']);
    self::assertCount(2, $this->repository->list(1));
  }

  /**
   * A stale template instance can never delete a released version.
   */
  public function testTemplateDeletionUsesTheFreshVersionUnderTheFamilyLock(): void {
    $catalog = $this->container->get('beantragt_docgen.template_catalog');
    $jurisdiction = (int) $this->approval->get('field_jurisdiction')->target_id;
    $draft = $this->template($jurisdiction, 'Ablehnung', 'rejection', DocgenTemplateInterface::STATUS_DRAFT);
    $storage = $this->container->get('entity_type.manager')->getStorage('docgen_template');
    $stale = $storage->loadUnchanged($draft->id());
    self::assertFalse($stale->isReleased());

    // A second request releases the draft.
    $catalog->release($storage->loadUnchanged($draft->id()), $this->getCurrentUser());
    // The first request still holds the draft instance.
    $this->assertCode('docgen.template_immutable', fn () => $catalog->deleteDraft($stale));
    $this->assertCode('docgen.template_immutable', fn () => $catalog->updateDraft($stale, 'Neu', NULL));
    $reloaded = $storage->loadUnchanged($draft->id());
    self::assertTrue($reloaded->isReleased());
    self::assertNotNull($reloaded->getFile());
    self::assertFileExists($reloaded->getFile()->getFileUri());

    // A release with a stale instance of a deleted draft finds nothing.
    $gone = $this->template($jurisdiction, 'Anhörung', 'acknowledgement', DocgenTemplateInterface::STATUS_DRAFT);
    $stale_gone = $storage->loadUnchanged($gone->id());
    $catalog->deleteDraft($storage->loadUnchanged($gone->id()));
    $this->assertCode('docgen.template_not_found', fn () => $catalog->release($stale_gone, $this->getCurrentUser()));

    // The family lock of an upload or release in progress blocks deletion.
    $other = new DatabaseLockBackend($this->container->get('database'));
    $third = $this->template($jurisdiction, 'Nachweis', 'other', DocgenTemplateInterface::STATUS_DRAFT);
    self::assertTrue($other->acquire('beantragt_docgen:template_family:' . $third->getFamily(), 60.0));
    try {
      $this->assertCode('docgen.locked', fn () => $catalog->deleteDraft($third));
    }
    finally {
      $other->release('beantragt_docgen:template_family:' . $third->getFamily());
    }
    self::assertNotNull($storage->loadUnchanged($third->id()));
  }

  /**
   * The provided copy keeps the protection of its draft.
   */
  public function testProvidedCopyOfProtectedNoticeNeedsThePermission(): void {
    $service = $this->service();
    $permitted = $this->account((int) $this->getCurrentUser()->id(), TRUE);
    $uuid = $this->document($this->approval, TRUE);
    // A person without the permission cannot even confirm the preview.
    $this->assertCode('docgen.access_denied', fn () => $service->confirmViewed($this->application, $uuid, $this->repository->find(1, $uuid)['pdf_sha256'], $this->account(77, FALSE)));
    $this->view($service, $uuid, $permitted);
    $result = $service->release($this->application, [$uuid], $permitted);
    $response = $result['response'];
    $file = $response['documents'][0]['id'];
    self::assertSame('1', (string) $this->responses->find(['uuid' => $response['id']])['contains_protected']);

    $verified_applicant = $this->account(79, FALSE, FALSE, strtoupper(self::APPLICANT_EMAIL));
    $mailbox = $this->createMock(ConfirmedApplicationMailbox::class);
    $mailbox->method('allowsRead')->willReturnCallback(static fn (ApplicationInterface $application, AccountInterface $reader): bool => $reader === $verified_applicant);
    $responses = $this->responseService(confirmed_mailbox: $mailbox);
    // Staff without the permission: refused, nothing logged.
    try {
      $responses->download($this->application, $response['id'], $file, TRUE, $this->account(77, FALSE));
      self::fail('Staff without the protected-data permission read the provided copy.');
    }
    catch (ProtectedResponseAccessException) {
      // Expected.
    }
    self::assertSame([], $this->accessLog());
    // Staff with the permission: served and logged.
    self::assertFileExists($responses->download($this->application, $response['id'], $file, TRUE, $this->account(78, TRUE))->getFileUri());
    self::assertSame([['78', 'external_response_download']], $this->accessLog());
    // The applicant reads their own notice as before, without a log entry:
    // as owner, and as the verified applicant of a public submission (view
    // access by email, no staff view permission).
    self::assertFileExists($responses->download($this->application, $response['id'], $file, FALSE, $this->applicant)->getFileUri());
    self::assertFileExists($responses->download($this->application, $response['id'], $file, FALSE, $verified_applicant)->getFileUri());
    self::assertCount(1, $this->accessLog());
    // The same address without mailbox provenance grants no applicant access.
    try {
      $responses->download($this->application, $response['id'], $file, FALSE, $this->account(82, FALSE, FALSE, self::APPLICANT_EMAIL));
      self::fail('An unverified address read the protected copy as applicant.');
    }
    catch (ProtectedResponseAccessException) {
      // Expected under the SSO verified-mail contract.
    }
    self::assertCount(1, $this->accessLog());
    // Without staff permissions, but neither owner nor applicant email (view
    // access granted some other way): staff, not the applicant.
    try {
      $responses->download($this->application, $response['id'], $file, FALSE, $this->account(81, FALSE, FALSE, 'someone@example.org'));
      self::fail('An account that is not the applicant read the protected copy as applicant.');
    }
    catch (ProtectedResponseAccessException) {
      // Expected.
    }
    self::assertCount(1, $this->accessLog());
    // Staff reading through the applicant's path are still staff.
    try {
      $responses->download($this->application, $response['id'], $file, FALSE, $this->account(80, FALSE));
      self::fail('Staff without delivery access read the protected copy through the applicant path.');
    }
    catch (ProtectedResponseAccessException) {
      // Expected.
    }

    // A notice without protected values stays unrestricted.
    $plain = $this->document($this->requestInfo);
    $this->view($service, $plain, $permitted);
    $other = $service->release($this->application, [$plain], $permitted)['response'];
    self::assertSame('0', (string) $this->responses->find(['uuid' => $other['id']])['contains_protected']);
    self::assertFileExists($responses->download($this->application, $other['id'], $other['documents'][0]['id'], TRUE, $this->account(77, FALSE))->getFileUri());
  }

  /**
   * Only protected placeholders actually used by the template are gaps.
   */
  public function testProtectedOmissionsAreBoundedToRenderedMissingTags(): void {
    $client = $this->createMock(DocgenClient::class);
    $client->method('render')->willReturn([
      'docx' => 'rendered request',
      'sha256' => hash('sha256', 'rendered request'),
      'missing' => ['antrag.used', 'vorgang.status'],
    ]);
    $builder = $this->createMock(RenderDataBuilder::class);
    $builder->method('build')->willReturn([
      'data' => [],
      'protectedOmitted' => ['antrag.used', 'antrag.unused'],
      'protectedValues' => FALSE,
      'dataSha256' => str_repeat('d', 64),
      'schema' => [],
    ]);
    $service = $this->service(['client' => $client, 'dataBuilder' => $builder, 'templates' => [$this->requestInfo]]);
    $created = $service->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser());
    $row = $this->repository->find(1, $created['id']);
    $flags = json_decode($row['missing'], TRUE);
    self::assertSame(['antrag.used'], $flags['protectedOmitted']);
    self::assertSame(['antrag.used', 'vorgang.status'], $flags['missing']);
  }

  /**
   * Critical request placeholders cannot produce an incomplete letter.
   */
  public function testMissingRequestDetailsPreventPersistence(): void {
    foreach ([
      'empfaenger.anschrift', 'nachforderung.frist', 'nachforderung.mitwirkungshinweis', 'vorgang.eingang', 'vorgang.fehlende_nachweise',
    ] as $missing) {
      $client = $this->createMock(DocgenClient::class);
      $client->method('render')->willReturn([
        'docx' => 'incomplete letter',
        'sha256' => hash('sha256', 'incomplete letter'),
        'missing' => [$missing],
      ]);
      $builder = $this->createMock(RenderDataBuilder::class);
      $builder->method('build')->willReturn([
        'data' => [],
        'protectedOmitted' => [],
        'protectedValues' => FALSE,
        'dataSha256' => str_repeat('d', 64),
        'schema' => [],
      ]);
      $service = $this->service(['client' => $client, 'dataBuilder' => $builder, 'templates' => [$this->requestInfo]]);
      $this->assertCode('docgen.request_details_missing', fn() => $service->create($this->application, $this->requestInfo->uuid(), NULL, $this->getCurrentUser()));
      $this->assertSame([], $this->repository->list(1));
    }
  }

  /**
   * Draft policy blocks release; committed release observers cannot undo it.
   */
  public function testCaseExtensionBlocksDraftButNotAnOngoingRelease(): void {
    $stopped = TRUE;
    $extension = $this->createMock(DocgenCaseExtensionInterface::class);
    $extension->method('releaseBlockers')->willReturnCallback(static function () use (&$stopped): array {
      return $stopped ? ['docgen.agents_stopped'] : [];
    });
    $extension->expects(self::once())->method('released')->willReturnCallback(function ($application, array $rows): void {
      self::assertSame($this->application, $application);
      self::assertSame((int) $this->getCurrentUser()->id(), (int) $rows[0]['released_by']);
      self::assertSame('released', $this->repository->find(1, $rows[0]['uuid'])['state']);
      throw new \RuntimeException('sensitive observer detail');
    });
    $service = $this->service(['extensions' => $extension]);
    $uuid = $this->document($this->requestInfo);
    $account = $this->getCurrentUser();
    $this->view($service, $uuid, $account);
    self::assertContains('docgen.agents_stopped', $service->get($this->application, $uuid, $account)['releaseBlockers']);
    $this->assertCode('docgen.agents_stopped', fn() => $service->release($this->application, [$uuid], $account));
    $row = $this->repository->find(1, $uuid);
    $row['state'] = DocumentRepository::STATE_RELEASING;
    self::assertSame([], $service->releaseBlockers($row, $account));
    $stopped = FALSE;
    self::assertSame('released', $service->release($this->application, [$uuid], $account)['documents'][0]['state']);
  }

  /**
   * The batch reader returns every state but no content and no other cases.
   */
  public function testDocumentBatchReader(): void {
    $uuid = $this->document($this->requestInfo);
    $this->document($this->approval);
    self::assertSame([], $this->repository->forApplications([], 'request_info'));
    self::assertSame([], $this->repository->forApplications([2], 'request_info'));
    $rows = $this->repository->forApplications([1], 'request_info');
    self::assertCount(1, $rows);
    self::assertSame(['uuid', 'application_id', 'state', 'created_by', 'created'], array_keys($rows[0]));
    self::assertSame($uuid, $rows[0]['uuid']);
    $this->repository->update($uuid, ['state' => 'released']);
    self::assertSame('released', $this->repository->forApplications([1], 'request_info')[0]['state']);
    $this->expectException(\InvalidArgumentException::class);
    $this->repository->forApplications(range(1, 501), 'request_info');
  }

  /**
   * Creates a template version with a stored file (released by default).
   */
  private function template(int $jurisdiction, string $label, string $purpose, string $status = DocgenTemplateInterface::STATUS_RELEASED): DocgenTemplateInterface {
    $bytes = 'template ' . $label;
    $directory = TemplateCatalog::DIRECTORY . '/' . $jurisdiction;
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $file = $this->container->get('file.repository')->writeData($bytes, $directory . '/' . $purpose . '-' . $this->container->get('uuid')->generate() . '.docx', FileExists::Error);
    $template = $this->container->get('entity_type.manager')->getStorage('docgen_template')->create([
      'family' => $this->container->get('uuid')->generate(),
      'version' => 1,
      'label' => $label,
      'purpose' => $purpose,
      'status' => $status,
      'field_jurisdiction' => $jurisdiction,
      'file' => ['target_id' => $file->id()],
      'sha256' => hash('sha256', $bytes),
      'inspection' => '{"placeholders":[],"loops":[],"errors":[]}',
    ]);
    $template->save();
    assert($template instanceof DocgenTemplateInterface);
    return $template;
  }

  /**
   * Stores a draft with a compliant preview of its current DOCX.
   */
  private function document(DocgenTemplateInterface $template, bool $protected = FALSE): string {
    $uuid = $this->container->get('uuid')->generate();
    $directory = DocumentService::DIRECTORY . '/application-one/' . $uuid;
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $docx = 'docx of ' . $uuid;
    $pdf = "%PDF-1.7\n% " . $uuid . "\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    $repository = $this->container->get('file.repository');
    $docx_file = $repository->writeData($docx, $directory . '/r1.docx', FileExists::Error);
    $pdf_file = $repository->writeData($pdf, $directory . '/preview.pdf', FileExists::Error);
    $this->repository->insert([
      'uuid' => $uuid,
      'application_id' => 1,
      'application_uuid' => 'application-one',
      'template_id' => (int) $template->id(),
      'template_family' => $template->getFamily(),
      'template_version' => 1,
      'template_sha256' => $template->getSha256(),
      'purpose' => $template->getPurpose(),
      'title' => (string) $template->label(),
      'state' => DocumentRepository::STATE_DRAFT,
      'data_sha256' => str_repeat('d', 64),
      'missing' => $protected ? '{"missing":[],"protectedOmitted":[],"protectedValues":true}' : NULL,
      'docx_file_id' => (int) $docx_file->id(),
      'docx_sha256' => hash('sha256', $docx),
      'docx_revision' => 1,
      'pdf_file_id' => (int) $pdf_file->id(),
      'pdf_sha256' => hash('sha256', $pdf),
      'pdf_docx_sha256' => hash('sha256', $docx),
      'validation' => '{"profile":"2b","compliant":true,"failedRules":0,"failedChecks":0,"summary":""}',
      // Rendered today: a request letter from an earlier day is blocked with
      // docgen.deadline_outdated (deadline from the release day).
      'rendered_at' => $this->container->get('datetime.time')->getRequestTime(),
      'created_by' => (int) $this->getCurrentUser()->id(),
      'created' => 1000,
      'changed' => 1000,
    ]);
    $this->repository->appendEvent($uuid, 1, 'created', (int) $this->getCurrentUser()->id(), hash('sha256', $docx), NULL, 1000);
    return $uuid;
  }

  /**
   * Downloads the preview and confirms the received bytes, like the UI.
   */
  private function view(DocumentService $service, string $uuid, AccountInterface $account): array {
    $file = $service->pdfFile($this->application, $uuid, $account);
    return $service->confirmViewed($this->application, $uuid, hash('sha256', (string) file_get_contents($file->getFileUri())), $account);
  }

  /**
   * Asserts that an operation fails with a docgen code.
   */
  private function assertCode(string $code, callable $operation): void {
    try {
      $operation();
      self::fail('Expected ' . $code . ', the operation succeeded.');
    }
    catch (DocgenApiException $exception) {
      self::assertSame($code, $exception->errorCode, (string) json_encode($exception->details));
    }
  }

  /**
   * Makes storage return an anonymized application on subsequent reads.
   */
  public function anonymize(): void {
    $this->current = $this->applicationDouble('{"_anonymized":true}');
  }

  /**
   * Application double with the given field_data.
   */
  private function applicationDouble(string $data): ApplicationInterface&MockObject {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('uuid')->willReturn('application-one');
    $application->method('getOwnerId')->willReturn((int) $this->applicant->id());
    $application->method('getData')->willReturn($data);
    $email = $this->createMock(FieldItemListInterface::class);
    $email->method('isEmpty')->willReturn(FALSE);
    $email->method('__isset')->willReturn(TRUE);
    $email->method('__get')->willReturnCallback(static fn (string $name) => $name === 'value' ? self::APPLICANT_EMAIL : NULL);
    $application->method('hasField')->willReturnCallback(static fn (string $name): bool => $name === 'field_applicant_email');
    $application->method('get')->willReturnCallback(static fn (string $name) => $email);
    return $application;
  }

  /**
   * Account double: staff (view permission) or not, protected data or not.
   */
  private function account(int $uid, bool $protected, bool $staff = TRUE, string $email = ''): AccountInterface {
    $granted = array_merge(
      $protected ? [RenderDataBuilder::PROTECTED_PERMISSION] : [],
      $staff ? ['view published application entities'] : [],
    );
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getEmail')->willReturn($email);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => in_array($permission, $granted, TRUE));
    return $account;
  }

  /**
   * Rows of the protected access log as [uid, channel].
   *
   * @return array<int, array{0: string, 1: string}>
   *   Log rows.
   */
  private function accessLog(): array {
    $rows = $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'l')->fields('l', ['uid', 'channel'])->orderBy('id')->execute()->fetchAll(FetchAs::List);
    return array_map(static fn (array $row): array => [(string) $row[0], (string) $row[1]], $rows);
  }

  /**
   * URIs of all document files of the application.
   *
   * @return string[]
   *   Sorted URIs.
   */
  private function files(): array {
    $uris = array_map(static fn ($file): string => $file->getFileUri(), $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
    $uris = array_values(array_filter($uris, static fn (string $uri): bool => str_starts_with($uri, DocumentService::DIRECTORY . '/application-one/')));
    sort($uris);
    return $uris;
  }

  /**
   * Entity type manager whose application storage returns $this->current.
   */
  private function manager(): EntityTypeManagerInterface {
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
  private function responseService(string $scan = AttachmentScanResult::STATUS_CLEAN, ?ConfirmedApplicationMailbox $confirmed_mailbox = NULL): ExternalResponseService {
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
      new ApplicationDataProtectionService($manager, $this->container->get('logger.channel.beantragt_docgen'), new ProtectedAccessLog($this->container->get('database'), $this->container->get('datetime.time'))),
      confirmedMailbox: $confirmed_mailbox,
    );
  }

}
