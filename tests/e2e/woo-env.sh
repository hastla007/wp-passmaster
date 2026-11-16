#!/usr/bin/env bash
# Second disposable WordPress site with WooCommerce for tests/e2e/woocommerce-blocks.test.js.
#
#   eval "$(WPCAP_WOO_ZIP=/path/to/woocommerce.zip tests/e2e/woo-env.sh start)"
#   tests/e2e/woo-env.sh stop
#
# Uses tests/support/wp-env.sh with its own directory, table prefix and port (WPCAP_WOO_WP_DIR, default
# ${TMPDIR:-/tmp}/wpcap-browser-woo; prefix wpwc_; WPCAP_WOO_PORT, default 8892). Same WPCAP_DB_* and
# WPCAP_WP_CORE/WPCAP_WP_VERSION as the main site. Prints "export WPCAP_E2E_WOO_BASE=… WPCAP_E2E_WOO_WP_PATH=…".
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export WPCAP_WP_DIR="${WPCAP_WOO_WP_DIR:-${TMPDIR:-/tmp}/wpcap-browser-woo}"
export WPCAP_WP_PORT="${WPCAP_WOO_PORT:-8892}"
export WPCAP_DB_PREFIX="${WPCAP_WOO_DB_PREFIX:-wpwc_}"

if [ "${1:-start}" = stop ]; then exec "$HERE/../support/wp-env.sh" stop; fi

"$HERE/../support/wp-env.sh" start > /dev/null
if [ ! -f "$WPCAP_WP_DIR/wp-content/plugins/woocommerce/woocommerce.php" ]; then
	[ -n "${WPCAP_WOO_ZIP:-}" ] || { echo "Set WPCAP_WOO_ZIP to a woocommerce.zip" >&2; exit 2; }
	TMP="$(mktemp -d)"
	unzip -q "$WPCAP_WOO_ZIP" -d "$TMP"
	mv "$TMP/woocommerce" "$WPCAP_WP_DIR/wp-content/plugins/woocommerce"
	rm -rf "$TMP"
fi
echo "export WPCAP_E2E_WOO_BASE='http://127.0.0.1:$WPCAP_WP_PORT' WPCAP_E2E_WOO_WP_PATH='$WPCAP_WP_DIR'"
