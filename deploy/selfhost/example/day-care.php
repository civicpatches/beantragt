<?php

/**
 * @file
 * Demo: the fictional Musterstadt form for a Kita admission.
 *
 * Form title "Aufnahme in eine Kindertageseinrichtung". A self-authored form
 * of the fictional Stadt Musterstadt, so that the public demo keeps a Kita
 * admission form after every reinstall. It adds no product
 * capability: no attachment analysis (plain uploads without an analysis
 * profile, as the optional uploads of seed-demo-wohnberechtigungsschein.php),
 * no own workflow, no free-text field about the child's health.
 *
 * The form carries one leaflet (a public document in the form sidebar,
 * German page and English page) as the assistant's context
 * source, and two approved precheck criteria quoted from it: the kind of
 * childcare and whether the child lives in Musterstadt. The age band of the
 * legal entitlement stays leaflet text, not a question (personal data).
 * An existing form keeps its operator-configured context sources and
 * precheck criteria, except the retired seed age criterion. Placement and
 * version metadata of bindings to the seed-owned leaflet are refreshed. A new form
 * receives the two seed defaults. Publishing a form with a context source makes
 * the optional context module index it against the site's own RAG service, as for the
 * showcase forms; the seed never calls the indexer itself.
 *
 * Runtime limits this seed works around instead of changing the product:
 * - The citizen renderer ignores `repeatable` sections, so the second person
 *   with custody is a fixed optional block behind a checkbox.
 * - `conditionalOn` compares one field of the same section (the public schema
 *   projection drops cross-section references), so every condition points to
 *   a checkbox in its own section.
 *
 * The form is scoped to the Bürgerbüro of Stadt Musterstadt.
 * scripts/install-public-demo.sh runs this seed after the focused product-smoke
 * catalogue; the exclusive catalogue check in seed-product-smoke.php exempts
 * its source key.
 *
 * The script is idempotent: the form is matched by field_source_filename, the
 * leaflet by a fixed UUID. An unchanged form and leaflet are left alone; a
 * changed payload, label or scope is applied through a draft revision and
 * published again, because FormSchema accepts schema changes only on a
 * persisted draft. A new form is stored as a plain draft first, because the
 * leaflet refers to it.
 *
 * Usage:
 *   ddev drush php:script scripts/seed-demo-kita-aufnahme.php
 *
 * Environment variables:
 * - BEANTRAGT_ALLOW_DEMO_KITA_SEED=1 allows a deliberate run outside DDEV; the
 *   form then stays a draft on the intranet.
 * - BEANTRAGT_DEMO_KITA_PUBLISH=1, together with the allow flag, publishes the
 *   form outside DDEV as well, for a public demo tenant such as
 *   an installation explicitly configured to receive example data.
 */

declare(strict_types=1);

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

// The leaflet PDF uses the showcase catalogue's plain PDF writer.
require_once __DIR__ . '/lib/showcase-form-catalog.php';

