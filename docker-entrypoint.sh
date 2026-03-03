#!/usr/bin/env bash
set -e

# Railway provides $PORT. Default to 8080 if not set (some platforms use 8080).
PORT="${PORT:-8080}"

# Update Apache to listen on $PORT instead of 80
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf

# Update the default vhost to match the port
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Some images also include this file; safe to attempt
if [ -f /etc/apache2/sites-enabled/000-default.conf ]; then
  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf || true
fi

exec "$@"