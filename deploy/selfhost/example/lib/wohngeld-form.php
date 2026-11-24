<?php

/**
 * @file
 * Defines the fictional housing benefit form.
 */

declare(strict_types=1);

// The public catalogue validator uses the exact seed payload without Drupal.
if (!function_exists('beantragt_demo_wohngeld_payload')) {

  /**
   * Builds the fictional Wohngeld form without reading runtime state.
   *
   * @return array<string, mixed>
   *   The schema used by the seed and the catalogue value gate.
   */
  function beantragt_demo_wohngeld_payload(): array {
    $text = static fn(string $id, string $label): array => [
      'id' => $id,
      'type' => 'text',
      'label' => $label,
      'required' => TRUE,
      'sensitive' => TRUE,
    ];
    $file = static function (string $id, string $label, string $type, bool $required = TRUE, array $facts = ['legible']): array {
      // The criterion text staff and the model read (base rules v3). English
      // texts wait for the English criterion field (Vorprüfung step 6).
      $titles = [
        'legible' => 'Das Dokument ist gut lesbar.',
        'is_income_document' => 'Das Dokument ist ein Einkommensnachweis.',
        'pay_period_visible' => 'Der Abrechnungszeitraum ist erkennbar.',
        'pay_period_current' => 'Der Abrechnungszeitraum endet innerhalb der drei Monate vor dem Stichtag.',
      ];
      $properties = [];
      foreach ($facts as $fact) {
        $properties[$fact] = [
          'type' => 'boolean',
          'title' => $titles[$fact],
          'nonPersonal' => TRUE,
        ];
      }
      return [
        'id' => $id,
        'type' => 'file',
        'label' => $label,
        'required' => $required,
        'analysis' => [
          'enabled' => TRUE,
          'version' => 1,
          'expectedDocumentType' => $type,
          'egressPolicy' => 'sovereign_only',
          'modelClass' => 'openai_compatible',
          'redaction' => 'none',
          'promptSuggestion' => 'Prüfen Sie nur Dokumentart und Lesbarkeit. Treffen Sie keine Entscheidung über den Antrag.',
          'resultSchema' => [
            'type' => 'object',
            'required' => $facts,
            'properties' => $properties,
          ],
        ],
      ];
    };
    $payload = [
      'version' => 1,
      'title' => 'Wohngeld (Mietzuschuss)',
      'description' => 'Fiktives Musterstadt-Formular für die Stapelverarbeitung.',
      'sections' => [
      [
        'id' => 'antrag',
        'title' => 'Antrag',
        'fields' => [
          $text('antragsteller_vorname', 'Vorname') + ['crm_key' => 'person.first_name'],
          $text('antragsteller_nachname', 'Nachname') + ['crm_key' => 'person.last_name'],
          $text('anschrift_strasse', 'Straße') + ['crm_key' => 'person.street'],
          $text('anschrift_plz', 'Postleitzahl') + ['crm_key' => 'person.postal_code'],
          $text('anschrift_ort', 'Ort') + ['crm_key' => 'person.city'],
          $file('mietvertrag', 'Mietvertrag', 'rental_contract'),
          $file('mietbescheinigung', 'Mietbescheinigung', 'rent_certificate'),
          $file('ausweis', 'Ausweis', 'id_document', FALSE),
        ],
      ],
      [
        'id' => 'haushalt',
        'title' => 'Haushalt',
        'repeatable' => TRUE,
        'fields' => [
          $text('vorname', 'Vorname'),
          $text('nachname', 'Nachname'),
        [
          'id' => 'erwerbstaetig',
          'type' => 'checkbox',
          'label' => 'Erwerbstätig',
        ],
          [
            'id' => 'rente',
            'type' => 'checkbox',
            'label' => 'Rentenbezug',
          ],
          $file('einkommensnachweis', 'Einkommensnachweis', 'payslip', TRUE, [
            'legible',
            'is_income_document',
            'pay_period_visible',
            'pay_period_current',
          ]) + [
            'multiple' => TRUE,
            'validation' => ['maxFiles' => 10],
            'conditionalOn' => [
              'fieldId' => 'erwerbstaetig',
              'value' => TRUE,
            ],
          ],
          $file('rentenbescheid', 'Rentenbescheid', 'pension_notice') + [
            'conditionalOn' => [
              'fieldId' => 'rente',
              'value' => TRUE,
            ],
          ],
        ],
      ],
      ],
    ];
    return $payload;
  }

}

/**
 * Uses the housing schema as a standalone form with local attachment handling.
 *
 * @return array<string, mixed>
 *   Definition for the starter writer.
 */
function beantragt_example_housing_definition(): array {
  $housing = beantragt_demo_wohngeld_payload();
  foreach ($housing['sections'] as &$section) {
    foreach ($section['fields'] as &$field) {
      unset($field['analysis']);
      $field['privacy']['kind'] = match ($field['id']) {
        'antragsteller_vorname', 'antragsteller_nachname', 'vorname', 'nachname', 'ausweis' => 'identity',
        'anschrift_strasse', 'anschrift_plz', 'anschrift_ort' => 'contact',
        default => 'other_personal',
      };
    }
    unset($field);
  }
  unset($section);
  return [
    'key' => 'wohngeld',
    'organisation' => 'citizen-services',
    'title' => $housing['title'],
    'title_en' => 'Housing benefit (rent allowance)',
    'description' => 'Beantragen Sie einen Zuschuss zur Miete für Ihren Haushalt.',
    'description_en' => 'Apply for help paying your household rent.',
    'category' => 'soziales',
    'sections' => $housing['sections'],
  ];
}
