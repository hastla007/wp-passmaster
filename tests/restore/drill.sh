#!/usr/bin/env bash
# Upgrade / rollback / restore drill against a real WordPress + MariaDB install (A113, A469). Only for a TEST
# WordPress install: drops all plugin tables while running, then restores the tables of this install's prefix
# from the dump taken at the start. See docs/OPERATIONS.md "Rollback und Restore".
# Usage: drill.sh <wp-path> <old-plugin-dir> <new-plugin-dir> <workdir>
set -uo pipefail
WP="$1"; OLD="$2"; NEW="$3"; W="$4"
DIR="$(cd "$(dirname "$0")" && pwd)"
wpc() { wp --allow-root --path="$WP" "$@"; }
DBN="$(wpc config get DB_NAME)"; DBU="$(wpc config get DB_USER)"; DBP="$(wpc config get DB_PASSWORD)"; DBH="$(wpc config get DB_HOST)"
PFX="$(wpc db prefix)"
DUMP="$(command -v mariadb-dump || command -v mysqldump)"; CLIENT="$(command -v mariadb || command -v mysql)"
# MySQL's mysqldump against MariaDB needs --column-statistics=0 (no COLUMN_STATISTICS table there).
DUMP_OPTS=(--single-transaction --skip-dump-date --no-tablespaces)
"$DUMP" --help 2>/dev/null | grep -q -- '--column-statistics' && DUMP_OPTS+=(--column-statistics=0)
# Only the tables of this install's prefix are dumped and restored: the database may hold other installs
# (other prefixes), which the drill must never overwrite.
prefix_tables() {
	wpc db query "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX//_/\\_}%'" --skip-column-names
}
dump() {
	local tables
	mapfile -t tables < <(prefix_tables)
	[ "${#tables[@]}" -gt 0 ] || { echo "FAIL no tables with prefix $PFX" >&2; exit 2; }
	"$DUMP" -h"$DBH" -u"$DBU" -p"$DBP" "${DUMP_OPTS[@]}" "$DBN" "${tables[@]}" > "$1" || { echo "FAIL database dump failed" >&2; exit 2; }
	grep -q "CREATE TABLE" "$1" || { echo "FAIL database dump is empty" >&2; exit 2; }
}
load() { "$CLIENT" -h"$DBH" -u"$DBU" -p"$DBP" "$DBN" < "$1"; }
LINK="$WP/wp-content/plugins/pass-captain"
# Releases up to 1.4 have the main file wp-pass-captain.php, later ones pass-captain.php (renamed for WordPress.org,
# legacy-slug): after switching the linked code, point an active entry of this folder at the main file it now has.
use() {
	rm -f "$LINK"; ln -s "$1" "$LINK"
	local main="pass-captain.php"; [ -f "$1/$main" ] || main="wp-pass-captain.php" # legacy-slug
	MAIN="$main" wpc --skip-plugins eval '$a = (array) get_option( "active_plugins", array() ); $n = array(); foreach ( $a as $p ) { $n[] = 0 === strpos( $p, "pass-captain/" ) ? "pass-captain/" . getenv( "MAIN" ) : $p; } if ( $n !== $a ) { update_option( "active_plugins", $n ); }' >/dev/null
}
upgrade() { wpc eval 'PassCaptain\Plugin::maybe_upgrade("admin"); wp_cache_flush();' ; }
state() { wpc eval-file "$DIR/state.php"; }
# State outside the schema (A469): lc <action> [arg] – see lifecycle.php.
lc() { LC="$1" LC_ARG="${2:-}" wpc eval-file "$DIR/lifecycle.php" 2>/dev/null; }
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }
field() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j[$argv[1]]??null; echo is_bool($v)?($v?"true":"false"):(is_scalar($v)?$v:json_encode($v));' "$1"; }
neworder() { wpc eval '
	global $wpdb; $j=json_decode(file_get_contents(getenv("SEED_OUT")),true);
	$o=PassCaptain\Orders::create(array("email"=>"drill-after-".uniqid()."@example.com","items"=>array(array("ticket_type_id"=>$j["type"],"quantity"=>1)),"accept_terms"=>true,"idempotency_key"=>uniqid("drill",true)));
	echo is_wp_error($o) ? "ERR ".$o->get_error_message() : "OK"; '; }
MU="$WP/wp-content/mu-plugins/wpcap-drill-rollback-secrets.php"

ORIG_LINK="$(readlink "$LINK")"
dump "$W/pre-drill.sql"

reset_plugin() {
	wpc plugin deactivate pass-captain >/dev/null 2>&1
	wpc db query "SELECT CONCAT('DROP TABLE \`',table_name,'\`;') FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PFX//_/\\_}wpcap\\_%'" --skip-column-names | wpc db query >/dev/null
	wpc db query "DELETE FROM ${PFX}options WHERE option_name LIKE 'wpcap\\_%' OR option_name LIKE '\\_transient%wpcap%'" >/dev/null
	rm -f "$MU"
}

echo "== Scenario 1: install old version, seed, upgrade, rollback, roll forward, restore"
reset_plugin; use "$OLD"; wpc plugin activate pass-captain >/dev/null
SEED_TAG=old SEED_OUT="$W/seed.json" wpc eval-file "$DIR/seed.php"; export SEED_OUT="$W/seed.json"
lc set-plain-secrets >/dev/null
S_OLD="$(state)"; echo "   old:      $S_OLD"
dump "$W/backup-old.sql"
use "$NEW"; upgrade; S_UP="$(state)"; echo "   upgraded: $S_UP"
[ "$(echo "$S_OLD" | field data_hash)" = "$(echo "$S_UP" | field data_hash)" ] && ok "upgrade keeps all orders, tickets and check-ins unchanged" || bad "data changed by upgrade"
[ "$(echo "$S_UP" | field columns)" -gt "$(echo "$S_OLD" | field columns)" ] && ok "upgrade adds the new schema" || bad "schema not upgraded"
[ "$(neworder)" = OK ] && ok "new version sells after the upgrade" || bad "sale after upgrade failed"
SET="$(lc settings)"
[ "$(lc stripe)" = yes ] && [ "$(echo "$SET" | field plaintext_secrets)" = "[]" ] && ok "Stripe keys of the old version moved to the encrypted store and still work" || bad "Stripe after upgrade: $(lc stripe), settings $SET"
[ "$(echo "$SET" | field default_entry_window)" = 0 ] && [ "$(echo "$SET" | field entry_window_notice)" = 1 ] && ok "existing install keeps admission without the new default entry window, admin notice flagged (A465)" || bad "default entry window after upgrade: $SET"
S_UP2="$(state)"
use "$OLD"; upgrade; S_RB="$(state)"; echo "   rollback: $S_RB"
[ "$(echo "$S_UP2" | field data_hash)" = "$(echo "$S_RB" | field data_hash)" ] && ok "code rollback keeps the data" || bad "data changed by rollback"
[ "$(echo "$S_RB" | field columns)" -ge "$(echo "$S_UP2" | field columns)" ] && ok "code rollback drops no tables/columns" || bad "rollback dropped schema"
R="$(neworder)"; [ "$R" = OK ] && ok "old version sells on the newer schema" || bad "old version cannot sell on newer schema: $R"
S_RB2="$(state)"
use "$NEW"; upgrade; S_FW="$(state)"; echo "   forward:  $S_FW"
[ "$(echo "$S_RB2" | field data_hash)" = "$(echo "$S_FW" | field data_hash)" ] && ok "rolling forward again keeps the data" || bad "data changed rolling forward"
[ "$(echo "$S_FW" | field db_version)" = "$(echo "$S_UP" | field db_version)" ] && ok "schema version is current again" || bad "schema version"
load "$W/backup-old.sql"; use "$(readlink "$LINK")"; wpc cache flush >/dev/null; upgrade; S_RS="$(state)"; echo "   restore:  $S_RS"
[ "$(echo "$S_OLD" | field data_hash)" = "$(echo "$S_RS" | field data_hash)" ] && ok "restoring the old backup under the new version gives exactly the backed-up data" || bad "restore data mismatch"
[ "$(echo "$S_RS" | field columns)" = "$(echo "$S_UP" | field columns)" ] && ok "restored old backup is upgraded to the full new schema" || bad "restored schema incomplete"
[ "$(neworder)" = OK ] && ok "sales work after the restore" || bad "sale after restore failed"

echo "== Scenario 2: fresh new install, rollback to old code, roll forward (settings must not change)"
reset_plugin; use "$NEW"; wpc plugin activate pass-captain >/dev/null
SEED_TAG=new SEED_OUT="$W/seed.json" wpc eval-file "$DIR/seed.php"
S_N="$(state)"; echo "   new:      $S_N"
use "$OLD"; upgrade; use "$NEW"; upgrade; S_N2="$(state)"; echo "   forward:  $S_N2"
[ "$(echo "$S_N" | field credit_prefix)" = "$(echo "$S_N2" | field credit_prefix)" ] && ok "credit note prefix unchanged after rollback and roll forward" || bad "credit note prefix changed: $(echo "$S_N" | field credit_prefix) -> $(echo "$S_N2" | field credit_prefix)"
[ "$(echo "$S_N" | field data_hash)" = "$(echo "$S_N2" | field data_hash)" ] && ok "data unchanged" || bad "data changed"
SET="$(lc settings)"
[ "$(echo "$SET" | field default_entry_window)" = 1 ] && [ "$(echo "$SET" | field entry_window_notice)" = 0 ] && ok "fresh install keeps the new default entry window across rollback and roll forward" || bad "default entry window changed: $SET"

echo "== Scenario 3: provider keys and sales in the old code during a rollback (A463, A464, A469)"
reset_plugin; use "$NEW"; wpc plugin activate pass-captain >/dev/null
SEED_TAG=lc SEED_OUT="$W/seed3.json" wpc eval-file "$DIR/seed.php"; export SEED_OUT="$W/seed3.json"
lc set-secrets >/dev/null
C="$(lc capacity)"; echo "   new:      $C"
[ "$(lc stripe)" = yes ] && ok "new version: Stripe configured from the encrypted store" || bad "Stripe not configured on the new version"
[ "$(echo "$C" | field state)" = ready ] && ok "new version decides capacity with the counters" || bad "counters not ready: $C"
mkdir -p "$(dirname "$MU")"
{ echo '<?php'; wpc pass-captain secrets rollback-config --yes 2>/dev/null; } > "$MU"
grep -q "WPCAP_STRIPE_SECRET_KEY" "$MU" && ok "rollback-config exports the stored keys as wp-config constants" || bad "rollback-config exported nothing"
use "$OLD"; upgrade >/dev/null
[ "$(lc stripe)" = yes ] && ok "old version (no encrypted store) still has Stripe through the exported constants" || bad "old version lost Stripe although the constants are set"
mv "$MU" "$MU.off"
[ "$(lc stripe)" = no ] && ok "without the constants the old version has no Stripe keys (the documented rollback step is needed)" || bad "unexpected: old version reads Stripe without constants"
mv "$MU.off" "$MU"
R="$(lc sell 3x10)"; [ "$R" = OK ] && ok "old version sells 30 tickets without maintaining the counters" || bad "old version could not sell: $R"
[ "$(lc backdate 3)" -ge 3 ] && ok "those sales are three days old (outside the two days the cron reconcile checks)" || bad "backdating failed"
use "$NEW"; upgrade >/dev/null
C="$(lc capacity)"; echo "   forward:  $C"
[ "$(echo "$C" | field state)" != ready ] && [ "$(echo "$C" | field enabled)" = false ] && ok "roll forward after a rollback: counters are rebuilt, decisions use the SUM queries until verified" || bad "stale counters still decide after the rollback: $C"
[ "$(echo "$C" | field drift)" = 0 ] && ok "rebuilt counters equal the orders (including the old version's sales)" || bad "counter drift after roll forward: $C"
AV="$(echo "$C" | field available)"; EXP=$(( $(echo "$C" | field capacity) - $(echo "$C" | field sold) ))
[ "$AV" = "$EXP" ] && ok "availability is capacity minus every paid ticket ($AV)" || bad "availability $AV, expected $EXP"
[ "$(lc buy $(( EXP + 1 )))" != OK ] && ok "no overselling: buying one more than is left is rejected" || bad "OVERSOLD after rollback and roll forward"
[ "$(lc verify)" = ready ] && ok "reconcile cron finishes the rebuild and verifies the counters" || bad "counters did not become ready"
C="$(lc capacity)"
[ "$(echo "$C" | field available)" = "$EXP" ] && [ "$(lc buy $(( EXP + 1 )))" != OK ] && [ "$(lc buy "$EXP")" = OK ] && ok "verified counters: no overselling, the rest can still be sold" || bad "capacity wrong with verified counters: $C"
[ "$(lc stripe)" = yes ] && ok "new version keeps Stripe (constants and encrypted store)" || bad "Stripe lost after roll forward"
rm -f "$MU"
[ "$(lc stripe)" = yes ] && ok "after removing the rollback constants the encrypted store is used again" || bad "Stripe lost after removing the rollback constants"
H="$(lc health)"
[ "$(echo "$H" | field schema_error)" = false ] && [ "$(echo "$H" | field events)" = true ] && ok "no schema error, recurring events scheduled" || bad "health after roll forward: $H"

echo "== Cleanup: restore the tables of prefix $PFX and the plugin link from before the drill"
rm -f "$MU"
load "$W/pre-drill.sql"; use "$ORIG_LINK"; wpc cache flush >/dev/null
exit $fail
