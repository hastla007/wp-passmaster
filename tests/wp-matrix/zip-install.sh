#!/usr/bin/env bash
# A178: the built release ZIP itself (not the git checkout) in a fresh WordPress install:
# 1. contents: required files present, no development files (tests, CI, dev dependencies, sources maps …)
# 2. install via `wp plugin install <zip>`, activate, schema created, no PHP warnings/notices/fatals
# 3. deactivate and uninstall with data removal leave nothing behind
# Usage: [WPCAP_ZIP_WP_VERSION=6.4] zip-install.sh <zip> <workdir>   (needs wp-cli and a MySQL/MariaDB reachable as wp/wp@127.0.0.1, db "wp";
#        the install uses its own table prefix, so it can share the database with other test installs)
set -uo pipefail
ZIP="$1"; WORK="$2"
SLUG="wp-pass-captain"
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }

[ -f "$ZIP" ] || { echo "ZIP not found: $ZIP" >&2; exit 2; }
LIST="$(unzip -Z1 "$ZIP")"

# --- 1. contents -------------------------------------------------------------------------------
TOP="$(cut -d/ -f1 <<<"$LIST" | sort -u)"
[ "$TOP" = "$SLUG" ] && ok "single top-level folder $SLUG/" || bad "unexpected top-level entries: $(tr '\n' ' ' <<<"$TOP")"
for f in wp-pass-captain.php readme.txt uninstall.php vendor/autoload.php languages/wp-pass-captain.pot src/Plugin.php languages/wp-pass-captain-en_US.mo; do
	grep -qx "$SLUG/$f" <<<"$LIST" && ok "contains $f" || bad "missing $f"
done
# Development files that must never ship.
FORBIDDEN='(^|/)(tests?|\.github|\.claude|node_modules|build|docs/release-acceptance)/|(^|/)(phpcs\.xml(\.dist)?|phpunit\.xml(\.dist)?|phpstan\.neon(\.dist)?|eslint\.config\.mjs|package(-lock)?\.json|composer\.lock|\.distignore|\.gitignore|\.gitattributes|\.editorconfig|TODO\.md|ARBEITSLISTE\.md|CLAUDE\.md)$|\.map$|(^|/)vendor/(squizlabs|wp-coding-standards|phpcompatibility|dealerdirect|phpcsstandards|phpunit|bin)/'
HITS="$(grep -E "$FORBIDDEN" <<<"$LIST" || true)"
[ -z "$HITS" ] && ok "no development files in the ZIP" || bad "development files in the ZIP: $(head -n 10 <<<"$HITS" | tr '\n' ' ')"
grep -qE '\.php$' <<<"$(grep "^$SLUG/vendor/composer/" <<<"$LIST")" && ok "optimized Composer autoloader present" || bad "Composer autoloader missing"

