FROM node:22 AS frontend-build

WORKDIR /app/frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.2-apache

ENV PORT=8080

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=frontend-build /app/public/app /var/www/html/public/app
COPY docker/apache-storage.conf /etc/apache2/conf-available/protect-storage.conf
COPY docker/entrypoint.sh /usr/local/bin/project-entrypoint

RUN a2enconf protect-storage \
    && chmod +x /usr/local/bin/project-entrypoint \
    && mkdir -p /var/www/html/storage/documentos /var/www/html/storage/legal \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/project-entrypoint"]
