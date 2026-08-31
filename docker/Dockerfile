# syntax=docker/dockerfile:1.7

# ----------------------------------------------------------------------------
# TryPost Dockerfile — multi-stage with `dev` and `production` targets.
#
#   docker build --target dev        -t trypost:dev  -f docker/Dockerfile .
#   docker build --target production -t trypost:prod -f docker/Dockerfile .
# ----------------------------------------------------------------------------

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ----------------------------------------------------------------------------
# Stage 1: system-base — PHP-FPM + system packages + extensions
# ----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS system-base

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        git \
        unzip \
        shadow \
        tzdata \
        postgresql-client \
        postgresql-dev \
        mysql-client \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libwebp-dev \
        libavif-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pdo_mysql \
        gd \
        zip \
        opcache \
        bcmath \
        exif \
        pcntl \
        intl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ----------------------------------------------------------------------------
# Stage 2: composer-deps — full PHP deps (incl. dev) for tooling/asset build
# ----------------------------------------------------------------------------
FROM system-base AS composer-deps

COPY composer.json composer.lock ./
RUN composer install \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ----------------------------------------------------------------------------
# Stage 3: composer-deps-prod — production deps only (no dev tooling)
# ----------------------------------------------------------------------------
FROM system-base AS composer-deps-prod

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ----------------------------------------------------------------------------
# Stage 4: asset-build — wayfinder TS gen + Vite + Inertia SSR build
#
# PHP and Node together in one stage because @laravel/vite-plugin-wayfinder
# invokes `php artisan wayfinder:generate` during vite build — splitting
# the stages would mean the vite phase has no PHP available.
#
# Vite inlines VITE_* values into the JS bundle at build time. Pass them
# as build args (--build-arg VITE_REVERB_APP_KEY=...) so the bundle reaches
# the browser with the correct Reverb/PostHog config. Runtime env vars on
# the container have no effect on the already-compiled bundle.
# ----------------------------------------------------------------------------
FROM composer-deps AS asset-build

# Node 22 for Vite + npm scripts.
RUN apk add --no-cache nodejs npm

ARG VITE_APP_NAME=TryPost
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ARG VITE_POSTHOG_ENABLED=false
ARG VITE_POSTHOG_API_KEY=
ARG VITE_POSTHOG_HOST=https://us.i.posthog.com

# Stub PHP envs so artisan can boot (overridden at runtime).
ENV APP_KEY=base64:c3R1Yi13YXlmaW5kZXItZ2VuLWtleS1mb3ItYnVpbGRpbmctYXNzZXRzMA== \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME} \
    VITE_POSTHOG_ENABLED=${VITE_POSTHOG_ENABLED} \
    VITE_POSTHOG_API_KEY=${VITE_POSTHOG_API_KEY} \
    VITE_POSTHOG_HOST=${VITE_POSTHOG_HOST}

COPY --from=composer-deps /var/www/html/vendor ./vendor
COPY . .

# Ensure Laravel runtime dirs exist — .dockerignore strips their contents
# (correct: caches shouldn't bloat builds), but artisan boot needs the dirs.
RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache

RUN composer dump-autoload --no-scripts --optimize \
    && php artisan wayfinder:generate --with-form \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && npm run build:ssr

# ----------------------------------------------------------------------------
# Stage 6: dev — local development image (bind-mount the source at runtime)
# ----------------------------------------------------------------------------
FROM system-base AS dev

ARG UID=1000
ARG GID=1000

# Node + npm for in-container Vite, npm scripts, ad-hoc tooling.
RUN apk add --no-cache nodejs npm

# Create non-root app user matching host UID/GID for clean bind-mount writes.
RUN groupmod -g "${GID}" www-data 2>/dev/null || groupadd -g "${GID}" app \
    && (id -u app >/dev/null 2>&1 || useradd -u "${UID}" -g "${GID}" -d /home/app -m -s /bin/bash app) \
    && chown -R "${UID}:${GID}" /var/www/html

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.dev.ini /usr/local/etc/php/conf.d/99-trypost.ini
COPY docker/supervisord.dev.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/.env.docker.example /var/www/html/.env.docker.example
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV TRYPOST_TARGET=dev

EXPOSE 80 5173 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ----------------------------------------------------------------------------
# Stage 7: production — self-contained image for self-hosters
# ----------------------------------------------------------------------------
FROM system-base AS production

# php-fpm runs as www-data (Alpine default pool config) — that's the user
# storage and bootstrap/cache must be owned by, so Laravel can write logs,
# session files, view cache, etc.

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.prod.ini /usr/local/etc/php/conf.d/99-trypost.ini
COPY docker/supervisord.prod.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Application source + generated wayfinder TS + built assets — all from
# asset-build (the merged stage that ran php artisan wayfinder:generate,
# npm run build, and npm run build:ssr).
COPY --from=asset-build /var/www/html /var/www/html

# Replace the dev-equipped vendor/ with production-only deps. composer-deps-prod
# is built independently and is much smaller (no phpunit, no debugbar, no pail,
# no pint). Also wipe bootstrap/cache/*.php — package:discover ran with dev
# deps and recorded providers (Pail, Telescope etc) that no longer exist;
# Laravel rediscovers cleanly at first boot.
RUN rm -rf /var/www/html/vendor /var/www/html/bootstrap/cache/*.php
COPY --from=composer-deps-prod /var/www/html/vendor /var/www/html/vendor

RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENV TRYPOST_TARGET=production

EXPOSE 80 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
