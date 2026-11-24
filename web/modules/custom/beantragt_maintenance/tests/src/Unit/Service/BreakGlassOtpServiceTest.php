<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_maintenance\Unit\Service;

use Drupal\beantragt_maintenance\Service\BreakGlassOtpService;
use Drupal\beantragt_maintenance\Service\RecoveryCryptoInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the isolated break-glass OTP and recovery-token service.
 */
#[CoversClass(BreakGlassOtpService::class)]
#[Group('beantragt_maintenance')]
final class BreakGlassOtpServiceTest extends UnitTestCase {

  /**
   * Eligible maintenance accounts receive an isolated hashed code.
   */
  public function testCodeIsIssuedOnlyToPermittedAccount(): void {
    $user = $this->user(TRUE);
    $user_storage = $this->userStorage();
    $user_storage->expects($this->once())
      ->method('loadByProperties')
      ->with(['mail' => 'operator@example.com'])
      ->willReturn([$user]);
    $code_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $code_store->method('get')->willReturn([
      'uid' => 42,
      'email' => 'operator@example.com',
      'code' => 'expired-hash',
      'expires' => time() - 1,
    ]);
    $code_store->expects($this->once())
      ->method('setWithExpire')
      ->with(
        hash('sha256', 'operator@example.com'),
        $this->callback(static fn (array $record): bool => $record['uid'] === 42
          && $record['email'] === 'operator@example.com'
          && $record['code'] === 'hashed-code'),
        600,
      );
    $terminated = FALSE;
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->with(
        'beantragt_maintenance',
        'break_glass_code',
        'operator@example.com',
        'de',
        $this->callback(static function (array $params) use (&$terminated): bool {
          self::assertTrue($terminated, 'Mail must be sent during kernel termination.');
          return $params['code'] === '123456';
        }),
        NULL,
        TRUE,
      )
      ->willReturn(['result' => TRUE]);
    $crypto = $this->createMock(RecoveryCryptoInterface::class);
    $crypto->expects($this->once())->method('generateCode')->willReturn('123456');
    $crypto->expects($this->once())->method('hashCode')->with('123456')->willReturn('hashed-code');

    $service = $this->service(
      $user_storage,
      $code_store,
      mail: $mail,
      crypto: $crypto,
    );
    $result = $service->requestCode('operator@example.com');

    self::assertSame($this->genericResult(), $result);
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber($service);
    $terminated = TRUE;
    $dispatcher->dispatch(new \stdClass(), KernelEvents::TERMINATE);
    $dispatcher->dispatch(new \stdClass(), KernelEvents::TERMINATE);
  }

  /**
   * A failed transport allows another request to deliver a usable code.
   */
  #[DataProvider('mailFailureModes')]
  public function testMailFailureAllowsRetry(bool $throws): void {
    $user = $this->user(TRUE);
    $email = $user->getEmail();
    $user_storage = $this->userStorage();
    $user_storage->method('loadByProperties')->willReturn([$user]);
    $user_storage->method('load')->with(42)->willReturn($user);
    $records = [];
    $code_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $code_store->method('get')->willReturnCallback(static function (string $key) use (&$records): mixed {
      return $records[$key] ?? NULL;
    });
    $code_store->expects($this->exactly(4))->method('setWithExpire')
      ->willReturnCallback(static function (string $key, mixed $value, int $ttl) use (&$records): void {
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(600, $ttl);
        $records[$key] = $value;
      });

    $delivered = [];
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->exactly(2))->method('mail')
      ->willReturnCallback(static function ($module, $key, $to, $langcode, array $params) use (&$delivered, $throws): array {
        if ($params['code'] === '123456') {
          if ($throws) {
            throw new \RuntimeException('Transport unavailable');
          }
          return ['result' => FALSE];
        }
        $delivered[] = $params['code'];
        return ['result' => TRUE];
      });
    $token = str_repeat('T', 43);
    $crypto = $this->createMock(RecoveryCryptoInterface::class);
    $crypto->expects($this->exactly(2))->method('generateCode')->willReturnOnConsecutiveCalls('123456', '654321');
    $crypto->method('hashCode')->willReturnCallback(static fn (string $code): string => 'hash-for-' . $code);
    $crypto->method('verifyCode')
      ->willReturnCallback(static fn (string $code, string $hash): bool => $hash === 'hash-for-' . $code);
    $crypto->method('generateToken')->willReturn($token);

    $first_request = $this->service($user_storage, $code_store, mail: $mail, crypto: $crypto);
    self::assertSame($this->genericResult(), $first_request->requestCode($email));
    $first_request->onTerminate();
    self::assertSame([], $delivered);

