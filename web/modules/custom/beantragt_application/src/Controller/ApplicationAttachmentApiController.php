<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\AttachmentProofProviderInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_comment\Entity\ApplicationCommentInterface;
use Drupal\beantragt_application\Service\AttachmentPrecheck;
use Drupal\beantragt_application\Service\ApplicationProofAssignments;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\beantragt_application\Exception\AttachmentAnalysisMetadataLockedException;
use Drupal\beantragt_application\Exception\AttachmentAnalysisRerunRefusedException;
use Drupal\beantragt_application\Exception\AttachmentAnalysisSourceException;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\Service\AttachmentAnalysisDispatcher;
use Drupal\beantragt_application\Service\AttachmentAnalysisMetadataWriter;
use Drupal\beantragt_application\Service\AttachmentAnalysisSourcePreparer;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\AttachmentMimeSniffer;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_application\Service\ProtectedDataGate;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\StaffAttachmentAnalysis;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Environment;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Public v1 API for pre-submission application attachments.
 */
final class ApplicationAttachmentApiController extends ControllerBase {

  private const API_VERSION = '1.0';
  private const FLOOD_LIMIT = 10;
  private const FLOOD_WINDOW = 60;
  private const TARGET_FLOOD_LIMIT = 5;
  private const CLAIM_TTL = 21600;
  private const ANALYSIS_LEASE_TTL = 300;
  private const MAX_FILE_SIZE = 10485760;
  private const DEFAULT_MAX_ATTACHMENT_BYTES_PER_APPLICATION = 52428800;
  private const MAX_FILENAME_LENGTH = 180;
  private const MAX_ANALYSIS_METADATA_BYTES = 32768;
  private const MAX_ANALYSIS_METADATA_ITEMS = 80;

