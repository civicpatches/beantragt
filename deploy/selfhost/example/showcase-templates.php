<?php

/**
 * @file
 * Demo: decision templates of the showcase forms A and B.
 *
 * From one base body (scripts/lib/DemoDocx.php: recipient and information
 * block, 2.5/2 cm margins, footer with address, opening hours, web address
 * and page number) the seed releases into the catalogue of the form's office:
 * - form A "Sondernutzung für Außengastronomie beantragen" (Bürgerbüro):
 *   Eingangsbestätigung, Anhörung, Sondernutzungserlaubnis (approval, with
 *   the marked paragraph "[Bitte hier die Auflagen für diese Fläche
 *   eintragen]" for the clerk's Word path) and Ablehnungsbescheid;
 * - form B "Zuschuss für eine Vereinsveranstaltung beantragen" (Hauptamt):
 *   Zuwendungsbescheid (approval) and Ablehnungsbescheid.
 * Both forms get the decision route "template" with these templates allowed
 * (templates allowed before stay allowed). Delivery: A portal or post, B
 * portal only (form B asks for no postal address). A form with an active
 * integration assignment keeps its route and delivery offer (the seed runs
 * after the integration seeds and must not turn a case-system route back);
 * its templates only become allowed. No connection, mapping or contract is
 * created.
 *
 * The sender address comes from the jurisdiction (Rathausplatz 1, set by
 * scripts/seed-demo-docgen.php); the product's letterhead names it, so the
 * bodies carry no sender block and no bank line. Placeholders come from
 * docgen /inspect, or locally from the brace texts when docgen is not
 * configured, unavailable or busy (cp3 installs before docgen is
 * connected); a template docgen rejects stops the seed. A placeholder outside
 * the form's catalogue stops the seed, and so does one of a field the form
 * marks sensitive; protected identity placeholders (form A's address, a form
 * with a sensitive field protects them) are allowed, because case workers
 * render them through the audited reveal. Each template has
 * a seed key; its family UUID is derived from key and scope, so templates
 * uploaded by hand are never adopted. A rerun keeps a released version whose
 * bytes did not change and adds the next version when they did.
 *
 * The seed also writes briefkopf-stadt-musterstadt.docx (the base body with
 * the product letterhead applied and its three header tags replaced by text)
 * to .smoke-shots/showcase/ in DDEV, else to private://showcase/. No DOCX is
 * tracked in the repository.
 *
 * Every template, the letterhead check and the handout are built and checked
 * before the first write. No AI provider, FIT-Connect or mail is involved.
 * Runs after scripts/seed-demo-showcase-forms.php. It
 * needs BEANTRAGT_ALLOW_SHOWCASE_SEED=1 everywhere, in DDEV too: every
 * worktree's DDEV configuration names the main instance, so a hand-typed
 * drush call must not reach it; the installers pass the flag. It always
 * needs the Musterstadt example data.
 */

declare(strict_types=1);

use Drupal\beantragt_docgen\Docx\DocxLetterhead;
use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\VariableCatalog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

require_once __DIR__ . '/lib/DemoDocx.php';
require_once __DIR__ . '/lib/seed-docgen-template.php';
require_once __DIR__ . '/lib/showcase-form-catalog.php';

beantragt_showcase_docgen_assert_runtime();
$entity_type_manager = \Drupal::entityTypeManager();

// Preconditions, all before the first write.
$jurisdiction = beantragt_showcase_docgen_jurisdiction($entity_type_manager);
$admins = $entity_type_manager->getStorage('user')->loadByProperties(['mail' => $example_admin_email ?? (getenv('BEANTRAGT_EXAMPLE_ADMIN_EMAIL') ?: 'verwaltung@example.invalid')]);
$admin = reset($admins);
if (!$admin instanceof UserInterface) {
  $admin = $entity_type_manager->getStorage('user')->load(1);
}
if (!$admin instanceof UserInterface) {
  throw new RuntimeException('No account to own the showcase templates.');
}
$scope_access = \Drupal::service('beantragt_docgen.scope_access');
$catalog = \Drupal::service('beantragt_docgen.template_catalog');
$client = \Drupal::service('beantragt_docgen.client');
$settings = \Drupal::service('beantragt_docgen.settings');
$form_templates = \Drupal::service('beantragt_docgen.form_templates');

