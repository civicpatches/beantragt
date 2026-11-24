<?php

/**
 * @file
 * Demo: the showcase forms A, B and C of Stadt Musterstadt with their content.
 *
 * Seeds from scripts/lib/showcase-form-catalog.php:
 * - form A "Sondernutzung für Außengastronomie beantragen" (Bürgerbüro,
 *   published, internet) and form B "Zuschuss für eine Vereinsveranstaltung
 *   beantragen" (Hauptamt, published, internet, login required), each with
 *   its own workflow built by flag from the office's catalogue
 *   (scripts/lib/DemoFormWorkflow.php);
 * - form C "Sperrmüll-Abholung anmelden" (Umweltamt, draft, internet, never
 *   published by this seed; the draft example for the editor);
 * - three leaflets in the form sidebar and as context sources (form A's precheck
 *   quotes its leaflet);
 * - Info-Abschnitte for the form intro, sidebar and success page of A and B,
 *   org_start of the Hauptamt and one jurisdiction-wide sidebar contact;
 * - three Textbausteine, one per type (status_notes, service_provider,
 *   remarks).
 *
 * Every form carries a source key starting "seed:showcase:" (exempt from the
 * exclusive product-smoke catalogue). Nodes are found by a UUID derived from
 * their seed key, so content made by hand under another UUID is never
 * adopted. The seed owns its own forms and nodes: a rerun restores the
 * seeded state and discards manual edits to them. An item that already
 * equals the catalogue is not written at all, so a plain rerun leaves A and
 * B online. When a published form differs, it is briefly a draft while it is
 * rewritten and then published again.
 * Nothing here calls docgen, an AI provider or FIT-Connect, and the seed
 * never calls the RAG indexer itself. Publishing a form with context sources
 * makes the optional context module queue its usual rebuild job for that form, as any
 * publish does; the job runs against the site's own RAG service. Decision
 * templates and the decision route follow in their own seed.
 *
 * The seed checks every precondition and validates every payload before its
 * first write. A rerun reports every item "unchanged".
 *
 * The seed never deletes. The former form C "Meldung zur Mutterschutzliste"
 * (source key seed:showcase:musterstadt:maternity-protection-notice) left the
 * catalogue; an instance installed before keeps that draft, and the seed
 * only prints a one-line notice when it finds it. A reinstall is the way to
 * get rid of it.
 *
 * Runs after scripts/seed-demo-docgen.php (scripts/install-public-demo.sh,
 * scripts/setup-local.sh --with-product-smoke). It
 * needs BEANTRAGT_ALLOW_SHOWCASE_SEED=1 everywhere, in DDEV too: every
 * worktree's DDEV configuration names the main instance, so a hand-typed
 * drush call must not reach it; the installers pass the flag. It always
 * needs the Musterstadt example data.
 */

declare(strict_types=1);

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

require_once __DIR__ . '/lib/DemoFormWorkflow.php';
require_once __DIR__ . '/lib/showcase-form-catalog.php';

beantragt_showcase_assert_runtime();
$entity_type_manager = \Drupal::entityTypeManager();

// Preconditions, all before the first write.
$jurisdiction = beantragt_showcase_jurisdiction($entity_type_manager);
$form_definitions = beantragt_showcase_form_definitions();
$local_example = getenv('BEANTRAGT_EXAMPLE_DATA') === '1';
if ($local_example) {
  // The release example has no external analysis or case system configured.
  $form_definitions['club-event-grant']['workflow_flags'] = ['approved', 'rejected'];
  $form_definitions['bulky-waste-collection']['publish'] = TRUE;
}
$organisations = [];
foreach ($form_definitions as $definition) {
  $organisations[$definition['organisation']] = NULL;
}
foreach (beantragt_showcase_content_section_definitions() as $section) {
  if (isset($section['org'])) {
    $organisations[$section['org']] = NULL;
  }
}
foreach (array_keys($organisations) as $slug) {
  $organisations[$slug] = beantragt_showcase_organisation($entity_type_manager, $jurisdiction, (string) $slug);
}
beantragt_showcase_assert_content_model($entity_type_manager);
$workflows = [];
foreach ($form_definitions as $key => $definition) {
  $workflows[$key] = $definition['workflow_flags'] === NULL
    ? NULL
    : DemoFormWorkflow::definition($entity_type_manager, (int) $organisations[$definition['organisation']]->id(), $definition['workflow_flags']);
}
$placeholders = beantragt_showcase_placeholder_sources();
foreach (array_keys($form_definitions) as $key) {
  beantragt_showcase_validate_payload(beantragt_showcase_runtime_payload($local_example, $key, $placeholders), $key);
}
$owner = $entity_type_manager->getStorage('user')->load(1);
if (!$owner instanceof UserInterface) {
  throw new RuntimeException('The showcase forms need UID 1 as owner.');
}

