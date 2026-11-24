<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\RenderDataBuilder;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Site\Settings;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The protected-value rule of RenderDataBuilder::build().
 *
 * Same rule as the staff API: the application's own schema decides whether
 * values are protected (fail closed when it cannot be resolved), the
 * permission decides whether they are revealed, and every reveal is logged.
 * The data protection service is the real one; only storage is faked.
 */
#[Group('beantragt_docgen')]
final class RenderDataProtectionTest extends UnitTestCase {

  private const PERMISSION = 'view protected application data';

  /**
   * Original installation settings, restored after every test.
   */
  private array $originalSettings;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    try {
      Settings::getInstance();
      $this->originalSettings = Settings::getAll();
    }
    catch (\BadMethodCallException) {
      $this->originalSettings = [];
    }
    new Settings(['hash_salt' => bin2hex(random_bytes(32))] + $this->originalSettings);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    new Settings($this->originalSettings);
    parent::tearDown();
  }

  /**
   * Schema with one sensitive field.
   */
  private const SENSITIVE_SCHEMA = [
    'sections' => [[
      'id' => 'antrag',
      'fields' => [
        ['id' => 'parkzone', 'type' => 'text'],
        ['id' => 'steuer_id', 'type' => 'text', 'sensitive' => TRUE],
      ],
    ],
    ],
  ];

  /**
   * The same form after the sensitive field was removed.
   */
  private const PLAIN_SCHEMA = [
    'sections' => [[
      'id' => 'antrag',
      'fields' => [
        ['id' => 'parkzone', 'type' => 'text'],
      ],
    ],
    ],
  ];

  /**
   * Submitted values.
   */
  private const DATA = ['parkzone' => 'Zone B', 'steuer_id' => '12345678901'];

  /**
   * Submitted values for the plain form.
   */
  private const PLAIN_DATA = ['parkzone' => 'Zone B'];

  /**
   * Without the data protection service nothing is rendered.
   */
  public function testMissingDataProtectionFailsClosed(): void {
    $builder = $this->builder(NULL);
    try {
      $builder->build($this->application(self::SENSITIVE_SCHEMA, 0, 0), $this->account(TRUE));
      self::fail('Rendered without the protected-field rule.');
    }
    catch (DocgenApiException $exception) {
      self::assertSame('docgen.internal_error', $exception->errorCode);
    }
  }

  /**
   * Without the permission protected values stay out and nothing is logged.
   */
  public function testProtectedValuesAreOmittedWithoutPermission(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::never())->method('notice');
    $schema = self::SENSITIVE_SCHEMA;
    unset($schema['sections'][0]['fields'][1]['sensitive']);
    $result = $this->builder($this->protection($logger))->build($this->application($schema, 0, 0), $this->account(FALSE));
    self::assertSame('Zone B', $result['data']['antrag.parkzone']);
    self::assertArrayNotHasKey('antrag.steuer_id', $result['data']);
    self::assertContains('antrag.steuer_id', $result['protectedOmitted']);
    self::assertFalse($result['protectedValues']);
  }

  /**
   * An unresolvable schema version redacts everything.
   *
   * The application was submitted against version 5, which no longer exists;
   * the current form (version 6) has no sensitive field any more. The
   * current form must not decide: without the permission nothing is
   * revealed.
   */
  public function testUnresolvableSchemaVersionRedactsEverything(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::never())->method('notice');
    $result = $this->builder($this->protection($logger))->build($this->application(self::PLAIN_SCHEMA, 5, 6), $this->account(FALSE));
    self::assertArrayNotHasKey('antrag.parkzone', $result['data']);
    self::assertArrayNotHasKey('antrag.steuer_id', $result['data']);
    self::assertSame(['antrag.parkzone', 'antrag.steuer_id'], $result['protectedOmitted']);
    self::assertFalse($result['protectedValues']);
  }

  /**
   * With the permission values are revealed and the reveal is logged.
   */
  public function testPermissionRevealsAndLogs(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('notice')->with(self::anything(), self::callback(static fn (array $context): bool => $context['@channel'] === 'docgen_render'));
    $result = $this->builder($this->protection($logger))->build($this->application(self::SENSITIVE_SCHEMA, 0, 0), $this->account(TRUE));
    self::assertSame('12345678901', $result['data']['antrag.steuer_id']);
    self::assertSame([], $result['protectedOmitted']);
    self::assertTrue($result['protectedValues']);
  }

  /**
   * A form without protected fields reveals without a log entry.
   */
  public function testPlainFormNeedsNoPermission(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::never())->method('notice');
    $result = $this->builder($this->protection($logger))->build($this->application(self::PLAIN_SCHEMA, 0, 0, self::PLAIN_DATA), $this->account(FALSE));
    self::assertSame('Zone B', $result['data']['antrag.parkzone']);
    self::assertSame([], $result['protectedOmitted']);
    self::assertFalse($result['protectedValues']);
  }

  /**
   * Account identifiers never become the clerk name.
   */
  public function testClerkNameIsNeverAnAccountIdentifier(): void {
    self::assertSame('', RenderDataBuilder::clerkName('erika.muster@stadt.de'));
    self::assertSame('', RenderDataBuilder::clerkName('sso.bundid.4f2a9c'));
    self::assertSame('', RenderDataBuilder::clerkName('  '));
    self::assertSame('', RenderDataBuilder::clerkName('agent.nachforderung'));
    self::assertSame('', RenderDataBuilder::clerkName(' Agent.Nachforderung '));
    self::assertSame('Erika Muster', RenderDataBuilder::clerkName('Erika Muster'));
    $result = $this->builder($this->protection($this->createMock(LoggerInterface::class)))
      ->build($this->application(self::PLAIN_SCHEMA, 0, 0, self::PLAIN_DATA), $this->account(TRUE, 'erika.muster@stadt.de'));
    self::assertArrayNotHasKey('bearbeitung.name', $result['data']);
  }

  /**
   * Only provided tags enter render data; core and protected keys stay owned.
   */
  public function testProvidedValuesCannotReplaceCoreOrProtectedValues(): void {
    $extension = $this->createMock(DocgenCaseExtensionInterface::class);
    $extension->method('systemValues')->willReturn([
      'vorgang.fehlende_nachweise' => "Mietvertrag\nEinkommen, Haushaltsmitglied 2",
      'heute' => 'forged',
      'empfaenger.name' => 'Forged recipient',
      'empfaenger.anschrift' => 'Forged address',
      'empfaenger.anrede' => 'Forged greeting',
      'antrag.steuer_id' => 'forged',
    ]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::never())->method('notice');
    $result = $this->builder($this->protection($logger), $extension)
      ->build($this->application(self::SENSITIVE_SCHEMA, 0, 0), $this->account(FALSE, 'agent.nachforderung'));
    self::assertSame("Mietvertrag\nEinkommen, Haushaltsmitglied 2", $result['data']['vorgang.fehlende_nachweise']);
    self::assertSame('21.10.2026', $result['data']['heute']);
    self::assertArrayNotHasKey('empfaenger.name', $result['data']);
    self::assertArrayNotHasKey('empfaenger.anschrift', $result['data']);
    self::assertSame('Sehr geehrte Damen und Herren,', $result['data']['empfaenger.anrede']);
    self::assertArrayNotHasKey('antrag.steuer_id', $result['data']);
    self::assertArrayNotHasKey('bearbeitung.name', $result['data']);

    $empty = $this->createMock(DocgenCaseExtensionInterface::class);
    $empty->method('systemValues')->willReturn(['vorgang.fehlende_nachweise' => '']);
    $result = $this->builder($this->protection($logger), $empty)
      ->build($this->application(self::SENSITIVE_SCHEMA, 0, 0), $this->account(FALSE));
    self::assertArrayNotHasKey('vorgang.fehlende_nachweise', $result['data']);
  }

  /**
   * An unused sensitive field does not trigger a reveal or protect the file.
   */
  public function testUnusedProtectedFieldsAreNotRevealed(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::never())->method('notice');
    $result = $this->builder($this->protection($logger))->build(
      $this->application(self::SENSITIVE_SCHEMA, 0, 0), $this->account(TRUE),
      ['placeholders' => ['antrag.parkzone'], 'loops' => [], 'errors' => []],
    );
    self::assertSame(['antrag.parkzone' => 'Zone B'], $result['data']);
    self::assertFalse($result['protectedValues']);
  }

  /**
   * Outer-scope tags in loops use resolved names and retain protected flags.
   */
  public function testOuterScopeTagsInLoops(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('notice');
    $schema = self::SENSITIVE_SCHEMA;
    $schema['sections'][] = ['id' => 'kinder', 'repeatable' => TRUE, 'fields' => [['id' => 'name', 'type' => 'text']]];
    $result = $this->builder($this->protection($logger))->build(
      $this->application($schema, 0, 0), $this->account(TRUE),
      [
        'placeholders' => ['antrag.kinder.heute', 'antrag.kinder.antrag.steuer_id'],
        'loops' => ['antrag.kinder'],
        'errors' => [],
      ],
    );
    self::assertSame('21.10.2026', $result['data']['heute']);
    self::assertSame('12345678901', $result['data']['antrag.steuer_id']);
    self::assertTrue($result['protectedValues']);
  }

  /**
   * Even a loop with static body reveals the protected household row count.
   */
  public function testProtectedLoopCountRequiresPermission(): void {
    $schema = [
      'sections' => [[
        'id' => 'kinder',
        'repeatable' => TRUE,
        'fields' => [['id' => 'name', 'type' => 'text', 'sensitive' => TRUE]],
      ],
      ],
    ];
    foreach ([FALSE, TRUE] as $allowed) {
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects($allowed ? self::once() : self::never())->method('notice');
      $result = $this->builder($this->protection($logger))->build(
        $this->application($schema, 0, 0, ['kinder' => [['name' => 'Fictional child']]]),
        $this->account($allowed), ['placeholders' => [], 'loops' => ['antrag.kinder'], 'errors' => []],
      );
      self::assertSame($allowed, $result['protectedValues']);
      if ($allowed) {
        self::assertSame([[]], $result['data']['antrag.kinder']);
      }
      else {
        self::assertArrayNotHasKey('antrag.kinder', $result['data']);
        self::assertContains('antrag.kinder', $result['protectedOmitted']);
      }
    }
  }

  /**
   * Flat fields and repeatable rows use German and keep the schema format.
   */
  public function testBuildFormatsNumbersAndAddressesInBothScopes(): void {
    $schema = [
      'sections' => [
        [
          'id' => 'main',
          'fields' => [
            ['id' => 'amount', 'type' => 'number', 'format' => 'currency'],
            ['id' => 'location', 'type' => 'address'],
          ],
        ],
        [
          'id' => 'items',
          'repeatable' => TRUE,
          'fields' => [
            ['id' => 'fee', 'type' => 'number', 'format' => 'currency'],
          ],
        ],
      ],
    ];
    $data = [
      'amount' => '1200,5',
      'location' => [
        'street' => 'Beispielweg',
        'houseNumber' => '4',
        'postcode' => '01234',
        'city' => 'Beispielstadt',
        'lat' => 50.1,
        'lng' => 7.2,
      ],
      'items' => [['fee' => '12,5']],
    ];
    $builder = $this->builder($this->protection($this->createMock(LoggerInterface::class)));
    $application = $this->application($schema, 0, 0, $data);
    $application->expects(self::never())->method('language');
    $result = $builder->build($application, $this->account(FALSE));
    self::assertSame("1.200,50\u{00A0}€", $result['data']['antrag.amount']);
    self::assertSame([['fee' => "12,50\u{00A0}€"]], $result['data']['antrag.items']);
    self::assertSame('Beispielweg 4, 01234 Beispielstadt', $result['data']['antrag.location']);
    self::assertFalse($result['protectedValues']);
    self::assertSame($schema, $result['schema']);
    $schema['sections'][0]['fields'][0]['sensitive'] = TRUE;
    $schema['sections'][0]['fields'][1]['sensitive'] = TRUE;
    $result = $builder->build($this->application($schema, 0, 0, $data), $this->account(FALSE));
    self::assertArrayNotHasKey('antrag.amount', $result['data']);
    self::assertArrayNotHasKey('antrag.location', $result['data']);
    self::assertContains('antrag.amount', $result['protectedOmitted']);
    self::assertContains('antrag.location', $result['protectedOmitted']);
  }

  /**
   * Builds the service under test.
   */
  private function builder(?ApplicationDataProtectionService $protection, ?DocgenCaseExtensionInterface $extension = NULL, ?LoggerInterface $logger = NULL): RenderDataBuilder {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(FALSE);
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('format')->willReturn('21.10.2026');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1792540800);
    $settings = $this->createMock(DocgenSettingsRepository::class);
    $settings->method('senderAddress')->willReturn('');
    $scope = $this->createMock(DocgenScopeAccess::class);
    $scope->method('scopeOf')->willReturn(NULL);
    return new RenderDataBuilder($manager, $formatter, $time, $settings, $scope, $protection, $extension, $logger ?? new NullLogger());
  }

  /**
   * The real data protection service on fake storage.
   */
  private function protection(LoggerInterface $logger): ApplicationDataProtectionService {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    // Not revisionable: an older schema version cannot be loaded.
    $manager->method('getStorage')->willReturn($this->createMock(EntityStorageInterface::class));
    return new ApplicationDataProtectionService($manager, $logger);
  }

  /**
   * An application submitted against a schema version.
   *
   * @param array<string, mixed> $schema
   *   The current form schema.
   * @param int $submitted_version
   *   Schema version stored on the application (0 = current).
   * @param int $current_version
   *   Version of the current form.
   * @param array<string, mixed> $data
   *   Submitted data.
   */
  private function application(array $schema, int $submitted_version, int $current_version, array $data = self::DATA): ApplicationInterface {
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('getSchema')->willReturn(json_encode($schema, JSON_THROW_ON_ERROR));
    $form->method('getVersion')->willReturn($current_version);
    $form->method('id')->willReturn(7);
    $reference = $this->createMock(FieldItemListInterface::class);
    $reference->method('isEmpty')->willReturn(FALSE);
    $reference->method('__isset')->willReturn(TRUE);
    $reference->method('__get')->willReturnCallback(static fn (string $name) => $name === 'entity' ? $form : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getData')->willReturn(json_encode($data, JSON_THROW_ON_ERROR));
    $application->method('hasField')->willReturnCallback(static fn (string $name): bool => $name === 'field_schema');
    $application->method('get')->willReturn($reference);
    $application->method('getSchemaVersion')->willReturn($submitted_version);
    $application->method('getReferenceNumber')->willReturn('MS-BB-2026-00001');
    $application->method('getCreatedTime')->willReturn(1792454400);
    $application->method('getWorkflowStatus')->willReturn('in_bearbeitung');
    $application->method('getWorkflowStatusId')->willReturn(NULL);
    $application->method('uuid')->willReturn('application-uuid');
    return $application;
  }

  /**
   * A staff account.
   */
  private function account(bool $may_reveal, string $name = 'Erika Muster'): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $permission === self::PERMISSION && $may_reveal);
    $account->method('getDisplayName')->willReturn($name);
    $account->method('id')->willReturn(5);
    return $account;
  }

  /**
   * The builder guards direct extensions as well as the normal collector.
   */
  public function testNonStringProvidedValuesNeverReachStringFilter(): void {
    foreach ([NULL, FALSE, 12, 2.5, ['private'], new \stdClass()] as $value) {
      $extension = $this->createMock(DocgenCaseExtensionInterface::class);
      $extension->method('systemValues')->willReturn(['vorgang.fehlende_nachweise' => $value]);
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects(self::once())->method('warning')->with(self::anything(), ['@class' => $extension::class]);
      $result = $this->builder($this->protection($logger), $extension, $logger)
        ->build($this->application(self::SENSITIVE_SCHEMA, 0, 0), $this->account(FALSE));
      self::assertArrayNotHasKey('vorgang.fehlende_nachweise', $result['data']);
      self::assertArrayNotHasKey('antrag.steuer_id', $result['data']);
    }
  }

}
