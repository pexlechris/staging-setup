#!/usr/bin/env bash
#
# setup_staging.sh — Initialize staging environment and clean up
# Fri Sep 24 17:22:00 EEST 2025
#

set -euo pipefail

# — Configuration —
REPO_URL="https://raw.githubusercontent.com/pexlechris/staging-setup/main"
PHP_SCRIPT="staging-setup.php"
SH_SCRIPT="$(basename "$0")"

# — Cleanup function —
cleanup() {
  rm -f "${PHP_SCRIPT}" "${SH_SCRIPT}"
  echo "Files ${PHP_SCRIPT} & ${SH_SCRIPT} deleted"
}
trap cleanup EXIT

# — 1) Download PHP script directly to root —
echo "Downloading ${PHP_SCRIPT}..."
wget -q -O "./${PHP_SCRIPT}" "${REPO_URL}/${PHP_SCRIPT}"
chmod 644 "./${PHP_SCRIPT}"

# — 2) Execute PHP script —
echo "Running ${PHP_SCRIPT}..."
php "./${PHP_SCRIPT}" --env=staging

# — 3) Exit (trap will clean up and echo message) —
exit 0
