# Dependensi dipasang di stage terpisah supaya image akhir tidak membawa Composer.
FROM composer:2.8.12 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

FROM php:8.3.29-cli-alpine3.23

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev \
    && docker-php-ext-install curl \
    && apk del .build-deps \
    && apk add --no-cache libcurl

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY composer.json ./
COPY src ./src
COPY bin ./bin
COPY examples ./examples

RUN adduser -D -u 10001 appuser
USER appuser

EXPOSE 8080

# Contoh menjalankan aplikasi plain PHP. Ganti dengan entrypoint aplikasi Anda.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "examples/plain/public"]
