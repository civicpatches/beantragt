<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Controller;

use Drupal\beantragt_content\Content\PublicContent;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API for jurisdiction-scoped content pages.
 */
final class ContentPageController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Public page route slug shape.
   */
  private const PUBLIC_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Returns published page route metadata for a jurisdiction.
   */
  public function listPages(Request $request, string $slug): CacheableJsonResponse {
    $jurisdiction = $this->loadJurisdictionBySlug($slug);
    if (!$jurisdiction) {
      $metadata = (new CacheableMetadata())
        ->addCacheTags(['group_list'])
        ->addCacheContexts(['url.path'])
        ->setCacheMaxAge(60);
      return $this->publicApiErrorResponse(
        'page.not_found',
        'Page not found.',
        Response::HTTP_NOT_FOUND,
        $metadata,
      );
    }

    $organisation = $this->loadRequestedOrganisation($request, $jurisdiction);
    if ($this->hasOrganisationQuery($request) && !$organisation) {
      $metadata = (new CacheableMetadata())
        ->addCacheTags($jurisdiction->getCacheTags())
        ->addCacheTags(['group_list'])
        ->addCacheContexts($this->pageCacheContexts())
        ->setCacheMaxAge(60);
      return $this->publicApiErrorResponse(
        'page.not_found',
        'Page not found.',
        Response::HTTP_NOT_FOUND,
        $metadata,
      );
    }

    $langcode = PublicContent::requestedLangcode($request);
    $nodes = $this->loadPublishedPages(
      (int) $jurisdiction->id(),
      $organisation instanceof GroupInterface ? (int) $organisation->id() : NULL,
    );
    $items = [];
    $seen = [];
    $metadata = (new CacheableMetadata())
      ->addCacheTags($jurisdiction->getCacheTags())
      ->addCacheTags(['node_list:page'])
      ->addCacheContexts($this->pageCacheContexts())
      ->setCacheMaxAge(300);
    if ($organisation instanceof GroupInterface) {
      $metadata->addCacheTags($organisation->getCacheTags());
    }

    foreach ($nodes as $node) {
      $localized = PublicContent::publishedTranslation($node, $langcode);
      $page_slug = $this->pageSlug($localized);
      if ($page_slug === NULL) {
        continue;
      }
      if ($this->listItemSeen($seen, $localized, $page_slug)) {
        continue;
      }

      $items[] = [
        'type' => 'page',
        'id' => $localized->uuid(),
        'attributes' => [
          'key' => $this->pageKey($localized),
          'title' => $localized->label(),
          'langcode' => $localized->language()->getId(),
          'changed' => $localized->getChangedTime(),
          'slug' => $page_slug,
          'path' => '/pages/' . $page_slug,
          'footerVisible' => $this->footerVisible($localized),
          'order' => $this->pageWeight($localized),
        ],
      ];

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
        'resource' => 'contentPageList',
      ],
    ]);
    $response->addCacheableDependency($metadata);
    $response->setMaxAge($metadata->getCacheMaxAge());
    $response->setPublic();

    return $response;
  }

  /**
   * Returns one published page by jurisdiction slug and page key or slug.
   */
  public function getPage(Request $request, string $slug, string $page_key): CacheableJsonResponse {
    $jurisdiction = $this->loadJurisdictionBySlug($slug);
    if (!$jurisdiction) {
      $metadata = (new CacheableMetadata())
        ->addCacheTags(['group_list'])
        ->addCacheContexts(['url.path'])
        ->setCacheMaxAge(60);
      return $this->publicApiErrorResponse(
        'page.not_found',
        'Page not found.',
        Response::HTTP_NOT_FOUND,
        $metadata,
      );
    }

    $organisation = $this->loadRequestedOrganisation($request, $jurisdiction);
    if ($this->hasOrganisationQuery($request) && !$organisation) {
      $metadata = (new CacheableMetadata())
        ->addCacheTags($jurisdiction->getCacheTags())
        ->addCacheTags(['group_list', 'node_list:page'])
        ->addCacheContexts($this->pageCacheContexts())
        ->setCacheMaxAge(60);
      return $this->publicApiErrorResponse(
        'page.not_found',
        'Page not found.',
        Response::HTTP_NOT_FOUND,
        $metadata,
      );
    }

    $node = $this->loadPublishedPage(
      (int) $jurisdiction->id(),
      $page_key,
      $organisation instanceof GroupInterface ? (int) $organisation->id() : NULL,
    );
    if (!$node) {
      $metadata = (new CacheableMetadata())
        ->addCacheTags($jurisdiction->getCacheTags())
        ->addCacheTags(['node_list:page'])
        ->addCacheContexts($this->pageCacheContexts())
        ->setCacheMaxAge(60);
      if ($organisation instanceof GroupInterface) {
        $metadata->addCacheTags($organisation->getCacheTags());
      }
      return $this->publicApiErrorResponse(
        'page.not_found',
        'Page not found.',
        Response::HTTP_NOT_FOUND,
        $metadata,
      );
    }

    $node = PublicContent::publishedTranslation($node, PublicContent::requestedLangcode($request));
    $page_slug = $this->pageSlug($node);

    $response = new CacheableJsonResponse([
      'data' => [
        'type' => 'page',
        'id' => $node->uuid(),
        'attributes' => [
          'key' => $this->pageKey($node),
          'slug' => $page_slug,
          'path' => $page_slug ? '/pages/' . $page_slug : NULL,
          'title' => $node->label(),
          'body' => PublicContent::bodyPayload($node),
          'langcode' => $node->language()->getId(),
          'changed' => $node->getChangedTime(),
        ],
      ],
      'meta' => [
        'apiVersion' => '1.0',
        'resource' => 'contentPage',
      ],
    ]);

    $metadata = (new CacheableMetadata())
      ->addCacheTags($jurisdiction->getCacheTags())
      ->addCacheTags($node->getCacheTags())
      ->addCacheContexts($this->pageCacheContexts())
      ->setCacheMaxAge(300);
    if ($organisation instanceof GroupInterface) {
      $metadata->addCacheTags($organisation->getCacheTags());
    }
    $response->addCacheableDependency($metadata);
    $response->setMaxAge($metadata->getCacheMaxAge());
    $response->setPublic();

    return $response;
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
   * Checks whether the request explicitly asked for an organisation scope.
   */
  private function hasOrganisationQuery(Request $request): bool {
    return $request->query->has('organisation')
      || $request->query->has('organization')
      || $request->query->has('org');
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
   * Cache contexts used by public page responses.
   *
   * @return string[]
   *   Cache contexts.
   */
  private function pageCacheContexts(): array {
    return [
      'url.path',
      'url.query_args:lang',
      'url.query_args:organisation',
      'url.query_args:organization',
      'url.query_args:org',
    ];
  }

  /**
   * Loads newest published public pages for a jurisdiction.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published page nodes.
   */
  private function loadPublishedPages(int $jurisdiction_id, ?int $organisation_id): array {
    if ($organisation_id === NULL) {
      return $this->loadScopedPublishedPages($jurisdiction_id, NULL);
    }

    return array_merge(
      $this->loadScopedPublishedPages($jurisdiction_id, $organisation_id),
      $this->loadScopedPublishedPages($jurisdiction_id, NULL),
    );
  }

  /**
   * Loads published pages for one exact organisation scope.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published page nodes.
   */
  private function loadScopedPublishedPages(int $jurisdiction_id, ?int $organisation_id): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->condition('status', 1)
      ->condition('field_jurisdiction.target_id', $jurisdiction_id)
      ->sort('field_page_weight', 'ASC')
      ->sort('changed', 'DESC');
    if ($organisation_id === NULL) {
      $query->notExists('field_organisation');
    }
    else {
      $query->condition('field_organisation.target_id', $organisation_id);
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
      if ($node === NULL) {
        continue;
      }
      $nodes[] = $node;
    }

    return $nodes;
  }

  /**
   * Loads the newest published page for a jurisdiction by page key or slug.
   */
  private function loadPublishedPage(int $jurisdiction_id, string $identifier, ?int $organisation_id): ?NodeInterface {
    $identifier = strtolower(trim($identifier));
    $scopes = $organisation_id === NULL ? [NULL] : [$organisation_id, NULL];
    foreach ($scopes as $scope_organisation_id) {
      foreach ($this->loadScopedPublishedPages($jurisdiction_id, $scope_organisation_id) as $node) {
        if ($this->pageKey($node) === $identifier || $this->pageSlug($node) === $identifier) {
          return $node;
        }

        foreach (array_keys(PublicContent::SUPPORTED_LOCALES) as $langcode) {
          if (!$node->hasTranslation($langcode)) {
            continue;
          }
          $translation = $node->getTranslation($langcode);
          if ($translation->isPublished() && $this->pageSlug($translation) === $identifier) {
            return $translation;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Marks list identities as seen and returns TRUE when an override exists.
   *
   * @param array<string, bool> $seen
   *   Seen route identities.
   * @param \Drupal\node\NodeInterface $node
   *   The page to check.
   * @param string $slug
   *   Public slug of the page.
   */
  private function listItemSeen(array &$seen, NodeInterface $node, string $slug): bool {
    $identities = ['slug:' . $slug];
    $key = $this->pageKey($node);
    if ($key !== NULL && $key !== 'content') {
      $identities[] = 'key:' . $key;
    }

    foreach ($identities as $identity) {
      if (isset($seen[$identity])) {
        return TRUE;
      }
    }

    foreach ($identities as $identity) {
      $seen[$identity] = TRUE;
    }

    return FALSE;
  }

  /**
   * Returns the stable public key for a page node.
   */
  private function pageKey(NodeInterface $node): ?string {
    if (!$node->hasField('field_page_key')) {
      return NULL;
    }

    $field = $node->get('field_page_key');
    if ($field->isEmpty()) {
      return NULL;
    }

    $item = $field->first();
    $value = is_object($item) && isset($item->value)
      ? $item->value
      : ($field->value ?? '');
    $key = strtolower(trim((string) $value));

    return $key !== '' ? $key : NULL;
  }

  /**
   * Returns the content-owned public route slug for a page.
   */
  private function pageSlug(NodeInterface $node): ?string {
    if (!$node->hasField('field_public_slug')) {
      return NULL;
    }

    $field = $node->get('field_public_slug');
    if ($field->isEmpty()) {
      return NULL;
    }

    $item = $field->first();
    $slug = is_object($item) && isset($item->value)
      ? (string) $item->value
      : (string) ($field->value ?? '');
    $slug = strtolower(trim($slug));

    return preg_match(self::PUBLIC_SLUG_PATTERN, $slug) === 1 ? $slug : NULL;
  }

  /**
   * Returns whether this page should be shown in navigation lists.
   */
  private function footerVisible(NodeInterface $node): bool {
    if (!$node->hasField('field_footer_visible') || $node->get('field_footer_visible')->isEmpty()) {
      return TRUE;
    }

    return (bool) $node->get('field_footer_visible')->value;
  }

  /**
   * Returns the editorial sort weight.
   */
  private function pageWeight(NodeInterface $node): int {
    if (!$node->hasField('field_page_weight') || $node->get('field_page_weight')->isEmpty()) {
      return 0;
    }

    return (int) $node->get('field_page_weight')->value;
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
