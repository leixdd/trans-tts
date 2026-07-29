# syntax=docker/dockerfile:1

FROM oven/bun:1 AS frontend

WORKDIR /app

COPY package.json bun.lock* ./
RUN bun install --frozen-lockfile || bun install

COPY resources ./resources
COPY vite.config.js ./
COPY public ./public

RUN bun run build

FROM dunglas/frankenphp:php8.4 AS app

WORKDIR /app

RUN install-php-extensions \
    pcntl \
    pdo_sqlite \
    sqlite3 \
    bcmath \
    intl \
    zip \
    opcache \
    redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private/translation-audio \
        bootstrap/cache \
        database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && composer dump-autoload --optimize --no-dev

ENV SERVER_NAME=":8000"
EXPOSE 8000

ENTRYPOINT ["php", "artisan"]
CMD ["octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
