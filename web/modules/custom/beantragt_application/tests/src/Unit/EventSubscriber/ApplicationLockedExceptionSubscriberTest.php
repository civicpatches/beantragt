<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\EventSubscriber;

use Drupal\beantragt_application\EventSubscriber\ApplicationLockedExceptionSubscriber;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Exception\ApplicationLockedHttpException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the stable 423 answer for saves refused under the case lock.
 */
#[CoversClass(ApplicationLockedExceptionSubscriber::class)]
#[CoversClass(ApplicationLockedHttpException::class)]
#[Group('beantragt_application')]
final class ApplicationLockedExceptionSubscriberTest extends UnitTestCase {

  /**
   * Only a lock failure, wrapped or direct, becomes the 423 refusal.
   */
  public function testOnlyLockFailuresBecomeTheRefusal(): void {
    $lock = new ApplicationDeletionLockedException('The application is currently being deleted or delivered.');
    $wrapped = new EntityStorageException('Locked.', 0, $lock);
    foreach ([$wrapped, $lock] as $failure) {
      $refusal = ApplicationLockedHttpException::fromFailure($failure);
      $this->assertInstanceOf(ApplicationLockedHttpException::class, $refusal);
      $this->assertSame(423, $refusal->getStatusCode());
      $this->assertSame($failure, $refusal->getPrevious());
    }
    $other = new EntityStorageException('Disk full.');
    $this->assertSame($other, ApplicationLockedHttpException::fromFailure($other));
  }

  /**
   * The refusal renders JSON with the stable code; others stay with core.
   */
  public function testRendersTheStableCodeAndIgnoresOtherExceptions(): void {
    $subscriber = new ApplicationLockedExceptionSubscriber();
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new ExceptionEvent($kernel, Request::create('/api/admin/applications/1/status', 'PATCH'), HttpKernelInterface::MAIN_REQUEST,
      new ApplicationLockedHttpException());
    $subscriber->onException($event);
    $response = $event->getResponse();
    $this->assertNotNull($response);
    $this->assertSame(423, $response->getStatusCode());
    $this->assertSame([
      'message' => 'Application is currently being delivered or changed. Retry saving shortly.',
      'error' => ['code' => 'application.locked'],
    ], json_decode((string) $response->getContent(), TRUE));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

    $other = new ExceptionEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new EntityStorageException('Disk full.'));
    $subscriber->onException($other);
    $this->assertNull($other->getResponse());
  }

  /**
   * Refusals of the staff data edit route are JSON with the message.
   */
  public function testDataEditRefusalsAreJson(): void {
    $subscriber = new ApplicationLockedExceptionSubscriber();
    $kernel = $this->createMock(HttpKernelInterface::class);
    $request = static function (string $route): Request {
      $request = Request::create('/api/admin/applications/1/data', 'PATCH');
      $request->attributes->set('_route', $route);
      return $request;
    };
    foreach ([
      new UnprocessableEntityHttpException('Section "s", entry 1: Field "a" must be a string.'),
      new BadRequestHttpException('Application data must be an object.'),
      new ConflictHttpException('Application changed since it was loaded.'),
      new HttpException(428, 'Missing expected changed timestamp.'),
    ] as $refusal) {
      $event = new ExceptionEvent($kernel, $request(ApplicationLockedExceptionSubscriber::DATA_EDIT_ROUTE), HttpKernelInterface::MAIN_REQUEST, $refusal);
      $subscriber->onException($event);
      $response = $event->getResponse();
      $this->assertNotNull($response, $refusal->getMessage());
      $this->assertSame($refusal->getStatusCode(), $response->getStatusCode());
      $this->assertSame([
        'message' => $refusal->getMessage(),
        'error' => ['message' => $refusal->getMessage()],
      ], json_decode((string) $response->getContent(), TRUE));
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
    // Access denial stays with core; other routes stay with core.
    $denied = new ExceptionEvent($kernel, $request(ApplicationLockedExceptionSubscriber::DATA_EDIT_ROUTE), HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException());
    $subscriber->onException($denied);
    $this->assertNull($denied->getResponse());
    $other = new ExceptionEvent($kernel, $request('beantragt_application.admin_status_update'), HttpKernelInterface::MAIN_REQUEST, new UnprocessableEntityHttpException('x'));
    $subscriber->onException($other);
    $this->assertNull($other->getResponse());
    // The lock keeps its own code on the data route.
    $locked = new ExceptionEvent($kernel, $request(ApplicationLockedExceptionSubscriber::DATA_EDIT_ROUTE), HttpKernelInterface::MAIN_REQUEST, new ApplicationLockedHttpException());
    $subscriber->onException($locked);
    $this->assertSame('application.locked', json_decode((string) $locked->getResponse()->getContent(), TRUE)['error']['code']);
  }

}
