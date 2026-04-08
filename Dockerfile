# =============================================================================
# Stage 1 — Composer dependencies
# =============================================================================
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./

# Install production dependencies only (no dev tools needed in the image)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

# Copy the rest of the source so autoload paths resolve correctly
COPY . .

# =============================================================================
# Stage 2 — Node / Vite build
# =============================================================================
FROM node:22-alpine AS node

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Copy full source so Vite can resolve resources/js and vite.config.js
COPY . .

# Provide a minimal APP_URL so laravel-vite-plugin resolves asset paths
ENV APP_URL=http://localhost

RUN npm run build

# =============================================================================
# Stage 3 — PHP-FPM + nginx runtime
# =============================================================================
FROM php:8.2-fpm-alpine AS runtime

LABEL maintainer="Onboard.Ninja"
LABEL description="Onboard.Ninja — DIY self-hosted image"

# ---------------------------------------------------------------------------
# System packages
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        bcmath \
        intl \
        opcache \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# ---------------------------------------------------------------------------
# PHP runtime configuration
# ---------------------------------------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# ---------------------------------------------------------------------------
# nginx configuration
# ---------------------------------------------------------------------------
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# ---------------------------------------------------------------------------
# Supervisor configuration (manages php-fpm + nginx + queue worker)
# ---------------------------------------------------------------------------
COPY docker/supervisord.conf /etc/supervisord.conf

# ---------------------------------------------------------------------------
# Application files
# ---------------------------------------------------------------------------
WORKDIR /var/www/html

# Copy application source
COPY --chown=www-data:www-data . .

# Replace vendor with the production-optimised set from Stage 1
COPY --from=composer --chown=www-data:www-data /app/vendor ./vendor

# Replace public/build with the compiled assets from Stage 2
COPY --from=node --chown=www-data:www-data /app/public/build ./public/build

# ---------------------------------------------------------------------------
# Directory permissions
# ---------------------------------------------------------------------------
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
