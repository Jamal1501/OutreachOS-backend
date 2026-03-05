FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libsqlite3-dev \
    && docker-php-ext-install zip pdo_sqlite sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader
RUN php artisan config:cache || true && php artisan route:cache || true

COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]