// Kernel tests include this script more than once in one process. The payload
// builder is therefore declared once, and before the validation guard below,
// because conditionally declared functions are not hoisted.
if (!function_exists('beantragt_demo_kita_schema_payload')) {

  /**
   * Builds the FormSchema payload of the Kita admission demo form.
   *
   * Only the first person with custody maps to the applicant profile
   * (crm_key person.*); the child and the second person carry no crm_key, so
   * their data never lands in the applicant's citizen profile.
   *
   * With the leaflet's stored context source, the payload also carries that
   * source and the approved precheck criteria quoted from it.
   *
   * @param array{id: string, title: string, version: string, changed: int}|null $leaflet
   *   Context source of the stored leaflet, or NULL for the plain form.
   *
   * @return array<string, mixed>
   *   FormSchema payload.
   */
  function beantragt_demo_kita_schema_payload(?array $leaflet = NULL): array {
    $description = 'Beantragen Sie einen Kita-Platz für Ihr Kind: in der Krippe, im Kindergarten oder im Hort. Halten Sie die Geburtsurkunde bereit.';
    $facilities = [
      'Kita Am Musterpark',
      'Kita Sternschnuppe',
      'Kita Kleine Entdecker',
      'Familienzentrum Musterstadt-Süd',
    ];

    $payload = [
      'id' => 'demo_kita_aufnahme',
      'title' => 'Aufnahme in eine Kindertageseinrichtung',
      'description' => $description,
      'sections' => [
        [
          'id' => 'care',
          'title' => 'Betreuung',
          'description' => 'Welche Betreuung wünschen Sie, ab wann und in welchem Umfang?',
          'fields' => [
            [
              'id' => 'care_type',
              'type' => 'radio',
              'label' => 'Betreuungsart',
              'required' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
              'options' => [
                'Krippe (Kinder unter drei Jahren)',
                'Kindergarten (Kinder ab drei Jahren bis zum Schuleintritt)',
                'Hort (Schulkinder)',
              ],
            ],
            [
              'id' => 'care_start',
              'type' => 'date',
              'label' => 'Gewünschter Betreuungsbeginn',
              'required' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
            ],
            [
              'id' => 'care_hours',
              'type' => 'radio',
              'label' => 'Betreuungsumfang je Woche',
              'required' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
              'options' => [
                'Halbtags, etwa 20 Stunden',
                'Dreivierteltags, etwa 30 Stunden',
                'Ganztags, etwa 40 Stunden',
              ],
            ],
          ],
        ],
        [
          'id' => 'child',
          'title' => 'Kind',
          'description' => 'Angaben zu dem Kind, das betreut werden soll.',
          'fields' => [
            [
              'id' => 'child_first_name',
              'type' => 'text',
              'label' => 'Vorname des Kindes',
              'required' => TRUE,
              'privacy' => ['kind' => 'identity'],
              'validation' => ['maxLength' => 120],
            ],
            [
              'id' => 'child_last_name',
              'type' => 'text',
              'label' => 'Familienname des Kindes',
              'required' => TRUE,
              'privacy' => ['kind' => 'identity'],
              'validation' => ['maxLength' => 120],
            ],
            [
              'id' => 'child_birthdate',
              'type' => 'date',
              'label' => 'Geburtsdatum des Kindes',
              'required' => TRUE,
              'privacy' => ['kind' => 'identity'],
            ],
            [
              'id' => 'child_street_address',
              'type' => 'text',
              'label' => 'Straße und Hausnummer',
              'required' => TRUE,
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 220],
            ],
            [
              'id' => 'child_postal_code',
              'type' => 'text',
              'label' => 'Postleitzahl',
              'required' => TRUE,
              'format' => 'postal_code',
              'privacy' => ['kind' => 'contact'],
            ],
            [
              'id' => 'child_city',
              'type' => 'text',
              'label' => 'Ort',
              'required' => TRUE,
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 160],
            ],
          ],
        ],
        [
          'id' => 'facility',
          'title' => 'Wunsch-Einrichtung',
          'description' => 'Ein Wunsch ist noch keine Zusage. Das Bürgerbüro vergibt die Plätze nach Verfügbarkeit und meldet sich bei Ihnen.',
          'fields' => [
            [
              'id' => 'facility_first_choice',
              'type' => 'select',
              'label' => 'Erstwunsch',
              'required' => TRUE,
              'privacy' => ['kind' => 'not_personal'],
              'options' => $facilities,
            ],
            [
              'id' => 'facility_second_choice',
              'type' => 'select',
              'label' => 'Zweitwunsch',
              'required' => FALSE,
              'privacy' => ['kind' => 'not_personal'],
              'options' => $facilities,
            ],
          ],
        ],
        [
          'id' => 'guardians',
          'title' => 'Sorgeberechtigte',
          'description' => 'Ihre Angaben als sorgeberechtigte Person. Gibt es eine zweite sorgeberechtigte Person, setzen Sie bitte das Häkchen und ergänzen deren Angaben.',
          'fields' => [
            [
              'id' => 'guardian_1_full_name',
              'type' => 'text',
              'label' => 'Vor- und Familienname',
              'required' => TRUE,
              'placeholder' => 'Erika Mustermann',
              'crm_key' => 'person.full_name',
              'privacy' => ['kind' => 'identity'],
              'validation' => ['minLength' => 3, 'maxLength' => 180],
            ],
            [
              'id' => 'guardian_1_street_address',
              'type' => 'text',
              'label' => 'Straße und Hausnummer',
              'required' => TRUE,
              'crm_key' => 'person.street',
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 220],
            ],
            [
              'id' => 'guardian_1_postal_code',
              'type' => 'text',
              'label' => 'Postleitzahl',
              'required' => TRUE,
              'format' => 'postal_code',
              'crm_key' => 'person.postal_code',
              'privacy' => ['kind' => 'contact'],
            ],
            [
              'id' => 'guardian_1_city',
              'type' => 'text',
              'label' => 'Ort',
              'required' => TRUE,
              'crm_key' => 'person.city',
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 160],
            ],
            [
              'id' => 'guardian_1_phone',
              'type' => 'tel',
              'label' => 'Telefon',
              'required' => TRUE,
              'crm_key' => 'person.phone',
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 23],
            ],
            [
              'id' => 'guardian_1_email',
              'type' => 'email',
              'label' => 'E-Mail-Adresse',
              'required' => TRUE,
              'placeholder' => 'erika.mustermann@post.example',
              'crm_key' => 'person.email',
              'privacy' => ['kind' => 'contact'],
              'validation' => ['maxLength' => 254],
            ],
            [
              'id' => 'guardian_2_present',
              'type' => 'checkbox',
              'label' => 'Es gibt eine zweite sorgeberechtigte Person.',
              'required' => FALSE,
              'privacy' => ['kind' => 'other_personal'],
            ],
            [
              'id' => 'guardian_2_full_name',
              'type' => 'text',
              'label' => 'Vor- und Familienname der zweiten sorgeberechtigten Person',
              'required' => TRUE,
              'privacy' => ['kind' => 'identity'],
              'conditionalOn' => ['fieldId' => 'guardian_2_present', 'value' => TRUE],
              'validation' => ['minLength' => 3, 'maxLength' => 180],
            ],
            [
              'id' => 'guardian_2_street_address',
              'type' => 'text',
              'label' => 'Straße und Hausnummer der zweiten sorgeberechtigten Person',
              'required' => TRUE,
              'privacy' => ['kind' => 'contact'],
              'conditionalOn' => ['fieldId' => 'guardian_2_present', 'value' => TRUE],
              'validation' => ['maxLength' => 220],
            ],
            [
              'id' => 'guardian_2_postal_code',
              'type' => 'text',
              'label' => 'Postleitzahl der zweiten sorgeberechtigten Person',
              'required' => TRUE,
              'format' => 'postal_code',
              'privacy' => ['kind' => 'contact'],
              'conditionalOn' => ['fieldId' => 'guardian_2_present', 'value' => TRUE],
            ],
            [
              'id' => 'guardian_2_city',
              'type' => 'text',
              'label' => 'Ort der zweiten sorgeberechtigten Person',
              'required' => TRUE,
              'privacy' => ['kind' => 'contact'],
              'conditionalOn' => ['fieldId' => 'guardian_2_present', 'value' => TRUE],
              'validation' => ['maxLength' => 160],
            ],
            [
              'id' => 'guardian_2_phone',
              'type' => 'tel',
              'label' => 'Telefon der zweiten sorgeberechtigten Person',
              'required' => FALSE,
              'privacy' => ['kind' => 'contact'],
              'conditionalOn' => ['fieldId' => 'guardian_2_present', 'value' => TRUE],
              'validation' => ['maxLength' => 23],
            ],
            [
              'id' => 'guardian_2_email',
              'type' => 'email',
              'label' => 'E-Mail-Adresse der zweiten sorgeberechtigten Person',
              'required' => FALSE,
              'privacy' => ['kind' => 'contact'],
              'conditionalOn' => ['fieldId' => 'guardian_2_present', 'value' => TRUE],
              'validation' => ['maxLength' => 254],
            ],
          ],
        ],
        [
          'id' => 'attachments',
          'title' => 'Anlagen',
          'description' => 'Laden Sie die Geburtsurkunde Ihres Kindes hoch. Sind Sie allein sorgeberechtigt, laden Sie auch den Nachweis dazu hoch.',
          'fields' => [
            [
              'id' => 'birth_certificate',
              'type' => 'file',
              'label' => 'Geburtsurkunde des Kindes',
              'required' => TRUE,
              'sensitive' => TRUE,
              'privacy' => ['kind' => 'identity'],
              'validation' => ['maxFiles' => 2, 'maxSizeBytes' => 10485760],
            ],
            [
              'id' => 'sole_custody',
              'type' => 'checkbox',
              'label' => 'Ich bin allein sorgeberechtigt.',
              'required' => FALSE,
              'privacy' => ['kind' => 'other_personal'],
            ],
            [
              'id' => 'custody_proof',
              'type' => 'file',
              'label' => 'Nachweis über das alleinige Sorgerecht',
              'required' => TRUE,
              'sensitive' => TRUE,
              'privacy' => ['kind' => 'other_personal'],
              'conditionalOn' => ['fieldId' => 'sole_custody', 'value' => TRUE],
              'validation' => ['maxFiles' => 2, 'maxSizeBytes' => 10485760],
            ],
          ],
        ],
        [
          'id' => 'declaration',
          'title' => 'Erklärung',
          'fields' => [
            [
              'id' => 'declaration_complete',
              'type' => 'checkbox',
              'label' => 'Ich versichere, dass meine Angaben vollständig und richtig sind.',
              'required' => TRUE,
            ],
          ],
        ],
      ],
      'auth' => [
        'mode' => 'optional',
        'providers' => ['passwordless'],
        'collect' => [
          'fullName' => 'required',
          'email' => 'required',
        ],
      ],
      'version' => 1,
      'semantics' => [
        'category' => 'familie',
        'entities' => ['person', 'application', 'workspace'],
        'process' => 'kita_aufnahme',
      ],
      'source' => [
        'type' => 'demo_neutral',
        'file' => 'scripts/seed-demo-kita-aufnahme.php',
        'key' => 'seed:demo:kita-aufnahme',
        'label' => 'Demo-Formular der Stadt Musterstadt',
        'note' => 'Fiktives Demo-Schema der Stadt Musterstadt. Kein offizieller Online-Dienst einer realen Kommune.',
        'retrieved_at' => '2026-11-13',
        'demo_only' => TRUE,
        'official_service' => FALSE,
        'review_required' => TRUE,
      ],
    ];
    if ($leaflet !== NULL) {
      $payload['contextSources'] = [
        [
          'type' => 'form_attachment',
          'id' => $leaflet['id'],
          'title' => $leaflet['title'],
          'placement' => 'form_sidebar',
          'version' => $leaflet['version'],
          'changed' => $leaflet['changed'],
          'enabledFor' => ['editor', 'citizen_assistant'],
          'requiredCitation' => TRUE,
        ],
      ];
      $payload['precheckSchema'] = beantragt_demo_kita_precheck($leaflet);
    }
    return $payload;
  }

  /**
   * The leaflet of the Kita admission form: its card and its PDF text.
   *
   * Fictional rules of the fictional Stadt Musterstadt. Each paragraph is one
   * line of the leaflet; the precheck criteria quote two of them verbatim.
   *
   * @return array{uuid: string, filename: string, title_de: string, title_en: string, body_de: string, body_en: string, paragraphs_de: list<string>, paragraphs_en: list<string>}
   *   Leaflet definition.
   */
  function beantragt_demo_kita_leaflet(): array {
    return [
      // Fixed, so a rerun finds the same node and never adopts a hand-made one.
      'uuid' => '9434e8db-ca39-5ab0-8efb-cc64010b433e',
      'filename' => 'merkblatt-kita-aufnahme-musterstadt.pdf',
      'title_de' => 'Merkblatt Aufnahme in eine Kindertageseinrichtung',
      'title_en' => 'Leaflet on admission to a childcare centre',
      'body_de' => '<p>Wer den Antrag stellt, welche Unterlagen Sie brauchen und wohin Sie sich bei freien Trägern, auswärtigen Einrichtungen und Kindertagespflege wenden.</p>',
      'body_en' => '<p>Who applies, which documents you need and where to turn for independent providers, facilities outside the town and childminders.</p>',
      'paragraphs_de' => [
        'Merkblatt Aufnahme in eine Kindertageseinrichtung Musterstadt',
        'Mit diesem Antrag melden Sie Ihr Kind für einen Platz in einer städtischen Kindertageseinrichtung der Stadt Musterstadt an.',
        'Wer den Antrag stellt: die Sorgeberechtigten des Kindes. Das Kind hat seinen Wohnsitz in Musterstadt.',
        'Rechtsanspruch: Kinder haben ab dem vollendeten ersten Lebensjahr bis zum Schuleintritt Anspruch auf Förderung in einer Tageseinrichtung oder in Kindertagespflege (§ 24 SGB VIII).',
        'Diese Unterlagen brauchen Sie: den ausgefüllten Aufnahmeantrag, eine Kopie der Geburtsurkunde des Kindes und einen Nachweis über das Sorgerecht.',
        'Städtische Kita: Den Antrag stellen Sie online oder bei der Kitaverwaltung im Bürgerbüro.',
        'Kita eines freien Trägers: Den Antrag stellen Sie direkt bei der jeweiligen Einrichtung, nicht bei der Stadt.',
        'Kita außerhalb von Musterstadt: Bietet Ihnen eine auswärtige Einrichtung einen Platz an, stellen Sie bei der Stadt Musterstadt einen Antrag zum Wunsch- und Wahlrecht. Dieser Antrag ist ein eigenes Verfahren.',
        'Kindertagespflege (Tagesmutter oder Tagesvater): Den Antrag stellen Sie beim Jugendamt des Kreises Musterhausen.',
        'So geht es weiter: Nach dem Eingang erhalten Sie eine Eingangsbestätigung mit Hinweisen zum weiteren Ablauf. Über die Aufnahme entscheidet die Sachbearbeitung des Bürgerbüros.',
        'Mittagsversorgung: Die Teilnahme am Mittagessen melden Sie gesondert an.',
        'Elternbeiträge: Die Höhe richtet sich nach der Kita-Satzung der Stadt Musterstadt.',
        'Fiktives Demo-Merkblatt.',
      ],
      'paragraphs_en' => [
        'Leaflet on admission to a childcare centre, Musterstadt',
        'With this application you register your child for a place in a municipal childcare centre of the town of Musterstadt.',
        'Who applies: the persons with custody of the child. The child lives in Musterstadt.',
        'Legal entitlement: from their first birthday until they start school, children are entitled to support in a childcare centre or in childminding (section 24 SGB VIII).',
        'Documents you need: the completed admission application, a copy of the child\'s birth certificate and proof of custody.',
        'Municipal childcare centre: apply online or at the childcare administration in the Bürgerbüro.',
        'Childcare centre of an independent provider: apply directly at that facility, not at the town.',
        'Childcare centre outside Musterstadt: if a facility outside the town offers you a place, apply to the town of Musterstadt under the right to choose a facility (Wunsch- und Wahlrecht). This application is a separate procedure.',
        'Childminding (Tagesmutter or Tagesvater): apply to the youth welfare office of the district of Musterhausen.',
        'What happens next: after receipt you get a confirmation with notes on the next steps. The Bürgerbüro\'s caseworkers decide on the admission.',
        'Lunch: register for lunch separately.',
        'Parental fees: the amount follows the childcare statute of the town of Musterstadt.',
        'Fictional demo leaflet.',
      ],
    ];
  }

  /**
   * Renders the leaflet as two pages, German and English.
   *
   * Every sentence starts a new line and is wrapped at 80 bytes (umlauts
   * count twice, so a line never runs past the page); an empty line follows
   * each paragraph. A short quoted sentence thus stays on one line.
   */
  function beantragt_demo_kita_leaflet_pdf(): string {
    $leaflet = beantragt_demo_kita_leaflet();
    $pages = [];
    foreach (['paragraphs_de', 'paragraphs_en'] as $key) {
      $lines = [];
      foreach ($leaflet[$key] as $paragraph) {
        foreach (preg_split('/(?<=\.)\s+(?=\p{Lu})/u', $paragraph) as $sentence) {
          array_push($lines, ...explode("\n", wordwrap($sentence, 80)));
        }
        $lines[] = '';
      }
      $pages[] = $lines;
    }
    return beantragt_showcase_pdf($pages);
  }

  /**
   * Approved precheck criteria of the Kita form, quoted from its leaflet.
   *
   * Neither criterion is bound to a form field: the form has no field that
   * may be asked in the dialogue (the care type is other_personal, the child's
   * data identity or contact). Both are classified as non-personal
   * yes/no or choice facts, like the residence question of the residents
   * parking permit. The child's age is never asked, not even as a coarse
   * band: that would be personal data about the child; the legal entitlement
   * stays leaflet text.
   *
   * @param array{id: string, title: string} $leaflet
   *   Context source of the leaflet.
   *
   * @return array<string, mixed>
   *   Precheck schema.
   */
  function beantragt_demo_kita_precheck(array $leaflet): array {
    $evidence = static fn (string $quote): array => [
      'sourceType' => 'context_source',
      'sourceId' => $leaflet['id'],
      'sourceTitle' => $leaflet['title'],
      'quote' => $quote,
      'page' => 1,
    ];
    return [
      'version' => 1,
      'criteria' => [
        [
          'id' => 'betreuungsangebot',
          'factKey' => 'facility_provider',
          'factType' => 'enum',
          'label' => [
            'de' => 'Art der Betreuung',
            'en' => 'Type of childcare',
          ],
          'question' => [
            'de' => 'Für welche Art von Betreuung suchen Sie einen Platz?',
            'en' => 'What kind of childcare are you looking for a place in?',
          ],
          'affects' => 'procedure_fit',
          'order' => 0,
          'reviewStatus' => 'approved',
          'evidence' => $evidence('Mit diesem Antrag melden Sie Ihr Kind für einen Platz in einer städtischen Kindertageseinrichtung der Stadt Musterstadt an.'),
          'options' => [
            'Städtische Kita',
            'Kita eines freien Trägers',
            'Kita außerhalb von Musterstadt',
            'Kindertagespflege',
            'Unklar',
          ],
          'privacy' => ['kind' => 'not_personal'],
        ],
        [
          'id' => 'wohnsitz_in_musterstadt',
          'factKey' => 'residence_in_municipality',
          'factType' => 'boolean',
          'label' => [
            'de' => 'Wohnsitz des Kindes in Musterstadt',
            'en' => 'Child lives in Musterstadt',
          ],
          'question' => [
            'de' => 'Hat Ihr Kind seinen Wohnsitz in Musterstadt?',
            'en' => 'Does your child live in Musterstadt?',
          ],
          'affects' => 'procedure_fit',
          'order' => 1,
          'reviewStatus' => 'approved',
          'evidence' => $evidence('Das Kind hat seinen Wohnsitz in Musterstadt.'),
          'privacy' => ['kind' => 'not_personal'],
        ],
      ],
    ];
  }

  /**
   * Checks leaflet ownership without writing files, documents or a draft form.
   *
   * @return array{0: \Drupal\node\NodeInterface|false, 1: \Drupal\file\FileInterface|false}
   *   Existing seed document and file, if present.
   */
  function beantragt_demo_kita_leaflet_file_state(EntityTypeManagerInterface $entity_type_manager): array {
    $leaflet = beantragt_demo_kita_leaflet();
    $uri = 'private://public_documents/' . $leaflet['filename'];
    $storage = $entity_type_manager->getStorage('node');
    $nodes = $storage->loadByProperties(['uuid' => $leaflet['uuid']]);
    $node = reset($nodes);
    if ($node instanceof NodeInterface && $node->bundle() !== 'public_document') {
      throw new RuntimeException(sprintf('Node %s is a %s, not a public_document.', $leaflet['uuid'], $node->bundle()));
    }
    $files = $entity_type_manager->getStorage('file')->loadByProperties(['uri' => $uri]);
    if (count($files) > 1) {
      throw new RuntimeException(sprintf('The Kita leaflet file %s is duplicated.', $uri));
    }
    $file = reset($files);
    if ($file instanceof FileInterface) {
      // A familiar filename does not establish ownership. Only this seed's
      // fixed-UUID document may own a file that the seed is allowed to replace.
      $owned = $node instanceof NodeInterface
        && (int) $node->get('field_public_document_file')->target_id === (int) $file->id();
      $references = $storage->getQuery()->accessCheck(FALSE)
        ->condition('field_public_document_file.target_id', $file->id())
        ->execute();
      $exclusive = $owned && count($references) === 1
        && (int) reset($references) === (int) $node->id();
      $usage = \Drupal::service('file.usage')->listUsage($file);
      $adoptable = !$node instanceof NodeInterface && $references === [] && $usage === []
        && file_exists($uri) && file_get_contents($uri) === beantragt_demo_kita_leaflet_pdf();
      foreach ($usage as $module => $types) {
        foreach ($types as $type => $usages) {
          foreach ($usages as $id => $count) {
            if ($count > 0 && ($module !== 'file' || $type !== 'node' || !$owned || (int) $id !== (int) $node->id())) {
              $exclusive = FALSE;
            }
          }
        }
      }
      if (!$exclusive && !$adoptable) {
        throw new RuntimeException('The Kita leaflet filename is owned or shared by another document; no file was replaced.');
      }
    }
    elseif (file_exists($uri) && file_get_contents($uri) !== beantragt_demo_kita_leaflet_pdf()) {
      // Volumes can outlive the database on a local reinstall. Only the exact
      // seed bytes establish ownership of an otherwise unmanaged leftover.
      throw new RuntimeException('The Kita leaflet filename already exists without seed-owned bytes; no file was replaced.');
    }
    return [$node, $file];
  }

  /**
   * Creates or updates the leaflet: its private PDF and its public document.
   *
   * The node is bound to the form as a download, German with an English
   * translation, found by its fixed UUID. Nothing is written when bytes,
   * texts and fields already match, so the document's revision and with it
   * the form's context source version stay the same on a rerun.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: string}
   *   The document and created, updated or unchanged.
   */
  function beantragt_demo_kita_ensure_leaflet(
    EntityTypeManagerInterface $entity_type_manager,
    GroupInterface $jurisdiction,
    FormSchemaInterface $form,
  ): array {
    $leaflet = beantragt_demo_kita_leaflet();
    $bytes = beantragt_demo_kita_leaflet_pdf();
    // Flat: the context source resolver reads only files directly inside
    // private://public_documents (PublicDocumentPathResolver).
    $uri = 'private://public_documents/' . $leaflet['filename'];
    $file_system = \Drupal::service('file_system');
    [$node, $file] = beantragt_demo_kita_leaflet_file_state($entity_type_manager);
    $storage = $entity_type_manager->getStorage('node');
    $realpath = $file instanceof FileInterface ? $file_system->realpath($uri) : FALSE;
    $file_status = 'unchanged';
    if (!$file instanceof FileInterface || !is_string($realpath) || !is_file($realpath) || file_get_contents($realpath) !== $bytes) {
      $directory = 'private://public_documents';
      if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new RuntimeException(sprintf('Unable to prepare %s.', $directory));
      }
      if ($file instanceof FileInterface) {
        $file_system->saveData($bytes, $uri, FileExists::Replace);
        $file->setSize(strlen($bytes));
        $file_status = 'updated';
      }
      else {
        if (file_exists($uri) && file_get_contents($uri) === $bytes) {
          // Adopt this seed's leftover without rewriting the existing file.
          $file = $entity_type_manager->getStorage('file')->create([
            'uri' => $uri,
            'filename' => $leaflet['filename'],
            'filesize' => strlen($bytes),
            'uid' => 1,
          ]);
        }
        else {
          $file = \Drupal::service('file.repository')->writeData($bytes, $uri, FileExists::Replace);
        }
        $file_status = 'created';
      }
      $file->setMimeType('application/pdf');
      $file->setPermanent();
      $file->save();
    }

    $is_new = !$node instanceof NodeInterface;
    if ($is_new) {
      $node = $storage->create([
        'type' => 'public_document',
        'uuid' => $leaflet['uuid'],
        'langcode' => 'de',
        'uid' => 1,
      ]);
    }
    $fields = [
      'field_jurisdiction' => (int) $jurisdiction->id(),
      'field_organisation' => NULL,
      'field_form_schema' => (int) $form->id(),
      'field_public_document_file' => (int) $file->id(),
      'field_content_placement' => 'form_sidebar',
      'field_content_weight' => 10,
    ];
    $text_matches = static fn (NodeInterface $version, string $title, string $body): bool => (string) $version->label() === $title
      && (string) ($version->get('body')->value ?? '') === $body
      && (string) ($version->get('body')->format ?? '') === 'basic_html'
      && $version->isPublished();
    $changed = $is_new
      || $node->language()->getId() !== 'de'
      || !$text_matches($node, $leaflet['title_de'], $leaflet['body_de'])
      || !$node->hasTranslation('en')
      || !$text_matches($node->getTranslation('en'), $leaflet['title_en'], $leaflet['body_en']);
    foreach ($fields as $name => $target) {
      $item = $node->get($name);
      $is_reference = in_array($item->getFieldDefinition()->getType(), ['entity_reference', 'file'], TRUE);
      $current = $item->isEmpty() ? NULL : ($is_reference ? (int) $item->target_id : $item->value);
      if ($target === NULL ? $current !== NULL : (string) $current !== (string) $target) {
        $changed = TRUE;
      }
    }
    if (!$changed) {
      return [$node, $file_status];
    }

    $node->setTitle($leaflet['title_de']);
    $node->set('body', ['value' => $leaflet['body_de'], 'format' => 'basic_html']);
    foreach ($fields as $name => $target) {
      $is_reference = in_array($node->get($name)->getFieldDefinition()->getType(), ['entity_reference', 'file'], TRUE);
      $node->set($name, $target === NULL ? NULL : ($is_reference ? ['target_id' => $target] : $target));
    }
    $node->setPublished();
    $translation = $node->hasTranslation('en') ? $node->getTranslation('en') : $node->addTranslation('en');
    $translation->setTitle($leaflet['title_en']);
    $translation->set('body', ['value' => $leaflet['body_en'], 'format' => 'basic_html']);
    $translation->setPublished();
    $node->save();
    return [$node, $is_new ? 'created' : 'updated'];
  }

}

