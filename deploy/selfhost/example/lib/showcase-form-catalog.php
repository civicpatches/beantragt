<?php

/**
 * @file
 * Catalogue of the three showcase forms of Stadt Musterstadt.
 *
 * Pure data and pure functions: no Drupal call, so
 * scripts/validate-seed-form-payloads.php can validate every payload without
 * a database. scripts/seed-demo-showcase-forms.php writes it.
 *
 * - A "Sondernutzung für Außengastronomie beantragen": published, internet,
 *   Bürgerbüro. Address group, address widget with map and boundary hint,
 *   condition, default value, every validation key, protected field
 *   (criminal_offense), attachment analysis with eight criteria, redaction,
 *   two analysis stories with a purpose-bound value, precheck, two leaflets.
 * - B "Zuschuss für eine Vereinsveranstaltung beantragen": published,
 *   internet, login required, retention 365 days, company register number,
 *   the club's bank account without profile keys (an association's account
 *   is no personal bank data, so the form offers the contact consent only;
 *   the bank consent is shown by the residents parking permit), no
 *   sensitive non-file field, staff reading with a purpose-bound value.
 * - C "Sperrmüll-Abholung anmelden": draft, internet, Umweltamt, never
 *   published (the draft example for the editor). Address group of the
 *   pick-up place, select, date, number with min and max, a checkbox with a
 *   conditional field, textarea and a photo upload with an analysis story.
 *   No computed field: the citizen form would show it and the demo installers
 *   do not enable beantragt_compute.
 *
 * Every source key starts with "seed:showcase:", which the exclusive
 * product-smoke catalogue exempts.
 */

declare(strict_types=1);

require_once __DIR__ . '/starter-form-catalog.php';

/**
 * Source key prefix of every showcase form.
 */
function beantragt_showcase_source_prefix(): string {
  return 'seed:showcase:musterstadt:';
}

/**
 * Derives a stable, RFC 4122 shaped UUID (version 5 layout) from a seed key.
 *
 * Nodes of the showcase seed are found by this UUID, so a rerun never adopts
 * content created by hand and every install gets the same identifiers.
 */
