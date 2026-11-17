#!/usr/bin/env bash
# Backup/restore drill (docs/OPERATIONS.md "Restore-Probe"): realistic data in a throwaway WordPress with this
# plugin, logical backup with mysqldump/mariadb-dump, writes after the backup, destruction, restore, then
# Diagnostics and consistency checks and an idempotent re-run of the queued work.
#
#   tests/drills/restore-drill.sh
#
# Steps (each prints ok/FAIL; exit code 1 on any failure):
#  1. Fresh WordPress (WP-CLI) with the plugin from this working tree (symlink), activated.
#  2. Data (build/ci-data.php seed + tests/drills/drill.php inflight): free and paid orders with tickets, a
#     check-in, an external refund, a pending order with an active hold, a queued job and the work that is "in
#     flight" at backup time: a paid order whose issuance crashed (queued ticket_issue job), one whose worker
#     died (stale running claim) and an expired hold the cleanup did not release. Diagnostics must report
#     exactly these (expired_holds, paid_without_tickets = 2, stale_claims = 1).
#  3. Backup: dump of the whole database (--single-transaction) + snapshot of the business data.
#  4. Writes after the backup (order, refund, check-in, job run) and destruction (DROP TABLE tickets, TRUNCATE
#     orders, schema version deleted) – the site must be visibly broken.
#  5. Restore: drop every table, import the dump. Data equals the snapshot, the ticket codes from before
#     the backup resolve again, Diagnostics reports the same findings as before the backup.
#  6. Recovery as in OPERATIONS.md: `wp pass-captain diagnose --repair-all`, cron (jobs, cleanup), then
#     `wp pass-captain diagnose --strict` without findings and the data invariants (no duplicate codes,
#     admissions, refunds or job keys, no orphans, no paid order without tickets, no tickets of unpaid
#     orders, no overselling).
#  7. Idempotency: repair + cron again and once more after a second restore + recovery: no data change, no
#     further mail, every crashed order has exactly its ordered number of tickets.
#
# Environment (defaults in brackets) – use a DISPOSABLE database, every table in it is dropped:
#   WPCAP_DRILL_DB_HOST [127.0.0.1]  _PORT [3306]  _USER [wp]  _PASS [wp]  _NAME [wp_drill]
#   WPCAP_DRILL_DUMP    dump command   [mariadb-dump or mysqldump]  e.g. "docker exec -i <db-container> mysqldump"
#   WPCAP_DRILL_MYSQL   client command [mariadb or mysql]            e.g. "docker exec -i <db-container> mysql"
#                       (with docker exec the host/port are those seen inside the container: set
#                       WPCAP_DRILL_CLIENT_HOST, default 127.0.0.1, and WPCAP_DRILL_CLIENT_PORT, default 3306)
#   WPCAP_DRILL_WP_CORE WordPress core to copy (otherwise `wp core download`, version WPCAP_DRILL_WP_VERSION)
#   WPCAP_DRILL_WP_CLI  path to wp-cli.phar (downloaded if missing)
#   WPCAP_DRILL_WORK    working directory [mktemp]; kept with WPCAP_DRILL_KEEP=1
set -euo pipefail
export LC_ALL=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DB_HOST="${WPCAP_DRILL_DB_HOST:-127.0.0.1}"
DB_PORT="${WPCAP_DRILL_DB_PORT:-3306}"
DB_USER="${WPCAP_DRILL_DB_USER:-wp}"
DB_PASS="${WPCAP_DRILL_DB_PASS:-wp}"
DB_NAME="${WPCAP_DRILL_DB_NAME:-wp_drill}"
CLIENT_HOST="${WPCAP_DRILL_CLIENT_HOST:-${DB_HOST}}"
CLIENT_PORT="${WPCAP_DRILL_CLIENT_PORT:-${DB_PORT}}"
WP_CLI_VERSION="2.12.0"
WORK="${WPCAP_DRILL_WORK:-$(mktemp -d "${TMPDIR:-/tmp}/wpcap-drill.XXXXXX")}"
mkdir -p "${WORK}"
[ "${WPCAP_DRILL_KEEP:-}" = "1" ] || trap 'rm -rf "${WORK}"' EXIT

