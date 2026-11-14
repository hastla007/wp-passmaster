#!/usr/bin/env bash
# CI entry point for the browser-based suites: JS unit tests, accessibility release gate, E2E.
#
#   tests/ci/browser-tests.sh [js|a11y|e2e|all]      (default: all)
#
# js:   node:test unit tests of assets/*.js (no services needed)
# a11y: tests/a11y/gate.sh – axe (public pages + all admin screens) + keyboard walkthrough
# e2e:  tests/e2e/run.sh – Playwright (Chromium) checkout, portal, scanner, offline PWA
#       (+ WooCommerce Blocks when WPCAP_WOO_ZIP is set)
#
# a11y/e2e need PHP (mysqli, sodium, gd), a MariaDB/MySQL test database (WPCAP_DB_HOST/_USER/_PASS/_NAME,
# defaults 127.0.0.1/wp/wp/wp_browser; the user needs CREATE DATABASE or the database must exist), git
# access to github.com for the WordPress clone (or WPCAP_WP_CORE) and Playwright + Chromium. Playwright is
# installed on the fly when it is missing (npm i --no-save playwright + npx playwright install --with-deps).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WHAT="${1:-all}"
status=0

ensure_playwright() {
	if node -e "require('playwright')" 2>/dev/null; then return; fi
	(cd "$ROOT/tests/a11y" && npm i --no-save --no-audit --no-fund playwright@1)
	(cd "$ROOT/tests/a11y" && npx playwright install --with-deps chromium)
	export NODE_PATH="$ROOT/tests/a11y/node_modules${NODE_PATH:+:$NODE_PATH}"
}

if [ "$WHAT" = js ] || [ "$WHAT" = all ]; then
	echo "::group::JS unit tests"
	(cd "$ROOT/tests/js" && npm test) || status=1
	echo "::endgroup::"
fi
if [ "$WHAT" = a11y ] || [ "$WHAT" = e2e ] || [ "$WHAT" = all ]; then
	ensure_playwright
	(cd "$ROOT/tests/a11y" && [ -d node_modules/axe-core ] || npm ci --no-audit --no-fund)
	# One site for both suites (installed on first use, stopped at the end).
	ENV_LINE="$("$ROOT/tests/support/wp-env.sh" start)"
	eval "$ENV_LINE"
	trap '"$ROOT/tests/support/wp-env.sh" stop' EXIT
fi
if [ "$WHAT" = a11y ] || [ "$WHAT" = all ]; then
	echo "::group::Accessibility gate"
	"$ROOT/tests/a11y/gate.sh" || status=1
	echo "::endgroup::"
fi
if [ "$WHAT" = e2e ] || [ "$WHAT" = all ]; then
	echo "::group::Browser E2E (${WPCAP_E2E_BROWSER:-chromium})"
	"$ROOT/tests/e2e/run.sh" || status=1
	echo "::endgroup::"
fi
exit "$status"
