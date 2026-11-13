#!/usr/bin/env bash
# Upgrade / rollback / restore drill (A113). Only for a TEST WordPress install: drops all plugin tables while running,
# then restores the database dump taken at the start. See docs/OPERATIONS.md "Rollback und Restore".
# Upgrade / rollback / restore drill against a real WordPress + MariaDB install (A113).
# Usage: drill.sh <wp-path> <old-plugin-dir> <new-plugin-dir> <workdir>
set -uo pipefail
WP="$1"; OLD="$2"; NEW="$3"; W="$4"
DIR="$(cd "$(dirname "$0")" && pwd)"
wpc() { wp --allow-root --path="$WP" "$@"; }
DBN="$(wpc config get DB_NAME)"; DBU="$(wpc config get DB_USER)"; DBP="$(wpc config get DB_PASSWORD)"; DBH="$(wpc config get DB_HOST)"
DUMP="$(command -v mariadb-dump || command -v mysqldump)"; CLIENT="$(command -v mariadb || command -v mysql)"
# MySQL's mysqldump against MariaDB needs --column-statistics=0 (no COLUMN_STATISTICS table there).
DUMP_OPTS=(--single-transaction --skip-dump-date --no-tablespaces)
"$DUMP" --help 2>/dev/null | grep -q -- '--column-statistics' && DUMP_OPTS+=(--column-statistics=0)
dump() {
	"$DUMP" -h"$DBH" -u"$DBU" -p"$DBP" "${DUMP_OPTS[@]}" "$DBN" > "$1" || { echo "FAIL database dump failed" >&2; exit 2; }
	grep -q "CREATE TABLE" "$1" || { echo "FAIL database dump is empty" >&2; exit 2; }
}
load() { "$CLIENT" -h"$DBH" -u"$DBU" -p"$DBP" "$DBN" < "$1"; }
LINK="$WP/wp-content/plugins/pass-captain"
use() { rm -f "$LINK"; ln -s "$1" "$LINK"; }
upgrade() { wpc eval 'PassCaptain\Plugin::maybe_upgrade("admin"); wp_cache_flush();' ; }
state() { wpc eval-file "$DIR/state.php"; }
fail=0
ok() { echo "ok   $1"; }
bad() { echo "FAIL $1"; fail=1; }
field() { php -r '$j=json_decode(stream_get_contents(STDIN),true); echo is_scalar($j[$argv[1]]??null)?$j[$argv[1]]:json_encode($j[$argv[1]]??null);' "$1"; }
neworder() { wpc eval '
	global $wpdb; $j=json_decode(file_get_contents(getenv("SEED_OUT")),true);
	$o=PassCaptain\Orders::create(array("email"=>"drill-after-".uniqid()."@example.com","items"=>array(array("ticket_type_id"=>$j["type"],"quantity"=>1)),"accept_terms"=>true,"idempotency_key"=>uniqid("drill",true)));
	echo is_wp_error($o) ? "ERR ".$o->get_error_message() : "OK"; '; }

ORIG_LINK="$(readlink "$LINK")"
dump "$W/pre-drill.sql"

reset_plugin() {
	wpc plugin deactivate pass-captain >/dev/null 2>&1
	wpc db query "SELECT CONCAT('DROP TABLE \`',table_name,'\`;') FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '$(wpc db prefix)wpcap\\_%'" --skip-column-names | wpc db query >/dev/null
	wpc db query "DELETE FROM $(wpc db prefix)options WHERE option_name LIKE 'wpcap\\_%' OR option_name LIKE '\\_transient%wpcap%'" >/dev/null

}

echo "== Scenario 1: install old version, seed, upgrade, rollback, roll forward, restore"
reset_plugin; use "$OLD"; wpc plugin activate pass-captain >/dev/null
SEED_TAG=old SEED_OUT="$W/seed.json" wpc eval-file "$DIR/seed.php"; export SEED_OUT="$W/seed.json"
S_OLD="$(state)"; echo "   old:      $S_OLD"
dump "$W/backup-old.sql"
use "$NEW"; upgrade; S_UP="$(state)"; echo "   upgraded: $S_UP"
[ "$(echo "$S_OLD" | field data_hash)" = "$(echo "$S_UP" | field data_hash)" ] && ok "upgrade keeps all orders, tickets and check-ins unchanged" || bad "data changed by upgrade"
[ "$(echo "$S_UP" | field columns)" -gt "$(echo "$S_OLD" | field columns)" ] && ok "upgrade adds the new schema" || bad "schema not upgraded"
[ "$(neworder)" = OK ] && ok "new version sells after the upgrade" || bad "sale after upgrade failed"
S_UP2="$(state)"
use "$OLD"; upgrade; S_RB="$(state)"; echo "   rollback: $S_RB"
[ "$(echo "$S_UP2" | field data_hash)" = "$(echo "$S_RB" | field data_hash)" ] && ok "code rollback keeps the data" || bad "data changed by rollback"
[ "$(echo "$S_RB" | field columns)" -ge "$(echo "$S_UP2" | field columns)" ] && ok "code rollback drops no tables/columns" || bad "rollback dropped schema"
R="$(neworder)"; [ "$R" = OK ] && ok "old version sells on the newer schema" || bad "old version cannot sell on newer schema: $R"
S_RB2="$(state)"
use "$NEW"; upgrade; S_FW="$(state)"; echo "   forward:  $S_FW"
[ "$(echo "$S_RB2" | field data_hash)" = "$(echo "$S_FW" | field data_hash)" ] && ok "rolling forward again keeps the data" || bad "data changed rolling forward"
[ "$(echo "$S_FW" | field db_version)" = "$(echo "$S_UP" | field db_version)" ] && ok "schema version is current again" || bad "schema version"
load "$W/backup-old.sql"; wpc cache flush >/dev/null; upgrade; S_RS="$(state)"; echo "   restore:  $S_RS"
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

echo "== Cleanup: restore the database and plugin link from before the drill"
load "$W/pre-drill.sql"; use "$ORIG_LINK"; wpc cache flush >/dev/null
exit $fail
