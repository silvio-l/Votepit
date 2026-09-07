# syntax=docker/dockerfile:1
#
# Votepit — optional Docker self-host image.
#
# This is an ADDITIONAL, community-maintained way to run Votepit — it does
# not replace the classic PHP/MySQL shared-hosting deployment described in
# documentation/installation.md (build locally, upload public/ + src/ +
# vendor/ + config/config.php over FTPES, no Docker). Use whichever fits
# your hosting; both run the exact same application code.
#
# Two stages: build the React SPA with Node/pnpm, then assemble the PHP
# runtime (Apache + mod_php, matching the shared-hosting deployment model —
# public/.htaccess already carries the rewrite/security-header rules, so
# reusing Apache here means nothing has to be reimplemented for nginx/FPM).

# ---------------------------------------------------------------------------
# Stage 1: build the React SPA (app/, workspace: @votepit/ui)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS spa-build

WORKDIR /workspace

RUN corepack enable && corepack prepare pnpm@11.7.0 --activate

# Install dependencies first (better layer caching) — copy only manifests.
COPY package.json pnpm-workspace.yaml pnpm-lock.yaml ./
COPY app/package.json app/package.json
COPY packages/ui/package.json packages/ui/package.json
COPY packages/cli/package.json packages/cli/package.json
RUN pnpm install --frozen-lockfile

COPY app app
COPY packages packages
RUN pnpm --filter votepit-app run build

# ---------------------------------------------------------------------------
# Stage 2: PHP runtime (Apache + mod_php)
# ---------------------------------------------------------------------------
FROM php:8.2-apache AS runtime

# Extensions required by composer.json (curl, gd, intl, mbstring, pdo) plus
# pdo_mysql (the concrete PDO driver Votepit\Persistence\ConnectionFactory
# uses — composer.json only declares the driver-agnostic ext-pdo).
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libcurl4-openssl-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl gd intl mbstring pdo pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies first (better layer caching) — same recipe as
# documentation/installation.md ("composer install --no-dev
# --optimize-autoloader", run locally there / in this build stage here).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . .
COPY --from=spa-build /workspace/app/dist/ /var/www/html/public/

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/avatars logs var \
    && chown -R www-data:www-data storage logs var \
    && cp docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

# / with an HTML Accept header always serves the SPA shell (public/index.php
# — see its own doc comment) regardless of config/DB state, so this also
# catches a container that came up but never finished configuring/migrating.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS -H "Accept: text/html" http://localhost/ -o /dev/null || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
