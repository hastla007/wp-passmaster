#!/usr/bin/env bash
# Runs every integration module on its own (WPCAP_TEST_ONLY='^<module>$', core files included) and
# reports the modules that fail alone. Checks test isolation: a module must not rely on stubs, helpers,
# registered modules/providers or settings that only an earlier module sets up.
#
# Usage: tests/integration/run-each.sh [-j JOBS] [REGEX]
#   -j JOBS  parallel runs (default 1); each job uses its own table prefix <WPCAP_TEST_PREFIX>j<N>_
#   REGEX    only modules whose basename matches (default: all)
# Same database requirements as run.php. Logs of failed modules: $WPCAP_EACH_LOGS (default: a temp dir).
set -uo pipefail
cd "$(dirname "$0")/../.."

jobs=1
if [ "${1:-}" = "-j" ]; then jobs="$2"; shift 2; fi
filter="${1:-.}"
base_prefix="${WPCAP_TEST_PREFIX:-wptest_}"
logs="${WPCAP_EACH_LOGS:-$(mktemp -d)}"
mkdir -p "$logs"

modules=$(for f in tests/integration/modules/*.php; do basename "$f" .php; done | grep -E -- "$filter")
total=$(printf '%s\n' "$modules" | grep -c .)

run_one() {
	local module="$1" slot="$2" log="$logs/$1.log"
	if WPCAP_TEST_PREFIX="${base_prefix}j${slot}_" WPCAP_TEST_CORE=1 WPCAP_TEST_ONLY="^${module}\$" \
		php -d memory_limit=4G tests/integration/run.php > "$log" 2>&1; then
		rm -f "$log"
		echo "ok   $module"
	else
		echo "FAIL $module ($(grep -c '^  FAIL ' "$log") failed checks, log: $log)"
	fi
}

# Job N runs every JOBS-th module in sequence with its own table prefix, so parallel runs never share tables.
: > "$logs/summary.txt"
for slot in $(seq 0 $(( jobs - 1 ))); do
	(
		printf '%s\n' "$modules" | awk -v j="$jobs" -v s="$slot" '(NR - 1) % j == s' | while read -r module; do
			run_one "$module" "$slot"
		done
	) | tee -a "$logs/summary.txt" &
done
wait

failed=$(grep -c '^FAIL' "$logs/summary.txt" || true)
echo
echo "$total modules, $failed failed alone (logs in $logs)"
[ "$failed" -eq 0 ]
