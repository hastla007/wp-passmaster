#!/usr/bin/env bash
# Tests for build/release-acceptance.sh (A126).
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/docs/release-acceptance"
fail=0
check() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }
run() { bash "$root/build/release-acceptance.sh" 9.9.9 "$tmp" >/dev/null 2>&1 && echo pass || echo refuse; }
check "missing record is refused" "$(run)" refuse
sed 's/X\.Y\.Z/9.9.9/g' "$root/docs/release-acceptance/TEMPLATE.md" > "$tmp/docs/release-acceptance/v9.9.9.md"
check "untouched template is refused" "$(run)" refuse
sed -i 's/^Datum:.*/Datum: 2026-11-13/; s/^Geprüft von:.*/Geprüft von: Test/; s/^Commit:.*/Commit: abc1234/; s/- \[ \]/- [x]/' "$tmp/docs/release-acceptance/v9.9.9.md"
check "complete record passes" "$(run)" pass
sed -i '0,/- \[x\]/s//- [ ]/' "$tmp/docs/release-acceptance/v9.9.9.md"
check "one open item is refused" "$(run)" refuse
sed -i 's/- \[ \]/- [x]/; s/^Geprüft von:.*/Geprüft von:/' "$tmp/docs/release-acceptance/v9.9.9.md"
check "empty tester field is refused" "$(run)" refuse
exit $fail
