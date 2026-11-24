<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Service;

use Drupal\beantragt_notification\Service\BrandedMailComposer;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * A response notification is sent only when the mail transport accepts it.
 */
#[Group('beantragt_notification')]
final class ExternalResponseNotificationTest extends UnitTestCase {

  /**
   * Ensures the actual mail hook and branded renderer accept the new key.
   */
  public function testNewTemplatePassesTheActualMailHookAndBrandingAllowlist(): void {
    require_once dirname(__DIR__, 4) . '/beantragt_notification.module';
    $message = ['send' => TRUE, 'langcode' => 'de', 'body' => []];
    beantragt_notification_mail('external_response_available', $message, [
      'resolvedTemplate' => ['subject' => 'Bescheid @reference_number', 'body' => 'Abruf: @citizen_application_url'],
      'replacements' => [
        '@reference_number' => 'TEST-115',
        '@citizen_application_url' => 'https://example.invalid/meine-antraege/test',
      ],
    ]);
    self::assertTrue($message['send']);
    self::assertSame('Bescheid TEST-115', $message['subject']);
    self::assertStringContainsString('https://example.invalid/meine-antraege/test', $message['body'][0]);
    self::assertTrue(BrandedMailComposer::supports('beantragt_notification', 'external_response_available'));
  }

  /**
   * Reports transport acceptance without inventing success.
   */
  #[DataProvider('outcomes')]
  public function testTruthfulResult(bool $enabled, string $email, bool $accepted, bool $expected, int $calls): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'enabled_templates.external_response_available' ? $enabled : NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getDefaultLanguage')->willReturn($language);
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects(self::exactly($calls))->method('mail')->willReturn(['result' => $accepted]);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getApplicantEmail')->willReturn($email);
    $mailer = $this->getMockBuilder(NotificationMailer::class)->setConstructorArgs([
      $mail, $factory, $this->createMock(EntityTypeManagerInterface::class), $this->createMock(LoggerInterface::class), $languages,
    ])->onlyMethods(['resolveTokens'])->getMock();
    $mailer->method('resolveTokens')->willReturn([]);
    self::assertSame($expected, $mailer->sendCitizenNotificationWithResult($application, 'external_response_available'));
  }

  /**
   * Supplies enabled, missing-recipient and transport-result combinations.
   */
  public static function outcomes(): array {
    return [
      'accepted' => [TRUE, 'synthetic@example.invalid', TRUE, TRUE, 1],
      'rejected' => [TRUE, 'synthetic@example.invalid', FALSE, FALSE, 1],
      'disabled' => [FALSE, 'synthetic@example.invalid', TRUE, FALSE, 0],
      'no recipient' => [TRUE, '', TRUE, FALSE, 0],
    ];
  }

}
