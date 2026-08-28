#!/bin/sh
#
# config:cache freezes env() at the moment it runs, so it has to run here and
# not in the image — otherwise every value compose sets at runtime would be
# baked to whatever was present at build time.
set -eu

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan event:cache >/dev/null

echo "laravel octane: http://0.0.0.0:${HTTP_PORT:-8080}  workers=${OCTANE_WORKERS:-auto}  workspaces=${WORKSPACES:-512}"

exec php artisan octane:start \
  --server=frankenphp \
  --host=0.0.0.0 \
  --port="${HTTP_PORT:-8080}" \
  --workers="${OCTANE_WORKERS:-auto}" \
  --max-requests="${OCTANE_MAX_REQUESTS:-10000000}" \
  --no-interaction
