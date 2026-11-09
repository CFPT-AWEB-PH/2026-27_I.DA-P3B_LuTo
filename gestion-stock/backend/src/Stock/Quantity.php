<?php

declare(strict_types=1);

namespace App\Stock;

/**
 * Quantités à 3 décimales manipulées en millièmes entiers : aucune erreur d'arrondi flottant.
 * Les valeurs DECIMAL de MariaDB arrivent sous forme de chaînes (« 12.500 »).
 */
final class Quantity
{
    public static function toMilli(string|int|float $value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    public static function fromMilli(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }

    public static function sub(string $a, string $b): string
    {
        return self::fromMilli(self::toMilli($a) - self::toMilli($b));
    }

    public static function abs(string $value): string
    {
        return self::fromMilli(abs(self::toMilli($value)));
    }

    public static function equals(string $a, string $b): bool
    {
        return self::toMilli($a) === self::toMilli($b);
    }

    /** Valeur JSON : nombre sans zéros inutiles. */
    public static function out(string|int|float|null $value): ?float
    {
        return $value === null ? null : self::toMilli($value) / 1000;
    }

    /** Affichage français : « 12,5 ». */
    public static function format(string|int|float $value): string
    {
        $text = number_format(self::toMilli($value) / 1000, 3, ',', "\u{202F}");
        return rtrim(rtrim($text, '0'), ',');
    }
}
