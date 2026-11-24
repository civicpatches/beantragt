<?php

/**
 * @file
 * Post-update functions for beantragt_form_schema_group.
 */

declare(strict_types=1);

use Drupal\user\Entity\Role;

/**
 * Grants form schema group permissions to roles that may manage flows.
 */
function beantragt_form_schema_group_post_update_grant_role_permissions(array &$sandbox): string {
  $available_permissions = array_keys(\Drupal::service('user.permissions')->getPermissions());
  $role_permissions = [
    'case_worker' => [
      'view published form_schema_group entities',
    ],
    'department_admin' => [
      'administer form_schema_group entities',
      'view published form_schema_group entities',
      'view unpublished form_schema_group entities',
      'create form_schema_group entities',
      'edit own form_schema_group entities',
      'edit any form_schema_group entities',
      'delete own form_schema_group entities',
      'delete any form_schema_group entities',
    ],
    'service_account' => [
      'view published form_schema_group entities',
      'view unpublished form_schema_group entities',
    ],
  ];

  $changed = FALSE;
  foreach ($role_permissions as $role_id => $permissions) {
    $role = Role::load($role_id);
    if (!$role) {
      continue;
    }

    $role_changed = FALSE;
    foreach (array_intersect($permissions, $available_permissions) as $permission) {
      if (!$role->hasPermission($permission)) {
        $role->grantPermission($permission);
        $role_changed = TRUE;
        $changed = TRUE;
      }
    }

    if ($role_changed) {
      $role->save();
    }
  }

  return $changed
    ? 'Granted form schema group permissions to application roles.'
    : 'Form schema group permissions were already present.';
}

/**
 * Removes unnecessary edit permissions from the service account.
 */
function beantragt_form_schema_group_post_update_revoke_service_account_write_permissions(array &$sandbox): string {
  $role = Role::load('service_account');
  if (!$role) {
    return 'service_account role not found.';
  }

  $changed = FALSE;
  foreach ([
    'edit any form_schema_group entities',
  ] as $permission) {
    if ($role->hasPermission($permission)) {
      $role->revokePermission($permission);
      $changed = TRUE;
    }
  }

  if ($changed) {
    $role->save();
    return 'Revoked form schema group edit permissions from service_account.';
  }

  return 'service_account form schema group edit permissions were already absent.';
}
