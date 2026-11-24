<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\EventSubscriber;

use Drupal\beantragt_delivery\EventSubscriber\IntegrationExceptionSubscriber;
use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\EventSubscriber\ExceptionLoggingSubscriber;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures safe responses do not hide exceptions from Drupal's real logger.
 */
#[Group('beantragt_delivery')]
final class IntegrationExceptionSubscriberTest extends UnitTestCase {

  /**
   * Core logs the original failure before the integration response is set.
   */
  public function testCoreLoggingRunsBeforeSanitization(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects(self::once())->method('log')->with(self::anything(), self::anything(), self::callback(static function (array $context): bool {
      return str_contains((string) ($context['@message'] ?? ''), 'Synthetic internal failure');
    }));
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new ExceptionLoggingSubscriber($factory));
    $dispatcher->addSubscriber($this->subscriber());
    $event = $this->event(new \RuntimeException('Synthetic internal failure'));
    $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    self::assertSame(500, $event->getResponse()->getStatusCode());
    self::assertStringContainsString('integration.internal_error', $event->getResponse()->getContent());
    self::assertStringNotContainsString('Synthetic internal failure', $event->getResponse()->getContent());
  }

  /**
   * Status codes and meaningful protocol headers survive JSON conversion.
   */
  public function testHttpErrorsPreserveProtocolHeaders(): void {
    foreach ([
      [400, 'integration.bad_request', []],
      [405, 'integration.method_not_allowed', ['Allow' => 'GET']],
      [406, 'integration.json_required', []],
      [415, 'integration.json_required', []],
      [429, 'integration.rate_limited', ['Retry-After' => '60']],
    ] as [$status, $code, $headers]) {
      $event = $this->event(new HttpException($status, 'Synthetic private detail', NULL, $headers));
      $this->subscriber()->onException($event);
      $response = $event->getResponse();
      self::assertSame($status, $response->getStatusCode());
      self::assertStringContainsString($code, $response->getContent());
      self::assertStringNotContainsString('Synthetic private detail', $response->getContent());
      self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
      foreach ($headers as $name => $value) {
        self::assertSame($value, $response->headers->get($name));
      }
    }
  }

  /**
   * Transfer errors have the same safe projection as management errors.
   */
  public function testTransferErrorsAreSanitizedAndUncacheable(): void {
    foreach (['transfer_list', 'transfer_approve', 'transfer_retry'] as $route) {
      $event = $this->event(new HttpException(403, 'Synthetic private detail'));
      $event->getRequest()->attributes->set('_route', 'beantragt_delivery.' . $route);
      $this->subscriber()->onException($event);
      $response = $event->getResponse();
      self::assertSame(403, $response->getStatusCode());
      self::assertStringNotContainsString('Synthetic private detail', $response->getContent());
      self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
  }

  /**
   * A shared URL prefix never captures routes owned by another module.
   */
  public function testOnlyOwnIntegrationRoutesAreHandled(): void {
    $event = $this->event(new \RuntimeException('Synthetic'));
    $event->getRequest()->attributes->set('_route', 'another_module.integration');
    $this->subscriber()->onException($event);
    self::assertNull($event->getResponse());
    $event->getRequest()->attributes->remove('_route');
    $this->subscriber()->onException($event);
    self::assertNull($event->getResponse());
  }

  /**
   * Builds the production subscriber with non-persistent audit dependencies.
   */
  private function subscriber(): IntegrationExceptionSubscriber {
    $audit = new IntegrationAudit(new NullLogger(), $this->createMock(TimeInterface::class), $this->createMock(AccountProxyInterface::class));
    return new IntegrationExceptionSubscriber($audit);
  }

  /**
   * Creates a framework exception event for the actual integration route name.
   */
  private function event(\Throwable $exception): ExceptionEvent {
    $request = Request::create('/api/admin/integrations/connections');
    $request->attributes->set('_route', 'beantragt_delivery.integration_connection_list');
    return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $exception);
  }

}
