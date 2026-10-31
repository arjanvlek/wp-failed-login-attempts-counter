#!/usr/bin/env bash
#
# Regenerates languages/failed-login-attempts-counter.pot from the source,
# merges new strings into every .po file and compiles the .mo files.
#
# Uses WP-CLI from the host when available, otherwise the wordpress:cli
# Docker image. Usage: bin/update-translations.sh
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="failed-login-attempts-counter"
POT="languages/${SLUG}.pot"

if command -v wp > /dev/null; then
	wp() { command wp "$@"; }
else
	wp() { docker run --rm -v "$PWD:/app" -w /app wordpress:cli wp "$@"; }
fi

wp i18n make-pot . "$POT" \
	--slug="$SLUG" \
	--domain="$SLUG" \
	--exclude=vendor,build,dist,tests,bin,docker \
	--headers='{"Report-Msgid-Bugs-To":"","Last-Translator":"","Language-Team":""}'

if ls languages/*.po > /dev/null 2>&1; then
	wp i18n update-po "$POT" languages/
	wp i18n make-mo languages/
fi

echo "Translations updated."
