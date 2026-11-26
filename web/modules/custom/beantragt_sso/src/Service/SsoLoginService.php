<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Service;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\ValidationError;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Coordinates SSO login starts, ACS processing, and mock login.
 */
final class SsoLoginService {

  /**
   * Constructs the SSO login service.
   */
  public function __construct(
    private readonly SsoProviderManager $providerManager,
    private readonly SsoClientFactory $clientFactory,
    private readonly SsoReplayCache $replayCache,
    private readonly SsoIdentityLinker $identityLinker,
    private readonly LoggerInterface $logger,
    private readonly SsoRequestCorrelation $requestCorrelation,
  ) {
  }

  /**
   * Builds an SP-initiated login redirect and its correlation cookie.
   *
   * @return array{url: string, cookie: \Symfony\Component\HttpFoundation\Cookie|null}
   *   The login URL and, for SAML, its browser correlation cookie.
   */
  public function startLogin(string $provider_id, string $relay_state, Request $request): array {
    $provider = $this->providerManager->enabledProvider($provider_id);
    if (!$this->providerManager->hasMatchingAcsUrl($provider_id, $provider, $request->getHost())) {
      throw new SsoAuthenticationException('acs_url_misconfigured', 'The configured ACS URL does not match the login host and route.');
    }
    $this->providerManager->assertMockAllowed($provider);
    if ($this->providerManager->isMockProvider($provider)) {
      return ['url' => $this->mockLoginUrl($provider_id, $relay_state), 'cookie' => NULL];
    }

    $auth = $this->clientFactory->auth($provider_id);
    $url = $auth->login($relay_state, [], FALSE, FALSE, TRUE);
    $request_id = (string) $auth->getLastRequestID();
    if ($request_id === '') {
      throw new \RuntimeException('SSO login did not generate a request ID.');
    }
    $cookie = $this->requestCorrelation->create($provider_id, $request_id, $relay_state);
    if ($this->allowsSessionFallback($request)) {
      $request->getSession()->set($this->requestIdKey($provider_id), ['cookie_key' => $cookie->getValue()]);
    }
    return ['url' => (string) $url, 'cookie' => $cookie];
  }

  /**
   * Consumes the browser correlation or the development HTTP fallback.
   *
   * @return array{request_id: string, relay_state: string}|null
   *   The request ID and its original return target, if available.
   */
  public function consumeRequestCorrelation(string $provider_id, Request $request, SessionInterface $session): ?array {
    $fallback = $this->allowsSessionFallback($request) ? $session->remove($this->requestIdKey($provider_id)) : NULL;
    $cookie_name = SsoRequestCorrelation::cookieName($provider_id);
    if ($request->cookies->has($cookie_name)) {
      return $this->requestCorrelation->consume($provider_id, $request->cookies->all()[$cookie_name]);
    }
    if (is_array($fallback)) {
      return $this->requestCorrelation->consume($provider_id, $fallback['cookie_key'] ?? NULL);
    }
    // Earlier login starts stored the request ID and RelayState separately.
    if (is_string($fallback) && $fallback !== '') {
      $key = $this->relayStateKey($provider_id);
      $relay_state = $session->remove($key);
      return ['request_id' => $fallback, 'relay_state' => is_string($relay_state) ? $relay_state : '/'];
    }
    return NULL;
  }

  /**
   * Checks whether the request can fall back to dev HTTP session storage.
   */
  private function allowsSessionFallback(Request $request): bool {
    return !$request->isSecure() && $this->providerManager->isDevContext();
  }

  /**
   * Processes a POSTed SSO response and logs in the mapped identity.
   *
   * @return array<string, mixed>
   *   Authenticated user payload.
   */
  public function processAcs(string $provider_id, Request $request, SessionInterface $session, ?string $request_id): array {
    $provider = $this->providerManager->enabledProvider($provider_id);
    if ($this->providerManager->isMockProvider($provider)) {
      throw new BadRequestHttpException('Mock providers do not accept SAMLResponse payloads.');
    }

    if ($request_id === NULL || $request_id === '') {
      throw new AccessDeniedHttpException('Missing SSO request correlation.');
    }

    $sso_response = $request->request->get('SAMLResponse');
    if (!is_string($sso_response) || trim($sso_response) === '') {
      throw new BadRequestHttpException('Missing SAMLResponse.');
    }

    $auth = $this->clientFactory->auth($provider_id);
    $this->processResponse($auth, $request, $request_id);

    if (!$auth->isAuthenticated()) {
      throw new AccessDeniedHttpException('SSO response is not authenticated.');
    }

    // Replay protection needs both identifiers. An empty message or assertion
    // ID means the response cannot be tracked, so reject it explicitly here.
    // Without this guard the replay cache would still fail closed, but with
    // the misleading "already processed" reason.
    $message_id = (string) $auth->getLastMessageId();
    $assertion_id = (string) $auth->getLastAssertionId();
    if ($message_id === '' || $assertion_id === '') {
      throw new AccessDeniedHttpException('SSO response is missing required identifiers.');
    }

    if (
          !$this->replayCache->checkAndStore(
              $provider_id,
              $message_id,
              $assertion_id,
              $auth->getLastAssertionNotOnOrAfter(),
          )
      ) {
      throw new AccessDeniedHttpException('SSO response was already processed.');
    }

    $user = $this->identityLinker->authenticate(
          $provider_id,
          $provider,
          (string) $auth->getNameId(),
          $auth->getAttributes(),
          $auth->getAttributesWithFriendlyName(),
      );

    $this->storeLastLogin($session, $provider_id, $user);
    return $user;
  }

