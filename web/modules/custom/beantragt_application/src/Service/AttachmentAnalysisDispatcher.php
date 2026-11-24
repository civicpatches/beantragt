<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\AttachmentAnalysisMetadataLockedException;
use Drupal\beantragt_application\Exception\AttachmentAnalysisSourceException;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Queues and dispatches submitted attachment analysis jobs.
 */
final class AttachmentAnalysisDispatcher {

  public const QUEUE_NAME = 'beantragt_application_attachment_analysis';
  public const DEFAULT_MAX_ATTEMPTS = 3;
  public const RETRY_DELAY_SECONDS = 300;

  private const MAX_ATTACHMENT_BYTES = 10485760;
  /**
   * Lease of a pending queued run; also used by the staff rerun route.
   */
  public const PENDING_DISPATCH_LEASE_SECONDS = 3600;
  private const ANALYSIS_RUN_ID_PATTERN = '/^[a-f0-9]{32}$/';
  private const DEFAULT_TIMEOUT_SECONDS = 240;
  private const DEFAULT_DDEV_NUXT_BASE_URL = 'http://node-dev:3000';
  private const DEFAULT_DEVELOPMENT_INTELLIGENCE_SERVICE_KEY = 'beantragt-intelligence-local-development';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
    private readonly ?LockBackendInterface $lock = NULL,
    private readonly ?TimeInterface $time = NULL,
    private readonly ?DocumentGuardClientInterface $documentGuardClient = NULL,
    private readonly ?FileSystemInterface $fileSystem = NULL,
    private readonly ?FileRepositoryInterface $fileRepository = NULL,
    private readonly ?AttachmentStorageQuota $attachmentStorageQuota = NULL,
    private readonly ?AttachmentAnalysisMetadataWriter $metadataWriter = NULL,
    private readonly ?AttachmentAnalysisSourcePreparer $sourcePreparer = NULL,
  ) {}

  /**
   * Queues a newly claimed attachment for asynchronous analysis.
   */
  public function enqueue(ApplicationAttachmentInterface $attachment): bool {
    if (!$this->isDispatchableAttachment($attachment)) {
      return FALSE;
    }

    $item = [
      'attachmentId' => (string) $attachment->uuid(),
      'applicationId' => (int) $attachment->get('field_application')->target_id,
    ];
    $profile = $this->attachmentFieldAnalysisProfile($attachment);
    $story = is_array($profile) ? $this->queuedStory($profile) : NULL;
    if ($story !== NULL && $story !== AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
      $item['story'] = $story;
    }
    $this->queueFactory->get(self::QUEUE_NAME)->createItem($item);

    return TRUE;
  }

  /**
   * Prepares Drupal's database queue before opening the rerun transaction.
   */
  public function prepareRerunQueue(Connection $database): void {
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    if (!$queue instanceof DatabaseQueue || $this->rerunQueueTableExists($database)) {
      return;
    }
    // Lazy creation in DatabaseQueue::createItem() would commit MySQL writes.
    if ($database->inTransaction()) {
      throw new \RuntimeException('Attachment analysis queue storage is not initialized.');
    }
    try {
      $database->schema()->createTable(DatabaseQueue::TABLE_NAME, $queue->schemaDefinition());
    }
    catch (\Exception $exception) {
      // Another request may have initialized the queue concurrently.
      if (!$this->rerunQueueTableExists($database)) {
        throw $exception;
      }
    }
  }

  /**
   * Checks for the queue table, including concurrent first-use creation.
   *
   * @phpstan-impure
   */
  private function rerunQueueTableExists(Connection $database): bool {
    return $database->schema()->tableExists(DatabaseQueue::TABLE_NAME);
  }

  /**
   * Queues a manual rerun whose run the rerun route has already started.
   *
   * The story is pending with a fresh lease, so enqueue() would skip it.
   * The item carries the run id instead; processing accepts the item only
   * while that run id is still the story's current one.
   */
  public function enqueueRerun(ApplicationAttachmentInterface $attachment, string $story, string $analysis_run_id): void {
    $created = $this->queueFactory->get(self::QUEUE_NAME)->createItem([
      'attachmentId' => (string) $attachment->uuid(),
      'applicationId' => (int) $attachment->get('field_application')->target_id,
      'story' => $story,
      'analysisRunId' => $analysis_run_id,
    ]);
    if ($created === FALSE) {
      throw new \RuntimeException('Attachment analysis queue refused the item.');
    }
  }

  /**
   * Queues a story whose upload run failed after the claim.
   *
   * The claim-time enqueue skips a story while the run the upload started
   * still holds its lease. When that run fails later, nothing else would
   * queue the story, so the analysis update route calls this. Only the story
   * the queue runs for the profile is queued (story A under the external
   * policies, on the guard derivative); any other story returns FALSE.
   */
  public function enqueueAfterUploadRunFailed(ApplicationAttachmentInterface $attachment, string $story): bool {
    $profile = $this->attachmentFieldAnalysisProfile($attachment);
    if (!is_array($profile) || $this->queuedStory($profile) !== $story) {
      return FALSE;
    }
    return $this->enqueue($attachment);
  }

  /**
   * Processes one queued attachment.
   *
   * @param string $attachment_uuid
   *   Attachment UUID.
   * @param string|null $requested_story
   *   Story of the queue item, NULL for items from before the story mode.
   * @param string|null $rerun_run_id
   *   Run id of a staff rerun item; the rerun route already set the story
   *   pending with this run id.
   */
  public function processQueuedAttachment(string $attachment_uuid, ?string $requested_story = NULL, ?string $rerun_run_id = NULL): AttachmentAnalysisDispatchResult {
    $rerun = $rerun_run_id !== NULL;
    if ($rerun && (!in_array($requested_story, [
      AttachmentAnalysisMetadataWriter::STORY_LEGACY,
      ...AttachmentAnalysisMetadataWriter::STORIES,
    ], TRUE) || preg_match(self::ANALYSIS_RUN_ID_PATTERN, $rerun_run_id) !== 1)) {
      return AttachmentAnalysisDispatchResult::done('attachment.not_dispatchable');
    }
    $dispatchable = fn (ApplicationAttachmentInterface $candidate): bool => $rerun
      ? $this->isRerunDispatchable($candidate, (string) $requested_story, (string) $rerun_run_id)
      : $this->isDispatchableAttachment($candidate);

    $attachment = $this->loadAttachment($attachment_uuid);
    if (!$attachment instanceof ApplicationAttachmentInterface) {
      return AttachmentAnalysisDispatchResult::done('attachment.not_found');
    }
    if (!$dispatchable($attachment)) {
      return AttachmentAnalysisDispatchResult::done('attachment.not_dispatchable');
    }

    $analysis_lock_name = NULL;
    if ($this->lock instanceof LockBackendInterface) {
      $application = $this->loadAttachmentApplication($attachment);
      if (!$application instanceof ApplicationInterface) {
        return AttachmentAnalysisDispatchResult::done('application.not_found');
      }
      $analysis_lock_name = Application::analysisLockName((int) $application->id());
      if (!$this->lock->acquire($analysis_lock_name, $this->analysisLockTtlSeconds())) {
        return AttachmentAnalysisDispatchResult::retry('application.analysis_locked');
      }

      $attachment_storage = $this->entityTypeManager->getStorage('application_attachment');
      $attachment_id = (int) $attachment->id();
      if ($attachment_id > 0) {
        $attachment_storage->resetCache([$attachment_id]);
      }
      $attachment = $this->loadAttachment($attachment_uuid);
      if (!$attachment instanceof ApplicationAttachmentInterface
        || (int) ($attachment->get('field_application')->target_id ?? 0) !== (int) $application->id()
        || !$dispatchable($attachment)) {
        $this->lock->release($analysis_lock_name);
        return AttachmentAnalysisDispatchResult::done('attachment.not_dispatchable');
      }
    }

    try {
      $analysis_profile = $this->attachmentFieldAnalysisProfile($attachment);
      $story = $rerun
        ? $requested_story
        : (is_array($analysis_profile) ? $this->queuedStory($analysis_profile) : NULL);
      if (!is_array($analysis_profile)
        || $story === NULL
        || (!$rerun && $requested_story !== NULL && $requested_story !== $story)) {
        return AttachmentAnalysisDispatchResult::done('attachment.not_dispatchable');
      }

      $attempt = $this->nextAttempt($attachment, $story);
      $max_attempts = $this->maxAttempts();
      $analysis_run_id = NULL;
      if ($story === AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
        // A legacy rerun keeps the run id the route minted; the legacy
        // callback path checks it.
        $analysis_run_id = $rerun_run_id;
        $this->markAttachment($attachment, 'pending', $this->workerMetadata($attachment, 'analysis_queued', $attempt, $max_attempts, $analysis_run_id));
      }
      else {
        // Each story run gets its own run id and lease; the other story's
        // record stays untouched. A rerun keeps the run id of its route.
        try {
          $written = $this->metadataWriter()->mergeStory(
            $attachment,
            $story,
            $this->storyWorkerPatch($attachment, 'analysis_queued', $attempt, $max_attempts),
            !$rerun,
          );
        }
        catch (AttachmentAnalysisMetadataLockedException) {
          return AttachmentAnalysisDispatchResult::retry('attachment.analysis_metadata_locked');
        }
        $attachment = $written['attachment'];
        $analysis_run_id = (string) $written['story']['analysisRunId'];
        if ($rerun && $analysis_run_id !== $rerun_run_id) {
          return AttachmentAnalysisDispatchResult::done('attachment.not_dispatchable');
        }
      }

      // The guard can finish a protected pre-check without a model service.
      // If it produces a derivative, dispatchToNuxt still checks the key.
      if ($this->intelligenceServiceAuthorizationHeader() === ''
        && !AttachmentAnalysisSourcePreparer::requiresSovereignDerivative($analysis_profile, $story)) {
        return $this->dispatchFailureResult(
          $attachment,
          $attachment_uuid,
          $attempt,
          $max_attempts,
          NULL,
          $story,
          $analysis_run_id,
        );
      }

      // Only a staff rerun of story A sends the consented original under
      // external_allowed; the queue itself always uses the guard derivative.
      $consented_original = $rerun
        && $story === AttachmentAnalysisMetadataWriter::STORY_CITIZEN
        && ($analysis_profile['egressPolicy'] ?? NULL) === 'external_allowed'
        && $this->hasExternalAnalysisConsent($attachment);

      if ($this->requiresGuardDerivative($analysis_profile) && !$consented_original) {
        try {
          if (!$this->ensureGuardDerivative($attachment)) {
            $this->markStory(
              $attachment,
              $story,
              'failed',
              'guard_blocked',
              $attempt,
              $max_attempts,
              [],
              $analysis_run_id,
            );
            return AttachmentAnalysisDispatchResult::done('analysis.guard_blocked');
          }
        }
        catch (\Throwable) {
          $this->logger->warning('Attachment document guard failed for @attachment on attempt @attempt.', [
            '@attachment' => $attachment_uuid,
            '@attempt' => $attempt,
          ]);
          $exhausted = $attempt >= $max_attempts;
          $guard_fields = $exhausted && $attachment->hasField('field_guard_status')
            ? ['field_guard_status' => 'failed']
            : [];
          if ($story === AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
            foreach ($guard_fields as $field_name => $value) {
              $attachment->set($field_name, $value);
            }
            $guard_fields = [];
          }
          // Bewusst auch bei bevorstehendem Retry 'failed' und nicht
          // 'pending': isDispatchableAttachment() leased 'pending' fuer
          // 3600 s, der Queue-Retry kaeme aber nach 300 s und wuerde
          // verschluckt. Der Retry-Kontext steht in den Worker-Metadaten.
          $this->markStory(
            $attachment,
            $story,
            'failed',
            $exhausted ? 'guard_unavailable' : 'guard_retry_pending',
            $attempt,
            $max_attempts,
            $guard_fields,
            $analysis_run_id,
          );
          return $exhausted
            ? AttachmentAnalysisDispatchResult::done('analysis.guard_unavailable')
            : AttachmentAnalysisDispatchResult::retry('analysis.guard_unavailable');
        }
      }

      try {
        $source = $this->prepareSource($attachment, $analysis_profile, $story, $consented_original);
      }
      catch (AttachmentAnalysisSourceException $e) {
        $this->logger->warning('Attachment analysis source failed for @attachment attempt @attempt: @code', [
          '@attachment' => $attachment_uuid,
          '@attempt' => $attempt,
          '@code' => $e->analysisCode,
        ]);
        $exhausted = !$e->retryable || $attempt >= $max_attempts;
        $this->markStory($attachment, $story, 'failed', $e->analysisCode, $attempt, $max_attempts, [], $analysis_run_id);
        return $exhausted
          ? AttachmentAnalysisDispatchResult::done('analysis.' . $e->analysisCode)
          : AttachmentAnalysisDispatchResult::retry('analysis.' . $e->analysisCode);
      }
      catch (\Throwable $e) {
        return $this->dispatchFailureResult($attachment, $attachment_uuid, $attempt, $max_attempts, $e, $story, $analysis_run_id);
      }

      try {
        $this->dispatchToNuxt($attachment, $source, $story, $analysis_run_id);
        return AttachmentAnalysisDispatchResult::done('analysis.dispatched');
      }
      catch (\Throwable $e) {
        return $this->dispatchFailureResult($attachment, $attachment_uuid, $attempt, $max_attempts, $e, $story, $analysis_run_id);
      }
    }
    catch (AttachmentAnalysisMetadataLockedException) {
      // A terminal story write still met a held claim lock after waiting out
      // the lock TTL, which only a writer re-taking the lock for over 30 s
      // causes. The run stays pending until its lease expires; the rerun
      // route is the recovery.
      return AttachmentAnalysisDispatchResult::retry('attachment.analysis_metadata_locked');
    }
    finally {
      if (is_string($analysis_lock_name)) {
        $this->lock->release($analysis_lock_name);
      }
    }
  }

  /**
   * Returns the retry delay for the queue worker.
   */
  public function retryDelaySeconds(): int {
    return self::RETRY_DELAY_SECONDS;
  }

  /**
   * Applies the existing dispatch retry policy to one failed request.
   */
  private function dispatchFailureResult(ApplicationAttachmentInterface $attachment, string $attachment_uuid, int $attempt, int $max_attempts, ?\Throwable $e, string $story = AttachmentAnalysisMetadataWriter::STORY_LEGACY, ?string $analysis_run_id = NULL): AttachmentAnalysisDispatchResult {
    $context = [
      '@attachment' => $attachment_uuid,
      '@attempt' => $attempt,
    ];
    if ($e === NULL) {
      $this->logger->warning('Attachment analysis key is not configured for @attachment on attempt @attempt.', $context);
    }
    else {
      $this->logger->warning('Attachment analysis dispatch failed for @attachment on attempt @attempt: @exception.', $context + [
        '@exception' => get_class($e),
      ]);
    }
    $dispatch_uncertain = $e instanceof ConnectException
      || ($e instanceof RequestException && !$e->hasResponse());
    if ($dispatch_uncertain) {
      // The downstream request may still be running. Keep the initial durable
      // pending state and consume this queue item so the same private bytes are
      // not dispatched twice. A late callback may still replace pending with
      // its terminal result.
      return AttachmentAnalysisDispatchResult::done('analysis.dispatch_uncertain');
    }

    $this->markStory($attachment, $story, 'failed', 'worker_dispatch_failed', $attempt, $max_attempts, [], $analysis_run_id);

    return $attempt < $max_attempts
      ? AttachmentAnalysisDispatchResult::retry('analysis.dispatch_failed')
      : AttachmentAnalysisDispatchResult::done('analysis.dispatch_failed');
  }

  /**
   * Chooses the bytes one run may send and prepares them.
   *
   * The external policies read the persisted full-profile guard derivative
   * (as before); a staff rerun of story A under external_allowed with
   * consent reads the original. sovereign_only and local_only go through the
   * source preparer: PDFs leave as rendered pages, never as the raw file.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment.
   * @param array<string, mixed> $analysis_profile
   *   Field analysis profile.
   * @param string $story
   *   Server-selected story of this run, including legacy queue items.
   * @param bool $consented_original
   *   TRUE for a staff rerun of story A that may send the consented original.
   *
   * @return array<string, mixed>
   *   Prepared source: kind file (data, mediaType, sourceKind, optional
   *   redaction) or kind pages (pages, pageCount, truncated, renderedFrom,
   *   redaction, sourceKind, sourceMediaType, size).
   */
  private function prepareSource(ApplicationAttachmentInterface $attachment, array $analysis_profile, string $story, bool $consented_original): array {
    if ($this->requiresGuardDerivative($analysis_profile) && !$consented_original) {
      $file = $this->guardDerivativeFile($attachment);
      if (!$file instanceof FileInterface) {
        throw new \RuntimeException('Attachment analysis source file is missing.');
      }
      $data = $this->readFileBytes($file);
      $this->validateGuardDerivativeBytes($attachment, $data);
      return [
        'kind' => 'file',
        'data' => $data,
        'mediaType' => 'application/pdf',
        'sourceKind' => 'derivative',
        'redaction' => AttachmentAnalysisSourcePreparer::REDACTION_REDACTED,
      ];
    }

    $file = $this->attachmentFile($attachment);
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException('Attachment analysis source file is missing.');
    }
    $data = $this->readFileBytes($file);
    $media_type = $this->attachmentMimeType($attachment, $file);
    if (!AttachmentAnalysisSourcePreparer::handlesProfile($analysis_profile)) {
      return [
        'kind' => 'file',
        'data' => $data,
        'mediaType' => $media_type,
        'sourceKind' => 'original',
      ];
    }

    // A transient sovereign derivative may hide behind these pages. It is
    // never the persisted guard derivative, so the source stays 'original'
    // and Nuxt accepts pages only for a sovereign or local profile.
    return $this->sourcePreparer()->prepareSovereign($analysis_profile, $data, $media_type, $story) + [
      'sourceKind' => 'original',
      'sourceMediaType' => $media_type,
      'size' => strlen($data),
    ];
  }

  /**
   * Reads the bytes of one file entity.
   */
  private function readFileBytes(FileInterface $file): string {
    $uri = (string) $file->getFileUri();
    if ($uri === '' || !is_file($uri)) {
      throw new \RuntimeException('Attachment file bytes are unavailable.');
    }
    return $this->readBoundedFile($uri);
  }

  /**
   * Returns the source preparer.
   */
  private function sourcePreparer(): AttachmentAnalysisSourcePreparer {
    return $this->sourcePreparer ?? new AttachmentAnalysisSourcePreparer($this->documentGuardClient);
  }

  /**
   * Returns TRUE when the citizen consented to external analysis.
   */
  private function hasExternalAnalysisConsent(ApplicationAttachmentInterface $attachment): bool {
    return $attachment->hasField('field_external_analysis_consent')
      && (string) ($attachment->get('field_external_analysis_consent')->value ?? '0') === '1';
  }

  /**
   * Dispatches one prepared source to the Nuxt analysis worker endpoint.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment.
   * @param array<string, mixed> $source
   *   Source from prepareSource().
   * @param string $story
   *   Story of this run.
   * @param string|null $analysis_run_id
   *   Run id Nuxt writes the result back with.
   */
  private function dispatchToNuxt(ApplicationAttachmentInterface $attachment, array $source, string $story = AttachmentAnalysisMetadataWriter::STORY_LEGACY, ?string $analysis_run_id = NULL): void {
    $base_url = $this->nuxtInternalBaseUrl();
    if ($base_url === '') {
      throw new \RuntimeException('Nuxt internal base URL is not configured.');
    }

    $intelligence_header = $this->intelligenceServiceAuthorizationHeader();
    if ($intelligence_header === '') {
      throw new \RuntimeException('BEANTRAGT_INTELLIGENCE_SERVICE_KEY is not configured for attachment analysis outside local development.');
    }

    $service_header = $this->serviceAuthorizationHeader();
    if ($service_header === '') {
      throw new \RuntimeException('Nuxt service authorization is not configured.');
    }

    $form_uuid = $this->referencedEntityUuid($attachment, 'field_schema');
    if ($form_uuid === '') {
      throw new \RuntimeException('Attachment form schema is missing.');
    }

    $payload = [
      'attachmentId' => (string) $attachment->uuid(),
      'applicationId' => $this->referencedEntityUuid($attachment, 'field_application'),
      'formId' => $form_uuid,
      'fieldId' => (string) ($attachment->get('field_field_id')->value ?? ''),
    ];
    if (($source['kind'] ?? NULL) === 'pages') {
      // The media type names the source (application/pdf); every page
      // carries its own image type. No raw PDF leaves Drupal on this path.
      $payload += [
        'mediaType' => (string) $source['sourceMediaType'],
        'size' => (int) $source['size'],
        'citizenConsent' => $this->hasExternalAnalysisConsent($attachment),
        'sourceKind' => (string) $source['sourceKind'],
        'language' => $this->analysisLanguage($attachment),
        'renderedFrom' => (string) $source['renderedFrom'],
        'pageCount' => (int) $source['pageCount'],
        'truncated' => (bool) $source['truncated'],
        'redaction' => (string) $source['redaction'],
        'pages' => array_map(
          static fn (array $page): array => [
            'mediaType' => (string) $page['mediaType'],
            'data' => base64_encode((string) $page['data']),
          ],
          $source['pages'],
        ),
      ];
    }
    else {
      $data = (string) $source['data'];
      $payload += [
        'mediaType' => (string) $source['mediaType'],
        'size' => strlen($data),
        'citizenConsent' => $this->hasExternalAnalysisConsent($attachment),
        'sourceKind' => (string) $source['sourceKind'],
        'language' => $this->analysisLanguage($attachment),
        'data' => base64_encode($data),
      ];
      if (is_string($source['redaction'] ?? NULL)) {
        $payload['redaction'] = $source['redaction'];
      }
    }
    if ($analysis_run_id !== NULL) {
      // Nuxt writes the result back with this run id: under stories.{story}
      // for a story, on the legacy path for a legacy rerun.
      if ($story !== AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
        $payload['story'] = $story;
      }
      $payload['analysisRunId'] = $analysis_run_id;
    }

    $response = $this->httpClient->request('POST', $base_url . '/api/internal/attachments/analyze', [
      'allow_redirects' => FALSE,
      'headers' => [
        'Accept' => 'application/json',
        'Authorization' => $intelligence_header,
        'Content-Type' => 'application/json',
        'X-Beantragt-Service-Authorization' => $service_header,
      ],
      'json' => $payload,
      'timeout' => $this->timeoutSeconds(),
    ]);

    $status_code = $response->getStatusCode();
    if ($status_code < 200 || $status_code >= 300) {
      throw new \RuntimeException(sprintf('Nuxt analysis worker returned HTTP %d.', $status_code));
    }
  }

  /**
   * Returns TRUE when the attachment should be queued.
   */
  private function isDispatchableAttachment(ApplicationAttachmentInterface $attachment): bool {
    if (!$this->hasDispatchableFile($attachment)) {
      return FALSE;
    }

    $analysis_profile = $this->attachmentFieldAnalysisProfile($attachment);
    if ($analysis_profile === NULL) {
      return FALSE;
    }
    $story = $this->queuedStory($analysis_profile);
    if ($story === NULL) {
      return FALSE;
    }

    $stored = $this->storedMetadata($attachment);
    // A profile edit may have replaced a story profile with a legacy one
    // since upload. Keep the citizen's terminal skip across that switch.
    $record = $stored;
    if (AttachmentAnalysisMetadataWriter::isV2($stored)) {
      $record_story = $story === AttachmentAnalysisMetadataWriter::STORY_LEGACY ? AttachmentAnalysisMetadataWriter::STORY_CITIZEN : $story;
      $record = AttachmentAnalysisMetadataWriter::storyRecord($stored, $record_story);
    }
    if (($record['code'] ?? NULL) === AttachmentAnalysisSourcePreparer::REDACTION_NOT_POSSIBLE) {
      return FALSE;
    }

    if ($story === AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
      $analysis_status = (string) ($attachment->get('field_analysis_status')->value ?? 'not_requested');
      if ($analysis_status === 'complete') {
        return FALSE;
      }
      if ($analysis_status === 'pending'
        && $attachment->getChangedTime() >= $this->requestTime() - self::PENDING_DISPATCH_LEASE_SECONDS) {
        return FALSE;
      }
    }
    else {
      // Only the queued story counts: a pending or complete story A never
      // blocks story B, and the overall status is not consulted. A legacy run
      // from before a profile switch is kept, never overwritten by a story.
      if (AttachmentAnalysisMetadataWriter::holdsLegacyRun($stored)) {
        return FALSE;
      }
      if (($record['status'] ?? NULL) === 'complete'
        || AttachmentAnalysisMetadataWriter::hasActiveLease($record, $this->requestTime(), self::PENDING_DISPATCH_LEASE_SECONDS)) {
        return FALSE;
      }
    }

    return AttachmentAnalysisMetadataWriter::modelClassAllowed($analysis_profile);
  }

  /**
   * Returns TRUE while a staff rerun item still owns the story's run.
   *
   * The complete check and the lease are skipped for this story only: the
   * rerun route set it pending with this run id. A newer rerun (other run
   * id) or a finished run makes the item obsolete.
   */
  private function isRerunDispatchable(ApplicationAttachmentInterface $attachment, string $story, string $run_id): bool {
    if (!$this->hasDispatchableFile($attachment)) {
      return FALSE;
    }
    $analysis_profile = $this->attachmentFieldAnalysisProfile($attachment);
    if (!AttachmentAnalysisMetadataWriter::rerunStoryAllowed($analysis_profile, $story)) {
      return FALSE;
    }
    $stored = $this->storedMetadata($attachment);
    if ($story === AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
      if (AttachmentAnalysisMetadataWriter::isV2($stored)) {
        return FALSE;
      }
      $record = $stored;
      $status = $attachment->get('field_analysis_status')->value;
    }
    else {
      $record = AttachmentAnalysisMetadataWriter::storyRecord($stored, $story);
      $status = $record['status'] ?? NULL;
    }
    return ($record['code'] ?? NULL) !== AttachmentAnalysisSourcePreparer::REDACTION_NOT_POSSIBLE
      && is_string($record['analysisRunId'] ?? NULL)
      && hash_equals($record['analysisRunId'], $run_id)
      && in_array($status, ['pending', 'failed'], TRUE);
  }

  /**
   * Returns TRUE for a bound, clean attachment with a file.
   */
  private function hasDispatchableFile(ApplicationAttachmentInterface $attachment): bool {
    if ($attachment->get('field_application')->isEmpty()
      || $attachment->get('field_schema')->isEmpty()
      || $attachment->get('field_file')->isEmpty()
      || trim((string) ($attachment->get('field_field_id')->value ?? '')) === '') {
      return FALSE;
    }

    if ($attachment->hasField('field_scan_status')) {
      $scan_status = (string) ($attachment->get('field_scan_status')->value ?? AttachmentScanResult::STATUS_CLEAN);
      if ($scan_status !== AttachmentScanResult::STATUS_CLEAN) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns the story the queue runs after the claim, or NULL for none.
   *
   * Legacy profiles keep their single run. In story mode the queue runs
   * story A on the guard derivative for external_redacted and
   * external_allowed (the upload path skips them without consent) and
   * story B only under sovereign_only. Story A under sovereign_only runs on
   * the upload path, never in the queue.
   *
   * @param array<string, mixed> $analysis_profile
   *   Field analysis profile.
   */
  private function queuedStory(array $analysis_profile): ?string {
    if (!AttachmentAnalysisMetadataWriter::isStoryProfile($analysis_profile)) {
      return AttachmentAnalysisMetadataWriter::STORY_LEGACY;
    }
    $egress_policy = $analysis_profile['egressPolicy'] ?? NULL;
    if (in_array($egress_policy, ['external_redacted', 'external_allowed'], TRUE)) {
      return AttachmentAnalysisMetadataWriter::profileHasCitizenStory($analysis_profile)
        ? AttachmentAnalysisMetadataWriter::STORY_CITIZEN
        : NULL;
    }
    if ($egress_policy === 'sovereign_only') {
      return AttachmentAnalysisMetadataWriter::profileHasStaffStory($analysis_profile)
        ? AttachmentAnalysisMetadataWriter::STORY_STAFF
        : NULL;
    }
    return NULL;
  }

  /**
   * Returns the shared metadata writer.
   */
  private function metadataWriter(): AttachmentAnalysisMetadataWriter {
    return $this->metadataWriter ?? new AttachmentAnalysisMetadataWriter($this->entityTypeManager, $this->lock, $this->time);
  }

  /**
   * Decodes the stored analysis metadata.
   *
   * @return array<string, mixed>
   *   Decoded metadata.
   */
  private function storedMetadata(ApplicationAttachmentInterface $attachment): array {
    return AttachmentAnalysisMetadataWriter::decode((string) ($attachment->get('field_analysis_metadata')->value ?? ''));
  }

  /**
   * Returns the enabled analysis profile for the attachment's form field.
   *
   * @return array<string, mixed>|null
   *   The server-owned analysis profile, or NULL when no dispatch is allowed.
   */
  private function attachmentFieldAnalysisProfile(ApplicationAttachmentInterface $attachment): ?array {
    $field_id = trim((string) ($attachment->get('field_field_id')->value ?? ''));
    if ($field_id === '') {
      return NULL;
    }

    $schema_entity = $attachment->get('field_schema')->entity;
    if (!$schema_entity instanceof FormSchemaInterface) {
      return NULL;
    }

    try {
      $schema = json_decode((string) $schema_entity->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($schema)) {
      return NULL;
    }

    foreach (($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach (($section['fields'] ?? []) as $field) {
        if (!is_array($field) || ($field['id'] ?? NULL) !== $field_id) {
          continue;
        }
        $analysis = $field['analysis'] ?? NULL;
        if (($field['type'] ?? NULL) === 'file'
          && is_array($analysis)
          && ($analysis['enabled'] ?? FALSE) === TRUE) {
          return $analysis;
        }
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Returns TRUE when external analysis requires a guarded derivative.
   *
   * @param array<string, mixed> $analysis
   *   Field analysis profile.
   */
  private function requiresGuardDerivative(array $analysis): bool {
    return in_array(
      $analysis['egressPolicy'] ?? NULL,
      ['external_redacted', 'external_allowed'],
      TRUE,
    );
  }

  /**
   * Loads one attachment by UUID.
   */
  private function loadAttachment(string $attachment_uuid): ?ApplicationAttachmentInterface {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $attachment_uuid)) {
      return NULL;
    }
    $matches = $this->entityTypeManager->getStorage('application_attachment')->loadByProperties(['uuid' => $attachment_uuid]);
    $attachment = count($matches) === 1 ? reset($matches) : NULL;
    return $attachment instanceof ApplicationAttachmentInterface ? $attachment : NULL;
  }

  /**
   * Loads the application referenced by an attachment before locking it.
   */
  private function loadAttachmentApplication(ApplicationAttachmentInterface $attachment): ?ApplicationInterface {
    if ($attachment->get('field_application')->isEmpty()) {
      return NULL;
    }
    $application_id = (int) $attachment->get('field_application')->target_id;
    if ($application_id <= 0) {
      return NULL;
    }
    $application = $this->entityTypeManager->getStorage('application')->load($application_id);
    return $application instanceof ApplicationInterface ? $application : NULL;
  }

  /**
   * Loads the attachment file entity.
   */
  private function attachmentFile(ApplicationAttachmentInterface $attachment): ?FileInterface {
    if ($attachment->get('field_file')->isEmpty()) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $attachment->get('field_file')->target_id);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Loads the only file source permitted for external analysis dispatch.
   */
  private function guardDerivativeFile(ApplicationAttachmentInterface $attachment): ?FileInterface {
    if (!$attachment->hasField('field_guard_derivative')
      || $attachment->get('field_guard_derivative')->isEmpty()) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load(
      (int) $attachment->get('field_guard_derivative')->target_id,
    );
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Verifies persisted derivative bytes against the immutable guard report.
   */
  private function validateGuardDerivativeBytes(ApplicationAttachmentInterface $attachment, string $derivative): void {
    $report_json = trim((string) ($attachment->get('field_guard_report')->value ?? ''));
    try {
      $report = json_decode($report_json, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException('Document guard report is invalid.', 0, $exception);
    }
    $metadata = is_array($report) ? ($report['derivative'] ?? NULL) : NULL;
    if (!is_array($metadata)
      || !in_array($report['status'] ?? NULL, ['clean', 'redacted'], TRUE)
      || ($metadata['mediaType'] ?? NULL) !== 'application/pdf'
      || ($metadata['size'] ?? NULL) !== strlen($derivative)
      || !is_string($metadata['sha256'] ?? NULL)
      || preg_match('/^[a-f0-9]{64}$/', $metadata['sha256']) !== 1
      || !hash_equals($metadata['sha256'], hash('sha256', $derivative))
      || !str_starts_with($derivative, '%PDF-')) {
      throw new \RuntimeException('Document guard derivative does not match its report.');
    }
  }

  /**
   * Ensures an external attachment has a usable guarded derivative.
   *
   * @return bool
   *   TRUE for a dispatchable derivative, FALSE for a terminal guard block.
   */
  private function ensureGuardDerivative(ApplicationAttachmentInterface $attachment): bool {
    if (!$attachment->hasField('field_guard_status')
      || !$attachment->hasField('field_guard_report')
      || !$attachment->hasField('field_guard_derivative')) {
      throw new \RuntimeException('Document guard storage is not installed.');
    }

    $guard_status = trim((string) ($attachment->get('field_guard_status')->value ?? ''));
    if (in_array($guard_status, ['needs_review', 'rejected'], TRUE)) {
      return FALSE;
    }
    if (in_array($guard_status, ['clean', 'redacted'], TRUE)) {
      $existing_derivative = $this->guardDerivativeFile($attachment);
      if ($existing_derivative instanceof FileInterface) {
        $existing_uri = (string) $existing_derivative->getFileUri();
        $existing_bytes = NULL;
        if ($existing_uri !== '' && is_file($existing_uri)) {
          try {
            $existing_bytes = $this->readBoundedFile($existing_uri);
            $this->validateGuardDerivativeBytes($attachment, $existing_bytes);
            return TRUE;
          }
          catch (\RuntimeException) {
            // Regenerate an invalid or stale derivative below.
          }
        }
      }

      // Die Datei faellt VOR dem Kappen der Referenz: ein Crash dazwischen
      // hinterlaesst so eine tote Referenz, die der naechste Lauf ueber die
      // Regenerierung selbst heilt, statt einer unreferenzierten Datei, die
      // kein Retention-Pfad je wieder erfasst.
      if ($existing_derivative instanceof FileInterface) {
        $existing_derivative->delete();
      }
      $attachment->set('field_guard_status', 'pending');
      $attachment->set('field_guard_report', NULL);
      $attachment->set('field_guard_derivative', NULL);
      $attachment->save();
      $guard_status = 'pending';
    }
    if (!in_array($guard_status, ['', 'pending', 'failed'], TRUE)) {
      throw new \RuntimeException('Document guard status is invalid.');
    }
    if (!$this->documentGuardClient instanceof DocumentGuardClientInterface
      || !$this->fileSystem instanceof FileSystemInterface
      || !$this->fileRepository instanceof FileRepositoryInterface) {
      throw new \RuntimeException('Document guard is not configured.');
    }

    $original_file = $this->attachmentFile($attachment);
    if (!$original_file instanceof FileInterface) {
      throw new \RuntimeException('Attachment file is missing for document guard.');
    }
    $uri = (string) $original_file->getFileUri();
    if ($uri === '' || !is_file($uri)) {
      throw new \RuntimeException('Attachment file bytes are unavailable for document guard.');
    }
    $document = $this->readBoundedFile($uri);

    $result = $this->documentGuardClient->createDerivative(
      $document,
      $this->attachmentMimeType($attachment, $original_file),
      DocumentGuardClientInterface::ENTITY_PROFILE_FULL,
    );
    // Only a full-profile guard result may be persisted: a reduced
    // (analysis_sovereign) derivative keeps dates and must never be stored
    // or reused for an external provider.
    if ($result['entityProfile'] !== DocumentGuardClientInterface::ENTITY_PROFILE_FULL) {
      throw new \RuntimeException('Document guard result was not produced with the full entity profile.');
    }
    $status = $result['status'];
    $report_json = $result['reportJson'];
    if (!in_array($status, ['clean', 'redacted', 'needs_review', 'rejected'], TRUE)
      || $report_json === '') {
      throw new \RuntimeException('Document guard envelope is incomplete.');
    }

    if (in_array($status, ['needs_review', 'rejected'], TRUE)) {
      $attachment->set('field_guard_status', $status);
      $attachment->set('field_guard_report', $report_json);
      $attachment->save();
      return FALSE;
    }

    $derivative = $result['derivative'];
    if (!is_string($derivative)
      || $derivative === ''
      || strlen($derivative) > self::MAX_ATTACHMENT_BYTES
      || !str_starts_with($derivative, '%PDF-')
      || $result['mediaType'] !== 'application/pdf') {
      throw new \RuntimeException('Document guard derivative is invalid.');
    }
    $form = $attachment->get('field_schema')->entity;
    if (!$form instanceof FormSchemaInterface) {
      throw new \RuntimeException('Document guard form schema is missing.');
    }
    $form_uuid = (string) $form->uuid();
    $field_id = trim((string) ($attachment->get('field_field_id')->value ?? ''));
    if ($form_uuid === '' || preg_match('/^[a-z][a-z0-9_]{0,127}$/', $field_id) !== 1) {
      throw new \RuntimeException('Document guard derivative context is invalid.');
    }
    $month_shard = date('Y-m', $this->time?->getRequestTime() ?? time());
    $directory = sprintf(
      'private://application_attachments/derivatives/%s/%s/%s',
      $month_shard,
      $form_uuid,
      $field_id,
    );
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException('Document guard derivative directory is unavailable.');
    }

    $quota_lock_name = $this->acquireDerivativeQuotaLock($form, strlen($derivative));
    try {
      $derivative_file = $this->fileRepository->writeData(
        $derivative,
        $directory . '/' . $attachment->uuid() . '.pdf',
        FileExists::Replace,
      );
      try {
        $derivative_file->setPermanent();
        $derivative_file->setOwnerId((int) $attachment->getOwnerId());
        $derivative_file->save();
        $attachment->set('field_guard_status', $status);
        $attachment->set('field_guard_report', $report_json);
        $attachment->set('field_guard_derivative', ['target_id' => (int) $derivative_file->id()]);
        $attachment->save();
      }
      catch (\Throwable $exception) {
        $derivative_file->delete();
        $attachment->set('field_guard_status', 'pending');
        $attachment->set('field_guard_report', NULL);
        $attachment->set('field_guard_derivative', NULL);
        throw $exception;
      }
    }
    finally {
      if (is_string($quota_lock_name)) {
        $this->lock?->release($quota_lock_name);
      }
    }

    return TRUE;
  }

  /**
   * Acquires and checks the tenant quota before storing a derivative.
   */
  private function acquireDerivativeQuotaLock(FormSchemaInterface $form, int $derivative_size): ?string {
    if (!$this->attachmentStorageQuota instanceof AttachmentStorageQuota) {
      return NULL;
    }
    if (!$this->lock instanceof LockBackendInterface) {
      throw new \RuntimeException('Tenant attachment storage quota lock is unavailable.');
    }
    $jurisdiction = $this->attachmentStorageQuota->jurisdictionForForm($form);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }

    $lock_name = 'beantragt_application.attachment_storage_quota.' . (int) $jurisdiction->id();
    if (!$this->lock->acquire($lock_name, 30.0)) {
      throw new \RuntimeException('Tenant attachment storage quota is currently being checked.');
    }
    try {
      if ($this->attachmentStorageQuota->violationForJurisdiction($jurisdiction, $derivative_size) !== NULL) {
        throw new \RuntimeException('Tenant attachment storage quota would be exceeded.');
      }
    }
    catch (\Throwable $exception) {
      $this->lock->release($lock_name);
      throw $exception;
    }

    return $lock_name;
  }

  /**
   * Reads an attachment without ever buffering more than the payload limit.
   */
  private function readBoundedFile(string $uri): string {
    $handle = fopen($uri, 'rb');
    if ($handle === FALSE) {
      throw new \RuntimeException('Attachment file could not be read.');
    }

    $data = '';
    try {
      while (!feof($handle) && strlen($data) <= self::MAX_ATTACHMENT_BYTES) {
        $chunk = fread($handle, min(8192, self::MAX_ATTACHMENT_BYTES + 1 - strlen($data)));
        if ($chunk === FALSE) {
          throw new \RuntimeException('Attachment file could not be read.');
        }
        $data .= $chunk;
      }
    }
    finally {
      fclose($handle);
    }

    if ($data === '') {
      throw new \RuntimeException('Attachment file could not be read.');
    }
    if (strlen($data) > self::MAX_ATTACHMENT_BYTES) {
      throw new \RuntimeException('Attachment file exceeds the analysis payload limit.');
    }
    return $data;
  }

  /**
   * Writes bounded worker metadata directly when Nuxt cannot be reached.
   */
  private function markAttachment(ApplicationAttachmentInterface $attachment, string $status, array $metadata): void {
    $attachment->set('field_analysis_status', $status);
    $attachment->set('field_analysis_metadata', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $attachment->save();
  }

  /**
   * Writes a worker-owned state for the queued story.
   *
   * Legacy keeps the whole version 1 record as before; a story write only
   * replaces stories.{story} and keeps that story's run id, so a late
   * callback of the same run is still recognised.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment; replaced by the reloaded entity after a story write.
   * @param string $story
   *   Queued story: legacy, citizen or staff.
   * @param string $status
   *   Lifecycle status to write.
   * @param string $code
   *   Worker code.
   * @param int $attempt
   *   Current attempt.
   * @param int $max_attempts
   *   Maximum attempts.
   * @param array<string, mixed> $extra_fields
   *   Further entity fields to save with a story write.
   * @param string|null $legacy_run_id
   *   Run id of a legacy rerun, kept in the version 1 record.
   */
  private function markStory(ApplicationAttachmentInterface &$attachment, string $story, string $status, string $code, int $attempt, int $max_attempts, array $extra_fields = [], ?string $legacy_run_id = NULL): void {
    if ($story === AttachmentAnalysisMetadataWriter::STORY_LEGACY) {
      $this->markAttachment($attachment, $status, $this->workerMetadata($attachment, $code, $attempt, $max_attempts, $legacy_run_id));
      return;
    }
    $patch = $this->storyWorkerPatch($attachment, $code, $attempt, $max_attempts);
    $patch['status'] = $status;
    // The run was already marked pending with a lease. Giving up here would
    // leave it pending, and the queue retry would be consumed by that lease:
    // wait out a crashed lock holder instead.
    $written = $this->metadataWriter()->mergeStory(
      $attachment,
      $story,
      $patch,
      FALSE,
      $extra_fields,
      AttachmentAnalysisMetadataWriter::TERMINAL_WRITE_WAIT_ROUNDS,
    );
    $attachment = $written['attachment'];
  }

  /**
   * Builds the worker-owned part of one story record.
   *
   * @return array<string, mixed>
   *   Story record patch.
   */
  private function storyWorkerPatch(ApplicationAttachmentInterface $attachment, string $code, int $attempt, int $max_attempts): array {
    return [
      'status' => $code === 'analysis_queued' ? 'pending' : 'failed',
      'code' => $code,
      'requiresHumanReview' => TRUE,
      'source' => [
        'attachmentId' => (string) $attachment->uuid(),
        'fieldId' => (string) ($attachment->get('field_field_id')->value ?? ''),
        'mimeType' => (string) ($attachment->get('field_mime_type')->value ?? ''),
        'size' => (int) ($attachment->get('field_size')->value ?? 0),
      ],
      'worker' => [
        'attempt' => $attempt,
        'maxAttempts' => $max_attempts,
      ],
    ];
  }

  /**
   * Builds metadata for worker-owned lifecycle states.
   *
   * @return array<string, mixed>
   *   Metadata.
   */
  private function workerMetadata(ApplicationAttachmentInterface $attachment, string $code, int $attempt, int $max_attempts, ?string $analysis_run_id = NULL): array {
    $run = [];
    if ($analysis_run_id !== NULL) {
      // A legacy rerun keeps its run id and start time, so the callback of
      // exactly this run is accepted and its lease stays visible.
      $started_at = $this->storedMetadata($attachment)['analysisStartedAt'] ?? NULL;
      $run = [
        'analysisRunId' => $analysis_run_id,
        'analysisStartedAt' => is_int($started_at) ? $started_at : $this->requestTime(),
      ];
    }
    return $run + [
      'version' => 1,
      'status' => $code === 'analysis_queued' ? 'pending' : 'failed',
      'code' => $code,
      'requiresHumanReview' => TRUE,
      'language' => $this->analysisLanguage($attachment),
      'source' => [
        'attachmentId' => (string) $attachment->uuid(),
        'fieldId' => (string) ($attachment->get('field_field_id')->value ?? ''),
        'mimeType' => (string) ($attachment->get('field_mime_type')->value ?? ''),
        'size' => (int) ($attachment->get('field_size')->value ?? 0),
      ],
      'worker' => [
        'attempt' => $attempt,
        'maxAttempts' => $max_attempts,
      ],
    ];
  }

  /**
   * Reads the bounded citizen-facing analysis language from stored metadata.
   *
   * The language stays at the top level in both versions; a version 2
   * record without it falls back to the language of story A.
   */
  private function analysisLanguage(ApplicationAttachmentInterface $attachment): string {
    $raw = (string) ($attachment->get('field_analysis_metadata')->value ?? '');
    if ($raw === '') {
      return 'de';
    }
    try {
      $metadata = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return 'de';
    }
    if (!is_array($metadata)) {
      return 'de';
    }
    $language = $metadata['language'] ?? NULL;
    if (!is_string($language) && AttachmentAnalysisMetadataWriter::isV2($metadata)) {
      $language = AttachmentAnalysisMetadataWriter::storyRecord($metadata, AttachmentAnalysisMetadataWriter::STORY_CITIZEN)['language'] ?? NULL;
    }
    return is_string($language) && in_array($language, ['de', 'en'], TRUE)
      ? $language
      : 'de';
  }

  /**
   * Reads the next attempt number from stored analysis metadata.
   */
  private function nextAttempt(ApplicationAttachmentInterface $attachment, string $story = AttachmentAnalysisMetadataWriter::STORY_LEGACY): int {
    $raw = (string) ($attachment->get('field_analysis_metadata')->value ?? '');
    if ($raw === '') {
      return 1;
    }
    try {
      $metadata = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return 1;
    }
    if (!is_array($metadata)) {
      return 1;
    }
    $worker = $story === AttachmentAnalysisMetadataWriter::STORY_LEGACY
      ? ($metadata['worker'] ?? NULL)
      : (AttachmentAnalysisMetadataWriter::storyRecord($metadata, $story)['worker'] ?? NULL);
    $attempt = is_array($worker) ? (int) ($worker['attempt'] ?? 0) : 0;
    return max(1, min(50, $attempt + 1));
  }

  /**
   * Reads the configured maximum attempts.
   */
  private function maxAttempts(): int {
    $attempts = (int) (getenv('BEANTRAGT_ATTACHMENT_ANALYSIS_MAX_ATTEMPTS') ?: self::DEFAULT_MAX_ATTEMPTS);
    return max(1, min(10, $attempts));
  }

  /**
   * Reads the Nuxt worker endpoint base URL.
   */
  private function nuxtInternalBaseUrl(): string {
    $raw = trim((string) (getenv('BEANTRAGT_NUXT_INTERNAL_BASE_URL') ?: getenv('NUXT_INTERNAL_BASE_URL') ?: ''));
    if ($raw === '' && $this->isDevelopmentEnvironment()) {
      $raw = self::DEFAULT_DDEV_NUXT_BASE_URL;
    }
    if ($raw === '') {
      return '';
    }
    $parts = parse_url($raw);
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '') {
      return '';
    }
    return rtrim($raw, '/');
  }

  /**
   * Returns TRUE for local development runtime.
   */
  private function isDevelopmentEnvironment(): bool {
    $environment = strtolower(trim((string) (getenv('BEANTRAGT_ENV') ?: '')));
    return getenv('IS_DDEV_PROJECT') === 'true'
      || in_array($environment, ['dev', 'local', 'ddev', 'development'], TRUE);
  }

  /**
   * Builds the legacy Intelligence license authorization header.
   */
  private function intelligenceServiceAuthorizationHeader(): string {
    $key = trim((string) (getenv('BEANTRAGT_INTELLIGENCE_SERVICE_KEY') ?: ''));
    if ($key === '' && $this->isDevelopmentEnvironment()) {
      $key = self::DEFAULT_DEVELOPMENT_INTELLIGENCE_SERVICE_KEY;
    }
    if ($key === '') {
      return '';
    }
    return preg_match('/^(?:Basic|Bearer)\s+\S+$/i', $key) === 1
      ? $key
      : 'Bearer ' . $key;
  }

  /**
   * Builds the shared service authorization header.
   */
  private function serviceAuthorizationHeader(): string {
    $user = getenv('NUXT_DRUPAL_SERVICE_USER') ?: 'nuxt_service';
    $password = getenv('NUXT_DRUPAL_SERVICE_PASSWORD') ?: '';
    return $password === '' ? '' : 'Basic ' . base64_encode($user . ':' . $password);
  }

  /**
   * Reads one referenced entity UUID.
   */
  private function referencedEntityUuid(ApplicationAttachmentInterface $attachment, string $field_name): string {
    if (!$attachment->hasField($field_name) || $attachment->get($field_name)->isEmpty()) {
      return '';
    }
    $entity = $attachment->get($field_name)->entity;
    return $entity ? (string) $entity->uuid() : '';
  }

  /**
   * Resolves the best known media type for analysis.
   */
  private function attachmentMimeType(ApplicationAttachmentInterface $attachment, FileInterface $file): string {
    $stored = trim((string) ($attachment->get('field_mime_type')->value ?? ''));
    if ($stored !== '') {
      return $stored;
    }
    return $file->getMimeType() ?: 'application/octet-stream';
  }

  /**
   * Reads HTTP timeout.
   */
  private function timeoutSeconds(): float {
    $timeout = (float) (getenv('BEANTRAGT_ATTACHMENT_ANALYSIS_TIMEOUT_SECONDS') ?: self::DEFAULT_TIMEOUT_SECONDS);
    return max(1.0, min(300.0, $timeout));
  }

  /**
   * Keeps the egress lock beyond the maximum downstream request timeout.
   */
  private function analysisLockTtlSeconds(): float {
    return max(900.0, $this->timeoutSeconds() + 300.0);
  }

  /**
   * Returns the request time used for durable pending leases.
   */
  private function requestTime(): int {
    return $this->time?->getRequestTime() ?? time();
  }

}
