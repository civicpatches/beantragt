<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Kernel;

use Drupal\beantragt_maintenance\Service\BreakGlassOtpService;
use Drupal\beantragt_notification\ForcedPhpMailManager;
use Drupal\beantragt_notification\Plugin\Mail\BrandedHtmlMail;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Verifies that Mailpit mode only allows internal PHP mail plugins.
 */
#[Group('beantragt_notification')]
final class ForcedPhpMailManagerTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'language',
    'serialization',
    'beantragt_compute_api',
    'beantragt_roles_api',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_workflow',
    'beantragt_notification',
    'beantragt_maintenance',
  ];

  /**
   * Previous environment values restored after the test.
   *
   * @var array<string, string|false>
   */
  private array $mailEnvironment = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    foreach (['BEANTRAGT_MAIL_MODE', 'BEANTRAGT_ENV', 'BEANTRAGT_MAIL_REDIRECT_RECIPIENT'] as $name) {
      $this->mailEnvironment[$name] = getenv($name);
    }
    putenv('BEANTRAGT_MAIL_MODE=mailpit');
    parent::setUp();

    $this->config('system.mail')
      ->set('interface', [
        'default' => 'symfony_mailer',
        'beantragt_notification_assignment_alert' => 'symfony_mailer',
      ])
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->mailEnvironment as $name => $value) {
      putenv($value === FALSE ? $name : $name . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Branded messages use the internal multipart plugin.
   *
   * Arbitrary configuration cannot bypass the PHP mail transport.
   */
  public function testMailpitModeForcesPhpMailForEverySelectionPath(): void {
    $manager = $this->container->get('plugin.manager.mail');

    $this->assertInstanceOf(ForcedPhpMailManager::class, $manager);
    $this->assertInstanceOf(BrandedHtmlMail::class, $manager->getInstance([
      'module' => 'beantragt_notification',
      'key' => 'assignment_alert',
    ]));
    $this->assertInstanceOf(PhpMail::class, $manager->getInstance([
      'module' => 'system',
      'key' => 'mailpit_contract',
    ]));
    $this->assertInstanceOf(PhpMail::class, $manager->createInstance('symfony_mailer'));
  }

  /**
   * SMTP mode still uses only the container-owned PHP mail transport.
   */
  public function testSmtpModeRejectsDirectDrupalMailerSelection(): void {
    putenv('BEANTRAGT_MAIL_MODE=smtp');
    $manager = $this->container->get('plugin.manager.mail');

    $this->assertInstanceOf(PhpMail::class, $manager->getInstance([
      'module' => 'system',
      'key' => 'configured_direct_mailer',
    ]));
    $this->assertInstanceOf(PhpMail::class, $manager->createInstance('symfony_mailer'));
    $this->assertInstanceOf(BrandedHtmlMail::class, $manager->getInstance([
      'module' => 'beantragt_status',
      'key' => 'status_lookup_code',
    ]));
  }

  /**
   * The confirmation fallback goes to the transport and stays out of errors.
   */
  #[DataProvider('confirmationDeliveryCases')]
  public function testFailedConfirmationRedactsFallbackRecipient(?string $redirect): void {
    putenv('BEANTRAGT_ENV=development');
    putenv($redirect === NULL ? 'BEANTRAGT_MAIL_REDIRECT_RECIPIENT' : 'BEANTRAGT_MAIL_REDIRECT_RECIPIENT=' . $redirect);
    $this->installConfig(['beantragt_notification']);
    $this->config('system.site')->set('mail', 'sender@platform.example')->save();
    $recipient = 'private-applicant@fixture.example';
    $protection = $this->container->get('beantragt_application.data_protection');
    $application = $this->container->get('entity_type.manager')->getStorage('application')->create([
      'field_data' => '{}',
      'field_applicant_email_hash' => $protection->applicantEmailLookupHash($recipient),
    ]);
    $this->assertNull($protection->protectedApplicantEmail($application));

    $transport = $this->createMock(MailInterface::class);
    $transport->method('format')->willReturnArgument(0);
    $delivery_recipient = $redirect ?? $recipient;
    $transport->expects($this->exactly(2))->method('mail')
      ->with($this->callback(static fn (array $message): bool => $message['to'] === $delivery_recipient))
      ->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $manager = $this->container->get('plugin.manager.mail');
    (new \ReflectionProperty($manager, 'instances'))->setValue($manager, ['beantragt_html_mail' => $transport]);

    $errors = [];
    $redirect_logs = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->method('log')->willReturnCallback(static function ($level, $message, array $context) use (&$errors, &$redirect_logs): void {
      if ($level === RfcLogLevel::ERROR) {
        $errors[$context['channel']][] = [(string) $message, $context];
      }
      if ($level === RfcLogLevel::NOTICE && isset($context['@original_recipient'])) {
        $redirect_logs[] = $context['@original_recipient'];
      }
    });
    $this->container->get('logger.factory')->addLogger($logger);

    $mailer = $this->container->get('beantragt_notification.mailer');
    $confirmation_url = 'https://fixture.example/confirm/test-token';
    $mailer->sendApplicationConfirmationRequest($application, $confirmation_url, $recipient);
    $mailer->sendApplicationConfirmationRequest($application, $confirmation_url, $recipient);

    $this->assertCount(1, $errors['mail']);
    $this->assertSame($redirect === NULL ? 'fixture.example' : 'redirect.example', $errors['mail'][0][1]['%to']);
    $this->assertSame('platform.example', $errors['mail'][0][1]['%from']);
    $this->assertCount(1, $errors['beantragt_notification']);
    [$template, $context] = $errors['beantragt_notification'][0];
    $this->assertStringContainsString('*@domain', $template);
    $this->assertSame('fixture.example', $context['@domain']);
    $this->assertStringNotContainsString($recipient, serialize($errors));
    $this->assertSame($redirect === NULL ? [] : ['fixture.example', $recipient], $redirect_logs);
  }

  /**
   * The recovery subject and both rendered parts use the account language.
   */
  #[DataProvider('recoveryMailLanguages')]
  public function testRecoveryMailUsesAccountLanguage(string $langcode, string $subject, string $body, string $expiry): void {
    putenv('BEANTRAGT_MAIL_REDIRECT_RECIPIENT');
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('system.site')
      ->set('name', 'Beantragt')
      ->set('mail', 'sender@platform.example')
      ->save();
    $user = $this->createUser(['access site in maintenance mode'], values: ['preferred_langcode' => $langcode]);
    self::assertNotFalse($user);
    $this->container->get('state')->set(BreakGlassOtpService::PLATFORM_MAINTENANCE_STATE, TRUE);

    $mail = [];
    $transport = $this->createMock(MailInterface::class);
    $transport->method('format')->willReturnArgument(0);
    $transport->expects($this->once())->method('mail')
      ->willReturnCallback(static function (array $message) use (&$mail): bool {
        $mail = $message;
        return TRUE;
      });
    $manager = $this->container->get('plugin.manager.mail');
    (new \ReflectionProperty($manager, 'instances'))->setValue($manager, ['beantragt_html_mail' => $transport]);

    $service = $this->container->get('beantragt_maintenance.break_glass_otp');
    $service->requestCode($user->getEmail());
    $service->onTerminate();

    $params = $mail['params'];
    self::assertSame($subject, $mail['subject']);
    self::assertStringContainsString($body . ' ' . $params['code'], $params['_plain_alt']);
    self::assertStringContainsString($expiry, $params['_plain_alt']);
    $html = $mail['body'][0];
    self::assertStringContainsString('<html lang="' . $langcode . '"', $html);
    self::assertStringContainsString('<title>' . $subject . '</title>', $html);
    self::assertStringContainsString($body, $html);
    self::assertStringContainsString($expiry, $html);
    self::assertStringContainsString($params['code'], $html);
  }

  /**
   * Provides the localized recovery mail content.
   */
  public static function recoveryMailLanguages(): array {
    return [
      'German' => [
        'de',
        'Beantragt: Wiederherstellungscode für den Wartungsmodus',
        'Ihr Wiederherstellungscode für den Wartungsmodus lautet:',
        'Der Code läuft in 10 Minuten ab.',
      ],
      'English' => [
        'en',
        'Beantragt maintenance recovery code',
        'Your maintenance recovery code is:',
        'The code expires in 10 minutes.',
      ],
    ];
  }

  /**
   * Provides direct and redirected mail delivery cases.
   */
  public static function confirmationDeliveryCases(): array {
    return [
      'direct' => [NULL],
      'development redirect' => ['fence@redirect.example'],
    ];
  }

  /**
   * The internal plugin emits genuine multipart alternative MIME.
   */
  public function testBrandedPluginBuildsMultipartAlternativeWithInlineLogo(): void {
    $manager = $this->container->get('plugin.manager.mail');
    $plugin = $manager->getInstance([
      'module' => 'beantragt_passwordless',
      'key' => 'verification_code',
    ]);
    $this->assertInstanceOf(BrandedHtmlMail::class, $plugin);

    $message = $plugin->format([
      'headers' => [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8Bit',
      ],
      'body' => ['fallback'],
      'params' => [
        '_html_body' => '<html><body><strong>123456</strong><img src="cid:tenant-logo@beantragt.ai"></body></html>',
        '_plain_alt' => "Code: 123456\n",
        '_inline_logo' => [
          'mime' => 'image/png',
          'content' => "\x89PNG\r\n\x1a\nlogo",
          'cid' => 'tenant-logo@beantragt.ai',
        ],
      ],
    ]);

    $this->assertStringStartsWith('multipart/alternative; boundary="beantragt-alt-', $message['headers']['Content-Type']);
    $this->assertArrayNotHasKey('Content-Transfer-Encoding', $message['headers']);
    $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $message['body']);
    $this->assertStringContainsString('Content-Type: multipart/related;', $message['body']);
    $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $message['body']);
    $this->assertStringContainsString('Content-ID: <tenant-logo@beantragt.ai>', $message['body']);
    $this->assertStringContainsString('Code: 123456', $message['body']);

    $invalid_logo = $plugin->format([
      'headers' => [],
      'body' => ['fallback'],
      'params' => [
        '_html_body' => '<html><body>Safe</body></html>',
        '_plain_alt' => "Safe\n",
        '_inline_logo' => [
          'mime' => 'image/png',
          'content' => 'not-a-png',
          'cid' => 'tenant-logo@beantragt.ai',
        ],
      ],
    ]);
    $this->assertStringNotContainsString('Content-ID:', $invalid_logo['body']);
  }

}
