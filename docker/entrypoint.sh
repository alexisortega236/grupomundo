#!/usr/bin/env bash
set -euo pipefail

if [ -z "${APP_KEY:-}" ]; then
  export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  SQLITE_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
  mkdir -p "$(dirname "${SQLITE_PATH}")"
  touch "${SQLITE_PATH}"
fi

php artisan config:clear --no-interaction
php artisan storage:link --force --no-interaction || true
php artisan migrate --force --no-interaction

if [ "${RUN_SEEDERS:-true}" = "true" ]; then
  php artisan db:seed --force --no-interaction
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
