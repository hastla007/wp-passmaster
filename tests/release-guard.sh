#!/usr/bin/env bash
# Negative/positive tests of build/release-guard.sh (ancestry part; the CI part needs the GitHub API).
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
cd "$tmp"
git init -q -b main repo && cd repo
git config user.email t@example.com && git config user.name t
echo a > f && git add f && git commit -qm a
on_main="$(git rev-parse HEAD)"
git checkout -qb feature && echo b > f && git commit -qam b
off_main="$(git rev-parse HEAD)"
git checkout -q main
fail=0
RELEASE_GUARD_SKIP_CI=1 bash "$root/build/release-guard.sh" "$on_main" main >/dev/null && echo "ok   commit on main is accepted" || { echo "FAIL commit on main"; fail=1; }
if RELEASE_GUARD_SKIP_CI=1 bash "$root/build/release-guard.sh" "$off_main" main >/dev/null 2>&1; then echo "FAIL commit off main was accepted"; fail=1; else echo "ok   commit off main is refused"; fi
exit $fail
