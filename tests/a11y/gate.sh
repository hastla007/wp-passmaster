#!/usr/bin/env bash
# Accessibility release gate: axe-core scan (public pages + every Pass Captain admin screen) and the
# keyboard walkthrough. Exit code != 0 when a violation at or above WPCAP_A11Y_FAIL_ON (default: minor,
# i.e. any axe violation) is found, a page does not answer with HTTP 200, or a keyboard check fails.
#
#   tests/a11y/gate.sh
#
# Without WPCAP_A11Y_BASE/WPCAP_A11Y_WP_PATH a disposable WordPress site is installed and served by
# tests/support/wp-env.sh (needs PHP, a MariaDB/MySQL test database and either WPCAP_WP_CORE or network
# access for the WordPress clone; see that script for WPCAP_DB_* and WPCAP_WP_*). Needs Playwright with
# Chromium (global install or `npm i --no-save playwright && npx playwright install chromium`).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

STARTED=0
if [ -z "${WPCAP_A11Y_BASE:-}" ] || [ -z "${WPCAP_A11Y_WP_PATH:-}${WPCAP_A11Y_FIXTURE:-}" ]; then
	ENV_LINE="$("$HERE/../support/wp-env.sh" start)" || { echo "a11y gate: could not start the WordPress test site" >&2; exit 2; }
	eval "$ENV_LINE"
	STARTED=1
fi
cleanup() { if [ "$STARTED" = 1 ]; then "$HERE/../support/wp-env.sh" stop; fi; }
trap cleanup EXIT

if [ ! -d node_modules/axe-core ]; then npm ci --no-audit --no-fund >&2 || exit 2; fi

status=0
node run.js --fail-on="${WPCAP_A11Y_FAIL_ON:-minor}" || status=1
node keyboard.js || status=1
if [ "$status" = 0 ]; then echo "a11y gate: passed"; else echo "a11y gate: FAILED" >&2; fi
exit "$status"
