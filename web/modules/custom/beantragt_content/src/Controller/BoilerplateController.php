<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Controller;

use Drupal\beantragt_content\Content\AdminContent;
use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
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
 * Admin API for jurisdiction-scoped boilerplates.
 */
final class BoilerplateController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Boilerplate types supported by the Mark-a-Spot-compatible content model.
   */
  private const ALLOWED_TYPES = [
    'status_notes' => TRUE,
    'service_provider' => TRUE,
    'remarks' => TRUE,
  ];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly ApplicationCaseScope $applicationCaseScope,
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
      $container->get('beantragt_application.application_case_scope'),
    );
  }

  /**
   * Access check for admin boilerplate endpoints.
   *
   * Management roles keep their access to every type. Besides them, whoever
   * may change application status in an office may read that office's
   * status-note text blocks shared by the municipality or owned by the office.
   */
  public function accessCheck(AccountInterface $account, string $jurisdiction_id, ?Request $request = NULL): AccessResultInterface {
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

    $membership_cache_tags = ApplicationCaseScope::cacheTags($account);
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
            ->addCacheTags($membership_cache_tags);
        }
      }
    }

    $read_contexts = ['user', 'user.permissions', 'url.path', 'url.query_args:type', 'url.query_args:organisation'];
    $office = $this->statusNoteReadOffice($account, $resolved_id, $request);
    if ($office instanceof GroupInterface) {
      return AccessResult::allowed()
        ->addCacheContexts($read_contexts)
        ->addCacheTags($membership_cache_tags)
        ->addCacheableDependency($office);
    }

    return AccessResult::forbidden('User is not an administrator for this jurisdiction.')
      ->addCacheContexts(array_merge(['user.roles'], $read_contexts))
      ->addCacheTags($membership_cache_tags)
      ->setCacheMaxAge(0);
  }

  /**
   * Returns the office whose status-note text blocks the caller may read.
   *
   * All must hold: the permission status changes require; type status_notes;
   * an office named that resolves inside the municipality of the path
   * (slug or numeric id, compared by resolved id); and the status rule of
   * ApplicationCaseScope in that office or its municipality.
   */
  private function statusNoteReadOffice(AccountInterface $account, int $jurisdiction_id, ?Request $request): ?GroupInterface {
    if (!$request instanceof Request || !$account->hasPermission('manage workflow transitions')) {
      return NULL;
    }
    // InputBag::get() throws for arrays; an unnamed or non-string scope
    // fails closed.
    $query = $request->query->all();
    $type = array_key_exists('type', $query) ? $query['type'] : 'status_notes';
    $organisation = $query['organisation'] ?? NULL;
    if (!is_string($type) || strtolower(trim($type)) !== 'status_notes'
      || !is_string($organisation) || trim($organisation) === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager()->getStorage('group');
    $jurisdiction = $storage->load($jurisdiction_id);
    if (!$jurisdiction instanceof GroupInterface || !$this->isJurisdictionGroup($jurisdiction)) {
      return NULL;
    }
    $organisation_id = $this->resolveOrganisationId($jurisdiction, $organisation);
    $office = $organisation_id !== NULL ? $storage->load($organisation_id) : NULL;
    if (!$office instanceof GroupInterface || !$this->applicationCaseScope->allowsGroup($office, $account)) {
      return NULL;
    }
    return $office;
  }

  /**
   * Lists status-note boilerplates for one jurisdiction.
   */
  public function listBoilerplates(Request $request, string $jurisdiction_id): JsonResponse {
    $jurisdiction = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$jurisdiction) {
      return $this->errorResponse('Jurisdiction not found.', Response::HTTP_NOT_FOUND);
    }

    $type = strtolower(trim((string) $request->query->get('type', 'status_notes')));
    if (!isset(self::ALLOWED_TYPES[$type])) {
      return $this->errorResponse('Unknown boilerplate type.', Response::HTTP_BAD_REQUEST);
    }

    $organisation_id = $this->resolveOrganisationId($jurisdiction, $request->query->get('organisation'));
    if ($request->query->has('organisation') && $organisation_id === NULL) {
      return $this->errorResponse('Organisation not found.', Response::HTTP_NOT_FOUND);
    }

    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'boilerplate')
      ->condition('status', 1)
      ->condition('field_boilerplate_type', $type)
      ->condition('field_jurisdiction.target_id', (int) $jurisdiction->id())
      ->sort('title', 'ASC')
      ->range(0, 100);

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

    $data = [];
    foreach ($nodes as $node) {
      $data[] = $this->serializeBoilerplate($node);
    }

    return $this->privateResponse(['data' => $data]);
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
      if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug)) {
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
   * Serializes a boilerplate node for admin UI use.
   */
  private function serializeBoilerplate(NodeInterface $node): array {
    $body = AdminContent::bodyPayload($node);

    return [
      'id' => $node->uuid(),
      'internalId' => (int) $node->id(),
      'title' => $node->label(),
      'type' => $node->hasField('field_boilerplate_type')
        ? (string) ($node->get('field_boilerplate_type')->value ?? 'status_notes')
        : 'status_notes',
      'body' => $body,
      'text' => trim(Html::decodeEntities(strip_tags($body['processed'] ?: $body['value']))),
    ];
  }

  /**
   * Builds an error response.
   */
  private function errorResponse(string $message, int $status): JsonResponse {
    return $this->privateResponse([
      'error' => [
        'message' => $message,
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
