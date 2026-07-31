FROM php:8.2-cli

RUN apt-get update && apt-get install -y git unzip libpq-dev \
  && docker-php-ext-install pgsql pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .

ENV COMPOSER_ALLOW_SUPERUSER=1

# Production-safe defaults for Render. Render env vars still override these at runtime.
ENV APP_ENV=production \
    DB_CONNECTION=pgsql \
    CACHE_STORE=file \
    CACHE_DRIVER=file \
    QUEUE_CONNECTION=database \
    DB_QUEUE_CONNECTION=pgsql


RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist \
  || (composer clear-cache && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-source) \
  && chmod +x artisan
COPY start.sh /start.sh
COPY worker.sh /worker.sh
COPY scheduler.sh /scheduler.sh
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /start.sh /worker.sh /scheduler.sh /entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/start.sh"]