$theme = \Drupal::service('beantragt_group.jurisdiction_config')->getSection($jurisdiction, 'theme');
$logo = (string) ($theme['logoDataUrl'] ?? '');
if (!str_starts_with($logo, 'data:image/png;base64,') && !str_starts_with($logo, 'data:image/jpeg;base64,')) {
  throw new RuntimeException('The Musterstadt letterhead needs a PNG or JPEG logo; run scripts/seed-demo-docgen.php first.');
}
$logo_alt = (string) ($theme['logoAlt'] ?? 'Stadt Musterstadt');

$forms = [];
foreach (beantragt_showcase_docgen_definitions() as $form_key => $form_definition) {
  $form = beantragt_showcase_docgen_form($entity_type_manager, $jurisdiction, $form_key);
  $scope = $scope_access->scopeOf($form);
  if (!$scope instanceof GroupInterface || (string) $scope->get('field_slug')->value !== $form_definition['office_slug']) {
    throw new RuntimeException(sprintf('Showcase form %s is not scoped to %s; run scripts/seed-demo-showcase-forms.php first.', $form_key, $form_definition['office_slug']));
  }
  $sender = $settings->senderAddress($scope);
  if (trim($sender) === '') {
    throw new RuntimeException(sprintf('No sender address for %s; run scripts/seed-demo-docgen.php first.', $form_key));
  }
  $variables = $form_templates->variables($form);
  $sensitive_tags = beantragt_showcase_docgen_sensitive_tags($form);
  $built = [];
  foreach ($form_definition['templates'] as $template_key => $template) {
    $letter = beantragt_showcase_docgen_letter($form_definition, $template);
    $bytes = DemoDocx::letter($letter);
    DocxPrecheck::assertDocx($bytes);
    // The product writes its letterhead into every draft; the footer must
    // survive that (DocxLetterhead keeps footers).
    if (!DemoDocx::hasFooter(DocxLetterhead::apply($bytes, $logo, $logo_alt))) {
      throw new RuntimeException(sprintf('The footer of %s does not survive the letterhead.', $template['label']));
    }
    [$inspection, $source] = beantragt_showcase_docgen_inspect($client, $bytes, $template['label'], $letter);
    if ($inspection['errors'] !== []) {
      throw new RuntimeException(sprintf('The showcase template %s has template errors: %s', $template['label'], json_encode($inspection['errors'])));
    }
    $comparison = VariableCatalog::compare($inspection, $variables);
    if ($comparison['unknown'] !== []) {
      throw new RuntimeException(sprintf('The showcase template %s uses placeholders the form does not provide: %s', $template['label'], implode(', ', array_column($comparison['unknown'], 'tag'))));
    }
    // A form with a sensitive field protects every field that identifies
    // the applicant (ApplicationDataProtectionService::protectedFieldIds()),
    // so form A's recipient address is protected: case workers render it
    // through the audited reveal ('view protected application data'). A
    // placeholder of a field marked sensitive itself never goes into a
    // letter.
    $sensitive_used = array_values(array_intersect($comparison['protected'], $sensitive_tags));
    if ($sensitive_used !== []) {
      throw new RuntimeException(sprintf('The showcase template %s uses sensitive placeholders: %s', $template['label'], implode(', ', $sensitive_used)));
    }
    $built[$template_key] = $template + [
      'bytes' => $bytes,
      'inspection' => $inspection,
      'source' => $source,
      'protected' => $comparison['protected'],
    ];
  }
  $forms[$form_key] = ['form' => $form, 'scope' => $scope, 'sender' => $sender, 'templates' => $built] + $form_definition;
}

$handout = beantragt_showcase_docgen_handout($logo, $logo_alt, $jurisdiction);
$handout_path = beantragt_showcase_docgen_handout_directory() . '/briefkopf-stadt-musterstadt.docx';

