#!/usr/bin/env bash
# A126: release gate – the manual acceptance record for the version must exist and be complete.
# Usage: build/release-acceptance.sh <version> [root]
set -euo pipefail
version="${1:?version}"
root="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
file="$root/docs/release-acceptance/v${version}.md"
fail() { echo "Release acceptance v${version}: $*" >&2; exit 1; }
[ -f "$file" ] || fail "missing ${file#$root/} (copy docs/release-acceptance/TEMPLATE.md and complete it)"
grep -qE "^Version: *${version//./\\.} *$" "$file" || fail "line 'Version: ${version}' missing"
for field in "Datum" "Geprüft von" "Commit"; do
	grep -qE "^${field}: *[^ ].*$" "$file" || fail "field '${field}:' is empty"
done
open="$(grep -cE '^\s*- \[ \]' "$file" || true)"
[ "$open" = "0" ] || fail "${open} unchecked item(s): $(grep -nE '^\s*- \[ \]' "$file" | head -3 | tr '\n' ' ')"
checked="$(grep -cE '^\s*- \[[xX]\]' "$file" || true)"
[ "$checked" -ge 10 ] || fail "only ${checked} checked item(s) – incomplete record"
echo "Release acceptance v${version}: complete (${checked} items)"
