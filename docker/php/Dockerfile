FROM php:8.5-fpm-alpine3.23

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

WORKDIR /var/www

EXPOSE 9000
