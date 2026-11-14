#!/usr/bin/env bash
# Installs the release ZIP into a fresh, real WordPress and checks it (CI job "install-test").
#
#   bash build/ci-install-test.sh [path/to/pass-captain.zip]
#
# 1. Package checks (no WordPress needed): one top-level folder pass-captain/, main file, readme,
#    uninstall.php, Composer autoloader, every tracked file of src/, templates/, assets/ and the
#    compiled .mo files present; no tests, docs, build/CI files, Composer/tool configs, Markdown,
#    .po sources or vendor test folders; no embedded private keys or live API keys.
# 2. Fresh WordPress (WP-CLI: core download, config, install) against a disposable database,
#    optionally with WooCommerce; `wp plugin install <zip> --activate` must not print PHP errors
#    and must not log any from the plugin's files.
# 3. Smoke check (build/ci-smoke.php via wp eval-file): tables, schema version, capabilities, cron,
#    REST routes, QR code (runtime dependency), WooCommerce/HPOS state; with WooCommerce once with
#    HPOS on and once off. Then deactivate/activate again and repeat the smoke check.
# 4. Update path (only with WPCAP_IT_UPGRADE_FROM/_FROM_ZIP): before step 2 the previous release is installed
#    and activated, realistic data is created with it (build/ci-data.php seed: orders, tickets, check-in,
#    refund, hold, job) and "Daten beim Loeschen entfernen" is switched on. Step 2 then installs the new ZIP
#    next to it. Checks: the new copy took over the activation (up to 1.4 the plugin was "wp-pass-captain/
#    wp-pass-captain.php"), the schema was upgraded to the current version (smoke check), no PHP errors, data
#    unchanged (snapshot) and consistent; deleting the old copy afterwards removes no data.
# 5. Uninstall (always; skip with WPCAP_IT_SKIP_UNINSTALL=1): with default settings `wp plugin uninstall`
#    keeps all tables and options (only the cron events are removed); reinstalling finds the data again;
#    with delete_data_on_uninstall=1 tables, options, transients, roles, capabilities, user meta and cron
#    events are gone (uninstall.php).
#
# Environment (defaults in brackets):
#   WPCAP_IT_DB_HOST [127.0.0.1]  WPCAP_IT_DB_USER [wp]  WPCAP_IT_DB_PASS [wp]  WPCAP_IT_DB_NAME [wp]
#   WPCAP_IT_WP_VERSION [latest]  WordPress version for `wp core download`
#   WPCAP_IT_WP_CORE_DIR          copy WordPress core from this directory instead of downloading it
#   WPCAP_IT_WC_VERSION           WooCommerce version ("latest" or e.g. 8.2.0); empty: without WooCommerce
#   WPCAP_IT_WC_ZIP               install WooCommerce from this ZIP instead of WordPress.org (needs WC_VERSION)
#   WPCAP_IT_WP_CLI               path to wp-cli.phar (downloaded if missing)
#   WPCAP_IT_WORK                 working directory [mktemp]; kept when WPCAP_IT_KEEP=1
#   WPCAP_IT_STATIC_ONLY=1        only run the package checks (step 1)
#   WPCAP_IT_UPGRADE_FROM         git ref of the previous release to update from (its ZIP is built from that
#                                 commit with Composer, honouring its .distignore), e.g. the 1.4.0 release commit
#   WPCAP_IT_UPGRADE_FROM_ZIP     ZIP of the previous release instead of building it
#   WPCAP_IT_SKIP_UNINSTALL=1     skip step 5
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
WC_VERSION="${WPCAP_IT_WC_VERSION:-}"
WORK="${WPCAP_IT_WORK:-$(mktemp -d "${TMPDIR:-/tmp}/wpcap-it.XXXXXX")}"
mkdir -p "${WORK}"
[ "${WPCAP_IT_KEEP:-}" = "1" ] || trap 'rm -rf "${WORK}"' EXIT

