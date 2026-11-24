<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists admin workspaces visible to the current Verwaltung session.
 */
final class AdminWorkspaceController extends ControllerBase {

  /**
   * Constructs an AdminWorkspaceController.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $manager,
    protected readonly AccountInterface $account,
    private readonly FormSchemaWorkspaceScope $workspaceScope,
  ) {}

  /**
   * Current user's group memberships, keyed by group id.
   *
   * @var array<int, string[]>|null
   */
  private ?array $membershipsByGroupId = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_schema.workspace_scope'),
    );
  }

  /**
   * Lists jurisdiction and organisation workspaces for the current user.
   */
  public function listWorkspaces(): JsonResponse {
    if (!$this->isStaffAccount()) {
      return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
    }

    $groups = $this->loadWorkspaceGroups();
    $jurisdictions = [];
    $organisations = [];
    $personal = $this->account->isAuthenticated() && !BeantragtRoles::isServiceAccount($this->account);
    $options = $personal && $this->manager->getAccessControlHandler('form_schema')->createAccess(NULL, $this->account)
      ? $this->workspaceScope->workspaceOptions($this->account)
      : [];
    // An organisation admin may select its municipality as a parent but
    // needs jurisdiction management to create a municipality-wide form.
    $municipal_forms = array_diff_key($options['jurisdictions'] ?? [], array_flip($options['organisation_required_jurisdictions'] ?? []));

    foreach ($groups as $group) {
      if ($group->bundle() !== 'jur') {
        continue;
      }
      if ($this->account->hasPermission('bypass beantragt group scope') || $this->isGroupAdmin($group)) {
        $workspace = $this->normalizeJurisdiction($group);
        if ($workspace !== NULL) {
          if ($personal) {
            $workspace['canCreateFormSchema'] = isset($municipal_forms[(int) $group->id()]);
          }
          $jurisdictions[] = $workspace;
        }
      }
    }

    foreach ($groups as $group) {
      if ($group->bundle() !== 'org') {
        continue;
      }
      $jurisdiction = $this->parentJurisdiction($group);
      if (!$jurisdiction instanceof GroupInterface) {
        continue;
      }
      if (
        $this->account->hasPermission('bypass beantragt group scope')
        || $this->isGroupMember($group)
        || $this->isGroupAdmin($group)
        || $this->isGroupAdmin($jurisdiction)
      ) {
        $workspace = $this->normalizeOrganisation($group, $jurisdiction);
        if ($workspace !== NULL) {
          if ($personal) {
            $workspace['canCreateFormSchema'] = isset($options['jurisdictions'][(int) $jurisdiction->id()], $options['organisations'][(int) $group->id()]);
          }
          $organisations[] = $workspace;
        }
      }
    }

    usort($jurisdictions, $this->workspaceSorter(...));
    usort($organisations, $this->workspaceSorter(...));

    $workspaces = [...$jurisdictions, ...$organisations];

    return $this->json([
      'jurisdictions' => $jurisdictions,
      'organisations' => $organisations,
      'workspaces' => $workspaces,
      'count' => count($workspaces),
    ]);
  }

  /**
   * Returns TRUE when the current account may enter Verwaltung APIs.
   */
  private function isStaffAccount(): bool {
    return $this->account->hasPermission('bypass beantragt group scope')
      || BeantragtRoles::isStaffAccount($this->account);
  }

  /**
   * Loads all jurisdiction and organisation group entities.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Groups keyed by entity id.
   */
  private function loadWorkspaceGroups(): array {
    $storage = $this->manager->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', ['jur', 'org'], 'IN')
      ->condition('status', 1)
      ->sort('label')
      ->execute();

    return array_filter(
      $storage->loadMultiple($ids),
      static fn ($group): bool => $group instanceof GroupInterface
    );
  }

  /**
   * Returns TRUE when the current account has an admin role in the group.
   */
  private function isGroupAdmin(GroupInterface $group): bool {
    $roles = [];
    foreach ($this->currentUserMemberships()[(int) $group->id()] ?? [] as $role_id) {
      $role = $this->manager->getStorage('group_role')->load($role_id);
      if ($role instanceof GroupRoleInterface) {
        $roles[] = $role;
      }
    }
    return ApplicationCaseScope::hasAdminGroupRole($roles);
  }

  /**
   * Returns TRUE when the current account is a member of the group.
   */
  private function isGroupMember(GroupInterface $group): bool {
    return array_key_exists((int) $group->id(), $this->currentUserMemberships());
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

    $uid = (int) $this->account->id();
    if ($uid <= 0) {
      $this->membershipsByGroupId = [];
      return $this->membershipsByGroupId;
    }

    $storage = $this->manager->getStorage('group_relationship');
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
   * Normalizes a jurisdiction group to the Nuxt admin workspace shape.
   *
   * @return array<string, mixed>|null
   *   Workspace data or NULL when the group has no public slug.
   */
  private function normalizeJurisdiction(GroupInterface $group): ?array {
    $slug = $this->groupSlug($group);
    if ($slug === NULL) {
      return NULL;
    }

    $label = $group->label() ?: $slug;
    $parent = $this->parentJurisdictionForJurisdiction($group);
    return [
      'type' => 'jurisdiction',
      'id' => $group->uuid(),
      'internalId' => (int) $group->id(),
      'label' => $label,
      'slug' => $slug,
      'key' => $slug,
      'path' => '/' . $slug,
      'jurisdictionId' => $group->uuid(),
      'jurisdictionInternalId' => (int) $group->id(),
      'jurisdictionSlug' => $slug,
      'jurisdictionLabel' => $label,
      'organisationId' => NULL,
      'organisationInternalId' => NULL,
      'organisationSlug' => NULL,
      'organisationLabel' => NULL,
      'parentId' => $parent?->uuid(),
      'parentLabel' => $parent?->label(),
    ];
  }

  /**
   * Resolves a jurisdiction group's optional parent jurisdiction.
   */
  private function parentJurisdictionForJurisdiction(GroupInterface $group): ?GroupInterface {
    if (!$group->hasField('field_parent_jurisdiction') || $group->get('field_parent_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $jurisdiction = $group->get('field_parent_jurisdiction')->__get('entity');
    return $jurisdiction instanceof GroupInterface && $jurisdiction->bundle() === 'jur'
      ? $jurisdiction
      : NULL;
  }

  /**
   * Normalizes an organisation group to the Nuxt admin workspace shape.
   *
   * @return array<string, mixed>|null
   *   Workspace data or NULL when either group has no public slug.
   */
  private function normalizeOrganisation(GroupInterface $group, GroupInterface $jurisdiction): ?array {
    $slug = $this->groupSlug($group);
    $jurisdiction_slug = $this->groupSlug($jurisdiction);
    if ($slug === NULL || $jurisdiction_slug === NULL) {
      return NULL;
    }

    $label = $group->label() ?: $slug;
    $jurisdiction_label = $jurisdiction->label() ?: $jurisdiction_slug;
    $key = $jurisdiction_slug . '/' . $slug;

    return [
      'type' => 'organisation',
      'id' => $group->uuid(),
      'internalId' => (int) $group->id(),
      'label' => $label,
      'slug' => $slug,
      'key' => $key,
      'path' => '/' . $key,
      'jurisdictionId' => $jurisdiction->uuid(),
      'jurisdictionInternalId' => (int) $jurisdiction->id(),
      'jurisdictionSlug' => $jurisdiction_slug,
      'jurisdictionLabel' => $jurisdiction_label,
      'organisationId' => $group->uuid(),
      'organisationInternalId' => (int) $group->id(),
      'organisationSlug' => $slug,
      'organisationLabel' => $label,
      'parentId' => $jurisdiction->uuid(),
      'parentLabel' => $jurisdiction_label,
    ];
  }

  /**
   * Returns a public slug for a workspace group.
   */
  private function groupSlug(GroupInterface $group): ?string {
    if (!$group->hasField('field_slug') || $group->get('field_slug')->isEmpty()) {
      return NULL;
    }

    $slug = strtolower(trim((string) $group->get('field_slug')->__get('value')));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1
      ? $slug
      : NULL;
  }

  /**
   * Sorts workspace rows by jurisdiction and label.
   *
   * @param array<string, mixed> $a
   *   First workspace.
   * @param array<string, mixed> $b
   *   Second workspace.
   */
  private function workspaceSorter(array $a, array $b): int {
    return strnatcasecmp((string) ($a['jurisdictionLabel'] ?? $a['label'] ?? ''), (string) ($b['jurisdictionLabel'] ?? $b['label'] ?? ''))
      ?: strnatcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
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
