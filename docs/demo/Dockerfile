FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libzip-dev libonig-dev libxml2-dev libicu-dev libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j4 pdo_mysql pdo_sqlite curl mbstring zip gd intl bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*