[ -f "${ZIP}" ] || { echo "ZIP not found: ${ZIP} (run bash build/release.sh first)" >&2; exit 1; }

failures=0
fail() { echo "FAIL  $*" >&2; failures=$((failures + 1)); }
ok() { echo "ok    $*"; }
section() { echo; echo "== $* =="; }

# ---------------------------------------------------------------------------------------------
section "Package contents: ${ZIP}"
LIST="${WORK}/zip-list.txt"
unzip -Z1 "${ZIP}" > "${LIST}"
echo "$(wc -l < "${LIST}") entries, $(du -h "${ZIP}" | cut -f1)"

if grep -qv "^${SLUG}/" "${LIST}"; then fail "entries outside ${SLUG}/: $(grep -v "^${SLUG}/" "${LIST}" | head -5 | tr '\n' ' ')"; else ok "single top-level folder ${SLUG}/"; fi

for required in "${SLUG}.php" readme.txt uninstall.php vendor/autoload.php vendor/composer/autoload_real.php \
	vendor/composer/autoload_classmap.php vendor/endroid/qr-code/src/QrCode.php languages/pass-captain.pot; do
	if grep -qxF "${SLUG}/${required}" "${LIST}"; then ok "contains ${required}"; else fail "missing ${required}"; fi
done

# Every tracked runtime file of the commit the ZIP was built from must be in the package.
missing_tracked=0
while IFS= read -r file; do
	grep -qxF "${SLUG}/${file}" "${LIST}" || { fail "tracked file missing in ZIP: ${file}"; missing_tracked=$((missing_tracked + 1)); }
done < <(git -C "${ROOT}" ls-files -- src templates assets 'languages/*.mo' | grep -v '\.md$')
[ "${missing_tracked}" -eq 0 ] && ok "all tracked files of src/, templates/, assets/ and languages/*.mo present"

# Development files that must never ship.
FORBIDDEN='^pass-captain/(tests|docs|build|node_modules|\.github|\.claude|\.codex)/|/\.git(/|ignore$|attributes$)|/\.distignore$|^pass-captain/(composer\.(json|lock)|phpcs\.xml(\.dist)?|phpcs-baseline\.json|phpstan(-baseline)?\.neon(\.dist)?|\.gitleaks\.toml|phpunit\.xml(\.dist)?|package(-lock)?\.json|\.editorconfig)$|\.md$|\.po$|^pass-captain/vendor/[^/]+/[^/]+/(tests?|\.github)/|^pass-captain/vendor/bin/|\.(sh|neon|log|sql|bak|orig|swp)$|(^|/)\.DS_Store$'
if grep -E "${FORBIDDEN}" "${LIST}" > "${WORK}/forbidden.txt"; then
	fail "development files in the ZIP: $(head -10 "${WORK}/forbidden.txt" | tr '\n' ' ')"
else
	ok "no tests, docs, build/CI files, tool configs, Markdown or .po sources"
fi

# Embedded secrets (a full gitleaks scan of the unpacked ZIP runs as a separate CI step).
UNPACKED="${WORK}/unpacked"
rm -rf "${UNPACKED}" && mkdir -p "${UNPACKED}" && unzip -q "${ZIP}" -d "${UNPACKED}"
SECRET_RE='-----BEGIN ((RSA|EC|DSA|OPENSSH|ENCRYPTED) )?PRIVATE KEY-----|sk_live_[0-9A-Za-z]{16,}|rk_live_[0-9A-Za-z]{16,}|whsec_[0-9A-Za-z]{24,}|AKIA[0-9A-Z]{16}|gh[pousr]_[0-9A-Za-z]{36}|xox[baprs]-[0-9A-Za-z-]{10,}|live_[0-9A-Za-z]{30}'
if grep -rIEl -- "${SECRET_RE}" "${UNPACKED}" > "${WORK}/secrets.txt"; then
	fail "possible secrets in: $(sed "s|${UNPACKED}/||" "${WORK}/secrets.txt" | tr '\n' ' ')"
