<?php

/**
 * @file
 * Demo: letters from Word templates for the residents parking permit.
 *
 * "Im Demo machen wir alles möglich": Stadt Musterstadt gets the full letter
 * set of the residents parking permit as released templates in its catalog,
 * a sender address for {organisation.anschrift}, and the published residents
 * parking form gets the decision route "template" with all of them allowed:
 *
 * - Eingangsbestätigung Bewohnerparkausweis (acknowledgement)
 * - Anhörung vor Ablehnung Bewohnerparkausweis (hearing under § 28 VwVfG)
 * - Bewilligung Bewohnerparkausweis (fee and approval notice)
 * - Ablehnungsbescheid Bewohnerparkausweis (rejection)
 * - Bewohnerparkausweis zum Auslegen (the printable permit card)
 *
 * Staff draft, preview and release each letter themselves; the templates
 * decide nothing. The DOCX files are generated here
 * (Drupal\beantragt_docgen\Docx\DocxWriter), so no DOCX fixture sits in the
 * repository. The Musterstadt logo PNG is stored in jurisdiction
 * branding (beantragt_group/assets/musterstadt-demo-logo.png). Tags come
 * from docgen /inspect when docgen is reachable; otherwise they are read from
 * the generated text with a loud notice (cp3 reinstalls before docgen is
 * connected there). Every placeholder must exist in the form's catalog, or
 * the seed stops.
 *
 * Each template has a seed key; its family UUID is derived from the key and
 * the form's scope, so templates uploaded by hand with similar names are
 * never adopted or changed. Only the fee notice falls back to its label, so
 * installs seeded before the keys existed keep their family. Rerunning keeps
 * the released version when the document did not change and adds the next
 * version when it did. Templates already allowed on the form stay allowed.
 *
 * Runs after the starter forms are published (scripts/setup-local.sh
 * --with-product-smoke, scripts/install-public-demo.sh).
 *
 * Environment variables:
 * - BEANTRAGT_DEMO_DOCGEN_JURISDICTION_SLUG, default musterstadt.
 */

declare(strict_types=1);

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\beantragt_docgen\Service\VariableCatalog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

$slug = trim((string) (getenv('BEANTRAGT_DEMO_DOCGEN_JURISDICTION_SLUG') ?: 'musterstadt'));
$form_source = 'seed:product-smoke:musterstadt:residents-parking-permit';
$sender_address = "Rathausplatz 1\n12345 Musterstadt";
$admin_email = $example_admin_email ?? (getenv('BEANTRAGT_EXAMPLE_ADMIN_EMAIL') ?: 'verwaltung@example.invalid');

if (!\Drupal::moduleHandler()->moduleExists('beantragt_docgen')) {
  throw new RuntimeException('beantragt_docgen is not enabled.');
}
$entity_type_manager = \Drupal::entityTypeManager();

$jurisdiction_ids = $entity_type_manager->getStorage('group')->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'jur')
  ->condition('field_slug', $slug)
  ->range(0, 2)
  ->execute();
if (count($jurisdiction_ids) !== 1) {
  throw new RuntimeException(sprintf('Expected exactly one jurisdiction %s, found %d.', $slug, count($jurisdiction_ids)));
}
$jurisdiction = $entity_type_manager->getStorage('group')->load(reset($jurisdiction_ids));
if (!$jurisdiction instanceof GroupInterface) {
  throw new RuntimeException('The demo jurisdiction could not be loaded.');
}

$form_ids = $entity_type_manager->getStorage('form_schema')->getQuery()
  ->accessCheck(FALSE)
  ->condition('field_jurisdiction', (int) $jurisdiction->id())
  ->condition('field_source_filename', $form_source)
  ->condition('field_workflow_status', 'published')
  ->range(0, 2)
  ->execute();
if (count($form_ids) !== 1) {
  throw new RuntimeException(sprintf('Expected the published residents parking form of %s once, found %d; run scripts/seed-starter-forms.php first.', $slug, count($form_ids)));
}
$form = $entity_type_manager->getStorage('form_schema')->load(reset($form_ids));
if (!$form instanceof FormSchemaInterface) {
  throw new RuntimeException('The residents parking form could not be loaded.');
}
$scope_access = \Drupal::service('beantragt_docgen.scope_access');
$scope = $scope_access->scopeOf($form);
if (!$scope instanceof GroupInterface) {
  throw new RuntimeException('The residents parking form has no scope group.');
}