function beantragt_showcase_uuid(string $key): string {
  $hash = sha1('beantragt.ai showcase seed:' . $key);
  $time_high = (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000;
  $clock = (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000;
  return sprintf(
    '%s-%s-%04x-%04x-%s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    $time_high,
    $clock,
    substr($hash, 20, 12),
  );
}

/**
 * Returns the three showcase form definitions.
 *
 * @return array<string, array<string, mixed>>
 *   Definitions keyed by form key.
 */
function beantragt_showcase_form_definitions(): array {
  return [
    'outdoor-dining-permit' => [
      'title' => 'Sondernutzung für Außengastronomie beantragen',
      'title_en' => 'Apply for an outdoor dining permit',
      'description' => 'Erlaubnis für Tische und Stühle eines Gastronomiebetriebs auf öffentlichem Straßenland der Stadt Musterstadt.',
      'description_en' => 'Permit for the tables and chairs of a restaurant or café on public street land of the city of Musterstadt.',
      'organisation' => 'citizen-services',
      'publish' => TRUE,
      'workflow_flags' => ['request_info', 'approved', 'rejected'],
      'documents' => ['outdoor-dining-leaflet', 'outdoor-dining-design-guide'],
      'category' => 'ordnung',
    ],
    'club-event-grant' => [
      'title' => 'Zuschuss für eine Vereinsveranstaltung beantragen',
      'title_en' => 'Apply for a grant for a club event',
      'description' => 'Zuschuss der Stadt Musterstadt für eine öffentliche Veranstaltung eines eingetragenen Vereins.',
      'description_en' => 'Grant of the city of Musterstadt for a public event of a registered club.',
      // Not the mayor's office: its catalogue is a citizen-inquiry flow
      // without the flags below (scripts/seed-demo-data.php), while the
      // Hauptamt's generic catalogue carries every one of them.
      'organisation' => 'central-administration',
      'publish' => TRUE,
      'workflow_flags' => ['hand_over', 'in_case_system', 'decision_issued', 'approved', 'rejected', 'filed'],
      'documents' => ['club-grant-guideline'],
      'category' => 'foerderung',
    ],
    'bulky-waste-collection' => [
      'title' => 'Sperrmüll-Abholung anmelden',
      'title_en' => 'Book a bulky waste collection',
      'description' => 'Anmeldung einer Abholung von Sperrmüll aus einem Haushalt in der Stadt Musterstadt.',
      'description_en' => 'Booking of a collection of bulky waste from a household in the city of Musterstadt.',
      'organisation' => 'environment-office',
      'publish' => FALSE,
      // A citizen service, so scope internet even as a draft; A and B derive
      // their scope from "publish" (scripts/seed-demo-showcase-forms.php).
      'scope' => 'internet',
      'workflow_flags' => NULL,
      'documents' => [],
      'category' => 'umwelt',
    ],
  ];
}

/**
 * Builds the FormSchema payload of one showcase form.
 *
 * @param string $key
 *   Form key of beantragt_showcase_form_definitions().
 * @param array<string, array{id: string, title: string, version: string, changed: int}> $sources
 *   Context source metadata per document key. The seed passes the stored
 *   documents; the validator passes deterministic placeholders.
 *
 * @return array<string, mixed>
 *   FormSchema definition.
 */
function beantragt_showcase_form_payload(string $key, array $sources): array {
  $definitions = beantragt_showcase_form_definitions();
  if (!isset($definitions[$key])) {
    throw new InvalidArgumentException(sprintf('Unknown showcase form %s.', $key));
  }
  $definition = $definitions[$key];
  $source_filename = beantragt_showcase_source_prefix() . $key;

  $context_sources = [];
  $documents = beantragt_showcase_document_definitions();
  foreach ($definition['documents'] as $document_key) {
    if (!isset($sources[$document_key])) {
      throw new InvalidArgumentException(sprintf('Showcase form %s misses its document %s.', $key, $document_key));
    }
    $source = $sources[$document_key];
    $context_sources[] = [
      'type' => 'form_attachment',
      'id' => $source['id'],
      'title' => $source['title'],
      'placement' => $documents[$document_key]['placement'],
      'version' => $source['version'],
      'changed' => $source['changed'],
      'enabledFor' => ['editor', 'citizen_assistant'],
      'requiredCitation' => TRUE,
    ];
  }

  $payload = [
    'id' => 'showcase_' . str_replace('-', '_', $key),
    'title' => $definition['title'],
    'description' => $definition['description'],
    'i18n' => [
      'en' => [
        'title' => $definition['title_en'],
        'description' => $definition['description_en'],
      ],
    ],
    'sections' => match ($key) {
      'outdoor-dining-permit' => beantragt_showcase_outdoor_dining_sections(),
      'club-event-grant' => beantragt_showcase_club_grant_sections(),
      'bulky-waste-collection' => beantragt_showcase_bulky_waste_sections(),
      default => throw new InvalidArgumentException(sprintf('Showcase form %s has no sections.', $key)),
    },
    'auth' => match ($key) {
      'outdoor-dining-permit' => [
        'mode' => 'optional',
        'providers' => ['passwordless'],
        'collect' => ['fullName' => 'required', 'email' => 'required'],
      ],
      default => [
        'mode' => 'required',
        'providers' => ['passwordless'],
        'collect' => ['fullName' => 'required', 'email' => 'required'],
      ],
    },
    'version' => 1,
    'semantics' => [
      'category' => $definition['category'],
      'entities' => ['person', 'application', 'organisation'],
      'process' => $key,
      'languages' => ['de', 'en'],
    ],
    'source' => [
      'type' => 'demo_neutral',
      'file' => 'scripts/seed-demo-showcase-forms.php',
      'key' => $source_filename,
      'label' => 'beantragt.ai showcase catalogue',
      'note' => 'Fictitious demo form. Do not enter real personal data.',
      'demo_only' => TRUE,
      'official_service' => FALSE,
      'review_required' => TRUE,
      'published_by_seed' => $definition['publish'],
    ],
  ];
  if ($key === 'club-event-grant') {
    $payload['retention'] = ['dataRetentionDays' => 365];
  }
  if ($context_sources !== []) {
    $payload['contextSources'] = $context_sources;
  }
  if ($key === 'outdoor-dining-permit') {
    $payload['precheckSchema'] = beantragt_showcase_outdoor_dining_precheck($sources['outdoor-dining-leaflet']);
  }
  return $payload;
}

/**
 * Sections of form A, outdoor dining permit.
 *
 * @return list<array<string, mixed>>
 *   Sections.
 */
function beantragt_showcase_outdoor_dining_sections(): array {
  $not_personal = ['privacy' => ['kind' => 'not_personal']];
  $contact = ['privacy' => ['kind' => 'contact']];

  $business = beantragt_starter_section(
    'betrieb',
    'Angaben zum Betrieb',
    'Business details',
    'Der Gastronomiebetrieb, der die Fläche nutzen möchte.',
    'The restaurant or café that wants to use the area.',
    [
      beantragt_starter_field('betriebsname', 'text', 'Betriebsname', 'Business name', TRUE, [
        'crm_key' => 'company.name',
        'placeholder' => 'Café am Brunnen',
      ] + $not_personal, ['placeholder' => 'Café am Brunnen']),
      beantragt_starter_field('rechtsform', 'select', 'Rechtsform', 'Legal form', TRUE, [
        'crm_key' => 'company.legal_form',
        'options' => ['Einzelunternehmen', 'GbR', 'GmbH', 'UG (haftungsbeschränkt)', 'Sonstige'],
      ] + $not_personal, [
        'options' => [
          'Einzelunternehmen' => 'Sole proprietorship',
          'GbR' => 'Civil law partnership (GbR)',
          'GmbH' => 'Limited company (GmbH)',
          'UG (haftungsbeschränkt)' => 'Mini limited company (UG)',
          'Sonstige' => 'Other',
        ],
      ]),
      beantragt_starter_field('ansprechperson', 'text', 'Ansprechperson im Betrieb', 'Contact person at the business', TRUE, [
        'crm_key' => 'company.contact_person',
        'privacy' => ['kind' => 'identity'],
      ]),
      beantragt_starter_field('betrieb_email', 'email', 'E-Mail des Betriebs', 'Business email', TRUE, [
        'placeholder' => 'kontakt@cafe-am-brunnen.example',
      ] + $contact, ['placeholder' => 'contact@cafe-am-brunnen.example']),
      beantragt_starter_field('betrieb_telefon', 'tel', 'Telefon des Betriebs', 'Business phone', FALSE, [
        'placeholder' => '030 23125 400',
      ] + $contact, ['placeholder' => '030 23125 400']),
      beantragt_starter_field('website', 'text', 'Website', 'Website', FALSE, [
        'format' => 'url',
        'placeholder' => 'https://www.cafe-am-brunnen.example',
      ] + $not_personal, ['placeholder' => 'https://www.cafe-am-brunnen.example']),
      beantragt_starter_field('gewerbe_aktenzeichen', 'text', 'Aktenzeichen der Gewerbeanmeldung', 'Reference of the business registration', TRUE, [
        'format' => 'reference_number',
        'placeholder' => 'GA-2026-00123',
        'validation' => ['pattern' => '^GA-[0-9]{4}-[0-9]{5}$'],
        'privacy' => ['kind' => 'other_personal'],
      ], ['placeholder' => 'GA-2026-00123']),
    ],
  );

  $address = beantragt_starter_section(
    'betriebsanschrift',
    'Anschrift des Betriebs',
    'Business address',
    'Die Anschrift, unter der der Betrieb geführt wird.',
    'The address at which the business is run.',
    [
      beantragt_starter_field('betrieb_strasse', 'text', 'Straße', 'Street', TRUE, $contact),
      beantragt_starter_field('betrieb_hausnummer', 'text', 'Hausnummer', 'House number', TRUE, [
        'validation' => ['maxLength' => 10],
      ] + $contact),
      beantragt_starter_field('betrieb_plz', 'text', 'Postleitzahl', 'Postcode', TRUE, [
        'format' => 'postal_code',
        'validation' => ['maxLength' => 5],
      ] + $contact),
      beantragt_starter_field('betrieb_ort', 'text', 'Ort', 'Town', TRUE, [
        'placeholder' => 'Musterstadt',
      ] + $contact, ['placeholder' => 'Musterstadt']),
    ],
  );
  $address['addressGroups'] = [
    [
      'id' => 'betriebsanschrift_gruppe',
      'label' => 'Anschrift des Betriebs',
      'fields' => [
        'street' => 'betrieb_strasse',
        'houseNumber' => 'betrieb_hausnummer',
        'postcode' => 'betrieb_plz',
        'city' => 'betrieb_ort',
      ],
      'autocomplete' => TRUE,
    ],
  ];

  $area = beantragt_starter_section(
    'flaeche',
    'Fläche und Zeitraum',
    'Area and period',
    'Wo und wie lange Tische und Stühle stehen sollen.',
    'Where and for how long tables and chairs are to stand.',
    [
      beantragt_starter_field('standort', 'address', 'Standort der Fläche', 'Location of the area', TRUE, [
        'widget' => ['autocomplete' => TRUE, 'map' => TRUE, 'boundary' => 'advisory'],
      ] + $not_personal),
      beantragt_starter_field('oeffentlicher_grund', 'checkbox', 'Die Fläche liegt auf öffentlichem Straßenland (Gehweg, Platz oder Fußgängerzone).', 'The area is on public street land (pavement, square or pedestrian zone).', FALSE, $not_personal),
      beantragt_starter_field('flaechenart', 'select', 'Art der Fläche', 'Type of area', TRUE, [
        'options' => ['Gehweg', 'Fußgängerzone', 'Platz', 'Parkstreifen'],
      ] + $not_personal, [
        'options' => [
          'Gehweg' => 'Pavement',
          'Fußgängerzone' => 'Pedestrian zone',
          'Platz' => 'Square',
          'Parkstreifen' => 'Parking strip',
        ],
      ]),
      beantragt_starter_field('zeitraum', 'radio', 'Zeitraum', 'Period', TRUE, [
        'options' => ['Saison', 'Ganzjährig'],
      ] + $not_personal, [
        'options' => ['Saison' => 'Season', 'Ganzjährig' => 'All year'],
      ]),
      beantragt_starter_field('beginn', 'date', 'Beginn', 'Start', TRUE, $not_personal),
      beantragt_starter_field('ende', 'date', 'Ende', 'End', TRUE, $not_personal),
      beantragt_starter_field('flaeche_m2', 'number', 'Fläche in m²', 'Area in m²', TRUE, [
        'validation' => ['min' => 1, 'max' => 200, 'step' => 0.5],
      ] + $not_personal),
      beantragt_starter_field('restgehweg_m', 'number', 'Verbleibende Gehwegbreite neben der Fläche in Metern', 'Remaining pavement width next to the area in metres', TRUE, [
        'validation' => ['min' => 0, 'max' => 10, 'step' => 0.1],
      ] + $not_personal),
      beantragt_starter_field('anzahl_tische', 'number', 'Anzahl Tische', 'Number of tables', TRUE, [
        'defaultValue' => 4,
        'validation' => ['min' => 1, 'max' => 40, 'step' => 1],
      ] + $not_personal),
      beantragt_starter_field('heizstrahler', 'checkbox', 'Ich möchte Heizstrahler aufstellen.', 'I would like to set up patio heaters.', FALSE, $not_personal),
      beantragt_starter_field('anzahl_heizstrahler', 'number', 'Anzahl Heizstrahler', 'Number of patio heaters', TRUE, [
        'conditionalOn' => ['fieldId' => 'heizstrahler', 'value' => TRUE],
        'validation' => ['min' => 1, 'max' => 10, 'step' => 1],
      ] + $not_personal),
      beantragt_starter_field('beschreibung', 'textarea', 'Beschreibung der Möblierung', 'Description of the furniture', TRUE, [
        'placeholder' => 'Zum Beispiel: sechs Bistrotische, zwölf Stühle, zwei Sonnenschirme',
        'validation' => ['minLength' => 20, 'maxLength' => 1000],
      ] + $not_personal, ['placeholder' => 'For example: six bistro tables, twelve chairs, two parasols']),
    ],
  );

  $reliability = beantragt_starter_section(
    'zuverlaessigkeit',
    'Zuverlässigkeit',
    'Reliability',
    'Diese Angabe sehen nur die zuständigen Sachbearbeiterinnen und Sachbearbeiter. Jeder Zugriff wird festgehalten.',
    'Only the responsible case workers see this answer. Every access is recorded.',
    [
      beantragt_starter_field('laufende_verfahren', 'textarea', 'Laufende Verfahren oder Untersagungen', 'Pending proceedings or prohibitions', FALSE, [
        'placeholder' => 'Falls keine: bitte leer lassen',
        'sensitive' => TRUE,
        'privacy' => ['kind' => 'criminal_offense'],
        'validation' => ['maxLength' => 2000],
      ], ['placeholder' => 'If none: please leave empty']),
    ],
  );

  $attachments = beantragt_starter_section(
    'anlagen',
    'Anlagen',
    'Attachments',
    'Lageplan, Fotos und Versicherungsnachweis als PDF oder Bild.',
    'Site plan, photos and proof of insurance as PDF or image.',
    [
      beantragt_starter_field('lageplan', 'file', 'Lageplan der Fläche', 'Site plan of the area', TRUE, [
        'validation' => ['maxFiles' => 1, 'maxSizeBytes' => 10485760],
        'analysis' => beantragt_showcase_site_plan_analysis(),
      ]),
      beantragt_starter_field('fotos', 'file', 'Fotos der Fläche', 'Photos of the area', FALSE, [
        'validation' => ['maxFiles' => 3, 'maxSizeBytes' => 10485760],
      ]),
      beantragt_starter_field('deckungssumme_euro', 'number', 'Deckungssumme der Betriebshaftpflicht in Euro', 'Liability insurance cover in euro', TRUE, [
        'format' => 'currency',
        'validation' => ['min' => 0, 'step' => 1],
      ] + $not_personal),
      beantragt_starter_field('haftpflichtnachweis', 'file', 'Nachweis der Betriebshaftpflichtversicherung', 'Proof of business liability insurance', TRUE, [
        'validation' => ['maxFiles' => 1, 'maxSizeBytes' => 10485760],
        'analysis' => beantragt_showcase_insurance_analysis(),
      ]),
    ],
  );

  return [$business, $address, $area, $reliability, $attachments];
}

/**
 * Site plan analysis of form A: eight criteria, sovereign, redacted.
 */
function beantragt_showcase_site_plan_analysis(): array {
  $criteria = [
    'flaeche_eingezeichnet' => 'Beantragte Fläche eingezeichnet / Requested area marked',
    'masse_angegeben' => 'Länge und Breite der Fläche angegeben / Length and width of the area given',
    'massstab_erkennbar' => 'Maßstab erkennbar / Scale visible',
    'gehwegbreite_angegeben' => 'Verbleibende Gehwegbreite angegeben / Remaining pavement width given',
    'zugaenge_frei' => 'Hauseingänge und Einfahrten frei / House entrances and driveways kept clear',
    'hydranten_frei' => 'Hydranten und Schächte frei / Hydrants and manholes kept clear',
    'moeblierung_dargestellt' => 'Tische und Stühle dargestellt / Tables and chairs shown',
    'nordpfeil' => 'Nordpfeil oder Straßennamen erkennbar / North arrow or street names visible',
  ];
  return [
    'enabled' => TRUE,
    'version' => 1,
    'expectedDocumentType' => 'Lageplan einer Sondernutzungsfläche / Site plan of a special use area',
    'promptSuggestion' => 'Prüfe ausschließlich als unverbindliche Vorprüfung, ob der Lageplan die acht genannten Angaben erkennbar zeigt. Triff keine Entscheidung. Check only as a non-binding pre-check whether the site plan visibly shows the eight listed items. Make no decision.',
    'redaction' => 'when_possible',
    'resultSchema' => [
      'type' => 'object',
      'required' => array_keys($criteria),
      'properties' => beantragt_showcase_boolean_properties($criteria),
    ],
    'egressPolicy' => 'sovereign_only',
    'modelClass' => 'openai_compatible',
  ];
}

/**
 * Insurance analysis of form A: two stories and one purpose-bound value.
 */
function beantragt_showcase_insurance_analysis(): array {
  return [
    'enabled' => TRUE,
    'version' => 1,
    'expectedDocumentType' => 'Versicherungsbestätigung einer Betriebshaftpflicht / Confirmation of business liability insurance',
    'citizenPrompt' => 'Prüfe als unverbindliche Vorprüfung, ob die Anlage eine Bestätigung einer Betriebshaftpflichtversicherung ist und ob ein Gültigkeitszeitraum erkennbar ist. Lies keine Beträge aus und triff keine Entscheidung. Check as a non-binding pre-check whether the attachment confirms a business liability insurance and whether a period of validity is visible. Do not read out amounts and make no decision.',
    'staffPrompt' => 'Lies für die Sachbearbeitung die Deckungssumme für Personen- und Sachschäden als Zahl in Euro aus. Gib keine Namen, Kennungen oder sonstigen Beträge aus und triff keine Entscheidung. Read the cover for personal injury and property damage as a number in euro for the case worker. Return no names, identifiers or other amounts and make no decision.',
    'redaction' => 'when_possible',
    'resultSchema' => [
      'type' => 'object',
      'required' => ['ist_haftpflichtnachweis', 'gueltigkeit_erkennbar'],
      'properties' => [
        'ist_haftpflichtnachweis' => [
          'type' => 'boolean',
          'title' => 'Bestätigung einer Betriebshaftpflicht / Confirmation of business liability insurance',
          'nonPersonal' => TRUE,
        ],
        'gueltigkeit_erkennbar' => [
          'type' => 'boolean',
          'title' => 'Gültigkeitszeitraum erkennbar / Period of validity visible',
          'nonPersonal' => TRUE,
        ],
        'deckungssumme' => [
          'type' => 'number',
          'title' => 'Deckungssumme laut Nachweis in Euro / Cover on the proof in euro',
          'purposeBound' => TRUE,
          'compareWith' => 'deckungssumme_euro',
        ],
      ],
    ],
    'egressPolicy' => 'sovereign_only',
    'modelClass' => 'openai_compatible',
  ];
}

/**
 * Precheck of form A, both criteria quoted from the leaflet.
 *
 * @param array{id: string, title: string} $leaflet
 *   Context source of the leaflet.
 */
function beantragt_showcase_outdoor_dining_precheck(array $leaflet): array {
  return [
    'version' => 1,
    'criteria' => [
      [
        'id' => 'oeffentlicher_grund',
        'factKey' => 'public_ground',
        'fieldId' => 'oeffentlicher_grund',
        'factType' => 'boolean',
        'label' => [
          'de' => 'Fläche auf öffentlichem Straßenland',
          'en' => 'Area on public street land',
        ],
        'question' => [
          'de' => 'Liegt die gewünschte Fläche auf öffentlichem Straßenland?',
          'en' => 'Is the requested area on public street land?',
        ],
        'affects' => 'procedure_fit',
        'order' => 0,
        'reviewStatus' => 'approved',
        'evidence' => [
          'sourceType' => 'context_source',
          'sourceId' => $leaflet['id'],
          'sourceTitle' => $leaflet['title'],
          'quote' => 'Eine Sondernutzungserlaubnis braucht, wer öffentliches Straßenland nutzt.',
          'page' => 1,
        ],
      ],
      [
        'id' => 'restgehwegbreite',
        'factKey' => 'remaining_pavement_width',
        'fieldId' => 'restgehweg_m',
        'factType' => 'number',
        'label' => [
          'de' => 'Verbleibende Gehwegbreite',
          'en' => 'Remaining pavement width',
        ],
        'question' => [
          'de' => 'Wie breit bleibt der Gehweg neben der Fläche in Metern?',
          'en' => 'How wide does the pavement next to the area remain, in metres?',
        ],
        'affects' => 'obligation',
        'order' => 1,
        'reviewStatus' => 'approved',
        'evidence' => [
          'sourceType' => 'context_source',
          'sourceId' => $leaflet['id'],
          'sourceTitle' => $leaflet['title'],
          'quote' => 'Neben der Fläche muss ein Gehweg von mindestens 1,50 Metern frei bleiben.',
          'page' => 1,
        ],
        'unit' => 'm',
      ],
    ],
  ];
}

/**
 * Sections of form B, club event grant.
 *
 * @return list<array<string, mixed>>
 *   Sections.
 */
function beantragt_showcase_club_grant_sections(): array {
  $not_personal = ['privacy' => ['kind' => 'not_personal']];
  $identity = ['privacy' => ['kind' => 'identity']];
  $financial = ['privacy' => ['kind' => 'financial']];

  return [
    beantragt_starter_section(
      'verein',
      'Angaben zum Verein',
      'Club details',
      'Der eingetragene Verein, der die Veranstaltung ausrichtet, und Sie als die Person, die den Antrag für den Verein stellt.',
      'The registered club that hosts the event, and you as the person who files the application for the club.',
      [
        beantragt_starter_field('vereinsname', 'text', 'Name des Vereins', 'Name of the club', TRUE, [
          'crm_key' => 'company.name',
          'placeholder' => 'Heimatverein Musterstadt e. V.',
        ] + $not_personal, ['placeholder' => 'Heimatverein Musterstadt e. V.']),
        beantragt_starter_field('vereinsregisternummer', 'text', 'Vereinsregisternummer', 'Register of associations number', TRUE, [
          'crm_key' => 'company.register_number',
          'placeholder' => 'VR 1234',
        ] + $not_personal, ['placeholder' => 'VR 1234']),
        // The applicant's own data: with the contact consent these keys reach
        // the applicant's profile, so they must never ask for someone else.
        beantragt_starter_field('vorname', 'text', 'Ihr Vorname', 'Your first name', TRUE, [
          'crm_key' => 'person.first_name',
        ] + $identity),
        beantragt_starter_field('nachname', 'text', 'Ihr Nachname', 'Your last name', TRUE, [
          'crm_key' => 'person.last_name',
        ] + $identity),
        beantragt_starter_field('geburtsdatum', 'date', 'Ihr Geburtsdatum', 'Your date of birth', TRUE, [
          'crm_key' => 'person.birthdate',
        ] + $identity),
        beantragt_starter_field('funktion_im_verein', 'text', 'Funktion im Verein', 'Role in the club', TRUE, [
          'placeholder' => 'Vorsitz, Kassenwart, Schriftführung',
          'privacy' => ['kind' => 'other_personal'],
        ], ['placeholder' => 'Chair, treasurer, secretary']),
      ],
    ),
    beantragt_starter_section(
      'veranstaltung',
      'Veranstaltung',
      'Event',
      'Was, wann und wo gefeiert, gespielt oder gezeigt wird.',
      'What is celebrated, played or shown, when and where.',
      [
        beantragt_starter_field('titel', 'text', 'Titel der Veranstaltung', 'Title of the event', TRUE, [
          'placeholder' => 'Sommerfest am Stadtweiher',
          'validation' => ['maxLength' => 120],
        ] + $not_personal, ['placeholder' => 'Summer fete at the town pond']),
        beantragt_starter_field('datum', 'date', 'Datum', 'Date', TRUE, $not_personal),
        beantragt_starter_field('veranstaltungsort', 'text', 'Veranstaltungsort', 'Venue', TRUE, [
          'placeholder' => 'Festwiese am Stadtweiher',
        ] + $not_personal, ['placeholder' => 'Fairground at the town pond']),
        beantragt_starter_field('besucher', 'number', 'Erwartete Besucherzahl', 'Expected number of visitors', TRUE, [
          'validation' => ['min' => 1, 'max' => 5000, 'step' => 1],
        ] + $not_personal),
        beantragt_starter_field('veranstaltung_beschreibung', 'textarea', 'Beschreibung', 'Description', TRUE, [
          'validation' => ['minLength' => 30, 'maxLength' => 2000],
        ] + $not_personal),
        beantragt_starter_field('barrierefrei', 'checkbox', 'Die Veranstaltung ist barrierefrei zugänglich.', 'The event is accessible without barriers.', FALSE, $not_personal),
      ],
    ),
    beantragt_starter_section(
      'finanzen',
      'Finanzierung und Bankverbindung',
      'Funding and bank account',
      'Kosten, beantragter Zuschuss und das Konto des Vereins für die Auszahlung.',
      'Costs, the requested grant and the club account for the payment.',
      [
        beantragt_starter_field('gesamtkosten', 'number', 'Gesamtkosten der Veranstaltung in Euro', 'Total cost of the event in euro', TRUE, [
          'format' => 'currency',
          'validation' => ['min' => 0, 'step' => 0.01],
        ] + $financial),
        // Currency is a number format: the editor and the citizen's page treat
        // the field as a number (FormSetupState::FORMAT_FIELD_TYPES, frontend
        // app/utils/form-schema.ts), so the type says so too.
        beantragt_starter_field('zuschuss', 'number', 'Beantragter Zuschuss in Euro', 'Requested grant in euro', TRUE, [
          'format' => 'currency',
          'placeholder' => '500',
          'validation' => ['min' => 0, 'step' => 0.01],
        ] + $financial, ['placeholder' => '500']),
        // The club's account: no person.* profile key, so it never lands in
        // the chair's personal profile (security review of slices 4 and 5).
        beantragt_starter_field('kontoinhaber', 'text', 'Kontoinhaber', 'Account holder', TRUE, $financial),
        beantragt_starter_field('iban', 'text', 'IBAN', 'IBAN', TRUE, [
          'format' => 'iban',
        ] + $financial),
        beantragt_starter_field('bic', 'text', 'BIC', 'BIC', FALSE, [
          'format' => 'bic',
        ] + $financial),
      ],
    ),
    beantragt_starter_section(
      'anlagen',
      'Anlagen',
      'Attachments',
      'Der Kostenplan der Veranstaltung als PDF.',
      'The cost plan of the event as PDF.',
      [
        beantragt_starter_field('kostenplan', 'file', 'Kostenplan', 'Cost plan', TRUE, [
          'validation' => ['maxFiles' => 1, 'maxSizeBytes' => 10485760],
          'analysis' => [
            'enabled' => TRUE,
            'version' => 1,
            'expectedDocumentType' => 'Kosten- und Finanzierungsplan einer Veranstaltung / Cost and funding plan of an event',
            'staffPrompt' => 'Lies für die Sachbearbeitung die Gesamtsumme der Kosten als Zahl in Euro aus. Gib keine Namen, Kennungen oder sonstigen Beträge aus und triff keine Entscheidung. Read the total of the costs as a number in euro for the case worker. Return no names, identifiers or other amounts and make no decision.',
            'resultSchema' => [
              'type' => 'object',
              'required' => ['ist_kostenplan'],
              'properties' => [
                'ist_kostenplan' => [
                  'type' => 'boolean',
                  'title' => 'Kostenplan einer Veranstaltung / Cost plan of an event',
                  'nonPersonal' => TRUE,
                ],
                'gesamtsumme' => [
                  'type' => 'number',
                  'title' => 'Gesamtsumme laut Kostenplan in Euro / Total on the cost plan in euro',
                  'purposeBound' => TRUE,
                  'compareWith' => 'gesamtkosten',
                ],
              ],
            ],
            'egressPolicy' => 'sovereign_only',
            'modelClass' => 'openai_compatible',
          ],
        ]),
      ],
    ),
  ];
}

/**
 * Sections of form C, bulky waste collection.
 *
 * The pick-up place is the applicant's home in most cases, so its address
 * fields are contact data; items, date and photo describe things, not people.
 *
 * @return list<array<string, mixed>>
 *   Sections.
 */
function beantragt_showcase_bulky_waste_sections(): array {
  $not_personal = ['privacy' => ['kind' => 'not_personal']];
  $contact = ['privacy' => ['kind' => 'contact']];

  $place = beantragt_starter_section(
    'abholort',
    'Abholort',
    'Pick-up place',
    'Die Anschrift, vor der die Gegenstände am Abholtag stehen.',
    'The address in front of which the items stand on the collection day.',
    [
      beantragt_starter_field('abholort_strasse', 'text', 'Straße', 'Street', TRUE, $contact),
      beantragt_starter_field('abholort_hausnummer', 'text', 'Hausnummer', 'House number', TRUE, [
        'validation' => ['maxLength' => 10],
      ] + $contact),
      beantragt_starter_field('abholort_plz', 'text', 'Postleitzahl', 'Postcode', TRUE, [
        'format' => 'postal_code',
        'validation' => ['maxLength' => 5],
      ] + $contact),
      beantragt_starter_field('abholort_ort', 'text', 'Ort', 'Town', TRUE, [
        'placeholder' => 'Musterstadt',
      ] + $contact, ['placeholder' => 'Musterstadt']),
    ],
  );
  $place['addressGroups'] = [
    [
      'id' => 'abholort_gruppe',
      'label' => 'Abholort',
      'fields' => [
        'street' => 'abholort_strasse',
        'houseNumber' => 'abholort_hausnummer',
        'postcode' => 'abholort_plz',
        'city' => 'abholort_ort',
      ],
      'autocomplete' => TRUE,
    ],
  ];

  $items = beantragt_starter_section(
    'gegenstaende',
    'Gegenstände und Termin',
    'Items and date',
    'Was abgeholt werden soll und wann. Höchstens 20 Teile je Abholung.',
    'What is to be collected and when. At most 20 items per collection.',
    [
      beantragt_starter_field('art_gegenstaende', 'select', 'Überwiegende Art der Gegenstände', 'Main type of items', TRUE, [
        'options' => [
          'Möbel',
          'Matratzen und Lattenroste',
          'Teppiche und Bodenbeläge',
          'Metallteile wie Fahrräder',
          'Gemischter Hausrat',
        ],
      ] + $not_personal, [
        'options' => [
          'Möbel' => 'Furniture',
          'Matratzen und Lattenroste' => 'Mattresses and slatted frames',
          'Teppiche und Bodenbeläge' => 'Carpets and floor coverings',
          'Metallteile wie Fahrräder' => 'Metal items such as bicycles',
          'Gemischter Hausrat' => 'Mixed household items',
        ],
      ]),
      beantragt_starter_field('anzahl_teile', 'number', 'Anzahl der Teile', 'Number of items', TRUE, [
        'validation' => ['min' => 1, 'max' => 20, 'step' => 1],
      ] + $not_personal),
      beantragt_starter_field('wunschtermin', 'date', 'Wunschtermin', 'Preferred date', TRUE, $not_personal),
      beantragt_starter_field('elektrogeraete', 'checkbox', 'Es sind Elektrogroßgeräte dabei, zum Beispiel ein Kühlschrank oder eine Waschmaschine.', 'There are large electrical appliances, for example a fridge or a washing machine.', FALSE, $not_personal),
      beantragt_starter_field('anzahl_elektrogeraete', 'number', 'Anzahl der Elektrogroßgeräte', 'Number of large electrical appliances', TRUE, [
        'conditionalOn' => ['fieldId' => 'elektrogeraete', 'value' => TRUE],
        'validation' => ['min' => 1, 'max' => 5, 'step' => 1],
      ] + $not_personal),
      beantragt_starter_field('beschreibung_gegenstaende', 'textarea', 'Beschreibung der Gegenstände', 'Description of the items', TRUE, [
        'placeholder' => 'Zum Beispiel: ein Sofa, zwei Stühle, ein Lattenrost',
        'validation' => ['minLength' => 10, 'maxLength' => 1000],
      ] + $not_personal, ['placeholder' => 'For example: a sofa, two chairs, a slatted frame']),
      beantragt_starter_field('foto_gegenstaende', 'file', 'Foto der Gegenstände', 'Photo of the items', FALSE, [
        'validation' => ['maxFiles' => 3, 'maxSizeBytes' => 10485760],
        // A photo in front of a house can show people, house numbers or
        // number plates: personal in the honest reading, redacted when
        // possible before the sovereign analysis.
        'privacy' => ['kind' => 'other_personal'],
        'analysis' => beantragt_showcase_bulky_waste_photo_analysis(),
      ]),
    ],
  );

  return [$place, $items];
}

/**
 * Photo analysis of form C: one citizen story, two criteria, sovereign.
 */
function beantragt_showcase_bulky_waste_photo_analysis(): array {
  return [
    'enabled' => TRUE,
    'version' => 1,
    'expectedDocumentType' => 'Foto von Sperrmüll aus einem Haushalt / Photo of bulky waste from a household',
    'citizenPrompt' => 'Prüfe als unverbindliche Vorprüfung, ob das Foto Sperrmüll aus einem Haushalt zeigt, etwa Möbel, Matratzen oder Teppiche, und ob Bauschutt, Farbeimer oder Autoreifen zu sehen sind, die nicht zum Sperrmüll gehören. Beschreibe keine Personen, lies keine Hausnummern oder Kennzeichen aus und triff keine Entscheidung. Check as a non-binding pre-check whether the photo shows bulky waste from a household, such as furniture, mattresses or carpets, and whether rubble, paint buckets or car tyres are visible, which are no bulky waste. Describe no people, read out no house numbers or number plates and make no decision.',
    'redaction' => 'when_possible',
    'resultSchema' => [
      'type' => 'object',
      'required' => ['haushaltsgegenstaende_erkennbar', 'keine_fremdstoffe'],
      'properties' => beantragt_showcase_boolean_properties([
        'haushaltsgegenstaende_erkennbar' => 'Gegenstände aus einem Haushalt erkennbar / Household items visible',
        'keine_fremdstoffe' => 'Kein Bauschutt, keine Farbe, keine Reifen erkennbar / No rubble, paint or tyres visible',
      ]),
    ],
    'egressPolicy' => 'sovereign_only',
    'modelClass' => 'openai_compatible',
  ];
}

/**
 * Builds non-personal boolean result properties from key and title.
 *
 * @param array<string, string> $titles
 *   Bilingual titles per result key.
 *
 * @return array<string, array<string, mixed>>
 *   Result schema properties.
 */
function beantragt_showcase_boolean_properties(array $titles): array {
  $properties = [];
  foreach ($titles as $key => $title) {
    $properties[$key] = ['type' => 'boolean', 'title' => $title, 'nonPersonal' => TRUE];
  }
  return $properties;
}

/**
 * Returns the public documents (leaflets) of the showcase forms.
 *
 * Every document is a form download and a context source of its form. The
 * two quotes of form A's precheck stand on single lines of its leaflet.
 *
 * @return array<string, array<string, mixed>>
 *   Definitions keyed by document key.
 */
function beantragt_showcase_document_definitions(): array {
  return [
    'outdoor-dining-leaflet' => [
      'form' => 'outdoor-dining-permit',
      'placement' => 'form_sidebar',
      'filename' => 'merkblatt-aussengastronomie-musterstadt.pdf',
      'title_de' => 'Merkblatt Sondernutzung Außengastronomie Musterstadt',
      'title_en' => 'Musterstadt outdoor dining permit leaflet',
      'body_de' => '<p>Wann eine Sondernutzungserlaubnis nötig ist, welche Fläche frei bleiben muss und welche Unterlagen der Antrag braucht.</p>',
      'body_en' => '<p>When a permit is needed, which space must stay clear and which documents the application needs (in German).</p>',
      'pages' => [
        [
          'Stadt Musterstadt · Bürgerbüro',
          'Merkblatt: Sondernutzung für Außengastronomie',
          'Stand: November 2026 · Fiktives Demo-Merkblatt',
          '',
          'Wann brauche ich eine Erlaubnis?',
          'Eine Sondernutzungserlaubnis braucht, wer öffentliches Straßenland nutzt.',
          'Dazu gehören Gehwege, Plätze, Fußgängerzonen und Parkstreifen.',
          'Private Flächen, etwa ein eigener Hof, brauchen keine Erlaubnis der Stadt.',
          '',
          'Was muss frei bleiben?',
          'Neben der Fläche muss ein Gehweg von mindestens 1,50 Metern frei bleiben.',
          'Hauseingänge, Einfahrten, Hydranten und Schächte bleiben immer frei.',
          'Heizstrahler sind nur mit Gas aus geprüften Flaschen erlaubt.',
          '',
          'Welche Unterlagen brauche ich?',
          '- einen Lageplan mit Maßen, Maßstab und der verbleibenden Gehwegbreite',
          '- bis zu drei Fotos der Fläche',
          '- den Nachweis einer Betriebshaftpflichtversicherung',
          '',
          'Wie lange dauert es?',
          'In der Regel zwei bis drei Wochen. Die Erlaubnis gilt für eine Saison',
          '(1. April bis 31. Oktober) oder für ein Jahr und ist widerruflich.',
          '',
          'Gebühren',
          'Die Gebühr richtet sich nach Fläche und Dauer. Sie steht im Bescheid.',
          '',
          'Kontakt: Bürgerbüro, Rathausplatz 1, 12345 Musterstadt',
          'Telefon 030 23125 200 · buergerbuero@musterstadt.example.org',
        ],
      ],
    ],
    'outdoor-dining-design-guide' => [
      'form' => 'outdoor-dining-permit',
      'placement' => 'form_sidebar',
      'filename' => 'gestaltungshinweise-aussengastronomie-musterstadt.pdf',
      'title_de' => 'Gestaltungshinweise für Außengastronomie Musterstadt',
      'title_en' => 'Musterstadt design notes for outdoor dining',
      'body_de' => '<p>Hinweise zu Möbeln, Schirmen, Abgrenzungen und Werbung auf der Fläche.</p>',
      'body_en' => '<p>Notes on furniture, parasols, barriers and advertising on the area (in German).</p>',
      'pages' => [
        [
          'Stadt Musterstadt · Bürgerbüro',
          'Gestaltungshinweise für Außengastronomie',
          'Stand: November 2026 · Fiktives Demo-Merkblatt',
          '',
          'Möbel',
          'Tische und Stühle in gedeckten Farben, ohne Werbeaufdrucke.',
          'Nach Betriebsschluss werden die Möbel zusammengestellt oder abgeräumt.',
          '',
          'Schirme und Abgrenzungen',
          'Sonnenschirme ohne Bodenverankerung, höchstens drei Meter Durchmesser.',
          'Abgrenzungen sind höchstens 80 Zentimeter hoch und nicht fest montiert.',
          '',
          'Werbung',
          'Ein Aufsteller je Betrieb ist erlaubt, er steht innerhalb der Fläche.',
          '',
          'Kontakt: Bürgerbüro, Rathausplatz 1, 12345 Musterstadt',
          'Telefon 030 23125 200 · buergerbuero@musterstadt.example.org',
        ],
      ],
    ],
    'club-grant-guideline' => [
      'form' => 'club-event-grant',
      'placement' => 'form_sidebar',
      'filename' => 'richtlinie-vereinsfoerderung-musterstadt.pdf',
      'title_de' => 'Richtlinie zur Förderung von Vereinsveranstaltungen Musterstadt',
      'title_en' => 'Musterstadt guideline on grants for club events',
      'body_de' => '<p>Wer gefördert wird, wie hoch der Zuschuss sein kann und welche Nachweise nötig sind.</p>',
      'body_en' => '<p>Who is eligible, how high the grant can be and which proofs are needed (in German).</p>',
      'pages' => [
        [
          'Stadt Musterstadt · Hauptamt',
          'Richtlinie zur Förderung von Vereinsveranstaltungen',
          'Stand: November 2026 · Fiktive Demo-Richtlinie',
          '',
          'Wer wird gefördert?',
          'Eingetragene Vereine mit Sitz in Musterstadt für öffentliche Veranstaltungen.',
          '',
          'Wie hoch ist der Zuschuss?',
          'Bis zu 50 Prozent der Gesamtkosten, höchstens 2.000 Euro je Veranstaltung.',
          '',
          'Welche Nachweise sind nötig?',
          '- ein Kosten- und Finanzierungsplan der Veranstaltung',
          '- nach der Veranstaltung ein kurzer Verwendungsnachweis',
          '',
          'Wann muss der Antrag da sein?',
          'Spätestens acht Wochen vor der Veranstaltung.',
          '',
          'Kontakt: Hauptamt, Rathausplatz 1, 12345 Musterstadt',
          'Telefon 030 23125 300 · hauptamt@musterstadt.example.org',
        ],
      ],
    ],
  ];
}

/**
 * Returns the Info-Abschnitte (content sections) of the showcase.
 *
 * The contact section is jurisdiction-wide. Other form sections are scoped
 * to their form; the office section uses org_start.
 *
 * @return array<string, array<string, mixed>>
 *   Definitions keyed by section key. Scope: "form" (form key), "org"
 *   (organisation slug) or neither (jurisdiction).
 */
function beantragt_showcase_content_section_definitions(): array {
  return [
    // The one demo notice of the public demo, on the municipality's start
    // page; forms and cards carry none of their own.
    'start-demo-notice' => [
      'placement' => 'org_start',
      'weight' => -10,
      'title_de' => 'Hinweis',
      'body_de' => '<p>Stadt Musterstadt ist erfunden. Hier gestellte Anträge erreichen keine Behörde. Bitte geben Sie keine echten persönlichen Daten ein.</p>',
      'title_en' => 'Note',
      'body_en' => '<p>Musterstadt is a fictional town. Applications made here do not reach any public authority. Please do not enter real personal data.</p>',
    ],
    'footer-contact' => [
      'placement' => 'form_sidebar',
      'weight' => 50,
      'title_de' => 'Kontakt Stadt Musterstadt',
      'body_de' => '<p>Stadt Musterstadt, Rathausplatz 1, 12345 Musterstadt. Telefon 030 23125 100, service@musterstadt.example.org.</p>',
      'title_en' => 'Contact City of Musterstadt',
      'body_en' => '<p>City of Musterstadt, Rathausplatz 1, 12345 Musterstadt. Phone 030 23125 100, service@musterstadt.example.org.</p>',
    ],
    'org-start-grants' => [
      'placement' => 'org_start',
      'org' => 'central-administration',
      'weight' => 0,
      'title_de' => 'Förderung für Vereine',
      'body_de' => '<p>Das Hauptamt fördert öffentliche Veranstaltungen eingetragener Vereine. Stellen Sie den Antrag spätestens acht Wochen vorher.</p>',
      'title_en' => 'Grants for clubs',
      'body_en' => '<p>The central administration supports public events of registered clubs. Apply at least eight weeks in advance.</p>',
    ],
    'outdoor-dining-intro' => [
      'placement' => 'form_intro',
      'form' => 'outdoor-dining-permit',
      'weight' => 0,
      'title_de' => 'Bevor Sie beginnen',
      'body_de' => '<p>Halten Sie einen Lageplan mit Maßen und den Nachweis Ihrer Betriebshaftpflicht bereit. Neben der Fläche müssen mindestens 1,50 Meter Gehweg frei bleiben.</p>',
      'title_en' => 'Before applying for an outdoor dining permit',
      'body_en' => '<p>Have a site plan with measurements and your proof of liability insurance at hand. At least 1.50 metres of pavement must stay clear next to the area.</p>',
    ],
    'outdoor-dining-sidebar' => [
      'placement' => 'form_sidebar',
      'form' => 'outdoor-dining-permit',
      'weight' => 0,
      'title_de' => 'Bearbeitung und Gebühren',
      'body_de' => '<p>Bearbeitungszeit: zwei bis drei Wochen. Die Gebühr richtet sich nach Fläche und Dauer. Fragen beantwortet das Bürgerbüro unter 030 23125 200.</p>',
      'title_en' => 'Processing and fees',
      'body_en' => '<p>Processing time: two to three weeks. The fee depends on area and period. The citizen services office answers questions on 030 23125 200.</p>',
    ],
    'outdoor-dining-success' => [
      'placement' => 'submission_success',
      'form' => 'outdoor-dining-permit',
      'weight' => 0,
      'title_de' => 'Wie es weitergeht',
      'body_de' => '<p>Das Bürgerbüro prüft Ihren Antrag und meldet sich, falls etwas fehlt. Die Erlaubnis mit ihren Auflagen erhalten Sie als Bescheid im Bürgerportal.</p>',
      'title_en' => 'What happens next',
      'body_en' => '<p>The citizen services office checks your application and contacts you if anything is missing. You receive the permit with its conditions as a notice in the citizen portal.</p>',
    ],
    'club-grant-intro' => [
      'placement' => 'form_intro',
      'form' => 'club-event-grant',
      'weight' => 0,
      'title_de' => 'Bevor Sie beginnen',
      'body_de' => '<p>Für diesen Antrag melden Sie sich an. Halten Sie die Vereinsregisternummer, die Bankverbindung des Vereins und den Kostenplan bereit.</p>',
      'title_en' => 'Before applying for a club grant',
      'body_en' => '<p>This application requires you to sign in. Have the register number, the club bank account and the cost plan at hand.</p>',
    ],
    'club-grant-sidebar' => [
      'placement' => 'form_sidebar',
      'form' => 'club-event-grant',
      'weight' => 0,
      'title_de' => 'Höhe des Zuschusses',
      'body_de' => '<p>Bis zu 50 Prozent der Gesamtkosten, höchstens 2.000 Euro je Veranstaltung. Fragen beantwortet das Hauptamt unter 030 23125 300.</p>',
      'title_en' => 'Amount of the grant',
      'body_en' => '<p>Up to 50 percent of the total cost, at most 2,000 euro per event. The central administration answers questions on 030 23125 300.</p>',
    ],
    'club-grant-success' => [
      'placement' => 'submission_success',
      'form' => 'club-event-grant',
      'weight' => 0,
      'title_de' => 'Wie es weitergeht',
      'body_de' => '<p>Das Hauptamt prüft den Antrag und den Kostenplan. Über den Zuschuss entscheidet ein Bescheid, den Sie im Bürgerportal finden.</p>',
      'title_en' => 'What happens next',
      'body_en' => '<p>The central administration checks the application and the cost plan. A notice decides on the grant; you find it in the citizen portal.</p>',
    ],
  ];
}

/**
 * Returns the three Textbausteine, one per boilerplate type.
 *
 * @return array<string, array<string, mixed>>
 *   Definitions keyed by boilerplate key.
 */
function beantragt_showcase_boilerplate_definitions(): array {
  return [
    'status-note-documents-complete' => [
      'type' => 'status_notes',
      'title_de' => '070 Unterlagen vollständig, Prüfung läuft',
      'body_de' => '<p>Ihre Unterlagen sind vollständig. Wir prüfen jetzt Ihren Antrag und melden uns, sobald die Entscheidung feststeht.</p>',
      'title_en' => '070 Documents complete, review under way',
      'body_en' => '<p>Your documents are complete. We are now reviewing your application and will contact you as soon as the decision has been made.</p>',
    ],
    'service-provider-citizen-services' => [
      'type' => 'service_provider',
      'title_de' => 'Bürgerbüro der Stadt Musterstadt',
      'body_de' => '<p>Bürgerbüro der Stadt Musterstadt, Rathausplatz 1, 12345 Musterstadt. Telefon 030 23125 200, buergerbuero@musterstadt.example.org. Montag bis Freitag 8 bis 16 Uhr.</p>',
      'title_en' => 'Citizen services office of the City of Musterstadt',
      'body_en' => '<p>Citizen services office of the City of Musterstadt, Rathausplatz 1, 12345 Musterstadt. Phone 030 23125 200, buergerbuero@musterstadt.example.org. Monday to Friday 8 am to 4 pm.</p>',
    ],
    'remarks-revocable-permit' => [
      'type' => 'remarks',
      'title_de' => 'Erlaubnis widerruflich',
      'body_de' => '<p>Die Erlaubnis ist widerruflich. Bei Bauarbeiten oder Veranstaltungen kann die Stadt verlangen, die Fläche vorübergehend zu räumen.</p>',
      'title_en' => 'Permit revocable',
      'body_en' => '<p>The permit is revocable. During construction work or events the city may require the area to be cleared for a while.</p>',
    ],
  ];
}

/**
 * Renders line-based pages as a small PDF with the standard Helvetica font.
 *
 * Plain Tj strings, so the context indexer extracts the text line by line.
 *
 * @param list<list<string>> $pages
 *   Lines per page.
 */
function beantragt_showcase_pdf(array $pages): string {
  if ($pages === []) {
    throw new InvalidArgumentException('A showcase PDF needs at least one page.');
  }
  $page_ids = [];
  $objects = [
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
  ];
  foreach ($pages as $index => $lines) {
    $page_id = 4 + ($index * 2);
    $content_id = $page_id + 1;
    $page_ids[] = sprintf('%d 0 R', $page_id);
    $stream_lines = ['BT', '/F1 11 Tf', '56 790 Td', '16 TL'];
    foreach ($lines as $line) {
      $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
      $encoded = is_string($encoded) ? $encoded : $line;
      $stream_lines[] = sprintf('(%s) Tj', str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded));
      $stream_lines[] = 'T*';
    }
    $stream_lines[] = 'ET';
    $stream = implode("\n", $stream_lines) . "\n";
    $objects[$page_id] = sprintf(
      '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
      $content_id,
    );
    $objects[$content_id] = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($stream), $stream);
  }
  $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $page_ids), count($page_ids));
  ksort($objects);

  $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
  $offsets = [];
  foreach ($objects as $id => $object) {
    $offsets[$id] = strlen($pdf);
    $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
  }
  $xref_offset = strlen($pdf);
  $size = max(array_keys($objects)) + 1;
  $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $size);
  for ($id = 1; $id < $size; $id++) {
    $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
  }
  $pdf .= sprintf("trailer << /Root 1 0 R /Size %d >>\nstartxref\n%d\n%%%%EOF\n", $size, $xref_offset);
  return $pdf;
}

/**
 * Deterministic context source placeholders for validation without a site.
 *
 * @return array<string, array{id: string, title: string, version: string, changed: int}>
 *   Placeholders per document key.
 */
function beantragt_showcase_placeholder_sources(): array {
  $sources = [];
  foreach (beantragt_showcase_document_definitions() as $key => $document) {
    $sources[$key] = [
      'id' => beantragt_showcase_uuid('document:' . $key),
      'title' => $document['title_de'],
      'version' => 'revision:0:changed:0',
      'changed' => 0,
    ];
  }
  return $sources;
}
