<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Service;

use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\beantragt_notification\Service\NotificationTemplateResolver;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests notification template fallback resolution.
 */
#[CoversClass(NotificationTemplateResolver::class)]
#[Group('beantragt_notification')]
final class NotificationTemplateResolverTest extends UnitTestCase {

  /**
   * Jurisdiction language text wins over all fallback sources.
   */
  public function testJurisdictionLanguageOverrideWins(): void {
    $resolver = $this->resolver([
      'status_change' => [
        'enabled' => FALSE,
        'default' => [
          'subject' => 'Jur default subject',
          'body' => 'Jur default body',
        ],
        'translations' => [
          'en' => [
            'subject' => 'Jur en subject',
            'body' => 'Jur en body',
          ],
        ],
      ],
    ]);

    $resolved = $resolver->resolve($this->createMock(GroupInterface::class), 'status_change', 'en');

    $this->assertSame('Jur en subject', $resolved['subject']);
    $this->assertSame('Jur en body', $resolved['body']);
    $this->assertFalse($resolved['enabled']);
  }

  /**
   * Missing jurisdiction language text falls back field by field.
   */
  public function testFallbackChainUsesJurisdictionDefaultThenRequestedSiteThenDefaultSiteThenBaseThenCatalog(): void {
    $resolver = $this->resolver([
      'status_change' => [
        'default' => [
          'subject' => 'Jur default subject',
        ],
      ],
    ], siteLanguageOverride: [
      'subject' => 'English site override subject',
      'body' => 'English site override body',
    ]);

    $resolved = $resolver->resolve($this->createMock(GroupInterface::class), 'status_change', 'en');

    $this->assertSame('Jur default subject', $resolved['subject']);
    $this->assertSame('English site override body', $resolved['body']);
    $this->assertTrue($resolved['enabled']);
  }

  /**
   * Requested language site config wins before the default-language override.
   */
  public function testRequestedLanguageSiteOverrideWinsBeforeDefaultLanguageOverride(): void {
    $resolver = $this->resolver([], siteLanguageOverride: [
      'subject' => 'English site override subject',
      'body' => 'English site override body',
    ], siteDefaultOverride: [
      'subject' => 'German site override subject',
      'body' => 'German site override body',
    ]);

    $resolved = $resolver->resolve($this->createMock(GroupInterface::class), 'status_change', 'en');

    $this->assertSame('English site override subject', $resolved['subject']);
    $this->assertSame('English site override body', $resolved['body']);
  }

  /**
   * Empty strings are skipped and do not terminate the fallback chain.
   */
  public function testEmptyTextFallsThroughToNextFallback(): void {
    $resolver = $this->resolver([
      'status_change' => [
        'default' => [
          'subject' => '',
          'body' => '   ',
        ],
      ],
    ], siteLanguageOverride: [
      'subject' => 'English site override subject',
      'body' => 'English site override body',
    ]);

    $resolved = $resolver->resolve($this->createMock(GroupInterface::class), 'status_change', 'en');

    $this->assertSame('English site override subject', $resolved['subject']);
    $this->assertSame('English site override body', $resolved['body']);
  }

  /**
   * Base config and catalog are the final text fallbacks.
   */
  public function testBaseConfigAndCatalogAreFinalFallbacks(): void {
    $resolver = $this->resolver([], siteDefaultOverride: [], baseTemplate: [
      'subject' => 'Base subject',
    ]);

    $resolved = $resolver->resolve(NULL, 'status_change', 'en');

    $this->assertSame('Base subject', $resolved['subject']);
    $this->assertSame('Catalog body', $resolved['body']);
    $this->assertTrue($resolved['enabled']);
  }

  /**
   * The double opt-in system template is always enabled.
   */
  public function testLockedEnabledTemplateAlwaysResolvesEnabled(): void {
    $resolver = $this->resolver([
      'application_confirmation_request' => [
        'enabled' => FALSE,
      ],
    ]);

    $resolved = $resolver->resolve($this->createMock(GroupInterface::class), 'application_confirmation_request', 'en');

    $this->assertTrue($resolved['enabled']);
  }

