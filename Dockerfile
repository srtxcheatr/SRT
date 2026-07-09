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

# Apache strips the Authorization header before PHP ever sees it,
# unless mod_rewrite explicitly passes it through (see .htaccess) —
# and Apache ignores .htaccess files entirely unless AllowOverride is
# turned on for the directory. Both are off by default in this image.
RUN a2enmod rewrite headers && \
    { \
      echo '<Directory /var/www/html>'; \
      echo '    AllowOverride All'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/z-allowoverride.conf && \
    a2enconf z-allowoverride

EXPOSE 80
