<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_delivery\Service\ApplicationAnonymizedException;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\DocgenAppendix;
use Drupal\beantragt_docgen\DocgenAppendixBatchInterface;
use Drupal\beantragt_docgen\DocgenAppendixInterface;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\DocgenRequestDraftGuardInterface;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Life cycle of decision documents on an application.
 *
 * Draft (/render) -> optional revised upload -> mandatory preview
 * (/finalize, PDF/A-2b, veraPDF compliant) -> release by a person to whom
 * exactly that PDF was served (pdfFile()) and whose browser confirmed the
 * received bytes (confirmViewed()). The release is first
 * stored as "releasing" (no longer editable), then handed to
 * ExternalResponseService (source "docgen"), the same path as decision
 * notices from a case system:
 * it stores the PDF for the applicant, sends the provision mail and, for a
 * closing purpose, calls SemanticStatusChanger::decisionIssued() on behalf
 * of the releasing person (the current user names the status revision; a
 * resume by someone else is recorded as a system change).
 * Several documents released together become one response with several PDFs.
 *
 * Section 35a VwVfG: no fully automated administrative act; the system only
 * drafts, a person releases.
 *
 * Request letters (purpose request_info) carry a request record and, while
 * the jurisdiction switches it on, a cover sheet: a companion PDF/A on the
 * letter's own row (cover_pdf_*), rendered by printable() at draft and
 * re-render time and released only with its letter, as the next document of
 * the same response. Their deadline counts from the render day: a draft
 * rendered on an earlier day is rendered again on preview (rendered source)
 * or blocked with docgen.deadline_outdated until rerender() (uploaded
 * source).
 */
class DocumentService {

  public const DIRECTORY = 'private://docgen/cases';
  public const MAX_RELEASE_DOCUMENTS = 10;
  private const LOCK_SECONDS = 300.0;
  private const REQUEST_REQUIRED = [
    'empfaenger.anschrift', 'nachforderung.frist', 'nachforderung.mitwirkungshinweis',
    'vorgang.eingang', 'vorgang.fehlende_nachweise',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly DocumentRepository $repository,
    private readonly TemplateCatalog $catalog,
    private readonly DocgenSettingsRepository $settings,
    private readonly RenderDataBuilder $dataBuilder,
    private readonly DocgenClient $client,
    private readonly DocgenScopeAccess $scopeAccess,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
    private readonly ?ExternalResponseService $responses = NULL,
    private readonly ?ApplicationDependentMutationLock $applicationLock = NULL,
    private readonly ?DocgenCaseExtensionInterface $extensions = NULL,
    private readonly ?LetterheadService $letterhead = NULL,
    private readonly ?DocgenAppendixInterface $appendix = NULL,
  ) {}

  /**
   * Lists the documents of an application for a staff member.
   *
   * @return array<int, array<string, mixed>>
   *   Projections.
   */
  public function listDocuments(ApplicationInterface $application, AccountInterface $account): array {
    return array_map(fn (array $row): array => $this->project($application, $row, $account), $this->repository->list((int) $application->id()));
  }

  /**
   * Returns one document projection.
   */
  public function get(ApplicationInterface $application, string $uuid, AccountInterface $account): array {
    return $this->project($application, $this->requireRow($application, $uuid), $account);
  }

  /**
   * Templates a staff member may draft from for this application.
   *
   * @return array{route: string, templates: \Drupal\beantragt_docgen\Entity\DocgenTemplateInterface[]}
   *   Decision route of the form and the newest released version per
   *   allowed family.
   */
  public function availableTemplates(ApplicationInterface $application): array {
    $form = $this->form($application);
    if (!$form instanceof ContentEntityInterface) {
      return ['route' => DocgenSettingsRepository::ROUTE_NONE, 'templates' => []];
    }
    $settings = $this->settings->formSettings((string) $form->uuid());
    $templates = [];
    if ($settings['route'] === DocgenSettingsRepository::ROUTE_TEMPLATE) {
      $scope = $this->scopeAccess->scopeOf($form);
      foreach ($settings['templates'] as $family) {
        $template = $this->catalog->latestReleased($family);
        if ($template instanceof DocgenTemplateInterface && $scope instanceof GroupInterface
          && (int) $template->getScopeGroup()?->id() === (int) $scope->id()) {
          $templates[] = $template;
        }
      }
    }
    return ['route' => $settings['route'], 'templates' => $templates];
  }

