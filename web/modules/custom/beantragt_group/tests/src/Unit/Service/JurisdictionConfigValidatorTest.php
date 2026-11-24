<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_group\Service\JurisdictionConfigValidator;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the jurisdiction settings validators.
 */
#[CoversClass(JurisdictionConfigValidator::class)]
#[Group('beantragt_group')]
final class JurisdictionConfigValidatorTest extends UnitTestCase {

  /**
   * The validator under test.
   */
  private JurisdictionConfigValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new JurisdictionConfigValidator(new EmailValidator());
  }

  /**
   * Valid general-section values pass.
   */
  #[DataProvider('validGeneralValueProvider')]
  public function testValidGeneralValues(string $key, mixed $value): void {
    $this->assertNull($this->validator->validateGeneralValue($key, $value));
  }

  /**
   * Provides valid general-section values.
   */
  public static function validGeneralValueProvider(): array {
    return [
      'platform name' => ['platformName', 'Stadt Musterstadt'],
      'platform name empty' => ['platformName', ''],
      'email footer' => ['emailFooter', "Mit freundlichen Grüßen\nIhr Bürgerbüro"],
      'email footer max length' => ['emailFooter', str_repeat('a', 2000)],
      'legal notice html' => ['legalNotice', '<h2>Impressum</h2><p>Stadt Musterstadt</p>'],
      'legal notice bare ampersand' => ['legalNotice', 'AGB & Datenschutz: Müller & Partner GmbH'],
      'legal notice self-closing br' => ['legalNotice', 'Zeile 1<br/>Zeile 2'],
      'privacy policy html' => ['privacyPolicy', '<p>Datenschutz</p>'],
      'privacy policy script accepted, sanitized on write' => ['privacyPolicy', '<p>Ok</p><script>alert(1)</script>'],
      'contact email' => ['contactEmail', 'rathaus@musterstadt.example.org'],
      'contact email empty' => ['contactEmail', ''],
      'website https' => ['website', 'https://www.musterstadt.de/rathaus'],
      'website http' => ['website', 'http://musterstadt.example'],
      'website empty' => ['website', ''],
      'phone' => ['phone', '+49 (0) 30 23125-260'],
      'phone empty' => ['phone', ''],
    ];
  }

  /**
   * Invalid general-section values are rejected with a message.
   */
  #[DataProvider('invalidGeneralValueProvider')]
  public function testInvalidGeneralValues(string $key, mixed $value): void {
    $this->assertNotNull($this->validator->validateGeneralValue($key, $value));
  }

  /**
   * Provides invalid general-section values.
   */
  public static function invalidGeneralValueProvider(): array {
    return [
      'platform name html' => ['platformName', '<b>Musterstadt</b>'],
      'platform name too long' => ['platformName', str_repeat('a', 101)],
      'platform name non-string' => ['platformName', 42],
      'email footer html' => ['emailFooter', '<script>alert(1)</script>'],
      'email footer too long' => ['emailFooter', str_repeat('a', 2001)],
      'legal notice too long' => ['legalNotice', str_repeat('a', 50001)],
      'privacy policy non-string' => ['privacyPolicy', ['html' => 'x']],
      'contact email invalid' => ['contactEmail', 'not-an-email'],
      'contact email non-string' => ['contactEmail', 7],
      'website javascript' => ['website', 'javascript:alert(1)'],
      'website ftp' => ['website', 'ftp://example.com'],
      'website not a url' => ['website', 'https://'],
      'website too long' => ['website', 'https://example.com/' . str_repeat('a', 250)],
      'phone letters' => ['phone', 'call me maybe'],
      'phone too long' => ['phone', str_repeat('1', 51)],
      'unknown key' => ['somethingElse', 'value'],
    ];
  }

  /**
   * Color values accept Tailwind palette names and HEX codes.
   */
  public function testValidColorValues(): void {
    $this->assertNull($this->validator->validateColorValue('blue'));
    $this->assertNull($this->validator->validateColorValue('rose'));
    $this->assertNull($this->validator->validateColorValue('#FF5733'));
    $this->assertNull($this->validator->validateColorValue('#abc'));
  }

  /**
   * Invalid color values are rejected.
   */
  public function testInvalidColorValues(): void {
    $this->assertNotNull($this->validator->validateColorValue('hotpink'));
    $this->assertNotNull($this->validator->validateColorValue('#GGHHII'));
    $this->assertNotNull($this->validator->validateColorValue('#FF57331'));
    $this->assertNotNull($this->validator->validateColorValue(['blue']));
    $this->assertNotNull($this->validator->validateColorValue('blue; } body { display: none'));
  }

  /**
   * Logo data URLs accept bounded PNG, JPEG and inert SVG payloads.
   */
  #[DataProvider('validLogoDataUrlProvider')]
  public function testValidLogoDataUrls(string $data_url): void {
    $this->assertNull($this->validator->validateLogoDataUrl($data_url));
  }

  /**
   * Provides valid logo data URLs.
   *
   * @return array<string, array{string}>
   *   Valid data URL payloads.
   */
  public static function validLogoDataUrlProvider(): array {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16" fill="#2563eb"/></svg>';

    return [
      'empty removal value' => [''],
      'png magic bytes' => [self::dataUrl('image/png', "\x89PNG\r\n\x1A\npayload")],
      'jpeg magic bytes' => [self::dataUrl('image/jpeg', "\xFF\xD8\xFFpayload")],
      'svg with namespace' => [self::dataUrl('image/svg+xml', $svg)],
    ];
  }

  /**
   * The dark logo takes the same checks; the message names its own field.
   */
  #[DataProvider('validLogoDataUrlProvider')]
  public function testValidDarkLogoDataUrls(string $data_url): void {
    $this->assertNull($this->validator->validateLogoDataUrl($data_url, 'logoDarkDataUrl'));
  }

  /**
   * Every refusal of the dark logo names logoDarkDataUrl, never logoDataUrl.
   */
  #[DataProvider('invalidLogoDataUrlProvider')]
  public function testInvalidDarkLogoDataUrlsNameTheirField(mixed $data_url): void {
    $error = $this->validator->validateLogoDataUrl($data_url, 'logoDarkDataUrl');
    $this->assertNotNull($error);
    $this->assertStringStartsWith('logoDarkDataUrl ', $error);
    $light = $this->validator->validateLogoDataUrl($data_url);
    $this->assertStringStartsWith('logoDataUrl ', (string) $light);
    $this->assertSame(substr((string) $light, strlen('logoDataUrl')), substr($error, strlen('logoDarkDataUrl')));
  }

  /**
   * Dangerous or malformed logo payloads are rejected.
   */
  #[DataProvider('invalidLogoDataUrlProvider')]
  public function testInvalidLogoDataUrls(mixed $data_url): void {
    $this->assertNotNull($this->validator->validateLogoDataUrl($data_url));
  }

  /**
   * Provides invalid logo data URLs.
   *
   * @return array<string, array{mixed}>
   *   Invalid data URL payloads.
   */
  public static function invalidLogoDataUrlProvider(): array {
    return [
      'non string' => [42],
      'not a data url' => ['https://example.com/logo.png'],
      'unsupported image type' => [self::dataUrl('image/gif', 'GIF89a')],
      'invalid png payload' => [self::dataUrl('image/png', 'not-png')],
      'svg script tag' => [self::dataUrl('image/svg+xml', '<svg><script>alert(1)</script></svg>')],
      'svg foreign object' => [self::dataUrl('image/svg+xml', '<svg><foreignObject><p>x</p></foreignObject></svg>')],
      'svg event handler' => [self::dataUrl('image/svg+xml', '<svg onload="alert(1)"></svg>')],
      'svg external href' => [self::dataUrl('image/svg+xml', '<svg><image href="https://evil.example/logo.png"/></svg>')],
      'svg data href' => [self::dataUrl('image/svg+xml', '<svg><image href="data:image/png;base64,AAAA"/></svg>')],
      'svg external css url' => [self::dataUrl('image/svg+xml', '<svg><rect style="fill:url(https://evil.example/a.svg#x)"/></svg>')],
      'svg without root' => [self::dataUrl('image/svg+xml', '<html></html>')],
    ];
  }

  /**
   * Safe custom CSS passes.
   */
  public function testValidCustomCss(): void {
    $this->assertNull($this->validator->validateCustomCss(''));
    $this->assertNull($this->validator->validateCustomCss(
      ':root { --brand: #005599; } .header { background: var(--brand); } .logo { background: url("/files/logo.png"); }'
    ));
  }

  /**
   * Dangerous CSS injection vectors are blocked.
   */
  #[DataProvider('dangerousCssProvider')]
  public function testDangerousCustomCssIsBlocked(mixed $css): void {
    $this->assertNotNull($this->validator->validateCustomCss($css));
  }

  /**
   * Provides dangerous CSS payloads.
   */
  public static function dangerousCssProvider(): array {
    return [
      'import' => ['@import url("evil.css");'],
      'import uppercase' => ['@IMPORT url("evil.css");'],
      'javascript uri' => ['a { background: url(javascript:alert(1)); }'],
      'expression' => ['width: expression(alert(1));'],
      'behavior' => ['behavior: url(evil.htc);'],
      'moz binding' => ['-moz-binding: url(evil.xml);'],
      'script tag' => ['</style>< script>alert(1)</script>'],
      'external url http' => ['.x { background: url(http://evil.example/x.png); }'],
      'external url https quoted' => [".x { background: url( 'https://evil.example/x.png' ); }"],
      'protocol-relative url' => ['.x { background: url(//evil.example/x.png); }'],
      'protocol-relative url quoted' => ['.x { background: url("//evil.example/x.png"); }'],
      'data uri' => ['.x { background: url(data:text/html;base64,PHNjcmlwdD4=); }'],
      'blob uri' => ['.x { background: url(blob:https://evil.example/uuid); }'],
      'vbscript uri' => ['.x { background: url(vbscript:msgbox(1)); }'],
      'non string' => [['css' => 'x']],
      'too long' => [str_repeat('a', 50001)],
    ];
  }

  /**
   * Builds a base64 data URL for tests.
   */
  private static function dataUrl(string $mime, string $payload): string {
    return 'data:' . $mime . ';base64,' . base64_encode($payload);
  }

  /**
   * Valid languages configs pass.
   */
  public function testValidLanguages(): void {
    $this->assertNull($this->validator->validateLanguages(['default' => 'de', 'available' => ['de']]));
    $this->assertNull($this->validator->validateLanguages(['default' => 'en', 'available' => ['de', 'en']]));
  }

  /**
   * Every jurisdiction language has its own applicant profile consent text.
   *
   * Without it the consent checkbox would show the German fallback text.
   */
  public function testEverySupportedLanguageHasProfileConsentText(): void {
    foreach (JurisdictionConfigValidator::SUPPORTED_LANGUAGES as $langcode) {
      $this->assertContains($langcode, ProfileConsentText::locales());
    }
  }

  /**
   * Invalid languages configs are rejected.
   */
  #[DataProvider('invalidLanguagesProvider')]
  public function testInvalidLanguages(array $languages): void {
    $this->assertNotNull($this->validator->validateLanguages($languages));
  }

  /**
   * Provides invalid languages configs.
   */
  public static function invalidLanguagesProvider(): array {
    return [
      'unsupported default' => [['default' => 'fr', 'available' => ['fr']]],
      'missing default' => [['available' => ['de']]],
      'non-string default' => [['default' => 1, 'available' => ['de']]],
      'empty available' => [['default' => 'de', 'available' => []]],
      'available not array' => [['default' => 'de', 'available' => 'de']],
      'unsupported available' => [['default' => 'de', 'available' => ['de', 'fr']]],
      'default not in available' => [['default' => 'en', 'available' => ['de']]],
    ];
  }

  /**
   * Valid retention values pass.
   */
  #[DataProvider('validRetentionValueProvider')]
  public function testValidRetentionValues(string $key, mixed $value): void {
    $this->assertNull($this->validator->validateRetentionValue($key, $value));
  }

  /**
   * Provides valid retention values.
   *
   * @return array<string, array{string, mixed}>
   *   Valid retention values.
   */
  public static function validRetentionValueProvider(): array {
    return [
      'disabled' => ['dataRetentionDays', 0],
      'two years' => ['dataRetentionDays', 730],
      'string int' => ['dataRetentionDays', '365'],
      'maximum' => ['dataRetentionDays', 36500],
    ];
  }

  /**
   * Invalid retention values are rejected.
   */
  #[DataProvider('invalidRetentionValueProvider')]
  public function testInvalidRetentionValues(string $key, mixed $value): void {
    $this->assertNotNull($this->validator->validateRetentionValue($key, $value));
  }

  /**
   * Provides invalid retention values.
   *
   * @return array<string, array{string, mixed}>
   *   Invalid retention values.
   */
  public static function invalidRetentionValueProvider(): array {
    return [
      'negative' => ['dataRetentionDays', -1],
      'fraction' => ['dataRetentionDays', 12.5],
      'too large' => ['dataRetentionDays', 36501],
      'empty string' => ['dataRetentionDays', ''],
      'text' => ['dataRetentionDays', 'soon'],
      'bool true' => ['dataRetentionDays', TRUE],
      'bool false' => ['dataRetentionDays', FALSE],
      'null' => ['dataRetentionDays', NULL],
      'float' => ['dataRetentionDays', 12.0],
      'array' => ['dataRetentionDays', ['days' => 30]],
      'object' => ['dataRetentionDays', new \stdClass()],
      'unknown key' => ['retentionDays', 730],
    ];
  }

  /**
   * Valid resubmission values pass.
   */
  #[DataProvider('validResubmissionValueProvider')]
  public function testValidResubmissionValues(string $key, mixed $value): void {
    $this->assertNull($this->validator->validateResubmissionValue($key, $value));
  }

  /**
   * Provides valid resubmission values.
   *
   * @return array<string, array{string, mixed}>
   *   Valid resubmission values.
   */
  public static function validResubmissionValueProvider(): array {
    return [
      'one day' => ['reminderDays', 1],
      'six weeks' => ['reminderDays', 42],
      'string int' => ['reminderDays', '365'],
      'org scope' => ['reminderScope', 'org'],
      'user scope' => ['reminderScope', 'user'],
    ];
  }

  /**
   * Invalid resubmission values are rejected.
   */
  #[DataProvider('invalidResubmissionValueProvider')]
  public function testInvalidResubmissionValues(string $key, mixed $value): void {
    $this->assertNotNull($this->validator->validateResubmissionValue($key, $value));
  }

  /**
   * Provides invalid resubmission values.
   *
   * @return array<string, array{string, mixed}>
   *   Invalid resubmission values.
   */
  public static function invalidResubmissionValueProvider(): array {
    return [
      'zero days' => ['reminderDays', 0],
      'negative days' => ['reminderDays', -1],
      'fraction days' => ['reminderDays', 12.5],
      'too many days' => ['reminderDays', 366],
      'empty string days' => ['reminderDays', ''],
      'text days' => ['reminderDays', 'soon'],
      'bool days' => ['reminderDays', TRUE],
      'array days' => ['reminderDays', ['days' => 30]],
      'invalid scope' => ['reminderScope', 'team'],
      'empty scope' => ['reminderScope', ''],
      'bool scope' => ['reminderScope', TRUE],
      'unknown key' => ['resubmissionDays', 42],
    ];
  }

  /**
   * Valid assistant policy values pass.
   */
  #[DataProvider('validAssistantValueProvider')]
  public function testValidAssistantValues(string $key, mixed $value): void {
    $this->assertNull($this->validator->validateAssistantValue($key, $value));
  }

  /**
   * Provides valid assistant policy values.
   *
   * @return array<string, array{string, mixed}>
   *   Valid assistant policy values.
   */
  public static function validAssistantValueProvider(): array {
    return [
      'neutral tone' => ['tone', 'neutral'],
      'friendly tone' => ['tone', 'friendly'],
      'empathetic tone' => ['tone', 'empathetic'],
      'formal tone' => ['tone', 'formal'],
      'concise verbosity' => ['verbosity', 'concise'],
      'balanced verbosity' => ['verbosity', 'balanced'],
      'conversational verbosity' => ['verbosity', 'conversational'],
      'acknowledgement off' => ['acknowledgementStyle', 'off'],
      'acknowledgement brief' => ['acknowledgementStyle', 'brief'],
      'acknowledgement contextual' => ['acknowledgementStyle', 'contextual'],
      'empty instructions' => ['additionalInstructions', ''],
      'plain instructions' => ['additionalInstructions', 'Nutze kurze Sätze und den Begriff Bürgerbüro.'],
      'maximum instructions' => ['additionalInstructions', str_repeat('a', 2000)],
    ];
  }

  /**
   * Invalid assistant policy values are rejected.
   */
  #[DataProvider('invalidAssistantValueProvider')]
  public function testInvalidAssistantValues(string $key, mixed $value): void {
    $this->assertNotNull($this->validator->validateAssistantValue($key, $value));
  }

  /**
   * Provides invalid assistant policy values.
   *
   * @return array<string, array{string, mixed}>
   *   Invalid assistant policy values.
   */
  public static function invalidAssistantValueProvider(): array {
    return [
      'unknown tone' => ['tone', 'cheerful'],
      'non-string tone' => ['tone', 1],
      'unknown verbosity' => ['verbosity', 'long'],
      'unknown acknowledgement' => ['acknowledgementStyle', 'always'],
      'instructions html' => ['additionalInstructions', '<b>Freundlich</b>'],
      'instructions control character' => ['additionalInstructions', "Freundlich\x00formulieren"],
      'instructions C1 control character' => ['additionalInstructions', "Freundlich\u{0085}formulieren"],
      'instructions too long' => ['additionalInstructions', str_repeat('a', 2001)],
      'instructions non-string' => ['additionalInstructions', ['text' => 'x']],
      'unknown key' => ['temperature', 0.5],
    ];
  }

  /**
   * Valid embed-section values pass.
   */
  #[DataProvider('validEmbedValueProvider')]
  public function testValidEmbedValues(string $key, mixed $value): void {
    $this->assertNull($this->validator->validateEmbedValue($key, $value));
  }

  /**
   * Provides valid embed-section values.
   *
   * @return array<string, array{string, mixed}>
   *   Valid embed values.
   */
  public static function validEmbedValueProvider(): array {
    return [
      'theme auto' => ['defaultTheme', 'auto'],
      'theme light' => ['defaultTheme', 'light'],
      'theme dark' => ['defaultTheme', 'dark'],
      'width percent' => ['defaultWidth', '100%'],
      'width rem' => ['defaultWidth', '48rem'],
      'height px' => ['defaultHeight', '800px'],
      'height vh' => ['defaultHeight', '90vh'],
      'show border true' => ['showBorder', TRUE],
      'show border false' => ['showBorder', FALSE],
    ];
  }

  /**
   * Invalid embed-section values are rejected.
   */
  #[DataProvider('invalidEmbedValueProvider')]
  public function testInvalidEmbedValues(string $key, mixed $value): void {
    $this->assertNotNull($this->validator->validateEmbedValue($key, $value));
  }

  /**
   * Provides invalid embed-section values.
   *
   * @return array<string, array{string, mixed}>
   *   Invalid embed values.
   */
  public static function invalidEmbedValueProvider(): array {
    return [
      'theme invalid' => ['defaultTheme', 'sepia'],
      'theme non string' => ['defaultTheme', 1],
      'width bare number' => ['defaultWidth', '800'],
      'width css injection' => ['defaultWidth', '100%" onload="alert(1)'],
      'width calc' => ['defaultWidth', 'calc(100% - 1rem)'],
      'height zero' => ['defaultHeight', '0px'],
      'height too large' => ['defaultHeight', '10001px'],
      'height non string' => ['defaultHeight', 800],
      'show border non bool' => ['showBorder', 'true'],
      'unknown key' => ['allowedOrigins', 'https://example.org'],
    ];
  }

  /**
   * Valid applicant profile values pass.
   */
  #[DataProvider('validApplicantProfileValueProvider')]
  public function testValidApplicantProfileValues(string $key, mixed $value): void {
    $this->assertNull($this->validator->validateApplicantProfileValue($key, $value));
  }

  /**
   * Provides valid applicant profile values.
   *
   * @return array<string, array{string, mixed}>
   *   Valid applicant profile values.
   */
  public static function validApplicantProfileValueProvider(): array {
    return [
      'enabled' => ['enabled', TRUE],
      'disabled' => ['enabled', FALSE],
      'bank switch on' => ['bankConsent', TRUE],
      'bank switch off' => ['bankConsent', FALSE],
      'organisation scope' => ['scope', 'organisation'],
      'jurisdiction scope' => ['scope', 'jurisdiction'],
    ];
  }

  /**
   * Invalid applicant profile values are rejected.
   */
  #[DataProvider('invalidApplicantProfileValueProvider')]
  public function testInvalidApplicantProfileValues(string $key, mixed $value): void {
    $this->assertNotNull($this->validator->validateApplicantProfileValue($key, $value));
  }

  /**
   * Provides invalid applicant profile values.
   *
   * @return array<string, array{string, mixed}>
   *   Invalid applicant profile values.
   */
  public static function invalidApplicantProfileValueProvider(): array {
    return [
      'enabled string' => ['enabled', 'true'],
      'enabled integer' => ['enabled', 1],
      'enabled null' => ['enabled', NULL],
      'bank switch string' => ['bankConsent', 'true'],
      'short scope' => ['scope', 'org'],
      'global scope' => ['scope', 'everyone'],
      'uppercase scope' => ['scope', 'Organisation'],
      'scope non string' => ['scope', TRUE],
      'unknown key' => ['includeBankDetails', TRUE],
    ];
  }

}
