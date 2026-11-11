#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/build/dist/wp-pass-captain"

rm -rf "${ROOT}/build/dist"
mkdir -p "${BUILD_DIR}"
composer install --working-dir="${ROOT}" --no-dev --no-interaction --prefer-dist --optimize-autoloader
rsync -a --exclude='.git' --exclude='.github' --exclude='.gitignore' --exclude='build' --exclude='tests' --exclude='CONCEPT.md' --exclude='TODO.md' --exclude='ROADMAP.md' --exclude='ROADMAP_11_30.md' --exclude='README.md' --exclude='composer.json' --exclude='composer.lock' --exclude='phpunit.xml.dist' "${ROOT}/" "${BUILD_DIR}/"
(cd "${ROOT}/build/dist" && zip -qr "wp-pass-captain.zip" "wp-pass-captain")
echo "Created ${ROOT}/build/dist/wp-pass-captain.zip"
