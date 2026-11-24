<?php

/**
 * @file
 * Creates the local Musterstadt workspace and normal sign-in accounts.
 */

declare(strict_types=1);

use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

require_once __DIR__ . '/lib/workspace.php';

if (getenv('BEANTRAGT_EXAMPLE_DATA') !== '1' || getenv('BEANTRAGT_MAIL_MODE') !== 'mailpit') {
  throw new RuntimeException('The example accounts require the local Mailpit installation.');
}

$manager = \Drupal::entityTypeManager();
$groups = $manager->getStorage('group');
$users = $manager->getStorage('user');
$state = \Drupal::state();
$lock = \Drupal::lock();
$lock_name = 'beantragt:selfhost-example-workspace';
if (!$lock->acquire($lock_name, 120.0)) {
  throw new RuntimeException('The example workspace is already being installed.');
}

$transaction = \Drupal::database()->startTransaction();
try {
  \Drupal::moduleHandler()->loadInclude('beantragt_group', 'install');
  $installed = $state->get('beantragt.selfhost_example_accounts', []);
  if ($installed === [] && ($groups->loadByProperties(['type' => 'jur', 'field_slug' => ['musterstadt', 'stadt-musterstadt']])
    || $groups->loadByProperties(['type' => 'jur', 'label' => ['Musterstadt', 'Stadt Musterstadt']]))) {
    throw new RuntimeException('The example seed will not adopt an existing Musterstadt jurisdiction.');
  }
  _beantragt_group_ensure_musterstadt_example($manager);
  _beantragt_group_ensure_workspace_inviter_scope();

  $jurisdiction = beantragt_demo_find_group($manager, 'jur', 'musterstadt');
  if (!$jurisdiction instanceof GroupInterface) {
    throw new RuntimeException('The Musterstadt jurisdiction is missing.');
  }
  $offices = [];
  $office_slugs = [
    'citizen-services', 'central-administration', 'personnel-office', 'building-department',
    'mayors-office', 'environment-office', 'procurement-office', 'stadtkasse',
  ];
  foreach ($groups->loadByProperties([
    'type' => 'org',
    'field_jurisdiction' => $jurisdiction->id(),
    'field_slug' => $office_slugs,
  ]) as $office) {
    $offices[(string) $office->get('field_slug')->value] = $office;
  }

  if (count($offices) !== count($office_slugs)) {
    throw new RuntimeException('The example offices are incomplete.');
  }

  $summary = [
    'workflow_statuses_created' => 0,
    'workflow_statuses_updated' => 0,
    'workflow_statuses_deleted' => 0,
    'workflow_statuses_prune_skipped' => 0,
  ];
  beantragt_demo_seed_workflow_statuses($manager, ['jur' => ['musterstadt' => $jurisdiction], 'org' => $offices], $summary);
  beantragt_demo_ensure_brand_primary($jurisdiction, '#3d7f4a');

  $definitions = [
    'buerger' => ['Bertha Bürger', [], NULL, NULL],
    // Form administration may invite colleagues, limited to Musterstadt.
    'verwaltung' => ['Verwaltung Musterstadt', ['department_admin', 'workspace_inviter'], $jurisdiction, 'jur-admin'],
  ];
  foreach ($offices as $slug => $office) {
    $definitions[$slug] = ['Sachbearbeitung ' . $office->label(), ['case_worker'], $office, 'org-member'];
  }

  foreach ($definitions as $key => [$label, $roles, $group, $group_role]) {
    $mail = $key . '@example.invalid';
    $name = 'musterstadt-' . $key;
    $existing = $users->loadByProperties(['mail' => $mail]);
    if (isset($installed[$key])) {
      $user = reset($existing);
      if (count($existing) !== 1 || !$user instanceof UserInterface || $user->uuid() !== $installed[$key]) {
        throw new RuntimeException('An installed example account has been removed or replaced.');
      }
      // Once created, accounts are managed through the normal user tools.
      continue;
    }
    if ($existing || $users->loadByProperties(['name' => $name])) {
      throw new RuntimeException('An existing user conflicts with the example account ' . $mail . '.');
    }
    $user = $users->create(['name' => $name, 'mail' => $mail, 'status' => 1, 'field_last_name' => $label]);
    $user->setPassword(bin2hex(random_bytes(32)));
    foreach ($roles as $role) {
      $user->addRole($role);
    }
    if (in_array('workspace_inviter', $roles, TRUE)) {
      $user->set('field_scope_jurisdiction', ['target_id' => $jurisdiction->id()]);
    }
    $user->save();
    if ($group !== NULL) {
      beantragt_demo_ensure_group_role($group, $user, $group_role);
    }
    $installed[$key] = $user->uuid();
  }
  $state->set('beantragt.selfhost_example_accounts', $installed);
  \Drupal::configFactory()->getEditable('beantragt_group.settings')
    ->set('default_jurisdiction_slug', 'musterstadt')
    ->set('default_organisation_slug', 'citizen-services')
    ->save();
  unset($transaction);
}
catch (Throwable $exception) {
  $transaction->rollBack();
  throw $exception;
}
finally {
  $lock->release($lock_name);
}
