#!/usr/bin/env bash
# Shell tests for build/verify-release.sh (release gate of .github/workflows/release.yml).
# Builds a throw-away origin + clone, fakes `gh api` with canned Actions API responses and checks
# that the gate passes only for a commit on main with successful CI and matching versions.
# Usage: bash tests/release/verify-release.test.sh   (needs git and jq; no network)
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../../build/verify-release.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/wpcap-verify-release.XXXXXX")"
trap 'rm -rf "${WORK}"' EXIT
pass=0; failn=0
ok()  { pass=$((pass + 1)); echo "  ok   $1"; }
bad() { failn=$((failn + 1)); echo "  FAIL $1"; }

g() { git -c user.name=t -c user.email=t@example.com -c init.defaultBranch=main -c commit.gpgsign=false "$@"; }
plugin() { # <dir> <header version> <constant> <stable tag>
	printf '<?php\n/**\n * Plugin Name: Pass Captain\n * Version: %s\n */\ndefine( '"'"'WPCAP_VERSION'"'"', '"'"'%s'"'"' );\n' "$2" "$3" > "$1/pass-captain.php"
	printf '=== Pass Captain ===\nStable tag: %s\n' "$4" > "$1/readme.txt"
}

g init -q --bare "${WORK}/origin.git"
g clone -q "${WORK}/origin.git" "${WORK}/repo" 2>/dev/null
R="${WORK}/repo"
plugin "$R" 1.4.0 1.4.0 1.4.0
g -C "$R" add -A && g -C "$R" commit -qm main1 && g -C "$R" push -q origin HEAD:main 2>/dev/null
MAIN_SHA="$(git -C "$R" rev-parse HEAD)"
g -C "$R" checkout -qb side
echo x > "$R/x" && g -C "$R" add -A && g -C "$R" commit -qm side && g -C "$R" push -q origin side 2>/dev/null
SIDE_SHA="$(git -C "$R" rev-parse HEAD)"
g -C "$R" checkout -q main
# A later main commit: the tagged commit only has to be an ancestor of main, not its tip.
echo y > "$R/y" && g -C "$R" add -A && g -C "$R" commit -qm main2 && g -C "$R" push -q origin HEAD:main 2>/dev/null
g -C "$R" checkout -q "$MAIN_SHA" 2>/dev/null
git -C "$R" update-ref -d refs/remotes/origin/main # must be fetched by the script

