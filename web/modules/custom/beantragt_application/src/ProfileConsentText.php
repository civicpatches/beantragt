<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

/**
 * Backend-owned consent texts for the applicant profile path.
 *
 * Single source for the wording, version and hash of the two consents a
 * citizen can give on submission: reusing contact data ("contact") and
 * reusing bank details ("bank"). The public jurisdiction config exposes the
 * templates with the {stelle} placeholder; the consent record on an
 * application stores the rendered snapshot and its hash.
 *
 * Lives in beantragt_application because the jurisdiction config
 * (beantragt_group), the submission API (beantragt_application) and the
 * profile sync (beantragt_applicant) all depend on this module.
 */
final class ProfileConsentText {

  /**
   * Consent for reusing contact and address data.
   */
  public const KIND_CONTACT = 'contact';

  /**
   * Consent for reusing bank details (IBAN and account holder).
   */
  public const KIND_BANK = 'bank';

  /**
   * Placeholder for the organisation or jurisdiction label.
   */
  public const PLACEHOLDER = '{stelle}';

  /**
   * Locale used when the requested locale has no text.
   */
  public const DEFAULT_LOCALE = 'de';

  /**
   * Every wording per consent kind, oldest first, keyed by version.
   *
   * Version format: the date of the wording, with a ".n" suffix for a further
   * wording on the same day. The last entry is the current wording. Add a new
   * version only for the kind whose text changed, so a new bank wording does
   * not mark unchanged contact consents as outdated. Older wordings stay here:
   * records carrying them remain valid proof of what was shown.
   * Since 2026-11-20, offers render only the eligible fields of the form.
   *
   * Contact: 2026-10-20 first wording; 2026-10-20.2 first person throughout,
   * the stored data named in plain words, withdrawal explained as working for
   * the future; 2026-10-20.3 says that the email address stays as sign-in
   * address after a withdrawal until all data is deleted.
   * Bank: 2026-10-20 first wording; 2026-10-20.2 first person throughout and
   * withdrawal explained as working for the future.
   */
  private const HISTORY = [
    self::KIND_CONTACT => [
      '2026-10-20' => [
        'de' => "Meine Kontakt- und Adressdaten für zukünftige Anträge bei {stelle} speichern. Gespeichert werden nur Kontakt- und Identitätsangaben, bis Sie diese Einwilligung unter 'Meine Daten' widerrufen.",
        'en' => "Save my contact and address details for future applications to {stelle}. Only contact and identity details are stored, until you withdraw this consent under 'My Data'.",
      ],
      '2026-10-20.2' => [
        'de' => 'Ich willige ein, dass mein Name, mein Geburtsdatum, meine Anschrift, E-Mail-Adresse und Telefonnummer sowie, falls angegeben, Geschlecht, Staatsangehörigkeit und Familienstand aus diesem Antrag in meinem Profil gespeichert werden, damit meine künftigen Anträge bei {stelle} vorausgefüllt werden. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.',
        'en' => 'I consent to my name, date of birth, address, email address and phone number, and where given my gender, nationality and marital status, from this application being stored in my profile so that my future applications to {stelle} are prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future; my earlier applications stay unchanged.',
      ],
      '2026-10-20.3' => [
        'de' => 'Ich willige ein, dass mein Name, mein Geburtsdatum, meine Anschrift, E-Mail-Adresse und Telefonnummer sowie, falls angegeben, Geschlecht, Staatsangehörigkeit und Familienstand aus diesem Antrag in meinem Profil gespeichert werden, damit meine künftigen Anträge bei {stelle} vorausgefüllt werden. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert. Meine E-Mail-Adresse bleibt danach als Anmeldeadresse gespeichert, bis ich unter „Meine Daten“ alle meine Daten lösche.',
        'en' => 'I consent to my name, date of birth, address, email address and phone number, and where given my gender, nationality and marital status, from this application being stored in my profile so that my future applications to {stelle} are prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future; my earlier applications stay unchanged. My email address then stays stored as my sign-in address until I delete all my data under “My Data”.',
      ],
      '2026-11-20' => [
        'de' => 'Ich willige ein, dass die folgenden Angaben, soweit ich sie in diesem Antrag angebe, in meinem Profil gespeichert werden: {fields}. Damit werden meine künftigen Anträge bei der Stelle „{stelle}“ vorausgefüllt. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.{email_notice}',
        'en' => 'I consent to the following details being saved in my profile if I provide them in this application: {fields}. This allows my future applications to {stelle} to be prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future. My earlier applications stay unchanged.{email_notice}',
      ],
      '2026-11-21' => [
        'de' => 'Ich willige ein, dass die folgenden Angaben, soweit ich sie in diesem Antrag angebe, in meinem Profil gespeichert werden: {fields}. Damit werden meine künftigen Anträge bei der Dienststelle „{stelle}“ vorausgefüllt. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.{email_notice}',
        'en' => 'I consent to the following details being saved in my profile if I provide them in this application: {fields}. This allows my future applications to {stelle} to be prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future. My earlier applications stay unchanged.{email_notice}',
      ],
    ],
    self::KIND_BANK => [
      '2026-10-20' => [
        'de' => "Meine Bankverbindung (IBAN und Kontoinhaber) für zukünftige Anträge bei {stelle} speichern. Sie wird verschlüsselt gespeichert, bis Sie diese Einwilligung unter 'Meine Daten' widerrufen.",
        'en' => "Save my bank details (IBAN and account holder) for future applications to {stelle}. They are stored encrypted until you withdraw this consent under 'My Data'.",
      ],
      '2026-10-20.2' => [
        'de' => 'Ich willige ein, dass meine Bankverbindung (IBAN und Kontoinhaber) aus diesem Antrag verschlüsselt in meinem Profil gespeichert wird, damit meine künftigen Anträge bei {stelle} vorausgefüllt werden. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.',
        'en' => 'I consent to my bank details (IBAN and account holder) from this application being stored encrypted in my profile so that my future applications to {stelle} are prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future; my earlier applications stay unchanged.',
      ],
      '2026-11-20' => [
        'de' => 'Ich willige ein, dass die folgenden Bankdaten, soweit ich sie in diesem Antrag angebe, verschlüsselt in meinem Profil gespeichert werden: {fields}. Damit werden meine künftigen Anträge bei der Stelle „{stelle}“ vorausgefüllt. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.',
        'en' => 'I consent to the following bank details being saved encrypted in my profile if I provide them in this application: {fields}. This allows my future applications to {stelle} to be prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future. My earlier applications stay unchanged.',
      ],
      '2026-11-21' => [
        'de' => 'Ich willige ein, dass die folgenden Bankdaten, soweit ich sie in diesem Antrag angebe, verschlüsselt in meinem Profil gespeichert werden: {fields}. Damit werden meine künftigen Anträge bei der Dienststelle „{stelle}“ vorausgefüllt. Ich kann diese Einwilligung jederzeit unter „Meine Daten“ widerrufen. Der Widerruf gilt für die Zukunft, meine bisherigen Anträge bleiben unverändert.',
        'en' => 'I consent to the following bank details being saved encrypted in my profile if I provide them in this application: {fields}. This allows my future applications to {stelle} to be prefilled. I can withdraw this consent at any time under “My Data”. The withdrawal applies to the future. My earlier applications stay unchanged.',
      ],
    ],
  ];

