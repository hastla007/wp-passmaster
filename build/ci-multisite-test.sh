#!/usr/bin/env bash
# Multisite leg of the install test (CI job "install-test", leg "multisite"): installs the release ZIP into a
# fresh WordPress multisite network and checks network activation, site deletion and the uninstall.
#
#   bash build/ci-multisite-test.sh [path/to/pass-captain.zip]
#
# 1. `wp core multisite-install` (subdirectories) plus WPCAP_MS_SITES-1 further sites (default 30 sites).
# 2. Network activation: Plugin::activate() installs the first 25 sites within its time budget right away
#    (Plugin::NETWORK_ACTIVATION_SITES), every further site only gets a wpcap_run_upgrade cron event. Checked:
#    installed + deferred = all sites, at most 25 installed, the deferred ones have the event and no schema.
#    Then the cron event runs per deferred site (WP-Cron is disabled) and installs it.
# 3. Deleting a site drops its plugin tables (filter wpmu_drop_tables), no orphaned tables.
# 4. Uninstall with mixed opt-in: every second site switches "Daten beim Loeschen entfernen" on. After
#    `wp plugin uninstall` the opted-in sites have no plugin tables/options left, the others keep them;
#    network-wide user meta stays (not every site opted in), per-site user options of opted-in sites are gone;
#    no plugin cron events are left on any site.
#
# Environment: WPCAP_IT_DB_HOST/_USER/_PASS/_NAME, WPCAP_IT_WP_VERSION, WPCAP_IT_WP_CORE_DIR, WPCAP_IT_WP_CLI,
# WPCAP_IT_WORK, WPCAP_IT_KEEP (see build/ci-install-test.sh), WPCAP_MS_SITES [30].
set -euo pipefail
export LC_ALL=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP="$(realpath "${1:-${ROOT}/build/dist/pass-captain.zip}")"
SLUG="pass-captain"
WP_CLI_VERSION="2.12.0"
DB_HOST="${WPCAP_IT_DB_HOST:-127.0.0.1}"
DB_USER="${WPCAP_IT_DB_USER:-wp}"
DB_PASS="${WPCAP_IT_DB_PASS:-wp}"
DB_NAME="${WPCAP_IT_DB_NAME:-wp}"
WP_VERSION="${WPCAP_IT_WP_VERSION:-latest}"
SITES="${WPCAP_MS_SITES:-30}"
WORK="${WPCAP_IT_WORK:-$(mktemp -d "${TMPDIR:-/tmp}/wpcap-ms.XXXXXX")}"
mkdir -p "${WORK}"
[ -f "${ZIP}" ] || { echo "ZIP not found: ${ZIP} (run bash build/release.sh first)" >&2; exit 1; }

failures=0
fail() { echo "FAIL  $*" >&2; failures=$((failures + 1)); }
ok() { echo "ok    $*"; }
section() { echo; echo "== $* =="; }

WP_CLI="${WPCAP_IT_WP_CLI:-${WORK}/wp-cli.phar}"
if [ ! -f "${WP_CLI}" ]; then
	curl -fsSL -o "${WP_CLI}" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
fi
WP_PATH="${WORK}/wordpress"
DEBUG_LOG="${WORK}/debug.log"
PREFIX="wpms$(date +%s | tail -c 6)_"
HELPER="${ROOT}/build/ci-multisite.php"
wp() { php -d memory_limit=512M "${WP_CLI}" --path="${WP_PATH}" --allow-root "$@"; }
# JSON field of the helper output: jq-free (php).
json() { php -r '$d = json_decode( stream_get_contents( STDIN ), true ); echo eval( "return " . $argv[1] . ";" );' "$1"; }

cleanup() {
	wp eval 'global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->base_prefix ) . "%" ) ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }' >/dev/null 2>&1 || true
	[ "${WPCAP_IT_KEEP:-}" = "1" ] || rm -rf "${WORK}"
}
trap cleanup EXIT

section "WordPress multisite with ${SITES} sites"
if [ -n "${WPCAP_IT_WP_CORE_DIR:-}" ]; then
	mkdir -p "${WP_PATH}" && cp -a "${WPCAP_IT_WP_CORE_DIR}/." "${WP_PATH}/"
	rm -rf "${WP_PATH}/.git" "${WP_PATH}/wp-config.php" "${WP_PATH}/wp-content"
else
	wp core download --version="${WP_VERSION}" --skip-content --force
fi
mkdir -p "${WP_PATH}/wp-content/plugins" "${WP_PATH}/wp-content/themes"
wp config create --dbname="${DB_NAME}" --dbuser="${DB_USER}" --dbpass="${DB_PASS}" --dbhost="${DB_HOST}" \
	--dbprefix="${PREFIX}" --skip-check --force --extra-php <<PHP
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '${DEBUG_LOG}' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
PHP
wp core multisite-install --url=http://localhost --title="Pass Captain Multisite CI" --admin_user=admin \
	--admin_password=admin --admin_email=admin@example.org --skip-email >/dev/null
for i in $(seq 2 "${SITES}"); do wp site create --slug="site${i}" --title="Site ${i}" --email=admin@example.org >/dev/null; done
count="$(wp site list --field=blog_id | wc -l)"
[ "${count}" = "${SITES}" ] && ok "network with ${count} sites" || fail "network has ${count} sites, expected ${SITES}"

