<?php

/**
 * @file
 * Defines the small bilingual starter catalogue for new beantragt.ai stacks.
 */

declare(strict_types=1);

/**
 * Builds one localized form field.
 *
 * @param array<string, mixed> $extra
 *   Canonical German field metadata.
 * @param array<string, mixed> $english
 *   English placeholder and option labels.
 *
 * @return array<string, mixed>
 *   FormSchema field definition.
 */
function beantragt_starter_field(
  string $id,
  string $type,
  string $label_de,
  string $label_en,
  bool $required = FALSE,
  array $extra = [],
  array $english = [],
): array {
  return [
    'id' => $id,
    'type' => $type,
    'label' => $label_de,
    'i18n' => [
      'en' => ['label' => $label_en] + $english,
    ],
    'required' => $required,
  ] + $extra;
}

/**
 * Builds one localized FormSchema section.
 *
 * @param array<int, array<string, mixed>> $fields
 *   Section fields.
 *
 * @return array<string, mixed>
 *   FormSchema section definition.
 */
function beantragt_starter_section(
  string $id,
  string $title_de,
  string $title_en,
  string $description_de,
  string $description_en,
  array $fields,
): array {
  return [
    'id' => $id,
    'title' => $title_de,
    'description' => $description_de,
    'i18n' => [
      'en' => [
        'title' => $title_en,
        'description' => $description_en,
      ],
    ],
    'fields' => $fields,
  ];
}

/**
 * Returns the shared applicant section.
 *
 * @return array<string, mixed>
 *   Applicant section.
 */
function beantragt_starter_applicant_section(): array {
  return beantragt_starter_section(
    'applicant',
    'Antragstellende Person',
    'Applicant',
    'Kontaktdaten für diesen Antrag.',
    'Contact details for this application.',
    [
      beantragt_starter_field(
        'full_name',
        'text',
        'Vollständiger Name',
        'Full name',
        TRUE,
        [
          'placeholder' => 'Max Mustermann',
          'crm_key' => 'person.full_name',
          'privacy' => ['kind' => 'identity'],
          'validation' => ['minLength' => 3, 'maxLength' => 180],
        ],
        ['placeholder' => 'Jane Example'],
      ),
      beantragt_starter_field(
        'email',
        'email',
        'E-Mail-Adresse',
        'Email address',
        TRUE,
        [
          'placeholder' => 'max@example.org',
          'crm_key' => 'person.email',
          'privacy' => ['kind' => 'contact'],
        ],
        ['placeholder' => 'jane@example.org'],
      ),
      beantragt_starter_field(
        'street_address',
        'text',
        'Straße und Hausnummer',
        'Street and house number',
        TRUE,
        [
          'crm_key' => 'person.street',
          'privacy' => ['kind' => 'contact'],
          'validation' => ['maxLength' => 220],
        ],
      ),
      beantragt_starter_field(
        'postal_code',
        'text',
        'Postleitzahl',
        'Postal code',
        TRUE,
        [
          'format' => 'postal_code',
          'crm_key' => 'person.postal_code',
          'privacy' => ['kind' => 'contact'],
        ],
      ),
      beantragt_starter_field(
        'city',
        'text',
        'Ort',
        'City',
        TRUE,
        [
          'crm_key' => 'person.city',
          'privacy' => ['kind' => 'contact'],
          'validation' => ['maxLength' => 160],
        ],
      ),
    ],
  );
}

/**
 * Returns the replaced Golden Master definition for a guarded migration.
 *
 * @return array<string, mixed>
 *   Legacy dog deregistration definition.
 */
function beantragt_starter_legacy_dog_deregistration_definition(): array {
  return [
    'key' => 'dog-tax-deregistration',
    'title' => 'Hund abmelden',
    'title_en' => 'Deregister a dog',
    'description' => 'Abmeldung eines Hundes bei Wegzug, Abgabe, Tod oder aus einem anderen Grund.',
    'description_en' => 'Deregistration of a dog due to relocation, transfer, death or another reason.',
    'category' => 'taxes-and-animals',
    'sections' => [
      beantragt_starter_applicant_section(),
      beantragt_starter_section(
        'deregistration',
        'Angaben zur Abmeldung',
        'Deregistration details',
        'Bitte machen Sie die Angaben zur Abmeldung.',
        'Please provide the deregistration details.',
        [
          beantragt_starter_field('dog_tax_number', 'text', 'Kassenzeichen oder Steuernummer', 'Account or tax number', FALSE, [
            'format' => 'reference_number',
            'validation' => ['maxLength' => 80],
          ]),
          beantragt_starter_field('dog_name', 'text', 'Name des Hundes', 'Name of the dog', TRUE, [
            'privacy' => ['kind' => 'not_personal'],
            'validation' => ['maxLength' => 120],
          ]),
          beantragt_starter_field('dog_tag_number', 'text', 'Nummer der Hundemarke', 'Dog tag number', FALSE, [
            'validation' => ['maxLength' => 80],
          ]),
          beantragt_starter_field('deregistration_date', 'date', 'Abmeldedatum', 'Deregistration date', TRUE),
          beantragt_starter_field(
            'deregistration_reason',
            'select',
            'Grund der Abmeldung',
            'Reason for deregistration',
            TRUE,
            [
              'options' => [
                'Wegzug aus der Kommune',
                'Abgabe an eine andere Person',
                'Hund ist verstorben',
                'Hund ist entlaufen',
                'Sonstiger Grund',
              ],
            ],
            [
              'options' => [
                'Wegzug aus der Kommune' => 'Moved out of the municipality',
                'Abgabe an eine andere Person' => 'Transferred to another person',
                'Hund ist verstorben' => 'Dog has died',
                'Hund ist entlaufen' => 'Dog is missing',
                'Sonstiger Grund' => 'Other reason',
              ],
            ],
          ),
          beantragt_starter_field('new_holder', 'textarea', 'Neue Halterin oder neuer Halter, falls bekannt', 'New keeper, if known', FALSE, [
            'sensitive' => TRUE,
            'privacy' => ['kind' => 'other_personal'],
            'validation' => ['maxLength' => 600],
          ]),
        ],
      ),
    ],
  ];
}

