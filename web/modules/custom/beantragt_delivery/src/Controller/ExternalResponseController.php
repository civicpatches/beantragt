<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\ProtectedResponseAccessException;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Application-scoped external response API using the actual session account.
 */
final class ExternalResponseController extends ControllerBase {

  public function __construct(
    private readonly ExternalResponseService $responses,
    private readonly AccountInterface $account,
    private readonly DeliveryController $deliveries,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_delivery.external_response_service'),
      $container->get('current_user'),
      $container->get('class_resolver')->getInstanceFromDefinition(DeliveryController::class),
    );
  }

  /**
   * View is restricted to the owner or staff with scoped application access.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    $allowed = $account->isAuthenticated()
      && !BeantragtRoles::isServiceAccount($account)
      && $application->access('view', $account);
    return AccessResult::allowedIf($allowed)->cachePerUser()->cachePerPermissions()->addCacheableDependency($application)->setCacheMaxAge(0);
  }

  /**
   * Requires scoped delivery administration and application update access.
   */
  public function releaseAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->access($application, $account)
      ->andIf($this->deliveries->access($application, $account))
      ->andIf($application->access('update', $account, TRUE));
  }

  /**
   * Lists responses using the current session account.
   */
  public function listResponses(ApplicationInterface $application): JsonResponse {
    $this->assertView($application);
    $can_release = $this->releaseAccess($application, $this->account)->isAllowed();
    $meta = [
      'canRelease' => $can_release,
      // Documents with containsProtected need this permission (staff).
      'canReadProtected' => $this->account->hasPermission(ExternalResponseService::PROTECTED_PERMISSION),
    ];
    if ($can_release) {
      // Where a release goes: the confirmation text names the post.
      $meta['delivery'] = $this->responses->deliveryPreview($application);
    }
    return $this->json([
      'data' => $this->responses->listForApplication($application, $this->isStaff($application, $this->account), $this->account),
      'meta' => $meta,
    ]);
  }

  /**
   * Makes a response available without changing its original release time.
   */
  public function release(ApplicationInterface $application, string $response_id): JsonResponse {
    $this->assertView($application);
    if (!$this->releaseAccess($application, $this->account)->isAllowed()) {
      throw new AccessDeniedHttpException();
    }
    try {
      return $this->json(['data' => $this->responses->release($application, $response_id)]);
    }
    catch (\OutOfBoundsException) {
      throw new NotFoundHttpException();
    }
    catch (\DomainException) {
      // A filing receipt stays with staff.
      return new JsonResponse(
        ['error' => ['code' => 'response.not_releasable', 'details' => []]],
        409,
        ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
      );
    }
  }

  /**
   * Serves a verified private document as a protected attachment.
   */
  public function download(ApplicationInterface $application, string $response_id, string $file_uuid): Response {
    $this->assertView($application);
    try {
      $file = $this->responses->download($application, $response_id, $file_uuid, $this->isStaff($application, $this->account), $this->account);
    }
    catch (\OutOfBoundsException) {
      throw new NotFoundHttpException();
    }
    catch (ProtectedResponseAccessException) {
      // A notice with protected data in clear text: staff need the
      // protected-data permission (the applicant's own access is unchanged).
      throw new AccessDeniedHttpException();
    }
    $response = new BinaryFileResponse($file->getFileUri());
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFilename(), 'bescheid.pdf');
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
    return $response;
  }

  /**
   * Rejects accounts without application-scoped response access.
   */
  private function assertView(ApplicationInterface $application): void {
    if (!$this->access($application, $this->account)->isAllowed()) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * Checks tenant-specific delivery administration for pending responses.
   */
  private function isStaff(ApplicationInterface $application, AccountInterface $account): bool {
    // Delivery access checks tenant membership independently of applicant
    // ownership, so a staff member applying elsewhere cannot see pending files.
    return $application->access('view', $account)
      && $this->deliveries->access($application, $account)->isAllowed();
  }

  /**
   * Returns an uncacheable response containing private application data.
   */
  private function json(array $data): JsonResponse {
    return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
  }

}
