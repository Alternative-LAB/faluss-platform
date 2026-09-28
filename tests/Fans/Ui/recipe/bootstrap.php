<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

// A Windows test checkout can share vendor with another checkout. Resolve the
// project classes from this checkout first so legacy façade files are loaded once.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Faluss\\Platform\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);
