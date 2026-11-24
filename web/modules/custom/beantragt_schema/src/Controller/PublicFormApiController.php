<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Controller;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Service\PublicFieldDefault;
use Drupal\beantragt_schema\Trait\PublicApiFloodTrait;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public v1 form catalogue and schema API.
 */
final class PublicFormApiController extends ControllerBase {

  use PublicApiFloodTrait;

  /**
   * Max public form API requests per IP per hour.
   */
  private const FLOOD_LIMIT = 60;

  /**
   * Flood window in seconds.
   */
  private const FLOOD_WINDOW = 3600;

  /**
   * Public slug pattern shared by jurisdiction and organisation slugs.
   */
  private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  /**
   * Public UUID pattern for form schema resources.
   */
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

  /**
   * Public locale key pattern for schema translations.
   */
  private const LOCALE_KEY_PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i';

  /**
   * Max number of locale maps projected publicly.
   */
  private const MAX_PUBLIC_I18N_LOCALES = 16;

  /**
   * Max localized text length projected publicly.
   */
  private const MAX_PUBLIC_I18N_TEXT_LENGTH = 5000;

  /**
   * Field types exposed by the public form contract.
   */
  private const PUBLIC_FIELD_TYPES = [
    'text',
    'textarea',
    'email',
    'tel',
    'number',
    'date',
    'select',
    'radio',
    'checkbox',
    'address',
    'file',
  ];

  /**
   * Public field types that may expose canonical options.
   */
  private const PUBLIC_OPTION_FIELD_TYPES = [
    'select',
    'radio',
    'checkbox',
  ];

  /**
   * Field formats exposed by the public form contract.
   */
  private const PUBLIC_FIELD_FORMATS = [
    'postal_code',
    'iban',
    'bic',
    'tax_id',
    'currency',
    'reference_number',
    'url',
  ];

  /**
   * Privacy classifications exposed by the public form contract.
   */
  private const PUBLIC_FIELD_PRIVACY_KINDS = [
    'not_personal',
    'contact',
    'identity',
    'financial',
    'special_category',
    'criminal_offense',
    'other_personal',
  ];

  /**
   * Reusable profile CRM keys and their compatible public field types.
   *
   * Date of birth, gender, nationality and marital status are registered
   * data covered by the contact consent; a client prefills a select or radio
   * field only with a value that matches one of its options. The house number
   * has no profile field: it marks the target of the number a client splits
   * off the stored street, as FIM forms ask for street and number apart.
   */
  private const PUBLIC_PROFILE_CRM_FIELD_TYPES = [
    'person.full_name' => ['text'],
    'person.first_name' => ['text'],
    'person.last_name' => ['text'],
    'person.email' => ['email'],
    'person.phone' => ['tel'],
    'person.address' => ['address', 'textarea', 'text'],
    'person.street' => ['text', 'textarea'],
    'person.house_number' => ['text'],
    'person.postal_code' => ['text'],
    'person.city' => ['text'],
    'person.birthdate' => ['date'],
    'person.gender' => ['select', 'radio', 'text'],
    'person.nationality' => ['select', 'text'],
    'person.marital_status' => ['select', 'radio', 'text'],
  ];

  /**
   * Privacy kinds eligible for cross-form profile prefill.
   */
  private const PUBLIC_PROFILE_PRIVACY_KINDS = [
    'contact',
    'identity',
  ];

  /**
   * Supported public submission auth modes.
   */
  private const PUBLIC_AUTH_MODES = [
    'anonymous',
    'optional',
    'required',
  ];

  /**
   * Supported public auth providers.
   */
  private const PUBLIC_AUTH_PROVIDERS = [
    'passwordless',
    'bundid',
  ];