    $retry = $this->service($user_storage, $code_store, mail: $mail, crypto: $crypto);
    self::assertSame($this->genericResult(), $retry->requestCode($email));
    $retry->onTerminate();
    self::assertSame(['654321'], $delivered);

    $later_request = $this->service($user_storage, $code_store, mail: $mail, crypto: $crypto);
    self::assertSame($this->genericResult(), $later_request->requestCode($email));
    $later_request->onTerminate();
    self::assertNull($later_request->verifyCode($email, '123456'));
    self::assertSame(['recoveryToken' => $token, 'expiresIn' => 300], $later_request->verifyCode($email, $delivered[0]));
  }

  /**
   * Provides Drupal transport error outcomes.
   */
  public static function mailFailureModes(): array {
    return [
      'false result' => [FALSE],
      'exception' => [TRUE],
    ];
  }

  /**
   * Unknown and nonpermitted accounts receive the same generic response.
   */
  public function testUnknownAndNonpermittedAccountsStayGeneric(): void {
    foreach ([[], [$this->user(FALSE)]] as $users) {
      $user_storage = $this->userStorage();
      $user_storage->expects($this->once())
        ->method('loadByProperties')
        ->willReturn($users);
      $code_store = $this->createMock(KeyValueStoreExpirableInterface::class);
      $code_store->expects($this->never())->method('setWithExpire');
      $mail = $this->createMock(MailManagerInterface::class);
      $mail->expects($this->never())->method('mail');
      $crypto = $this->createMock(RecoveryCryptoInterface::class);
      $crypto->expects($this->once())
        ->method('hashCode')
        ->with('000000')
        ->willReturn('dummy-hash');

      $service = $this->service(
        $user_storage,
        $code_store,
        mail: $mail,
        crypto: $crypto,
      );
      $result = $service->requestCode('unknown@example.com');
      $service->onTerminate();

      self::assertSame($this->genericResult(), $result);
    }
  }

  /**
   * Missing-code verification performs the timing-safe dummy comparison.
   */
  public function testMissingCodeCallsTimingDummyVerifier(): void {
    $code_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $code_store->expects($this->once())
      ->method('get')
      ->with(hash('sha256', 'unknown@example.com'))
      ->willReturn(NULL);
    $crypto = $this->createMock(RecoveryCryptoInterface::class);
    $crypto->expects($this->once())
      ->method('verifyCode')
      ->with('000000', BreakGlassOtpService::DUMMY_HASH)
      ->willReturn(FALSE);

    $result = $this->service(
      $this->userStorage(),
      $code_store,
      crypto: $crypto,
    )->verifyCode('unknown@example.com', '123456');

    self::assertNull($result);
  }

  /**
   * A code survives 24 misses and is replaced on request after the 25th.
   */
  #[TestWith([24])]
  #[TestWith([25])]
  public function testRecoveryCodeAttemptCeiling(int $guesses): void {
    $user = $this->user(TRUE);
    $email = $user->getEmail();
    $user_storage = $this->userStorage();
    $user_storage->method('loadByProperties')->willReturn([$user]);
    $user_storage->expects($this->once())->method('load')->with(42)->willReturn($user);
    $request_time = time();
    $expires = $request_time + 599;
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($request_time);
    $time->method('getCurrentTime')->willReturn($request_time + 2);
    // Codes issued before the attempt ceiling lack the counter.
    $record = [
      'uid' => 42,
      'email' => $email,
      'code' => 'hash-for-123456',
      'expires' => $expires,
    ];
    $key = hash('sha256', $email);
    $code_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $code_store->method('get')->willReturnCallback(static function (string $name) use (&$record, $key): ?array {
      return $name === $key ? $record : NULL;
    });
    $code_store->method('setWithExpire')->willReturnCallback(
      static function (string $name, array $value, int $ttl) use (&$record, $key, $request_time): void {
        self::assertSame($key, $name);
        self::assertSame($value['expires'], $request_time + $ttl);
        $record = $value;
      },
    );
    $code_store->method('delete')->with($key)->willReturnCallback(static function () use (&$record): void {
      $record = NULL;
    });
    $token = str_repeat('T', 43);
    $token_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $token_store->expects($this->once())
      ->method('setWithExpire')
      ->with(
        hash('sha256', $token),
        $this->callback(static fn (array $token_record): bool => $token_record['uid'] === 42),
        300,
      );
    $crypto = $this->createMock(RecoveryCryptoInterface::class);
    $crypto->expects($this->exactly($guesses === 25 ? 1 : 0))->method('generateCode')->willReturn('654321');
    $crypto->method('hashCode')->willReturnCallback(static fn (string $code): string => 'hash-for-' . $code);
    $crypto->method('verifyCode')
      ->willReturnCallback(static fn (string $code, string $hash): bool => $hash === 'hash-for-' . $code);
    $crypto->expects($this->once())->method('generateToken')->willReturn($token);

    $delivered = [];
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(
      static function ($module, $key, $to, $langcode, array $params) use (&$delivered): array {
        $delivered[] = $params['code'];
        return ['result' => TRUE];
      },
    );
    $service = $this->service($user_storage, $code_store, $token_store, $mail, $crypto, $time);
    for ($attempt = 0; $attempt < $guesses; $attempt++) {
      self::assertNull($service->verifyCode($email, '111111'));
    }
    if ($guesses === 25) {
      self::assertNull($record);
    }
    self::assertSame($this->genericResult(), $service->requestCode($email));
    self::assertSame([], $delivered);
    $service->onTerminate();

    $valid_code = '123456';
    if ($guesses === 25) {
      self::assertSame(['654321'], $delivered);
      self::assertSame(0, $record['attempts']);
      self::assertNull($service->verifyCode($email, $valid_code));
      $valid_code = $delivered[0];
    }
    else {
      self::assertSame([], $delivered);
      self::assertSame(24, $record['attempts']);
      self::assertSame($expires, $record['expires']);
    }
    $result = $service->verifyCode($email, $valid_code);

    self::assertSame([
      'recoveryToken' => $token,
      'expiresIn' => 300,
    ], $result);
  }

  /**
   * A maintenance transition removes all credentials from the prior cycle.
   */
  public function testMaintenanceTransitionInvalidatesAllRecoveryArtifacts(): void {
    $code_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $code_store->expects($this->once())->method('deleteAll');
    $token_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $token_store->expects($this->once())->method('deleteAll');

    $this->service(
      $this->userStorage(),
      $code_store,
      $token_store,
    )->invalidateRecoveryArtifacts();
  }

  /**
   * Creates the service with mocked infrastructure.
   */
  private function service(
    EntityStorageInterface $userStorage,
    KeyValueStoreExpirableInterface $codeStore,
    ?KeyValueStoreExpirableInterface $tokenStore = NULL,
    ?MailManagerInterface $mail = NULL,
    ?RecoveryCryptoInterface $crypto = NULL,
    ?TimeInterface $time = NULL,
  ): BreakGlassOtpService {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnMap([
      [BreakGlassOtpService::PLATFORM_MAINTENANCE_STATE, FALSE, TRUE],
      ['system.maintenance_mode', FALSE, FALSE],
    ]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($userStorage);
    $tokenStore ??= $this->createMock(KeyValueStoreExpirableInterface::class);
    $key_value = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value->method('get')->willReturnCallback(
      static fn (string $collection): KeyValueStoreExpirableInterface => match ($collection) {
        BreakGlassOtpService::CODE_COLLECTION => $codeStore,
        BreakGlassOtpService::TOKEN_COLLECTION => $tokenStore,
      },
    );
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['code_lifetime', 600],
    ]);
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->with('name')->willReturn('beantragt.ai');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturnMap([
      ['beantragt_passwordless.settings', $settings],
      ['system.site', $site],
    ]);
    if ($time === NULL) {
      $time = $this->createMock(TimeInterface::class);
      $time->method('getRequestTime')->willReturn(time());
      $time->method('getCurrentTime')->willReturnCallback('time');
    }

    return new BreakGlassOtpService(
      $state,
      $entity_type_manager,
      $key_value,
      $lock,
      $mail ?? $this->createMock(MailManagerInterface::class),
      $config_factory,
      $this->createMock(LoggerInterface::class),
      $crypto ?? $this->createMock(RecoveryCryptoInterface::class),
      $time,
    );
  }

  /**
   * Creates a user storage mock.
   */
  private function userStorage(): EntityStorageInterface {
    return $this->createMock(EntityStorageInterface::class);
  }

  /**
   * Creates an active account with configurable maintenance permission.
   */
  private function user(bool $permitted): UserInterface {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn(42);
    $user->method('getEmail')->willReturn('operator@example.com');
    $user->method('getPreferredLangcode')->willReturn('de');
    $user->method('isActive')->willReturn(TRUE);
    $user->method('hasPermission')
      ->with('access site in maintenance mode')
      ->willReturn($permitted);
    return $user;
  }

  /**
   * Returns the stable request response shared by all account states.
   *
   * @return array{success: true, message: string, expiresIn: int, codeLength: int}
   *   Generic request response.
   */
  private function genericResult(): array {
    return [
      'success' => TRUE,
      'message' => 'If the account is eligible, a recovery code has been sent.',
      'expiresIn' => 600,
      'codeLength' => 6,
    ];
  }

}
