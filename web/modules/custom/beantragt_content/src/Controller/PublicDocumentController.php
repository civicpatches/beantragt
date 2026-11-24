<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Controller;

use Drupal\beantragt_content\Content\PublicContent;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\beantragt_schema\Trait\PublicApiFloodTrait;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public API for editorial downloads such as form leaflets.
 */
final class PublicDocumentController extends ControllerBase {

  use PublicApiFloodTrait;

  use JurisdictionIdResolverTrait;

  /**
   * Max public document list requests per identifier per hour.
   *
   * One form view can make four document requests. At the previous limit of
   * 60 requests per hour, a visitor could be blocked after about 15 forms.
   */
  private const FLOOD_LIMIT = 600;

  /**
   * File downloads keep their own 60-per-hour budget, separate from lists.
   */
  private const FLOOD_DOWNLOAD_LIMIT = 60;

  /**
   * Flood window in seconds.
   */
  private const FLOOD_WINDOW = 3600;

  /**
   * Public document file directory.
   */
  private const PUBLIC_DOCUMENT_DIRECTORY = 'private://public_documents/';

  /**
   * Public document file MIME types.
   */
  private const PUBLIC_DOCUMENT_MIME_TYPES = [
    'application/pdf' => TRUE,
    'image/jpeg' => TRUE,
    'image/png' => TRUE,
  ];

  /**
   * Public route slug shape.
   */
  private const PUBLIC_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  /**
   * Public FormSchema UUID shape.
   */
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

