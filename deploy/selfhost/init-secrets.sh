#!/bin/sh
set -eu
umask 077

# The init container is the only writer. Rename fully written files into place
# so an interrupted first start can be retried with the same values.
generate() {
  if [ ! -s "$1" ]; then
    temporary="$1.tmp"
    od -An -v -N32 -tx1 /dev/urandom | tr -d ' \n' > "$temporary"
    chmod 0444 "$temporary"
    mv "$temporary" "$1"
  fi
  if ! grep -qE '^[0-9a-f]{64}$' "$1"; then
    echo "Invalid generated secret file: $1" >&2
    exit 1
  fi
}

generate /generated/db-root/password
generate /generated/db-client/DB_PASSWORD
generate /generated/drupal/DRUPAL_HASH_SALT
generate /generated/drupal/BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY
generate /generated/nuxt/NUXT_DRUPAL_SERVICE_PASSWORD
generate /generated/docgen-client/BEANTRAGT_DOCGEN_TOKEN
generate /generated/gotenberg/password

# One client token per installation. Docgen receives only its SHA-256 digest.
digest=$(sha256sum /generated/docgen-client/BEANTRAGT_DOCGEN_TOKEN)
printf 'selfhost %s\n' "${digest%% *}" > /generated/docgen-server/tokens.tmp
chmod 0444 /generated/docgen-server/tokens.tmp
mv /generated/docgen-server/tokens.tmp /generated/docgen-server/tokens
