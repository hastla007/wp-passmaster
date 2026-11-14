#!/usr/bin/env bash
# Browser E2E tests (Playwright + node:test) against a disposable WordPress site.
#
#   tests/e2e/run.sh [file.test.js …]
#
# Without WPCAP_A11Y_BASE/WPCAP_A11Y_WP_PATH the site is installed and served by tests/support/wp-env.sh
# (PHP, MariaDB/MySQL test database, WPCAP_WP_CORE or network for the WordPress clone). Browser:
# WPCAP_E2E_BROWSER=chromium (default) | firefox | webkit. Failure screenshots: tests/e2e/artifacts/.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

STARTED=0
if [ -z "${WPCAP_A11Y_BASE:-}" ] || [ -z "${WPCAP_A11Y_WP_PATH:-}" ]; then
	ENV_LINE="$("$HERE/../support/wp-env.sh" start)" || { echo "e2e: could not start the WordPress test site" >&2; exit 2; }
	eval "$ENV_LINE"
	STARTED=1
fi
cleanup() { if [ "$STARTED" = 1 ]; then "$HERE/../support/wp-env.sh" stop; fi; }
trap cleanup EXIT

rm -rf artifacts
node --test --test-concurrency=1 --test-reporter=spec "$@"
