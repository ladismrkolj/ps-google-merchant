<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the GmcFeedManager\ namespace.
 *
 * The module intentionally ships without a Composer dependency so it
 * installs on any PrestaShop host without a `composer install` step.
 * Namespace `GmcFeedManager\Foo\Bar` maps to `src/Foo/Bar.php`.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'GmcFeedManager\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
