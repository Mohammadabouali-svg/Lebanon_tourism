# syntax=docker/dockerfile:1
#
# PHP-FPM image for the Laravel API.
#
# One Dockerfile, several "targets" (stages). Docker Compose picks the target:
#   - `dev`  : source code is bind-mounted from your disk, Xdebug-free, verbose errors.
#   - `prod` : source code and vendor/ are baked into the image, opcache is tuned,
#              no Composer dev dependencies, errors are logged instead of displayed.
#
# Sharing a `base` stage guarantees dev and prod run the *same* PHP version with
# the *same* extensions, which removes a whole class of "works on my machine" bugs.

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm AS base

# install-php-extensions is a widely used helper that knows which system
# libraries every PHP extension needs (e.g. libpq for pdo_pgsql).
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions \
        pdo_pgsql \
        intl \
        zip \
        bcmath \
        opcache \
        pcntl \
        gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run PHP as the same user id as the person on the host. Files the container
# creates (logs, uploads, generated code) are then owned by *you*, not by root.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g www-data www-data

WORKDIR /var/www/backend

COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
FROM base AS dev

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
# Composer needs a writable home for its cache.
ENV COMPOSER_HOME=/tmp/composer \
    CONTAINER_MODE=dev
USER www-data

# ---------------------------------------------------------------------------
FROM base AS prod

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
ENV CONTAINER_MODE=prod

# Install dependencies *before* copying the source code. Docker caches each
# layer, so as long as composer.json/lock do not change, rebuilding the image
# after a code change skips the slow `composer install` step entirely.
COPY --chown=www-data:www-data backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=www-data:www-data backend/ ./
# .dockerignore keeps local logs/caches out of the image, so recreate the
# empty folders Laravel writes to at runtime.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs storage/app/public bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