/**
 * Builds the reviewed draft or consent-gated demo analysis profile.
 *
 * @return array<string, mixed>
 *   Attachment analysis profile.
 */
function beantragt_starter_plan_analysis(string $kind, bool $external_analysis): array {
  $is_area = $kind === 'setback_area';
  $criterion = $is_area ? 'setback_areas_shown' : 'required_distances_shown';
  $criterion_title = $is_area
    ? 'Notwendige Abstandsflächen dargestellt / Required setback areas shown'
    : 'Notwendige Abstände dargestellt / Required distances shown';
  $scale_title = $is_area
    ? 'Maßstab 1:200 erkennbar / Scale 1:200 visible'
    : 'Maßstab mindestens 1:250 erkennbar / Scale of at least 1:250 visible';
  $profile = [
    'enabled' => $external_analysis,
    'version' => 1,
    'expectedDocumentType' => $is_area
      ? 'Abstandsflächenplan nach Art. 6 Abs. 2 Satz 3 BayBO / Setback area plan under Art. 6(2), sentence 3 BayBO'
      : 'Abstandsplan nach Art. 28 oder Art. 30 BayBO / Required distance plan under Art. 28 or Art. 30 BayBO',
    'promptSuggestion' => $is_area
      ? 'Prüfe ausschließlich als unverbindliche Vorprüfung, ob Grenzverlauf, Bestandsgebäude, geplante Gebäude in Rot, notwendige Abstandsflächen und Maßstab 1:200 erkennbar sind. Check only as a non-binding pre-check whether plot boundaries, existing buildings, planned buildings in red, required setback areas and scale 1:200 are visible.'
      : 'Prüfe ausschließlich als unverbindliche Vorprüfung, ob Grenzverlauf, Bestandsgebäude, geplante Gebäude in Rot, notwendige Abstände, ein Maßstab nicht kleiner als 1:250 und die erforderliche Unterschrift erkennbar sind. Check only as a non-binding pre-check whether plot boundaries, existing buildings, planned buildings in red, required distances, a scale of at least 1:250 and the required signature are visible.',
    'resultSchema' => [
      'type' => 'object',
      'required' => [
        'boundary_shown',
        'existing_buildings_shown',
        'planned_buildings_shown',
        $criterion,
        'scale_shown',
      ],
      'properties' => [
        'boundary_shown' => [
          'type' => 'boolean',
          'title' => 'Grenzverlauf dargestellt / Plot boundary shown',
          'nonPersonal' => TRUE,
        ],
        'existing_buildings_shown' => [
          'type' => 'boolean',
          'title' => 'Vorhandene Gebäude dargestellt / Existing buildings shown',
          'nonPersonal' => TRUE,
        ],
        'planned_buildings_shown' => [
          'type' => 'boolean',
          'title' => 'Geplante Gebäude in Rot dargestellt / Planned buildings shown in red',
          'nonPersonal' => TRUE,
        ],
        $criterion => [
          'type' => 'boolean',
          'title' => $criterion_title,
          'nonPersonal' => TRUE,
        ],
        'scale_shown' => [
          'type' => 'boolean',
          'title' => $scale_title,
          'nonPersonal' => TRUE,
        ],
      ],
    ],
    'egressPolicy' => $external_analysis ? 'external_allowed' : 'no_ai',
  ];
  if (!$is_area) {
    $profile['resultSchema']['required'][] = 'designer_signature_shown';
    $profile['resultSchema']['properties']['designer_signature_shown'] = [
      'type' => 'boolean',
      'title' => 'Unterschrift der entwurfsverfassenden Person erkennbar / Designer signature visible',
      'nonPersonal' => TRUE,
    ];
  }
  if ($external_analysis) {
    $profile['modelClass'] = 'claude_sonnet';
  }
  return $profile;
}

/**
 * Builds the reviewed attachment profiles for the tree protection showcase.
 *
 * @return array<string, mixed>
 *   Attachment analysis profile.
 */