if (defined('BEANTRAGT_SEED_PAYLOAD_VALIDATION')) {
  return;
}

$is_ddev = getenv('IS_DDEV_PROJECT') === 'true' || (string) getenv('DDEV_PROJECT') !== '';
if (!$is_ddev && getenv('BEANTRAGT_ALLOW_DEMO_KITA_SEED') !== '1') {
  throw new RuntimeException('Refusing to seed the Kita admission demo form outside DDEV. Set BEANTRAGT_ALLOW_DEMO_KITA_SEED=1 only for an intentional non-production import.');
}

$entity_type_manager = \Drupal::entityTypeManager();
$slug = 'musterstadt';

$group_storage = $entity_type_manager->getStorage('group');
$jurisdiction_ids = $group_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'jur')
  ->condition('field_slug', $slug)
  ->range(0, 2)
  ->execute();
if (count($jurisdiction_ids) !== 1) {
  throw new RuntimeException(sprintf('Expected exactly one jurisdiction %s, found %d.', $slug, count($jurisdiction_ids)));
}
$jurisdiction = $group_storage->load(reset($jurisdiction_ids));
if (!$jurisdiction instanceof GroupInterface) {
  throw new RuntimeException('The demo jurisdiction could not be loaded.');
}

$organisation_ids = $group_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'org')
  ->condition('field_slug', 'citizen-services')
  ->condition('field_jurisdiction', (int) $jurisdiction->id())
  ->range(0, 2)
  ->execute();