  /**
   * Performs a mock login with real Drupal session finalizing.
   *
   * @return array<string, mixed>
   *   Authenticated user payload.
   */
  public function mockLogin(string $provider_id, SessionInterface $session): array {
    $provider = $this->providerManager->enabledProvider($provider_id);
    $this->providerManager->assertMockAllowed($provider);
    if (!$this->providerManager->isMockProvider($provider)) {
      throw new BadRequestHttpException('Provider is not configured as a mock provider.');
    }

    $claims = is_array($provider['mock_claims'] ?? NULL) ? $provider['mock_claims'] : [];
    $name_id = is_scalar($claims['subject'] ?? NULL)
        ? (string) $claims['subject']
        : 'bundid-demo-subject';
    unset($claims['subject']);

    $attributes = [];
    foreach ($claims as $key => $value) {
      if (!is_scalar($value) || trim((string) $value) === '') {
        continue;
      }
      $attributes[(string) $key] = [(string) $value];
    }

    $user = $this->identityLinker->authenticate($provider_id, $provider, $name_id, $attributes, $attributes);
    $this->storeLastLogin($session, $provider_id, $user);
    $this->logger->notice('SSO mock login executed for @provider.', ['@provider' => $provider_id]);
    return $user;
  }

  /**
   * Processes the response through php-saml without trusting raw globals.
   */
  private function processResponse(Auth $auth, Request $request, string $request_id): void {
    // OneLogin php-saml reads the POST binding from $_POST. Keep the global
    // shim isolated and restore it immediately after processing.
      // phpcs:disable DrupalPractice.Variables.GetRequestData.SuperglobalAccessed,DrupalPractice.Variables.GetRequestData.SuperglobalAccessedWithVar
    $previous_post = $_POST;
    $_POST = [
      'SAMLResponse' => (string) $request->request->get('SAMLResponse'),
    ];
    $relay_state = $request->request->get('RelayState');
    if (is_string($relay_state) && $relay_state !== '') {
      $_POST['RelayState'] = $relay_state;
    }

    try {
      $auth->processResponse($request_id);
    }
    finally {
      $_POST = $previous_post;
    }
      // phpcs:enable DrupalPractice.Variables.GetRequestData.SuperglobalAccessed,DrupalPractice.Variables.GetRequestData.SuperglobalAccessedWithVar

    if ($auth->getErrors() !== []) {
      $error = $auth->getLastErrorException();
      if ($error instanceof ValidationError && in_array($error->getCode(), [
        ValidationError::INVALID_SIGNATURE,
        ValidationError::NO_SIGNED_MESSAGE,
        ValidationError::NO_SIGNED_ASSERTION,
        ValidationError::NO_SIGNATURE_FOUND,
      ], TRUE)) {
        throw new SsoAuthenticationException('bad_signature', 'Invalid SSO signature.');
      }
      throw new AccessDeniedHttpException('Invalid SSO response.');
    }
  }

  /**
   * Builds the local URL for mock login completion.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param string $relay_state
   *   Sanitized RelayState target.
   */
  private function mockLoginUrl(string $provider_id, string $relay_state): string {
    return '/auth/sso/' . rawurlencode($provider_id) . '/mock-idp?RelayState=' . rawurlencode($relay_state);
  }

  /**
   * Stores lightweight last-login metadata in the session.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   Request session.
   * @param string $provider_id
   *   Provider machine name.
   * @param array<string, mixed> $user
   *   Authenticated user payload.
   */
  private function storeLastLogin(SessionInterface $session, string $provider_id, array $user): void {
    $session->set('beantragt_sso.last_login', [
      'provider' => $provider_id,
      'uid' => $user['uid'],
      'assurance_level' => $user['assurance_level'] ?? NULL,
      'time' => time(),
    ]);
  }

  /**
   * Builds the session key for AuthnRequest IDs.
   */
  private function requestIdKey(string $provider_id): string {
    return "beantragt_sso.$provider_id.request_id";
  }

  /**
   * Builds the session key for sanitized RelayState targets.
   */
  private function relayStateKey(string $provider_id): string {
    return "beantragt_sso.$provider_id.relay_state";
  }

}