  private const SIGN_IN_EMAIL_NOTICE = [
    'de' => ' Meine E-Mail-Adresse bleibt danach als Anmeldeadresse gespeichert, bis ich unter „Meine Daten“ alle meine Daten lösche.',
    'en' => ' My email address then stays stored as my sign-in address until I delete all my data under “My Data”.',
  ];

  private const EMAIL_NOTICES = [
    '2026-11-20' => self::SIGN_IN_EMAIL_NOTICE,
    '2026-11-21' => self::SIGN_IN_EMAIL_NOTICE,
  ];

  private const JURISDICTION_FIELDS = [
    self::KIND_CONTACT => [
      'person.full_name',
      'person.birthdate',
      'person.address',
      'person.email',
      'person.phone',
      'person.gender',
      'person.nationality',
      'person.marital_status',
    ],
    self::KIND_BANK => ['person.iban', 'person.account_holder'],
  ];

  private const FIELD_LABELS = [
    'person.full_name' => ['de' => 'Name', 'en' => 'full name'],
    'person.first_name' => ['de' => 'Vorname', 'en' => 'first name'],
    'person.last_name' => ['de' => 'Nachname', 'en' => 'last name'],
    'person.birthdate' => ['de' => 'Geburtsdatum', 'en' => 'date of birth'],
    'person.email' => ['de' => 'E-Mail-Adresse', 'en' => 'email address'],
    'person.phone' => ['de' => 'Telefonnummer', 'en' => 'phone number'],
    'person.gender' => ['de' => 'Geschlecht', 'en' => 'gender'],
    'person.nationality' => ['de' => 'Staatsangehörigkeit', 'en' => 'nationality'],
    'person.marital_status' => ['de' => 'Familienstand', 'en' => 'marital status'],
    'person.address' => ['de' => 'Anschrift', 'en' => 'address'],
    'person.street' => ['de' => 'Straße', 'en' => 'street'],
    'person.house_number' => ['de' => 'Hausnummer', 'en' => 'house number'],
    'person.postal_code' => ['de' => 'Postleitzahl', 'en' => 'postcode'],
    'person.city' => ['de' => 'Ort', 'en' => 'city'],
    'person.iban' => ['de' => 'IBAN', 'en' => 'IBAN'],
    'person.account_holder' => ['de' => 'Kontoinhaber', 'en' => 'account holder'],
  ];

