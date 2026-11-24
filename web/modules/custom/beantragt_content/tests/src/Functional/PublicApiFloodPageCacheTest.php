<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises flood denials through the real anonymous Internal Page Cache.
 */
#[Group('beantragt_content')]
final class PublicApiFloodPageCacheTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime', 'file', 'json_form_widget', 'serialization', 'page_cache',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Existing sections and documents move without changing their revisions.
   */
  public function testLegacyFooterPlacementMigration(): void {
    $manager = $this->container->get('entity_type.manager');
    $storage = $manager->getStorage('node');
    $field_storage = $manager->getStorage('field_storage_config');
    $placement = $field_storage->load('node.field_content_placement');
    $values = $placement->getSetting('allowed_values');
    $this->assertArrayNotHasKey('footer', $values);
    $placement->setSetting('allowed_values', $values + ['footer' => 'Footer'])->save();
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Musterstadt',
      'field_slug' => 'musterstadt-migration-test',
      'status' => TRUE,
    ]);

    $file_id = $this->createPublicPdf();
    $legacy = [];
    foreach (['content_section', 'public_document'] as $bundle) {
      $node = $storage->create([
        'type' => $bundle,
        'title' => 'Legacy ' . $bundle,
        'status' => TRUE,
        'field_jurisdiction' => $jurisdiction->id(),
        'field_content_placement' => 'footer',
      ]);
      if ($bundle === 'public_document') {
        $node->set('field_public_document_file', $file_id);
        $document_uuid = $node->uuid();
      }
      $node->save();
      $legacy[$node->id()][] = $node->getRevisionId();
      $node->setNewRevision(TRUE);
      $node->isDefaultRevision(FALSE);
      $node->setUnpublished()->save();
      $legacy[$node->id()][] = $node->getRevisionId();
    }
    $unrelated = $storage->create([
      'type' => 'content_section',
      'title' => 'An existing introduction',
      'status' => FALSE,
      'field_content_placement' => 'form_intro',
    ]);
    $unrelated->save();

    $base = '/api/v1/jurisdictions/musterstadt-migration-test';
    $this->drupalGet($base . '/documents');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], Json::decode($this->getSession()->getPage()->getContent())['data']);
    $this->drupalGet($base . '/sections');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame([], Json::decode($this->getSession()->getPage()->getContent())['data']);
    $this->drupalGet($base . '/sections');
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');

    $this->container->get('module_handler')->loadInclude('beantragt_content', 'install');
    for ($run = 0; $run < 2; $run++) {
      beantragt_content_update_10016();
      $this->assertSame($values, $field_storage->load('node.field_content_placement')->getSetting('allowed_values'));
      foreach ($legacy as $id => [$published_revision, $draft_revision]) {
        $node = $storage->load($id);
        $this->assertTrue($node->isPublished());
        $this->assertSame($published_revision, $node->getRevisionId());
        $this->assertSame('form_sidebar', $node->get('field_content_placement')->value);
        $pending = $storage->loadRevision($draft_revision);
        $this->assertFalse($pending->isPublished());
        $this->assertSame('form_sidebar', $pending->get('field_content_placement')->value);
        $this->assertSame((int) $draft_revision, (int) $storage->getLatestRevisionId($id));
      }
      $this->assertSame('form_intro', $storage->loadUnchanged($unrelated->id())->get('field_content_placement')->value);
    }

    $this->drupalGet($base . '/sections');
    $items = Json::decode($this->getSession()->getPage()->getContent())['data'];
    $this->assertSame(['form_sidebar'], array_column(array_column($items, 'attributes'), 'placement'));
    $this->drupalGet($base . '/documents');
    $attributes = Json::decode($this->getSession()->getPage()->getContent())['data'][0]['attributes'];
    $this->assertSame('form_sidebar', $attributes['placement']);
    $file_url = $base . '/documents/' . $document_uuid . '/file';
    $this->assertSame($file_url . '?placement=form_sidebar', $attributes['file']['url']);
    $this->drupalGet($file_url, ['query' => ['placement' => 'form_sidebar']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($file_url, ['query' => ['placement' => 'footer']]);
    $this->assertSession()->statusCodeEquals(404);
    foreach (['sections' => 'section.invalid_query', 'documents' => 'document.invalid_query'] as $resource => $error) {
      $this->drupalGet($base . '/' . $resource, ['query' => ['placement' => 'footer']]);
      $this->assertSession()->statusCodeEquals(400);
      $this->assertSame($error, Json::decode($this->getSession()->getPage()->getContent())['error']['code']);
    }
  }

  /**
   * Flood denials never persist after the requesting client's limit is cleared.
   */
  public function testFloodDenialsCannotPoisonPublicUrls(): void {
    $manager = $this->container->get('entity_type.manager');
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Flood test municipality',
      'field_slug' => 'flood-test',
      'status' => TRUE,
    ]);
    $schema = $manager->getStorage('form_schema')->create([
      'label' => 'Flood test form',
      'field_schema' => Json::encode([
        'title' => 'Flood test form',
        'sections' => [
          [
            'id' => 'details',
            'title' => 'Details',
            'fields' => [
              [
                'id' => 'subject',
                'type' => 'text',
                'label' => 'Subject',
                'privacy' => ['kind' => 'not_personal'],
              ],
            ],
          ],
        ],
      ]),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $schema->save();

    $document = $manager->getStorage('node')->create([
      'type' => 'public_document',
      'title' => 'Flood test document',
      'status' => TRUE,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_content_placement' => 'form_sidebar',
      'field_public_document_file' => ['target_id' => $this->createPublicPdf()],
    ]);
    $document->save();

    $paths = [
      '/api/v1/jurisdictions/flood-test/documents' => 'beantragt_content.public_documents',
      '/api/v1/jurisdictions/flood-test/sections' => 'beantragt_content.public_sections',
      '/api/v1/jurisdictions/flood-test/forms' => 'beantragt_schema.public_forms',
      '/api/v1/forms/' . $schema->uuid() . '?jurisdiction=flood-test' => 'beantragt_schema.public_form',
      '/api/v1/jurisdictions/flood-test/documents/' . $document->uuid() . '/file?placement=form_sidebar' => 'beantragt_content.public_document_download',
    ];
    // Limits per event, including the separate section and document limits.
    $limits = [
      'beantragt_content.public_documents' => 600,
      'beantragt_content.public_document_download' => 60,
      'beantragt_content.public_sections' => 600,
      'beantragt_schema.public_forms' => 60,
      'beantragt_schema.public_form' => 60,
    ];
    $database = $this->container->get('database');
    $flood = $this->container->get('flood');
    $page_cache = $this->container->get('cache.page');
    $observed = [];
    $expected = [];

    foreach ($paths as $path => $event) {
      $parts = parse_url($path);
      parse_str($parts['query'] ?? '', $query);
      // Core creates the flood table lazily on the first registered event.
      if ($database->schema()->tableExists('flood')) {
        $database->delete('flood')->execute();
      }
      $page_cache->deleteAll();
      $this->container->get('cache.dynamic_page_cache')->deleteAll();
      $this->drupalGet($parts['path'], ['query' => $query]);
      $this->assertSession()->statusCodeEquals(200);
      $identifier = $database->select('flood', 'f')->fields('f', ['identifier'])
        ->condition('event', $event)->execute()->fetchField();
      $this->assertIsString($identifier);

      // Prove page_cache is actually used, not bypassed by sessions or auth.
      if (!str_contains($path, '/file?')) {
        $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
        $this->drupalGet($parts['path'], ['query' => $query]);
        $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
      }

      // Fill the real flood table to one slot below the limit. The next HTTP
      // request consumes the final slot. No mock bypasses the flood service.
      $limit = $limits[$event];
      for ($attempt = 1; $attempt < $limit - 1; $attempt++) {
        $flood->register($event, 3600, $identifier);
      }
      $page_cache->deleteAll();
      $this->container->get('cache.dynamic_page_cache')->deleteAll();
      $this->drupalGet($parts['path'], ['query' => $query]);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertFalse($flood->isAllowed($event, $limit, 3600, $identifier));
      // Exhausting either document bucket leaves the other completely unused.
      $other_event = match ($event) {
        'beantragt_content.public_documents' => 'beantragt_content.public_document_download',
        'beantragt_content.public_document_download' => 'beantragt_content.public_documents',
        default => NULL,
      };
      if ($other_event !== NULL) {
        $this->assertSame(0, (int) $database->select('flood', 'f')->condition('event', $other_event)->countQuery()->execute()->fetchField());
        $this->assertTrue($flood->isAllowed($other_event, $limits[$other_event], 3600, $identifier));
      }
      // Discard successful responses from both caches to reach the controller.
      // There must be NO cache clear between the denial and the recovery.
      $page_cache->deleteAll();
      $this->container->get('cache.dynamic_page_cache')->deleteAll();
      $this->drupalGet($parts['path'], ['query' => $query]);
      $this->assertSession()->statusCodeEquals(429);
      $this->assertSame([
        'error' => [
          'code' => 'rate_limit.exceeded',
          'message' => 'Too many requests. Please try again later.',
        ],
        'meta' => ['apiVersion' => '1.0'],
      ], Json::decode($this->getSession()->getPage()->getContent()));
      $first_cache_header = $this->getSession()->getResponseHeader('X-Drupal-Cache');
      $first_cache_control = (string) $this->getSession()->getResponseHeader('Cache-Control');
      $this->assertNotSame('HIT', $first_cache_header, $path . ': a 429 must never be served from page_cache.');
      $this->assertStringContainsString('no-store', $first_cache_control, $path . ': the 429 must forbid storage in browsers and proxies.');
      $this->drupalGet($parts['path'], ['query' => $query]);
      $this->assertSession()->statusCodeEquals(429);
      $second_cache_header = $this->getSession()->getResponseHeader('X-Drupal-Cache');
      $second_cache_control = (string) $this->getSession()->getResponseHeader('Cache-Control');
      $this->assertNotSame('HIT', $second_cache_header, $path . ': a repeated 429 must never be served from page_cache.');
      $this->assertStringContainsString('no-store', $second_cache_control, $path . ': the repeated 429 must forbid storage.');
      // Both caches were emptied right before the denials, so any row
      // would be a stored 429.
      $this->assertSame(0, (int) $database->select('cache_page')->countQuery()->execute()->fetchField(), $path . ': page_cache stored a 429.');

      $database->delete('flood')->execute();
      // A fresh anonymous browser requests exactly the same URL, with the
      // existing page cache intact and the flood table empty.
      $this->getSession()->reset();
      $this->drupalGet($parts['path'], ['query' => $query]);
      $observed[$path] = [
        'recovered_status' => $this->getSession()->getStatusCode(),
        'first_429_cache' => $first_cache_header,
        'second_429_cache' => $second_cache_header,
        'first_no_store' => str_contains($first_cache_control, 'no-store'),
        'second_no_store' => str_contains($second_cache_control, 'no-store'),
      ];
      $expected[$path] = [
        'recovered_status' => 200,
        'first_429_cache' => 'UNCACHEABLE (no cacheability)',
        'second_429_cache' => 'UNCACHEABLE (no cacheability)',
        'first_no_store' => TRUE,
        'second_no_store' => TRUE,
      ];
      if ($this->getSession()->getStatusCode() === 200 && !str_contains($path, '/file?')) {
        $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'MISS');
        $this->drupalGet($parts['path'], ['query' => $query]);
        $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');
      }
    }

    $this->assertSame($expected, $observed, 'Every URL recovers without clearing page_cache; no 429 is a cache HIT.');
  }

  /**
   * Creates a synthetic file behind the public document route.
   */
  private function createPublicPdf(): int {
    $directory = 'private://public_documents';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = $directory . '/test.pdf';
    file_put_contents($uri, "%PDF-1.4\n% Synthetic public document.\n%%EOF\n");
    $file = $this->container->get('entity_type.manager')->getStorage('file')->create([
      'uri' => $uri,
      'filename' => 'test.pdf',
      'filemime' => 'application/pdf',
      'status' => 1,
    ]);
    $file->save();
    return (int) $file->id();
  }

}
