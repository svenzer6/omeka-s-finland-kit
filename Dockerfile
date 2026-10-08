# Omeka S Finland Kit: Omeka S 4.2 + 33 pinned modules + Finnish translations.

# --- Stage 1: download Omeka S, modules and themes (pinned in modules.json)
FROM python:3.12-slim AS fetch
WORKDIR /kit
COPY modules.json ./
COPY scripts/ ./scripts/
COPY translations/ ./translations/
RUN python scripts/fetch_modules.py /omeka --with-core \
 && python scripts/i18n.py install /omeka

# --- Stage 2: runtime
FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
      libicu-dev libzip-dev imagemagick ghostscript fonts-dejavu-core \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd intl zip exif \
 && a2enmod rewrite headers \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/omeka.ini
COPY --from=fetch --chown=www-data:www-data /omeka /var/www/html
COPY --chown=www-data:www-data docker/local.config.php /var/www/html/config/local.config.php
COPY . /opt/kit
COPY docker/entrypoint.sh /usr/local/bin/kit-entrypoint
RUN chmod +x /usr/local/bin/kit-entrypoint \
 && mkdir -p /var/www/html/files \
 && chown -R www-data:www-data /var/www/html/files

VOLUME /var/www/html/files
ENTRYPOINT ["kit-entrypoint"]
CMD ["apache2-foreground"]
