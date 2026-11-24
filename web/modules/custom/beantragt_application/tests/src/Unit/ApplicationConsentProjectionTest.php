<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\ApplicationConsentProjection;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the consent blocks of staff and citizen application details.
 */
#[CoversClass(ApplicationConsentProjection::class)]
#[Group('beantragt_application')]
final class ApplicationConsentProjectionTest extends UnitTestCase {

  /**
   * Given consents expose time, version and Stelle, never text or hash.
   */
  public function testGivenConsentOmitsTextAndHash(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasProfileConsent')->willReturn(TRUE);
    $application->method('getProfileConsentRecord')->willReturn([
      'time' => 1781431200,
      'locale' => 'de',
      'textVersion' => '2026-10-20',
      'text' => 'Meine Kontakt- und Adressdaten ...',
      'textHash' => str_repeat('a', 64),
      'scope' => ['type' => 'org', 'id' => 4, 'uuid' => 'x', 'label' => 'Bürgerbüro'],
      'source' => 'public_submit',
    ]);
    $application->method('hasBankConsent')->willReturn(FALSE);
    $application->method('getBankConsentRecord')->willReturn([]);

    $projection = ApplicationConsentProjection::project($application);

    $this->assertSame([
      'given' => TRUE,
      'at' => '2026-06-14T10:00:00+00:00',
      // Unconfirmed: the consent is not effective yet.
      'effectiveAt' => NULL,
      'textVersion' => '2026-10-20',
      'scopeLabel' => 'Bürgerbüro',
    ], $projection['profileConsent']);
    $this->assertSame([
      'given' => FALSE,
      'at' => NULL,
      'effectiveAt' => NULL,
      'textVersion' => NULL,
      'scopeLabel' => NULL,
    ], $projection['bankConsent']);
  }

  /**
   * A consent confirmed by link takes effect at the confirmation.
   */
  public function testConfirmedConsentIsEffectiveAtConfirmation(): void {
    $confirmed = $this->createMock(FieldItemListInterface::class);
    $confirmed->method('isEmpty')->willReturn(FALSE);
    $confirmed->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? '1781434800' : NULL);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('isEmailConfirmed')->willReturn(TRUE);
    $application->method('hasField')->willReturn(TRUE);
    $application->method('get')->with('field_email_confirmed_time')->willReturn($confirmed);
    $application->method('hasProfileConsent')->willReturn(TRUE);
    $application->method('getProfileConsentRecord')->willReturn([
      'time' => 1781431200,
      'textVersion' => '2026-10-20',
      'scope' => ['label' => 'Bürgerbüro'],
    ]);
    $application->method('hasBankConsent')->willReturn(FALSE);
    $application->method('getBankConsentRecord')->willReturn([]);

    $projection = ApplicationConsentProjection::project($application);

    $this->assertSame('2026-06-14T10:00:00+00:00', $projection['profileConsent']['at']);
    $this->assertSame('2026-06-14T11:00:00+00:00', $projection['profileConsent']['effectiveAt']);
  }

}