$admins = $entity_type_manager->getStorage('user')->loadByProperties(['mail' => $admin_email]);
$admin = reset($admins);
if (!$admin instanceof UserInterface) {
  $admin = $entity_type_manager->getStorage('user')->load(1);
}
if (!$admin instanceof UserInterface) {
  throw new RuntimeException('No account to own the demo templates.');
}

// Synthetic letters of Stadt Musterstadt; every placeholder exists in the
// form's catalog (checked below, no warning in the form editor).
$letterhead = [
  '{antrag.full_name}',
  '{antrag.wohnanschrift}',
  '',
  'Aktenzeichen: {vorgang.aktenzeichen}',
  'Datum: {heute}',
  '',
];
$salutation = 'Sehr geehrte Antragstellerin, sehr geehrter Antragsteller,';
$signature = [
  '',
  'Mit freundlichen Grüßen',
  'Im Auftrag',
  '{bearbeitung.name}',
  '',
];
$remedy = [
  ['text' => 'Rechtsbehelfsbelehrung', 'bold' => TRUE],
  'Gegen diesen Bescheid kann innerhalb eines Monats nach Bekanntgabe Widerspruch erhoben werden. Der Widerspruch ist schriftlich oder zur Niederschrift bei der Stadt Musterstadt, Bürgerbüro, Rathausplatz 1, 12345 Musterstadt, einzulegen.',
];
// Cell styles of the permit card: banner rows, field captions, large values.
$card_banner = ['align' => 'center', 'color' => 'FFFFFF', 'fill' => '1F3A5F', 'span' => 2];
$card_caption = ['size' => 18, 'color' => '4D4D4D'];
$card_large = ['bold' => TRUE, 'size' => 40];
$requirements = 'Ein Bewohnerparkausweis setzt voraus, dass Sie im Bewohnerparkgebiet mit Hauptwohnsitz gemeldet sind, dass das Fahrzeug auf Sie zugelassen ist oder Ihnen nachweislich dauerhaft zur Nutzung überlassen wurde und dass Ihnen kein privater Stellplatz zur Verfügung steht.';

