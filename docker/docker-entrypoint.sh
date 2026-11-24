#!/bin/sh
set -e

load_secret_environment() {
  [ -n "${BEANTRAGT_SECRET_ENV_NAMES:-}" ] || return 0

  old_ifs="$IFS"
  IFS=','
  for variable_name in $BEANTRAGT_SECRET_ENV_NAMES; do
    case "$variable_name" in
      ''|*[!A-Z0-9_]*|[0-9]*)
        echo "ERROR: Invalid Docker Secret environment name." >&2
        exit 1
        ;;
    esac
    secret_file="/run/secrets/$variable_name"
    if [ ! -r "$secret_file" ]; then
      echo "ERROR: Required Docker Secret is not readable: $variable_name" >&2
      exit 1
    fi
    secret_value="$(cat "$secret_file")"
    export "$variable_name=$secret_value"
  done
  IFS="$old_ifs"
}

load_secret_environment

# Production mounts /tmp as tmpfs, which masks the directory created in the
# image layer. Recreate Drupal's configured temporary directory on every start.
mkdir -p /tmp/drupal
chmod 700 /tmp/drupal

if [ -n "$COOKIE_DOMAIN" ]; then
  if ! echo "$COOKIE_DOMAIN" | grep -qE '^\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*$'; then
    echo "ERROR: COOKIE_DOMAIN '$COOKIE_DOMAIN' has invalid format. Expected: .example.com"
    exit 1
  fi

  cat > /app/data/web/sites/default/services.yml <<YAML
parameters:
  session.storage.options:
    gc_probability: 1
    gc_divisor: 100
    gc_maxlifetime: 200000
    cookie_lifetime: 2000000
    cookie_domain: '${COOKIE_DOMAIN}'
YAML
fi

MSMTP_HOST="${SMTP_HOST:-mailpit}"
MSMTP_PORT="${SMTP_PORT:-1025}"
MSMTP_FROM="${SMTP_FROM:-noreply@beantragt.ai}"
MSMTP_EHLO_HOST="${SMTP_EHLO_HOST:-${MSMTP_FROM#*@}}"
MAIL_MODE="${BEANTRAGT_MAIL_MODE:-mailpit}"
MSMTP_PASSWORD_FILE="${SMTP_PASSWORD_FILE:-}"

if [ -n "$MSMTP_PASSWORD_FILE" ]; then
  case "$MSMTP_PASSWORD_FILE" in
    *[!A-Za-z0-9_./-]*|[!/]*)
      echo "ERROR: SMTP_PASSWORD_FILE must be an absolute path using only letters, digits, underscores, dots, slashes and hyphens." >&2
      exit 1
      ;;
  esac
  if [ -n "${SMTP_PASSWORD:-}" ] || [ ! -f "$MSMTP_PASSWORD_FILE" ] || [ ! -r "$MSMTP_PASSWORD_FILE" ] || [ ! -s "$MSMTP_PASSWORD_FILE" ]; then
    echo "ERROR: SMTP_PASSWORD_FILE must be a readable, nonempty file and cannot be combined with SMTP_PASSWORD." >&2
    exit 1
  fi
fi

case "$MAIL_MODE" in
  mailpit)
    if [ -n "${SMTP_HOST:-}" ] && [ "$SMTP_HOST" != "mailpit" ]; then
      echo "ERROR: mailpit mode requires SMTP_HOST=mailpit." >&2
      exit 1
    fi
    if [ -n "${SMTP_PORT:-}" ] && [ "$SMTP_PORT" != "1025" ]; then
      echo "ERROR: mailpit mode requires SMTP_PORT=1025." >&2
      exit 1
    fi
    if [ -n "${SMTP_USER:-}" ] || [ -n "${SMTP_PASSWORD:-}" ] || [ -n "$MSMTP_PASSWORD_FILE" ]; then
      echo "ERROR: mailpit mode must not receive SMTP credentials." >&2
      exit 1
    fi
    MSMTP_HOST="mailpit"
    MSMTP_PORT="1025"
    ;;
  smtp)
    if [ -z "${SMTP_HOST:-}" ]; then
      echo "ERROR: smtp mode requires SMTP_HOST." >&2
      exit 1
    fi
    if { [ -n "${SMTP_USER:-}" ] && [ -z "${SMTP_PASSWORD:-}$MSMTP_PASSWORD_FILE" ]; } \
      || { [ -z "${SMTP_USER:-}" ] && [ -n "${SMTP_PASSWORD:-}$MSMTP_PASSWORD_FILE" ]; }; then
      echo "ERROR: smtp mode requires SMTP_USER together with SMTP_PASSWORD or SMTP_PASSWORD_FILE." >&2
      exit 1
    fi
    ;;
  *)
    echo "ERROR: BEANTRAGT_MAIL_MODE must be 'mailpit' or 'smtp'." >&2
    exit 1
    ;;
esac

export BEANTRAGT_MAIL_MODE="$MAIL_MODE"

# Every value below becomes one msmtp directive: a line break would add another.
for msmtp_value in "$MSMTP_HOST" "$MSMTP_PORT" "$MSMTP_FROM" "$MSMTP_EHLO_HOST" "${SMTP_USER:-}" "${SMTP_PASSWORD:-}"; do
  case "$msmtp_value" in
    *[[:cntrl:]]*)
      echo "ERROR: SMTP settings must not contain control characters." >&2
      exit 1
      ;;
  esac
done
unset msmtp_value

msmtp_umask="$(umask)"
umask 077
cat > /tmp/msmtprc <<MSMTP
defaults
logfile /tmp/msmtp.log

account default
host ${MSMTP_HOST}
port ${MSMTP_PORT}
from ${MSMTP_FROM}
domain ${MSMTP_EHLO_HOST}
MSMTP

if [ "$MAIL_MODE" = "smtp" ] && [ -n "${SMTP_USER:-}" ]; then
  cat >> /tmp/msmtprc <<MSMTP
tls on
tls_starttls on
auth on
user ${SMTP_USER}
MSMTP
  if [ -n "$MSMTP_PASSWORD_FILE" ]; then
    # Read file-backed credentials on demand without exporting them to PHP or cron.
    printf 'passwordeval cat -- %s\n' "$MSMTP_PASSWORD_FILE" >> /tmp/msmtprc
  else
    printf 'password %s\n' "$SMTP_PASSWORD" >> /tmp/msmtprc
  fi
else
  cat >> /tmp/msmtprc <<MSMTP
tls off
auth off
MSMTP
fi

chmod 600 /tmp/msmtprc
umask "$msmtp_umask"
unset msmtp_umask

supercronic /etc/crontab &

exec "$@"
