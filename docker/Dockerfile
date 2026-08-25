ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine
RUN apk add --no-cache git unzip linux-headers postgresql-dev $PHPIZE_DEPS \
 && pecl install pcov && docker-php-ext-enable pcov \
 && docker-php-ext-install pdo_mysql pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# The mounted repo belongs to the host user; git refuses "dubious ownership" otherwise.
RUN git config --global --add safe.directory /app
WORKDIR /app