  /**
   * Supported identity collection requirements.
   */
  private const PUBLIC_IDENTITY_REQUIREMENTS = [
    'required',
    'optional',
    'none',
  ];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected readonly LoggerInterface $logger,
    protected readonly FloodInterface $flood,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.channel.beantragt_schema'),
      $container->get('flood'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Lists public forms for a jurisdiction and optional organisation.
   */
  public function listJurisdictionForms(Request $request, string $slug): JsonResponse {
    $cache_metadata = $this->basePublicCacheMetadata()
      ->addCacheTags(['group_list', 'form_schema_list']);

    if (!$this->allowedQueryKeys($request, ['organisation', 'organization', 'org'])) {
      return $this->publicApiErrorResponse('jurisdiction.not_found', 'Jurisdiction not found.', Response::HTTP_NOT_FOUND, $this->unknownQueryCacheMetadata());
    }
    $flood_response = $this->floodResponse($request, 'beantragt_schema.public_forms');
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $jurisdiction_slug = $this->normalizeSlug($slug);
    $jurisdiction = $jurisdiction_slug !== NULL ? $this->loadPublishedGroupBySlug('jur', $jurisdiction_slug) : NULL;
    if (!$jurisdiction instanceof GroupInterface) {
      return $this->publicApiErrorResponse('jurisdiction.not_found', 'Jurisdiction not found.', Response::HTTP_NOT_FOUND, $cache_metadata);
    }
    $cache_metadata->addCacheableDependency($jurisdiction);

    $organisation = NULL;
    $organisation_input = $this->queryString($request, 'organisation')
      ?? $this->queryString($request, 'organization')
      ?? $this->queryString($request, 'org');
    if ($organisation_input !== NULL) {
      $organisation_slug = $this->normalizeSlug($organisation_input);
      $organisation = $organisation_slug !== NULL
        ? $this->loadPublishedOrganisationBySlug($organisation_slug, $jurisdiction)
        : NULL;
      if (!$organisation instanceof GroupInterface) {
        return $this->publicApiErrorResponse('organisation.not_found', 'Organisation not found.', Response::HTTP_NOT_FOUND, $cache_metadata);
      }
      $cache_metadata->addCacheableDependency($organisation);
    }

    $forms = [];
    foreach ($this->loadPublishedInternetForms() as $form) {
      $scope = $this->resolveFormScope($form);
      if (($scope['jurisdictionId'] ?? NULL) !== (string) $jurisdiction->id()) {
        continue;
      }
      if ($organisation instanceof GroupInterface && ($scope['organisationId'] ?? NULL) !== (string) $organisation->id()) {
        continue;
      }
      $schema = $this->publicSchemaProjection($form->getSchema());
      if ($schema === NULL) {
        continue;
      }
      $cache_metadata->addCacheableDependency($form);
      $this->addScopeCacheTags($cache_metadata, $scope);
      $forms[] = $this->formSummary($form, $scope, $schema);
    }

    usort(
      $forms,
      static fn(array $a, array $b): int => strnatcasecmp((string) $a['title'], (string) $b['title']),
    );

    return $this->publicApiResponse([
      'data' => $forms,
      'meta' => [
        'apiVersion' => '1.0',
        'resource' => 'forms',
        'count' => count($forms),
      ],
    ], $cache_metadata);
  }

  /**
   * Returns one public form schema by UUID.
   */
  public function getForm(Request $request, string $form_schema_uuid): JsonResponse {
    $cache_metadata = $this->basePublicCacheMetadata()
      ->addCacheTags(['form_schema_list', 'group_list']);

    if (!$this->allowedQueryKeys($request, ['jurisdiction', 'organisation', 'organization', 'org'])) {
      return $this->publicApiErrorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND, $this->unknownQueryCacheMetadata());
    }
    $flood_response = $this->floodResponse($request, 'beantragt_schema.public_form');
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $uuid = $this->normalizeUuid($form_schema_uuid);
    $form = $uuid !== NULL ? $this->loadFormByUuid($uuid) : NULL;
    if (!$form instanceof FormSchemaInterface || !$this->isPublicForm($form)) {
      return $this->publicApiErrorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND, $cache_metadata);
    }
    $cache_metadata->addCacheableDependency($form);