  /**
   * Creates a draft from a released, assigned template (docgen /render).
   */
  public function create(ApplicationInterface $application, string $template_uuid, ?string $title, AccountInterface $account): array {
    $available = $this->availableTemplates($application);
    if ($available['route'] === DocgenSettingsRepository::ROUTE_CASE_SYSTEM) {
      throw new DocgenApiException('docgen.form_uses_case_system', 409);
    }
    if ($available['route'] !== DocgenSettingsRepository::ROUTE_TEMPLATE) {
      throw new DocgenApiException('docgen.form_not_configured', 409);
    }
    $template = NULL;
    foreach ($available['templates'] as $candidate) {
      if ($candidate->uuid() === strtolower($template_uuid) || $candidate->getFamily() === strtolower($template_uuid)) {
        $template = $candidate;
      }
    }
    if (!$template instanceof DocgenTemplateInterface) {
      throw new DocgenApiException('docgen.template_not_allowed', 409);
    }
    $title = trim((string) ($title ?? '')) !== '' ? trim((string) $title) : (string) $template->label();
    if (mb_strlen($title) > 200) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'title']);
    }
    $request = $template->getPurpose() === 'request_info' && $this->extensions instanceof DocgenRequestDraftGuardInterface;
    if ($request) {
      $this->assertRequestDraftAllowed($application);
    }
    // The docgen calls run outside the application lock: they may take many
    // seconds and must not hold up deletion or other saves of the case.
    $uuid = $this->uuid->generate();
    $result = $this->renderFromTemplate($application, $template, $uuid, $account);
    $now = $this->time->getRequestTime();
    $this->underApplicationLock($application, function () use ($application, $template, $title, $result, $uuid, $now, $account, $request): void {
      if ($request) {
        $this->assertRequestDraftAllowed($application);
      }
      $this->persistDraft($application, $template, $title, $result, $uuid, $now, $account);
    });
    return $this->get($application, $uuid, $account);
  }

  /**
   * Stores a historical, offline-prepared request for the review workflow.
   *
   * @internal
   *   For trusted demo imports. Preview regenerates the letter and any cover
   *   with the current deadline before release. No PDF or view is booked here.
   */
  public function importPreparedRequest(ApplicationInterface $application, DocgenTemplateInterface $template, string $uuid, string $docx, array $data, array $missing, DocgenAppendix $appendix, int $rendered_at, AccountInterface $account): void {
    $available = array_map(static fn(DocgenTemplateInterface $candidate): string => $candidate->uuid(), $this->availableTemplates($application)['templates']);
    if ($template->getPurpose() !== 'request_info' || !in_array($template->uuid(), $available, TRUE)) {
      throw new DocgenApiException('docgen.template_not_allowed', 409);
    }
    if (!$this->extensions instanceof DocgenRequestDraftGuardInterface || $appendix->coverDocx !== NULL
      || date('Y-m-d', $rendered_at) >= date('Y-m-d', $this->time->getRequestTime())) {
      throw new DocgenApiException('docgen.input_invalid', 422);
    }
    if (array_intersect($missing, self::REQUEST_REQUIRED) !== []) {
      throw new DocgenApiException('docgen.request_details_missing', 409);
    }
    DocxPrecheck::assertDocx($docx);
    $result = [
      'data' => $data,
      'rendered' => ['docx' => $docx, 'sha256' => hash('sha256', $docx)],
      'missing' => $missing,
      'appendix' => $appendix,
      'cover' => NULL,
    ];
    $this->underApplicationLock($application, function () use ($application, $template, $uuid, $result, $rendered_at, $account): void {
      $this->assertRequestDraftAllowed($application);
      $this->persistDraft($application, $template, (string) $template->label(), $result, $uuid, $rendered_at, $account);
    });
  }

  /**
   * Rechecks for a competing draft before persisting any rendered files.
   */
  private function assertRequestDraftAllowed(ApplicationInterface $application): void {
    assert($this->extensions instanceof DocgenRequestDraftGuardInterface);
    $documents = $this->repository->forApplications([(int) $application->id()], 'request_info');
    $this->extensions->assertRequestDraftAllowed($application, $documents);
  }

  /**
   * Renders a template and, for a request letter, its cover sheet.
   *
   * Nothing is stored. The appendix prepares the request record and the
   * standalone cover sheet first, so the letter, the record and the sheet
   * read the deadline in the same request.
   *
   * @return array{data: array<string, mixed>, rendered: array{docx: string, sha256: string, missing: string[]}, missing: string[], appendix: ?\Drupal\beantragt_docgen\DocgenAppendix, cover: ?array{pdf: string, sha256: string, validation: array<string, mixed>}}
   *   Render data, the rendered letter, its missing placeholders, what the
   *   appendix stores and the rendered cover sheet.
   */
  private function renderFromTemplate(ApplicationInterface $application, DocgenTemplateInterface $template, string $uuid, AccountInterface $account): array {
    $letterhead = $this->letterhead ?? throw new DocgenApiException('docgen.letterhead_missing', 409);
    $request_info = $template->getPurpose() === 'request_info';
    $appendix = $request_info ? $this->appendix?->prepare($application, $uuid, $account) : NULL;
    $bytes = $letterhead->apply($application, $this->catalog->bytes($template));
    $inspection = $this->client->inspect($bytes);
    $data = $this->dataBuilder->build($application, $account, $inspection, $request_info);
    $rendered = $this->client->render($bytes, $data['data']);
    if ($request_info && array_intersect($rendered['missing'], self::REQUEST_REQUIRED) !== []) {
      throw new DocgenApiException('docgen.request_details_missing', 409);
    }
    $cover = $appendix instanceof DocgenAppendix && $appendix->coverDocx !== NULL
      ? $this->renderCover($application, $appendix->coverDocx, $account)
      : NULL;
    $data['protectedOmitted'] = array_values(array_intersect($data['protectedOmitted'], $rendered['missing']));
    $missing = array_values(array_unique($rendered['missing']));
    sort($missing);
    return ['data' => $data, 'rendered' => $rendered, 'missing' => $missing, 'appendix' => $appendix, 'cover' => $cover];
  }

  /**
   * Renders the cover sheet of a request letter draft.
   *
   * A sheet that cannot be produced or is not PDF/A compliant fails the
   * draft with docgen.cover_sheet_unavailable (503, retryable); nothing is
   * stored. A busy docgen stays docgen.busy.
   *
   * @return array{pdf: string, sha256: string, validation: array<string, mixed>}
   *   The compliant cover sheet.
   */
  private function renderCover(ApplicationInterface $application, string $docx, AccountInterface $account): array {
    try {
      return $this->printable($application, $docx, $account);
    }
    catch (DocgenApiException $exception) {
      if (in_array($exception->errorCode, ['docgen.busy', 'docgen.application_anonymized', 'docgen.letterhead_missing'], TRUE)) {
        throw $exception;
      }
      $this->logger->warning('The cover sheet of a request letter could not be rendered: @code.', ['@code' => $exception->errorCode]);
      throw new DocgenApiException('docgen.cover_sheet_unavailable', 503, [], DocgenClient::DEFAULT_RETRY_AFTER_SECONDS);
    }
  }

  /**
   * Renders a body-only DOCX with the tenant's letterhead into a PDF/A-2b.
   *
   * The same path for the cover sheet of a request letter draft and for the
   * staff reprint: letterhead, /inspect, render data without protected form
   * values (the header needs only tenant names), /render, /finalize. Stores
   * nothing.
   *
   * @return array{pdf: string, sha256: string, validation: array<string, mixed>}
   *   The veraPDF-compliant PDF, its SHA-256 and the validation summary.
   *
   * @throws \Drupal\beantragt_docgen\Exception\DocgenApiException
   *   Code docgen.pdf_not_compliant (422) and every docgen failure.
   */
  public function printable(ApplicationInterface $application, string $docx, AccountInterface $account): array {
    DocxPrecheck::assertDocx($docx);
    $letterhead = $this->letterhead ?? throw new DocgenApiException('docgen.letterhead_missing', 409);
    $bytes = $letterhead->apply($application, $docx);
    $inspection = $this->client->inspect($bytes);
    $data = $this->dataBuilder->build($application, $account, $inspection, FALSE);
    $rendered = $this->client->render($bytes, $data['data']);
    $result = $this->client->finalize($rendered['docx']);
    if ($result['validation']['compliant'] !== TRUE) {
      throw new DocgenApiException('docgen.pdf_not_compliant', 422, ['validation' => $result['validation']]);
    }
    return ['pdf' => $result['pdf'], 'sha256' => $result['sha256'], 'validation' => $result['validation']];
  }

  /**
   * Writes the rendered draft files and its row (caller holds the case lock).
   *
   * The docgen row, its events, the request record and the draft identifier
   * are stored in one transaction; a failure removes the files as well.
   */
  private function persistDraft(ApplicationInterface $application, DocgenTemplateInterface $template, string $title, array $result, string $uuid, int $now, AccountInterface $account): void {
    ['data' => $data, 'rendered' => $rendered, 'missing' => $missing] = $result;
    $file = $this->writeFile($application, $uuid, 'r1.docx', $rendered['docx'], TemplateCatalog::safeFilename($title, 'bescheid.docx'), $account);
    try {
      $cover_file = $this->writeCover($application, $uuid, $result['cover'], $account);
    }
    catch (\Throwable $exception) {
      $file->delete();
      throw $exception;
    }
    $transaction = $this->repository->startTransaction();
    try {
      $this->repository->insert([
        'uuid' => $uuid,
        'application_id' => (int) $application->id(),
        'application_uuid' => (string) $application->uuid(),
        'template_id' => (int) $template->id(),
        'template_family' => $template->getFamily(),
        'template_version' => $template->getVersion(),
        'template_sha256' => $template->getSha256(),
        'purpose' => $template->getPurpose(),
        'title' => $title,
        'state' => DocumentRepository::STATE_DRAFT,
        'data_sha256' => $data['dataSha256'],
        'missing' => json_encode([
          'missing' => $missing,
          'protectedOmitted' => $data['protectedOmitted'],
          'protectedValues' => $data['protectedValues'],
        ], JSON_THROW_ON_ERROR),
        'docx_file_id' => (int) $file->id(),
        'docx_sha256' => $rendered['sha256'],
        'docx_revision' => 1,
        'docx_source' => 'rendered',
        'rendered_at' => $now,
        'created_by' => (int) $account->id(),
        'created' => $now,
        'changed' => $now,
      ] + self::coverFields($cover_file, $result['cover']));
      $this->repository->appendEvent($uuid, (int) $application->id(), 'created', (int) $account->id(), $rendered['sha256'], NULL, $now);
      $this->commitAppendix($application, $uuid, $result, $cover_file, $now, $account);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $file->delete();
      $cover_file?->delete();
      throw $exception;
    }
    unset($transaction);
  }

  /**
   * Writes a rendered cover sheet below the document directory.
   */
  private function writeCover(ApplicationInterface $application, string $uuid, ?array $cover, AccountInterface $account): ?FileInterface {
    if ($cover === NULL) {
      return NULL;
    }
    return $this->writeFile($application, $uuid, 'cover-' . substr($cover['sha256'], 0, 16) . '.pdf', $cover['pdf'], 'deckblatt.pdf', $account);
  }

  /**
   * Row fields of a stored cover sheet (all NULL without one).
   *
   * @return array<string, int|string|null>
   *   Fields cover_pdf_file_id, cover_pdf_sha256, cover_validation.
   */
  private static function coverFields(?FileInterface $file, ?array $cover): array {
    if (!$file instanceof FileInterface || $cover === NULL) {
      return ['cover_pdf_file_id' => NULL, 'cover_pdf_sha256' => NULL, 'cover_validation' => NULL];
    }
    return [
      'cover_pdf_file_id' => (int) $file->id(),
      'cover_pdf_sha256' => $cover['sha256'],
      'cover_validation' => json_encode($cover['validation'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ];
  }

  /**
   * Records the cover sheet and stores the appendix (inside the transaction).
   */
  private function commitAppendix(ApplicationInterface $application, string $uuid, array $result, ?FileInterface $cover_file, int $now, AccountInterface $account): void {
    if ($cover_file instanceof FileInterface && $result['cover'] !== NULL) {
      $this->repository->appendEvent($uuid, (int) $application->id(), 'cover_rendered', (int) $account->id(), NULL, $result['cover']['sha256'], $now);
    }
    if ($result['appendix'] instanceof DocgenAppendix) {
      ($this->appendix ?? throw new DocgenApiException('docgen.cover_sheet_unavailable', 503))->commit($application, $uuid, $result['appendix'], $now);
    }
  }

  /**
   * Replaces the draft DOCX with a revised upload.
   */
  public function uploadRevision(ApplicationInterface $application, string $uuid, string $filename, string $bytes, AccountInterface $account): array {
    return $this->withLock($uuid, function () use ($application, $uuid, $filename, $bytes, $account): array {
      $this->requireDraft($application, $uuid);
      DocxPrecheck::assertDocx($bytes);
      $this->catalog->assertClean($bytes, TemplateCatalog::safeFilename($filename, 'bescheid.docx'));
      $this->underApplicationLock($application, function () use ($application, $uuid, $bytes, $account): void {
        $this->persistRevision($application, $this->requireDraft($application, $uuid), $bytes, $account);
      });
      return $this->get($application, $uuid, $account);
    });
  }

  /**
   * Stores a revised DOCX of a draft (caller holds both locks).
   */
  private function persistRevision(ApplicationInterface $application, array $row, string $bytes, AccountInterface $account): void {
    $uuid = (string) $row['uuid'];
    $revision = (int) $row['docx_revision'] + 1;
    // A revision keeps clear protected values when the draft had them, or
    // may gain them from a person who sees them elsewhere.
    $flags = self::flags($row);
    $flags['protectedValues'] = $flags['protectedValues']
      || ($account->hasPermission(RenderDataBuilder::PROTECTED_PERMISSION) && $this->dataBuilder->hasProtectedData($application));
    $sha256 = hash('sha256', $bytes);
    $file = $this->writeFile($application, $uuid, 'r' . $revision . '.docx', $bytes, TemplateCatalog::safeFilename((string) $row['title'], 'bescheid.docx'), $account);
    $now = $this->time->getRequestTime();
    $transaction = $this->repository->startTransaction();
    try {
      $this->repository->update($uuid, [
        'docx_file_id' => (int) $file->id(),
        'docx_sha256' => $sha256,
        'docx_revision' => $revision,
        'docx_source' => 'uploaded',
        'missing' => json_encode($flags, JSON_THROW_ON_ERROR),
        'pdf_file_id' => NULL,
        'pdf_sha256' => NULL,
        'pdf_docx_sha256' => NULL,
        'validation' => NULL,
        'changed' => $now,
      ]);
      $this->repository->appendEvent($uuid, (int) $application->id(), 'uploaded', (int) $account->id(), $sha256, NULL, $now);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $file->delete();
      throw $exception;
    }
    unset($transaction);
    $this->deleteFile($row['docx_file_id']);
    $this->deleteFile($row['pdf_file_id']);
  }

  /**
   * Produces the mandatory preview: PDF/A-2b of exactly the current DOCX.
   *
   * Only a veraPDF-compliant PDF is stored; a non-compliant result is
   * answered with docgen.pdf_not_compliant and its validation summary.
   */
  public function preview(ApplicationInterface $application, string $uuid, AccountInterface $account): array {
    return $this->withLock($uuid, function () use ($application, $uuid, $account): array {
      $row = $this->requireDraft($application, $uuid);
      if ($row['docx_source'] === 'rendered' && self::deadlineOutdated($row, $this->time->getRequestTime())) {
        // The deadline counts from the release day: a request letter
        // rendered on an earlier day is rendered again first, with a new
        // deadline, request record and cover sheet.
        $row = $this->rerenderDraft($application, $row, $account);
      }
      $docx = $this->readFile($application, $row['docx_file_id'], (string) $row['docx_sha256']);
      $result = $this->client->finalize($docx);
      if (!hash_equals((string) $row['docx_sha256'], $result['docxSha256'])) {
        throw new DocgenApiException('docgen.integrity_failed', 502);
      }
      if ($result['validation']['compliant'] !== TRUE) {
        throw new DocgenApiException('docgen.pdf_not_compliant', 422, ['validation' => $result['validation']]);
      }
      $this->underApplicationLock($application, function () use ($application, $uuid, $row, $result, $account): void {
        $current = $this->requireDraft($application, $uuid);
        if (!hash_equals((string) $row['docx_sha256'], (string) $current['docx_sha256'])) {
          throw new DocgenApiException('docgen.preview_stale', 409);
        }
        $this->persistPreview($application, $current, $result, $account);
      });
      return $this->get($application, $uuid, $account);
    });
  }

  /**
   * Stores a compliant preview PDF of a draft (caller holds both locks).
   */
  private function persistPreview(ApplicationInterface $application, array $row, array $result, AccountInterface $account): void {
    $uuid = (string) $row['uuid'];
    $file = $this->writeFile($application, $uuid, 'preview-' . substr($result['sha256'], 0, 16) . '.pdf', $result['pdf'], TemplateCatalog::safeFilename((string) $row['title'], 'bescheid.pdf'), $account);
    $now = $this->time->getRequestTime();
    $transaction = $this->repository->startTransaction();
    try {
      $this->repository->update($uuid, [
        'pdf_file_id' => (int) $file->id(),
        'pdf_sha256' => $result['sha256'],
        'pdf_docx_sha256' => $result['docxSha256'],
        'validation' => json_encode($result['validation'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'changed' => $now,
      ]);
      $this->repository->appendEvent($uuid, (int) $application->id(), 'previewed', (int) $account->id(), $result['docxSha256'], $result['sha256'], $now);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $file->delete();
      throw $exception;
    }
    unset($transaction);
    if ((int) ($row['pdf_file_id'] ?? 0) !== (int) $file->id()) {
      $this->deleteFile($row['pdf_file_id']);
    }
  }

  /**
   * Renders a draft again from its template ("Neu aus der Vorlage erstellen").
   *
   * Uploaded Word edits are lost; the deadline, the request record and the
   * cover sheet are produced anew for today. The document keeps its UUID, so
   * links to it (bulk case) stay valid.
   */
  public function rerender(ApplicationInterface $application, string $uuid, AccountInterface $account): array {
    return $this->withLock($uuid, function () use ($application, $uuid, $account): array {
      $this->rerenderDraft($application, $this->requireDraft($application, $uuid), $account);
      return $this->get($application, $uuid, $account);
    });
  }

  /**
   * Renders a draft again with the same UUID (caller holds the document lock).
   *
   * Runs with the calling account: a request letter needs that account's
   * protected values, otherwise docgen.request_details_missing.
   *
   * @return array<string, mixed>
   *   The stored row.
   */
  private function rerenderDraft(ApplicationInterface $application, array $row, AccountInterface $account): array {
    $template = $this->entityTypeManager->getStorage('docgen_template')->load((int) $row['template_id']);
    if (!$template instanceof DocgenTemplateInterface) {
      throw new DocgenApiException('docgen.template_not_found', 409);
    }
    $uuid = (string) $row['uuid'];
    $result = $this->renderFromTemplate($application, $template, $uuid, $account);
    $this->underApplicationLock($application, function () use ($application, $uuid, $row, $result, $account): void {
      $current = $this->requireDraft($application, $uuid);
      if (!hash_equals((string) $row['docx_sha256'], (string) $current['docx_sha256'])) {
        throw new DocgenApiException('docgen.preview_stale', 409);
      }
      $this->persistRerender($application, $current, $result, $account);
    });
    return $this->requireRow($application, $uuid);
  }

  /**
   * Stores a re-rendered draft (caller holds both locks).
   */
  private function persistRerender(ApplicationInterface $application, array $row, array $result, AccountInterface $account): void {
    $uuid = (string) $row['uuid'];
    $revision = (int) $row['docx_revision'] + 1;
    ['data' => $data, 'rendered' => $rendered, 'missing' => $missing] = $result;
    $file = $this->writeFile($application, $uuid, 'r' . $revision . '.docx', $rendered['docx'], TemplateCatalog::safeFilename((string) $row['title'], 'bescheid.docx'), $account);
    try {
      $cover_file = $this->writeCover($application, $uuid, $result['cover'], $account);
    }
    catch (\Throwable $exception) {
      $file->delete();
      throw $exception;
    }
    $now = $this->time->getRequestTime();
    $transaction = $this->repository->startTransaction();
    try {
      $this->repository->update($uuid, [
        'docx_file_id' => (int) $file->id(),
        'docx_sha256' => $rendered['sha256'],
        'docx_revision' => $revision,
        'docx_source' => 'rendered',
        'data_sha256' => $data['dataSha256'],
        'missing' => json_encode([
          'missing' => $missing,
          'protectedOmitted' => $data['protectedOmitted'],
          'protectedValues' => $data['protectedValues'],
        ], JSON_THROW_ON_ERROR),
        'pdf_file_id' => NULL,
        'pdf_sha256' => NULL,
        'pdf_docx_sha256' => NULL,
        'validation' => NULL,
        'rendered_at' => $now,
        'changed' => $now,
      ] + self::coverFields($cover_file, $result['cover']));
      $this->repository->appendEvent($uuid, (int) $application->id(), 'rerendered', (int) $account->id(), $rendered['sha256'], NULL, $now);
      $this->commitAppendix($application, $uuid, $result, $cover_file, $now, $account);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $file->delete();
      $cover_file?->delete();
      throw $exception;
    }
    unset($transaction);
    foreach (['docx_file_id' => $file, 'cover_pdf_file_id' => $cover_file] as $key => $new) {
      if ((int) ($row[$key] ?? 0) !== (int) $new?->id()) {
        $this->deleteFile($row[$key] ?? NULL);
      }
    }
    $this->deleteFile($row['pdf_file_id']);
  }

  /**
   * Returns the current DOCX file.
   *
   * A draft with clear protected values needs the protected-data permission
   * and the download is logged (channel docgen_download).
   */
  public function docxFile(ApplicationInterface $application, string $uuid, AccountInterface $account): FileInterface {
    $row = $this->requireRow($application, $uuid);
    $file = $this->loadFile($application, $row['docx_file_id']);
    $this->authorizeContent($application, $row, $account);
    return $file;
  }

  /**
   * Returns the preview or released PDF.
   *
   * Serving it records only the delivery to this person (no view): a
   * download can still fail on its way to the browser (proxy limit,
   * network). The person's browser confirms the bytes it received with
   * confirmViewed(), which counts only for a PDF served to that person.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string $uuid
   *   The document UUID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person opening the PDF.
   */
  public function pdfFile(ApplicationInterface $application, string $uuid, AccountInterface $account): FileInterface {
    $row = $this->requireRow($application, $uuid);
    if (empty($row['pdf_file_id']) || empty($row['pdf_sha256'])) {
      throw new DocgenApiException('docgen.preview_missing', 404);
    }
    $file = $this->loadFile($application, $row['pdf_file_id']);
    $this->authorizeContent($application, $row, $account);
    if ($row['state'] === DocumentRepository::STATE_DRAFT) {
      $this->repository->recordServed((string) $row['uuid'], (string) $row['pdf_sha256'], (int) $account->id(), $this->time->getRequestTime());
    }
    return $file;
  }

  /**
   * Returns the cover sheet PDF of a request letter.
   *
   * Same gate as the letter's PDF: scope and permission in the controller,
   * the protected-data check and its audit row for a letter with clear
   * protected values. The sheet itself holds no personal data.
   */
  public function coverPdfFile(ApplicationInterface $application, string $uuid, AccountInterface $account): FileInterface {
    $row = $this->requireRow($application, $uuid);
    if (empty($row['cover_pdf_file_id']) || empty($row['cover_pdf_sha256'])) {
      throw new DocgenApiException('docgen.cover_sheet_missing', 404);
    }
    $file = $this->loadFile($application, $row['cover_pdf_file_id']);
    $this->authorizeContent($application, $row, $account);
    return $file;
  }

  /**
   * Records that the person's browser received exactly the current preview.
   *
   * The UI sends the SHA-256 it computed over the PDF bytes it received. Only
   * the hash of the current preview counts, and only when that PDF was
   * served to this person (pdfFile()): the hash alone, which the projection
   * shows, proves nothing. This is the "opened" of the release rule. The
   * same content gate as the download applies, without a second entry in
   * the protected access log (the download wrote one).
   */
  public function confirmViewed(ApplicationInterface $application, string $uuid, string $pdf_sha256, AccountInterface $account): array {
    $row = $this->requireDraft($application, $uuid);
    if (empty($row['pdf_file_id']) || empty($row['pdf_sha256'])) {
      throw new DocgenApiException('docgen.preview_missing', 404);
    }
    if (!preg_match('/^[0-9a-f]{64}$/D', $pdf_sha256)) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'pdfSha256']);
    }
    if (!hash_equals((string) $row['pdf_sha256'], $pdf_sha256)) {
      throw new DocgenApiException('docgen.preview_stale', 409);
    }
    if (self::flags($row)['protectedValues'] && !$account->hasPermission(RenderDataBuilder::PROTECTED_PERMISSION)) {
      throw new DocgenApiException('docgen.access_denied', 403, ['reason' => 'protected_values']);
    }
    if (!$this->repository->wasServed((string) $row['uuid'], (string) $row['pdf_sha256'], (int) $account->id())) {
      // Confirming a PDF that was never served to this person.
      throw new DocgenApiException('docgen.preview_not_viewed', 409);
    }
    $this->repository->recordViewOnce((string) $row['uuid'], (int) $application->id(), (string) $row['pdf_sha256'], $row['pdf_docx_sha256'], (int) $account->id(), $this->time->getRequestTime());
    return $this->get($application, $uuid, $account);
  }

  /**
   * Releases one or more documents together as one provision.
   *
   * Two steps, so a crash can never leave a provided notice as an editable
   * draft: the documents are first stored as "releasing" (no longer
   * editable or discardable), then ExternalResponseService::ingest() provides
   * them, then they are booked as released. The authorized resumeRelease()
   * path naming any document of an unfinished release resumes that whole
   * release with the same
   * deterministic identity; ingest() then finds the stored response instead
   * of providing twice. When ingest() fails and nothing was stored, the
   * documents return to draft.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string[] $uuids
   *   Document UUIDs of the application (1 to 10).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The releasing person; must have opened each PDF (not for a resume:
   *   the release was decided by the person recorded in released_by).
   *
   * @return array{documents: array<int, array<string, mixed>>, response: array<string, mixed>}
   *   Released documents and the external response the applicant receives.
   */
  public function release(ApplicationInterface $application, array $uuids, AccountInterface $account): array {
    return $this->releaseDocuments($application, $uuids, $account, FALSE);
  }

  /**
   * Resumes only an interrupted release under the caller's current authority.
   */
  public function resumeRelease(ApplicationInterface $application, array $uuids, AccountInterface $account): array {
    return $this->releaseDocuments($application, $uuids, $account, TRUE);
  }

  /**
   * Releases or resumes after enforcing current case and release access.
   */
  private function releaseDocuments(ApplicationInterface $application, array $uuids, AccountInterface $account, bool $resume_allowed): array {
    if (!$this->scopeAccess->canWorkOnCase($application, $account, DocgenScopeAccess::PERMISSION_RELEASE, TRUE)) {
      throw new DocgenApiException('docgen.access_denied', 403);
    }
    $uuids = array_values(array_unique(array_map('strtolower', array_filter($uuids, 'is_string'))));
    if ($uuids === [] || count($uuids) > self::MAX_RELEASE_DOCUMENTS) {
      throw new DocgenApiException('docgen.release_count_invalid', 422, ['max' => self::MAX_RELEASE_DOCUMENTS]);
    }
    if (!$this->responses instanceof ExternalResponseService) {
      throw new DocgenApiException('docgen.delivery_unavailable', 503);
    }
    $uuids = $this->releaseSet($application, $uuids);
    sort($uuids);
    $held = [];
    try {
      foreach ($uuids as $uuid) {
        if (!$this->lock->acquire($this->lockName($uuid), self::LOCK_SECONDS)) {
          throw new DocgenApiException('docgen.locked', 409);
        }
        $held[] = $uuid;
      }
      $rows = array_map(fn (string $uuid): array => $this->requireRow($application, $uuid), $uuids);
      $resume = in_array(DocumentRepository::STATE_RELEASING, array_column($rows, 'state'), TRUE);
      if ($resume !== $resume_allowed) {
        throw new DocgenApiException('docgen.document_releasing', 409);
      }
      if ($resume) {
        // Under the locks the set must still be exactly one open release.
        $group = (string) ($rows[0]['release_uuid'] ?? '');
        $members = $group === '' ? [] : array_column($this->repository->listRelease((int) $application->id(), $group), 'uuid');
        sort($members);
        foreach ($rows as $row) {
          if ($row['state'] !== DocumentRepository::STATE_RELEASING || (string) $row['release_uuid'] !== $group) {
            throw new DocgenApiException('docgen.document_releasing', 409, ['document' => $row['uuid']]);
          }
        }
        if ($members !== $uuids) {
          // The release changed between widening and locking.
          throw new DocgenApiException('docgen.document_releasing', 409, ['document' => $rows[0]['uuid']]);
        }
        // A release started before outcomes existed may mix purposes; it is
        // finished as it was decided, without an outcome.
        $payload = $this->releasePayload($application, $rows, FALSE);
      }
      else {
        foreach ($rows as $row) {
          $blockers = $this->releaseBlockers($row, $account);
          if ($blockers !== []) {
            throw new DocgenApiException($blockers[0], 409, ['document' => $row['uuid'], 'blockers' => $blockers]);
          }
        }
        // Read and check every PDF before anything changes; a mixed
        // approval and rejection is refused here, before markReleasing().
        $payload = $this->releasePayload($application, $rows);
        $rows = $this->markReleasing($application, $rows, $account);
      }
      $response = $this->provide($application, $rows, $payload, $account, $resume);
      $this->markReleased($application, $rows, $response, $account, $resume);
      $this->logger->info('Decision documents released for application @id: @count document(s), closes procedure: @closes@resumed.', [
        '@id' => (string) $application->id(),
        '@count' => (string) count($rows),
        '@closes' => $payload['closes'] ? 'yes' : 'no',
        '@resumed' => $resume ? ', resumed after an interruption' : '',
      ]);
      // A deletion of the application meanwhile took its rows along; the
      // provision itself succeeded.
      $remaining = array_filter($rows, fn (array $row): bool => $this->repository->find((int) $application->id(), (string) $row['uuid']) !== NULL);
      return [
        'documents' => array_values(array_map(fn (array $row): array => $this->get($application, $row['uuid'], $account), $remaining)),
        'response' => $response,
      ];
    }
    finally {
      foreach ($held as $uuid) {
        $this->lock->release($this->lockName($uuid));
      }
    }
  }

  /**
   * Widens a request that names a document of an unfinished release.
   *
   * @return string[]
   *   The requested UUIDs, or all documents of that one release.
   */
  private function releaseSet(ApplicationInterface $application, array $uuids): array {
    $groups = [];
    $others = FALSE;
    foreach ($uuids as $uuid) {
      $row = $this->requireRow($application, $uuid);
      if ($row['state'] === DocumentRepository::STATE_RELEASING && (string) $row['release_uuid'] !== '') {
        $groups[(string) $row['release_uuid']] = (string) $row['uuid'];
      }
      else {
        $others = TRUE;
      }
    }
    if ($groups === []) {
      return $uuids;
    }
    if ($others || count($groups) > 1) {
      // A started release is finished as it was decided, never mixed.
      throw new DocgenApiException('docgen.document_releasing', 409, ['document' => reset($groups)]);
    }
    $members = array_column($this->repository->listRelease((int) $application->id(), (string) array_key_first($groups)), 'uuid');
    return $members !== [] ? $members : $uuids;
  }

  /**
   * Reads and checks the PDFs of a release and derives its identity.
   *
   * The outcome follows the closing purposes: only approval gives approved,
   * only rejection gives rejected, neither gives NULL. Approval and
   * rejection in one release contradict each other and are refused (422
   * docgen.release_outcome_conflict) before anything changes.
   * isDecision is the same OR over the closing purposes as closes, kept as
   * its own key so a later decision mark changes only the delivery rule: a
   * bundle with one approval among acknowledgements is a decision.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param array<int, array<string, mixed>> $rows
   *   The documents of the release.
   * @param bool $strict
   *   FALSE for the resume of an already started release: a mixed set then
   *   gets no outcome instead of an error.
   *
   * @return array{documents: array<int, array<string, string>>, closes: bool, isDecision: bool, protected: bool, externalId: string, title: string, reference: string, outcome: string|null}
   *   Provision input.
   */
  private function releasePayload(ApplicationInterface $application, array $rows, bool $strict = TRUE): array {
    $purposes = array_values(array_unique(array_intersect(array_column($rows, 'purpose'), DocgenTemplateInterface::CLOSING_PURPOSES)));
    $mixed = in_array('approval', $purposes, TRUE) && in_array('rejection', $purposes, TRUE);
    if ($mixed && $strict) {
      throw new DocgenApiException('docgen.release_outcome_conflict', 422);
    }
    $outcome = match (TRUE) {
      $mixed => NULL,
      $purposes === ['approval'] => 'approved',
      $purposes === ['rejection'] => 'rejected',
      default => NULL,
    };
    $documents = [];
    $closes = FALSE;
    $is_decision = FALSE;
    $protected = FALSE;
    foreach ($rows as $row) {
      $pdf = $this->readFile($application, $row['pdf_file_id'], (string) $row['pdf_sha256']);
      $documents[] = [
        'bytes' => $pdf,
        'filename' => TemplateCatalog::safeFilename((string) $row['title'], 'bescheid.pdf'),
        'mimeType' => 'application/pdf',
        'sha512' => hash('sha512', $pdf),
      ];
      if (!empty($row['cover_pdf_file_id']) && !empty($row['cover_pdf_sha256'])) {
        // The cover sheet follows its letter in the same response: one
        // delivery route, one notification, never on its own.
        $cover = $this->readFile($application, $row['cover_pdf_file_id'], (string) $row['cover_pdf_sha256']);
        $documents[] = [
          'bytes' => $cover,
          'filename' => TemplateCatalog::safeFilename('Deckblatt ' . $row['title'], 'deckblatt.pdf'),
          'mimeType' => 'application/pdf',
          'sha512' => hash('sha512', $cover),
        ];
      }
      $closes = $closes || in_array($row['purpose'], DocgenTemplateInterface::CLOSING_PURPOSES, TRUE);
      $is_decision = $is_decision || in_array($row['purpose'], DocgenTemplateInterface::CLOSING_PURPOSES, TRUE);
      $protected = $protected || self::flags($row)['protectedValues'];
    }
    if (count($documents) > self::MAX_RELEASE_DOCUMENTS) {
      // One response holds at most ten PDFs, cover sheets included.
      throw new DocgenApiException('docgen.release_count_invalid', 422, ['max' => self::MAX_RELEASE_DOCUMENTS]);
    }
    // Deterministic identity over the documents sorted by UUID and their PDF
    // hashes, which a releasing document can no longer change: a resume
    // finds the stored response instead of providing twice. A cover sheet
    // adds its hash; rows without one keep the earlier identity.
    $identity = implode('|', array_map(static fn (array $row): string => $row['uuid'] . ':' . $row['pdf_sha256'] . (empty($row['cover_pdf_sha256']) ? '' : ':' . $row['cover_pdf_sha256']), $rows));
    return [
      'documents' => $documents,
      'closes' => $closes,
      'isDecision' => $is_decision,
      'protected' => $protected,
      'externalId' => 'docgen-release:' . hash('sha256', $identity),
      'title' => mb_substr(implode(', ', array_map(static fn (array $row): string => (string) $row['title'], $rows)), 0, 255),
      'reference' => mb_substr(implode(', ', array_map(static fn (array $row): string => $row['title'] . ' (v' . $row['template_version'] . ')', $rows)), 0, 255),
      'outcome' => $outcome,
    ];
  }

  /**
   * Stores the release decision durably before anything is provided.
   *
   * @return array<int, array<string, mixed>>
   *   The rows as stored.
   */
  private function markReleasing(ApplicationInterface $application, array $rows, AccountInterface $account): array {
    $now = $this->time->getRequestTime();
    $release_uuid = $this->uuid->generate();
    $transaction = $this->repository->startTransaction();
    try {
      foreach ($rows as $row) {
        $this->repository->update($row['uuid'], [
          'state' => DocumentRepository::STATE_RELEASING,
          'released_by' => (int) $account->id(),
          'release_uuid' => $release_uuid,
          'changed' => $now,
        ]);
        $this->repository->appendEvent($row['uuid'], (int) $application->id(), 'release_started', (int) $account->id(), $row['docx_sha256'], $row['pdf_sha256'], $now);
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
    return array_map(fn (array $row): array => $this->requireRow($application, $row['uuid']), $rows);
  }

  /**
   * Provides the release through ExternalResponseService.
   *
   * On failure the documents go back to draft only when no response was
   * stored; otherwise they stay "releasing" for a resume. A resume repeats
   * the closing status change (idempotent): the interrupted attempt may have
   * stored the response without closing the procedure. The status revision
   * names the person only when that person also decided the release.
   */
  private function provide(ApplicationInterface $application, array $rows, array $payload, AccountInterface $account, bool $resume): array {
    $by_person = (int) $account->id() === (int) ($rows[0]['released_by'] ?? 0);
    try {
      return $this->responses->ingest($application, 'docgen', $payload['externalId'], $payload['reference'], $payload['title'], $payload['documents'], TRUE, $payload['closes'], $by_person, $payload['protected'], $resume, outcome: $payload['outcome'], isDecision: $payload['isDecision']);
    }
    catch (\Throwable $exception) {
      try {
        $stored = $this->responses->findIngested($application, 'docgen', $payload['externalId']) !== NULL;
      }
      catch (\Throwable) {
        // Unknown: stay "releasing"; a resume is idempotent either way.
        $stored = TRUE;
      }
      if (!$stored) {
        $this->abortRelease($application, $rows, $account);
      }
      throw match (TRUE) {
        $exception instanceof DocgenApiException => $exception,
        // Anonymized by the retention run after the release started.
        $exception instanceof ApplicationAnonymizedException => new DocgenApiException('docgen.application_anonymized', 409),
        $exception instanceof \UnexpectedValueException => new DocgenApiException('docgen.scan_failed', 422),
        $exception instanceof \InvalidArgumentException => new DocgenApiException('docgen.release_invalid', 422),
        // Application locked by another mutation, storage unavailable, or a
        // step after the provision failed.
        default => new DocgenApiException('docgen.delivery_failed', 503, $stored ? ['state' => DocumentRepository::STATE_RELEASING] : [], DocgenClient::DEFAULT_RETRY_AFTER_SECONDS),
      };
    }
  }

  /**
   * Returns an unfinished release to draft when nothing was provided.
   */
  private function abortRelease(ApplicationInterface $application, array $rows, AccountInterface $account): void {
    $now = $this->time->getRequestTime();
    $transaction = $this->repository->startTransaction();
    try {
      foreach ($rows as $row) {
        // A deletion or anonymization of the application meanwhile took the
        // rows along; nothing to return.
        if ($this->repository->find((int) $application->id(), (string) $row['uuid']) === NULL) {
          continue;
        }
        $this->repository->update($row['uuid'], [
          'state' => DocumentRepository::STATE_DRAFT,
          'released_by' => NULL,
          'released_at' => NULL,
          'release_uuid' => NULL,
          'changed' => $now,
        ]);
        $this->repository->appendEvent($row['uuid'], (int) $application->id(), 'release_aborted', (int) $account->id(), $row['docx_sha256'], $row['pdf_sha256'], $now);
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      // The documents stay "releasing"; a resume provides them.
      $this->logger->error('Release of decision documents for application @id could not be returned to draft: @class.', [
        '@id' => (string) $application->id(),
        '@class' => $exception::class,
      ]);
    }
  }

  /**
   * Books the provided documents as released.
   */
  private function markReleased(ApplicationInterface $application, array $rows, array $response, AccountInterface $account, bool $resumed): void {
    $now = $this->time->getRequestTime();
    $booked = [];
    $transaction = $this->repository->startTransaction();
    try {
      foreach ($rows as $row) {
        // The application may have been deleted meanwhile; its cleanup took
        // the rows along.
        if ($this->repository->find((int) $application->id(), (string) $row['uuid']) === NULL) {
          continue;
        }
        $booked[] = $row;
        if ($resumed) {
          $this->repository->appendEvent($row['uuid'], (int) $application->id(), 'release_resumed', (int) $account->id(), $row['docx_sha256'], $row['pdf_sha256'], $now);
        }
        $this->repository->update($row['uuid'], [
          'state' => DocumentRepository::STATE_RELEASED,
          'released_at' => $now,
          'response_uuid' => (string) $response['id'],
          'changed' => $now,
        ]);
        $this->repository->appendEvent($row['uuid'], (int) $application->id(), 'released', (int) $row['released_by'], $row['docx_sha256'], $row['pdf_sha256'], $now);
      }
      // Cover sheet identifiers become valid and request records open in
      // the same transaction; a failure keeps the release resumable.
      if ($booked !== [] && in_array('request_info', array_column($booked, 'purpose'), TRUE)) {
        $this->appendix?->released($application, $booked, (string) $response['id'], $now);
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
    try {
      $this->extensions?->released($application, $rows, $account);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Document release observer failed: @class.', ['@class' => $exception::class]);
    }
  }

  /**
   * Deletes a draft with its files and history.
   */
  public function discard(ApplicationInterface $application, string $uuid): void {
    $this->withLock($uuid, function () use ($application, $uuid): void {
      $row = $this->requireDraft($application, $uuid);
      $this->repository->delete($uuid);
      $this->deleteFile($row['docx_file_id']);
      $this->deleteFile($row['pdf_file_id']);
      $this->deleteFile($row['cover_pdf_file_id'] ?? NULL);
      if ($row['purpose'] === 'request_info') {
        try {
          // The draft identifier and the draft request record go with it;
          // cron removes leftovers when this fails.
          $this->appendix?->discarded($application, $uuid);
        }
        catch (\Throwable $exception) {
          $this->logger->error('Request record of a discarded draft could not be removed: @class.', ['@class' => $exception::class]);
        }
      }
      $directory = self::DIRECTORY . '/' . $application->uuid() . '/' . $uuid;
      if (is_dir((string) $this->fileSystem->realpath($directory))) {
        $this->fileSystem->deleteRecursive($directory);
      }
    });
  }

  /**
   * Removes every document, file and event of an application.
   */
  public function cleanupForApplication(int $application_id, string $application_uuid): void {
    if ($application_uuid !== '' && !preg_match('/^[A-Za-z0-9-]{1,128}$/D', $application_uuid)) {
      throw new \InvalidArgumentException('Invalid application storage identity.');
    }
    $storage = $this->entityTypeManager->getStorage('file');
    $ids = [];
    foreach ($this->repository->list($application_id) as $row) {
      foreach (['docx_file_id', 'pdf_file_id', 'cover_pdf_file_id'] as $key) {
        if (!empty($row[$key])) {
          $ids[] = (int) $row[$key];
        }
      }
      $this->repository->delete((string) $row['uuid']);
    }
    $directory = self::DIRECTORY . '/' . $application_uuid;
    if ($application_uuid !== '') {
      $ids = array_merge($ids, array_map('intval', $storage->getQuery()->accessCheck(FALSE)
        ->condition('uri', $directory . '/', 'STARTS_WITH')->execute()));
    }
    foreach (array_unique($ids) as $id) {
      $storage->load($id)?->delete();
    }
    $path = $application_uuid === '' ? FALSE : $this->fileSystem->realpath($directory);
    if (is_string($path) && is_dir($path)) {
      $this->fileSystem->deleteRecursive($directory);
    }
  }

  /**
   * Codes that block the release of a document by this account.
   *
   * @return string[]
   *   Empty when the account may release the document.
   */
  public function releaseBlockers(array $row, AccountInterface $account, ?bool $preview_viewed = NULL): array {
    if ($row['state'] === DocumentRepository::STATE_RELEASING) {
      // Decided and stored; any person with the release permission may
      // finish the provision (the decision stays with released_by).
      return [];
    }
    if ($row['state'] !== DocumentRepository::STATE_DRAFT) {
      return ['docgen.document_released'];
    }
    $request = $this->requestBlockers($row);
    if (empty($row['pdf_file_id']) || empty($row['pdf_sha256'])) {
      return array_values(array_unique(array_merge(['docgen.preview_missing'], $request, $this->extensions?->releaseBlockers($row) ?? [])));
    }
    $blockers = $request;
    if (!hash_equals((string) $row['docx_sha256'], (string) $row['pdf_docx_sha256'])) {
      $blockers[] = 'docgen.preview_stale';
    }
    $validation = json_decode((string) ($row['validation'] ?? ''), TRUE);
    if (!is_array($validation) || ($validation['compliant'] ?? FALSE) !== TRUE) {
      $blockers[] = 'docgen.pdf_not_compliant';
    }
    if (!($preview_viewed ?? $this->repository->hasViewed((string) $row['uuid'], (string) $row['pdf_sha256'], (int) $account->id()))) {
      $blockers[] = 'docgen.preview_not_viewed';
    }
    return array_values(array_unique(array_merge($blockers, $this->extensions?->releaseBlockers($row) ?? [])));
  }

  /**
   * Checks waiting releases with batched appendix reads and no preview lookup.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   Authorized applications keyed by ID.
   * @param array<int, array<string, mixed>> $rows
   *   Release metadata rows.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current reader.
   *
   * @return array<string, string[]>
   *   Full blockers keyed by document UUID. The caller decides which are
   *   relevant to waiting work; this does not relax release authorization.
   */
  public function releaseBlockersForEvents(array $applications, array $rows, AccountInterface $account): array {
    $read = function () use ($rows, $account): array {
      $blockers = [];
      foreach ($rows as $row) {
        $blockers[$row['uuid']] = $this->releaseBlockers($row, $account, FALSE);
      }
      return $blockers;
    };
    return $this->appendix instanceof DocgenAppendixBatchInterface
      ? $this->appendix->withCoverRequirements($applications, $rows, $read) : $read();
  }

  /**
   * Blockers of a request letter draft: old render day, missing cover sheet.
   *
   * @return string[]
   *   docgen.deadline_outdated, docgen.cover_sheet_missing or nothing.
   */
  private function requestBlockers(array $row): array {
    if (($row['purpose'] ?? '') !== 'request_info') {
      return [];
    }
    $blockers = [];
    if (self::deadlineOutdated($row, $this->time->getRequestTime())) {
      $blockers[] = 'docgen.deadline_outdated';
    }
    if ($this->appendix instanceof DocgenAppendixInterface && $this->appendix->coverRequired($row)) {
      $validation = json_decode((string) ($row['cover_validation'] ?? ''), TRUE);
      if (empty($row['cover_pdf_file_id']) || empty($row['cover_pdf_sha256'])
        || !is_array($validation) || ($validation['compliant'] ?? FALSE) !== TRUE) {
        $blockers[] = 'docgen.cover_sheet_missing';
      }
    }
    return $blockers;
  }

  /**
   * TRUE for a request letter draft rendered before today.
   *
   * Local days of the installation timezone; drafts from before the render
   * day was stored count from their creation.
   */
  private static function deadlineOutdated(array $row, int $now): bool {
    if (($row['purpose'] ?? '') !== 'request_info' || ($row['state'] ?? '') !== DocumentRepository::STATE_DRAFT) {
      return FALSE;
    }
    $rendered = (int) ($row['rendered_at'] ?? 0);
    $rendered = $rendered > 0 ? $rendered : (int) ($row['created'] ?? 0);
    return date('Y-m-d', $rendered) < date('Y-m-d', $now);
  }

  /**
   * Gate for reading a document's content (DOCX or PDF).
   */
  private function authorizeContent(ApplicationInterface $application, array $row, AccountInterface $account): void {
    if (self::flags($row)['protectedValues']) {
      $this->dataBuilder->authorizeProtectedRead($application, $account, 'docgen_download');
    }
  }

  /**
   * Render bookkeeping of a document row.
   *
   * @return array{missing: string[], protectedOmitted: string[], protectedValues: bool}
   *   Placeholders without a value and protected tags left out when the
   *   draft was rendered, and whether the content carries clear protected
   *   values.
   */
  public static function flags(array $row): array {
    $decoded = json_decode((string) ($row['missing'] ?? ''), TRUE);
    $decoded = is_array($decoded) ? $decoded : [];
    $list = static fn (mixed $value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    return [
      'missing' => $list($decoded['missing'] ?? NULL),
      'protectedOmitted' => $list($decoded['protectedOmitted'] ?? NULL),
      'protectedValues' => ($decoded['protectedValues'] ?? FALSE) === TRUE,
    ];
  }

  /**
   * Staff projection of one document.
   */
  private function project(ApplicationInterface $application, array $row, AccountInterface $account): array {
    $base = '/api/admin/applications/' . $application->id() . '/docgen/documents/' . $row['uuid'];
    $template = $this->entityTypeManager->getStorage('docgen_template')->load((int) $row['template_id']);
    $flags = self::flags($row);
    $hide_fingerprints = $flags['protectedValues'] && !$account->hasPermission(RenderDataBuilder::PROTECTED_PERMISSION);
    $uploaded = $row['docx_source'] === 'uploaded';
    $validation = json_decode((string) ($row['validation'] ?? ''), TRUE);
    $docx = !empty($row['docx_file_id']) ? $this->entityTypeManager->getStorage('file')->load((int) $row['docx_file_id']) : NULL;
    $preview = NULL;
    if (!empty($row['pdf_sha256'])) {
      $preview = [
        'sha256' => $hide_fingerprints ? NULL : $row['pdf_sha256'],
        'docxSha256' => $hide_fingerprints ? NULL : $row['pdf_docx_sha256'],
        // A rendered request letter from an earlier day is rendered again
        // on the next preview: its preview is not current.
        'current' => hash_equals((string) $row['docx_sha256'], (string) $row['pdf_docx_sha256'])
        && !($uploaded === FALSE && self::deadlineOutdated($row, $this->time->getRequestTime())),
        'validation' => is_array($validation) ? $validation : NULL,
        'viewedByYou' => $this->repository->hasViewed((string) $row['uuid'], (string) $row['pdf_sha256'], (int) $account->id()),
        'downloadUrl' => $base . '/pdf',
      ];
    }
    $cover = NULL;
    if (!empty($row['cover_pdf_file_id']) && !empty($row['cover_pdf_sha256'])) {
      $cover_validation = json_decode((string) ($row['cover_validation'] ?? ''), TRUE);
      $cover = [
        'sha256' => $hide_fingerprints ? NULL : $row['cover_pdf_sha256'],
        'validation' => is_array($cover_validation) ? $cover_validation : NULL,
        'downloadUrl' => $base . '/cover-pdf',
      ];
    }
    $blockers = $this->releaseBlockers($row, $account);
    if (!$this->scopeAccess->canWorkOnCase($application, $account, DocgenScopeAccess::PERMISSION_RELEASE, TRUE)) {
      $blockers[] = 'docgen.access_denied';
    }
    return [
      'id' => $row['uuid'],
      'title' => $row['title'],
      'purpose' => $row['purpose'],
      'closesProcedure' => in_array($row['purpose'], DocgenTemplateInterface::CLOSING_PURPOSES, TRUE),
      'state' => $row['state'],
      'template' => [
        'id' => $template instanceof DocgenTemplateInterface ? $template->uuid() : NULL,
        'family' => $row['template_family'],
        'version' => (int) $row['template_version'],
        'name' => $template instanceof DocgenTemplateInterface ? (string) $template->label() : NULL,
        'sha256' => $row['template_sha256'],
      ],
      'dataSha256' => $hide_fingerprints ? NULL : $row['data_sha256'],
      // After an upload the gaps of the rendered draft say nothing about the
      // current file: they move to renderedMissing.
      'missing' => $uploaded ? [] : $flags['missing'],
      'protectedOmitted' => $uploaded ? [] : $flags['protectedOmitted'],
      'renderedMissing' => $flags['missing'],
      'protectedValues' => $flags['protectedValues'],
      'docx' => [
        'sha256' => $hide_fingerprints ? NULL : $row['docx_sha256'],
        'revision' => (int) $row['docx_revision'],
        'source' => $row['docx_source'],
        'size' => $docx instanceof FileInterface ? (int) $docx->getSize() : NULL,
        'downloadUrl' => $base . '/docx',
      ],
      'preview' => $preview,
      // Request letters: the cover sheet released with the letter as the
      // next document of the same response, NULL without one.
      'cover' => $cover,
      'renderedAt' => empty($row['rendered_at']) ? NULL : (int) $row['rendered_at'],
      'canRelease' => $blockers === [],
      'releaseBlockers' => array_values(array_unique($blockers)),
      'createdAt' => (int) $row['created'],
      'createdBy' => $this->userName((int) $row['created_by']),
      'changedAt' => (int) $row['changed'],
      'releasedAt' => $row['released_at'] === NULL ? NULL : (int) $row['released_at'],
      'releasedBy' => $row['released_by'] === NULL ? NULL : $this->userName((int) $row['released_by']),
      'responseId' => $row['response_uuid'],
      'releaseId' => $row['release_uuid'],
      'historyIntact' => $this->repository->verifyChain((string) $row['uuid']),
    ];
  }

  /**
   * The form of an application (current entity; settings are per form).
   */
  private function form(ApplicationInterface $application): ?ContentEntityInterface {
    if (!$application->hasField('field_schema') || $application->get('field_schema')->isEmpty()) {
      return NULL;
    }
    $form = $application->get('field_schema')->entity ?? NULL;
    return $form instanceof ContentEntityInterface ? $form : NULL;
  }

  /**
   * Loads a document of the application or throws 404.
   */
  private function requireRow(ApplicationInterface $application, string $uuid): array {
    $row = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)
      ? $this->repository->find((int) $application->id(), strtolower($uuid))
      : NULL;
    return $row ?? throw new DocgenApiException('docgen.document_not_found', 404);
  }

  /**
   * Loads a draft or throws.
   */
  private function requireDraft(ApplicationInterface $application, string $uuid): array {
    $row = $this->requireRow($application, $uuid);
    if ($row['state'] === DocumentRepository::STATE_RELEASING) {
      throw new DocgenApiException('docgen.document_releasing', 409);
    }
    if ($row['state'] !== DocumentRepository::STATE_DRAFT) {
      throw new DocgenApiException('docgen.document_released', 409);
    }
    return $row;
  }

  /**
   * Persists under the application's shared mutation lock.
   *
   * The lock is the one application deletion, every application save (the
   * retention run's anonymization too) and outbound delivery take. Inside it
   * the application is loaded fresh: a deleted or anonymized application
   * gets no new document content. A deletion or anonymization that starts
   * later finds the stored rows and removes them with the application.
   */
  private function underApplicationLock(ApplicationInterface $application, callable $persist): mixed {
    if (!$this->applicationLock instanceof ApplicationDependentMutationLock) {
      // Fail closed: without the lock nothing is stored for a case.
      throw new DocgenApiException('docgen.storage_unavailable', 503);
    }
    if (!$this->applicationLock->acquireApplication($application)) {
      if (!$this->freshApplication($application) instanceof ApplicationInterface) {
        throw new DocgenApiException('docgen.application_not_found', 404);
      }
      throw new DocgenApiException('docgen.locked', 409, [], DocgenClient::DEFAULT_RETRY_AFTER_SECONDS);
    }
    try {
      $current = $this->freshApplication($application);
      if (!$current instanceof ApplicationInterface) {
        throw new DocgenApiException('docgen.application_not_found', 404);
      }
      $data = json_decode((string) ($current->getData() ?? ''), TRUE);
      if (is_array($data) && ($data['_anonymized'] ?? NULL) === TRUE) {
        throw new DocgenApiException('docgen.application_anonymized', 409);
      }
      return $persist();
    }
    finally {
      $this->applicationLock->release($application);
    }
  }

  /**
   * Loads the application from storage, bypassing the static entity cache.
   */
  private function freshApplication(ApplicationInterface $application): ?ApplicationInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $storage->resetCache([(int) $application->id()]);
    $current = $storage->load((int) $application->id());
    return $current instanceof ApplicationInterface && $current->uuid() === $application->uuid() ? $current : NULL;
  }

  /**
   * Runs a mutation under the document lock.
   */
  private function withLock(string $uuid, callable $callback): mixed {
    $name = $this->lockName(strtolower($uuid));
    if (!$this->lock->acquire($name, self::LOCK_SECONDS)) {
      throw new DocgenApiException('docgen.locked', 409);
    }
    try {
      return $callback();
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Lock name of a document.
   */
  private function lockName(string $uuid): string {
    return 'beantragt_docgen:document:' . $uuid;
  }

  /**
   * Writes a permanent private file below the document directory.
   */
  private function writeFile(ApplicationInterface $application, string $uuid, string $name, string $bytes, string $filename, AccountInterface $account): FileInterface {
    $directory = self::DIRECTORY . '/' . $application->uuid() . '/' . $uuid;
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new DocgenApiException('docgen.storage_unavailable', 503);
    }
    $file = $this->fileRepository->writeData($bytes, $directory . '/' . $name, FileExists::Replace);
    $file->setFilename($filename);
    $file->setOwnerId((int) $account->id());
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Loads a document file of this application.
   */
  private function loadFile(ApplicationInterface $application, mixed $file_id): FileInterface {
    $file = empty($file_id) ? NULL : $this->entityTypeManager->getStorage('file')->load((int) $file_id);
    $prefix = self::DIRECTORY . '/' . $application->uuid() . '/';
    if (!$file instanceof FileInterface || !str_starts_with($file->getFileUri(), $prefix) || !is_file($file->getFileUri())) {
      throw new DocgenApiException('docgen.document_not_found', 404);
    }
    return $file;
  }

  /**
   * Reads a document file and checks its hash.
   */
  private function readFile(ApplicationInterface $application, mixed $file_id, string $sha256): string {
    $bytes = @file_get_contents($this->loadFile($application, $file_id)->getFileUri());
    if (!is_string($bytes) || !hash_equals($sha256, hash('sha256', $bytes))) {
      throw new DocgenApiException('docgen.integrity_failed', 500);
    }
    return $bytes;
  }

  /**
   * Deletes a file entity when it exists.
   */
  private function deleteFile(mixed $file_id): void {
    if (!empty($file_id)) {
      $this->entityTypeManager->getStorage('file')->load((int) $file_id)?->delete();
    }
  }

  /**
   * Routes of the next decision and communication, NULL without delivery.
   *
   * @return array{decision: array{route: string, reason: ?string}, communication: array{route: string, reason: ?string}}|null
   *   Release previews (ExternalResponseService::deliveryPreview()).
   */
  public function deliveryPreview(ApplicationInterface $application): ?array {
    return $this->responses?->deliveryPreview($application);
  }

  /**
   * Display name of a user, or NULL.
   */
  private function userName(int $uid): ?string {
    $user = $uid > 0 ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;
    return $user instanceof UserInterface ? (string) $user->getDisplayName() : NULL;
  }

}
