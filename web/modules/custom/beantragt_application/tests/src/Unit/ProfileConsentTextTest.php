<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\Controller\ApplicationDataController;
use Drupal\beantragt_application\Controller\PublicApplicationApiController;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the backend-owned applicant profile consent texts.
 */
#[CoversClass(ProfileConsentText::class)]
#[Group('beantragt_application')]
final class ProfileConsentTextTest extends UnitTestCase {

  /**
   * Both kinds exist in German and English and carry their own version.
   */
  public function testVersionKindsAndLocales(): void {
    self::assertSame(['contact', 'bank'], ProfileConsentText::kinds());
    self::assertSame(['de', 'en'], ProfileConsentText::locales());
    foreach (ProfileConsentText::kinds() as $kind) {
      self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}(\.\d+)?$/', ProfileConsentText::version($kind), $kind);
    }
  }

  /**
   * An unknown kind has no version.
   */
  public function testUnknownKindHasNoVersion(): void {
    $this->expectException(\InvalidArgumentException::class);
    ProfileConsentText::version('tax');
  }

  /**
   * Every submission locale has its own consent text.
   *
   * A submission in a locale without text would store a German snapshot.
   */
  public function testEverySubmissionLocaleHasConsentText(): void {
    foreach ([PublicApplicationApiController::SUBMISSION_LOCALES, ApplicationDataController::SUBMISSION_LOCALES] as $submission_locales) {
      foreach (array_keys($submission_locales) as $locale) {
        self::assertContains($locale, ProfileConsentText::locales());
      }
    }
  }

  /**
   * Every template carries the placeholder exactly once.
   */
  public function testEveryTemplateCarriesThePlaceholder(): void {
    foreach (ProfileConsentText::kinds() as $kind) {
      foreach (ProfileConsentText::locales() as $locale) {
        self::assertSame(1, substr_count(ProfileConsentText::template($kind, $locale), ProfileConsentText::PLACEHOLDER), $kind . '/' . $locale);
      }
    }
  }

  /**
   * The wording matches the approved consent texts.
   *
   * Contact 2026-10-20.3, bank 2026-10-20.2. A wording change needs a new
   * version per changed kind; records with an older version stay valid proof
   * of the text shown then.
   */
  public function testApprovedWording(): void {
    self::assertSame('2026-11-21', ProfileConsentText::version(ProfileConsentText::KIND_CONTACT));
    self::assertSame('2026-11-21', ProfileConsentText::version(ProfileConsentText::KIND_BANK));
    self::assertSame(
      'Ich willige ein, dass mein Name, mein Geburtsdatum, meine Anschrift, E-Mail-Adresse und Telefonnummer sowie, falls angegeben, Geschlecht, Staatsangehörigkeit und Familienstand aus diesem Antrag in meinem Profil gespeichert werden, damit meine künftigen Anträge bei {stelle} vorausgefüllt werden. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert. Meine E-Mail-Adresse bleibt danach als Anmeldeadresse gespeichert, bis ich unter „Meine Daten“ alle meine Daten lösche.',
      ProfileConsentText::template(ProfileConsentText::KIND_CONTACT, 'de', '2026-10-20.3'),
    );
    self::assertSame(
      'Ich willige ein, dass meine Bankverbindung (IBAN und Kontoinhaber) aus diesem Antrag verschlüsselt in meinem Profil gespeichert wird, damit meine künftigen Anträge bei {stelle} vorausgefüllt werden. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.',
      ProfileConsentText::template(ProfileConsentText::KIND_BANK, 'de', '2026-10-20.2'),
    );
    self::assertSame(
      'I consent to my name, date of birth, address, email address and phone number, and where given my gender, nationality and marital status, from this application being stored in my profile so that my future applications to {stelle} are prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future; my earlier applications stay unchanged. My email address then stays stored as my sign-in address until I delete all my data under “My Data”.',
      ProfileConsentText::template(ProfileConsentText::KIND_CONTACT, 'en', '2026-10-20.3'),
    );
    self::assertSame(
      'I consent to my bank details (IBAN and account holder) from this application being stored encrypted in my profile so that my future applications to {stelle} are prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future; my earlier applications stay unchanged.',
      ProfileConsentText::template(ProfileConsentText::KIND_BANK, 'en', '2026-10-20.2'),
    );
  }

  /**
   * Every text speaks as the consenting person and explains the withdrawal.
   *
   * The checkbox is the citizen's own declaration: no switch to "Sie"/"you",
   * no jargon such as "Identitätsangaben", and the withdrawal is the one
   * "Meine Daten" offers.
   */
  public function testTextsUseFirstPersonAndNameTheWithdrawal(): void {
    foreach (ProfileConsentText::kinds() as $kind) {
      $de = ProfileConsentText::template($kind, 'de');
      self::assertStringStartsWith('Ich willige ein', $de, $kind);
      self::assertDoesNotMatchRegularExpression('/\b(Sie|Ihre?[nmrs]?)\b/u', $de, $kind);
      self::assertStringNotContainsString('Identitätsangaben', $de, $kind);
      self::assertStringContainsString('unter „Meine Daten“ widerrufen', $de, $kind);
      self::assertStringContainsString('Der Widerruf gilt für die Zukunft', $de, $kind);

      $en = ProfileConsentText::template($kind, 'en');
      self::assertStringStartsWith('I consent', $en, $kind);
      self::assertDoesNotMatchRegularExpression('/\byour?\b/i', $en, $kind);
      self::assertStringNotContainsString('identity details', $en, $kind);
      self::assertStringContainsString('withdraw this consent at any time under “My Data”', $en, $kind);
    }
  }

  /**
   * The contact text says the email stays as sign-in address after withdrawal.
   *
   * The withdrawal keeps the address, so the consent must not promise more.
   */
  public function testContactTextNamesTheEmailKeptAfterWithdrawal(): void {
    self::assertStringContainsString('Meine E-Mail-Adresse bleibt danach als Anmeldeadresse gespeichert', ProfileConsentText::render('contact', 'de', 'Hauptamt', '2026-11-20', ['person.email']));
    self::assertStringContainsString('My email address then stays stored as my sign-in address', ProfileConsentText::render('contact', 'en', 'Hauptamt', '2026-11-20', ['person.email']));
    self::assertStringNotContainsString('E-Mail-Adresse', ProfileConsentText::render('contact', 'de', 'Hauptamt', '2026-11-20', ['person.full_name']));
  }

  /**
   * A previous wording renders and hashes as it was shown.
   *
   * Mutation probe: rendering the current template for an echoed previous
   * version would store a text the citizen never saw.
   */
  public function testPreviousWordingRendersAndHashesItself(): void {
    $previous = ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'de', 'Bürgerbüro', '2026-10-20.2');
    self::assertStringEndsWith('meine bisherigen Anträge bleiben unverändert.', $previous);
    self::assertNotSame(ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'de', 'Bürgerbüro'), $previous);
    $expected_hashes = [
      ['contact', '2026-10-20.3', 'de', 'cdc18936ef706461de20be42371116daebda7f71c5a63432b6833756ad0c29d9'],
      ['contact', '2026-10-20.3', 'en', 'c8d90b4905f6e2b39beb955679ed910bf6a0208e235f38cea167dfbd75c19674'],
      ['contact', '2026-10-20.2', 'de', '131d092d62643fefef4629c55fba8bd5c4041db130f121d45ccaa79b1282b298'],
      ['contact', '2026-10-20.2', 'en', 'bf403ba9d6eb5c035db441bd9202e68dd43907b595201eb187b2bb779e617ffe'],
      ['bank', '2026-10-20.2', 'de', '57e0decde05339232db54eaa563b9cc99610d251ef417d303c79878201d5ad0c'],
      ['bank', '2026-10-20.2', 'en', '7cfa51ab3464b4780b1cf2f36a948ebd00867a2b4f9d50514e1f0ac20e3628aa'],
      ['contact', '2026-11-20', 'de', '8143047339c18ae130349c8e350174440eaae8a30744856f8b2f83af54025179'],
      ['contact', '2026-11-20', 'en', 'a99515c3d966e3485cc834c7d617114064f188b6909ec22eb6193f11e1eb5854'],
      ['bank', '2026-11-20', 'de', '540c2aeea71f78a00e0ae453a80bbe5feda24d16e4eec68e50e4599a79e297a9'],
      ['bank', '2026-11-20', 'en', 'ac05c5f3aa188f2eaee112a1dc128ec7205b2b8a28c7fa0e3c4835363b90f126'],
    ];
    foreach ($expected_hashes as [$kind, $version, $locale, $hash]) {
      self::assertSame($hash, ProfileConsentText::hash($kind, $locale, 'Bürgerbüro', $version));
    }
  }

  /**
   * Only the German scope wording changes in the new versions.
   */
  public function testOfficeTerminologyPreservesTheRestOfTheText(): void {
    foreach (ProfileConsentText::kinds() as $kind) {
      $old = ProfileConsentText::render($kind, 'de', 'Bürgerbüro', '2026-11-20');
      $current = ProfileConsentText::render($kind, 'de', 'Bürgerbüro');
      self::assertSame(str_replace('bei der Stelle', 'bei der Dienststelle', $old), $current);
      self::assertSame(
        ProfileConsentText::render($kind, 'en', 'Bürgerbüro', '2026-11-20'),
        ProfileConsentText::render($kind, 'en', 'Bürgerbüro'),
      );
      self::assertStringContainsString(
        'bei „Stadt Musterstadt“ vorausgefüllt',
        ProfileConsentText::render($kind, 'de', 'Stadt Musterstadt', jurisdiction: TRUE),
      );
      self::assertNotSame(
        ProfileConsentText::hash($kind, 'de', 'Bürgerbüro', '2026-11-20'),
        ProfileConsentText::hash($kind, 'de', 'Bürgerbüro'),
      );
    }
  }

  /**
   * A full address is named once, with labels for standalone address parts.
   */
  public function testAddressLabels(): void {
    $parts = ['person.street', 'person.postal_code', 'person.city'];
    $address = ['person.address', ...$parts, 'person.phone'];
    self::assertStringContainsString(': Anschrift, Telefonnummer.', ProfileConsentText::render('contact', 'de', 'Bürgerbüro', '2026-11-20', $address));
    self::assertStringContainsString(': address, phone number.', ProfileConsentText::render('contact', 'en', 'Bürgerbüro', '2026-11-20', $address));
    self::assertStringContainsString(': Straße, Postleitzahl, Ort.', ProfileConsentText::render('contact', 'de', 'Bürgerbüro', '2026-11-20', $parts));
  }

  /**
   * An unknown version is rejected.
   */
  public function testUnknownVersionIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    ProfileConsentText::template(ProfileConsentText::KIND_CONTACT, 'de', '2026-01-01');
  }

  /**
   * The contact consent never promises bank or tax data.
   */
  public function testContactConsentExcludesBankAndTaxData(): void {
    foreach (ProfileConsentText::locales() as $locale) {
      $text = ProfileConsentText::template(ProfileConsentText::KIND_CONTACT, $locale);
      self::assertStringNotContainsString('IBAN', $text);
      self::assertStringNotContainsStringIgnoringCase('steuer', $text);
      self::assertStringNotContainsStringIgnoringCase('tax', $text);
    }
  }

  /**
   * Unsupported locales fall back to German.
   */
  public function testUnsupportedLocaleFallsBackToGerman(): void {
    self::assertSame('de', ProfileConsentText::normalizeLocale('fr'));
    self::assertSame('en', ProfileConsentText::normalizeLocale(' EN '));
    self::assertSame(
      ProfileConsentText::template(ProfileConsentText::KIND_BANK, 'de'),
      ProfileConsentText::template(ProfileConsentText::KIND_BANK, 'fr'),
    );
  }

  /**
   * Rendering substitutes the organisation or jurisdiction label.
   */
  public function testRenderSubstitutesTheLabel(): void {
    $text = ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'de', "  Bürgerbüro\n Musterstadt ");

    self::assertStringContainsString('bei der Dienststelle „Bürgerbüro Musterstadt“ vorausgefüllt', $text);
    self::assertStringNotContainsString(ProfileConsentText::PLACEHOLDER, $text);
    self::assertStringContainsString('bei der Stelle „Hauptamt“ vorausgefüllt', ProfileConsentText::render('bank', 'de', 'Hauptamt', '2026-11-20', ['person.iban']));
  }

  /**
   * An empty label is rejected instead of rendering an incomplete consent.
   */
  public function testRenderRejectsEmptyLabel(): void {
    $this->expectException(\InvalidArgumentException::class);
    ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'de', "  \n ");
  }

  /**
   * Unknown kinds are rejected.
   */
  public function testUnknownKindIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    ProfileConsentText::template('tax', 'de');
  }

  /**
   * The hash is stable and distinguishes kind, locale and label.
   */
  public function testHashIsStableAndDistinct(): void {
    $hash = ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'de', 'Bürgerbüro');

    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    self::assertSame($hash, ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'de', 'Bürgerbüro'));
    self::assertSame(
      hash('sha256', ProfileConsentText::version(ProfileConsentText::KIND_CONTACT) . '|contact|de|' . ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'de', 'Bürgerbüro')),
      $hash,
    );
    self::assertNotSame($hash, ProfileConsentText::hash(ProfileConsentText::KIND_BANK, 'de', 'Bürgerbüro'));
    self::assertNotSame($hash, ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'en', 'Bürgerbüro'));
    self::assertNotSame($hash, ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'de', 'Bauamt'));
    self::assertSame($hash, ProfileConsentText::hash(ProfileConsentText::KIND_CONTACT, 'fr', 'Bürgerbüro'));
  }

}