if (count($organisation_ids) !== 1) {
  throw new RuntimeException(sprintf('Expected the Bürgerbüro (citizen-services) of %s once, found %d; run scripts/seed-demo-data.php first.', $slug, count($organisation_ids)));
}
$organisation = $group_storage->load(reset($organisation_ids));
if (!$organisation instanceof GroupInterface) {
  throw new RuntimeException('The Bürgerbüro could not be loaded.');
}

$owner = $entity_type_manager->getStorage('user')->load(1);
if (!$owner instanceof UserInterface) {
  throw new RuntimeException('User 1 is missing.');
}

$source_filename = 'seed:demo:kita-aufnahme';
$storage = $entity_type_manager->getStorage('form_schema');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('field_source_filename', $source_filename)
  ->range(0, 2)
  ->execute();
if (count($ids) > 1) {
  throw new RuntimeException('More than one Kita admission demo form exists; remove the duplicate first.');
}
$schema = $ids ? $storage->load(reset($ids)) : $storage->create(['type' => 'form_schema']);
if (!$schema instanceof FormSchemaInterface) {
  throw new RuntimeException('Seeded form_schema entity does not implement FormSchemaInterface.');
}
$is_new = $schema->isNew();

$publish = $is_ddev || getenv('BEANTRAGT_DEMO_KITA_PUBLISH') === '1';
$target_status = $publish ? 'published' : 'draft';
$target_scope = $publish ? 'internet' : 'intranet';
$group_targets = [
  'field_jurisdiction' => (int) $jurisdiction->id(),
  'field_organisation' => (int) $organisation->id(),
  'field_group' => (int) $organisation->id(),
];