# --- 2. fresh install from the ZIP ---------------------------------------------------------------
WP="$WORK/wp"
rm -rf "$WP"; mkdir -p "$WP"
wpc() { wp --allow-root --path="$WP" "$@"; }
PFX="zipi_"
wpc core download --version="${WPCAP_ZIP_WP_VERSION:-latest}" --locale=en_US --quiet >/dev/null 2>&1 || { echo "wp core download failed" >&2; exit 2; }
wpc config create --dbname="${WPCAP_ZIP_DB_NAME:-wp}" --dbuser="${WPCAP_ZIP_DB_USER:-wp}" --dbpass="${WPCAP_ZIP_DB_PASS:-wp}" --dbhost="${WPCAP_ZIP_DB_HOST:-127.0.0.1}" --dbprefix="$PFX" --quiet >/dev/null
wpc config set WP_DEBUG true --raw >/dev/null; wpc config set WP_DEBUG_LOG true --raw >/dev/null; wpc config set WP_DEBUG_DISPLAY false --raw >/dev/null
wpc config set AUTOMATIC_UPDATER_DISABLED true --raw >/dev/null
wpc db query "DROP TABLE IF EXISTS $(wpc db query "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}%'" --skip-column-names | sed 's/NULL//')" >/dev/null 2>&1
wpc core install --url=http://localhost --title=ZIP --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null
LOG="$WP/wp-content/debug.log"; rm -f "$LOG"
tables() { wpc db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}wpcap\\_%'" --skip-column-names; }
options() { wpc db query "SELECT COUNT(*) FROM ${PFX}options WHERE option_name LIKE 'wpcap\\_%'" --skip-column-names; }
php_errors() { [ -f "$LOG" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$LOG" | grep -i "$SLUG" || true; }

wpc plugin install "$ZIP" --activate >/dev/null 2>&1 && ok "wp plugin install <zip> --activate" || bad "installing/activating the ZIP failed"
[ -L "$WP/wp-content/plugins/$SLUG" ] && bad "plugin folder is a symlink" || ok "plugin installed as a real folder from the ZIP"
wpc plugin is-active "$SLUG" && ok "plugin is active" || bad "plugin not active"
wpc eval 'PassCaptain\Plugin::maybe_upgrade("admin");' >/dev/null
T="$(tables)"; [ "$T" -ge 50 ] && ok "schema created ($T tables)" || bad "schema missing ($T tables)"
V="$(wpc option get wpcap_db_version 2>/dev/null)"; E="$(wpc eval 'echo PassCaptain\Installer::version();')"
[ -n "$V" ] && [ "$V" = "$E" ] && ok "db version $V recorded" || bad "db version '$V' (expected '$E')"
HV="$(wpc plugin get "$SLUG" --field=version)"; CV="$(wpc eval 'echo WPCAP_VERSION;')"
[ "$HV" = "$CV" ] && ok "header version $HV = WPCAP_VERSION" || bad "header version $HV ≠ WPCAP_VERSION $CV"
wpc eval 'switch_to_locale( "en_US" ); load_plugin_textdomain( "wp-pass-captain", false, "wp-pass-captain/languages" ); exit( "Speichern" !== __( "Speichern", "wp-pass-captain" ) ? 0 : 1 );' && ok "translations load from the ZIP (en_US)" || bad "translations do not load from the ZIP"
wpc eval 'do_action( "rest_api_init" ); $r = rest_get_server()->get_routes(); exit( isset( $r["/wp-pass-captain/v1/events"] ) ? 0 : 1 );' && ok "REST routes registered" || bad "REST routes missing"
wpc eval 'do_action( "wpcap_process_jobs" ); do_action( "wpcap_cron" );' >/dev/null 2>&1; ok "cron hooks run"

# --- 3. deactivate / uninstall -------------------------------------------------------------------
wpc plugin deactivate "$SLUG" >/dev/null && ok "deactivation" || bad "deactivation failed"
wpc eval '$s = get_option( "wpcap_settings", array() ); $s["delete_data_on_uninstall"] = 1; update_option( "wpcap_settings", $s );'
wpc plugin uninstall "$SLUG" >/dev/null 2>&1 && ok "uninstall (deletes the plugin folder)" || bad "uninstall failed"
[ ! -e "$WP/wp-content/plugins/$SLUG" ] && ok "plugin folder removed" || bad "plugin folder left"
[ "$(tables)" = "0" ] && ok "no plugin tables left" || bad "$(tables) plugin tables left"
[ "$(options)" = "0" ] && ok "no plugin options left" || bad "$(options) plugin options left"

ERR="$(php_errors)"
[ -z "$ERR" ] && ok "no PHP fatals/warnings/notices/deprecations from the plugin" || bad "PHP messages: $(head -n 5 <<<"$ERR")"
# Leave the shared database clean.
wpc db query "DROP TABLE IF EXISTS $(wpc db query "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}%'" --skip-column-names)" >/dev/null 2>&1

[ "$fail" = 0 ] && echo "ZIP install test passed." || echo "ZIP install test FAILED."
exit "$fail"
