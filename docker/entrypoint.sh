#!/bin/sh
set -eu

cd /app

# Laravel's encrypter needs a key at boot even though this API never encrypts
# anything (no sessions, no cookies). Generate an ephemeral one if none is set.
if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export APP_KEY
fi

if [ -z "${API_TOKEN:-}" ]; then
    echo "WARNING: API_TOKEN is empty - every request will be rejected with 401." >&2
fi

# The database is already healthy (depends_on: service_healthy).
php artisan migrate --force --no-interaction

# exec: PHP becomes the main process and receives SIGTERM from `docker compose
# stop`; Octane forwards it to FrankenPHP, which stops accepting connections
# and lets in-flight requests finish (graceful shutdown).
exec php artisan octane:frankenphp \
    --host=0.0.0.0 \
    --port=8080 \
    --workers="${OCTANE_WORKERS:-128}" \
    --max-requests="${OCTANE_MAX_REQUESTS:-1000000}" \
    --log-level="${OCTANE_LOG_LEVEL:-WARN}"