// Writes a payload as a draft; FormSchema accepts schema changes only on a
// persisted draft.
// A signed-in seeding account authors the form; drush runs keep the seed owner.
$form_owner_id = \Drupal::currentUser()->isAuthenticated() ? (int) \Drupal::currentUser()->id() : (int) $owner->id();

$save_draft = static function (FormSchemaInterface $schema, array $payload) use ($source_filename, $target_scope, $form_owner_id, $group_targets): void {
  $schema->set('label', $payload['title']);
  $schema->set('field_description', $payload['description']);
  $schema->set('field_schema', json_encode(
    $payload,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  ));
  $schema->set('field_source_filename', $source_filename);
  $schema->set('field_workflow_status', 'draft');
  $schema->set('field_publication_scope', $target_scope);
  $schema->setOwnerId($form_owner_id);
  foreach ($group_targets as $field_name => $target_id) {
    if ($schema->hasField($field_name)) {
      $schema->set($field_name, ['target_id' => $target_id]);
    }
  }
  $schema->set('status', FALSE);
  $schema->save();
};

// Refuse an unrelated filename before even the new draft form is persisted.
beantragt_demo_kita_leaflet_file_state($entity_type_manager);

// Pass 1: the leaflet document refers to the form, and the context module
// refuses an approved precheck whose source is not stored yet
// when the context module is enabled. A new form is therefore stored
// as a plain draft first; the leaflet follows, then the full payload.
if ($is_new) {
  $save_draft($schema, beantragt_demo_kita_schema_payload());
}
[$leaflet, $leaflet_status] = beantragt_demo_kita_ensure_leaflet($entity_type_manager, $jurisdiction, $schema);

