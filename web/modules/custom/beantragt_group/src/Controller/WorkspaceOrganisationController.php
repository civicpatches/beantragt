<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Service\ReferenceNumberGenerator;
use Drupal\beantragt_group\Service\WorkspaceReferencePattern;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\OrgHierarchyResolverInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API for organisation workspace structure management.
 */
final class WorkspaceOrganisationController extends ControllerBase {

  /**
   * Allowed workspace slug shape.
   */
  private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  /**
   * Allowed organisation identifier shape.
   */
  private const ORGANISATION_IDENTIFIER_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

  /**
   * Route and app segments that cannot be used as workspace slugs.
   */
  private const RESERVED_SLUGS = [
    'admin',
    'api',
    'auth',
    'documents',
    'form',
    'forms',
    'login',
    'logout',
    'meine-antraege',
    'meine-daten',
    'pages',
    'schema',
    'schemas',
    'start',
    'status',
    'workspace',
  ];

  /**
   * Max generated reference number length.
   */
  private const REFERENCE_NUMBER_MAX_LENGTH = 64;

  /**
   * Current user's group memberships keyed by group ID.
   *
   * @var array<int, string[]>|null
   */
  private ?array $membershipsByGroupId = NULL;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    protected readonly OrgHierarchyResolverInterface $orgHierarchyResolver,
    protected readonly EmailValidatorInterface $emailValidator,
    protected readonly LoggerInterface $logger,
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
      $container->get('beantragt_group.org_hierarchy_resolver'),
      $container->get('email.validator'),
      $container->get('logger.channel.beantragt_group'),
    );
  }

  /**
   * Lists active organisations below the requested jurisdiction.
   */
  public function listOrganisations(Request $request): JsonResponse {
    $jurisdiction = $this->resolveManageableJurisdiction($request);
    if ($jurisdiction instanceof JsonResponse) {
      return $jurisdiction;
    }

    $organisations = $this->activeOrganisationsForJurisdiction($jurisdiction);
    usort($organisations, $this->organisationSorter(...));

    return $this->json([
      'jurisdiction' => $this->normalizeJurisdiction($jurisdiction),
      'organisations' => array_map($this->normalizeOrganisation(...), $organisations),
      'count' => count($organisations),
    ]);
  }

  /**
   * Creates an organisation below the requested jurisdiction.
   */
  public function createOrganisation(Request $request): JsonResponse {
    $jurisdiction = $this->resolveManageableJurisdiction($request);
    if ($jurisdiction instanceof JsonResponse) {
      return $jurisdiction;
    }

    $payload = $this->decodeDataPayload($request);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }

    $validation = $this->validateOrganisationPayload($jurisdiction, NULL, $payload, TRUE);
    if ($validation instanceof JsonResponse) {
      return $validation;
    }

    $organisation = $this->groupStorage()->create([
      'type' => 'org',
      'label' => $validation['label'],
      'field_slug' => $validation['slug'],
      'field_jurisdiction' => ['target_id' => (int) $jurisdiction->id()],
      'status' => TRUE,
    ]);
    if (!$organisation instanceof GroupInterface) {
      return $this->errorResponse('Organisation could not be created.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->applyOrganisationValues($organisation, $validation);

    try {
      $organisation->save();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to create organisation workspace below jurisdiction @id: @message', [
        '@id' => $jurisdiction->id(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->errorResponse('Organisation could not be created.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('User @user created organisation workspace @id below jurisdiction @jurisdiction', [
      '@user' => $this->currentUser->getDisplayName(),
      '@id' => $organisation->id(),
      '@jurisdiction' => $jurisdiction->id(),
    ]);

    return $this->json(['organisation' => $this->normalizeOrganisation($organisation)], Response::HTTP_CREATED);
  }

  /**
   * Updates structure and metadata for an organisation.
   */
  public function patchOrganisation(Request $request, string $organisation_id): JsonResponse {
    $jurisdiction = $this->resolveManageableJurisdiction($request);
    if ($jurisdiction instanceof JsonResponse) {
      return $jurisdiction;
    }

    $organisation = $this->loadOrganisationInJurisdiction($jurisdiction, $organisation_id);
    if (!$organisation instanceof GroupInterface) {
      return $this->errorResponse('Organisation not found.', NULL, Response::HTTP_NOT_FOUND);
    }

    $payload = $this->decodeDataPayload($request);
    if ($payload instanceof JsonResponse) {
      return $payload;
    }
    if ($payload === []) {
      return $this->errorResponse('No fields provided.');
    }

    $validation = $this->validateOrganisationPayload($jurisdiction, $organisation, $payload, FALSE);
    if ($validation instanceof JsonResponse) {
      return $validation;
    }

    $this->applyOrganisationValues($organisation, $validation);

    try {
      $organisation->save();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to update organisation workspace @id: @message', [
        '@id' => $organisation->id(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->errorResponse('Organisation could not be saved.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('User @user updated organisation workspace @id (keys: @keys)', [
      '@user' => $this->currentUser->getDisplayName(),
      '@id' => $organisation->id(),
      '@keys' => implode(', ', array_keys($validation)),
    ]);

    return $this->json(['organisation' => $this->normalizeOrganisation($organisation)]);
  }

  /**
   * Archives an organisation so it disappears from public/admin workspaces.
   */
  public function deleteOrganisation(Request $request, string $organisation_id): JsonResponse {
    $jurisdiction = $this->resolveManageableJurisdiction($request);
    if ($jurisdiction instanceof JsonResponse) {
      return $jurisdiction;
    }

    $organisation = $this->loadOrganisationInJurisdiction($jurisdiction, $organisation_id);
    if (!$organisation instanceof GroupInterface) {
      return $this->errorResponse('Organisation not found.', NULL, Response::HTTP_NOT_FOUND);
    }

    $children = $this->activeChildren($organisation);
    if ($children !== []) {
      return $this->errorResponse(
        'Organisation cannot be archived while active child organisations exist.',
        'parentOrganisationId',
        Response::HTTP_CONFLICT
      );
    }

    $references = $this->blockingReferences($organisation);
    if ($references !== []) {
      return $this->errorResponse(
        'Organisation cannot be archived while active forms, applications, or content reference it.',
        'organisation',
        Response::HTTP_CONFLICT
      );
    }

    $organisation->set('status', FALSE);

    try {
      $organisation->save();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to archive organisation workspace @id: @message', [
        '@id' => $organisation->id(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->errorResponse('Organisation could not be archived.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('User @user archived organisation workspace @id', [
      '@user' => $this->currentUser->getDisplayName(),
      '@id' => $organisation->id(),
    ]);

    return $this->json([
      'organisation' => $this->normalizeOrganisation($organisation),
      'archived' => TRUE,
    ]);
  }

  /**
   * Resolves and authorizes the jurisdiction addressed by query.
   */
  private function resolveManageableJurisdiction(Request $request): GroupInterface|JsonResponse {
    if (!$this->isStaffAccount()) {
      return $this->errorResponse('Access denied.', NULL, Response::HTTP_FORBIDDEN);
    }

    $jurisdiction_slug = $this->normalizeSlug($request->query->get('jurisdiction'));
    if ($jurisdiction_slug === NULL) {
      return $this->errorResponse(
        'jurisdiction must contain lowercase letters, numbers, and inner hyphens only.',
        'jurisdiction'
      );
    }

    $jurisdiction = $this->loadPublishedGroupBySlug('jur', $jurisdiction_slug);
    if (!$jurisdiction instanceof GroupInterface) {
      return $this->errorResponse('Jurisdiction not found.', NULL, Response::HTTP_NOT_FOUND);
    }

    if (!$this->canManageJurisdiction($jurisdiction)) {
      return $this->errorResponse('Organisation management access denied.', NULL, Response::HTTP_FORBIDDEN);
    }

    return $jurisdiction;
  }

  /**
   * Decodes a `{ data: object }` payload.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Payload data or an error response.
   */
  private function decodeDataPayload(Request $request): array|JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
      return $this->errorResponse('Request body must be a JSON object with a "data" object.');
    }
    return $payload['data'];
  }

  /**
   * Validates incoming organisation values.
   *
   * @param \Drupal\group\Entity\GroupInterface $jurisdiction
   *   Parent jurisdiction for the organisation tree.
   * @param \Drupal\group\Entity\GroupInterface|null $organisation
   *   Existing organisation on updates, or NULL on create.
   * @param array<string, mixed> $data
   *   Incoming payload data.
   * @param bool $create
   *   Whether required create fields should be enforced.
   *
   * @return array<string, mixed>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Normalized values or an error response.
   */
  private function validateOrganisationPayload(
    GroupInterface $jurisdiction,
    ?GroupInterface $organisation,
    array $data,
    bool $create,
  ): array|JsonResponse {
    $allowed = ['label', 'slug', 'parentOrganisationId', 'orgEmail', 'referencePrefix', 'referencePattern'];
    foreach (array_keys($data) as $key) {
      if (!in_array((string) $key, $allowed, TRUE)) {
        return $this->errorResponse(
          'Unknown key: ' . $key . '. Allowed keys: ' . implode(', ', $allowed) . '.',
          (string) $key
        );
      }
    }

    if ($create && !array_key_exists('label', $data)) {
      return $this->errorResponse('label is required.', 'label');
    }
    if ($create && !array_key_exists('slug', $data)) {
      return $this->errorResponse('slug is required.', 'slug');
    }

    $values = [];
    if (array_key_exists('label', $data)) {
      $error = $this->validatePlainString('label', $data['label'], 255, TRUE);
      if ($error !== NULL) {
        return $this->errorResponse($error, 'label');
      }
      $values['label'] = trim((string) $data['label']);
    }

    if (array_key_exists('slug', $data)) {
      $error = $this->validateSlug($jurisdiction, $organisation, $data['slug']);
      if ($error !== NULL) {
        return $this->errorResponse($error, 'slug');
      }
      $values['slug'] = strtolower(trim((string) $data['slug']));
    }

    if (array_key_exists('parentOrganisationId', $data)) {
      $parent = $this->resolveParentOrganisation($jurisdiction, $organisation, $data['parentOrganisationId']);
      if ($parent instanceof JsonResponse) {
        return $parent;
      }
      $values['parentOrganisationId'] = $parent instanceof GroupInterface ? (int) $parent->id() : NULL;
    }

    if (array_key_exists('orgEmail', $data)) {
      $error = $this->validateEmailValue('orgEmail', $data['orgEmail']);
      if ($error !== NULL) {
        return $this->errorResponse($error, 'orgEmail');
      }
      $values['orgEmail'] = trim((string) $data['orgEmail']);
    }

    if (array_key_exists('referencePrefix', $data)) {
      $error = WorkspaceReferencePattern::validatePrefix($data['referencePrefix']);
      if ($error !== NULL) {
        return $this->errorResponse($error, 'referencePrefix');
      }
      $values['referencePrefix'] = trim((string) $data['referencePrefix']);
    }

    if (array_key_exists('referencePattern', $data)) {
      $error = $this->validateReferencePattern($data['referencePattern']);
      if ($error !== NULL) {
        return $this->errorResponse($error, 'referencePattern');
      }
      $values['referencePattern'] = trim((string) $data['referencePattern']);
    }

    return $values;
  }

  /**
   * Applies normalized organisation values to an entity.
   *
   * @param \Drupal\group\Entity\GroupInterface $organisation
   *   Organisation to mutate.
   * @param array<string, mixed> $values
   *   Normalized values.
   */
  private function applyOrganisationValues(GroupInterface $organisation, array $values): void {
    foreach ($values as $key => $value) {
      match ($key) {
        'label' => $organisation->set('label', $value),
        'slug' => $organisation->set('field_slug', $value),
        'parentOrganisationId' => $organisation->set(
          'field_parent_org',
          $value === NULL ? NULL : ['target_id' => $value]
        ),
        'orgEmail' => $organisation->set('field_org_email', $value),
        'referencePrefix' => $organisation->set('field_reference_prefix', $value),
        'referencePattern' => $organisation->set('field_reference_pattern', $value),
        default => NULL,
      };
    }
  }

  /**
   * Resolves an optional parent organisation from a payload value.
   */
  private function resolveParentOrganisation(
    GroupInterface $jurisdiction,
    ?GroupInterface $organisation,
    mixed $value,
  ): GroupInterface|JsonResponse|null {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!is_string($value) && !is_int($value)) {
      return $this->errorResponse('parentOrganisationId must be an organisation uuid or null.', 'parentOrganisationId');
    }

    $identifier = strtolower(trim((string) $value));
    if (preg_match(self::ORGANISATION_IDENTIFIER_PATTERN, $identifier) !== 1) {
      return $this->errorResponse('parentOrganisationId must be an organisation uuid or null.', 'parentOrganisationId');
    }

    $parent = $this->loadOrganisationInJurisdiction($jurisdiction, $identifier);
    if (!$parent instanceof GroupInterface) {
      return $this->errorResponse('Parent organisation not found in this jurisdiction.', 'parentOrganisationId');
    }
    if ($organisation instanceof GroupInterface && (int) $parent->id() === (int) $organisation->id()) {
      return $this->errorResponse('An organisation cannot be its own parent.', 'parentOrganisationId');
    }
    if ($organisation instanceof GroupInterface && $this->parentCreatesCycle($organisation, $parent)) {
      return $this->errorResponse('Parent organisation would create a circular hierarchy.', 'parentOrganisationId');
    }

    return $parent;
  }

  /**
   * Validates a workspace slug and checks collision scope.
   */
  private function validateSlug(GroupInterface $jurisdiction, ?GroupInterface $organisation, mixed $value): ?string {
    if (!is_string($value)) {
      return 'slug must be a string.';
    }

    $slug = strtolower(trim($value));
    if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
      return 'slug must contain lowercase letters, numbers, and inner hyphens only.';
    }
    if (in_array($slug, self::RESERVED_SLUGS, TRUE)) {
      return 'slug is reserved for application routes.';
    }
    if ($this->slugConflicts($jurisdiction, $organisation, $slug)) {
      return 'slug is already used in this workspace scope.';
    }

    return NULL;
  }

  /**
   * Checks whether a slug collides with an active sibling organisation.
   */
  private function slugConflicts(GroupInterface $jurisdiction, ?GroupInterface $organisation, string $slug): bool {
    foreach ($this->groupStorage()->loadByProperties([
      'type' => 'org',
      'field_slug' => $slug,
      'status' => 1,
    ]) as $candidate) {
      if (!$candidate instanceof GroupInterface) {
        continue;
      }
      if ($organisation instanceof GroupInterface && (int) $candidate->id() === (int) $organisation->id()) {
        continue;
      }
      if ((int) ($this->parentJurisdiction($candidate)?->id() ?? 0) === (int) $jurisdiction->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Validates a plain string.
   */
  private function validatePlainString(
    string $key,
    mixed $value,
    int $maxLength,
    bool $required,
    bool $ascii = FALSE,
  ): ?string {
    if (!is_string($value)) {
      return "$key must be a string.";
    }

    $trimmed = trim($value);
    if ($required && $trimmed === '') {
      return "$key is required.";
    }
    if ($trimmed !== strip_tags($trimmed)) {
      return "$key must not contain HTML tags.";
    }
    if (mb_strlen($trimmed) > $maxLength) {
      return "$key must not exceed $maxLength characters.";
    }
    if ($ascii && preg_match('/[^\x20-\x7E]/', $trimmed) === 1) {
      return "$key must only contain ASCII characters.";
    }

    return NULL;
  }

  /**
   * Validates an optional email value.
   */
  private function validateEmailValue(string $key, mixed $value): ?string {
    if (!is_string($value)) {
      return "$key must be a string.";
    }

    $trimmed = trim($value);
    if ($trimmed !== '' && !$this->emailValidator->isValid($trimmed)) {
      return "$key must be a valid email address.";
    }
    return NULL;
  }

  /**
   * Validates an optional org reference pattern.
   */
  private function validateReferencePattern(mixed $value): ?string {
    $plain_error = $this->validatePlainString('referencePattern', $value, 128, FALSE, TRUE);
    if ($plain_error !== NULL) {
      return $plain_error;
    }

    assert(is_string($value));
    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    $unknown_tokens = [];
    preg_match_all('/\{([^}]+)\}/', $trimmed, $matches);
    foreach ($matches[1] as $raw_token) {
      $token = (string) $raw_token;
      if (in_array($token, ['ORG', 'YEAR', 'FORM_CODE', 'UUID_SHORT', 'SEQ'], TRUE)) {
        continue;
      }
      if (preg_match('/^SEQ:(\d+)$/', $token, $seq_match) === 1) {
        $padding = (int) $seq_match[1];
        if ($padding >= 1 && $padding <= ReferenceNumberGenerator::MAX_SEQ_PADDING) {
          continue;
        }
      }
      $unknown_tokens[] = $token;
    }
    if ($unknown_tokens !== []) {
      return 'referencePattern contains unsupported tokens: ' . implode(', ', array_unique($unknown_tokens)) . '.';
    }
    if (preg_match('/\{SEQ(?::\d+)?\}|\{UUID_SHORT\}/', $trimmed) !== 1) {
      return 'referencePattern must include {SEQ}, {SEQ:N}, or {UUID_SHORT}.';
    }

    $rendered = WorkspaceReferencePattern::renderWorstCase($trimmed);
    $length = strlen($rendered);
    if ($length < 3 || $length > self::REFERENCE_NUMBER_MAX_LENGTH) {
      return 'referencePattern must render to a reference number between 3 and 64 characters.';
    }
    if (preg_match('/^[A-Za-z0-9-]+$/', $rendered) !== 1) {
      return 'referencePattern must render using only letters, numbers, and hyphens.';
    }

    return NULL;
  }

  /**
   * Loads a published group by bundle and slug.
   */
  private function loadPublishedGroupBySlug(string $bundle, string $slug): ?GroupInterface {
    $groups = $this->groupStorage()->loadByProperties([
      'type' => $bundle,
      'field_slug' => $slug,
      'status' => 1,
    ]);

    $group = reset($groups);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Loads an active organisation by UUID inside a jurisdiction.
   */
  private function loadOrganisationInJurisdiction(GroupInterface $jurisdiction, string $identifier): ?GroupInterface {
    $identifier = strtolower(trim($identifier));
    if (preg_match(self::ORGANISATION_IDENTIFIER_PATTERN, $identifier) !== 1) {
      return NULL;
    }

    foreach ($this->groupStorage()->loadByProperties(['uuid' => $identifier]) as $candidate) {
      if (!$candidate instanceof GroupInterface || $candidate->bundle() !== 'org') {
        continue;
      }
      if (!$this->isActive($candidate)) {
        continue;
      }
      if ((int) ($this->parentJurisdiction($candidate)?->id() ?? 0) === (int) $jurisdiction->id()) {
        return $candidate;
      }
    }

    return NULL;
  }

  /**
   * Returns labels for active references that block archiving.
   *
   * @return string[]
   *   Blocking reference labels.
   */
  private function blockingReferences(GroupInterface $organisation): array {
    $organisation_id = (int) $organisation->id();
    $checks = [
      'forms' => [
        'entity_type' => 'form_schema',
        'fields' => ['field_organisation', 'field_group'],
      ],
      'applications' => [
        'entity_type' => 'application',
        'fields' => ['field_organisation', 'field_group'],
      ],
      'content' => [
        'entity_type' => 'node',
        'fields' => ['field_organisation'],
        'conditions' => [['status', 1]],
      ],
    ];

    $blocking = [];
    foreach ($checks as $label => $check) {
      foreach ($check['fields'] as $field_name) {
        if ($this->hasReference(
          $check['entity_type'],
          $field_name,
          $organisation_id,
          $check['conditions'] ?? []
        )) {
          $blocking[] = $label;
          break;
        }
      }
    }

    return $blocking;
  }

  /**
   * Checks whether an entity type has references to an organisation.
   *
   * @param string $entity_type
   *   Entity type ID.
   * @param string $field_name
   *   Reference field name.
   * @param int $organisation_id
   *   Organisation group ID.
   * @param array<int, array{0: string, 1: mixed}> $conditions
   *   Additional exact-match conditions.
   */
  private function hasReference(
    string $entity_type,
    string $field_name,
    int $organisation_id,
    array $conditions = [],
  ): bool {
    try {
      $query = $this->entityTypeManager()->getStorage($entity_type)->getQuery()
        ->accessCheck(FALSE)
        ->condition($field_name, $organisation_id)
        ->range(0, 1);
      foreach ($conditions as [$condition_field, $condition_value]) {
        $query->condition($condition_field, $condition_value);
      }
      return $query->execute() !== [];
    }
    catch (\Throwable $exception) {
      $this->logger->error(
        'Failed to verify @entity_type references for organisation @id via @field: @message',
        [
          '@entity_type' => $entity_type,
          '@id' => $organisation_id,
          '@field' => $field_name,
          '@message' => $exception->getMessage(),
        ]
      );
      return TRUE;
    }
  }

  /**
   * Loads active organisations below a jurisdiction.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Active organisation groups.
   */
  private function activeOrganisationsForJurisdiction(GroupInterface $jurisdiction): array {
    $organisations = [];
    foreach ($this->groupStorage()->loadByProperties([
      'type' => 'org',
      'status' => 1,
    ]) as $organisation) {
      if (!$organisation instanceof GroupInterface) {
        continue;
      }
      if ((int) ($this->parentJurisdiction($organisation)?->id() ?? 0) === (int) $jurisdiction->id()) {
        $organisations[] = $organisation;
      }
    }
    return $organisations;
  }

  /**
   * Finds active child organisations.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Active child organisations.
   */
  private function activeChildren(GroupInterface $organisation): array {
    $children = [];
    foreach ($this->groupStorage()->loadByProperties([
      'type' => 'org',
      'field_parent_org' => (int) $organisation->id(),
      'status' => 1,
    ]) as $child) {
      if ($child instanceof GroupInterface && $this->isActive($child)) {
        $children[] = $child;
      }
    }
    return $children;
  }

  /**
   * Checks whether a parent assignment creates a cycle.
   */
  private function parentCreatesCycle(GroupInterface $organisation, GroupInterface $parent): bool {
    $organisation_id = (int) $organisation->id();
    if ($organisation_id <= 0) {
      return FALSE;
    }

    $parent_id = (int) $parent->id();
    if ($parent_id <= 0 || $parent_id === $organisation_id) {
      return TRUE;
    }

    if ($this->orgHierarchyResolver->getRootOrgId($parent_id) === NULL) {
      return TRUE;
    }

    return in_array($organisation_id, $this->orgHierarchyResolver->getAncestorIds($parent_id), TRUE);
  }

  /**
   * Normalizes an incoming slug.
   */
  private function normalizeSlug(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }

    $slug = strtolower(trim($value));
    return preg_match(self::SLUG_PATTERN, $slug) === 1 ? $slug : NULL;
  }

  /**
   * Returns TRUE when the current account may enter Verwaltung APIs.
   */
  private function isStaffAccount(): bool {
    if ($this->currentUser->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }

    return BeantragtRoles::isManagementAccount($this->currentUser);
  }

  /**
   * Returns TRUE when the current account may manage the jurisdiction tree.
   */
  private function canManageJurisdiction(GroupInterface $jurisdiction): bool {
    if ((int) $this->currentUser->id() === 1 || $this->currentUser->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    if (in_array('administrator', $this->currentUser->getRoles(), TRUE)) {
      return TRUE;
    }
    if (!BeantragtRoles::isManagementAccount($this->currentUser)) {
      return FALSE;
    }
    if ($this->groupHasAdminMembership((int) $jurisdiction->id())) {
      return TRUE;
    }

    foreach ($this->currentUserMemberships() as $group_id => $role_ids) {
      if (!$this->roleIdsGrantAdmin($role_ids)) {
        continue;
      }
      $managed_group = $this->groupStorage()->load($group_id);
      if (!$managed_group instanceof GroupInterface || $managed_group->bundle() !== 'jur') {
        continue;
      }
      if (in_array((int) $jurisdiction->id(), $this->hierarchyResolver->getDescendantIds((int) $managed_group->id()), TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when the user holds an admin role in a group.
   */
  private function groupHasAdminMembership(int $group_id): bool {
    return $this->roleIdsGrantAdmin($this->currentUserMemberships()[$group_id] ?? []);
  }

  /**
   * Returns TRUE when any role id grants group admin.
   *
   * @param string[] $role_ids
   *   Group role IDs.
   */
  private function roleIdsGrantAdmin(array $role_ids): bool {
    $roles = [];
    foreach ($role_ids as $role_id) {
      $role = $this->entityTypeManager()->getStorage('group_role')->load($role_id);
      if ($role instanceof GroupRoleInterface) {
        $roles[] = $role;
      }
    }
    return ApplicationCaseScope::hasAdminGroupRole($roles);
  }

  /**
   * Loads current user memberships from group_relationship storage.
   *
   * @return array<int, string[]>
   *   Group role ids keyed by group id.
   */
  private function currentUserMemberships(): array {
    if ($this->membershipsByGroupId !== NULL) {
      return $this->membershipsByGroupId;
    }

    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      $this->membershipsByGroupId = [];
      return $this->membershipsByGroupId;
    }

    $storage = $this->entityTypeManager()->getStorage('group_relationship');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $uid)
      ->execute();

    $memberships = [];
    foreach ($storage->loadMultiple($ids) as $relationship) {
      if (!$relationship instanceof GroupRelationshipInterface) {
        continue;
      }
      if (!$relationship->hasField('gid') || $relationship->get('gid')->isEmpty()) {
        continue;
      }
      $group_id = (int) ($relationship->get('gid')->__get('target_id') ?? 0);
      if ($group_id <= 0) {
        continue;
      }

      $roles = [];
      if ($relationship->hasField('group_roles')) {
        foreach ($relationship->get('group_roles')->getValue() as $item) {
          $role_id = (string) ($item['target_id'] ?? '');
          if ($role_id !== '') {
            $roles[] = $role_id;
          }
        }
      }
      $memberships[$group_id] = $roles;
    }

    $this->membershipsByGroupId = $memberships;
    return $this->membershipsByGroupId;
  }

  /**
   * Resolves an organisation group's parent jurisdiction.
   */
  private function parentJurisdiction(GroupInterface $group): ?GroupInterface {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $jurisdiction = $group->get('field_jurisdiction')->__get('entity');
    return $jurisdiction instanceof GroupInterface && $jurisdiction->bundle() === 'jur'
      ? $jurisdiction
      : NULL;
  }

  /**
   * Resolves a group entity reference field.
   */
  private function referencedGroup(GroupInterface $group, string $field_name, string $bundle): ?GroupInterface {
    if (!$group->hasField($field_name) || $group->get($field_name)->isEmpty()) {
      return NULL;
    }

    $referenced = $group->get($field_name)->__get('entity');
    return $referenced instanceof GroupInterface && $referenced->bundle() === $bundle
      ? $referenced
      : NULL;
  }

  /**
   * Returns TRUE for active groups.
   */
  private function isActive(GroupInterface $group): bool {
    if (!$group->hasField('status') || $group->get('status')->isEmpty()) {
      return TRUE;
    }
    return (bool) ($group->get('status')->__get('value') ?? FALSE);
  }

  /**
   * Normalizes a jurisdiction summary.
   *
   * @return array<string, mixed>
   *   Jurisdiction summary.
   */
  private function normalizeJurisdiction(GroupInterface $jurisdiction): array {
    $slug = $this->fieldString($jurisdiction, 'field_slug');
    return [
      'id' => $jurisdiction->uuid(),
      'internalId' => (int) $jurisdiction->id(),
      'label' => $jurisdiction->label(),
      'slug' => $slug,
      'key' => $slug,
      'path' => '/' . $slug,
    ];
  }

  /**
   * Normalizes an organisation workspace row.
   *
   * @return array<string, mixed>
   *   Organisation row.
   */
  private function normalizeOrganisation(GroupInterface $organisation): array {
    $jurisdiction = $this->parentJurisdiction($organisation);
    $jurisdiction_slug = $jurisdiction ? $this->fieldString($jurisdiction, 'field_slug') : '';
    $slug = $this->fieldString($organisation, 'field_slug');
    $parent = $this->referencedGroup($organisation, 'field_parent_org', 'org');

    return [
      'type' => 'organisation',
      'id' => $organisation->uuid(),
      'internalId' => (int) $organisation->id(),
      'label' => $organisation->label(),
      'slug' => $slug,
      'key' => $jurisdiction_slug . '/' . $slug,
      'path' => '/' . $jurisdiction_slug . '/' . $slug,
      'jurisdictionId' => $jurisdiction?->uuid(),
      'jurisdictionInternalId' => $jurisdiction ? (int) $jurisdiction->id() : NULL,
      'jurisdictionSlug' => $jurisdiction_slug,
      'jurisdictionLabel' => $jurisdiction?->label(),
      'organisationId' => $organisation->uuid(),
      'organisationInternalId' => (int) $organisation->id(),
      'organisationSlug' => $slug,
      'organisationLabel' => $organisation->label(),
      'parentId' => $parent?->uuid() ?? $jurisdiction?->uuid(),
      'parentLabel' => $parent?->label() ?? $jurisdiction?->label(),
      'parentOrganisation' => $parent instanceof GroupInterface ? [
        'id' => $parent->uuid(),
        'internalId' => (int) $parent->id(),
        'label' => $parent->label(),
        'slug' => $this->fieldString($parent, 'field_slug'),
      ] : NULL,
      'settings' => [
        'label' => $organisation->label(),
        'slug' => $slug,
        'orgEmail' => $this->fieldString($organisation, 'field_org_email'),
        'referencePrefix' => $this->fieldString($organisation, 'field_reference_prefix'),
        'referencePattern' => $this->fieldString($organisation, 'field_reference_pattern'),
      ],
    ];
  }

  /**
   * Reads a scalar string field.
   */
  private function fieldString(GroupInterface $group, string $field_name): string {
    if (!$group->hasField($field_name) || $group->get($field_name)->isEmpty()) {
      return '';
    }

    return trim((string) ($group->get($field_name)->__get('value') ?? ''));
  }

  /**
   * Sorts organisation rows by label.
   */
  private function organisationSorter(GroupInterface $a, GroupInterface $b): int {
    return strnatcasecmp($a->label(), $b->label());
  }

  /**
   * Returns group storage.
   */
  private function groupStorage(): EntityStorageInterface {
    return $this->entityTypeManager()->getStorage('group');
  }

  /**
   * Builds an error response.
   */
  private function errorResponse(string $message, ?string $field = NULL, int $status = Response::HTTP_BAD_REQUEST): JsonResponse {
    return $this->json([
      'error' => [
        'message' => $message,
        ...($field ? ['field' => $field] : []),
      ],
    ], $status);
  }

  /**
   * Builds a no-store JSON response.
   *
   * @param mixed[] $data
   *   Response payload.
   * @param int $status
   *   HTTP status code.
   */
  private function json(array $data, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

}