pick() { for c in "$@"; do command -v "${c}" >/dev/null 2>&1 && { echo "${c}"; return; }; done; }
DUMP="${WPCAP_DRILL_DUMP:-$(pick mariadb-dump mysqldump)}"
MYSQL="${WPCAP_DRILL_MYSQL:-$(pick mariadb mysql)}"
[ -n "${DUMP}" ] && [ -n "${MYSQL}" ] || { echo "need mariadb-dump/mysqldump and mariadb/mysql (or WPCAP_DRILL_DUMP/_MYSQL)" >&2; exit 2; }
# Password via the documented option file mechanism is not portable through docker exec; -p on the command
# line is acceptable for a disposable test database.
db_args=(-h "${CLIENT_HOST}" -P "${CLIENT_PORT}" -u "${DB_USER}" "-p${DB_PASS}")
# shellcheck disable=SC2086 # the commands may contain arguments ("docker exec -i … mysql")
sql() { ${MYSQL} "${db_args[@]}" -N -B "${DB_NAME}" -e "$1" 2>&1 | grep -v 'Using a password' || true; }

failures=0
fail() { echo "FAIL  $*" >&2; failures=$((failures + 1)); }
ok() { echo "ok    $*"; }
section() { echo; echo "== $* =="; }
expect() { # label actual expected
	if [ "$2" = "$3" ]; then ok "$1 ($2)"; else fail "$1: got '$2', expected '$3'"; fi
}

# ---------------------------------------------------------------------------------------------
section "Throwaway WordPress with the plugin from ${ROOT}"
WP_CLI="${WPCAP_DRILL_WP_CLI:-${WORK}/wp-cli.phar}"
[ -f "${WP_CLI}" ] || curl -fsSL -o "${WP_CLI}" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
WP_PATH="${WORK}/wordpress"
MAILS="${WORK}/mails.log"
wp() { php -d memory_limit=512M "${WP_CLI}" --path="${WP_PATH}" --allow-root --skip-themes "$@"; }
data() { wp eval-file "${ROOT}/build/ci-data.php" "$@"; }
drill() { wp eval-file "${ROOT}/tests/drills/drill.php" "$@"; }
json_field() { php -r '$s = stream_get_contents( STDIN ); $d = json_decode( substr( $s, (int) strpos( $s, "{" ) ), true ); foreach ( explode( ".", $argv[1] ) as $k ) { $d = is_array( $d ) ? ( $d[ $k ] ?? null ) : null; } echo is_array( $d ) ? json_encode( $d ) : (string) $d;' "$1"; }
mail_count() { [ -f "${MAILS}" ] && wc -l < "${MAILS}" | tr -d ' ' || echo 0; }

if [ -n "${WPCAP_DRILL_WP_CORE:-}" ]; then
	mkdir -p "${WP_PATH}" && cp -a "${WPCAP_DRILL_WP_CORE}/." "${WP_PATH}/" && rm -rf "${WP_PATH}/.git"
else
	wp core download --version="${WPCAP_DRILL_WP_VERSION:-latest}" --skip-content --force
