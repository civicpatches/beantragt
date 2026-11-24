<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_group\Service\JurisdictionConfigBlobValidator;
use Drupal\beantragt_group\Service\JurisdictionConfigValidator;
use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests validation of complete jurisdiction configuration JSON.
 */
#[CoversClass(JurisdictionConfigBlobValidator::class)]
#[Group('beantragt_group')]
final class JurisdictionConfigBlobValidatorTest extends UnitTestCase {

  /**
   * The validator under test.
   */
  private JurisdictionConfigBlobValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $catalog = $this->createMock(NotificationTemplateCatalogInterface::class);
    $catalog->method('has')->willReturnCallback(
      static fn (string $key): bool => $key === 'application_confirmation_request',
    );
    $catalog->method('isLockedEnabled')->willReturn(TRUE);
    $catalog->method('get')->willReturnCallback(
      static fn (string $key): ?array => $key === 'application_confirmation_request'
        ? [
          'subject' => 'Confirm @reference',
          'body' => 'Open @confirmation_url',
          'enabled' => TRUE,
          'requiredTokens' => ['@reference', '@confirmation_url'],
          'lockedEnabled' => TRUE,
        ]
        : NULL,
    );

    $this->validator = new JurisdictionConfigBlobValidator(
      new JurisdictionConfigValidator(new EmailValidator()),
      $catalog,
    );
  }

  /**
   * A complete allowlisted object passes unchanged.
   */
  public function testValidCompleteConfiguration(): void {
    $raw = json_encode([
      'general' => [
        'contactEmail' => 'service@musterstadt.example.org',
        'website' => 'https://musterstadt.example.org',
      ],
      'theme' => [
        'primary' => 'blue',
        'logoDarkDataUrl' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1A\npayload"),
        'logoAlt' => 'Stadt Musterstadt',
      ],
      'languages' => [
        'default' => 'de',
        'available' => ['de', 'en'],
      ],
      'retention' => ['dataRetentionDays' => 365],
      'applicant_profile' => ['enabled' => TRUE, 'scope' => 'organisation'],
      'notification_mail' => [
        'templates' => [
          'application_confirmation_request' => [
            'enabled' => TRUE,
            'default' => [
              'subject' => 'Confirm @reference',
              'body' => 'Open @confirmation_url',
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);

    self::assertSame([], $this->validator->validateRaw($raw));
  }

  /**
   * Malformed JSON and non-object roots fail closed.
   */
  public function testMalformedAndNonObjectJsonAreRejected(): void {
    self::assertNotEmpty($this->validator->validateRaw('{not-json'));
    self::assertNotEmpty($this->validator->validateRaw('["theme"]'));
  }

  /**
   * Unknown sections and keys cannot bypass the API allowlist.
   */
  public function testUnknownStorageShapeIsRejected(): void {
    $errors = $this->validator->validateRaw(json_encode([
      'general' => [
        'contactEmail' => 'service@musterstadt.example.org',
        'dangerous' => '<script>alert(1)</script>',
      ],
      'internal' => ['secret' => TRUE],
    ], JSON_THROW_ON_ERROR));

    self::assertContains('Unknown key: general.dangerous.', $errors);
    self::assertContains('Unknown section: internal.', $errors);
  }

  /**
   * The applicant profile switch keeps its allowlist and value checks.
   */
  public function testApplicantProfileSectionIsValidated(): void {
    $errors = $this->validator->validateRaw(json_encode([
      'applicant_profile' => [
        'enabled' => 'yes',
        'scope' => 'everyone',
        'includeBankDetails' => TRUE,
      ],
    ], JSON_THROW_ON_ERROR));

    self::assertContains('Unknown key: applicant_profile.includeBankDetails.', $errors);
    self::assertContains('applicant_profile.enabled: enabled must be a boolean.', $errors);
    self::assertContains('applicant_profile.scope: scope must be one of: organisation, jurisdiction.', $errors);
    self::assertSame([], $this->validator->validateRaw('{"applicant_profile":{"enabled":false}}'));
    self::assertSame([], $this->validator->validateRaw('{"applicant_profile":{}}'));
  }

  /**
   * Invalid API values and required notification tokens are rejected.
   */
  public function testInvalidValuesAndTemplateTokensAreRejected(): void {
    $errors = $this->validator->validateRaw(json_encode([
      'languages' => [
        'default' => 'fr',
        'available' => ['fr'],
      ],
      'notification_mail' => [
        'templates' => [
          'application_confirmation_request' => [
            'enabled' => FALSE,
            'default' => [
              'subject' => 'Confirm request',
              'body' => 'No confirmation link',
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    self::assertNotEmpty(array_filter(
      $errors,
      static fn (string $error): bool => str_starts_with($error, 'languages:'),
    ));
    self::assertNotEmpty(array_filter(
      $errors,
      static fn (string $error): bool => str_contains($error, 'cannot disable a system template'),
    ));
    self::assertNotEmpty(array_filter(
      $errors,
      static fn (string $error): bool => str_contains($error, 'must include @confirmation_url'),
    ));
  }

  /**
   * A stored dark logo is checked like the logo and named in the error.
   */
  public function testInvalidDarkLogoIsRejectedWithItsField(): void {
    $errors = $this->validator->validateRaw(json_encode([
      'theme' => ['logoDarkDataUrl' => 'https://example.com/dark.png'],
    ], JSON_THROW_ON_ERROR));
    self::assertSame(['theme.logoDarkDataUrl: logoDarkDataUrl must be a PNG, JPEG, or SVG data URL.'], $errors);
  }

  /**
   * Notification overrides fail closed without the catalogue service.
   */
  public function testNotificationOverridesRequireCatalog(): void {
    $validator = new JurisdictionConfigBlobValidator(
      new JurisdictionConfigValidator(new EmailValidator()),
    );
    $errors = $validator->validateRaw(json_encode([
      'notification_mail' => [
        'templates' => [
          'unknown' => ['enabled' => TRUE],
        ],
      ],
    ], JSON_THROW_ON_ERROR));

    self::assertSame(
      ['notification_mail.templates cannot be validated while the notification template catalog is unavailable.'],
      $errors,
    );
  }

}
