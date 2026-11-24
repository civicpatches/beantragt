<?php

declare(strict_types=1);

namespace Drupal\beantragt_comment\Controller;

use Drupal\beantragt_application\Access\ApplicationCommentStaffScope;
use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanService;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\beantragt_comment\Entity\ApplicationCommentInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * REST API controller for application comments.
 *
 * Provides list, create, and upload endpoints for the comment system.
 * No PII (author name/email) is exposed in responses.
 */
class CommentApiController extends ControllerBase {

  /**
   * Public API version emitted by v1 comment endpoints.
   */
  protected const API_VERSION = '1.0';

  /**
   * Maximum allowed body length.
   */
  protected const MAX_BODY_LENGTH = 5000;

  /**
   * Default page size for v1 comment lists.
   */
  protected const DEFAULT_LIMIT = 20;

  /**
   * Maximum page size for v1 comment lists.
   */
  protected const MAX_LIMIT = 100;

  /**
   * Maximum offset accepted by v1 comment lists.
   */
  protected const MAX_OFFSET = 10000;

  /**
   * Pseudo field id used for comment attachment claim entities.
   */
  protected const COMMENT_ATTACHMENT_FIELD_ID = 'comment_attachment';

  /**
   * Comment attachment claim token lifetime in seconds.
   */
  protected const CLAIM_TTL = 21600;

  /**
   * Flood window in seconds for comment mutations.
   */
  protected const FLOOD_WINDOW = 60;

  /**
   * General comment create limit per citizen/IP.
   */
  protected const COMMENT_CREATE_FLOOD_LIMIT = 20;

  /**
   * Application-scoped comment create limit per citizen/IP.
   */
  protected const COMMENT_CREATE_TARGET_FLOOD_LIMIT = 10;

  /**
   * General comment attachment upload limit per citizen/IP.
   */
  protected const COMMENT_ATTACHMENT_UPLOAD_FLOOD_LIMIT = 10;

  /**
   * Application-scoped comment attachment upload limit per citizen/IP.
   */
  protected const COMMENT_ATTACHMENT_UPLOAD_TARGET_FLOOD_LIMIT = 5;

  /**
   * Base64url claim token shape.
   */
  protected const CLAIM_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

  /**
   * UUID validation pattern for public inputs.
   */
  protected const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

  /**
   * Maximum attachments per comment.
   */
  protected const MAX_ATTACHMENTS = 5;

  /**
   * Allowed file extensions for uploads.
   */
  protected const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