  /**
   * Returns the supported consent kinds.
   *
   * @return list<string>
   *   Consent kinds.
   */
  public static function kinds(): array {
    return array_keys(self::HISTORY);
  }

  /**
   * Returns the current wording version of one consent kind.
   *
   * @throws \InvalidArgumentException
   *   When the kind is unknown.
   */
  public static function version(string $kind): string {
    $versions = array_keys(self::history($kind));
    return (string) end($versions);
  }

  /**
   * Returns the locales that have their own text.
   *
   * @return list<string>
   *   Locale codes.
   */
  public static function locales(): array {
    return array_keys(self::HISTORY[self::KIND_CONTACT][self::version(self::KIND_CONTACT)]);
  }

  /**
   * Maps a requested locale to one with a text, falling back to German.
   */
  public static function normalizeLocale(string $locale): string {
    $locale = strtolower(trim($locale));
    return in_array($locale, self::locales(), TRUE) ? $locale : self::DEFAULT_LOCALE;
  }

  /**
   * Returns the unrendered template with the {stelle} placeholder.
   *
   * @param string $kind
   *   Consent kind.
   * @param string $locale
   *   Requested locale.
   * @param string|null $version
   *   Wording version, NULL for the current one.
   *
   * @throws \InvalidArgumentException
   *   When the kind or the version is unknown.
   */
  public static function template(string $kind, string $locale, ?string $version = NULL): string {
    $history = self::history($kind);
    $version ??= self::version($kind);
    if (!isset($history[$version])) {
      throw new \InvalidArgumentException('Unknown profile consent text version: ' . $version . '.');
    }
    return $history[$version][self::normalizeLocale($locale)];
  }

  /**
   * Returns all current templates of one kind keyed by locale.
   *
   * @return array<string, string>
   *   Templates keyed by locale.
   */
  public static function templates(string $kind): array {
    $version = self::version($kind);
    $templates = [];
    foreach (self::locales() as $locale) {
      $templates[$locale] = self::renderFields($kind, $locale, $version, self::JURISDICTION_FIELDS[$kind]);
    }
    return $templates;
  }

