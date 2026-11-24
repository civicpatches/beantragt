<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Kernel;

use Drupal\beantragt_notification\Service\TenantMailBranding;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies branding against real jurisdiction field storage.
 */
#[Group('beantragt_notification')]
final class TenantMailBrandingTest extends GroupKernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_notification']);
    $this->installEntitySchema('workflow_status');
  }

  /**
   * Stored tenant fields become a safe renderer package.
   */
  public function testResolvesJurisdictionBrandingById(): void {
    $logo = "\x89PNG\r\n\x1a\nsynthetic-logo";
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Musterstadt',
      'field_slug' => 'musterstadt',
      'field_platform_name' => 'Stadt Musterstadt',
      'field_email_footer' => "Rathaus Musterstadt\nDigitales Amt",
      'field_org_email' => 'service@musterstadt.example.org',
      'field_jur_config' => json_encode([
        'theme' => [
          'primary' => '#16A34A',
          'secondary' => '#334155',
          'logoAlt' => 'Wappen der Stadt Musterstadt',
          'logoDataUrl' => 'data:image/png;base64,' . base64_encode($logo),
        ],
      ], JSON_UNESCAPED_SLASHES),
    ]);

    $branding = $this->branding()->resolve([
      'jurisdiction_id' => $jurisdiction->id(),
    ], 'de');

    $this->assertSame((int) $jurisdiction->id(), $branding['tenant_id']);
    $this->assertSame('Stadt Musterstadt', $branding['name']);
    $this->assertSame('#16a34a', $branding['primary_color']);
    $this->assertSame('#000000', $branding['primary_foreground_color']);
    $this->assertSame('#334155', $branding['secondary_color']);
    $this->assertSame('#334155', $branding['secondary_text_color']);
    $this->assertSame('Wappen der Stadt Musterstadt', $branding['logo_alt']);
    $this->assertSame('Rathaus Musterstadt' . "\n" . 'Digitales Amt', $branding['footer']);
    $this->assertSame('service@musterstadt.example.org', $branding['reply_to']);
    $this->assertSame('image/png', $branding['inline_logo']['mime']);
    $this->assertSame($logo, $branding['inline_logo']['content']);
    $this->assertSame('tenant-logo@beantragt.ai', $branding['inline_logo']['cid']);
  }

  /**
   * The dark screen logo never reaches a mail, not even as a fallback.
   */
  public function testDarkLogoIsNeverEmbedded(): void {
    $light = "\x89PNG\r\n\x1a\nlight-logo";
    $dark = "\x89PNG\r\n\x1a\ndark-logo";
    $both = $this->createGroup([
      'type' => 'jur',
      'label' => 'Musterstadt',
      'field_slug' => 'musterstadt',
      'field_jur_config' => json_encode([
        'theme' => [
          'logoDataUrl' => 'data:image/png;base64,' . base64_encode($light),
          'logoDarkDataUrl' => 'data:image/png;base64,' . base64_encode($dark),
        ],
      ], JSON_UNESCAPED_SLASHES),
    ]);
    $dark_only = $this->createGroup([
      'type' => 'jur',
      'label' => 'Dunkelstadt',
      'field_slug' => 'dunkelstadt',
      'field_jur_config' => json_encode([
        'theme' => ['logoDarkDataUrl' => 'data:image/png;base64,' . base64_encode($dark)],
      ], JSON_UNESCAPED_SLASHES),
    ]);

    $branding = $this->branding()->resolve(['jurisdiction_id' => $both->id()], 'de');
    $this->assertSame($light, $branding['inline_logo']['content']);

    $branding = $this->branding()->resolve(['jurisdiction_id' => $dark_only->id()], 'de');
    $this->assertNull($branding['inline_logo']);
  }

  /**
   * Tenant palette extremes always produce accessible mail text colors.
   */
  public function testChoosesAccessibleForegroundColors(): void {
    $cases = [
      ['#ffffff', '#000000'],
      ['#ffff00', '#000000'],
      ['#000000', '#ffffff'],
      ['#16a34a', '#000000'],
    ];

    foreach ($cases as $index => [$color, $expectedForeground]) {
      $jurisdiction = $this->createGroup([
        'type' => 'jur',
        'label' => 'Contrast ' . $index,
        'field_jur_config' => json_encode([
          'theme' => [
            'primary' => $color,
            'secondary' => $color,
          ],
        ], JSON_UNESCAPED_SLASHES),
      ]);

      $branding = $this->branding()->resolve([
        'jurisdiction_id' => $jurisdiction->id(),
      ], 'en');

      $this->assertSame($expectedForeground, $branding['primary_foreground_color']);
      $this->assertSame(
        $color === '#000000' ? '#000000' : '#0f172a',
        $branding['secondary_text_color'],
      );
    }
  }

  /**
   * The wording update reaches the site and jurisdiction mail defaults.
   */
  public function testOfficeWordingUpdateChangesOnlyGermanStandardPhrases(): void {
    $mail = $this->config('beantragt_notification.mail');
    $old_status = 'Bei Fragen wenden Sie sich bitte an Ihre zuständige Stelle.';
    $new_status = 'Bei Fragen wenden Sie sich bitte an Ihre zuständige Dienststelle.';
    $old_delegation = 'Ein Antrag wurde an Ihre Organisation delegiert.';
    $new_delegation = 'Ein Antrag wurde an Ihre Dienststelle delegiert.';
    $old_rejection = "Wenden Sie sich bitte an\nIhre zuständige Stelle.";
    $new_rejection = "Wenden Sie sich bitte an\nIhre zuständige Dienststelle.";
    $this->assertStringContainsString($new_status, $mail->get('status_change.body'));
    $this->assertStringContainsString($new_delegation, $mail->get('delegation_alert.body'));
    $this->assertStringContainsString('Ihre zuständige Dienststelle.', $mail->get('rejection.body'));

    $override = $this->container->get('language.config_factory_override');
    $english = $override->getOverride('en', 'beantragt_notification.mail');
    $english_before = $english->getRawData();
    $german = $override->getOverride('de', 'beantragt_notification.mail');
    $mail->set('status_change.body', "$old_status\n@org_name")->save();
    $german->set('rejection.body', $old_rejection)->save();

    $delegation_tokens = "@reference_number\n@source_org\n@target_org\n@note\n@admin_application_url";
    $rejection_tokens = "@full_name\n@form_title\n@reference_number\n@rejection_reason\n@citizen_application_url\n@org_name";
    $section = [
      'templates' => [
        'delegation_alert' => [
          'enabled' => FALSE,
          'default' => [
            'subject' => 'Eigener Betreff',
            'body' => "$old_delegation\nZusatztext\n$delegation_tokens",
          ],
          'translations' => [
            'de' => ['body' => "$old_delegation\n$delegation_tokens"],
            'en' => ['body' => "An application has been delegated to your organisation.\n$delegation_tokens"],
          ],
        ],
        'rejection' => [
          'default' => ['body' => "Kontaktieren Sie bitte die verantwortliche Stelle.\n$rejection_tokens"],
        ],
      ],
    ];
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Stadt Musterstadt',
      'field_slug' => 'musterstadt',
      'field_jur_config' => json_encode(['notification_mail' => $section], JSON_THROW_ON_ERROR),
    ]);

    require_once dirname(__DIR__, 3) . '/beantragt_notification.install';
    \beantragt_notification_update_10021();
    \beantragt_notification_update_10021();

    $german = $override->getOverride('de', 'beantragt_notification.mail');
    $english = $override->getOverride('en', 'beantragt_notification.mail');
    $this->assertSame("$new_status\n@org_name", $mail->get('status_change.body'));
    $this->assertSame($new_rejection, $german->get('rejection.body'));
    $this->assertSame($english_before, $english->getRawData());
    $section['templates']['delegation_alert']['default']['body'] = "$new_delegation\nZusatztext\n$delegation_tokens";
    $section['templates']['delegation_alert']['translations']['de']['body'] = "$new_delegation\n$delegation_tokens";
    $fresh = $this->entityTypeManager()->getStorage('group')->loadUnchanged($jurisdiction->id());
    $service = $this->container->get('beantragt_group.jurisdiction_config');
    $this->assertSame($section, $service->getSection($fresh, 'notification_mail'));
  }

  /**
   * Every shipped citizen salutation uses a name only when one is available.
   */
  public function testCitizenGreetingsInGermanAndEnglishTemplates(): void {
    $config = dirname(__DIR__, 3) . '/config/install/';
    $renderer = $this->container->get('beantragt_notification.html_renderer');
    foreach (['de' => 'Guten Tag', 'en' => 'Hello'] as $locale => $greeting) {
      $path = $locale === 'en' ? 'language/en/' : '';
      $templates = Yaml::parseFile($config . $path . 'beantragt_notification.mail.yml');
      foreach (['application_confirmation', 'status_change', 'approval', 'rejection', 'application_comment_staff'] as $key) {
        foreach (['' => '', '   ' => '', 'applicant@example.test' => '', 'Alex Kim' => ' Alex Kim'] as $name => $suffix) {
          $message = ['langcode' => $locale, 'body' => []];
          \beantragt_notification_mail($key, $message, [
            'resolvedTemplate' => $templates[$key],
            'replacements' => ['@full_name' => $name],
          ]);
          $parts = $renderer->render([], $message);
          self::assertStringStartsWith($greeting . $suffix . ",\n", $parts['plain'], "$locale/$key");
          self::assertStringContainsString($greeting . $suffix . ',<br', $parts['html'], "$locale/$key");
        }
      }
    }
  }

  /**
   * Returns the branding service under test.
   */
  private function branding(): TenantMailBranding {
    return $this->container->get('beantragt_notification.mail_branding');
  }

}