  /**
   * Placements supported by the public content contract.
   */
  private const PLACEMENTS = [
    'org_start' => TRUE,
    'form_intro' => TRUE,
    'form_downloads' => TRUE,
    'form_sidebar' => TRUE,
    'submission_success' => TRUE,
  ];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly FloodInterface $flood,
    private readonly KillSwitch $pageCacheKillSwitch,
    private readonly LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('flood'),
      $container->get('page_cache_kill_switch'),
      $container->get('logger.factory')->get('beantragt_content'),
    );
  }

  /**
   * Lists public documents for a jurisdiction, organisation or form context.
   */
  public function listDocuments(Request $request, string $slug): JsonResponse {
    $flood_response = $this->floodResponse($request, 'beantragt_content.public_documents');
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $jurisdiction = $this->loadJurisdictionBySlug($slug);
    if (!$jurisdiction) {
      return $this->publicApiErrorResponse(
        'document.not_found',
        'Document not found.',
        Response::HTTP_NOT_FOUND,
        (new CacheableMetadata())
          ->addCacheTags(['group_list'])
          ->addCacheContexts(['url.path'])
          ->setCacheMaxAge(60),
      );
    }

    $organisation = $this->loadRequestedOrganisation($request, $jurisdiction);
    if ($this->hasOrganisationQuery($request) && !$organisation) {
      return $this->notFoundForScope($jurisdiction, NULL);
    }

    $form = $this->loadRequestedForm($request, $jurisdiction, $organisation);
    if ($this->hasFormQuery($request) && !$form) {
      return $this->notFoundForScope($jurisdiction, $organisation);
    }
    $effective_organisation = $organisation;
    if (!$effective_organisation instanceof GroupInterface && $form instanceof FormSchemaInterface) {
      $form_scope = $this->resolveFormScope($form);
      if ($form_scope['organisation'] instanceof GroupInterface) {
        $effective_organisation = $form_scope['organisation'];
      }
    }

    $placement = $this->requestedPlacement($request);
    if ($placement === FALSE) {
      return $this->publicApiErrorResponse(
        'document.invalid_query',
        'Invalid document query.',
        Response::HTTP_BAD_REQUEST,
        $this->baseMetadata($jurisdiction, $effective_organisation)->setCacheMaxAge(60),
      );
    }

    $nodes = $this->loadPublishedDocuments(
      (int) $jurisdiction->id(),
      $effective_organisation instanceof GroupInterface ? (int) $effective_organisation->id() : NULL,
      $form instanceof FormSchemaInterface ? (int) $form->id() : NULL,
      $placement,
    );

    $metadata = $this->baseMetadata($jurisdiction, $effective_organisation)
      ->addCacheTags(['node_list:public_document', 'file_list'])
      ->setCacheMaxAge(300);
    if ($form instanceof FormSchemaInterface) {
      $metadata->addCacheTags($form->getCacheTags());
    }

    $items = [];
    $seen = [];
    foreach ($nodes as $node) {
      $localized = PublicContent::publishedTranslation($node, PublicContent::requestedLangcode($request));
      $item = $this->documentPayload($localized, $slug, $effective_organisation, $form);
      if ($item === NULL || isset($seen[$item['id']])) {
        continue;
      }
      $seen[$item['id']] = TRUE;
      $items[] = $item;
      $metadata->addCacheTags($localized->getCacheTags());
    }

    usort($items, static function (array $a, array $b): int {
      $order_a = (int) $a['attributes']['order'];
      $order_b = (int) $b['attributes']['order'];
      if ($order_a !== $order_b) {
        return $order_a <=> $order_b;
      }
      return strcmp((string) $a['attributes']['title'], (string) $b['attributes']['title']);
    });

    $response = new CacheableJsonResponse([
      'data' => $items,
      'meta' => [
        'apiVersion' => '1.0',
        'resource' => 'publicDocumentList',
      ],
    ]);
    $response->addCacheableDependency($metadata);
    $response->setMaxAge($metadata->getCacheMaxAge());
    $response->setPublic();

    return $response;
  }

  /**
   * Downloads a published public document through the same scope contract.
   */
  public function downloadDocument(Request $request, string $slug, string $document_uuid): Response {
    $flood_response = $this->floodResponse($request, 'beantragt_content.public_document_download', self::FLOOD_DOWNLOAD_LIMIT);
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $jurisdiction = $this->loadJurisdictionBySlug($slug);
    if (!$jurisdiction) {
      throw new NotFoundHttpException();
    }

    $organisation = $this->loadRequestedOrganisation($request, $jurisdiction);
    if ($this->hasOrganisationQuery($request) && !$organisation) {
      throw new NotFoundHttpException();
    }

    $form = $this->loadRequestedForm($request, $jurisdiction, $organisation);
    if ($this->hasFormQuery($request) && !$form) {
      throw new NotFoundHttpException();
    }
    $effective_organisation = $organisation;
    if (!$effective_organisation instanceof GroupInterface && $form instanceof FormSchemaInterface) {
      $form_scope = $this->resolveFormScope($form);
      if ($form_scope['organisation'] instanceof GroupInterface) {
        $effective_organisation = $form_scope['organisation'];
      }
    }

    $placement = $this->requestedPlacement($request);
    if ($placement === FALSE || $placement === NULL) {
      throw new NotFoundHttpException();
    }

    $node = $this->loadPublishedDocumentByUuid($document_uuid);
    if (!$node instanceof NodeInterface || !$this->documentMatchesDownloadScope($node, $jurisdiction, $effective_organisation, $form, $placement)) {
      throw new NotFoundHttpException();
    }

    $file = $this->documentFile($node);
    if (!$this->isAllowedPublicDocumentFile($file)) {
      throw new NotFoundHttpException();
    }
    $uri = (string) $file->getFileUri();
    if ($uri === '' || !is_file($uri)) {
      throw new NotFoundHttpException();
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
   * Returns a public not-found response for an existing jurisdiction scope.
   */
  private function notFoundForScope(GroupInterface $jurisdiction, ?GroupInterface $organisation): CacheableJsonResponse {
    return $this->publicApiErrorResponse(
      'document.not_found',
      'Document not found.',
      Response::HTTP_NOT_FOUND,
      $this->baseMetadata($jurisdiction, $organisation)
        ->addCacheTags(['node_list:public_document', 'group_list', 'form_schema_list'])
        ->setCacheMaxAge(60),
    );
  }

  /**
   * Builds base cache metadata for document responses.
   */
  private function baseMetadata(GroupInterface $jurisdiction, ?GroupInterface $organisation): CacheableMetadata {
    $metadata = (new CacheableMetadata())
      ->addCacheTags($jurisdiction->getCacheTags())
      ->addCacheContexts($this->documentCacheContexts());
    if ($organisation instanceof GroupInterface) {
      $metadata->addCacheTags($organisation->getCacheTags());
    }
    return $metadata;
  }

  /**
   * Loads a published jurisdiction by public slug.
   */
  private function loadJurisdictionBySlug(string $slug): ?GroupInterface {
    $slug = strtolower(trim($slug));
    if ($slug === '' || ctype_digit($slug)) {
      return NULL;
    }

    $id = $this->resolveJurisdictionId($slug);
    if ($id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($id);

    return $group instanceof GroupInterface && $group->bundle() === $this->getJurisdictionGroupType()
      ? $group
      : NULL;
  }

  /**
   * Loads a requested published organisation within the jurisdiction.
   */
  private function loadRequestedOrganisation(Request $request, GroupInterface $jurisdiction): ?GroupInterface {
    $raw = $this->organisationQueryValue($request);
    if ($raw === NULL) {
      return NULL;
    }

    $raw = is_array($raw) ? reset($raw) : $raw;
    if (!is_string($raw)) {
      return NULL;
    }

    $slug = strtolower(trim($raw));
    if (preg_match(self::PUBLIC_SLUG_PATTERN, $slug) !== 1 || ctype_digit($slug)) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('status', 1)
      ->condition('field_slug', $slug)
      ->condition('field_jurisdiction.target_id', (int) $jurisdiction->id())
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }

    $group = $storage->load((string) reset($ids));

    return $group instanceof GroupInterface && $group->bundle() === 'org' ? $group : NULL;
  }

  /**
   * Loads and validates a requested public form.
   */
  private function loadRequestedForm(Request $request, GroupInterface $jurisdiction, ?GroupInterface $organisation): ?FormSchemaInterface {
    $raw = $this->formQueryValue($request);
    if ($raw === NULL) {
      return NULL;
    }

    $raw = is_array($raw) ? reset($raw) : $raw;
    if (!is_string($raw)) {
      return NULL;
    }
    $uuid = strtolower(trim($raw));
    if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
      return NULL;
    }

    $forms = $this->entityTypeManager()->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    $form = reset($forms);
    if (!$form instanceof FormSchemaInterface
      || !$form->isPublished()
      || $form->getSchemaStatus() !== 'published'
      || $form->getPublicationScope() !== 'internet') {
      return NULL;
    }

    return $this->formMatchesScope($form, $jurisdiction, $organisation) ? $form : NULL;
  }

  /**
   * Checks whether a public form belongs to the requested scope.
   */
  private function formMatchesScope(FormSchemaInterface $form, GroupInterface $jurisdiction, ?GroupInterface $organisation): bool {
    $scope = $this->resolveFormScope($form);
    if (!$scope['jurisdiction'] instanceof GroupInterface || (string) $scope['jurisdiction']->id() !== (string) $jurisdiction->id()) {
      return FALSE;
    }
    if (!$organisation instanceof GroupInterface || !$scope['organisation'] instanceof GroupInterface) {
      return TRUE;
    }
    return (string) $scope['organisation']->id() === (string) $organisation->id();
  }

  /**
   * Resolves public jurisdiction and organisation scope for a form.
   *
   * @return array{jurisdiction: \Drupal\group\Entity\GroupInterface|null, organisation: \Drupal\group\Entity\GroupInterface|null}
   *   Public form scope.
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
   * Loads newest published public documents in fallback order.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published public document nodes.
   */
  private function loadPublishedDocuments(int $jurisdiction_id, ?int $organisation_id, ?int $form_id, ?string $placement): array {
    $scopes = [];
    if ($organisation_id !== NULL && $form_id !== NULL) {
      $scopes = [[$organisation_id, $form_id], [$organisation_id, NULL], [NULL, $form_id], [NULL, NULL]];
    }
    elseif ($organisation_id !== NULL) {
      $scopes = [[$organisation_id, NULL], [NULL, NULL]];
    }
    elseif ($form_id !== NULL) {
      $scopes = [[NULL, $form_id], [NULL, NULL]];
    }
    else {
      $scopes = [[NULL, NULL]];
    }

    $nodes = [];
    foreach ($scopes as [$scope_organisation_id, $scope_form_id]) {
      array_push($nodes, ...$this->loadScopedPublishedDocuments($jurisdiction_id, $scope_organisation_id, $scope_form_id, $placement));
    }

    return $nodes;
  }

  /**
   * Loads published documents for one exact org/form scope.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published public document nodes.
   */
  private function loadScopedPublishedDocuments(int $jurisdiction_id, ?int $organisation_id, ?int $form_id, ?string $placement): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'public_document')
      ->condition('status', 1)
      ->condition('field_jurisdiction.target_id', $jurisdiction_id)
      ->sort('field_content_weight', 'ASC')
      ->sort('changed', 'DESC');
    if ($organisation_id === NULL) {
      $query->notExists('field_organisation');
    }
    else {
      $query->condition('field_organisation.target_id', $organisation_id);
    }
    if ($form_id === NULL) {
      $query->notExists('field_form_schema');
    }
    else {
      $query->condition('field_form_schema.target_id', $form_id);
    }
    if ($placement !== NULL) {
      $query->condition('field_content_placement', $placement);
    }

    $ids = $query->execute();
    if (!$ids) {
      return [];
    }

    /** @var array<int|string, \Drupal\node\NodeInterface> $loaded */
    $loaded = $storage->loadMultiple($ids);
    $nodes = [];
    foreach ($ids as $id) {
      $node = $loaded[$id] ?? $loaded[(string) $id] ?? NULL;
      if ($node instanceof NodeInterface) {
        $nodes[] = $node;
      }
    }

    return $nodes;
  }

  /**
   * Serializes one public document.
   */
  private function documentPayload(NodeInterface $node, string $slug, ?GroupInterface $organisation, ?FormSchemaInterface $form): ?array {
    $placement = $this->scalarValue($node, 'field_content_placement') ?? 'form_downloads';
    if ($placement === 'footer') {
      return NULL;
    }
    $file = $this->documentFile($node);
    if (!$this->isAllowedPublicDocumentFile($file)) {
      return NULL;
    }
    $uri = (string) $file->getFileUri();
    $filename = basename(substr($uri, strlen(self::PUBLIC_DOCUMENT_DIRECTORY)));

    return [
      'type' => 'publicDocument',
      'id' => $node->uuid(),
      'attributes' => [
        'title' => $node->label(),
        'description' => PublicContent::bodyPayload($node),
        'placement' => $placement,
        'order' => $this->intValue($node, 'field_content_weight'),
        'file' => [
          'filename' => $filename,
          'mimeType' => $file->getMimeType(),
          'size' => (int) $file->getSize(),
          'url' => $this->documentDownloadUrl($node, $slug, $organisation, $form),
        ],
        'form' => $this->referencedPublicFormProjection($node),
        'langcode' => $node->language()->getId(),
        'revisionId' => (int) $node->getRevisionId(),
        'changed' => $node->getChangedTime(),
      ],
    ];
  }

  /**
   * Builds a scoped public download route for one document.
   */
  private function documentDownloadUrl(NodeInterface $node, string $slug, ?GroupInterface $organisation, ?FormSchemaInterface $form): string {
    $query = [
      'placement' => $this->scalarValue($node, 'field_content_placement') ?? 'form_downloads',
    ];
    if (!$organisation instanceof GroupInterface && $form instanceof FormSchemaInterface) {
      $form_scope = $this->resolveFormScope($form);
      $organisation = $form_scope['organisation'] instanceof GroupInterface ? $form_scope['organisation'] : NULL;
    }
    if ($organisation instanceof GroupInterface) {
      $organisation_slug = $this->groupSlug($organisation);
      if ($organisation_slug !== NULL) {
        $query['organisation'] = $organisation_slug;
      }
    }
    if ($form instanceof FormSchemaInterface) {
      $query['form'] = $form->uuid();
    }

    return '/api/v1/jurisdictions/' . rawurlencode($slug) . '/documents/' . rawurlencode((string) $node->uuid()) . '/file?' . UrlHelper::buildQuery($query);
  }

  /**
   * Loads one published public document by UUID.
   */
  private function loadPublishedDocumentByUuid(string $uuid): ?NodeInterface {
    $uuid = strtolower(trim($uuid));
    if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
      return NULL;
    }

    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'public_document')
      ->condition('status', 1)
      ->condition('uuid', $uuid)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }

    $node = $this->entityTypeManager()->getStorage('node')->load((string) reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Checks the requested download context against document scope fields.
   */
  private function documentMatchesDownloadScope(NodeInterface $node, GroupInterface $jurisdiction, ?GroupInterface $organisation, ?FormSchemaInterface $form, string $placement): bool {
    if ($this->targetId($node, 'field_jurisdiction') !== (int) $jurisdiction->id()) {
      return FALSE;
    }

    $document_organisation_id = $this->targetId($node, 'field_organisation');
    if ($document_organisation_id !== NULL
      && (!$organisation instanceof GroupInterface || $document_organisation_id !== (int) $organisation->id())) {
      return FALSE;
    }

    $document_form_id = $this->targetId($node, 'field_form_schema');
    if ($document_form_id !== NULL
      && (!$form instanceof FormSchemaInterface || $document_form_id !== (int) $form->id())) {
      return FALSE;
    }

    return ($this->scalarValue($node, 'field_content_placement') ?? 'form_downloads') === $placement;
  }

  /**
   * Returns the document file referenced by a public document node.
   */
  private function documentFile(NodeInterface $node): ?FileInterface {
    if (!$node->hasField('field_public_document_file') || $node->get('field_public_document_file')->isEmpty()) {
      return NULL;
    }

    $file = $node->get('field_public_document_file')->entity ?? NULL;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Checks whether a referenced file is safe for this public resource.
   */
  private function isAllowedPublicDocumentFile(?FileInterface $file): bool {
    if (!$file instanceof FileInterface) {
      return FALSE;
    }

    $uri = $file->getFileUri();
    if (!is_string($uri) || !str_starts_with($uri, self::PUBLIC_DOCUMENT_DIRECTORY)) {
      return FALSE;
    }
    $relative = substr($uri, strlen(self::PUBLIC_DOCUMENT_DIRECTORY));
    if ($relative === ''
      || str_contains($relative, '/')
      || str_contains($relative, '\\')
      || str_contains($relative, '..')
      || str_contains($relative, "\0")) {
      return FALSE;
    }

    $mime_type = $file->getMimeType();
    if (!is_string($mime_type) || !isset(self::PUBLIC_DOCUMENT_MIME_TYPES[$mime_type])) {
      return FALSE;
    }

    return preg_match('/\.(?:pdf|jpe?g|png)$/i', $relative) === 1;
  }

  /**
   * Projects the optional referenced form using public identifiers.
   */
  private function referencedPublicFormProjection(NodeInterface $node): ?array {
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
   * Returns the optional referenced form.
   */
  private function referencedForm(NodeInterface $node): ?FormSchemaInterface {
    if (!$node->hasField('field_form_schema') || $node->get('field_form_schema')->isEmpty()) {
      return NULL;
    }

    $form = $node->get('field_form_schema')->entity ?? NULL;
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * Returns a referenced group if present.
   */
  private function referencedGroup(EntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }

    $group = $entity->get($field_name)->entity ?? NULL;
    return $group instanceof GroupInterface && $group->isPublished() ? $group : NULL;
  }

  /**
   * Reads a target ID when an entity reference is set.
   */
  private function targetId(NodeInterface $node, string $field_name): ?int {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    return (int) ($node->get($field_name)->target_id ?? 0) ?: NULL;
  }

  /**
   * Reads a public group slug.
   */
  private function groupSlug(GroupInterface $group): ?string {
    if (!$group->hasField('field_slug') || $group->get('field_slug')->isEmpty()) {
      return NULL;
    }

    $slug = strtolower(trim((string) ($group->get('field_slug')->value ?? '')));
    return preg_match(self::PUBLIC_SLUG_PATTERN, $slug) === 1 ? $slug : NULL;
  }

  /**
   * Reads a scalar field value.
   */
  private function scalarValue(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }

    $item = $node->get($field_name)->first();
    $value = is_object($item) && isset($item->value)
      ? $item->value
      : ($node->get($field_name)->value ?? NULL);

    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * Reads an integer field value.
   */
  private function intValue(NodeInterface $node, string $field_name): int {
    $value = $this->scalarValue($node, $field_name);
    return $value === NULL ? 0 : (int) $value;
  }

  /**
   * Returns a normalized placement, FALSE when invalid.
   */
  private function requestedPlacement(Request $request): string|false|null {
    $query = $request->query->all();
    $raw = $query['placement'] ?? NULL;
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $raw = is_array($raw) ? reset($raw) : $raw;
    if (!is_string($raw)) {
      return FALSE;
    }

    $placement = strtolower(trim($raw));
    return isset(self::PLACEMENTS[$placement]) ? $placement : FALSE;
  }

  /**
   * Checks whether the request explicitly asked for an organisation scope.
   */
  private function hasOrganisationQuery(Request $request): bool {
    return $request->query->has('organisation')
      || $request->query->has('organization')
      || $request->query->has('org');
  }

  /**
   * Checks whether the request explicitly asked for a form scope.
   */
  private function hasFormQuery(Request $request): bool {
    return $request->query->has('form')
      || $request->query->has('formSchema')
      || $request->query->has('form_schema');
  }

  /**
   * Returns the first organisation query alias.
   */
  private function organisationQueryValue(Request $request): mixed {
    foreach (['organisation', 'organization', 'org'] as $key) {
      if ($request->query->has($key)) {
        return $request->query->get($key);
      }
    }

    return NULL;
  }

  /**
   * Returns the first form query alias.
   */
  private function formQueryValue(Request $request): mixed {
    foreach (['form', 'formSchema', 'form_schema'] as $key) {
      if ($request->query->has($key)) {
        return $request->query->get($key);
      }
    }

    return NULL;
  }

  /**
   * Cache contexts used by public document responses.
   *
   * @return string[]
   *   Cache contexts.
   */
  private function documentCacheContexts(): array {
    return [
      'url.path',
      'url.query_args:lang',
      'url.query_args:organisation',
      'url.query_args:organization',
      'url.query_args:org',
      'url.query_args:form',
      'url.query_args:formSchema',
      'url.query_args:form_schema',
      'url.query_args:placement',
    ];
  }

  /**
   * Builds a v1 public API error response.
   */
  private function publicApiErrorResponse(string $code, string $message, int $status, CacheableMetadata $cache_metadata): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
      'meta' => [
        'apiVersion' => '1.0',
      ],
    ], $status);
    $response->addCacheableDependency($cache_metadata);
    $response->setMaxAge($cache_metadata->getCacheMaxAge());
    $response->setPublic();
    return $response;
  }

}
