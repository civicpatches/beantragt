<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\beantragt_group\Service\CaseLayout;
use Drupal\beantragt_group\Service\CaseLayoutAccess;
use Drupal\beantragt_group\Service\CaseLayoutStore;
use Drupal\beantragt_group\Service\SharedAccountReadOnlyInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Internal cookie-authenticated staff case-layout preferences API.
 */
final class CaseLayoutController extends ControllerBase {

  /**
   * Constructs the case layout endpoint.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $account,
    private readonly UserDataInterface $userData,
    private readonly CaseLayoutAccess $access,
    private readonly CaseLayoutStore $store,
    private readonly SharedAccountReadOnlyInterface $sharedAccountReadOnly,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $account;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('user.data'),
      $container->get('beantragt_group.case_layout_access'),
      $container->get('beantragt_group.case_layout_store'),
      $container->get('beantragt_group.shared_account_read_only'),
    );
  }

  /**
   * Reads or replaces the current user's personal workspace layout.
   */
  public function preferences(Request $request, string $workspace_id): JsonResponse {
    return $this->handle($request, $workspace_id, FALSE);
  }

  /**
   * Replaces or clears the selected workspace's shared default.
   */
  public function workspace(Request $request, string $workspace_id): JsonResponse {
    return $this->handle($request, $workspace_id, TRUE);
  }

  /**
   * Applies shared workspace validation and the appropriate write policy.
   */
  private function handle(Request $request, string $workspace_id, bool $shared): JsonResponse {
    $workspace = ctype_digit($workspace_id) && (int) $workspace_id > 0
      ? $this->entityTypeManager()->getStorage('group')->load($workspace_id) : NULL;
    if (!$workspace instanceof GroupInterface
      || !$this->access->allows($this->currentUser(), $workspace, $shared)) {
      return $this->json(['error' => 'Workspace access denied.'], 403);
    }
    $key = 'case_layout.' . $workspace->id();
    $uid = (int) $this->currentUser()->id();
    $shared_demo = $this->sharedAccountReadOnly->isReadOnly($this->currentUser());
    if ($shared_demo && !$request->isMethodSafe()) {
      return $this->json(['error' => 'Shared demo accounts keep no layout preferences.'], 403);
    }
    if (!$request->isMethodSafe()) {
      $content = $request->getContent();
      $payload = strlen($content) <= 2048 ? json_decode($content, TRUE) : NULL;
      if (!is_array($payload) || array_keys($payload) !== ['layout']
        || ($payload['layout'] !== NULL && !CaseLayout::valid($payload['layout']))) {
        return $this->json(['error' => 'Invalid case layout.'], 400);
      }
      if ($shared) {
        try {
          $this->store->set($workspace, $payload['layout']);
        }
        catch (\InvalidArgumentException | \RuntimeException | EntityStorageException $exception) {
          return $this->json(['error' => 'Workspace layout could not be saved.'], 409);
        }
      }
      elseif ($payload['layout'] === NULL) {
        $this->userData->delete('beantragt_group', $uid, $key);
      }
      else {
        $this->userData->set('beantragt_group', $uid, $key, $payload['layout']);
      }
    }
    $personal = $shared_demo ? NULL : $this->userData->get('beantragt_group', $uid, $key);
    try {
      $default = $this->store->get($workspace);
    }
    catch (\InvalidArgumentException $exception) {
      $default = NULL;
    }
    return $this->json([
      'layout' => CaseLayout::valid($personal) ? $personal : NULL,
      'writable' => !$shared_demo,
      'workspaceDefault' => $default,
      'workspaceDefaultWritable' => !$shared_demo && $this->access->allows($this->currentUser(), $workspace, TRUE),
    ]);
  }

  /**
   * Keeps personal preferences out of shared and browser caches.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