// Writes.
$counts = ['written' => 0, 'unchanged' => 0];
foreach ($forms as $form_key => $entry) {
  $seeded = [];
  foreach ($entry['templates'] as $template_key => $template) {
    $released = beantragt_demo_docgen_ensure_template($entity_type_manager, $catalog, $jurisdiction, $entry['scope'], $admin, $form_key . ':' . $template_key, $template, $counts);
    $seeded[] = $released->getFamily();
  }
  $form = $entry['form'];
  $current = $settings->formSettings((string) $form->uuid());
  $families = array_values(array_unique(array_merge($current['templates'], $seeded)));
  if (beantragt_showcase_docgen_has_active_assignment((string) $form->uuid())) {
    // A form handed to a case system keeps its route and delivery offer,
    // also when an integration seed set them earlier in the same install;
    // the seeded templates only become allowed.
    if ($current['templates'] === $families) {
      printf("Unchanged decision settings of %s: active integration assignment, route %s left as it is.\n", $form_key, $current['route']);
      $counts['unchanged']++;
    }
    else {
      $settings->saveFormSettings((string) $form->uuid(), NULL, $families, (int) $admin->id(), NULL);
      printf("Updated allowed templates of %s: active integration assignment, route %s and delivery left as they are.\n", $form_key, $current['route']);
      $counts['written']++;
    }
  }
  elseif ($current['route'] === DocgenSettingsRepository::ROUTE_TEMPLATE
    && $current['templates'] === $families
    && $current['delivery'] === $entry['delivery']) {
    printf("Unchanged decision settings of %s: route template, %d template(s), delivery %s.\n", $form_key, count($families), implode(' or ', $entry['delivery']['routes']));
    $counts['unchanged']++;
  }
  else {
    $settings->saveFormSettings((string) $form->uuid(), DocgenSettingsRepository::ROUTE_TEMPLATE, $families, (int) $admin->id(), $entry['delivery']);
    printf("Updated decision settings of %s: route template, %d template(s), delivery %s.\n", $form_key, count($families), implode(' or ', $entry['delivery']['routes']));
    $counts['written']++;
  }
  printf("Sender address of %s (%s): %s.\n", $form_key, $entry['scope']->label(), str_replace("\n", ', ', $entry['sender']));
}

$current_handout = is_file($handout_path) ? file_get_contents($handout_path) : FALSE;
if ($current_handout === $handout) {
  printf("Unchanged letterhead handout %s.\n", $handout_path);
  $counts['unchanged']++;
}
else {
  if (file_put_contents($handout_path, $handout) === FALSE) {
    throw new RuntimeException(sprintf('Unable to write %s.', $handout_path));
  }
  printf("Wrote letterhead handout %s.\n", $handout_path);
  $counts['written']++;
}
if (!$client->isConfigured()) {
  fwrite(STDERR, "NOTICE: docgen is not configured on this host; drafts and previews fail until BEANTRAGT_DOCGEN_BASE_URL and the token are set.\n");
}
printf("Showcase docgen seed: %d written, %d unchanged.\n", $counts['written'], $counts['unchanged']);

/**
 * Refuses to run outside a demo.
 */
function beantragt_showcase_docgen_assert_runtime(): void {
  if (!\Drupal::moduleHandler()->moduleExists('beantragt_docgen')) {
    throw new RuntimeException('beantragt_docgen is not enabled.');
  }
  // Required in DDEV too: every worktree's DDEV configuration names the main
  // local instance.
  if (getenv('BEANTRAGT_ALLOW_SHOWCASE_SEED') !== '1') {
    throw new RuntimeException('Refusing the showcase docgen seed without BEANTRAGT_ALLOW_SHOWCASE_SEED=1 (required in DDEV too; the installers pass it).');
  }
}

/**
 * Loads the Musterstadt jurisdiction.
 */
function beantragt_showcase_docgen_jurisdiction($entity_type_manager): GroupInterface {
  $ids = $entity_type_manager->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'jur')
    ->condition('field_slug', 'musterstadt')
    ->range(0, 2)
    ->execute();
  $jurisdiction = count($ids) === 1 ? $entity_type_manager->getStorage('group')->load(reset($ids)) : NULL;
  if (!$jurisdiction instanceof GroupInterface) {
    throw new RuntimeException(sprintf('The showcase docgen seed needs exactly one Musterstadt jurisdiction, found %d.', count($ids)));
  }
  return $jurisdiction;
}

/**
 * Loads one published showcase form by its source key.
 */
function beantragt_showcase_docgen_form($entity_type_manager, GroupInterface $jurisdiction, string $key): FormSchemaInterface {
  $ids = $entity_type_manager->getStorage('form_schema')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', beantragt_showcase_source_prefix() . $key)
    ->condition('field_workflow_status', 'published')
    ->range(0, 2)
    ->execute();
  $form = count($ids) === 1 ? $entity_type_manager->getStorage('form_schema')->load(reset($ids)) : NULL;
  if (!$form instanceof FormSchemaInterface) {
    throw new RuntimeException(sprintf('Expected the published showcase form %s once, found %d; run scripts/seed-demo-showcase-forms.php first.', $key, count($ids)));
  }
  return $form;
}