section "Network activation (25 sites now, the rest via wpcap_run_upgrade)"
wp plugin install "${ZIP}" >/dev/null
out="$(wp plugin activate "${SLUG}" --network 2>&1)" || fail "network activation failed: ${out}"
grep -E 'Fatal error|Warning|Notice|Deprecated' <<<"${out}" | grep -q "/plugins/${SLUG}/" && fail "PHP errors during the network activation: ${out}"
state="$(wp eval-file "${HELPER}" state)"
installed="$(json 'count( array_filter( $d["sites"], static fn( $s ) => "" !== $s["db_version"] && $s["tables"] > 0 ) )' <<<"${state}")"
deferred="$(json 'count( array_filter( $d["sites"], static fn( $s ) => "" === $s["db_version"] && 0 === $s["tables"] && in_array( "wpcap_run_upgrade", $s["cron"], true ) ) )' <<<"${state}")"
if [ "${installed}" -ge 1 ] && [ "${installed}" -le 25 ] && [ "$((installed + deferred))" = "${SITES}" ]; then
	ok "activation request installed ${installed} sites, deferred ${deferred} (wpcap_run_upgrade scheduled)"
else
	fail "after the network activation: ${installed} installed, ${deferred} deferred of ${SITES}"
fi
[ "${SITES}" -le 25 ] || [ "${deferred}" -ge "$((SITES - 25))" ] && ok "at most 25 sites in the activation request" || fail "more than 25 sites installed in the activation request"
for url in $(json 'implode( " ", array_map( static fn( $s ) => $s["url"], array_filter( $d["sites"], static fn( $s ) => "" === $s["db_version"] ) ) )' <<<"${state}"); do
	wp cron event run wpcap_run_upgrade --url="${url}" >/dev/null || fail "wpcap_run_upgrade on ${url}"
done
state="$(wp eval-file "${HELPER}" state)"
installed="$(json 'count( array_filter( $d["sites"], static fn( $s ) => "" !== $s["db_version"] && $s["tables"] > 0 ) )' <<<"${state}")"
[ "${installed}" = "${SITES}" ] && ok "after the deferred cron events all ${SITES} sites are installed" || fail "only ${installed} of ${SITES} sites installed after wpcap_run_upgrade"

section "Delete a site"
victim="$(wp site list --field=blog_id | tail -n 1)"
wp site delete "${victim}" --yes >/dev/null
state="$(wp eval-file "${HELPER}" state)"
[ "$(json '$d["orphan_tables"]' <<<"${state}")" = "0" ] && ok "site ${victim} deleted, its plugin tables dropped" || fail "$(json '$d["orphan_tables"]' <<<"${state}") plugin tables of deleted sites left"
SITES=$((SITES - 1))

section "Uninstall with mixed opt-in"
optin="$(json 'implode( ",", array_map( static fn( $s ) => $s["id"], array_values( array_filter( $d["sites"], static fn( $s ) => 0 === $s["id"] % 2 ) ) ) )' <<<"${state}")"
wp eval-file "${HELPER}" optin "${optin}" >/dev/null
wp plugin deactivate "${SLUG}" --network >/dev/null
out="$(wp plugin uninstall "${SLUG}" 2>&1)" || fail "uninstall failed: ${out}"
state="$(wp eval-file "${HELPER}" state)"
bad_optin="$(json 'implode( ",", array_map( static fn( $s ) => $s["id"], array_filter( $d["sites"], static fn( $s ) => 0 === $s["id"] % 2 && ( $s["tables"] > 0 || $s["options"] > 0 ) ) ) )' <<<"${state}")"
bad_keep="$(json 'implode( ",", array_map( static fn( $s ) => $s["id"], array_filter( $d["sites"], static fn( $s ) => 1 === $s["id"] % 2 && ( 0 === $s["tables"] || "" === $s["db_version"] ) ) ) )' <<<"${state}")"
cron_left="$(json 'implode( ",", array_map( static fn( $s ) => $s["id"], array_filter( $d["sites"], static fn( $s ) => [] !== $s["cron"] ) ) )' <<<"${state}")"
[ -z "${bad_optin}" ] && ok "opted-in sites (${optin}) have no plugin tables or options left" || fail "opted-in sites with data left: ${bad_optin}"
[ -z "${bad_keep}" ] && ok "the other sites keep their tables and schema version" || fail "sites without opt-in lost data: ${bad_keep}"
[ -z "${cron_left}" ] && ok "no plugin cron events left on any site" || fail "plugin cron events left on sites ${cron_left}"
meta="$(wp eval-file "${HELPER}" usermeta)"
[ "$(json '$d["global"]' <<<"${meta}")" -ge 1 ] && ok "network-wide wpcap_* user meta kept (not every site opted in)" || fail "network-wide user meta removed although sites keep their data"
[ "$(json '$d["all"] - $d["global"]' <<<"${meta}")" = "0" ] && ok "per-site wpcap_* user options of opted-in sites removed" || fail "per-site user options left: ${meta}"

if [ -s "${DEBUG_LOG}" ] && grep -q "/plugins/${SLUG}/" "${DEBUG_LOG}"; then
	fail "PHP errors from the plugin in debug.log"; grep "/plugins/${SLUG}/" "${DEBUG_LOG}" | head -20
fi

echo
if [ "${failures}" -gt 0 ]; then echo "${failures} check(s) failed." >&2; exit 1; fi
echo "Multisite install test passed."
