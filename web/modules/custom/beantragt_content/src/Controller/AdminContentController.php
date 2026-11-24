<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_content\Content\AdminContent;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API for jurisdiction-scoped editorial pages and boilerplates.
 */
final class AdminContentController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Editable content bundles.
   */
  private const EDITABLE_BUNDLES = [
    'page' => TRUE,
    'boilerplate' => TRUE,
    'content_section' => TRUE,
  ];

  /**
   * Stable public page keys.
   */
  private const PAGE_KEYS = [
    'privacy' => TRUE,
    'legal_notice' => TRUE,
    'accessibility' => TRUE,
    'easy_language' => TRUE,
    'sign_language' => TRUE,
    'content' => TRUE,
  ];

  /**
   * Boilerplate types supported by the Mark-a-Spot-compatible content model.
   */
  private const BOILERPLATE_TYPES = [
    'status_notes' => TRUE,
    'service_provider' => TRUE,
    'remarks' => TRUE,
  ];

  /**
   * Public placements supported by HTML content sections.
   */
  private const PLACEMENTS = [
    'org_start' => TRUE,
    'form_intro' => TRUE,
    'form_sidebar' => TRUE,
    'submission_success' => TRUE,
  ];

  /**
   * Public page route slug shape.
   */
  private const PUBLIC_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  /**
   * Public FormSchema UUID shape.
   */
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

  /**
   * Maximum editable body size.
   */
  private const MAX_BODY_LENGTH = 50000;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_group.jurisdiction_hierarchy_resolver'),
    );
  }

  /**
   * Access check for admin content endpoints.
   */
  public function accessCheck(AccountInterface $account, string $jurisdiction_id): AccessResultInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path']);
    }

    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    $membership_cache_tag = 'group_relationship_list:plugin:group_membership:entity:' . $account->id();
    if (BeantragtRoles::isManagementAccount($account)) {
      foreach (GroupMembership::loadByUser($account) as $membership) {
        $managed_group = $membership->getGroup();
        if (!$this->isJurisdictionGroup($managed_group) || !$this->membershipGrantsAdmin($membership)) {
          continue;
        }
        $scope_ids = $this->hierarchyResolver->getDescendantIds((int) $managed_group->id());
        if (in_array($resolved_id, $scope_ids, TRUE)) {
          return AccessResult::allowed()
            ->addCacheContexts(['user', 'url.path'])
            ->addCacheTags([$membership_cache_tag]);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles', 'url.path'])
      ->addCacheTags([$membership_cache_tag]);
  }

  /**
   * Lists editable pages and boilerplates for a jurisdiction workspace.
   */
  public function listContent(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $bundle = $this->optionalBundle($request->query->get('kind'));
    if ($bundle === FALSE) {
      return $this->errorResponse('Unknown content kind.', Response::HTTP_BAD_REQUEST, 'kind');
    }

    $organisation_id = $this->resolveOrganisationId($jurisdiction, $request->query->get('organisation'));
    if ($request->query->has('organisation') && $organisation_id === NULL) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND, 'organisation');
    }

    $nodes = $this->loadContentNodes(
      (int) $jurisdiction->id(),
      $organisation_id,
      $bundle ?: NULL,
    );

    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->serializeContent($node);
    }

    usort($data, static function (array $a, array $b): int {
      $kind_order = strcmp((string) $a['kind'], (string) $b['kind']);
      if ($kind_order !== 0) {
        return $kind_order;
      }
      $weight_order = ((int) ($a['page']['order'] ?? 0)) <=> ((int) ($b['page']['order'] ?? 0));
      if ($weight_order !== 0) {
        return $weight_order;
      }
      return strnatcasecmp((string) $a['title'], (string) $b['title']);
    });

    return $this->privateResponse([
      'data' => $data,
      'meta' => [
        'workspace' => [
          'jurisdictionInternalId' => (int) $jurisdiction->id(),
          'organisationInternalId' => $organisation_id,
        ],
      ],
    ]);
  }

  /**
   * Creates a page or boilerplate in the requested jurisdiction.
   */
  public function createContent(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $payload = $this->requestPayload($request);
    $bundle = $this->requiredBundle($payload['kind'] ?? NULL);
    if ($bundle === NULL) {
      return $this->errorResponse('Unknown content kind.', Response::HTTP_BAD_REQUEST, 'kind');
    }

    $organisation_id = $this->resolveOrganisationInput($jurisdiction, $payload, $request);
    if ($organisation_id === FALSE) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND, 'organisation');
    }

    $title = $this->requiredString($payload['title'] ?? NULL, 255);
    if ($title === NULL) {
      return $this->errorResponse('Title is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'title');
    }

    $node = $this->entityTypeManager()->getStorage('node')->create([
      'type' => $bundle,
      'title' => $title,
      'status' => $this->boolValue($payload['status'] ?? TRUE),
      'uid' => (int) $this->currentUser()->id(),
    ]);

    $field_error = $this->applySharedFields($node, $jurisdiction, $organisation_id, $payload, TRUE);
    if ($field_error !== NULL) {
      return $field_error;
    }
    $field_error = $this->applyKindFields($node, $jurisdiction, $organisation_id, $payload, TRUE);
    if ($field_error !== NULL) {
      return $field_error;
    }

    $validation_error = $this->validateContent($node);
    if ($validation_error !== NULL) {
      return $validation_error;
    }

    $node->setRevisionLogMessage('Created through beantragt.ai content editor.');
    $node->save();

    return $this->privateResponse(['data' => $this->serializeContent($node)], Response::HTTP_CREATED);
  }

  /**
   * Updates an existing page or boilerplate by UUID.
   */
  public function updateContent(Request $request, string $jurisdiction_id, string $content_uuid): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $node = $this->loadEditableContentByUuid($content_uuid);
    if (!$node || !$this->nodeBelongsToJurisdiction($node, (int) $jurisdiction->id())) {
      return $this->errorResponse('Content not found.', Response::HTTP_NOT_FOUND);
    }

    $payload = $this->requestPayload($request);
    if (isset($payload['kind']) && $this->requiredBundle($payload['kind']) !== $node->bundle()) {
      return $this->errorResponse('Content kind cannot be changed.', Response::HTTP_BAD_REQUEST, 'kind');
    }

    if (array_key_exists('title', $payload)) {
      $title = $this->requiredString($payload['title'], 255);
      if ($title === NULL) {
        return $this->errorResponse('Title is required.', Response::HTTP_UNPROCESSABLE_ENTITY, 'title');
      }
      $node->setTitle($title);
    }
    if (array_key_exists('status', $payload)) {
      if ($this->boolValue($payload['status'])) {
        $node->setPublished();
      }
      else {
        $node->setUnpublished();
      }
    }

    $organisation_id = $this->resolveOrganisationInput($jurisdiction, $payload, $request, FALSE);
    if ($organisation_id === FALSE) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND, 'organisation');
    }

    $field_error = $this->applySharedFields($node, $jurisdiction, $organisation_id, $payload, FALSE);
    if ($field_error !== NULL) {
      return $field_error;
    }
    $field_error = $this->applyKindFields($node, $jurisdiction, $organisation_id, $payload, FALSE);
    if ($field_error !== NULL) {
      return $field_error;
    }

    $validation_error = $this->validateContent($node);
    if ($validation_error !== NULL) {
      return $validation_error;
    }

    $node->setNewRevision(TRUE);

    $node->setRevisionUserId((int) $this->currentUser()->id());

    $node->setRevisionCreationTime((int) $request->server->get('REQUEST_TIME', time()));
    $node->setRevisionLogMessage('Updated through beantragt.ai content editor.');
    $node->save();

    return $this->privateResponse(['data' => $this->serializeContent($node)]);
  }

  /**
   * Checks whether a group membership carries a group-admin role.
   */
  private function membershipGrantsAdmin(GroupMembershipInterface $membership): bool {
    return ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Loads a jurisdiction by numeric ID or slug.
   */
  private function loadJurisdictionGroup(string $jurisdiction_id): ?GroupInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($resolved_id);

    return $group instanceof GroupInterface && $group->bundle() === $this->getJurisdictionGroupType()
      ? $group
      : NULL;
  }

  /**
   * Loads editable nodes for the selected scope.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Editable nodes.
   */
  private function loadContentNodes(int $jurisdiction_id, ?int $organisation_id, ?string $bundle): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle ? [$bundle] : array_keys(self::EDITABLE_BUNDLES), 'IN')
      ->condition('field_jurisdiction.target_id', $jurisdiction_id)
      ->sort('title', 'ASC')
      ->range(0, 200);

    if ($organisation_id !== NULL) {
      $query->condition(
        $query->orConditionGroup()
          ->notExists('field_organisation')
          ->condition('field_organisation.target_id', $organisation_id)
      );
    }
    else {
      $query->notExists('field_organisation');
    }

    $nodes = $storage->loadMultiple($query->execute());
    return array_values($nodes);
  }

  /**
   * Resolves an organisation slug or numeric ID inside the jurisdiction.
   */
  private function resolveOrganisationId(GroupInterface $jurisdiction, mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $raw = is_array($value) ? reset($value) : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }

    $storage = $this->entityTypeManager()->getStorage('group');
    $organisation = NULL;
    if (is_numeric($raw)) {
      $candidate = $storage->load((int) $raw);
      $organisation = $candidate instanceof GroupInterface ? $candidate : NULL;
    }
    else {
      $slug = strtolower(trim($raw));
      if (!preg_match(self::PUBLIC_SLUG_PATTERN, $slug)) {
        return NULL;
      }
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'org')
        ->condition('field_slug', $slug)
        ->condition('field_jurisdiction.target_id', (int) $jurisdiction->id())
        ->range(0, 1)
        ->execute();
      if ($ids !== []) {
        $candidate = $storage->load((string) reset($ids));
        $organisation = $candidate instanceof GroupInterface ? $candidate : NULL;
      }
    }

    if (!$organisation || $organisation->bundle() !== 'org' || !$organisation->hasField('field_jurisdiction')) {
      return NULL;
    }

    $target_id = (int) ($organisation->get('field_jurisdiction')->target_id ?? 0);
    return $target_id === (int) $jurisdiction->id() ? (int) $organisation->id() : NULL;
  }

  /**
   * Resolves organisation from body or query.
   *
   * @return int|false|null
   *   Organisation id, FALSE when invalid, NULL when global/no change.
   */
  private function resolveOrganisationInput(GroupInterface $jurisdiction, array $payload, Request $request, bool $create = TRUE): int|false|null {
    if (array_key_exists('organisation', $payload)) {
      $organisation = $payload['organisation'];
      if ($organisation === NULL || $organisation === '') {
        return NULL;
      }
      return $this->resolveOrganisationId($jurisdiction, $organisation) ?? FALSE;
    }

    if ($create && $request->query->has('organisation')) {
      return $this->resolveOrganisationId($jurisdiction, $request->query->get('organisation')) ?? FALSE;
    }

    return NULL;
  }

  /**
   * Applies scope and body fields.
   */
  private function applySharedFields(NodeInterface $node, GroupInterface $jurisdiction, int|false|null $organisation_id, array $payload, bool $create): ?JsonResponse {
    if ($node->hasField('field_jurisdiction')) {
      $node->set('field_jurisdiction', ['target_id' => $jurisdiction->id()]);
    }

    if ($node->hasField('field_organisation') && ($create || array_key_exists('organisation', $payload) || $organisation_id !== NULL)) {
      $node->set('field_organisation', $organisation_id ? ['target_id' => $organisation_id] : []);
    }

    if ($node->hasField('body') && array_key_exists('body', $payload)) {
      $body = $this->bodyString($payload['body']);
      if ($body === FALSE) {
        return $this->errorResponse('Body is too large.', Response::HTTP_UNPROCESSABLE_ENTITY, 'body');
      }
      $node->set('body', [
        'value' => $body,
        'format' => 'basic_html',
      ]);
    }
    elseif ($create && $node->hasField('body')) {
      $node->set('body', [
        'value' => '',
        'format' => 'basic_html',
      ]);
    }

    return NULL;
  }

  /**
   * Applies bundle-specific fields and returns a validation response on error.
   */
  private function applyKindFields(NodeInterface $node, GroupInterface $jurisdiction, int|false|null $organisation_id, array $payload, bool $create): ?JsonResponse {
    if ($node->bundle() === 'page') {
      return $this->applyPageFields($node, $payload);
    }
    if ($node->bundle() === 'boilerplate') {
      return $this->applyBoilerplateFields($node, $payload);
    }
    if ($node->bundle() === 'content_section') {
      return $this->applyContentSectionFields($node, $jurisdiction, $organisation_id, $payload, $create);
    }

    return $this->errorResponse('Unsupported content kind.', Response::HTTP_BAD_REQUEST, 'kind');
  }

  /**
   * Applies page-specific fields.
   */
  private function applyPageFields(NodeInterface $node, array $payload): ?JsonResponse {
    $page = $this->nestedArray($payload['page'] ?? []);

    if ($node->hasField('field_page_key') && (array_key_exists('pageKey', $payload) || array_key_exists('key', $page) || !$node->id())) {
      $key = $this->stringValue($payload['pageKey'] ?? $page['key'] ?? 'content');
      if ($key === NULL || !isset(self::PAGE_KEYS[$key])) {
        return $this->errorResponse('Unknown page key.', Response::HTTP_UNPROCESSABLE_ENTITY, 'pageKey');
      }
      $node->set('field_page_key', $key);
    }

    if ($node->hasField('field_public_slug') && (array_key_exists('publicSlug', $payload) || array_key_exists('slug', $page) || !$node->id())) {
      $slug = $this->slugValue($payload['publicSlug'] ?? $page['slug'] ?? '');
      if ($slug === FALSE) {
        return $this->errorResponse('Public slug is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY, 'publicSlug');
      }
      $node->set('field_public_slug', $slug ?? []);
    }

    if ($node->hasField('field_footer_visible') && (array_key_exists('footerVisible', $payload) || array_key_exists('footerVisible', $page) || !$node->id())) {
      $node->set('field_footer_visible', $this->boolValue($payload['footerVisible'] ?? $page['footerVisible'] ?? TRUE));
    }

    if ($node->hasField('field_page_weight') && (array_key_exists('order', $payload) || array_key_exists('order', $page) || !$node->id())) {
      $node->set('field_page_weight', $this->intValue($payload['order'] ?? $page['order'] ?? 0));
    }

    return NULL;
  }

  /**
   * Applies boilerplate-specific fields.
   */
  private function applyBoilerplateFields(NodeInterface $node, array $payload): ?JsonResponse {
    $boilerplate = $this->nestedArray($payload['boilerplate'] ?? []);
    if ($node->hasField('field_boilerplate_type') && (array_key_exists('boilerplateType', $payload) || array_key_exists('type', $boilerplate) || !$node->id())) {
      $type = $this->stringValue($payload['boilerplateType'] ?? $boilerplate['type'] ?? 'status_notes');
      if ($type === NULL || !isset(self::BOILERPLATE_TYPES[$type])) {
        return $this->errorResponse('Unknown boilerplate type.', Response::HTTP_UNPROCESSABLE_ENTITY, 'boilerplateType');
      }
      $node->set('field_boilerplate_type', $type);
    }

    return NULL;
  }

  /**
   * Applies content-section fields.
   */
  private function applyContentSectionFields(NodeInterface $node, GroupInterface $jurisdiction, int|false|null $organisation_id, array $payload, bool $create): ?JsonResponse {
    $section = $this->nestedArray($payload['section'] ?? []);

    if ($node->hasField('field_form_schema') && ($create || array_key_exists('formId', $payload) || array_key_exists('formId', $section) || array_key_exists('form', $payload) || array_key_exists('form', $section))) {
      $form = $this->resolveFormInput($jurisdiction, $organisation_id, $payload, $section);
      if ($form === FALSE) {
        return $this->errorResponse('Form not found.', Response::HTTP_UNPROCESSABLE_ENTITY, 'formId');
      }
      $node->set('field_form_schema', $form instanceof FormSchemaInterface ? ['target_id' => $form->id()] : []);
    }

    if ($node->hasField('field_content_placement') && ($create || array_key_exists('placement', $payload) || array_key_exists('placement', $section))) {
      $placement = $this->stringValue($payload['placement'] ?? $section['placement'] ?? 'form_intro');
      if ($placement === NULL || !isset(self::PLACEMENTS[$placement])) {
        return $this->errorResponse('Unknown placement.', Response::HTTP_UNPROCESSABLE_ENTITY, 'placement');
      }
      $node->set('field_content_placement', $placement);
    }

    if ($node->hasField('field_content_weight') && ($create || array_key_exists('order', $payload) || array_key_exists('order', $section))) {
      $node->set('field_content_weight', $this->intValue($payload['order'] ?? $section['order'] ?? 0));
    }

    return NULL;
  }

  /**
   * Resolves a form UUID inside the selected jurisdiction and organisation.
   *
   * @return \Drupal\beantragt_schema\Entity\FormSchemaInterface|false|null
   *   Form entity, FALSE when invalid, NULL when empty.
   */
  private function resolveFormInput(GroupInterface $jurisdiction, int|false|null $organisation_id, array $payload, array $section): FormSchemaInterface|false|null {
    $raw = $payload['formId'] ?? $section['formId'] ?? $payload['form'] ?? $section['form'] ?? NULL;
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    $uuid = strtolower(trim((string) (is_array($raw) ? reset($raw) : $raw)));
    if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
      return FALSE;
    }
    $matches = $this->entityTypeManager()->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    $form = reset($matches);
    if (!$form instanceof FormSchemaInterface) {
      return FALSE;
    }

    $scope = $this->resolveFormScope($form);
    if (!$scope['jurisdiction'] instanceof GroupInterface || (string) $scope['jurisdiction']->id() !== (string) $jurisdiction->id()) {
      return FALSE;
    }
    if ($organisation_id !== NULL) {
      return $scope['organisation'] instanceof GroupInterface && (int) $scope['organisation']->id() === $organisation_id ? $form : FALSE;
    }
    return $form;
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
   * Returns a referenced group if present.
   */
  private function referencedGroup(EntityInterface $entity, string $field_name): ?GroupInterface {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }

    $group = $entity->get($field_name)->entity ?? NULL;
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Loads one editable node by UUID.
   */
  private function loadEditableContentByUuid(string $uuid): ?NodeInterface {
    if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
      return NULL;
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uuid', strtolower($uuid))
      ->condition('type', array_keys(self::EDITABLE_BUNDLES), 'IN')
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }

    $node = $storage->load((string) reset($ids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Validates content before saving and exposes the first violation safely.
   */
  private function validateContent(NodeInterface $node): ?JsonResponse {
    $violations = $node->validate();
    if ($violations->count() === 0) {
      return NULL;
    }

    $violation = $violations[0];
    $field = trim((string) $violation->getPropertyPath()) ?: NULL;
    return $this->errorResponse(
      (string) $violation->getMessage(),
      Response::HTTP_UNPROCESSABLE_ENTITY,
      $field,
    );
  }

  /**
   * Checks jurisdiction ownership for an editable node.
   */
  private function nodeBelongsToJurisdiction(NodeInterface $node, int $jurisdiction_id): bool {
    return $node->hasField('field_jurisdiction')
      && !$node->get('field_jurisdiction')->isEmpty()
      && (int) ($node->get('field_jurisdiction')->target_id ?? 0) === $jurisdiction_id;
  }

  /**
   * Serializes an editable content node for the admin UI.
   */
  private function serializeContent(NodeInterface $node): array {
    $body = AdminContent::bodyPayload($node);
    $item = [
      'id' => $node->uuid(),
      'internalId' => (int) $node->id(),
      'kind' => $node->bundle(),
      'title' => $node->label(),
      'status' => $node->isPublished(),
      'langcode' => $node->language()->getId(),
      'changed' => $node->getChangedTime(),
      'scope' => [
        'jurisdictionInternalId' => $this->targetId($node, 'field_jurisdiction'),
        'organisationInternalId' => $this->targetId($node, 'field_organisation'),
      ],
      'body' => $body,
    ];

    if ($node->bundle() === 'page') {
      $slug = $this->scalarValue($node, 'field_public_slug');
      $item['page'] = [
        'key' => $this->scalarValue($node, 'field_page_key') ?: 'content',
        'slug' => $slug,
        'path' => $slug ? '/pages/' . $slug : NULL,
        'footerVisible' => $this->boolField($node, 'field_footer_visible', TRUE),
        'order' => $this->intField($node, 'field_page_weight', 0),
      ];
    }
    elseif ($node->bundle() === 'boilerplate') {
      $item['boilerplate'] = [
        'type' => $this->scalarValue($node, 'field_boilerplate_type') ?: 'status_notes',
        'text' => trim(Html::decodeEntities(strip_tags($body['processed'] ?: $body['value']))),
      ];
    }
    elseif ($node->bundle() === 'content_section') {
      $item['section'] = [
        'placement' => $this->scalarValue($node, 'field_content_placement') ?: 'form_intro',
        'order' => $this->intField($node, 'field_content_weight', 0),
        'form' => $this->referencedFormProjection($node),
      ];
    }

    return $item;
  }

  /**
   * Projects the optional referenced form using public identifiers.
   */
  private function referencedFormProjection(NodeInterface $node): ?array {
    if (!$node->hasField('field_form_schema') || $node->get('field_form_schema')->isEmpty()) {
      return NULL;
    }
    $form = $node->get('field_form_schema')->entity ?? NULL;
    if (!$form instanceof FormSchemaInterface) {
      return NULL;
    }

    return [
      'id' => $form->uuid(),
      'title' => $form->label(),
    ];
  }

  /**
   * Parses a JSON request body into a plain array.
   */
  private function requestPayload(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Normalizes optional kind query.
   */
  private function optionalBundle(mixed $value): string|false|null {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $bundle = $this->requiredBundle($value);
    return $bundle ?? FALSE;
  }

  /**
   * Normalizes a required content kind.
   */
  private function requiredBundle(mixed $value): ?string {
    $kind = $this->stringValue($value);
    if ($kind === NULL) {
      return NULL;
    }
    $kind = match ($kind) {
      'pages' => 'page',
      'boilerplates' => 'boilerplate',
      'section', 'sections' => 'content_section',
      default => $kind,
    };
    return isset(self::EDITABLE_BUNDLES[$kind]) ? $kind : NULL;
  }

  /**
   * Returns a string value.
   */
  private function stringValue(mixed $value): ?string {
    $raw = is_array($value) ? reset($value) : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }
    $value = strtolower(trim((string) $raw));
    return $value === '' ? NULL : $value;
  }

  /**
   * Returns a required human string.
   */
  private function requiredString(mixed $value, int $max_length): ?string {
    $raw = is_array($value) ? reset($value) : $value;
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }
    $value = trim((string) $raw);
    if ($value === '' || mb_strlen($value) > $max_length) {
      return NULL;
    }
    return $value;
  }

  /**
   * Returns a normalized public slug, FALSE for invalid, NULL for empty.
   */
  private function slugValue(mixed $value): string|false|null {
    $raw = is_array($value) ? reset($value) : $value;
    if ($raw === NULL || $raw === '') {
      return NULL;
    }
    if (!is_string($raw) && !is_numeric($raw)) {
      return FALSE;
    }
    $slug = strtolower(trim((string) $raw));
    if ($slug === '') {
      return NULL;
    }
    return preg_match(self::PUBLIC_SLUG_PATTERN, $slug) === 1 ? $slug : FALSE;
  }

  /**
   * Returns a safe body string within the supported size.
   */
  private function bodyString(mixed $value): string|false {
    if (is_array($value)) {
      $value = $value['value'] ?? '';
    }
    $body = is_string($value) || is_numeric($value) ? (string) $value : '';
    return mb_strlen($body) <= self::MAX_BODY_LENGTH ? $body : FALSE;
  }

  /**
   * Returns a nested array.
   */
  private function nestedArray(mixed $value): array {
    return is_array($value) ? $value : [];
  }

  /**
   * Returns a boolean value from common JSON form values.
   */
  private function boolValue(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_numeric($value)) {
      return (int) $value === 1;
    }
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
    }
    return FALSE;
  }

  /**
   * Returns an integer value.
   */
  private function intValue(mixed $value): int {
    if (is_numeric($value)) {
      return max(-9999, min(9999, (int) $value));
    }
    return 0;
  }

  /**
   * Reads a scalar field value.
   */
  private function scalarValue(NodeInterface $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $value = $node->get($field)->value ?? NULL;
    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * Reads an entity reference target id.
   */
  private function targetId(NodeInterface $node, string $field): ?int {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $target_id = (int) ($node->get($field)->target_id ?? 0);
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * Reads a boolean field.
   */
  private function boolField(NodeInterface $node, string $field, bool $default): bool {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return $default;
    }
    return (bool) $node->get($field)->value;
  }

  /**
   * Reads an integer field.
   */
  private function intField(NodeInterface $node, string $field, int $default): int {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return $default;
    }
    return (int) $node->get($field)->value;
  }

  /**
   * Builds an error response.
   */
  private function errorResponse(string $message, int $status, ?string $field = NULL): JsonResponse {
    return $this->privateResponse([
      'error' => [
        'message' => $message,
        'field' => $field,
      ],
    ], $status);
  }

  /**
   * Builds a private admin JSON response.
   */
  private function privateResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
