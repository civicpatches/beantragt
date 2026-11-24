<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Service\ReferenceNumberGenerator;
use Drupal\beantragt_group\Service\WorkspaceReferencePattern;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
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
 * Admin API for workspace identity, slugs, and organisation metadata.
 */
final class WorkspaceSettingsController extends ControllerBase {

  /**
   * Allowed workspace slug shape.
   */
  private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

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
      $container->get('email.validator'),
      $container->get('logger.channel.beantragt_group'),
    );
  }

  /**
   * Returns editable metadata for the requested workspace.
   */
  public function getWorkspaceSettings(Request $request): JsonResponse {
    if (!$this->isStaffAccount()) {
      return $this->json(['error' => ['message' => 'Access denied.']], Response::HTTP_FORBIDDEN);
    }

    $workspace = $this->resolveRequestedWorkspace($request);
    if ($workspace instanceof JsonResponse) {
      return $workspace;
    }
    if (!$workspace instanceof GroupInterface) {
      return $this->json(['error' => ['message' => 'Workspace not found.']], Response::HTTP_NOT_FOUND);
    }
    if (!$this->canManageWorkspace($workspace)) {
      return $this->json(['error' => ['message' => 'Workspace settings access denied.']], Response::HTTP_FORBIDDEN);
    }

    return $this->workspaceResponse($workspace);
  }

  /**
   * Updates editable metadata for the requested workspace.
   */
  public function patchWorkspaceSettings(Request $request): JsonResponse {
    if (!$this->isStaffAccount()) {
      return $this->json(['error' => ['message' => 'Access denied.']], Response::HTTP_FORBIDDEN);
    }

    $workspace = $this->resolveRequestedWorkspace($request);
    if ($workspace instanceof JsonResponse) {
      return $workspace;
    }
    if (!$workspace instanceof GroupInterface) {
      return $this->json(['error' => ['message' => 'Workspace not found.']], Response::HTTP_NOT_FOUND);
    }
    if (!$this->canManageWorkspace($workspace)) {
      return $this->json(['error' => ['message' => 'Workspace settings access denied.']], Response::HTTP_FORBIDDEN);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
      return $this->errorResponse('Request body must be a JSON object with a "data" object.');
    }

    $data = $payload['data'];
    if ($data === []) {
      return $this->errorResponse('No fields provided.');
    }

    $allowed = $workspace->bundle() === 'org'
      ? ['label', 'slug', 'orgEmail', 'referencePrefix', 'referencePattern']
      : ['label', 'slug'];
    foreach (array_keys($data) as $key) {
      if (!in_array((string) $key, $allowed, TRUE)) {
        return $this->errorResponse(
          'Unknown key: ' . $key . '. Allowed keys: ' . implode(', ', $allowed) . '.',
          (string) $key
        );
      }
    }

    $updates = [];
    foreach ($data as $key => $value) {
      $error = $this->validateWorkspaceField($workspace, (string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
      $updates[(string) $key] = $this->normalizeWorkspaceFieldValue((string) $key, $value);
    }

    foreach ($updates as $key => $value) {
      match ($key) {
        'label' => $workspace->set('label', $value),
        'slug' => $workspace->set('field_slug', $value),
        'orgEmail' => $workspace->set('field_org_email', $value),
        'referencePrefix' => $workspace->set('field_reference_prefix', $value),
        'referencePattern' => $workspace->set('field_reference_pattern', $value),
        default => NULL,
      };
    }

    try {
      $workspace->save();
    }
    catch (\Throwable $exception) {
      $this->logger->error(
        'Failed to update workspace settings for @type @id: @message',
        [
          '@type' => $workspace->bundle(),
          '@id' => $workspace->id(),
          '@message' => $exception->getMessage(),
        ]
      );
      return $this->errorResponse(
        'Workspace settings could not be saved.',
        NULL,
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }

    $this->logger->notice(
      'User @user updated workspace settings for @type @id (keys: @keys)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@type' => $workspace->bundle(),
        '@id' => $workspace->id(),
        '@keys' => implode(', ', array_keys($updates)),
      ]
    );

    return $this->workspaceResponse($workspace);
  }

  /**
   * Validates one workspace settings value.
   */
  private function validateWorkspaceField(GroupInterface $workspace, string $key, mixed $value): ?string {
    return match ($key) {
      'label' => $this->validatePlainString('label', $value, 255, TRUE),
      'slug' => $this->validateSlug($workspace, $value),
      'orgEmail' => $this->validateEmailValue('orgEmail', $value),
      'referencePrefix' => WorkspaceReferencePattern::validatePrefix($value),
      'referencePattern' => $this->validateReferencePattern($value),
      default => 'Unknown field: ' . $key . '.',
    };
  }

  /**
   * Normalizes a validated workspace settings value.
   */
  private function normalizeWorkspaceFieldValue(string $key, mixed $value): mixed {
    if (!is_string($value)) {
      return $value;
    }

    return match ($key) {
      'slug' => strtolower(trim($value)),
      default => trim($value),
    };
  }

  /**
   * Validates a workspace slug and checks collision scope.
   */
  private function validateSlug(GroupInterface $workspace, mixed $value): ?string {
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

    if ($this->slugConflicts($workspace, $slug)) {
      return 'slug is already used in this workspace scope.';
    }

    return NULL;
  }

  /**
   * Checks whether a slug collides with another published workspace.
   */
  private function slugConflicts(GroupInterface $workspace, string $slug): bool {
    $existing = $this->groupStorage()->loadByProperties([
      'type' => $workspace->bundle(),
      'field_slug' => $slug,
      'status' => 1,
    ]);

    foreach ($existing as $candidate) {
      if (!$candidate instanceof GroupInterface || (int) $candidate->id() === (int) $workspace->id()) {
        continue;
      }
      if ($workspace->bundle() === 'jur') {
        return TRUE;
      }
      if ((int) ($this->parentJurisdiction($candidate)?->id() ?? 0) === (int) ($this->parentJurisdiction($workspace)?->id() ?? 0)) {
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
   * Resolves the workspace addressed by jurisdiction and optional org slug.
   */
  private function resolveRequestedWorkspace(Request $request): GroupInterface|JsonResponse|null {
    $jurisdiction_slug = $this->normalizeSlug($request->query->get('jurisdiction'));
    if ($jurisdiction_slug === NULL) {
      return NULL;
    }

    $organisation_slug = NULL;
    foreach (['organisation', 'organization', 'org'] as $query_key) {
      if (!$request->query->has($query_key)) {
        continue;
      }
      $organisation_slug = $this->normalizeSlug($request->query->get($query_key));
      if ($organisation_slug === NULL) {
        return $this->errorResponse(
          'organisation must contain lowercase letters, numbers, and inner hyphens only.',
          'organisation'
        );
      }
      break;
    }

    $jurisdiction = $this->loadPublishedGroupBySlug('jur', $jurisdiction_slug);
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }

    if ($organisation_slug === NULL) {
      return $jurisdiction;
    }

    foreach ($this->groupStorage()->loadByProperties([
      'type' => 'org',
      'field_slug' => $organisation_slug,
      'status' => 1,
    ]) as $organisation) {
      if (!$organisation instanceof GroupInterface) {
        continue;
      }
      if ((int) ($this->parentJurisdiction($organisation)?->id() ?? 0) === (int) $jurisdiction->id()) {
        return $organisation;
      }
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
   * Returns TRUE when the current account may manage the workspace.
   */
  private function canManageWorkspace(GroupInterface $workspace): bool {
    if ((int) $this->currentUser->id() === 1 || $this->currentUser->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }
    if (in_array('administrator', $this->currentUser->getRoles(), TRUE)) {
      return TRUE;
    }

    if (!BeantragtRoles::isManagementAccount($this->currentUser)) {
      return FALSE;
    }

    $target_group_id = (int) $workspace->id();
    if ($workspace->bundle() === 'org' && $this->groupHasAdminMembership($target_group_id)) {
      return TRUE;
    }

    $jurisdiction = $workspace->bundle() === 'jur' ? $workspace : $this->parentJurisdiction($workspace);
    if (!$jurisdiction instanceof GroupInterface) {
      return FALSE;
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
   * Returns TRUE when the current account may enter Verwaltung APIs.
   */
  private function isStaffAccount(): bool {
    if ($this->currentUser->hasPermission('bypass beantragt group scope')) {
      return TRUE;
    }

    return BeantragtRoles::isManagementAccount($this->currentUser);
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
   * Builds the response for a workspace.
   */
  private function workspaceResponse(GroupInterface $workspace): JsonResponse {
    return $this->json(['workspace' => $this->normalizeWorkspace($workspace)]);
  }

  /**
   * Normalizes a workspace group for the admin UI.
   *
   * @return array<string, mixed>
   *   Normalized workspace settings.
   */
  private function normalizeWorkspace(GroupInterface $workspace): array {
    if ($workspace->bundle() === 'org') {
      $jurisdiction = $this->parentJurisdiction($workspace);
      $jurisdiction_slug = $jurisdiction ? $this->fieldString($jurisdiction, 'field_slug') : '';
      $slug = $this->fieldString($workspace, 'field_slug');
      return [
        'type' => 'organisation',
        'id' => $workspace->uuid(),
        'internalId' => (int) $workspace->id(),
        'label' => $workspace->label(),
        'slug' => $slug,
        'key' => $jurisdiction_slug . '/' . $slug,
        'path' => '/' . $jurisdiction_slug . '/' . $slug,
        'jurisdictionId' => $jurisdiction?->uuid(),
        'jurisdictionInternalId' => $jurisdiction ? (int) $jurisdiction->id() : NULL,
        'jurisdictionSlug' => $jurisdiction_slug,
        'jurisdictionLabel' => $jurisdiction?->label(),
        'organisationId' => $workspace->uuid(),
        'organisationInternalId' => (int) $workspace->id(),
        'organisationSlug' => $slug,
        'organisationLabel' => $workspace->label(),
        'parentId' => $jurisdiction?->uuid(),
        'parentLabel' => $jurisdiction?->label(),
        'parentOrganisation' => $this->normalizeParentOrganisation($workspace),
        'settings' => [
          'label' => $workspace->label(),
          'slug' => $slug,
          'orgEmail' => $this->fieldString($workspace, 'field_org_email'),
          'referencePrefix' => $this->fieldString($workspace, 'field_reference_prefix'),
          'referencePattern' => $this->fieldString($workspace, 'field_reference_pattern'),
        ],
      ];
    }

    $parent = $this->referencedGroup($workspace, 'field_parent_jurisdiction', 'jur');
    $slug = $this->fieldString($workspace, 'field_slug');
    return [
      'type' => 'jurisdiction',
      'id' => $workspace->uuid(),
      'internalId' => (int) $workspace->id(),
      'label' => $workspace->label(),
      'slug' => $slug,
      'key' => $slug,
      'path' => '/' . $slug,
      'jurisdictionId' => $workspace->uuid(),
      'jurisdictionInternalId' => (int) $workspace->id(),
      'jurisdictionSlug' => $slug,
      'jurisdictionLabel' => $workspace->label(),
      'organisationId' => NULL,
      'organisationInternalId' => NULL,
      'organisationSlug' => NULL,
      'organisationLabel' => NULL,
      'parentId' => $parent?->uuid(),
      'parentLabel' => $parent?->label(),
      'parentJurisdiction' => $parent ? [
        'id' => $parent->uuid(),
        'internalId' => (int) $parent->id(),
        'label' => $parent->label(),
        'slug' => $this->fieldString($parent, 'field_slug'),
      ] : NULL,
      'settings' => [
        'label' => $workspace->label(),
        'slug' => $slug,
      ],
    ];
  }

  /**
   * Normalizes an organisation's optional parent organisation.
   */
  private function normalizeParentOrganisation(GroupInterface $workspace): ?array {
    $parent = $this->referencedGroup($workspace, 'field_parent_org', 'org');
    if (!$parent instanceof GroupInterface) {
      return NULL;
    }

    return [
      'id' => $parent->uuid(),
      'internalId' => (int) $parent->id(),
      'label' => $parent->label(),
      'slug' => $this->fieldString($parent, 'field_slug'),
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