else
	ok "no private keys or live API keys"
fi

if [ "${WPCAP_IT_STATIC_ONLY:-}" = "1" ]; then
	[ "${failures}" -eq 0 ] || { echo "${failures} check(s) failed." >&2; exit 1; }
	echo "Package checks passed."
	exit 0
fi

# ---------------------------------------------------------------------------------------------
section "Fresh WordPress ${WP_VERSION}${WC_VERSION:+ + WooCommerce ${WC_VERSION}}"
WP_CLI="${WPCAP_IT_WP_CLI:-${WORK}/wp-cli.phar}"
if [ ! -f "${WP_CLI}" ]; then
	curl -fsSL -o "${WP_CLI}" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
fi
WP_PATH="${WORK}/wordpress"
DEBUG_LOG="${WORK}/debug.log"
PREFIX="wpit$(date +%s | tail -c 6)_"
wp() { php -d memory_limit=512M "${WP_CLI}" --path="${WP_PATH}" --allow-root "$@"; }

# Runs a WP-CLI command and fails on PHP errors in its output or new plugin errors in debug.log.
wp_checked() {
	local label="$1"; shift
	local out="${WORK}/wp-out.txt" before=0
	[ -f "${DEBUG_LOG}" ] && before="$(wc -l < "${DEBUG_LOG}")"
	if wp "$@" > "${out}" 2>&1; then status=0; else status=$?; fi
	cat "${out}"
	if [ "${status}" -ne 0 ]; then fail "${label}: wp $* exited with ${status}"; return 0; fi
	if grep -E '(PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated)[: ]' "${out}" | grep -q "/plugins/${SLUG}/"; then
		fail "${label}: PHP errors from the plugin in the WP-CLI output"
	elif [ -f "${DEBUG_LOG}" ] && tail -n +"$((before + 1))" "${DEBUG_LOG}" | grep -E 'PHP (Fatal error|Parse error|Warning|Notice|Deprecated)' | grep -q "/plugins/${SLUG}/"; then
		fail "${label}: PHP errors from the plugin in debug.log"
		tail -n +"$((before + 1))" "${DEBUG_LOG}" | grep "/plugins/${SLUG}/" | head -20
	else
		ok "${label}"
	fi
}

if [ -n "${WPCAP_IT_WP_CORE_DIR:-}" ]; then
	mkdir -p "${WP_PATH}" && cp -a "${WPCAP_IT_WP_CORE_DIR}/." "${WP_PATH}/" && rm -rf "${WP_PATH}/.git"
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
define( 'WP_HTTP_BLOCK_EXTERNAL', false );
PHP
wp core install --url=http://localhost --title="Pass Captain CI" --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.org --skip-email
ok "WordPress $(wp core version) installed (table prefix ${PREFIX}, PHP $(php -r 'echo PHP_VERSION;'))"

cleanup_db() {
	wp eval 'global $wpdb; foreach ( $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . "%" ) ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }' >/dev/null 2>&1 || true
}
[ "${WPCAP_IT_KEEP:-}" = "1" ] || trap 'cleanup_db; rm -rf "${WORK}"' EXIT

if [ -n "${WC_VERSION}" ]; then
	if [ -n "${WPCAP_IT_WC_ZIP:-}" ]; then
		wp_checked "WooCommerce installed from ${WPCAP_IT_WC_ZIP} and activated" plugin install "${WPCAP_IT_WC_ZIP}" --activate
	elif [ "${WC_VERSION}" = "latest" ]; then
		wp_checked "WooCommerce installed and activated" plugin install woocommerce --activate
	else
		wp_checked "WooCommerce ${WC_VERSION} installed and activated" plugin install woocommerce --version="${WC_VERSION}" --activate
	fi
	wp option update woocommerce_coming_soon no >/dev/null 2>&1 || true
	echo "WooCommerce $(wp plugin get woocommerce --field=version)"
