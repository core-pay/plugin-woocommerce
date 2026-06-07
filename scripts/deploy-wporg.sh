#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"

if [[ -z "${VERSION}" ]]; then
	echo "Usage: $0 VERSION"
	exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${WPORG_PLUGIN_SLUG:-corepay-money-woocommerce}"
SVN_URL="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/${PLUGIN_SLUG}}"
SVN_DIR="${WPORG_SVN_DIR:-${ROOT_DIR}/.wordpress-org/svn}"

# assets/ contains plugin runtime JS/CSS and is synced into SVN trunk.
# .wordpress-org/assets contains WordPress.org screenshots/icons/banners only
# and is synced into the SVN top-level assets directory.
mkdir -p "${SVN_DIR}"

if [[ ! -d "${SVN_DIR}/.svn" ]]; then
	rm -rf "${SVN_DIR}"
	svn checkout "${SVN_URL}" "${SVN_DIR}"
else
	svn update "${SVN_DIR}"
fi

mkdir -p "${SVN_DIR}/trunk" "${SVN_DIR}/assets" "${SVN_DIR}/tags"

rsync -av --delete "${ROOT_DIR}/" "${SVN_DIR}/trunk/" \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.wordpress-org' \
	--exclude='scripts' \
	--exclude='README.md' \
	--exclude='package.json' \
	--exclude='package-lock.json'

rsync -av --delete "${ROOT_DIR}/.wordpress-org/assets/" "${SVN_DIR}/assets/"

svn add --force "${SVN_DIR}/trunk" "${SVN_DIR}/assets"

if [[ -e "${SVN_DIR}/tags/${VERSION}" ]]; then
	echo "SVN tag ${VERSION} already exists."
	exit 1
fi

svn copy "${SVN_DIR}/trunk" "${SVN_DIR}/tags/${VERSION}"

svn status "${SVN_DIR}"
svn commit "${SVN_DIR}" -m "Release ${VERSION}"