  /**
   * Cap of operator labels in analysis metadata (provider.location).
   *
   * Matches AI_PROVIDER_LABEL_MAX_LENGTH in the Nuxt provider labels.
   */
  private const MAX_ANALYSIS_LABEL_LENGTH = 120;
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
  private const FIELD_ID_PATTERN = '/^[a-z][a-z0-9_]{0,127}$/';
  private const ANALYSIS_RUN_ID_PATTERN = '/^[a-f0-9]{32}$/';
  private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
  private const ALLOWED_MIMES = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
  ];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly TimeInterface $time,
    private readonly AttachmentScanServiceInterface $attachmentScanner,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    ConfigFactoryInterface $config_factory,
    private readonly ?AttachmentStorageQuota $attachmentStorageQuota = NULL,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    private readonly ?AttachmentAnalysisMetadataWriter $metadataWriter = NULL,
    private readonly ?AttachmentAnalysisDispatcher $analysisDispatcher = NULL,
    private readonly ?AttachmentAnalysisSourcePreparer $sourcePreparer = NULL,
    private readonly ?AttachmentMimeSniffer $mimeSniffer = NULL,
    private readonly ?ProtectedAccessLog $accessLog = NULL,
    private readonly ?AttachmentPrecheck $precheck = NULL,
    private readonly ?StaffAttachmentAnalysis $staffAnalysis = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('flood'),
      $container->get('logger.channel.beantragt_application'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('datetime.time'),
      $container->get('beantragt_application.attachment_scanner'),
      $container->get('database'),
      $container->get('lock'),
      $container->get('config.factory'),
      $container->get('beantragt_application.attachment_storage_quota'),
      $container->get('beantragt_application.data_protection'),
      $container->get('beantragt_application.attachment_analysis_metadata_writer'),
      $container->get('beantragt_application.attachment_analysis_dispatcher'),
      $container->get('beantragt_application.attachment_analysis_source_preparer'),
      $container->get('beantragt_application.attachment_mime_sniffer'),
      $container->get('beantragt_application.protected_access_log'),
      $container->get('beantragt_application.attachment_precheck'),
      $container->get('beantragt_application.staff_attachment_analysis'),
    );
  }

  /**
   * Uploads one attachment before final application submission.
   */
  public function uploadAttachment(Request $request): JsonResponse {
    $flood_response = $this->floodResponse($request, 'beantragt_application.attachment_upload');
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $form_uuid = $this->normalizeUuid($request->request->get('formId'));
    $field_id = $this->normalizeFieldId($request->request->get('fieldId'));
    if ($form_uuid === NULL || $field_id === NULL) {
      return $this->errorResponse('attachment.invalid_context', 'A valid formId and fieldId are required.', Response::HTTP_BAD_REQUEST);
    }

    $target_flood_response = $this->targetFloodResponse($request, $form_uuid, $field_id);
    if ($target_flood_response !== NULL) {
      return $target_flood_response;
    }

    $form = $this->loadPublicFormByUuid($form_uuid);
    $file_field = $form instanceof FormSchemaInterface ? $this->formFileField($form, $field_id) : NULL;
    if (!$form instanceof FormSchemaInterface || $file_field === NULL) {
      return $this->errorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND);
    }
    $external_analysis_consent = $this->trustedExternalAnalysisConsent($request, $file_field);
    $analysis_language = $this->trustedAnalysisLanguage($request);

    $trusted_owner_id = $this->trustedUploadedOwnerId($request, $request->request->get('ownerUuid'));
    if ($this->formRequiresAuthenticatedSubmission($form) && $trusted_owner_id === NULL) {
      return $this->errorResponse('auth.required', 'This form requires authenticated upload.', Response::HTTP_UNAUTHORIZED);
    }

    $owner_id = $trusted_owner_id ?? $this->publicSubmissionOwnerId();
    if ($owner_id === NULL) {
      return $this->errorResponse('attachment.owner_unavailable', 'Attachment owner is not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $uploaded_file = $request->files->get('file');
    if (!$uploaded_file instanceof UploadedFile || !$uploaded_file->isValid()) {
      return $this->errorResponse('attachment.file_missing', 'No valid file was uploaded.', Response::HTTP_BAD_REQUEST);
    }

    $file_validation = $this->validateUploadedFile($uploaded_file, $this->fileFieldMaxBytes($file_field));
    if ($file_validation !== NULL) {
      return $this->errorResponse(
        (string) $file_validation['code'],
        (string) $file_validation['message'],
        (int) $file_validation['status'],
      );
    }

    $pending_quota_response = $this->pendingUploadQuotaResponse($form, $field_id, (int) $uploaded_file->getSize());
    if ($pending_quota_response !== NULL) {
      return $pending_quota_response;
    }

    $tenant_quota_lock_name = $this->acquireTenantQuotaLock($form);
    if ($tenant_quota_lock_name === FALSE) {
      return $this->errorResponse('attachment.tenant_storage_quota_locked', 'Tenant attachment storage quota is currently being checked.', Response::HTTP_CONFLICT);
    }

    try {
      $tenant_quota_response = $this->tenantStorageQuotaResponse($form, (int) $uploaded_file->getSize());
      if ($tenant_quota_response !== NULL) {
        return $tenant_quota_response;
      }

      $extension = strtolower($uploaded_file->getClientOriginalExtension());
      $actual_mime = $this->detectUploadedMime($uploaded_file) ?: (self::ALLOWED_MIMES[$extension] ?? 'application/octet-stream');
      $safe_name = $this->sanitizeFilename($uploaded_file->getClientOriginalName(), $extension);
      $month_shard = date('Y-m', $this->time->getRequestTime());
      $directory = 'private://application_attachments/' . $month_shard . '/' . $form_uuid . '/' . $field_id;
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $this->logger->error('Could not prepare attachment upload directory for form @form field @field.', [
          '@form' => $form_uuid,
          '@field' => $field_id,
        ]);
        return $this->errorResponse('attachment.save_failed', 'Attachment could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $file_data = file_get_contents((string) $uploaded_file->getRealPath());
      if ($file_data === FALSE) {
        throw new \RuntimeException('Could not read uploaded file.');
      }
      $scan_result = $this->attachmentScanner->scan($file_data, $safe_name, $actual_mime);
      $file = $this->fileRepository->writeData(
        $file_data,
        $directory . '/' . $safe_name,
        FileExists::Rename,
      );
      $file->setTemporary();
      $file->setOwnerId($owner_id);
      $file->save();

      $quarantined = $scan_result->isQuarantined();
      $claim_token = $quarantined ? '' : $this->claimToken();
      $expires_at = $quarantined ? $this->time->getRequestTime() : $this->time->getRequestTime() + self::CLAIM_TTL;
      // Story A of a story profile runs right after this upload in the
      // trusted proxy. Its run id is minted here, so the callback can only
      // write stories.citizen of this very run.
      $analysis_metadata = [
        'version' => 1,
        'status' => 'not_requested',
        'language' => $analysis_language,
      ];
      $citizen_run_id = NULL;
      if (!$quarantined
        && $this->isTrustedServiceRequest($request)
        && AttachmentAnalysisMetadataWriter::uploadRunsCitizenStory(
          is_array($file_field['analysis'] ?? NULL) ? $file_field['analysis'] : NULL,
          $external_analysis_consent,
        )) {
        $start = $this->metadataWriter()->startRecordWithCitizenRun($analysis_language);
        $analysis_metadata = $start['metadata'];
        $citizen_run_id = $start['runId'];
      }
      $attachment = $this->entityTypeManager->getStorage('application_attachment')->create([
        'field_schema' => ['target_id' => (int) $form->id()],
        'field_field_id' => $field_id,
        'field_file' => ['target_id' => (int) $file->id()],
        'field_claim_token_hash' => $quarantined ? NULL : hash('sha256', $claim_token),
        'field_claim_expires' => $expires_at,
        'field_upload_status' => 'uploaded',
        'field_analysis_status' => $citizen_run_id !== NULL ? 'pending' : 'not_requested',
        'field_analysis_metadata' => json_encode($analysis_metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'field_external_analysis_consent' => $external_analysis_consent,
        'field_scan_status' => $scan_result->status(),
        'field_scan_sha256' => $scan_result->isVerifiedClean() ? hash('sha256', $file_data) : NULL,
        'field_scan_message' => $scan_result->message(),
        'field_original_filename' => $file->getFilename(),
        'field_mime_type' => $actual_mime,
        'field_size' => (int) $file->getSize(),
        'uid' => $owner_id,
      ]);
      $attachment->save();
      if ($quarantined) {
        $this->logger->warning('Application attachment @attachment quarantined for form @form field @field.', [
          '@form' => $form_uuid,
          '@field' => $field_id,
          '@attachment' => $attachment->uuid(),
        ]);
        return $this->errorResponse(
          'attachment.quarantined',
          'Attachment was quarantined by malware scanning.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
          [
            'attachmentId' => (string) $attachment->uuid(),
            'scanStatus' => AttachmentScanResult::STATUS_QUARANTINED,
          ],
        );
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Application attachment upload failed for form @form field @field: @exception.', [
        '@form' => $form_uuid,
        '@field' => $field_id,
        '@exception' => get_class($e),
      ]);
      return $this->errorResponse('attachment.save_failed', 'Attachment could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    finally {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
    }

    return $this->successResponse([
      'id' => (string) $attachment->uuid(),
      'type' => 'application_attachment',
      'formId' => $form_uuid,
      'fieldId' => $field_id,
      'claimToken' => $claim_token,
      'filename' => $file->getFilename(),
      'mimeType' => $actual_mime,
      'size' => (int) $file->getSize(),
      'analysisStatus' => $citizen_run_id !== NULL ? 'pending' : 'not_requested',
      'analysisLanguage' => $analysis_language,
      'externalAnalysisConsent' => $external_analysis_consent,
      'scanStatus' => AttachmentScanResult::STATUS_CLEAN,
      'expiresAt' => gmdate('c', $expires_at),
    ] + ($citizen_run_id !== NULL ? [
      // Only for the trusted proxy, which strips both before the browser.
      'analysisRunId' => $citizen_run_id,
      'analysisStory' => AttachmentAnalysisMetadataWriter::STORY_CITIZEN,
    ] : []), Response::HTTP_CREATED);
  }

  /**
   * Records a later citizen opt-in and returns the canonical stored bytes.
   *
   * The claim token binds the opt-in to the exact unsubmitted attachment.
   * Only the trusted Nuxt proxy receives the private file bytes for analysis.
   */
  public function consentAttachmentAnalysis(string $attachment_uuid, Request $request): Response {
    $flood_response = $this->floodResponse($request, 'beantragt_application.attachment_analysis_consent');
    if ($flood_response !== NULL) {
      return $flood_response;
    }
    if (!$this->isTrustedServiceRequest($request)) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }

    $attachment_uuid = $this->normalizeUuid($attachment_uuid) ?? '';
    $form_uuid = $this->normalizeUuid($request->request->get('formId'));
    $field_id = $this->normalizeFieldId($request->request->get('fieldId'));
    $claim_token = trim((string) $request->request->get('claimToken', ''));
    if ($attachment_uuid === ''
      || $form_uuid === NULL
      || $field_id === NULL
      || preg_match('/^[A-Za-z0-9_-]{32,96}$/', $claim_token) !== 1
      || $request->request->get('analysisConsent') !== 'true') {
      return $this->errorResponse('attachment.invalid_claim', 'Attachment analysis consent is invalid.', Response::HTTP_BAD_REQUEST);
    }

    $form = $this->loadPublicFormByUuid($form_uuid);
    $file_field = $form instanceof FormSchemaInterface ? $this->formFileField($form, $field_id) : NULL;
    if (!$form instanceof FormSchemaInterface || $file_field === NULL) {
      return $this->errorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND);
    }
    $analysis = $file_field['analysis'] ?? NULL;
    if (is_array($analysis) && ($analysis['egressPolicy'] ?? NULL) === 'external_redacted') {
      return $this->errorResponse(
        'attachment.analysis_consent_not_available',
        'Citizen consent is not available for redacted-only external analysis.',
        Response::HTTP_CONFLICT,
      );
    }
    if (!$this->trustedExternalAnalysisConsent($request, $file_field)) {
      return $this->errorResponse('attachment.analysis_not_allowed', 'Attachment analysis is not allowed.', Response::HTTP_CONFLICT);
    }
    // Same rule as the upload path (uploadRunsCitizenStory): a story profile
    // without a citizen prompt and without nonPersonal values has no story A.
    if (is_array($analysis)
      && AttachmentAnalysisMetadataWriter::isStoryProfile($analysis)
      && !AttachmentAnalysisMetadataWriter::profileHasCitizenStory($analysis)) {
      return $this->errorResponse('attachment.analysis_not_allowed', 'Attachment analysis is not allowed.', Response::HTTP_CONFLICT);
    }

    $initial_attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$initial_attachment instanceof ApplicationAttachmentInterface) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }
    $lock_name = $this->attachmentClaimLockName($initial_attachment);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      return $this->errorResponse('attachment.claim_in_progress', 'Attachment claim is already in progress.', Response::HTTP_CONFLICT);
    }

    try {
      $this->entityTypeManager->getStorage('application_attachment')->resetCache([(int) $initial_attachment->id()]);
      $attachment = $this->loadAttachmentByUuid($attachment_uuid);
      if (!$attachment instanceof ApplicationAttachmentInterface
        || (int) $attachment->get('field_schema')->target_id !== (int) $form->id()
        || (string) ($attachment->get('field_field_id')->value ?? '') !== $field_id
        || (string) ($attachment->get('field_upload_status')->value ?? '') !== 'uploaded'
        || !$attachment->get('field_application')->isEmpty()
        || $this->attachmentScanStatus($attachment) !== AttachmentScanResult::STATUS_CLEAN) {
        return $this->errorResponse('attachment.claim_invalid', 'Attachment claim is invalid.', Response::HTTP_CONFLICT);
      }

      $story_mode = AttachmentAnalysisMetadataWriter::isStoryProfile(is_array($analysis) ? $analysis : NULL);
      if ($story_mode) {
        // Only story A counts here; story B never runs before the claim. A
        // legacy run from before a profile switch is kept as it is.
        $stored_metadata = AttachmentAnalysisMetadataWriter::decode((string) ($attachment->get('field_analysis_metadata')->value ?? ''));
        $citizen_record = AttachmentAnalysisMetadataWriter::storyRecord(
          $stored_metadata,
          AttachmentAnalysisMetadataWriter::STORY_CITIZEN,
        );
        if (AttachmentAnalysisMetadataWriter::holdsLegacyRun($stored_metadata)
          || ($citizen_record['status'] ?? NULL) === 'complete'
          || AttachmentAnalysisMetadataWriter::hasActiveLease($citizen_record, $this->time->getRequestTime(), self::ANALYSIS_LEASE_TTL)) {
          return $this->errorResponse('attachment.analysis_already_started', 'Attachment analysis has already started.', Response::HTTP_CONFLICT);
        }
      }
      else {
        $analysis_status = (string) ($attachment->get('field_analysis_status')->value ?? 'not_requested');
        $current_analysis_metadata = $this->attachmentAnalysisMetadata($attachment);
        $analysis_started_at = (int) ($current_analysis_metadata['analysisStartedAt'] ?? 0);
        $has_active_analysis_lease = $analysis_status === 'pending'
          && $analysis_started_at > $this->time->getRequestTime() - self::ANALYSIS_LEASE_TTL;
        if ($analysis_status === 'complete' || $has_active_analysis_lease) {
          return $this->errorResponse('attachment.analysis_already_started', 'Attachment analysis has already started.', Response::HTTP_CONFLICT);
        }
      }

      $expires = (int) ($attachment->get('field_claim_expires')->value ?? 0);
      $claim_hash = (string) ($attachment->get('field_claim_token_hash')->value ?? '');
      if ($expires <= $this->time->getRequestTime()
        || $claim_hash === ''
        || !hash_equals($claim_hash, hash('sha256', $claim_token))) {
        return $this->errorResponse('attachment.claim_invalid', 'Attachment claim is invalid.', Response::HTTP_FORBIDDEN);
      }

      $file = $attachment->get('field_file')->entity;
      if (!$file instanceof FileInterface) {
        return $this->errorResponse('attachment.claim_invalid', 'Attachment file is missing.', Response::HTTP_CONFLICT);
      }
      $uri = (string) $file->getFileUri();
      $size = (int) $file->getSize();
      if ($uri === ''
        || !is_file($uri)
        || $size <= 0
        || $size > $this->fileFieldMaxBytes($file_field)
        || $size > self::MAX_FILE_SIZE) {
        return $this->errorResponse('attachment.claim_invalid', 'Attachment file is unavailable.', Response::HTTP_CONFLICT);
      }

      $language = $this->trustedAnalysisLanguage($request);
      $attachment->set('field_external_analysis_consent', TRUE);
      try {
        if ($story_mode) {
          $written = $this->metadataWriter()->mergeStoryLocked(
            $attachment,
            AttachmentAnalysisMetadataWriter::STORY_CITIZEN,
            ['status' => 'pending', 'language' => $language],
            TRUE,
          );
          $analysis_run_id = (string) $written['story']['analysisRunId'];
        }
        else {
          $analysis_run_id = bin2hex(random_bytes(16));
          $attachment->set('field_analysis_status', 'pending');
          $attachment->set('field_analysis_metadata', json_encode([
            'version' => 1,
            'status' => 'pending',
            'language' => $language,
            'analysisRunId' => $analysis_run_id,
            'analysisStartedAt' => $this->time->getRequestTime(),
          ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
          $attachment->save();
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Attachment analysis consent could not be saved for @attachment: @exception.', [
          '@attachment' => $attachment_uuid,
          '@exception' => get_class($e),
        ]);
        return $this->errorResponse('attachment.analysis_save_failed', 'Attachment analysis consent could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $response = new BinaryFileResponse($uri, Response::HTTP_OK, [
        'Content-Type' => (string) ($attachment->get('field_mime_type')->value ?? $file->getMimeType() ?? 'application/octet-stream'),
        'Cache-Control' => 'private, no-store, max-age=0',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Beantragt-Attachment-Id' => $attachment_uuid,
        'X-Beantragt-Form-Id' => $form_uuid,
        'X-Beantragt-Field-Id' => $field_id,
        'X-Beantragt-Analysis-Language' => $language,
        'X-Beantragt-Analysis-Run-Id' => $analysis_run_id,
        'X-Beantragt-Attachment-Size' => (string) $size,
      ] + ($story_mode ? ['X-Beantragt-Analysis-Story' => AttachmentAnalysisMetadataWriter::STORY_CITIZEN] : []), FALSE);
      $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'attachment');
      return $response;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Lists attachments for one application.
   *
   * Internal route for the Nuxt proxy only, not part of /api/v1. An emitted,
   * non-quarantined attachment may carry a batch "precheck" block.
   * The protected "staffAnalysis" block describes the staff run separately.
   */
  public function listApplicationAttachments(ApplicationInterface $application): JsonResponse {
    if (!$application->access('view')) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }

    $ids = $this->entityTypeManager->getStorage('application_attachment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', (int) $application->id())
      ->sort('created', 'ASC')
      ->execute();

    $attachments = $this->entityTypeManager->getStorage('application_attachment')->loadMultiple($ids);
    $correctedFields = ApplicationProofAssignments::read($application);
    $messages = $this->attachmentMessages($application, $attachments);
    $data = [];
    // Row index per attachment entity id that may get a pre-check block.
    $precheck_rows = [];
    $logged_extracted_read = FALSE;
    $logged_filename_read = FALSE;
    $can_read_staff_analysis = ProtectedDataGate::canReadProtectedAttachmentData($this->currentUser());
    $schema_fields = $can_read_staff_analysis && $this->staffAnalysis !== NULL ? $this->applicationSchemaFields($application) : [];
    $file_storage = $this->entityTypeManager->getStorage('file');
    foreach ($attachments as $attachment) {
      if (!$attachment instanceof ApplicationAttachmentInterface) {
        continue;
      }
      $field_id = (string) $attachment->get('field_field_id')->value;
      $file_id = (int) ($attachment->get('field_file')->target_id ?? 0);
      $message = $field_id === 'comment_attachment' ? ($messages[$file_id] ?? NULL) : NULL;
      // Upload reservations are not messages until a sent comment links them.
      if ($field_id === 'comment_attachment' && $message === NULL) {
        continue;
      }
      $scan_status = $this->attachmentScanStatus($attachment);
      if (!$this->canAccessClaimedAttachment($application, $attachment, $field_id, $correctedFields)) {
        continue;
      }
      $can_resolve_quarantine = $scan_status === AttachmentScanResult::STATUS_QUARANTINED
        && $this->canResolveQuarantine($application);
      $file = NULL;
      if ($attachment->hasField('field_file') && !$attachment->get('field_file')->isEmpty()) {
        $file = $file_storage->load($attachment->get('field_file')->target_id);
      }
      if (!$file instanceof FileInterface) {
        continue;
      }
      $guard_status = $attachment->hasField('field_guard_status')
        && !$attachment->get('field_guard_status')->isEmpty()
          ? (string) ($attachment->get('field_guard_status')->value ?? '')
          : '';
      $guard_derivative_url = NULL;
      if (in_array($guard_status, ['clean', 'redacted'], TRUE)
        && $attachment->hasField('field_guard_derivative')
        && !$attachment->get('field_guard_derivative')->isEmpty()) {
        $guard_derivative_url = Url::fromRoute('beantragt_application.admin_application_attachment_derivative', [
          'application' => (int) $application->id(),
          'attachment_uuid' => (string) $attachment->uuid(),
        ])->toString();
      }
      $stored_metadata = $this->attachmentAnalysisMetadata($attachment);
      $staff_record = AttachmentAnalysisMetadataWriter::storyRecord($stored_metadata, AttachmentAnalysisMetadataWriter::STORY_STAFF);
      $hide_staff = $staff_record !== [] && (!$can_read_staff_analysis || $scan_status !== AttachmentScanResult::STATUS_CLEAN);
      if ($hide_staff) {
        unset($stored_metadata['stories'][AttachmentAnalysisMetadataWriter::STORY_STAFF]);
        $stored_metadata['status'] = AttachmentAnalysisMetadataWriter::overallStatus($stored_metadata['stories']);
      }
      // Staff findings are a protected read regardless of the file's current
      // sensitivity flag. Record the read before decrypting or projecting it.
      $staff_result = !$hide_staff && (($staff_record['status'] ?? NULL) === 'complete' || !empty($staff_record['result']));
      $sensitive_values = $this->analysisMetadataHasExtractedValues($stored_metadata)
        && $this->attachmentFieldIsSensitive($application, $field_id);
      if (!$logged_extracted_read && ($staff_result || $sensitive_values)) {
        $protection = $this->dataProtection ?? throw new ServiceUnavailableHttpException(NULL, 'Attachment analysis reads cannot be audited.');
        $protection->logProtectedReveal($application, $this->currentUser(), 'attachment_analysis');
        $logged_extracted_read = TRUE;
      }
      $analysis_metadata = $this->analysisMetadataForResponse($stored_metadata, $application, $field_id, (string) $attachment->uuid());
      $staff_analysis = $can_read_staff_analysis && $this->staffAnalysis !== NULL
        ? $this->staffAnalysis->view($application, $attachment, (array) $analysis_metadata, $schema_fields, $scan_status, $this->time->getRequestTime())
        : NULL;
      $original_filename = trim((string) $attachment->get('field_original_filename')->value);
      $filename = $this->legacyBatchAttachmentLabel($application, $field_id, $file, $original_filename)
        ?? $this->attachmentFilename($attachment, $file);
      if (!$logged_filename_read
        && $original_filename !== ''
        && $filename !== $file->getFilename()) {
        // A scan name can carry a person's name whatever the protection of
        // its file field, so every list that returns one is a logged read.
        if ($this->dataProtection instanceof ApplicationDataProtectionService) {
          $this->dataProtection->logProtectedReveal($application, $this->currentUser(), 'attachment_list');
        }
        $logged_filename_read = TRUE;
      }
      $data[] = [
        'id' => (string) $attachment->uuid(),
        'type' => 'application_attachment',
        'fieldId' => $field_id,
        ...($message === NULL ? [] : ['comment' => $message, 'fileId' => (string) $file->uuid()]),
        'filename' => $filename,
        'url' => Url::fromRoute('beantragt_application.admin_application_attachment_download', [
          'application' => (int) $application->id(),
          'attachment_uuid' => (string) $attachment->uuid(),
        ])->toString(),
        'mimeType' => (string) ($attachment->get('field_mime_type')->value ?? ''),
        'size' => (int) $file->getSize(),
        'analysisStatus' => $hide_staff
          ? $stored_metadata['status']
          : (string) ($attachment->get('field_analysis_status')->value ?? 'not_requested'),
        'externalAnalysisConsent' => $attachment->hasField('field_external_analysis_consent')
        && (string) ($attachment->get('field_external_analysis_consent')->value ?? '0') === '1',
        'analysisMetadata' => $analysis_metadata,
        ...($staff_analysis === NULL ? [] : ['staffAnalysis' => $staff_analysis]),
        'guardStatus' => $guard_status,
        'guardReport' => $this->attachmentGuardReport($attachment),
        'guardDerivativeUrl' => $guard_derivative_url,
        'scanStatus' => $scan_status,
        'scanMessage' => $this->attachmentScanMessage($attachment),
        'canResolveQuarantine' => $can_resolve_quarantine,
        'createdAt' => gmdate('c', (int) $attachment->get('created')->value),
      ];
      // A quarantined file is listed but not served: no finding about it.
      if ($scan_status !== AttachmentScanResult::STATUS_QUARANTINED && (int) $attachment->id() > 0) {
        $precheck_rows[(int) $attachment->id()] = array_key_last($data);
      }
    }
    $data = $this->withPrechecks($application, $data, $precheck_rows);
    $completeness = NULL;
    if ($this->precheck instanceof AttachmentPrecheck) {
      try {
        $completeness = $this->precheck->proofsForApplication(
          $application,
          array_keys($precheck_rows),
          fn(string $fieldId): bool => $this->canAccessAttachmentField($application, $fieldId),
        );
      }
      catch (\Throwable $exception) {
        $this->logger->warning('Attachment proof projection failed: @class.', ['@class' => $exception::class]);
        $completeness = ['available' => FALSE];
      }
      if ($completeness !== NULL && !$this->auditPrecheckRead($application)) {
        $completeness = ['available' => FALSE];
      }
    }
    if (($completeness['available'] ?? FALSE) === TRUE) {
      $fieldByAttachment = array_fill_keys($completeness['unassignedAttachmentIds'], AttachmentProofProviderInterface::UNASSIGNED_FIELD_ID);
      foreach ($completeness['proofs'] as $proof) {
        foreach ($proof['attachmentIds'] as $attachmentId) {
          $fieldByAttachment[$attachmentId] = $proof['fieldId'];
        }
      }
      foreach ($data as &$row) {
        $row['fieldId'] = $fieldByAttachment[$row['id']] ?? $row['fieldId'];
      }
      unset($row);
    }

    return $this->noStore(new JsonResponse([
      'data' => $data,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application_attachments',
        'count' => count($data),
        ...($completeness !== NULL ? ['proofCompleteness' => $completeness] : []),
      ],
    ]));
  }

  /**
   * Maps sent message files to their context, without exposing message text.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application already checked by the scoped admin route.
   * @param array $attachments
   *   Attachments being listed.
   *
   * @return array<int, array{id: string, authorRole: string, visibility: string, createdAt: string}>
   *   Message context by file id.
   */
  private function attachmentMessages(ApplicationInterface $application, array $attachments): array {
    $has_message_files = FALSE;
    foreach ($attachments as $attachment) {
      if ($attachment instanceof ApplicationAttachmentInterface
        && $attachment->get('field_field_id')->value === 'comment_attachment') {
        $has_message_files = TRUE;
        break;
      }
    }
    if (!$has_message_files) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('application_comment');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_application', (int) $application->id())
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();
    $messages = [];
    $account = $this->currentUser();
    $staff = (int) $account->id() === 1 || BeantragtRoles::isStaffAccount($account);
    foreach ($storage->loadMultiple($ids) as $comment) {
      if (!$comment instanceof ApplicationCommentInterface
        || ($comment->getVisibility() === 'internal' && !$staff)) {
        continue;
      }
      foreach ($comment->get('field_attachments')->getValue() as $item) {
        $fid = (int) ($item['target_id'] ?? 0);
        $messages[$fid] ??= [
          'id' => (string) $comment->id(),
          'authorRole' => $comment->getAuthorRole(),
          'visibility' => $comment->getVisibility(),
          'createdAt' => gmdate('c', $comment->getCreatedTime()),
        ];
      }
    }
    return $messages;
  }

  /**
   * Decodes the persisted PII-free document guard report.
   */
  private function attachmentGuardReport(ApplicationAttachmentInterface $attachment): ?object {
    if (!$attachment->hasField('field_guard_report') || $attachment->get('field_guard_report')->isEmpty()) {
      return NULL;
    }
    $report_json = trim((string) ($attachment->get('field_guard_report')->value ?? ''));
    if ($report_json === '') {
      return NULL;
    }
    try {
      $report = json_decode($report_json, FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_object($report) ? $report : NULL;
  }

  /**
   * Updates attachment analysis metadata from the trusted Nuxt service proxy.
   */
  public function updateAttachmentAnalysis(string $attachment_uuid, Request $request): JsonResponse {
    if (!$this->isTrustedServiceRequest($request)) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }

    $initial_attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$initial_attachment instanceof ApplicationAttachmentInterface) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }
    $lock_name = $this->attachmentClaimLockName($initial_attachment);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      return $this->errorResponse('attachment.claim_in_progress', 'Attachment claim is already in progress.', Response::HTTP_CONFLICT);
    }
    try {
      $this->entityTypeManager->getStorage('application_attachment')->resetCache([(int) $initial_attachment->id()]);
      $attachment = $this->loadAttachmentByUuid($attachment_uuid);
      if (!$attachment instanceof ApplicationAttachmentInterface) {
        return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
      }
      if ($this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_QUARANTINED) {
        return $this->errorResponse('attachment.quarantined', 'Quarantined attachments cannot be analyzed.', Response::HTTP_CONFLICT);
      }
      $payload = $this->decodeAnalysisPayload($request);
      $status = $this->normalizeAnalysisStatus($payload['status'] ?? NULL);
      $analysis_run_id = $this->normalizeAnalysisRunId($payload['analysisRunId'] ?? NULL);
      if (array_key_exists('analysisRunId', $payload) && $analysis_run_id === NULL) {
        throw new BadRequestHttpException('Attachment analysis run id is invalid.');
      }
      $story = $payload['story'] ?? NULL;
      if ($story !== NULL && !in_array($story, [
        ...AttachmentAnalysisMetadataWriter::STORIES,
        AttachmentAnalysisMetadataWriter::STORY_LEGACY,
      ], TRUE)) {
        throw new BadRequestHttpException('Attachment analysis story is invalid.');
      }
      $stored_metadata = AttachmentAnalysisMetadataWriter::decode((string) ($attachment->get('field_analysis_metadata')->value ?? ''));
      // The stored record decides before the profile: a version 2 record is
      // always written per story, a legacy run from before a switch to a
      // story profile still lands on the legacy path.
      $story_mode = AttachmentAnalysisMetadataWriter::isV2($stored_metadata)
        || (AttachmentAnalysisMetadataWriter::isStoryProfile(AttachmentAnalysisMetadataWriter::fieldAnalysisProfile($attachment))
          && !AttachmentAnalysisMetadataWriter::holdsLegacyRun($stored_metadata));
      // Story A is a narrower check than the legacy run: a 'citizen' write
      // against a legacy record takes the legacy path and its run id check.
      // A 'staff' write outside story mode is refused there.
      if ($story_mode || $story === AttachmentAnalysisMetadataWriter::STORY_STAFF) {
        return $this->updateAttachmentAnalysisStory($attachment, $attachment_uuid, $payload, $status, $analysis_run_id, is_string($story) ? $story : NULL, $story_mode, $stored_metadata);
      }
      $current_metadata = $this->attachmentAnalysisMetadata($attachment);
      $current_analysis_run_id = $this->normalizeAnalysisRunId($current_metadata['analysisRunId'] ?? NULL);
      if ($current_analysis_run_id !== NULL || $analysis_run_id !== NULL) {
        $form_uuid = $this->normalizeUuid($payload['formId'] ?? NULL);
        $field_id = $this->normalizeFieldId($payload['fieldId'] ?? NULL);
        if ($analysis_run_id === NULL
          || $current_analysis_run_id === NULL
          || $form_uuid === NULL
          || $field_id === NULL
          || (string) ($attachment->get('field_schema')->entity?->uuid() ?? '') !== $form_uuid
          || (string) ($attachment->get('field_field_id')->value ?? '') !== $field_id
          || (string) ($attachment->get('field_analysis_status')->value ?? '') !== 'pending'
          || $current_analysis_run_id !== $analysis_run_id) {
          return $this->errorResponse('attachment.analysis_run_invalid', 'Attachment analysis run is no longer current.', Response::HTTP_CONFLICT);
        }
      }
      $metadata = $this->normalizeAnalysisMetadata($payload['metadata'] ?? []);
      $metadata['status'] = $status;
      if ($analysis_run_id !== NULL) {
        $metadata['analysisRunId'] = $analysis_run_id;
      }
      if (!empty($metadata['result']['extracted'])) {
        $protection = $this->dataProtection ?? throw new \RuntimeException('Analysis data protection is unavailable.');
        $metadata['result'] = $protection->protectAnalysisResult($metadata['result']);
      }
      foreach ($metadata['stories'] ?? [] as $name => $record) {
        if (is_array($record) && !empty($record['result']['extracted'])) {
          $protection = $this->dataProtection ?? throw new \RuntimeException('Analysis data protection is unavailable.');
          $metadata['stories'][$name]['result'] = $protection->protectAnalysisResult($record['result']);
        }
      }
      $encoded_metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      if (strlen($encoded_metadata) > self::MAX_ANALYSIS_METADATA_BYTES) {
        throw new BadRequestHttpException('Attachment analysis metadata is too large.');
      }
      $attachment->set('field_analysis_status', $status);
      $attachment->set('field_analysis_metadata', $encoded_metadata);
      try {
        $attachment->save();
      }
      catch (\Throwable $e) {
        $this->logger->error('Attachment analysis update failed for @attachment: @exception.', [
          '@attachment' => $attachment_uuid,
          '@exception' => get_class($e),
        ]);
        return $this->errorResponse('attachment.analysis_save_failed', 'Attachment analysis could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return $this->noStore(new JsonResponse([
        'data' => [
          'id' => (string) $attachment->uuid(),
          'type' => 'application_attachment',
          'analysisStatus' => $status,
          'analysisMetadata' => $this->analysisMetadataForResponse($metadata),
        ],
        'meta' => [
          'apiVersion' => self::API_VERSION,
          'resource' => 'application_attachment_analysis',
        ],
      ]));
    }
    catch (BadRequestHttpException $e) {
      return $this->errorResponse('attachment.invalid_analysis', $e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    catch (\JsonException) {
      return $this->errorResponse('attachment.invalid_analysis', 'Attachment analysis metadata is invalid.', Response::HTTP_BAD_REQUEST);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Writes one story result of a story profile.
   *
   * A story write needs the story and the run id that Drupal minted for it.
   * It is accepted only while that story is pending with the same run id and
   * replaces stories.{story} alone, so a stale or replayed callback can never
   * touch the other story. A write without story or run id is refused.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   Attachment loaded under the claim lock.
   * @param string $attachment_uuid
   *   Attachment UUID for logging.
   * @param array<string, mixed> $payload
   *   Decoded request payload.
   * @param string $status
   *   Normalised lifecycle status.
   * @param string|null $analysis_run_id
   *   Run id sent by the callback.
   * @param string|null $story
   *   Story sent by the callback.
   * @param bool $story_mode
   *   TRUE for a story profile or a version 2 record.
   * @param array<string, mixed> $stored_metadata
   *   Decoded stored metadata.
   */
  private function updateAttachmentAnalysisStory(
    ApplicationAttachmentInterface $attachment,
    string $attachment_uuid,
    array $payload,
    string $status,
    ?string $analysis_run_id,
    ?string $story,
    bool $story_mode,
    array $stored_metadata,
  ): JsonResponse {
    $form_uuid = $this->normalizeUuid($payload['formId'] ?? NULL);
    $field_id = $this->normalizeFieldId($payload['fieldId'] ?? NULL);
    $record = $story !== NULL && in_array($story, AttachmentAnalysisMetadataWriter::STORIES, TRUE)
      ? AttachmentAnalysisMetadataWriter::storyRecord($stored_metadata, $story)
      : [];
    $current_run_id = $this->normalizeAnalysisRunId($record['analysisRunId'] ?? NULL);
    if (!$story_mode
      || $story === NULL
      || !in_array($story, AttachmentAnalysisMetadataWriter::STORIES, TRUE)
      || $analysis_run_id === NULL
      || $current_run_id === NULL
      || $form_uuid === NULL
      || $field_id === NULL
      || (string) ($attachment->get('field_schema')->entity?->uuid() ?? '') !== $form_uuid
      || (string) ($attachment->get('field_field_id')->value ?? '') !== $field_id
      || ($record['status'] ?? NULL) !== 'pending'
      || !hash_equals($current_run_id, $analysis_run_id)) {
      return $this->errorResponse('attachment.analysis_run_invalid', 'Attachment analysis run is no longer current.', Response::HTTP_CONFLICT);
    }

    $patch = $this->normalizeAnalysisMetadata($payload['metadata'] ?? []);
    unset($patch['version'], $patch['stories'], $patch['analysisRunId'], $patch['analysisStartedAt']);
    $patch['status'] = $status;
    $encoded_patch = json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($encoded_patch) > self::MAX_ANALYSIS_METADATA_BYTES) {
      throw new BadRequestHttpException('Attachment analysis metadata is too large.');
    }
    try {
      $written = $this->metadataWriter()->mergeStoryLocked($attachment, $story, $patch);
    }
    catch (\Throwable $e) {
      $this->logger->error('Attachment analysis update failed for @attachment: @exception.', [
        '@attachment' => $attachment_uuid,
        '@exception' => get_class($e),
      ]);
      return $this->errorResponse('attachment.analysis_save_failed', 'Attachment analysis could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    $metadata = $this->normalizeAnalysisMetadata($written['metadata'], TRUE);
    // A run the upload started (no worker record; the queue adds one to each
    // of its runs) may fail after the claim, whose enqueue skipped the story
    // while the run held its lease. Re-enqueue after the lease ends. Runs that
    // started in the queue follow its retry policy.
    if ($status === 'failed'
      && !array_key_exists('worker', $record)
      && !$attachment->get('field_application')->isEmpty()
      && $this->analysisDispatcher instanceof AttachmentAnalysisDispatcher) {
      try {
        $this->analysisDispatcher->enqueueAfterUploadRunFailed($attachment, $story);
      }
      catch (\Throwable $e) {
        $this->logger->error('Attachment analysis enqueue after a failed upload run failed for @attachment: @exception.', [
          '@attachment' => $attachment_uuid,
          '@exception' => get_class($e),
        ]);
      }
    }

    return $this->noStore(new JsonResponse([
      'data' => [
        'id' => (string) $attachment->uuid(),
        'type' => 'application_attachment',
        'analysisStatus' => (string) ($metadata['status'] ?? $status),
        'analysisStory' => $story,
        'analysisMetadata' => $this->analysisMetadataForResponse($metadata),
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application_attachment_analysis',
      ],
    ]));
  }

  /**
   * Starts a case worker's rerun of one analysis story.
   *
   * The story is set to pending with a new run id and lease under the claim
   * lock and queued directly; the queue prepares the source in Drupal (guard
   * derivative, sovereign pages or, for story A under external_allowed with
   * consent, the original). The browser and Nuxt never send file bytes. A
   * complete story reruns, a failed one too; a guard block stays final.
   */
  public function rerunApplicationAttachmentAnalysis(ApplicationInterface $application, string $attachment_uuid, string $story): JsonResponse {
    if (!$application->access('view')) {
      throw new AccessDeniedHttpException();
    }
    if (!in_array($story, [
      AttachmentAnalysisMetadataWriter::STORY_LEGACY,
      ...AttachmentAnalysisMetadataWriter::STORIES,
    ], TRUE)) {
      return $this->errorResponse('attachment.invalid_request', 'Attachment analysis story is invalid.', Response::HTTP_BAD_REQUEST);
    }
    $attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$attachment instanceof ApplicationAttachmentInterface
      || (int) $attachment->get('field_application')->target_id !== (int) $application->id()) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }
    $field_id = (string) ($attachment->get('field_field_id')->value ?? '');
    if (!$this->canAccessClaimedAttachment($application, $attachment, $field_id)
      || ($story === AttachmentAnalysisMetadataWriter::STORY_STAFF && !ProtectedDataGate::canReadProtectedAttachmentData($this->currentUser()))) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }
    if ($this->attachmentScanStatus($attachment) !== AttachmentScanResult::STATUS_CLEAN) {
      return $this->errorResponse('attachment.quarantined', 'Quarantined attachments cannot be analyzed.', Response::HTTP_CONFLICT);
    }
    $profile = AttachmentAnalysisMetadataWriter::fieldAnalysisProfile($attachment);
    if (!AttachmentAnalysisMetadataWriter::rerunStoryAllowed($profile, $story)
      || !$this->analysisDispatcher instanceof AttachmentAnalysisDispatcher) {
      return $this->errorResponse('attachment.analysis_not_available', 'This analysis cannot run for the attachment.', Response::HTTP_CONFLICT);
    }
    $egress_policy = $profile['egressPolicy'] ?? NULL;
    $consented_original = $story === AttachmentAnalysisMetadataWriter::STORY_CITIZEN
      && $egress_policy === 'external_allowed'
      && $attachment->hasField('field_external_analysis_consent')
      && (string) ($attachment->get('field_external_analysis_consent')->value ?? '0') === '1';
    $guard_derivative_required = in_array($egress_policy, ['external_redacted', 'external_allowed'], TRUE)
      && !$consented_original;

    try {
      $this->analysisDispatcher->prepareRerunQueue($this->database);
      $this->metadataWriter()->startRerun(
        $attachment,
        $story,
        $guard_derivative_required,
        AttachmentAnalysisDispatcher::PENDING_DISPATCH_LEASE_SECONDS,
        fn (ApplicationAttachmentInterface $fresh, string $run_id) => $this->analysisDispatcher->enqueueRerun($fresh, $story, $run_id),
      );
    }
    catch (AttachmentAnalysisRerunRefusedException $e) {
      return $this->errorResponse($e->getMessage(), 'Attachment analysis cannot be rerun now.', Response::HTTP_CONFLICT);
    }
    catch (AttachmentAnalysisMetadataLockedException) {
      return $this->errorResponse('attachment.claim_in_progress', 'Attachment claim is already in progress.', Response::HTTP_CONFLICT);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Attachment analysis could not be queued for @attachment: @class.', [
        '@attachment' => $attachment_uuid,
        '@class' => $exception::class,
      ]);
      return $this->errorResponse('attachment.analysis_queue_unavailable', 'Attachment analysis could not be queued.', Response::HTTP_SERVICE_UNAVAILABLE);
    }
    $this->logger->info('Attachment analysis rerun of story @story queued for @attachment by user @uid.', [
      '@story' => $story,
      '@attachment' => $attachment_uuid,
      '@uid' => (int) $this->currentUser()->id(),
    ]);

    return $this->successResponse([
      'id' => (string) $attachment->uuid(),
      'type' => 'application_attachment',
      'analysisStory' => $story,
      'analysisStatus' => 'pending',
    ], Response::HTTP_ACCEPTED);
  }

  /**
   * Prepares the pages of story A for one citizen upload that is unclaimed.
   *
   * Only the trusted Nuxt upload proxy calls this, right after the upload
   * minted the run of story A, with the claim token the citizen holds and
   * that run id. A sovereign or local PDF never leaves Drupal raw: the source
   * preparer renders its first pages, from a transient analysis_sovereign
   * derivative when redaction is wanted, and nothing of it is stored (no
   * field_guard_* write, no file). A preparation failure is written into
   * stories.citizen of this run only, with its own code. The claim lock is
   * held for the checks, not for the guard and render calls, which may take
   * longer than the lock lives.
   */
  public function prepareCitizenAnalysisSource(string $attachment_uuid, Request $request): JsonResponse {
    if (!$this->isTrustedServiceRequest($request)) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }
    if ($request->query->get('story') !== AttachmentAnalysisMetadataWriter::STORY_CITIZEN) {
      // Story B never runs before the claim; it reads its pages in the queue.
      return $this->errorResponse('attachment.invalid_request', 'Only story A is prepared for an upload.', Response::HTTP_BAD_REQUEST);
    }
    try {
      $payload = $this->decodeAnalysisPayload($request);
    }
    catch (BadRequestHttpException) {
      return $this->errorResponse('attachment.invalid_request', 'Attachment analysis source request is invalid.', Response::HTTP_BAD_REQUEST);
    }
    $attachment_uuid = $this->normalizeUuid($attachment_uuid);
    $form_uuid = $this->normalizeUuid($payload['formId'] ?? NULL);
    $field_id = $this->normalizeFieldId($payload['fieldId'] ?? NULL);
    $claim_token = $this->normalizeClaimToken($payload['claimToken'] ?? NULL);
    $analysis_run_id = $this->normalizeAnalysisRunId($payload['analysisRunId'] ?? NULL);
    if ($attachment_uuid === NULL || $form_uuid === NULL || $field_id === NULL || $claim_token === NULL || $analysis_run_id === NULL) {
      return $this->errorResponse('attachment.invalid_request', 'Attachment analysis source request is invalid.', Response::HTTP_BAD_REQUEST);
    }

    $initial_attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$initial_attachment instanceof ApplicationAttachmentInterface) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }
    $lock_name = $this->attachmentClaimLockName($initial_attachment);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      return $this->errorResponse('attachment.claim_in_progress', 'Attachment claim is already in progress.', Response::HTTP_CONFLICT);
    }
    try {
      $this->entityTypeManager->getStorage('application_attachment')->resetCache([(int) $initial_attachment->id()]);
      $attachment = $this->loadAttachmentByUuid($attachment_uuid);
      if (!$attachment instanceof ApplicationAttachmentInterface
        || !$this->holdsUnclaimedUpload($attachment, $claim_token)
        || (string) ($attachment->get('field_schema')->entity?->uuid() ?? '') !== $form_uuid
        || (string) ($attachment->get('field_field_id')->value ?? '') !== $field_id) {
        return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
      }
      $profile = AttachmentAnalysisMetadataWriter::fieldAnalysisProfile($attachment);
      if ($profile === NULL
        || ($profile['enabled'] ?? FALSE) !== TRUE
        || !AttachmentAnalysisMetadataWriter::isStoryProfile($profile)
        || !AttachmentAnalysisMetadataWriter::profileHasCitizenStory($profile)
        || !AttachmentAnalysisSourcePreparer::handlesProfile($profile)) {
        return $this->errorResponse('attachment.analysis_not_available', 'This analysis cannot run for the attachment.', Response::HTTP_CONFLICT);
      }
      $record = AttachmentAnalysisMetadataWriter::storyRecord(
        AttachmentAnalysisMetadataWriter::decode((string) ($attachment->get('field_analysis_metadata')->value ?? '')),
        AttachmentAnalysisMetadataWriter::STORY_CITIZEN,
      );
      $current_run_id = $this->normalizeAnalysisRunId($record['analysisRunId'] ?? NULL);
      if ($current_run_id === NULL
        || !hash_equals($current_run_id, $analysis_run_id)
        || !AttachmentAnalysisMetadataWriter::hasActiveLease($record, $this->time->getRequestTime(), self::ANALYSIS_LEASE_TTL)) {
        return $this->errorResponse('attachment.analysis_run_invalid', 'Attachment analysis run is no longer current.', Response::HTTP_CONFLICT);
      }
      $media_type = (string) ($attachment->get('field_mime_type')->value ?? '');
      if ($media_type !== 'application/pdf' && !AttachmentAnalysisSourcePreparer::requiresSovereignDerivative($profile, AttachmentAnalysisMetadataWriter::STORY_CITIZEN)) {
        // Images reach the proxy with the upload itself; only PDFs need pages.
        return $this->errorResponse('attachment.analysis_source_unsupported', 'Only PDF uploads are prepared as pages.', Response::HTTP_CONFLICT);
      }
      $original = $this->readUnclaimedUploadBytes($attachment);
    }
    finally {
      $this->lock->release($lock_name);
    }
    if ($original === NULL) {
      return $this->errorResponse('attachment.analysis_source_unavailable', 'Attachment file is unavailable.', Response::HTTP_CONFLICT);
    }

    // Guard and render calls each have their own bounded timeout; the
    // request must outlive both (the queue path runs them in cron).
    Environment::setTimeLimit(180);
    try {
      $source = $this->sourcePreparer()->prepareSovereign($profile, $original, $media_type, AttachmentAnalysisMetadataWriter::STORY_CITIZEN);
    }
    catch (AttachmentAnalysisSourceException $e) {
      $recorded = $this->recordCitizenSourceFailure($attachment, $analysis_run_id, $e->analysisCode);
      $this->logger->warning('Citizen attachment analysis source failed for @attachment: @code', [
        '@attachment' => $attachment_uuid,
        '@code' => $e->analysisCode,
      ]);
      return $this->errorResponse(
        'attachment.analysis_source_failed',
        'The attachment could not be prepared for analysis.',
        $e->retryable ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_CONFLICT,
        ['analysisCode' => $e->analysisCode, 'recorded' => $recorded],
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Citizen attachment analysis source failed unexpectedly for @attachment: @exception.', [
        '@attachment' => $attachment_uuid,
        '@exception' => get_class($e),
      ]);
      return $this->errorResponse('attachment.analysis_source_unavailable', 'The attachment could not be prepared for analysis.', Response::HTTP_SERVICE_UNAVAILABLE);
    }
    if (($source['kind'] ?? NULL) !== 'pages' || !is_array($source['pages'] ?? NULL) || $source['pages'] === []) {
      return $this->errorResponse('attachment.analysis_source_unavailable', 'The attachment could not be prepared for analysis.', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    return $this->noStore(new JsonResponse([
      'data' => [
        'id' => $attachment_uuid,
        'type' => 'application_attachment_analysis_source',
        'story' => AttachmentAnalysisMetadataWriter::STORY_CITIZEN,
        'mediaType' => $media_type,
        'size' => strlen($original),
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
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application_attachment_analysis_source',
      ],
    ]));
  }

  /**
   * Returns the applicant's view of story A for one unclaimed upload.
   *
   * Only the trusted Nuxt proxy calls this, with the claim token the citizen
   * holds. Every mismatch (unknown attachment, foreign or expired token,
   * claimed or quarantined upload) answers the same 404. The answer is an
   * allowlist of stories.citizen, or of a version 1 record: status, code,
   * language and the result without anything purpose-bound. extracted keeps
   * only story A or legacy nonPersonal yes/no facts, from which the proxy
   * rebuilds the criteria. Never returned: stories.staff, run ids, lease and
   * worker data, provider, prompt version and source facts.
   */
  public function citizenAttachmentAnalysis(string $attachment_uuid, Request $request): JsonResponse {
    if (!$this->isTrustedServiceRequest($request)) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }
    try {
      $payload = $this->decodeAnalysisPayload($request);
    }
    catch (BadRequestHttpException) {
      $payload = [];
    }
    $attachment_uuid = $this->normalizeUuid($attachment_uuid);
    $claim_token = $this->normalizeClaimToken($payload['claimToken'] ?? NULL);
    if ($attachment_uuid === NULL || $claim_token === NULL) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }
    $attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$attachment instanceof ApplicationAttachmentInterface
      || !$this->holdsUnclaimedUpload($attachment, $claim_token)) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }

    $metadata = $this->attachmentAnalysisMetadata($attachment);
    $story_mode = AttachmentAnalysisMetadataWriter::isV2($metadata);
    $record = $story_mode
      ? AttachmentAnalysisMetadataWriter::storyRecord($metadata, AttachmentAnalysisMetadataWriter::STORY_CITIZEN)
      : $metadata;
    $status = in_array($record['status'] ?? NULL, ['pending', 'complete', 'failed'], TRUE)
      ? (string) $record['status']
      : 'not_requested';
    $language = in_array($metadata['language'] ?? NULL, ['de', 'en'], TRUE) ? $metadata['language'] : 'de';
    $data = [
      'id' => (string) $attachment->uuid(),
      'type' => 'application_attachment_citizen_analysis',
      'formId' => (string) ($attachment->get('field_schema')->entity?->uuid() ?? ''),
      'fieldId' => (string) ($attachment->get('field_field_id')->value ?? ''),
      'mode' => $story_mode ? 'story' : 'legacy',
      'language' => $language,
      'status' => $status,
    ];
    if ($status !== 'not_requested' && is_string($record['code'] ?? NULL)) {
      $data['code'] = $record['code'];
    }
    if ($status === 'complete' && is_array($record['result'] ?? NULL)) {
      $profile = AttachmentAnalysisMetadataWriter::fieldAnalysisProfile($attachment);
      try {
        $result = $this->citizenAnalysisResult($record['result'], $profile);
      }
      catch (\RuntimeException) {
        $safe_result = $record['result'];
        unset($safe_result['extracted']);
        $result = $this->citizenAnalysisResult($safe_result, $profile);
        $data['extractedUnavailable'] = TRUE;
        $this->logUnavailableAnalysisValues((string) $attachment->uuid());
      }
      if ($result !== []) {
        $data['result'] = $result;
      }
    }

    return $this->noStore(new JsonResponse([
      'data' => $data,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application_attachment_citizen_analysis',
      ],
    ]));
  }

  /**
   * Reduces a normalised result to what the applicant's view may build on.
   *
   * @param array<string, mixed> $result
   *   Normalised result metadata.
   * @param array<string, mixed>|null $profile
   *   Current field analysis profile.
   *
   * @return array<string, mixed>
   *   Outcome, counts, notices and the nonPersonal yes/no facts only.
   */
  private function citizenAnalysisResult(array $result, ?array $profile): array {
    $reduced = [];
    foreach (['outcome', 'missingCount', 'missingKeys', 'confidence', 'notices', 'pagesChecked'] as $key) {
      if (array_key_exists($key, $result)) {
        $reduced[$key] = $result[$key];
      }
    }
    // This reveal exception requires an explicit nonPersonal: true, not an
    // alias or a truthy value. The shared helper also excludes purposeBound.
    $fact_profile = $profile ?? [];
    foreach (['properties', 'fields'] as $shape) {
      if (is_array($fact_profile['resultSchema'][$shape] ?? NULL)) {
        $fact_profile['resultSchema'][$shape] = array_filter(
          $fact_profile['resultSchema'][$shape],
          static fn (mixed $definition): bool => is_array($definition) && ($definition['nonPersonal'] ?? FALSE) === TRUE,
        );
      }
    }
    $fact_keys = AttachmentAnalysisMetadataWriter::citizenFactKeys($fact_profile);
    if (isset($reduced['missingKeys'])) {
      $reduced['missingKeys'] = array_values(array_intersect($reduced['missingKeys'], $fact_keys));
      if ($reduced['missingKeys'] === []) {
        unset($reduced['missingKeys']);
      }
    }
    $candidates = array_values(array_filter(
      (array) ($result['extracted'] ?? []),
      static fn (mixed $item): bool => is_array($item)
        && in_array($item['key'] ?? NULL, $fact_keys, TRUE)
        && array_key_exists('value', $item),
    ));
    if ($candidates === []) {
      return $reduced;
    }
    $protection = $this->dataProtection ?? throw new \RuntimeException('Analysis data protection is unavailable.');
    $revealed = $protection->revealAnalysisResult(['extracted' => $candidates]);
    // No protected-access log: after claim-token validation these are the
    // citizen's own non-personal criteria, not a staff read of protected data.
    $facts = [];
    foreach ($revealed['extracted'] as $item) {
      if (is_bool($item['value'])) {
        $facts[] = ['key' => $item['key'], 'type' => 'boolean', 'value' => $item['value']];
      }
    }
    if ($facts !== []) {
      $reduced['extracted'] = $facts;
    }
    return $reduced;
  }

  /**
   * Returns TRUE for a clean, unclaimed upload whose claim token matches.
   */
  private function holdsUnclaimedUpload(ApplicationAttachmentInterface $attachment, string $claim_token): bool {
    $expires = (int) ($attachment->get('field_claim_expires')->value ?? 0);
    $claim_hash = (string) ($attachment->get('field_claim_token_hash')->value ?? '');
    return (string) ($attachment->get('field_upload_status')->value ?? '') === 'uploaded'
      && $attachment->get('field_application')->isEmpty()
      && $this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_CLEAN
      && $expires > $this->time->getRequestTime()
      && $claim_hash !== ''
      && hash_equals($claim_hash, hash('sha256', $claim_token));
  }

  /**
   * Reads the bytes of an unclaimed upload within the field's size limit.
   */
  private function readUnclaimedUploadBytes(ApplicationAttachmentInterface $attachment): ?string {
    $file = $attachment->get('field_file')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $uri = (string) $file->getFileUri();
    $size = (int) $file->getSize();
    $form = $attachment->get('field_schema')->entity;
    $file_field = $form instanceof FormSchemaInterface
      ? $this->formFileField($form, (string) ($attachment->get('field_field_id')->value ?? ''))
      : NULL;
    $max_bytes = $file_field !== NULL ? $this->fileFieldMaxBytes($file_field) : self::MAX_FILE_SIZE;
    if ($uri === '' || !is_file($uri) || $size <= 0 || $size > $max_bytes) {
      return NULL;
    }
    $data = file_get_contents($uri, FALSE, NULL, 0, self::MAX_FILE_SIZE + 1);
    return is_string($data) && $data !== '' && strlen($data) <= $max_bytes ? $data : NULL;
  }

  /**
   * Writes a preparation failure into stories.citizen of this run only.
   *
   * @return bool
   *   TRUE when the failure was stored; FALSE when the run is no longer
   *   current or the write failed (the proxy then releases the run itself).
   */
  private function recordCitizenSourceFailure(ApplicationAttachmentInterface $attachment, string $analysis_run_id, string $code): bool {
    $lock_name = $this->attachmentClaimLockName($attachment);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      $this->lock->wait($lock_name, 5);
      if (!$this->lock->acquire($lock_name, 30.0)) {
        return FALSE;
      }
    }
    try {
      $this->entityTypeManager->getStorage('application_attachment')->resetCache([(int) $attachment->id()]);
      $fresh = $this->loadAttachmentByUuid((string) $attachment->uuid());
      if (!$fresh instanceof ApplicationAttachmentInterface) {
        return FALSE;
      }
      $record = AttachmentAnalysisMetadataWriter::storyRecord(
        AttachmentAnalysisMetadataWriter::decode((string) ($fresh->get('field_analysis_metadata')->value ?? '')),
        AttachmentAnalysisMetadataWriter::STORY_CITIZEN,
      );
      $current_run_id = $this->normalizeAnalysisRunId($record['analysisRunId'] ?? NULL);
      if (($record['status'] ?? NULL) !== 'pending'
        || $current_run_id === NULL
        || !hash_equals($current_run_id, $analysis_run_id)) {
        return FALSE;
      }
      $this->metadataWriter()->mergeStoryLocked($fresh, AttachmentAnalysisMetadataWriter::STORY_CITIZEN, [
        'status' => 'failed',
        'code' => $code,
        'requiresHumanReview' => TRUE,
      ]);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('Citizen attachment analysis failure could not be saved for @attachment: @exception.', [
        '@attachment' => (string) $attachment->uuid(),
        '@exception' => get_class($e),
      ]);
      return FALSE;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns the source preparer; without one every preparation fails closed.
   */
  private function sourcePreparer(): AttachmentAnalysisSourcePreparer {
    return $this->sourcePreparer ?? new AttachmentAnalysisSourcePreparer();
  }

  /**
   * Normalizes a citizen claim token, NULL when malformed.
   */
  private function normalizeClaimToken(mixed $claim_token): ?string {
    if (!is_string($claim_token)) {
      return NULL;
    }
    $claim_token = trim($claim_token);
    return preg_match('/^[A-Za-z0-9_-]{32,96}$/', $claim_token) === 1 ? $claim_token : NULL;
  }

  /**
   * Returns TRUE when the metadata holds values read from the document.
   *
   * Version 1 keeps them in result.extracted, version 2 per story.
   *
   * @param array<string, mixed> $metadata
   *   Normalised metadata, retaining encrypted extracted entries from storage.
   */
  private function analysisMetadataHasExtractedValues(array $metadata): bool {
    if (!empty($metadata['result']['extracted'])) {
      return TRUE;
    }
    if (!is_array($metadata['stories'] ?? NULL)) {
      return FALSE;
    }
    foreach ($metadata['stories'] as $record) {
      if (is_array($record) && !empty($record['result']['extracted'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the shared metadata writer.
   */
  private function metadataWriter(): AttachmentAnalysisMetadataWriter {
    return $this->metadataWriter ?? new AttachmentAnalysisMetadataWriter($this->entityTypeManager, $this->lock, $this->time, $this->dataProtection, $this->database);
  }

  /**
   * Downloads one attachment through the application scope.
   *
   * Internal route for the Nuxt proxy only, not part of /api/v1.
   */
  public function downloadApplicationAttachment(ApplicationInterface $application, string $attachment_uuid): BinaryFileResponse {
    if (!$application->access('view')) {
      throw new AccessDeniedHttpException();
    }

    $attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$attachment instanceof ApplicationAttachmentInterface
      || (int) $attachment->get('field_application')->target_id !== (int) $application->id()) {
      throw new NotFoundHttpException();
    }

    $field_id = (string) $attachment->get('field_field_id')->value;
    if (!$this->canAccessClaimedAttachment($application, $attachment, $field_id)) {
      throw new AccessDeniedHttpException();
    }
    if ($this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_QUARANTINED) {
      throw new AccessDeniedHttpException();
    }

    if ($attachment->get('field_file')->isEmpty()) {
      throw new NotFoundHttpException();
    }
    $file = $this->entityTypeManager->getStorage('file')->load($attachment->get('field_file')->target_id);
    if (!$file instanceof FileInterface) {
      throw new NotFoundHttpException();
    }
    $uri = (string) $file->getFileUri();
    if ($uri === '' || !is_file($uri)) {
      throw new NotFoundHttpException();
    }

    $this->auditDownload($application, $attachment);
    $response = new BinaryFileResponse($uri, Response::HTTP_OK, [
      'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
      'Cache-Control' => 'private, no-store, max-age=0',
      'Pragma' => 'no-cache',
      'X-Content-Type-Options' => 'nosniff',
    ], FALSE);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $this->attachmentFilename($attachment, $file),
    );
    return $response;
  }

  /**
   * Names a legacy batch scan by its submitted file field in the staff list.
   */
  private function legacyBatchAttachmentLabel(ApplicationInterface $application, string $field_id, FileInterface $file, string $original_filename): ?string {
    // Batch intake keeps the UUID filename in a dedicated case directory.
    if ($original_filename !== ''
      || !str_starts_with((string) $file->getFileUri(), 'private://application_attachments/bulk-')
      || !Uuid::isValid(pathinfo($file->getFilename(), PATHINFO_FILENAME))) {
      return NULL;
    }

    $schema = PublicSubmission::submittedSchema($application, $this->entityTypeManager);
    $field = $schema instanceof FormSchemaInterface ? $this->formFileField($schema, $field_id) : NULL;
    $label = is_string($field['label'] ?? NULL) ? trim($field['label']) : '';
    return $label !== '' ? $label : (string) $this->t('Attachment');
  }

  /**
   * Returns the original filename with safe separators and the file extension.
   */
  private function attachmentFilename(ApplicationAttachmentInterface $attachment, FileInterface $file): string {
    $original = trim((string) $attachment->get('field_original_filename')->value);
    $stored = $file->getFilename();
    $filename = preg_replace('~[/\\\\\p{Cc}]~u', '_', $original !== '' ? $original : $stored) ?? '';
    $extension = pathinfo($stored, PATHINFO_EXTENSION);
    $actual_extension = strtolower($extension);
    $name_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($actual_extension === 'jpeg') {
      $actual_extension = 'jpg';
    }
    if ($name_extension === 'jpeg') {
      $name_extension = 'jpg';
    }
    if ($actual_extension !== '' && $actual_extension !== $name_extension) {
      $filename .= '.' . $extension;
    }
    return $filename !== '' ? $filename : 'attachment';
  }

  /**
   * Downloads one guarded derivative through the application scope.
   */
  public function downloadApplicationAttachmentDerivative(ApplicationInterface $application, string $attachment_uuid): Response {
    if (!$application->access('view')) {
      throw new AccessDeniedHttpException();
    }

    $attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$attachment instanceof ApplicationAttachmentInterface
      || (int) $attachment->get('field_application')->target_id !== (int) $application->id()) {
      throw new NotFoundHttpException();
    }

    $field_id = (string) $attachment->get('field_field_id')->value;
    if (!$this->canAccessClaimedAttachment($application, $attachment, $field_id)) {
      throw new AccessDeniedHttpException();
    }
    if ($this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_QUARANTINED) {
      throw new AccessDeniedHttpException();
    }

    if (!$attachment->hasField('field_guard_status')
      || !in_array((string) ($attachment->get('field_guard_status')->value ?? ''), ['clean', 'redacted'], TRUE)
      || !$attachment->hasField('field_guard_derivative')
      || $attachment->get('field_guard_derivative')->isEmpty()) {
      return $this->noStore(new JsonResponse([
        'error' => ['code' => 'attachment.guard_derivative_missing'],
      ], Response::HTTP_CONFLICT));
    }
    $file = $this->entityTypeManager->getStorage('file')->load(
      $attachment->get('field_guard_derivative')->target_id,
    );
    if (!$file instanceof FileInterface) {
      return $this->noStore(new JsonResponse([
        'error' => ['code' => 'attachment.guard_derivative_missing'],
      ], Response::HTTP_CONFLICT));
    }
    $uri = (string) $file->getFileUri();
    if ($uri === '' || !is_file($uri) || !$this->guardDerivativeMatchesReport($attachment, $uri)) {
      return $this->noStore(new JsonResponse([
        'error' => ['code' => 'attachment.guard_derivative_missing'],
      ], Response::HTTP_CONFLICT));
    }

    $this->auditDownload($application, $attachment);
    $response = new BinaryFileResponse($uri, Response::HTTP_OK, [
      'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
      'Cache-Control' => 'private, no-store, max-age=0',
      'Pragma' => 'no-cache',
      'X-Content-Type-Options' => 'nosniff',
    ], FALSE);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $file->getFilename() ?: 'attachment',
    );
    return $response;
  }

  /**
   * Verifies a guarded derivative against its persisted PII-free report.
   */
  private function guardDerivativeMatchesReport(ApplicationAttachmentInterface $attachment, string $uri): bool {
    if (!$attachment->hasField('field_guard_report')) {
      return FALSE;
    }
    $report_json = trim((string) ($attachment->get('field_guard_report')->value ?? ''));
    try {
      $report = json_decode($report_json, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return FALSE;
    }
    $metadata = is_array($report) ? ($report['derivative'] ?? NULL) : NULL;
    if (!is_array($metadata)
      || !in_array($report['status'] ?? NULL, ['clean', 'redacted'], TRUE)
      || ($metadata['mediaType'] ?? NULL) !== 'application/pdf') {
      return FALSE;
    }
    $bytes = $this->readBoundedDerivative($uri);
    return is_string($bytes)
      && str_starts_with($bytes, '%PDF-')
      && ($metadata['size'] ?? NULL) === strlen($bytes)
      && is_string($metadata['sha256'] ?? NULL)
      && preg_match('/^[a-f0-9]{64}$/', $metadata['sha256']) === 1
      && hash_equals($metadata['sha256'], hash('sha256', $bytes));
  }

  /**
   * Reads a derivative without buffering beyond the attachment size limit.
   */
  private function readBoundedDerivative(string $uri): ?string {
    $handle = fopen($uri, 'rb');
    if ($handle === FALSE) {
      return NULL;
    }

    $bytes = '';
    try {
      while (!feof($handle) && strlen($bytes) <= self::MAX_FILE_SIZE) {
        $chunk = fread($handle, min(8192, self::MAX_FILE_SIZE + 1 - strlen($bytes)));
        if ($chunk === FALSE) {
          return NULL;
        }
        $bytes .= $chunk;
      }
    }
    finally {
      fclose($handle);
    }

    return $bytes !== '' && strlen($bytes) <= self::MAX_FILE_SIZE ? $bytes : NULL;
  }

  /**
   * Deletes a quarantined attachment and writes an application audit revision.
   */
  public function deleteQuarantinedApplicationAttachment(ApplicationInterface $application, string $attachment_uuid, Request $request): JsonResponse {
    if (!$application->access('update') || !$this->canResolveQuarantine($application)) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }

    $attachment = $this->loadAttachmentByUuid($attachment_uuid);
    if (!$attachment instanceof ApplicationAttachmentInterface
      || (int) $attachment->get('field_application')->target_id !== (int) $application->id()) {
      return $this->errorResponse('attachment.not_found', 'Attachment not found.', Response::HTTP_NOT_FOUND);
    }
    $field_id = (string) $attachment->get('field_field_id')->value;
    if (!$this->canAccessClaimedAttachment($application, $attachment, $field_id)) {
      return $this->errorResponse('attachment.access_denied', 'Access denied.', Response::HTTP_FORBIDDEN);
    }
    if ($this->attachmentScanStatus($attachment) !== AttachmentScanResult::STATUS_QUARANTINED) {
      return $this->errorResponse('attachment.not_quarantined', 'Only quarantined attachments can be resolved here.', Response::HTTP_CONFLICT);
    }

    try {
      $payload = $this->decodeDeletePayload($request);
      $reason = $this->normalizeDeleteReason($payload['reason'] ?? NULL);
    }
    catch (BadRequestHttpException $e) {
      return $this->errorResponse('attachment.invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST);
    }
    $file = NULL;
    if (!$attachment->get('field_file')->isEmpty()) {
      $file = $this->entityTypeManager->getStorage('file')->load($attachment->get('field_file')->target_id);
    }

    $account = $this->currentUser();
    $scan_message = $this->attachmentScanMessage($attachment);
    $transaction = $this->database->startTransaction('application_attachment_quarantine_delete');
    try {
      $application->setNewRevision(TRUE);
      $application->setRevisionUserId((int) $account->id());
      $application->setRevisionCreationTime($this->time->getRequestTime());
      RevisionLog::setEvent($application, 'attachment_deleted');
      RevisionLog::setMessage($application, $reason);
      $application->save();

      $this->entityTypeManager->getStorage('application_attachment')->delete([$attachment]);
      if ($file instanceof FileInterface) {
        $this->entityTypeManager->getStorage('file')->delete([$file]);
      }
      unset($transaction);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      // A transfer, deletion or comment holds the case: nothing was deleted,
      // the same request may succeed shortly.
      $locked = ApplicationLockedHttpException::fromFailure($e);
      if ($locked instanceof ApplicationLockedHttpException) {
        throw $locked;
      }
      $this->logger->error('Failed to delete quarantined attachment @attachment for application @application.', [
        '@attachment' => $attachment_uuid,
        '@application' => (string) $application->uuid(),
      ]);
      return $this->errorResponse('attachment.delete_failed', 'Attachment could not be deleted.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('Quarantined attachment @attachment for application @application deleted by uid @uid. Scanner message: @message', [
      '@attachment' => $attachment_uuid,
      '@application' => (string) $application->uuid(),
      '@uid' => (string) $account->id(),
      '@message' => $scan_message,
    ]);

    return $this->noStore(new JsonResponse([
      'data' => [
        'id' => $attachment_uuid,
        'type' => 'application_attachment',
        'deleted' => TRUE,
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application_attachment',
        'revisionId' => (int) $application->getRevisionId(),
      ],
    ]));
  }

  /**
   * Validates uploaded file properties.
   *
   * @return array<string, mixed>|null
   *   Error shape or NULL.
   */
  private function validateUploadedFile(UploadedFile $uploaded_file, int $max_size_bytes = self::MAX_FILE_SIZE): ?array {
    if ((int) $uploaded_file->getSize() > $max_size_bytes) {
      return [
        'code' => 'attachment.file_too_large',
        'message' => 'File exceeds the configured maximum size.',
        'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
      ];
    }

    $extension = strtolower($uploaded_file->getClientOriginalExtension());
    if (!in_array($extension, self::ALLOWED_EXTENSIONS, TRUE)) {
      return [
        'code' => 'attachment.unsupported_file_type',
        'message' => 'Only PDF, JPG, JPEG and PNG files are allowed.',
        'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
      ];
    }

    $expected_mime = self::ALLOWED_MIMES[$extension];
    $actual_mime = $this->detectUploadedMime($uploaded_file);
    if ($actual_mime !== $expected_mime) {
      $this->logger->warning('Application attachment MIME mismatch for extension @extension: expected @expected, detected @detected.', [
        '@extension' => $extension,
        '@expected' => $expected_mime,
        '@detected' => $actual_mime ?? 'unknown',
      ]);
      return [
        'code' => 'attachment.mime_mismatch',
        'message' => 'File content does not match the file extension.',
        'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
      ];
    }

    return NULL;
  }

  /**
   * Detects supported upload MIME types from file signatures.
   */
  private function detectUploadedMime(UploadedFile $uploaded_file): ?string {
    $path = $uploaded_file->getRealPath();
    if (!is_string($path) || $path === '' || !is_file($path)) {
      return NULL;
    }

    return ($this->mimeSniffer ?? new AttachmentMimeSniffer())->fromPath($path);
  }

  /**
   * Loads an application attachment by UUID.
   */
  private function loadAttachmentByUuid(string $uuid): ?ApplicationAttachmentInterface {
    $matches = $this->entityTypeManager->getStorage('application_attachment')->loadByProperties(['uuid' => $uuid]);
    $attachment = count($matches) === 1 ? reset($matches) : NULL;
    return $attachment instanceof ApplicationAttachmentInterface ? $attachment : NULL;
  }

  /**
   * Returns the stored malware scan status.
   */
  private function attachmentScanStatus(ApplicationAttachmentInterface $attachment): string {
    if (!$attachment->hasField('field_scan_status') || $attachment->get('field_scan_status')->isEmpty()) {
      return AttachmentScanResult::STATUS_CLEAN;
    }
    $status = (string) ($attachment->get('field_scan_status')->value ?? '');
    return in_array($status, [AttachmentScanResult::STATUS_CLEAN, AttachmentScanResult::STATUS_QUARANTINED], TRUE)
      ? $status
      : AttachmentScanResult::STATUS_QUARANTINED;
  }

  /**
   * Returns the stored malware scan message.
   */
  private function attachmentScanMessage(ApplicationAttachmentInterface $attachment): string {
    if (!$attachment->hasField('field_scan_message') || $attachment->get('field_scan_message')->isEmpty()) {
      return '';
    }
    return (string) ($attachment->get('field_scan_message')->value ?? '');
  }

  /**
   * Returns sanitized attachment analysis metadata.
   *
   * @return array<string, mixed>
   *   Analysis metadata or an empty object shape.
   */
  private function attachmentAnalysisMetadata(ApplicationAttachmentInterface $attachment): array {
    if (!$attachment->hasField('field_analysis_metadata') || $attachment->get('field_analysis_metadata')->isEmpty()) {
      return [];
    }
    $raw = (string) ($attachment->get('field_analysis_metadata')->value ?? '');
    if ($raw === '') {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      return $this->normalizeAnalysisMetadata($decoded, TRUE);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Keeps empty metadata as a JSON object for API consumers.
   *
   * @return array<string, mixed>|\stdClass
   *   Metadata response value.
   */
  private function analysisMetadataForResponse(array $metadata, ?ApplicationInterface $application = NULL, string $field_id = '', string $attachment_uuid = ''): array|\stdClass {
    // Callback acknowledgements have no staff access context. Do not reveal
    // stored values there, including the other story's result.
    $can_reveal = $application !== NULL && $this->canAccessAttachmentField($application, $field_id);
    $records = AttachmentAnalysisMetadataWriter::isV2($metadata) ? $metadata['stories'] : ['legacy' => $metadata];
    foreach ($records as $name => $record) {
      if (!is_array($record) || empty($record['result']['extracted'])) {
        continue;
      }
      if ($can_reveal) {
        $protection = $this->dataProtection ?? throw new \RuntimeException('Analysis data protection is unavailable.');
        try {
          $record['result'] = $protection->revealAnalysisResult($record['result']);
        }
        catch (\RuntimeException) {
          unset($record['result']['extracted']);
          $record['extractedUnavailable'] = TRUE;
          $this->logUnavailableAnalysisValues($attachment_uuid);
        }
      }
      else {
        unset($record['result']['extracted']);
      }
      if (AttachmentAnalysisMetadataWriter::isV2($metadata)) {
        $metadata['stories'][$name] = $record;
      }
      else {
        $metadata = $record;
      }
    }
    if (($metadata['stories'] ?? NULL) === []) {
      $metadata['stories'] = new \stdClass();
    }
    return $metadata === [] ? new \stdClass() : $metadata;
  }

  /**
   * Logs a failed reveal without values, keys, ciphertext or exception text.
   */
  private function logUnavailableAnalysisValues(string $attachment_uuid): void {
    $this->logger->warning('Attachment analysis values unavailable for @attachment.', [
      '@attachment' => $attachment_uuid,
    ]);
  }

  /**
   * Decodes the service-provided analysis payload.
   *
   * @return array<string, mixed>
   *   Request payload.
   */
  private function decodeAnalysisPayload(Request $request): array {
    $content = trim($request->getContent());
    if ($content === '' || strlen($content) > self::MAX_ANALYSIS_METADATA_BYTES + 4096) {
      throw new BadRequestHttpException('Attachment analysis payload is invalid.');
    }
    $payload = json_decode($content, TRUE);
    if (!is_array($payload) || array_is_list($payload)) {
      throw new BadRequestHttpException('Attachment analysis payload is invalid.');
    }
    return $payload;
  }

  /**
   * Normalizes the stored analysis lifecycle status.
   */
  private function normalizeAnalysisStatus(mixed $status): string {
    if (!is_string($status)) {
      throw new BadRequestHttpException('Attachment analysis status is invalid.');
    }
    $status = trim($status);
    if (!in_array($status, ['not_requested', 'pending', 'complete', 'failed'], TRUE)) {
      throw new BadRequestHttpException('Attachment analysis status is invalid.');
    }
    return $status;
  }

  /**
   * Normalizes metadata, accepting encryption envelopes only from storage.
   *
   * @return array<string, mixed>
   *   Sanitized metadata.
   */
  private function normalizeAnalysisMetadata(mixed $metadata, bool $stored = FALSE): array {
    if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
      throw new BadRequestHttpException('Attachment analysis metadata must be an object.');
    }
    if (AttachmentAnalysisMetadataWriter::isV2($metadata)) {
      return $this->normalizeAnalysisMetadataV2($metadata, $stored);
    }
    $sanitized = [];
    if (isset($metadata['version']) && is_int($metadata['version']) && $metadata['version'] > 0) {
      $sanitized['version'] = min($metadata['version'], 99);
    }
    if (isset($metadata['status']) && in_array($metadata['status'], ['not_requested', 'pending', 'complete', 'failed'], TRUE)) {
      $sanitized['status'] = $metadata['status'];
    }
    $code = $this->analysisMetadataToken($metadata['code'] ?? NULL);
    if ($code !== NULL) {
      $sanitized['code'] = $code;
    }
    if (isset($metadata['requiresHumanReview']) && is_bool($metadata['requiresHumanReview'])) {
      $sanitized['requiresHumanReview'] = $metadata['requiresHumanReview'];
    }
    if (isset($metadata['language']) && in_array($metadata['language'], ['de', 'en'], TRUE)) {
      $sanitized['language'] = $metadata['language'];
    }
    $analysis_run_id = $this->normalizeAnalysisRunId($metadata['analysisRunId'] ?? NULL);
    if ($analysis_run_id !== NULL) {
      $sanitized['analysisRunId'] = $analysis_run_id;
    }
    if (isset($metadata['analysisStartedAt']) && is_int($metadata['analysisStartedAt']) && $metadata['analysisStartedAt'] > 0) {
      $sanitized['analysisStartedAt'] = $metadata['analysisStartedAt'];
    }
    $prompt_version = $this->analysisMetadataToken($metadata['promptVersion'] ?? NULL);
    if ($prompt_version !== NULL) {
      $sanitized['promptVersion'] = $prompt_version;
    }
    foreach ([
      'profile' => $this->normalizeAnalysisProfileMetadata($metadata['profile'] ?? NULL),
      'source' => $this->normalizeAnalysisSourceMetadata($metadata['source'] ?? NULL),
      'worker' => $this->normalizeAnalysisWorkerMetadata($metadata['worker'] ?? NULL),
      'provider' => $this->normalizeAnalysisProviderMetadata($metadata['provider'] ?? NULL),
      'result' => $this->normalizeAnalysisResultMetadata($metadata['result'] ?? NULL, $stored),
    ] as $key => $value) {
      if ($value !== []) {
        $sanitized[$key] = $value;
      }
    }
    return $sanitized;
  }

  /**
   * Normalizes a version 2 record: top level plus one record per story.
   *
   * Each story passes the version 1 allowlist; unknown stories are dropped.
   *
   * @param array<string, mixed> $metadata
   *   Decoded version 2 metadata.
   * @param bool $stored
   *   TRUE accepts encrypted envelopes from storage, never from callbacks.
   *
   * @return array<string, mixed>
   *   Sanitized metadata.
   */
  private function normalizeAnalysisMetadataV2(array $metadata, bool $stored = FALSE): array {
    $sanitized = ['version' => AttachmentAnalysisMetadataWriter::METADATA_VERSION];
    if (isset($metadata['status']) && in_array($metadata['status'], ['not_requested', 'pending', 'complete', 'failed'], TRUE)) {
      $sanitized['status'] = $metadata['status'];
    }
    if (isset($metadata['language']) && in_array($metadata['language'], ['de', 'en'], TRUE)) {
      $sanitized['language'] = $metadata['language'];
    }
    $profile = $this->normalizeAnalysisProfileMetadata($metadata['profile'] ?? NULL);
    if ($profile !== []) {
      $sanitized['profile'] = $profile;
    }
    $stories = [];
    foreach (AttachmentAnalysisMetadataWriter::STORIES as $story) {
      $record = $metadata['stories'][$story] ?? NULL;
      if (!is_array($record) || $record === [] || array_is_list($record)) {
        continue;
      }
      unset($record['version'], $record['stories'], $record['profile']);
      $normalized = $this->normalizeAnalysisMetadata($record, $stored);
      unset($normalized['version'], $normalized['profile']);
      if ($normalized !== []) {
        $stories[$story] = $normalized;
      }
    }
    $sanitized['stories'] = $stories === [] ? new \stdClass() : $stories;
    return $sanitized;
  }

  /**
   * Normalizes trusted, administrator-authored analysis profile metadata.
   *
   * @return array<string, mixed>
   *   Bounded profile metadata.
   */
  private function normalizeAnalysisProfileMetadata(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }
    $profile = [];
    if (isset($value['version']) && is_int($value['version']) && $value['version'] > 0) {
      $profile['version'] = min($value['version'], 99);
    }
    foreach (['egressPolicy', 'modelClass'] as $key) {
      $token = $this->analysisMetadataToken($value[$key] ?? NULL);
      if ($token !== NULL) {
        $profile[$key] = $token;
      }
    }
    return $profile;
  }

  /**
   * Normalizes attachment source metadata.
   *
   * @return array<string, mixed>
   *   Bounded source metadata.
   */
  private function normalizeAnalysisSourceMetadata(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }
    $source = [];
    foreach (['attachmentId', 'fieldId', 'mimeType'] as $key) {
      $token = $this->analysisMetadataToken($value[$key] ?? NULL);
      if ($token !== NULL) {
        $source[$key] = $token;
      }
    }
    if (isset($value['size']) && is_int($value['size']) && $value['size'] >= 0) {
      $source['size'] = min($value['size'], self::MAX_FILE_SIZE);
    }
    // Rendered pages (sovereign PDF chain): what the model saw.
    if (in_array($value['renderedFrom'] ?? NULL, ['derivative', 'original'], TRUE)) {
      $source['renderedFrom'] = $value['renderedFrom'];
    }
    foreach (['pages', 'pageCount'] as $key) {
      if (isset($value[$key]) && is_int($value[$key]) && $value[$key] > 0) {
        $source[$key] = min($value[$key], 9999);
      }
    }
    if (isset($value['truncated']) && is_bool($value['truncated'])) {
      $source['truncated'] = $value['truncated'];
    }
    if (in_array($value['redaction'] ?? NULL, ['redacted', 'unredacted_needs_review', 'none'], TRUE)) {
      $source['redaction'] = $value['redaction'];
    }
    return $source;
  }

  /**
   * Normalizes queue-attempt metadata.
   *
   * @return array<string, int>
   *   Bounded worker metadata.
   */
  private function normalizeAnalysisWorkerMetadata(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }
    $worker = [];
    foreach (['attempt', 'maxAttempts'] as $key) {
      if (isset($value[$key]) && is_int($value[$key]) && $value[$key] >= 0) {
        $worker[$key] = min($value[$key], 99);
      }
    }
    return $worker;
  }

  /**
   * Normalizes provider audit metadata.
   *
   * @return array<string, string>
   *   Bounded provider metadata.
   */
  private function normalizeAnalysisProviderMetadata(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }
    $provider = [];
    foreach (['name', 'model'] as $key) {
      $token = $this->analysisMetadataToken($value[$key] ?? NULL);
      if ($token !== NULL) {
        $provider[$key] = $token;
      }
    }
    // Where the run sent the file, as the operator labels it (for example
    // "Rechenzentrum in Deutschland"): free text, so not a token.
    $location = $this->analysisMetadataLabel($value['location'] ?? NULL);
    if ($location !== NULL) {
      $provider['location'] = $location;
    }
    return $provider;
  }

  /**
   * Normalizes an operator-provided label to one bounded line of text.
   *
   * Control characters become spaces, white space collapses, the length is
   * capped like the Nuxt side caps it. Invalid UTF-8 is dropped.
   */
  private function analysisMetadataLabel(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $label = preg_replace('/[\p{Cc}\s]+/u', ' ', $value);
    if (!is_string($label)) {
      return NULL;
    }
    $label = trim(mb_substr(trim($label), 0, self::MAX_ANALYSIS_LABEL_LENGTH));
    return $label === '' ? NULL : $label;
  }

  /**
   * Normalizes the model-result boundary to controlled fields only.
   *
   * @return array<string, mixed>
   *   Bounded result metadata.
   */
  private function normalizeAnalysisResultMetadata(mixed $value, bool $stored = FALSE): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }
    $result = [];
    if (isset($value['outcome']) && in_array($value['outcome'], ['pass', 'needs_review', 'fail', 'unknown'], TRUE)) {
      $result['outcome'] = $value['outcome'];
    }
    if (isset($value['missingCount']) && is_int($value['missingCount']) && $value['missingCount'] >= 0) {
      $result['missingCount'] = min($value['missingCount'], self::MAX_ANALYSIS_METADATA_ITEMS);
    }
    if (isset($value['confidence']) && (is_int($value['confidence']) || is_float($value['confidence']))) {
      $result['confidence'] = max(0.0, min(1.0, (float) $value['confidence']));
    }
    if (isset($value['missingKeys']) && is_array($value['missingKeys']) && array_is_list($value['missingKeys'])) {
      $missing_keys = [];
      foreach (array_slice($value['missingKeys'], 0, self::MAX_ANALYSIS_METADATA_ITEMS) as $item) {
        $token = $this->analysisMetadataToken($item);
        if ($token !== NULL && !in_array($token, $missing_keys, TRUE)) {
          $missing_keys[] = $token;
        }
      }
      if ($missing_keys !== []) {
        $result['missingKeys'] = $missing_keys;
      }
    }
    if (isset($value['extracted']) && is_array($value['extracted']) && array_is_list($value['extracted'])) {
      $extracted = [];
      foreach (array_slice($value['extracted'], 0, self::MAX_ANALYSIS_METADATA_ITEMS) as $item) {
        if (!is_array($item) || array_is_list($item)) {
          continue;
        }
        $key = $this->analysisMetadataToken($item['key'] ?? NULL);
        $item_value = $item['value'] ?? NULL;
        $encrypted = $stored && $this->dataProtection?->isEncryptedValue($item_value);
        if ($key === NULL || (!$encrypted && !is_bool($item_value) && !is_int($item_value) && !is_float($item_value) && $item_value !== NULL)) {
          continue;
        }
        $normalized = ['key' => $key, 'value' => $item_value];
        $type = $this->analysisMetadataToken($item['type'] ?? NULL);
        if ($type !== NULL && in_array($type, ['boolean', 'number', 'integer'], TRUE)) {
          $normalized['type'] = $type;
        }
        $extracted[] = $normalized;
      }
      if ($extracted !== []) {
        $result['extracted'] = $extracted;
      }
    }
    if (isset($value['notices']) && is_array($value['notices']) && array_is_list($value['notices'])) {
      $notices = array_values(array_unique(array_filter(
        $value['notices'],
        static fn (mixed $notice): bool => in_array($notice, ['pages_truncated', 'unredacted_scan'], TRUE),
      )));
      if ($notices !== []) {
        $result['notices'] = $notices;
      }
    }
    if (isset($value['pagesChecked']) && is_int($value['pagesChecked']) && $value['pagesChecked'] > 0) {
      $result['pagesChecked'] = min($value['pagesChecked'], 99);
    }
    if (($result['outcome'] ?? NULL) === 'pass'
      && (($result['missingCount'] ?? 0) > 0 || ($result['missingKeys'] ?? []) !== [] || ($result['notices'] ?? []) !== [])) {
      $result['outcome'] = 'needs_review';
    }
    return $result;
  }

  /**
   * Returns one bounded machine-readable metadata token.
   */
  private function analysisMetadataToken(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,199}$/', $value) === 1 ? $value : NULL;
  }

  /**
   * Returns TRUE when the current user may access an attachment field.
   */
  public function canAccessAttachmentField(ApplicationInterface $application, string $field_id, ?AccountInterface $account = NULL): bool {
    if (!$this->attachmentFieldIsSensitive($application, $field_id)) {
      return TRUE;
    }

    return ProtectedDataGate::canReadProtectedAttachmentData($account ?? $this->currentUser());
  }

  /**
   * Requires access to both the submitted field and a corrected proof target.
   */
  private function canAccessClaimedAttachment(ApplicationInterface $application, ApplicationAttachmentInterface $attachment, string $fieldId, ?array $correctedFields = NULL): bool {
    if (!$this->canAccessAttachmentField($application, $fieldId)) {
      return FALSE;
    }
    $target = ApplicationProofAssignments::target($correctedFields ?? ApplicationProofAssignments::read($application), $attachment->uuid());
    return $target === NULL || $target === $fieldId || $this->canAccessAttachmentField($application, $target);
  }

  /**
   * Returns TRUE when the current user can resolve a quarantined attachment.
   */
  private function canResolveQuarantine(ApplicationInterface $application): bool {
    return $application->access('update')
      && $this->currentUser()->hasPermission('manage quarantined application attachments');
  }

  /**
   * Decodes the optional quarantine delete payload.
   *
   * @return array<string, mixed>
   *   Request payload.
   */
  private function decodeDeletePayload(Request $request): array {
    $content = trim($request->getContent());
    if ($content === '') {
      return [];
    }
    $payload = json_decode($content, TRUE);
    if (!is_array($payload) || array_is_list($payload)) {
      throw new BadRequestHttpException('Invalid quarantine decision payload.');
    }
    return $payload;
  }

  /**
   * Normalizes an optional quarantine decision reason.
   */
  private function normalizeDeleteReason(mixed $reason): string {
    if ($reason === NULL) {
      return '';
    }
    if (!is_string($reason)) {
      throw new BadRequestHttpException('Invalid quarantine decision reason.');
    }
    $reason = trim($reason);
    $reason = preg_replace('/[\p{C}\s]+/u', ' ', $reason) ?? '';
    $reason = trim($reason);
    if (mb_strlen($reason) > 500) {
      throw new BadRequestHttpException('Quarantine decision reason is too long.');
    }
    return $reason;
  }

  /**
   * Audits every attachment download before a byte leaves (N24).
   *
   * One row per download of any origin (submission, comment, bulk,
   * Nachreichung), no dedupe; without the row nothing is served.
   */
  private function auditDownload(ApplicationInterface $application, ApplicationAttachmentInterface $attachment): void {
    if (!$this->accessLog instanceof ProtectedAccessLog) {
      throw new ServiceUnavailableHttpException(NULL, 'Attachment downloads cannot be audited.');
    }
    try {
      $this->accessLog->recordSubject('application_attachment', (string) $attachment->uuid(), $this->currentUser(), 'attachment_download', $application);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Attachment download could not be audited: @class.', ['@class' => $exception::class]);
      throw new ServiceUnavailableHttpException(NULL, 'Attachment downloads cannot be audited.');
    }
  }

  /**
   * Adds the pre-check blocks of the emitted attachments.
   *
   * One provider call for the whole list. A provider failure keeps the
   * attachments without blocks. The audit sits outside that fallback: a
   * finding is a document-derived assessment, so no block leaves unless its
   * read was recorded first (D2).
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application whose attachments are listed.
   * @param array<int, array<string, mixed>> $data
   *   Attachment rows in list order.
   * @param array<int, int> $rows
   *   Row index per attachment entity id eligible for a block.
   *
   * @return array<int, array<string, mixed>>
   *   The rows, with a "precheck" key where a block was delivered.
   */
  private function withPrechecks(ApplicationInterface $application, array $data, array $rows): array {
    if (!$this->precheck instanceof AttachmentPrecheck || $rows === []) {
      return $data;
    }
    try {
      $blocks = array_intersect_key($this->precheck->forApplication($application, array_keys($rows)), $rows);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Attachment pre-check projection failed: @class.', ['@class' => $exception::class]);
      return $data;
    }
    if ($blocks === [] || !$this->auditPrecheckRead($application)) {
      return $data;
    }
    foreach ($blocks as $id => $block) {
      if ($this->canAccessAttachmentField($application, $block['fieldId'])) {
        $data[$rows[$id]]['precheck'] = $block;
      }
    }
    return $data;
  }

  /**
   * Records the read of pre-check blocks; FALSE when it cannot be recorded.
   *
   * Always written when a block is emitted, whatever the file names; the
   * access log's application dedupe applies (channel attachment_list).
   */
  private function auditPrecheckRead(ApplicationInterface $application): bool {
    if (!$this->accessLog instanceof ProtectedAccessLog) {
      $this->logger->error('Attachment pre-check read cannot be audited.');
      return FALSE;
    }
    try {
      $this->accessLog->record($application, $this->currentUser(), 'attachment_list');
      return TRUE;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Attachment pre-check read could not be audited: @class.', ['@class' => $exception::class]);
      return FALSE;
    }
  }

  /**
   * Returns TRUE when a file field is sensitive or cannot be proven safe.
   */
  private function attachmentFieldIsSensitive(ApplicationInterface $application, string $field_id): bool {
    $fields = $this->applicationSchemaFields($application);
    return !isset($fields[$field_id]) || $this->isSensitiveSchemaField($fields[$field_id]);
  }

  /**
   * Returns the submitted form fields of the viewed case, or no fields.
   *
   * @return array<string, array<string, mixed>>
   *   Form fields keyed by their ids.
   */
  private function applicationSchemaFields(ApplicationInterface $application): array {
    try {
      $schema_entity = PublicSubmission::protectedSchema($application, $this->entityTypeManager());
      if (!$schema_entity instanceof FormSchemaInterface) {
        return [];
      }
      $schema = json_decode((string) $schema_entity->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($schema) ? $this->schemaFields($schema) : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Returns schema fields keyed by field id.
   *
   * @return array<string, array<string, mixed>>
   *   Schema fields.
   */
  private function schemaFields(array $schema): array {
    $fields = [];
    foreach (($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach (($section['fields'] ?? []) as $field) {
        if (is_array($field) && is_string($field['id'] ?? NULL) && $field['id'] !== '') {
          $fields[$field['id']] = $field;
        }
      }
    }
    return $fields;
  }

  /**
   * Returns TRUE when a schema field is marked sensitive.
   */
  private function isSensitiveSchemaField(array $field): bool {
    if (!array_key_exists('sensitive', $field)) {
      return TRUE;
    }

    $sensitive = $field['sensitive'];
    if (is_bool($sensitive)) {
      return $sensitive;
    }
    if (is_string($sensitive) || is_int($sensitive)) {
      $parsed = filter_var($sensitive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      return $parsed ?? TRUE;
    }
    return TRUE;
  }

  /**
   * Sanitizes a client filename.
   */
  private function sanitizeFilename(string $filename, string $extension): string {
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? '';
    $safe = preg_replace('/_+/', '_', $safe) ?? '';
    $safe = trim($safe, '_.');
    if ($safe === '' || $safe === '.' . $extension) {
      $safe = 'attachment_' . time() . '.' . $extension;
    }
    if (strlen($safe) > self::MAX_FILENAME_LENGTH) {
      $pathinfo = pathinfo($safe);
      $basename = substr((string) $pathinfo['filename'], 0, 140);
      $safe = $basename . '.' . $extension;
    }
    return $safe;
  }

  /**
   * Loads a public form schema by UUID.
   */
  private function loadPublicFormByUuid(string $uuid): ?FormSchemaInterface {
    $matches = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    if (count($matches) !== 1) {
      return NULL;
    }
    $form = reset($matches);
    if (!$form instanceof FormSchemaInterface) {
      return NULL;
    }
    return $form->isPublished()
      && $form->getSchemaStatus() === 'published'
      && $form->getPublicationScope() === 'internet'
      ? $form
      : NULL;
  }

  /**
   * Returns the requested file field from the form schema.
   *
   * @return array<string, mixed>|null
   *   File field definition or NULL.
   */
  private function formFileField(FormSchemaInterface $form, string $field_id): ?array {
    try {
      $schema = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
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
        if (is_array($field)
          && ($field['id'] ?? NULL) === $field_id
          && ($field['type'] ?? NULL) === 'file') {
          return $field;
        }
      }
    }
    return NULL;
  }

  /**
   * Resolves an explicit external-analysis opt-in from a trusted upload.
   *
   * The persisted flag is intentionally not inferred from the form schema or
   * from a general application consent. A citizen must opt in for this exact
   * attachment, and only the trusted Nuxt upload proxy may forward that value.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Trusted upload request.
   * @param array<string, mixed> $file_field
   *   The server-loaded file field definition.
   */
  private function trustedExternalAnalysisConsent(Request $request, array $file_field): bool {
    if (!$this->isTrustedServiceRequest($request)
      || $request->request->get('analysisConsent') !== 'true') {
      return FALSE;
    }

    $analysis = $file_field['analysis'] ?? NULL;
    return is_array($analysis)
      && ($analysis['enabled'] ?? FALSE) === TRUE
      && ($analysis['egressPolicy'] ?? NULL) === 'external_allowed';
  }

  /**
   * Resolves the bounded citizen-facing language from the trusted upload proxy.
   */
  private function trustedAnalysisLanguage(Request $request): string {
    if (!$this->isTrustedServiceRequest($request)) {
      return 'de';
    }
    $language = $request->request->get('language');
    return is_string($language) && in_array($language, ['de', 'en'], TRUE)
      ? $language
      : 'de';
  }

  /**
   * Resolves the maximum byte size for one file field.
   *
   * @param array<string, mixed> $field
   *   Schema field definition.
   */
  private function fileFieldMaxBytes(array $field): int {
    $validation = is_array($field['validation'] ?? NULL) ? $field['validation'] : [];
    $candidates = [
      $validation['maxSizeBytes'] ?? NULL,
      $validation['maxFileSizeBytes'] ?? NULL,
      $field['maxSizeBytes'] ?? NULL,
      $field['maxFileSizeBytes'] ?? NULL,
    ];

    foreach ($candidates as $candidate) {
      $value = $this->positiveInteger($candidate);
      if ($value !== NULL) {
        return min($value, self::MAX_FILE_SIZE);
      }
    }

    return self::MAX_FILE_SIZE;
  }

  /**
   * Normalizes a positive integer-like schema value.
   */
  private function positiveInteger(mixed $value): ?int {
    if (is_int($value) && $value > 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1) {
      return (int) $value;
    }
    return NULL;
  }

  /**
   * Returns an error when open uploads exceed the aggregate cap.
   */
  private function pendingUploadQuotaResponse(FormSchemaInterface $form, string $field_id, int $next_size): ?JsonResponse {
    $max_total_size = $this->maxAttachmentBytesPerApplication();
    if ($max_total_size <= 0) {
      return NULL;
    }

    $pending_size = $this->pendingUploadBytes((int) $form->id(), $field_id);
    if ($pending_size + max(0, $next_size) <= $max_total_size) {
      return NULL;
    }

    return $this->errorResponse(
      'attachment.total_size_exceeded',
      'Total attachment size exceeds the application limit.',
      Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
      ['limit' => $max_total_size],
    );
  }

  /**
   * Sums open, unclaimed upload bytes for the same form field.
   */
  private function pendingUploadBytes(int $form_id, string $field_id): int {
    $ids = $this->entityTypeManager->getStorage('application_attachment')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_schema', $form_id)
      ->condition('field_field_id', $field_id)
      ->condition('field_upload_status', 'uploaded')
      ->condition('field_claim_expires', $this->time->getRequestTime(), '>')
      ->execute();

    $total = 0;
    foreach ($this->entityTypeManager->getStorage('application_attachment')->loadMultiple($ids) as $attachment) {
      if ($attachment instanceof ApplicationAttachmentInterface && $attachment->get('field_application')->isEmpty()) {
        $total += max(0, (int) ($attachment->get('field_size')->value ?? 0));
      }
    }
    return $total;
  }

  /**
   * Returns an error when the tenant storage quota would be exceeded.
   */
  private function tenantStorageQuotaResponse(FormSchemaInterface $form, int $next_size): ?JsonResponse {
    $violation = $this->attachmentStorageQuota?->violationForFormUpload($form, $next_size);
    if ($violation === NULL) {
      return NULL;
    }

    return $this->errorResponse(
      'attachment.tenant_storage_quota_exceeded',
      'Tenant attachment storage quota would be exceeded.',
      Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
      $violation,
    );
  }

  /**
   * Acquires the tenant quota lock for one form upload.
   */
  private function acquireTenantQuotaLock(FormSchemaInterface $form): string|false|null {
    $jurisdiction = $this->attachmentStorageQuota?->jurisdictionForForm($form);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $lock_name = 'beantragt_application.attachment_storage_quota.' . (int) $jurisdiction->id();
    return $this->lock->acquire($lock_name, 30.0) ? $lock_name : FALSE;
  }

  /**
   * Releases a tenant quota lock when one was acquired.
   */
  private function releaseTenantQuotaLock(string|false|null $lock_name): void {
    if (is_string($lock_name)) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns the lock shared by public claims and attachment analysis updates.
   */
  private function attachmentClaimLockName(ApplicationAttachmentInterface $attachment): string {
    return AttachmentAnalysisMetadataWriter::claimLockName($attachment);
  }

  /**
   * Returns the aggregate attachment size cap for one application.
   */
  private function maxAttachmentBytesPerApplication(): int {
    $configured = $this->configFactory
      ->get('beantragt_application.settings')
      ->get('attachment_max_total_bytes_per_application');
    $value = $this->nonNegativeInteger($configured);
    return $value ?? self::DEFAULT_MAX_ATTACHMENT_BYTES_PER_APPLICATION;
  }

  /**
   * Normalizes a non-negative integer-like config value.
   */
  private function nonNegativeInteger(mixed $value): ?int {
    if (is_int($value) && $value >= 0) {
      return $value;
    }
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,11})$/', $value) === 1) {
      return (int) $value;
    }
    return NULL;
  }

  /**
   * Returns TRUE when the form only allows authenticated submissions.
   */
  private function formRequiresAuthenticatedSubmission(FormSchemaInterface $form): bool {
    try {
      $schema = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return TRUE;
    }
    if (!is_array($schema)) {
      return TRUE;
    }
    $auth = $schema['auth'] ?? NULL;
    if (!is_array($auth) || array_is_list($auth)) {
      return FALSE;
    }
    $mode = $auth['mode'] ?? NULL;
    return is_string($mode) && $mode === 'required';
  }

  /**
   * Resolves a citizen owner passed by the trusted Nuxt upload proxy.
   */
  private function trustedUploadedOwnerId(Request $request, mixed $owner_uuid): ?int {
    $uuid = $this->normalizeUuid($owner_uuid);
    if ($uuid === NULL || !$this->isTrustedServiceRequest($request)) {
      return NULL;
    }

    $matches = $this->entityTypeManager->getStorage('user')->loadByProperties(['uuid' => $uuid]);
    if (count($matches) !== 1) {
      return NULL;
    }
    $user = reset($matches);
    if (!in_array('authenticated', $user->getRoles(), TRUE)
      || BeantragtRoles::isStaffAccount($user)
      || BeantragtRoles::isServiceAccount($user)) {
      return NULL;
    }
    $owner_id = (int) $user->id();
    return $owner_id > 0 ? $owner_id : NULL;
  }

  /**
   * Applies general flood protection.
   */
  private function floodResponse(Request $request, string $name): ?JsonResponse {
    $identifier = $this->trustedProxyFloodIdentifier($request)
      ?? ($request->getClientIp() ?? 'unknown');
    if (!$this->flood->isAllowed($name, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return $this->errorResponse('rate_limit.exceeded', 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register($name, self::FLOOD_WINDOW, $identifier);
    return NULL;
  }

  /**
   * Applies target-level flood protection.
   */
  private function targetFloodResponse(Request $request, string $form_uuid, string $field_id): ?JsonResponse {
    $target = $this->trustedProxyFloodIdentifier($request) ?? ($request->getClientIp() ?? 'unknown');
    $identifier = hash('sha256', $form_uuid . ':' . $field_id . ':' . $target);
    if (!$this->flood->isAllowed('beantragt_application.attachment_upload_target', self::TARGET_FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return $this->errorResponse('rate_limit.exceeded', 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register('beantragt_application.attachment_upload_target', self::FLOOD_WINDOW, $identifier);
    return NULL;
  }

  /**
   * Returns a Nuxt-proxied flood identifier after service-header validation.
   */
  private function trustedProxyFloodIdentifier(Request $request): ?string {
    if (!$this->isTrustedServiceRequest($request)) {
      return NULL;
    }

    $identifier = (string) $request->headers->get('X-Beantragt-Rate-Limit-Identifier', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $identifier)) {
      return NULL;
    }

    return 'proxy:' . $identifier;
  }

  /**
   * Returns TRUE when the request was signed by the Nuxt service proxy.
   */
  private function isTrustedServiceRequest(Request $request): bool {
    $expected = $this->expectedServiceAuthorizationHeader();
    $provided = (string) $request->headers->get('X-Beantragt-Service-Authorization', '');
    return $expected !== '' && hash_equals($expected, $provided);
  }

  /**
   * Builds the service secret value expected from the Nuxt proxy.
   */
  private function expectedServiceAuthorizationHeader(): string {
    $user = getenv('NUXT_DRUPAL_SERVICE_USER') ?: 'nuxt_service';
    $password = getenv('NUXT_DRUPAL_SERVICE_PASSWORD') ?: '';
    if ($password === '') {
      return '';
    }

    return 'Basic ' . base64_encode($user . ':' . $password);
  }

  /**
   * Resolves the configured service account as owner.
   */
  private function publicSubmissionOwnerId(): ?int {
    $service_user = trim((string) getenv('NUXT_DRUPAL_SERVICE_USER'));
    if ($service_user === '') {
      $this->logger->error('Attachment upload owner is not configured.');
      return NULL;
    }

    $matches = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $service_user]);
    if (count($matches) !== 1) {
      $this->logger->error('Attachment upload owner "@name" is not unique or does not exist.', ['@name' => $service_user]);
      return NULL;
    }
    $user = reset($matches);
    if (!BeantragtRoles::isServiceAccount($user)) {
      $this->logger->error('Attachment upload owner "@name" is not a service account.', ['@name' => $service_user]);
      return NULL;
    }
    $owner_id = (int) $user->id();
    return $owner_id > 0 ? $owner_id : NULL;
  }

  /**
   * Builds a random one-time claim token.
   */
  private function claimToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Normalizes public UUID inputs.
   */
  private function normalizeUuid(mixed $uuid): ?string {
    if (!is_string($uuid)) {
      return NULL;
    }
    $uuid = strtolower(trim($uuid));
    return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : NULL;
  }

  /**
   * Normalizes form field ids.
   */
  private function normalizeFieldId(mixed $field_id): ?string {
    if (!is_string($field_id)) {
      return NULL;
    }
    $field_id = trim($field_id);
    return preg_match(self::FIELD_ID_PATTERN, $field_id) === 1 ? $field_id : NULL;
  }

  /**
   * Normalizes a server-created analysis generation identifier.
   */
  private function normalizeAnalysisRunId(mixed $analysis_run_id): ?string {
    if (!is_string($analysis_run_id)) {
      return NULL;
    }
    $analysis_run_id = strtolower(trim($analysis_run_id));
    return preg_match(self::ANALYSIS_RUN_ID_PATTERN, $analysis_run_id) === 1
      ? $analysis_run_id
      : NULL;
  }

  /**
   * Builds a v1 success response.
   */
  private function successResponse(array $data, int $status): JsonResponse {
    return $this->noStore(new JsonResponse([
      'data' => $data,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application_attachment',
      ],
    ], $status));
  }

  /**
   * Builds a v1 error response.
   */
  private function errorResponse(string $code, string $message, int $status, array $details = []): JsonResponse {
    $error = [
      'code' => $code,
      'message' => $message,
    ];
    if ($details !== []) {
      $error['details'] = $details;
    }
    return $this->noStore(new JsonResponse([
      'error' => $error,
      'meta' => [
        'apiVersion' => self::API_VERSION,
      ],
    ], $status));
  }

  /**
   * Applies private no-store headers.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