$results = [];
$record = static function (string $kind, string $key, string $label, string $status) use (&$results): void {
  $id = $kind . ':' . $key;
  // A form created and then bound to its leaflets in the same run counts as
  // created; one updated twice counts once.
  $rank = ['unchanged' => 0, 'updated' => 1, 'created' => 2];
  if (!isset($results[$id]) || $rank[$status] > $rank[$results[$id]['status']]) {
    $results[$id] = ['kind' => $kind, 'key' => $key, 'label' => $label, 'status' => $status];
  }
};

// Pass 1: documents are bound to a form, so a missing form is created first,
// as a draft. The context module refuses to publish a precheck whose source
// is not available yet (when enabled); a
// document's UUID is known in advance, its version and changed time follow
// in pass 2. Existing forms are left alone until their documents are right.
$forms = [];
foreach ($form_definitions as $key => $definition) {
  $existing = beantragt_showcase_find_form($entity_type_manager, $jurisdiction, $key);
  if ($existing instanceof FormSchemaInterface) {
    $forms[$key] = $existing;
    continue;
  }
  [$forms[$key], $status] = beantragt_showcase_ensure_form(
    $entity_type_manager,
    $jurisdiction,
    $organisations[$definition['organisation']],
    $owner,
    $key,
    ['publish' => FALSE] + $definition,
    beantragt_showcase_runtime_payload($local_example, $key, beantragt_showcase_stored_sources($entity_type_manager, $placeholders)),
    $workflows[$key],
  );
  $record('form', $key, $definition['title'], $status);
}

// Documents, bound to their forms.
$weight = 100;
foreach (beantragt_showcase_document_definitions() as $key => $document) {
  $status = beantragt_showcase_ensure_document($entity_type_manager, $jurisdiction, $forms[$document['form']], $key, $document, $weight);
  $record('document', $key, $document['title_de'], $status);
  $weight += 10;
}

// Pass 2: context source metadata of the stored documents.
foreach ($form_definitions as $key => $definition) {
  $sources = beantragt_showcase_stored_sources($entity_type_manager, $placeholders);
  [$forms[$key], $status] = beantragt_showcase_ensure_form(
    $entity_type_manager,
    $jurisdiction,
    $organisations[$definition['organisation']],
    $owner,
    $key,
    $definition,
    beantragt_showcase_runtime_payload($local_example, $key, $sources),
    $workflows[$key],
  );
  $record('form', $key, $definition['title'], $status);
}

foreach (beantragt_showcase_content_section_definitions() as $key => $section) {
  $status = beantragt_showcase_ensure_node(
    $entity_type_manager,
    'content_section',
    beantragt_showcase_uuid('section:' . $key),
    [
      'field_jurisdiction' => (int) $jurisdiction->id(),
      'field_organisation' => isset($section['org']) ? (int) $organisations[$section['org']]->id() : NULL,
      'field_form_schema' => isset($section['form']) ? (int) $forms[$section['form']]->id() : NULL,
      'field_content_placement' => $section['placement'],
      'field_content_weight' => $section['weight'],
    ],
    $section,
  );
  $record('content section', $key, $section['title_de'], $status);
}

foreach (beantragt_showcase_boilerplate_definitions() as $key => $boilerplate) {
  $status = beantragt_showcase_ensure_node(
    $entity_type_manager,
    'boilerplate',
    beantragt_showcase_uuid('boilerplate:' . $key),
    [
      'field_jurisdiction' => (int) $jurisdiction->id(),
      'field_organisation' => NULL,
      'field_boilerplate_type' => $boilerplate['type'],
    ],
    $boilerplate,
  );
  $record('boilerplate', $key, $boilerplate['title_de'], $status);
}

$counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
foreach ($results as $result) {
  printf("%s showcase %s %s: %s.\n", ucfirst($result['status']), $result['kind'], $result['key'], $result['label']);
  $counts[$result['status']]++;
}
foreach ($forms as $key => $form) {
  printf("SHOWCASE_FORM %s %s %s /musterstadt/form/%s\n", $key, $form->getSchemaStatus(), (string) $form->get('field_publication_scope')->value, strtolower((string) $form->uuid()));
}
beantragt_showcase_report_retired_forms($entity_type_manager, $jurisdiction);
printf("Showcase seed: %d created, %d updated, %d unchanged.\n", $counts['created'], $counts['updated'], $counts['unchanged']);

