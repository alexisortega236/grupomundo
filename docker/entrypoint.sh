#!/usr/bin/env bash
set -euo pipefail

if [ -z "${APP_KEY:-}" ]; then
  echo "ERROR: APP_KEY must be configured before starting the application." >&2
  exit 1
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  SQLITE_PATH="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
  mkdir -p "$(dirname "${SQLITE_PATH}")"
  touch "${SQLITE_PATH}"
fi

php artisan config:clear --no-interaction
php artisan storage:link --force --no-interaction || true
php artisan migrate --force --no-interaction
echo "Seeding Morelos postal settlements..."
php artisan db:seed --class=MorelosPostalSettlementsSeeder --force --no-interaction || {
  echo "ERROR: MorelosPostalSettlementsSeeder failed; aborting container startup." >&2
  exit 1
}
echo "Seeding Ciudad de México postal settlements..."
php artisan db:seed --class=CdmxPostalSettlementsSeeder --force --no-interaction || {
  echo "ERROR: CdmxPostalSettlementsSeeder failed; aborting container startup." >&2
  exit 1
}
echo "Seeding Ciudad de México official colonies..."
php artisan db:seed --class=CdmxOfficialColoniesSeeder --force --no-interaction || {
  echo "ERROR: CdmxOfficialColoniesSeeder failed; aborting container startup." >&2
  exit 1
}

# DatabaseSeeder contains development/demo data. Production must opt in
# explicitly; the catalog seeders above remain part of the application boot.
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  php artisan db:seed --force --no-interaction
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

chown -R www-data:www-data storage bootstrap/cache database public/storage 2>/dev/null || true

if command -v apache2-foreground >/dev/null 2>&1; then
  APACHE_PORT="${PORT:-10000}"
  sed -ri "s/^Listen .*/Listen ${APACHE_PORT}/" /etc/apache2/ports.conf
  sed -ri "s/<VirtualHost \\*:\\$\\{PORT\\}>/<VirtualHost *:${APACHE_PORT}>/" /etc/apache2/sites-available/000-default.conf
  sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${APACHE_PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

exec "$@"
