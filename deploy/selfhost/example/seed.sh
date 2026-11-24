#!/bin/bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../../.."
seed_dir=deploy/selfhost/example

case "${1:-}" in
  local)
    [[ "${BEANTRAGT_EXAMPLE_DATA:-}" == 1 && "${BEANTRAGT_MAIL_MODE:-}" == mailpit ]] ||
      { echo 'Musterstadt requires BEANTRAGT_EXAMPLE_DATA=1 and local Mailpit.' >&2; exit 1; }
    drush php:script "$seed_dir/workspace.php"
    BEANTRAGT_ALLOW_STARTER_FORM_SEED=1 \
      BEANTRAGT_STARTER_JURISDICTION_SLUG=musterstadt \
      BEANTRAGT_STARTER_JURISDICTION_LABEL='Stadt Musterstadt' \
      BEANTRAGT_STARTER_JURISDICTION_EMAIL=service@musterstadt.example.org \
      BEANTRAGT_STARTER_MODE=demo BEANTRAGT_STARTER_PUBLISH=1 \
      drush php:script "$seed_dir/starter-forms.php"
    drush php:script "$seed_dir/local-forms.php"
    bash "$seed_dir/seed.sh" catalogue
    drush php:script "$seed_dir/parking-cases.php"
    ;;
  catalogue)
    # Called after the starter forms and parking form in either installation.
    # This shared step seeds forms, templates and content. The public demo
    # separately imports the versioned application and bulk-case catalogue.
    drush php:script "$seed_dir/parking-templates.php"
    BEANTRAGT_ALLOW_DEMO_KITA_SEED=1 BEANTRAGT_DEMO_KITA_PUBLISH=1 \
      drush php:script "$seed_dir/day-care.php"
    BEANTRAGT_ALLOW_SHOWCASE_SEED=1 drush php:script "$seed_dir/showcase-forms.php"
    BEANTRAGT_ALLOW_SHOWCASE_SEED=1 drush php:script "$seed_dir/showcase-templates.php"
    # shellcheck disable=SC2016 # PHP variables are evaluated by PHP.
    BEANTRAGT_ALLOW_CONTENT_DEMO_SEED=1 drush php:eval '
      \Drupal::moduleHandler()->loadInclude("beantragt_content", "install");
      foreach (_beantragt_content_seed_demo_content(\Drupal::entityTypeManager(), getenv("BEANTRAGT_EXAMPLE_DATA") === "1") as $message) {
        print $message . PHP_EOL;
      }
    '
    ;;
  *)
    echo 'Usage: seed.sh {local|catalogue}' >&2
    exit 2
    ;;
esac
