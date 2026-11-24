<?php

/**
 * @file
 * Defines and installs the Musterstadt residents parking form.
 */

declare(strict_types=1);

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\group\Entity\GroupInterface;

require_once __DIR__ . '/DemoFormWorkflow.php';

/**
 * Creates or updates the jurisdiction-direct residents parking demo form.
 */
function beantragt_product_smoke_ensure_parking_form(
  $entity_type_manager,
  GroupInterface $jurisdiction,
): FormSchemaInterface {
  $storage = $entity_type_manager->getStorage('form_schema');
  $source_filename = 'seed:product-smoke:musterstadt:residents-parking-permit';
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', $source_filename)
    ->range(0, 2)
    ->execute();
  if (count($ids) > 1) {
    throw new RuntimeException('Product-smoke residents parking form is duplicated.');
  }

  $form = $ids ? $storage->load(reset($ids)) : $storage->create(['type' => 'form_schema']);
  if (!$form instanceof FormSchemaInterface) {
    throw new RuntimeException('Product-smoke residents parking form is invalid.');
  }

  $payload = beantragt_product_smoke_parking_form_payload();
  $stored_payload = NULL;
  if (!$form->isNew()) {
    $stored_payload = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach (['contextSources', 'precheckSchema'] as $key) {
      if (array_key_exists($key, $stored_payload)) {
        $payload[$key] = $stored_payload[$key];
      }
    }
  }
  $schema_json = json_encode(
    $payload,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  );
  $workflow = beantragt_product_smoke_parking_form_workflow($jurisdiction);
  $changed = $form->isNew()
    || !DemoFormWorkflow::same($form->get('field_workflow_definition')->value, $workflow)
    || (string) $form->label() !== 'Bewohnerparkausweis beantragen'
    || (string) ($form->get('field_description')->value ?? '') !== 'Beantragen Sie einen Bewohnerparkausweis für das Gebiet, in dem Sie wohnen.'
    || $stored_payload != $payload
    || (string) ($form->get('field_source_filename')->value ?? '') !== $source_filename
    || $form->getSchemaStatus() !== 'published'
    || (string) ($form->get('field_publication_scope')->value ?? '') !== 'internet'
    || (int) ($form->get('field_jurisdiction')->target_id ?? 0) !== (int) $jurisdiction->id()
    || !$form->get('field_organisation')->isEmpty()
    || (int) ($form->get('field_group')->target_id ?? 0) !== (int) $jurisdiction->id()
    || !$form->isPublished();
  if (!$changed) {
    return $form;
  }

  if (!$form->isNew() && $form->getSchemaStatus() !== 'draft') {
    $form->set('field_workflow_status', 'draft');
    $form->set('status', FALSE);
    $form->save();
    $form_id = (int) $form->id();
    $storage->resetCache([$form_id]);
    $form = $storage->load($form_id);
    if (!$form instanceof FormSchemaInterface) {
      throw new RuntimeException('Product-smoke residents parking form could not be reloaded as a draft.');
    }
  }

  $form->set('label', 'Bewohnerparkausweis beantragen');
  $form->set('field_description', 'Beantragen Sie einen Bewohnerparkausweis für das Gebiet, in dem Sie wohnen.');
  $form->set('field_schema', $schema_json);
  $form->set('field_source_filename', $source_filename);
  $form->set('field_workflow_definition', DemoFormWorkflow::encode($workflow));
  $form->set('field_workflow_status', 'draft');
  $form->set('field_publication_scope', 'internet');
  $form->setOwnerId(1);
  $form->set('field_jurisdiction', ['target_id' => $jurisdiction->id()]);
  $form->set('field_organisation', []);
  $form->set('field_group', ['target_id' => $jurisdiction->id()]);
  $form->set('status', FALSE);
  $form->save();

  $form->set('field_workflow_status', 'published');
  $form->set('status', TRUE);
  $form->save();
  return $form;
}

