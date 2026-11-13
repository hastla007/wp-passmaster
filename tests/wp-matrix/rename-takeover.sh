#!/usr/bin/env bash
# Rename "WP Pass Captain" (folder wp-pass-captain) → "Pass Captain" (pass-captain) in a real WordPress:
# an existing site with the old release and data installs the new ZIP next to it and activates it.
# Expected: no fatal error, the old copy is switched off, all data stays, old REST URLs (payment/SMS
# webhooks, Wallet passes, apps) keep working, deleting the old copy keeps the data even with
# "delete data on uninstall" on, and cron events come back afterwards.
# Usage: rename-takeover.sh <old-release.zip> <new-release.zip> <workdir>   (wp-cli, DB wp/wp@127.0.0.1 "wp")
# (legacy-slug: this file names the old folder on purpose)
set -uo pipefail
OLD_ZIP="$1"; NEW_ZIP="$2"; WORK="$3"
OLD="wp-pass-captain"; NEW="pass-captain"
DIR="$(cd "$(dirname "$0")/../restore" && pwd)"
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }
WP="$WORK/wp"; PFX="rnmt_"
rm -rf "$WP"; mkdir -p "$WP"
wpc() { wp --allow-root --path="$WP" "$@"; }
dropall() { wpc db query "DROP TABLE IF EXISTS $(wpc db query "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}%'" --skip-column-names)" >/dev/null 2>&1; }
wpc core download --version="${WPCAP_ZIP_WP_VERSION:-latest}" --locale=en_US --quiet >/dev/null 2>&1 || { echo "wp core download failed" >&2; exit 2; }
wpc config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost="${WPCAP_ZIP_DB_HOST:-127.0.0.1}" --dbprefix="$PFX" --quiet >/dev/null
wpc config set WP_DEBUG true --raw >/dev/null; wpc config set WP_DEBUG_LOG true --raw >/dev/null; wpc config set WP_DEBUG_DISPLAY false --raw >/dev/null
wpc config set AUTOMATIC_UPDATER_DISABLED true --raw >/dev/null
dropall
wpc core install --url=http://localhost --title=Rename --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null
LOG="$WP/wp-content/debug.log"; rm -f "$LOG"
state() { wpc eval-file "$DIR/state.php" | php -r '$s = json_decode( stream_get_contents( STDIN ), true ); echo $s["data_hash"], " ", $s["orders"], " ", $s["tickets"];'; }
active() { wpc option get active_plugins --format=json; }

# 1. Old release with data.
wpc plugin install "$OLD_ZIP" --activate >/dev/null 2>&1 && ok "old release installed and active ($OLD)" || bad "old release could not be installed"
wpc eval 'PassCaptain\Plugin::maybe_upgrade("admin");' >/dev/null
SEED_TAG=rename SEED_OUT="$WORK/seed.json" wpc eval-file "$DIR/seed.php" >/dev/null 2>&1
BEFORE="$(state)"; read -r _ ORD TIX <<<"$BEFORE"
[ "${ORD:-0}" -ge 1 ] && [ "${TIX:-0}" -ge 1 ] && ok "seeded data: $ORD orders, $TIX tickets" || bad "seeding failed ($BEFORE)"
wpc eval '$s = get_option( "wpcap_settings", array() ); $s["delete_data_on_uninstall"] = 1; update_option( "wpcap_settings", $s );'