/**
 * Placeholder tags of the fields a form marks sensitive.
 *
 * Uses the product's own definition
 * (ApplicationDataProtectionService::sensitiveFieldIds(): every spelling of
 * the flag, file fields by default).
 *
 * @return list<string>
 *   Tags such as antrag.laufende_verfahren.
 */
function beantragt_showcase_docgen_sensitive_tags(FormSchemaInterface $form): array {
  $schema = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
  return array_values(array_map(
    static fn(string $id): string => VariableCatalog::FORM_PREFIX . $id,
    \Drupal::service('beantragt_application.data_protection')->sensitiveFieldIds($schema),
  ));
}

/**
 * Whether a form has an active integration assignment (a case system).
 */
function beantragt_showcase_docgen_has_active_assignment(string $form_uuid): bool {
  $database = \Drupal::database();
  if (!$database->schema()->tableExists('beantragt_integration_assignment')) {
    return FALSE;
  }
  return (bool) $database->select('beantragt_integration_assignment', 'a')
    ->condition('a.form_schema_uuid', $form_uuid)
    ->condition('a.status', 'active')
    ->countQuery()
    ->execute()
    ->fetchField();
}

/**
 * Reads the placeholders of a template, locally when docgen is unreachable.
 *
 * @return array{0: array{placeholders: list<string>, loops: list<string>, errors: list<mixed>}, 1: string}
 *   Inspection and its source.
 */
