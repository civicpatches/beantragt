<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Controller;

use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\beantragt_sso\Service\SsoClientFactory;
use Drupal\beantragt_sso\Service\SsoFailureLog;
use Drupal\beantragt_sso\Service\SsoLoginService;
use Drupal\beantragt_sso\Service\SsoProviderManager;
use Drupal\beantragt_sso\Service\SsoRelayStateValidator;
use Drupal\beantragt_sso\Service\SsoRequestCorrelation;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles generic SSO service provider endpoints.
 */
final class SsoAuthController extends ControllerBase {

  /**
   * Constructs a SSO auth controller.
   */
  public function __construct(
    private readonly SsoProviderManager $providerManager,
    private readonly SsoClientFactory $clientFactory,
    private readonly SsoLoginService $loginService,
    private readonly SsoRelayStateValidator $relayState,
    private readonly LoggerInterface $logger,
    private readonly FloodInterface $flood,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
          $container->get('beantragt_sso.provider_manager'),
          $container->get('beantragt_sso.client_factory'),
          $container->get('beantragt_sso.login_service'),
          $container->get('beantragt_sso.relay_state'),
          $container->get('logger.channel.beantragt_sso'),
          $container->get('flood'),
          $container->get('config.factory'),
      );
  }

  /**
   * Returns SP metadata XML for a provider.
   */
  public function metadata(string $provider): Response {
    try {
      $this->providerManager->provider($provider);
      $settings = $this->clientFactory->settings($provider, TRUE);
      $metadata = $settings->getSPMetadata();
      $errors = $settings->validateMetadata($metadata);
      if ($errors !== []) {
        throw new \RuntimeException(implode(', ', $errors));
      }
    }
    catch (\Throwable $exception) {
      SsoFailureLog::log($this->logger, 'error', 'metadata_unavailable', $provider);
      $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
      return $this->errorJson('metadata_unavailable', $status);
    }

    return $this->noStore(new Response($metadata, 200, [
      'Content-Type' => 'application/samlmetadata+xml; charset=UTF-8',
    ]));
  }

  /**
   * Starts SP-initiated SSO login.
   */
  public function login(Request $request, string $provider): Response {
    $query = $request->query->all();
    $target = ($query['returnTo'] ?? NULL) ?: ($query['RelayState'] ?? NULL);
    try {
      $this->providerManager->enabledProvider($provider);
      $settings = $this->config('beantragt_sso.settings');
      $limit = (int) ($settings->get('login_flood_limit') ?? 30);
      $window = (int) ($settings->get('login_flood_window') ?? 600);
      $ip = $request->getClientIp() ?? 'unknown';
      $event = 'beantragt_sso.login';
      if (!$this->flood->isAllowed($event, $limit, $window, $ip)) {
        return $this->errorRedirect($request, $provider, $target, 'rate_limited');
      }
      $this->flood->register($event, $window, $ip);

      $relay_state = $this->relayState->sanitize($provider, $target);
      $login = $this->loginService->startLogin($provider, $relay_state, $request);
    }
    catch (NotFoundHttpException | AccessDeniedHttpException) {
      return $this->errorRedirect($request, $provider, $target, 'provider_unavailable');
    }
    catch (\Throwable $exception) {
      $code = SsoFailureLog::code($exception, 'login_start_failed');
      SsoFailureLog::log($this->logger, 'error', $code, $provider);
      return $this->errorRedirect($request, $provider, $target, $code);
    }

    // The login service uses the configured IdP URL or a local mock URL.
    $request->query->remove('destination');
    $response = new TrustedRedirectResponse($login['url']);
    if ($login['cookie'] !== NULL) {
      $response->headers->setCookie($login['cookie']);
    }
    $response->setContent('');
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    $this->noStore($response);
    return $response;
  }

  /**
   * Handles SSO ACS POST responses.
   */
  public function acs(Request $request, string $provider): RedirectResponse {
    $target = NULL;
    try {
      $session = $this->session($request);
      $request_correlation = $this->loginService->consumeRequestCorrelation($provider, $request, $session);
      $target = $request_correlation['relay_state'] ?? NULL;
      $this->loginService->processAcs($provider, $request, $session, $request_correlation['request_id'] ?? NULL);
      $response = $this->relayRedirect($request, $this->relayState->sanitize($provider, $target));
    }
    catch (\Throwable $exception) {
      $code = SsoFailureLog::code($exception, 'acs_rejected');
      SsoFailureLog::log($this->logger, 'warning', $code, $provider);
      $response = $this->errorRedirect($request, $provider, $target, $code);
    }

    $response->headers->setCookie(SsoRequestCorrelation::clearedCookie($provider));
    return $response;
  }

  /**
   * Executes a mock login and returns to RelayState.
   */
  public function mockLogin(Request $request, string $provider): RedirectResponse {
    $target = $request->query->all()['RelayState'] ?? NULL;
    try {
      $provider_config = $this->providerManager->enabledProvider($provider);
      if (!$this->providerManager->isMockProvider($provider_config)) {
        return $this->errorRedirect($request, $provider, $target, 'mock_not_allowed');
      }
      $this->providerManager->assertMockAllowed($provider_config);

      $session = $this->session($request);
      $relay_state = $this->relayState->sanitize($provider, $target);
      $this->consumeMockState($request, $provider, $session);
      $this->loginService->mockLogin($provider, $session);
    }
    catch (NotFoundHttpException | AccessDeniedHttpException) {
      return $this->errorRedirect($request, $provider, $target, 'provider_unavailable');
    }
    catch (\Throwable $exception) {
      $code = SsoFailureLog::code($exception, 'mock_login_failed');
      SsoFailureLog::log($this->logger, 'warning', $code, $provider);
      return $this->errorRedirect($request, $provider, $target, $code);
    }
    return $this->relayRedirect($request, $relay_state);
  }

  /**
   * Redirects to a target already validated by the RelayState validator.
   */
  private function relayRedirect(Request $request, string $target): RedirectResponse {
    // Drupal's redirect subscriber can otherwise override validated RelayState.
    $request->query->remove('destination');
    if (preg_match('~^https?://~i', $target) === 1
        && !UrlHelper::externalIsLocal($target, $request->getSchemeAndHttpHost() . $request->getBasePath() . '/')) {
      $response = new TrustedRedirectResponse($target);
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
    else {
      $response = new RedirectResponse($target);
    }
    $response->setContent('');
    $this->noStore($response);
    return $response;
  }

  /**
   * Shows the mock identity provider handoff.
   */
  public function mockIdp(Request $request, string $provider): Response {
    try {
      $provider_config = $this->providerManager->enabledProvider($provider);
      if (!$this->providerManager->isMockProvider($provider_config)) {
        return $this->errorJson('mock_not_allowed', 400);
      }
      $this->providerManager->assertMockAllowed($provider_config);
      $session = $this->session($request);
      $relay_state = $this->relayState->sanitize($provider, $request->query->all()['RelayState'] ?? NULL);
      return $this->renderMockIdp($provider, $provider_config, $session, $relay_state);
    }
    catch (SsoAuthenticationException) {
      return $this->errorJson('mock_not_allowed', 403);
    }
    catch (\Throwable $exception) {
      $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
      return $this->errorJson('provider_unavailable', $status);
    }
  }

  /**
   * Renders the development-only mock handoff.
   *
   * @param string $provider
   *   Provider machine name.
   * @param array<string, mixed> $provider_config
   *   Provider configuration.
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   Browser session.
   * @param string $relay_state
   *   Validated return target.
   */
  private function renderMockIdp(string $provider, array $provider_config, SessionInterface $session, string $relay_state): Response {
    $claims = is_array($provider_config['mock_claims'] ?? NULL)
        ? $provider_config['mock_claims']
        : [];
    $label = is_scalar($provider_config['label'] ?? NULL)
        ? (string) $provider_config['label']
        : 'BundID Demo';
    $profile = is_scalar($provider_config['profile'] ?? NULL)
        ? (string) $provider_config['profile']
        : '';
    $notice = $provider === 'bundid_mock' || $profile === 'bundid_mock'
        ? 'Nachstellung für die Demo, keine echte BundID-Anmeldung.'
        : 'Nachstellung für die Demo, keine echte Anmeldung beim Identitätsanbieter.';
    $button_label = 'Mit ' . $label . ' fortfahren';

    $rows = $this->mockClaimRows($claims);
    $attributes = '';
    foreach ($rows as $key => $value) {
      $attributes .= '<dt>' . Html::escape($key) . '</dt><dd>' . Html::escape($value) . '</dd>';
    }

    $login_url = '/auth/sso/' . rawurlencode($provider) . '/mock-login';
    $mock_state = bin2hex(random_bytes(16));
    $session->set($this->mockStateKey($provider), $mock_state);
    $cancel_url = $relay_state !== '' ? $relay_state : '/';
    $css = implode('', [
      ':root{color-scheme:light dark;',
      'font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}',
      'body{margin:0;background:#eef2f7;color:#0f172a;}',
      'main{min-height:100vh;display:grid;place-items:center;padding:32px;}',
      '.card{width:min(680px,100%);background:#fff;border:1px solid #d7dee8;border-radius:8px;',
      'box-shadow:0 18px 60px rgba(15,23,42,.14);overflow:hidden;}',
      '.top{background:#111827;color:#fff;padding:22px 28px;}',
      '.body{padding:26px 28px 28px;}',
      '.notice{margin:0 0 18px;padding:12px 14px;border:1px solid #facc15;',
      'background:#fef9c3;color:#713f12;border-radius:6px;}',
      'dl{display:grid;grid-template-columns:minmax(120px,180px) 1fr;',
      'gap:10px 18px;margin:18px 0 24px;}',
      'dt{font-weight:700;color:#334155;}',
      'dd{margin:0;min-width:0;color:#0f172a;overflow-wrap:anywhere;}',
      '.actions{display:flex;gap:12px;flex-wrap:wrap;}',
      'button,a{appearance:none;border-radius:6px;padding:10px 16px;',
      'font-weight:700;text-decoration:none;cursor:pointer;}',
      'button{border:1px solid #1d4ed8;background:#1d4ed8;color:#fff;}',
      'a{border:1px solid #cbd5e1;color:#0f172a;background:#fff;}',
      '@media (max-width:520px){main{padding:16px}.top{padding:18px 20px}',
      '.body{padding:20px}dl{grid-template-columns:1fr;gap:4px;margin:16px 0 22px}',
      'dt{margin-top:10px}.actions{flex-direction:column}',
      'button,a{box-sizing:border-box;width:100%;text-align:center}}',
      '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e5e7eb}',
      '.card{background:#111827;border-color:#334155}.body{color:#e5e7eb}',
      '.notice{background:#422006;border-color:#854d0e;color:#fde68a}',
      'dt{color:#cbd5e1}dd{color:#f8fafc}',
      'button{background:#60a5fa;border-color:#93c5fd;color:#0f172a}',
      'a{background:#111827;color:#f8fafc;border-color:#475569}}',
    ]);
    $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . Html::escape($label) . ' - Demo</title>'
        . '<style>' . $css . '</style></head><body><main><section class="card" aria-labelledby="title">'
        . '<div class="top"><h1 id="title">' . Html::escape($label) . '</h1>'
        . '</div>'
        . '<div class="body"><p class="notice">' . Html::escape($notice) . '</p>'
        . '<p>Diese Daten der Demo-Identität werden an beantragt.ai übergeben:</p>'
        . '<dl>' . $attributes . '</dl>'
        . '<form class="actions" method="get" action="' . Html::escape($login_url) . '">'
        . '<input type="hidden" name="RelayState" value="' . Html::escape($relay_state) . '">'
        . '<input type="hidden" name="mockState" value="' . Html::escape($mock_state) . '">'
        . '<button type="submit">' . Html::escape($button_label) . '</button>'
        . '<a href="' . Html::escape($cancel_url) . '">Abbrechen</a>'
        . '</form></div></section></main></body></html>';

    return $this->noStore(new Response($html, 200, [
      'Content-Type' => 'text/html; charset=UTF-8',
    ]));
  }

  /**
   * Formats configured mock claims for the demo identity provider page.
   *
   * @param array<string, mixed> $claims
   *   Mock claims from provider config.
   *
   * @return array<string, string>
   *   Display labels and values.
   */
  private function mockClaimRows(array $claims): array {
    $labels = [
      'subject' => 'Stabile SSO-ID',
      'email' => 'E-Mail',
      'first_name' => 'Vorname',
      'last_name' => 'Nachname',
      'full_name' => 'Name',
      'birthdate' => 'Geburtsdatum',
      'assurance_level' => 'Vertrauensniveau',
    ];

    $rows = [];
    foreach ($labels as $key => $label) {
      $value = $claims[$key] ?? NULL;
      if (is_scalar($value) && trim((string) $value) !== '') {
        $rows[$label] = (string) $value;
      }
    }

    return $rows ?: ['Demo-Identität' => 'Max Mustermann'];
  }

  /**
   * Requires a mock IdP handoff state before completing mock login.
   */
  private function consumeMockState(Request $request, string $provider, SessionInterface $session): void {
    $expected = $session->get($this->mockStateKey($provider));
    $provided = $request->query->all()['mockState'] ?? NULL;
    $session->remove($this->mockStateKey($provider));

    if (!is_string($expected) || !is_string($provided) || !hash_equals($expected, $provided)) {
      throw new HttpException(400, 'login_failed');
    }
  }

  /**
   * Builds the session key for mock IdP handoff state.
   */
  private function mockStateKey(string $provider): string {
    return 'beantragt_sso.' . $provider . '.mock_state';
  }

  /**
   * Redirects with only the public failure category.
   */
  private function errorRedirect(Request $request, string $provider, mixed $return_target, string $code): RedirectResponse {
    $target = $this->relayState->sanitize($provider, $return_target, '/auth/login');
    $parts = UrlHelper::parse($target);
    $query = is_array($parts['query'] ?? NULL) ? $parts['query'] : [];
    unset($query['sso_code'], $query['sso_correlation']);
    $query['sso_error'] = SsoFailureLog::publicCode($code);

    $url = $parts['path'] . '?' . UrlHelper::buildQuery($query);
    if (!empty($parts['fragment'])) {
      $url .= '#' . $parts['fragment'];
    }

    return $this->relayRedirect($request, $url);
  }

  /**
   * Returns a public failure category with its HTTP status.
   */
  private function errorJson(string $code, int $status): Response {
    return $this->noStore(new JsonResponse(['error' => ['code' => SsoFailureLog::publicCode($code)]], $status));
  }

  /**
   * Returns the request session or fails with a server error.
   */
  private function session(Request $request): SessionInterface {
    if (!$request->hasSession()) {
      throw new HttpException(500, 'unavailable');
    }
    return $request->getSession();
  }

  /**
   * Adds no-store headers to auth responses.
   */
  private function noStore(Response $response): Response {
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