fi

# Builds the installable ZIP of an older commit like its build/release.sh did (git archive, composer --no-dev,
# .distignore). The plugin folder is named after the main file of that commit (wp-pass-captain up to 1.4).
build_ref_zip() {
	local ref="$1" out="$2" src="${WORK}/ref-src" pkg="${WORK}/ref-pkg" slug
	rm -rf "${src}" "${pkg}" && mkdir -p "${src}" "${pkg}"
	git -C "${ROOT}" archive --format=tar "${ref}" | tar -x -C "${src}"
	slug="$(cd "${src}" && ls ./*pass-captain.php | head -n1 | sed 's|^\./||; s|\.php$||')"
	[ -n "${slug}" ] || { echo "no main plugin file in ${ref}" >&2; return 1; }
	composer install --working-dir="${src}" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --quiet >&2
	mkdir -p "${pkg}/${slug}"
	rsync -a --exclude-from="${src}/.distignore" "${src}/" "${pkg}/${slug}/"
	(cd "${pkg}" && zip -qr -X "${out}" "${slug}")
	echo "${slug}"
}

DATA="${ROOT}/build/ci-data.php"
UPGRADE_FROM="${WPCAP_IT_UPGRADE_FROM:-}"
OLD_ZIP="${WPCAP_IT_UPGRADE_FROM_ZIP:-}"
OLD_SLUG=""
if [ -n "${UPGRADE_FROM}" ] && [ -z "${OLD_ZIP}" ]; then
	section "Build previous release from ${UPGRADE_FROM}"
	OLD_ZIP="${WORK}/previous.zip"
	OLD_SLUG="$(build_ref_zip "${UPGRADE_FROM}" "${OLD_ZIP}")"
	ok "built ${OLD_SLUG}.zip from $(git -C "${ROOT}" rev-parse --short "${UPGRADE_FROM}")"
fi
if [ -n "${OLD_ZIP}" ]; then
	[ -n "${OLD_SLUG}" ] || OLD_SLUG="$(unzip -Z1 "${OLD_ZIP}" | head -n1 | cut -d/ -f1)"
	section "Previous release: ${OLD_SLUG}"
	wp_checked "previous release installed and activated" plugin install "${OLD_ZIP}" --activate
	OLD_VERSION="$(wp plugin get "${OLD_SLUG}" --field=version)"
	OLD_DB="$(wp option get wpcap_db_version)"
	ok "previous release ${OLD_VERSION} active, schema ${OLD_DB}"
	if wp eval-file "${DATA}" seed upgrade > "${WORK}/seed.json"; then ok "data created with ${OLD_VERSION}: $(head -c 300 "${WORK}/seed.json")"; else fail "seeding data with the previous release"; cat "${WORK}/seed.json"; fi
	# The hardest case for the takeover: the operator opted in to data removal on uninstall.
	wp eval '$s = get_option( "wpcap_settings", array() ); $s = is_array( $s ) ? $s : array(); $s["delete_data_on_uninstall"] = 1; update_option( "wpcap_settings", $s );'
	wp eval-file "${DATA}" snapshot > "${WORK}/before-update.json"
fi

section "Install and activate ${SLUG}.zip"
wp_checked "plugin installed and activated from the ZIP" plugin install "${ZIP}" --activate
if wp plugin is-active "${SLUG}"; then ok "plugin is active"; else fail "plugin is not active"; fi
[ -f "${WP_PATH}/wp-content/plugins/${SLUG}/${SLUG}.php" ] && ok "installed to wp-content/plugins/${SLUG}/" || fail "unexpected install folder"
if [ -n "${OLD_ZIP}" ]; then
	if wp plugin is-active "${OLD_SLUG}"; then fail "previous copy ${OLD_SLUG} is still active"; else ok "new copy took over the activation of ${OLD_SLUG}"; fi
	active_count="$(wp option get active_plugins --format=json | php -r '$a = json_decode( stream_get_contents( STDIN ), true ); echo count( array_filter( (array) $a, static fn( $p ) => str_ends_with( $p, "pass-captain.php" ) ) );')"
	[ "${active_count}" = "1" ] && ok "exactly one active Pass Captain entry in active_plugins" || fail "${active_count} Pass Captain entries in active_plugins: $(wp option get active_plugins --format=json)"
