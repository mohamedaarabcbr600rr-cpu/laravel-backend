FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git unzip zip curl \
    libzip-dev libicu-dev libonig-dev nodejs npm \
    && docker-php-ext-install pdo pdo_mysql zip intl pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --no-dev --optimize-autoloader

RUN npm install
RUN npm run build

EXPOSE 8080