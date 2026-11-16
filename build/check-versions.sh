#!/usr/bin/env bash
# Checks that every place carrying the plugin version or the platform requirements agrees.
#
#   bash build/check-versions.sh            consistency check of the working tree
#   bash build/check-versions.sh v1.4.0     additionally: the version must match this tag
#
# Complements build/verify-release.sh (release gate: tag/header/constant/Stable tag, ancestry, CI)
# with the checks that run on every CI build.
# Version: plugin header "Version", WPCAP_VERSION, readme.txt "Stable tag", newest released
# CHANGELOG.md section ("## X.Y.Z – date"; "## Unveröffentlicht" is skipped) – both skipped for
# pre-release versions like 1.5.0-rc.1 –, composer.json "version" (only if present).
# Requirements: "Requires PHP" (header, readme, composer.json require.php, config.platform.php),
# "Requires at least" (header and readme), "WC requires at least"/"WC tested up to" (readme only
# if it repeats them). Exit code 1 on any mismatch.
set -euo pipefail
export LC_ALL=C

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="${ROOT}/pass-captain.php"
README="${ROOT}/readme.txt"
CHANGELOG="${ROOT}/CHANGELOG.md"
COMPOSER="${ROOT}/composer.json"
TAG="${1:-}"
errors=0

fail() { echo "FAIL  $*" >&2; errors=$((errors + 1)); }
ok() { echo "ok    $*"; }

# Value of a "Key: value" line of the plugin header (" * Key: value") or readme ("Key: value").
header_field() { sed -n "s/^[ *]*$2:[[:space:]]*//p" "$1" | head -n1 | tr -d '\r' | sed 's/[[:space:]]*$//'; }
# Top-level JSON value via PHP (always available where the plugin is developed/built).
composer_field() { php -r '$j = json_decode( file_get_contents( $argv[1] ), true ); $v = $j; foreach ( explode( ".", $argv[2] ) as $k ) { $v = is_array( $v ) && array_key_exists( $k, $v ) ? $v[ $k ] : null; } echo is_scalar( $v ) ? $v : "";' "${COMPOSER}" "$1"; }

same() { # label expected actual
	if [ -z "$3" ]; then fail "$1 not found"; elif [ "$2" = "$3" ]; then ok "$1 = $3"; else fail "$1 is '$3', expected '$2'"; fi
}

version="$(header_field "${MAIN}" 'Version')"
[ -n "${version}" ] || { echo "FAIL  no Version in the plugin header of pass-captain.php" >&2; exit 1; }
echo "Plugin version (header): ${version}"
[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.]+)?$ ]] || fail "header Version '${version}' is not X.Y.Z"

constant="$(sed -n "s/^define( *'WPCAP_VERSION', *'\([^']*\)' *);.*/\1/p" "${MAIN}" | head -n1)"
same "WPCAP_VERSION" "${version}" "${constant}"
if [[ "${version}" == *-* ]]; then
	# Pre-release (as in build/verify-release.sh): Stable tag and CHANGELOG keep the last stable release.
	echo "skip  readme.txt Stable tag and CHANGELOG.md for pre-release ${version}"
else
	same "readme.txt Stable tag" "${version}" "$(header_field "${README}" 'Stable tag')"
	changelog="$(sed -n 's/^## \([0-9][0-9A-Za-z.-]*\)\([[:space:]].*\)\{0,1\}$/\1/p' "${CHANGELOG}" | head -n1)"
	same "CHANGELOG.md newest release" "${version}" "${changelog}"
fi

composer_version="$(composer_field version)"
if [ -n "${composer_version}" ]; then same "composer.json version" "${version}" "${composer_version}"; fi

if [ -n "${TAG}" ]; then same "release tag" "v${version}" "${TAG}"; fi

# Platform requirements.
php_req="$(header_field "${MAIN}" 'Requires PHP')"
same "readme.txt Requires PHP" "${php_req}" "$(header_field "${README}" 'Requires PHP')"
same "composer.json require.php" ">=${php_req}" "$(composer_field require.php)"
platform="$(composer_field config.platform.php)"
same "composer.json config.platform.php (major.minor)" "${php_req}" "$(echo "${platform}" | cut -d. -f1-2)"
same "readme.txt Requires at least" "$(header_field "${MAIN}" 'Requires at least')" "$(header_field "${README}" 'Requires at least')"
# WooCommerce headers belong in the plugin header; the readme only has to agree if it repeats them.
for field in 'WC requires at least' 'WC tested up to'; do
	readme_value="$(header_field "${README}" "${field}")"
	[ -z "${readme_value}" ] || same "readme.txt ${field}" "$(header_field "${MAIN}" "${field}")" "${readme_value}"
done

if [ "${errors}" -gt 0 ]; then
	echo "${errors} version/requirement mismatch(es)." >&2
	exit 1
fi
echo "All versions and requirements are consistent."
