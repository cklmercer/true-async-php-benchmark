FROM trueasync/php-true-async:latest

# Benchmarks measure the application, not the debugger.
#
# Xdebug is removed rather than disabled: xdebug.mode=off stops it profiling,
# but the extension still loads and keeps its Zend engine hooks installed,
# which costs on every opcode dispatch. The base image does ship it.
#
# The filename matters. /etc/php.d is parsed in filename order, and the base
# image ships opcache.ini — so a file named 99-*.ini is read BEFORE it and
# silently overridden. Hence zz-.
#
# Same reasoning applies to async.debug_deadlock, which the true_async build
# leaves ON by default. It is deadlock *detection* — per-coroutine scheduler
# bookkeeping — and test one holds twenty thousand live coroutines at once,
# which is precisely where that bookkeeping is worst. It was missed the first
# time round; removing Xdebug and leaving this on is half a job.
#
# Every value below was checked against what the image actually reports, so
# none of them are no-ops:
#
#   zend.assertions=1        assert() is compiled in AND evaluated. -1 compiles
#                            it out entirely, which is the production setting.
#   display_errors=1         a dev default. Any warning on the request path
#                            becomes a write to stdout. log_errors stays on, so
#                            problems still reach `make logs` via stderr.
#   interned_strings=16      doubled: this server compiles a fixed set of
#                            classes once and then runs the same string keys
#                            forever, which is what the buffer is for.
#   save_comments=1          nothing here reads a doc comment at runtime, and
#                            attributes are stored separately, so this is dead
#                            weight in the opcache.
#   huge_code_pages=0        the JIT buffer and interned strings are the hot
#                            region; backing them with 2MB pages cuts iTLB
#                            misses. Degrades to a startup warning if the
#                            kernel has no transparent hugepages.
#   realpath_cache_ttl=120   the image is immutable and validate_timestamps is
#                            already 0, so re-stat'ing resolved paths is waste.
#
# Already correct in the image and therefore not repeated: opcache.enable,
# memory_consumption=256, max_accelerated_files=32531, validate_timestamps=0,
# jit_buffer_size=128M. opcache.jit=1254 is the image's `tracing` alias, stated
# explicitly. Opt level 5 was tried and is not the tracing default; it is back
# at 4 until a quiet box says otherwise.
#
# jit_max_polymorphic_calls defaults to 2, which is low for a middleware onion:
# the stack is a chain of closures each dispatching into a different Middleware
# implementation, and the JIT gives up guarding a call site past the limit.
#
# fiber.stack_size defaults to 2MB and the fiber pool holds 1023. Test one
# parks twenty thousand coroutines at once, so every fiber past the pool was a
# 2MB mmap/munmap per request. The handler stack is shallow — kernel, pipeline,
# fifteen middleware, controller — so 256KB is generous and pools far better.
RUN rm -f /etc/php.d/xdebug.ini \
 && mkdir -p /etc/php.d \
 && printf '%s\n' \
      '[benchmark]' \
      'opcache.jit=1254' \
      'opcache.jit_max_polymorphic_calls=8' \
      'opcache.interned_strings_buffer=32' \
      'opcache.save_comments=0' \
      'opcache.huge_code_pages=1' \
      'zend.assertions=-1' \
      'zend.exception_ignore_args=1' \
      'display_errors=0' \
      'log_errors=1' \
      'realpath_cache_size=8192K' \
      'realpath_cache_ttl=3600' \
      'memory_limit=2G' \
      'error_reporting=E_ALL & ~E_DEPRECATED' \
      'async.debug_deadlock=0' \
      'fiber.stack_size=262144' \
    > /etc/php.d/zz-benchmark.ini

WORKDIR /app

# Laravel's layout, so the paths in bootstrap/app.php are the paths a Laravel
# app would use. Copied as separate trees rather than `COPY . /app` to keep
# results/, benchmark/ and the compose file out of the image.
COPY app /app/app
COPY bootstrap /app/bootstrap
COPY routes /app/routes
COPY bin /app/bin

CMD ["php", "bin/server"]
