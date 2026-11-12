#!/usr/bin/env bash
# Builds the installable plugin ZIP (build/dist/wp-pass-captain.zip) from a committed tree.
#
# - Exports the git tree (default HEAD; override with WPCAP_BUILD_REF=<ref>) with `git archive`,
#   so uncommitted or untracked files in the working copy never end up in a release.
# - Runs `composer install --no-dev` inside the export, never in the development tree.
# - Copies the export into the package while honouring .distignore (from the exported tree).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REF="${WPCAP_BUILD_REF:-HEAD}"
DIST_DIR="${ROOT}/build/dist"
SLUG="wp-pass-captain"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/wpcap-build.XXXXXX")"
trap 'rm -rf "${WORK}"' EXIT

git -C "${ROOT}" rev-parse --verify --quiet "${REF}^{commit}" >/dev/null || { echo "Unknown git ref: ${REF}" >&2; exit 1; }
if [ -n "$(git -C "${ROOT}" status --porcelain --untracked-files=no 2>/dev/null)" ] && [ "${REF}" = "HEAD" ]; then
	echo "Note: uncommitted changes are not part of the build (building ${REF} = $(git -C "${ROOT}" rev-parse --short HEAD))." >&2
fi

mkdir -p "${WORK}/src"
git -C "${ROOT}" archive --format=tar "${REF}" | tar -x -C "${WORK}/src"

if [ -f "${WORK}/src/composer.json" ]; then
	composer install --working-dir="${WORK}/src" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi

DISTIGNORE="${WORK}/src/.distignore"
[ -f "${DISTIGNORE}" ] || { echo "Missing .distignore in ${REF}" >&2; exit 1; }

mkdir -p "${WORK}/pkg/${SLUG}"
rsync -a --exclude-from="${DISTIGNORE}" "${WORK}/src/" "${WORK}/pkg/${SLUG}/"

# Sanity checks: the main file, readme and runtime autoloader must be in the package.
for required in "${SLUG}.php" readme.txt uninstall.php vendor/autoload.php; do
	[ -e "${WORK}/pkg/${SLUG}/${required}" ] || { echo "Package is missing ${required}" >&2; exit 1; }
done

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"
(cd "${WORK}/pkg" && zip -qr -X "${DIST_DIR}/${SLUG}.zip" "${SLUG}")
echo "Created ${DIST_DIR}/${SLUG}.zip from ${REF} ($(git -C "${ROOT}" rev-parse --short "${REF}"))"
