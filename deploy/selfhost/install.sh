#!/bin/bash
set -euo pipefail
cd /app/data

# Keep the sync directory outside the nginx web root and resolve it
# independently of Drush's working directory when Drupal writes .htaccess.
export CONFIG_SYNC_DIR="${CONFIG_SYNC_DIR:-/app/data/config/sync}"
mkdir -p -- "$CONFIG_SYNC_DIR"

if ! drush status --field=bootstrap | grep -q 'Successful'; then
  # Drush's installer can drop an existing schema. Only initialize an empty DB
  # and propagate connection errors instead of treating them as an empty site.
  # shellcheck disable=SC2016 # PHP variables are evaluated by PHP.
  php -r '
    $pdo = new PDO(
      "mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"),
      getenv("DB_USER"),
      getenv("DB_PASSWORD"),
    );
    if ($pdo->query("SHOW FULL TABLES")->fetchColumn() !== FALSE) {
      fwrite(STDERR, "Refusing to install into a nonempty database without a Drupal bootstrap.\n");
      exit(1);
    }
  '
  # Drush shows a one-time administrator link on success. Keep it out of logs.
  drush site:install standard --yes --quiet --site-name="beantragt.ai" \
    --account-name=admin --account-mail=admin@example.invalid \
    --account-pass="$(php -r 'echo bin2hex(random_bytes(32));')"
fi

if [[ $(drush state:get beantragt.selfhost_initialized --format=string) == 1 ]]; then
  exit 0
fi

# Commit the platform initialization before any optional fixtures. A retry
# can resume fixture installation without replaying platform configuration.
if [[ $(drush state:get beantragt.selfhost_base_initialized --format=string) != 1 ]]; then
  # Standard enables these modules; the self-host package does not use their
  # external fetches. Remove them before beantragt_group installs the Gin theme.
  # shellcheck disable=SC2016 # PHP variables are evaluated by PHP.
  drush php:eval '
    $modules = array_filter(["update", "announcements_feed"], \Drupal::moduleHandler()->moduleExists(...));
    if ($modules) {
      \Drupal::service("module_installer")->uninstall(array_values($modules));
    }
  '
  drush pm:enable --yes \
    beantragt_applicant beantragt_application beantragt_comment \
    beantragt_compute beantragt_compute_api \
    beantragt_content beantragt_delivery beantragt_docgen \
    beantragt_form_schema_group beantragt_group beantragt_maintenance \
    beantragt_nachreichung beantragt_notification beantragt_passwordless \
    beantragt_roles beantragt_roles_api beantragt_schema beantragt_sso \
    beantragt_status beantragt_workflow beantragt_workflow_status

  # The locked Drush version does not register Drupal 11.4's moved recipe
  # command. Apply the recipe inside the full Drush bootstrap, after the
  # regular module installer has run requirement checks and install hooks.
  drush php:eval '
  \Drupal\Core\Recipe\RecipeRunner::processRecipe(
    \Drupal\Core\Recipe\Recipe::createFromDirectory(dirname(DRUPAL_ROOT) . "/recipes/beantragt_core"),
  );
  '

  # shellcheck disable=SC2016 # PHP variables are evaluated by PHP.
  drush php:eval '
  $account = user_load_by_name("nuxt_service");
  if (!$account || (int) $account->id() <= 1 || !$account->isActive() || $account->getRoles(TRUE) !== ["service_account"]) {
    throw new RuntimeException("The limited Nuxt service account is missing or misconfigured.");
  }
  // Keep the public Nuxt proxy limited to the OSS installer role contract.
  $permissions = [
    "create application entities",
    "create application_attachment entities",
    "view published form_schema entities",
    "view own application entities",
    "view published application entities",
    "view application_attachment entities",
    "create applicant entities",
    "edit own applicant entities",
    "view own applicant entities",
    "create application_comment entities",
    "view application_comment entities",
    "view published form_schema_group entities",
  ];
  $available = \Drupal::service("user.permissions")->getPermissions();
  if ($missing = array_diff($permissions, array_keys($available))) {
    throw new RuntimeException("Unavailable Nuxt service permissions: " . implode(", ", $missing));
  }
  $role = \Drupal\user\Entity\Role::load("service_account");
  foreach (array_diff($role->getPermissions(), $permissions) as $permission) {
    $role->revokePermission($permission);
  }
  foreach ($permissions as $permission) {
    $role->grantPermission($permission);
  }
  $role->save();

  $password = getenv("NUXT_DRUPAL_SERVICE_PASSWORD");
  if ($password === FALSE || $password === "") {
    throw new RuntimeException("Missing generated Nuxt service password.");
  }
  $account->setPassword($password)->save();
  \Drupal::service("file.htaccess_writer")->ensure();
  '
  drush config:set --yes beantragt_group.settings frontend_base_url "$BEANTRAGT_SELFHOST_UI_URL"
  drush cache:rebuild
  drush state:set beantragt.selfhost_base_initialized 1 --input-format=integer
fi
if [[ "${BEANTRAGT_EXAMPLE_DATA:-0}" == 1 ]]; then
  bash deploy/selfhost/example/seed.sh local
  drush cache:rebuild
fi
drush state:set beantragt.selfhost_initialized 1 --input-format=integer
