#!/usr/bin/env bash
set -euo pipefail

BASE_PATH="${STATIC_BASE_PATH:-/grupomundo}"
HOST="${STATIC_HOST:-127.0.0.1}"
PORT="${STATIC_PORT:-8099}"
ORIGIN="http://${HOST}:${PORT}"
OUT_DIR="docs"

php artisan migrate --seed --force >/dev/null
npm run build >/dev/null

rm -rf "${OUT_DIR}"
mkdir -p "${OUT_DIR}"
cp -R public/build "${OUT_DIR}/build"
touch "${OUT_DIR}/.nojekyll"

php artisan serve --host="${HOST}" --port="${PORT}" >/tmp/grupo-mundo-static-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "${SERVER_PID}" >/dev/null 2>&1 || true' EXIT
sleep 2

SLUG_FILE="/tmp/grupo-mundo-static-slugs.txt"
php artisan tinker --execute="App\\Models\\Property::published()->pluck('slug')->each(fn (\$slug) => print(\$slug.PHP_EOL));" > "${SLUG_FILE}"

write_page() {
    local route_path="$1"
    local target="$2"
    mkdir -p "$(dirname "${target}")"
    curl -fsSL "${ORIGIN}${route_path}" \
        | sed \
            -e "s#href=\"/#href=\"${BASE_PATH}/#g" \
            -e "s#src=\"/#src=\"${BASE_PATH}/#g" \
            -e "s#action=\"/#action=\"${BASE_PATH}/#g" \
            -e "s#content=\"${ORIGIN}/#content=\"${BASE_PATH}/#g" \
        > "${target}"
}

write_page "/" "${OUT_DIR}/index.html"
write_page "/propiedades" "${OUT_DIR}/propiedades/index.html"
write_page "/servicios" "${OUT_DIR}/servicios/index.html"
write_page "/nosotros" "${OUT_DIR}/nosotros/index.html"
write_page "/contacto" "${OUT_DIR}/contacto/index.html"

while IFS= read -r slug; do
    [ -z "${slug}" ] && continue
    write_page "/propiedades/${slug}" "${OUT_DIR}/propiedades/${slug}/index.html"
done < "${SLUG_FILE}"

curl -fsSL "${ORIGIN}/sitemap.xml" \
    | sed -e "s#${ORIGIN}#https://alexisortega236.github.io${BASE_PATH}#g" \
    > "${OUT_DIR}/sitemap.xml"

printf 'Static export written to %s with base path %s\n' "${OUT_DIR}" "${BASE_PATH}"
