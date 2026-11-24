<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Controller\PublicFormApiController;
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
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the public v1 form API controller.
 */
#[CoversClass(PublicFormApiController::class)]
#[Group('beantragt_schema')]
final class PublicFormApiControllerTest extends UnitTestCase {

  /**
   * Form schema storage mock.
   */
  private EntityStorageInterface $formStorage;

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Flood control mock.
   */
  private FloodInterface $flood;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Controller under test.
   */
  private PublicFormApiController $controller;

  /**
   * Original Nuxt service user environment value.
   */
  private string|false $originalServiceUser;

  /**
   * Original Nuxt service password environment value.
   */
  private string|false $originalServicePassword;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->originalServiceUser = getenv('NUXT_DRUPAL_SERVICE_USER');
    $this->originalServicePassword = getenv('NUXT_DRUPAL_SERVICE_PASSWORD');
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $this->formStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->flood = $this->createMock(FloodInterface::class);
    $this->flood->method('isAllowed')->willReturn(TRUE);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'form_schema' => $this->formStorage,
        'group' => $this->groupStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $this->controller = new PublicFormApiController(
      $this->entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $this->flood,
      new KillSwitch(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_USER', $this->originalServiceUser);
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_PASSWORD', $this->originalServicePassword);

    parent::tearDown();
  }

  /**
   * Public form detail returns allowlisted schema data and external IDs.
   */
  public function testGetFormReturnsPublicProjection(): void {
    $jurisdiction = $this->mockGroup('jur', 3, '339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'musterstadt', 'Stadt Musterstadt');
    $organisation = $this->mockGroup('org', 4, 'cc81c10a-fd7b-4489-a5a1-81a4ef4e9704', 'citizen-services', 'Buergerbuero', $jurisdiction);
    $form = $this->mockForm(
      12,
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
      'Smoke Antrag: Buergerbuero',
      $this->schemaJson(),
      $jurisdiction,
      $organisation,
    );
    $this->formStorage->method('loadByProperties')->with(['uuid' => 'fbc3b086-ac53-412e-9bbf-ccb03f286923'])->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => match ([$properties['type'] ?? NULL, $properties['field_slug'] ?? NULL]) {
        ['jur', 'musterstadt'] => [$jurisdiction],
        ['org', 'citizen-services'] => [$organisation],
        default => [],
      }
    );

    $response = $this->controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923?jurisdiction=musterstadt&organisation=citizen-services'),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('form', $payload['meta']['resource']);
    $this->assertSame('fbc3b086-ac53-412e-9bbf-ccb03f286923', $payload['data']['id']);
    $this->assertSame('musterstadt', $payload['data']['jurisdiction']['slug']);
    $this->assertSame('citizen-services', $payload['data']['organisation']['slug']);
    $this->assertArrayNotHasKey('numericId', $payload['data']);
    $this->assertSame('required', $payload['data']['schema']['auth']['mode']);
    $this->assertSame(['passwordless', 'bundid'], $payload['data']['schema']['auth']['providers']);
    $this->assertSame('Smoke request', $payload['data']['schema']['i18n']['en']['title']);
    $this->assertSame('Public English copy', $payload['data']['schema']['i18n']['en']['description']);
    $this->assertArrayNotHasKey('private', $payload['data']['schema']['i18n']);
    $this->assertSame('Person', $payload['data']['schema']['sections'][0]['i18n']['en']['title']);
    $this->assertSame('Full name', $payload['data']['schema']['sections'][0]['fields'][0]['i18n']['en']['label']);
    $this->assertSame('Enter your full name', $payload['data']['schema']['sections'][0]['fields'][0]['i18n']['en']['placeholder']);
    $this->assertSame('full_name', $payload['data']['schema']['sections'][0]['fields'][0]['id']);
    $this->assertArrayNotHasKey('pattern', $payload['data']['schema']['sections'][0]['fields'][0]['validation']);
    $this->assertSame(1, $payload['data']['schema']['sections'][0]['fields'][0]['validation']['maxFiles']);
    $this->assertSame(10485760, $payload['data']['schema']['sections'][0]['fields'][0]['validation']['maxSizeBytes']);
    $this->assertTrue($payload['data']['schema']['sections'][0]['fields'][0]['sensitive']);
    $this->assertSame('special_category', $payload['data']['schema']['sections'][0]['fields'][0]['privacy']['kind']);
    $this->assertArrayNotHasKey('internalBasis', $payload['data']['schema']['sections'][0]['fields'][0]['privacy']);
    $this->assertArrayNotHasKey('crm_key', $payload['data']['schema']['sections'][0]['fields'][0]);
    $this->assertArrayNotHasKey('crm_key', $payload['data']['schema']['sections'][0]['fields'][1]);
    $this->assertArrayNotHasKey('options', $payload['data']['schema']['sections'][0]['fields'][1]);
    $this->assertArrayNotHasKey('options', $payload['data']['schema']['sections'][0]['fields'][1]['i18n']['en']);
    $this->assertTrue($payload['data']['schema']['sections'][0]['fields'][1]['sensitive']);
    $this->assertSame('financial', $payload['data']['schema']['sections'][0]['fields'][1]['privacy']['kind']);
    $this->assertSame('not_personal', $payload['data']['schema']['sections'][0]['fields'][2]['privacy']['kind']);
    $this->assertSame(['A' => 'Type A', 'B' => 'Type B'], $payload['data']['schema']['sections'][0]['fields'][2]['i18n']['en']['options']);
    $this->assertSame('address', $payload['data']['schema']['sections'][0]['fields'][3]['type']);
    $this->assertSame([
      'autocomplete' => FALSE,
      'map' => FALSE,
      'boundary' => 'off',
    ], $payload['data']['schema']['sections'][0]['fields'][3]['widget']);
    $this->assertSame('contact', $payload['data']['schema']['sections'][0]['fields'][3]['privacy']['kind']);
    $this->assertSame('person.address', $payload['data']['schema']['sections'][0]['fields'][3]['crm_key']);
    $this->assertArrayNotHasKey('crm_key', $payload['data']['schema']['sections'][0]['fields'][4]);
    $this->assertSame('person.postal_code', $payload['data']['schema']['sections'][0]['fields'][7]['crm_key']);
    $this->assertArrayNotHasKey('crm_key', $payload['data']['schema']['sections'][0]['fields'][9]);
    $this->assertSame('person.birthdate', $payload['data']['schema']['sections'][0]['fields'][10]['crm_key']);
    $this->assertSame('person.marital_status', $payload['data']['schema']['sections'][0]['fields'][11]['crm_key']);
    $this->assertArrayNotHasKey('crm_key', $payload['data']['schema']['sections'][0]['fields'][12]);
    // FIM forms ask for given and family name and for street and house
    // number apart; the house number marks the target of a split street.
    $this->assertSame('person.first_name', $payload['data']['schema']['sections'][0]['fields'][13]['crm_key']);
    $this->assertSame('person.last_name', $payload['data']['schema']['sections'][0]['fields'][14]['crm_key']);
    $this->assertSame('person.house_number', $payload['data']['schema']['sections'][0]['fields'][15]['crm_key']);
    $this->assertArrayNotHasKey('crm_key', $payload['data']['schema']['sections'][0]['fields'][16]);
    $this->assertSame([
      'autocomplete' => TRUE,
      'map' => TRUE,
      'boundary' => 'advisory',
    ], $payload['data']['schema']['sections'][0]['fields'][4]['widget']);
    $this->assertSame([
      [
        'id' => 'location_search',
        'fields' => [
          'street' => 'street',
          'houseNumber' => 'house_number',
          'postcode' => 'postcode',
          'city' => 'city',
        ],
        'autocomplete' => TRUE,
        'map' => FALSE,
        'boundary' => 'off',
        'label' => 'Adresse suchen',
      ],
    ], $payload['data']['schema']['sections'][0]['addressGroups']);
  }

  /**
   * Scope mismatch fails closed with a stable v1 error envelope.
   */
  public function testGetFormRejectsScopeMismatch(): void {
    $jurisdiction = $this->mockGroup('jur', 3, '339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'musterstadt', 'Stadt Musterstadt');
    $form = $this->mockForm(
      12,
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
      'Smoke Antrag: Buergerbuero',
      $this->schemaJson(),
      $jurisdiction,
    );
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => ($properties['type'] ?? NULL) === 'jur' && ($properties['field_slug'] ?? NULL) === 'musterstadt'
        ? [$jurisdiction]
        : []
    );

    $response = $this->controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923?jurisdiction=dunkelstadt'),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('form.not_found', $payload['error']['code']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Jurisdiction list only returns public forms in that jurisdiction.
   */
  public function testListJurisdictionFormsFiltersByJurisdiction(): void {
    $musterstadt = $this->mockGroup('jur', 3, '339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'musterstadt', 'Stadt Musterstadt');
    $sampletown = $this->mockGroup('jur', 9, '639d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'sampletown', 'Stadt Sampletown');
    $musterstadt_form = $this->mockForm(12, 'fbc3b086-ac53-412e-9bbf-ccb03f286923', 'Smoke Antrag: Buergerbuero', $this->schemaJson(), $musterstadt);
    $sampletown_form = $this->mockForm(13, '3358c25f-ee80-4b11-ba5d-a29eda5fdbce', 'Sampletown Formular', $this->schemaJson('Sampletown Formular'), $sampletown);

    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => ($properties['type'] ?? NULL) === 'jur' && ($properties['field_slug'] ?? NULL) === 'musterstadt'
        ? [$musterstadt]
        : []
    );
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([12, 13]);
    $this->formStorage->method('getQuery')->willReturn($query);
    $this->formStorage->method('loadMultiple')->with([12, 13])->willReturn([$musterstadt_form, $sampletown_form]);

    $response = $this->controller->listJurisdictionForms(Request::create('/api/v1/jurisdictions/musterstadt/forms'), 'musterstadt');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('forms', $payload['meta']['resource']);
    $this->assertSame(1, $payload['meta']['count']);
    $this->assertSame('fbc3b086-ac53-412e-9bbf-ccb03f286923', $payload['data'][0]['id']);
    $this->assertSame('/musterstadt/form/fbc3b086-ac53-412e-9bbf-ccb03f286923', $payload['data'][0]['path']);
    $this->assertSame('Smoke request', $payload['data'][0]['i18n']['en']['title']);
    $this->assertContains('Full name', $payload['data'][0]['keywords']);
  }

  /**
   * Core-unpublished form entities are never exposed through direct UUID reads.
   */
  public function testGetFormRejectsCoreUnpublishedForm(): void {
    $jurisdiction = $this->mockGroup('jur', 3, '339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'musterstadt', 'Stadt Musterstadt');
    $form = $this->mockForm(
      12,
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
      'Smoke Antrag: Buergerbuero',
      $this->schemaJson(),
      $jurisdiction,
      published: FALSE,
    );
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => ($properties['type'] ?? NULL) === 'jur' && ($properties['field_slug'] ?? NULL) === 'musterstadt'
        ? [$jurisdiction]
        : []
    );

    $response = $this->controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923?jurisdiction=musterstadt'),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('form.not_found', $payload['error']['code']);
  }

  /**
   * Forms scoped to unpublished workspaces fail closed.
   */
  public function testGetFormRejectsUnpublishedScopeGroup(): void {
    $jurisdiction = $this->mockGroup('jur', 3, '339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'musterstadt', 'Stadt Musterstadt', published: FALSE);
    $form = $this->mockForm(
      12,
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
      'Smoke Antrag: Buergerbuero',
      $this->schemaJson(),
      $jurisdiction,
    );
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => ($properties['type'] ?? NULL) === 'jur' && ($properties['field_slug'] ?? NULL) === 'musterstadt'
        ? [$jurisdiction]
        : []
    );

    $response = $this->controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923'),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('form.not_found', $payload['error']['code']);
  }

  /**
   * Unknown query parameters fail closed before they can expand cache keys.
   */
  public function testGetFormRejectsUnknownQueryParameters(): void {
    $response = $this->controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923?cachebuster=1'),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('form.not_found', $payload['error']['code']);
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    $this->assertContains('url.query_args', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Unknown list query parameters are uncacheable.
   */
  public function testListFormsRejectsUnknownQueryParametersWithoutCaching(): void {
    $response = $this->controller->listJurisdictionForms(
      Request::create('/api/v1/jurisdictions/musterstadt/forms?cachebuster=1'),
      'musterstadt',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('jurisdiction.not_found', $payload['error']['code']);
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    $this->assertContains('url.query_args', $response->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * Public form rate limits use the stable v1 error envelope.
   */
  public function testGetFormRateLimitUsesV1Envelope(): void {
    $flood = $this->createMock(FloodInterface::class);
    // Counted per address: the once-per-hour warning guard is asked first.
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static fn (...$arguments): bool => match ($arguments) {
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_schema.public_form'] => FALSE,
        ['beantragt_schema.public_form', 60, 3600, '198.51.100.22'] => FALSE,
        default => self::fail('Unexpected flood arguments: ' . json_encode($arguments)),
      });
    $flood->expects($this->never())->method('register');
    $kill_switch = $this->createMock(KillSwitch::class);
    $kill_switch->expects($this->once())->method('trigger');
    $controller = new PublicFormApiController(
      $this->entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $flood,
      $kill_switch,
    );

    $response = $controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.22']),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Signed Nuxt proxy form reads use a stable public flood identifier.
   */
  public function testGetFormRateLimitAcceptsSignedProxyIdentifier(): void {
    $proxy_identifier = '0123456789abcdef0123456789abcdef';
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_schema.public_form', 60, 3600, 'proxy:' . $proxy_identifier)
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');
    $controller = new PublicFormApiController(
      $this->entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $flood,
      new KillSwitch(),
    );

    $response = $controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.22',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $proxy_identifier,
      ]),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
  }

  /**
   * Unsigned Nuxt proxy form read identifiers fall back to client IP.
   */
  public function testGetFormRateLimitRejectsSpoofedProxyIdentifier(): void {
    $flood = $this->createMock(FloodInterface::class);
    // Counted per address: the once-per-hour warning guard is asked first.
    $flood->expects($this->exactly(2))
      ->method('isAllowed')
      ->willReturnCallback(static fn (...$arguments): bool => match ($arguments) {
        ['beantragt_schema.flood_fallback_warning', 1, 3600, 'beantragt_schema.public_form'] => FALSE,
        ['beantragt_schema.public_form', 60, 3600, '198.51.100.22'] => FALSE,
        default => self::fail('Unexpected flood arguments: ' . json_encode($arguments)),
      });
    $flood->expects($this->never())->method('register');
    $controller = new PublicFormApiController(
      $this->entityTypeManager,
      $this->createMock(LoggerInterface::class),
      $flood,
      new KillSwitch(),
    );

    $response = $controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923', 'GET', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.22',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:wrong-secret'),
        'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => '0123456789abcdef0123456789abcdef',
      ]),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
  }

  /**
   * Conditional metadata only references projected public fields.
   */
  public function testGetFormDropsConditionalsReferencingHiddenFields(): void {
    $jurisdiction = $this->mockGroup('jur', 3, '339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', 'musterstadt', 'Stadt Musterstadt');
    $form = $this->mockForm(
      12,
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
      'Smoke Antrag: Buergerbuero',
      $this->schemaJsonWithConditionals(),
      $jurisdiction,
    );
    $this->formStorage->method('loadByProperties')->willReturn([$form]);
    $this->groupStorage->method('loadByProperties')->willReturnCallback(
      static fn(array $properties): array => ($properties['type'] ?? NULL) === 'jur' && ($properties['field_slug'] ?? NULL) === 'musterstadt'
        ? [$jurisdiction]
        : []
    );

    $response = $this->controller->getForm(
      Request::create('/api/v1/forms/fbc3b086-ac53-412e-9bbf-ccb03f286923?jurisdiction=musterstadt'),
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);
    $fields = $payload['data']['schema']['sections'][0]['fields'];

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertArrayNotHasKey('conditionalOn', $fields[0]);
    $this->assertSame(['fieldId' => 'kind', 'value' => 'A'], $fields[2]['conditionalOn']);
  }

  /**
   * Both valid and rejected defaults pass through the real field projection.
   */
  public function testPublicFieldDefaults(): void {
    $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/public-field-default-cases.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $project = new \ReflectionMethod($this->controller, 'fieldProjections');
    foreach ($cases as $index => $case) {
      if ($case['field']['type'] === 'internal') {
        continue;
      }
      $field = [
        'id' => 'default_' . $index,
        'label' => $case['name'],
        'defaultValue' => $case['value'],
      ] + $case['field'];
      $projected = $project->invoke($this->controller, [$field]);
      if ($case['name'] === 'choice after hundredth option') {
        $this->assertCount(101, $projected[0]['options']);
        $this->assertSame('Type 100', $projected[0]['i18n']['en']['options']['option_100']);
      }
      if ($case['valid']) {
        $this->assertSame($case['value'], $projected[0]['defaultValue'], $case['name']);
      }
      else {
        $this->assertArrayNotHasKey('defaultValue', $projected[0], $case['name']);
      }
    }
  }

  /**
   * Builds a form schema mock.
   */
  private function mockForm(
    int $id,
    string $uuid,
    string $label,
    string $schema,
    GroupInterface $jurisdiction,
    ?GroupInterface $organisation = NULL,
    bool $published = TRUE,
  ): FormSchemaInterface {
    $fields = [
      'field_description' => $this->fieldList('Public description'),
      'field_jurisdiction' => $this->entityReference($jurisdiction),
      'field_organisation' => $organisation instanceof GroupInterface ? $this->entityReference($organisation) : $this->emptyField(),
      'field_group' => $this->emptyField(),
    ];
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn($id);
    $form->method('uuid')->willReturn($uuid);
    $form->method('label')->willReturn($label);
    $form->method('isPublished')->willReturn($published);
    $form->method('getSchemaStatus')->willReturn('published');
    $form->method('getPublicationScope')->willReturn('internet');
    $form->method('getVersion')->willReturn(4);
    $form->method('getSchema')->willReturn($schema);
    $form->method('getCacheTags')->willReturn(['form_schema:' . $id]);
    $form->method('getCacheContexts')->willReturn([]);
    $form->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $form->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $form->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $form;
  }

  /**
   * Builds a group mock.
   */
  private function mockGroup(
    string $bundle,
    int $id,
    string $uuid,
    string $slug,
    string $label,
    ?GroupInterface $jurisdiction = NULL,
    bool $published = TRUE,
  ): GroupInterface {
    $fields = [
      'field_slug' => $this->fieldList($slug),
      'field_jurisdiction' => $jurisdiction instanceof GroupInterface ? $this->entityReference($jurisdiction) : $this->emptyField(),
    ];
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('id')->willReturn($id);
    $group->method('uuid')->willReturn($uuid);
    $group->method('label')->willReturn($label);
    $group->method('isPublished')->willReturn($published);
    $group->method('getCacheTags')->willReturn(['group:' . $id]);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $group->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $group;
  }

  /**
   * Builds a field list with a scalar value.
   */
  private function fieldList(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an entity reference field list.
   */
  private function entityReference(GroupInterface $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => match ($property) {
        'entity' => $entity,
        'target_id' => $entity->id(),
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

  /**
   * Builds schema fixture JSON.
   */
  private function schemaJson(string $title = 'Smoke Antrag: Buergerbuero'): string {
    return (string) json_encode([
      'id' => 'internal-demo',
      'title' => $title,
      'description' => 'Public German copy',
      'version' => 1,
      'i18n' => [
        'en' => [
          'title' => 'Smoke request',
          'description' => 'Public English copy',
          'internalCopy' => 'Hidden',
        ],
        'private' => [
          'title' => 'Hidden locale',
        ],
      ],
      'auth' => [
        'mode' => 'owner-only',
        'providers' => ['passwordless', 'not-a-provider', 'bundid'],
        'collect' => [
          'fullName' => 'required',
          'email' => 'optional',
        ],
      ],
      'sections' => [
        [
          'id' => 'person',
          'title' => 'Person',
          'i18n' => [
            'en' => [
              'title' => 'Person',
              'description' => 'Personal details',
              'internalCopy' => 'Hidden',
            ],
          ],
          'fields' => [
            [
              'id' => 'full_name',
              'type' => 'text',
              'label' => 'Name',
              'i18n' => [
                'en' => [
                  'label' => 'Full name',
                  'placeholder' => 'Enter your full name',
                  'internalCopy' => 'Hidden',
                ],
              ],
              'required' => TRUE,
              'crm_key' => 'person.full_name',
              'sensitive' => ' TRUE ',
              'privacy' => [
                'kind' => 'special-category',
                'internalBasis' => 'hidden',
              ],
              'validation' => [
                'minLength' => 3,
                'maxFiles' => 1,
                'maxSizeBytes' => 10485760,
                'pattern' => '^.+$',
              ],
            ],
            [
              'id' => 'iban',
              'type' => 'text',
              'label' => 'IBAN',
              'options' => ['A', 'B'],
              'i18n' => [
                'en' => [
                  'label' => 'IBAN',
                  'options' => [
                    'A' => 'Type A',
                  ],
                ],
              ],
              'crm_key' => 'person.iban',
              'sensitive' => 1,
              'privacy' => [
                'kind' => 'Financial',
              ],
            ],
            [
              'id' => 'kind',
              'type' => 'select',
              'label' => 'Art',
              'options' => ['A', 'B'],
              'privacy' => [
                'kind' => 'not_personal',
              ],
              'i18n' => [
                'en' => [
                  'label' => 'Type',
                  'options' => [
                    'A' => 'Type A',
                    'B' => 'Type B',
                    'C' => 'Hidden retired type',
                  ],
                ],
              ],
            ],
            [
              'id' => 'subject_address',
              'type' => 'address',
              'label' => 'Adresse des Vorhabens',
              'crm_key' => 'person.address',
              'widget' => [
                'autocomplete' => FALSE,
                'privateProvider' => 'hidden',
              ],
              'privacy' => [
                'kind' => 'contact',
              ],
            ],
            [
              'id' => 'event_address',
              'type' => 'address',
              'label' => 'Veranstaltungsort',
              'crm_key' => 'person.address',
              'widget' => [
                'autocomplete' => TRUE,
                'map' => TRUE,
                'boundary' => 'advisory',
              ],
            ],
            ['id' => 'street', 'type' => 'text', 'label' => 'Straße'],
            ['id' => 'house_number', 'type' => 'text', 'label' => 'Hausnummer'],
            [
              'id' => 'postcode',
              'type' => 'text',
              'format' => 'postal_code',
              'label' => 'Postleitzahl',
              'crm_key' => 'person.postal_code',
              'privacy' => ['kind' => 'contact'],
            ],
            ['id' => 'city', 'type' => 'text', 'label' => 'Ort'],
            [
              'id' => 'incompatible_city',
              'type' => 'select',
              'label' => 'Ort aus Liste',
              'crm_key' => 'person.city',
              'privacy' => ['kind' => 'contact'],
            ],
            [
              'id' => 'birthdate',
              'type' => 'date',
              'label' => 'Geburtsdatum',
              'crm_key' => 'person.birthdate',
              'privacy' => ['kind' => 'identity'],
            ],
            [
              'id' => 'marital_status',
              'type' => 'select',
              'label' => 'Familienstand',
              'options' => ['ledig', 'verheiratet'],
              'crm_key' => 'person.marital_status',
              'privacy' => ['kind' => 'identity'],
            ],
            [
              'id' => 'birthdate_as_text',
              'type' => 'text',
              'label' => 'Geburtsdatum als Text',
              'crm_key' => 'person.birthdate',
              'privacy' => ['kind' => 'identity'],
            ],
            [
              'id' => 'given_name',
              'type' => 'text',
              'label' => 'Vorname',
              'crm_key' => 'person.first_name',
              'privacy' => ['kind' => 'identity'],
            ],
            [
              'id' => 'family_name',
              'type' => 'text',
              'label' => 'Familienname',
              'crm_key' => 'person.last_name',
              'privacy' => ['kind' => 'identity'],
            ],
            [
              'id' => 'applicant_house_number',
              'type' => 'text',
              'label' => 'Hausnummer',
              'crm_key' => 'person.house_number',
              'privacy' => ['kind' => 'contact'],
            ],
            [
              'id' => 'house_number_as_date',
              'type' => 'date',
              'label' => 'Hausnummer als Datum',
              'crm_key' => 'person.house_number',
              'privacy' => ['kind' => 'contact'],
            ],
          ],
          'addressGroups' => [
            [
              'id' => 'location_search',
              'label' => 'Adresse suchen',
              'fields' => [
                'street' => 'street',
                'houseNumber' => 'house_number',
                'postcode' => 'postcode',
                'city' => 'city',
              ],
              'autocomplete' => TRUE,
              'map' => FALSE,
              'boundary' => 'off',
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Builds schema fixture JSON with public and hidden conditional sources.
   */
  private function schemaJsonWithConditionals(): string {
    return (string) json_encode([
      'title' => 'Smoke Antrag: Buergerbuero',
      'sections' => [
        [
          'id' => 'person',
          'title' => 'Person',
          'fields' => [
            [
              'id' => 'full_name',
              'type' => 'text',
              'label' => 'Name',
              'conditionalOn' => [
                'fieldId' => 'internal_case_type',
                'value' => 'secret',
              ],
            ],
            [
              'id' => 'internal_case_type',
              'type' => 'internal',
              'label' => 'Internal case type',
            ],
            [
              'id' => 'kind',
              'type' => 'select',
              'label' => 'Art',
              'options' => ['A', 'B'],
            ],
            [
              'id' => 'details',
              'type' => 'textarea',
              'label' => 'Details',
              'conditionalOn' => [
                'fieldId' => 'kind',
                'value' => 'A',
              ],
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Restores an environment variable to its original value.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }
    putenv($name . '=' . $value);
  }

}
