<?php

/**
 * @file
 * Seeds the bilingual starter forms for a new tenant or the public demo.
 *
 * Environment variables:
 * - BEANTRAGT_STARTER_JURISDICTION_SLUG, default tenant-reserved.
 * - BEANTRAGT_STARTER_JURISDICTION_LABEL, default Tenant (reserviert).
 * - BEANTRAGT_STARTER_MODE, golden or demo, default golden.
 * - BEANTRAGT_STARTER_PUBLISH, 1 publishes the forms.
 * - BEANTRAGT_STARTER_EXTERNAL_ANALYSIS, 1 enables consent-gated cloud checks.
 * - BEANTRAGT_STARTER_CREATE_JURISDICTION, 1 permits a missing demo target.
 * - BEANTRAGT_STARTER_CLAIM_RESERVED_TENANT, 1 renames a pristine placeholder.
 * - BEANTRAGT_STARTER_FORCE_UPDATE, 1 overwrites a drifted starter form.
 * - BEANTRAGT_STARTER_PRESERVE_CONTEXT_SOURCES, 1 preserves selected RAG
 *   sources and their reviewed precheck criteria during a force update.
 * - BEANTRAGT_STARTER_JURISDICTION_EMAIL, target mailbox.
 * - BEANTRAGT_ALLOW_STARTER_FORM_SEED, required outside DDEV.
 */

declare(strict_types=1);

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

require_once __DIR__ . '/lib/starter-form-catalog.php';
require_once __DIR__ . '/lib/DemoFormWorkflow.php';

if (defined('BEANTRAGT_SEED_PAYLOAD_VALIDATION')) {
  return;
}

$entity_type_manager = \Drupal::entityTypeManager();
$slug = trim((string) (getenv('BEANTRAGT_STARTER_JURISDICTION_SLUG') ?: 'tenant-reserved'));
$label = trim((string) (getenv('BEANTRAGT_STARTER_JURISDICTION_LABEL') ?: 'Tenant (reserviert)'));
$mode = trim((string) (getenv('BEANTRAGT_STARTER_MODE') ?: 'golden'));
$publish = getenv('BEANTRAGT_STARTER_PUBLISH') === '1';
$external_analysis = getenv('BEANTRAGT_STARTER_EXTERNAL_ANALYSIS') === '1';
$allow_create = getenv('BEANTRAGT_STARTER_CREATE_JURISDICTION') === '1';
$claim_reserved = getenv('BEANTRAGT_STARTER_CLAIM_RESERVED_TENANT') === '1';
$force_update = getenv('BEANTRAGT_STARTER_FORCE_UPDATE') === '1';
$mail = trim((string) (getenv('BEANTRAGT_STARTER_JURISDICTION_EMAIL')
  ?: sprintf('service@%s.example.org', str_replace('-', '.', $slug))));

