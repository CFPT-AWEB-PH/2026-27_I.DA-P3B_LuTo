FROM php:8.3-fpm-alpine

# GD (JPEG, PNG, WebP) : les photos envoyées sont décodées puis réencodées en WebP
RUN apk add --no-cache fcgi libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql opcache gd \
    && apk del .build-deps

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /app
COPY backend/ /app/
COPY database/migrations/ /app/migrations/
RUN mkdir -p /app/storage/uploads && chown -R www-data:www-data /app/storage

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

USER www-data
