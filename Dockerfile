FROM php:8.3-apache

# curl est requis par index.php (httpGet / curl_multi)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY index.php /var/www/html/index.php

WORKDIR /var/www/html

EXPOSE 80