# 2. New ZIP next to it, activated while the old copy is active.
wpc plugin install "$NEW_ZIP" >/dev/null 2>&1 && ok "new ZIP installed next to the old copy" || bad "new ZIP could not be installed"
OUT="$(wpc plugin activate "$NEW" 2>&1)"; RC=$?
[ $RC = 0 ] && ok "activating $NEW while $OLD is active succeeds" || bad "activation failed: $OUT"
grep -qi "fatal" <<<"$OUT" && bad "fatal error on activation: $OUT" || ok "no fatal error on activation"
# The next requests settle on the new copy alone (WordPress may re-add the old entry once during activation).
for i in 1 2 3; do wpc eval 'echo "";' >/dev/null 2>&1; done
A="$(active)"
wpc plugin is-active "$NEW" && ok "$NEW is active" || bad "$NEW not active: $A"
wpc plugin is-active "$OLD" && bad "$OLD still active: $A" || ok "$OLD was switched off automatically"
wpc eval 'do_action( "admin_init" );' >/dev/null 2>&1   # finishes the takeover (cron) and pauses data deletion
wpc eval 'PassCaptain\Plugin::maybe_upgrade("admin"); PassCaptain\LegacySlug::watch_legacy_copy();' >/dev/null
V="$(wpc option get wpcap_db_version)"; E="$(wpc eval 'echo PassCaptain\Installer::version();')"
[ "$V" = "$E" ] && ok "schema upgraded to $V" || bad "schema $V, expected $E"
[ "$(state)" = "$BEFORE" ] && ok "all business data unchanged after the switch" || bad "data changed: $(state) vs $BEFORE"
DEL="$(wpc eval '$s = get_option( "wpcap_settings" ); echo (int) ( $s["delete_data_on_uninstall"] ?? 0 ), (int) get_option( "wpcap_uninstall_delete_parked" );')"
[ "$DEL" = "01" ] && ok "delete-on-uninstall paused while the old folder exists" || bad "delete-on-uninstall not paused ($DEL)"

# 3. Old REST URLs keep working (webhooks registered at providers, Wallet passes, apps).
REST="$(wpc eval '
$r = rest_do_request( new WP_REST_Request( "GET", "/wp-pass-captain/v1/events" ) );
$h = $r->get_headers();
$n = rest_do_request( new WP_REST_Request( "GET", "/pass-captain/v1/events" ) );
echo $r->get_status(), " ", $h["Deprecation"] ?? "-", " ", $n->get_status(), " ", isset( $n->get_headers()["Deprecation"] ) ? "dep" : "-";')"
[ "$REST" = "200 true 200 -" ] && ok "old REST namespace answers 200 with Deprecation; new namespace 200 without" || bad "REST: $REST"
WH="$(wpc eval '$r = rest_do_request( new WP_REST_Request( "POST", "/wp-pass-captain/v1/payments/stripe/webhook" ) ); echo $r->get_status();')"
[ "$WH" != "404" ] && ok "old payment webhook URL is routed (status $WH, not 404)" || bad "old webhook URL returns 404"

# 4. Delete the old copy (its own uninstall.php runs): data stays, cron comes back, setting restored.
wpc plugin delete "$OLD" >/dev/null 2>&1 && ok "old copy deleted (its uninstall.php ran)" || bad "deleting the old copy failed"
[ "$(state)" = "$BEFORE" ] && ok "data kept although delete-on-uninstall was on" || bad "data lost when deleting the old copy: $(state)"
wpc eval 'PassCaptain\LegacySlug::watch_legacy_copy();' >/dev/null
DEL2="$(wpc eval '$s = get_option( "wpcap_settings" ); echo (int) ( $s["delete_data_on_uninstall"] ?? 0 ), (int) get_option( "wpcap_uninstall_delete_parked" );')"
[ "$DEL2" = "10" ] && ok "delete-on-uninstall restored after the old copy is gone" || bad "setting not restored ($DEL2)"
grep -q '^wpcap_process_jobs' <<<"$(wpc cron event list --fields=hook --format=csv 2>/dev/null)" && ok "cron events scheduled again" || bad "cron events missing after the old copy's uninstall"
ERR="$( [ -f "$LOG" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$LOG" | grep -iE "pass-captain" || true )"
[ -z "$ERR" ] && ok "no PHP fatals/warnings/notices" || bad "PHP messages: $(head -n 5 <<<"$ERR")"
wpc plugin deactivate "$NEW" >/dev/null 2>&1; dropall
[ "$fail" = 0 ] && echo "Rename takeover test passed." || echo "Rename takeover test FAILED."
exit "$fail"
