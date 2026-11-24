<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_passwordless\Kernel;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_passwordless\Service\OtpService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Real OTP storage and claims; session and mail side effects are test doubles.
 */
#[Group('beantragt_passwordless')]
final class LoginOtpKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $database = $this->container->get('database');
    if (!in_array($database->databaseType(), ['mysql', 'pgsql'], TRUE)) {
      $this->markTestSkipped('OTP row-lock tests require MySQL or PostgreSQL.');
    }
    // Exercise only this service, without bootstrapping application workflows.
    $loader = require dirname(DRUPAL_ROOT) . '/vendor/autoload.php';
    foreach (['beantragt_passwordless', 'beantragt_applicant'] as $module) {
      $loader->addPsr4('Drupal\\' . $module . '\\', DRUPAL_ROOT . '/modules/custom/' . $module . '/src');
    }
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_passwordless/beantragt_passwordless.install';
    $database->schema()->createTable('beantragt_passwordless_codes', beantragt_passwordless_schema()['beantragt_passwordless_codes']);
  }

  /**
   * All pending rows share the failed-guess budget.
   */
  public function testTwoPendingRowsShareTheAttemptLimit(): void {
    $this->insertCode('111111');
    $this->insertCode('222222');
    $service = $this->service($this->container->get('database'));
    for ($attempt = 0; $attempt < 3; $attempt++) {
      self::assertFalse($service->verifyCode('citizen@example.test', '999999')['success']);
    }
    $counts = $this->container->get('database')->select('beantragt_passwordless_codes', 'c')->fields('c', ['attempts'])->execute()->fetchCol();
    self::assertSame([3, 3], array_map('intval', $counts));
    self::assertFalse($service->verifyCode('citizen@example.test', '111111')['success']);
    self::assertFalse($service->verifyCode('citizen@example.test', '222222')['success']);
    self::assertSame(0, $service->finalizations);
  }

  /**
   * The final allowed guess can succeed, and an OTP is never reusable.
   */
  public function testLastAllowedGuessCanBeClaimedExactlyOnce(): void {
    $id = $this->insertCode('123456', 2);
    $service = $this->service($this->container->get('database'));
    self::assertTrue($service->verifyCode('citizen@example.test', '123456')['success']);
    self::assertFalse($service->verifyCode('citizen@example.test', '123456')['success']);
    self::assertSame(1, $service->finalizations);
    $row = $this->container->get('database')->select('beantragt_passwordless_codes', 'c')->fields('c')->condition('id', $id)->execute()->fetchObject();
    self::assertSame(3, (int) $row->attempts);
    self::assertSame(1, (int) $row->verified);
  }

  /**
   * Issuing a code invalidates older rows only, and uses the dummy hash cost.
   */
  public function testNewRequestReplacesOnlyOlderCodes(): void {
    $old = $this->insertCode('111111');
    $messages = [];
    $service = $this->service($this->container->get('database'), $messages);
    self::assertTrue($service->requestCode('citizen@example.test', 'de')['success']);
    self::assertCount(1, $messages);
    $rows = $this->container->get('database')->select('beantragt_passwordless_codes', 'c')->fields('c')->orderBy('id')->execute()->fetchAll();
    self::assertSame($old, (int) $rows[0]->id);
    self::assertSame(2, (int) $rows[0]->verified);
    self::assertSame(0, (int) $rows[1]->verified);
    self::assertSame(12, password_get_info($rows[1]->code)['options']['cost']);
    self::assertFalse($service->verifyCode('citizen@example.test', '111111')['success']);
    self::assertTrue($service->verifyCode('citizen@example.test', $messages[0])['success']);
  }

  /**
   * Empty queries release their transaction, real pending rows remain locked.
   */
  public function testOnlyPendingIdentityKeepsTransactionDuringBcrypt(): void {
    $this->insertCode('123456');
    $service = $this->service($this->container->get('database'));
    self::assertFalse($service->verifyCode('unknown@example.test', '999999')['success']);
    self::assertFalse($service->lockedDuringVerify);
    self::assertFalse($service->verifyCode('citizen@example.test', '999999')['success']);
    self::assertTrue($service->lockedDuringVerify);
  }

  /**
   * Missing, expired and exhausted codes disclose the same failure.
   */
  public function testIneligibleCodesHaveTheSameResponse(): void {
    $id = $this->insertCode('123456', 0, -1);
    $database = $this->container->get('database');
    $service = $this->service($database);
    $unknown = $service->verifyCode('unknown@example.test', '123456');
    self::assertSame($unknown, $service->verifyCode('citizen@example.test', '123456'));
    $database->update('beantragt_passwordless_codes')->fields(['expires' => time() + 600, 'attempts' => 3])->condition('id', $id)->execute();
    self::assertSame($unknown, $service->verifyCode('citizen@example.test', '123456'));
    self::assertFalse($unknown['success']);
    self::assertSame(0, $service->finalizations);
  }

  /**
   * Builds the real service with harmless session and mail side effects.
   *
   * Public so the isolated parallel/timing harness can reuse this fixture.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Actual test database connection.
   * @param string[]|null $messages
   *   Captured test-only codes, never written to logs.
   *
   * @return \Drupal\beantragt_passwordless\Service\OtpService
   *   The real OTP implementation with fake authentication side effects.
   */
  public function service(Connection $database, ?array &$messages = NULL): OtpService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['code_length', 6], ['code_lifetime', 600], ['max_attempts', 3], ['auto_register', TRUE],
    ]);
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->willReturn('Test platform');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['beantragt_passwordless.settings', $config], ['system.site', $site],
    ]);
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(static function ($module, $key, $to, $langcode, $params) use (&$messages): array {
      $messages[] = $params['code'];
      return ['result' => TRUE];
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $service = new class($database, $mail, $this->createMock(AccountProxyInterface::class), $this->createMock(LoggerInterface::class), $factory, $manager, $this->createMock(LanguageManagerInterface::class), $this->createMock(ApplicantManager::class)) extends OtpService {

      /**
       * Whether a real transaction surrounds the most recent bcrypt call.
       */
      public ?bool $lockedDuringVerify = NULL;

      /**
       * Observe the actual connection while retaining real bcrypt execution.
       */
      protected function verifyCodeHash(string $code, string $hash): bool {
        $this->lockedDuringVerify = $this->database->inTransaction();
        return parent::verifyCodeHash($code, $hash);
      }

      /**
       * The harmless authenticated test account.
       */
      public User $authenticated;

      /**
       * How many committed OTP claims reached session finalization.
       */
      public int $finalizations = 0;

      /**
       * {@inheritdoc}
       */
      protected function authenticateUser(string $email, bool $finalize_login = TRUE): ?User {
        return $this->authenticated;
      }

      /**
       * Encryption ordering is covered by the existing service unit tests.
       */
      protected function assertApplicantEncryptionRuntimeAvailable(): void {}

      /**
       * Count the session boundary without touching any actual user session.
       */
      protected function finalizeLogin(User $user): void {
        $this->finalizations++;
      }

    };
    $user = $this->createMock(User::class);
    $user->method('id')->willReturn(1);
    $user->method('uuid')->willReturn('00000000-0000-4000-8000-000000000001');
    $user->method('getAccountName')->willReturn('Test applicant');
    $user->method('getEmail')->willReturn('citizen@example.test');
    $user->method('getRoles')->willReturn(['authenticated']);
    $service->authenticated = $user;
    return $service;
  }

  /**
   * Inserts a pending test code with an optional attempt count and expiry.
   */
  private function insertCode(string $code, int $attempts = 0, int $lifetime = 600): int {
    return (int) $this->container->get('database')->insert('beantragt_passwordless_codes')->fields([
      'email' => 'citizen@example.test',
      'code' => password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]),
      'attempts' => $attempts,
      'created' => time(),
      'expires' => time() + $lifetime,
      'verified' => 0,
    ])->execute();
  }

}
