FROM composer:2 AS composer

FROM php:8.3-apache

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libonig-dev libpq-dev libsqlite3-dev libzip-dev python3 unzip \
    && docker-php-ext-install intl mbstring pdo_pgsql pdo_sqlite zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY ecole-filament/ .
COPY madrassa-backend/ /tmp/madrassa-backend/

RUN python3 /tmp/madrassa-backend/scripts/install.py /var/www/html \
    && rm -rf /tmp/madrassa-backend \
    && touch database/database.sqlite \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R ug+rwx storage bootstrap/cache database

COPY ecole-filament/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/madrassa-entrypoint
RUN chmod +x /usr/local/bin/madrassa-entrypoint

EXPOSE 80
ENTRYPOINT ["madrassa-entrypoint"]
CMD ["apache2-foreground"]
