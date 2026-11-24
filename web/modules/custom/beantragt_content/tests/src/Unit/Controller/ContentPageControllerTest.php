<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Controller;

use Drupal\beantragt_content\Controller\ContentPageController;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the public jurisdiction content page API controller.
 */
#[CoversClass(ContentPageController::class)]
#[Group('beantragt_content')]
final class ContentPageControllerTest extends UnitTestCase {

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Node storage mock.
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * Controller under test.
   */
  private ContentPageController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(
      fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $this->groupStorage,
        'node' => $this->nodeStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $this->controller = new ContentPageController($entity_type_manager);
  }

  /**
   * Published translations are returned without leaking raw body HTML.
   */
  public function testGetPageReturnsPublishedLocalizedPageWithoutRawBody(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $source = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Datenschutz',
      langcode: 'de',
      value: '<script>alert(1)</script><p>Datenschutz</p>',
      processed: '<p>Datenschutz</p>',
    );
    $translation = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Privacy Policy',
      langcode: 'en',
      value: '<script>alert(1)</script><p>Privacy</p>',
      processed: '<p>Privacy</p>',
    );
    $source->method('hasTranslation')->with('en')->willReturn(TRUE);
    $source->method('getTranslation')->with('en')->willReturn($translation);

    $conditions = [];
    $this->mockPublishedPageQuery($source, $conditions);

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/musterstadt/pages/privacy?lang=en'),
      'musterstadt',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('page', $payload['data']['type']);
    $this->assertSame('8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d', $payload['data']['id']);
    $this->assertSame('privacy', $payload['data']['attributes']['key']);
    $this->assertSame('Privacy Policy', $payload['data']['attributes']['title']);
    $this->assertSame('', $payload['data']['attributes']['body']['value']);
    $this->assertSame('<p>Privacy</p>', $payload['data']['attributes']['body']['processed']);
    $this->assertSame('basic_html', $payload['data']['attributes']['body']['format']);
    $this->assertSame('en', $payload['data']['attributes']['langcode']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertSame('contentPage', $payload['meta']['resource']);
    $this->assertStringNotContainsString('<script>alert(1)</script>', json_encode($payload, JSON_THROW_ON_ERROR));
    $this->assertContains('url.query_args:lang', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertSame([
      ['type', 'page'],
      ['status', 1],
      ['field_jurisdiction.target_id', 3],
    ], $conditions);
  }

  /**
   * Published page list exposes route metadata without raw body content.
   */
  public function testListPagesReturnsPublishedLocalizedRouteMetadata(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $privacy = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Datenschutz',
      langcode: 'de',
      value: '<p>Datenschutz</p>',
      processed: '<p>Datenschutz</p>',
      key: 'privacy',
      publicSlug: 'datenschutz',
      weight: 10,
    );
    $privacy_translation = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Privacy Policy',
      langcode: 'en',
      value: '<p>Privacy</p>',
      processed: '<p>Privacy</p>',
      key: 'privacy',
      publicSlug: 'privacy',
      weight: 10,
    );
    $privacy->method('hasTranslation')->with('en')->willReturn(TRUE);
    $privacy->method('getTranslation')->with('en')->willReturn($privacy_translation);

    $legal_notice = $this->mockPage(
      id: 78,
      uuid: '96f79ad2-7d72-454f-b7fd-4102d1e8a891',
      title: 'Impressum',
      langcode: 'de',
      value: '<p>Impressum</p>',
      processed: '<p>Impressum</p>',
      key: 'legal_notice',
      publicSlug: 'legal-notice',
      weight: 20,
    );
    $contact = $this->mockPage(
      id: 79,
      uuid: 'd26cf5bc-f081-40bd-bf0a-b6b45e19c410',
      title: 'Kontakt',
      langcode: 'de',
      value: '<p>Kontakt</p>',
      processed: '<p>Kontakt</p>',
      key: 'content',
      publicSlug: 'kontakt',
      weight: 30,
    );

    $conditions = [];
    $this->mockPublishedPageListQuery([$privacy, $legal_notice, $contact], $conditions);

    $response = $this->controller->listPages(
      Request::create('/api/v1/jurisdictions/musterstadt/pages?lang=en'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('contentPageList', $payload['meta']['resource']);
    $this->assertSame('privacy', $payload['data'][0]['attributes']['key']);
    $this->assertSame('Privacy Policy', $payload['data'][0]['attributes']['title']);
    $this->assertSame('/pages/privacy', $payload['data'][0]['attributes']['path']);
    $this->assertSame(TRUE, $payload['data'][0]['attributes']['footerVisible']);
    $this->assertSame(10, $payload['data'][0]['attributes']['order']);
    $this->assertSame('legal_notice', $payload['data'][1]['attributes']['key']);
    $this->assertSame('/pages/legal-notice', $payload['data'][1]['attributes']['path']);
    $this->assertSame('content', $payload['data'][2]['attributes']['key']);
    $this->assertSame('Kontakt', $payload['data'][2]['attributes']['title']);
    $this->assertSame('/pages/kontakt', $payload['data'][2]['attributes']['path']);
    $this->assertArrayNotHasKey('body', $payload['data'][0]['attributes']);
    $this->assertSame([
      ['type', 'page'],
      ['status', 1],
      ['field_jurisdiction.target_id', 3],
    ], $conditions);
    $this->assertContains('url.query_args:lang', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Detail pages can be loaded by arbitrary content-owned public slug.
   */
  public function testGetPageReturnsPageByPublicSlug(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $contact = $this->mockPage(
      id: 79,
      uuid: 'd26cf5bc-f081-40bd-bf0a-b6b45e19c410',
      title: 'Kontakt',
      langcode: 'de',
      value: '<p>Kontakt</p>',
      processed: '<p>Kontakt</p>',
      key: 'content',
      publicSlug: 'kontakt',
      weight: 30,
    );

    $conditions = [];
    $this->mockPublishedPageQuery($contact, $conditions);

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/musterstadt/pages/kontakt?lang=de'),
      'musterstadt',
      'kontakt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('content', $payload['data']['attributes']['key']);
    $this->assertSame('kontakt', $payload['data']['attributes']['slug']);
    $this->assertSame('/pages/kontakt', $payload['data']['attributes']['path']);
    $this->assertSame('Kontakt', $payload['data']['attributes']['title']);
    $this->assertSame('<p>Kontakt</p>', $payload['data']['attributes']['body']['processed']);
    $this->assertSame([
      ['type', 'page'],
      ['status', 1],
      ['field_jurisdiction.target_id', 3],
    ], $conditions);
  }

  /**
   * Organisation page lists prefer org pages and keep jurisdiction fallbacks.
   */
  public function testListPagesUsesOrganisationOverridesAndJurisdictionFallbacks(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $organisation = $this->mockOrganisation(11, 'citizen-services');
    $this->mockJurisdictionLookup($jurisdiction, $organisation);

    $organisation_privacy = $this->mockPage(
      id: 90,
      uuid: 'c75eb9fd-d0f5-4be2-9d9f-a31ea8ef4dd0',
      title: 'Datenschutz Bürgerbüro',
      langcode: 'de',
      value: '<p>Datenschutz Bürgerbüro</p>',
      processed: '<p>Datenschutz Bürgerbüro</p>',
      key: 'privacy',
      publicSlug: 'datenschutz-buergerbuero',
      weight: 10,
    );
    $jurisdiction_privacy = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Datenschutz',
      langcode: 'de',
      value: '<p>Datenschutz</p>',
      processed: '<p>Datenschutz</p>',
      key: 'privacy',
      publicSlug: 'datenschutz',
      weight: 10,
    );
    $jurisdiction_contact = $this->mockPage(
      id: 79,
      uuid: 'd26cf5bc-f081-40bd-bf0a-b6b45e19c410',
      title: 'Kontakt',
      langcode: 'de',
      value: '<p>Kontakt</p>',
      processed: '<p>Kontakt</p>',
      key: 'content',
      publicSlug: 'kontakt',
      weight: 30,
    );
    $this->mockPublishedPageScopedQueries([
      [$organisation_privacy],
      [$jurisdiction_privacy, $jurisdiction_contact],
    ]);

    $response = $this->controller->listPages(
      Request::create('/api/v1/jurisdictions/musterstadt/pages?organisation=citizen-services&lang=de'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(['datenschutz-buergerbuero', 'kontakt'], array_column(
      array_column($payload['data'], 'attributes'),
      'slug',
    ));
    $this->assertSame('Datenschutz Bürgerbüro', $payload['data'][0]['attributes']['title']);
    $this->assertSame('Kontakt', $payload['data'][1]['attributes']['title']);
    $this->assertContains('url.query_args:organisation', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('group:11', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Organisation detail pages prefer org-scoped pages over jurisdiction pages.
   */
  public function testGetPageUsesOrganisationOverrideBeforeJurisdictionFallback(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $organisation = $this->mockOrganisation(11, 'citizen-services');
    $this->mockJurisdictionLookup($jurisdiction, $organisation);

    $organisation_privacy = $this->mockPage(
      id: 90,
      uuid: 'c75eb9fd-d0f5-4be2-9d9f-a31ea8ef4dd0',
      title: 'Datenschutz Bürgerbüro',
      langcode: 'de',
      value: '<p>Datenschutz Bürgerbüro</p>',
      processed: '<p>Datenschutz Bürgerbüro</p>',
      key: 'privacy',
      publicSlug: 'datenschutz-buergerbuero',
      weight: 10,
    );
    $this->mockPublishedPageScopedQueries([[$organisation_privacy]]);

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/musterstadt/pages/privacy?organisation=citizen-services&lang=de'),
      'musterstadt',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('Datenschutz Bürgerbüro', $payload['data']['attributes']['title']);
    $this->assertSame('datenschutz-buergerbuero', $payload['data']['attributes']['slug']);
    $this->assertSame('<p>Datenschutz Bürgerbüro</p>', $payload['data']['attributes']['body']['processed']);
    $this->assertContains('group:11', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Unpublished translations are ignored and the published source is used.
   */
  public function testGetPageIgnoresUnpublishedTranslation(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $source = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Datenschutz',
      langcode: 'de',
      value: '<p>Datenschutz</p>',
      processed: '<p>Datenschutz</p>',
    );
    $translation = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Draft Privacy',
      langcode: 'en',
      value: '<p>Draft</p>',
      processed: '<p>Draft</p>',
      published: FALSE,
    );
    $source->method('hasTranslation')->with('en')->willReturn(TRUE);
    $source->method('getTranslation')->with('en')->willReturn($translation);

    $conditions = [];
    $this->mockPublishedPageQuery($source, $conditions);

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/musterstadt/pages/privacy?lang=en'),
      'musterstadt',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('Datenschutz', $payload['data']['attributes']['title']);
    $this->assertSame('de', $payload['data']['attributes']['langcode']);
    $this->assertSame('<p>Datenschutz</p>', $payload['data']['attributes']['body']['processed']);
    $this->assertSame([
      ['type', 'page'],
      ['status', 1],
      ['field_jurisdiction.target_id', 3],
    ], $conditions);
  }

  /**
   * Numeric jurisdiction ids are not accepted on the public page API.
   */
  public function testNumericJurisdictionIdIsRejected(): void {
    $this->groupStorage->expects($this->never())->method('loadByProperties');
    $this->groupStorage->expects($this->never())->method('load');
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/3/pages/privacy'),
      '3',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('page.not_found', $payload['error']['code']);
    $this->assertContains('url.path', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Missing pages vary by path to avoid broad cached 404 responses.
   */
  public function testMissingPageReturnsPathScopedNotFound(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $conditions = [];
    $this->mockMissingPageQuery($conditions);

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/musterstadt/pages/privacy'),
      'musterstadt',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('page.not_found', $payload['error']['code']);
    $this->assertContains('url.path', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('node_list:page', $response->getCacheableMetadata()->getCacheTags());
    $this->assertSame([
      ['type', 'page'],
      ['status', 1],
      ['field_jurisdiction.target_id', 3],
    ], $conditions);
  }

  /**
   * Unsupported body text formats are not emitted to the public API.
   */
  public function testUnsupportedBodyFormatDoesNotEmitProcessedHtml(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $source = $this->mockPage(
      id: 77,
      uuid: '8f4f4f2a-e062-47a6-b8b1-4c4aa7db1f3d',
      title: 'Datenschutz',
      langcode: 'de',
      value: '<script>alert(1)</script><p>Datenschutz</p>',
      processed: '<script>alert(1)</script><p>Datenschutz</p>',
      format: 'full_html',
    );
    $source->method('hasTranslation')->willReturn(FALSE);

    $conditions = [];
    $this->mockPublishedPageQuery($source, $conditions);

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/musterstadt/pages/privacy'),
      'musterstadt',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('', $payload['data']['attributes']['body']['value']);
    $this->assertSame('', $payload['data']['attributes']['body']['processed']);
    $this->assertSame('full_html', $payload['data']['attributes']['body']['format']);
    $this->assertStringNotContainsString('<script>alert(1)</script>', json_encode($payload, JSON_THROW_ON_ERROR));
  }

  /**
   * Unknown jurisdictions fail with a stable public v1 error envelope.
   */
  public function testUnknownJurisdictionReturnsV1NotFound(): void {
    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->getPage(
      Request::create('/api/v1/jurisdictions/missing/pages/privacy'),
      'missing',
      'privacy',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('page.not_found', $payload['error']['code']);
    $this->assertSame('Page not found.', $payload['error']['message']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertContains('group_list', $response->getCacheableMetadata()->getCacheTags());
    $this->assertContains('url.path', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Wires the jurisdiction slug resolver.
   */
  private function mockJurisdictionLookup(GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): void {
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn (array $properties): array => $properties === [
        'type' => 'jur',
        'field_slug' => 'musterstadt',
        'status' => 1,
      ] ? [$jurisdiction] : []
    );
    $this->groupStorage->method('load')->willReturnCallback(static function (int|string $id) use ($jurisdiction, $organisation): ?GroupInterface {
      if ((int) $id === 3) {
        return $jurisdiction;
      }
      if ($organisation instanceof GroupInterface && (int) $id === (int) $organisation->id()) {
        return $organisation;
      }
      return NULL;
    });

    if ($organisation instanceof GroupInterface) {
      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->with(FALSE)->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('range')->with(0, 1)->willReturnSelf();
      $query->method('execute')->willReturn([(string) $organisation->id()]);
      $this->groupStorage->method('getQuery')->willReturn($query);
    }
  }

  /**
   * Wires the published page entity query.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node returned by the query.
   * @param array<int, array{0: string, 1: mixed}> $conditions
   *   Captured simple condition calls.
   */
  private function mockPublishedPageQuery(NodeInterface $node, array &$conditions): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$args) use (&$conditions, $query): QueryInterface {
        if (isset($args[0], $args[1]) && is_string($args[0])) {
          $conditions[] = [$args[0], $args[1]];
        }
        return $query;
      }
    );
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([77]);

    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->method('loadMultiple')->with([77])->willReturn([77 => $node]);
  }

  /**
   * Wires an empty page entity query.
   *
   * @param array<int, array{0: string, 1: mixed}> $conditions
   *   Captured simple condition calls.
   */
  private function mockMissingPageQuery(array &$conditions): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$args) use (&$conditions, $query): QueryInterface {
        if (isset($args[0], $args[1]) && is_string($args[0])) {
          $conditions[] = [$args[0], $args[1]];
        }
        return $query;
      }
    );
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->expects($this->never())->method('loadMultiple');
  }

  /**
   * Wires a page list entity query.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Nodes returned by the query.
   * @param array<int, array{0: string, 1: mixed}> $conditions
   *   Captured simple condition calls.
   */
  private function mockPublishedPageListQuery(array $nodes, array &$conditions): void {
    $ids = [];
    $loaded = [];
    foreach ($nodes as $node) {
      $id = (string) $node->id();
      $ids[] = $id;
      $loaded[$id] = $node;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$args) use (&$conditions, $query): QueryInterface {
        if (isset($args[0], $args[1]) && is_string($args[0])) {
          $conditions[] = [$args[0], $args[1]];
        }
        return $query;
      }
    );
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->method('loadMultiple')->with($ids)->willReturn($loaded);
  }

  /**
   * Wires consecutive exact-scope page queries.
   *
   * @param array<int, \Drupal\node\NodeInterface[]> $pages_by_query
   *   Nodes returned for each query.
   */
  private function mockPublishedPageScopedQueries(array $pages_by_query): void {
    $queries = [];
    $loaded = [];
    foreach ($pages_by_query as $nodes) {
      $ids = [];
      foreach ($nodes as $node) {
        $id = (string) $node->id();
        $ids[] = $id;
        $loaded[$id] = $node;
      }

      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->with(FALSE)->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('notExists')->willReturnSelf();
      $query->method('sort')->willReturnSelf();
      $query->method('execute')->willReturn($ids);
      $queries[] = $query;
    }

    $this->nodeStorage->method('getQuery')->willReturnOnConsecutiveCalls(...$queries);
    $this->nodeStorage->method('loadMultiple')->willReturnCallback(
      static fn (array $ids): array => array_intersect_key($loaded, array_flip(array_map('strval', $ids))),
    );
  }

  /**
   * Builds a jurisdiction group mock.
   */
  private function mockJurisdiction(int $id, string $slug): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('jur');
    $group->method('label')->willReturn('Stadt Musterstadt');
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'field_slug');
    $group->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => $field === 'field_slug'
      ? $this->fieldValue($slug)
      : $this->emptyField());

    return $group;
  }

  /**
   * Builds an organisation group mock.
   */
  private function mockOrganisation(int $id, string $slug): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('org');
    $group->method('label')->willReturn('Bürgerbüro');
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'field_slug');
    $group->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => $field === 'field_slug'
      ? $this->fieldValue($slug)
      : $this->emptyField());

    return $group;
  }

  /**
   * Builds a content page node mock.
   */
  private function mockPage(
    int $id,
    string $uuid,
    string $title,
    string $langcode,
    string $value,
    string $processed,
    bool $published = TRUE,
    string $format = 'basic_html',
    string $key = 'privacy',
    string $publicSlug = 'datenschutz',
    bool $footerVisible = TRUE,
    int $weight = 10,
  ): NodeInterface {
    $fields = [
      'body' => $this->bodyField($value, $processed, $format),
      'field_page_key' => $this->fieldValue($key),
      'field_public_slug' => $this->fieldValue($publicSlug),
      'field_footer_visible' => $this->fieldValue($footerVisible ? '1' : '0'),
      'field_page_weight' => $this->fieldValue((string) $weight),
    ];
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn($uuid);
    $node->method('label')->willReturn($title);
    $node->method('isPublished')->willReturn($published);
    $node->method('getChangedTime')->willReturn(1781431200);
    $node->method('language')->willReturn($this->language($langcode));
    $node->method('getCacheTags')->willReturn(['node:' . $id]);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $node->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $node;
  }

  /**
   * Builds a language mock.
   */
  private function language(string $langcode): LanguageInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    return $language;
  }

  /**
   * Builds a text body field list.
   */
  private function bodyField(string $value, string $processed, string $format): FieldItemListInterface {
    $item = new \stdClass();
    $item->value = $value;
    $item->processed = $processed;
    $item->format = $format;

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '' && $processed === '');
    $field->method('first')->willReturn($item);
    return $field;
  }

  /**
   * Builds a scalar field list.
   */
  private function fieldValue(string $value): FieldItemListInterface {
    $item = new \stdClass();
    $item->value = $value;

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('first')->willReturn($item);
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

}
