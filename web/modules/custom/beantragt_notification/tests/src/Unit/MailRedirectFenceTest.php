<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/beantragt_notification.module';

/**
 * Tests the staging recipient fence for outbound mail.
 */
#[Group('beantragt_notification')]
final class MailRedirectFenceTest extends UnitTestCase {

  /**
   * Original environment values restored after each test.
   *
   * @var array<string, string|false>
   */
  private array $originalEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['BEANTRAGT_MAIL_REDIRECT_RECIPIENT', 'BEANTRAGT_ENV', 'BEANTRAGT_MAIL_MODE', 'BEANTRAGT_MAILPIT_PUBLIC'] as $name) {
      $this->originalEnv[$name] = getenv($name);
      putenv($name);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->originalEnv as $name => $value) {
      if ($value === FALSE) {
        putenv($name);
        continue;
      }
      putenv($name . '=' . $value);
    }

    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Unset redirect recipient leaves mail untouched.
   */
  public function testUnsetRedirectRecipientLeavesMessageUntouched(): void {
    $message = $this->buildMessage();
    $message['to'] = 'Erika Mustermann <erika@beantragt.ai>';
    $original = $message;

    beantragt_notification_mail_alter($message);

    $this->assertSame($original, $message);
  }

  /**
   * Reserved recipient domains are not handed to the SMTP provider.
   */
  public function testReservedRecipientDomainBlocksDelivery(): void {
    putenv('BEANTRAGT_MAIL_MODE=smtp');
    $captured_context = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(static function (string $message, array $context) use (&$captured_context): void {
        $captured_context = $context;
      });
    $this->setLogger($logger);

    $message = $this->buildMessage();
    $message['to'] = 'citizen-services@sampletown.example.org';

    beantragt_notification_mail_alter($message);

    $this->assertFalse($message['send']);
    $this->assertTrue($message['beantragt_reserved_recipient_suppressed']);
    $this->assertSame('sampletown.example.org', $captured_context['@domain']);
    $this->assertSame('beantragt_notification:application_confirmation', $captured_context['@message_key']);
    $this->assertStringNotContainsString('citizen-services', implode(' ', $captured_context));
  }

  /**
   * Every RFC 2606 and RFC 6761 reserved domain counts as reserved.
   */
  public function testReservedDomainsAreRecognised(): void {
    $reserved = [
      'example.com',
      'example.net',
      'example.org',
      'sampletown.example.org',
      'demo.example',
      'cp3.invalid',
      'foo.test',
      'app.localhost',
    ];
    foreach ($reserved as $domain) {
      $this->assertTrue(_beantragt_notification_mail_domain_is_reserved($domain), $domain);
    }
    $deliverable = [
      'beantragt.ai',
      'civic-patches.com',
      'notexample.org',
      'example.org.de',
      'testing.de',
    ];
    foreach ($deliverable as $domain) {
      $this->assertFalse(_beantragt_notification_mail_domain_is_reserved($domain), $domain);
    }
  }

  /**
   * A real or unparsable recipient keeps the message eligible for delivery.
   */
  #[DataProvider('unredirectedRecipientsProvider')]
  public function testMailWithMixedOrUnparsableRecipientsIsLeftUntouched(string $recipients): void {
    putenv('BEANTRAGT_MAIL_MODE=smtp');
    $message = $this->buildMessage();
    $message['to'] = $recipients;
    $original = $message;

    beantragt_notification_mail_alter($message);

    $this->assertSame($original, $message);
  }

  /**
   * Supplies the recipients for each case.
   *
   * @return array<string, array{string}>
   *   Named test cases.
   */
  public static function unredirectedRecipientsProvider(): array {
    return [
      'mixed real and demo recipients' => ['erika@example.org, team@beantragt.ai'],
      'comma inside display name' => ['"Muster, Inc." <info@example.org>'],
    ];
  }

  /**
   * Display-name form, comma lists, case and trailing dots are all blocked.
   */
  public function testReservedRecipientVariantsBlockDelivery(): void {
    putenv('BEANTRAGT_MAIL_MODE=smtp');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->exactly(3))->method('notice');
    $this->setLogger($logger);

    foreach ([
      'Erika Mustermann <erika@example.org>',
      'a@sampletown.example.org, b@musterstadt.example.org',
      'Superuser@CP3.INVALID.',
    ] as $to) {
      $message = $this->buildMessage();
      $message['to'] = $to;

      beantragt_notification_mail_alter($message);

      $this->assertFalse($message['send'], $to);
    }
  }

  /**
   * Mailpit mode keeps reserved recipients so smokes can assert on them.
   */
  public function testMailpitModeKeepsReservedRecipients(): void {
    foreach ([FALSE, 'mailpit'] as $mode) {
      $mode === FALSE ? putenv('BEANTRAGT_MAIL_MODE') : putenv('BEANTRAGT_MAIL_MODE=' . $mode);
      $message = $this->buildMessage();
      $original = $message;

      beantragt_notification_mail_alter($message);

      $this->assertSame($original, $message);
    }
  }

  /**
   * A message already cancelled by another alter is neither touched nor logged.
   */
  public function testAlreadyCancelledMessageIsLeftAlone(): void {
    putenv('BEANTRAGT_MAIL_MODE=smtp');
    $message = $this->buildMessage();
    $message['send'] = FALSE;
    $original = $message;

    beantragt_notification_mail_alter($message);

    $this->assertSame($original, $message);
  }

  /**
   * Active redirect rewrites To, strips copy headers, and prefixes domain.
   */
  public function testActiveRedirectRewritesRecipientsAndPrefixesSubject(): void {
    putenv('BEANTRAGT_MAIL_REDIRECT_RECIPIENT=fence-test@example.org');
    putenv('BEANTRAGT_ENV=ddev');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('notice');
    $logger->expects($this->never())->method('error');
    $this->setLogger($logger);

    $message = $this->buildMessage();
    $message['headers']['To'] = 'Erika Mustermann <erika@example.org>';
    $message['headers']['cc'] = 'copy@example.net';
    $message['headers']['Bcc'] = 'blind@example.com';
    $message['headers']['reply-to'] = 'reply@example.org';

    beantragt_notification_mail_alter($message);

    $this->assertSame('fence-test@example.org', $message['to']);
    $this->assertSame('fence-test@example.org', $message['reply-to']);
    $this->assertSame('fence-test@example.org', $message['headers']['To']);
    $this->assertSame('fence-test@example.org', $message['headers']['Reply-To']);
    $this->assertArrayNotHasKey('reply-to', $message['headers']);
    $this->assertSame('[STAGING→example.org] Antrag eingegangen', $message['subject']);
    $this->assertSame('text/plain; charset=UTF-8', $message['headers']['Content-Type']);
    foreach (array_keys($message['headers']) as $header_name) {
      $this->assertNotSame(0, strcasecmp((string) $header_name, 'Cc'));
      $this->assertNotSame(0, strcasecmp((string) $header_name, 'Bcc'));
    }
    $this->assertSame('example.org', $message['beantragt_redirect_log']['domains']['@original_recipient']);
    $this->assertSame('example.org', $message['beantragt_redirect_log']['domains']['@redirect_recipient']);
    $this->assertSame('Erika Mustermann <erika@example.org>', $message['beantragt_redirect_log']['accepted']['@original_recipient']);
    $this->assertSame('fence-test@example.org', $message['beantragt_redirect_log']['accepted']['@redirect_recipient']);
  }

  /**
   * Non-development logs only the original recipient domain.
   */
  public function testNonDevelopmentLogOmitsFullOriginalRecipient(): void {
    putenv('BEANTRAGT_MAIL_REDIRECT_RECIPIENT=fence-test@example.org');
    putenv('BEANTRAGT_ENV=staging');

    $captured_context = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(static function (string $message, array $context) use (&$captured_context): void {
        $captured_context = $context;
      });
    $this->setLogger($logger);

    $message = $this->buildMessage();

    beantragt_notification_mail_alter($message);

    $this->assertSame('example.org', $captured_context['@original_recipient']);
    $this->assertSame('example.org', $captured_context['@redirect_recipient']);
    $this->assertStringNotContainsString('erika@example.org', implode(' ', $captured_context));
  }

  /**
   * Invalid redirect recipient logs an error and blocks delivery.
   */
  public function testInvalidRedirectRecipientLogsErrorAndBlocksDelivery(): void {
    putenv('BEANTRAGT_MAIL_REDIRECT_RECIPIENT=not-an-email');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('error');
    $logger->expects($this->never())->method('notice');
    $this->setLogger($logger);

    $message = $this->buildMessage();
    $message['headers']['Cc'] = 'copy@example.org';
    $message['headers']['Bcc'] = 'blind@example.org';
    $message['headers']['Reply-To'] = 'reply@example.org';

    beantragt_notification_mail_alter($message);

    $this->assertFalse($message['send']);
    $this->assertSame('', $message['to']);
    $this->assertNull($message['reply-to']);
    $this->assertArrayNotHasKey('Cc', $message['headers']);
    $this->assertArrayNotHasKey('Bcc', $message['headers']);
    $this->assertArrayNotHasKey('Reply-To', $message['headers']);
  }

  /**
   * The mail alter hook is ordered after other implementations.
   */
  public function testMailAlterImplementationIsMovedToEnd(): void {
    $implementations = [
      'beantragt_notification' => FALSE,
      'system' => FALSE,
      'user' => FALSE,
    ];

    beantragt_notification_module_implements_alter($implementations, 'mail_alter');

    $this->assertSame(['system', 'user', 'beantragt_notification'], array_keys($implementations));
  }

  /**
   * Without the public Mailpit flag, Mailpit mode keeps real recipients.
   */
  public function testPublicMailpitFenceIsOffByDefault(): void {
    putenv('BEANTRAGT_MAIL_MODE=mailpit');
    $message = $this->buildMessage();
    $message['to'] = 'prospect@municipality.de';
    $original = $message;

    beantragt_notification_mail_alter($message);

    $this->assertSame($original, $message);
  }

  /**
   * A public Mailpit never receives mail for a real mailbox.
   */
  public function testPublicMailpitDropsRealRecipient(): void {
    putenv('BEANTRAGT_MAIL_MODE=mailpit');
    putenv('BEANTRAGT_MAILPIT_PUBLIC=true');
    $captured_context = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('notice')
      ->willReturnCallback(static function (string $message, array $context) use (&$captured_context): void {
        $captured_context = $context;
      });
    $this->setLogger($logger);

    $message = $this->buildMessage();
    $message['module'] = 'beantragt_passwordless';
    $message['key'] = 'verification_code';
    $message['to'] = 'Erika Prospect <erika.prospect@municipality.de>';

    beantragt_notification_mail_alter($message);

    $this->assertFalse($message['send']);
    $this->assertTrue($message['beantragt_reserved_recipient_suppressed']);
    $this->assertSame('municipality.de', $captured_context['@domain']);
    $this->assertSame('recipient_not_reserved', $captured_context['@reason']);
    $this->assertStringNotContainsString('erika', implode(' ', $captured_context));
  }

  /**
   * Cc, Bcc, unparsable and missing recipients fail closed.
   */
  public function testPublicMailpitFailsClosedOnOtherRecipients(): void {
    putenv('BEANTRAGT_MAILPIT_PUBLIC=1');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->exactly(5))->method('notice');
    $this->setLogger($logger);

    $cases = [
      'cc' => ['to' => 'erika@example.org', 'headers' => ['CC' => 'staff@municipality.de']],
      'bcc' => ['to' => 'erika@example.org', 'headers' => ['bcc' => 'a@example.org, b@municipality.de']],
      'mixed to' => ['to' => 'erika@example.org, team@beantragt.ai', 'headers' => []],
      'unparsable' => ['to' => '"Muster, Inc." <info@example.org>', 'headers' => []],
      'empty' => ['to' => '', 'headers' => []],
    ];
    foreach ($cases as $label => $case) {
      $message = $this->buildMessage();
      $message['to'] = $case['to'];
      $message['headers'] += $case['headers'];

      beantragt_notification_mail_alter($message);

      $this->assertFalse($message['send'], $label);
    }
  }

  /**
   * Demo mail to reserved addresses stays visible in the public Mailpit.
   */
  public function testPublicMailpitKeepsReservedRecipients(): void {
    putenv('BEANTRAGT_MAIL_MODE=mailpit');
    putenv('BEANTRAGT_MAILPIT_PUBLIC=true');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('notice');
    $this->setLogger($logger);

    foreach ([
      'Erika Mustermann <erika@example.org>',
      'a@sampletown.example.org, B@Musterstadt.Example.Org.',
      'superuser@demo.invalid',
    ] as $to) {
      $message = $this->buildMessage();
      $message['to'] = $to;
      $original = $message;

      beantragt_notification_mail_alter($message);

      $this->assertSame($original, $message, $to);
    }
  }

  /**
   * Sign-in codes for UID 1 and staff never reach a public Mailpit.
   */
  public function testPublicMailpitDropsPrivilegedSignInMail(): void {
    putenv('BEANTRAGT_MAIL_MODE=mailpit');
    putenv('BEANTRAGT_MAILPIT_PUBLIC=true');
    $reasons = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->method('notice')
      ->willReturnCallback(static function (string $message, array $context) use (&$reasons): void {
        $reasons[] = $context['@reason'];
      });
    $this->setLogger($logger, [
      'superuser@demo.invalid' => [$this->account(1, ['authenticated'])],
      'clerk@musterstadt.example.org' => [$this->account(7, ['authenticated', 'case_worker'])],
      'erika@example.org' => [$this->account(9, ['authenticated'])],
    ]);

    $expectations = [
      ['beantragt_passwordless', 'superuser@demo.invalid', FALSE],
      ['user', 'Superuser <SUPERUSER@demo.invalid>', FALSE],
      ['beantragt_passwordless', 'clerk@musterstadt.example.org', FALSE],
      ['beantragt_passwordless', 'erika@example.org', TRUE],
      ['beantragt_passwordless', 'new.visitor@example.org', TRUE],
      // Workflow mail to staff is demo data and stays visible.
      ['beantragt_notification', 'clerk@musterstadt.example.org', TRUE],
    ];
    foreach ($expectations as [$module, $to, $kept]) {
      $message = $this->buildMessage();
      $message['module'] = $module;
      $message['key'] = $module === 'beantragt_notification' ? 'application_confirmation' : 'verification_code';
      $message['to'] = $to;

      beantragt_notification_mail_alter($message);

      $this->assertSame($kept, !array_key_exists('send', $message) || $message['send'] !== FALSE, $module . ' ' . $to);
    }
    $this->assertSame(['privileged_sign_in', 'privileged_sign_in', 'privileged_sign_in'], $reasons);
  }

  /**
   * A failing account lookup counts as privileged.
   */
  public function testPublicMailpitAccountLookupFailureFailsClosed(): void {
    putenv('BEANTRAGT_MAILPIT_PUBLIC=true');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('notice');
    $this->setLogger($logger, NULL);

    $message = $this->buildMessage();
    $message['module'] = 'beantragt_passwordless';
    $message['key'] = 'verification_code';

    beantragt_notification_mail_alter($message);

    $this->assertFalse($message['send']);
  }

  /**
   * Builds a user account double.
   *
   * @param int $uid
   *   User ID.
   * @param list<string> $roles
   *   Role IDs.
   */
  private function account(int $uid, array $roles): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('getRoles')->willReturn($roles);

    return $account;
  }

  /**
   * Wires a logger and a user lookup into Drupal's static container.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module logger.
   * @param array<string, list<\Drupal\user\UserInterface>>|null $accounts_by_mail
   *   Accounts per lowercase mail address, or NULL for a failing lookup.
   */
  private function setLogger(LoggerChannelInterface $logger, ?array $accounts_by_mail = []): void {
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')
      ->with('beantragt_notification')
      ->willReturn($logger);

    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);

    // Account lookup by mail; NULL makes the lookup fail.
    $storage = $this->createMock(EntityStorageInterface::class);
    if ($accounts_by_mail === NULL) {
      $storage->method('loadByProperties')->willThrowException(new \RuntimeException('database unavailable'));
    }
    else {
      $storage->method('loadByProperties')
        ->willReturnCallback(static fn (array $values): array => $accounts_by_mail[strtolower((string) ($values['mail'] ?? ''))] ?? []);
    }
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($storage);
    $container->set('entity_type.manager', $entity_type_manager);

    \Drupal::setContainer($container);
  }

  /**
   * Builds a minimal Drupal mail message.
   *
   * @return array<string, mixed>
   *   Mail message fixture.
   */
  private function buildMessage(): array {
    return [
      'module' => 'beantragt_notification',
      'key' => 'application_confirmation',
      'to' => 'Erika Mustermann <erika@example.org>',
      'subject' => 'Antrag eingegangen',
      'body' => ['Hallo'],
      'langcode' => 'de',
      'params' => [],
      'headers' => [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ],
    ];
  }

}
