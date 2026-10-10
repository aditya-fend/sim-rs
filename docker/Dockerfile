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

# The built-in server (and the CLI test suite) runs as the CLI SAPI, where
# OPcache is off by default, so every request in development recompiles the
# whole framework. Enabling it here also removes opcode compilation from the
# unit/feature test timings.
RUN { \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

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

# Production target: deployment.md §5 specifies PHP 8.3-FPM behind Nginx. The
# CLI base above is for development and the test suites; the CLI server is not
# a production SAPI, and its per-request overhead is large enough to distort
# the NFR-03 latency profile, so the released image needs a real FPM pool.
FROM php:8.3-fpm-bookworm AS fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
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
    && pecl install redis && docker-php-ext-enable redis \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/klinikform.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-klinikform.conf
COPY --chown=www-data:www-data docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --chown=www-data:www-data . /var/www/html

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data
EXPOSE 9000

CMD ["php-fpm", "-F"]

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