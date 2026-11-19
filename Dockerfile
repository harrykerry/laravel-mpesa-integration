# Development image for running this project in Docker.
#
#   docker compose up -d
#
# It serves the app with artisan and runs a queue worker beside it, which is
# enough for local work and for exercising the callback flow. For production
# put PHP-FPM behind nginx and run the worker under a supervisor.

FROM php:8.5-cli-alpine

# intl and bcmath for money and formatting, pdo_mysql for the database,
# sockets for the RabbitMQ transport, zip for composer, opcache for speed.
RUN apk add --no-cache git unzip icu-data-full icu-dev libzip-dev oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pdo_mysql sockets zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so a code change does not reinstall them.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache \
    && chmod -R ug+w storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