fi

smoke() {
	local label="$1"; shift
	section "Smoke check: ${label}"
	wp_checked "smoke check (${label})" eval-file "${ROOT}/build/ci-smoke.php" "$@"
}

set_hpos() { # on|off
	local value; [ "$1" = "on" ] && value=yes || value=no
	wp option update woocommerce_custom_orders_table_data_sync_enabled no >/dev/null
	wp option update woocommerce_custom_orders_table_enabled "${value}" >/dev/null
	# Load the order storage once so WooCommerce creates/validates its tables for the new mode.
	wp eval 'wc_get_orders( array( "limit" => 1 ) );' >/dev/null
}

if [ -n "${WC_VERSION}" ]; then
	set_hpos on;  smoke "WooCommerce, HPOS on" on
	set_hpos off; smoke "WooCommerce, HPOS off" off
	set_hpos on
else
	smoke "without WooCommerce"
fi

section "Deactivate and activate again"
wp_checked "plugin deactivated" plugin deactivate "${SLUG}"
if wp plugin is-active "${SLUG}"; then fail "plugin still active after deactivation"; else ok "plugin is inactive"; fi
wp_checked "plugin activated again" plugin activate "${SLUG}"
if [ -n "${WC_VERSION}" ]; then smoke "after reactivation" on; else smoke "after reactivation"; fi

verify_data() { # label snapshot-file
	local label="$1"; shift
	if wp eval-file "${DATA}" verify "$@" > "${WORK}/verify.txt" 2>&1; then ok "${label}: $(sed 's/^ok *//' "${WORK}/verify.txt")"; else fail "${label}: $(cat "${WORK}/verify.txt")"; fi
	if wp eval-file "${DATA}" invariants > "${WORK}/inv.txt" 2>&1; then ok "${label}: invariants hold"; else fail "${label}: $(cat "${WORK}/inv.txt")"; fi
}
leftover() { # field of `build/ci-data.php leftovers` (arrays comma-separated)
	wp eval-file "${DATA}" leftovers | php -r '$d = json_decode( stream_get_contents( STDIN ), true ); $v = $d[ $argv[1] ] ?? null; echo is_array( $v ) ? implode( ",", $v ) : (string) $v;' "$1"
}
set_delete_data() { # 1|0
	wp eval "\$s = get_option( 'wpcap_settings', array() ); \$s = is_array( \$s ) ? \$s : array(); \$s['delete_data_on_uninstall'] = (int) '${1}'; update_option( 'wpcap_settings', \$s );"
}

if [ -n "${OLD_ZIP}" ]; then
	section "Update from ${OLD_SLUG} ${OLD_VERSION}: data"
	NEW_DB="$(wp option get wpcap_db_version)"
	[ "${NEW_DB}" != "${OLD_DB}" ] && ok "schema upgraded ${OLD_DB} -> ${NEW_DB}" || fail "schema version unchanged (${OLD_DB})"
	verify_data "after the update" "${WORK}/before-update.json"
	wp_checked "previous copy ${OLD_SLUG} deleted (runs its uninstall.php with delete_data_on_uninstall=1)" plugin delete "${OLD_SLUG}"
	[ ! -d "${WP_PATH}/wp-content/plugins/${OLD_SLUG}" ] && ok "folder of the previous copy removed" || fail "folder of the previous copy still there"
	verify_data "after deleting the previous copy" "${WORK}/before-update.json"
	if [ -n "${WC_VERSION}" ]; then smoke "after deleting the previous copy" on; else smoke "after deleting the previous copy"; fi
