<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Kernel;

use Drupal\beantragt_delivery\Service\Postbox\PostboxTransportInterface;
use Drupal\beantragt_docgen\Controller\DocgenCatalogController;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_schema\Controller\PublicFormApiController;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\group\Entity\GroupInterface;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivery offer of a form: storage, PUT rules and the computed public offer.
 */
#[Group('beantragt_docgen')]
final class FormDeliverySettingsKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'options', 'jsonapi', 'json_form_widget',
    'serialization', 'language', 'beantragt_roles_api', 'beantragt_schema',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_notification', 'beantragt_workflow', 'beantragt_delivery',
    'beantragt_docgen',
  ];

  /**
   * A template family stored on the form before the delivery PUTs.
   */
  private const FAMILY = '0b6c3e6a-0000-4000-8000-000000000001';

  /**
   * The published internet form under test.
   */
  private FormSchemaInterface $form;

  /**
   * The settings repository.
   */
  private DocgenSettingsRepository $settings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('docgen_template');
    $this->installSchema('beantragt_docgen', array_keys(DocumentRepository::schema() + DocgenSettingsRepository::schema()));
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->container->get('entity_type.manager'));
    $this->setCurrentUser($this->createUser([
      'administer docgen templates',
      'bypass beantragt group scope',
      'view published form_schema entities',
    ]));
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Stadt Musterstadt', 'field_slug' => 'musterstadt']);
    $this->form = $this->createForm($jurisdiction);
    $this->settings = $this->container->get('beantragt_docgen.settings');
  }

  /**
   * Update 10001 adds both columns with their defaults to existing rows.
   */
  public function testUpdateAddsDeliveryColumns(): void {
    $schema = $this->container->get('database')->schema();
    $old = DocgenSettingsRepository::schema()['beantragt_docgen_form'];
    $old['fields'] = array_diff_key($old['fields'], DocgenSettingsRepository::deliveryFields());
    $schema->dropTable('beantragt_docgen_form');
    $schema->createTable('beantragt_docgen_form', $old);
    $this->container->get('database')->insert('beantragt_docgen_form')->fields([
      'form_uuid' => $this->form->uuid(),
      'decision_route' => 'template',
      'template_families' => json_encode([self::FAMILY]),
    ])->execute();
    self::assertFalse($schema->fieldExists('beantragt_docgen_form', 'delivery_routes'));

    $this->container->get('module_handler')->loadInclude('beantragt_docgen', 'install');
    beantragt_docgen_update_10001();
    beantragt_docgen_update_10001();

    self::assertTrue($schema->fieldExists('beantragt_docgen_form', 'delivery_routes'));
    self::assertTrue($schema->fieldExists('beantragt_docgen_form', 'delivery_default'));
    $columns = ['delivery_routes', 'delivery_default'];
    $row = $this->container->get('database')->select('beantragt_docgen_form', 'f')->fields('f', $columns)->execute()->fetchAssoc();
    self::assertSame(['delivery_routes' => '', 'delivery_default' => ''], $row);
    $settings = $this->settings->formSettings((string) $this->form->uuid());
    self::assertSame('template', $settings['route']);
    self::assertSame([self::FAMILY], $settings['templates']);
    self::assertSame(['routes' => [], 'default' => ''], $settings['delivery']);
  }

  /**
   * PUT keys are independently optional; the rules refuse bundid defaults.
   */
  public function testPutKeepsWhatItDoesNotName(): void {
    $uuid = (string) $this->form->uuid();
    $this->settings->saveFormSettings($uuid, 'template', [self::FAMILY], 1);

    $data = $this->put(['delivery' => ['routes' => ['postal', 'portal', 'bundid'], 'default' => 'portal']]);
    self::assertSame([
      'configured' => TRUE,
      'routes' => ['portal', 'postal', 'bundid'],
      'default' => 'portal',
      'availableRoutes' => ['portal', 'postal'],
    ], $data['delivery']);
    self::assertSame('template', $data['route']);
    $stored = $this->settings->formSettings($uuid);
    self::assertSame(['template', [self::FAMILY]], [$stored['route'], $stored['templates']]);

    // Route alone keeps templates and delivery; no templates key is no reset.
    $data = $this->put(['route' => 'case_system']);
    self::assertSame('case_system', $data['route']);
    $stored = $this->settings->formSettings($uuid);
    self::assertSame([self::FAMILY], $stored['templates']);
    self::assertSame(['portal', 'postal', 'bundid'], $stored['delivery']['routes']);

    // An empty body changes nothing.
    $changed = $stored['changed'];
    $this->put([]);
    self::assertSame($stored, $this->settings->formSettings($uuid));
    self::assertSame($changed, $this->settings->formSettings($uuid)['changed']);

    $this->assertRefused(['delivery' => ['routes' => ['portal', 'bundid'], 'default' => 'bundid']], 'deliveryDefault');
    $this->assertRefused(['delivery' => ['routes' => ['bundid'], 'default' => 'bundid']], 'deliveryRoutes');
    $this->assertRefused(['delivery' => ['routes' => ['portal', 'fax'], 'default' => 'portal']], 'deliveryRoutes');
    $this->assertRefused(['delivery' => ['routes' => ['portal', 'portal'], 'default' => 'portal']], 'deliveryRoutes');
    $this->assertRefused(['delivery' => ['routes' => ['portal'], 'default' => 'postal']], 'deliveryDefault');
    $this->assertRefused(['delivery' => ['routes' => [], 'default' => 'portal']], 'deliveryDefault');
    $this->assertRefused(['delivery' => ['routes' => ['portal']]], 'deliveryDefault');
    $this->assertRefused(['delivery' => ['routes' => ['portal'], 'default' => 'portal', 'consent' => TRUE]], 'deliveryRoutes');
    $this->assertRefused(['delivery' => 'portal'], 'deliveryRoutes');
    $this->assertRefused(['delivery' => ['portal']], 'deliveryRoutes');
    self::assertSame(['portal', 'postal', 'bundid'], $this->settings->formSettings($uuid)['delivery']['routes']);

    // Not configured again.
    $data = $this->put(['delivery' => ['routes' => [], 'default' => '']]);
    self::assertSame(['configured' => FALSE, 'routes' => [], 'default' => '', 'availableRoutes' => ['portal', 'postal']], $data['delivery']);
    self::assertSame([self::FAMILY], $this->settings->formSettings($uuid)['templates']);

    // A connected postbox makes bundid available, also as the default.
    $this->container->set('beantragt_delivery.postbox_transport', $this->postbox(TRUE));
    $this->container->set('beantragt_docgen.form_templates', NULL);
    $data = $this->put(['delivery' => ['routes' => ['bundid'], 'default' => 'bundid']]);
    self::assertSame([
      'configured' => TRUE,
      'routes' => ['bundid'],
      'default' => 'bundid',
      'availableRoutes' => ['portal', 'postal', 'bundid'],
    ], $data['delivery']);

    // The repository refuses malformed offers of direct callers (seeds).
    $malformed = [
      ['routes' => ['fax'], 'default' => 'fax'],
      ['routes' => ['portal'], 'default' => 'postal'],
      ['routes' => ['portal', 'portal'], 'default' => 'portal'],
    ];
    foreach ($malformed as $offer) {
      try {
        $this->settings->saveFormSettings($uuid, NULL, NULL, 1, $offer);
        self::fail('A malformed offer was stored.');
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
    // A new row without a route gets the column default.
    $other = $this->createForm($this->createGroup(['type' => 'jur', 'label' => 'Other', 'field_slug' => 'other']));
    $postal = ['routes' => ['postal'], 'default' => 'postal'];
    $this->settings->saveFormSettings((string) $other->uuid(), NULL, NULL, 1, $postal);
    $stored = $this->settings->formSettings((string) $other->uuid());
    self::assertSame(['none', [], $postal], [$stored['route'], $stored['templates'], $stored['delivery']]);
  }

  /**
   * The public offer filters bundid and follows every save, also when cached.
   */
  public function testPublicOfferFollowsTheSettings(): void {
    $uuid = (string) $this->form->uuid();
    self::assertSame('{"configured":false}', $this->normalized()['value']);

    $all = ['routes' => ['portal', 'postal', 'bundid'], 'default' => 'portal'];
    $this->settings->saveFormSettings($uuid, NULL, NULL, 1, $all);
    $normalized = $this->normalized();
    self::assertSame('{"configured":true,"routes":["portal","postal"],"default":"portal"}', $normalized['value']);
    self::assertContains('beantragt_docgen_form:' . $uuid, $normalized['tags']);

    // Only bundid stored and no postbox: the public form is unconfigured.
    $this->container->set('beantragt_delivery.postbox_transport', $this->postbox(TRUE));
    $this->container->set('beantragt_docgen.form_templates', NULL);
    $this->settings->saveFormSettings($uuid, NULL, NULL, 1, ['routes' => ['bundid'], 'default' => 'bundid']);
    self::assertSame('{"configured":true,"routes":["bundid"],"default":"bundid"}', $this->normalized()['value']);
    $this->container->set('beantragt_delivery.postbox_transport', $this->postbox(FALSE));
    $this->container->set('beantragt_docgen.form_templates', NULL);
    self::assertSame('{"configured":false}', $this->reload()->get('field_delivery_offer')->value);

    // The v1 public form carries the offer and its cache tag.
    $this->settings->saveFormSettings($uuid, NULL, NULL, 1, ['routes' => ['portal', 'postal'], 'default' => 'postal']);
    $response = $this->publicForm();
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    self::assertSame(['configured' => TRUE, 'routes' => ['portal', 'postal'], 'default' => 'postal'], json_decode((string) $response->getContent(), TRUE)['data']['deliveryOffer']);
    self::assertContains('beantragt_docgen_form:' . $uuid, $response->getCacheableMetadata()->getCacheTags());
    $this->settings->saveFormSettings($uuid, NULL, NULL, 1, ['routes' => [], 'default' => '']);
    self::assertSame(['configured' => FALSE], json_decode((string) $this->publicForm()->getContent(), TRUE)['data']['deliveryOffer']);

    // Deleting the form drops its settings and invalidates the offer.
    $this->settings->saveFormSettings($uuid, NULL, NULL, 1, ['routes' => ['portal'], 'default' => 'portal']);
    self::assertSame('{"configured":true,"routes":["portal"],"default":"portal"}', $this->normalized()['value']);
    $this->settings->deleteForm($uuid);
    self::assertSame('{"configured":false}', $this->normalized()['value']);
  }

  /**
   * Sends a PUT through the staff controller.
   *
   * @return array<string, mixed>
   *   The data of a 200 response.
   */
  private function put(array $body): array {
    $response = $this->send($body);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE)['data'];
  }

  /**
   * Asserts a 422 for one field and an unchanged offer.
   */
  private function assertRefused(array $body, string $field): void {
    $response = $this->send($body);
    self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    $error = json_decode((string) $response->getContent(), TRUE)['error'];
    self::assertSame(['docgen.input_invalid', $field], [$error['code'], $error['details']['field'] ?? NULL], json_encode($body));
  }

  /**
   * Sends a PUT {"data": ...} with a CSRF token.
   */
  private function send(array $body): Response {
    $request = Request::create('/api/admin/docgen/forms/' . $this->form->uuid(), 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['data' => (object) $body], JSON_THROW_ON_ERROR));
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    return DocgenCatalogController::create($this->container)->formSettings($request, (string) $this->form->uuid());
  }

  /**
   * The JSON:API normalization of the computed offer.
   *
   * @return array{value: string, tags: string[]}
   *   Value and cache tags.
   */
  private function normalized(): array {
    $resource_type = $this->container->get('jsonapi.resource_type.repository')->get('form_schema', 'form_schema');
    $normalization = $this->container->get('jsonapi.serializer')->normalize(
      ResourceObject::createFromEntity($resource_type, $this->reload()),
      'api_json',
      [
        'account' => $this->container->get('current_user'),
        'resource_type' => $resource_type,
        'sparse_fieldset' => [$resource_type->getTypeName() => ['field_delivery_offer']],
      ],
    );
    self::assertInstanceOf(CacheableNormalization::class, $normalization);
    return [
      'value' => $normalization->getNormalization()['attributes']['field_delivery_offer'],
      'tags' => $normalization->getCacheTags(),
    ];
  }

  /**
   * GET /api/v1/forms/{uuid}.
   */
  private function publicForm(): CacheableJsonResponse {
    // Each request loads the form afresh; the computed offer lives on the
    // loaded entity object.
    $this->container->get('entity_type.manager')->getStorage('form_schema')->resetCache([$this->form->id()]);
    $response = PublicFormApiController::create($this->container)->getForm(Request::create('/api/v1/forms/' . $this->form->uuid()), (string) $this->form->uuid());
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    return $response;
  }

  /**
   * Reloads the form past the entity cache.
   */
  private function reload(): FormSchemaInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('form_schema');
    $storage->resetCache([$this->form->id()]);
    $form = $storage->load($this->form->id());
    self::assertInstanceOf(FormSchemaInterface::class, $form);
    return $form;
  }

  /**
   * A postbox transport double.
   */
  private function postbox(bool $available): PostboxTransportInterface {
    return new class($available) implements PostboxTransportInterface {

      /**
       * Constructs the double.
       */
      public function __construct(private readonly bool $available) {}

      /**
       * {@inheritdoc}
       */
      public function available(?GroupInterface $scope): bool {
        return $this->available;
      }

    };
  }

  /**
   * Creates a published internet form of a jurisdiction.
   */
  private function createForm(GroupInterface $jurisdiction): FormSchemaInterface {
    $form = $this->container->get('entity_type.manager')->getStorage('form_schema')->create([
      'label' => 'Bewohnerparkausweis beantragen',
      'field_schema' => json_encode([
        'title' => 'Bewohnerparkausweis beantragen',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => [
              ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'privacy' => ['kind' => 'identity']],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $form->save();
    self::assertInstanceOf(FormSchemaInterface::class, $form);
    return $form;
  }

}
