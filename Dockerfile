# Dockerfile
FROM php:8.2-apache

# Install PostgreSQL PDO driver + common deps
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Copy app into Apache web root
COPY . /var/www/html

# (Optional) If your app is in a subfolder, adjust accordingly.
# Example: COPY ./portal /var/www/html

# Apache listens on 80 in the container; Railway routes traffic automatically.
EXPOSE 80