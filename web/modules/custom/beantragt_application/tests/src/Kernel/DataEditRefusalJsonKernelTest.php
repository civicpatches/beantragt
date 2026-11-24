<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\EventSubscriber\ApplicationLockedExceptionSubscriber;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A refused staff data edit reaches the Nuxt proxy as JSON with its reason.
 *
 * Dispatched through the container's event dispatcher, so core's own
 * exception subscribers (HTML 4xx page, final plain text) run as well.
 */
#[Group('beantragt_application')]
final class DataEditRefusalJsonKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * The 422 body is JSON with the validator's message as error.message.
   */
  public function testRefusalBodyIsJson(): void {
    $message = 'Section "s", entry 1: Hidden field "a" cannot be changed.';
    $request = Request::create('/api/admin/applications/1/data', 'PATCH');
    $request->attributes->set('_route', ApplicationLockedExceptionSubscriber::DATA_EDIT_ROUTE);
    $event = new ExceptionEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new UnprocessableEntityHttpException($message));
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::EXCEPTION);
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    $this->assertSame(['message' => $message, 'error' => ['message' => $message]], json_decode((string) $response->getContent(), TRUE));
  }

}
