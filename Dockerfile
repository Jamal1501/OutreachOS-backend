FROM php:8.2-cli

RUN apt-get update && apt-get install -y git unzip libpq-dev \
  && docker-php-ext-install pgsql pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

RUN composer install --no-dev --optimize-autoloader && chmod +x artisan
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]