function beantragt_starter_tree_analysis(string $kind, bool $external_analysis): array {
  $is_site_plan = $kind === 'site_plan';
  $profile = [
    'enabled' => $external_analysis,
    'version' => 1,
    'expectedDocumentType' => $is_site_plan
      ? 'Lageplan zum Baumstandort / Site plan showing the tree location'
      : 'Übersichtsfotos des geschützten Baums / Overview photos of the protected tree',
    'promptSuggestion' => $is_site_plan
      ? 'Prüfe ausschließlich als unverbindliche Vorprüfung, ob Grundstücksgrenzen, Gebäude und der Standort des betroffenen Baums erkennbar markiert sind. Triff keine fachliche oder rechtliche Entscheidung. Check only as a non-binding pre-check whether plot boundaries, buildings and the location of the affected tree are visibly marked. Do not make a technical or legal decision.'
      : 'Prüfe ausschließlich als unverbindliche Vorprüfung, ob der betroffene Baum vollständig erkennbar ist, Stamm und Krone sichtbar sind und ein Größenbezug vorhanden ist. Triff keine fachliche oder rechtliche Entscheidung. Check only as a non-binding pre-check whether the affected tree, trunk and crown are visible and a size reference is present. Do not make a technical or legal decision.',
    'resultSchema' => [
      'type' => 'object',
      'required' => $is_site_plan
        ? ['property_boundary_visible', 'buildings_visible', 'tree_location_marked']
        : ['tree_visible', 'trunk_visible', 'crown_visible', 'size_reference_visible'],
      'properties' => $is_site_plan
        ? [
          'property_boundary_visible' => [
            'type' => 'boolean',
            'title' => 'Grundstücksgrenze erkennbar / Plot boundary visible',
            'nonPersonal' => TRUE,
          ],
          'buildings_visible' => [
            'type' => 'boolean',
            'title' => 'Gebäude erkennbar / Buildings visible',
            'nonPersonal' => TRUE,
          ],
          'tree_location_marked' => [
            'type' => 'boolean',
            'title' => 'Baumstandort markiert / Tree location marked',
            'nonPersonal' => TRUE,
          ],
        ]
        : [
          'tree_visible' => [
            'type' => 'boolean',
            'title' => 'Betroffener Baum erkennbar / Affected tree visible',
            'nonPersonal' => TRUE,
          ],
          'trunk_visible' => [
            'type' => 'boolean',
            'title' => 'Stamm erkennbar / Trunk visible',
            'nonPersonal' => TRUE,
          ],
          'crown_visible' => [
            'type' => 'boolean',
            'title' => 'Krone erkennbar / Crown visible',
            'nonPersonal' => TRUE,
          ],
          'size_reference_visible' => [
            'type' => 'boolean',
            'title' => 'Größenbezug erkennbar / Size reference visible',
            'nonPersonal' => TRUE,
          ],
        ],
    ],
    'egressPolicy' => $external_analysis ? 'external_allowed' : 'no_ai',
  ];
  if ($external_analysis) {
    $profile['modelClass'] = 'claude_sonnet';
  }
  return $profile;
}

/**
 * Returns the applicant section aligned with FIM S05000039.
 *
 * Given and family name and street and house number are separate, as the FIM
 * data schema requires them.
 *
 * @return array<string, mixed>
 *   Applicant section.
 */
function beantragt_starter_fim_applicant_section(): array {
  return beantragt_starter_section(
    'applicant',
    'Antragstellende Person',
    'Applicant',
    // Length limits follow the FIM S05000039 leaves the mapping fills.
    'Kontaktdaten für diesen Antrag.',
    'Contact details for this application.',
    [
      beantragt_starter_field('given_name', 'text', 'Vorname', 'Given name', TRUE, [
        'crm_key' => 'person.first_name',
        'privacy' => ['kind' => 'identity'],
        'validation' => ['maxLength' => 120],
      ]),
      beantragt_starter_field('family_name', 'text', 'Familienname', 'Family name', TRUE, [
        'crm_key' => 'person.last_name',
        'privacy' => ['kind' => 'identity'],
        'validation' => ['maxLength' => 120],
      ]),
      beantragt_starter_field('email', 'email', 'E-Mail-Adresse', 'Email address', TRUE, [
        'placeholder' => 'max@example.org',
        'crm_key' => 'person.email',
        'privacy' => ['kind' => 'contact'],
        'validation' => ['maxLength' => 254],
      ], ['placeholder' => 'jane@example.org']),
      beantragt_starter_field('phone', 'tel', 'Telefon', 'Phone', FALSE, [
        'crm_key' => 'person.phone',
        'privacy' => ['kind' => 'contact'],
        'validation' => ['maxLength' => 23],
      ]),
      beantragt_starter_field('street', 'text', 'Straße', 'Street', TRUE, [
        'crm_key' => 'person.street',
        'privacy' => ['kind' => 'contact'],
        'validation' => ['maxLength' => 55],
      ]),
      beantragt_starter_field('house_number', 'text', 'Hausnummer', 'House number', TRUE, [
        'crm_key' => 'person.house_number',
        'privacy' => ['kind' => 'contact'],
        'validation' => ['maxLength' => 9],
      ]),
      beantragt_starter_field('postal_code', 'text', 'Postleitzahl', 'Postal code', TRUE, [
        'format' => 'postal_code',
        'crm_key' => 'person.postal_code',
        'privacy' => ['kind' => 'contact'],
      ]),
      beantragt_starter_field('city', 'text', 'Ort', 'Town', TRUE, [
        'crm_key' => 'person.city',
        'privacy' => ['kind' => 'contact'],
        'validation' => ['maxLength' => 50],
      ]),
    ],
  );
}

