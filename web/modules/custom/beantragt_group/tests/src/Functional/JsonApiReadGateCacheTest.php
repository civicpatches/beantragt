<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Functional;

use Drupal\beantragt_schema\Access\JsonApiReadGateAccessCheck;
use Drupal\Component\Serialization\Json;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * The JSON:API read gate over real HTTP, with both page caches.
 *
 * Every assertion message carries the X-Drupal-Cache and
 * X-Drupal-Dynamic-Cache values of the response it is about.
 */
#[Group('beantragt_group')]
#[Group('beantragt_schema')]
final class JsonApiReadGateCacheTest extends BrowserTestBase {

  use GroupTestTrait;

  private const API_JSON = 'application/vnd.api+json';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'dynamic_page_cache',
    'file',
    'jsonapi',
    'json_form_widget',
    'language',
    'page_cache',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The published organisation.
   */
  private GroupInterface $organisation;

  /**
   * Callers.
   */
  private UserInterface $staff;

  /**
   * A signed-in citizen.
   */
  private UserInterface $citizen;

  /**
   * The service account (Basic auth).
   */
  private UserInterface $service;

  /**
   * Session cookies per caller.
   *
   * @var array<string, \GuzzleHttp\Cookie\CookieJar>
   */
  private array $jars = [];

  /**
   * Every response's cache headers, in request order.
   *
   * @var array<int, array{0: string, 1: string, 2: int, 3: string, 4: string}>
   */
  private array $log = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-url' => -8, 'language-selected' => 12])
      ->save();
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    foreach (['case_worker', 'service_account'] as $role_id) {
      if (!Role::load($role_id)) {
        Role::create(['id' => $role_id, 'label' => $role_id])->save();
      }
    }
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Cache jurisdiction', 'field_slug' => 'cache-jur']);
    $this->organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Cache organisation',
      'field_slug' => 'cache-org',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);

    $this->staff = $this->createUser(['view published application entities']);
    $this->staff->addRole('case_worker');
    $this->staff->save();
    $this->organisation->addMember($this->staff, ['group_roles' => ['org-member']]);
    $this->citizen = $this->createUser(['view own application entities']);
    $this->service = $this->createUser([]);
    $this->service->addRole('service_account');
    $this->service->save();
    $this->rebuildAll();

    foreach (['staff' => $this->staff, 'citizen' => $this->citizen] as $label => $account) {
      $this->drupalLogin($account);
      $this->jars[$label] = $this->getSessionCookies();
      // Keep the session alive on the server: forget it without logging out.
      $this->loggedInUser = FALSE;
      $this->getSession()->reset();
    }
  }

  /**
   * One refusal per caller class, also under a language prefix and slash.
   */
  public function testRefusalsOverHttp(): void {
    $list = '/jsonapi/group/org';
    $anonymous = $this->call('GET', $list, 'anonymous');
    $citizen = $this->call('GET', $list, 'citizen');
    $this->assertRefusal($anonymous, 401, 'anonymous');
    $this->assertRefusal($citizen, 403, 'citizen');
    foreach (['/de' . $list, $list . '/', '/de' . $list . '/'] as $variant) {
      $this->assertSame($this->normalized($anonymous), $this->normalized($this->assertRefusal($this->call('GET', $variant, 'anonymous'), 401, "anonymous $variant")));
      $this->assertSame($this->normalized($citizen), $this->normalized($this->assertRefusal($this->call('GET', $variant, 'citizen'), 403, "citizen $variant")));
    }

    // A made-up value under the real session cookie name selects 403.
    $name = NULL;
    foreach ($this->jars['citizen'] as $cookie) {
      $name = $cookie->getName();
    }
    $this->assertNotNull($name);
    $this->assertMatchesRegularExpression('/^S?SESS/', (string) $name);
    $fake = CookieJar::fromArray([(string) $name => 'made-up-session-value'], parse_url($this->baseUrl, PHP_URL_HOST));
    $made_up = $this->assertRefusal($this->call('GET', $list, $fake), 403, 'made-up session cookie');
    $this->assertSame($this->normalized($citizen), $this->normalized($made_up));
  }

  /**
   * Relationship writes with and without a CSRF token get the same refusal.
   */
  public function testRelationshipWritesWithAndWithoutCsrfToken(): void {
    $url = '/jsonapi/group/org/' . $this->organisation->uuid() . '/relationships/uid';
    $token = (string) $this->getHttpClient()->request('GET', $this->buildUrl('/session/token'), [
      'cookies' => $this->jars['citizen'],
      'http_errors' => FALSE,
    ])->getBody();
    $this->assertNotSame('', $token);
    $document = Json::encode(['data' => ['type' => 'user--user', 'id' => $this->citizen->uuid()]]);
    $reference = NULL;
    foreach (['POST', 'PATCH', 'DELETE'] as $method) {
      foreach (['with token' => ['X-CSRF-Token' => $token], 'without token' => []] as $label => $headers) {
        $response = $this->assertRefusal(
          $this->call($method, $url, 'citizen', ['Content-Type' => self::API_JSON] + $headers, $document),
          403,
          "$method $label",
        );
        $reference ??= $this->normalized($response);
        $this->assertSame($reference, $this->normalized($response), "$method $label");
      }
    }
    $this->assertSame((int) $this->organisation->getOwnerId(), (int) $this->reloadOrganisation()->getOwnerId());
  }

  /**
   * Cached answers never cross caller classes; the warm cache follows roles.
   */
  public function testCachedAnswersFollowTheCaller(): void {
    $list = '/jsonapi/group/org';
    $foreign_jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Foreign jurisdiction',
      'field_slug' => 'other-cache-jur',
    ]);
    $foreign_office = $this->createGroup([
      'type' => 'org',
      'label' => 'Foreign office',
      'field_slug' => 'other-cache-org',
      'field_jurisdiction' => ['target_id' => $foreign_jurisdiction->id()],
    ]);
    $other_staff = $this->createUser([]);
    foreach ($this->staff->getRoles(TRUE) as $role) {
      $other_staff->addRole($role);
    }
    $other_staff->save();
    $foreign_office->addMember($other_staff, ['group_roles' => ['org-member']]);
    $this->drupalLogin($other_staff);
    $this->jars['other-staff'] = $this->getSessionCookies();
    $this->loggedInUser = FALSE;
    $this->getSession()->reset();

    foreach ([
      ['staff', 'citizen', 'staff'],
      ['anonymous', 'staff', 'anonymous'],
      ['anonymous', 'service'],
      ['staff', 'staff', 'citizen', 'anonymous', 'service', 'citizen'],
    ] as $order) {
      foreach ($order as $who) {
        $response = $this->call('GET', $list, $who);
        if (in_array($who, ['staff', 'service'], TRUE)) {
          $this->assertSame(200, $response->getStatusCode(), $this->describe($response, $who));
          $data = Json::decode((string) $response->getBody())['data'] ?? [];
          $this->assertContains($this->organisation->uuid(), array_column($data, 'id'), $this->describe($response, $who));
        }
        else {
          $this->assertRefusal($response, $who === 'anonymous' ? 401 : 403, $who);
        }
      }
    }

    // The page cache served at least one anonymous refusal.
    $hits = array_filter($this->log, static fn (array $row): bool => $row[1] === 'anonymous' && $row[2] === 401 && $row[3] === 'HIT');
    $this->assertNotSame([], $hits, $this->logText());

    // Dynamic page cache: a staff HIT directly before a citizen request to
    // the same URL proves the separation; without any cacheable staff answer
    // the test says exactly that instead.
    $staff_dpc = array_unique(array_column(array_filter($this->log, static fn (array $row): bool => $row[1] === 'staff'), 4));
    $separation = FALSE;
    for ($i = 0; $i + 1 < count($this->log); $i++) {
      [$url, $who, , , $dpc] = $this->log[$i];
      [$next_url, $next_who, $next_status] = $this->log[$i + 1];
      if ($who === 'staff' && $dpc === 'HIT' && $next_who === 'citizen' && $next_url === $url) {
        $this->assertSame(403, $next_status, $this->logText());
        $separation = TRUE;
      }
    }
    // Measured on this fixture: the organisation list is cacheable for staff,
    // so the separation is exercised, not inferred.
    $this->assertTrue($separation, 'No staff HIT directly before a citizen request; staff answers: ' . implode(', ', $staff_dpc) . "\n" . $this->logText());

    foreach (['staff', 'other-staff', 'other-staff', 'staff'] as $who) {
      $response = $this->call('GET', $list, $who);
      $this->assertSame(200, $response->getStatusCode(), $this->describe($response, $who));
      $data = Json::decode((string) $response->getBody())['data'] ?? [];
      $expected = $who === 'staff' ? $this->organisation : $foreign_office;
      $this->assertSame([$expected->uuid()], array_column($data, 'id'), $this->describe($response, $who));
    }
  }

  /**
   * A warm cache does not keep a removed role's access.
   */
  public function testRemovedRoleIsRefusedOnWarmCache(): void {
    $list = '/jsonapi/group/org';
    for ($i = 0; $i < 2; $i++) {
      $response = $this->call('GET', $list, 'staff');
      $this->assertSame(200, $response->getStatusCode(), $this->describe($response, 'staff'));
    }
    $this->staff->removeRole('case_worker');
    $this->staff->save();
    $this->assertRefusal($this->call('GET', $list, 'staff'), 403, 'staff without role');
  }

  /**
   * Removing a membership changes a collection already served from cache.
   */
  public function testRevokedMembershipInvalidatesWarmCollection(): void {
    $path = $this->warmWorkspaceCollection();
    $this->reloadOrganisation()->removeMember($this->staff);
    $this->assertEmptyWorkspaceCollection($path);
  }

  /**
   * Removing a Group permission invalidates an already cached collection.
   */
  public function testRevokedGroupPermissionInvalidatesWarmCollection(): void {
    $path = $this->warmWorkspaceCollection();
    $role = GroupRole::load('org-member');
    $this->assertNotNull($role);
    $role->revokePermission('view group')->save();
    $this->assertNotFalse($this->reloadOrganisation()->getMember($this->staff));
    $this->assertEmptyWorkspaceCollection($path);
  }

  /**
   * Returns the session request URL after proving a dynamic cache hit.
   */
  private function warmWorkspaceCollection(): string {
    $path = '/jsonapi/group/org';
    $this->call('GET', $path, 'staff');
    $hit = $this->call('GET', $path, 'staff');
    $this->assertSame(200, $hit->getStatusCode(), $this->logText());
    $this->assertSame('HIT', $hit->getHeaderLine('X-Drupal-Dynamic-Cache'), $this->logText());
    $this->assertSame([$this->organisation->uuid()], array_column(Json::decode((string) $hit->getBody())['data'], 'id'));
    return $path;
  }

  /**
   * Verifies revoked scope even when the staff session is still authorized.
   */
  private function assertEmptyWorkspaceCollection(string $path): void {
    $response = $this->call('GET', $path, 'staff');
    $this->assertSame(200, $response->getStatusCode(), $this->logText());
    $document = Json::decode((string) $response->getBody());
    $this->assertSame([], $document['data'], $this->logText());
    $this->assertArrayNotHasKey('omitted', $document['meta'] ?? []);
    $this->assertSame(['self'], array_keys($document['links']));
  }

  /**
   * Sends a request as a caller and records its cache headers.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Path with query.
   * @param string|\GuzzleHttp\Cookie\CookieJar $who
   *   'anonymous', 'staff' or 'citizen' (session), 'service' (Basic auth),
   *   or a cookie jar.
   * @param array<string, string> $headers
   *   Extra headers.
   * @param string|null $body
   *   Request body.
   */
  private function call(string $method, string $path, string|CookieJar $who, array $headers = [], ?string $body = NULL): ResponseInterface {
    $options = [
      'headers' => $headers + ['Accept' => self::API_JSON],
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ];
    if ($who instanceof CookieJar) {
      $options['cookies'] = $who;
      $who = 'made-up cookie';
    }
    elseif (isset($this->jars[$who])) {
      $options['cookies'] = $this->jars[$who];
    }
    elseif ($who === 'service') {
      $options['auth'] = [$this->service->getAccountName(), $this->service->passRaw];
    }
    if ($body !== NULL) {
      $options['body'] = $body;
    }
    $response = $this->getHttpClient()->request($method, $this->buildUrl($path), $options);
    $this->log[] = [
      $path,
      $who,
      $response->getStatusCode(),
      $response->getHeaderLine('X-Drupal-Cache') ?: '-',
      $response->getHeaderLine('X-Drupal-Dynamic-Cache') ?: '-',
    ];
    return $response;
  }

  /**
   * Asserts the fixed refusal and returns the response.
   */
  private function assertRefusal(ResponseInterface $response, int $status, string $who): ResponseInterface {
    $message = $this->describe($response, $who);
    $this->assertSame($status, $response->getStatusCode(), $message);
    $document = Json::decode((string) $response->getBody());
    $this->assertIsArray($document, $message);
    $keys = array_keys($document);
    sort($keys);
    $this->assertSame(['errors', 'jsonapi'], $keys, $message);
    if ($status === 401) {
      $this->assertStringStartsWith('Basic realm=', $response->getHeaderLine('WWW-Authenticate'), $message);
      $this->assertSame('No authentication credentials provided.', $document['errors'][0]['detail'], $message);
    }
    else {
      $this->assertSame('', $response->getHeaderLine('WWW-Authenticate'), $message);
      $this->assertSame(JsonApiReadGateAccessCheck::REASON, $document['errors'][0]['detail'], $message);
    }
    return $response;
  }

  /**
   * Returns the body without the echo of the caller's own URL.
   */
  private function normalized(ResponseInterface $response): string {
    $document = Json::decode((string) $response->getBody());
    foreach ($document['errors'] ?? [] as $index => $error) {
      unset($document['errors'][$index]['links']['via']['href']);
    }
    return Json::encode($document);
  }

  /**
   * Describes a response with its cache headers.
   */
  private function describe(ResponseInterface $response, string $who): string {
    return sprintf('%s: %d, X-Drupal-Cache %s, X-Drupal-Dynamic-Cache %s',
      $who,
      $response->getStatusCode(),
      $response->getHeaderLine('X-Drupal-Cache') ?: '-',
      $response->getHeaderLine('X-Drupal-Dynamic-Cache') ?: '-',
    );
  }

  /**
   * Returns the request log as text.
   */
  private function logText(): string {
    return implode("\n", array_map(static fn (array $row): string => implode(' | ', $row), $this->log));
  }

  /**
   * Reloads the organisation.
   */
  private function reloadOrganisation(): GroupInterface {
    $group = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($this->organisation->id());
    assert($group instanceof GroupInterface);
    return $group;
  }

}
