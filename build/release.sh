#!/usr/bin/env bash
# Builds the installable plugin ZIP (build/dist/pass-captain.zip) from a committed tree.
#
# - Exports the git tree (default HEAD; override with WPCAP_BUILD_REF=<ref>) with `git archive`,
#   so uncommitted or untracked files in the working copy never end up in a release.
# - Runs `composer install --no-dev` inside the export, never in the development tree.
# - Copies the export into the package while honouring .distignore (from the exported tree).
# - Reproducible: the same commit and the same composer.lock give a byte-identical ZIP. Every
#   entry gets the commit time as mtime (SOURCE_DATE_EPOCH, default: committer date of the ref),
#   normalised permissions (0644, 0755 for directories and executables), a sorted entry order,
#   UTC timestamps and no extra attributes (zip -X).
# - Writes build/dist/pass-captain.zip.sha256 (published with the release, see docs/COMPATIBILITY.md).
set -euo pipefail
export LC_ALL=C TZ=UTC
umask 022

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REF="${WPCAP_BUILD_REF:-HEAD}"
DIST_DIR="${ROOT}/build/dist"
SLUG="pass-captain"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/wpcap-build.XXXXXX")"
trap 'rm -rf "${WORK}"' EXIT

git -C "${ROOT}" rev-parse --verify --quiet "${REF}^{commit}" >/dev/null || { echo "Unknown git ref: ${REF}" >&2; exit 1; }
if [ -n "$(git -C "${ROOT}" status --porcelain --untracked-files=no 2>/dev/null)" ] && [ "${REF}" = "HEAD" ]; then
	echo "Note: uncommitted changes are not part of the build (building ${REF} = $(git -C "${ROOT}" rev-parse --short HEAD))." >&2
fi

SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT}" log -1 --format=%ct "${REF}")}"
export SOURCE_DATE_EPOCH

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

# Normalise metadata so the archive depends on nothing but the file contents.
find "${WORK}/pkg" -type d -exec chmod 0755 {} +
find "${WORK}/pkg" -type f -perm /111 -exec chmod 0755 {} +
find "${WORK}/pkg" -type f ! -perm /111 -exec chmod 0644 {} +
find "${WORK}/pkg" -exec touch -h -d "@${SOURCE_DATE_EPOCH}" {} +

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"
# Sorted entry list including directory entries; -X drops uid/gid and extended timestamps.
(cd "${WORK}/pkg" && find "${SLUG}" -print | sort | zip -q -X -@ "${DIST_DIR}/${SLUG}.zip")
(cd "${DIST_DIR}" && sha256sum "${SLUG}.zip" > "${SLUG}.zip.sha256")
echo "Created ${DIST_DIR}/${SLUG}.zip from ${REF} ($(git -C "${ROOT}" rev-parse --short "${REF}"), SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH})"
echo "sha256: $(cut -d' ' -f1 "${DIST_DIR}/${SLUG}.zip.sha256")"