/**
 * Returns the reduction reasons of FIM code list C05000061, in code order.
 *
 * @return string[]
 *   German option values; codes 1 to 8 follow this order.
 */
function beantragt_starter_dog_tax_reduction_options(): array {
  return [
    'Hund aus dem Tierheim',
    'Laufende Leistungen nach dem Zweiten oder Zwölften Buch Sozialgesetzbuch',
    'Rettungshund',
    'Jagdhund',
    'Hirtenhund, Herdenschutzhund oder Hütehund',
    'Assistenzhund, der Menschen mit Behinderungen unterstützt',
    'Bestandene Verhaltensprüfung',
    'Anderer Grund',
  ];
}

/**
 * Returns English labels for the reduction reasons.
 *
 * @return array<string, string>
 *   English labels keyed by German option value.
 */
function beantragt_starter_dog_tax_reduction_options_en(): array {
  return array_combine(beantragt_starter_dog_tax_reduction_options(), [
    'Dog from an animal shelter',
    'Ongoing benefits under Book II or XII of the Social Code',
    'Rescue dog',
    'Hunting dog',
    'Shepherd, livestock guardian or herding dog',
    'Assistance dog supporting people with disabilities',
    'Passed behaviour test',
    'Other reason',
  ]);
}

/**
 * Returns exactly four bilingual starter form definitions.
 *
 * @return array<int, array<string, mixed>>
 *   Starter catalogue.
 */
