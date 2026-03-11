FROM php:8.5-fpm-alpine3.23

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN apk add --no-cache \
    bash \
    git \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    sqlite \
    sqlite-dev \
    $PHPIZE_DEPS

RUN docker-php-ext-install \
    intl \
    pdo \
    pdo_sqlite \
    mbstring \
    zip

RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN deluser www-data || true \
    && delgroup www-data || true \
    && addgroup -g "${HOST_GID}" -S www-data \
    && adduser -S -D -H -u "${HOST_UID}" -G www-data www-data

WORKDIR /var/www

USER www-data

EXPOSE 9000
