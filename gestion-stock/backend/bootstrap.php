<?php

declare(strict_types=1);

const APP_ROOT = __DIR__;
const MIGRATIONS_DIR = __DIR__ . '/migrations';

error_reporting(E_ALL);
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