$templates = [
  'acknowledgement' => [
    'label' => 'Eingangsbestätigung Bewohnerparkausweis',
    'purpose' => 'acknowledgement',
    'filename' => 'eingangsbestaetigung-bewohnerparkausweis.docx',
    'blocks' => array_merge($letterhead, [
      ['text' => 'Eingangsbestätigung zu Ihrem Antrag auf einen Bewohnerparkausweis', 'bold' => TRUE, 'size' => 28],
      $salutation,
      'Ihr Antrag auf einen Bewohnerparkausweis ist am {vorgang.eingang} bei uns eingegangen. Wir haben ihn unter dem Aktenzeichen {vorgang.aktenzeichen} erfasst. Bitte geben Sie dieses Aktenzeichen bei Rückfragen an.',
      [
        'table' => [
          ['Angabe', 'Wert'],
          ['Bewohnerparkgebiet', '{antrag.parkzone}'],
          ['Amtliches Kennzeichen', '{antrag.kennzeichen}'],
          ['Gewünschter Beginn', '{antrag.gewuenschter_beginn}'],
          ['Gültigkeitsdauer', '{antrag.gueltigkeitsdauer}'],
        ],
        'widths' => [3500, 5500],
      ],
      'Wir prüfen Ihren Antrag jetzt. Fehlen Angaben oder Nachweise, melden wir uns bei Ihnen. Den Stand der Bearbeitung können Sie jederzeit im Bürgerportal nachsehen.',
      'Diese Bestätigung ist noch keine Entscheidung über Ihren Antrag.',
    ], $signature, [
      'Dies ist ein Demonstrationsschreiben der Stadt Musterstadt. Es hat keine Rechtswirkung.',
    ]),
  ],
  'hearing' => [
    'label' => 'Anhörung vor Ablehnung Bewohnerparkausweis',
    'purpose' => 'request_info',
    'filename' => 'anhoerung-bewohnerparkausweis.docx',
    'blocks' => array_merge($letterhead, [
      ['text' => 'Anhörung nach § 28 VwVfG zu Ihrem Antrag auf einen Bewohnerparkausweis', 'bold' => TRUE, 'size' => 28],
      $salutation,
      'Sie haben am {vorgang.eingang} einen Bewohnerparkausweis für das Bewohnerparkgebiet {antrag.parkzone} und das Fahrzeug mit dem amtlichen Kennzeichen {antrag.kennzeichen} beantragt.',
      $requirements . ' Anhand der bisher vorliegenden Angaben und Unterlagen können wir nicht feststellen, dass alle Voraussetzungen erfüllt sind. Nach dem derzeitigen Stand kommt deshalb eine Ablehnung Ihres Antrags in Betracht.',
      'Bevor wir entscheiden, geben wir Ihnen nach § 28 Verwaltungsverfahrensgesetz Gelegenheit, sich zu äußern. Sie können uns innerhalb von zwei Wochen nach Zugang dieses Schreibens Ihre Sicht schildern und fehlende Nachweise einreichen, zum Beispiel eine Meldebescheinigung oder die Zulassungsbescheinigung Teil I. Das geht über das Bürgerportal, schriftlich oder zur Niederschrift im Bürgerbüro, Rathausplatz 1, 12345 Musterstadt.',
      'Mit diesem Schreiben ist noch nicht über Ihren Antrag entschieden. Wir beziehen Ihre Äußerung und die eingereichten Unterlagen in die Prüfung ein. Äußern Sie sich nicht innerhalb der Frist, entscheiden wir nach Aktenlage.',
    ], $signature, [
      'Dies ist ein Demonstrationsschreiben der Stadt Musterstadt. Es hat keine Rechtswirkung.',
    ]),
  ],
  'approval' => [
    'label' => 'Bewilligung Bewohnerparkausweis',
    // Seeded before the keys existed: adopt the family by label.
    'adopt_label' => TRUE,
    'purpose' => 'approval',
    'filename' => 'bewilligung-bewohnerparkausweis.docx',
    'blocks' => array_merge($letterhead, [
      ['text' => 'Gebührenbescheid über die Erteilung eines Bewohnerparkausweises', 'bold' => TRUE, 'size' => 28],
      $salutation,
      'auf Ihren Antrag vom {vorgang.eingang} erteilen wir Ihnen einen Bewohnerparkausweis für das Bewohnerparkgebiet {antrag.parkzone}.',
      [
        'table' => [
          ['Angabe', 'Wert'],
          ['Amtliches Kennzeichen', '{antrag.kennzeichen}'],
          ['Hersteller und Modell', '{antrag.fahrzeug_modell}'],
          ['Gültig ab', '{antrag.gewuenschter_beginn}'],
          ['Gültigkeitsdauer', '{antrag.gueltigkeitsdauer}'],
          ['Gebühr', '30,00 Euro'],
        ],
        'widths' => [3500, 5500],
      ],
      // The demo uses a fixed fee because the product does not calculate fees.
      'Für die Erteilung setzen wir eine Verwaltungsgebühr von 30,00 Euro fest. Bitte zahlen Sie den Betrag innerhalb eines Monats nach Bekanntgabe unter Angabe des Aktenzeichens.',
      'Der Ausweis gilt nur für das genannte Fahrzeug und das genannte Bewohnerparkgebiet. Bitte legen Sie ihn gut lesbar hinter die Windschutzscheibe.',
    ], $remedy, $signature, [
      'Dies ist ein Demonstrationsbescheid der Stadt Musterstadt. Er hat keine Rechtswirkung.',
    ]),
  ],
  'rejection' => [
    'label' => 'Ablehnungsbescheid Bewohnerparkausweis',
    'purpose' => 'rejection',
    'filename' => 'ablehnung-bewohnerparkausweis.docx',
    'blocks' => array_merge($letterhead, [
      ['text' => 'Bescheid über Ihren Antrag auf einen Bewohnerparkausweis', 'bold' => TRUE, 'size' => 28],
      $salutation,
      'über Ihren Antrag vom {vorgang.eingang} auf einen Bewohnerparkausweis für das Bewohnerparkgebiet {antrag.parkzone} und das Fahrzeug mit dem amtlichen Kennzeichen {antrag.kennzeichen} entscheiden wir wie folgt:',
      ['text' => 'Ihr Antrag wird abgelehnt.', 'bold' => TRUE],
      ['text' => 'Begründung', 'bold' => TRUE],
      $requirements . ' Wir haben Ihre Angaben, die eingereichten Unterlagen und Ihre Äußerung im Anhörungsverfahren geprüft. Danach sind die Voraussetzungen für die Erteilung nicht erfüllt.',
      'Für diese Entscheidung erheben wir keine Gebühr. Sie können jederzeit einen neuen Antrag stellen, wenn sich die Verhältnisse ändern.',
    ], $remedy, $signature, [
      'Dies ist ein Demonstrationsbescheid der Stadt Musterstadt. Er hat keine Rechtswirkung.',
    ]),
  ],
  'permit' => [
    'label' => 'Bewohnerparkausweis zum Auslegen',
    'purpose' => 'other',
    'filename' => 'bewohnerparkausweis-zum-auslegen.docx',
    'blocks' => [
      ['text' => 'Ihr Bewohnerparkausweis', 'bold' => TRUE, 'size' => 28],
      'Bitte schneiden Sie den Ausweis entlang des Rahmens aus und legen Sie ihn von außen gut lesbar hinter die Windschutzscheibe.',
      '',
      [
        'card' => [
          [['text' => 'BEWOHNERPARKAUSWEIS', 'bold' => TRUE, 'size' => 36] + $card_banner],
          // The issuing city; {organisation.name} would name the office.
          [['text' => 'Stadt Musterstadt', 'size' => 22] + $card_banner],
          [['text' => 'Bewohnerparkgebiet'] + $card_caption, ['text' => '{antrag.parkzone}'] + $card_large],
          [['text' => 'Amtliches Kennzeichen'] + $card_caption, ['text' => '{antrag.kennzeichen}'] + $card_large],
          [['text' => 'Fahrzeug'] + $card_caption, '{antrag.fahrzeug_modell}'],
          [['text' => 'Name'] + $card_caption, '{antrag.full_name}'],
          [['text' => 'Gültig ab'] + $card_caption, '{antrag.gewuenschter_beginn}'],
          [['text' => 'Gültigkeitsdauer'] + $card_caption, '{antrag.gueltigkeitsdauer}'],
          [['text' => 'Ausweisnummer'] + $card_caption, ['text' => '{vorgang.aktenzeichen}', 'bold' => TRUE]],
          [
            [
              'text' => 'Ausgestellt am {heute}. Gilt nur für das eingetragene Fahrzeug im eingetragenen Bewohnerparkgebiet.',
              'size' => 16,
              'align' => 'center',
              'fill' => 'E8EDF3',
              'span' => 2,
            ],
          ],
        ],
        // 14.8 cm wide, the width of a DIN A6 card in landscape.
        'widths' => [2900, 5500],
        'frame' => ['size' => 24, 'color' => '1F3A5F'],
        'lines' => ['size' => 4, 'color' => 'BFC8D4'],
        'padding' => 80,
      ],
      '',
      'Der Ausweis ist nicht übertragbar. Ändern sich Kennzeichen oder Wohnanschrift, beantragen Sie bitte einen neuen Ausweis.',
      'Dies ist ein Demonstrationsausweis der Stadt Musterstadt. Er hat keine Rechtswirkung.',
    ],
  ],
];

