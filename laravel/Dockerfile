# The control: stock Laravel on Octane, no TrueAsync anywhere.
#
# The skeleton is generated at build time rather than committed, so this tree
# holds only the files that make it a benchmark — the routes, two controllers,
# and the one middleware that closes a gap against the other stack. Everything
# else is whatever `composer create-project laravel/laravel` ships.
FROM dunglas/frankenphp:latest

# pdo_pgsql for the chat log, pcntl because Octane's workers want it, opcache
# because running a framework without it would be a strawman.
RUN install-php-extensions pdo_pgsql opcache pcntl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The base image ships its own /app, so the skeleton is created beside it and
# moved in — create-project refuses a directory that is not empty.
RUN composer create-project --no-interaction --prefer-dist --no-progress laravel/laravel:^12 /usr/src/laravel \
 && rm -rf /app \
 && mv /usr/src/laravel /app

WORKDIR /app

# Octane's FrankenPHP driver shells out to a `frankenphp` binary and downloads
# one if it cannot find it. The base image already has it; the symlink is what
# stops the build reaching for the network a second time.
RUN composer require --no-interaction --no-progress laravel/octane \
 && php artisan octane:install --server=frankenphp --no-interaction \
 && ln -sf /usr/local/bin/frankenphp /app/frankenphp \
 && composer clear-cache

# The same opcache and JIT settings the TrueAsync image gets, minus the async
# and fiber tuning that has no meaning here. Without this the comparison would
# be measuring PHP's defaults on one side and a tuned runtime on the other.
COPY docker/zz-benchmark.ini /usr/local/etc/php/conf.d/zz-benchmark.ini

COPY bootstrap/app.php /app/bootstrap/app.php
COPY config/benchmark.php /app/config/benchmark.php
COPY routes/web.php /app/routes/web.php
COPY app /app/app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# Caddy writes its data and config under $XDG_*; without these it tries to use
# root's home and the worker exits before it listens.
ENV XDG_CONFIG_HOME=/tmp XDG_DATA_HOME=/tmp

RUN chmod +x /usr/local/bin/entrypoint \
 && chmod -R 777 storage bootstrap/cache

CMD ["entrypoint"]
