FROM php:8.2-fpm-alpine

# Install build deps, pecl apcu, enable it, then clean up
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS autoconf && \
    pecl install apcu && \
    docker-php-ext-enable apcu && \
    apk del .build-deps

# Optional APCu settings
RUN printf "apc.enabled=1\napc.shm_size=128M\napc.enable_cli=0\n" > /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini

# Keep same user/working dir as base image
WORKDIR /var/www/html
