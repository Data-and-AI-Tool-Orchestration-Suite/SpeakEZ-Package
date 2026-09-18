# This was updated with the latest *compatible* packages on 10/5/2023 by Sam. Let me know if something doesn't build properly.
FROM php:8.5.0RC2-fpm-alpine

# Define build-time arguments
ARG MC_ALIAS
ARG MC_URL
ARG MC_USER
ARG MC_PASSWORD

RUN apk add libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql

RUN apk update; \
    apk upgrade; \
    apk add autoconf g++ libmcrypt libmcrypt-dev libzip libzip-dev linux-headers make oniguruma oniguruma-dev openldap-dev openssl openssl-dev unixodbc-dev;
    
RUN pecl install xdebug \
    && pecl install mcrypt \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install \
      ldap \
      zip \
    && docker-php-ext-enable \
      mcrypt \
      xdebug

RUN apk add --no-cache ffmpeg

# Install Minio Client
# (dl.min.io no longer serves mc releases; pinned GitHub release instead)
RUN curl -fsSL https://github.com/minio/mc/releases/download/RELEASE.2025-08-13T08-35-41Z/mc.linux-amd64.RELEASE.2025-08-13T08-35-41Z -o /usr/bin/mc
RUN chmod +x /usr/bin/mc
 
# Set MinIO Client alias
USER www-data
RUN mc alias set $MC_ALIAS $MC_URL $MC_USER $MC_PASSWORD
USER root

# Latest release
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

COPY uploads.ini /usr/local/etc/php/conf.d/
COPY prod.ini /usr/local/etc/php/conf.d/
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
