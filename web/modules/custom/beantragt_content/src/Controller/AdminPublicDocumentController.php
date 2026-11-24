<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_content\Content\AdminContent;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Admin API for public editorial documents assigned to workspaces or forms.
 */
final class AdminPublicDocumentController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  private const MAX_BODY_LENGTH = 50000;
  private const MAX_FILE_SIZE = 20 * 1024 * 1024;
  private const MAX_FILE_BASE64_LENGTH = 27962028;
  private const PUBLIC_DOCUMENT_DIRECTORY = 'private://public_documents';
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
  private const PLACEMENTS = [
    'org_start' => TRUE,
    'form_intro' => TRUE,
    'form_downloads' => TRUE,
    'form_sidebar' => TRUE,
    'submission_success' => TRUE,
  ];
  private const ALLOWED_EXTENSIONS = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
  ];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileUsageInterface $fileUsage,
    private readonly AttachmentScanServiceInterface $attachmentScanner,
    private readonly LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_group.jurisdiction_hierarchy_resolver'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('file.usage'),
      $container->get('beantragt_application.attachment_scanner'),
      $container->get('logger.factory')->get('beantragt_content'),
    );
  }

  /**
   * Access check for public document editor endpoints.
   */
  public function accessCheck(AccountInterface $account, string $jurisdiction_id): AccessResultInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return AccessResult::forbidden('Jurisdiction not found.')->addCacheContexts(['url.path']);
    }

    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    $membership_cache_tag = 'group_relationship_list:plugin:group_membership:entity:' . $account->id();
    if (BeantragtRoles::isManagementAccount($account)) {
      foreach (GroupMembership::loadByUser($account) as $membership) {
        $managed_group = $membership->getGroup();
        if (!$this->isJurisdictionGroup($managed_group) || !$this->membershipGrantsAdmin($membership)) {
          continue;
        }
        $scope_ids = $this->hierarchyResolver->getDescendantIds((int) $managed_group->id());
        if (in_array($resolved_id, $scope_ids, TRUE)) {
          return AccessResult::allowed()
            ->addCacheContexts(['user', 'url.path'])
            ->addCacheTags([$membership_cache_tag]);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles', 'url.path'])
      ->addCacheTags([$membership_cache_tag]);
  }

  /**
   * Lists public documents for the selected admin workspace.
   */
  public function listDocuments(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $organisation_id = $this->resolveOrganisationId($jurisdiction, $request->query->get('organisation'));
    if ($request->query->has('organisation') && $organisation_id === NULL) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND, 'organisation');
    }

    $nodes = $this->loadDocumentNodes((int) $jurisdiction->id(), $organisation_id);
    $data = array_map(fn (NodeInterface $node): array => $this->serializeDocument($node), $nodes);
    usort($data, static function (array $a, array $b): int {
      $order = ((int) $a['document']['order']) <=> ((int) $b['document']['order']);
      return $order !== 0 ? $order : strnatcasecmp((string) $a['title'], (string) $b['title']);
    });

    return $this->privateResponse([
      'data' => $data,
      'meta' => [
        'workspace' => [
          'jurisdictionInternalId' => (int) $jurisdiction->id(),
          'organisationInternalId' => $organisation_id,
        ],
      ],
    ]);
  }

  /**
   * Creates a public document in the requested jurisdiction.
   */
  public function createDocument(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $payload = $this->requestPayload($request);
    $title = $this->requiredString($payload['title'] ?? NULL, 255);
    if ($title === NULL) {
      return $this->errorResponse('Title is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'title');
    }

    $organisation_id = $this->resolveOrganisationInput($jurisdiction, $payload, $request);
    if ($organisation_id === FALSE) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND, 'organisation');
    }

    $form_result = $this->resolveFormInput($jurisdiction, $organisation_id, $payload);
    if ($form_result === FALSE) {
      return $this->errorResponse('Form not found.', Response::HTTP_NOT_FOUND, 'form');
    }

    if (!$this->hasValidPlacement($payload)) {
      return $this->errorResponse('Unknown placement.', Response::HTTP_UNPROCESSABLE_ENTITY, 'placement');
    }
    $file = $this->createFileFromPayload($payload['file'] ?? NULL);
    if ($file instanceof JsonResponse) {
      return $file;
    }

    $node = $this->entityTypeManager()->getStorage('node')->create([
      'type' => 'public_document',
      'title' => $title,
      'status' => $this->boolValue($payload['status'] ?? TRUE),
      'uid' => (int) $this->currentUser()->id(),
    ]);

    $field_error = $this->applyDocumentFields($node, $jurisdiction, $organisation_id, $form_result, $payload, $file, TRUE);
    if ($field_error instanceof JsonResponse) {
      $this->deleteUploadedFile($file);
      return $field_error;
    }

    $validation_error = $this->validateDocument($node);
    if ($validation_error instanceof JsonResponse) {
      $this->deleteUploadedFile($file);
      return $validation_error;
    }

    try {
      $this->markFilePermanent($file);
      $node->setRevisionLogMessage('Created through beantragt.ai public document editor.');
      $node->save();
    }
    catch (\Throwable) {
      $this->deleteUploadedFile($file);
      return $this->errorResponse('Document could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return $this->privateResponse(['data' => $this->serializeDocument($node)], Response::HTTP_CREATED);
  }

  /**
   * Updates a public document by UUID.
   */
  public function updateDocument(Request $request, string $jurisdiction_id, string $document_uuid): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $node = $this->loadDocumentByUuid($document_uuid);
    if (!$node || !$this->nodeBelongsToJurisdiction($node, (int) $jurisdiction->id())) {
      return $this->errorResponse('Document not found.', Response::HTTP_NOT_FOUND);
    }

    $payload = $this->requestPayload($request);
    if (array_key_exists('title', $payload)) {
      $title = $this->requiredString($payload['title'], 255);
      if ($title === NULL) {
        return $this->errorResponse('Title is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'title');
      }
      $node->setTitle($title);
    }
    if (array_key_exists('status', $payload)) {
      $this->boolValue($payload['status']) ? $node->setPublished() : $node->setUnpublished();
    }

    $organisation_id = $this->resolveOrganisationInput($jurisdiction, $payload, $request, FALSE);
    if ($organisation_id === FALSE) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND, 'organisation');
    }

    $form_result = $this->resolveFormInput($jurisdiction, $organisation_id, $payload, FALSE);
    if ($form_result === FALSE) {
      return $this->errorResponse('Form not found.', Response::HTTP_NOT_FOUND, 'form');
    }

    if (!$this->hasValidPlacement($payload)) {
      return $this->errorResponse('Unknown placement.', Response::HTTP_UNPROCESSABLE_ENTITY, 'placement');
    }
    $file = NULL;
    if (array_key_exists('file', $payload)) {
      $file = $this->createFileFromPayload($payload['file']);
      if ($file instanceof JsonResponse) {
        return $file;
      }
    }

    $field_error = $this->applyDocumentFields($node, $jurisdiction, $organisation_id, $form_result, $payload, $file, FALSE);
    if ($field_error instanceof JsonResponse) {
      $this->deleteUploadedFile($file);
      return $field_error;
    }

    $validation_error = $this->validateDocument($node);
    if ($validation_error instanceof JsonResponse) {
      $this->deleteUploadedFile($file);
      return $validation_error;
    }

    try {
      if ($file instanceof FileInterface) {
        $this->markFilePermanent($file);
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $this->currentUser()->id());
      $node->setRevisionCreationTime((int) $request->server->get('REQUEST_TIME', time()));
      $node->setRevisionLogMessage('Updated through beantragt.ai public document editor.');
      $node->save();
    }
    catch (\Throwable) {
      $this->deleteUploadedFile($file);
      return $this->errorResponse('Document could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return $this->privateResponse(['data' => $this->serializeDocument($node)]);
  }

  /**
   * Deletes one form-bound public document after its schema references ended.
   */
  public function deleteDocument(Request $request, string $jurisdiction_id, string $document_uuid): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $node = $this->loadDocumentByUuid($document_uuid);
    if (!$node || !$this->nodeBelongsToJurisdiction($node, (int) $jurisdiction->id())) {
      return $this->errorResponse('Document not found.', Response::HTTP_NOT_FOUND);
    }

    $form_uuid = strtolower(trim((string) $request->query->get('form', '')));
    if (preg_match(self::UUID_PATTERN, $form_uuid) !== 1) {
      return $this->errorResponse('A valid bound form is required.', Response::HTTP_BAD_REQUEST, 'form');
    }

    $form = $this->referencedForm($node);
    if (!$form instanceof FormSchemaInterface || strtolower((string) $form->uuid()) !== $form_uuid) {
      return $this->errorResponse('Document not found.', Response::HTTP_NOT_FOUND);
    }
    $form_scope = $this->resolveFormScope($form);
    if (!$form_scope['jurisdiction'] instanceof GroupInterface
      || (int) $form_scope['jurisdiction']->id() !== (int) $jurisdiction->id()) {
      return $this->errorResponse('Document not found.', Response::HTTP_NOT_FOUND);
    }

    if ($this->schemaReferencesDocument($form, $document_uuid)) {
      return $this->errorResponse(
        'Document is still referenced by the form schema.',
        Response::HTTP_CONFLICT,
        'form',
      );
    }

    $file = $this->referencedFile($node);
    try {
      $node->delete();
    }
    catch (\Throwable) {
      return $this->errorResponse('Document could not be deleted.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $file_deleted = FALSE;
    $file_cleanup = 'not_applicable';
    if ($file instanceof FileInterface) {
      try {
        if ($this->fileUsage->listUsage($file) === []) {
          $file->delete();
          $file_deleted = TRUE;
          $file_cleanup = 'deleted';
        }
        else {
          $file_cleanup = 'retained_in_use';
        }
      }
      catch (\Throwable $error) {
        $file_cleanup = 'cleanup_failed';
        // The authoritative document is gone. A remaining private orphan is
        // safer than reporting failure after the irreversible node deletion.
        $this->logger->error(
          'Public document {document_uuid} was deleted, but private file {file_id} could not be cleaned up: {message}',
          [
            'document_uuid' => strtolower($document_uuid),
            'file_id' => (string) $file->id(),
            'message' => $error->getMessage(),
          ],
        );
      }
    }

    return $this->privateResponse([
      'data' => [
        'id' => strtolower($document_uuid),
        'deleted' => TRUE,
        'fileDeleted' => $file_deleted,
        'fileCleanup' => $file_cleanup,
      ],
    ]);
  }

  /**
   * Reports a UUID-specific document state for convergent delete handling.
   */
  public function documentStatus(Request $request, string $jurisdiction_id, string $document_uuid): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $form_uuid = strtolower(trim((string) $request->query->get('form', '')));
    if (preg_match(self::UUID_PATTERN, $form_uuid) !== 1) {
      return $this->errorResponse('A valid bound form is required.', Response::HTTP_BAD_REQUEST, 'form');
    }

    $node = $this->loadDocumentByUuid($document_uuid);
    if (
      !$node
      || !$this->nodeBelongsToJurisdiction($node, (int) $jurisdiction->id())
    ) {
      return $this->privateResponse([
        'data' => [
          'id' => strtolower($document_uuid),
          'state' => 'unavailable',
        ],
      ]);
    }

    $form = $this->referencedForm($node);
    $state = 'rebound';
    if ($form instanceof FormSchemaInterface && strtolower((string) $form->uuid()) === $form_uuid) {
      $form_scope = $this->resolveFormScope($form);
      $state = $form_scope['jurisdiction'] instanceof GroupInterface
        && (int) $form_scope['jurisdiction']->id() === (int) $jurisdiction->id()
          ? 'active'
          : 'rebound';
    }
    return $this->privateResponse([
      'data' => [
        'id' => strtolower($document_uuid),
        'state' => $state,
      ],
    ]);
  }

  /**
   * Streams the file of a public document for workspace administrators.
   *
   * The public file route only serves documents whose bound form is
   * published. Precheck proposals and editor previews must read leaflets
   * that are still attached to draft forms, which is why administrators get
   * their own download path guarded by the shared admin access check.
   */
  public function downloadDocumentFile(Request $request, string $jurisdiction_id, string $document_uuid): Response {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $node = $this->loadDocumentByUuid($document_uuid);
    if (!$node || !$this->nodeBelongsToJurisdiction($node, (int) $jurisdiction->id())) {
      return $this->errorResponse('Document not found.', Response::HTTP_NOT_FOUND);
    }

    $file = $this->referencedFile($node);
    $uri = $file instanceof FileInterface ? (string) $file->getFileUri() : '';
    if (!$file instanceof FileInterface || $uri === '' || !is_file($uri)) {
      return $this->errorResponse('Document not found.', Response::HTTP_NOT_FOUND);
    }

    $response = new BinaryFileResponse($uri, Response::HTTP_OK, [
      'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
      'Cache-Control' => 'private, no-store, max-age=0',
      'Pragma' => 'no-cache',
      'X-Content-Type-Options' => 'nosniff',
    ], FALSE);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $file->getFilename() ?: 'document',
    );

    return $response;
  }

  /**
   * Checks whether a group membership carries a group-admin role.
   */
  private function membershipGrantsAdmin(GroupMembershipInterface $membership): bool {
    return ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Loads a jurisdiction by numeric ID or slug.
   */
  private function loadJurisdictionGroup(string $jurisdiction_id): ?GroupInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return NULL;
    }
    $group = $this->entityTypeManager()->getStorage('group')->load($resolved_id);
    return $group instanceof GroupInterface && $group->bundle() === $this->getJurisdictionGroupType() ? $group : NULL;
  }

  /**
   * Loads editable document nodes for the selected scope.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Editable public document nodes.
   */
  private function loadDocumentNodes(int $jurisdiction_id, ?int $organisation_id): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'public_document')
      ->condition('field_jurisdiction.target_id', $jurisdiction_id)
      ->sort('field_content_weight', 'ASC')
      ->sort('title', 'ASC')
      ->range(0, 200);
    if ($organisation_id !== NULL) {
      $query->condition(
        $query->orConditionGroup()
          ->notExists('field_organisation')
          ->condition('field_organisation.target_id', $organisation_id)
      );
    }
    else {
      $query->notExists('field_organisation');
    }
    return array_values($storage->loadMultiple($query->execute()));
  }

  /**
   * Resolves an organisation slug or numeric ID inside the jurisdiction.
   */
  private function resolveOrganisationId(GroupInterface $jurisdiction, mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $raw = is_array($value) ? reset($value) : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage('group');
    $organisation = NULL;
    if (is_numeric($raw)) {
      $candidate = $storage->load((int) $raw);
      $organisation = $candidate instanceof GroupInterface ? $candidate : NULL;
    }
    else {
      $slug = strtolower(trim($raw));
      if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug)) {
        return NULL;
      }
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'org')
        ->condition('field_slug', $slug)
        ->condition('field_jurisdiction.target_id', (int) $jurisdiction->id())
        ->range(0, 1)
        ->execute();
      if ($ids !== []) {
        $candidate = $storage->load((string) reset($ids));
        $organisation = $candidate instanceof GroupInterface ? $candidate : NULL;
      }
    }

    if (!$organisation || $organisation->bundle() !== 'org' || !$organisation->hasField('field_jurisdiction')) {
      return NULL;
    }
    $target_id = (int) ($organisation->get('field_jurisdiction')->target_id ?? 0);
    return $target_id === (int) $jurisdiction->id() ? (int) $organisation->id() : NULL;
  }

  /**
   * Resolves organisation from body or query.
   *
   * @return int|false|null
   *   Organisation id, FALSE when invalid, NULL when global/no change.
   */
  private function resolveOrganisationInput(GroupInterface $jurisdiction, array $payload, Request $request, bool $create = TRUE): int|false|null {
    if (array_key_exists('organisation', $payload)) {
      $organisation = $payload['organisation'];
      if ($organisation === NULL || $organisation === '') {
        return NULL;
      }
      return $this->resolveOrganisationId($jurisdiction, $organisation) ?? FALSE;
    }
    if ($create && $request->query->has('organisation')) {
      return $this->resolveOrganisationId($jurisdiction, $request->query->get('organisation')) ?? FALSE;
    }
    return NULL;
  }

  /**
   * Resolves a form UUID inside the selected jurisdiction and organisation.
   *
   * @return \Drupal\beantragt_schema\Entity\FormSchemaInterface|false|null
   *   Form entity, FALSE when invalid, NULL when empty or unchanged.
   */
  private function resolveFormInput(GroupInterface $jurisdiction, int|false|null $organisation_id, array $payload, bool $create = TRUE): FormSchemaInterface|false|null {
    if (!array_key_exists('formId', $payload) && !array_key_exists('form', $payload)) {
      return $create ? NULL : NULL;
    }
    $raw = $payload['formId'] ?? $payload['form'];
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $uuid = strtolower(trim((string) (is_array($raw) ? reset($raw) : $raw)));
    if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
      return FALSE;
    }
    $matches = $this->entityTypeManager()->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    $form = reset($matches);
    if (!$form instanceof FormSchemaInterface) {
      return FALSE;
    }

    $scope = $this->resolveFormScope($form);
    if (!$scope['jurisdiction'] instanceof GroupInterface || (string) $scope['jurisdiction']->id() !== (string) $jurisdiction->id()) {
      return FALSE;
    }
    if ($organisation_id !== NULL) {
      return $scope['organisation'] instanceof GroupInterface && (int) $scope['organisation']->id() === $organisation_id ? $form : FALSE;
    }
    return $form;
  }

  /**
   * Checks the requested placement before an upload can be stored.
   */
  private function hasValidPlacement(array $payload): bool {
    $placement = $this->stringValue($payload['placement'] ?? 'form_downloads');
    return $placement !== NULL && isset(self::PLACEMENTS[$placement]);
  }

  /**
   * Applies public document fields.
   */
  private function applyDocumentFields(NodeInterface $node, GroupInterface $jurisdiction, int|false|null $organisation_id, FormSchemaInterface|false|null $form, array $payload, ?FileInterface $file, bool $create): ?JsonResponse {
    if ($node->hasField('field_jurisdiction')) {
      $node->set('field_jurisdiction', ['target_id' => $jurisdiction->id()]);
    }
    if ($node->hasField('field_organisation') && ($create || array_key_exists('organisation', $payload) || $organisation_id !== NULL)) {
      $node->set('field_organisation', $organisation_id ? ['target_id' => $organisation_id] : []);
    }
    if ($node->hasField('field_form_schema') && ($create || array_key_exists('formId', $payload) || array_key_exists('form', $payload))) {
      $node->set('field_form_schema', $form instanceof FormSchemaInterface ? ['target_id' => $form->id()] : []);
    }
    if ($node->hasField('field_content_placement') && ($create || array_key_exists('placement', $payload))) {
      $node->set('field_content_placement', $this->stringValue($payload['placement'] ?? 'form_downloads'));
    }
    if ($node->hasField('field_content_weight') && ($create || array_key_exists('order', $payload))) {
      $node->set('field_content_weight', $this->intValue($payload['order'] ?? 0));
    }
    if ($node->hasField('body') && ($create || array_key_exists('body', $payload))) {
      $body = $this->bodyString($payload['body'] ?? '');
      if ($body === FALSE) {
        return $this->errorResponse('Body is too large.', Response::HTTP_UNPROCESSABLE_ENTITY, 'body');
      }
      $node->set('body', [
        'value' => $body,
        'format' => 'basic_html',
      ]);
    }
    if ($file instanceof FileInterface && $node->hasField('field_public_document_file')) {
      $node->set('field_public_document_file', ['target_id' => $file->id()]);
    }

    return NULL;
  }

  /**
   * Creates a public file entity from a base64 JSON upload payload.
   */
  private function createFileFromPayload(mixed $payload): FileInterface|JsonResponse {
    if (!is_array($payload)) {
      return $this->errorResponse('File is required.', Response::HTTP_BAD_REQUEST, 'file');
    }
    $filename = $this->requiredString($payload['filename'] ?? NULL, 180);
    $data_base64 = is_string($payload['dataBase64'] ?? NULL) ? (string) $payload['dataBase64'] : '';
    if ($filename === NULL || $data_base64 === '') {
      return $this->errorResponse('File is required.', Response::HTTP_BAD_REQUEST, 'file');
    }
    if (strlen($data_base64) > self::MAX_FILE_BASE64_LENGTH) {
      return $this->errorResponse('File exceeds maximum size of 20 MB.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'file');
    }
    $data = base64_decode($data_base64, TRUE);
    if (!is_string($data) || $data === '') {
      return $this->errorResponse('File is invalid.', Response::HTTP_BAD_REQUEST, 'file');
    }
    if (strlen($data) > self::MAX_FILE_SIZE) {
      return $this->errorResponse('File exceeds maximum size of 20 MB.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'file');
    }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!isset(self::ALLOWED_EXTENSIONS[$extension])) {
      return $this->errorResponse('Only PDF, JPG, JPEG and PNG files are allowed.', Response::HTTP_UNPROCESSABLE_ENTITY, 'file');
    }
    $actual_mime = $this->detectMime($data);
    if ($actual_mime !== self::ALLOWED_EXTENSIONS[$extension]) {
      return $this->errorResponse('File content does not match the file extension.', Response::HTTP_UNPROCESSABLE_ENTITY, 'file');
    }
    $scan_result = $this->attachmentScanner->scan($data, $filename, $actual_mime);
    if ($scan_result->isQuarantined()) {
      return $this->errorResponse('File failed the security scan.', Response::HTTP_UNPROCESSABLE_ENTITY, 'file');
    }
    $directory = self::PUBLIC_DOCUMENT_DIRECTORY;
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return $this->errorResponse('File could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR, 'file');
    }

    try {
      $file = $this->fileRepository->writeData(
        $data,
        $directory . '/' . $this->sanitizeFilename($filename, $extension),
        FileExists::Rename,
      );
      $file->setOwnerId((int) $this->currentUser()->id());
      $file->save();
    }
    catch (\Throwable) {
      return $this->errorResponse('File could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR, 'file');
    }

    return $file;
  }

  /**
   * Marks a newly uploaded file permanent after the node passed validation.
   */
  private function markFilePermanent(FileInterface $file): void {
    $file->setPermanent();
    $file->setOwnerId((int) $this->currentUser()->id());
    $file->save();
  }

  /**
   * Deletes a file that was uploaded before a later validation failed.
   */
  private function deleteUploadedFile(?FileInterface $file): void {
    if (!$file instanceof FileInterface) {
      return;
    }
    try {
      $file->delete();
    }
    catch (\Throwable) {
      // Best-effort cleanup only. Preserve the validation error response.
    }
  }

  /**
   * Validates a public document before save.
   */
  private function validateDocument(NodeInterface $node): ?JsonResponse {
    $violations = $node->validate();
    if ($violations->count() === 0) {
      return NULL;
    }
    $violation = $violations[0];
    $field = trim((string) $violation->getPropertyPath()) ?: NULL;
    return $this->errorResponse((string) $violation->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $field);
  }

  /**
   * Loads one public document by UUID.
   */
  private function loadDocumentByUuid(string $uuid): ?NodeInterface {
    if (preg_match(self::UUID_PATTERN, strtolower($uuid)) !== 1) {
      return NULL;
    }
    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', strtolower($uuid))
      ->condition('type', 'public_document')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $node = $this->entityTypeManager()->getStorage('node')->load((string) reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Checks jurisdiction ownership for a public document node.
   */
  private function nodeBelongsToJurisdiction(NodeInterface $node, int $jurisdiction_id): bool {
    return $node->hasField('field_jurisdiction')
      && !$node->get('field_jurisdiction')->isEmpty()
      && (int) ($node->get('field_jurisdiction')->target_id ?? 0) === $jurisdiction_id;
  }

  /**
   * Serializes one public document for the admin editor.
   */
  private function serializeDocument(NodeInterface $node): array {
    $file = $this->referencedFile($node);
    return [
      'id' => $node->uuid(),
      'internalId' => (int) $node->id(),
      'kind' => 'public_document',
      'title' => $node->label(),
      'status' => $node->isPublished(),
      'langcode' => $node->language()->getId(),
      'changed' => $node->getChangedTime(),
      'revisionId' => (int) $node->getRevisionId(),
      'scope' => [
        'jurisdictionInternalId' => $this->targetId($node, 'field_jurisdiction'),
        'organisationInternalId' => $this->targetId($node, 'field_organisation'),
      ],
      'body' => AdminContent::bodyPayload($node),
      'document' => [
        'placement' => $this->scalarValue($node, 'field_content_placement') ?: 'form_downloads',
        'order' => $this->intField($node, 'field_content_weight', 0),
        'form' => $this->referencedFormProjection($node),
        'file' => $file instanceof FileInterface ? [
          'filename' => $file->getFilename(),
          'mimeType' => $file->getMimeType(),
          'size' => (int) $file->getSize(),
          'url' => $node->isPublished() ? $this->documentDownloadUrl($node) : NULL,
        ] : NULL,
      ],
    ];
  }

  /**
   * Builds a public download URL for a published document.
   */
  private function documentDownloadUrl(NodeInterface $node): ?string {
    $jurisdiction = $this->referencedGroup($node, 'field_jurisdiction');
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }
    $jurisdiction_slug = $this->groupSlug($jurisdiction);
    if ($jurisdiction_slug === NULL) {
      return NULL;
    }

    $query = [
      'placement' => $this->scalarValue($node, 'field_content_placement') ?: 'form_downloads',
    ];
    $organisation = $this->referencedGroup($node, 'field_organisation');
    if ($organisation instanceof GroupInterface) {
      $organisation_slug = $this->groupSlug($organisation);
      if ($organisation_slug !== NULL) {
        $query['organisation'] = $organisation_slug;
      }
    }
    $form = $this->referencedForm($node);
    if ($form instanceof FormSchemaInterface) {
      $query['form'] = $form->uuid();
    }

    return '/api/v1/jurisdictions/' . rawurlencode($jurisdiction_slug) . '/documents/' . rawurlencode((string) $node->uuid()) . '/file?' . UrlHelper::buildQuery($query);
  }

  /**
   * Parses a JSON request body into a plain array.
   */
  private function requestPayload(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Builds a compact projection of the referenced form schema.
   */
  private function referencedFormProjection(NodeInterface $node): ?array {
    $form = $this->referencedForm($node);
    if (!$form instanceof FormSchemaInterface) {
      return NULL;
    }
    return [
      'id' => $form->uuid(),
      'title' => $form->label(),
    ];
  }

  /**
   * Loads the form schema referenced by a public document.
   */
  private function referencedForm(NodeInterface $node): ?FormSchemaInterface {
    if (!$node->hasField('field_form_schema') || $node->get('field_form_schema')->isEmpty()) {
      return NULL;
    }
    $form = $node->get('field_form_schema')->entity ?? NULL;
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * Loads the file referenced by a public document.
   */
  private function referencedFile(NodeInterface $node): ?FileInterface {
    if (!$node->hasField('field_public_document_file') || $node->get('field_public_document_file')->isEmpty()) {
      return NULL;
    }
    $file = $node->get('field_public_document_file')->entity ?? NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Checks both context selection and precheck evidence for source references.
   */
  private function schemaReferencesDocument(FormSchemaInterface $form, string $document_uuid): bool {
    try {
      $schema = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      // Refuse deletion when the authoritative reference state is unreadable.
      return TRUE;
    }
    if (!is_array($schema) || array_is_list($schema)) {
      return TRUE;
    }

    $source_id = strtolower($document_uuid);
    $context_sources = $schema['contextSources'] ?? [];
    if (!is_array($context_sources) || !array_is_list($context_sources)) {
      return TRUE;
    }
    foreach ($context_sources as $source) {
      if (
        !is_array($source)
        || array_is_list($source)
        || !in_array($source['type'] ?? NULL, ['public_document', 'form_attachment'], TRUE)
        || !is_string($source['id'] ?? NULL)
        || preg_match(self::UUID_PATTERN, $source['id']) !== 1
      ) {
        return TRUE;
      }
      if (strtolower($source['id']) === $source_id) {
        return TRUE;
      }
    }
    if (!array_key_exists('precheckSchema', $schema)) {
      return FALSE;
    }
    $precheck = $schema['precheckSchema'];
    if (
      !is_array($precheck)
      || array_is_list($precheck)
      || ($precheck['version'] ?? NULL) !== 1
      || !array_key_exists('criteria', $precheck)
    ) {
      return TRUE;
    }
    $criteria = $precheck['criteria'];
    if (!is_array($criteria) || !array_is_list($criteria)) {
      return TRUE;
    }
    foreach ($criteria as $criterion) {
      if (
        !is_array($criterion)
        || array_is_list($criterion)
        || !array_key_exists('evidence', $criterion)
      ) {
        return TRUE;
      }
      $evidence = $criterion['evidence'];
      if (!is_array($evidence) || array_is_list($evidence)) {
        return TRUE;
      }
      $source_type = $evidence['sourceType'] ?? NULL;
      if (!in_array($source_type, ['form_source', 'context_source'], TRUE)) {
        return TRUE;
      }
      $evidence_id = $evidence['sourceId'] ?? NULL;
      if (
        (
          $source_type === 'context_source'
          && (
            !is_string($evidence_id)
            || preg_match(self::UUID_PATTERN, $evidence_id) !== 1
          )
        )
        || ($source_type === 'form_source' && $evidence_id !== NULL)
      ) {
        return TRUE;
      }
      if (is_string($evidence_id) && strtolower($evidence_id) === $source_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolves effective jurisdiction and organisation scope for a form.
   */
  private function resolveFormScope(FormSchemaInterface $form): array {
    $direct_jurisdiction = $this->referencedGroup($form, 'field_jurisdiction');
    $direct_organisation = $this->referencedGroup($form, 'field_organisation');
    $legacy_group = $this->referencedGroup($form, 'field_group');
    $organisation = $direct_organisation
      ?? ($legacy_group instanceof GroupInterface && $legacy_group->bundle() === 'org' ? $legacy_group : NULL);
    $jurisdiction = $direct_jurisdiction
      ?? ($organisation instanceof GroupInterface ? $this->referencedGroup($organisation, 'field_jurisdiction') : NULL)
      ?? ($legacy_group instanceof GroupInterface && $legacy_group->bundle() === 'jur' ? $legacy_group : NULL);
    return [
      'jurisdiction' => $jurisdiction instanceof GroupInterface ? $jurisdiction : NULL,
      'organisation' => $organisation instanceof GroupInterface ? $organisation : NULL,
    ];
  }

  /**
   * Loads a referenced group from a content entity field.
   */
  private function referencedGroup(EntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $group = $entity->get($field_name)->entity ?? NULL;
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Returns a valid public slug from a group.
   */
  private function groupSlug(GroupInterface $group): ?string {
    if (!$group->hasField('field_slug') || $group->get('field_slug')->isEmpty()) {
      return NULL;
    }
    $slug = strtolower(trim((string) ($group->get('field_slug')->value ?? '')));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Normalizes a submitted body value.
   */
  private function bodyString(mixed $value): string|false {
    $raw = is_array($value) ? ($value['value'] ?? '') : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return '';
    }
    $body = trim((string) $raw);
    return mb_strlen($body) > self::MAX_BODY_LENGTH ? FALSE : $body;
  }

  /**
   * Normalizes a required string value.
   */
  private function requiredString(mixed $value, int $max_length): ?string {
    $raw = is_array($value) ? reset($value) : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }
    $value = trim((string) $raw);
    return $value === '' || mb_strlen($value) > $max_length ? NULL : $value;
  }

  /**
   * Normalizes an optional string value.
   */
  private function stringValue(mixed $value): ?string {
    $raw = is_array($value) ? reset($value) : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }
    $value = strtolower(trim((string) $raw));
    return $value === '' ? NULL : $value;
  }

  /**
   * Normalizes a boolean-ish request value.
   */
  private function boolValue(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
    }
    return (bool) $value;
  }

  /**
   * Normalizes an integer-ish request value.
   */
  private function intValue(mixed $value): int {
    $raw = is_array($value) ? reset($value) : $value;
    return is_numeric($raw) ? (int) $raw : 0;
  }

  /**
   * Returns a target ID from an entity reference field.
   */
  private function targetId(NodeInterface $node, string $field_name): ?int {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    return (int) ($node->get($field_name)->target_id ?? 0) ?: NULL;
  }

  /**
   * Returns a scalar field value as a string.
   */
  private function scalarValue(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $item = $node->get($field_name)->first();
    $value = is_object($item) && isset($item->value) ? $item->value : ($node->get($field_name)->value ?? NULL);
    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * Returns an integer field value with a fallback.
   */
  private function intField(NodeInterface $node, string $field_name, int $fallback): int {
    $value = $this->scalarValue($node, $field_name);
    return $value === NULL ? $fallback : (int) $value;
  }

  /**
   * Detects a supported MIME type from file bytes.
   */
  private function detectMime(string $data): ?string {
    if (str_starts_with($data, '%PDF-')) {
      return 'application/pdf';
    }
    if (substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") {
      return 'image/png';
    }
    if (substr($data, 0, 3) === "\xFF\xD8\xFF") {
      return 'image/jpeg';
    }
    return NULL;
  }

  /**
   * Sanitizes an uploaded document filename.
   */
  private function sanitizeFilename(string $filename, string $extension): string {
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $basename = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $basename));
    $basename = trim($basename, '.-_');
    return ($basename !== '' ? substr($basename, 0, 120) : 'document') . '.' . $extension;
  }

  /**
   * Returns a non-cacheable JSON response.
   */
  private function privateResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * Returns a structured API error response.
   */
  private function errorResponse(string $message, int $status, ?string $field = NULL): JsonResponse {
    return $this->privateResponse([
      'error' => [
        'code' => $field ? 'validation.failed' : 'request.failed',
        'message' => $message,
        'field' => $field,
      ],
    ], $status);
  }

}
