#!/bin/sh
# Starts veraPDF-rest with a scrubbed environment (mounted read-only into the
# official verapdf/rest image). See gotenberg-launch.sh for the reason.
#
# Usage (compose): entrypoint [/bin/sh, <this file>], command [dumb-init, --, java, ...]
set -eu

exec env -i \
  PATH="$PATH" \
  HOME=/tmp \
  JAVA_HOME="${JAVA_HOME:-/opt/java/openjdk}" \
  VERAPDF_REST_VERSION="${VERAPDF_REST_VERSION:-}" \
  VERAPDF_MAX_FILE_SIZE="${VERAPDF_MAX_FILE_SIZE:-25}" \
  "$@"