/**
 * Prints one notice per retired showcase form still present; never writes.
 *
 * The seed never deletes: an instance installed before a form left the
 * catalogue keeps it, and a reinstall removes it.
 */
function beantragt_showcase_report_retired_forms(EntityTypeManagerInterface $entity_type_manager, GroupInterface $jurisdiction): void {
  $retired = [
    'maternity-protection-notice' => 'Meldung zur Mutterschutzliste',
  ];
  foreach ($retired as $key => $title) {
    $ids = $entity_type_manager->getStorage('form_schema')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', beantragt_showcase_source_prefix() . $key)
      ->execute();
    if ($ids !== []) {
      printf("Notice: the retired showcase form %s (%s) is still here as form %s; the seed leaves it untouched, a reinstall removes it.\n", $key, $title, implode(', ', $ids));
    }
  }
}

/**
 * Refuses to run outside a demo.
 */
function beantragt_showcase_assert_runtime(): void {
  // Required in DDEV too: every worktree's DDEV configuration names the main
  // local instance.
  if (getenv('BEANTRAGT_ALLOW_SHOWCASE_SEED') !== '1') {
    throw new RuntimeException('Refusing the showcase seed without BEANTRAGT_ALLOW_SHOWCASE_SEED=1 (required in DDEV too; the installers pass it).');
  }
}

/**
 * Loads the Musterstadt jurisdiction.
 */
function beantragt_showcase_jurisdiction(EntityTypeManagerInterface $entity_type_manager): GroupInterface {
  $ids = $entity_type_manager->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'jur')
    ->condition('field_slug', 'musterstadt')
    ->range(0, 2)
    ->execute();
  if (count($ids) !== 1) {
    throw new RuntimeException(sprintf('The showcase seed needs exactly one Musterstadt jurisdiction, found %d.', count($ids)));
  }
  $jurisdiction = $entity_type_manager->getStorage('group')->load(reset($ids));
  if (!$jurisdiction instanceof GroupInterface) {
    throw new RuntimeException('The Musterstadt jurisdiction could not be loaded.');
  }
  return $jurisdiction;
}

/**
 * Loads one Musterstadt organisation by slug.
 */
function beantragt_showcase_organisation(EntityTypeManagerInterface $entity_type_manager, GroupInterface $jurisdiction, string $slug): GroupInterface {
  $ids = $entity_type_manager->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'org')
    ->condition('field_slug', $slug)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->range(0, 2)
    ->execute();
  if (count($ids) !== 1) {
    throw new RuntimeException(sprintf('The showcase seed needs exactly one Musterstadt organisation %s, found %d; run scripts/seed-demo-data.php first.', $slug, count($ids)));
  }
  $organisation = $entity_type_manager->getStorage('group')->load(reset($ids));
  if (!$organisation instanceof GroupInterface) {
    throw new RuntimeException(sprintf('Organisation %s could not be loaded.', $slug));
  }
  return $organisation;
}

/**
 * Checks content types, their fields and both languages.
 */
function beantragt_showcase_assert_content_model(EntityTypeManagerInterface $entity_type_manager): void {
  $fields = [
    'public_document' => [
      'body',
      'field_jurisdiction',
      'field_organisation',
      'field_form_schema',
      'field_public_document_file',
      'field_content_placement',
      'field_content_weight',
    ],
    'content_section' => [
      'body',
      'field_jurisdiction',
      'field_organisation',
      'field_form_schema',
      'field_content_placement',
      'field_content_weight',
    ],
    'boilerplate' => ['body', 'field_jurisdiction', 'field_organisation', 'field_boilerplate_type'],
  ];
  $field_manager = \Drupal::service('entity_field.manager');
  foreach ($fields as $bundle => $names) {
    if ($entity_type_manager->getStorage('node_type')->load($bundle) === NULL) {
      throw new RuntimeException(sprintf('Content type %s is missing; enable beantragt_content.', $bundle));
    }
    $definitions = $field_manager->getFieldDefinitions('node', $bundle);
    foreach ($names as $name) {
      if (!isset($definitions[$name])) {
        throw new RuntimeException(sprintf('Field %s of content type %s is missing.', $name, $bundle));
      }
    }
  }
  foreach (['de', 'en'] as $langcode) {
    if (\Drupal::languageManager()->getLanguage($langcode) === NULL) {
      throw new RuntimeException(sprintf('Language %s is missing.', $langcode));
    }
  }
  if (!$entity_type_manager->hasDefinition('form_schema') || !$entity_type_manager->hasDefinition('workflow_status')) {
    throw new RuntimeException('Form schemas or workflow statuses are not available.');
  }
}

