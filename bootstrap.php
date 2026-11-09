<?php

declare(strict_types=1);

/**
 * Standalone bootstrap. Works with or without `composer install`:
 * if Composer's autoloader exists it is used, otherwise a small PSR-4
 * autoloader for the Shinigami namespace is registered.
 */

$root = __DIR__;
$composer = $root . '/vendor/autoload.php';

if (is_file($composer)) {
    require $composer;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Shinigami\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

return $root;
