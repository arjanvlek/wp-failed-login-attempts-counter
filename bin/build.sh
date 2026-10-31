#!/usr/bin/env bash
#
# Builds the distributable plugin zip in dist/.
#
# Files listed in .distignore are left out, the same list that
# `wp dist-archive` uses. Usage: bin/build.sh
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="failed-login-attempts-counter"
MAIN_FILE="${SLUG}.php"
VERSION=$(grep -E '^ \* Version:' "$MAIN_FILE" | awk '{print $3}')

if [ -z "$VERSION" ]; then
	echo "Could not read the version from $MAIN_FILE" >&2
	exit 1
fi

if command -v php > /dev/null; then
	echo "Checking PHP syntax..."
	find . -name '*.php' -not -path './vendor/*' -not -path './build/*' -print0 | xargs -0 -n1 php -l > /dev/null
fi

BUILD_DIR="build/${SLUG}"
DIST_DIR="dist"
ZIP_FILE="${DIST_DIR}/${SLUG}-${VERSION}.zip"

rm -rf "./${BUILD_DIR:?}"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

rsync -a --exclude-from=.distignore ./ "$BUILD_DIR/"

rm -f "./${ZIP_FILE:?}"
( cd build && zip -rq "../$ZIP_FILE" "$SLUG" )

rm -rf "./${BUILD_DIR:?}"

echo "Built $ZIP_FILE"
unzip -l "$ZIP_FILE"
