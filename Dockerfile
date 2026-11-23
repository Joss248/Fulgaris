# syntax=docker/dockerfile:1

FROM php:8.2-apache-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install -j"$(nproc)" opcache pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite setenvif

WORKDIR /var/www/html

FROM composer:2 AS composer-cli

FROM php-base AS backend

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-cli /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY artisan ./
COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && composer check-platform-reqs --no-dev

FROM node:24-bookworm-slim AS frontend

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ resources/
COPY --from=backend /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views/ vendor/laravel/framework/src/Illuminate/Pagination/resources/views/
RUN npm run build && test -s public/build/manifest.json

FROM php-base AS web

ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    MAIL_MAILER=array

COPY --from=backend /var/www/html/ ./
COPY --from=frontend /build/public/build/ public/build/
COPY docker/apache.conf /etc/apache2/sites-available/bepartners.conf
COPY docker/init-postgres.php docker/init-postgres.php
COPY docker/init-admin.php docker/init-admin.php

RUN chown -R www-data:www-data storage bootstrap/cache \
    && truncate -s 0 /etc/apache2/ports.conf \
    && a2dissite 000-default \
    && a2ensite bepartners \
    && apache2ctl configtest

EXPOSE 10000
CMD ["sh", "-ec", "if [ -n \"${BEPARTNERS_INIT_DB:-}\" ] && [ -n \"${BEPARTNERS_INIT_ADMIN_DB:-}\" ]; then echo 'BEPARTNERS ADMIN ERROR: habilita solo un inicializador.' >&2; exit 1; fi; if [ -n \"${BEPARTNERS_INIT_DB:-}\" ]; then php -d display_errors=0 -d log_errors=0 docker/init-postgres.php; fi; if [ -n \"${BEPARTNERS_INIT_ADMIN_DB:-}\" ]; then php -d display_errors=0 -d log_errors=0 docker/init-admin.php; fi; unset BEPARTNERS_INIT_ADMIN_DB BEPARTNERS_INIT_ADMIN_NAME BEPARTNERS_INIT_ADMIN_EMAIL BEPARTNERS_INIT_ADMIN_PASSWORD; exec apache2-foreground"]
