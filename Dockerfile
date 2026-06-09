FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    nginx \
    postgresql-dev \
    librdkafka-dev \
    linux-headers \
    $PHPIZE_DEPS \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && docker-php-ext-install pdo pdo_pgsql pcntl \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /var/www/html/docker/entrypoint-web.sh

COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

EXPOSE 8080

CMD ["/var/www/html/docker/entrypoint-web.sh"]
