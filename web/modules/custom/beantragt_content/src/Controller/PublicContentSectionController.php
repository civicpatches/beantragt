<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Controller;

use Drupal\beantragt_content\Content\PublicContent;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\beantragt_schema\Trait\PublicApiFloodTrait;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API for editorial HTML sections assigned to workspaces or forms.
 */
final class PublicContentSectionController extends ControllerBase {

  use PublicApiFloodTrait;

  use JurisdictionIdResolverTrait;

  /**
   * Max public section API requests per identifier per hour.
   *
   * A form view can make three section requests and four document requests
   * against separate limits. At 60 requests per endpoint per hour, a visitor
   * could reach the document limit after about 15 form views.
   */
  private const FLOOD_LIMIT = 600;

  /**
   * Flood window in seconds.
   */
  private const FLOOD_WINDOW = 3600;

  /**
   * Public route slug shape.
   */
  private const PUBLIC_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  /**
   * Public FormSchema UUID shape.
   */
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

  /**
   * Placements supported by public HTML content sections.
   */
  private const PLACEMENTS = [
    'org_start' => TRUE,
    'form_intro' => TRUE,
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
   * Lists public content sections for a jurisdiction, organisation or form.
   */
  public function listSections(Request $request, string $slug): JsonResponse {
    $flood_response = $this->floodResponse($request, 'beantragt_content.public_sections');
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $jurisdiction = $this->loadJurisdictionBySlug($slug);
    if (!$jurisdiction) {
      return $this->publicApiErrorResponse(
        'section.not_found',
        'Content section not found.',
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
        'section.invalid_query',
        'Invalid content section query.',
        Response::HTTP_BAD_REQUEST,
        $this->baseMetadata($jurisdiction, $effective_organisation)->setCacheMaxAge(60),
      );
    }

    $nodes = $this->loadPublishedSections(
      (int) $jurisdiction->id(),
      $effective_organisation instanceof GroupInterface ? (int) $effective_organisation->id() : NULL,
      $form instanceof FormSchemaInterface ? (int) $form->id() : NULL,
      $placement,
    );

    $metadata = $this->baseMetadata($jurisdiction, $effective_organisation)
      ->addCacheTags(['node_list:content_section', 'form_schema_list'])
      ->setCacheMaxAge(300);
    if ($form instanceof FormSchemaInterface) {
      $metadata->addCacheTags($form->getCacheTags());
    }

    $items = [];
    $seen = [];
    foreach ($nodes as $node) {
      $localized = PublicContent::publishedTranslation($node, PublicContent::requestedLangcode($request));
      $item = $this->sectionPayload($localized);
      if ($item['attributes']['placement'] === 'footer' || isset($seen[$item['id']])) {
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
        'resource' => 'contentSectionList',
      ],
    ]);
    $response->addCacheableDependency($metadata);
    $response->setMaxAge($metadata->getCacheMaxAge());
    $response->setPublic();

    return $response;
  }

  /**
   * Returns a public not-found response for an existing jurisdiction scope.
   */
  private function notFoundForScope(GroupInterface $jurisdiction, ?GroupInterface $organisation): CacheableJsonResponse {
    return $this->publicApiErrorResponse(
      'section.not_found',
      'Content section not found.',
      Response::HTTP_NOT_FOUND,
      $this->baseMetadata($jurisdiction, $organisation)
        ->addCacheTags(['node_list:content_section', 'group_list', 'form_schema_list'])
        ->setCacheMaxAge(60),
    );
  }

  /**
   * Builds base cache metadata for section responses.
   */
  private function baseMetadata(GroupInterface $jurisdiction, ?GroupInterface $organisation): CacheableMetadata {
    $metadata = (new CacheableMetadata())
      ->addCacheTags($jurisdiction->getCacheTags())
      ->addCacheContexts($this->sectionCacheContexts());
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
   * Loads newest published content sections in fallback order.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published section nodes.
   */
  private function loadPublishedSections(int $jurisdiction_id, ?int $organisation_id, ?int $form_id, ?string $placement): array {
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
      array_push($nodes, ...$this->loadScopedPublishedSections($jurisdiction_id, $scope_organisation_id, $scope_form_id, $placement));
    }

    return $nodes;
  }

  /**
   * Loads published sections for one exact org/form scope.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published section nodes.
   */
  private function loadScopedPublishedSections(int $jurisdiction_id, ?int $organisation_id, ?int $form_id, ?string $placement): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'content_section')
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
   * Serializes one public content section.
   */
  private function sectionPayload(NodeInterface $node): array {
    return [
      'type' => 'contentSection',
      'id' => $node->uuid(),
      'attributes' => [
        'title' => $node->label(),
        'body' => PublicContent::bodyPayload($node),
        'placement' => $this->scalarValue($node, 'field_content_placement') ?? 'form_intro',
        'order' => $this->intValue($node, 'field_content_weight'),
        'form' => $this->referencedPublicFormProjection($node),
        'langcode' => $node->language()->getId(),
        'changed' => $node->getChangedTime(),
      ],
    ];
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
   * Cache contexts used by public section responses.
   *
   * @return string[]
   *   Cache contexts.
   */
  private function sectionCacheContexts(): array {
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
