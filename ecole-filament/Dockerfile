FROM composer:2 AS vendor

WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libsqlite3-dev libzip-dev unzip \
    && docker-php-ext-install intl mbstring pdo_sqlite zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/* \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=vendor /app .

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/madrassa-entrypoint
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN chmod +x /usr/local/bin/madrassa-entrypoint

EXPOSE 80
ENTRYPOINT ["madrassa-entrypoint"]
CMD ["apache2-foreground"]
