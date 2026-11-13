# Laravel 12 on Octane + FrankenPHP (worker mode): the framework boots once per
# worker and stays in memory, instead of once per request as with PHP-FPM.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pcntl opcache zip \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/zz-app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first for layer caching. Dev dependencies (phpunit) are kept so
# the test suites can run inside the container.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress

COPY . .
RUN sed -i 's/\r$//' docker/entrypoint.sh && chmod +x docker/entrypoint.sh \
 && mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions storage/logs bootstrap/cache \
 && composer dump-autoload --optimize --no-interaction \
 && php artisan package:discover --ansi

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    OCTANE_SERVER=frankenphp

EXPOSE 8080

ENTRYPOINT ["/app/docker/entrypoint.sh"]
