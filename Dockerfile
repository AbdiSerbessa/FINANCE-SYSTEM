FROM php:8.2-fpm

# Install system dependencies and PostgreSQL drivers
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql