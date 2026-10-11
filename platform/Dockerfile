# The platform (ND-09): Laravel 12, Filament 5, PHP 8.3 behind Apache. One image serves the web
# application, the queue worker and the scheduler; migrations run apart, as the database owner,
# in the one-shot `migrate` service of compose.yaml (DEPLOY.md).
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs

FROM php:8.3-apache
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_pgsql zip gd opcache \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/madrassa.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/madrassa-entrypoint
COPY docker/migrate.sh /usr/local/bin/madrassa-migrate

WORKDIR /var/www/html
COPY --from=vendor /app/vendor vendor
COPY . .
# Filament's scripts and styles are published once, into the image (README: `filament:assets`).
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan filament:assets \
    && mkdir -p storage/app/private storage/app/mpdf storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/madrassa-entrypoint /usr/local/bin/madrassa-migrate

EXPOSE 80
ENTRYPOINT ["madrassa-entrypoint"]
CMD ["apache2-foreground"]
