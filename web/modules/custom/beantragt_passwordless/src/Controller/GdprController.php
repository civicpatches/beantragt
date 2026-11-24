<?php

declare(strict_types=1);

namespace Drupal\beantragt_passwordless\Controller;

use Drupal\beantragt_passwordless\Service\CitizenErasureService;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the "Meine Daten" erasure endpoints for authenticated citizens.
 *
 * A citizen deletes the account, profiles, consents, login links and
 * unconfirmed submissions. Submitted applications, decisions, messages and
 * attachments stay with the authority until the retention rules remove them;
 * a wider erasure is a request to the authority under Art. 17 GDPR.
 */
class GdprController extends ControllerBase {

  /**
   * Constructs a GdprController object.
   */
  public function __construct(
    AccountInterface $current_user,
    protected CsrfTokenGenerator $csrfToken,
    protected SessionConfigurationInterface $sessionConfiguration,
    protected CitizenErasureService $erasure,
    protected SessionManagerInterface $sessionManager,
    protected UserSessionRepositoryInterface $sessionRepository,
  ) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container->get('session_configuration'),
      $container->get('beantragt_passwordless.citizen_erasure'),
      $container->get('session_manager'),
      $container->get(UserSessionRepositoryInterface::class),
    );
  }

  /**
   * Shows what an erasure deletes and which applications stay.
   *
   * GET /auth/gdpr/preview.
   */
  public function preview(): JsonResponse {
    if (!$this->isCitizenAccount()) {
      return $this->notCitizenResponse();
    }
    $response = new JsonResponse($this->erasure->preview($this->currentUser));
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

  /**
   * Deletes the account data of the authenticated citizen.
   *
   * DELETE /auth/gdpr/all.
   *
   * Deletes the account, profiles with consents and login confirmations,
   * SSO identity links and unconfirmed submissions, keeps confirmed
   * applications unchanged with the authority, then ends the session.
   */
  public function deleteAll(Request $request): JsonResponse {
    if (!$this->validateCsrfToken($request)) {
      return new JsonResponse([
        'error' => 'CSRF token validation failed',
      ], Response::HTTP_FORBIDDEN);
    }

    if (!$this->isCitizenAccount()) {
      return $this->notCitizenResponse();
    }

    $session_options = $this->sessionConfiguration->getOptions($request);
    $session_name = $session_options['name'] ?? session_name();

    $uid = (int) $this->currentUser->id();
    $summary = $this->erasure->erase($this->currentUser);
    $this->endSessions($uid);

    $response = new JsonResponse([
      'success' => TRUE,
      'erasedAt' => date(\DateTimeInterface::ATOM, (int) $request->server->get('REQUEST_TIME', time())),
    ] + $summary);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    $response->headers->setCookie($this->expireSessionCookie($session_name, $session_options));
    return $response;
  }

  /**
   * Ends every session of the erased account, on all devices.
   *
   * Core keeps session rows of a deleted account until session garbage
   * collection, and user_logout() would write the account name (for
   * passwordless accounts the email address) to the log. This ends the
   * sessions without that log entry.
   */
  protected function endSessions(int $uid): void {
    if ($uid <= 0) {
      return;
    }
    $this->moduleHandler()->invokeAll('user_logout', [$this->currentUser]);
    $this->sessionRepository->deleteAll($uid);
    $this->sessionManager->destroy();
    if ($this->currentUser instanceof AccountProxyInterface) {
      $this->currentUser->setAccount(new AnonymousUserSession());
    }
  }

  /**
   * Returns TRUE for a citizen account that may erase itself.
   *
   * Staff, administrators and the service account are managed by the
   * operator: erasing them here would reassign or remove content of other
   * people (the service account owns every public submission).
   */
  protected function isCitizenAccount(): bool {
    return $this->currentUser->isAuthenticated()
      && (int) $this->currentUser->id() !== 1
      && !BeantragtRoles::isServiceAccount($this->currentUser)
      && !BeantragtRoles::isStaffAccount($this->currentUser);
  }

  /**
   * Refuses the erasure endpoints for non-citizen accounts.
   */
  protected function notCitizenResponse(): JsonResponse {
    return new JsonResponse([
      'error' => 'Only citizen accounts can be erased here.',
      'code' => 'gdpr.not_citizen_account',
    ], Response::HTTP_FORBIDDEN);
  }

  /**
   * Builds an expired session cookie for the active Drupal session.
   */
  protected function expireSessionCookie(string $session_name, array $session_options): Cookie {
    return new Cookie(
      $session_name,
      '',
      1,
      '/',
      $session_options['cookie_domain'] ?? '',
      TRUE,
      TRUE,
      FALSE,
      Cookie::SAMESITE_LAX
    );
  }

  /**
   * Validates the CSRF token from the request header.
   */
  protected function validateCsrfToken(Request $request): bool {
    $csrf_token = $request->headers->get('X-CSRF-Token');
    if (empty($csrf_token)) {
      return FALSE;
    }
    return $this->csrfToken->validate($csrf_token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
      || $this->csrfToken->validate($csrf_token, 'rest');
  }

}