/**
 * Builds a self-contained payload for the local example.
 */
function beantragt_showcase_runtime_payload(bool $local_example, string $key, array $sources): array {
  $payload = beantragt_showcase_form_payload($key, $sources);
  if (!$local_example) {
    return $payload;
  }
  unset($payload['contextSources'], $payload['precheckSchema']);
  foreach ($payload['sections'] as &$section) {
    foreach ($section['fields'] as &$field) {
      unset($field['analysis']);
    }
    unset($field);
  }
  unset($section);
  return $payload;
}

/**
 * Validates a payload with the same guards as a published form.
 *
 * A draft is held to the published rules as well, so it can be published
 * later without changes.
 */
function beantragt_showcase_validate_payload(array $payload, string $key): void {
  $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  try {
    FormSchema::validateFormSchemaJson($json);
    FormSchema::validateFormSchemaDefinition($json);
    $normalized = FormSchema::normalizeSchemaPrivacyKinds($json);
    FormSchema::validatePublishedFormSchemaShape($normalized);
    FormSchema::validatePublishableFormSchemaDefinition($normalized);
  }
  catch (InvalidArgumentException $exception) {
    throw new RuntimeException(sprintf('Showcase form %s failed FormSchema validation: %s', $key, $exception->getMessage()), 0, $exception);
  }
}

/**
 * Context source metadata of the stored showcase documents.
 *
 * A document not stored yet keeps its placeholder: same UUID and title,
 * version and changed time follow once it exists.
 *
 * @return array<string, array{id: string, title: string, version: string, changed: int}>
 *   Sources per document key.
 */
function beantragt_showcase_stored_sources(EntityTypeManagerInterface $entity_type_manager, array $placeholders): array {
  $sources = $placeholders;
  $storage = $entity_type_manager->getStorage('node');
  $storage->resetCache();
  foreach (array_keys($placeholders) as $key) {
    $nodes = $storage->loadByProperties(['uuid' => $placeholders[$key]['id']]);
    $node = reset($nodes);
    if ($node instanceof NodeInterface) {
      $sources[$key] = [
        'id' => strtolower((string) $node->uuid()),
        'title' => (string) $node->label(),
        'version' => sprintf('revision:%d:changed:%d', (int) $node->getRevisionId(), $node->getChangedTime()),
        'changed' => $node->getChangedTime(),
      ];
    }
  }
  return $sources;
}

/**
 * Finds a showcase form by its source key.
 */
function beantragt_showcase_find_form(EntityTypeManagerInterface $entity_type_manager, GroupInterface $jurisdiction, string $key): ?FormSchemaInterface {
  $storage = $entity_type_manager->getStorage('form_schema');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', beantragt_showcase_source_prefix() . $key)
    ->range(0, 2)
    ->execute();
  if (count($ids) > 1) {
    throw new RuntimeException(sprintf('Showcase form %s is duplicated.', $key));
  }
  if ($ids === []) {
    return NULL;
  }
  $storage->resetCache([(int) reset($ids)]);
  $form = $storage->load(reset($ids));
  if (!$form instanceof FormSchemaInterface) {
    throw new RuntimeException(sprintf('Showcase form %s is invalid.', $key));
  }
  return $form;
}

/**
 * Creates or updates one showcase form; leaves an equal one untouched.
 *
 * Schema and workflow change only on a draft, like the editor: a published
 * form gets a draft revision first, then the target, then publication.
 *
 * @return array{0: \Drupal\beantragt_schema\Entity\FormSchemaInterface, 1: string}
 *   The form and created, updated or unchanged.
 */
