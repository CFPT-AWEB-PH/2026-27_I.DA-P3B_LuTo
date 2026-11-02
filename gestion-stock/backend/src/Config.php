<?php

declare(strict_types=1);

namespace App;

/** Lecture de la configuration fournie par les variables d'environnement du conteneur. */
final class Config
{
    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function required(string $name): string
    {
        $value = self::get($name);
        if ($value === null) {
            throw new \RuntimeException("Variable d'environnement manquante : {$name}");
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    public static function version(): string
    {
        return self::get('APP_VERSION', 'dev');
    }
}