  /**
   * Builds a resolver with overridable fallback sources.
   *
   * @param array<string, mixed> $jurisdictionTemplates
   *   Stored jurisdiction templates.
   * @param array<string, mixed>|null $siteDefaultOverride
   *   Site default language override.
   * @param array<string, mixed>|null $baseTemplate
   *   Site base config template.
   * @param array<string, mixed>|null $siteLanguageOverride
   *   Requested language site override.
   */
  private function resolver(
    array $jurisdictionTemplates,
    ?array $siteDefaultOverride = [
      'subject' => 'Site default override subject',
      'body' => 'Site default override body',
    ],
    ?array $baseTemplate = [
      'subject' => 'Base subject',
      'body' => 'Base body',
    ],
    ?array $siteLanguageOverride = NULL,
  ): NotificationTemplateResolver {
    $catalog = $this->catalog();

    $jurisdiction_config = $this->createMock(JurisdictionConfigServiceInterface::class);
    $jurisdiction_config->method('getSection')
      ->with($this->isInstanceOf(GroupInterface::class), 'notification_mail')
      ->willReturn(['templates' => $jurisdictionTemplates]);

    $mail_config = $this->createMock(Config::class);
    $mail_config->method('get')
      ->willReturnCallback(static fn(string $key): ?array => $key === 'status_change' ? $baseTemplate : NULL);

    $settings_config = $this->createMock(ImmutableConfig::class);
    $settings_config->method('get')
      ->willReturnCallback(static fn(string $key): mixed => $key === 'enabled_templates.status_change' ? TRUE : NULL);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')
      ->with('beantragt_notification.mail')
      ->willReturn($mail_config);
    $config_factory->method('get')
      ->with('beantragt_notification.settings')
      ->willReturn($settings_config);

    $language_manager = $this->createMock(LanguageManagerInterface::class);
    $language_manager->method('getDefaultLanguage')->willReturn(new Language(['id' => 'de']));

    $site_language_override = $this->templateOverrideConfig($siteLanguageOverride);
    $site_default_override = $this->templateOverrideConfig($siteDefaultOverride);
    $empty_override = $this->templateOverrideConfig(NULL);

    $language_override = $this->createMock(LanguageConfigFactoryOverrideInterface::class);
    $language_override->method('getOverride')
      ->willReturnCallback(
        static fn(string $langcode, string $name): Config => match ($langcode) {
          'en' => $site_language_override,
          'de' => $site_default_override,
          default => $empty_override,
        }
      );

    return new NotificationTemplateResolver(
      $catalog,
      $jurisdiction_config,
      $config_factory,
      $language_manager,
      $language_override,
    );
  }

  /**
   * Builds a language override config mock for status_change.
   *
   * @param array<string, mixed>|null $template
   *   Template value returned by the override.
   */
  private function templateOverrideConfig(?array $template): Config {
    $override = $this->createMock(Config::class);
    $override->method('get')
      ->willReturnCallback(static fn(string $key): ?array => $key === 'status_change' ? $template : NULL);
    return $override;
  }

  /**
   * Creates a notification template catalogue mock.
   */
  private function catalog(): NotificationTemplateCatalogInterface {
    $definitions = [
      'status_change' => [
        'subject' => 'Catalog subject',
        'body' => 'Catalog body',
        'enabled' => TRUE,
        'requiredTokens' => ['@reference_number'],
        'lockedEnabled' => FALSE,
      ],
      'application_confirmation_request' => [
        'subject' => 'Catalog confirmation subject',
        'body' => 'Catalog confirmation body',
        'enabled' => TRUE,
        'requiredTokens' => ['@confirmation_url'],
        'lockedEnabled' => TRUE,
      ],
    ];

    $catalog = $this->createMock(NotificationTemplateCatalogInterface::class);
    $catalog->method('get')
      ->willReturnCallback(static fn(string $key): ?array => $definitions[$key] ?? NULL);
    $catalog->method('isLockedEnabled')
      ->willReturnCallback(static fn(string $key): bool => (bool) ($definitions[$key]['lockedEnabled'] ?? FALSE));

    return $catalog;
  }

}