// Central branding: the Musterstadt logo as PNG (rendered once
// from the SVG by scripts/render-demo-logo-png.mjs, pinned by its manifest).
// The render path inserts it, never the template seed. The dark PNG is the
// same SVG in the dark colour scheme (theme.logoDarkDataUrl, screen only:
// mails and letterheads keep reading logoDataUrl).
$logo_dir = DRUPAL_ROOT . '/modules/custom/beantragt_group/assets';
$logo_png = @file_get_contents($logo_dir . '/musterstadt-demo-logo.png');
$logo_dark_png = @file_get_contents($logo_dir . '/musterstadt-demo-logo-dark.png');
$logo_manifest = json_decode((string) @file_get_contents($logo_dir . '/musterstadt-demo-logo.json'), TRUE);
if (!is_string($logo_png) || !is_array($logo_manifest) || hash('sha256', $logo_png) !== ($logo_manifest['pngSha256'] ?? NULL)) {
  throw new RuntimeException('The Musterstadt logo PNG is missing or does not match musterstadt-demo-logo.json.');
}
if (!is_string($logo_dark_png) || hash('sha256', $logo_dark_png) !== ($logo_manifest['darkPngSha256'] ?? NULL)) {
  throw new RuntimeException('The dark Musterstadt logo PNG is missing or does not match musterstadt-demo-logo.json.');
}
$branding = \Drupal::service('beantragt_group.jurisdiction_config');
$logo_values = ['logoDataUrl' => 'data:image/png;base64,' . base64_encode($logo_png), 'logoAlt' => 'Stadt Musterstadt'];
$theme = $branding->getSection($jurisdiction, 'theme');
if ($jurisdiction->get('field_slug')->value === 'musterstadt') {
  \Drupal::moduleHandler()->loadInclude('beantragt_group', 'install');
  $current_logo = (string) ($theme['logoDataUrl'] ?? '');
  // Replace only the install-time demo SVG; custom branding stays untouched.
  if (trim($current_logo) === '' || $current_logo === _beantragt_group_musterstadt_demo_logo_data_url()) {
    // mergeSection() saves a freshly loaded entity, so $jurisdiction still
    // carries the old blob and getSection($jurisdiction) would return the
    // install-time SVG. Its return value is the stored section. Reading the
    // stale object left the dark logo unset on every fresh install; only a
    // second run set it.
    $theme = $branding->mergeSection($jurisdiction, 'theme', $logo_values);
  }
  // The dark logo only pairs with the Musterstadt demo logo, never with a
  // logo someone uploaded. After the replacement above an empty logo or the
  // install-time SVG has become the demo PNG, so the PNG is the only demo
  // logo left to recognise.
  if (trim((string) ($theme['logoDarkDataUrl'] ?? '')) === '' && ($theme['logoDataUrl'] ?? NULL) === $logo_values['logoDataUrl']) {
    $branding->mergeSection($jurisdiction, 'theme', ['logoDarkDataUrl' => 'data:image/png;base64,' . base64_encode($logo_dark_png)]);
  }
}