  /**
   * Allowed MIME types by extension.
   */
  protected const ALLOWED_MIMES = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
  ];

  /**
   * Maximum file size in bytes (10 MB).
   */
  protected const MAX_FILE_SIZE = 10485760;

  /**
   * Maximum filename length persisted for comment uploads.
   */
  protected const MAX_FILENAME_LENGTH = 180;

  /**
   * Application owner for a mutation lock held by this controller.
   */
  private ?ApplicationInterface $applicationMutationLockOwner = NULL;

  /**
   * Whether the last status lookup asked the governing workflow.
   *
   * Set by resolveSemanticStatusId(); a miss after such a lookup is a normal
   * outcome, any other miss is logged.
   */
  private bool $requestInfoWorkflowAsked = FALSE;

  /**
   * Constructs a CommentApiController.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository service.
   * @param \Drupal\beantragt_application\Service\TenantScopedEntityQuery $tenantScopedEntityQuery
   *   The tenant-scoped entity query service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator|null $csrfToken
   *   The CSRF token generator.
   * @param \Drupal\Core\Lock\LockBackendInterface|null $lock
   *   The lock backend.
   * @param \Drupal\Component\Datetime\TimeInterface|null $time
   *   The time service.
   * @param \Drupal\Core\Flood\FloodInterface|null $flood
   *   The flood service.
   * @param \Drupal\Core\Database\Connection|null $database
   *   The database connection.
   * @param \Drupal\beantragt_application\Service\AttachmentScanServiceInterface|null $attachmentScanner
   *   The attachment scanner.
   * @param \Drupal\beantragt_application\Service\AttachmentStorageQuota|null $attachmentStorageQuota
   *   The tenant attachment storage quota service.
   * @param \Drupal\beantragt_application\Service\ApplicationDependentMutationLock|null $applicationMutationLock
   *   The shared application mutation lock service.
   * @param \Drupal\beantragt_workflow\Service\SemanticStatusChanger|null $semanticStatuses
   *   The semantic status changer, which knows the governing workflow.
   * @param \Drupal\beantragt_application\Service\ConfirmedApplicationMailbox|null $confirmedMailbox
   *   The confirmed anonymous application ownership service.
   */
  public function __construct(
    protected readonly LoggerInterface $logger,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly FileRepositoryInterface $fileRepository,
    protected readonly TenantScopedEntityQuery $tenantScopedEntityQuery,
    protected readonly ?CsrfTokenGenerator $csrfToken = NULL,
    protected readonly ?LockBackendInterface $lock = NULL,
    protected readonly ?TimeInterface $time = NULL,
    protected readonly ?FloodInterface $flood = NULL,
    protected readonly ?Connection $database = NULL,
    protected readonly ?AttachmentScanServiceInterface $attachmentScanner = NULL,
    protected readonly ?AttachmentStorageQuota $attachmentStorageQuota = NULL,
    protected readonly ?ApplicationDependentMutationLock $applicationMutationLock = NULL,
    protected readonly ?SemanticStatusChanger $semanticStatuses = NULL,
    protected readonly ?ConfirmedApplicationMailbox $confirmedMailbox = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('logger.channel.beantragt_comment'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('beantragt_application.tenant_scoped_entity_query'),
      $container->get('csrf_token'),
      $container->get('lock'),
      $container->get('datetime.time'),
      $container->get('flood'),
      $container->get('database'),
      $container->get('beantragt_application.attachment_scanner'),
      $container->get('beantragt_application.attachment_storage_quota'),
      $container->get('beantragt_application.dependent_mutation_lock'),
      $container->get('beantragt_workflow.semantic_status_changer'),
      $container->get('beantragt_application.confirmed_mailbox'),
    );
  }

  /**
   * Lists all comments for an application.
   *
   * @param string $application_id
   *   The application entity ID or UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON array of comments sorted by created ASC.
   */
  public function list(string $application_id): JsonResponse {
    $application = $this->loadApplication($application_id);
    if (!$application instanceof ApplicationInterface) {
      return new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      );
    }

    // Use the same response as a missing application to avoid disclosing it.
    if (!$this->canAccessApplicationComments($application)) {
      return new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      );
    }

    $comment_storage = $this->entityTypeManager()->getStorage('application_comment');
    $application_entity_id = (int) $application->id();
    $ids = $comment_storage->getQuery()
      // Application-level access was checked above and also supports
      // applicant-email ownership for service-account-created applications.
      ->accessCheck(FALSE)
      ->condition('field_application', $application_entity_id)
      ->condition('status', 1)
      ->sort('created', 'ASC')
      ->execute();

    $comments = [];
    if (!empty($ids)) {
      $file_storage = $this->entityTypeManager()->getStorage('file');

      $can_view_internal = $this->canSeeInternalComments($application);

      /** @var \Drupal\beantragt_comment\Entity\ApplicationCommentInterface $comment */
      foreach ($comment_storage->loadMultiple($ids) as $comment) {
        if (!$this->commentVisibleToCurrentUser($comment, $can_view_internal)) {
          continue;
        }

        $attachments = [];
        if (!$comment->get('field_attachments')->isEmpty()) {
          foreach ($comment->get('field_attachments') as $item) {
            $file = $file_storage->load($item->target_id);
            if ($file) {
              $attachments[] = [
                'id' => (string) $file->uuid(),
                'filename' => $file->getFilename(),
                'url' => Url::fromRoute('beantragt_comment.attachment_download', [
                  'application_id' => $application_id,
                  'file_uuid' => (string) $file->uuid(),
                ])->toString(),
                'size' => (int) $file->getSize(),
              ];
            }
          }
        }

        $comments[] = [
          'id' => (int) $comment->id(),
          // Additive internal projection; v1 already uses the public UUID.
          'uuid' => (string) $comment->uuid(),
          'body' => $comment->getBody() ?? '',
          'authorRole' => $comment->getAuthorRole(),
          'visibility' => $comment->getVisibility(),
          'createdAt' => $this->formatTimestamp($comment->getCreatedTime()),
          'attachments' => $attachments,
        ];
      }
    }

    $response = new JsonResponse(['data' => $comments]);
    $response->setPrivate();
    $response->setMaxAge(0);

    return $response;
  }

  /**
   * Lists comments for an application in the public v1 shape.
   *
   * @param string $application_uuid
   *   The application UUID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Public v1 comment list response.
   */
  public function listV1(string $application_uuid, Request $request): JsonResponse {
    $auth_error = $this->authorizeApiV1('view application_comment entities');
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    $pagination = $this->parsePagination($request);
    if ($pagination instanceof JsonResponse) {
      return $pagination;
    }

    $application = $this->loadScopedApplicationV1($application_uuid, $request);
    if ($application instanceof JsonResponse) {
      return $application;
    }

    if (!$this->canAccessApplicationComments($application)) {
      return $this->applicationNotFoundV1();
    }

    $comment_storage = $this->entityTypeManager()->getStorage('application_comment');
    $ids = $comment_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', (int) $application->id())
      ->condition('status', 1)
      ->condition('field_visibility', 'public')
      ->sort('created', 'ASC')
      ->range($pagination['offset'], $pagination['limit'] + 1)
      ->execute();

    $comments = [];
    foreach ($comment_storage->loadMultiple($ids) as $comment) {
      if ($comment instanceof ApplicationCommentInterface) {
        if ($comment->isInternal()) {
          continue;
        }
        $comments[] = $this->normalizeCommentV1($comment);
      }
    }

    $has_more = count($comments) > $pagination['limit'];
    $comments = array_slice($comments, 0, $pagination['limit']);

    return $this->noStoreResponse([
      'data' => $comments,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'applicationComments',
        'count' => count($comments),
        'page' => $this->paginationMeta($pagination['limit'], $pagination['offset'], $has_more),
      ],
    ]);
  }

  /**
   * Creates a new comment on an application.
   *
   * @param string $application_id
   *   The application entity ID or UUID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The created comment or an error response.
   */
  public function add(string $application_id, Request $request): JsonResponse {
    $application = $this->loadApplication($application_id);
    if (!$application instanceof ApplicationInterface) {
      return new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      );
    }

    // Use the same response as a missing application to avoid disclosing it.
    if (!$this->canAccessApplicationComments($application)) {
      return new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      );
    }

    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return new JsonResponse(
        ['error' => 'Invalid JSON body.'],
        Response::HTTP_BAD_REQUEST,
      );
    }

    $body = $data['body'] ?? '';
    if (!is_string($body)) {
      return new JsonResponse(
        ['error' => 'The body field is required.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $body = trim(strip_tags($body));
    if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
      return new JsonResponse(
        ['error' => 'The body must not exceed ' . self::MAX_BODY_LENGTH . ' characters.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $author_role = $this->hasStaffCommentAccess($application) ? 'staff' : 'citizen';

    $visibility = $this->validatedCommentVisibility($data['visibility'] ?? NULL, $author_role);
    if ($visibility instanceof JsonResponse) {
      return $visibility;
    }

    $attachment_ids = $data['attachment_ids'] ?? [];
    if (!is_array($attachment_ids)) {
      $attachment_ids = [];
    }
    if (count($attachment_ids) > self::MAX_ATTACHMENTS) {
      return new JsonResponse(
        ['error' => 'Maximum ' . self::MAX_ATTACHMENTS . ' attachments allowed.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $comment_storage = $this->entityTypeManager()->getStorage('application_comment');
    $application_entity_id = (int) $application->id();
    $comment = $comment_storage->create([
      'field_application' => $application_entity_id,
      'field_body' => $body,
      'field_author_role' => $author_role,
      'field_visibility' => $visibility,
      'uid' => $this->currentUser()->id(),
      'status' => 1,
    ]);
    if (!$comment instanceof ApplicationCommentInterface) {
      $this->logger->error('Application comment storage returned an unexpected entity type.');
      return new JsonResponse(
        ['error' => 'Failed to save comment.'],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }

    $tenant_quota_lock_name = NULL;
    $legacy_claim_locks = [];
    $legacy_claim_reservations = [];
    $valid_files = [];
    if (!empty($attachment_ids)) {
      $file_storage = $this->entityTypeManager()->getStorage('file');
      $valid_fids = [];
      $valid_attachment_bytes = 0;
      foreach ($attachment_ids as $fid) {
        $file = $file_storage->load($fid);
        if ($file instanceof FileInterface
          && (int) $file->getOwnerId() === (int) $this->currentUser()->id()
          && $this->legacyCommentUploadMatchesApplication($file, $application_entity_id)) {
          $scan_error = $this->legacyCommentFileScanError($file);
          if ($scan_error instanceof JsonResponse) {
            return $scan_error;
          }
          $valid_fids[] = ['target_id' => (int) $file->id()];
          $valid_files[] = $file;
          $valid_attachment_bytes += max(0, (int) $file->getSize());
        }
      }
      if (!empty($valid_fids)) {
        $legacy_claim_reservations = $this->legacyCommentAttachmentReservations($application, $valid_files);
        $legacy_claim_locks = $this->acquireCommentAttachmentLocks($legacy_claim_reservations);
        if ($legacy_claim_locks === NULL) {
          return new JsonResponse(
            ['error' => 'Attachment claim is currently being processed.'],
            Response::HTTP_CONFLICT,
          );
        }
        $revalidated_claims = $this->revalidateLegacyCommentAttachmentReservations(
          $application,
          $valid_files,
          $legacy_claim_reservations,
        );
        if ($revalidated_claims instanceof JsonResponse) {
          $this->releaseCommentAttachmentLocks($legacy_claim_locks);
          return $revalidated_claims;
        }
        $valid_files = $revalidated_claims['files'];
        $legacy_claim_reservations = $revalidated_claims['attachments'];
        $valid_fids = array_values(array_map(
          static fn(FileInterface $file): array => ['target_id' => (int) $file->id()],
          $valid_files,
        ));
        $valid_attachment_bytes = array_sum(array_map(
          static fn(FileInterface $file): int => max(0, (int) $file->getSize()),
          $valid_files,
        ));
        $tenant_quota_lock_name = $this->acquireTenantQuotaLockForApplication($application);
        if ($tenant_quota_lock_name === FALSE) {
          $this->releaseCommentAttachmentLocks($legacy_claim_locks);
          return new JsonResponse(
            ['error' => 'Tenant attachment storage quota is currently being checked.'],
            Response::HTTP_CONFLICT,
          );
        }
        $tenant_quota_response = $this->tenantStorageQuotaResponse($application, $valid_attachment_bytes);
        if ($tenant_quota_response instanceof JsonResponse) {
          $this->releaseTenantQuotaLock($tenant_quota_lock_name);
          $this->releaseCommentAttachmentLocks($legacy_claim_locks);
          return $tenant_quota_response;
        }
        $comment->set('field_attachments', $valid_fids);
      }
    }

    // Invalid or foreign uploads cannot make an otherwise empty message valid.
    if ($body === '' && empty($valid_files)) {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseCommentAttachmentLocks($legacy_claim_locks);
      return new JsonResponse(
        ['error' => 'Please write a message or add an attachment.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $application_lock_name = $this->acquireApplicationMutationLock($application);
    if ($application_lock_name === FALSE) {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseCommentAttachmentLocks($legacy_claim_locks);
      return new JsonResponse(
        ['error' => 'The application is currently being deleted or delivered.'],
        Response::HTTP_CONFLICT,
      );
    }

    try {
      foreach ($valid_files as $file) {
        // Mark file as permanent only after quota enforcement passes.
        $file->setPermanent();
        $file->save();
        $this->claimLegacyCommentAttachmentReservation($application, $file, $legacy_claim_reservations);
      }
      $comment->save();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save application comment: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(
        ['error' => 'Failed to save comment.'],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }
    finally {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseCommentAttachmentLocks($legacy_claim_locks);
      $this->releaseApplicationMutationLock($application_lock_name);
    }

    // Auto-set application status to the organisation's semantically flagged
    // request-info status when staff writes an external citizen message.
    $status_changed = FALSE;
    if ($author_role === 'staff' && $visibility === 'public') {
      $status_changed = $this->markApplicationRequestInfo($application);
    }

    return new JsonResponse(
      [
        'data' => [
          'id' => (int) $comment->id(),
          // Additive internal projection; v1 already uses the public UUID.
          'uuid' => (string) $comment->uuid(),
          'body' => $comment->getBody() ?? '',
          'authorRole' => $comment->getAuthorRole(),
          'visibility' => $comment->getVisibility(),
          'createdAt' => $this->formatTimestamp($comment->getCreatedTime()),
          'attachments' => [],
          'statusChanged' => $status_changed,
        ],
      ],
      Response::HTTP_CREATED,
    );
  }

  /**
   * Creates a text comment for an application in the public v1 shape.
   *
   * @param string $application_uuid
   *   The application UUID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Public v1 created comment response.
   */
  public function addV1(string $application_uuid, Request $request): JsonResponse {
    $auth_error = $this->authorizeApiV1('create application_comment entities');
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    if (!$this->validateCsrfToken($request)) {
      return $this->apiV1Error(
        'csrf.invalid',
        'A valid X-CSRF-Token header is required for comment mutations.',
        Response::HTTP_FORBIDDEN,
      );
    }

    $application = $this->loadScopedApplicationV1($application_uuid, $request);
    if ($application instanceof JsonResponse) {
      return $application;
    }

    if (!$this->canAccessApplicationComments($application)) {
      return $this->applicationNotFoundV1();
    }

    $flood_response = $this->commentFloodResponse($request, $application, 'create');
    if ($flood_response instanceof JsonResponse) {
      return $flood_response;
    }

    $parsed = $this->parseCommentCreateV1($request);
    if ($parsed instanceof JsonResponse) {
      return $parsed;
    }

    $claim_result = $this->validateCommentAttachmentClaims($parsed['attachments'], $application);
    if ($claim_result instanceof JsonResponse) {
      return $claim_result;
    }

    $locks = $this->acquireCommentAttachmentLocks($claim_result['entities']);
    if ($locks === NULL) {
      return $this->apiV1Error(
        'attachment.claim_locked',
        'Attachment claim is currently being processed.',
        Response::HTTP_CONFLICT,
      );
    }
    $tenant_quota_lock_name = $this->acquireTenantQuotaLock($application, $claim_result['entities']);
    if ($tenant_quota_lock_name === FALSE) {
      $this->releaseCommentAttachmentLocks($locks);
      return $this->apiV1Error(
        'attachment.tenant_storage_quota_locked',
        'Tenant attachment storage quota is currently being checked.',
        Response::HTTP_CONFLICT,
      );
    }

    $this->resetCommentAttachmentCache($claim_result['entities']);
    $claim_result = $this->validateCommentAttachmentClaims($parsed['attachments'], $application);
    if ($claim_result instanceof JsonResponse) {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseCommentAttachmentLocks($locks);
      return $claim_result;
    }
    if ($claim_result['entities'] !== []) {
      $tenant_quota_response = $this->tenantStorageQuotaResponse($application, 0);
      if ($tenant_quota_response instanceof JsonResponse) {
        $this->releaseTenantQuotaLock($tenant_quota_lock_name);
        $this->releaseCommentAttachmentLocks($locks);
        return $tenant_quota_response;
      }
    }

    $application_lock_name = $this->acquireApplicationMutationLock($application);
    if ($application_lock_name === FALSE) {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseCommentAttachmentLocks($locks);
      return $this->apiV1Error(
        'comment.application_locked',
        'The application is currently being deleted or delivered.',
        Response::HTTP_CONFLICT,
      );
    }

    $comment_storage = $this->entityTypeManager()->getStorage('application_comment');
    $create_values = [
      'field_application' => (int) $application->id(),
      'field_body' => $parsed['body'],
      'field_author_role' => $this->authorRoleForAccount($this->currentUser()),
      'field_visibility' => 'public',
      'uid' => $this->currentUser()->id(),
      'status' => 1,
    ];
    if ($claim_result['files'] !== []) {
      $create_values['field_attachments'] = array_values(
        array_map(
          static fn(FileInterface $file): array => ['target_id' => (int) $file->id()],
          $claim_result['files'],
        ),
      );
    }
    $comment = $comment_storage->create($create_values);

    $transaction = $this->database instanceof Connection ? $this->database->startTransaction() : NULL;
    try {
      $comment->save();
      $this->claimCommentAttachments($claim_result['entities'], $application);
      unset($transaction);
    }
    catch (\Exception $e) {
      if ($transaction !== NULL) {
        $transaction->rollBack();
      }
      $this->logger->error('Failed to save public v1 application comment: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $this->apiV1Error(
        'comment.save_failed',
        'Failed to save comment.',
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }
    finally {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseCommentAttachmentLocks($locks);
      $this->releaseApplicationMutationLock($application_lock_name);
    }

    return $this->noStoreResponse(
      [
        'data' => $comment instanceof ApplicationCommentInterface
          ? $this->normalizeCommentV1($comment)
          : [
            'type' => 'application_comment',
            'id' => (string) $comment->uuid(),
            'body' => $parsed['body'],
            'authorRole' => $this->authorRoleForAccount($this->currentUser()),
            'visibility' => 'public',
            'createdAt' => '',
            'attachments' => [],
          ],
        'meta' => [
          'apiVersion' => self::API_VERSION,
          'resource' => 'applicationComment',
        ],
      ],
    )->setStatusCode(Response::HTTP_CREATED);
  }

  /**
   * Uploads a comment attachment in the public v1 shape.
   */
  public function uploadV1(string $application_uuid, Request $request): JsonResponse {
    $auth_error = $this->authorizeApiV1('create application_comment entities');
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    if (!$this->validateCsrfToken($request)) {
      return $this->apiV1Error(
        'csrf.invalid',
        'A valid X-CSRF-Token header is required for comment attachment uploads.',
        Response::HTTP_FORBIDDEN,
      );
    }

    $application = $this->loadScopedApplicationV1($application_uuid, $request);
    if ($application instanceof JsonResponse) {
      return $application;
    }

    if (!$this->canAccessApplicationComments($application)) {
      return $this->applicationNotFoundV1();
    }

    $flood_response = $this->commentFloodResponse($request, $application, 'attachment_upload');
    if ($flood_response instanceof JsonResponse) {
      return $flood_response;
    }

    $schema_id = $this->applicationSchemaId($application);
    if ($schema_id === NULL) {
      return $this->apiV1Error(
        'attachment.invalid_context',
        'Application attachment context is invalid.',
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $uploaded_file = $request->files->get('file');
    if (!$uploaded_file instanceof UploadedFile || !$uploaded_file->isValid()) {
      return $this->apiV1Error(
        'attachment.file_missing',
        'No valid file was uploaded.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $validation_error = $this->validateCommentUploadFile($uploaded_file);
    if ($validation_error instanceof JsonResponse) {
      return $validation_error;
    }
    $application_lock_name = $this->acquireApplicationMutationLock($application);
    if ($application_lock_name === FALSE) {
      return $this->apiV1Error(
        'attachment.application_locked',
        'The application is currently being deleted or delivered.',
        Response::HTTP_CONFLICT,
      );
    }

    $tenant_quota_lock_name = $this->acquireTenantQuotaLockForApplication($application);
    if ($tenant_quota_lock_name === FALSE) {
      $this->releaseApplicationMutationLock($application_lock_name);
      return $this->apiV1Error(
        'attachment.tenant_storage_quota_locked',
        'Tenant attachment storage quota is currently being checked.',
        Response::HTTP_CONFLICT,
      );
    }

    try {
      $tenant_quota_response = $this->tenantStorageQuotaResponse($application, (int) $uploaded_file->getSize());
      if ($tenant_quota_response instanceof JsonResponse) {
        return $tenant_quota_response;
      }

      $extension = strtolower($uploaded_file->getClientOriginalExtension());
      $actual_mime = $this->detectUploadedMime($uploaded_file) ?: (self::ALLOWED_MIMES[$extension] ?? 'application/octet-stream');
      $safe_name = $this->sanitizeCommentFilename($uploaded_file->getClientOriginalName(), $extension);
      $directory = 'private://application_comments/' . (int) $application->id() . '/comment-attachments';
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $this->logger->error('Could not prepare comment attachment upload directory for application @application.', [
          '@application' => $application->id(),
        ]);
        return $this->apiV1Error(
          'attachment.save_failed',
          'Attachment could not be saved.',
          Response::HTTP_INTERNAL_SERVER_ERROR,
        );
      }

      $file_data = file_get_contents((string) $uploaded_file->getRealPath());
      if ($file_data === FALSE) {
        throw new \RuntimeException('Could not read uploaded file.');
      }
      $file = $this->fileRepository->writeData(
        $file_data,
        $directory . '/' . $safe_name,
        FileExists::Rename,
      );
      $file->setTemporary();
      $file->setOwnerId((int) $this->currentUser()->id());
      $file->save();

      $scan_result = $this->scanAttachmentBytes($file_data, $actual_mime, $uploaded_file->getClientOriginalName());
      if ($scan_result->isQuarantined()) {
        $attachment = $this->entityTypeManager()->getStorage('application_attachment')->create([
          'field_schema' => ['target_id' => $schema_id],
          'field_application' => ['target_id' => (int) $application->id()],
          'field_field_id' => self::COMMENT_ATTACHMENT_FIELD_ID,
          'field_file' => ['target_id' => (int) $file->id()],
          'field_claim_token_hash' => NULL,
          'field_claim_expires' => $this->requestTime(),
          'field_upload_status' => 'uploaded',
          'field_scan_status' => AttachmentScanResult::STATUS_QUARANTINED,
          'field_scan_message' => $scan_result->message(),
          'field_analysis_status' => 'not_requested',
          'field_analysis_metadata' => json_encode([
            'context' => 'application_comment',
            'applicationUuid' => (string) $application->uuid(),
          ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
          'field_original_filename' => $file->getFilename(),
          'field_mime_type' => $actual_mime,
          'field_size' => (int) $file->getSize(),
          'uid' => (int) $this->currentUser()->id(),
        ]);
        $attachment->save();

        return $this->apiV1Error(
          'attachment.quarantined',
          'Attachment was quarantined by malware scanning.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
          [
            'attachmentId' => (string) $attachment->uuid(),
            'scanStatus' => AttachmentScanResult::STATUS_QUARANTINED,
          ],
        );
      }

      $claim_token = $this->claimToken();
      $expires_at = $this->requestTime() + self::CLAIM_TTL;
      $attachment = $this->entityTypeManager()->getStorage('application_attachment')->create([
        'field_schema' => ['target_id' => $schema_id],
        'field_application' => ['target_id' => (int) $application->id()],
        'field_field_id' => self::COMMENT_ATTACHMENT_FIELD_ID,
        'field_file' => ['target_id' => (int) $file->id()],
        'field_claim_token_hash' => hash('sha256', $claim_token),
        'field_claim_expires' => $expires_at,
        'field_upload_status' => 'uploaded',
        'field_scan_status' => AttachmentScanResult::STATUS_CLEAN,
        'field_scan_message' => NULL,
        'field_analysis_status' => 'not_requested',
        'field_analysis_metadata' => json_encode([
          'context' => 'application_comment',
          'applicationUuid' => (string) $application->uuid(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'field_original_filename' => $file->getFilename(),
        'field_mime_type' => $actual_mime,
        'field_size' => (int) $file->getSize(),
        'uid' => (int) $this->currentUser()->id(),
      ]);
      $attachment->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Comment attachment upload failed: @message', ['@message' => $e->getMessage()]);
      return $this->apiV1Error(
        'attachment.save_failed',
        'Attachment could not be saved.',
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }
    finally {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseApplicationMutationLock($application_lock_name);
    }

    return $this->noStoreResponse([
      'data' => [
        'id' => (string) $attachment->uuid(),
        'type' => 'comment_attachment',
        'applicationId' => (string) $application->uuid(),
        'claimToken' => $claim_token,
        'filename' => $file->getFilename(),
        'mimeType' => $actual_mime,
        'size' => (int) $file->getSize(),
        'scanStatus' => AttachmentScanResult::STATUS_CLEAN,
        'expiresAt' => gmdate('c', $expires_at),
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'commentAttachment',
      ],
    ])->setStatusCode(Response::HTTP_CREATED);
  }

  /**
   * Uploads a file attachment for a comment.
   *
   * @param string $application_id
   *   The application entity ID or UUID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request with multipart file upload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The created file entity ID or an error response.
   */
  public function upload(string $application_id, Request $request): JsonResponse {
    $application = $this->loadApplication($application_id);
    if (!$application instanceof ApplicationInterface) {
      return new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      );
    }

    // Use the same response as a missing application to avoid disclosing it.
    if (!$this->canAccessApplicationComments($application)) {
      return new JsonResponse(
        ['error' => 'Application not found.'],
        Response::HTTP_NOT_FOUND,
      );
    }

    $uploaded_file = $request->files->get('file');
    if (!$uploaded_file || !$uploaded_file->isValid()) {
      return new JsonResponse(
        ['error' => 'No valid file uploaded.'],
        Response::HTTP_BAD_REQUEST,
      );
    }

    if ($uploaded_file->getSize() > self::MAX_FILE_SIZE) {
      return new JsonResponse(
        ['error' => 'File exceeds maximum size of 10 MB.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $extension = strtolower($uploaded_file->getClientOriginalExtension());
    if (!in_array($extension, self::ALLOWED_EXTENSIONS, TRUE)) {
      return new JsonResponse(
        ['error' => 'Only PDF, JPG, JPEG, and PNG files are allowed.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    // Validate actual MIME type via magic bytes (prevents extension spoofing).
    $actual_mime = $this->detectUploadedMime($uploaded_file);
    $expected_mime = self::ALLOWED_MIMES[$extension];
    if ($actual_mime !== $expected_mime) {
      return new JsonResponse(
        ['error' => 'File content does not match extension.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $file_data = file_get_contents((string) $uploaded_file->getRealPath());
    if ($file_data === FALSE) {
      return new JsonResponse(
        ['error' => 'Failed to read uploaded file.'],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }
    $scan_result = $this->scanAttachmentBytes($file_data, $actual_mime, $uploaded_file->getClientOriginalName());
    if ($scan_result->isQuarantined()) {
      return new JsonResponse(
        ['error' => 'Attachment was quarantined by malware scanning.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $application_lock_name = $this->acquireApplicationMutationLock($application);
    if ($application_lock_name === FALSE) {
      return new JsonResponse(
        ['error' => 'The application is currently being deleted or delivered.'],
        Response::HTTP_CONFLICT,
      );
    }

    $tenant_quota_lock_name = $this->acquireTenantQuotaLockForApplication($application);
    if ($tenant_quota_lock_name === FALSE) {
      $this->releaseApplicationMutationLock($application_lock_name);
      return new JsonResponse(
        ['error' => 'Tenant attachment storage quota is currently being checked.'],
        Response::HTTP_CONFLICT,
      );
    }

    try {
      $tenant_quota_response = $this->tenantStorageQuotaResponse($application, (int) $uploaded_file->getSize());
      if ($tenant_quota_response instanceof JsonResponse) {
        return $tenant_quota_response;
      }

      $original_name = $uploaded_file->getClientOriginalName();
      $sanitized_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
      $sanitized_name = preg_replace('/_+/', '_', $sanitized_name);
      $sanitized_name = trim($sanitized_name, '_.');
      if (empty($sanitized_name) || $sanitized_name === '.' . $extension) {
        $sanitized_name = 'attachment_' . time() . '.' . $extension;
      }

      $application_entity_id = (int) $application->id();
      $directory = 'private://application_comments/' . $application_entity_id;
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $file = $this->fileRepository->writeData(
        $file_data,
        $directory . '/' . $sanitized_name,
        FileExists::Rename,
      );

      // Set file as temporary until linked to a comment.
      $file->setTemporary();
      $file->setOwnerId((int) $this->currentUser()->id());
      $file->save();
      $this->reserveLegacyCommentAttachment($application, $file, $actual_mime);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save uploaded file: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse(
        ['error' => 'Failed to save file.'],
        Response::HTTP_INTERNAL_SERVER_ERROR,
      );
    }
    finally {
      $this->releaseTenantQuotaLock($tenant_quota_lock_name);
      $this->releaseApplicationMutationLock($application_lock_name);
    }

    return new JsonResponse(
      [
        'data' => [
          'file_id' => (int) $file->id(),
          'filename' => $file->getFilename(),
        ],
      ],
      Response::HTTP_CREATED,
    );
  }

  /**
   * Downloads one comment attachment through the application/comment scope.
   */
  public function downloadAttachment(string $application_id, string $file_uuid): BinaryFileResponse {
    $application = $this->loadApplication($application_id);
    if (!$application instanceof ApplicationInterface) {
      throw new NotFoundHttpException();
    }
    if (!$this->canAccessApplicationComments($application)) {
      throw new NotFoundHttpException();
    }

    $file = $this->loadFileByUuid($file_uuid);
    if (!$file instanceof FileInterface) {
      throw new NotFoundHttpException();
    }

    $comment = $this->loadCommentForAttachment($application, $file);
    if (!$comment instanceof ApplicationCommentInterface
      || !$this->commentVisibleToCurrentUser($comment, $this->canSeeInternalComments($application))) {
      throw new NotFoundHttpException();
    }
    if ($this->commentAttachmentFileQuarantined($application, $file)) {
      throw new NotFoundHttpException();
    }

    $uri = (string) $file->getFileUri();
    $real_path = $uri !== '' ? $this->fileSystem->realpath($uri) : FALSE;
    if (!is_string($real_path) || $real_path === '' || !is_file($real_path)) {
      throw new NotFoundHttpException();
    }
    $contents = file_get_contents($real_path);
    if ($contents === FALSE
      || $this->scanAttachmentBytes(
        $contents,
        $file->getMimeType() ?: 'application/octet-stream',
        $file->getFilename() ?: 'attachment',
      )->isQuarantined()) {
      throw new NotFoundHttpException();
    }

    $response = new BinaryFileResponse($real_path, Response::HTTP_OK, [
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
   * Checks whether the current user can access comments for an application.
   *
   * Mailbox proof permits public replies and attachment uploads on confirmed
   * anonymous applications as well as reading public comments. Staff comment
   * visibility and authorship still require staff access to this application.
   */
  protected function canAccessApplicationComments(ApplicationInterface $application): bool {
    $account = $this->currentUser();
    if (!$application->isEmailConfirmed()
      && !$account->hasPermission('administer application entities')) {
      return FALSE;
    }

    if ($this->hasStaffCommentAccess($application) || (int) $application->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }
    if ($this->confirmedMailbox?->allowsRead($application, $account) !== TRUE) {
      return FALSE;
    }

    $this->confirmedMailbox->auditRead($application, $account);
    return TRUE;
  }

  /**
   * Checks staff access against the application's workspace scope.
   */
  protected function canStaffAccessApplicationComments(ApplicationInterface $application): bool {
    return (new ApplicationCommentStaffScope($this->entityTypeManager()))
      ->allows($application, $this->currentUser());
  }

  /**
   * Checks the staff profile and application scope together.
   */
  private function hasStaffCommentAccess(ApplicationInterface $application): bool {
    return $this->isStaffAccount($this->currentUser()) && $this->canStaffAccessApplicationComments($application);
  }

  /**
   * Authorizes the public v1 comment profile.
   */
  protected function authorizeApiV1(string $permission): ?JsonResponse {
    $account = $this->currentUser();
    if ((int) $account->id() <= 0) {
      return $this->apiV1Error(
        'auth.required',
        'Authentication required.',
        Response::HTTP_UNAUTHORIZED,
      );
    }
    if ($this->isServiceAccount($account)) {
      return $this->apiV1Error(
        'auth.unsupported_profile',
        'Service accounts cannot use citizen comment endpoints.',
        Response::HTTP_FORBIDDEN,
      );
    }
    if ($this->isStaffAccount($account)) {
      return $this->apiV1Error(
        'auth.unsupported_profile',
        'Staff accounts cannot use citizen comment endpoints.',
        Response::HTTP_FORBIDDEN,
      );
    }
    if (!$account->hasPermission($permission)) {
      return $this->apiV1Error(
        'comment.forbidden',
        'The authenticated account is not allowed to use application comments.',
        Response::HTTP_FORBIDDEN,
      );
    }
    return NULL;
  }

  /**
   * Returns TRUE when an account is a backend service account.
   */
  protected function isServiceAccount(AccountInterface $account): bool {
    return BeantragtRoles::isServiceAccount($account);
  }

  /**
   * Returns TRUE when an account is a staff/admin profile.
   */
  protected function isStaffAccount(AccountInterface $account): bool {
    return (int) $account->id() === 1 || BeantragtRoles::isStaffAccount($account);
  }

  /**
   * Validates or derives comment visibility.
   */
  protected function validatedCommentVisibility(mixed $value, string $author_role): string|JsonResponse {
    if ($author_role !== 'staff') {
      return 'public';
    }

    if ($value === NULL || $value === '') {
      return 'public';
    }
    if (!is_string($value) || !in_array($value, ['public', 'internal'], TRUE)) {
      return new JsonResponse(
        ['error' => 'The visibility field must be public or internal.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }
    return $value;
  }

  /**
   * Returns TRUE when the current user can see internal staff notes.
   */
  protected function canSeeInternalComments(ApplicationInterface $application): bool {
    return $this->hasStaffCommentAccess($application);
  }

  /**
   * Returns TRUE when a comment should be exposed to the current user.
   */
  protected function commentVisibleToCurrentUser(ApplicationCommentInterface $comment, bool $can_view_internal): bool {
    return !$comment->isInternal() || $can_view_internal;
  }

  /**
   * Moves an application to the semantic request-info status when available.
   *
   * Only a status of the workflow that governs the application qualifies; a
   * form whose workflow has none keeps its status, and the comment mail
   * still goes out. That is a normal outcome, not a warning.
   */
  protected function markApplicationRequestInfo(ApplicationInterface $application): bool {
    $target_status_id = $this->resolveSemanticStatusId($application, 'request_info', [
      'returned',
      'nachforderung_offen',
      'rueckfrage_offen',
    ]);
    if ($target_status_id === NULL) {
      // Silent only when the governing workflow was asked and has no such
      // status; no organisation, no catalogue or no resolver stay a warning.
      if (!$this->requestInfoWorkflowAsked) {
        $this->logger->warning('Application @id: could not resolve a request-info workflow status after staff comment.', [
          '@id' => $application->id(),
        ]);
      }
      return FALSE;
    }

    if ($application->getWorkflowStatusId() === $target_status_id) {
      return FALSE;
    }

    $application->setWorkflowStatusId($target_status_id);
    $application->markSystemStatusChange(SemanticStatusChanger::COMMENT_REQUEST_INFO);
    try {
      $application->save();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update application status after staff comment: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Resolves a status by semantic flag, with legacy machine-name fallbacks.
   *
   * Both the flag and the legacy names are looked up among the statuses of
   * the governing workflow only (SemanticStatusChanger::governingStatusIds()).
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application receiving the status update.
   * @param string $flag
   *   Semantic workflow flag.
   * @param string[] $fallback_machine_names
   *   Legacy machine names to try if no semantic flag is stored yet (the
   *   unflagged "Rückfrage offen" statuses of the local demo seed).
   */
  protected function resolveSemanticStatusId(ApplicationInterface $application, string $flag, array $fallback_machine_names = []): ?int {
    $this->requestInfoWorkflowAsked = FALSE;
    $org_id = $this->applicationOrganisationId($application);
    if ($org_id === NULL || !$this->entityTypeManager()->hasDefinition('workflow_status')) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage('workflow_status');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_group', $org_id)
      ->sort('field_weight', 'ASC')
      ->execute();
    $governing = $this->semanticStatuses?->governingStatusIds($application, $org_id);
    if ($governing !== NULL) {
      $this->requestInfoWorkflowAsked = TRUE;
      $ids = array_intersect($ids, $governing);
      if ($ids === []) {
        return NULL;
      }
    }

    $fallback_ids = [];
    foreach ($storage->loadMultiple($ids) as $status) {
      if (!$status instanceof WorkflowStatusInterface) {
        continue;
      }
      if ($status->hasSemanticFlag($flag)) {
        return (int) $status->id();
      }
      if (in_array($status->getMachineName(), $fallback_machine_names, TRUE)) {
        $fallback_ids[] = (int) $status->id();
      }
    }

    return $fallback_ids[0] ?? NULL;
  }

  /**
   * Resolves the owning organisation for an application.
   */
  protected function applicationOrganisationId(ApplicationInterface $application): ?int {
    if (function_exists('_beantragt_application_resolve_organisation_id')) {
      return _beantragt_application_resolve_organisation_id($application);
    }
    if ($application->hasField('field_organisation') && !$application->get('field_organisation')->isEmpty()) {
      return (int) $application->get('field_organisation')->target_id;
    }
    return NULL;
  }

  /**
   * Loads a file entity by UUID.
   */
  protected function loadFileByUuid(string $file_uuid): ?FileInterface {
    if ($this->normalizeUuid($file_uuid) === NULL) {
      return NULL;
    }
    $matches = $this->entityTypeManager()->getStorage('file')->loadByProperties(['uuid' => $file_uuid]);
    $file = count($matches) === 1 ? reset($matches) : NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Loads the application comment that owns a file attachment.
   */
  protected function loadCommentForAttachment(ApplicationInterface $application, FileInterface $file): ?ApplicationCommentInterface {
    $comment_storage = $this->entityTypeManager()->getStorage('application_comment');
    $ids = $comment_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_application', (int) $application->id())
      ->condition('status', 1)
      ->sort('created', 'ASC')
      ->execute();

    foreach ($comment_storage->loadMultiple($ids) as $comment) {
      if ($comment instanceof ApplicationCommentInterface && $this->commentOwnsFile($comment, $file)) {
        return $comment;
      }
    }
    return NULL;
  }

  /**
   * Returns TRUE when a comment references the given file.
   */
  protected function commentOwnsFile(ApplicationCommentInterface $comment, FileInterface $file): bool {
    if (!$comment->hasField('field_attachments') || $comment->get('field_attachments')->isEmpty()) {
      return FALSE;
    }
    $file_id = (int) $file->id();
    foreach ($comment->get('field_attachments') as $item) {
      if ((int) ($item->target_id ?? 0) === $file_id) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Verifies that a legacy comment upload belongs to the same application.
   */
  protected function legacyCommentUploadMatchesApplication(FileInterface $file, int $application_id): bool {
    $uri = (string) $file->getFileUri();
    $prefix = 'private://application_comments/' . $application_id . '/';
    return str_starts_with($uri, $prefix);
  }

  /**
   * Re-scans legacy comment files before binding them to a comment.
   */
  protected function legacyCommentFileScanError(FileInterface $file): ?JsonResponse {
    $uri = (string) $file->getFileUri();
    $path = $uri !== '' ? $this->fileSystem->realpath($uri) : FALSE;
    $contents = $uri !== '' ? file_get_contents(is_string($path) && $path !== '' ? $path : $uri) : FALSE;
    if ($contents === FALSE) {
      return new JsonResponse(
        ['error' => 'Attachment claim is invalid.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }
    $scan_result = $this->scanAttachmentBytes(
      $contents,
      $file->getMimeType() ?: 'application/octet-stream',
      $file->getFilename() ?: 'attachment',
    );
    if ($scan_result->isQuarantined()) {
      return new JsonResponse(
        ['error' => 'Attachment was quarantined by malware scanning.'],
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }
    return NULL;
  }

  /**
   * Creates an unclaimed attachment record for a legacy comment upload.
   */
  protected function reserveLegacyCommentAttachment(ApplicationInterface $application, FileInterface $file, string $mime_type): void {
    $schema_id = $this->applicationSchemaId($application);
    if ($schema_id === NULL) {
      return;
    }

    $attachment = $this->entityTypeManager()->getStorage('application_attachment')->create([
      'field_schema' => ['target_id' => $schema_id],
      'field_application' => ['target_id' => (int) $application->id()],
      'field_field_id' => self::COMMENT_ATTACHMENT_FIELD_ID,
      'field_file' => ['target_id' => (int) $file->id()],
      'field_claim_token_hash' => NULL,
      'field_claim_expires' => $this->requestTime() + self::CLAIM_TTL,
      'field_upload_status' => 'uploaded',
      'field_scan_status' => AttachmentScanResult::STATUS_CLEAN,
      'field_scan_message' => NULL,
      'field_analysis_status' => 'not_requested',
      'field_analysis_metadata' => json_encode([
        'context' => 'legacy_application_comment',
        'applicationUuid' => (string) $application->uuid(),
      ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      'field_original_filename' => $file->getFilename(),
      'field_mime_type' => $mime_type,
      'field_size' => (int) $file->getSize(),
      'uid' => (int) $this->currentUser()->id(),
    ]);
    $attachment->save();
  }

  /**
   * Claims a legacy comment upload reservation when the file is bound.
   */
  protected function claimLegacyCommentAttachmentReservation(ApplicationInterface $application, FileInterface $file, array $locked_reservations = []): void {
    $matches = $locked_reservations ?: $this->entityTypeManager()->getStorage('application_attachment')->loadByProperties([
      'field_file' => (int) $file->id(),
      'field_field_id' => self::COMMENT_ATTACHMENT_FIELD_ID,
      'field_upload_status' => 'uploaded',
    ]);
    foreach ($matches as $attachment) {
      if (!$attachment instanceof ApplicationAttachmentInterface
        || (int) ($attachment->get('field_file')->target_id ?? 0) !== (int) $file->id()
        || !$this->commentAttachmentMatchesApplication($attachment, $application)) {
        continue;
      }
      $attachment->set('field_application', ['target_id' => (int) $application->id()]);
      $attachment->set('field_upload_status', 'claimed');
      $attachment->set('field_claim_expires', NULL);
      $attachment->save();
      return;
    }
  }

  /**
   * Loads legacy upload reservations that require a claim lock.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application receiving the comment.
   * @param \Drupal\file\FileInterface[] $files
   *   Validated legacy upload files.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[]
   *   Matching reservation entities keyed by entity ID.
   */
  protected function legacyCommentAttachmentReservations(ApplicationInterface $application, array $files): array {
    $storage = $this->entityTypeManager()->getStorage('application_attachment');
    $reservations = [];
    foreach ($files as $file) {
      $matches = $storage->loadByProperties([
        'field_file' => (int) $file->id(),
        'field_field_id' => self::COMMENT_ATTACHMENT_FIELD_ID,
        'field_upload_status' => 'uploaded',
      ]);
      foreach ($matches as $attachment) {
        if ($attachment instanceof ApplicationAttachmentInterface
          && $this->commentAttachmentMatchesApplication($attachment, $application)) {
          $reservations[(int) $attachment->id()] = $attachment;
        }
      }
    }
    return $reservations;
  }

  /**
   * Reloads legacy files and reservations under their claim locks.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application receiving the comment.
   * @param \Drupal\file\FileInterface[] $files
   *   Files validated before lock acquisition.
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $reservations
   *   Reservations whose claim locks are held.
   *
   * @return array{files: \Drupal\file\FileInterface[], attachments: \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[]}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Fresh locked claims or a validation error.
   */
  protected function revalidateLegacyCommentAttachmentReservations(ApplicationInterface $application, array $files, array $reservations): array|JsonResponse {
    if ($reservations === [] || count($reservations) !== count($files)) {
      return new JsonResponse(['error' => 'Attachment claim is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $attachment_storage = $this->entityTypeManager()->getStorage('application_attachment');
    $file_storage = $this->entityTypeManager()->getStorage('file');
    $reservation_ids = array_map('intval', array_keys($reservations));
    $file_ids = array_values(array_map(
      static fn(FileInterface $file): int => (int) $file->id(),
      $files,
    ));
    $attachment_storage->resetCache($reservation_ids);
    $file_storage->resetCache($file_ids);

    $fresh_files = [];
    $fresh_reservations = [];
    foreach ($file_ids as $file_id) {
      $file = $file_storage->load($file_id);
      if (!$file instanceof FileInterface
        || (int) $file->getOwnerId() !== (int) $this->currentUser()->id()
        || !$this->legacyCommentUploadMatchesApplication($file, (int) $application->id())) {
        return new JsonResponse(['error' => 'Attachment claim is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      $scan_error = $this->legacyCommentFileScanError($file);
      if ($scan_error instanceof JsonResponse) {
        return $scan_error;
      }

      $matches = $attachment_storage->loadByProperties([
        'field_file' => $file_id,
        'field_field_id' => self::COMMENT_ATTACHMENT_FIELD_ID,
        'field_upload_status' => 'uploaded',
      ]);
      $matched = NULL;
      foreach ($matches as $attachment) {
        $attachment_id = $attachment instanceof ApplicationAttachmentInterface ? (int) $attachment->id() : 0;
        if ($attachment instanceof ApplicationAttachmentInterface
          && isset($reservations[$attachment_id])
          && (int) $attachment->getOwnerId() === (int) $this->currentUser()->id()
          && (int) ($attachment->get('field_file')->target_id ?? 0) === $file_id
          && (int) ($attachment->get('field_claim_expires')->value ?? 0) > $this->requestTime()
          && $this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_CLEAN
          && $this->commentAttachmentMatchesApplication($attachment, $application)) {
          $matched = $attachment;
          break;
        }
      }
      if (!$matched instanceof ApplicationAttachmentInterface) {
        return new JsonResponse(['error' => 'Attachment claim is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      $fresh_files[] = $file;
      $fresh_reservations[(int) $matched->id()] = $matched;
    }

    return [
      'files' => $fresh_files,
      'attachments' => $fresh_reservations,
    ];
  }

  /**
   * Validates the CSRF header for public v1 comment mutations.
   */
  protected function validateCsrfToken(Request $request): bool {
    $token = $request->headers->get('X-CSRF-Token');
    if ($token === NULL || trim($token) === '' || !$this->csrfToken instanceof CsrfTokenGenerator) {
      return FALSE;
    }
    return $this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      || $this->csrfToken->validate($token, 'rest');
  }

  /**
   * Loads a v1 application by UUID and required workspace scope.
   */
  protected function loadScopedApplicationV1(string $application_uuid, Request $request): ApplicationInterface|JsonResponse {
    $scope = $this->resolveRequiredWorkspaceScopeV1($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }

    $storage = $this->entityTypeManager()->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', $application_uuid)
      ->range(0, 1);
    $this->tenantScopedEntityQuery->applyWorkspaceScope(
      $query,
      'application',
      $scope['jurisdiction'],
      $scope['organisation'] ?? NULL,
    );
    $ids = $query->execute();
    if (!$ids) {
      return $this->applicationNotFoundV1();
    }

    $application = $storage->load(reset($ids));
    return $application instanceof ApplicationInterface ? $application : $this->applicationNotFoundV1();
  }

  /**
   * Resolves the required public v1 workspace scope.
   *
   * @return array{jurisdiction: \Drupal\group\Entity\GroupInterface, organisation?: \Drupal\group\Entity\GroupInterface}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Resolved workspace scope, or an error response.
   */
  protected function resolveRequiredWorkspaceScopeV1(Request $request): array|JsonResponse {
    if (!$request->query->has('jurisdiction')) {
      return $this->apiV1Error(
        'workspace.required',
        'Query parameter jurisdiction is required.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $jurisdiction_slug = $this->normalizePublicSlug($request->query->get('jurisdiction'));
    $organisation_slug = $this->normalizePublicSlug(
      $request->query->get('organisation')
      ?? $request->query->get('organization')
      ?? $request->query->get('org')
    );

    if ($jurisdiction_slug === NULL
      || (($request->query->has('organisation') || $request->query->has('organization') || $request->query->has('org')) && $organisation_slug === NULL)) {
      return $this->apiV1Error(
        'workspace.not_found',
        'Workspace scope not found.',
        Response::HTTP_NOT_FOUND,
      );
    }

    $jurisdiction = $this->loadGroupBySlug('jur', $jurisdiction_slug);
    if (!$jurisdiction instanceof GroupInterface) {
      return $this->apiV1Error(
        'workspace.not_found',
        'Workspace scope not found.',
        Response::HTTP_NOT_FOUND,
      );
    }

    $scope = ['jurisdiction' => $jurisdiction];
    if ($organisation_slug !== NULL) {
      $organisation = $this->loadOrganisationBySlug($organisation_slug, (int) $jurisdiction->id());
      if (!$organisation instanceof GroupInterface) {
        return $this->apiV1Error(
          'workspace.not_found',
          'Workspace scope not found.',
          Response::HTTP_NOT_FOUND,
        );
      }
      $scope['organisation'] = $organisation;
    }

    return $scope;
  }

  /**
   * Normalizes a public workspace slug.
   */
  protected function normalizePublicSlug(mixed $value): ?string {
    if (is_array($value)) {
      $value = reset($value);
    }
    if (!is_string($value)) {
      return NULL;
    }
    $slug = strtolower(trim($value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Loads a jurisdiction or organisation group by public slug.
   */
  protected function loadGroupBySlug(string $bundle, string $slug): ?GroupInterface {
    $storage = $this->entityTypeManager()->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_slug', $slug)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Loads an organisation group by public slug and jurisdiction.
   */
  protected function loadOrganisationBySlug(string $slug, int $jurisdiction_id): ?GroupInterface {
    $storage = $this->entityTypeManager()->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_slug', $slug)
      ->condition('field_jurisdiction', $jurisdiction_id)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Parses the v1 list pagination query.
   *
   * @return array{limit: int, offset: int}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Parsed pagination values, or an error response.
   */
  protected function parsePagination(Request $request): array|JsonResponse {
    $limit = $this->parseIntegerQuery($request, 'limit', self::DEFAULT_LIMIT);
    $offset = $this->parseIntegerQuery($request, 'offset', 0);
    if ($limit === NULL || $limit < 1 || $limit > self::MAX_LIMIT) {
      return $this->apiV1Error(
        'pagination.invalid',
        'Query parameter limit must be an integer between 1 and 100.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    if ($offset === NULL || $offset < 0 || $offset > self::MAX_OFFSET) {
      return $this->apiV1Error(
        'pagination.invalid',
        'Query parameter offset must be an integer between 0 and 10000.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    return [
      'limit' => $limit,
      'offset' => $offset,
    ];
  }

  /**
   * Parses an integer query parameter.
   */
  protected function parseIntegerQuery(Request $request, string $name, int $default): ?int {
    $raw = $request->query->get($name);
    if ($raw === NULL || $raw === '') {
      return $default;
    }
    $value = filter_var((string) $raw, FILTER_VALIDATE_INT);
    return $value === FALSE ? NULL : (int) $value;
  }

  /**
   * Builds the v1 pagination metadata.
   *
   * @return array{limit: int, offset: int, hasMore: bool, nextOffset?: int}
   *   Pagination metadata.
   */
  protected function paginationMeta(int $limit, int $offset, bool $has_more): array {
    $page = [
      'limit' => $limit,
      'offset' => $offset,
      'hasMore' => $has_more,
    ];
    if ($has_more) {
      $page['nextOffset'] = $offset + $limit;
    }
    return $page;
  }

  /**
   * Parses and validates a public v1 comment create request body.
   *
   * @return array{body: string, attachments: array<int, array<string, mixed>>}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Parsed create payload, or an error response.
   */
  protected function parseCommentCreateV1(Request $request): array|JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return $this->apiV1Error(
        'comment.invalid_request',
        'Request body must be a JSON object.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $resource = $data['data'] ?? NULL;
    if (!is_array($resource) || ($resource['type'] ?? NULL) !== 'application_comment') {
      return $this->apiV1Error(
        'comment.invalid_request',
        'Request body must contain data.type application_comment.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    $attributes = $resource['attributes'] ?? NULL;
    $body = is_array($attributes) ? ($attributes['body'] ?? NULL) : NULL;
    if (!is_string($body) || trim($body) === '') {
      return $this->apiV1Error(
        'comment.body_required',
        'The comment body is required.',
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $body = strip_tags(trim($body));
    if ($body === '') {
      return $this->apiV1Error(
        'comment.body_required',
        'The comment body is required.',
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }
    if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
      return $this->apiV1Error(
        'comment.body_too_long',
        'The comment body must not exceed ' . self::MAX_BODY_LENGTH . ' characters.',
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $attachments = $attributes['attachments'] ?? [];
    if (!is_array($attachments) || !array_is_list($attachments)) {
      return $this->apiV1Error(
        'attachment.invalid_claims',
        'Attachment claims must be an array.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    return [
      'body' => $body,
      'attachments' => $attachments,
    ];
  }

  /**
   * Normalizes a comment into the external public v1 shape.
   */
  protected function normalizeCommentV1(ApplicationCommentInterface $comment): array {
    return [
      'type' => 'application_comment',
      'id' => (string) $comment->uuid(),
      'body' => $comment->getBody() ?? '',
      'authorRole' => $comment->getAuthorRole(),
      'visibility' => $comment->getVisibility(),
      'createdAt' => $this->formatTimestamp($comment->getCreatedTime()),
      'attachments' => $this->normalizeCommentAttachmentsV1($comment),
    ];
  }

  /**
   * Normalizes comment attachments without exposing file entity IDs.
   *
   * @return array<int, array{filename: string, size: int}>
   *   Safe attachment metadata.
   */
  protected function normalizeCommentAttachmentsV1(ApplicationCommentInterface $comment): array {
    if (!$comment->hasField('field_attachments') || $comment->get('field_attachments')->isEmpty()) {
      return [];
    }

    $attachments = [];
    $file_storage = $this->entityTypeManager()->getStorage('file');
    foreach ($comment->get('field_attachments') as $item) {
      $file = NULL;
      if (isset($item->entity) && $item->entity instanceof FileInterface) {
        $file = $item->entity;
      }
      elseif (isset($item->target_id)) {
        $file = $file_storage->load((int) $item->target_id);
      }
      if (!$file instanceof FileInterface) {
        continue;
      }
      $attachments[] = [
        'filename' => (string) $file->getFilename(),
        'size' => (int) $file->getSize(),
      ];
    }
    return $attachments;
  }

  /**
   * Applies v1 comment mutation flood protection.
   */
  protected function commentFloodResponse(Request $request, ApplicationInterface $application, string $operation): ?JsonResponse {
    if (!$this->flood instanceof FloodInterface) {
      return NULL;
    }

    $limits = match ($operation) {
      'attachment_upload' => [
        'event' => 'beantragt_comment.v1_attachment_upload',
        'target_event' => 'beantragt_comment.v1_attachment_upload_target',
        'limit' => self::COMMENT_ATTACHMENT_UPLOAD_FLOOD_LIMIT,
        'target_limit' => self::COMMENT_ATTACHMENT_UPLOAD_TARGET_FLOOD_LIMIT,
      ],
      default => [
        'event' => 'beantragt_comment.v1_create',
        'target_event' => 'beantragt_comment.v1_create_target',
        'limit' => self::COMMENT_CREATE_FLOOD_LIMIT,
        'target_limit' => self::COMMENT_CREATE_TARGET_FLOOD_LIMIT,
      ],
    };

    $identifier = $this->commentFloodIdentifier($request);
    if (!$this->flood->isAllowed($limits['event'], $limits['limit'], self::FLOOD_WINDOW, $identifier)) {
      return $this->apiV1Error('rate_limit.exceeded', 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register($limits['event'], self::FLOOD_WINDOW, $identifier);

    $target_identifier = hash('sha256', (string) $application->uuid() . ':' . $identifier);
    if (!$this->flood->isAllowed($limits['target_event'], $limits['target_limit'], self::FLOOD_WINDOW, $target_identifier)) {
      return $this->apiV1Error('rate_limit.exceeded', 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register($limits['target_event'], self::FLOOD_WINDOW, $target_identifier);

    return NULL;
  }

  /**
   * Builds a flood identifier for authenticated citizen comment mutations.
   */
  protected function commentFloodIdentifier(Request $request): string {
    return hash('sha256', implode(':', [
      (string) $this->currentUser()->id(),
      (string) ($request->getClientIp() ?? 'unknown'),
    ]));
  }

  /**
   * Validates comment attachment claims for a comment create request.
   *
   * @return array{entities: array<int, \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface>, files: array<int, \Drupal\file\FileInterface>}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Valid claim entities and files, or an error response.
   */
  protected function validateCommentAttachmentClaims(array $claims, ApplicationInterface $application): array|JsonResponse {
    if ($claims === []) {
      return [
        'entities' => [],
        'files' => [],
      ];
    }
    if (count($claims) > self::MAX_ATTACHMENTS) {
      return $this->apiV1Error(
        'attachment.too_many',
        'Maximum ' . self::MAX_ATTACHMENTS . ' attachments allowed.',
        Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
      );
    }

    $seen = [];
    $entities = [];
    $files = [];
    $attachment_storage = $this->entityTypeManager()->getStorage('application_attachment');
    $file_storage = $this->entityTypeManager()->getStorage('file');
    foreach ($claims as $claim) {
      if (!is_array($claim) || array_is_list($claim)) {
        return $this->apiV1Error(
          'attachment.invalid_claim',
          'Attachment claim is invalid.',
          Response::HTTP_BAD_REQUEST,
        );
      }
      $attachment_uuid = $this->normalizeUuid($claim['attachmentId'] ?? NULL);
      $claim_token = is_string($claim['claimToken'] ?? NULL) ? trim($claim['claimToken']) : '';
      if ($attachment_uuid === NULL || preg_match(self::CLAIM_TOKEN_PATTERN, $claim_token) !== 1) {
        return $this->apiV1Error(
          'attachment.invalid_claim',
          'Attachment claim is invalid.',
          Response::HTTP_BAD_REQUEST,
        );
      }
      if (isset($seen[$attachment_uuid])) {
        return $this->apiV1Error(
          'attachment.duplicate',
          'Attachment claim is duplicated.',
          Response::HTTP_BAD_REQUEST,
        );
      }
      $seen[$attachment_uuid] = TRUE;

      $matches = $attachment_storage->loadByProperties(['uuid' => $attachment_uuid]);
      $attachment = count($matches) === 1 ? reset($matches) : NULL;
      if (!$attachment instanceof ApplicationAttachmentInterface
        || $attachment->get('field_application')->isEmpty()
        || (int) $attachment->get('field_application')->target_id !== (int) $application->id()
        || !$this->commentAttachmentMatchesApplication($attachment, $application)
        || (string) $attachment->get('field_field_id')->value !== self::COMMENT_ATTACHMENT_FIELD_ID
        || (string) $attachment->get('field_upload_status')->value !== 'uploaded'
        || (int) $attachment->getOwnerId() !== (int) $this->currentUser()->id()) {
        return $this->apiV1Error(
          'attachment.claim_invalid',
          'Attachment claim is invalid.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
        );
      }
      if ($this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_QUARANTINED) {
        return $this->apiV1Error(
          'attachment.quarantined',
          'Attachment was quarantined by malware scanning.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
          ['attachmentId' => $attachment_uuid],
        );
      }

      $expires = (int) $attachment->get('field_claim_expires')->value;
      if ($expires <= $this->requestTime()) {
        return $this->apiV1Error(
          'attachment.claim_expired',
          'Attachment claim has expired.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
        );
      }
      $hash = (string) $attachment->get('field_claim_token_hash')->value;
      if ($hash === '' || !hash_equals($hash, hash('sha256', $claim_token))) {
        return $this->apiV1Error(
          'attachment.claim_invalid',
          'Attachment claim is invalid.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
        );
      }

      $file = NULL;
      if (!$attachment->get('field_file')->isEmpty()) {
        $file = $file_storage->load($attachment->get('field_file')->target_id);
      }
      if (!$file instanceof FileInterface) {
        return $this->apiV1Error(
          'attachment.claim_invalid',
          'Attachment claim is invalid.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
        );
      }
      $uri = (string) $file->getFileUri();
      if ($uri === '' || !is_file($uri)) {
        return $this->apiV1Error(
          'attachment.claim_invalid',
          'Attachment claim is invalid.',
          Response::HTTP_UNPROCESSABLE_ENTITY,
        );
      }

      $entities[(int) $attachment->id()] = $attachment;
      $files[(int) $file->id()] = $file;
    }

    return [
      'entities' => $entities,
      'files' => $files,
    ];
  }

  /**
   * Verifies that an uploaded comment attachment belongs to the application.
   */
  protected function commentAttachmentMatchesApplication(ApplicationAttachmentInterface $attachment, ApplicationInterface $application): bool {
    if (!$attachment->hasField('field_analysis_metadata') || $attachment->get('field_analysis_metadata')->isEmpty()) {
      return FALSE;
    }

    try {
      $metadata = json_decode((string) $attachment->get('field_analysis_metadata')->value, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return FALSE;
    }

    return is_array($metadata)
      && in_array($metadata['context'] ?? NULL, ['application_comment', 'legacy_application_comment'], TRUE)
      && is_string($metadata['applicationUuid'] ?? NULL)
      && strtolower($metadata['applicationUuid']) === strtolower((string) $application->uuid());
  }

  /**
   * Claims uploaded comment attachments after comment creation.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated claim entities.
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   Application owning the created comment.
   */
  protected function claimCommentAttachments(array $attachments, ApplicationInterface $application): void {
    if ($attachments === []) {
      return;
    }

    $file_storage = $this->entityTypeManager()->getStorage('file');
    foreach ($attachments as $attachment) {
      if (!$attachment->get('field_file')->isEmpty()) {
        $file = $file_storage->load($attachment->get('field_file')->target_id);
        if ($file instanceof FileInterface) {
          $file->setPermanent();
          $file->setOwnerId((int) $this->currentUser()->id());
          $file->save();
        }
      }
      $attachment->set('field_application', ['target_id' => (int) $application->id()]);
      $attachment->set('field_upload_status', 'claimed');
      $attachment->set('field_claim_token_hash', NULL);
      $attachment->set('field_claim_expires', NULL);
      $attachment->setOwnerId((int) $this->currentUser()->id());
      $attachment->save();
    }
  }

  /**
   * Returns an error when the tenant storage quota would be exceeded.
   */
  protected function tenantStorageQuotaResponse(ApplicationInterface $application, int $next_size): ?JsonResponse {
    if (!$this->attachmentStorageQuota instanceof AttachmentStorageQuota) {
      return NULL;
    }
    $jurisdiction = $this->jurisdictionForApplication($application);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $violation = $this->attachmentStorageQuota->violationForJurisdiction($jurisdiction, $next_size);
    if ($violation === NULL) {
      return NULL;
    }

    return $this->apiV1Error(
      'attachment.tenant_storage_quota_exceeded',
      'Tenant attachment storage quota would be exceeded.',
      Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
      $violation,
    );
  }

  /**
   * Resolves the jurisdiction group for an application.
   */
  protected function jurisdictionForApplication(ApplicationInterface $application): ?GroupInterface {
    if (!$application->hasField('field_jurisdiction') || $application->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }
    $field = $application->get('field_jurisdiction');
    $group = $field->entity ?? NULL;
    if ($group instanceof GroupInterface) {
      return $group;
    }
    $target_id = (int) ($field->target_id ?? 0);
    if ($target_id <= 0) {
      return NULL;
    }
    $loaded = $this->entityTypeManager()->getStorage('group')->load($target_id);
    return $loaded instanceof GroupInterface ? $loaded : NULL;
  }

  /**
   * Scans raw attachment bytes with the configured scanner.
   */
  protected function scanAttachmentBytes(string $bytes, string $mime_type, string $filename): AttachmentScanResult {
    $scanner = $this->attachmentScanner ?? new AttachmentScanService();
    return $scanner->scan($bytes, $filename, $mime_type);
  }

  /**
   * Returns the persisted scan status for an attachment entity.
   */
  protected function attachmentScanStatus(ApplicationAttachmentInterface $attachment): string {
    if (!$attachment->hasField('field_scan_status') || $attachment->get('field_scan_status')->isEmpty()) {
      return AttachmentScanResult::STATUS_CLEAN;
    }
    $status = (string) $attachment->get('field_scan_status')->value;
    return in_array($status, [AttachmentScanResult::STATUS_CLEAN, AttachmentScanResult::STATUS_QUARANTINED], TRUE)
      ? $status
      : AttachmentScanResult::STATUS_QUARANTINED;
  }

  /**
   * Checks whether a comment file has a quarantined attachment record.
   */
  protected function commentAttachmentFileQuarantined(ApplicationInterface $application, FileInterface $file): bool {
    $matches = $this->entityTypeManager()->getStorage('application_attachment')->loadByProperties([
      'field_application' => (int) $application->id(),
      'field_file' => (int) $file->id(),
    ]);
    foreach ($matches as $attachment) {
      if ($attachment instanceof ApplicationAttachmentInterface
        && $this->attachmentScanStatus($attachment) === AttachmentScanResult::STATUS_QUARANTINED) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Acquires short-lived locks for comment attachment claims.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated claim entities.
   *
   * @return string[]|null
   *   Lock names, or NULL when acquisition failed.
   */
  protected function acquireCommentAttachmentLocks(array $attachments): ?array {
    if ($attachments === [] || !$this->lock instanceof LockBackendInterface) {
      return [];
    }

    $locks = [];
    foreach ($attachments as $attachment) {
      $lock_name = 'beantragt_comment.attachment_claim.' . (int) $attachment->id();
      if (!$this->lock->acquire($lock_name, 30.0)) {
        $this->releaseCommentAttachmentLocks($locks);
        return NULL;
      }
      $locks[] = $lock_name;
    }
    return $locks;
  }

  /**
   * Acquires the jurisdiction quota lock while comment claims are bound.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The comment target application.
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated claim entities.
   *
   * @return string|false|null
   *   Lock name, NULL when no lock is needed, or FALSE when acquisition failed.
   */
  protected function acquireTenantQuotaLock(ApplicationInterface $application, array $attachments): string|false|null {
    if ($attachments === []) {
      return NULL;
    }
    return $this->acquireTenantQuotaLockForApplication($application);
  }

  /**
   * Acquires the jurisdiction quota lock for one application.
   */
  protected function acquireTenantQuotaLockForApplication(ApplicationInterface $application): string|false|null {
    if (!$this->lock instanceof LockBackendInterface || !$this->attachmentStorageQuota instanceof AttachmentStorageQuota) {
      return NULL;
    }
    $jurisdiction = $this->jurisdictionForApplication($application);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $lock_name = 'beantragt_application.attachment_storage_quota.' . (int) $jurisdiction->id();
    return $this->lock->acquire($lock_name, 30.0) ? $lock_name : FALSE;
  }

  /**
   * Releases a tenant quota lock.
   */
  protected function releaseTenantQuotaLock(string|false|null $lock_name): void {
    if (is_string($lock_name) && $this->lock instanceof LockBackendInterface) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Acquires the application deletion barrier and reloads the target from DB.
   */
  protected function acquireApplicationMutationLock(ApplicationInterface $application): string|false|null {
    if ($this->applicationMutationLock instanceof ApplicationDependentMutationLock) {
      if (!$this->applicationMutationLock->acquireApplication($application)) {
        return FALSE;
      }
      $this->applicationMutationLockOwner = $application;
      return Application::deletionLockName((int) $application->id());
    }

    if (!$this->lock instanceof LockBackendInterface) {
      return NULL;
    }

    $application_id = (int) $application->id();
    if ($application_id <= 0) {
      return FALSE;
    }
    $lock_name = Application::deletionLockName($application_id);
    if (!$this->lock->acquire($lock_name, 300.0)) {
      return FALSE;
    }

    try {
      $storage = $this->entityTypeManager()->getStorage('application');
      $storage->resetCache([$application_id]);
      $current = $storage->load($application_id);
    }
    catch (\Throwable $exception) {
      $this->lock->release($lock_name);
      throw $exception;
    }
    if (!$current instanceof ApplicationInterface
      || $current->uuid() !== $application->uuid()) {
      $this->lock->release($lock_name);
      return FALSE;
    }

    return $lock_name;
  }

  /**
   * Releases an application mutation lock.
   */
  protected function releaseApplicationMutationLock(string|false|null $lock_name): void {
    if ($this->applicationMutationLock instanceof ApplicationDependentMutationLock
      && $this->applicationMutationLockOwner instanceof ApplicationInterface) {
      $this->applicationMutationLock->release($this->applicationMutationLockOwner);
      $this->applicationMutationLockOwner = NULL;
      return;
    }

    if (is_string($lock_name) && $this->lock instanceof LockBackendInterface) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Releases comment attachment claim locks.
   *
   * @param string[] $locks
   *   Lock names.
   */
  protected function releaseCommentAttachmentLocks(array $locks): void {
    if (!$this->lock instanceof LockBackendInterface) {
      return;
    }
    foreach ($locks as $lock_name) {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Clears attachment storage cache before revalidating locked claim state.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface[] $attachments
   *   Validated claim entities.
   */
  protected function resetCommentAttachmentCache(array $attachments): void {
    if ($attachments === []) {
      return;
    }

    $ids = array_values(array_map(
      static fn(ApplicationAttachmentInterface $attachment): int => (int) $attachment->id(),
      $attachments,
    ));
    $this->entityTypeManager()->getStorage('application_attachment')->resetCache($ids);
  }

  /**
   * Validates one uploaded comment attachment file.
   */
  protected function validateCommentUploadFile(UploadedFile $uploaded_file): ?JsonResponse {
    if ((int) $uploaded_file->getSize() > self::MAX_FILE_SIZE) {
      return $this->apiV1Error(
        'attachment.file_too_large',
        'File exceeds maximum size of 10 MB.',
        Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
      );
    }

    $extension = strtolower($uploaded_file->getClientOriginalExtension());
    if (!in_array($extension, self::ALLOWED_EXTENSIONS, TRUE)) {
      return $this->apiV1Error(
        'attachment.unsupported_file_type',
        'Only PDF, JPG, JPEG and PNG files are allowed.',
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    $actual_mime = $this->detectUploadedMime($uploaded_file);
    $expected_mime = self::ALLOWED_MIMES[$extension];
    if ($actual_mime !== $expected_mime) {
      return $this->apiV1Error(
        'attachment.mime_mismatch',
        'File content does not match extension.',
        Response::HTTP_UNPROCESSABLE_ENTITY,
      );
    }

    return NULL;
  }

  /**
   * Detects supported MIME types using magic bytes.
   */
  protected function detectUploadedMime(UploadedFile $uploaded_file): ?string {
    $path = $uploaded_file->getRealPath();
    if (!is_string($path) || $path === '') {
      return NULL;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === FALSE) {
      return NULL;
    }
    $bytes = (string) fread($handle, 12);
    fclose($handle);

    if (str_starts_with($bytes, '%PDF-')) {
      return 'application/pdf';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
      return 'image/png';
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
      return 'image/jpeg';
    }
    return NULL;
  }

  /**
   * Sanitizes a client-provided upload filename.
   */
  protected function sanitizeCommentFilename(string $filename, string $extension): string {
    $basename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? '';
    $basename = preg_replace('/_+/', '_', $basename) ?? '';
    $basename = trim($basename, '_.');
    if ($basename === '' || $basename === $extension) {
      $basename = 'attachment_' . $this->requestTime() . '.' . $extension;
    }
    if (!str_ends_with(strtolower($basename), '.' . $extension)) {
      $basename .= '.' . $extension;
    }
    if (strlen($basename) > self::MAX_FILENAME_LENGTH) {
      $suffix = '.' . $extension;
      $basename = substr($basename, 0, self::MAX_FILENAME_LENGTH - strlen($suffix)) . $suffix;
    }
    return $basename;
  }

  /**
   * Returns the schema id attached to an application.
   */
  protected function applicationSchemaId(ApplicationInterface $application): ?int {
    if (!$application->hasField('field_schema') || $application->get('field_schema')->isEmpty()) {
      return NULL;
    }
    $target_id = (int) $application->get('field_schema')->target_id;
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * Builds a random one-time claim token.
   */
  protected function claimToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Normalizes public UUID inputs.
   */
  protected function normalizeUuid(mixed $uuid): ?string {
    if (!is_string($uuid)) {
      return NULL;
    }
    $uuid = strtolower(trim($uuid));
    return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : NULL;
  }

  /**
   * Returns the current request time.
   */
  protected function requestTime(): int {
    return $this->time instanceof TimeInterface ? $this->time->getRequestTime() : time();
  }

  /**
   * Derives the comment author role from the authenticated account.
   */
  protected function authorRoleForAccount(AccountInterface $account): string {
    if ($this->isStaffAccount($account)) {
      return 'staff';
    }
    return 'citizen';
  }

  /**
   * Creates a private, non-cacheable JSON response.
   */
  protected function noStoreResponse(array $data): JsonResponse {
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Creates a private public v1 error envelope.
   */
  protected function apiV1Error(string $code, string $message, int $status, array $details = []): JsonResponse {
    $error = [
      'code' => $code,
      'message' => $message,
    ];
    if ($details !== []) {
      $error['details'] = $details;
    }
    return $this->noStoreResponse([
      'error' => $error,
      'meta' => [
        'apiVersion' => self::API_VERSION,
      ],
    ])->setStatusCode($status);
  }

  /**
   * Creates the v1 not-found response for hidden or missing applications.
   */
  protected function applicationNotFoundV1(): JsonResponse {
    return $this->apiV1Error(
      'application.not_found',
      'Application not found.',
      Response::HTTP_NOT_FOUND,
    );
  }

  /**
   * Loads an application by numeric entity ID or UUID.
   *
   * @param string $application_id
   *   The incoming application identifier.
   *
   * @return \Drupal\beantragt_application\Entity\ApplicationInterface|null
   *   The loaded application or NULL.
   */
  protected function loadApplication(string $application_id): ?ApplicationInterface {
    $storage = $this->entityTypeManager()->getStorage('application');
    if (ctype_digit($application_id)) {
      $application = $storage->load((int) $application_id);
      return $application instanceof ApplicationInterface ? $application : NULL;
    }

    $applications = $storage->loadByProperties(['uuid' => $application_id]);
    $application = reset($applications);
    return $application instanceof ApplicationInterface ? $application : NULL;
  }

  /**
   * Formats an entity timestamp as an ISO-8601 string for the frontend.
   */
  protected function formatTimestamp(int $timestamp): string {
    return gmdate('c', $timestamp);
  }

}