fi

if [ "${WPCAP_IT_SKIP_UNINSTALL:-}" != "1" ]; then
	section "Uninstall with default settings (data is kept)"
	if [ -z "${OLD_ZIP}" ]; then
		if wp eval-file "${DATA}" seed uninstall > "${WORK}/seed.json"; then ok "data created: $(head -c 200 "${WORK}/seed.json")"; else fail "seeding data"; cat "${WORK}/seed.json"; fi
	fi
	set_delete_data 0
	wp eval-file "${DATA}" snapshot > "${WORK}/before-uninstall.json"
	tables_before="$(leftover tables)"
	wp_checked "plugin deactivated" plugin deactivate "${SLUG}"
	if wp plugin is-active "${SLUG}"; then fail "plugin still active after deactivation"; fi
	wp_checked "plugin uninstalled (uninstall.php, files deleted)" plugin uninstall "${SLUG}"
	[ ! -d "${WP_PATH}/wp-content/plugins/${SLUG}" ] && ok "plugin folder removed" || fail "plugin folder still there"
	[ "$(leftover tables)" = "${tables_before}" ] && ok "all ${tables_before} plugin tables kept" || fail "plugin tables: $(leftover tables), before ${tables_before}"
	options="$(leftover options)"
	case ",${options}," in *,wpcap_settings,*) ok "settings kept" ;; *) fail "wpcap_settings removed: ${options}" ;; esac
	case ",${options}," in *,wpcap_db_version,*) ok "schema version kept" ;; *) fail "wpcap_db_version removed" ;; esac
	[ -n "$(leftover roles)" ] && ok "plugin roles kept" || fail "plugin roles removed"
	[ -z "$(leftover cron)" ] && ok "cron events removed" || fail "cron events left: $(leftover cron)"

	section "Reinstall after uninstall (data found again)"
	wp_checked "plugin installed and activated again" plugin install "${ZIP}" --activate
	verify_data "after reinstalling" "${WORK}/before-uninstall.json"
	if [ -n "${WC_VERSION}" ]; then smoke "after reinstalling" on; else smoke "after reinstalling"; fi

	section "Uninstall with delete_data_on_uninstall (all data removed)"
	set_delete_data 1
	wp_checked "plugin deactivated" plugin deactivate "${SLUG}"
	if wp plugin is-active "${SLUG}"; then fail "plugin still active after deactivation"; fi
	wp_checked "plugin uninstalled with data removal" plugin uninstall "${SLUG}"
	[ "$(leftover tables)" = "0" ] && ok "no plugin tables left" || fail "$(leftover tables) plugin tables left"
	[ -z "$(leftover options)" ] && ok "no wpcap_* options or transients left" || fail "options left: $(leftover options)"
	[ -z "$(leftover roles)" ] && ok "plugin roles removed" || fail "roles left: $(leftover roles)"
	[ -z "$(leftover admin_caps)" ] && ok "capabilities removed from administrator" || fail "administrator still has: $(leftover admin_caps)"
	[ "$(leftover usermeta)" = "0" ] && ok "no wpcap_* user meta left" || fail "$(leftover usermeta) wpcap_* user meta rows left"
	[ -z "$(leftover cron)" ] && ok "no cron events left" || fail "cron events left: $(leftover cron)"
fi

if [ -s "${DEBUG_LOG}" ] && grep -q "/plugins/${SLUG}/" "${DEBUG_LOG}"; then
	echo; echo "debug.log entries mentioning the plugin:"; grep "/plugins/${SLUG}/" "${DEBUG_LOG}" | head -30
fi

echo
if [ "${failures}" -gt 0 ]; then echo "${failures} check(s) failed." >&2; exit 1; fi
echo "Release ZIP install test passed."
