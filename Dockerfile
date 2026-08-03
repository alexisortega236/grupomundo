FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

FROM php:8.3-cli-alpine
WORKDIR /var/www/html

RUN apk add --no-cache bash icu-dev libzip-dev postgresql-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-install intl mbstring pdo_mysql pdo_pgsql pdo_sqlite zip \
    && addgroup -g 1000 laravel \
    && adduser -D -G laravel -u 1000 laravel

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/render-entrypoint

RUN chmod +x /usr/local/bin/render-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R laravel:laravel storage bootstrap/cache database public

USER laravel

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
ENV SESSION_DRIVER=database
ENV CACHE_STORE=database
ENV QUEUE_CONNECTION=database
ENV DB_CONNECTION=sqlite
ENV DB_DATABASE=/var/www/html/database/database.sqlite

EXPOSE 10000

ENTRYPOINT ["render-entrypoint"]
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