beantragt_starter_assert_runtime($slug, $label, $mail, $mode, $publish, $external_analysis, $claim_reserved);
$lock = \Drupal::lock();
$lock_name = 'beantragt:starter-seed';
if (!$lock->acquire($lock_name, 120.0)) {
  throw new RuntimeException('Another starter seed or tenant claim is already running.');
}
$transaction = \Drupal::database()->startTransaction('beantragt_starter_seed');
try {
  $jurisdiction = beantragt_starter_ensure_jurisdiction(
    $entity_type_manager,
    $slug,
    $label,
    $mail,
    $allow_create || $slug === 'tenant-reserved',
    $claim_reserved,
  );
  beantragt_starter_ensure_demo_mailbox($jurisdiction, $mail, $mode, $force_update);
  $owner = $entity_type_manager->getStorage('user')->load(1);
  if (!$owner instanceof UserInterface) {
    throw new RuntimeException('User 1 is missing. Install the Drupal site before seeding starter forms.');
  }

  $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0];
  if (!$claim_reserved) {
    $summary['retired'] = beantragt_starter_retire_replaced_forms(
      $entity_type_manager,
      $jurisdiction,
      $slug,
      $force_update,
    );
  }
  $catalog = beantragt_starter_form_catalog($external_analysis, $mode === 'demo');
  if (getenv('BEANTRAGT_EXAMPLE_DATA') === '1') {
    // Reuse the optional reduction flow of the standard registration, with
    // no transfer consent and only local casework statuses.
    $catalog = beantragt_starter_form_catalog(FALSE, TRUE);
    $catalog[0]['key'] = 'dog-tax-registration';
    array_splice($catalog, 1, 1);
    $catalog[1]['organisation'] = 'environment-office';
    $catalog[2]['organisation'] = 'central-administration';
    $catalog[3]['organisation'] = 'building-department';
    require_once __DIR__ . '/lib/wohngeld-form.php';
    $catalog[] = beantragt_example_housing_definition();
  }
  foreach ($catalog as $definition) {
    beantragt_starter_ensure_form(
      $entity_type_manager,
      $jurisdiction,
      $owner,
      $definition,
      $slug,
      $mode,
      $publish,
      $claim_reserved,
      $force_update,
      $summary,
    );
  }

  $source_prefix = 'seed:starter-form:' . $slug . ':';
  $seeded_ids = $entity_type_manager->getStorage('form_schema')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', $source_prefix, 'STARTS_WITH')
    ->execute();
  if (count($seeded_ids) !== count($catalog)) {
    throw new RuntimeException(sprintf(
      'Starter catalogue invariant failed: expected %d forms for %s, found %d.',
      count($catalog),
      $slug,
      count($seeded_ids),
    ));
  }

  unset($transaction);
  printf(
    "Starter catalogue seeded: jurisdiction=%s forms=%d languages=de,en created=%d updated=%d unchanged=%d retired=%d state=%s analysis=%s.\n",
    $slug,
    count($catalog),
    $summary['created'],
    $summary['updated'],
    $summary['unchanged'],
    $summary['retired'],
    $publish ? 'published' : 'draft',
    $external_analysis ? 'external-consent-gated' : 'disabled-draft',
  );
}

catch (Throwable $exception) {
  if (isset($transaction)) {
    try {
      $transaction->rollBack();
    }
    catch (Throwable) {
      // Preserve the original seed failure.
    }
  }
  throw $exception;
}
finally {
  $lock->release($lock_name);
}

/**
 * Converges the one demo jurisdiction mailbox to a reserved recipient.
 */
function beantragt_starter_ensure_demo_mailbox(
  GroupInterface $jurisdiction,
  string $mail,
  string $mode,
  bool $force_update,
): void {
  if ($mode !== 'demo' || !$jurisdiction->hasField('field_org_email')) {
    return;
  }
  $current = (string) ($jurisdiction->get('field_org_email')->value ?? '');
  if ($current === $mail) {
    return;
  }
  if (!$force_update) {
    throw new RuntimeException('Demo jurisdiction mailbox requires BEANTRAGT_STARTER_FORCE_UPDATE=1 for migration.');
  }
  $jurisdiction->set('field_org_email', $mail);
  $jurisdiction->save();
  printf("Updated starter jurisdiction mailbox for %s.\n", $jurisdiction->label());
}

/**
 * Validates the seed boundary before any entity write.
 */
function beantragt_starter_assert_runtime(
  string $slug,
  string $label,
  string $mail,
  string $mode,
  bool $publish,
  bool $external_analysis,
  bool $claim_reserved,
): void {
  if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    throw new InvalidArgumentException('Starter jurisdiction slug is invalid.');
  }
  if ($label === '' || mb_strlen($label) > 160) {
    throw new InvalidArgumentException('Starter jurisdiction label is invalid.');
  }
  if (filter_var($mail, FILTER_VALIDATE_EMAIL) === FALSE) {
    throw new InvalidArgumentException('Starter jurisdiction email is invalid.');
  }
  if (!in_array($mode, ['golden', 'demo'], TRUE)) {
    throw new InvalidArgumentException('BEANTRAGT_STARTER_MODE must be golden or demo.');
  }
  if ($slug === 'tenant-reserved' && $publish) {
    throw new RuntimeException('The reserved tenant placeholder must never expose published starter forms.');
  }
  if ($external_analysis && (!$publish || $mode !== 'demo')) {
    throw new RuntimeException('External starter attachment analysis is allowed only for an explicitly published demo overlay.');
  }
  if ($mode === 'demo' && preg_match('/@(?:[a-z0-9-]+\.)*example\.(?:com|net|org)$/i', $mail) !== 1) {
    throw new RuntimeException('The public demo jurisdiction mailbox must use a reserved example domain.');
  }
  if ($claim_reserved && $slug === 'tenant-reserved') {
    throw new RuntimeException('The reserved tenant can only be claimed with a concrete target slug.');
  }
  $is_ddev = getenv('IS_DDEV_PROJECT') === 'true' || getenv('DDEV_PROJECT');
  if (!$is_ddev && getenv('BEANTRAGT_ALLOW_STARTER_FORM_SEED') !== '1') {
    throw new RuntimeException('Refusing starter seed outside DDEV without BEANTRAGT_ALLOW_STARTER_FORM_SEED=1.');
  }
}

