#!/usr/bin/env bash
# Rename on MULTISITE (audit finding): network with the old release network-activated and a subsite that opted
# into "delete data on uninstall". The new copy is network-activated, then the network admin uninstalls the old
# copy WITHOUT anyone having opened the subsite admin. The old uninstall.php loops over all sites – the subsite's
# tables must survive, its setting must come back afterwards and its cron events must be re-scheduled.
# Usage: rename-takeover-ms.sh <old-release.zip> <new-release.zip> <workdir>   (wp-cli, DB wp/wp@127.0.0.1 "wp")
# (legacy-slug: this file names the old folder on purpose)
set -uo pipefail
OLD_ZIP="$1"; NEW_ZIP="$2"; WORK="$3"
OLD="wp-pass-captain"; NEW="pass-captain"
DIR="$(cd "$(dirname "$0")/../restore" && pwd)"
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }
WP="$WORK/wp"; PFX="rnms_"; URL="http://localhost"; SUB="$URL/two/"
rm -rf "$WP"; mkdir -p "$WP"
wpc() { wp --allow-root --path="$WP" "$@"; }
dropall() { wpc db query "DROP TABLE IF EXISTS $(wpc db query "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX}%'" --skip-column-names)" >/dev/null 2>&1; }
wpc core download --version="${WPCAP_ZIP_WP_VERSION:-latest}" --locale=en_US --quiet >/dev/null 2>&1 || { echo "wp core download failed" >&2; exit 2; }
wpc config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost="${WPCAP_ZIP_DB_HOST:-127.0.0.1}" --dbprefix="$PFX" --quiet >/dev/null
wpc config set WP_DEBUG true --raw >/dev/null; wpc config set WP_DEBUG_LOG true --raw >/dev/null; wpc config set WP_DEBUG_DISPLAY false --raw >/dev/null
wpc config set AUTOMATIC_UPDATER_DISABLED true --raw >/dev/null
dropall
wpc core multisite-install --url="$URL" --title=Net --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null 2>&1 || { echo "multisite install failed" >&2; exit 2; }
wpc site create --slug=two --title=Two >/dev/null
LOG="$WP/wp-content/debug.log"; rm -f "$LOG"
state() { wpc --url="$SUB" eval-file "$DIR/state.php" | php -r '$s = json_decode( stream_get_contents( STDIN ), true ); echo $s["data_hash"], " ", $s["orders"], " ", $s["tables"];'; }
flag() { wpc --url="$SUB" eval '$s = get_option( "wpcap_settings" ); echo (int) ( $s["delete_data_on_uninstall"] ?? 0 );'; }

# 1. Old release network-activated, data and opt-in on the subsite.
wpc plugin install "$OLD_ZIP" >/dev/null 2>&1 && wpc plugin activate "$OLD" --network >/dev/null 2>&1 && ok "old release network-activated" || bad "old release could not be network-activated"
wpc --url="$SUB" eval 'PassCaptain\Plugin::maybe_upgrade("admin");' >/dev/null
SEED_TAG=ms SEED_OUT="$WORK/seed.json" wpc --url="$SUB" eval-file "$DIR/seed.php" >/dev/null 2>&1
wpc --url="$SUB" eval '$s = get_option( "wpcap_settings", array() ); $s["delete_data_on_uninstall"] = 1; update_option( "wpcap_settings", $s );'
BEFORE="$(state)"; read -r _ ORD TBL <<<"$BEFORE"
[ "${ORD:-0}" -ge 1 ] && [ "${TBL:-0}" -ge 50 ] && ok "subsite has data ($ORD orders, $TBL tables) and opted into data deletion" || bad "subsite setup failed ($BEFORE)"

# 2. New copy network-activated (takes over), only main-site requests afterwards.
wpc plugin install "$NEW_ZIP" >/dev/null 2>&1 && ok "new ZIP installed"
OUT="$(wpc plugin activate "$NEW" --network 2>&1)"; RC=$?
[ $RC = 0 ] && ! grep -qi fatal <<<"$OUT" && ok "network activation of $NEW while $OLD is network-active: no fatal" || bad "network activation failed: $OUT"
for i in 1 2 3; do wpc eval 'echo "";' >/dev/null 2>&1; done
wpc plugin is-active "$NEW" --network && ok "$NEW network-active" || bad "$NEW not network-active"
wpc plugin is-active "$OLD" --network && bad "$OLD still network-active" || ok "$OLD switched off network-wide"
[ "$(flag)" = "0" ] && ok "subsite's delete-on-uninstall paused without a subsite request" || bad "subsite's setting not paused (flag=$(flag))"

# 3. Network admin uninstalls the old copy (its uninstall.php loops over all sites).
wpc plugin uninstall "$OLD" >/dev/null 2>&1 && ok "old copy uninstalled network-wide (its own uninstall.php ran)" || bad "uninstalling the old copy failed"
AFTER="$(state)"; read -r H2 ORD2 TBL2 <<<"$AFTER"; read -r H1 _ _ <<<"$BEFORE"
[ "$H2" = "$H1" ] && [ "$ORD2" = "$ORD" ] && [ "${TBL2:-0}" -ge "$TBL" ] && ok "subsite keeps all tables and data ($TBL2 tables after the schema upgrade)" || bad "subsite data lost: $AFTER vs $BEFORE"
wpc eval 'echo "";' >/dev/null 2>&1   # any request: notices the old folder is gone, restores the settings network-wide
[ "$(flag)" = "1" ] && ok "subsite's delete-on-uninstall restored" || bad "subsite's setting not restored (flag=$(flag))"
wpc --url="$SUB" eval 'echo "";' >/dev/null 2>&1   # first request on the subsite: cron self-healing on init
grep -q '^wpcap_process_jobs' <<<"$(wpc --url="$SUB" cron event list --fields=hook --format=csv 2>/dev/null)" && ok "subsite cron events scheduled again" || bad "subsite cron events missing"
ERR="$( [ -f "$LOG" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$LOG" | grep -iE "pass-captain" || true )"
[ -z "$ERR" ] && ok "no PHP fatals/warnings/notices" || bad "PHP messages: $(head -n 5 <<<"$ERR")"
dropall
[ "$fail" = 0 ] && echo "Multisite rename takeover test passed." || echo "Multisite rename takeover test FAILED."
exit "$fail"