fi
mkdir -p "${WP_PATH}/wp-content/plugins" "${WP_PATH}/wp-content/themes" "${WP_PATH}/wp-content/mu-plugins"
ln -sfn "${ROOT}" "${WP_PATH}/wp-content/plugins/pass-captain"
# Mails are counted, never sent (re-running queued work after a restore must not mail anybody twice).
cat > "${WP_PATH}/wp-content/mu-plugins/drill-mail.php" <<PHP
<?php
add_filter( 'pre_wp_mail', static function ( \$short, \$atts ) { file_put_contents( '${MAILS}', gmdate( 'c' ) . ' ' . str_replace( "\n", ' ', (string) ( \$atts['subject'] ?? '' ) ) . "\n", FILE_APPEND ); return true; }, 1, 2 );
PHP
PREFIX="wpdr_"
rm -f "${WP_PATH}/wp-config.php"
wp config create --dbname="${DB_NAME}" --dbuser="${DB_USER}" --dbpass="${DB_PASS}" --dbhost="${DB_HOST}:${DB_PORT}" \
	--dbprefix="${PREFIX}" --skip-check --force --extra-php <<PHP
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '${WORK}/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
PHP
# Start from an empty database (it is disposable by contract).
for t in $(sql "SHOW TABLES"); do sql "DROP TABLE \`${t}\`" >/dev/null; done
wp core install --url=http://localhost --title="Pass Captain Restore Drill" --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.org --skip-email >/dev/null
wp plugin activate pass-captain >/dev/null
wp plugin is-active pass-captain && ok "WordPress $(wp core version), plugin active, schema $(wp option get wpcap_db_version)" || { fail "plugin not active"; exit 1; }

# ---------------------------------------------------------------------------------------------
section "Data (orders, tickets, check-in, refund, holds, jobs, in-flight work)"
data seed drill > "${WORK}/seed.json" || { fail "seed"; cat "${WORK}/seed.json"; exit 1; }
drill inflight "${WORK}/seed.json" > "${WORK}/inflight.json" || { fail "in-flight data"; cat "${WORK}/inflight.json"; exit 1; }
ok "seed $(head -c 160 "${WORK}/seed.json")…"
ok "in flight $(cat "${WORK}/inflight.json")"
CRASHED="$(json_field paid_crashed < "${WORK}/inflight.json")"
STALE="$(json_field paid_stale_claim < "${WORK}/inflight.json")"
PAID_CODE="$(json_field codes.paid < "${WORK}/seed.json" | php -r 'echo json_decode( stream_get_contents( STDIN ), true )[1] ?? "";')"
drill diagnose > "${WORK}/diag-before.json"
expect "Diagnostics before the backup: expired holds" "$(json_field expired_holds < "${WORK}/diag-before.json")" 2
expect "Diagnostics before the backup: paid without tickets" "$(json_field paid_without_tickets < "${WORK}/diag-before.json")" 2
expect "Diagnostics before the backup: stale claims" "$(json_field stale_claims < "${WORK}/diag-before.json")" 1
expect "Diagnostics before the backup: schema" "$(json_field schema < "${WORK}/diag-before.json")" 0

# ---------------------------------------------------------------------------------------------
section "Backup"
data snapshot > "${WORK}/snapshot.json"
DB_VERSION="$(wp option get wpcap_db_version)"
# shellcheck disable=SC2086
${DUMP} "${db_args[@]}" --single-transaction --quick --hex-blob --default-character-set=utf8mb4 --no-tablespaces \
	--routines --triggers "${DB_NAME}" 2> "${WORK}/dump.err" > "${WORK}/backup.sql" || { cat "${WORK}/dump.err"; fail "dump"; exit 1; }
grep -q "CREATE TABLE \`${PREFIX}wpcap_orders\`" "${WORK}/backup.sql" && ok "dump $(du -h "${WORK}/backup.sql" | cut -f1) with $(grep -c '^CREATE TABLE' "${WORK}/backup.sql") tables" || fail "dump without plugin tables"

# ---------------------------------------------------------------------------------------------
section "Writes after the backup, then destruction"
drill after-backup "${WORK}/seed.json" > "${WORK}/late.json" || { fail "writes after the backup"; cat "${WORK}/late.json"; }
wp cron event run wpcap_process_jobs >/dev/null 2>&1 || true
if data verify "${WORK}/snapshot.json" >/dev/null 2>&1; then fail "writes after the backup did not change the data (drill is not testing anything)"; else ok "data changed after the backup"; fi
sql "DROP TABLE \`${PREFIX}wpcap_tickets\`; TRUNCATE TABLE \`${PREFIX}wpcap_orders\`; DELETE FROM \`${PREFIX}options\` WHERE option_name='wpcap_db_version'" >/dev/null
if data invariants >/dev/null 2>&1; then fail "site still consistent after the destruction"; else ok "site broken: tickets dropped, orders truncated, schema version deleted"; fi