/**
 * Loads or explicitly creates the target jurisdiction.
 */
function beantragt_starter_ensure_jurisdiction(
  $entity_type_manager,
  string $slug,
  string $label,
  string $mail,
  bool $allow_create,
  bool $claim_reserved,
): GroupInterface {
  $storage = $entity_type_manager->getStorage('group');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'jur')
    ->condition('field_slug', $slug)
    ->range(0, 2)
    ->execute();
  if (count($ids) > 1) {
    throw new RuntimeException(sprintf('Jurisdiction slug %s is not unique.', $slug));
  }
  $jurisdiction = $ids ? $storage->load(reset($ids)) : NULL;
  if ($claim_reserved && $jurisdiction instanceof GroupInterface) {
    throw new RuntimeException(sprintf('Refusing tenant claim because target jurisdiction %s already exists.', $slug));
  }
  if ($claim_reserved) {
    $reserved_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'jur')
      ->condition('field_slug', 'tenant-reserved')
      ->range(0, 2)
      ->execute();
    if (count($reserved_ids) !== 1) {
      throw new RuntimeException('Claiming a starter tenant requires exactly one reserved jurisdiction.');
    }
    $jurisdiction = $storage->load(reset($reserved_ids));
    if (!$jurisdiction instanceof GroupInterface) {
      throw new RuntimeException('Reserved starter jurisdiction could not be loaded.');
    }
    beantragt_starter_assert_pristine_reserved_tenant($entity_type_manager, $jurisdiction);
    $jurisdiction->set('label', $label);
    $jurisdiction->set('field_slug', $slug);
    if ($jurisdiction->hasField('field_org_email')) {
      $jurisdiction->set('field_org_email', $mail);
    }
    $jurisdiction->save();
    printf("Claimed reserved starter jurisdiction as %s (%s).\n", $label, $slug);
  }
  if (!$jurisdiction instanceof GroupInterface) {
    if (!$allow_create) {
      throw new RuntimeException(sprintf(
        'Jurisdiction %s is missing. Set BEANTRAGT_STARTER_CREATE_JURISDICTION=1 only for an intentional new stack.',
        $slug,
      ));
    }
    $jurisdiction = $storage->create([
      'type' => 'jur',
      'label' => $label,
      'field_platform_name' => 'beantragt.ai',
      'field_slug' => $slug,
      'field_org_email' => $mail,
    ]);
    $jurisdiction->save();
    printf("Created starter jurisdiction %s (%s).\n", $label, $slug);
  }
  return $jurisdiction;
}

/**
 * Proves that the reserved jurisdiction is the untouched Golden Master.
 */
