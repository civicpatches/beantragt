<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_status\Unit;

use Drupal\beantragt_status\Controller\StatusController;
use Drupal\beantragt_status\EventSubscriber\StatusCodeSubscriber;
use Drupal\beantragt_status\Service\StatusBuilder;
use Drupal\beantragt_status\Service\StatusOtpService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests flood identifiers for public status proxy requests.
 */
#[Group('beantragt_status')]
final class StatusControllerProxyFloodTest extends UnitTestCase {

  /**
   * Valid public status token used by controller tests.
   */
  private const PUBLIC_STATUS_TOKEN = 'public_status_token_2026_aaaaaaaa';

  /**
   * Original service user environment value.
   */
  private string|false $originalServiceUser;

  /**
   * Original service password environment value.
   */
  private string|false $originalServicePassword;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->originalServiceUser = getenv('NUXT_DRUPAL_SERVICE_USER');
    $this->originalServicePassword = getenv('NUXT_DRUPAL_SERVICE_PASSWORD');
    putenv('NUXT_DRUPAL_SERVICE_USER=nuxt_service');
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=proxy-secret');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_USER', $this->originalServiceUser);
    $this->restoreEnv('NUXT_DRUPAL_SERVICE_PASSWORD', $this->originalServicePassword);

    parent::tearDown();
  }

  /**
   * Service-proxied status lookups use the trusted proxy flood identifier.
   */
  public function testTrustedProxyFloodIdentifierIsUsed(): void {
    $proxy_identifier = str_repeat('a', 32);
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_status.lookup', 30, 3600, 'proxy:' . $proxy_identifier)
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('beantragt_status.lookup', 3600, 'proxy:' . $proxy_identifier);

    $controller = $this->createController($flood);
    $request = Request::create('/api/application-status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001&jurisdiction=dunkelstadt&organisation=org-21', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
      'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:proxy-secret'),
      'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => $proxy_identifier,
    ]);

    $response = $controller->getApplicationStatus(self::PUBLIC_STATUS_TOKEN, $request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Eingegangen', $payload['status_label']);
  }

  /**
   * Untrusted proxy identifiers fall back to the request client IP.
   */
  public function testUntrustedProxyFloodIdentifierFallsBackToClientIp(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_status.lookup', 30, 3600, '198.51.100.22')
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('beantragt_status.lookup', 3600, '198.51.100.22');

    $controller = $this->createController($flood);
    $request = Request::create('/api/application-status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
      'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic invalid',
      'HTTP_X_BEANTRAGT_RATE_LIMIT_IDENTIFIER' => str_repeat('b', 32),
    ]);

    $response = $controller->getApplicationStatus(self::PUBLIC_STATUS_TOKEN, $request);

    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Public v1 status lookup wraps status data in a stable envelope.
   */
  public function testApplicationStatusV1WrapsSuccessfulLookup(): void {
    $flood = $this->createAllowedFlood();
    $controller = $this->createController($flood);
    $request = Request::create('/api/v1/status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001&jurisdiction=dunkelstadt&organisation=org-21', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
    ]);

    $response = $controller->getApplicationStatusV1(self::PUBLIC_STATUS_TOKEN, $request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'));
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertSame('applicationStatus', $payload['meta']['resource']);
    $this->assertSame('DUNKELSTADT-21-2026-00005', $payload['data']['referenceNumber']);
    $this->assertSame('eingegangen', $payload['data']['status']);
    $this->assertSame('Eingegangen', $payload['data']['statusLabel']);
    $this->assertSame('#2563eb', $payload['data']['statusColor']);
    $this->assertSame('i-lucide-inbox', $payload['data']['statusIcon']);
    $this->assertSame('Hund zur Hundesteuer anmelden', $payload['data']['formTitle']);
    $this->assertSame('Buergerbuero', $payload['data']['organisationName']);
    $this->assertSame('2026-06-04T12:33:51+02:00', $payload['data']['submittedAt']);
    $this->assertSame('In Bearbeitung', $payload['data']['timeline'][1]['label']);
    $this->assertSame('staff', $payload['data']['timeline'][1]['actor']);
    $this->assertNull($payload['data']['timeline'][2]['actor']);
    $this->assertArrayNotHasKey('reference_number', $payload['data']);
  }

  /**
   * Public v1 status lookup fails closed for malformed scope filters.
   */
  public function testApplicationStatusV1FailsClosedForInvalidScope(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $controller = $this->createController($flood, expectLookup: FALSE);
    $request = Request::create('/api/v1/status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001&jurisdiction=_invalid', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
    ]);

    $response = $controller->getApplicationStatusV1(self::PUBLIC_STATUS_TOKEN, $request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('status.not_found', $payload['error']['code']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Public v1 status lookup fails closed for non-scalar scope filters.
   */
  public function testApplicationStatusV1FailsClosedForArrayScope(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $controller = $this->createController($flood, expectLookup: FALSE);
    $request = Request::create('/api/v1/status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001&jurisdiction[]=dunkelstadt', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
    ]);

    $response = $controller->getApplicationStatusV1(self::PUBLIC_STATUS_TOKEN, $request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('status.not_found', $payload['error']['code']);
  }

  /**
   * Supplies the scope query for each case.
   *
   * @return array<string, array{string}>
   *   Named test cases.
   */
  public static function invalidPublicStatusScopeProvider(): array {
    return [
      'non-scalar organisation' => ['organisation[]=org-21'],
      'malformed jurisdiction' => ['jurisdiction=_invalid'],
    ];
  }

  /**
   * Malformed scope filters fail closed before lookup or rate limiting.
   */
  #[DataProvider('invalidPublicStatusScopeProvider')]
  public function testApplicationStatusFailsClosedForInvalidScope(string $scope_query): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->never())->method('isAllowed');
    $controller = $this->createController($flood, expectLookup: FALSE);
    $request = Request::create('/api/application-status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001&' . $scope_query, 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
    ]);

    $response = $controller->getApplicationStatus(self::PUBLIC_STATUS_TOKEN, $request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('Application not found.', $payload['error']);
  }

  /**
   * Public v1 rate limits use the API error envelope.
   */
  public function testApplicationStatusV1RateLimitUsesErrorEnvelope(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_status.lookup', 30, 3600, '198.51.100.22')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $controller = $this->createController($flood, expectLookup: FALSE);
    $request = Request::create('/api/v1/status/' . self::PUBLIC_STATUS_TOKEN . '?schemaId=00000000-0000-4000-8000-000000000001', 'GET', [], [], [], [
      'REMOTE_ADDR' => '198.51.100.22',
    ]);

    $response = $controller->getApplicationStatusV1(self::PUBLIC_STATUS_TOKEN, $request);
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('rate_limit.exceeded', $payload['error']['code']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
  }

  /**
   * Creates a status controller with mocked collaborators.
   */
  private function createController(FloodInterface $flood, bool $expectLookup = TRUE): StatusController {
    $status_builder = $this->createMock(StatusBuilder::class);
    if ($expectLookup) {
      $status_builder->expects($this->once())
        ->method('buildByPublicStatusToken')
        ->with(self::PUBLIC_STATUS_TOKEN, '00000000-0000-4000-8000-000000000001', $this->anything(), $this->anything())
        ->willReturn([
          'reference_number' => 'DUNKELSTADT-21-2026-00005',
          'status' => 'eingegangen',
          'status_label' => 'Eingegangen',
          'status_color' => '#2563eb',
          'status_icon' => 'i-lucide-inbox',
          'form_title' => 'Hund zur Hundesteuer anmelden',
          'org_name' => 'Buergerbuero',
          'submitted_at' => '2026-06-04T12:33:51+02:00',
          'updated_at' => '2026-06-04T12:33:51+02:00',
          'timeline' => [
            [
              'status' => 'eingegangen',
              'label' => 'Eingegangen',
              'color' => '#2563eb',
              'icon' => 'i-lucide-inbox',
              'timestamp' => '2026-06-04T12:33:51+02:00',
            ],
            [
              'status' => 'in_bearbeitung',
              'label' => 'In Bearbeitung',
              'color' => '#0284c7',
              'icon' => 'i-lucide-route',
              'timestamp' => '2026-06-05T08:15:00+02:00',
              'actor' => 'staff',
            ],
            [
              'status' => 'abgeschlossen',
              'label' => 'Abgeschlossen',
              'color' => '#16a34a',
              'icon' => 'i-lucide-check',
              'timestamp' => '2026-06-06T09:00:00+02:00',
              'actor' => 'case_worker',
            ],
          ],
        ]);
    }
    else {
      $status_builder->expects($this->never())->method('buildByPublicStatusToken');
    }

    $otp = new StatusOtpService(
      $this->createMock(Connection::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LanguageManagerInterface::class),
      $this->createMock(PrivateKey::class),
      $this->createMock(LoggerInterface::class),
    );
    return new StatusController(
      $status_builder,
      $otp,
      $flood,
      new StatusCodeSubscriber($status_builder, $otp, $this->createMock(LoggerInterface::class)),
    );
  }

  /**
   * Creates flood control that allows one public status lookup.
   */
  private function createAllowedFlood(): FloodInterface {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_status.lookup', 30, 3600, '198.51.100.22')
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('beantragt_status.lookup', 3600, '198.51.100.22');

    return $flood;
  }

  /**
   * Restores an environment variable to its original state.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }

    putenv($name . '=' . $value);
  }

}
