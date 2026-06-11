#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"

if [[ -z "${VERSION}" ]]; then
	echo "Usage: $0 VERSION"
	exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="${WPORG_PLUGIN_SLUG:-corepay-gateway-for-woocommerce}"
SVN_USERNAME="${WPORG_SVN_USERNAME:-corelabs}"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}/"
BUILD_DIR="${WPORG_SVN_BUILD_DIR:-${ROOT_DIR}/.wordpress-org/svn}"

if ! svn ls "${SVN_URL}" --username "${SVN_USERNAME}" >/dev/null; then
	echo "The WordPress.org SVN repository does not exist or the slug is not approved yet. Check WPORG_PLUGIN_SLUG."
	exit 1
fi

rm -rf "${BUILD_DIR}"
svn checkout "${SVN_URL}" "${BUILD_DIR}" --username "${SVN_USERNAME}"

mkdir -p "${BUILD_DIR}/trunk" "${BUILD_DIR}/assets" "${BUILD_DIR}/tags"

# assets/ contains plugin runtime JS/CSS and is synced into SVN trunk.
# .wordpress-org/assets contains WordPress.org screenshots/icons/banners only
# and is synced into the SVN top-level assets directory.
rsync -av --delete "${ROOT_DIR}/" "${BUILD_DIR}/trunk/" \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.wordpress-org' \
	--exclude='scripts' \
	--exclude='README.md' \
	--exclude='node_modules' \
	--exclude='vendor' \
	--exclude='*.zip' \
	--exclude='.DS_Store'

rsync -av --delete "${ROOT_DIR}/.wordpress-org/assets/" "${BUILD_DIR}/assets/"

cd "${BUILD_DIR}"

svn add --force trunk assets
svn status | awk '/^!/ {print $2}' | xargs -r svn delete

if [[ -e "tags/${VERSION}" ]]; then
	echo "SVN tag ${VERSION} already exists. Refusing to overwrite tags/${VERSION}."
	exit 1
fi

svn copy trunk "tags/${VERSION}"
svn status
svn commit -m "Release ${VERSION}" --username "${SVN_USERNAME}"