# Fake gh: prints responses/<n>.json for the n-th call (the last one repeats); records arguments.
mkdir -p "${WORK}/bin"
cat > "${WORK}/bin/gh" <<'GH'
#!/usr/bin/env bash
dir="${FAKE_GH_DIR:?}"
n=$(( $(cat "$dir/count" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$dir/count"
echo "$*" >> "$dir/args"
f="$dir/$n.json"; [ -f "$f" ] || f="$(ls "$dir"/*.json | sort -V | tail -n1)"
cat "$f"
GH
chmod +x "${WORK}/bin/gh"

run() { # <expect: pass|fail> <name> <tag> <sha> <responses...>  (env: extra VAR=... via ENVX)
	local expect="$1" name="$2" tag="$3" sha="$4"; shift 4
	local d; d="$(mktemp -d "${WORK}/gh.XXXX")"; local i=1
	for r in "$@"; do echo "$r" > "$d/$i.json"; i=$((i + 1)); done
	[ $# -gt 0 ] || echo '{"workflow_runs":[]}' > "$d/1.json"
	local out code
	out="$(cd "$R" && env ROOT="$R" GH="${WORK}/bin/gh" FAKE_GH_DIR="$d" GITHUB_REPOSITORY=acme/pc CI_WAIT_SECONDS="${WAIT:-0}" CI_POLL_SECONDS=0 ${ENVX:-} bash "$SCRIPT" "$tag" "$sha" 2>&1)"; code=$?
	if { [ "$expect" = pass ] && [ $code -eq 0 ]; } || { [ "$expect" = fail ] && [ $code -ne 0 ]; }; then ok "$name"; else bad "$name (exit $code)"; echo "$out" | sed 's/^/       /'; fi
	LAST_OUT="$out"; LAST_DIR="$d"
}
runjson() { # <sha> <repo> <status> <conclusion> [event]
	printf '{"id":%d,"head_sha":"%s","head_repository":{"full_name":"%s"},"status":"%s","conclusion":%s,"event":"%s"}' "$RANDOM" "$1" "$2" "$3" "$4" "${5:-push}"
}
S_OK="$(runjson "$MAIN_SHA" acme/pc completed '"success"')"
S_FAIL="$(runjson "$MAIN_SHA" acme/pc completed '"failure"')"
S_CANCEL="$(runjson "$MAIN_SHA" acme/pc completed '"cancelled"')"
S_RUN="$(runjson "$MAIN_SHA" acme/pc in_progress null)"
wr() { local IFS=,; echo "{\"workflow_runs\":[$*]}"; }

echo "Release gate (build/verify-release.sh)"
run pass "commit on main with successful CI and matching versions passes" v1.4.0 "$MAIN_SHA" "$(wr "$S_OK")"
grep -q "actions/workflows/ci.yml/runs" "$LAST_DIR/args" && grep -q "head_sha=${MAIN_SHA}" "$LAST_DIR/args" && ok "CI is queried for ci.yml runs of exactly the release SHA" || bad "CI query arguments: $(cat "$LAST_DIR/args")"
git -C "$R" rev-parse --verify -q refs/remotes/origin/main >/dev/null && ok "origin/main is fetched before the ancestry check" || bad "origin/main not fetched"
run fail "commit not on main (unmerged branch) is rejected" v1.4.0 "$SIDE_SHA" "$(wr "$(runjson "$SIDE_SHA" acme/pc completed '"success"')")"
echo "$LAST_OUT" | grep -q "not an ancestor" && ok "ancestry failure is reported" || bad "ancestry message: $LAST_OUT"
run fail "failed CI run blocks the release" v1.4.0 "$MAIN_SHA" "$(wr "$S_FAIL")"
echo "$LAST_OUT" | grep -qF "did not pass for" && ok "  … for the right reason (did not pass for)" || bad "  reason: $LAST_OUT"
run fail "one failed run blocks even when another run succeeded" v1.4.0 "$MAIN_SHA" "$(wr "$S_OK" "$S_FAIL")"
run pass "a cancelled run next to a successful one does not block" v1.4.0 "$MAIN_SHA" "$(wr "$S_OK" "$S_CANCEL")"
run fail "only a cancelled run is not a success" v1.4.0 "$MAIN_SHA" "$(wr "$S_CANCEL")"
echo "$LAST_OUT" | grep -qF "no successful CI" && ok "  … for the right reason (no successful CI)" || bad "  reason: $LAST_OUT"
run fail "no CI run for the SHA blocks the release" v1.4.0 "$MAIN_SHA" "$(wr)"
run fail "successful runs for other SHAs do not count" v1.4.0 "$MAIN_SHA" "$(wr "$(runjson "$SIDE_SHA" acme/pc completed '"success"')")"
run fail "successful runs from a fork do not count" v1.4.0 "$MAIN_SHA" "$(wr "$(runjson "$MAIN_SHA" evil/pc completed '"success"' pull_request)")"
echo "$LAST_OUT" | grep -qF "no CI (ci.yml) run found" && ok "  … for the right reason (no CI (ci.yml) run found)" || bad "  reason: $LAST_OUT"
run fail "CI still running after the wait limit blocks" v1.4.0 "$MAIN_SHA" "$(wr "$S_RUN")"
echo "$LAST_OUT" | grep -q "still running" && ok "timeout is reported as still running" || bad "timeout message: $LAST_OUT"
WAIT=60 run pass "running CI is waited for until it succeeds" v1.4.0 "$MAIN_SHA" "$(wr "$S_RUN")" "$(wr "$S_RUN" "$S_OK")" "$(wr "$S_OK" "$S_OK")"
[ "$(cat "$LAST_DIR/count")" = 3 ] && ok "gate polled until all runs had finished" || bad "poll count $(cat "$LAST_DIR/count")"
WAIT=60 run fail "running CI that then fails blocks" v1.4.0 "$MAIN_SHA" "$(wr "$S_RUN")" "$(wr "$S_FAIL")"
ENVX="GH=false" run fail "API errors block the release" v1.4.0 "$MAIN_SHA"
run fail "tag that does not match the plugin version is rejected" v1.4.1 "$MAIN_SHA" "$(wr "$S_OK")"
echo "$LAST_OUT" | grep -qF "plugin header Version" && ok "  … for the right reason (plugin header Version)" || bad "  reason: $LAST_OUT"
run fail "malformed tag is rejected" release-1.4.0 "$MAIN_SHA" "$(wr "$S_OK")"
run fail "abbreviated SHA is rejected" v1.4.0 "${MAIN_SHA:0:12}" "$(wr "$S_OK")"
plugin "$R" 1.4.0 1.4.0 1.3.0
ENVX="VERIFY_ONLY=version" run fail "readme Stable tag mismatch is rejected" v1.4.0 "$MAIN_SHA"
plugin "$R" 1.4.0 1.3.9 1.4.0
ENVX="VERIFY_ONLY=version" run fail "WPCAP_VERSION mismatch is rejected" v1.4.0 "$MAIN_SHA"
plugin "$R" 1.5.0-rc.1 1.5.0-rc.1 1.4.0
ENVX="VERIFY_ONLY=version" run pass "pre-release tag does not require the Stable tag to move" v1.5.0-rc.1 "$MAIN_SHA"
git -C "$R" checkout -q -- . 2>/dev/null

echo "$pass passed, $failn failed"
[ "$failn" -eq 0 ] && echo "ALL PASSED"
[ "$failn" -eq 0 ]
