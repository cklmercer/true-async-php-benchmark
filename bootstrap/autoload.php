<?php

declare(strict_types=1);

/**
 * PSR-4 for one namespace: App\ maps to app/, which is the mapping a stock
 * Laravel app's composer.json declares.
 *
 * A couple of dozen classes in one namespace does not need Composer, and
 * skipping it keeps the image free of a vendor tree that would only ever hold
 * an IDE stub.
 *
 * Required once per worker thread: each worker is its own PHP runtime and
 * inherits nothing the parent registered, the autoloader included.
 */
spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';

    if (is_file($file)) {
        require $file;
    }
});
