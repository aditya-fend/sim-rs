# syntax=docker/dockerfile:1

# Base image: PHP 8.3 CLI on Debian bookworm.
# The workspace host has PHP 8.5 without pdo/mbstring/dom, so the whole
# PHP toolchain (composer, artisan, phpstan, pest) runs in here instead.
FROM php:8.3-cli-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        xml \
        bcmath \
        curl \
        zip \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# ext-redis drives the Redis session/cache/queue stores named in tech-stack.md.
# buildDeps is installed, used, then removed in the same layer.
RUN savedAptMark="$(apt-mark showmanual)"; \
    apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libz-dev \
    ; \
    pecl install redis pcov && docker-php-ext-enable redis pcov; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/{redis,pcov}.so | awk '/= not found/ {print}'; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_CLI_SERVER_WORKERS=8 \
    APP_ENV=local

WORKDIR /var/www/html

# Dusk target: identical toolchain plus a matched chromium/chromedriver pair.
FROM base AS dusk

RUN apt-get update && apt-get install -y --no-install-recommends \
        chromium \
        chromium-driver \
        fonts-liberation \
        libnss3 \
        libxss1 \
        libasound2 \
    && rm -rf /var/lib/apt/lists/*

ENV DUSK_DRIVER_URL=/usr/bin/chromedriver