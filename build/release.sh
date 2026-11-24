#!/usr/bin/env bash
# Builds the installable plugin ZIP (build/dist/pass-captain.zip) from a committed tree.
#
# - Exports the git tree (default HEAD; override with WPCAP_BUILD_REF=<ref>) with `git archive`,
#   so uncommitted or untracked files in the working copy never end up in a release.
# - Runs `composer install --no-dev` inside the export, never in the development tree.
# - Copies the export into the package while honouring .distignore (from the exported tree).
# - Reproducible (A112): the same commit and the same composer.lock give a byte-identical ZIP. Every
#   entry gets the commit time as mtime (SOURCE_DATE_EPOCH, default: committer date of the ref),
#   normalised permissions (0644, 0755 for directories and executables), a sorted entry order,
#   UTC timestamps, no extra attributes (zip -X) and a fixed autoloader suffix.
# - Writes build/dist/pass-captain.zip.sha256 (published with the release, see docs/COMPATIBILITY.md)
#   and build/dist/build-info.txt (commit, epoch, checksum). Check a published ZIP with:
#   WPCAP_BUILD_REF=<tag> bash build/release.sh && sha256sum -c.
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

COMMIT="$(git -C "${ROOT}" rev-parse "${REF}^{commit}")"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT}" log -1 --format=%ct "${COMMIT}")}"
export SOURCE_DATE_EPOCH

mkdir -p "${WORK}/src"
git -C "${ROOT}" archive --format=tar "${REF}" | tar -x -C "${WORK}/src"

if [ -f "${WORK}/src/composer.json" ]; then
	# Fixed autoloader class suffix (otherwise derived from a random value in some Composer versions).
	composer config --no-plugins --working-dir="${WORK}/src" autoloader-suffix "WpcapRelease" >/dev/null
	COMPOSER_ALLOW_SUPERUSER=1 composer install --working-dir="${WORK}/src" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-scripts --no-plugins
	# A511: no Composer platform check in the ZIP – on a too old PHP it would end every request with a
	# fatal error; pass-captain.php checks the versions itself and only shows an admin notice.
	# Only enforced for trees whose composer.json opts out of the check: an older release (e.g. the
	# previous tag rebuilt for the upgrade tests) is reproduced as it was shipped.
	if [ -f "${WORK}/src/vendor/composer/platform_check.php" ]; then
		if grep -q '"platform-check"[[:space:]]*:[[:space:]]*false' "${WORK}/src/composer.json"; then
			echo "vendor/composer/platform_check.php must not be shipped (composer.json config.platform-check = false)." >&2
			exit 1
		fi
		echo "Note: ${REF} predates config.platform-check = false; its ZIP keeps vendor/composer/platform_check.php as shipped." >&2
	fi
fi

# A275: translations of the block editor scripts (src/blocks/*/editor.js) as JED JSON files
# (languages/pass-captain-<locale>-<md5 of the script path>.json), generated from the .po sources
# that are not shipped. WordPress loads them via wp_set_script_translations (Localization::block_translations).
if ls "${WORK}/src/languages/"*.po >/dev/null 2>&1; then
	if command -v wp >/dev/null 2>&1; then
		wp i18n make-json "${WORK}/src/languages" --no-purge --allow-root --quiet
	elif [ "${WPCAP_BUILD_SKIP_JSON:-}" != "1" ]; then
		echo "wp-cli is required for the script translations (wp i18n make-json); set WPCAP_BUILD_SKIP_JSON=1 to build without them." >&2
		exit 1
	fi
fi

# Releases up to 1.4 were called "WP Pass Captain" with folder/main file wp-pass-captain (legacy-slug):
# an older ref (e.g. the previous release for the upgrade drill) is packaged under its own name.
if [ ! -f "${WORK}/src/${SLUG}.php" ] && [ -f "${WORK}/src/wp-pass-captain.php" ]; then # legacy-slug
	SLUG="wp-pass-captain" # legacy-slug
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
SUM="$(cd "${DIST_DIR}" && sha256sum "${SLUG}.zip")"
echo "${SUM}" > "${DIST_DIR}/${SLUG}.zip.sha256"
{
	echo "commit=${COMMIT}"
	echo "ref=${REF}"
	echo "source_date_epoch=${SOURCE_DATE_EPOCH}"
	echo "sha256=${SUM%% *}"
	echo "composer_lock_sha256=$( [ -f "${WORK}/src/composer.lock" ] && sha256sum "${WORK}/src/composer.lock" | cut -d' ' -f1 )"
} > "${DIST_DIR}/build-info.txt"
echo "Created ${DIST_DIR}/${SLUG}.zip from ${REF} (${COMMIT:0:7}, SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH})"
echo "sha256: ${SUM%% *}"
