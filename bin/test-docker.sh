#!/usr/bin/env bash
#
# Runs the test suite inside Docker, no PHP or MySQL needed on the host.
# Usage: bin/test-docker.sh [phpunit args]
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose build php
docker compose run --rm php composer install --no-interaction --prefer-dist
docker compose run --rm php bin/install-wp-tests.sh wordpress_test root root db latest true
docker compose run --rm php composer test -- "$@"
