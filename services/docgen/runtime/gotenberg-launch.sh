#!/bin/sh
# Starts Gotenberg with a scrubbed environment (mounted read-only into the
# official gotenberg/gotenberg image; it is configuration, not a custom image).
#
# - Only the variables the image itself defines are passed on. Environment
#   injected by the orchestrator (DDEV passes every .ddev/.env value to every
#   container) never reaches LibreOffice, which had environment-leak CVEs.
# - The Basic Auth password comes from a secret file, because Gotenberg reads
#   it only from GOTENBERG_API_BASIC_AUTH_PASSWORD.
#
# The script runs as PID 1 and execs into the command, which should start
# with the init (tini), so PID 1 ends up with the scrubbed environment too.
#
# Usage (compose): entrypoint [/bin/sh, <this file>], command [/usr/bin/tini, --, gotenberg, --flags...]
set -eu

password_file="${GOTENBERG_PASSWORD_FILE:-/run/secrets/gotenberg_password}"
if [ ! -r "$password_file" ]; then
  echo "gotenberg-launch: password file is not readable for uid $(id -u): $password_file" >&2
  echo "gotenberg-launch: plain Compose mounts file secrets with their host mode. Docgen is run as a Swarm stack (deploy/README.md) or, for local operation, the file is kept readable (0644 inside a 0700 directory)." >&2
  exit 78
fi
password="$(cat "$password_file")"
if [ "${#password}" -lt 16 ]; then
  echo "gotenberg-launch: password must hold at least 16 characters" >&2
  exit 78
fi

exec env -i \
  PATH="$PATH" \
  HOME="${HOME:-/home/gotenberg}" \
  TZ=UTC \
  LANG=C.UTF-8 \
  LC_ALL=C.UTF-8 \
  PDFTK_BIN_PATH="${PDFTK_BIN_PATH:-}" \
  QPDF_BIN_PATH="${QPDF_BIN_PATH:-}" \
  EXIFTOOL_BIN_PATH="${EXIFTOOL_BIN_PATH:-}" \
  PDFCPU_BIN_PATH="${PDFCPU_BIN_PATH:-}" \
  LIBREOFFICE_BIN_PATH="${LIBREOFFICE_BIN_PATH:-}" \
  UNOCONVERTER_BIN_PATH="${UNOCONVERTER_BIN_PATH:-}" \
  GOTENBERG_VERSIONS_DIR_PATH="${GOTENBERG_VERSIONS_DIR_PATH:-}" \
  OTEL_TRACES_EXPORTER=none \
  OTEL_METRICS_EXPORTER=none \
  OTEL_LOGS_EXPORTER=none \
  GOTENBERG_API_BASIC_AUTH_USERNAME="${GOTENBERG_USERNAME:-docgen}" \
  GOTENBERG_API_BASIC_AUTH_PASSWORD="$password" \
  "$@"
