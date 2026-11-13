#!/usr/bin/env bash
# Release guard: a release may only be published for a commit that is on main and whose CI run
# ("WordPress plugin checks") succeeded. Used by .github/workflows/release.yml for tag pushes and
# manual runs (the workflow_run path is already gated by a successful CI run on main).
#
# Usage: build/release-guard.sh <sha> [main-ref] [workflow-name]
# Env:   GH_TOKEN (for the CI status), RELEASE_GUARD_SKIP_CI=1 (tests only: ancestry check alone).
set -euo pipefail
sha="${1:?commit sha required}"
main_ref="${2:-origin/main}"
workflow="${3:-WordPress plugin checks}"

if ! git merge-base --is-ancestor "$sha" "$main_ref" 2>/dev/null; then
	echo "release-guard: $sha is not on $main_ref – refusing to release" >&2
	exit 1
fi

if [ "${RELEASE_GUARD_SKIP_CI:-}" != "1" ]; then
	conclusion="$(gh run list --workflow "$workflow" --commit "$sha" --json conclusion,event --jq '[.[] | select(.event=="push") | .conclusion] | map(select(.=="success")) | length')"
	if [ "${conclusion:-0}" -lt 1 ]; then
		echo "release-guard: no successful \"$workflow\" push run for $sha – refusing to release" >&2
		exit 1
	fi
fi
echo "release-guard: $sha is on $main_ref and passed CI"
