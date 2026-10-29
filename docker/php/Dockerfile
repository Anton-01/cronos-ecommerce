# syntax=docker/dockerfile:1.7
ARG PHP_VERSION=8.3

# ---- base: runtime extensions shared by every stage ----
FROM php:${PHP_VERSION}-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libvips-tools ffmpeg exiftool unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd pcntl bcmath opcache \
    && pecl install redis igbinary \
    && docker-php-ext-enable redis igbinary \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# ---- vendor: dependency layer cached independently of source ----
FROM base AS vendor
COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- dev: bind-mounted source, dev tooling, xdebug off by default ----
FROM base AS dev
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-app.ini
RUN useradd -u 1000 -m app && chown -R app:app /var/www/html
USER app

# ---- prod: immutable image, non-root, OPcache tuned ----
FROM base AS prod
COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=vendor /var/www/html/vendor ./vendor
COPY backend/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev --no-scripts \
    && php artisan package:discover --ansi || true \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD php-fpm-healthcheck || pgrep php-fpm >/dev/null
