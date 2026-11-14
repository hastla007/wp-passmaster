#!/usr/bin/env bash
# Release gate, run by .github/workflows/release.yml before anything is built or published.
#
# Usage: build/verify-release.sh <tag> <sha>          (e.g. v1.4.0 "$RELEASE_SHA")
#
# Fails (exit 1) unless ALL of these hold:
#   1. version: <tag> is v<semver> and equals the plugin header "Version:", WPCAP_VERSION and,
#      for non-pre-release tags, the readme.txt "Stable tag" of the checked-out tree;
#   2. ancestry: <sha> is an ancestor of (or equal to) origin/main – a tag on an unmerged branch or
#      a fork commit is never released;
#   3. CI: the CI workflow (.github/workflows/ci.yml, "WordPress plugin checks") ran for exactly
#      <sha> in this repository, every run of it for that commit has finished, at least one
#      finished with conclusion "success" and none with a failing conclusion
#      (failure, timed_out, startup_failure, action_required). Still running CI is waited for
#      (CI_WAIT_SECONDS, default 2700) because a tag push starts CI and the release at once.
#
# Environment:
#   GITHUB_REPOSITORY  owner/repo (required for the CI check)
#   GH_TOKEN           token for `gh api` (GITHUB_TOKEN with actions: read)
#   CI_WORKFLOW        workflow file name, default ci.yml
#   CI_WAIT_SECONDS    max. wait for running CI, default 2700; CI_POLL_SECONDS poll interval, default 30
#   MAIN_BRANCH        default main; MAIN_REF overrides the ref compared against (default origin/<branch>)
#   SKIP_FETCH=1       do not `git fetch` the main branch (tests)
#   GH                 gh executable (tests inject a fake), default gh
#   VERIFY_ONLY        comma list of checks to run (version,ancestry,ci), default all
set -euo pipefail

TAG="${1:-}"
SHA="${2:-}"
ROOT="${ROOT:-$(pwd)}"
GH="${GH:-gh}"
CI_WORKFLOW="${CI_WORKFLOW:-ci.yml}"
CI_WAIT_SECONDS="${CI_WAIT_SECONDS:-2700}"
CI_POLL_SECONDS="${CI_POLL_SECONDS:-30}"
MAIN_BRANCH="${MAIN_BRANCH:-main}"
MAIN_REF="${MAIN_REF:-origin/${MAIN_BRANCH}}"
VERIFY_ONLY="${VERIFY_ONLY:-version,ancestry,ci}"

fail() { echo "::error::Release blocked: $*" >&2; exit 1; }
wants() { case ",${VERIFY_ONLY}," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }

[ -n "${TAG}" ] && [ -n "${SHA}" ] || { echo "Usage: $0 <tag> <sha>" >&2; exit 2; }
[[ "${SHA}" =~ ^[0-9a-f]{40}$ ]] || fail "'${SHA}' is not a full commit SHA"

check_version() {
	[[ "${TAG}" =~ ^v([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?)$ ]] || fail "tag '${TAG}' is not v<major>.<minor>.<patch>[-pre]"
	local version="${BASH_REMATCH[1]}" header constant stable
	header="$(sed -n 's/^ \* Version: *//p' "${ROOT}/pass-captain.php" | head -n1 | tr -d '\r')"
	constant="$(sed -n "s/^define( 'WPCAP_VERSION', '\([^']*\)' );.*/\1/p" "${ROOT}/pass-captain.php" | head -n1)"
	[ "${header}" = "${version}" ] || fail "plugin header Version '${header}' does not match tag ${TAG}"
	[ "${constant}" = "${version}" ] || fail "WPCAP_VERSION '${constant}' does not match tag ${TAG}"
	if [ -f "${ROOT}/readme.txt" ] && [[ "${version}" != *-* ]]; then
		stable="$(sed -n 's/^Stable tag: *//p' "${ROOT}/readme.txt" | head -n1 | tr -d '\r ')"
		[ "${stable}" = "${version}" ] || fail "readme.txt Stable tag '${stable}' does not match tag ${TAG}"
	fi
	echo "ok: tag ${TAG} matches plugin header, WPCAP_VERSION and readme Stable tag"
}