// New forms receive the leaflet's context source and its quoted precheck.
// Existing forms keep operator configuration, including intentionally empty
// lists. Key absence on legacy forms still gets the initial seed defaults.
$payload = beantragt_demo_kita_schema_payload(getenv('BEANTRAGT_EXAMPLE_DATA') === '1' ? NULL : [
  'id' => strtolower((string) $leaflet->uuid()),
  'title' => (string) $leaflet->label(),
  'version' => sprintf('revision:%d:changed:%d', (int) $leaflet->getRevisionId(), $leaflet->getChangedTime()),
  'changed' => $leaflet->getChangedTime(),
]);
$stored_payload = json_decode((string) $schema->getSchema(), TRUE);
if (!$is_new && is_array($stored_payload)) {
  foreach (['contextSources', 'precheckSchema'] as $key) {
    if (array_key_exists($key, $stored_payload) && is_array($stored_payload[$key])) {
      $preserved = $stored_payload[$key];
      if ($key === 'contextSources' && isset($payload[$key][0])) {
        // The seed owns the leaflet, not the operator's source configuration.
        // Refresh metadata for every binding to it, preserving all other keys.
        $seed_source = $payload[$key][0];
        foreach ($preserved as &$source) {
          if (is_array($source) && ($source['type'] ?? NULL) === $seed_source['type']
            && strtolower((string) ($source['id'] ?? '')) === $seed_source['id']) {
            $source['placement'] = $seed_source['placement'];
            $source['version'] = $seed_source['version'];
            $source['changed'] = $seed_source['changed'];
          }
        }
        unset($source);
      }
      if ($key === 'precheckSchema' && is_array($preserved['criteria'] ?? NULL)) {
        // Retire precisely the former seed question, leaving operator rules.
        $preserved['criteria'] = array_values(array_filter(
          $preserved['criteria'],
          static fn ($criterion): bool => !is_array($criterion) || ($criterion['id'] ?? NULL) !== 'alter_im_rechtsanspruch',
        ));
      }
      $payload[$key] = $preserved;
    }
  }
}

