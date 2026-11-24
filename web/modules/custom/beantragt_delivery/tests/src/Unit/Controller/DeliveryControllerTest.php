<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Controller;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Controller\DeliveryController;
use Drupal\beantragt_delivery\Service\DeliveryDispatcher;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\Tests\UnitTestCase;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests delivery controller security gates.
 */
#[CoversClass(DeliveryController::class)]
#[Group('beantragt_delivery')]
final class DeliveryControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('BEANTRAGT_ENV');
    putenv('IS_DDEV_PROJECT');
    parent::tearDown();
  }

  /**
   * Mock delivery may not mutate delivery state in production.
   */
  public function testMockDeliveryEndpointIsBlockedInProduction(): void {
    putenv('BEANTRAGT_ENV=production');

    $controller = new DeliveryController(
      $this->unconstructed(DeliveryLogRepository::class),
      $this->unconstructed(DeliveryDispatcher::class),
      $this->unconstructed(ScopeAccess::class),
    );

    $response = $controller->enqueueMock(
      $this->createMock(ApplicationInterface::class),
      Request::create(
        '/api/admin/applications/1/deliveries/mock',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['process' => TRUE], JSON_THROW_ON_ERROR),
      ),
    );

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('delivery.mock_not_allowed', $payload['error']['code']);
  }

  /**
   * Explicit production mode wins over accidental local DDEV flags.
   */
  public function testMockDeliveryEndpointBlocksProductionEvenWithDdevFlag(): void {
    putenv('BEANTRAGT_ENV=production');
    putenv('IS_DDEV_PROJECT=true');

    $controller = new DeliveryController(
      $this->unconstructed(DeliveryLogRepository::class),
      $this->unconstructed(DeliveryDispatcher::class),
      $this->unconstructed(ScopeAccess::class),
    );

    $response = $controller->enqueueMock(
      $this->createMock(ApplicationInterface::class),
      Request::create('/api/admin/applications/1/deliveries/mock', 'POST'),
    );

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Creates an instance without running a constructor.
   *
   * @param class-string<object> $class
   *   Class name.
   *
   * @return object
   *   Reflected instance.
   */
  private function unconstructed(string $class): object {
    return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
  }

}