function beantragt_starter_assert_pristine_reserved_tenant($entity_type_manager, GroupInterface $jurisdiction): void {
  $group_storage = $entity_type_manager->getStorage('group');
  $form_storage = $entity_type_manager->getStorage('form_schema');
  $reserved_form_ids = $form_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', 'seed:starter-form:tenant-reserved:', 'STARTS_WITH')
    ->execute();
  $counts = [
    'starter_forms' => count($reserved_form_ids),
    'all_forms' => (int) $form_storage->getQuery()->accessCheck(FALSE)->count()->execute(),
    'applications' => (int) $entity_type_manager->getStorage('application')->getQuery()->accessCheck(FALSE)->count()->execute(),
    'applicants' => (int) $entity_type_manager->getStorage('applicant')->getQuery()->accessCheck(FALSE)->count()->execute(),
    'organisations' => (int) $group_storage->getQuery()->accessCheck(FALSE)->condition('type', 'org')->count()->execute(),
    'groups' => (int) $group_storage->getQuery()->accessCheck(FALSE)->count()->execute(),
    'memberships' => (int) $entity_type_manager->getStorage('group_relationship')->getQuery()->accessCheck(FALSE)->count()->execute(),
    'files' => (int) $entity_type_manager->getStorage('file')->getQuery()->accessCheck(FALSE)->count()->execute(),
    'attachments' => (int) $entity_type_manager->getStorage('application_attachment')->getQuery()->accessCheck(FALSE)->count()->execute(),
    'users' => (int) $entity_type_manager->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('uid', 0, '>')->count()->execute(),
    'service_users' => (int) $entity_type_manager->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('name', 'nuxt_service')->condition('status', 1)->count()->execute(),
  ];
  $expected_counts = [
    'starter_forms' => 4,
    'all_forms' => 4,
    'applications' => 0,
    'applicants' => 0,
    'organisations' => 0,
    'groups' => 1,
    'memberships' => 0,
    'files' => 0,
    'attachments' => 0,
    'users' => 2,
    'service_users' => 1,
  ];
  if ($counts !== $expected_counts) {
    throw new RuntimeException('Reserved tenant is not pristine: ' . json_encode($counts, JSON_THROW_ON_ERROR));
  }

  if (
    (string) ($jurisdiction->get('field_slug')->value ?? '') !== 'tenant-reserved'
    || (string) $jurisdiction->label() !== 'Tenant (reserviert)'
    || !$jurisdiction->isPublished()
  ) {
    throw new RuntimeException('Reserved tenant metadata differs from the Golden Master.');
  }

  $expected_settings = [
    'field_platform_name' => 'beantragt.ai',
    'field_org_email' => 'service@tenant.reserved.example.org',
  ];
  foreach ($expected_settings as $field_name => $expected_value) {
    if (
      !$jurisdiction->hasField($field_name)
      || (string) ($jurisdiction->get($field_name)->value ?? '') !== $expected_value
    ) {
      throw new RuntimeException(sprintf(
        'Reserved tenant setting %s differs from the Golden Master.',
        $field_name,
      ));
    }
  }

  $empty_settings = [
    'field_custom_css',
    'field_email_footer',
    'field_jur_config',
    'field_legal_notice',
    'field_parent_jurisdiction',
    'field_privacy_policy',
  ];
  foreach ($empty_settings as $field_name) {
    if (!$jurisdiction->hasField($field_name) || !$jurisdiction->get($field_name)->isEmpty()) {
      throw new RuntimeException(sprintf(
        'Reserved tenant setting %s differs from the Golden Master.',
        $field_name,
      ));
    }
  }

  $current_tree_source = 'seed:starter-form:tenant-reserved:tree-felling-application';
  $legacy_tree_source = 'seed:starter-form:tenant-reserved:dog-tax-deregistration';
  $current_tree_ids = $form_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', $current_tree_source)
    ->execute();
  $legacy_tree_ids = $form_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', $legacy_tree_source)
    ->execute();
  $legacy_catalog = $current_tree_ids === [] && count($legacy_tree_ids) === 1;

  foreach (beantragt_starter_form_catalog(FALSE) as $definition) {
    $source_filename = 'seed:starter-form:tenant-reserved:' . $definition['key'];
    if ($legacy_catalog && $definition['key'] === 'tree-felling-application') {
      $source_filename = $legacy_tree_source;
    }
    $ids = $form_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', $source_filename)
      ->range(0, 2)
      ->execute();
    if (count($ids) !== 1) {
      throw new RuntimeException(sprintf('Reserved starter form %s is missing or duplicated.', $definition['key']));
    }
    $form = $form_storage->load(reset($ids));
    if (!$form instanceof FormSchemaInterface) {
      throw new RuntimeException(sprintf('Reserved starter form %s is invalid.', $definition['key']));
    }
    try {
      $payload = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (JsonException) {
      throw new RuntimeException(sprintf('Reserved starter form %s has invalid schema JSON.', $definition['key']));
    }
    if ($legacy_catalog && $definition['key'] === 'tree-felling-application') {
      beantragt_starter_assert_legacy_reserved_tree_slot($form, $jurisdiction, $payload, $source_filename);
      continue;
    }
    if (
      $form->getSchemaStatus() !== 'draft'
      || $form->getPublicationScope() !== 'intranet'
      || $form->isPublished()
      || $form->getVersion() !== 1
      || (int) $form->getOwnerId() !== 1
      || (string) $form->label() !== $definition['title']
      || (string) $form->getSourceFilename() !== $source_filename
      || (string) ($form->get('field_description')->value ?? '') !== beantragt_starter_visible_description($definition, 'golden')
      || $payload !== beantragt_starter_payload($definition, 'golden', FALSE, $source_filename)
      || (!beantragt_starter_form_has_scope($form, $jurisdiction)
        && !beantragt_starter_form_has_scope($form, $jurisdiction, beantragt_starter_form_organisation($entity_type_manager, $jurisdiction, $definition)))
    ) {
      throw new RuntimeException(sprintf('Reserved starter form %s differs from the Golden Master.', $definition['key']));
    }
  }
}

/**
 * Validates the one replaced slot of a pristine legacy Golden Master.
 */
function beantragt_starter_assert_legacy_reserved_tree_slot(
  FormSchemaInterface $form,
  GroupInterface $jurisdiction,
  array $payload,
  string $source_filename,
): void {
  $definition = beantragt_starter_legacy_dog_deregistration_definition();
  $expected_payload = beantragt_starter_payload($definition, 'golden', FALSE, $source_filename);
  if (
    $form->getSchemaStatus() !== 'draft'
    || $form->getPublicationScope() !== 'intranet'
    || $form->isPublished()
    || $form->getVersion() !== 1
    || (int) $form->getOwnerId() !== 1
    || (string) $form->label() !== $definition['title']
    || (string) $form->getSourceFilename() !== $source_filename
    || (string) ($form->get('field_description')->value ?? '') !== beantragt_starter_visible_description($definition, 'golden')
    || $payload !== $expected_payload
    || !beantragt_starter_form_has_scope($form, $jurisdiction)
  ) {
    throw new RuntimeException('Reserved legacy dog deregistration form differs from the Golden Master.');
  }
}

/**
 * Archives replaced starter forms without changing historical applications.
 */
function beantragt_starter_retire_replaced_forms(
  $entity_type_manager,
  GroupInterface $jurisdiction,
  string $slug,
  bool $force_update,
): int {
  $storage = $entity_type_manager->getStorage('form_schema');
  $sources = ['seed:starter-form:' . $slug . ':dog-tax-deregistration'];
  if ($slug === 'musterstadt') {
    $sources[] = 'seed:musterstadt-public-form:hundesteuer-abmeldung';
  }

  $retired = 0;
  foreach ($sources as $source) {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', $source)
      ->range(0, 2)
      ->execute();
    if (count($ids) > 1) {
      throw new RuntimeException(sprintf('Replaced starter form source is not unique: %s.', $source));
    }
    if ($ids === []) {
      continue;
    }
    if (!$force_update) {
      throw new RuntimeException(sprintf(
        'Replaced starter form %s requires BEANTRAGT_STARTER_FORCE_UPDATE=1 for retirement.',
        $source,
      ));
    }

    $form = $storage->load(reset($ids));
    if (!$form instanceof FormSchemaInterface) {
      throw new RuntimeException(sprintf('Replaced starter form is invalid: %s.', $source));
    }
    $retired_source = 'retired:' . $source;
    $conflicts = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', $retired_source)
      ->condition('id', (int) $form->id(), '<>')
      ->execute();
    if ($conflicts !== []) {
      throw new RuntimeException(sprintf('Retired starter form source already exists: %s.', $retired_source));
    }

    $form->set('field_source_filename', $retired_source);
    $form->set('field_workflow_status', 'archived');
    $form->set('field_publication_scope', 'intranet');
    $form->set('status', FALSE);
    $form->save();
    $retired++;
    printf("Retired replaced starter form %s.\n", $source);
  }
  return $retired;
}

/**
 * Checks direct and legacy jurisdiction scope fields on one starter form.
 */
function beantragt_starter_form_has_scope(FormSchemaInterface $form, GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): bool {
  $jurisdiction_id = (int) $jurisdiction->id();
  $owner_id = $organisation instanceof GroupInterface ? (int) $organisation->id() : $jurisdiction_id;
  return (
    !$form->hasField('field_jurisdiction')
    || (int) ($form->get('field_jurisdiction')->target_id ?? 0) === $jurisdiction_id
  ) && (
    !$form->hasField('field_organisation')
    || ($organisation instanceof GroupInterface
      ? (int) ($form->get('field_organisation')->target_id ?? 0) === $owner_id
      : $form->get('field_organisation')->isEmpty())
  ) && (
    !$form->hasField('field_group')
    || (int) ($form->get('field_group')->target_id ?? 0) === $owner_id
  );
}

/**
 * Returns the own workflow of a starter form, or NULL for the standard.
 *
 * Built by flag from the catalogue the form's applications land in (the
 * organisation, else the jurisdiction); see scripts/lib/DemoFormWorkflow.php.
 */
function beantragt_starter_form_workflow($entity_type_manager, GroupInterface $jurisdiction, ?GroupInterface $organisation, array $definition): ?array {
  // The dog tax goes to the assessment system: hand-over (status trigger of
  // the transfer rule), acceptance, and every follow-up of the case system's
  // answer (seed-demo-integration.php). Every other starter form keeps the
  // three-status standard.
  $flags = match ($definition['key']) {
    'dog-tax-registration' => getenv('BEANTRAGT_EXAMPLE_DATA') === '1'
      ? ['approved', 'rejected']
      : ['hand_over', 'in_case_system', 'decision_issued', 'approved', 'rejected', 'filed'],
    default => NULL,
  };
  if ($flags === NULL) {
    return NULL;
  }
  $group = $organisation ?? $jurisdiction;
  return DemoFormWorkflow::definition($entity_type_manager, (int) $group->id(), $flags);
}

/**
 * Returns the organisation a catalogue entry belongs to in a jurisdiction.
 *
 * A form names an organisation slug ("organisation") when its applications
 * must not land in the default organisation: the dog tax goes to the
 * Stadtkasse. Jurisdictions without that organisation keep the form
 * jurisdiction-direct.
 */
function beantragt_starter_form_organisation($entity_type_manager, GroupInterface $jurisdiction, array $definition): ?GroupInterface {
  $slug = (string) ($definition['organisation'] ?? '');
  if ($slug === '') {
    return NULL;
  }
  $ids = $entity_type_manager->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'org')
    ->condition('field_slug', $slug)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->range(0, 2)
    ->execute();
  if (count($ids) !== 1) {
    return NULL;
  }
  $organisation = $entity_type_manager->getStorage('group')->load(reset($ids));
  return $organisation instanceof GroupInterface ? $organisation : NULL;
}

