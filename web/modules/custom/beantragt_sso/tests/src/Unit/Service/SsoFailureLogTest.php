<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Service;

use Drupal\beantragt_sso\Service\SsoFailureLog;
use Drupal\beantragt_sso\Exception\SsoAuthenticationException;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests bounded SSO failure logging.
 *
 * @group beantragt_sso
 */
final class SsoFailureLogTest extends UnitTestCase {

  /**
   * Known categories stay distinguishable without trusting arbitrary content.
   */
  public function testTypedFailureCategoriesAreAllowlisted(): void {
    foreach ([
      'identity_disabled', 'account_ineligible', 'account_privileged',
      'assurance_missing', 'assurance_too_low', 'assurance_unrecognized',
      'identity_busy', 'bad_signature',
    ] as $code) {
      $this->assertSame($code, SsoFailureLog::code(new SsoAuthenticationException($code, 'hostile-saml-status'), 'acs_rejected'));
    }
    $this->assertSame('acs_rejected', SsoFailureLog::code(new SsoAuthenticationException('hostile-saml-status', 'secret'), 'mock_login_failed'));
    $this->assertSame('mock_login_failed', SsoFailureLog::code(new \RuntimeException('identity_disabled'), 'mock_login_failed'));
  }

  /**
   * The browser only prompts for stronger assurance when the level is too low.
   */
  public function testPublicCodeForAssuranceFailures(): void {
    $this->assertSame('acs_rejected', SsoFailureLog::publicCode('assurance_missing'));
    $this->assertSame('acs_rejected', SsoFailureLog::publicCode('assurance_unrecognized'));
    $this->assertSame('assurance_insufficient', SsoFailureLog::publicCode('assurance_too_low'));
    $this->assertSame('assurance_insufficient', SsoFailureLog::publicCode('assurance_insufficient'));
    $this->assertSame('acs_rejected', SsoFailureLog::publicCode('hostile-saml-status'));
  }

  /**
   * Hostile failure content can never enter the log message or context.
   */
  public function testFailureLogUsesOnlyBoundedValues(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())
      ->method('log')
      ->with(
        'warning',
        'SSO failure @code (correlation @correlation).',
        self::callback(static function (array $context): bool {
          return $context['@code'] === 'acs_rejected'
            && preg_match('/^[a-f0-9]{32}$/', $context['@correlation']) === 1
            && array_keys($context) === ['@code', '@correlation']
            && !str_contains(implode(' ', $context), 'hostile-saml-status');
        }),
      );

    SsoFailureLog::log($logger, 'warning', 'hostile-saml-status', 'bundid');
  }

}
