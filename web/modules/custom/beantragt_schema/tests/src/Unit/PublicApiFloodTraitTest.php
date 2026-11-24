<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Trait\PublicApiFloodTrait;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The flood identifier of public reads and the fallback to the client address.
 */
#[CoversTrait(PublicApiFloodTrait::class)]
#[Group('beantragt_schema')]
final class PublicApiFloodTraitTest extends UnitTestCase {

  private const EVENT = 'beantragt_content.public_documents';
  private const SECRET = 'invented-service-secret';
  private const IDENTIFIER = '0123456789abcdef0123456789abcdef';

  /**
   * Environment before the test.
   *
   * @var array<string, string|false>
   */
  private array $environment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['NUXT_DRUPAL_SERVICE_USER' => 'nuxt_service', 'NUXT_DRUPAL_SERVICE_PASSWORD' => self::SECRET] as $name => $value) {
      $this->environment[$name] = getenv($name);
      putenv($name . '=' . $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->environment as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * A valid secret with a well-formed identifier counts per visitor.
   */
  public function testTrustedProxyCountsPerVisitorWithoutWarning(): void {
    [$host, $flood, $logger] = $this->host();

    $this->assertNull($host->check($this->request('Basic ' . base64_encode('nuxt_service:' . self::SECRET), self::IDENTIFIER)));

    $this->assertSame([self::EVENT => ['proxy:' . self::IDENTIFIER => 1]], $flood->registered);
    $this->assertSame([], $logger->messages);
  }

  /**
   * A wrong or missing secret, or a malformed identifier, count per address.
   */
  #[DataProvider('untrustedRequests')]
  public function testUntrustedRequestFallsBackToAddressAndWarnsOnce(string $authorization, string $identifier): void {
    [$host, $flood, $logger] = $this->host();

    $this->assertNull($host->check($this->request($authorization, $identifier)));
    $this->assertNull($host->check($this->request($authorization, $identifier)));

    $this->assertSame(2, $flood->registered[self::EVENT]['198.51.100.7']);
    $this->assertArrayNotHasKey('proxy:' . self::IDENTIFIER, $flood->registered[self::EVENT]);
    // One warning for two requests: the guard is one flood entry per event.
    $this->assertCount(1, $logger->messages);
    $this->assertSame(['beantragt_schema.flood_fallback_warning' => [self::EVENT => 1]], array_intersect_key($flood->registered, ['beantragt_schema.flood_fallback_warning' => TRUE]));
    [$level, $message, $context] = $logger->messages[0];
    $this->assertSame('warning', $level);
    $this->assertSame(['@event' => self::EVENT], $context);
    $this->assertStringNotContainsString('198.51.100.7', $message);
    $this->assertStringNotContainsString(self::SECRET, $message);
  }

  /**
   * Requests that must not be trusted.
   *
   * @return array<string, array{string, string}>
   *   Authorization header and identifier.
   */
  public static function untrustedRequests(): array {
    return [
      'wrong secret' => ['Basic ' . base64_encode('nuxt_service:wrong'), self::IDENTIFIER],
      'missing secret' => ['', self::IDENTIFIER],
      'malformed identifier' => ['Basic ' . base64_encode('nuxt_service:' . self::SECRET), 'not-a-hex-identifier'],
    ];
  }

  /**
   * An empty or unset Drupal secret never trusts a supplied proxy identifier.
   */
  #[DataProvider('emptyDrupalSecrets')]
  public function testEmptyDrupalSecretFallsBackToAddress(bool $unset): void {
    putenv($unset ? 'NUXT_DRUPAL_SERVICE_PASSWORD' : 'NUXT_DRUPAL_SERVICE_PASSWORD=');
    [$host, $flood, $logger] = $this->host();
    $authorization = 'Basic ' . base64_encode('nuxt_service:' . self::SECRET);

    $this->assertNull($host->check($this->request($authorization, self::IDENTIFIER)));
    $this->assertNull($host->check($this->request($authorization, self::IDENTIFIER)));
    $this->assertSame(['198.51.100.7' => 2], $flood->registered[self::EVENT]);
    $this->assertSame([self::EVENT => 1], $flood->registered['beantragt_schema.flood_fallback_warning']);
    $this->assertCount(1, $logger->messages);
    $this->assertSame(['@event' => self::EVENT], $logger->messages[0][2]);
  }

  /**
   * Provides the two ways the Drupal side can lack the service secret.
   *
   * @return array<string, array{bool}>
   *   Whether to unset the variable or leave it explicitly empty.
   */
  public static function emptyDrupalSecrets(): array {
    return ['empty' => [FALSE], 'unset' => [TRUE]];
  }

  /**
   * The denial stays private, no-store and outside every cache.
   */
  public function testDenialStaysUncacheable(): void {
    [$host] = $this->host();
    $authorization = 'Basic ' . base64_encode('nuxt_service:' . self::SECRET);

    // The host allows two requests per window; the third is denied.
    $this->assertNull($host->check($this->request($authorization, self::IDENTIFIER)));
    $this->assertNull($host->check($this->request($authorization, self::IDENTIFIER)));
    $response = $host->check($this->request($authorization, self::IDENTIFIER));

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertNotInstanceOf(CacheableResponseInterface::class, $response);
    $this->assertSame(429, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
  }

  /**
   * Builds a request from the address 198.51.100.7.
   */
  private function request(string $authorization, string $identifier): Request {
    $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $identifier];
    if ($authorization !== '') {
      $server['HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION'] = $authorization;
    }
    return Request::create('/api/v1/jurisdictions/musterstadt/documents', 'GET', [], [], [], $server);
  }

  /**
   * Returns a trait host with an in-memory flood and a recording logger.
   *
   * @return array{object, object, object}
   *   Host, flood and logger.
   */
  private function host(): array {
    $flood = new class() implements FloodInterface {

      /**
       * Registered events per name and identifier.
       *
       * @var array<string, array<string, int>>
       */
      public array $registered = [];

      /**
       * {@inheritdoc}
       */
      public function register($name, $window = 3600, $identifier = NULL) {
        $this->registered[$name][(string) $identifier] = ($this->registered[$name][(string) $identifier] ?? 0) + 1;
      }

      /**
       * {@inheritdoc}
       */
      public function clear($name, $identifier = NULL) {
        unset($this->registered[$name][(string) $identifier]);
      }

      /**
       * {@inheritdoc}
       */
      public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL) {
        return ($this->registered[$name][(string) $identifier] ?? 0) < $threshold;
      }

      /**
       * {@inheritdoc}
       */
      public function garbageCollection() {}

    };
    $logger = new class() extends AbstractLogger {

      /**
       * Recorded log calls.
       *
       * @var array<int, array{string, string, array<string, mixed>}>
       */
      public array $messages = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->messages[] = [(string) $level, (string) $message, $context];
      }

    };
    $host = new class($flood, $logger) {

      use PublicApiFloodTrait;

      private const FLOOD_LIMIT = 2;

      private const FLOOD_WINDOW = 3600;

      /**
       * The page cache kill switch the trait triggers on a denial.
       */
      private KillSwitch $pageCacheKillSwitch;

      public function __construct(
        private FloodInterface $flood,
        private AbstractLogger $logger,
      ) {
        $this->pageCacheKillSwitch = new KillSwitch();
      }

      /**
       * Runs the trait for the documents event.
       */
      public function check(Request $request): ?JsonResponse {
        return $this->floodResponse($request, PublicApiFloodTraitTest::EVENT_NAME);
      }

    };
    return [$host, $flood, $logger];
  }

  /**
   * Public alias of the event for the anonymous host class.
   */
  public const EVENT_NAME = self::EVENT;

}
