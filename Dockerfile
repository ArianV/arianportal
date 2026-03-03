FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite \
    && a2dismod mpm_event mpm_worker mpm_prefork || true \
    && a2enmod mpm_prefork \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]