function beantragt_showcase_ensure_form(
  EntityTypeManagerInterface $entity_type_manager,
  GroupInterface $jurisdiction,
  GroupInterface $organisation,
  UserInterface $owner,
  string $key,
  array $definition,
  array $payload,
  ?array $workflow,
): array {
  $storage = $entity_type_manager->getStorage('form_schema');
  $source_filename = beantragt_showcase_source_prefix() . $key;
  $form = beantragt_showcase_find_form($entity_type_manager, $jurisdiction, $key)
    ?? $storage->create(['type' => 'form_schema']);
  if (!$form instanceof FormSchemaInterface) {
    throw new RuntimeException(sprintf('Showcase form %s is invalid.', $key));
  }
  $is_new = $form->isNew();
  $publish = (bool) $definition['publish'];
  // An explicit scope wins (form C, a citizen service kept as a draft);
  // otherwise a published form is internet and a draft intranet.
  $scope = $definition['scope'] ?? ($publish ? 'internet' : 'intranet');
  $stored_payload = $is_new ? NULL : json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
  $stored_workflow = $is_new ? NULL : $form->get('field_workflow_definition')->value;
  $workflow_matches = $workflow === NULL
    ? ($stored_workflow === NULL || $stored_workflow === '')
    : DemoFormWorkflow::same($stored_workflow, $workflow);
  $changed = $is_new
    || $stored_payload !== $payload
    || !$workflow_matches
    || (string) $form->label() !== $definition['title']
    || (string) ($form->get('field_description')->value ?? '') !== $definition['description']
    || (string) ($form->get('field_source_filename')->value ?? '') !== $source_filename
    || $form->getSchemaStatus() !== ($publish ? 'published' : 'draft')
    || $form->isPublished() !== $publish
    || (string) ($form->get('field_publication_scope')->value ?? '') !== $scope
    || (int) ($form->get('field_jurisdiction')->target_id ?? 0) !== (int) $jurisdiction->id()
    || (int) ($form->get('field_organisation')->target_id ?? 0) !== (int) $organisation->id()
    || (int) ($form->get('field_group')->target_id ?? 0) !== (int) $organisation->id();
  if (!$changed) {
    return [$form, 'unchanged'];
  }

  if (!$is_new && $form->getSchemaStatus() !== 'draft') {
    $form->set('field_workflow_status', 'draft');
    $form->set('status', FALSE);
    $form->save();
    $form_id = (int) $form->id();
    $storage->resetCache([$form_id]);
    $form = $storage->load($form_id);
    if (!$form instanceof FormSchemaInterface) {
      throw new RuntimeException(sprintf('Showcase form %s could not be reloaded as a draft.', $key));
    }
  }

  $form->set('label', $definition['title']);
  $form->set('field_description', $definition['description']);
  $form->set('field_schema', json_encode(
    $payload,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  ));
  $form->set('field_source_filename', $source_filename);
  $form->set('field_workflow_definition', $workflow === NULL ? NULL : DemoFormWorkflow::encode($workflow));
  $form->set('field_workflow_status', 'draft');
  $form->set('field_publication_scope', $scope);
  $form->setOwnerId((int) $owner->id());
  $form->set('field_jurisdiction', ['target_id' => $jurisdiction->id()]);
  $form->set('field_organisation', ['target_id' => $organisation->id()]);
  $form->set('field_group', ['target_id' => $organisation->id()]);
  $form->set('status', FALSE);
  $form->save();

  if ($publish) {
    $form->set('field_workflow_status', 'published');
    $form->set('status', TRUE);
    $form->save();
  }
  return [$form, $is_new ? 'created' : 'updated'];
}

/**
 * Creates or updates one leaflet: its PDF file and its public document.
 *
 * @return string
 *   Created, updated or unchanged.
 */
function beantragt_showcase_ensure_document(
  EntityTypeManagerInterface $entity_type_manager,
  GroupInterface $jurisdiction,
  FormSchemaInterface $form,
  string $key,
  array $document,
  int $weight,
): string {
  $bytes = beantragt_showcase_pdf($document['pages']);
  // Flat: the context source resolver reads only files directly inside
  // private://public_documents (PublicDocumentPathResolver).
  $uri = 'private://public_documents/' . $document['filename'];
  [$file, $file_status] = beantragt_showcase_ensure_file($entity_type_manager, $uri, $bytes);
  $node_status = beantragt_showcase_ensure_node(
    $entity_type_manager,
    'public_document',
    beantragt_showcase_uuid('document:' . $key),
    [
      'field_jurisdiction' => (int) $jurisdiction->id(),
      'field_organisation' => NULL,
      'field_form_schema' => (int) $form->id(),
      'field_public_document_file' => (int) $file->id(),
      'field_content_placement' => $document['placement'],
      'field_content_weight' => $weight,
    ],
    $document,
  );
  if ($node_status !== 'unchanged') {
    return $node_status;
  }
  return $file_status;
}

/**
 * Writes one private PDF file entity unless its bytes are already there.
 *
 * @return array{0: \Drupal\file\FileInterface, 1: string}
 *   The file and created, updated or unchanged.
 */
