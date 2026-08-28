# The third stack: the control's Laravel, served by the TrueAsync FrankenPHP
# worker instead of Octane.
#
# Three stages, because the first two are expensive and never change: PHP built
# from the true-async branch with the async extension, then FrankenPHP built
# from its own true-async branch against that PHP, then the application.
#
# The build context is the repository root, so the application stage copies the
# control's tree directly. There is no second copy of the routes, controllers
# or middleware anywhere — "the same app on a different server" is the claim
# this benchmark makes, and sharing the files is what makes it true.

# --- PHP: TrueAsync, ZTS, embed ------------------------------------------------
#
# Adapted from true-async/laravel-test for arm64, minus MySQL, Xdebug and the
# file watcher (the nowatcher build tag drops the last).
FROM ubuntu:24.04 AS php

ARG PHP_BRANCH=true-async
ARG TRUEASYNC_BRANCH=main
ARG GO_VERSION=1.25.4

RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf bison build-essential curl re2c git ca-certificates \
    cmake ninja-build wget dos2unix pkg-config \
    libxml2-dev libssl-dev libargon2-dev libcurl4-openssl-dev \
    libedit-dev libreadline-dev libsodium-dev libsqlite3-dev \
    libonig-dev libzip-dev libpq-dev libicu-dev libpcre2-dev libpsl-dev \
 && rm -rf /var/lib/apt/lists/*

# Arch-detected: the upstream Dockerfile hardcodes linux-amd64.
RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in amd64) goarch=amd64 ;; arm64) goarch=arm64 ;; *) echo "unsupported $arch" >&2; exit 1 ;; esac; \
    wget -q "https://go.dev/dl/go${GO_VERSION}.linux-${goarch}.tar.gz"; \
    tar -C /usr/local -xzf "go${GO_VERSION}.linux-${goarch}.tar.gz"; \
    rm "go${GO_VERSION}.linux-${goarch}.tar.gz"
ENV PATH="/usr/local/go/bin:$PATH" GOTOOLCHAIN=local

RUN wget -q https://github.com/libuv/libuv/archive/v1.49.0.tar.gz \
 && tar -xf v1.49.0.tar.gz && cd libuv-1.49.0 && mkdir build && cd build \
 && cmake .. -G Ninja -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTING=OFF \
 && ninja && ninja install && ldconfig && cd / && rm -rf libuv* v1.49.0.tar.gz

RUN wget -q https://github.com/curl/curl/releases/download/curl-8_10_1/curl-8.10.1.tar.gz \
 && tar -xf curl-8.10.1.tar.gz && cd curl-8.10.1 \
 && ./configure --prefix=/usr/local --with-openssl --enable-shared --disable-static >/dev/null \
 && make -j"$(nproc)" >/dev/null && make install && ldconfig \
 && cd / && rm -rf curl-8.10.1*

# The extension is a separate repository and is built in-tree, as ext/async.
RUN git clone --depth=1 --branch=${PHP_BRANCH} https://github.com/true-async/php-src /usr/src/php-src \
 && git clone --depth=1 --branch=${TRUEASYNC_BRANCH} https://github.com/true-async/php-async /usr/src/php-async \
 && mkdir -p /usr/src/php-src/ext/async \
 && cp -r /usr/src/php-async/. /usr/src/php-src/ext/async/ \
 && dos2unix /usr/src/php-src/ext/async/config.m4 || true

WORKDIR /usr/src/php-src
# --enable-werror dropped: it is in the upstream recipe but turns any new gcc-13
# warning on this toolchain into a failed build, and it buys nothing here.
RUN ./buildconf --force >/dev/null \
 && ./configure \
      --prefix=/usr/local \
      --enable-embed --enable-zts --enable-async \
      --with-pdo-pgsql --with-pgsql --with-pdo-sqlite \
      --with-openssl --with-curl --with-readline \
      --enable-mbstring --enable-sockets --enable-pcntl --enable-bcmath \
      --enable-intl --with-zip --with-zlib --enable-opcache \
      --without-pear \
      --with-config-file-path=/etc --with-config-file-scan-dir=/etc/php.d \
      --disable-debug \
 && make -j"$(nproc)" && make install \
 && mkdir -p /etc/php.d

# Fail the build here rather than at runtime if the extension did not land.
RUN php -v && php -m | grep -i async && php-config --configure-options | tr ' ' '\n' | grep -E 'embed|zts|async'

# --- FrankenPHP: the true-async branch ----------------------------------------
FROM php AS frankenphp

# The true-async-07 branch's go.mod requires go >= 1.26; the PHP stage pins
# 1.25.4, which is what the upstream recipe uses and what fails here.
ARG GO_VERSION=1.26.7
ARG FRANKENPHP_BRANCH=true-async-07

# Caddy pulls in cbrotli, which needs the brotli headers at cgo compile time.
RUN apt-get update && apt-get install -y --no-install-recommends libbrotli-dev \
 && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in amd64) goarch=amd64 ;; arm64) goarch=arm64 ;; *) exit 1 ;; esac; \
    rm -rf /usr/local/go; \
    wget -q "https://go.dev/dl/go${GO_VERSION}.linux-${goarch}.tar.gz"; \
    tar -C /usr/local -xzf "go${GO_VERSION}.linux-${goarch}.tar.gz"; \
    rm "go${GO_VERSION}.linux-${goarch}.tar.gz"; \
    go version

RUN git clone --depth=1 --branch=${FRANKENPHP_BRANCH} https://github.com/true-async/frankenphp /usr/src/frankenphp
WORKDIR /usr/src/frankenphp/caddy/frankenphp
# Built by hand from the branch's TRUE_ASYNC.README.md: its own build.sh is
# hardcoded to the author's home directory and an x86_64 gcc library path.
RUN export CGO_CFLAGS="$(php-config --includes)" \
 && export CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
 && go build -tags "trueasync,nowatcher" -o /usr/local/bin/frankenphp \
 && chmod +x /usr/local/bin/frankenphp \
 && ldconfig \
 && frankenphp version

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /app

# --- The application -----------------------------------------------------------
FROM frankenphp AS app

# The stage above installed composer through a pipe, which this interpreter
# dies on; fetch to a file instead. unzip because this PHP is built without
# ext-zip, so composer needs the binary to expand a --prefer-dist package.
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/* \
 && curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer \
 && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm /tmp/composer-setup.php \
 && composer --version

WORKDIR /app/www

# Laravel 12's dependency tree caps at php <8.6 (nette/schema and friends) and
# this interpreter reports 8.6.0-dev, so the platform requirement is ignored.
# The true-async project's own image does the same thing.
RUN composer create-project --no-interaction --prefer-dist --no-progress \
      --ignore-platform-req=php --ignore-platform-req=ext-zip \
      laravel/laravel:^12 /usr/src/laravel \
 && cp -a /usr/src/laravel/. /app/www/ \
 && rm -rf /usr/src/laravel \
 && composer clear-cache

# The control's application code, unchanged and uncopied — same files, from the
# same place the Octane image takes them.
COPY laravel/bootstrap/app.php /app/www/bootstrap/app.php
COPY laravel/config/benchmark.php /app/www/config/benchmark.php
COPY laravel/routes/web.php /app/www/routes/web.php
COPY laravel/app /app/www/app

# The pieces that exist only because of coroutines, and the worker config. Both
# managers replace a Laravel singleton that assumes one request per process.
COPY laravel-async/app/Database/PooledDatabaseManager.php /app/www/app/Database/PooledDatabaseManager.php
COPY laravel-async/app/Session/CoroutineAwareSessionManager.php /app/www/app/Session/CoroutineAwareSessionManager.php
COPY laravel-async/public/async_entrypoint.php /app/www/public/async_entrypoint.php
COPY laravel-async/Caddyfile /app/www/Caddyfile

# The control's opcache and JIT settings, plus the async and fiber tuning that
# only has meaning here. Written inline rather than copied from the control's
# conf.d file because this PHP scans /etc/php.d and expects a section header.
RUN printf '%s\n' \
      '[benchmark]' \
      'opcache.enable=1' \
      'opcache.enable_cli=1' \
      'opcache.jit=1254' \
      'opcache.jit_buffer_size=128M' \
      'opcache.memory_consumption=256' \
      'opcache.max_accelerated_files=32531' \
      'opcache.validate_timestamps=0' \
      'opcache.interned_strings_buffer=32' \
      'opcache.save_comments=0' \
      'zend.assertions=-1' \
      'display_errors=0' \
      'log_errors=1' \
      'error_reporting=E_ALL & ~E_DEPRECATED' \
      'memory_limit=2G' \
      'async.debug_deadlock=0' \
      'fiber.stack_size=262144' \
    > /etc/php.d/zz-benchmark.ini

COPY laravel-async/docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && composer dump-autoload --optimize --no-interaction --ignore-platform-req=php \
 && chmod -R 777 storage bootstrap/cache

# Caddy writes its data and config under $XDG_*; without these it tries to use
# root's home and the worker exits before it listens.
ENV XDG_CONFIG_HOME=/tmp XDG_DATA_HOME=/tmp

CMD ["entrypoint"]
