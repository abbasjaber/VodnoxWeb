<?php

declare(strict_types=1);

/**
 * Autoloader for the VODNOX call-event forwarder.
 *
 * The service runs on the Asterisk host with plain PHP and no Composer, so it
 * ships its own tiny PSR-4 autoloader instead of depending on the application's
 * vendor directory. Tests in the repository reuse this same file.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Vodnox\\Telephony\\Events\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__.'/src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});
