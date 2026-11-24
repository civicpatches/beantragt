<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Controller;

use Drupal\beantragt_content\Controller\PublicContentSectionController;
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
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\NullLogger;

/**
 * Tests the public content-section API controller.
 */
#[CoversClass(PublicContentSectionController::class)]
#[Group('beantragt_content')]
final class PublicContentSectionControllerTest extends UnitTestCase {

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Node storage mock.
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * Form schema storage mock.
   */
  private EntityStorageInterface $formStorage;

  /**
   * Entity type manager mock that returns the storages above.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Flood control mock.
   */
  private FloodInterface $flood;

  /**
   * The controller under test.
   */
  private PublicContentSectionController $controller;

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

    $this->controller = new PublicContentSectionController($this->entityTypeManager, $this->flood, new KillSwitch(), new NullLogger());
  }

  /**
   * Public content sections can be scoped to organisation and form.
   */
  public function testListSectionsReturnsOrganisationAndFormScopedSections(): void {
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

    $section = $this->mockSection(
      id: 91,
      uuid: '64b678f2-3804-4129-a69b-7dbb758e50a3',
      title: 'Vor dem Ausfüllen',
      body: '<p>Bitte lesen.</p>',
      form: $form,
      placement: 'form_intro',
      weight: 10,
    );
    $fallback = $this->mockSection(
      id: 92,
      uuid: 'e8ad6303-6a91-44c9-91ea-5c76180e7954',
      title: 'Allgemeiner Hinweis',
      body: '<p>Gilt für alle Formulare.</p>',
      form: NULL,
      placement: 'form_intro',
      weight: 20,
    );
    $conditions = [];
    $this->mockSectionScopedQueries([
      [$section],
      [],
      [],
      [$fallback],
    ], $conditions);

    $response = $this->controller->listSections(
      Request::create('/api/v1/jurisdictions/musterstadt/sections?organisation=citizen-services&form=4ebef1d0-c8d4-49fa-9957-0338b8fcbe17&placement=form_intro&lang=de'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertPublicCache($response);
    $this->assertSame('contentSectionList', $payload['meta']['resource']);
    $this->assertCount(2, $payload['data']);
    $this->assertSame('contentSection', $payload['data'][0]['type']);
    $this->assertSame('Vor dem Ausfüllen', $payload['data'][0]['attributes']['title']);
    $this->assertSame('<p>Bitte lesen.</p>', $payload['data'][0]['attributes']['body']['processed']);
    $this->assertSame('', $payload['data'][0]['attributes']['body']['value']);
    $this->assertSame('form_intro', $payload['data'][0]['attributes']['placement']);
    $this->assertSame('4ebef1d0-c8d4-49fa-9957-0338b8fcbe17', $payload['data'][0]['attributes']['form']['id']);
    $this->assertSame('Allgemeiner Hinweis', $payload['data'][1]['attributes']['title']);
    $this->assertNull($payload['data'][1]['attributes']['form']);
    $this->assertContains('url.query_args:form', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('url.query_args:placement', $response->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('group:11', $response->getCacheableMetadata()->getCacheTags());
    $this->assertContains(['field_organisation.target_id', 11], $conditions[0]);
    $this->assertContains(['field_form_schema.target_id', 44], $conditions[0]);
    $this->assertContains(['field_content_placement', 'form_intro'], $conditions[0]);
  }

  /**
   * Unsupported placements fail with a stable v1 error envelope.
   */
  public function testInvalidPlacementReturnsBadRequest(): void {
    $jurisdiction = $this->mockJurisdiction(3, 'musterstadt');
    $this->mockJurisdictionLookup($jurisdiction);
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listSections(
      Request::create('/api/v1/jurisdictions/musterstadt/sections?placement=bad'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertSame('section.invalid_query', $payload['error']['code']);
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
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_content.public_sections'] => FALSE,
        ['beantragt_content.public_sections', 600, 3600, '127.0.0.1'] => FALSE,
        default => self::fail('Unexpected flood arguments: ' . json_encode($arguments)),
      });
    $flood->expects($this->never())->method('register');
    $kill_switch = $this->createMock(KillSwitch::class);
    $kill_switch->expects($this->once())->method('trigger');
    $this->controller = new PublicContentSectionController(
      $this->entityTypeManager,
      $flood,
      $kill_switch,
      new NullLogger(),
    );
    $this->groupStorage->expects($this->never())->method('loadByProperties');
    $this->nodeStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->listSections(
      Request::create('/api/v1/jurisdictions/musterstadt/sections', server: ['REMOTE_ADDR' => '127.0.0.1']),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
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
   * Wires content-section queries in exact scope order.
   *
   * @param array<int, \Drupal\node\NodeInterface[]> $scoped_nodes
   *   Node lists returned by consecutive scope queries.
   * @param array<int, array<int, array{0: string, 1: mixed}>> $conditions
   *   Captured condition calls keyed by query index.
   */
  private function mockSectionScopedQueries(array $scoped_nodes, array &$conditions): void {
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
    $group_fields = ['field_slug', 'field_jurisdiction'];
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => in_array($field, $group_fields, TRUE));
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
    $form_fields = ['field_jurisdiction', 'field_organisation', 'field_group'];
    $form->method('hasField')->willReturnCallback(static fn (string $field): bool => in_array($field, $form_fields, TRUE));
    $form->method('get')->willReturnCallback(fn (string $field): FieldItemListInterface => match ($field) {
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      'field_organisation' => $organisation instanceof GroupInterface ? $this->entityReference($organisation) : $this->emptyField(),
      'field_group' => $this->emptyField(),
      default => $this->emptyField(),
    });

    return $form;
  }

  /**
   * Builds a public content-section node mock.
   */
  private function mockSection(
    int $id,
    string $uuid,
    string $title,
    string $body,
    ?FormSchemaInterface $form,
    string $placement,
    int $weight,
  ): NodeInterface {
    $fields = [
      'body' => $this->bodyField($body),
      'field_form_schema' => $form instanceof FormSchemaInterface ? $this->entityReference($form) : $this->emptyField(),
      'field_content_placement' => $this->fieldValue($placement),
      'field_content_weight' => $this->fieldValue((string) $weight),
    ];

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('uuid')->willReturn($uuid);
    $node->method('label')->willReturn($title);
    $node->method('isPublished')->willReturn(TRUE);
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
