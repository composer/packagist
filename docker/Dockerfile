FROM php:5.6-cli

MAINTAINER Alessandro Lai <alessandro.lai85@gmail.com>

RUN apt-get update \
    && apt-get install -y git libzip-dev \
    && docker-php-ext-install -j5 zip mbstring pdo_mysql \
    && apt-get clean

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer clear-cache
