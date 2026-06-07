#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${WPORG_PLUGIN_SLUG:-corepay-money-woocommerce}"
DIST_ROOT="${ROOT_DIR}/.dist"
PACKAGE_DIR="${DIST_ROOT}/${PLUGIN_SLUG}"

if [[ -n "${VERSION}" ]]; then
	ZIP_PATH="${DIST_ROOT}/${PLUGIN_SLUG}-${VERSION}.zip"
else
	ZIP_PATH="${DIST_ROOT}/${PLUGIN_SLUG}.zip"
fi

rm -rf "${PACKAGE_DIR}" "${ZIP_PATH}"
mkdir -p "${PACKAGE_DIR}"

rsync -av "${ROOT_DIR}/" "${PACKAGE_DIR}/" \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.wordpress-org' \
	--exclude='scripts' \
	--exclude='README.md' \
	--exclude='node_modules' \
	--exclude='vendor' \
	--exclude='.dist' \
	--exclude='*.zip' \
	--exclude='.DS_Store'

(
	cd "${DIST_ROOT}"
	zip -r "${ZIP_PATH}" "${PLUGIN_SLUG}" >/dev/null
)

echo "${ZIP_PATH}"
