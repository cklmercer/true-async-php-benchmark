#!/bin/sh
#
# config:cache freezes env() at the moment it runs, so it has to run here and
# not in the image — otherwise every value compose sets at runtime would be
# baked to whatever was present at build time.
set -eu

cd /app/www

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan event:cache >/dev/null

# ASYNC_THREADS is the worker count, and Caddy refuses num_threads equal to it —
# the runtime needs at least one thread that is not carrying a worker.
ASYNC_THREADS="${ASYNC_THREADS:-6}"
ASYNC_TOTAL_THREADS="$((ASYNC_THREADS + 1))"
export ASYNC_THREADS ASYNC_TOTAL_THREADS

echo "laravel trueasync: http://0.0.0.0:8080  threads=${ASYNC_THREADS} (+1 spare)  pool=${PG_POOL:-6}  workspaces=${WORKSPACES:-512}"

exec frankenphp run --config /app/www/Caddyfile
