FROM composer:2 AS vendor

WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-libs libpq libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev \
    && docker-php-ext-install bcmath intl pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html
COPY --from=vendor /app .
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data
CMD ["php-fpm", "-F"]
