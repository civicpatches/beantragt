<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\beantragt_passwordless\Service\VerifiedAccountMail;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_sso\Service\SsoIdentityLinker;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

/**
 * Tests SSO identity safety guards.
 *
 * @group beantragt_sso
 */
final class SsoIdentityLinkerTest extends UnitTestCase {

  /**
   * Lock contention has a safe category before any persistence can happen.
   */
  public function testBusyIdentityHasSafeFailureCode(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))->method('acquire')->willReturn(FALSE);
    $lock->expects($this->once())->method('wait');
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('startTransaction');
    new Settings(['hash_salt' => 'synthetic-test-salt']);
    $linker = new SsoIdentityLinker($database, $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ApplicantManager::class), $this->createMock(LoggerInterface::class), NULL,
      $this->createMock(VerifiedAccountMail::class), $lock);
    try {
      $linker->authenticate('test', [], 'synthetic-subject', [], []);
      $this->fail('Lock contention was accepted.');
    }
    catch (SsoAuthenticationException $exception) {
      $this->assertSame('identity_busy', $exception->failureCode);
    }
  }

  /**
   * Assurance failures report the reason for the refusal.
   */
  #[DataProvider('insufficientAssuranceLevels')]
  public function testRejectsInsufficientAssuranceLevel(string $minimum, ?string $actual, string $code): void {
    try {
      $this->invokeAssuranceGuard(['minimum_assurance_level' => $minimum], $actual);
      $this->fail('Insufficient assurance level was accepted.');
    }
    catch (SsoAuthenticationException $exception) {
      $this->assertSame($code, $exception->failureCode);
    }
  }

  /**
   * Provides missing and non-qualifying assurance claims.
   */
  public static function insufficientAssuranceLevels(): array {
    return [
      'missing' => ['substantial', NULL, 'assurance_missing'],
      'below minimum' => ['high', 'substantial', 'assurance_too_low'],
      'BundID normal' => ['high', 'normal', 'assurance_too_low'],
      'unknown claim' => ['high', 'unknown', 'assurance_unrecognized'],
      'custom minimum mismatch' => ['custom', 'high', 'assurance_unrecognized'],
      'different custom levels' => ['custom', 'other', 'assurance_unrecognized'],
    ];
  }

  /**
   * Equivalent or higher assurance levels are accepted.
   */
  public function testAllowsSufficientAssuranceLevel(): void {
    $this->invokeAssuranceGuard([
      'minimum_assurance_level' => 'substantial',
    ], 'high');

    $this->addToAssertionCount(1);
  }

  /**
   * The BundID "hoch" assurance level satisfies a "substantial" minimum.
   */
  public function testAcceptsBundidHochForSubstantialMinimum(): void {
    $this->invokeAssuranceGuard([
      'minimum_assurance_level' => 'substantial',
    ], 'hoch');

    $this->addToAssertionCount(1);
  }

  /**
   * An exact match satisfies a custom minimum.
   */
  public function testAcceptsMatchingCustomAssuranceLevel(): void {
    $this->invokeAssuranceGuard(['minimum_assurance_level' => 'custom'], 'custom');

    $this->addToAssertionCount(1);
  }

  /**
   * Privileged users cannot be linked by email on first login.
   */
  public function testRejectsEmailLinkingForPrivilegedUser(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('7');
    $user->method('getRoles')->willReturn(['authenticated', 'department_admin']);

    $this->expectException(\RuntimeException::class);
    $this->invokeEmailLinkGuard($user);
  }

  /**
   * UID 1 cannot be linked by email on first login.
   */
  public function testRejectsEmailLinkingForUidOne(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('1');
    $user->method('getRoles')->willReturn(['authenticated']);

    $this->expectException(\RuntimeException::class);
    $this->invokeEmailLinkGuard($user);
  }

  /**
   * Non-privileged users remain eligible for email linking.
   */
  public function testAllowsEmailLinkingForCitizenUser(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('9');
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getRoles')->willReturn(['authenticated']);

    $this->invokeEmailLinkGuard($user);
    $this->addToAssertionCount(1);
  }

  /**
   * Subject hashing fails closed when Drupal has no private hash salt.
   */
  public function testSubjectHashRequiresPrivateHashSalt(): void {
    new Settings([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SSO identity hashing requires a private Drupal hash salt.');

    $this->invokeSubjectHash('bundid', 'subject-1');
  }

  /**
   * Subject hashes are stable and scoped by provider and external subject.
   */
  public function testSubjectHashIsStableAndProviderScoped(): void {
    new Settings(['hash_salt' => 'beantragt-sso-private-test-salt']);

    $hash = $this->invokeSubjectHash('bundid', 'subject-1');

    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    $this->assertSame($hash, $this->invokeSubjectHash('bundid', 'subject-1'));
    $this->assertNotSame($hash, $this->invokeSubjectHash('adfs', 'subject-1'));
    $this->assertNotSame($hash, $this->invokeSubjectHash('bundid', 'subject-2'));
  }

  /**
   * Invokes the assurance guard.
   *
   * @param array<string, mixed> $provider
   *   Provider config.
   * @param string|null $assuranceLevel
   *   Assurance level value.
   */
  private function invokeAssuranceGuard(array $provider, ?string $assuranceLevel): void {
    $method = new \ReflectionMethod(SsoIdentityLinker::class, 'assertAssuranceLevel');
    $method->setAccessible(TRUE);
    $method->invoke($this->linker(), $provider, $assuranceLevel);
  }

  /**
   * Invokes the email-link guard.
   */
  private function invokeEmailLinkGuard(UserInterface $user): void {
    $method = new \ReflectionMethod(SsoIdentityLinker::class, 'assertEmailLinkAllowed');
    $method->setAccessible(TRUE);
    $method->invoke($this->linker(), $user);
  }

  /**
   * Invokes subject hashing.
   */
  private function invokeSubjectHash(string $providerId, string $nameId): string {
    $method = new \ReflectionMethod(SsoIdentityLinker::class, 'subjectHash');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($this->linker(), $providerId, $nameId);
  }

  /**
   * Builds the identity linker with unused collaborators mocked.
   */
  private function linker(): SsoIdentityLinker {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([]);
    $manager->method('getStorage')->willReturn($storage);
    return new SsoIdentityLinker(
      $this->createMock(Connection::class),
      $manager,
      $this->createMock(ApplicantManager::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $this->createMock(VerifiedAccountMail::class),
      $this->createMock(LockBackendInterface::class),
    );
  }

}
