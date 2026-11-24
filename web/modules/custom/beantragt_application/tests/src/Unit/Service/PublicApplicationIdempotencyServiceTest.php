<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\PublicApplicationIdempotencyService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests public application idempotency replay handling.
 */
#[CoversClass(PublicApplicationIdempotencyService::class)]
#[Group('beantragt_application')]
final class PublicApplicationIdempotencyServiceTest extends UnitTestCase {

  /**
   * Expirable store mock.
   */
  private KeyValueStoreExpirableInterface $store;

  /**
   * Lock backend mock.
   */
  private LockBackendInterface $lock;

  /**
   * Time service mock.
   */
  private TimeInterface $time;

  /**
   * Service under test.
   */
  private PublicApplicationIdempotencyService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    new Settings(['hash_salt' => 'beantragt-public-idempotency-test-salt']);
    $this->store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1781431200);
    $this->service = new PublicApplicationIdempotencyService($this->store, $this->lock, $this->time);
  }

  /**
   * Payload hashes are stable for equivalent JSON object key ordering.
   */
  public function testContextBuildsStablePayloadHash(): void {
    $request = $this->jsonRequest(['Idempotency-Key' => str_repeat('fixture-', 4)]);
    $first = $this->service->context(
      $request,
      [
        'data' => [
          'attributes' => [
            'kind' => 'A',
            'details' => 'Only for A',
          ],
          'type' => 'application',
        ],
      ],
    );
    $second = $this->service->context(
      $request,
      [
        'data' => [
          'type' => 'application',
          'attributes' => [
            'details' => 'Only for A',
            'kind' => 'A',
          ],
        ],
      ],
    );

    $this->assertNull($first['error']);
    $this->assertSame($first['payloadHash'], $second['payloadHash']);
    $this->assertSame(hash('sha256', str_repeat('fixture-', 4)), $first['keyHash']);
  }

  /**
   * Invalid keys are rejected before any store interaction.
   */
  public function testContextRejectsInvalidIdempotencyKey(): void {
    $this->store->expects($this->never())->method('get');

    $context = $this->service->context(
      $this->jsonRequest(['Idempotency-Key' => 'unsafe key']),
      ['data' => ['type' => 'application']],
    );

    $this->assertSame('idempotency.invalid_key', $context['error']['code']);
    $this->assertSame(Response::HTTP_BAD_REQUEST, $context['error']['status']);
  }

  /**
   * Stored replay records encrypt and decrypt bearer status values.
   */
  public function testStoreResponseEncryptsReplayTokensAndReplayDecryptsThem(): void {
    $context = $this->scopedContext();
    $records = [];
    $this->store->method('get')
      ->willReturnCallback(static function (string $key, mixed $default = NULL) use (&$records): mixed {
        return $records[$key] ?? $default;
      });
    $this->store->expects($this->once())
      ->method('setWithExpire')
      ->willReturnCallback(
        static function (string $key, array $record, int $expire) use (&$records): void {
          self::assertSame(900, $expire);
          self::assertIsArray($record['response']['statusToken']);
          self::assertIsArray($record['response']['statusUrl']);
          self::assertTrue($record['response']['statusToken']['__beantragt_encrypted_idempotency_status_token']);
          self::assertTrue($record['response']['statusUrl']['__beantragt_encrypted_idempotency_status_token']);
          self::assertStringNotContainsString('status-token', json_encode($record, JSON_THROW_ON_ERROR));
          $records[$key] = $record;
        }
      );

    $this->service->storeResponse(
      $context,
      [
        'id' => 'application-uuid',
        'statusToken' => 'status-token',
        'statusUrl' => '/api/v1/status/status-token',
      ],
      Response::HTTP_CREATED,
    );
    $replay = $this->service->replay($context);

    $this->assertNull($replay['error']);
    $this->assertSame(Response::HTTP_CREATED, $replay['status']);
    $this->assertSame('status-token', $replay['response']['statusToken']);
    $this->assertSame('/api/v1/status/status-token', $replay['response']['statusUrl']);
  }

  /**
   * Reusing a key for a different payload fails closed.
   */
  public function testReplayRejectsPayloadConflict(): void {
    $context = $this->scopedContext();
    $this->store->method('get')->willReturn(
      [
        'payloadHash' => 'different',
        'response' => [],
        'status' => Response::HTTP_CREATED,
      ],
    );

    $replay = $this->service->replay($context);

    $this->assertSame('idempotency.conflict', $replay['error']['code']);
    $this->assertSame(Response::HTTP_CONFLICT, $replay['error']['status']);
    $this->assertNull($replay['response']);
  }

  /**
   * Lock lifecycle delegates to the lock backend with scoped lock names.
   */
  public function testAcquireAndReleaseLock(): void {
    $context = $this->scopedContext();
    $this->lock->expects($this->once())
      ->method('acquire')
      ->with($this->stringStartsWith('beantragt_application.idempotency.'), 30.0)
      ->willReturn(TRUE);
    $this->lock->expects($this->once())
      ->method('release')
      ->with($this->stringStartsWith('beantragt_application.idempotency.'));

    $lock_name = $this->service->acquireLock($context);
    $this->assertIsString($lock_name);

    $this->service->releaseLock($lock_name);
  }

  /**
   * Builds a scoped idempotency context.
   *
   * @return array<string, mixed>
   *   Scoped idempotency context.
   */
  private function scopedContext(): array {
    $context = $this->service->context(
      $this->jsonRequest(['Idempotency-Key' => str_repeat('fixture-', 4)]),
      ['data' => ['type' => 'application']],
    );
    return $this->service->scopedContext(
      $context,
      'ip:198.51.100.22',
      'fbc3b086-ac53-412e-9bbf-ccb03f286923',
      NULL,
    );
  }

  /**
   * Creates a JSON request.
   */
  private function jsonRequest(array $headers): Request {
    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = $value;
    }
    return Request::create(
      '/api/v1/applications',
      'POST',
      [],
      [],
      [],
      $server,
      '{}',
    );
  }

}
