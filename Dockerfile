# Render doesn't run PHP natively, so this backend deploys as a
# Docker web service. On Render: New → Web Service → connect this
# repo → Language: Docker.

# Stage 1: install PHP dependencies (needs network, so this must
# happen at Render's build time, not on your PC or in any offline
# environment).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Stage 2: the actual server
FROM php:8.3-apache
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY . /var/www/html/

# Composer's own files don't need to be web-accessible.
RUN rm -f /var/www/html/composer.json

EXPOSE 80