function beantragt_showcase_docgen_inspect($client, string $bytes, string $label, array $letter): array {
  try {
    return [$client->inspect($bytes), 'docgen /inspect'];
  }
  catch (DocgenApiException $exception) {
    // Only an absent or busy docgen falls back; a template docgen rejects
    // stops the seed.
    if (!in_array($exception->errorCode, ['docgen.not_configured', 'docgen.unavailable', 'docgen.busy'], TRUE)) {
      throw $exception;
    }
    // The generated letters have no loops; their tags are the brace texts.
    preg_match_all('/\{([a-z][a-z0-9_.]*)\}/u', json_encode($letter, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $matches);
    $placeholders = array_values(array_unique($matches[1]));
    sort($placeholders);
    fwrite(STDERR, sprintf("NOTICE: docgen not reachable (%s); placeholders of %s read locally.\n", $exception->errorCode, $label));
    return [['placeholders' => $placeholders, 'loops' => [], 'errors' => []], 'local fallback'];
  }
}

/**
 * Builds the DemoDocx letter definition of one template.
 */
function beantragt_showcase_docgen_letter(array $form_definition, array $template): array {
  return [
    'recipient' => $form_definition['recipient'],
    'info' => [
      ['Aktenzeichen', '{vorgang.aktenzeichen}'],
      ['Datum', '{heute}'],
      ['Bearbeitung', '{bearbeitung.name}'],
      ['Telefon', $form_definition['phone']],
      ['E-Mail', $form_definition['mail']],
    ],
    'subject' => $template['subject'],
    'body' => array_merge(
      ['Sehr geehrte Damen und Herren,'],
      $template['body'],
      ['', 'Mit freundlichen Grüßen', 'Im Auftrag', '{bearbeitung.name}'],
    ),
    'footer' => beantragt_showcase_docgen_footer(),
  ];
}

/**
 * Footer lines of every Musterstadt letter.
 *
 * @return list<string>
 *   Footer lines.
 */
function beantragt_showcase_docgen_footer(): array {
  return [
    'Stadt Musterstadt · Rathausplatz 1 · 12345 Musterstadt · Telefon 030 23125 100',
    'Öffnungszeiten: Montag bis Freitag 8 bis 16 Uhr, Donnerstag bis 18 Uhr · www.musterstadt.example',
    // On every page, so no printout passes for a real notice.
    'Demonstrationsschreiben der fiktiven Stadt Musterstadt, ohne Rechtswirkung',
  ];
}

/**
 * Builds the letterhead handout: base body, product letterhead, plain text.
 */
function beantragt_showcase_docgen_handout(string $logo, string $logo_alt, GroupInterface $jurisdiction): string {
  $base = DemoDocx::letter([
    'recipient' => ['[Name oder Firma]', '[Straße und Hausnummer]', '[Postleitzahl und Ort]'],
    'info' => [
      ['Aktenzeichen', '[Aktenzeichen]'],
      ['Datum', '[Datum]'],
      ['Bearbeitung', '[Name der Sachbearbeitung]'],
      ['Telefon', '030 23125 200'],
      ['E-Mail', 'buergerbuero@musterstadt.example.org'],
    ],
    'subject' => '[Betreff]',
    'body' => [
      'Sehr geehrte Damen und Herren,',
      '[Text des Schreibens]',
      '',
      'Mit freundlichen Grüßen',
      'Im Auftrag',
      '[Name der Sachbearbeitung]',
    ],
    'footer' => beantragt_showcase_docgen_footer(),
  ]);
  $with_letterhead = DocxLetterhead::apply($base, $logo, $logo_alt);
  if (!DemoDocx::hasFooter($with_letterhead)) {
    throw new RuntimeException('The footer of the letterhead handout does not survive the letterhead.');
  }
  return DemoDocx::fillHeaderTags($with_letterhead, [
    'jurisdiction.name' => (string) $jurisdiction->label(),
    'organisation.name' => 'Bürgerbüro',
    'organisation.anschrift' => 'Rathausplatz 1, 12345 Musterstadt',
  ]);
}

/**
 * Directory of generated showcase files, created when missing.
 */
function beantragt_showcase_docgen_handout_directory(): string {
  if (getenv('IS_DDEV_PROJECT') === 'true' || (string) getenv('DDEV_PROJECT') !== '') {
    $directory = dirname(DRUPAL_ROOT) . '/.smoke-shots/showcase';
  }
  else {
    $private_root = \Drupal::service('file_system')->realpath('private://');
    if (!is_string($private_root) || $private_root === '' || !str_starts_with($private_root, '/')) {
      throw new RuntimeException('Unable to resolve private storage for the showcase files.');
    }
    $directory = rtrim($private_root, '/') . '/showcase';
  }
  if (!is_dir($directory) && !mkdir($directory, 0770, TRUE) && !is_dir($directory)) {
    throw new RuntimeException(sprintf('Unable to create %s.', $directory));
  }
  return $directory;
}

/**
 * The letters of forms A and B.
 *
 * Only required, unconditional fields are used, so a rendered draft leaves
 * no placeholder empty. Amounts and areas render as the stored number.
 *
 * @return array<string, array<string, mixed>>
 *   Per form key: office, recipient lines, contact, delivery and templates.
 */
function beantragt_showcase_docgen_definitions(): array {
  $remedy_a = [
    ['text' => 'Rechtsbehelfsbelehrung', 'bold' => TRUE],
    'Gegen diesen Bescheid kann innerhalb eines Monats nach Bekanntgabe Widerspruch erhoben werden. Der Widerspruch ist schriftlich oder zur Niederschrift bei der Stadt Musterstadt, Bürgerbüro, Rathausplatz 1, 12345 Musterstadt, einzulegen.',
  ];
  $remedy_b = [
    ['text' => 'Rechtsbehelfsbelehrung', 'bold' => TRUE],
    'Gegen diesen Bescheid kann innerhalb eines Monats nach Bekanntgabe Widerspruch erhoben werden. Der Widerspruch ist schriftlich oder zur Niederschrift bei der Stadt Musterstadt, Hauptamt, Rathausplatz 1, 12345 Musterstadt, einzulegen.',
  ];
  $area_table = [
    'table' => [
      ['Angabe', 'Wert'],
      ['Betrieb', '{antrag.betriebsname}'],
      ['Lage', 'vor {antrag.betrieb_strasse} {antrag.betrieb_hausnummer}'],
      ['Art der Fläche', '{antrag.flaechenart}'],
      ['Zeitraum', '{antrag.zeitraum}, {antrag.beginn} bis {antrag.ende}'],
      ['Fläche', '{antrag.flaeche_m2} m²'],
      ['Tische', '{antrag.anzahl_tische}'],
    ],
    'widths' => [3000, 6355],
  ];
  $grant_table = [
    'table' => [
      ['Angabe', 'Wert'],
      ['Verein', '{antrag.vereinsname}'],
      ['Veranstaltung', '{antrag.titel}'],
      ['Datum', '{antrag.datum}'],
      ['Ort', '{antrag.veranstaltungsort}'],
      ['Gesamtkosten', '{antrag.gesamtkosten} Euro'],
      ['Beantragter Zuschuss', '{antrag.zuschuss} Euro'],
    ],
    'widths' => [3000, 6355],
  ];

  return [
    'outdoor-dining-permit' => [
      'office_slug' => 'citizen-services',
      'phone' => '030 23125 200',
      'mail' => 'buergerbuero@musterstadt.example.org',
      'recipient' => [
        '{antrag.betriebsname}',
        'z. Hd. {antrag.ansprechperson}',
        '{antrag.betrieb_strasse} {antrag.betrieb_hausnummer}',
        '{antrag.betrieb_plz} {antrag.betrieb_ort}',
      ],
      'delivery' => ['routes' => ['portal', 'postal'], 'default' => 'portal'],
      'templates' => [
        'acknowledgement' => [
          'label' => 'Eingangsbestätigung Sondernutzung Außengastronomie',
          'purpose' => 'acknowledgement',
          'filename' => 'eingangsbestaetigung-sondernutzung-aussengastronomie.docx',
          'subject' => 'Eingangsbestätigung zu Ihrem Antrag auf eine Sondernutzungserlaubnis für Außengastronomie',
          'body' => [
            'Ihr Antrag ist am {vorgang.eingang} bei uns eingegangen. Wir haben ihn unter dem Aktenzeichen {vorgang.aktenzeichen} erfasst. Bitte geben Sie dieses Aktenzeichen bei Rückfragen an.',
            $area_table,
            'Wir prüfen Ihren Antrag jetzt. Fehlen Angaben oder Nachweise, melden wir uns bei Ihnen. Den Stand der Bearbeitung sehen Sie jederzeit im Bürgerportal.',
            'Diese Bestätigung ist noch keine Entscheidung über Ihren Antrag. Bitte stellen Sie Tische und Stühle erst auf, wenn die Erlaubnis vorliegt.',
          ],
        ],
        'hearing' => [
          'label' => 'Anhörung vor Ablehnung Sondernutzung Außengastronomie',
          'purpose' => 'request_info',
          'filename' => 'anhoerung-sondernutzung-aussengastronomie.docx',
          'subject' => 'Anhörung nach § 28 VwVfG zu Ihrem Antrag auf eine Sondernutzungserlaubnis',
          'body' => [
            'Sie haben am {vorgang.eingang} beantragt, eine Fläche von {antrag.flaeche_m2} m² ({antrag.flaechenart}) vor {antrag.betrieb_strasse} {antrag.betrieb_hausnummer} für Außengastronomie zu nutzen.',
            'Neben der Fläche muss ein Gehweg von mindestens 1,50 Metern frei bleiben. Nach Ihren Angaben bleiben {antrag.restgehweg_m} Meter. Nach dem derzeitigen Stand kommt deshalb eine Ablehnung Ihres Antrags in Betracht.',
            'Bevor wir entscheiden, geben wir Ihnen nach § 28 Verwaltungsverfahrensgesetz Gelegenheit, sich zu äußern. Sie können uns innerhalb von zwei Wochen nach Zugang dieses Schreibens einen geänderten Lageplan mit einer kleineren Fläche einreichen. Das geht über das Bürgerportal, schriftlich oder zur Niederschrift im Bürgerbüro, Rathausplatz 1, 12345 Musterstadt.',
            'Mit diesem Schreiben ist noch nicht über Ihren Antrag entschieden. Äußern Sie sich nicht innerhalb der Frist, entscheiden wir nach Aktenlage.',
          ],
        ],
        'approval' => [
          'label' => 'Sondernutzungserlaubnis Außengastronomie',
          'purpose' => 'approval',
          'filename' => 'sondernutzungserlaubnis-aussengastronomie.docx',
          'subject' => 'Sondernutzungserlaubnis für Außengastronomie',
          'body' => array_merge([
            'auf Ihren Antrag vom {vorgang.eingang} erteilen wir Ihnen die widerrufliche Erlaubnis, die folgende Fläche des öffentlichen Straßenlandes für Tische und Stühle Ihres Betriebs zu nutzen:',
            $area_table,
            ['text' => 'Auflagen', 'bold' => TRUE],
            // Plan 5.5: square brackets, so docgen leaves the paragraph alone
            // and the clerk writes the conditions in Word.
            '[Bitte hier die Auflagen für diese Fläche eintragen]',
            ['text' => 'Hinweise', 'bold' => TRUE],
            'Neben der Fläche muss ein Gehweg von mindestens 1,50 Metern frei bleiben. Hauseingänge, Einfahrten, Hydranten und Schächte bleiben frei. Die Erlaubnis ist widerruflich; bei Bauarbeiten oder Veranstaltungen kann die Stadt verlangen, die Fläche vorübergehend zu räumen.',
            'Die Gebühr für die Sondernutzung setzen wir mit gesondertem Bescheid fest.',
          ], $remedy_a),
        ],
        'rejection' => [
          'label' => 'Ablehnungsbescheid Sondernutzung Außengastronomie',
          'purpose' => 'rejection',
          'filename' => 'ablehnung-sondernutzung-aussengastronomie.docx',
          'subject' => 'Bescheid über Ihren Antrag auf eine Sondernutzungserlaubnis für Außengastronomie',
          'body' => array_merge([
            'über Ihren Antrag vom {vorgang.eingang} auf Nutzung einer Fläche von {antrag.flaeche_m2} m² vor {antrag.betrieb_strasse} {antrag.betrieb_hausnummer} entscheiden wir wie folgt:',
            ['text' => 'Ihr Antrag wird abgelehnt.', 'bold' => TRUE],
            ['text' => 'Begründung', 'bold' => TRUE],
            'Neben der Fläche muss ein Gehweg von mindestens 1,50 Metern frei bleiben. Nach Ihren Angaben und Ihrer Äußerung im Anhörungsverfahren bleiben {antrag.restgehweg_m} Meter. Damit ist der Gehweg für den Fußverkehr nicht mehr sicher nutzbar.',
            'Für diese Entscheidung erheben wir keine Gebühr. Sie können jederzeit einen neuen Antrag für eine kleinere Fläche stellen.',
          ], $remedy_a),
        ],
      ],
    ],
    'club-event-grant' => [
      'office_slug' => 'central-administration',
      'phone' => '030 23125 300',
      'mail' => 'hauptamt@musterstadt.example.org',
      'recipient' => [
        '{antrag.vereinsname}',
        'z. Hd. {antrag.vorname} {antrag.nachname}',
      ],
      // Form B asks for no postal address of the club, so the decision goes
      // to the citizen portal only.
      'delivery' => ['routes' => ['portal'], 'default' => 'portal'],
      'templates' => [
        'approval' => [
          'label' => 'Zuwendungsbescheid Vereinsveranstaltung',
          'purpose' => 'approval',
          'filename' => 'zuwendungsbescheid-vereinsveranstaltung.docx',
          'subject' => 'Zuwendungsbescheid für Ihre Vereinsveranstaltung',
          'body' => array_merge([
            'auf Ihren Antrag vom {vorgang.eingang} bewilligen wir dem Verein für die folgende Veranstaltung einen Zuschuss in Höhe von {antrag.zuschuss} Euro:',
            $grant_table,
            ['text' => 'Nebenbestimmungen', 'bold' => TRUE],
            'Der Zuschuss ist zweckgebunden und darf nur für die genannte Veranstaltung verwendet werden. Bitte reichen Sie innerhalb von drei Monaten nach der Veranstaltung einen kurzen Verwendungsnachweis mit einer Aufstellung der tatsächlichen Kosten ein. Nicht verwendete Mittel sind zurückzuzahlen.',
            'Der Betrag wird auf das im Antrag genannte Konto des Vereins überwiesen.',
          ], $remedy_b),
        ],
        'rejection' => [
          'label' => 'Ablehnungsbescheid Vereinszuschuss',
          'purpose' => 'rejection',
          'filename' => 'ablehnung-vereinszuschuss.docx',
          'subject' => 'Bescheid über Ihren Antrag auf einen Zuschuss für eine Vereinsveranstaltung',
          'body' => array_merge([
            'über Ihren Antrag vom {vorgang.eingang} auf einen Zuschuss für die Veranstaltung „{antrag.titel}“ am {antrag.datum} entscheiden wir wie folgt:',
            ['text' => 'Ihr Antrag wird abgelehnt.', 'bold' => TRUE],
            ['text' => 'Begründung', 'bold' => TRUE],
            'Die Fördermittel für Vereinsveranstaltungen sind für dieses Jahr bereits vergeben. Nach der Richtlinie zur Förderung von Vereinsveranstaltungen besteht auf einen Zuschuss kein Rechtsanspruch.',
            'Für diese Entscheidung erheben wir keine Gebühr. Sie können für eine spätere Veranstaltung einen neuen Antrag stellen.',
          ], $remedy_b),
        ],
      ],
    ],
  ];
}
