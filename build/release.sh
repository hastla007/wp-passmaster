#!/usr/bin/env bash
# Builds the installable plugin ZIP (build/dist/wp-pass-captain.zip) from a committed tree.
#
# - Exports the git tree (default HEAD; override with WPCAP_BUILD_REF=<ref>) with `git archive`,
#   so uncommitted or untracked files in the working copy never end up in a release.
# - Runs `composer install --no-dev` inside the export, never in the development tree.
# - Copies the export into the package while honouring .distignore (from the exported tree).
# - Reproducible (A112): file times are set to the commit time (SOURCE_DATE_EPOCH, default: commit date
#   of the ref), permissions are normalised (644/755), entries are added in sorted order without extra
#   attributes, and the autoloader suffix is fixed. The same commit therefore gives a byte-identical ZIP.
#   Next to the ZIP the build writes `wp-pass-captain.zip.sha256` and `build-info.txt` (commit, epoch,
#   checksum). Check a published ZIP with: WPCAP_BUILD_REF=<tag> bash build/release.sh && sha256sum -c.
set -euo pipefail
export LC_ALL=C TZ=UTC

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

COMMIT="$(git -C "${ROOT}" rev-parse "${REF}^{commit}")"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT}" log -1 --format=%ct "${COMMIT}")}"
export SOURCE_DATE_EPOCH

mkdir -p "${WORK}/src"
git -C "${ROOT}" archive --format=tar "${REF}" | tar -x -C "${WORK}/src"

if [ -f "${WORK}/src/composer.json" ]; then
	# Fixed autoloader class suffix (otherwise derived from a random value in some Composer versions).
	composer config --working-dir="${WORK}/src" autoloader-suffix "WpcapRelease" >/dev/null
	COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir="${WORK}/src" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi

DISTIGNORE="${WORK}/src/.distignore"
[ -f "${DISTIGNORE}" ] || { echo "Missing .distignore in ${REF}" >&2; exit 1; }

mkdir -p "${WORK}/pkg/${SLUG}"
rsync -a --exclude-from="${DISTIGNORE}" "${WORK}/src/" "${WORK}/pkg/${SLUG}/"

# Sanity checks: the main file, readme and runtime autoloader must be in the package.
for required in "${SLUG}.php" readme.txt uninstall.php vendor/autoload.php; do
	[ -e "${WORK}/pkg/${SLUG}/${required}" ] || { echo "Package is missing ${required}" >&2; exit 1; }
done

# Normalise metadata so the archive only depends on the file contents.
find "${WORK}/pkg" -type d -exec chmod 755 {} +
find "${WORK}/pkg" -type f -exec chmod 644 {} +
find "${WORK}/pkg" -exec touch -h -d "@${SOURCE_DATE_EPOCH}" {} +

rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"
(cd "${WORK}/pkg" && find "${SLUG}" -print | sort | zip -q -X -D -@ "${DIST_DIR}/${SLUG}.zip")
SUM="$(cd "${DIST_DIR}" && sha256sum "${SLUG}.zip")"
echo "${SUM}" > "${DIST_DIR}/${SLUG}.zip.sha256"
{
	echo "commit=${COMMIT}"
	echo "ref=${REF}"
	echo "source_date_epoch=${SOURCE_DATE_EPOCH}"
	echo "sha256=${SUM%% *}"
	echo "composer_lock_sha256=$( [ -f "${WORK}/src/composer.lock" ] && sha256sum "${WORK}/src/composer.lock" | cut -d' ' -f1 )"
} > "${DIST_DIR}/build-info.txt"
echo "Created ${DIST_DIR}/${SLUG}.zip from ${REF} (${COMMIT:0:7}), sha256 ${SUM%% *}"