/**
 * Returns the own workflow of the residents parking form.
 *
 * The docgen demo releases an approval or a rejection and can ask for
 * documents, so the form switches "Genehmigt", "Abgelehnt" and the request
 * on. The form has no organisation and its workflow cannot be edited in the
 * UI; its applications land in the citizen services office, so the
 * jurisdiction's catalogue and that office's catalogue must name the
 * statuses alike, or the definition would be skipped there.
 *
 * @return array<string, mixed>
 *   The workflow definition.
 */
function beantragt_product_smoke_parking_form_workflow(GroupInterface $jurisdiction): array {
  $manager = \Drupal::entityTypeManager();
  $flags = ['request_info', 'approved', 'rejected'];
  $workflow = DemoFormWorkflow::definition($manager, (int) $jurisdiction->id(), $flags);
  $offices = $manager->getStorage('group')->loadByProperties([
    'type' => 'org',
    'field_slug' => 'citizen-services',
    'field_jurisdiction' => $jurisdiction->id(),
  ]);
  foreach ($offices as $office) {
    if (DemoFormWorkflow::definition($manager, (int) $office->id(), $flags) !== $workflow) {
      throw new RuntimeException('The residents parking workflow differs between the jurisdiction and the citizen services catalogue.');
    }
  }
  return $workflow;
}

/**
 * Returns the bilingual schema for the German residents parking demo.
 *
 * @return array<string, mixed>
 *   Form schema payload without document-derived context metadata.
 */
