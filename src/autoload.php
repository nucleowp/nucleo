<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

// Runtime autoloader for Nucleo\ classes. Production never loads Composer's dev autoloader.
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Nucleo\\', 7) !== 0 || strncmp($class, 'Nucleo\\Tests\\', 13) === 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
