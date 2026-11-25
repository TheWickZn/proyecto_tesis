#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
    ""|*[!0-9]*)
        echo "PORT must be a number." >&2
        exit 1
        ;;
esac

if [ "$port" != "80" ]; then
    sed -i "s/Listen 80/Listen ${port}/" /etc/apache2/ports.conf
    sed -i "s/:80>/:${port}>/" /etc/apache2/sites-available/000-default.conf
fi

mkdir -p /var/www/html/storage/documentos /var/www/html/storage/legal
chown www-data:www-data /var/www/html/storage /var/www/html/storage/documentos /var/www/html/storage/legal

exec apache2-foreground