check_ancestry() {
	if [ "${SKIP_FETCH:-}" != "1" ]; then
		local args=(--no-tags origin "+refs/heads/${MAIN_BRANCH}:refs/remotes/origin/${MAIN_BRANCH}")
		if [ "$(git -C "${ROOT}" rev-parse --is-shallow-repository)" = "true" ]; then args=(--unshallow "${args[@]}"); fi
		git -C "${ROOT}" fetch --quiet "${args[@]}" || fail "could not fetch ${MAIN_BRANCH} from origin"
	fi
	git -C "${ROOT}" rev-parse --verify --quiet "${MAIN_REF}^{commit}" >/dev/null || fail "${MAIN_REF} not found"
	git -C "${ROOT}" cat-file -e "${SHA}^{commit}" 2>/dev/null || fail "commit ${SHA} not found"
	git -C "${ROOT}" merge-base --is-ancestor "${SHA}" "${MAIN_REF}" || fail "commit ${SHA} is not on ${MAIN_BRANCH} (not an ancestor of ${MAIN_REF})"
	echo "ok: ${SHA} is an ancestor of ${MAIN_REF}"
}

check_ci() {
	[ -n "${GITHUB_REPOSITORY:-}" ] || fail "GITHUB_REPOSITORY is not set"
	local deadline=$(( $(date +%s) + CI_WAIT_SECONDS )) runs total pending ok bad
	while :; do
		# Only runs of this repository (not fork PRs that happen to carry the same commit).
		runs="$("${GH}" api -X GET "repos/${GITHUB_REPOSITORY}/actions/workflows/${CI_WORKFLOW}/runs" -f head_sha="${SHA}" -f per_page=100 \
			| jq -c --arg sha "${SHA}" --arg repo "${GITHUB_REPOSITORY}" '[.workflow_runs[]? | select(.head_sha == $sha and (.head_repository.full_name // $repo) == $repo) | {id, event, status, conclusion}]')" \
			|| fail "could not query ${CI_WORKFLOW} runs for ${SHA}"
		total="$(jq 'length' <<<"${runs}")"
		pending="$(jq '[.[] | select(.status != "completed")] | length' <<<"${runs}")"
		ok="$(jq '[.[] | select(.status == "completed" and .conclusion == "success")] | length' <<<"${runs}")"
		bad="$(jq -r '[.[] | select(.status == "completed" and (.conclusion | IN("failure", "timed_out", "startup_failure", "action_required")))] | map("#\(.id) (\(.event)): \(.conclusion)") | join(", ")' <<<"${runs}")"
		[ -z "${bad}" ] || fail "CI (${CI_WORKFLOW}) did not pass for ${SHA}: ${bad}"
		if [ "${total}" -gt 0 ] && [ "${pending}" -eq 0 ]; then
			[ "${ok}" -gt 0 ] || fail "no successful CI (${CI_WORKFLOW}) run for ${SHA}"
			echo "ok: CI (${CI_WORKFLOW}) succeeded for ${SHA} (${ok} successful run(s))"
			return 0
		fi
		if [ "$(date +%s)" -ge "${deadline}" ]; then
			[ "${total}" -gt 0 ] && fail "CI (${CI_WORKFLOW}) for ${SHA} still running after ${CI_WAIT_SECONDS}s"
			fail "no CI (${CI_WORKFLOW}) run found for ${SHA}"
		fi
		echo "waiting for CI (${CI_WORKFLOW}) on ${SHA}: ${total} run(s), ${pending} still running …"
		sleep "${CI_POLL_SECONDS}"
	done
}

if wants version; then check_version; fi
if wants ancestry; then check_ancestry; fi
if wants ci; then check_ci; fi
echo "Release gate passed for ${TAG} at ${SHA}"
