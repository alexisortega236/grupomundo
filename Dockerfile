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

FROM php:8.3-apache
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends bash libicu-dev libzip-dev libpq-dev libsqlite3-dev libonig-dev \
    && docker-php-ext-install intl mbstring pdo_mysql pdo_pgsql pdo_sqlite zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/apache-laravel.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/render-entrypoint

RUN chmod +x /usr/local/bin/render-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache database public

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV APP_URL=https://grupomundo.onrender.com
ENV ASSET_URL=https://grupomundo.onrender.com
ENV LOG_CHANNEL=stderr
ENV SESSION_DRIVER=database
ENV CACHE_STORE=database
ENV QUEUE_CONNECTION=database
ENV DB_CONNECTION=sqlite
ENV DB_DATABASE=/var/www/html/database/database.sqlite

EXPOSE 10000

ENTRYPOINT ["render-entrypoint"]
CMD ["apache2-foreground"]