function beantragt_product_smoke_parking_form_payload(): array {
  return [
    'id' => 'musterstadt_residents_parking_permit_de',
    'title' => 'Bewohnerparkausweis beantragen',
    'description' => 'Beantragen Sie einen Bewohnerparkausweis für das Gebiet, in dem Sie wohnen.',
    'i18n' => [
      'en' => [
        'title' => 'Apply for a Residents\' Parking Permit',
        'description' => 'Use this service to apply for a parking permit for the area where you live.',
      ],
    ],
    'sections' => [
      [
        'id' => 'person',
        'title' => 'Angaben zur Person',
        'description' => 'Ihre Kontaktdaten für diesen Antrag.',
        'i18n' => [
          'en' => [
            'title' => 'Applicant Details',
            'description' => 'Your contact details for this application.',
          ],
        ],
        'fields' => [
          [
            'id' => 'full_name',
            'type' => 'text',
            'label' => 'Vollständiger Name',
            'i18n' => ['en' => ['label' => 'Full name', 'placeholder' => 'Jane Smith']],
            'required' => TRUE,
            'placeholder' => 'Max Mustermann',
            'crm_key' => 'person.full_name',
            'privacy' => ['kind' => 'contact'],
            'validation' => ['minLength' => 3, 'maxLength' => 180],
          ],
          [
            'id' => 'email',
            'type' => 'email',
            'label' => 'E-Mail-Adresse',
            'i18n' => ['en' => ['label' => 'Email address', 'placeholder' => 'jane@example.org']],
            'required' => TRUE,
            'placeholder' => 'max.mustermann@example.org',
            'crm_key' => 'person.email',
            'privacy' => ['kind' => 'contact'],
          ],
        ],
      ],
      [
        'id' => 'wohnort_und_parkzone',
        'title' => 'Wohnort und Parkzone',
        'description' => 'Geben Sie Wohnort und Bewohnerparkgebiet für den Antrag an.',
        'i18n' => [
          'en' => [
            'title' => 'Address and Parking Zone',
            'description' => 'Provide the address and residents parking zone for this application.',
          ],
        ],
        'fields' => [
          [
            'id' => 'wohnanschrift',
            'type' => 'textarea',
            'label' => 'Wohnanschrift',
            'i18n' => ['en' => ['label' => 'Residential address']],
            'required' => TRUE,
            'crm_key' => 'person.address',
            'privacy' => ['kind' => 'contact'],
            'validation' => ['maxLength' => 300],
          ],
          [
            'id' => 'postleitzahl',
            'type' => 'text',
            'label' => 'Postleitzahl',
            'i18n' => ['en' => ['label' => 'Postcode']],
            'required' => TRUE,
            'format' => 'postal_code',
            'crm_key' => 'person.postal_code',
            'privacy' => ['kind' => 'contact'],
            'validation' => ['maxLength' => 12],
          ],
          [
            'id' => 'parkzone',
            'type' => 'select',
            'label' => 'Bewohnerparkgebiet',
            'i18n' => [
              'en' => [
                'label' => 'Parking zone',
                'options' => [
                  'Innenstadt A' => 'Central Zone A',
                  'Flussufer B' => 'Riverside Zone B',
                  'Bahnhof C' => 'Station Zone C',
                  'Altstadt D' => 'Old Town Zone D',
                ],
              ],
            ],
            'required' => TRUE,
            'privacy' => ['kind' => 'not_personal'],
            'options' => ['Innenstadt A', 'Flussufer B', 'Bahnhof C', 'Altstadt D'],
          ],
          [
            'id' => 'hauptwohnsitz_in_zone',
            'type' => 'checkbox',
            'label' => 'Mein Hauptwohnsitz liegt im ausgewählten Bewohnerparkgebiet.',
            'i18n' => ['en' => ['label' => 'My principal residence is in the selected parking zone.']],
            'required' => TRUE,
            'privacy' => ['kind' => 'not_personal'],
          ],
          [
            'id' => 'privater_stellplatz',
            'type' => 'checkbox',
            'label' => 'Mir steht ein privater Stellplatz zur Verfügung.',
            'i18n' => [
              'en' => [
                'label' => 'A private off-street parking space is available to me.',
              ],
            ],
            'required' => FALSE,
            'privacy' => ['kind' => 'not_personal'],
          ],
          [
            'id' => 'gewuenschter_beginn',
            'type' => 'date',
            'label' => 'Gewünschter Beginn',
            'i18n' => ['en' => ['label' => 'Requested start date']],
            'required' => TRUE,
            'privacy' => ['kind' => 'not_personal'],
          ],
          [
            'id' => 'gueltigkeitsdauer',
            'type' => 'select',
            'label' => 'Gültigkeitsdauer',
            'i18n' => [
              'en' => [
                'label' => 'Permit duration',
                'options' => ['12 Monate' => '12 months', '6 Monate' => '6 months', '3 Monate' => '3 months'],
              ],
            ],
            'required' => TRUE,
            'privacy' => ['kind' => 'not_personal'],
            'defaultValue' => '12 Monate',
            'options' => ['12 Monate', '6 Monate', '3 Monate'],
          ],
        ],
      ],
      [
        'id' => 'fahrzeug',
        'title' => 'Angaben zum Fahrzeug',
        'description' => 'Geben Sie das Fahrzeug an, das mit dem Bewohnerparkausweis verknüpft werden soll.',
        'i18n' => [
          'en' => [
            'title' => 'Vehicle Details',
            'description' => 'Provide the vehicle that should be linked to the permit.',
          ],
        ],
        'fields' => [
          [
            'id' => 'kennzeichen',
            'type' => 'text',
            'label' => 'Amtliches Kennzeichen',
            'i18n' => ['en' => ['label' => 'Vehicle registration number']],
            'required' => TRUE,
            'privacy' => ['kind' => 'other_personal'],
            'validation' => ['maxLength' => 20],
          ],
          [
            'id' => 'fahrzeug_modell',
            'type' => 'text',
            'label' => 'Hersteller und Modell',
            'i18n' => ['en' => ['label' => 'Vehicle make and model']],
            'required' => FALSE,
            'privacy' => ['kind' => 'other_personal'],
            'validation' => ['maxLength' => 120],
          ],
          [
            'id' => 'fahrzeugbezug',
            'type' => 'select',
            'label' => 'Ihr Verhältnis zum Fahrzeug',
            'i18n' => [
              'en' => [
                'label' => 'Your relationship to the vehicle',
                'options' => [
                  'Auf mich zugelassen' => 'Registered keeper',
                  'Dauerhaft überlassen' => 'Named driver',
                  'Firmenfahrzeug' => 'Company vehicle',
                  'Leasing- oder Mietfahrzeug' => 'Lease or hire vehicle',
                ],
              ],
            ],
            'required' => TRUE,
            'privacy' => ['kind' => 'not_personal'],
            'options' => ['Auf mich zugelassen', 'Dauerhaft überlassen', 'Firmenfahrzeug', 'Leasing- oder Mietfahrzeug'],
          ],
        ],
      ],
      // Optional direct debit: the published demo form with a bank field, so
      // the second consent checkbox (bank details for future applications)
      // can be shown. Neither field is marked sensitive; a sensitive field
      // would make the form ineligible for both profile consents.
      [
        'id' => 'zahlung',
        'title' => 'Gebühr per Lastschrift',
        'description' => 'Optional: Die Gebühr für den Bewohnerparkausweis wird per SEPA-Lastschrift eingezogen. Ohne Angaben erhalten Sie einen Gebührenbescheid zur Überweisung.',
        'i18n' => [
          'en' => [
            'title' => 'Fee by Direct Debit',
            'description' => 'Optional: the permit fee is collected by SEPA direct debit. Without bank details you receive a fee notice for a bank transfer.',
          ],
        ],
        'fields' => [
          [
            'id' => 'kontoinhaber',
            'type' => 'text',
            'label' => 'Kontoinhaberin oder Kontoinhaber',
            'i18n' => ['en' => ['label' => 'Account holder']],
            'required' => FALSE,
            'crm_key' => 'person.account_holder',
            'privacy' => ['kind' => 'financial'],
            'validation' => ['maxLength' => 120],
          ],
          [
            'id' => 'iban',
            'type' => 'text',
            'label' => 'IBAN',
            'i18n' => ['en' => ['label' => 'IBAN']],
            'required' => FALSE,
            'format' => 'iban',
            'crm_key' => 'person.iban',
            'privacy' => ['kind' => 'financial'],
          ],
        ],
      ],
      [
        'id' => 'bestaetigungen',
        'title' => 'Bestätigungen',
        'description' => 'Prüfen und bestätigen Sie Ihre Angaben.',
        'i18n' => [
          'en' => [
            'title' => 'Confirmations',
            'description' => 'Review and confirm the information provided.',
          ],
        ],
        'fields' => [
          [
            'id' => 'angaben_bestaetigt',
            'type' => 'checkbox',
            'label' => 'Ich bestätige, dass ich an der angegebenen Anschrift wohne und meine Angaben richtig sind.',
            'i18n' => ['en' => ['label' => 'I confirm that I live at the address shown above and that the information provided is correct.']],
            'required' => TRUE,
            'privacy' => ['kind' => 'not_personal'],
          ],
        ],
      ],
    ],
    'auth' => [
      'mode' => 'optional',
      'providers' => ['passwordless'],
      'collect' => ['fullName' => 'required', 'email' => 'required'],
    ],
    'version' => 2,
    'semantics' => [
      'category' => 'transport-parking',
      'entities' => ['person', 'application', 'address', 'vehicle', 'jurisdiction'],
      'process' => 'residents-parking-permit',
    ],
    'source' => [
      'type' => 'synthetic-demo',
      'label' => 'Stadt Musterstadt: Bewohnerparkausweis',
      'file' => 'scripts/seed-product-smoke.php',
      'note' => 'Selbst erstelltes Formular für die synthetische Musterstadt-Demo.',
      'demo_only' => TRUE,
      'official_service' => FALSE,
    ],
  ];
}