function beantragt_showcase_ensure_file(EntityTypeManagerInterface $entity_type_manager, string $uri, string $bytes): array {
  $file_system = \Drupal::service('file_system');
  $directory = dirname($uri);
  $matches = $entity_type_manager->getStorage('file')->loadByProperties(['uri' => $uri]);
  if (count($matches) > 1) {
    throw new RuntimeException(sprintf('Showcase file %s is duplicated.', $uri));
  }
  $file = reset($matches);
  if ($file instanceof FileInterface) {
    $realpath = $file_system->realpath($uri);
    if (is_string($realpath) && is_file($realpath) && file_get_contents($realpath) === $bytes) {
      return [$file, 'unchanged'];
    }
  }
  if (!$file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
    throw new RuntimeException(sprintf('Unable to prepare %s.', $directory));
  }
  if ($file instanceof FileInterface) {
    $file_system->saveData($bytes, $uri, FileExists::Replace);
    $file->setSize(strlen($bytes));
    $file->setMimeType('application/pdf');
    $file->setPermanent();
    $file->save();
    return [$file, 'updated'];
  }
  $file = \Drupal::service('file.repository')->writeData($bytes, $uri, FileExists::Replace);
  $file->setMimeType('application/pdf');
  $file->setPermanent();
  $file->save();
  return [$file, 'created'];
}

/**
 * Creates or updates one German node with its English translation.
 *
 * @param array<string, int|string|null> $fields
 *   Target values: an integer for an entity reference, a string or integer
 *   for a plain value, NULL for an empty field.
 * @param array<string, mixed> $text
 *   Keys title_de, body_de, title_en and body_en.
 *
 * @return string
 *   Created, updated or unchanged.
 */
function beantragt_showcase_ensure_node(EntityTypeManagerInterface $entity_type_manager, string $bundle, string $uuid, array $fields, array $text): string {
  $storage = $entity_type_manager->getStorage('node');
  $nodes = $storage->loadByProperties(['uuid' => $uuid]);
  $node = reset($nodes);
  if ($node instanceof NodeInterface && $node->bundle() !== $bundle) {
    throw new RuntimeException(sprintf('Node %s is a %s, not a %s.', $uuid, $node->bundle(), $bundle));
  }
  $is_new = !$node instanceof NodeInterface;
  if ($is_new) {
    $node = $storage->create([
      'type' => $bundle,
      'uuid' => $uuid,
      'langcode' => 'de',
      'uid' => 1,
    ]);
  }

  $changed = $is_new
    || !$node->isPublished()
    || $node->language()->getId() !== 'de'
    || !beantragt_showcase_text_matches($node, $text['title_de'], $text['body_de'])
    || !$node->hasTranslation('en')
    || !beantragt_showcase_text_matches($node->getTranslation('en'), $text['title_en'], $text['body_en']);
  foreach ($fields as $name => $target) {
    $item = $node->get($name);
    $current = $item->isEmpty()
      ? NULL
      : ($item->getFieldDefinition()->getType() === 'entity_reference' || $item->getFieldDefinition()->getType() === 'file'
        ? (int) $item->target_id
        : $item->value);
    if ($target === NULL ? $current !== NULL : (string) $current !== (string) $target) {
      $changed = TRUE;
    }
  }
  if (!$changed) {
    return 'unchanged';
  }

  $node->setTitle($text['title_de']);
  $node->set('body', ['value' => $text['body_de'], 'format' => 'basic_html']);
  foreach ($fields as $name => $target) {
    $is_reference = in_array($node->get($name)->getFieldDefinition()->getType(), ['entity_reference', 'file'], TRUE);
    $node->set($name, $target === NULL ? NULL : ($is_reference ? ['target_id' => $target] : $target));
  }
  $node->setPublished();
  $translation = $node->hasTranslation('en') ? $node->getTranslation('en') : $node->addTranslation('en');
  $translation->setTitle($text['title_en']);
  $translation->set('body', ['value' => $text['body_en'], 'format' => 'basic_html']);
  $translation->setPublished();
  $node->save();
  return $is_new ? 'created' : 'updated';
}

/**
 * Whether one language version carries the given title and basic HTML body.
 */
function beantragt_showcase_text_matches(NodeInterface $node, string $title, string $body): bool {
  return (string) $node->label() === $title
    && (string) ($node->get('body')->value ?? '') === $body
    && (string) ($node->get('body')->format ?? '') === 'basic_html'
    && $node->isPublished();
}
