# syntax=docker/dockerfile:1.12

ARG PHP_IMAGE=php:8.3.30-apache-bookworm@sha256:daf3cad5642798e462a029e41d6347cba7f3362f7028f8e60c3623dbadc4e590
ARG COMPOSER_IMAGE=composer:2.8.10@sha256:20462d70afcfa999ad75dbd9333194067f4d869078bdb37430339e8d97e541d6

FROM ${COMPOSER_IMAGE} AS composer-binary

FROM ${PHP_IMAGE} AS php-runtime

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APACHE_RUN_USER=www-data \
    APACHE_RUN_GROUP=www-data \
    APACHE_RUN_DIR=/var/run/apache2 \
    APACHE_LOCK_DIR=/var/lock/apache2 \
    APACHE_LOG_DIR=/var/log/apache2

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libcurl4 \
        libcurl4-openssl-dev \
        libfreetype6 \
        libfreetype6-dev \
        libjpeg62-turbo \
        libjpeg62-turbo-dev \
        libonig5 \
        libonig-dev \
        libpng16-16 \
        libpng-dev \
        libpq5 \
        libpq-dev \
        libsodium23 \
        libsodium-dev \
        libwebp7 \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        exif \
        gd \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        sodium \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
        $PHPIZE_DEPS \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsodium-dev \
        libwebp-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers remoteip rewrite \
    && sed -ri 's!Listen 80!Listen 8080!g' /etc/apache2/ports.conf \
    && rm -f /etc/apache2/sites-enabled/000-default.conf \
    && mkdir -p /var/run/apache2 /var/lock/apache2 /var/www/html \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

COPY docker/php/apache-vhost.conf /etc/apache2/sites-available/zrodlo-slowa.conf
COPY docker/php/mpm-prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-zrodlo-slowa.ini

RUN a2ensite zrodlo-slowa

FROM php-runtime AS vendor-development

COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && composer validate --strict --no-check-publish \
    && composer install \
        --no-interaction \
        --no-ansi \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
    && rm -rf /root/.composer /var/lib/apt/lists/*

FROM php-runtime AS vendor-production

COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && composer validate --strict --no-check-publish \
    && composer install \
        --no-dev \
        --no-interaction \
        --no-ansi \
        --no-progress \
        --prefer-dist \
        --classmap-authoritative \
    && rm -rf /root/.composer /var/lib/apt/lists/*

FROM php-runtime AS application-base

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

RUN mkdir -p storage/cache storage/logs storage/sessions \
    && chown -R www-data:www-data storage \
    && find storage -type d -exec chmod 0700 {} \; \
    && find storage -type f -exec chmod 0600 {} \;

EXPOSE 8080

USER www-data:www-data

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=6 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health/live") === false ? 1 : 0);'

FROM application-base AS development

COPY --from=vendor-development --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor

FROM application-base AS production

USER root
RUN rm -rf tests scripts/dev phpunit.xml phpstan.neon

COPY --from=vendor-production --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor

USER www-data:www-data