restore() {
	for t in $(sql "SHOW TABLES"); do sql "SET FOREIGN_KEY_CHECKS=0; DROP TABLE \`${t}\`" >/dev/null; done
	# shellcheck disable=SC2086
	${MYSQL} "${db_args[@]}" "${DB_NAME}" < "${WORK}/backup.sql" 2> "${WORK}/restore.err" || { cat "${WORK}/restore.err"; return 1; }
}

# ---------------------------------------------------------------------------------------------
section "Restore"
restore && ok "dump imported into the emptied database" || fail "import"
expect "schema version restored" "$(wp option get wpcap_db_version 2>/dev/null)" "${DB_VERSION}"
out="$(data verify "${WORK}/snapshot.json" 2>&1)" && ok "${out#ok    }" || fail "${out}"
drill code-valid "${PAID_CODE}" && ok "ticket code issued before the backup resolves again" || fail "ticket code from before the backup does not resolve"
drill diagnose > "${WORK}/diag-restored.json"
if [ "$(cat "${WORK}/diag-restored.json")" = "$(cat "${WORK}/diag-before.json")" ]; then ok "Diagnostics after the restore = before the backup: $(cat "${WORK}/diag-restored.json")"; else fail "Diagnostics after the restore $(cat "${WORK}/diag-restored.json") differ from before the backup $(cat "${WORK}/diag-before.json")"; fi

recover() { # label
	wp pass-captain diagnose --repair-all --format=json > "${WORK}/repair.json" 2>&1 || true
	ok "$1: wp pass-captain diagnose --repair-all, repaired $(json_field repairs < "${WORK}/repair.json")"
	wp cron event run wpcap_process_jobs wpcap_cleanup_reservations > /dev/null 2>&1 || fail "$1: cron run"
	if wp pass-captain diagnose --strict --format=json > "${WORK}/strict.json" 2>&1; then ok "$1: wp pass-captain diagnose --strict without findings"; else fail "$1: diagnose --strict: $(cat "${WORK}/strict.json")"; fi
	out="$(data invariants 2>&1)" && ok "$1: ${out#ok    }" || fail "$1: ${out}"
	expect "$1: crashed order has its 2 tickets" "$(drill order-tickets "${CRASHED}" | json_field tickets)" 2
	expect "$1: order with the stale claim has its ticket" "$(drill order-tickets "${STALE}" | json_field tickets)" 1
}

# ---------------------------------------------------------------------------------------------
section "Recovery (OPERATIONS.md: diagnose --repair-all, cron, diagnose --strict)"
mails_before="$(mail_count)"
recover "after the restore"
expect "ticket mails for the two recovered orders" "$(( $(mail_count) - mails_before ))" 2

section "Idempotency of the queued work"
data snapshot > "${WORK}/recovered.json"
mails_before="$(mail_count)"
recover "second run"
out="$(data verify "${WORK}/recovered.json" 2>&1)" && ok "second run changed no data" || fail "second run: ${out}"
expect "no further mail" "$(( $(mail_count) - mails_before ))" 0

section "Second restore of the same backup, recovery twice"
restore && ok "dump imported again" || fail "second import"
out="$(data verify "${WORK}/snapshot.json" 2>&1)" && ok "second restore: ${out#ok    }" || fail "second restore: ${out}"
recover "second restore"
recover "second restore, repeated"

if [ -s "${WORK}/debug.log" ] && grep -E 'PHP (Fatal error|Warning|Notice|Deprecated)' "${WORK}/debug.log" | grep -qF -e "${ROOT}/" -e "plugins/pass-captain/"; then
	fail "PHP errors from the plugin in debug.log"; grep -F -e "${ROOT}/" -e "plugins/pass-captain/" "${WORK}/debug.log" | head -20
fi

echo
if [ "${failures}" -gt 0 ]; then echo "${failures} check(s) failed." >&2; exit 1; fi
echo "Restore drill passed."
