<?php
declare(strict_types=1);

// Autoloader minimale: Ablet\Foo\Bar -> src/Foo/Bar.php. Nessuna dipendenza esterna.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Ablet\\', 6) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

defined('ABLET_ROOT') || define('ABLET_ROOT', dirname(__DIR__));

Ablet\Support\Env::load(ABLET_ROOT . '/.env');
Ablet\Support\Env::loadPhp(ABLET_ROOT . '/config.php'); // prevale su .env