    $scope = $this->resolveFormScope($form);
    if ($scope['jurisdiction'] === NULL) {
      return $this->publicApiErrorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND, $cache_metadata);
    }
    $this->addScopeCacheTags($cache_metadata, $scope);
    $requested_jurisdiction = $this->queryString($request, 'jurisdiction');
    $requested_organisation = $this->queryString($request, 'organisation')
      ?? $this->queryString($request, 'organization')
      ?? $this->queryString($request, 'org');
    if (
      !$this->scopeMatches($scope, $requested_jurisdiction, $requested_organisation, $cache_metadata)
      || ($requested_organisation !== NULL && $requested_jurisdiction === NULL)
    ) {
      return $this->publicApiErrorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND, $cache_metadata);
    }

    $schema = $this->publicSchemaProjection($form->getSchema());
    if ($schema === NULL) {
      return $this->publicApiErrorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND, $cache_metadata);
    }
    // Computed offers depend on settings and group entities via field access.
    foreach (['field_delivery_offer', 'field_profile_consent_offer'] as $field) {
      if ($form->hasField($field)) {
        $cache_metadata->addCacheableDependency($form->get($field)->access('view', NULL, TRUE));
      }
    }

    return $this->publicApiResponse([
      'data' => $this->formDetail($form, $scope, $schema),
      'meta' => [
        'apiVersion' => '1.0',
        'resource' => 'form',
      ],
    ], $cache_metadata);
  }

  /**
   * Loads published internet forms.
   *
   * @return \Drupal\beantragt_schema\Entity\FormSchemaInterface[]
   *   Public form schemas keyed by entity ID.
   */
  private function loadPublishedInternetForms(): array {
    $storage = $this->entityTypeManager()->getStorage('form_schema');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('field_workflow_status', 'published')
      ->condition('field_publication_scope', 'internet')
      ->sort('label')
      ->execute();
    $forms = [];
    foreach ($storage->loadMultiple($ids) as $form) {
      if ($form instanceof FormSchemaInterface && $this->isPublicForm($form)) {
        $forms[$form->id()] = $form;
      }
    }
    return $forms;
  }

  /**
   * Loads a public form schema by UUID.
   */
  private function loadFormByUuid(string $uuid): ?FormSchemaInterface {
    $matches = $this->entityTypeManager()->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    if (count($matches) !== 1) {
      return NULL;
    }
    $form = reset($matches);
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * Checks public form visibility flags.
   */
  private function isPublicForm(FormSchemaInterface $form): bool {
    return $form->isPublished()
      && $form->getSchemaStatus() === 'published'
      && $form->getPublicationScope() === 'internet';
  }

  /**
   * Loads one published group by bundle and slug.
   */
  private function loadPublishedGroupBySlug(string $bundle, string $slug): ?GroupInterface {
    $groups = $this->entityTypeManager()->getStorage('group')->loadByProperties([
      'type' => $bundle,
      'field_slug' => $slug,
      'status' => 1,
    ]);
    if (count($groups) !== 1) {
      return NULL;
    }
    $group = reset($groups);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Loads one published organisation by slug within a jurisdiction.
   */
  private function loadPublishedOrganisationBySlug(string $slug, GroupInterface $jurisdiction): ?GroupInterface {
    $organisation = $this->loadPublishedGroupBySlug('org', $slug);
    if (!$organisation instanceof GroupInterface) {
      return NULL;
    }
    $parent = $this->referencedPublishedGroup($organisation, 'field_jurisdiction');
    if (!$parent instanceof GroupInterface || (string) $parent->id() !== (string) $jurisdiction->id()) {
      return NULL;
    }
    return $organisation;
  }

  /**
   * Resolves public jurisdiction and organisation scope for a form.
   *
   * @return array{jurisdiction: array<string, string|null>|null, jurisdictionId: string|null, organisation: array<string, string|null>|null, organisationId: string|null, cacheTags: string[]}
   *   Public scope data with external identifiers only.
   */
  private function resolveFormScope(FormSchemaInterface $form): array {
    $direct_jurisdiction = $this->referencedPublishedGroup($form, 'field_jurisdiction');
    $direct_organisation = $this->referencedPublishedGroup($form, 'field_organisation');
    $legacy_group = $this->referencedPublishedGroup($form, 'field_group');

    $organisation = $direct_organisation
      ?? ($legacy_group instanceof GroupInterface && $legacy_group->bundle() === 'org' ? $legacy_group : NULL);
    $jurisdiction = $direct_jurisdiction
      ?? ($organisation instanceof GroupInterface ? $this->referencedPublishedGroup($organisation, 'field_jurisdiction') : NULL)
      ?? ($legacy_group instanceof GroupInterface && $legacy_group->bundle() === 'jur' ? $legacy_group : NULL);

    return [
      'jurisdiction' => $jurisdiction instanceof GroupInterface ? $this->publicGroupProjection($jurisdiction) : NULL,
      'jurisdictionId' => $jurisdiction instanceof GroupInterface ? (string) $jurisdiction->id() : NULL,
      'organisation' => $organisation instanceof GroupInterface ? $this->publicGroupProjection($organisation) : NULL,
      'organisationId' => $organisation instanceof GroupInterface ? (string) $organisation->id() : NULL,
      'cacheTags' => array_values(array_unique(array_merge(
        $jurisdiction instanceof GroupInterface ? $jurisdiction->getCacheTags() : [],
        $organisation instanceof GroupInterface ? $organisation->getCacheTags() : [],
      ))),
    ];
  }

  /**
   * Projects a group using external identifiers only.
   *
   * @return array<string, string|null>
   *   Public group projection.
   */
  private function publicGroupProjection(GroupInterface $group): array {
    return [
      'slug' => $this->groupSlug($group),
      'name' => $group->label(),
      'uuid' => (string) $group->uuid(),
    ];
  }

  /**
   * Adds cache tags for public scope groups embedded in a response.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cache_metadata
   *   Cache metadata to update.
   * @param array{jurisdiction: array<string, string|null>|null, jurisdictionId: string|null, organisation: array<string, string|null>|null, organisationId: string|null, cacheTags: string[]} $scope
   *   Public form scope.
   */
  private function addScopeCacheTags(CacheableMetadata $cache_metadata, array $scope): void {
    if ($scope['cacheTags'] !== []) {
      $cache_metadata->addCacheTags($scope['cacheTags']);
    }
  }

  /**
   * Reads a referenced group from a content entity field.
   */
  private function referencedGroup(ContentEntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $candidate = $entity->get($field_name)->entity;
    return $candidate instanceof GroupInterface ? $candidate : NULL;
  }

  /**
   * Reads a referenced group that is safe to expose publicly.
   */
  private function referencedPublishedGroup(ContentEntityInterface $entity, string $field_name): ?GroupInterface {
    $group = $this->referencedGroup($entity, $field_name);
    return $group instanceof GroupInterface && $this->isPublishedGroup($group) ? $group : NULL;
  }

  /**
   * Checks whether a group is published.
   */
  private function isPublishedGroup(GroupInterface $group): bool {
    return $group->isPublished();
  }

  /**
   * Reads a public slug from a group.
   */
  private function groupSlug(GroupInterface $group): ?string {
    if (!$group->hasField('field_slug') || $group->get('field_slug')->isEmpty()) {
      return NULL;
    }
    $slug = $this->normalizeSlug((string) $group->get('field_slug')->value);
    return $slug;
  }

  /**
   * Builds a public form summary.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   Form schema entity.
   * @param array{jurisdiction: array<string, string|null>|null, jurisdictionId: string|null, organisation: array<string, string|null>|null, organisationId: string|null, cacheTags: string[]} $scope
   *   Public form scope.
   * @param array<string, mixed> $schema
   *   Public schema projection.
   *
   * @return array<string, mixed>
   *   Public form summary.
   */
  private function formSummary(FormSchemaInterface $form, array $scope, array $schema): array {
    $description = $this->fieldString($form, 'field_description');
    $summary = [
      'id' => (string) $form->uuid(),
      'type' => 'form',
      'title' => $form->label(),
      'description' => $description,
      'status' => 'published',
      'publicationScope' => 'internet',
      'version' => $form->getVersion(),
      'jurisdiction' => $scope['jurisdiction'],
      'organisation' => $scope['organisation'],
      'path' => $this->publicFormPath((string) $form->uuid(), $scope),
      'keywords' => $this->extractKeywords($schema),
    ];
    $i18n = $this->i18nProjection($schema['i18n'] ?? NULL, ['title', 'description']);
    if ($i18n !== []) {
      $summary['i18n'] = $i18n;
    }
    return $summary;
  }

  /**
   * Builds a public form detail.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   Form schema entity.
   * @param array{jurisdiction: array<string, string|null>|null, jurisdictionId: string|null, organisation: array<string, string|null>|null, organisationId: string|null, cacheTags: string[]} $scope
   *   Public form scope.
   * @param array<string, mixed> $schema
   *   Public schema projection.
   *
   * @return array<string, mixed>
   *   Public form detail.
   */
  private function formDetail(FormSchemaInterface $form, array $scope, array $schema): array {
    $profile_offers = $form->hasField('field_profile_consent_offer')
      ? json_decode((string) $form->get('field_profile_consent_offer')->value, TRUE)
      : NULL;
    return $this->formSummary($form, $scope, $schema) + [
      'schema' => $schema,
      'deliveryOffer' => $this->deliveryOffer($form),
      'profileConsentOffers' => $profile_offers,
    ];
  }

  /**
   * Public delivery offer of a form; unconfigured when absent or malformed.
   *
   * The computed field comes from beantragt_docgen, which this module does
   * not depend on.
   *
   * @return array{configured: bool, routes?: string[], default?: string}
   *   The offer.
   */
  private function deliveryOffer(FormSchemaInterface $form): array {
    $unconfigured = ['configured' => FALSE];
    if (!$form->hasField('field_delivery_offer')) {
      return $unconfigured;
    }
    $offer = json_decode((string) ($form->get('field_delivery_offer')->value ?? ''), TRUE);
    if (!is_array($offer) || ($offer['configured'] ?? NULL) !== TRUE) {
      return $unconfigured;
    }
    $routes = $offer['routes'] ?? NULL;
    $default = $offer['default'] ?? NULL;
    if (!is_array($routes) || $routes === [] || !array_is_list($routes) || !is_string($default) || !in_array($default, $routes, TRUE)) {
      return $unconfigured;
    }
    foreach ($routes as $route) {
      if (!in_array($route, ['portal', 'postal', 'bundid'], TRUE)) {
        return $unconfigured;
      }
    }
    return ['configured' => TRUE, 'routes' => $routes, 'default' => $default];
  }

  /**
   * Builds the public form path.
   *
   * @param string $form_uuid
   *   Public form schema UUID.
   * @param array{jurisdiction: array<string, string|null>|null, jurisdictionId: string|null, organisation: array<string, string|null>|null, organisationId: string|null, cacheTags: string[]} $scope
   *   Public form scope.
   */
  private function publicFormPath(string $form_uuid, array $scope): string {
    $jurisdiction_slug = $scope['jurisdiction']['slug'] ?? NULL;
    $organisation_slug = $scope['organisation']['slug'] ?? NULL;
    if (is_string($jurisdiction_slug) && $jurisdiction_slug !== '') {
      if (is_string($organisation_slug) && $organisation_slug !== '') {
        return sprintf('/%s/%s/form/%s', $jurisdiction_slug, $organisation_slug, $form_uuid);
      }
      return sprintf('/%s/form/%s', $jurisdiction_slug, $form_uuid);
    }
    return sprintf('/form/%s', $form_uuid);
  }

  /**
   * Checks whether requested scope query values match a form scope.
   *
   * @param array{jurisdiction: array<string, string|null>|null, jurisdictionId: string|null, organisation: array<string, string|null>|null, organisationId: string|null, cacheTags: string[]} $scope
   *   Public form scope.
   * @param string|null $jurisdiction
   *   Optional jurisdiction slug from the request.
   * @param string|null $organisation
   *   Optional organisation slug from the request.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache_metadata
   *   Cache metadata to update for loaded request scope groups.
   */
  private function scopeMatches(array $scope, ?string $jurisdiction, ?string $organisation, CacheableMetadata $cache_metadata): bool {
    $jurisdiction_group = NULL;
    if ($jurisdiction !== NULL) {
      $jurisdiction_slug = $this->normalizeSlug($jurisdiction);
      $jurisdiction_group = $jurisdiction_slug !== NULL
        ? $this->loadPublishedGroupBySlug('jur', $jurisdiction_slug)
        : NULL;
      if (!$jurisdiction_group instanceof GroupInterface || ($scope['jurisdictionId'] ?? NULL) !== (string) $jurisdiction_group->id()) {
        return FALSE;
      }
      $cache_metadata->addCacheableDependency($jurisdiction_group);
    }
    if ($organisation !== NULL) {
      if (!$jurisdiction_group instanceof GroupInterface) {
        return FALSE;
      }
      $organisation_slug = $this->normalizeSlug($organisation);
      $organisation_group = $organisation_slug !== NULL
        ? $this->loadPublishedOrganisationBySlug($organisation_slug, $jurisdiction_group)
        : NULL;
      if (!$organisation_group instanceof GroupInterface || ($scope['organisationId'] ?? NULL) !== (string) $organisation_group->id()) {
        return FALSE;
      }
      $cache_metadata->addCacheableDependency($organisation_group);
    }
    return TRUE;
  }

  /**
   * Projects stored schema JSON into the public form contract.
   *
   * @return array<string, mixed>|null
   *   Projected schema, or NULL when invalid or empty.
   */
  private function publicSchemaProjection(?string $raw): ?array {
    if ($raw === NULL || trim($raw) === '') {
      return NULL;
    }
    try {
      $parsed = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->warning('Public form schema projection failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    if (!is_array($parsed) || array_is_list($parsed)) {
      return NULL;
    }

    $title = $this->stringValue($parsed['title'] ?? NULL);
    $sections = $this->sectionProjections($parsed['sections'] ?? NULL);
    if ($title === NULL || $sections === []) {
      return NULL;
    }

    $out = [
      'title' => $title,
      'sections' => $sections,
    ];
    foreach (['id', 'description'] as $key) {
      $value = $this->stringValue($parsed[$key] ?? NULL);
      if ($value !== NULL) {
        $out[$key] = $value;
      }
    }
    $i18n = $this->i18nProjection($parsed['i18n'] ?? NULL, ['title', 'description']);
    if ($i18n !== []) {
      $out['i18n'] = $i18n;
    }
    $version = $this->numberValue($parsed['version'] ?? NULL);
    if ($version !== NULL) {
      $out['version'] = $version;
    }
    $auth = $this->authProjection($parsed['auth'] ?? NULL);
    if ($auth !== NULL) {
      $out['auth'] = $auth;
    }
    return $out;
  }

  /**
   * Projects schema sections.
   *
   * @return array<int, array<string, mixed>>
   *   Section projections.
   */
  private function sectionProjections(mixed $sections): array {
    if (!is_array($sections) || !array_is_list($sections)) {
      return [];
    }
    $out = [];
    foreach ($sections as $section) {
      if (!is_array($section) || array_is_list($section)) {
        continue;
      }
      $id = $this->stringValue($section['id'] ?? NULL);
      $title = $this->stringValue($section['title'] ?? NULL);
      $fields = $this->fieldProjections($section['fields'] ?? NULL);
      if ($id === NULL || $title === NULL || $fields === []) {
        continue;
      }
      $projection = [
        'id' => $id,
        'title' => $title,
        'fields' => $fields,
      ];
      $address_groups = $this->addressGroupProjections($section['addressGroups'] ?? NULL, $fields);
      if ($address_groups !== []) {
        $projection['addressGroups'] = $address_groups;
      }
      $description = $this->stringValue($section['description'] ?? NULL);
      if ($description !== NULL) {
        $projection['description'] = $description;
      }
      $i18n = $this->i18nProjection($section['i18n'] ?? NULL, ['title', 'description']);
      if ($i18n !== []) {
        $projection['i18n'] = $i18n;
      }
      foreach (['repeatable'] as $key) {
        if (is_bool($section[$key] ?? NULL)) {
          $projection[$key] = $section[$key];
        }
      }
      $max_instances = $this->numberValue($section['maxInstances'] ?? NULL);
      if ($max_instances !== NULL) {
        $projection['maxInstances'] = $max_instances;
      }
      $out[] = $projection;
    }
    return $out;
  }

  /**
   * Projects schema fields.
   *
   * @return array<int, array<string, mixed>>
   *   Field projections.
   */
  private function fieldProjections(mixed $fields): array {
    if (!is_array($fields) || !array_is_list($fields)) {
      return [];
    }
    $projected = [];
    $public_field_ids = [];
    foreach ($fields as $field) {
      if (!is_array($field) || array_is_list($field)) {
        continue;
      }
      $id = $this->stringValue($field['id'] ?? NULL);
      $type = $this->stringValue($field['type'] ?? NULL);
      $label = $this->stringValue($field['label'] ?? NULL);
      if ($id === NULL || $type === NULL || $label === NULL || !in_array($type, self::PUBLIC_FIELD_TYPES, TRUE)) {
        continue;
      }
      $projection = [
        'id' => $id,
        'type' => $type,
        'label' => $label,
      ];
      foreach (['placeholder'] as $key) {
        $value = $this->stringValue($field[$key] ?? NULL);
        if ($value !== NULL) {
          $projection[$key] = $value;
        }
      }
      $format = $this->stringValue($field['format'] ?? NULL);
      if ($format !== NULL && in_array($format, self::PUBLIC_FIELD_FORMATS, TRUE)) {
        $projection['format'] = $format;
      }
      if (is_bool($field['required'] ?? NULL)) {
        $projection['required'] = $field['required'];
      }
      if ($this->isSensitiveFlag($field['sensitive'] ?? NULL)) {
        $projection['sensitive'] = TRUE;
      }
      $privacy = $this->privacyProjection($field['privacy'] ?? NULL);
      if ($privacy !== NULL) {
        $projection['privacy'] = $privacy;
      }
      $crm_key = $this->stringValue($field['crm_key'] ?? NULL);
      if ($crm_key !== NULL && $this->isPublicProfileCrmField($field, $type, $privacy, $crm_key)) {
        $projection['crm_key'] = $crm_key;
      }
      $options = in_array($type, self::PUBLIC_OPTION_FIELD_TYPES, TRUE)
        ? $this->stringArray($field['options'] ?? NULL)
        : [];
      if ($options !== []) {
        $projection['options'] = $options;
      }
      $i18n = $this->i18nProjection($field['i18n'] ?? NULL, ['label', 'placeholder'], $options !== [], $options);
      if ($i18n !== []) {
        $projection['i18n'] = $i18n;
      }
      $validation = $this->validationProjection($field['validation'] ?? NULL);
      if ($validation !== []) {
        $projection['validation'] = $validation;
      }
      if ($type === 'address') {
        $projection['widget'] = $this->addressWidgetProjection($field['widget'] ?? NULL);
      }
      $default_field = $projection;
      if (is_string($field['validation']['pattern'] ?? NULL)) {
        $default_field['validation']['pattern'] = $field['validation']['pattern'];
      }
      $default = PublicFieldDefault::project($default_field, $field['defaultValue'] ?? NULL);
      if ($default !== NULL) {
        $projection['defaultValue'] = $default;
      }
      $public_field_ids[$id] = TRUE;
      $projected[] = [
        'field' => $projection,
        'conditionalOn' => $field['conditionalOn'] ?? NULL,
      ];
    }

    $out = [];
    foreach ($projected as $item) {
      $projection = $item['field'];
      $conditional = $this->conditionalProjection($item['conditionalOn'], $public_field_ids);
      if ($conditional !== NULL) {
        $projection['conditionalOn'] = $conditional;
      }
      $out[] = $projection;
    }
    return $out;
  }

  /**
   * Returns TRUE when a public field may receive reusable profile data.
   *
   * CRM keys are metadata, but exposing one enables client-side prefill. Keep
   * that behavior limited to non-sensitive contact or identity fields whose
   * widget type matches the profile value.
   *
   * @param array<string, mixed> $field
   *   Original schema field.
   * @param string $type
   *   Projected public field type.
   * @param array<string, mixed>|null $privacy
   *   Projected privacy metadata.
   * @param string $crm_key
   *   Candidate reusable profile CRM key.
   */
  private function isPublicProfileCrmField(array $field, string $type, ?array $privacy, string $crm_key): bool {
    if ($this->isSensitiveFlag($field['sensitive'] ?? NULL)) {
      return FALSE;
    }
    $allowed_types = self::PUBLIC_PROFILE_CRM_FIELD_TYPES[$crm_key] ?? [];
    if (!in_array($type, $allowed_types, TRUE)) {
      return FALSE;
    }
    return in_array($privacy['kind'] ?? NULL, self::PUBLIC_PROFILE_PRIVACY_KINDS, TRUE);
  }

  /**
   * Projects UI-only address groups without creating submission fields.
   *
   * @param mixed $groups
   *   Stored address group definitions.
   * @param array<int, array<string, mixed>> $fields
   *   Already allowlisted public fields for the section.
   *
   * @return array<int, array<string, mixed>>
   *   Safe address group projections.
   */
  private function addressGroupProjections(mixed $groups, array $fields): array {
    if (!is_array($groups) || !array_is_list($groups)) {
      return [];
    }
    $field_ids = [];
    foreach ($fields as $field) {
      if (is_string($field['id'] ?? NULL)) {
        $field_ids[$field['id']] = TRUE;
      }
    }
    $component_keys = ['street', 'houseNumber', 'postcode', 'city'];
    $used_group_ids = [];
    $used_field_ids = [];
    $out = [];
    foreach ($groups as $group) {
      if (!is_array($group) || array_is_list($group)) {
        continue;
      }
      $id = $this->stringValue($group['id'] ?? NULL);
      $components = $group['fields'] ?? NULL;
      if ($id === NULL || isset($used_group_ids[$id]) || !is_array($components) || array_is_list($components)) {
        continue;
      }
      $projected_fields = [];
      $valid = TRUE;
      foreach ($component_keys as $component) {
        $field_id = $this->stringValue($components[$component] ?? NULL);
        if ($field_id === NULL || !isset($field_ids[$field_id]) || isset($used_field_ids[$field_id])) {
          $valid = FALSE;
          break;
        }
        $projected_fields[$component] = $field_id;
      }
      if (!$valid || count(array_unique($projected_fields)) !== count($component_keys)) {
        continue;
      }
      $projection = [
        'id' => $id,
        'fields' => $projected_fields,
        'autocomplete' => ($group['autocomplete'] ?? TRUE) !== FALSE,
        'map' => ($group['map'] ?? FALSE) === TRUE,
        'boundary' => ($group['boundary'] ?? NULL) === 'advisory' ? 'advisory' : 'off',
      ];
      $label = $this->stringValue($group['label'] ?? NULL);
      if ($label !== NULL) {
        $projection['label'] = $label;
      }
      $used_group_ids[$id] = TRUE;
      foreach ($projected_fields as $field_id) {
        $used_field_ids[$field_id] = TRUE;
      }
      $out[] = $projection;
    }
    return $out;
  }

  /**
   * Projects the allowlisted address widget options.
   *
   * @return array{autocomplete: bool, map: bool, boundary: string}
   *   Public address widget configuration.
   */
  private function addressWidgetProjection(mixed $widget): array {
    return [
      'autocomplete' => !is_array($widget)
      || !array_key_exists('autocomplete', $widget)
      || $widget['autocomplete'] !== FALSE,
      'map' => is_array($widget) && ($widget['map'] ?? FALSE) === TRUE,
      'boundary' => is_array($widget) && ($widget['boundary'] ?? NULL) === 'advisory'
        ? 'advisory'
        : 'off',
    ];
  }

  /**
   * Projects public privacy classification metadata.
   *
   * @return array{kind: string}|null
   *   Public privacy projection.
   */
  private function privacyProjection(mixed $privacy): ?array {
    if (!is_array($privacy) || array_is_list($privacy)) {
      return NULL;
    }
    $kind = $this->normalizePrivacyKind($privacy['kind'] ?? NULL);
    if ($kind === NULL) {
      return NULL;
    }
    return ['kind' => $kind];
  }

  /**
   * Normalizes public privacy.kind spellings to canonical machine names.
   */
  private function normalizePrivacyKind(mixed $value): ?string {
    $kind = $this->stringValue($value);
    if ($kind === NULL) {
      return NULL;
    }
    $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($kind)));
    $normalized = trim((string) $normalized, '_');
    return in_array($normalized, self::PUBLIC_FIELD_PRIVACY_KINDS, TRUE) ? $normalized : NULL;
  }

  /**
   * Normalizes stored sensitive flag variants from legacy or imported schemas.
   */
  private function isSensitiveFlag(mixed $value): bool {
    if ($value === TRUE || $value === 1) {
      return TRUE;
    }
    if (!is_string($value)) {
      return FALSE;
    }
    return in_array(strtolower(trim($value)), ['true', 'yes', 'on', '1'], TRUE);
  }

  /**
   * Projects public validation rules.
   *
   * @return array<string, int|float>
   *   Validation rules.
   */
  private function validationProjection(mixed $validation): array {
    if (!is_array($validation) || array_is_list($validation)) {
      return [];
    }
    $out = [];
    foreach (['minLength', 'maxLength', 'min', 'max', 'step', 'maxFiles', 'maxSizeBytes', 'maxFileSizeBytes'] as $key) {
      $value = $this->numberValue($validation[$key] ?? NULL);
      if ($value !== NULL) {
        $out[$key] = $value;
      }
    }
    return $out;
  }

  /**
   * Projects localized display text.
   *
   * @param mixed $value
   *   Stored i18n map.
   * @param string[] $allowed_keys
   *   Text keys to expose.
   * @param bool $include_options
   *   Whether localized choice labels may be exposed.
   * @param string[] $allowed_options
   *   Canonical option values allowed for localized choice labels.
   *
   * @return array<string, array<string, mixed>>
   *   Public i18n map.
   */
  private function i18nProjection(mixed $value, array $allowed_keys, bool $include_options = FALSE, array $allowed_options = []): array {
    if (!is_array($value) || array_is_list($value)) {
      return [];
    }

    $out = [];
    foreach (array_slice($value, 0, self::MAX_PUBLIC_I18N_LOCALES, TRUE) as $locale => $translation_value) {
      if (!is_string($locale) || preg_match(self::LOCALE_KEY_PATTERN, $locale) !== 1 || !is_array($translation_value) || array_is_list($translation_value)) {
        continue;
      }
      $translation = [];
      foreach ($allowed_keys as $key) {
        $text = $this->i18nText($translation_value[$key] ?? NULL);
        if ($text !== NULL) {
          $translation[$key] = $text;
        }
      }
      if ($include_options) {
        $options = $this->i18nOptions($translation_value['options'] ?? NULL, $allowed_options);
        if ($options !== []) {
          $translation['options'] = $options;
        }
      }
      if ($translation !== []) {
        $out[$locale] = $translation;
      }
    }
    return $out;
  }

  /**
   * Reads localized text.
   */
  private function i18nText(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return $value === '' ? NULL : mb_substr($value, 0, self::MAX_PUBLIC_I18N_TEXT_LENGTH);
  }

  /**
   * Projects localized option labels.
   *
   * @return array<int|string, string>
   *   Option labels as index array or canonical-value map.
   */
  private function i18nOptions(mixed $value, array $allowed_options): array {
    if (!is_array($value) || $allowed_options === []) {
      return [];
    }
    $out = [];
    if (array_is_list($value)) {
      foreach (array_slice($value, 0, count($allowed_options)) as $label_value) {
        $label = $this->i18nText($label_value);
        if ($label !== NULL) {
          $out[] = $label;
        }
      }
      return $out;
    }

    foreach ($allowed_options as $option) {
      if (!is_string($option)) {
        continue;
      }
      $label = $this->i18nText($value[$option] ?? NULL);
      if ($label !== NULL) {
        $out[$option] = $label;
      }
    }
    return $out;
  }

  /**
   * Projects public conditional field metadata.
   *
   * @param mixed $conditional
   *   Stored conditional metadata.
   * @param array<string, bool> $public_field_ids
   *   Public field ID lookup for this section.
   *
   * @return array<string, mixed>|null
   *   Conditional metadata.
   */
  private function conditionalProjection(mixed $conditional, array $public_field_ids): ?array {
    if (!is_array($conditional) || array_is_list($conditional)) {
      return NULL;
    }
    $field_id = $this->stringValue($conditional['fieldId'] ?? NULL);
    if ($field_id === NULL || !isset($public_field_ids[$field_id])) {
      return NULL;
    }
    $out = ['fieldId' => $field_id];
    $value = $conditional['value'] ?? NULL;
    if (is_string($value) || is_bool($value)) {
      $out['value'] = $value;
    }
    return $out;
  }

  /**
   * Projects public auth metadata.
   *
   * @return array<string, mixed>|null
   *   Public auth metadata.
   */
  private function authProjection(mixed $auth): ?array {
    if (!is_array($auth) || array_is_list($auth)) {
      return NULL;
    }
    $out = [];
    $mode = $this->stringValue($auth['mode'] ?? NULL);
    if ($mode !== NULL) {
      $out['mode'] = in_array($mode, self::PUBLIC_AUTH_MODES, TRUE) ? $mode : 'required';
    }
    if (is_array($auth['providers'] ?? NULL)) {
      $providers = array_values(array_unique(array_filter(
        $auth['providers'],
        static fn(mixed $provider): bool => is_string($provider) && in_array($provider, self::PUBLIC_AUTH_PROVIDERS, TRUE),
      )));
      if ($providers !== []) {
        $out['providers'] = array_slice($providers, 0, 10);
      }
    }
    if (is_array($auth['collect'] ?? NULL) && !array_is_list($auth['collect'])) {
      $collect = [];
      foreach (['fullName', 'email'] as $key) {
        $requirement = $auth['collect'][$key] ?? NULL;
        if (is_bool($requirement) || (is_string($requirement) && in_array($requirement, self::PUBLIC_IDENTITY_REQUIREMENTS, TRUE))) {
          $collect[$key] = $requirement;
        }
      }
      if ($collect !== []) {
        $out['collect'] = $collect;
      }
    }
    return $out !== [] ? $out : NULL;
  }

  /**
   * Extracts public keywords for catalogue search hints.
   *
   * @param array<string, mixed> $schema
   *   Public schema projection.
   *
   * @return string[]
   *   Up to 30 keywords.
   */
  private function extractKeywords(array $schema): array {
    $keywords = [];
    $append = static function (mixed $value) use (&$keywords): void {
      if (is_string($value) && trim($value) !== '') {
        $keywords[] = trim($value);
      }
    };
    $append($schema['title'] ?? NULL);
    $append($schema['description'] ?? NULL);
    foreach ($schema['i18n'] ?? [] as $translation) {
      if (is_array($translation)) {
        $append($translation['title'] ?? NULL);
        $append($translation['description'] ?? NULL);
      }
    }
    foreach ($schema['sections'] ?? [] as $section) {
      if (!is_array($section)) {
        continue;
      }
      $append($section['title'] ?? NULL);
      $append($section['description'] ?? NULL);
      foreach ($section['i18n'] ?? [] as $translation) {
        if (is_array($translation)) {
          $append($translation['title'] ?? NULL);
          $append($translation['description'] ?? NULL);
        }
      }
      foreach ($section['fields'] ?? [] as $field) {
        if (!is_array($field)) {
          continue;
        }
        $append($field['label'] ?? NULL);
        $append($field['placeholder'] ?? NULL);
        foreach ($field['options'] ?? [] as $option) {
          $append($option);
        }
        foreach ($field['i18n'] ?? [] as $translation) {
          if (!is_array($translation)) {
            continue;
          }
          $append($translation['label'] ?? NULL);
          $append($translation['placeholder'] ?? NULL);
          foreach ($translation['options'] ?? [] as $option) {
            $append($option);
          }
        }
      }
    }
    return array_slice(array_values(array_unique($keywords)), 0, 30);
  }

  /**
   * Reads a field string from an entity.
   */
  private function fieldString(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }
    return (string) $entity->get($field_name)->value;
  }

  /**
   * Reads a scalar query parameter, rejecting arrays.
   */
  private function queryString(Request $request, string $name): ?string {
    $params = $request->query->all();
    if (!array_key_exists($name, $params)) {
      return NULL;
    }
    return is_string($params[$name]) ? $params[$name] : '';
  }

  /**
   * Rejects unrecognized query parameters to keep cache keys bounded.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Incoming request.
   * @param string[] $allowed
   *   Allowed query parameter names.
   */
  private function allowedQueryKeys(Request $request, array $allowed): bool {
    $allowed_lookup = array_fill_keys($allowed, TRUE);
    foreach (array_keys($request->query->all()) as $key) {
      if (!isset($allowed_lookup[$key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Normalizes public slugs.
   */
  private function normalizeSlug(string $slug): ?string {
    $slug = strtolower(trim($slug));
    return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : NULL;
  }

  /**
   * Normalizes public UUIDs.
   */
  private function normalizeUuid(string $uuid): ?string {
    $uuid = strtolower(trim($uuid));
    return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : NULL;
  }

  /**
   * Reads a non-empty string.
   */
  private function stringValue(mixed $value): ?string {
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Reads a finite JSON number.
   */
  private function numberValue(mixed $value): int|float|null {
    return (is_int($value) || is_float($value)) && is_finite($value) ? $value : NULL;
  }

  /**
   * Reads a string array.
   *
   * @return string[]
   *   Public string values.
   */
  private function stringArray(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter(
      $value,
      static fn(mixed $item): bool => is_string($item),
    ));
  }

  /**
   * Builds base public cache metadata.
   */
  private function basePublicCacheMetadata(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheContexts([
        'url.path',
        'url.query_args:jurisdiction',
        'url.query_args:organisation',
        'url.query_args:organization',
        'url.query_args:org',
      ])
      ->setCacheMaxAge(3600);
  }

  /**
   * Builds uncacheable metadata for unknown public query parameters.
   */
  private function unknownQueryCacheMetadata(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheContexts([
        'url.path',
        'url.query_args',
      ])
      ->setCacheMaxAge(0);
  }

  /**
   * Builds a v1 public response.
   *
   * @param array<string, mixed> $payload
   *   Response payload.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache_metadata
   *   Cache metadata to apply to the response.
   */
  private function publicApiResponse(array $payload, CacheableMetadata $cache_metadata): CacheableJsonResponse {
    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency($cache_metadata);
    $response->setMaxAge($cache_metadata->getCacheMaxAge());
    $response->setPublic();
    return $response;
  }

  /**
   * Builds a v1 public API error response.
   */
  private function publicApiErrorResponse(string $code, string $message, int $status, CacheableMetadata $cache_metadata): CacheableJsonResponse {
    return $this->publicApiResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
      'meta' => [
        'apiVersion' => '1.0',
      ],
    ], $cache_metadata)->setStatusCode($status);
  }

}
