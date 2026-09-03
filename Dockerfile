# Development image only: the published package ships no runtime image.
#
# Sylius's own PHP image ships pdo_mysql but not pdo_pgsql, and the test suite runs on
# PostgreSQL, so this adds the missing driver on top of it. The base tag is an argument so
# compose.override.yml can pick the fixuid/xdebug variant without a second Dockerfile.
ARG PHP_IMAGE=ghcr.io/sylius/sylius-php:8.3-alpine

FROM ${PHP_IMAGE}

RUN set -eux; \
    apk add --no-cache libpq; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    apk del .build-deps
