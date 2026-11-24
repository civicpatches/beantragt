<?php

/**
 * @file
 * Demo: Bertha Bürger asks for a new residents parking permit for a new car.
 *
 * "Im Demo machen wir alles möglich": the docgen demo needs a parking
 * application that is waiting for its decision, so staff can draft the fee
 * notice and the permit from the city's Word templates, preview and release
 * them. MS-BB-2026-99101 is the consent fixture of seed-demo-consents.php and
 * stays as it is (the small car); this script adds MS-BB-2026-99102 for the
 * bigger car, status "Eingegangen", without documents.
 *
 * The application is inserted unconfirmed and confirmed afterwards, like the
 * consent fixture, so no insert integration (mail, routing, delivery, profile
 * sync) runs. It carries no consent. Rerunning keeps an existing application
 * unchanged, also after staff worked on it during a demo.
 *
 * Licence plates use the unassigned district code MSTD: German district
 * codes have at most three letters, so no real vehicle carries these plates.
 *
 * Runs after scripts/seed-demo-consents.php (scripts/setup-local.sh
 * --with-product-smoke, scripts/install-public-demo.sh).
 *
 * Environment variables:
 * - BEANTRAGT_DEMO_PARKING_JURISDICTION_SLUG, default musterstadt.
 */

declare(strict_types=1);

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

require_once __DIR__ . '/lib/DemoCaseReference.php';

$persona_email = $example_citizen_email ?? 'buerger@example.invalid';
$form_source = 'seed:product-smoke:musterstadt:residents-parking-permit';

$entity_type_manager = \Drupal::entityTypeManager();
$slug = trim((string) (getenv('BEANTRAGT_DEMO_PARKING_JURISDICTION_SLUG') ?: 'musterstadt'));

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

$users = $entity_type_manager->getStorage('user')->loadByProperties(['mail' => $persona_email]);
$user = reset($users);
if (!$user instanceof UserInterface) {
  throw new RuntimeException('The demo citizen ' . $persona_email . ' is missing; run scripts/seed-demo-data.php first.');
}

$form_ids = $entity_type_manager->getStorage('form_schema')->getQuery()
  ->accessCheck(FALSE)
  ->condition('field_jurisdiction', (int) $jurisdiction->id())
  ->condition('field_source_filename', $form_source)
  ->condition('field_workflow_status', 'published')
  ->range(0, 2)
  ->execute();
if (count($form_ids) !== 1) {
  throw new RuntimeException(sprintf('Expected the published residents parking form of %s once, found %d; run scripts/seed-product-smoke.php first.', $slug, count($form_ids)));
}
$form = $entity_type_manager->getStorage('form_schema')->load(reset($form_ids));
if (!$form instanceof FormSchemaInterface) {
  throw new RuntimeException('The residents parking form could not be loaded.');
}

$application_storage = $entity_type_manager->getStorage('application');
$cases = isset($example_citizen_email)
  ? ['MS-BB-2026-99102' => 'eingegangen']
  : [
    'MS-BB-2026-99102' => 'eingegangen',
    'MS-BB-2026-99103' => 'in_bearbeitung',
    'MS-BB-2026-99104' => 'genehmigt',
  ];
if (!isset($example_citizen_email) && getenv('BEANTRAGT_EXAMPLE_DATA') !== '1') {
  throw new RuntimeException('The local example data is disabled.');
}

$now = \Drupal::time()->getRequestTime();
$data = [
  'full_name' => 'Bertha Bürger',
  'email' => $persona_email,
  'wohnanschrift' => 'Marktplatz 1, 12345 Musterstadt',
  'postleitzahl' => '12345',
  'parkzone' => 'Innenstadt A',
  'hauptwohnsitz_in_zone' => TRUE,
  'privater_stellplatz' => FALSE,
  'gewuenschter_beginn' => date('Y-m-d', $now),
  'gueltigkeitsdauer' => '12 Monate',
  'kennzeichen' => 'MSTD-B 202',
  'fahrzeug_modell' => 'Kombi',
  'fahrzeugbezug' => 'Auf mich zugelassen',
  'angaben_bestaetigt' => TRUE,
];

// A jurisdiction-direct form routes its applications to the configured
// default organisation, exactly as the public submission does.
$sink = NULL;
$sink_slug = (string) (\Drupal::config('beantragt_group.settings')->get('default_organisation_slug') ?? '');
if ($sink_slug !== '' && $form->get('field_organisation')->isEmpty()) {
  foreach ($entity_type_manager->getStorage('group')->loadByProperties(['type' => 'org', 'field_slug' => $sink_slug]) as $candidate) {
    if ($candidate instanceof GroupInterface && $candidate->isPublished()
      && (int) ($candidate->get('field_jurisdiction')->target_id ?? 0) === (int) $jurisdiction->id()) {
      $sink = $candidate;
      break;
    }
  }
}

foreach ($cases as $reference => $status) {
  $existing = DemoCaseReference::find($application_storage, $reference, $reference === 'MS-BB-2026-99102' ? ['MS-BP-DEMO-0102'] : []);
  if ($existing !== NULL) {
    DemoCaseReference::assertCase($existing, $form, $user);
    DemoCaseReference::rename($existing, $reference);
    printf("Demo parking case %s kept.\n", $reference);
    continue;
  }

  $case_data = $data;
  $case_data['kennzeichen'] = 'MSTD-B ' . (100 + (int) substr($reference, -3));
  $transaction = \Drupal::database()->startTransaction();
  try {
    $application = $application_storage->create([
      'field_schema' => ['target_id' => $form->id()],
      'field_schema_version' => $form->getVersion(),
      'field_data' => json_encode($case_data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
      'field_applicant_email' => $persona_email,
      'field_reference_number' => $reference,
      // Insert as pending so no insert integration runs (mail, routing,
      // delivery, profile sync); confirmed below without a lifecycle event.
      'field_email_confirmed' => FALSE,
      'uid' => $user->id(),
      'created' => $now,
      'status' => TRUE,
    ]);
    if (!$application instanceof ApplicationInterface) {
      throw new RuntimeException('Could not create the parking application.');
    }
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      if ($application->hasField($field_name) && $form->hasField($field_name) && !$form->get($field_name)->isEmpty()) {
        $application->set($field_name, $form->get($field_name)->getValue());
      }
    }
    if ($sink instanceof GroupInterface && $application->hasField('field_organisation')) {
      $application->set('field_organisation', ['target_id' => $sink->id()]);
    }
    if (!isset($example_citizen_email)) {
      $application->setWorkflowStatus($status);
    }
    if (!isset($example_citizen_email) && $application->getWorkflowStatus() !== $status) {
      throw new RuntimeException('Cannot resolve the example status ' . $status . '.');
    }
    $application->save();
    $application->set('field_email_confirmed', TRUE);
    if ($application->hasField('field_email_confirmed_time')) {
      $application->set('field_email_confirmed_time', $now);
    }
    // Each subsequent application save creates a revision.
    $application->save();

    printf("Demo parking case %s created (%s, %s).\n", $reference, $case_data['kennzeichen'], $case_data['fahrzeug_modell']);
    unset($transaction);
  }
  catch (Throwable $exception) {
    $transaction->rollBack();
    throw $exception;
  }
}