$print_result = static function (string $action) use ($payload, $source_filename, $publish, $slug, $organisation, $leaflet, $leaflet_status, &$schema): void {
  printf(
    "%s %s (%s): status=%s scope=%s\nFORM_ROUTE kita-aufnahme /%s/%s/form/%s\n",
    $action,
    $payload['title'],
    $source_filename,
    $publish ? 'published' : 'draft',
    $publish ? 'internet' : 'intranet',
    $slug,
    (string) $organisation->get('field_slug')->value,
    strtolower((string) $schema->uuid()),
  );
  printf(
    "Kita leaflet %s: %s; context sources=%d, precheck criteria=%d.\n",
    $leaflet_status,
    (string) $leaflet->label(),
    count($payload['contextSources'] ?? []),
    count($payload['precheckSchema']['criteria'] ?? []),
  );
};

$changed = $is_new
  || $stored_payload != $payload
  || (string) $schema->label() !== $payload['title']
  || (string) ($schema->get('field_description')->value ?? '') !== $payload['description']
  || (string) ($schema->get('field_source_filename')->value ?? '') !== $source_filename
  || $schema->getSchemaStatus() !== $target_status
  || (string) ($schema->get('field_publication_scope')->value ?? '') !== $target_scope
  || (int) $schema->getOwnerId() !== $form_owner_id
  || $schema->isPublished() !== $publish;
foreach ($group_targets as $field_name => $target_id) {
  if ($schema->hasField($field_name) && (int) ($schema->get($field_name)->target_id ?? 0) !== $target_id) {
    $changed = TRUE;
  }
}

if (!$changed) {
  $print_result('Unchanged');
  return;
}

// Like the admin editor and seed-demo-wohnberechtigungsschein.php, a rerun
// first saves an auditable draft revision, reloads it, applies the payload as
// a draft and publishes in a second save.
if ($schema->getSchemaStatus() !== 'draft') {
  $schema->set('field_workflow_status', 'draft');
  $schema->set('status', FALSE);
  $schema->save();
  $schema_id = (int) $schema->id();
  $storage->resetCache([$schema_id]);
  $schema = $storage->load($schema_id);
  if (!$schema instanceof FormSchemaInterface) {
    throw new RuntimeException('The Kita admission demo form could not be reloaded as a draft.');
  }
}

$save_draft($schema, $payload);

if ($publish) {
  $schema->set('field_workflow_status', 'published');
  $schema->set('status', TRUE);
  $schema->save();
}

$print_result($is_new ? 'Created' : 'Updated');
