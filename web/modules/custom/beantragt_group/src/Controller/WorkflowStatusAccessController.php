<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks whether the current user may administer workflow statuses for a group.
 */
final class WorkflowStatusAccessController extends ControllerBase {

  /**
   * Constructs a WorkflowStatusAccessController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $manager,
    protected readonly AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * Checks workflow-status administration access for an org or jurisdiction.
   *
   * Organisation access is granted to admins of that organisation or admins of
   * its parent jurisdiction. Jurisdiction access is granted to admins of that
   * jurisdiction. Only platform administrators with the restricted scope-bypass
   * permission skip the group membership check.
   */
  public function check(int $group_id): JsonResponse {
    $group = $this->manager->getStorage('group')->load($group_id);
    if (!$group instanceof GroupInterface) {
      return $this->json(['error' => 'Group not found.'], Response::HTTP_NOT_FOUND);
    }

    if (!in_array($group->bundle(), ['jur', 'org'], TRUE)) {
      return $this->json(['error' => 'Unsupported group type.'], Response::HTTP_BAD_REQUEST);
    }

    if (!$this->hasWorkflowAdminRole()) {
      return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
    }

    if ($this->account->hasPermission('bypass beantragt group scope')) {
      return $this->json(['allowed' => TRUE]);
    }

    if ($this->isGroupAdmin($group)) {
      return $this->json(['allowed' => TRUE]);
    }

    if ($group->bundle() === 'org') {
      $jurisdiction_id = $this->parentJurisdictionId($group);
      $jurisdiction = $jurisdiction_id !== NULL
        ? $this->manager->getStorage('group')->load($jurisdiction_id)
        : NULL;
      if ($jurisdiction instanceof GroupInterface
        && $jurisdiction->bundle() === 'jur'
        && $this->isGroupAdmin($jurisdiction)) {
        return $this->json(['allowed' => TRUE]);
      }
    }

    return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
  }

  /**
   * Returns TRUE if the account may administer workflow definitions.
   */
  protected function hasWorkflowAdminRole(): bool {
    return $this->account->hasPermission('administer workflow_status entities')
      || $this->account->hasPermission('bypass beantragt group scope');
  }

  /**
   * Returns TRUE if the current user has an admin membership in a group.
   */
  protected function isGroupAdmin(GroupInterface $group): bool {
    return \_beantragt_group_account_has_group_admin_role($group, $this->account);
  }

  /**
   * Resolves an organisation group's parent jurisdiction id.
   */
  protected function parentJurisdictionId(GroupInterface $group): ?int {
    if (!$group->hasField('field_jurisdiction') || $group->get('field_jurisdiction')->isEmpty()) {
      return NULL;
    }

    $target_id = (int) ($group->get('field_jurisdiction')->target_id ?? 0);
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * Builds a no-store JSON response.
   *
   * @param mixed[] $data
   *   Response payload.
   * @param int $status
   *   HTTP status code.
   */
  protected function json(array $data, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

}