function beantragt_starter_form_catalog(bool $external_attachment_analysis = FALSE, bool $demo = FALSE): array {
  $applicant = beantragt_starter_applicant_section();

  $catalog = [
    [
      'key' => 'dog-tax-registration',
      // Applications go to the Stadtkasse where the jurisdiction has one.
      'organisation' => 'stadtkasse',
      'title' => 'Hund zur Hundesteuer anmelden',
      'title_en' => 'Register a dog for dog tax',
      'description' => 'Melden Sie Ihren Hund zur Hundesteuer an. Halten Sie Rasse, Geburtsdatum und, falls vorhanden, die Mikrochip-Nummer bereit.',
      'description_en' => 'Register your dog for dog tax. Have the breed, date of birth and, if available, the microchip number at hand.',
      'category' => 'taxes-and-animals',
      'sections' => [
        beantragt_starter_fim_applicant_section(),
        beantragt_starter_section(
          'dog',
          'Angaben zum Hund',
          'Dog details',
          'Bitte machen Sie die Angaben zum anzumeldenden Hund. Pro Antrag wird ein Hund angemeldet. Wird der Hund an einer anderen Anschrift gehalten, fragt die Stadtkasse die Anschrift gesondert bei Ihnen an.',
          'Please provide details about the dog being registered. Each application registers one dog. If the dog is kept at a different address, the city treasury will ask you for that address separately.',
          [
            beantragt_starter_field('dog_name', 'text', 'Name des Hundes', 'Name of the dog', TRUE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['maxLength' => 120],
            ]),
            beantragt_starter_field('dog_breed', 'text', 'Rasse', 'Breed', TRUE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['maxLength' => 160],
            ]),
            beantragt_starter_field('dog_birthdate', 'date', 'Geburtsdatum des Hundes', 'Date of birth of the dog', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
            ]),
            beantragt_starter_field('dog_chip_number', 'text', 'Mikrochip-Nummer', 'Microchip number', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['maxLength' => 40],
            ]),
            // No keeping-start date: FIM S05000039 has no field for it, so
            // the value would never reach the tax procedure (data minimisation).
            beantragt_starter_field('dogs_registered', 'number', 'Anzahl der mit diesem Antrag angemeldeten Hunde', 'Number of dogs registered with this application', TRUE, [
              'privacy' => ['kind' => 'not_personal'],
              // One dog per application; FIM rule R05000237 would require a
              // repeated dog group for more.
              'validation' => ['min' => 1, 'max' => 1, 'step' => 1],
              'defaultValue' => 1,
            ]),
            beantragt_starter_field('dogs_in_household', 'number', 'Anzahl aller Hunde im Haushalt (einschließlich dieses Hundes)', 'Number of all dogs in the household (including this dog)', TRUE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['min' => 1, 'max' => 20, 'step' => 1],
            ]),
            beantragt_starter_field(
              'dog_kept_elsewhere',
              'select',
              'Wird der Hund an einer anderen Anschrift gehalten?',
              'Is the dog kept at a different address?',
              TRUE,
              [
                'privacy' => ['kind' => 'not_personal'],
                'options' => ['Nein', 'Ja'],
              ],
              ['options' => ['Nein' => 'No', 'Ja' => 'Yes']],
            ),
          ],
        ),
        beantragt_starter_section(
          'reduction',
          'Ermäßigung oder Befreiung',
          'Reduction or exemption',
          'Geben Sie den Grund für die Ermäßigung oder Befreiung und die Bezeichnung Ihres Nachweises an.',
          'State the reason for the reduction or exemption and enter the name of your supporting document.',
          [
            beantragt_starter_field(
              'reduction_reason',
              'select',
              'Grund für die Ermäßigung oder Befreiung',
              'Reason for the reduction or exemption',
              TRUE,
              [
                // One option reveals a disability, another social benefits.
                'privacy' => ['kind' => 'special_category'],
                'sensitive' => TRUE,
                'options' => beantragt_starter_dog_tax_reduction_options(),
              ],
              ['options' => beantragt_starter_dog_tax_reduction_options_en()],
            ),
            beantragt_starter_field('reduction_reason_other', 'text', 'Anderer Grund', 'Other reason', TRUE, [
              'privacy' => ['kind' => 'special_category'],
              'sensitive' => TRUE,
              // 250 characters stay below the 1024-byte transfer bound.
              'validation' => ['maxLength' => 250],
              'conditionalOn' => ['fieldId' => 'reduction_reason', 'value' => 'Anderer Grund'],
            ]),
            beantragt_starter_field('reduction_documents', 'text', 'Bezeichnung des Nachweises (Textangabe)', 'Name of supporting document (text entry)', TRUE, [
              'privacy' => ['kind' => 'special_category'],
              'sensitive' => TRUE,
              'placeholder' => 'Zum Beispiel: Abgabevertrag des Tierheims',
              'validation' => ['maxLength' => 250],
            ], ['placeholder' => 'For example: adoption contract from the animal shelter']),
          ],
        ),
        beantragt_starter_section(
          'consent',
          'Einwilligungen',
          'Consents',
          'Ohne diese Einwilligungen kann der Antrag nicht bearbeitet werden.',
          'The application cannot be processed without these consents.',
          [
            beantragt_starter_field(
              'privacy_consent',
              'radio',
              'Einwilligung in die Verarbeitung personenbezogener Daten',
              'Consent to the processing of personal data',
              TRUE,
              [
                'privacy' => ['kind' => 'not_personal'],
                'options' => ['Ich stimme zu.'],
              ],
              ['options' => ['Ich stimme zu.' => 'I agree.']],
            ),
            beantragt_starter_field(
              'transfer_consent',
              'radio',
              'Einwilligung in die Weiterleitung Ihrer Angaben an das Steuerverfahren der Stadtkasse',
              'Consent to forwarding your details to the tax procedure of the city treasury',
              TRUE,
              [
                'privacy' => ['kind' => 'not_personal'],
                'options' => ['Ich stimme zu.'],
              ],
              ['options' => ['Ich stimme zu.' => 'I agree.']],
            ),
          ],
        ),
      ],
    ],
    [
      'key' => 'tree-felling-application',
      'title' => 'Fällung oder Veränderung eines geschützten Baums beantragen',
      'title_en' => 'Apply to fell or alter a protected tree',
      'description' => 'Sie möchten einen geschützten Baum fällen oder verändern? Dafür brauchen Sie vorher eine Genehmigung der Stadt.',
      'description_en' => 'Do you want to fell or alter a protected tree? You need the city\'s permission first.',
      'category' => 'environment-and-nature',
      'sections' => [
        $applicant,
        beantragt_starter_section(
          'property_and_tree',
          'Grundstück und Baum',
          'Property and tree',
          'Bitte beschreiben Sie Standort, Baum und beantragte Maßnahme.',
          'Please describe the location, tree and requested work.',
          [
            beantragt_starter_field('property_address', 'address', 'Adresse des Grundstücks', 'Property address', TRUE, [
              'privacy' => ['kind' => 'contact'],
              'widget' => [
                'autocomplete' => TRUE,
                'map' => TRUE,
                'boundary' => 'advisory',
              ],
            ]),
            beantragt_starter_field('parcel_reference', 'text', 'Gemarkung, Flur und Flurstück', 'Cadastral district and parcel reference', FALSE, [
              'validation' => ['maxLength' => 180],
            ]),
            beantragt_starter_field('tree_species', 'text', 'Baumart', 'Tree species', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['maxLength' => 120],
            ]),
            beantragt_starter_field(
              'growth_form',
              'select',
              'Wuchsform',
              'Growth form',
              FALSE,
              [
                'privacy' => ['kind' => 'not_personal'],
                'options' => ['Baumförmig', 'Flächige Hecke', 'Strauchgruppe', 'Unklar'],
              ],
              [
                'options' => [
                  'Baumförmig' => 'Tree-shaped',
                  'Flächige Hecke' => 'Broad hedge',
                  'Strauchgruppe' => 'Group of shrubs',
                  'Unklar' => 'Unclear',
                ],
              ],
            ),
            beantragt_starter_field('trunk_circumference_cm', 'number', 'Stammumfang in Zentimetern', 'Trunk circumference in centimetres', TRUE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['min' => 1, 'max' => 2000],
            ]),
            beantragt_starter_field('measurement_height_m', 'number', 'Messhöhe über dem Boden in Metern', 'Measurement height above ground in metres', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['min' => 0.1, 'max' => 10, 'step' => 0.1],
            ]),
            beantragt_starter_field(
              'requested_measure',
              'select',
              'Beantragte Maßnahme',
              'Requested work',
              TRUE,
              ['options' => ['Fällung', 'Kronenrückschnitt', 'Wurzelschnitt', 'Sonstige Veränderung']],
              [
                'options' => [
                  'Fällung' => 'Felling',
                  'Kronenrückschnitt' => 'Crown reduction',
                  'Wurzelschnitt' => 'Root pruning',
                  'Sonstige Veränderung' => 'Other alteration',
                ],
              ],
            ),
            beantragt_starter_field('reason', 'textarea', 'Begründung', 'Reason', TRUE, [
              'sensitive' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
              'validation' => ['minLength' => 20, 'maxLength' => 1600],
            ]),
            beantragt_starter_field('replacement_planting_planned', 'checkbox', 'Eine Ersatzpflanzung ist vorgesehen.', 'Replacement planting is planned.', FALSE),
          ],
        ),
        beantragt_starter_section(
          'tree_attachments',
          'Lageplan und Fotos',
          'Site plan and photos',
          'Laden Sie den Lageplan und die Fotos hier hoch. Ein Hinweis zu Ihren Unterlagen ist noch keine Entscheidung über Ihren Antrag.',
          'Upload the site plan and the photos here. A note on your documents is not yet a decision on your application.',
          [
            beantragt_starter_field('tree_site_plan', 'file', 'Lageplan mit markiertem Baumstandort', 'Site plan with marked tree location', TRUE, [
              'validation' => ['maxFiles' => 1, 'maxSizeBytes' => 10485760],
              'analysis' => beantragt_starter_tree_analysis('site_plan', $external_attachment_analysis),
            ]),
            beantragt_starter_field('tree_photos', 'file', 'Übersichtsfotos von Baum, Stamm und Krone', 'Overview photos of the tree, trunk and crown', TRUE, [
              'validation' => ['maxFiles' => 3, 'maxSizeBytes' => 10485760],
              'analysis' => beantragt_starter_tree_analysis('photos', $external_attachment_analysis),
            ]),
          ],
        ),
      ],
    ],
    [
      'key' => 'business-registration',
      'title' => 'Gewerbe anmelden',
      'title_en' => 'Register a business',
      'description' => 'Melden Sie an, dass Sie ein Gewerbe beginnen. Beschreiben Sie Ihre Tätigkeit möglichst genau.',
      'description_en' => 'Notify us that you are starting a business. Describe your activity as precisely as you can.',
      'category' => 'business',
      'sections' => [
        $applicant,
        beantragt_starter_section(
          'business',
          'Angaben zum Gewerbe',
          'Business details',
          'Bitte beschreiben Sie den Betrieb und die angemeldete Tätigkeit.',
          'Please describe the business and the activity being registered.',
          [
            beantragt_starter_field(
              'legal_form',
              'select',
              'Rechtsform',
              'Legal form',
              TRUE,
              ['options' => ['Einzelunternehmen', 'GbR', 'GmbH', 'UG', 'OHG', 'KG', 'Sonstige Rechtsform']],
              [
                'options' => [
                  'Einzelunternehmen' => 'Sole proprietorship',
                  'GbR' => 'Civil-law partnership (GbR)',
                  'GmbH' => 'Limited liability company (GmbH)',
                  'UG' => 'Entrepreneurial company (UG)',
                  'OHG' => 'General partnership (OHG)',
                  'KG' => 'Limited partnership (KG)',
                  'Sonstige Rechtsform' => 'Other legal form',
                ],
              ],
            ),
            beantragt_starter_field('business_name', 'text', 'Name des Gewerbebetriebes', 'Business name', TRUE, [
              'crm_key' => 'company.name',
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['maxLength' => 180],
            ]),
            beantragt_starter_field('business_address', 'text', 'Anschrift der Betriebsstätte', 'Business address', TRUE, [
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 220],
            ]),
            beantragt_starter_field(
              'activity_description',
              'textarea',
              'Angemeldete Tätigkeit',
              'Registered activity',
              TRUE,
              [
                'placeholder' => 'Bitte die Tätigkeit konkret beschreiben.',
                'sensitive' => TRUE,
                'privacy' => ['kind' => 'other_personal'],
                'validation' => ['minLength' => 20, 'maxLength' => 1200],
              ],
              ['placeholder' => 'Please describe the activity specifically.'],
            ),
            beantragt_starter_field('business_start_date', 'date', 'Beginn der Tätigkeit', 'Start date of the activity', TRUE),
            beantragt_starter_field(
              'activity_scope',
              'select',
              'Art der Ausübung',
              'Type of occupation',
              TRUE,
              ['options' => ['Haupterwerb', 'Nebenerwerb']],
              ['options' => ['Haupterwerb' => 'Primary occupation', 'Nebenerwerb' => 'Secondary occupation']],
            ),
            beantragt_starter_field('employees_count', 'number', 'Anzahl der Beschäftigten', 'Number of employees', FALSE, [
              'validation' => ['min' => 0, 'max' => 10000],
            ]),
          ],
        ),
      ],
    ],
    [
      'key' => 'setback-area-consent-bavaria',
      'title' => 'Zustimmung zur Abstandsflächenübernahme',
      'title_en' => 'Consent to transfer required setback areas',
      'description' => 'Als Nachbarin oder Nachbar stimmen Sie zu, dass Abstandsflächen eines Bauvorhabens auf Ihrem Grundstück liegen (Art. 6 Abs. 2 Satz 3 BayBO).',
      'description_en' => 'As the neighbour, you agree that setback areas of a building project lie on your plot (Art. 6(2) sentence 3 BayBO).',
      'category' => 'building-and-property',
      'source' => [
        'label' => 'Anlage 5: Zustimmung zur Abstandsflächenübernahme mit Erläuterungen',
        'authority' => 'Bayerisches Staatsministerium für Wohnen, Bau und Verkehr',
        'legal_basis' => 'Art. 6 Abs. 2 Satz 3 BayBO, Formularstand März 2024',
      ],
      'sections' => [
        $applicant,
        beantragt_starter_section(
          'building_plot',
          'Baugrundstück und bauliche Anlage',
          'Building plot and proposed structure',
          'Grundstück und bauliche Anlage, für die die Übernahme erklärt werden soll.',
          'Plot and proposed structure for which the transfer is requested.',
          [
            beantragt_starter_field('building_cadastral_district', 'text', 'Gemarkung', 'Cadastral district', TRUE, [
              'validation' => ['maxLength' => 160],
            ]),
            beantragt_starter_field('building_parcel_number', 'text', 'Flur- oder Flurstücksnummer', 'Parcel number', TRUE, [
              'validation' => ['maxLength' => 120],
            ]),
            beantragt_starter_field('building_municipality', 'text', 'Gemeinde', 'Municipality', TRUE, [
              'validation' => ['maxLength' => 160],
            ]),
            beantragt_starter_field('building_address', 'text', 'Straße und Hausnummer des Baugrundstücks', 'Street and house number of the building plot', TRUE, [
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 220],
            ]),
            beantragt_starter_field('structure_length_m', 'number', 'Länge der baulichen Anlage in Metern', 'Length of the structure in metres', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['min' => 0],
            ]),
            beantragt_starter_field('minimum_boundary_distance_m', 'number', 'Minimaler Abstand zur Nachbargrenze in Metern', 'Minimum distance to the neighbouring boundary in metres', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['min' => 0],
            ]),
            beantragt_starter_field('maximum_boundary_distance_m', 'number', 'Maximaler Abstand zur Nachbargrenze in Metern', 'Maximum distance to the neighbouring boundary in metres', FALSE, [
              'privacy' => ['kind' => 'not_personal'],
              'validation' => ['min' => 0],
            ]),
          ],
        ),
        beantragt_starter_section(
          'neighbour',
          'Nachbargrundstück und verfügungsberechtigte Person',
          'Neighbouring plot and authorised person',
          'Angaben zum Nachbargrundstück und zur erklärenden Person.',
          'Details of the neighbouring plot and the person giving consent.',
          [
            beantragt_starter_field('neighbour_full_name', 'text', 'Name der Nachbarin oder des Nachbarn', 'Name of the neighbour', TRUE, [
              'sensitive' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
              'validation' => ['maxLength' => 180],
            ]),
            beantragt_starter_field('neighbour_address', 'text', 'Anschrift der Nachbarin oder des Nachbarn', 'Address of the neighbour', TRUE, [
              'sensitive' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
              'validation' => ['maxLength' => 260],
            ]),
            beantragt_starter_field('neighbour_cadastral_district', 'text', 'Gemarkung des Nachbargrundstücks', 'Cadastral district of the neighbouring plot', TRUE, [
              'validation' => ['maxLength' => 160],
            ]),
            beantragt_starter_field('neighbour_parcel_number', 'text', 'Flur- oder Flurstücksnummer des Nachbargrundstücks', 'Parcel number of the neighbouring plot', TRUE, [
              'validation' => ['maxLength' => 120],
            ]),
          ],
        ),
        beantragt_starter_section(
          'setback_areas',
          'Erforderliche Abstandsflächen',
          'Required setback areas',
          'Beschreibung der Abstandsflächen und Darstellung gemäß Anlage im Maßstab 1:200.',
          'Description of the setback areas and plan attached at scale 1:200.',
          [
            beantragt_starter_field('required_setback_areas', 'textarea', 'Erforderliche Abstandsflächen der baulichen Anlage', 'Required setback areas of the proposed structure', FALSE, [
              'validation' => ['maxLength' => 1600],
            ]),
            beantragt_starter_field('setback_areas_on_building_plot', 'textarea', 'Auf dem Baugrundstück einhaltbare Abstandsflächen', 'Setback areas that can be provided on the building plot', FALSE, [
              'validation' => ['maxLength' => 1600],
            ]),
            beantragt_starter_field('setback_areas_transferred', 'textarea', 'Auf das Nachbargrundstück zu übernehmende Abstandsflächen', 'Setback areas to be transferred to the neighbouring plot', FALSE, [
              'validation' => ['maxLength' => 1600],
            ]),
            beantragt_starter_field(
              'setback_area_plan',
              'file',
              'Darstellung der Abstandsflächen als Anlage',
              'Setback area plan as attachment',
              FALSE,
              [
                'validation' => ['maxFiles' => 1, 'maxSizeBytes' => 10485760],
                'analysis' => beantragt_starter_plan_analysis('setback_area', $external_attachment_analysis),
              ],
            ),
          ],
        ),
        beantragt_starter_section(
          'required_distances',
          'Erforderliche Abstände',
          'Required distances',
          'Beschreibung der Abstände und Darstellung gemäß Anlage in einem Maßstab nicht kleiner als 1:250.',
          'Description of required distances and attached plan at a scale of at least 1:250.',
          [
            beantragt_starter_field('article_28_applies', 'checkbox', 'Art. 28 Abs. 2 BayBO ist einschlägig.', 'Art. 28(2) BayBO applies.', FALSE),
            beantragt_starter_field('article_30_applies', 'checkbox', 'Art. 30 Abs. 2 BayBO ist einschlägig.', 'Art. 30(2) BayBO applies.', FALSE),
            beantragt_starter_field('required_distances', 'textarea', 'Erforderliche Abstände', 'Required distances', FALSE, [
              'validation' => ['maxLength' => 1600],
            ]),
            beantragt_starter_field('distances_on_building_plot', 'textarea', 'Auf dem Baugrundstück einhaltbare Abstände', 'Distances that can be provided on the building plot', FALSE, [
              'validation' => ['maxLength' => 1600],
            ]),
            beantragt_starter_field('distances_transferred', 'textarea', 'Auf das Nachbargrundstück zu übernehmende Abstände', 'Distances to be transferred to the neighbouring plot', FALSE, [
              'validation' => ['maxLength' => 1600],
            ]),
            beantragt_starter_field(
              'required_distance_plan',
              'file',
              'Darstellung der erforderlichen Abstände als Anlage',
              'Required distance plan as attachment',
              FALSE,
              [
                'validation' => ['maxFiles' => 1, 'maxSizeBytes' => 10485760],
                'analysis' => beantragt_starter_plan_analysis('required_distance', $external_attachment_analysis),
              ],
            ),
          ],
        ),
        beantragt_starter_section(
          'declaration',
          'Erklärung',
          'Declaration',
          'Die Erklärung ersetzt keine fachliche oder rechtliche Prüfung durch die zuständige Dienststelle.',
          'This declaration does not replace technical or legal review by the competent authority.',
          [
            beantragt_starter_field('authorised_owner', 'checkbox', 'Ich bin für das bezeichnete Nachbargrundstück verfügungsberechtigt.', 'I am authorised to dispose of the neighbouring plot described above.', TRUE),
            beantragt_starter_field('setback_transfer_accepted', 'checkbox', 'Ich dulde die beschriebene Erstreckung der Abstandsflächen auf das Nachbargrundstück.', 'I accept the described extension of setback areas onto the neighbouring plot.', FALSE),
            beantragt_starter_field('distance_transfer_accepted', 'checkbox', 'Ich dulde die beschriebene Erstreckung der Abstände auf das Nachbargrundstück.', 'I accept the described extension of required distances onto the neighbouring plot.', FALSE),
          ],
        ),
      ],
    ],
  ];

  if ($demo) {
    $standard = $catalog[0];
    $standard['key'] = 'dog-tax-registration-standard';
    $standard['title'] = 'Hund anmelden (Hundesteuer)';
    $standard['title_en'] = 'Dog registration (dog tax)';
    $standard['description'] = 'Melden Sie Ihren Hund zur Hundesteuer an. Eine Ermäßigung oder Befreiung können Sie bei Bedarf mitbeantragen.';
    $standard['description_en'] = 'Register your dog for dog tax. You can also request a reduction or exemption if needed.';
    $catalog[0]['description'] = 'FIT-Connect-Demo: Geführte Hundesteueranmeldung mit Ermäßigung oder Befreiung.';
    $catalog[0]['description_en'] = 'FIT-Connect demo: guided dog tax registration with a reduction or exemption.';

    foreach ($standard['sections'] as $index => $section) {
      if ($section['id'] === 'reduction') {
        $section['description'] = 'Wenn Sie eine Ermäßigung oder Befreiung beantragen, geben Sie den Grund und die Bezeichnung Ihres Nachweises an.';
        $section['i18n']['en']['description'] = 'If you request a reduction or exemption, state the reason and enter the name of your supporting document.';
        $requested = ['fieldId' => 'reduction_requested', 'value' => TRUE];
        foreach ($section['fields'] as $field_index => $field) {
          if (in_array($field['id'], ['reduction_reason', 'reduction_documents'], TRUE)) {
            $section['fields'][$field_index]['conditionalOn'] = $requested;
          }
          if ($field['id'] === 'reduction_documents') {
            // Keep the guided tour's existing field locator working.
            $catalog[0]['sections'][$index]['fields'][$field_index]['label'] = 'Nachweise, die Sie einreichen';
          }
        }
        array_unshift($section['fields'], beantragt_starter_field(
          'reduction_requested',
          'checkbox',
          'Ich beantrage eine Ermäßigung oder Befreiung.',
          'I request a reduction or exemption.',
          FALSE,
          ['privacy' => ['kind' => 'not_personal']],
        ));
        $standard['sections'][$index] = $section;
      }
      if ($section['id'] === 'consent') {
        // The standard registration stays with the local casework team.
        $standard['sections'][$index]['fields'] = array_values(array_filter(
          $section['fields'],
          static fn(array $field): bool => $field['id'] !== 'transfer_consent',
        ));
      }
    }
    array_unshift($catalog, $standard);
  }

  return $catalog;
}
