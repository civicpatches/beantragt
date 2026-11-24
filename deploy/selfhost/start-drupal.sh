#!/bin/sh
set -eu

load_secret() {
  value=$(cat "/run/beantragt/$2/$1")
  test -n "$value"
  export "$1=$value"
}

load_secret DB_PASSWORD db-client
load_secret DRUPAL_HASH_SALT drupal
load_secret BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY drupal
load_secret BEANTRAGT_DOCGEN_TOKEN docgen-client

load_secret NUXT_DRUPAL_SERVICE_PASSWORD nuxt

if [ "$1" = php-fpm ]; then
  if [ "${BEANTRAGT_MAIL_MODE:-mailpit}" = smtp ]; then
    : "${SMTP_HOST:?Self-host SMTP requires SMTP_HOST.}"
    : "${SMTP_USER:?Self-host SMTP requires SMTP_USER.}"
    : "${SMTP_FROM:?Self-host SMTP requires SMTP_FROM.}"
    : "${SMTP_PASSWORD_FILE:?Self-host SMTP requires a password file.}"
    if [ ! -f "$SMTP_PASSWORD_FILE" ] || [ ! -r "$SMTP_PASSWORD_FILE" ] || [ ! -s "$SMTP_PASSWORD_FILE" ]; then
      echo 'The SMTP password file must be readable by Drupal and nonempty.' >&2
      exit 1
    fi
  fi
  case "${BEANTRAGT_SELFHOST_UI_URL:-}" in
    https://*)
      case "${BEANTRAGT_SELFHOST_DRUPAL_URL:-}" in
        https://*) ;;
        *) echo 'An HTTPS UI requires an HTTPS DRUPAL_PUBLIC_URL.' >&2; exit 1 ;;
      esac
      if [ -z "${DRUPAL_REVERSE_PROXY_ADDRESSES:-}" ]; then
        echo 'The HTTPS self-host stack requires DRUPAL_REVERSE_PROXY_ADDRESSES.' >&2
        exit 1
      fi
      ;;
  esac
  /bin/bash deploy/selfhost/install.sh
  exec /docker-entrypoint.sh "$@"
fi

# Docker exec does not inherit variables exported by the container entrypoint.
exec "$@"
