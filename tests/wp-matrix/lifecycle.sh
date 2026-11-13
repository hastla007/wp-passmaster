#!/usr/bin/env bash
# A125: plugin lifecycle against a real WordPress install (any version): activation, deactivation,
# reactivation, uninstall with and without data removal. Run on a disposable install only.
# Usage: lifecycle.sh <wp-path> <plugin-dir>
set -uo pipefail
WP="$1"; PLUGIN="$2"
wpc() { wp --allow-root --path="$WP" "$@"; }
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }
LINK="$WP/wp-content/plugins/pass-captain"
PFX="$(wpc db prefix)"
LOG="$WP/wp-content/debug.log"
tables() { wpc db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}wpcap\\_%'" --skip-column-names; }
options() { wpc db query "SELECT COUNT(*) FROM ${PFX}options WHERE option_name LIKE 'wpcap\\_%'" --skip-column-names; }
php_errors() { [ -f "$LOG" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$LOG" | grep -i "pass-captain" || true; }

wpc config set WP_DEBUG true --raw >/dev/null; wpc config set WP_DEBUG_LOG true --raw >/dev/null; wpc config set WP_DEBUG_DISPLAY false --raw >/dev/null
rm -f "$LOG"
echo "WordPress $(wpc core version), PHP $(php -r 'echo PHP_VERSION;')"
wpc plugin deactivate pass-captain >/dev/null 2>&1
rm -f "$LINK"; ln -s "$PLUGIN" "$LINK"

wpc plugin activate pass-captain >/dev/null && ok "activation" || bad "activation failed"
wpc eval 'PassCaptain\Plugin::maybe_upgrade("admin");' >/dev/null
T1="$(tables)"; [ "$T1" -ge 50 ] && ok "activation created the schema ($T1 tables)" || bad "schema missing ($T1 tables)"
V="$(wpc option get wpcap_db_version)"; case "$V" in "$(wpc eval 'echo PassCaptain\Installer::version();')") ok "db version $V recorded";; *) bad "db version $V";; esac
wpc eval 'if ( get_option( "wpcap_schema_fingerprint" ) !== PassCaptain\Installer::fingerprint() ) { exit( 1 ); }' && ok "schema fingerprint matches" || bad "schema fingerprint mismatch"
# Real requests (needs a web server for the site URL; set WPCAP_LIFECYCLE_HTTP=1 when one runs).
if [ "${WPCAP_LIFECYCLE_HTTP:-0}" = "1" ]; then
	URL="$(wpc option get siteurl)"
	CODE="$(curl -s -o /dev/null -w '%{http_code}' "$URL/?rest_route=/pass-captain/v1/events")"
	[ "$CODE" = "200" ] && ok "public REST route answers 200" || bad "public REST route answered $CODE"
	JAR="$(mktemp)"
	curl -s -c "$JAR" -b "$JAR" -o /dev/null -d "log=admin&pwd=admin&wp-submit=1&testcookie=1" -b "wordpress_test_cookie=WP+Cookie+check" "$URL/wp-login.php"
	for page in pass-captain wpcap-orders wpcap-checkins wpcap-settings; do
		BODY="$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=$page")"
		# Here-strings, not pipes: with pipefail an early-exiting grep -q would fail the pipeline.
		if grep -q 'id="wpbody-content"' <<<"$BODY" && ! grep -qiE "critical error|kritischer Fehler|Fatal error" <<<"$BODY"; then ok "admin page $page renders"; else bad "admin page $page does not render"; fi
	done
	rm -f "$JAR"
fi

wpc plugin deactivate pass-captain >/dev/null && ok "deactivation" || bad "deactivation failed"
[ "$(tables)" = "$T1" ] && ok "deactivation keeps all data" || bad "deactivation changed tables"
grep -q '^wpcap_' <<<"$(wpc cron event list --fields=hook --format=csv 2>/dev/null)" && bad "cron events left after deactivation: $(wpc cron event list --fields=hook --format=csv | grep '^wpcap_' | tr '\n' ' ')" || ok "deactivation clears plugin cron events"
wpc plugin activate pass-captain >/dev/null && ok "reactivation" || bad "reactivation failed"
[ "$(tables)" = "$T1" ] && ok "reactivation keeps the schema unchanged" || bad "reactivation changed tables"

# Uninstall without "delete data": tables and settings stay.
wpc plugin deactivate pass-captain >/dev/null
wpc plugin uninstall pass-captain --skip-delete >/dev/null 2>&1 && ok "uninstall runs" || bad "uninstall failed"
[ "$(tables)" = "$T1" ] && ok "uninstall without data removal keeps tables" || bad "tables removed although data removal is off"
# Uninstall with "delete data".
wpc plugin activate pass-captain >/dev/null
wpc eval '$s = get_option( "wpcap_settings", array() ); $s["delete_data_on_uninstall"] = 1; update_option( "wpcap_settings", $s );'
wpc plugin deactivate pass-captain >/dev/null
wpc plugin uninstall pass-captain --skip-delete >/dev/null 2>&1
[ "$(tables)" = "0" ] && ok "uninstall with data removal drops all plugin tables" || bad "$(tables) plugin tables left"
[ "$(options)" = "0" ] && ok "uninstall with data removal deletes all plugin options" || bad "$(options) plugin options left"
grep -q '^wpcap_' <<<"$(wpc role list --fields=role --format=csv)" && bad "plugin roles left: $(wpc role list --fields=role --format=csv | grep '^wpcap_' | tr '\n' ' ')" || ok "plugin roles removed"
[ -e "$PLUGIN/pass-captain.php" ] && ok "plugin files untouched (--skip-delete)" || bad "plugin files deleted"

ERR="$(php_errors)"; [ -z "$ERR" ] && ok "no PHP errors, warnings or deprecations from the plugin in debug.log" || { bad "PHP messages from the plugin:"; echo "$ERR" | head -20; }
wpc plugin activate pass-captain >/dev/null
exit $fail
