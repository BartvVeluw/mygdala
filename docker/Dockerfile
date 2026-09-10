FROM php:8.2-apache

# libjpeg/libpng/libwebp-dev + gd: Portfolio image optimization
# (App\Service\ImageOptimizer) resizes/re-encodes JPEG/PNG/WebP uploads with
# GD. exif: reads JPEG orientation so portrait photos taken on a phone/camera
# aren't saved sideways (see ImageOptimizer::applyExifOrientation()).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql zip gd exif \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Composer, so `docker compose exec php composer ...` works without installing it on Windows.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Allow .htaccess overrides (used at the project root to block access to .env, vendor/, db/, src/).
COPY docker/apache-app.conf /etc/apache2/conf-available/z-app.conf
RUN a2enconf z-app

# Raise upload limits for the contact form's file attachment (base image default is too small).
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/z-uploads.ini

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