  /**
   * Normalizes a Stelle label the way it appears in the rendered text.
   *
   * Collapses Unicode whitespace runs to one space and trims the result, so
   * the stored scope label, the label on the public schema and the text
   * agree byte for byte and a frontend can replace {stelle} literally.
   */
  public static function normalizeStelle(string $stelle): string {
    return trim((string) preg_replace('/\s+/u', ' ', $stelle));
  }

  /**
   * Renders the consent text for one organisation or jurisdiction label.
   *
   * @param string $kind
   *   Consent kind.
   * @param string $locale
   *   Requested locale.
   * @param string $stelle
   *   Organisation or jurisdiction label.
   * @param string|null $version
   *   Wording version, NULL for the current one.
   * @param list<string>|null $fields
   *   CRM keys this offer covers. NULL uses the jurisdiction template.
   * @param bool $jurisdiction
   *   Whether the label names a jurisdiction directly.
   *
   * @throws \InvalidArgumentException
   *   When the kind or version is unknown or the label is empty.
   */
  public static function render(string $kind, string $locale, string $stelle, ?string $version = NULL, ?array $fields = NULL, bool $jurisdiction = FALSE): string {
    $stelle = self::normalizeStelle($stelle);
    if ($stelle === '') {
      throw new \InvalidArgumentException('The profile consent text needs a non-empty organisation or jurisdiction label.');
    }
    $locale = self::normalizeLocale($locale);
    $version ??= self::version($kind);
    $template = self::renderFields($kind, $locale, $version, $fields ?? self::JURISDICTION_FIELDS[$kind] ?? []);
    if ($jurisdiction) {
      $template = str_replace('bei der Dienststelle „{stelle}“', 'bei „{stelle}“', $template);
    }
    return str_replace(self::PLACEHOLDER, $stelle, $template);
  }

  /**
   * Inserts the fields covered by this version of the consent text.
   */
  private static function renderFields(string $kind, string $locale, string $version, array $fields): string {
    $template = self::template($kind, $locale, $version);
    $label_fields = $fields;
    if (in_array('person.address', $fields, TRUE)) {
      // The address label already covers the parts of an address. Keep the
      // underlying field keys for the offer and for profile storage.
      $label_fields = array_diff($fields, ['person.street', 'person.postal_code', 'person.city']);
    }
    $labels = array_map(static fn (string $key): string => self::FIELD_LABELS[$key][$locale], $label_fields);
    return strtr($template, [
      '{fields}' => implode(', ', $labels),
      '{email_notice}' => in_array('person.email', $fields, TRUE) ? (self::EMAIL_NOTICES[$version][$locale] ?? '') : '',
    ]);
  }

  /**
   * Returns the SHA-256 hash that proves the rendered text of a consent.
   *
   * @param string $kind
   *   Consent kind.
   * @param string $locale
   *   Requested locale.
   * @param string $stelle
   *   Organisation or jurisdiction label.
   * @param string|null $version
   *   Wording version, NULL for the current one.
   * @param list<string>|null $fields
   *   CRM keys covered by the offer, if it is form-specific.
   * @param bool $jurisdiction
   *   Whether the label names a jurisdiction directly.
   *
   * @throws \InvalidArgumentException
   *   When the kind or version is unknown or the label is empty.
   */
  public static function hash(string $kind, string $locale, string $stelle, ?string $version = NULL, ?array $fields = NULL, bool $jurisdiction = FALSE): string {
    $version ??= self::version($kind);
    return hash('sha256', implode('|', [
      $version,
      $kind,
      self::normalizeLocale($locale),
      self::render($kind, $locale, $stelle, $version, $fields, $jurisdiction),
    ]));
  }

  /**
   * Returns the wording history of one kind.
   *
   * @return array<string, array<string, string>>
   *   Templates keyed by version and locale.
   *
   * @throws \InvalidArgumentException
   *   When the kind is unknown.
   */
  private static function history(string $kind): array {
    if (!isset(self::HISTORY[$kind])) {
      throw new \InvalidArgumentException('Unknown profile consent kind: ' . $kind . '.');
    }
    return self::HISTORY[$kind];
  }

}
