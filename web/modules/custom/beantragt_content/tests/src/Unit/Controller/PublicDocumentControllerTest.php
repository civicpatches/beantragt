<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Controller;

use Drupal\beantragt_content\Controller\PublicDocumentController;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Language\LanguageInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\NullLogger;

/**
 * Tests the public document API controller.
 */
#[CoversClass(PublicDocumentController::class)]
#[Group('beantragt_content')]
final class PublicDocumentControllerTest extends UnitTestCase {

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Node storage mock.
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * Form storage mock.
   */
  private EntityStorageInterface $formStorage;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Flood service mock.
   */
  private FloodInterface $flood;

  /**
   * Controller under test.
   */
  private PublicDocumentController $controller;

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
    $this->formStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $this->groupStorage,
        'node' => $this->nodeStorage,
        'form_schema' => $this->formStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $this->controller = new PublicDocumentController($this->entityTypeManager, $this->flood, new KillSwitch(), new NullLogger());
  }

  /**
   * Public documents can be scoped to organisation and form.
   */
  public function testListDocumentsReturnsOrganisationAndFormScopedPublicDocuments(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $organisation = $this->mockOrganisation(11, 'citizen-services', $jurisdiction);
    $form = $this->mockForm(
      44,
      '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
      'Bauantrag',
      $jurisdiction,
      $organisation,
    );
    $this->mockJurisdictionLookup($jurisdiction, $organisation);
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17'])
      ->willReturn([$form]);

    $document = $this->mockDocument(
      id: 91,
      uuid: '64b678f2-3804-4129-a69b-7dbb758e50a3',
      title: 'Merkblatt Antragstellung',
      body: '<p>Bitte vor dem Ausfüllen lesen.</p>',
      file: $this->mockFile('private://public_documents/merkblatt.pdf'),
      form: $form,
      placement: 'form_downloads',
      weight: 10,
    );
    $fallback = $this->mockDocument(
      id: 92,
      uuid: 'e8ad6303-6a91-44c9-91ea-5c76180e7954',
      title: 'Gebührenblatt',
      body: '<p>Gebührenübersicht.</p>',
      file: $this->mockFile('private://public_documents/gebuehren.pdf'),
      form: NULL,
      placement: 'form_downloads',
      weight: 20,
    );
    $conditions = [];
    $this->mockDocumentScopedQueries([
      [$document],
      [],
      [],
      [$fallback],
    ], $conditions);

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents?organisation=citizen-services&form=4ebef1d0-c8d4-49fa-9957-0338b8fcbe17&placement=form_downloads&lang=de'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertPublicCache($response);
    $this->assertSame('publicDocumentList', $payload['meta']['resource']);
    $this->assertCount(2, $payload['data']);
    $this->assertSame('publicDocument', $payload['data'][0]['type']);
    $this->assertSame('Merkblatt Antragstellung', $payload['data'][0]['attributes']['title']);
    $this->assertSame('<p>Bitte vor dem Ausfüllen lesen.</p>', $payload['data'][0]['attributes']['description']['processed']);
    $this->assertSame('form_downloads', $payload['data'][0]['attributes']['placement']);
    $this->assertSame(7, $payload['data'][0]['attributes']['revisionId']);
    $this->assertSame('/api/v1/jurisdictions/musterstadt/documents/64b678f2-3804-4129-a69b-7dbb758e50a3/file?placement=form_downloads&organisation=citizen-services&form=4ebef1d0-c8d4-49fa-9957-0338b8fcbe17', $payload['data'][0]['attributes']['file']['url']);
    $this->assertSame('4ebef1d0-c8d4-49fa-9957-0338b8fcbe17', $payload['data'][0]['attributes']['form']['id']);
    $this->assertSame('Gebührenblatt', $payload['data'][1]['attributes']['title']);
    $this->assertNull($payload['data'][1]['attributes']['form']);
    $this->assertContains('url.query_args:form', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('url.query_args:placement', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('group:11', $response->getCacheableMetadata()->getCacheTags());
    $this->assertContains(['field_organisation.target_id', 11], $conditions[0]);
    $this->assertContains(['field_form_schema.target_id', 44], $conditions[0]);
    $this->assertContains(['field_content_placement', 'form_downloads'], $conditions[0]);
  }

  /**
   * Private files outside the editorial document directory are not emitted.
   */
  public function testPrivateDocumentFileIsSkipped(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $document = $this->mockDocument(
      id: 91,
      uuid: '64b678f2-3804-4129-a69b-7dbb758e50a3',
      title: 'Internes Merkblatt',
      body: '<p>Nicht öffentlich.</p>',
      file: $this->mockFile('private://application_attachments/scan.pdf'),
      form: NULL,
      placement: 'form_downloads',
      weight: 10,
    );
    $conditions = [];
    $this->mockDocumentScopedQueries([[$document]], $conditions);

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([], $payload['data']);
  }

  /**
   * Public files outside the editorial document directory are not emitted.
   */
  public function testNonDocumentPublicFileIsSkipped(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $document = $this->mockDocument(
      id: 91,
      uuid: '64b678f2-3804-4129-a69b-7dbb758e50a3',
      title: 'Logo',
      body: '<p>Nicht als Merkblatt gepflegt.</p>',
      file: $this->mockFile('public://logos/logo.png'),
      form: NULL,
      placement: 'form_downloads',
      weight: 10,
    );
    $conditions = [];
    $this->mockDocumentScopedQueries([[$document]], $conditions);

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([], $payload['data']);
  }

  /**
   * Path traversal shaped public document URIs are not emitted.
   */
  public function testTraversalDocumentFileUriIsSkipped(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $document = $this->mockDocument(
      id: 91,
      uuid: '64b678f2-3804-4129-a69b-7dbb758e50a3',
      title: 'Manipuliertes Merkblatt',
      body: '<p>Nicht ausliefern.</p>',
      file: $this->mockFile('private://public_documents/../other-public-file.pdf', 'application/pdf'),
      form: NULL,
      placement: 'form_downloads',
      weight: 10,
    );
    $conditions = [];
    $this->mockDocumentScopedQueries([[$document]], $conditions);

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([], $payload['data']);
  }

  /**
   * Form-only lookups include the form organisation scope.
   */
  public function testFormOnlyRequestUsesFormOrganisationScope(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $organisation = $this->mockOrganisation(11, 'citizen-services', $jurisdiction);
    $form = $this->mockForm(
      44,
      '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17',
      'Bauantrag',
      $jurisdiction,
      $organisation,
    );
    $this->mockJurisdictionLookup($jurisdiction);
    $this->formStorage->method('loadByProperties')
      ->with(['uuid' => '4ebef1d0-c8d4-49fa-9957-0338b8fcbe17'])
      ->willReturn([$form]);

    $document = $this->mockDocument(
      id: 91,
      uuid: '64b678f2-3804-4129-a69b-7dbb758e50a3',
      title: 'Merkblatt Antragstellung',
      body: '<p>Bitte vor dem Ausfüllen lesen.</p>',
      file: $this->mockFile('private://public_documents/merkblatt.pdf'),
      form: $form,
      placement: 'form_downloads',
      weight: 10,
    );
    $conditions = [];
    $this->mockDocumentScopedQueries([
      [$document],
      [],
      [],
      [],
    ], $conditions);

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents?form=4ebef1d0-c8d4-49fa-9957-0338b8fcbe17&placement=form_downloads'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertCount(1, $payload['data']);
    $this->assertSame('Merkblatt Antragstellung', $payload['data'][0]['attributes']['title']);
    $this->assertContains('group:11', $response->getCacheableMetadata()->getCacheTags());
    $this->assertContains(['field_organisation.target_id', 11], $conditions[0]);
    $this->assertContains(['field_form_schema.target_id', 44], $conditions[0]);
  }

  /**
   * Backend flood protection limits direct public API reads.
   */
  public function testFloodLimitReturnsRateLimit(): void {
    $flood = $this->createMock(FloodInterface::class);
    // Counted per address: the once-per-hour warning guard is asked first.
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static fn (...$arguments): bool => match ($arguments) {
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_content.public_documents'] => FALSE,
        ['beantragt_content.public_documents', 600, 3600, '127.0.0.1'] => FALSE,
        default => self::fail('Unexpected flood arguments: ' . json_encode($arguments)),
      });
    $flood->expects($this->never())->method('register');
    $kill_switch = $this->createMock(KillSwitch::class);
    $kill_switch->expects($this->once())->method('trigger');
    $this->controller = new PublicDocumentController(
      $this->entityTypeManager,
      $flood,
      $kill_switch,
      new NullLogger(),
    );
    $this->groupStorage->expects($this->never())->method('loadByProperties');
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents', server: ['REMOTE_ADDR' => '127.0.0.1']),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
  }

  /**
   * File downloads use their own event and keep the old hourly limit of 60.
   */
  public function testDownloadFloodLimitReturnsRateLimit(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static fn (...$arguments): bool => match ($arguments) {
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_content.public_document_download'] => FALSE,
        ['beantragt_content.public_document_download', 60, 3600, '127.0.0.1'] => FALSE,
        default => self::fail('Unexpected flood arguments: ' . json_encode($arguments)),
      });
    $flood->expects($this->never())->method('register');
    $kill_switch = $this->createMock(KillSwitch::class);
    $kill_switch->expects($this->once())->method('trigger');
    $controller = new PublicDocumentController($this->entityTypeManager, $flood, $kill_switch, new NullLogger());
    $this->groupStorage->expects($this->never())->method('loadByProperties');
    $this->nodeStorage->expects($this->never())->method('loadByProperties');

    $response = $controller->downloadDocument(
      Request::create('/api/v1/jurisdictions/musterstadt/documents/64b678f2-3804-4129-a69b-7dbb758e50a3/file?placement=footer', server: ['REMOTE_ADDR' => '127.0.0.1']),
      'musterstadt',
      '64b678f2-3804-4129-a69b-7dbb758e50a3',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
  }

  /**
   * Allowed list reads and file attempts register in separate buckets.
   */
  public function testListAndDownloadRegisterSeparateEvents(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->exactly(4))
      ->method('isAllowed')
      ->willReturnCallback(static fn (...$arguments): bool => match ($arguments) {
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_content.public_documents'],
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_content.public_document_download'] => FALSE,
        ['beantragt_content.public_documents', 600, 3600, '127.0.0.1'],
        ['beantragt_content.public_document_download', 60, 3600, '127.0.0.1'] => TRUE,
        default => self::fail('Unexpected flood arguments: ' . json_encode($arguments)),
      });
    $registered = [];
    $flood->expects($this->exactly(2))->method('register')
      ->willReturnCallback(static function (...$arguments) use (&$registered): void {
        $registered[] = $arguments;
      });
    $controller = new PublicDocumentController($this->entityTypeManager, $flood, new KillSwitch(), new NullLogger());
    $this->groupStorage->method('loadByProperties')->willReturn([]);
    $request = Request::create('/api/v1/jurisdictions/missing/documents', server: ['REMOTE_ADDR' => '127.0.0.1']);
    $this->assertSame(404, $controller->listDocuments($request, 'missing')->getStatusCode());
    try {
      $controller->downloadDocument($request, 'missing', '64b678f2-3804-4129-a69b-7dbb758e50a3');
      $this->fail('The missing municipality must return 404 after counting the file attempt.');
    }
    catch (NotFoundHttpException $exception) {
      $this->assertSame(404, $exception->getStatusCode());
    }
    $this->assertSame([
      ['beantragt_content.public_documents', 3600, '127.0.0.1'],
      ['beantragt_content.public_document_download', 3600, '127.0.0.1'],
    ], $registered);
  }

  /**
   * Missing organisation scopes carry list tags for cache invalidation.
   */
  public function testMissingOrganisationNotFoundCarriesListCacheTags(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn([]);
    $this->groupStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents?organisation=missing-org'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $cache_tags = $response->getCacheableMetadata()->getCacheTags();

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('document.not_found', $payload['error']['code']);
    $this->assertContains('node_list:public_document', $cache_tags);
    $this->assertContains('group_list', $cache_tags);
    $this->assertContains('form_schema_list', $cache_tags);
  }

  /**
   * Unsupported placements fail with a stable v1 error envelope.
   */
  public function testInvalidPlacementReturnsBadRequest(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listDocuments(
      Request::create('/api/v1/jurisdictions/musterstadt/documents?placement=bad'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('document.invalid_query', $payload['error']['code']);
  }

  /**
   * Asserts that public list responses are shared-cacheable.
   */
  private function assertPublicCache(Response $response): void {
    $cache_control = (string) $response->headers->get('Cache-Control');
    $this->assertStringContainsString('public', $cache_control);
    $this->assertStringContainsString('max-age=300', $cache_control);
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
   * Wires document queries in exact scope order.
   *
   * @param array<int, \Drupal\node\NodeInterface[]> $scoped_nodes
   *   Node lists returned by consecutive scope queries.
   * @param array<int, array<int, array{0: string, 1: mixed}>> $conditions
   *   Captured condition calls keyed by query index.
   */
  private function mockDocumentScopedQueries(array $scoped_nodes, array &$conditions): void {
    $queries = [];
    $loaded_by_ids = [];
    foreach ($scoped_nodes as $index => $nodes) {
      $ids = [];
      $loaded = [];
      foreach ($nodes as $node) {
        $id = (string) $node->id();
        $ids[] = $id;
        $loaded[$id] = $node;
      }
      $loaded_by_ids[implode('|', $ids)] = $loaded;

      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->with(FALSE)->willReturnSelf();
      $query->method('condition')->willReturnCallback(
        static function (...$args) use (&$conditions, $index, $query): QueryInterface {
          if (isset($args[0], $args[1]) && is_string($args[0])) {
            $conditions[$index][] = [$args[0], $args[1]];
          }
          return $query;
        }
      );
      $query->method('notExists')->willReturnCallback(
        static function (string $field) use (&$conditions, $index, $query): QueryInterface {
          $conditions[$index][] = ['notExists:' . $field, TRUE];
          return $query;
        }
      );
      $query->method('sort')->willReturnSelf();
      $query->method('execute')->willReturn($ids);
      $queries[] = $query;
    }

    $this->nodeStorage->method('getQuery')->willReturnOnConsecutiveCalls(...$queries);
    $this->nodeStorage->method('loadMultiple')->willReturnCallback(
      static fn (array $ids): array => $loaded_by_ids[implode('|', $ids)] ?? []
    );
  }

  /**
   * Builds a jurisdiction group mock.
   */
  private function mockJurisdiction(int $id, string $slug): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('jur');
    $group->method('isPublished')->willReturn(TRUE);
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
  private function mockOrganisation(int $id, string $slug, GroupInterface $jurisdiction): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn('org');
    $group->method('isPublished')->willReturn(TRUE);
    $group->method('label')->willReturn('Bürgerbüro');
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $group->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array(
        $field,
        ['field_slug', 'field_jurisdiction'],
        TRUE,
      ),
    );
    $group->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_slug' => $this->fieldValue($slug),
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      default => $this->emptyField(),
    });

    return $group;
  }

  /**
   * Builds a public form mock.
   */
  private function mockForm(int $id, string $uuid, string $label, GroupInterface $jurisdiction, ?GroupInterface $organisation): FormSchemaInterface {
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn($id);
    $form->method('uuid')->willReturn($uuid);
    $form->method('label')->willReturn($label);
    $form->method('isPublished')->willReturn(TRUE);
    $form->method('getSchemaStatus')->willReturn('published');
    $form->method('getPublicationScope')->willReturn('internet');
    $form->method('getCacheTags')->willReturn(['form_schema:' . $id]);
    $form->method('hasField')->willReturnCallback(
      static fn (string $field): bool => in_array(
        $field,
        ['field_jurisdiction', 'field_organisation', 'field_group'],
        TRUE,
      ),
    );
    $form->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      'field_organisation' => $organisation instanceof GroupInterface ? $this->entityReference($organisation) : $this->emptyField(),
      'field_group' => $this->emptyField(),
      default => $this->emptyField(),
    });

    return $form;
  }

  /**
   * Builds a public document node mock.
   */
  private function mockDocument(
    int $id,
    string $uuid,
    string $title,
    string $body,
    FileInterface $file,
    ?FormSchemaInterface $form,
    string $placement,
    int $weight,
  ): NodeInterface {
    $fields = [
      'body' => $this->bodyField($body),
      'field_public_document_file' => $this->entityReference($file),
      'field_form_schema' => $form instanceof FormSchemaInterface ? $this->entityReference($form) : $this->emptyField(),
      'field_content_placement' => $this->fieldValue($placement),
      'field_content_weight' => $this->fieldValue((string) $weight),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn($uuid);
    $node->method('label')->willReturn($title);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('getRevisionId')->willReturn(7);
    $node->method('getChangedTime')->willReturn(1781431200);
    $node->method('language')->willReturn($this->language('de'));
    $node->method('getCacheTags')->willReturn(['node:' . $id]);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $node->method('hasTranslation')->willReturn(FALSE);
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $node->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $node;
  }

  /**
   * Builds a file mock.
   */
  private function mockFile(string $uri, ?string $mime_type = NULL): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($uri);
    $file->method('getFilename')->willReturn(basename($uri));
    $file->method('getMimeType')->willReturn($mime_type ?? (str_ends_with($uri, '.pdf') ? 'application/pdf' : 'image/png'));
    $file->method('getSize')->willReturn(415);

    return $file;
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
  private function bodyField(string $processed): FieldItemListInterface {
    $item = new \stdClass();
    $item->value = '<script>alert(1)</script>' . $processed;
    $item->processed = $processed;
    $item->format = 'basic_html';

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
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
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => $property === 'value',
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an entity reference field.
   */
  private function entityReference(object $entity): FieldItemListInterface {
    $target_id = is_callable([$entity, 'id']) ? $entity->id() : NULL;
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturnCallback(
      static fn (string $property): bool => in_array($property, ['entity', 'target_id'], TRUE),
    );
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => $target_id,
        default => NULL,
      },
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
