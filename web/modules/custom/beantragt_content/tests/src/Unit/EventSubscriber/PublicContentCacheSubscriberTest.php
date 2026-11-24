<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\EventSubscriber;

use Drupal\beantragt_content\EventSubscriber\PublicContentCacheSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests final public cache headers for public content routes.
 */
#[CoversClass(PublicContentCacheSubscriber::class)]
#[Group('beantragt_content')]
final class PublicContentCacheSubscriberTest extends UnitTestCase {

  /**
   * Public content list routes get shared-cache headers after finalization.
   */
  public function testPublicContentRouteGetsPublicCacheHeader(): void {
    $request = Request::create('/api/v1/jurisdictions/musterstadt/sections');
    $request->attributes->set('_route', 'beantragt_content.public_sections');
    $response = new Response('{}', Response::HTTP_OK, [
      'Cache-Control' => 'must-revalidate, no-cache, private',
      'Pragma' => 'no-cache',
    ]);

    (new PublicContentCacheSubscriber())->onResponse($this->responseEvent($request, $response));

    $cache_control = (string) $response->headers->get('Cache-Control');
    $this->assertStringContainsString('public', $cache_control);
    $this->assertStringContainsString('max-age=300', $cache_control);
    $this->assertFalse($response->headers->has('Pragma'));
    $this->assertNotNull($response->getExpires());
  }

  /**
   * Non-content routes are left untouched.
   */
  public function testNonContentRouteIsLeftUntouched(): void {
    $request = Request::create('/api/v1/status/token');
    $request->attributes->set('_route', 'beantragt_status.public_status_v1');
    $response = new Response('{}', Response::HTTP_OK, [
      'Cache-Control' => 'private, no-store',
    ]);

    (new PublicContentCacheSubscriber())->onResponse($this->responseEvent($request, $response));

    $cache_control = (string) $response->headers->get('Cache-Control');
    $this->assertStringContainsString('private', $cache_control);
    $this->assertStringContainsString('no-store', $cache_control);
  }

  /**
   * Error responses keep their original cache policy.
   */
  public function testContentErrorResponseIsLeftUntouched(): void {
    $request = Request::create('/api/v1/jurisdictions/musterstadt/sections');
    $request->attributes->set('_route', 'beantragt_content.public_sections');
    $response = new Response('{}', Response::HTTP_NOT_FOUND, [
      'Cache-Control' => 'must-revalidate, no-cache, private',
    ]);

    (new PublicContentCacheSubscriber())->onResponse($this->responseEvent($request, $response));

    $this->assertSame('must-revalidate, no-cache, private', $response->headers->get('Cache-Control'));
  }

  /**
   * Builds a kernel response event.
   */
  private function responseEvent(Request $request, Response $response): ResponseEvent {
    return new ResponseEvent(
      $this->createMock(KernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
  }

}