$catalog = \Drupal::service('beantragt_docgen.template_catalog');
$client = \Drupal::service('beantragt_docgen.client');
$settings = \Drupal::service('beantragt_docgen.settings');
$variables = \Drupal::service('beantragt_docgen.form_templates')->variables($form);

/**
 * Derives the family UUID of a seeded template (UUID version 5 layout).
 */
$seed_family = static function (GroupInterface $scope, string $key): string {
  $hash = sha1('beantragt-demo-docgen:' . $scope->uuid() . ':' . $key);
  return sprintf(
    '%s-%s-5%s-%02x%s-%s',
    substr($hash, 0, 8),
    substr($hash, 8, 4),
    substr($hash, 13, 3),
    (hexdec(substr($hash, 16, 2)) & 0x3f) | 0x80,
    substr($hash, 18, 2),
    substr($hash, 20, 12),
  );
};

$seeded = [];
foreach ($templates as $key => $definition) {
  $label = $definition['label'];
  $bytes = DocxWriter::build($definition['blocks']);
  DocxPrecheck::assertDocx($bytes);
  $sha256 = hash('sha256', $bytes);

  $family = $seed_family($scope, $key);
  $existing = $catalog->latestVersion($family);
  if ($existing instanceof DocgenTemplateInterface && (int) $existing->getScopeGroup()?->id() !== (int) $scope->id()) {
    throw new RuntimeException(sprintf('Template family %s of %s belongs to another scope.', $family, $label));
  }
  if ($existing === NULL && !empty($definition['adopt_label'])) {
    foreach ($catalog->listForScope($scope) as $template) {
      if ((string) $template->label() === $label) {
        $existing = $catalog->latestVersion($template->getFamily());
        $family = $template->getFamily();
        break;
      }
    }
  }

  $released = $existing instanceof DocgenTemplateInterface && $existing->isReleased() && $existing->getSha256() === $sha256
    ? $existing
    : NULL;
  if ($released === NULL) {
    try {
      $inspection = $client->inspect($bytes);
      $source = 'docgen /inspect';
    }
    catch (DocgenApiException $exception) {
      // The generated documents have no loops; their tags are the brace
      // texts.
      preg_match_all('/\{([a-z][a-z0-9_.]*)\}/u', json_encode($definition['blocks'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $matches);
      $placeholders = array_values(array_unique($matches[1]));
      sort($placeholders);
      $inspection = ['placeholders' => $placeholders, 'loops' => [], 'errors' => []];
      $source = 'local fallback';
      fwrite(STDERR, sprintf("NOTICE: docgen not reachable (%s); placeholders of %s read locally.\n", $exception->errorCode, $label));
    }
    if ($inspection['errors'] !== []) {
      throw new RuntimeException(sprintf('The demo template %s has template errors: %s', $label, json_encode($inspection['errors'])));
    }
    $unknown = VariableCatalog::compare($inspection, $variables)['unknown'];
    if ($unknown !== []) {
      throw new RuntimeException(sprintf('The demo template %s uses placeholders the form does not provide: %s', $label, implode(', ', array_column($unknown, 'tag'))));
    }
    $directory = TemplateCatalog::DIRECTORY . '/' . (int) $scope->id();
    $file_system = \Drupal::service('file_system');
    if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new RuntimeException('Private file storage for docgen templates is unavailable.');
    }
    $file = \Drupal::service('file.repository')->writeData($bytes, $directory . '/' . \Drupal::service('uuid')->generate() . '.docx', FileExists::Error);
    $file->setFilename($definition['filename']);
    $file->setOwnerId((int) $admin->id());
    $file->setPermanent();
    $file->save();
    $template = $entity_type_manager->getStorage('docgen_template')->create([
      'family' => $family,
      'version' => ($existing?->getVersion() ?? 0) + 1,
      'label' => $label,
      'purpose' => $definition['purpose'],
      'status' => DocgenTemplateInterface::STATUS_DRAFT,
      'field_jurisdiction' => (int) $jurisdiction->id(),
      'field_organisation' => $scope->bundle() === 'org' ? (int) $scope->id() : NULL,
      'file' => ['target_id' => (int) $file->id()],
      'filename' => $definition['filename'],
      'sha256' => $sha256,
      'size' => strlen($bytes),
      'inspection' => json_encode($inspection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
      'uid' => (int) $admin->id(),
    ]);
    $template->save();
    assert($template instanceof DocgenTemplateInterface);
    if ($existing instanceof DocgenTemplateInterface && !$existing->isReleased()) {
      // An unreleased leftover of an earlier run is replaced, not kept.
      $catalog->deleteDraft($existing);
    }
    $released = $catalog->release($template, $admin);
    printf("Released demo template %s v%d (placeholders from %s).\n", $label, $released->getVersion(), $source);
  }
  else {
    printf("Demo template %s v%d unchanged.\n", $label, $released->getVersion());
  }
  $seeded[] = $released->getFamily();
}

$form_settings = $settings->formSettings((string) $form->uuid());
$families = array_values(array_unique(array_merge($form_settings['templates'], $seeded)));
// Delivery offer: portal or post, portal preselected (decisions go by post).
$delivery = ['routes' => ['portal', 'postal'], 'default' => 'portal'];
$settings->saveFormSettings((string) $form->uuid(), DocgenSettingsRepository::ROUTE_TEMPLATE, $families, (int) $admin->id(), $delivery);
$settings->setSenderAddress((int) $jurisdiction->id(), $sender_address);
printf("Residents parking form %s: decision route template, %d template(s) allowed, %d of them seeded, delivery portal or post; sender address of %s set.\n", $form->uuid(), count($families), count($seeded), $slug);
if (!$client->isConfigured()) {
  fwrite(STDERR, "NOTICE: docgen is not configured on this host; drafts and previews fail until BEANTRAGT_DOCGEN_BASE_URL and the token are set.\n");
}