/**
 * Creates or updates one scoped starter FormSchema.
 *
 * @param array<string, mixed> $definition
 *   Catalogue entry.
 * @param array{created: int, updated: int, unchanged: int, retired: int} $summary
 *   Mutable seed summary.
 */
function beantragt_starter_ensure_form(
  $entity_type_manager,
  GroupInterface $jurisdiction,
  UserInterface $owner,
  array $definition,
  string $slug,
  string $mode,
  bool $publish,
  bool $claim_reserved,
  bool $force_update,
  array &$summary,
): FormSchemaInterface {
  $storage = $entity_type_manager->getStorage('form_schema');
  $organisation = beantragt_starter_form_organisation($entity_type_manager, $jurisdiction, $definition);
  $source_filename = 'seed:starter-form:' . $slug . ':' . $definition['key'];
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_jurisdiction', (int) $jurisdiction->id())
    ->condition('field_source_filename', $source_filename)
    ->range(0, 2)
    ->execute();
  if (!$ids && $slug === 'musterstadt') {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', 'seed:musterstadt-public-form:' . beantragt_starter_legacy_key($definition['key']))
      ->range(0, 2)
      ->execute();
  }
  if (!$ids && $claim_reserved) {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', 'seed:starter-form:tenant-reserved:' . $definition['key'])
      ->range(0, 2)
      ->execute();
  }
  if (!$ids && $claim_reserved && $definition['key'] === 'tree-felling-application') {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_jurisdiction', (int) $jurisdiction->id())
      ->condition('field_source_filename', 'seed:starter-form:tenant-reserved:dog-tax-deregistration')
      ->range(0, 2)
      ->execute();
  }
  if (count($ids) > 1) {
    throw new RuntimeException(sprintf('Starter form source is not unique: %s.', $source_filename));
  }

  $schema = $ids ? $storage->load(reset($ids)) : $storage->create(['type' => 'form_schema']);
  if (!$schema instanceof FormSchemaInterface) {
    throw new RuntimeException('Seeded form entity does not implement FormSchemaInterface.');
  }
  $is_new = $schema->isNew();
  $payload = beantragt_starter_payload($definition, $mode, $publish, $source_filename);
  if (!$is_new && getenv('BEANTRAGT_STARTER_PRESERVE_CONTEXT_SOURCES') === '1') {
    try {
      $existing_payload = json_decode((string) $schema->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (is_array($existing_payload)
        && isset($existing_payload['contextSources'])
        && is_array($existing_payload['contextSources'])) {
        $payload['contextSources'] = $existing_payload['contextSources'];
        if (isset($existing_payload['precheckSchema'])
          && is_array($existing_payload['precheckSchema'])) {
          $payload['precheckSchema'] = $existing_payload['precheckSchema'];
        }
      }
    }
    catch (JsonException) {
      // The normal drift guard below will reject invalid existing JSON.
    }
  }
  $description = beantragt_starter_visible_description($definition, $mode);

  $workflow = beantragt_starter_form_workflow($entity_type_manager, $jurisdiction, $organisation, $definition);
  if (!$is_new && (string) $schema->getSourceFilename() === $source_filename) {
    if (beantragt_starter_form_matches_target($schema, $jurisdiction, $owner, $definition, $payload, $description, $publish, $organisation)
      && ($workflow === NULL || DemoFormWorkflow::same($schema->get('field_workflow_definition')->value, $workflow))) {
      $summary['unchanged']++;
      printf("Unchanged starter form %s (%s).\n", $definition['title'], $source_filename);
      return $schema;
    }
    if (!$force_update) {
      throw new RuntimeException(sprintf(
        'Starter form %s has been changed. Refusing to overwrite it without BEANTRAGT_STARTER_FORCE_UPDATE=1.',
        $definition['key'],
      ));
    }
  }
  elseif (!$is_new && !$claim_reserved && !$force_update) {
    throw new RuntimeException(sprintf(
      'Legacy starter form %s requires BEANTRAGT_STARTER_FORCE_UPDATE=1 for migration.',
      $definition['key'],
    ));
  }

  // Schema and workflow mutations are deliberately restricted to draft
  // entities. A force-update seed must follow the same lifecycle as the admin
  // editor instead of bypassing that guard: create an auditable draft
  // revision, reload the current entity, then apply and publish the target.
  if (!$is_new && $schema->getSchemaStatus() !== 'draft') {
    $schema->set('field_workflow_status', 'draft');
    $schema->set('status', FALSE);
    $schema->save();
    $schema_id = (int) $schema->id();
    $storage->resetCache([$schema_id]);
    $schema = $storage->load($schema_id);
    if (!$schema instanceof FormSchemaInterface) {
      throw new RuntimeException(sprintf('Starter form %s could not be reloaded as a draft.', $definition['key']));
    }
    printf("Moved starter form %s to a draft revision for force-update.\n", $definition['title']);
  }

  $schema->set('label', $definition['title']);
  $schema->set('field_description', $description);
  $schema->set('field_schema', json_encode(
    $payload,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  ));
  $schema->set('field_source_filename', $source_filename);
  if ($workflow !== NULL) {
    // Written in the same draft save that publishes, before any integration
    // seed binds a rule to the form revision.
    $schema->set('field_workflow_definition', DemoFormWorkflow::encode($workflow));
  }
  $schema->set('field_workflow_status', $publish ? 'published' : 'draft');
  $schema->set('field_publication_scope', $publish ? 'internet' : 'intranet');
  $schema->setOwnerId((int) $owner->id());
  beantragt_starter_set_scope($schema, $jurisdiction, $organisation);
  $schema->set('status', $publish);
  $schema->save();

  $summary[$is_new ? 'created' : 'updated']++;
  printf("%s starter form %s (%s).\n", $is_new ? 'Created' : 'Updated', $definition['title'], $source_filename);
  return $schema;
}

/**
 * Checks whether an existing starter form already equals the requested state.
 */
function beantragt_starter_form_matches_target(
  FormSchemaInterface $schema,
  GroupInterface $jurisdiction,
  UserInterface $owner,
  array $definition,
  array $payload,
  string $description,
  bool $publish,
  ?GroupInterface $organisation = NULL,
): bool {
  try {
    $actual_payload = json_decode((string) $schema->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  catch (JsonException) {
    return FALSE;
  }

  return (string) $schema->label() === $definition['title']
    && (string) ($schema->get('field_description')->value ?? '') === $description
    && $actual_payload === $payload
    && $schema->getSchemaStatus() === ($publish ? 'published' : 'draft')
    && $schema->getPublicationScope() === ($publish ? 'internet' : 'intranet')
    && $schema->isPublished() === $publish
    && (int) $schema->getOwnerId() === (int) $owner->id()
    && beantragt_starter_form_has_scope($schema, $jurisdiction, $organisation);
}

/**
 * Maps the three historical Musterstadt seed identifiers during migration.
 */
function beantragt_starter_legacy_key(string $key): string {
  return match ($key) {
    'dog-tax-registration' => 'hundesteuer-anmeldung',
    'business-registration' => 'gewerbeanmeldung-einzelunternehmen',
    default => $key,
  };
}

/**
 * Builds the persisted FormSchema payload.
 *
 * @param array<string, mixed> $definition
 *   Catalogue entry.
 *
 * @return array<string, mixed>
 *   FormSchema payload.
 */
function beantragt_starter_payload(array $definition, string $mode, bool $publish, string $source_filename): array {
  $demo = $mode === 'demo';
  $description = beantragt_starter_visible_description($definition, $mode);
  $description_en = $demo
    ? $definition['description_en']
    : 'Starter template. Technical and legal review is required before publication. ' . $definition['description_en'];
  $source = array_replace([
    'type' => $demo ? 'demo_neutral' : 'starter_template',
    'file' => 'scripts/seed-starter-forms.php',
    'key' => $source_filename,
    'label' => $demo ? 'beantragt.ai public demo starter catalogue' : 'beantragt.ai golden master starter catalogue',
    'note' => $demo
      ? 'Fictitious public demo form. Do not enter real personal data.'
      : 'Non-binding starter template. The tenant administrator must review it before publication.',
    'retrieved_at' => '2026-07-12',
    'demo_only' => $demo,
    'official_service' => FALSE,
    'review_required' => TRUE,
    'published_by_seed' => $publish,
  ], $definition['source'] ?? []);

  // The public demo states that it is a demo once, on the municipality's
  // start page (scripts/lib/showcase-form-catalog.php), not in every form.
  $sections = $definition['sections'];

  return [
    'id' => 'starter_' . str_replace('-', '_', $definition['key']),
    'title' => $definition['title'],
    'description' => $description,
    'i18n' => [
      'en' => [
        'title' => $definition['title_en'],
        'description' => $description_en,
      ],
    ],
    'sections' => $sections,
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
      'category' => $definition['category'],
      'entities' => ['person', 'application', 'jurisdiction'],
      'process' => $definition['key'],
      'languages' => ['de', 'en'],
    ],
    'source' => $source,
  ];
}

/**
 * Returns the visible legal and demo boundary.
 */
function beantragt_starter_visible_description(array $definition, string $mode): string {
  if ($mode === 'demo') {
    return $definition['description'];
  }
  return 'Startvorlage. Vor Veröffentlichung fachlich und rechtlich prüfen. ' . $definition['description'];
}

/**
 * Sets jurisdiction-direct or organisation ownership on a form.
 */
function beantragt_starter_set_scope(FieldableEntityInterface $entity, GroupInterface $jurisdiction, ?GroupInterface $organisation = NULL): void {
  if ($entity->hasField('field_jurisdiction')) {
    $entity->set('field_jurisdiction', ['target_id' => $jurisdiction->id()]);
  }
  if ($entity->hasField('field_organisation')) {
    $entity->set('field_organisation', $organisation instanceof GroupInterface ? ['target_id' => $organisation->id()] : []);
  }
  if ($entity->hasField('field_group')) {
    $entity->set('field_group', ['target_id' => ($organisation ?? $jurisdiction)->id()]);
  }
}
