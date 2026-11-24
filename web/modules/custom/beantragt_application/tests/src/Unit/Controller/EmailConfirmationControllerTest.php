<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Controller;

use Drupal\beantragt_application\Controller\EmailConfirmationController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\EmailConfirmationTokenService;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests public email confirmation endpoints.
 */
#[CoversClass(EmailConfirmationController::class)]
#[Group('beantragt_application')]
final class EmailConfirmationControllerTest extends UnitTestCase {

  /**
   * Environment values to restore after each test.
   *
   * The PHPUnit process is shared with later tests, including functional
   * tests that read NUXT_DRUPAL_SERVICE_USER, so nothing may be left unset.
   *
   * @var array<string, string|false>
   */
  private array $previousEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['NUXT_DRUPAL_SERVICE_PASSWORD', 'NUXT_DRUPAL_SERVICE_USER'] as $name) {
      $this->previousEnv[$name] = getenv($name);
    }
  }

  /**
   * Valid confirmation returns the pinned status.
   */
  public function testConfirmReturnsConfirmed(): void {
    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_CONFIRMED),
      $this->allowedFlood(),
    );

    $response = $controller->confirm($this->jsonRequest('/api/v1/public/applications/confirm', ['token' => str_repeat('A', 64)]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([
      'status' => 'confirmed',
      'referenceNumber' => NULL,
      'jurisdictionSlug' => NULL,
      'receipt' => NULL,
    ], json_decode((string) $response->getContent(), TRUE));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Already-confirmed tokens return the pinned replay status.
   */
  public function testConfirmReturnsAlreadyConfirmed(): void {
    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_ALREADY_CONFIRMED),
      $this->allowedFlood(),
    );

    $response = $controller->confirm($this->jsonRequest('/api/v1/public/applications/confirm', ['token' => str_repeat('A', 64)]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame([
      'status' => 'already_confirmed',
      'referenceNumber' => NULL,
      'jurisdictionSlug' => NULL,
      'receipt' => NULL,
    ], json_decode((string) $response->getContent(), TRUE));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  /**
   * Unknown, expired and malformed tokens all share one generic error.
   */
  public function testConfirmReturnsGenericInvalid(): void {
    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_INVALID),
      $this->allowedFlood(),
    );

    $response = $controller->confirm($this->jsonRequest('/api/v1/public/applications/confirm', ['token' => 'bad']));

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame(['error' => ['code' => 'confirmation.invalid']], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Confirmation claims are flood-limited by client IP.
   */
  public function testConfirmFloodLimit(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.confirm.ip', 30, 3600, 'ip:198.51.100.22')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_CONFIRMED),
      $flood,
    );

    $response = $controller->confirm($this->jsonRequest('/api/v1/public/applications/confirm', ['token' => str_repeat('A', 64)]));

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame(['error' => ['code' => 'rate_limit.exceeded']], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Resend accepts without revealing whether the pair matched.
   */
  public function testResendAlwaysAccepted(): void {
    $service = $this->tokenService(EmailConfirmationTokenService::STATUS_INVALID);
    $service->expectedResend = ['BEA-2026-00001', 'ada@example.org'];
    $controller = new EmailConfirmationController($service, $this->allowedFlood());

    $response = $controller->resend($this->jsonRequest('/api/v1/public/applications/confirm/resend', [
      'reference' => 'BEA-2026-00001',
      'email' => 'ada@example.org',
    ]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(['status' => 'accepted'], json_decode((string) $response->getContent(), TRUE));
    $this->assertTrue($service->resendCalled);
  }

  /**
   * Resend is flood-limited.
   */
  public function testResendFloodLimit(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.confirm_resend.ip', 3, 3600, 'ip:198.51.100.22')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_INVALID),
      $flood,
    );

    $response = $controller->resend($this->jsonRequest('/api/v1/public/applications/confirm/resend', [
      'reference' => 'BEA-2026-00001',
      'email' => 'ada@example.org',
    ]));

    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertSame(['error' => ['code' => 'rate_limit.exceeded']], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * A signed proxy request is flood-keyed per client, not on the proxy IP.
   */
  public function testConfirmHonorsTrustedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=s3cr3t');
    $identifier = str_repeat('a', 32);

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.confirm.ip', 30, 3600, 'proxy:' . $identifier)
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('beantragt_application.confirm.ip', 3600, 'proxy:' . $identifier);

    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_CONFIRMED),
      $flood,
    );

    $request = $this->jsonRequest('/api/v1/public/applications/confirm', ['token' => str_repeat('A', 64)]);
    $request->headers->set('X-Beantragt-Service-Authorization', 'Basic ' . base64_encode('nuxt_service:s3cr3t'));
    $request->headers->set('X-Beantragt-Rate-Limit-Identifier', $identifier);

    $response = $controller->confirm($request);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * A proxy identifier without a valid service secret is ignored.
   */
  public function testConfirmRejectsUnsignedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=s3cr3t');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.confirm.ip', 30, 3600, 'ip:198.51.100.22')
      ->willReturn(TRUE);

    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_CONFIRMED),
      $flood,
    );

    $request = $this->jsonRequest('/api/v1/public/applications/confirm', ['token' => str_repeat('A', 64)]);
    $request->headers->set('X-Beantragt-Service-Authorization', 'Basic ' . base64_encode('nuxt_service:wrong'));
    $request->headers->set('X-Beantragt-Rate-Limit-Identifier', str_repeat('a', 32));

    $response = $controller->confirm($request);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * A malformed proxy identifier from a trusted proxy falls back to the IP.
   */
  public function testConfirmRejectsMalformedProxyIdentifier(): void {
    putenv('NUXT_DRUPAL_SERVICE_PASSWORD=s3cr3t');

    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_application.confirm.ip', 30, 3600, 'ip:198.51.100.22')
      ->willReturn(TRUE);

    $controller = new EmailConfirmationController(
      $this->tokenService(EmailConfirmationTokenService::STATUS_CONFIRMED),
      $flood,
    );

    $request = $this->jsonRequest('/api/v1/public/applications/confirm', ['token' => str_repeat('A', 64)]);
    $request->headers->set('X-Beantragt-Service-Authorization', 'Basic ' . base64_encode('nuxt_service:s3cr3t'));
    $request->headers->set('X-Beantragt-Rate-Limit-Identifier', 'not-a-valid-identifier');

    $response = $controller->confirm($request);
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->previousEnv as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Builds a token service test double.
   */
  private function tokenService(string $status): EmailConfirmationTokenService {
    $application = $this->createStub(ApplicationInterface::class);
    return new class($status, $application) extends EmailConfirmationTokenService {

      /**
       * Whether resend was called.
       */
      public bool $resendCalled = FALSE;

      /**
       * @var array{string, string}|null
       */
      public ?array $expectedResend = NULL;

      /**
       * Constructs the token service test double.
       */
      public function __construct(
        private readonly string $status,
        private readonly ApplicationInterface $application,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function claim(string $token): array {
        return $this->status === self::STATUS_INVALID
          ? ['status' => self::STATUS_INVALID]
          : ['status' => $this->status, 'application' => $this->application];
      }

      /**
       * {@inheritdoc}
       */
      public function resend(string $referenceNumber, string $email): void {
        $this->resendCalled = TRUE;
        if ($this->expectedResend !== NULL) {
          Assert::assertSame($this->expectedResend, [$referenceNumber, $email]);
        }
      }

    };
  }

  /**
   * Builds an always-allowed flood mock.
   */
  private function allowedFlood(): FloodInterface {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    return $flood;
  }

  /**
   * Builds a JSON request.
   */
  private function jsonRequest(string $path, array $body): Request {
    return Request::create(
      $path,
      'POST',
      [],
      [],
      [],
      [
        'REMOTE_ADDR' => '198.51.100.22',
        'CONTENT_TYPE' => 'application/json',
      ],
      (string) json_encode($body, JSON_THROW_ON_ERROR),
    );
  }

}
