# syntax=docker/dockerfile:1.7
#
# Orbit images — one Dockerfile, two runtime targets:
#
#   docker build --target app   -t orbit-app   .   # PHP-FPM (web, queue worker, scheduler, migrations)
#   docker build --target nginx -t orbit-nginx .   # Nginx serving /public and proxying to PHP-FPM
#
ARG PHP_VERSION=8.4
ARG NGINX_VERSION=1.29

# -----------------------------------------------------------------------------
# base: PHP-FPM + extensions + production php.ini / opcache / fpm pool config
# -----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache \
        fcgi freetype icu-libs libjpeg-turbo libpng libpq libwebp libzip tini; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS freetype-dev icu-dev libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev linux-headers postgresql-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_pgsql zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear ~/.pearrc; \
    php -m

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/conf.d/orbit.ini "$PHP_INI_DIR/conf.d/zz-orbit.ini"
COPY docker/php/php-fpm.d/zz-orbit.conf /usr/local/etc/php-fpm.d/zz-orbit.conf
COPY docker/php/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck
COPY docker/php/entrypoint.sh /usr/local/bin/orbit-entrypoint
RUN chmod +x /usr/local/bin/fpm-healthcheck /usr/local/bin/orbit-entrypoint

# FPM pool sizing, overridable at runtime (e.g. from the Helm chart).
ENV PHP_FPM_PM=dynamic \
    PHP_FPM_PM_MAX_CHILDREN=10 \
    PHP_FPM_PM_START_SERVERS=3 \
    PHP_FPM_PM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_PM_MAX_SPARE_SERVERS=5 \
    PHP_FPM_PM_MAX_REQUESTS=500 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# vendor: install Composer dependencies (no dev) and build the optimized autoloader
# -----------------------------------------------------------------------------
FROM base AS vendor

RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php artisan package:discover --ansi; \
    rm -rf tests docker .git* .env; \
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# -----------------------------------------------------------------------------
# app: runtime image (PHP-FPM on :9000)
# -----------------------------------------------------------------------------
FROM base AS app

ARG APP_VERSION=dev
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    APP_VERSION=${APP_VERSION}

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

USER www-data

EXPOSE 9000
ENTRYPOINT ["/sbin/tini", "--", "orbit-entrypoint"]
CMD ["php-fpm"]

# -----------------------------------------------------------------------------
# nginx: static files + FastCGI proxy to PHP-FPM (non-root, listens on :8080)
# -----------------------------------------------------------------------------
FROM nginxinc/nginx-unprivileged:${NGINX_VERSION}-alpine AS nginx

# Upstream PHP-FPM address: 127.0.0.1 inside a Kubernetes pod (sidecar),
# the service name ("app") with docker compose.
ENV PHP_FPM_HOST=127.0.0.1 \
    PHP_FPM_PORT=9000 \
    NGINX_CLIENT_MAX_BODY_SIZE=12m

COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=vendor /var/www/html/public /var/www/html/public

EXPOSE 8080
