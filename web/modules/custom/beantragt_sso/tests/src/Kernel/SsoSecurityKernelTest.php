<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Kernel;

use Psr\Log\LoggerInterface;
use Drupal\Core\Database\Event\StatementExecutionEndEvent;
use Drupal\Core\Database\Event\StatementExecutionStartEvent;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\beantragt_sso\Service\SsoClientFactory;
use Drupal\beantragt_sso\Service\SsoIdentityLinker;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\beantragt_sso\Service\SsoAccountPolicy;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Session\UserSessionRepositoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Exercises real MariaDB identity binding, rollback, and upgrade invariants.
 */
#[Group('beantragt_sso')]
final class SsoSecurityKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'datetime', 'serialization',
    'beantragt_roles_api', 'beantragt_passwordless', 'beantragt_sso',
  ];

  /**
   * Isolated session used by real user_login_finalize().
   */
  private Session $session;

  /**
   * Provides unused profile dependencies without installing the full product.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('beantragt_applicant.manager', ApplicantManager::class)->setSynthetic(TRUE);
    $container->register('beantragt_application.retention_cleanup')->setSynthetic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('beantragt_passwordless', ['beantragt_verified_account_mail']);
    $this->installSchema('beantragt_sso', ['beantragt_sso_identities']);
    $this->installConfig(['system', 'user', 'beantragt_sso']);
    $this->session = new Session(new MockArraySessionStorage());
    $this->container->set('session', $this->session);
    $this->container->set('session_manager', $this->createMock(SessionManagerInterface::class));
    User::create(['uid' => 1, 'name' => 'root-test', 'status' => 1])->save();
  }

  /**
   * The kernel permits the configured IdP and sanitizes external return URLs.
   */
  public function testExternalIdpLoginRedirect(): void {
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $idp_url = 'https://idp.example.test/saml/sso';
    $local_return = '/meine-antraege';

    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $this->assertNotFalse($key);
    $csr = openssl_csr_new(['commonName' => 'idp.example.test'], $key);
    $this->assertNotFalse($csr);
    $certificate = openssl_csr_sign($csr, NULL, $key, 1);
    $this->assertNotFalse($certificate);
    $this->assertTrue(openssl_x509_export($certificate, $pem));

    $config = $this->config('beantragt_sso.settings');
    $provider = $config->get('providers.bundid');
    $provider['enabled'] = TRUE;
    $provider['profile'] = 'generic';
    $provider['idp_entity_id'] = 'https://idp.example.test/saml/metadata';
    $provider['idp_sso_url'] = $idp_url;
    $provider['idp_x509_cert'] = $pem;
    $provider['default_relay_path'] = $local_return;
    $provider['security']['authn_requests_signed'] = FALSE;
    $config->set('providers.external_idp', $provider)->save();

    foreach (['returnTo', 'RelayState'] as $parameter) {
      $request = Request::create('https://beantragt.example.test/auth/sso/external_idp/login', 'GET', [
        $parameter => 'https://other.example.test/return',
      ]);
      $request->setSession($this->session);
      $response = $this->container->get('http_kernel')->handle($request);

      $this->assertSame(302, $response->getStatusCode(), $parameter);
      $location = $response->headers->get('Location', '');
      $this->assertStringStartsWith($idp_url . '?', $location);
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
      $this->assertInstanceOf(CacheableResponseInterface::class, $response);
      $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());

      parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
      $this->assertNotEmpty($query['SAMLRequest']);
      $this->assertSame($local_return, $query['RelayState'], $parameter);
      $this->assertSame($local_return, $this->container->get('beantragt_sso.login_service')
        ->consumeRelayState('external_idp', $this->session));
    }
  }

  /**
   * A successful ACS returns through Drupal's redirect subscriber.
   */
  public function testAcsRelayRedirect(): void {
    $this->installSchema('beantragt_sso', ['beantragt_sso_replay']);
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $this->config('beantragt_sso.settings')
      ->set('providers.bundid.enabled', TRUE)
      ->set('providers.bundid.profile', 'generic')
      ->set('providers.bundid.allowed_relay_hosts', ['UI.EXAMPLE.TEST:8443'])
      ->set('providers.bundid.default_relay_path', '/fallback')
      ->save();

    // Stub the SAML toolkit boundary. The HTTP kernel, SSO login service,
    // replay cache, identity linker and session finalization are real.
    $auth = $this->createMock(Auth::class);
    $auth->method('login')->willReturn('https://idp.example.test/saml/sso');
    $auth->method('getLastRequestID')->willReturn('request-id');
    $auth->method('getErrors')->willReturn([]);
    $auth->method('isAuthenticated')->willReturn(TRUE);
    $auth->method('getNameId')->willReturn('redirect-test-subject');
    $auth->method('getAttributes')->willReturn([]);
    $auth->method('getAttributesWithFriendlyName')->willReturn([]);
    $auth->method('getLastMessageId')->willReturnCallback(static fn () => bin2hex(random_bytes(16)));
    $auth->method('getLastAssertionId')->willReturnCallback(static fn () => bin2hex(random_bytes(16)));
    $factory = $this->createMock(SsoClientFactory::class);
    $factory->method('auth')->willReturn($auth);
    $this->container->set('beantragt_sso.client_factory', $factory);

    foreach ($this->relayTargets() as $target => $expected) {
      $this->assertSame(302, $this->ssoRequest('bundid/login', 'GET', ['returnTo' => $target])->getStatusCode());
      $this->session->remove('beantragt_sso.last_login');
      $response = $this->ssoRequest('bundid/acs', 'POST', [
        'SAMLResponse' => 'verified-by-toolkit-stub',
        'RelayState' => 'https://ui.example.test:8443/ignored-post-value',
      ]);
      $this->assertSame('bundid', $this->session->get('beantragt_sso.last_login')['provider'] ?? NULL);
      $this->assertRelayRedirect($response, $expected);
    }
  }

  /**
   * A successful dev mock login applies the same RelayState rules.
   */
  public function testMockLoginRelayRedirect(): void {
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $this->config('beantragt_sso.settings')
      ->set('providers.bundid_mock.enabled', TRUE)
      ->set('providers.bundid_mock.allowed_relay_hosts', ['UI.EXAMPLE.TEST:8443'])
      ->set('providers.bundid_mock.default_relay_path', '/fallback')
      ->save();

    $previous_environment = getenv('BEANTRAGT_ENV');
    $previous_mock = getenv('BEANTRAGT_SSO_MOCK');
    putenv('BEANTRAGT_ENV=dev');
    putenv('BEANTRAGT_SSO_MOCK=true');
    try {
      foreach ($this->relayTargets() as $target => $expected) {
        $this->assertSame(200, $this->ssoRequest('bundid_mock/mock-idp', 'GET', ['RelayState' => $target])->getStatusCode());
        $this->session->remove('beantragt_sso.last_login');
        $response = $this->ssoRequest('bundid_mock/mock-login', 'GET', [
          'RelayState' => $target,
          'mockState' => $this->session->get('beantragt_sso.bundid_mock.mock_state'),
        ]);
        $this->assertSame('bundid_mock', $this->session->get('beantragt_sso.last_login')['provider'] ?? NULL);
        $this->assertRelayRedirect($response, $expected);
      }
    }
    finally {
      putenv('BEANTRAGT_ENV' . ($previous_environment === FALSE ? '' : '=' . $previous_environment));
      putenv('BEANTRAGT_SSO_MOCK' . ($previous_mock === FALSE ? '' : '=' . $previous_mock));
    }
  }

  /**
   * A rejected ACS returns error details to an allowed RelayState target.
   */
  public function testAcsFailureRelayRedirect(): void {
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $this->config('beantragt_sso.settings')
      ->set('providers.bundid.enabled', TRUE)
      ->set('providers.bundid.profile', 'generic')
      ->set('providers.bundid.allowed_relay_hosts', ['UI.EXAMPLE.TEST:8443'])
      ->set('providers.bundid.default_relay_path', '/fallback')
      ->save();

    $auth = $this->createMock(Auth::class);
    $auth->method('login')->willReturn('https://idp.example.test/saml/sso');
    $auth->method('getLastRequestID')->willReturn('request-id');
    $factory = $this->createMock(SsoClientFactory::class);
    $factory->method('auth')->willReturn($auth);
    $this->container->set('beantragt_sso.client_factory', $factory);

    foreach ($this->failureRelayTargets() as $target => $expected) {
      $this->assertSame(302, $this->ssoRequest('bundid/login', 'GET', ['returnTo' => $target])->getStatusCode());
      // The service rejects the callback because SAMLResponse is missing.
      $response = $this->ssoRequest('bundid/acs', 'POST', []);
      $this->assertFailureRelayRedirect($response, $expected, 'acs_rejected');
    }
  }

  /**
   * A mock callback with invalid session state follows the same rules.
   */
  public function testMockLoginFailureRelayRedirect(): void {
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $this->config('beantragt_sso.settings')
      ->set('providers.bundid_mock.enabled', TRUE)
      ->set('providers.bundid_mock.allowed_relay_hosts', ['UI.EXAMPLE.TEST:8443'])
      ->set('providers.bundid_mock.default_relay_path', '/fallback')
      ->save();

    $previous_environment = getenv('BEANTRAGT_ENV');
    $previous_mock = getenv('BEANTRAGT_SSO_MOCK');
    putenv('BEANTRAGT_ENV=dev');
    putenv('BEANTRAGT_SSO_MOCK=true');
    try {
      foreach ($this->failureRelayTargets() as $target => $expected) {
        $this->session->set('beantragt_sso.bundid_mock.mock_state', 'expected-state');
        $response = $this->ssoRequest('bundid_mock/mock-login', 'GET', [
          'RelayState' => $target,
          'mockState' => 'invalid-state',
        ]);
        $this->assertFailureRelayRedirect($response, $expected, 'mock_login_failed');
      }
    }
    finally {
      putenv('BEANTRAGT_ENV' . ($previous_environment === FALSE ? '' : '=' . $previous_environment));
      putenv('BEANTRAGT_SSO_MOCK' . ($previous_mock === FALSE ? '' : '=' . $previous_mock));
    }
  }

  /**
   * Production mock-login guards reject anonymous requests before redirects.
   */
  public function testProductionMockLoginDoesNotRedirect(): void {
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $config = $this->config('beantragt_sso.settings')
      ->set('providers.bundid_mock.enabled', TRUE)
      ->set('providers.staff_mock.enabled', FALSE)
      ->set('providers.bundid.enabled', TRUE);
    $expected_statuses = ['bundid_mock' => 403, 'staff_mock' => 403, 'bundid' => 400];
    foreach (array_keys($expected_statuses) as $provider) {
      $config->set("providers.$provider.allowed_relay_hosts", ['ui.example.test:8443']);
    }
    $config->save();

    $previous_environment = getenv('BEANTRAGT_ENV');
    $previous_mock = getenv('BEANTRAGT_SSO_MOCK');
    putenv('BEANTRAGT_ENV=production');
    putenv('BEANTRAGT_SSO_MOCK');
    try {
      $this->assertTrue($this->container->get('current_user')->isAnonymous());
      foreach ($expected_statuses as $provider => $status) {
        $response = $this->ssoRequest("$provider/mock-login", 'GET', [
          'RelayState' => 'https://ui.example.test:8443/return',
        ]);
        $this->assertSame($status, $response->getStatusCode(), $provider);
        $this->assertFalse($response->headers->has('Location'), $provider);
      }
    }
    finally {
      putenv('BEANTRAGT_ENV' . ($previous_environment === FALSE ? '' : '=' . $previous_environment));
      putenv('BEANTRAGT_SSO_MOCK' . ($previous_mock === FALSE ? '' : '=' . $previous_mock));
    }
  }

  /**
   * Returns the allowed external target and a target that falls back locally.
   *
   * @return array<string, string>
   *   Submitted targets keyed to expected locations with a placeholder for the
   *   error parameters.
   */
  private function failureRelayTargets(): array {
    return [
      'https://ui.example.test:8443/return?step=1#done' => 'https://ui.example.test:8443/return?step=1&%s#done',
      'https://other.example.test/return' => '/fallback?%s',
    ];
  }

  /**
   * Verifies the error marker, code, and generated correlation in the redirect.
   */
  private function assertFailureRelayRedirect(Response $response, string $expected, string $code): void {
    $location = $response->headers->get('Location', '');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $correlation = $query['sso_correlation'] ?? '';
    $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $correlation, $location);

    $error_query = 'sso_error=1&sso_code=' . $code . '&sso_correlation=' . $correlation;
    $this->assertRelayRedirect($response, sprintf($expected, $error_query));
  }

  /**
   * Returns input and expected locations shared by both SSO callbacks.
   *
   * @return array<string, string>
   *   Submitted RelayState targets keyed to their expected Location.
   */
  private function relayTargets(): array {
    // Drupal's cookie authentication subscriber appends check_logged_in
    // after user_login_finalize(), independently of the RelayState handling.
    $fallback = '/fallback?check_logged_in=1';
    return [
      'https://ui.example.test:8443/return?step=1#done' => 'https://ui.example.test:8443/return?step=1&check_logged_in=1#done',
      'https://other.example.test/return' => $fallback,
      'https://ui.example.test:444/return' => $fallback,
      'https://name@ui.example.test:8443/return' => $fallback,
      'https://ui.example.test:8443\\/return' => $fallback,
      '//ui.example.test:8443/return' => $fallback,
      '/return?step=1#done' => '/return?step=1&check_logged_in=1#done',
      'https://beantragt.example.test/return' => 'https://beantragt.example.test/return?check_logged_in=1',
    ];
  }

  /**
   * Handles an SSO request in Drupal's HTTP kernel with the test session.
   */
  private function ssoRequest(string $path, string $method, array $parameters): Response {
    $request = Request::create('https://beantragt.example.test/auth/sso/' . $path, $method, $parameters);
    $request->setSession($this->session);
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Verifies that only the explicitly configured external target is trusted.
   */
  private function assertRelayRedirect(Response $response, string $expected): void {
    $this->assertSame(302, $response->getStatusCode(), $expected);
    $this->assertSame($expected, $response->headers->get('Location'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    if (str_starts_with($expected, 'https://ui.example.test:8443/')) {
      $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    }
    else {
      $this->assertInstanceOf(LocalRedirectResponse::class, $response);
    }
    $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
  }

  /**
   * Verified registration and subsequent linked login retain one binding.
   */
  public function testVerifiedRegistrationAndExistingCitizen(): void {
    $response = $this->login('verified-subject', TRUE);
    $user = User::load($response['uid']);
    $this->assertSame('citizen@example.test', $user->getEmail());
    $this->assertSame('citizen@example.test', $this->mail()->getVerifiedEmail($user));
    $this->assertSame($response['uid'], $this->login('verified-subject', FALSE)['uid']);
    $this->assertSame('citizen@example.test', $this->mail()->getVerifiedEmail($user));
    $this->assertSame(1, $this->identityCount());
    $this->assertSame((string) $user->id(), $this->session->get('uid'));
  }

  /**
   * Only a verifying provider can use an email for automatic linking.
   */
  public function testVerifiedEmailLinkAndUnverifiedSyntheticRegistration(): void {
    $citizen = $this->user('citizen', 'citizen@example.test');
    $response = $this->login('verified-link', TRUE, ['link_by_email' => TRUE]);
    $this->assertSame((int) $citizen->id(), $response['uid']);
    $other = $this->login('unverified-subject', FALSE, ['link_by_email' => TRUE]);
    $this->assertNotSame((int) $citizen->id(), $other['uid']);
    $account = User::load($other['uid']);
    $this->assertStringEndsWith('@sso.local.invalid', $account->getEmail());
    $this->assertNull($this->mail()->getVerifiedEmail($account));
    $this->assertSame('citizen@example.test', $this->identity('unverified-subject')['email_claim']);
  }

  /**
   * An unused unverified address is never stored as account mail.
   */
  public function testUnverifiedNewAddressIsSynthetic(): void {
    $response = $this->login('new-unverified-subject', FALSE);
    $user = User::load($response['uid']);
    $this->assertStringEndsWith('@sso.local.invalid', $user->getEmail());
    $this->assertNull($this->mail()->getVerifiedEmail($user));
    $this->assertSame('citizen@example.test', $this->identity('new-unverified-subject')['email_claim']);
  }

  /**
   * Explicit staff links remain allowed, while every service path is denied.
   */
  public function testManualStaffAndServiceAccountResolution(): void {
    Role::create(['id' => 'department_admin', 'label' => 'Staff'])->save();
    Role::create(['id' => 'service_account', 'label' => 'Service'])->save();
    $staff = $this->user('staff', 'staff@example.test', ['department_admin']);
    $this->bind('staff-subject', $staff);
    $this->assertSame((int) $staff->id(), $this->login('staff-subject', FALSE)['uid']);
    $this->container->get('database')->update('beantragt_sso_identities')->fields(['link_method' => 'login'])
      ->condition('subject_hash', $this->hash('staff-subject'))->execute();
    $this->assertDeniedUnchanged('staff-subject', FALSE, [], 'account_privileged');
    $service = $this->user('service', 'service@example.test', ['service_account']);
    $this->bind('service-subject', $service);
    $this->assertDeniedUnchanged('service-subject', FALSE, [], 'account_ineligible');
    $username = 'sso.test.' . substr($this->hash('fallback'), 0, 16);
    $this->user($username, 'fallback@example.test', ['service_account']);
    $this->assertDeniedUnchanged('fallback', FALSE);
  }

  /**
   * Custom administrative permissions and admin roles cannot email-link.
   */
  public function testPrivilegedEmailLinking(): void {
    foreach (['administer users', 'bypass beantragt group scope', 'administer beantragt sso'] as $index => $permission) {
      $role = Role::create(['id' => 'custom_' . $index, 'label' => 'Custom']);
      $role->grantPermission($permission);
      $role->setSyncing(TRUE);
      $role->save();
      $user = $this->user('privileged_' . $index, 'citizen@example.test', [$role->id()]);
      $policy = new SsoAccountPolicy($this->container->get('entity_type.manager'));
      $this->assertTrue($policy->isPrivileged($user), $permission);
      $this->assertDeniedUnchanged('privileged_' . $index, TRUE, ['link_by_email' => TRUE]);
      $user->delete();
    }
    Role::create(['id' => 'custom_admin', 'label' => 'Admin', 'is_admin' => TRUE])->save();
    $this->user('custom_admin', 'citizen@example.test', ['custom_admin']);
    $this->assertDeniedUnchanged('custom-admin', TRUE, ['link_by_email' => TRUE]);
  }

  /**
   * Blocked and dangling bindings deny login; account deletion erases bindings.
   */
  public function testDeadMappingsStopRegistration(): void {
    $user = $this->user('blocked', 'blocked@example.test');
    $this->bind('blocked-subject', $user);
    $user->block()->save();
    $this->assertDeniedUnchanged('blocked-subject', TRUE, [], 'account_ineligible');
    $user->delete();
    $this->assertSame(0, $this->identityCount());
    $fresh = $this->login('blocked-subject', TRUE);
    $this->assertNotSame((int) $user->id(), $fresh['uid']);
    $this->bind('dangling-subject', 99999);
    $this->assertDeniedUnchanged('dangling-subject', TRUE);
  }

  /**
   * An insert failure rolls back user creation before profile/session effects.
   */
  public function testInsertFailureLeavesNoAccountOrSession(): void {
    $database = $this->container->get('database');
    $database->schema()->dropField('beantragt_sso_identities', 'email_claim');
    $this->assertDeniedUnchanged('insert-failure', TRUE);
  }

  /**
   * An existing subject cannot be assigned to another UID by login persistence.
   */
  public function testBindingUidIsImmutable(): void {
    $first = $this->user('first-binding', 'first@example.test');
    $other = $this->user('other-binding', 'other@example.test');
    $this->bind('immutable', $first);
    $linker = new SsoIdentityLinker(
      $this->container->get('database'), $this->container->get('entity_type.manager'),
      $this->createMock(ApplicantManager::class), new NullLogger(), NULL, $this->mail(),
      new DatabaseLockBackend($this->container->get('database')),
    );
    try {
      (new \ReflectionMethod(SsoIdentityLinker::class, 'storeIdentity'))->invoke(
        $linker, 'test', $this->hash('immutable'), (int) $other->id(), NULL, NULL,
      );
    }
    catch (IntegrityConstraintViolationException) {
      // A database constraint, never a merge/upsert, owns this invariant.
    }
    $this->assertSame((string) $first->id(), (string) $this->identity('immutable')['uid']);
  }

  /**
   * Upgrade quarantines unknown legacy origins and is safe to rerun.
   */
  public function testUpgradeDoesNotInferVerification(): void {
    $database = $this->container->get('database');
    $user = $this->user('legacy', 'citizen@example.test');
    $this->bind('legacy-subject', $user);
    foreach (['disabled', 'link_method', 'email_claim'] as $field) {
      $database->schema()->dropField('beantragt_sso_identities', $field);
    }
    $sessions = $this->createMock(UserSessionRepositoryInterface::class);
    $sessions->expects($this->exactly(2))->method('deleteAll')->with((int) $user->id());
    $this->container->set(UserSessionRepositoryInterface::class, $sessions);
    $this->container->get('module_handler')->loadInclude('beantragt_sso', 'install');
    beantragt_sso_update_10001();
    beantragt_sso_update_10001();
    beantragt_sso_update_10002();
    beantragt_sso_update_10002();
    $this->assertSame('1', (string) $this->identity('legacy-subject')['disabled']);
    $this->assertSame('legacy', $this->identity('legacy-subject')['link_method']);
    $this->assertNull($this->mail()->getVerifiedEmail($user));
    $this->assertDeniedUnchanged('legacy-subject', TRUE, [], 'identity_disabled');
    $this->assertDefaultBindingRequiresManualStaffLink('after-upgrade');
  }

  /**
   * The identity linker uses the shared nonpersistent request lock.
   */
  public function testIdentityLinkerUsesRequestLock(): void {
    $this->container->set('beantragt_applicant.manager', $this->createMock(ApplicantManager::class));
    $linker = $this->container->get('beantragt_sso.identity_linker');
    $lock = (new \ReflectionProperty(SsoIdentityLinker::class, 'lock'))->getValue($linker);
    $this->assertSame($this->container->get('lock'), $lock);
  }

  /**
   * Fresh installs never give an omitted provenance administrative trust.
   */
  public function testFreshBindingDefaultsToLogin(): void {
    $this->assertDefaultBindingRequiresManualStaffLink('fresh');
  }

  /**
   * A binding inserted without provenance cannot log into a staff account.
   */
  private function assertDefaultBindingRequiresManualStaffLink(string $subject): void {
    if (Role::load('case_worker') === NULL) {
      Role::create(['id' => 'case_worker', 'label' => 'Staff'])->save();
    }
    $user = $this->user($subject, $subject . '@example.test', ['case_worker']);
    $this->container->get('database')->insert('beantragt_sso_identities')->fields([
      'provider' => 'test',
      'subject_hash' => $this->hash($subject),
      'uid' => $user->id(),
      'created' => time(),
      'changed' => time(),
    ])->execute();
    $this->assertSame('login', $this->identity($subject)['link_method']);
    $this->assertDeniedUnchanged($subject, FALSE);
  }

  /**
   * Sites that already had the old default retain rows but get a safe default.
   */
  public function testEarlierUpgradeDefaultIsCorrected(): void {
    $database = $this->container->get('database');
    $this->container->get('module_handler')->loadInclude('beantragt_sso', 'install');
    $definition = beantragt_sso_schema()['beantragt_sso_identities']['fields']['link_method'];
    $definition['default'] = 'legacy';
    $database->schema()->changeField('beantragt_sso_identities', 'link_method', 'link_method', $definition);
    $user = $this->user('legacy-existing', 'legacy-existing@example.test');
    $this->bind('kept', $user);
    $database->update('beantragt_sso_identities')->fields(['link_method' => 'legacy', 'disabled' => 1])->execute();
    beantragt_sso_update_10002();
    $this->assertSame('legacy', $this->identity('kept')['link_method']);
    $this->assertSame('1', (string) $this->identity('kept')['disabled']);
    $this->assertDefaultBindingRequiresManualStaffLink('corrected');
  }

  /**
   * Any additional role excludes email linking, but is not a staff-login ban.
   */
  public function testAdditionalNonAdminRoleCannotEmailLink(): void {
    Role::create(['id' => 'custom_citizen', 'label' => 'Extended citizen'])->save();
    $user = $this->user('extended', 'citizen@example.test', ['custom_citizen']);
    $this->assertDeniedUnchanged('extended', TRUE, ['link_by_email' => TRUE]);
    $this->bind('extended', $user);
    $this->container->get('database')->update('beantragt_sso_identities')
      ->fields(['link_method' => 'login'])->condition('subject_hash', $this->hash('extended'))->execute();
    $this->assertSame((int) $user->id(), $this->login('extended', FALSE)['uid']);
  }

  /**
   * Typed config rejects an unsafe provider before it can be persisted.
   */
  public function testUnsafeEmailLinkConfigViolatesSchemaConstraint(): void {
    $data = $this->config('beantragt_sso.settings')->getRawData();
    $data['providers']['bundid_mock']['link_by_email'] = TRUE;
    $data['providers']['bundid_mock']['email_verified'] = FALSE;
    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('beantragt_sso.settings', $data)
      ->validate();

    $messages = array_map(
      static fn ($violation): string => (string) $violation->getMessage(),
      iterator_to_array($violations),
    );
    $matches = array_filter(
      $messages,
      static fn (string $message): bool => str_contains($message, 'requires verified email claims'),
    );
    $this->assertCount(1, $matches);
  }

  /**
   * Stale unsafe active config disables linking and emits one bounded code.
   */
  public function testUnsafeRuntimeConfigLogsOnlyBoundedCode(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('log')->with('warning',
      'SSO failure @code (correlation @correlation).',
      $this->callback(static fn(array $context): bool => $context['@code'] === 'unsafe_email_link_config'
        && array_keys($context) === ['@code', '@correlation']
        && preg_match('/^[a-f0-9]{32}$/', $context['@correlation']) === 1));
    $linker = new SsoIdentityLinker($this->container->get('database'),
      $this->container->get('entity_type.manager'), $this->createMock(ApplicantManager::class),
      $logger, NULL, $this->mail(), new DatabaseLockBackend($this->container->get('database')));
    $response = $linker->authenticate('test', [
      'link_by_email' => TRUE,
      'email_verified' => FALSE,
      'attribute_map' => ['email' => ['email']],
    ], 'unsafe-config', ['email' => ['citizen@example.test']], []);
    $this->assertStringEndsWith('@sso.local.invalid', (string) $response['email']);
  }

  /**
   * The runtime warning names the settings that allow development mock login.
   */
  public function testMockRequirementsDescribeDevelopmentOptIn(): void {
    $values = [
      'BEANTRAGT_ENV' => 'ddev',
      'BEANTRAGT_SSO_MOCK' => 'false',
    ];
    $previous = [];
    foreach ($values as $name => $value) {
      $previous[$name] = getenv($name);
      putenv($name . '=' . $value);
    }
    try {
      $this->config('beantragt_sso.settings')->set('providers.bundid_mock.enabled', TRUE)->save();
      require_once DRUPAL_ROOT . '/core/includes/install.inc';
      $this->container->get('module_handler')->loadInclude('beantragt_sso', 'install');
      $warning = beantragt_sso_requirements('runtime')['beantragt_sso_mock_provider'];
      self::assertSame(REQUIREMENT_WARNING, $warning['severity']);
      $description = $warning['description']->getUntranslatedString();
      self::assertStringContainsString('BEANTRAGT_SSO_MOCK=true', $description);
      self::assertStringNotContainsString('BEANTRAGT_DEMO_MODE', $description);
    }
    finally {
      foreach ($previous as $name => $value) {
        putenv($name . ($value === FALSE ? '' : '=' . $value));
      }
    }
  }

  /**
   * Two first logins through independent MariaDB connections share one binding.
   */
  public function testConcurrentFirstLogin(): void {
    $database = $this->container->get('database');
    // Establish the lock table before competing first-login requests.
    $lock = new DatabaseLockBackend($database);
    $lock->acquire('sso.test.setup');
    $lock->release('sso.test.setup');
    $children = [];
    $paths = [];
    $options = $database->getConnectionOptions();
    $barrier = sys_get_temp_dir() . '/sso-race-ready-' . bin2hex(random_bytes(8));
    for ($index = 0; $index < 2; $index++) {
      $path = sys_get_temp_dir() . '/sso-race-' . bin2hex(random_bytes(8));
      $paths[] = $path;
      $pid = pcntl_fork();
      if ($pid === 0) {
        try {
          Database::addConnectionInfo('sso-child-' . $index, 'default', $options);
          $child_database = Database::getConnection('default', 'sso-child-' . $index);
          $this->container->set('database', $child_database);
          if ($index === 0) {
            $child_database->enableEvents([StatementExecutionStartEvent::class, StatementExecutionEndEvent::class]);
            $this->container->get('event_dispatcher')->addListener(
              StatementExecutionEndEvent::class,
              static function ($event) use ($barrier): void {
                if (str_starts_with($event->queryString, 'INSERT') && str_contains($event->queryString, 'beantragt_sso_identities')) {
                  file_put_contents($barrier, 'ready');
                  usleep(350000);
                }
              },
            );
          }
          else {
            $until = microtime(TRUE) + 10;
            while (!file_exists($barrier) && microtime(TRUE) < $until) {
              usleep(10000);
            }
          }
          $manager = $this->container->get('entity_type.manager');
          $manager->clearCachedDefinitions();
          $mail = new VerifiedAccountMail($child_database, $this->container->get('cache_tags.invalidator'));
          $linker = new SsoIdentityLinker($child_database, $manager,
            $this->createMock(ApplicantManager::class), new NullLogger(), NULL,
            $mail, new DatabaseLockBackend($child_database));
          $response = $linker->authenticate('test', ['create_users' => TRUE], 'race', [], []);
          file_put_contents($path, (string) $response['uid']);
          // Avoid inherited PHPUnit/kernel teardown and its database cleanup.
          posix_kill(getmypid(), SIGKILL);
        }
        catch (\Throwable $exception) {
          file_put_contents($path, 'failed:' . $exception::class . ':' . $exception->getMessage());
          posix_kill(getmypid(), SIGKILL);
        }
      }
      $children[] = $pid;
    }
    foreach ($children as $pid) {
      pcntl_waitpid($pid, $status);
    }
    $results = array_map(static fn(string $path): string => (string) file_get_contents($path), $paths);
    foreach ($paths as $path) {
      unlink($path);
    }
    $this->assertFileExists($barrier, 'The first login paused before committing its binding.');
    if (file_exists($barrier)) {
      unlink($barrier);
    }
    $this->assertMatchesRegularExpression('/^[0-9]+$/', $results[0]);
    $this->assertSame($results[0], $results[1]);
    $this->assertSame(1, $this->identityCount());
    $this->assertSame(2, (int) $database->select('users')->countQuery()->execute()->fetchField());
  }

  /**
   * Returns the shared provenance store.
   */
  private function mail(): VerifiedAccountMail {
    return $this->container->get('beantragt_passwordless.verified_mail');
  }

  /**
   * Runs the real linker with a profile collaborator that rejects side effects.
   */
  private function login(string $subject, bool $verified, array $extra = [], bool $expect_denial = FALSE): array {
    $applicants = $this->createMock(ApplicantManager::class);
    if ($expect_denial) {
      $applicants->expects($this->never())->method('updateExistingFromLogin');
      $applicants->expects($this->never())->method('claimUnclaimedByVerifiedEmail');
    }
    $linker = new SsoIdentityLinker(
      $this->container->get('database'), $this->container->get('entity_type.manager'),
      $applicants, new NullLogger(), NULL, $this->mail(),
      new DatabaseLockBackend($this->container->get('database')),
    );
    return $linker->authenticate('test', $extra + [
      'email_verified' => $verified,
      'create_users' => TRUE,
      'attribute_map' => ['email' => ['email']],
    ], $subject, ['email' => ['citizen@example.test']], []);
  }

  /**
   * Creates an active synthetic account.
   */
  private function user(string $name, string $email, array $roles = []): UserInterface {
    $user = User::create(['name' => $name, 'mail' => $email, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Pre-links a synthetic subject as an explicit administrative action.
   */
  private function bind(string $subject, UserInterface|int $user): void {
    $this->container->get('database')->insert('beantragt_sso_identities')->fields([
      'provider' => 'test',
      'subject_hash' => $this->hash($subject),
      'uid' => $user instanceof UserInterface ? $user->id() : $user,
      'created' => time(),
      'changed' => time(),
      'link_method' => 'manual',
    ])->execute();
  }

  /**
   * Hashes subjects using the configured site salt.
   */
  private function hash(string $subject): string {
    return hash_hmac('sha256', "test\n" . $subject, Settings::getHashSalt());
  }

  /**
   * Reads a binding for assertions.
   */
  private function identity(string $subject): array {
    return $this->container->get('database')->select('beantragt_sso_identities', 'i')
      ->fields('i')->condition('subject_hash', $this->hash($subject))->execute()->fetchAssoc();
  }

  /**
   * Counts persisted bindings.
   */
  private function identityCount(): int {
    return (int) $this->container->get('database')->select('beantragt_sso_identities')->countQuery()->execute()->fetchField();
  }

  /**
   * Asserts a denial has no registration or authentication side effects.
   */
  private function assertDeniedUnchanged(string $subject, bool $verified, array $extra = [], ?string $code = NULL): void {
    $database = $this->container->get('database');
    $before = (int) $database->select('users')->countQuery()->execute()->fetchField();
    $bindings = $database->select('beantragt_sso_identities', 'i')->fields('i')->execute()->fetchAll();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->session->clear();
    try {
      $this->login($subject, $verified, $extra, TRUE);
      $this->fail('Unsafe login was accepted.');
    }
    catch (\RuntimeException | DatabaseExceptionWrapper $exception) {
      $this->assertNotSame('', $exception->getMessage());
      if ($code !== NULL) {
        $this->assertInstanceOf(SsoAuthenticationException::class, $exception);
        $this->assertSame($code, $exception->failureCode);
      }
    }
    $this->assertSame($before, (int) $database->select('users')->countQuery()->execute()->fetchField());
    $this->assertEquals($bindings, $database->select('beantragt_sso_identities', 'i')->fields('i')->execute()->fetchAll(), $exception->getMessage());
    $this->assertFalse($this->container->get('current_user')->isAuthenticated());
    $this->assertNull($this->session->get('uid'));
  }

}
