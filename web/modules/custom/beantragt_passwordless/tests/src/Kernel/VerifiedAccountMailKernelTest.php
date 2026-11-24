<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_passwordless\Kernel;

use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_passwordless\Service\OtpService;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\NullLogger;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies persisted mailbox provenance using the real database.
 *
 * @group beantragt_passwordless
 */
#[Group('beantragt_passwordless')]
final class VerifiedAccountMailKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'beantragt_passwordless'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('beantragt_applicant.manager', ApplicantManager::class)->setSynthetic(TRUE);
    $container->register('beantragt_application.retention_cleanup', ApplicantManager::class)->setSynthetic(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('beantragt_passwordless', ['beantragt_verified_account_mail', 'beantragt_passwordless_codes']);
  }

  /**
   * Normalizes the current address and rejects mismatched proof.
   */
  public function testVerificationTracksCurrentNormalisedAddress(): void {
    $account = $this->account('Ada@Example.Test');
    $mail = $this->container->get('beantragt_passwordless.verified_mail');
    $mail->verify($account, 'ada@example.test', 'passwordless_otp');
    $this->assertSame('ada@example.test', $mail->getVerifiedEmail($account));
    $this->expectException(\InvalidArgumentException::class);
    $mail->verify($account, 'other@example.test', 'passwordless_otp');
  }

  /**
   * Invalidates verification on address change and account deletion.
   */
  public function testAddressChangeAndDeletionClearProvenance(): void {
    $account = $this->account('ada@example.test');
    $mail = $this->container->get('beantragt_passwordless.verified_mail');
    $mail->verify($account, 'ada@example.test', 'passwordless_otp');
    $account->setEmail('new@example.test');
    $account->save();
    $this->assertNull($mail->getVerifiedEmail($account));
    $mail->verify($account, 'new@example.test', 'passwordless_otp');
    $uid = (int) $account->id();
    $account->delete();
    $this->assertSame(0, (int) $this->container->get('database')->select('beantragt_verified_account_mail')->condition('uid', $uid)->countQuery()->execute()->fetchField());
  }

  /**
   * Does not invent historical verification on a repeated upgrade.
   */
  public function testUpgradeIsIdempotentAndDoesNotInferMail(): void {
    $account = $this->account('legacy@example.test');
    $this->container->get('module_handler')->loadInclude('beantragt_passwordless', 'install');
    beantragt_passwordless_update_10003();
    beantragt_passwordless_update_10003();
    $this->assertNull($this->container->get('beantragt_passwordless.verified_mail')->getVerifiedEmail($account));
  }

  /**
   * Verification changes invalidate cached decisions for the account.
   */
  public function testVerificationInvalidatesAccountCacheTag(): void {
    $account = $this->account('cache@example.test');
    $cache = $this->container->get('cache.default');
    $tag = 'user:' . $account->id();
    $mail = $this->container->get('beantragt_passwordless.verified_mail');
    $cache->set('mail-proof', 'old-denial', -1, [$tag]);
    $mail->verify($account, 'cache@example.test', 'passwordless_otp');
    $this->assertFalse($cache->get('mail-proof'));
    $cache->set('mail-proof', 'old-allow', -1, [$tag]);
    $mail->invalidate($account);
    $this->assertFalse($cache->get('mail-proof'));
  }

  /**
   * A real successful code reservation establishes persisted mailbox proof.
   */
  public function testSuccessfulCodeLoginPersistsProvenance(): void {
    $account = $this->account('otp@example.test');
    $database = $this->container->get('database');
    $database->insert('beantragt_passwordless_codes')->fields([
      'email' => 'otp@example.test',
      'code' => password_hash('741852', PASSWORD_BCRYPT),
      'created' => time(),
      'expires' => time() + 300,
      'verified' => 0,
      'attempts' => 0,
    ])->execute();
    $mail = $this->container->get('beantragt_passwordless.verified_mail');
    $service = new class(
      $database, $this->createMock(MailManagerInterface::class),
      $this->container->get('current_user'), new NullLogger(),
      $this->container->get('config.factory'), $this->container->get('entity_type.manager'),
      $this->container->get('language_manager'), $this->createMock(ApplicantManager::class),
      NULL, $mail,
    ) extends OtpService {

      /**
       * Test fixture has no encrypted applicant entity to preflight.
       */
      protected function assertApplicantEncryptionRuntimeAvailable(): void {}

      /**
       * Records a session without an HTTP request in this kernel test.
       */
      protected function finalizeLogin(User $user): void {
        $this->currentUser->setAccount($user);
      }

    };
    $this->assertNull($mail->getVerifiedEmail($account));
    $result = $service->verifyCode('otp@example.test', '741852');
    $this->assertTrue($result['success']);
    $this->assertSame('otp@example.test', $mail->getVerifiedEmail($account));
    $this->assertSame('1', (string) $database->select('beantragt_passwordless_codes')->fields(NULL, ['verified'])->execute()->fetchField());
  }

  /**
   * Creates a synthetic active account.
   */
  private function account(string $email): User {
    $account = User::create(['name' => $email, 'mail' => $email, 'status' => 1]);
    $account->save();
    return $account;
  }

}
